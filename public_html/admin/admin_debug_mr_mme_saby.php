<?php
/**
 * admin/admin_debug_mr_mme_saby.php
 *
 * Debug ciblé du cas "MR & MME SABY" — pourquoi em_match_tiers({"societe":...})
 * ne retrouve pas un proprio pourtant lié à un tiers existant ?
 *
 * LECTURE SEULE.
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

$searchTerm = 'MR & MME SABY';
$report = ['search' => $searchTerm];

// ─── 1. Trouver le proprietaire correspondant ────────────────────────
try {
    $st = $pdo->prepare("SELECT id, id_tiers, type_personne, civilite, nom, prenom, societe,
                                email, telephone, actif
                         FROM proprietaires
                         WHERE societe = ? OR societe LIKE ?
                         LIMIT 5");
    $st->execute([$searchTerm, '%' . $searchTerm . '%']);
    $report['1_proprietaires_trouves'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $report['ERR_1'] = $e->getMessage(); }

// Prendre le premier comme cible
$targetProprio = $report['1_proprietaires_trouves'][0] ?? null;
$targetTiersId = $targetProprio ? (int)($targetProprio['id_tiers'] ?? 0) : 0;

// ─── 2. Lire le tiers lié ────────────────────────────────────────────
if ($targetTiersId > 0) {
    try {
        $st = $pdo->prepare("SELECT id, type_tiers, sous_type, civilite, nom, prenom, raison_sociale,
                                    nom_affichage, email, telephone, siret, siren, actif
                             FROM tiers WHERE id = ?");
        $st->execute([$targetTiersId]);
        $report['2_tiers_lie'] = $st->fetch(PDO::FETCH_ASSOC) ?: ['ERREUR' => 'tiers introuvable'];
    } catch (Throwable $e) { $report['ERR_2'] = $e->getMessage(); }
} else {
    $report['2_tiers_lie'] = '(aucun proprio cible trouvé ou id_tiers vide)';
}

// ─── 3. Normalisations appliquées par le moteur ──────────────────────
$report['3_normalisations'] = [
    'em_strip_accents("MR & MME SABY")' => em_strip_accents('MR & MME SABY'),
    'em_normalize_text("MR & MME SABY")' => em_normalize_text('MR & MME SABY'),
    'em_normalize_name("MR & MME SABY")' => em_normalize_name('MR & MME SABY'),
    'remarque' => 'em_normalize_name supprime le "&" via /[^a-z0-9 \-]/, le LIKE SQL devient %mr  mme saby% (2 espaces) qui ne matche pas "MR & MME SABY" en BDD',
];

// ─── 4. Reproduire la query SQL du moteur (telle qu'elle est faite) ──
$normRaison = em_normalize_name('MR & MME SABY');
$sqlSimu = "SELECT id, nom, prenom, raison_sociale, nom_affichage
            FROM tiers
            WHERE LOWER(raison_sociale) LIKE :rs OR LOWER(nom) LIKE :nm";
try {
    $st = $pdo->prepare($sqlSimu);
    $st->execute([':rs' => '%' . $normRaison . '%', ':nm' => '%' . $normRaison . '%']);
    $report['4_simul_query_moteur'] = [
        'sql'    => $sqlSimu,
        'params' => [':rs' => '%' . $normRaison . '%', ':nm' => '%' . $normRaison . '%'],
        'rows'   => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'remarque' => 'AUCUNE row attendue car le "&" est supprimé du pattern de recherche',
    ];
} catch (Throwable $e) { $report['ERR_4'] = $e->getMessage(); }

// ─── 5. Test : query AVEC ponctuation gardée (fix proposé) ──────────
$normSearchOk = em_normalize_text('MR & MME SABY'); // garde le "&"
try {
    $st = $pdo->prepare("SELECT id, nom, prenom, raison_sociale, nom_affichage, type_tiers
                         FROM tiers
                         WHERE LOWER(raison_sociale) LIKE :rs
                            OR LOWER(nom_affichage) LIKE :nm
                            OR LOWER(nom) LIKE :nn");
    $st->execute([':rs' => '%' . $normSearchOk . '%', ':nm' => '%' . $normSearchOk . '%', ':nn' => '%' . $normSearchOk . '%']);
    $report['5_simul_query_fix'] = [
        'pattern' => '%' . $normSearchOk . '%',
        'rows'    => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'remarque' => 'AVEC ponctuation gardée + scan multi-champs (raison_sociale, nom_affichage, nom)',
    ];
} catch (Throwable $e) { $report['ERR_5'] = $e->getMessage(); }

// ─── 6. Appel direct du moteur (état actuel buggé) ───────────────────
$resMoteur = em_match_tiers($pdo, ['raison_sociale' => 'MR & MME SABY']);
$report['6_em_match_tiers_actuel'] = [
    'input' => ['raison_sociale' => 'MR & MME SABY'],
    'found' => $resMoteur['found'],
    'count' => count($resMoteur['matches']),
    'confidence' => $resMoteur['confidence'],
    'best_id' => $resMoteur['best']['id'] ?? null,
];

// Avec 'societe' (mapping legacy)
$resMoteur2 = em_match_tiers($pdo, ['raison_sociale' => 'MR & MME SABY', 'societe' => 'MR & MME SABY']);
$report['6b_em_match_tiers_avec_societe'] = [
    'found' => $resMoteur2['found'],
    'count' => count($resMoteur2['matches']),
];

// ─── 7. Recherche tolérante manuelle (avec normalisation civilité) ──
// Test : si on remplace MR/MME/M./Madame par "", le pattern devient juste "saby"
$civiliteVariants = ['/\bmr\b/', '/\bmme\b/', '/\bm\.\b/', '/\bmonsieur\b/', '/\bmadame\b/', '/\bet\b/', '/&/'];
$saby = (string)preg_replace($civiliteVariants, '', strtolower('MR & MME SABY'));
$saby = trim((string)preg_replace('/\s+/', ' ', $saby));
$report['7_recherche_par_nom_apres_strip_civilites'] = [
    'after_strip_civilite' => $saby,
    'remarque'             => 'Si le moteur stripe les civilités/conjoints, on cherche juste "saby" → forte chance de matcher le tiers',
];
try {
    $st = $pdo->prepare("SELECT id, type_tiers, nom, prenom, raison_sociale, nom_affichage
                         FROM tiers
                         WHERE LOWER(nom) LIKE :n1 OR LOWER(raison_sociale) LIKE :n2 OR LOWER(nom_affichage) LIKE :n3
                         LIMIT 20");
    $patt = '%' . $saby . '%';
    $st->execute([':n1' => $patt, ':n2' => $patt, ':n3' => $patt]);
    $report['7_rows_apres_strip'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $report['ERR_7'] = $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Debug MR & MME SABY</title>
    <style>
        body { font-family: "DM Mono", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1200px; margin: 0 auto; }
        h1 { color: #fde68a; }
        h2 { color: #84a98c; font-size: 13px; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #334155; }
        pre { background: #1e293b; padding: 10px 14px; border-radius: 6px; overflow-x: auto; font-size: 11.5px; line-height: 1.55; }
        .verdict { background: #1e293b; padding: 18px 22px; border-radius: 8px; margin-top: 28px; border-left: 6px solid #f87171; }
    </style>
</head>
<body>

<h1>🔬 Debug ciblé : "MR & MME SABY"</h1>
<p>Pourquoi <code>em_match_tiers({"societe":"MR & MME SABY"})</code> retourne 0 alors que le proprio est lié à un tiers existant ?</p>

<?php foreach ($report as $key => $val): ?>
    <h2><?= htmlspecialchars($key) ?></h2>
    <pre><?= htmlspecialchars(is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
<?php endforeach; ?>

<?php
// Verdict dynamique : on relit la section 6 (état actuel du moteur) pour conclure
$found6 = (bool)($report['6_em_match_tiers_actuel']['found'] ?? false);
$bestId = $report['6_em_match_tiers_actuel']['best_id'] ?? null;
$resolved = $found6 && ($bestId === ($targetTiersId > 0 ? $targetTiersId : null));
?>
<div class="verdict" style="border-left-color: <?= $resolved ? '#84a98c' : '#f87171' ?> !important;">
    <h3 style="margin: 0 0 8px;"><?= $resolved ? '✅ RÉSOLU' : '❌ ENCORE BUGGÉ' ?> — état actuel du moteur</h3>

    <?php if ($resolved): ?>
        <p><strong>Le moteur trouve maintenant le bon tiers</strong> :</p>
        <ul>
            <li>Proprio #<?= (int)($targetProprio['id'] ?? 0) ?> "<?= htmlspecialchars((string)($targetProprio['societe'] ?? '')) ?>" → tiers #<?= (int)$targetTiersId ?> (cible attendue)</li>
            <li><code>em_match_tiers()</code> retourne <code>found=true</code>, <code>count=<?= (int)$report['6_em_match_tiers_actuel']['count'] ?></code>,
                <code>confidence=<?= (int)$report['6_em_match_tiers_actuel']['confidence'] ?></code>, <code>best_id=<?= (int)$bestId ?></code></li>
            <li>Match exact prime sur match famille (60 > 40)</li>
        </ul>
        <p><strong>Fixes appliqués</strong> :</p>
        <ol>
            <li><code>em_normalize_for_search()</code> garde la ponctuation (&, ', .) pour le pré-filtre SQL</li>
            <li>Pré-filtre scanne aussi <code>nom_affichage</code> (couples/indivisions)</li>
            <li><code>em_strip_civilites()</code> reconnaît "MR & MME SABY" ≡ "SABY" comme même famille</li>
            <li>Scoring : exact=60 > partiel=25 > famille=40 (l'exact prime systématiquement)</li>
        </ol>
        <p>Prochaine étape : lancer <a href="admin_entity_matcher_ab.php" style="color:#84a98c;">admin_entity_matcher_ab.php</a>
        avec <code>FEATURE_ENTITY_MATCHER=true</code> dans <code>inc/feature_flags.local.php</code> pour valider en conditions réelles.</p>
    <?php else: ?>
        <p>Voir sections 1-7 ci-dessus pour comprendre les écarts entre input et résultat.</p>
    <?php endif; ?>
</div>

</body>
</html>
