<?php
/**
 * admin/admin_sync_proprietaires_tiers.php
 *
 * Sprint 2B — AUDIT proprietaires ↔ tiers.
 *
 * LECTURE SEULE par défaut. Aucune écriture BDD.
 * Le bouton "Synchroniser" déclenche une SECONDE phase (admin_sync_proprietaires_tiers_apply.php)
 * qui crée les tiers manquants. Aucune suppression. Idempotent.
 *
 * Audit produit :
 *   1. Total proprietaires actifs
 *   2. Total tiers actifs
 *   3. Proprietaires SANS tiers (id_tiers IS NULL ou id_tiers invalide)
 *   4. Proprietaires AVEC tiers (déjà migrés)
 *   5. Tiers SANS rôle dans tiers_roles (orphelins)
 *   6. Proprietaires avec doublons probables dans tiers (même raison/SIRET/email)
 *   7. Écarts de noms/sociétés
 *   8. Taux de cohérence global (%)
 *   9. Recommandations + estimation du Sprint 2B-apply
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/entity_matcher.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = $GLOBALS['pdo'];

// ═══════════════════════════════════════════════════════════════════
// AUDIT — uniquement SELECT, aucune écriture
// ═══════════════════════════════════════════════════════════════════
$report = [];

// ─── 0. Détection dynamique des colonnes ────────────────────────────
// Permet de construire des requêtes qui ne plantent pas si une colonne
// optionnelle est absente (siret, telephone, etc. variable selon historique).
$colsProprio = $colsTiers = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM proprietaires") as $row) $colsProprio[strtolower($row['Field'])] = true;
} catch (Throwable $e) { $report['ERREUR_show_columns_proprietaires'] = $e->getMessage(); }
try {
    foreach ($pdo->query("SHOW COLUMNS FROM tiers") as $row) $colsTiers[strtolower($row['Field'])] = true;
} catch (Throwable $e) { $report['ERREUR_show_columns_tiers'] = $e->getMessage(); }

$hasP = static function (string $col) use ($colsProprio): bool { return isset($colsProprio[strtolower($col)]); };
$hasT = static function (string $col) use ($colsTiers): bool { return isset($colsTiers[strtolower($col)]); };

// Helper : retourne soit `p.col`, soit `NULL AS col` selon présence
$selP = static function (string $col) use ($hasP): string {
    return $hasP($col) ? ('p.' . $col) : ('NULL AS ' . $col);
};
$selT = static function (string $col) use ($hasT): string {
    return $hasT($col) ? ('t.' . $col) : ('NULL AS ' . $col);
};

$report['0_schema_detecte'] = [
    'proprietaires_colonnes' => array_keys($colsProprio),
    'tiers_colonnes'         => array_keys($colsTiers),
    'absences_critiques'     => array_values(array_filter([
        !$hasP('siret')     ? 'proprietaires.siret ABSENT'     : null,
        !$hasP('email')     ? 'proprietaires.email ABSENT'     : null,
        !$hasP('telephone') ? 'proprietaires.telephone ABSENT' : null,
        !$hasP('id_tiers')  ? 'proprietaires.id_tiers ABSENT (migration tiers non commencée)' : null,
        !$hasT('siret')     ? 'tiers.siret ABSENT'             : null,
        !$hasT('email')     ? 'tiers.email ABSENT'             : null,
        !$hasT('telephone') ? 'tiers.telephone ABSENT'         : null,
    ])),
];

// 1. Totaux
try {
    $report['1_total_proprietaires_actifs'] = (int)$pdo->query("SELECT COUNT(*) FROM proprietaires WHERE actif = 1")->fetchColumn();
    $report['2_total_tiers_actifs']         = (int)$pdo->query("SELECT COUNT(*) FROM tiers WHERE actif = 1")->fetchColumn();
} catch (Throwable $e) {
    $report['ERREUR_totaux'] = $e->getMessage();
}

// 3. Vérifier la présence de la colonne proprietaires.id_tiers
$hasIdTiers = false;
try {
    $hasIdTiers = (bool)$pdo->query("SHOW COLUMNS FROM proprietaires LIKE 'id_tiers'")->fetchColumn();
} catch (Throwable) {}
$report['3_colonne_proprietaires_id_tiers_existe'] = $hasIdTiers ? '✅ OUI' : '❌ NON (migration tiers non commencée ?)';

// 4. Proprietaires SANS tiers lié
// Adaptatif : SELECT n'utilise que les colonnes qui existent réellement
$proprietairesSansTiers = 0;
$samplesSansTiers = [];
if ($hasIdTiers) {
    try {
        $proprietairesSansTiers = (int)$pdo->query("
            SELECT COUNT(*) FROM proprietaires p
            WHERE p.actif = 1
              AND (p.id_tiers IS NULL OR p.id_tiers = 0
                   OR NOT EXISTS (SELECT 1 FROM tiers t WHERE t.id = p.id_tiers))
        ")->fetchColumn();
        $cols = [
            'p.id',
            $hasP('nom')      ? 'p.nom'       : 'NULL AS nom',
            $hasP('prenom')   ? 'p.prenom'    : 'NULL AS prenom',
            $hasP('societe')  ? 'p.societe'   : 'NULL AS societe',
            $hasP('email')    ? 'p.email'     : 'NULL AS email',
            $hasP('telephone')? 'p.telephone' : 'NULL AS telephone',
            $hasP('siret')    ? 'p.siret'     : 'NULL AS siret',
            'p.id_tiers',
        ];
        $sql = "SELECT " . implode(', ', $cols) . "
                FROM proprietaires p
                WHERE p.actif = 1
                  AND (p.id_tiers IS NULL OR p.id_tiers = 0
                       OR NOT EXISTS (SELECT 1 FROM tiers t WHERE t.id = p.id_tiers))
                ORDER BY p.id DESC
                LIMIT 10";
        $samplesSansTiers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $report['ERREUR_proprio_sans_tiers'] = $e->getMessage();
    }
}
$report['4_proprietaires_SANS_tiers'] = $proprietairesSansTiers;
$report['4_samples_sans_tiers'] = $samplesSansTiers;

// 5. Tiers SANS rôle (orphelins potentiels)
$tiersSansRole = 0;
try {
    $tiersSansRole = (int)$pdo->query("
        SELECT COUNT(*) FROM tiers t
        WHERE t.actif = 1
          AND NOT EXISTS (SELECT 1 FROM tiers_roles tr WHERE tr.id_tiers = t.id AND tr.actif = 1)
    ")->fetchColumn();
} catch (Throwable $e) {
    $report['ERREUR_tiers_sans_role'] = $e->getMessage();
}
$report['5_tiers_actifs_SANS_role_actif'] = $tiersSansRole;

// 6. Doublons probables : proprietaire dont la raison_sociale OR email OR siret
// matche un tiers existant. Adaptatif : ne référence que les colonnes présentes.
$doublonsProbables = 0;
$samplesDoublons = [];
if ($hasIdTiers) {
    try {
        // Construire dynamiquement les clauses ON selon colonnes disponibles
        $onClauses = [];
        if ($hasP('societe') && $hasT('raison_sociale')) {
            $onClauses[] = "(p.societe IS NOT NULL AND p.societe <> '' AND LOWER(TRIM(p.societe)) = LOWER(TRIM(t.raison_sociale)))";
        }
        if ($hasP('email') && $hasT('email')) {
            $onClauses[] = "(p.email IS NOT NULL AND p.email <> '' AND LOWER(p.email) = LOWER(t.email))";
        }
        if ($hasP('siret') && $hasT('siret')) {
            $onClauses[] = "(p.siret IS NOT NULL AND p.siret <> '' AND p.siret = t.siret)";
        }

        if (empty($onClauses)) {
            $report['6_skip_doublons'] = 'Aucune colonne commune (societe, email, siret) entre proprietaires et tiers — détection doublons impossible';
        } else {
            $onSql = implode(' OR ', $onClauses);

            $doublonsProbables = (int)$pdo->query("
                SELECT COUNT(DISTINCT p.id)
                FROM proprietaires p
                INNER JOIN tiers t ON ({$onSql})
                WHERE p.actif = 1
                  AND (p.id_tiers IS NULL OR p.id_tiers = 0)
            ")->fetchColumn();

            // Samples : SELECT dynamique
            $selCols = ['p.id AS proprio_id'];
            if ($hasP('nom'))     $selCols[] = 'p.nom';
            if ($hasP('prenom'))  $selCols[] = 'p.prenom';
            if ($hasP('societe')) $selCols[] = 'p.societe';
            if ($hasP('email'))   $selCols[] = 'p.email';
            if ($hasP('siret'))   $selCols[] = 'p.siret';
            $selCols[] = 't.id AS tiers_id';
            if ($hasT('raison_sociale')) $selCols[] = 't.raison_sociale AS t_raison';
            if ($hasT('email'))          $selCols[] = 't.email AS t_email';
            if ($hasT('siret'))          $selCols[] = 't.siret AS t_siret';

            $sql = "SELECT " . implode(', ', $selCols) . "
                    FROM proprietaires p
                    INNER JOIN tiers t ON ({$onSql})
                    WHERE p.actif = 1
                      AND (p.id_tiers IS NULL OR p.id_tiers = 0)
                    LIMIT 10";
            $samplesDoublons = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $report['ERREUR_doublons'] = $e->getMessage();
    }
}
$report['6_proprietaires_avec_tiers_doublon_probable'] = $doublonsProbables;
$report['6_samples_doublons'] = $samplesDoublons;

// 7. Rôles dans tiers_roles répartis
try {
    $repartitionRoles = $pdo->query("
        SELECT role_code, COUNT(*) AS nb
        FROM tiers_roles
        WHERE actif = 1
        GROUP BY role_code
        ORDER BY nb DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $report['7_repartition_role_code_actifs'] = $repartitionRoles;
} catch (Throwable $e) {
    $report['ERREUR_repartition_roles'] = $e->getMessage();
}

// 8. Tiers avec rôle 'proprietaire' ou 'bailleur'
$tiersAvecRoleProprio = 0;
try {
    $tiersAvecRoleProprio = (int)$pdo->query("
        SELECT COUNT(DISTINCT t.id)
        FROM tiers t
        INNER JOIN tiers_roles tr ON tr.id_tiers = t.id
        WHERE t.actif = 1 AND tr.actif = 1
          AND tr.role_code IN ('proprietaire', 'bailleur', 'bailleur_principal')
    ")->fetchColumn();
} catch (Throwable $e) {
    $report['ERREUR_role_proprio'] = $e->getMessage();
}
$report['8_tiers_avec_role_proprietaire_OU_bailleur'] = $tiersAvecRoleProprio;

// 9. Taux de cohérence
$totalP = $report['1_total_proprietaires_actifs'] ?? 0;
$tauxCoherence = 0;
$proprioMigres = max(0, $totalP - $proprietairesSansTiers);
if ($totalP > 0) {
    $tauxCoherence = round(($proprioMigres / $totalP) * 100, 1);
}
$report['9_taux_coherence_proprio_to_tiers'] = $tauxCoherence . ' %';
$report['9_proprietaires_migres'] = $proprioMigres . ' / ' . $totalP;

// 10. Verdict
$verdict = 'UNKNOWN';
$peutBasculer = false;
if ($tauxCoherence >= 95) {
    $verdict = '✅ OK — Peut basculer FEATURE_ENTITY_MATCHER=ON';
    $peutBasculer = true;
} elseif ($tauxCoherence >= 80) {
    $verdict = '⚠️ ACCEPTABLE — Sync recommandée avant bascule (gap = ' . (100 - $tauxCoherence) . ' %)';
} else {
    $verdict = '❌ KO — Bascule INTERDITE tant que sync pas faite (gap = ' . (100 - $tauxCoherence) . ' %)';
}
$report['10_verdict'] = $verdict;

// 11. Estimation du coût de sync
$report['11_estimation_sync'] = [
    'a_creer' => $proprietairesSansTiers - $doublonsProbables,
    'a_lier_a_un_tiers_existant' => $doublonsProbables,
    'duree_estimee' => '<1s par proprio (inserts simples)',
    'risque' => 'AUCUN (insertions seulement, aucune suppression, aucun update destructif)',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Audit proprietaires ↔ tiers — Sprint 2B</title>
    <style>
        body { font-family: "DM Mono", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1200px; margin: 0 auto; }
        h1 { color: #fde68a; margin: 0 0 8px; font-size: 22px; }
        h2 { color: #84a98c; font-size: 14px; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #334155; }
        .ok { color: #84a98c; font-weight: 700; }
        .ko { color: #f87171; font-weight: 700; }
        .warn { color: #fde68a; }
        pre { background: #1e293b; padding: 10px 14px; border-radius: 6px; overflow-x: auto; font-size: 11.5px; line-height: 1.55; margin: 4px 0 8px; }
        .verdict { background: #1e293b; padding: 18px 22px; border-radius: 8px; margin-top: 28px; border-left: 6px solid; }
        .verdict.ok { border-left-color: #84a98c; }
        .verdict.warn { border-left-color: #fde68a; }
        .verdict.ko { border-left-color: #f87171; }
        .verdict h3 { margin: 0 0 8px; font-size: 18px; }
        .read-only { display: inline-block; padding: 4px 10px; background: #155e75; color: #fff; border-radius: 4px; font-size: 11px; margin-left: 12px; }
    </style>
</head>
<body>

<h1>🔍 Audit proprietaires ↔ tiers (Sprint 2B) <span class="read-only">LECTURE SEULE</span></h1>
<p>Aucune écriture BDD. Le bouton « Synchroniser » ouvrira une seconde page d'application (à valider).</p>

<?php foreach ($report as $key => $val): ?>
    <h2><?= htmlspecialchars($key) ?></h2>
    <pre><?= htmlspecialchars(is_string($val) || is_int($val) ? (string)$val : json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<?php endforeach; ?>

<div class="verdict <?= $tauxCoherence >= 95 ? 'ok' : ($tauxCoherence >= 80 ? 'warn' : 'ko') ?>">
    <h3><?= htmlspecialchars($verdict) ?></h3>
    <p>Taux de cohérence : <strong><?= $tauxCoherence ?> %</strong>
       (<?= $proprioMigres ?> / <?= $totalP ?> proprietaires actifs ont un tiers lié).</p>
    <?php if (!$peutBasculer): ?>
        <p><strong>Prochaine étape recommandée</strong> : créer le script de synchronisation
        <code>admin_sync_proprietaires_tiers_apply.php</code> qui :</p>
        <ul>
            <li>Lit la liste des proprietaires sans tiers (étape 4).</li>
            <li>Pour chacun :
                <ul>
                    <li>Si doublon probable détecté (étape 6) → propose le rattachement à un tiers existant (validation user).</li>
                    <li>Sinon → INSERT tiers + INSERT tiers_roles(role_code='proprietaire' ou 'bailleur') + UPDATE proprietaires.id_tiers = <id_nouveau>.</li>
                </ul>
            </li>
            <li>Aucune suppression, aucun UPDATE de données existantes.</li>
            <li>Idempotent : on peut le relancer sans risque.</li>
            <li>Rapport avant/après comparé à cet audit.</li>
        </ul>
    <?php else: ?>
        <p>Tu peux activer le flag <code>FEATURE_ENTITY_MATCHER=true</code> dans
        <code>inc/feature_flags.local.php</code> et lancer la page A/B
        (<a href="admin_entity_matcher_ab.php" style="color:#84a98c;">admin_entity_matcher_ab.php</a>)
        pour confirmer que les résultats sont iso.</p>
    <?php endif; ?>
</div>

<p style="margin-top: 24px; color: #94a3b8; font-size: 12px;">
    Page admin générée le <?= date('Y-m-d H:i:s') ?> · Aucun fichier legacy modifié.
</p>

</body>
</html>
