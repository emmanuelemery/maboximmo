<?php
// ═══════════════════════════════════════════════════════════════════════
// MaBoxImmo — Dédoublonnage des tiers après backfill initial
// Créé le 2026-04-19 · Exécuté sur dev le 2026-04-19
// ═══════════════════════════════════════════════════════════════════════
// Problème corrigé : le backfill initial de Phase 1 a mergé les proprietaires
// et mandants "sans identité" (champs nom/prenom/email vides) vers le même
// tiers à cause du COALESCE. Résultat avant dédup : 22 tiers pour 311 propri
// et 637 tiers pour 645 mandants.
//
// Règle métier : chaque ligne `proprietaires` et chaque ligne `mandants`
// doit avoir SON PROPRE tiers. Pas de fusion sur champs vides.
//
// Usage :
//   php sql/migration_tiers_dedup.php [proprietaires|mandants|all|clean]
//   - proprietaires : dédoublonne seulement la table proprietaires
//   - mandants      : dédoublonne seulement la table mandants
//   - all           : proprietaires puis mandants puis clean (recommandé)
//   - clean         : supprime les tiers orphelins (créés puis non liés)
//
// ORDRE EN PRODUCTION après backfill Phase 1 :
//   1. php sql/migration_tiers_dedup.php all
// ═══════════════════════════════════════════════════════════════════════

declare(strict_types=1);

// Chargement de la config DB via db_config.php (racine projet)
$cfg = dirname(__DIR__) . '/db_config.php';
if (!is_file($cfg)) {
    fwrite(STDERR, "ERREUR : db_config.php introuvable à la racine du projet.\n");
    exit(1);
}
require_once $cfg;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cmd = $argv[1] ?? 'all';

function dedupTable(PDO $pdo, string $table): void
{
    $roleCode = $table === 'proprietaires' ? 'proprietaire' : 'mandant';
    echo "=== Analyse — $table ===\n";
    $st = $pdo->query("
        SELECT id_tiers, COUNT(*) AS n
        FROM `$table`
        WHERE id_tiers IS NOT NULL
        GROUP BY id_tiers
        HAVING n > 1
        ORDER BY n DESC
    ");
    $groupes = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($groupes as $g) {
        echo "  Tiers #{$g['id_tiers']} → {$g['n']} lignes (séparer " . ($g['n']-1) . ")\n";
        $total += (int)$g['n'] - 1;
    }
    echo "Total à déplacer : $total\n\n";
    if ($total === 0) { echo "Rien à faire pour $table.\n\n"; return; }

    $pdo->beginTransaction();
    try {
        if ($table === 'proprietaires') {
            $insert = $pdo->prepare("
                INSERT INTO tiers
                    (id_agence, type_tiers, civilite, nom, prenom, raison_sociale,
                     email, telephone, telephone_secondaire,
                     adresse_ligne1, adresse_ligne2, code_postal, ville, pays,
                     commentaire, notes_internes, actif,
                     date_creation, date_modification, source_creation)
                SELECT p.id_agence,
                       CASE WHEN p.type_personne='morale' THEN 'personne_morale' ELSE 'personne_physique' END,
                       p.civilite, p.nom, p.prenom, p.societe,
                       p.email, p.telephone, p.telephone_2,
                       p.adresse_1, p.adresse_2, p.code_postal, p.ville, p.pays,
                       p.commentaire, p.notes_internes, p.actif,
                       p.date_creation, p.date_modification, 'dedup_proprietaires'
                FROM proprietaires p WHERE p.id = :row_id
            ");
        } else {
            $insert = $pdo->prepare("
                INSERT INTO tiers
                    (type_tiers, civilite, nom, prenom, email, telephone,
                     adresse_ligne1, code_postal, ville, commentaire, actif, source_creation)
                SELECT 'personne_physique', m.civilite, m.nom, m.prenom, m.email, m.telephone,
                       m.adresse, m.code_postal, m.ville, m.commentaire, 1, 'dedup_mandants'
                FROM mandants m WHERE m.id = :row_id
            ");
        }
        $updateRow  = $pdo->prepare("UPDATE `$table` SET id_tiers = :new_tiers WHERE id = :row_id");
        $insertRole = $pdo->prepare("
            INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif)
            VALUES (:id_tiers, '$roleCode', NULL, NULL, :actif)
        ");

        $actifCol = $table === 'proprietaires' ? 'actif' : '1 AS actif';
        $nb = 0;
        foreach ($groupes as $g) {
            $stR = $pdo->prepare("SELECT id, $actifCol FROM `$table` WHERE id_tiers = ? ORDER BY id ASC");
            $stR->execute([$g['id_tiers']]);
            $rows = $stR->fetchAll(PDO::FETCH_ASSOC);
            array_shift($rows);  // garde le 1er sur le tiers actuel
            foreach ($rows as $r) {
                $insert->execute([':row_id' => $r['id']]);
                $newId = (int)$pdo->lastInsertId();
                $updateRow->execute([':new_tiers' => $newId, ':row_id' => $r['id']]);
                $insertRole->execute([':id_tiers' => $newId, ':actif' => (int)($r['actif'] ?? 1)]);
                $nb++;
            }
        }

        // Nettoyage des doublons tiers_roles (NULL objet_type ne respecte pas UNIQUE)
        $pdo->exec("
            DELETE tr1 FROM tiers_roles tr1
            INNER JOIN tiers_roles tr2
              ON tr1.id_tiers = tr2.id_tiers
              AND tr1.role_code = tr2.role_code
              AND tr1.objet_type IS NULL AND tr2.objet_type IS NULL
              AND tr1.id > tr2.id
        ");

        $pdo->commit();
        echo "✓ $nb nouveaux tiers créés pour $table\n\n";
    } catch (Throwable $ex) {
        $pdo->rollBack();
        echo "✗ ERREUR $table : " . $ex->getMessage() . "\n";
        exit(1);
    }
}

function cleanOrphans(PDO $pdo): void
{
    echo "=== Nettoyage tiers orphelins ===\n";
    $sqlWhere = "
        NOT EXISTS (SELECT 1 FROM proprietaires WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM mandants WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM agency_mandant WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM tiers_roles WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM tiers_contacts WHERE id_tiers_entite=t.id OR id_tiers_contact=t.id)
        AND NOT EXISTS (SELECT 1 FROM user_tiers WHERE id_tiers=t.id)
    ";
    $nb = (int)$pdo->query("SELECT COUNT(*) FROM tiers t WHERE $sqlWhere")->fetchColumn();
    echo "  Orphelins identifiés : $nb\n";
    if ($nb > 0) {
        $pdo->exec("DELETE t FROM tiers t WHERE $sqlWhere");
        echo "  ✓ $nb tiers orphelins supprimés\n";
    }
    echo "\n";
}

if (!in_array($cmd, ['proprietaires','mandants','all','clean'], true)) {
    echo "Usage : php migration_tiers_dedup.php [proprietaires|mandants|all|clean]\n";
    exit(1);
}

if ($cmd === 'all' || $cmd === 'proprietaires') dedupTable($pdo, 'proprietaires');
if ($cmd === 'all' || $cmd === 'mandants')      dedupTable($pdo, 'mandants');
if ($cmd === 'all' || $cmd === 'clean')          cleanOrphans($pdo);

echo "=== État final ===\n";
$stats = $pdo->query("
    SELECT 'tiers total' AS k, COUNT(*) AS v FROM tiers
    UNION ALL SELECT 'proprietaires liés', COUNT(*) FROM proprietaires WHERE id_tiers IS NOT NULL
    UNION ALL SELECT 'tiers distincts/proprio', COUNT(DISTINCT id_tiers) FROM proprietaires
    UNION ALL SELECT 'mandants liés', COUNT(*) FROM mandants WHERE id_tiers IS NOT NULL
    UNION ALL SELECT 'tiers distincts/mandant', COUNT(DISTINCT id_tiers) FROM mandants
    UNION ALL SELECT 'roles proprietaire', COUNT(*) FROM tiers_roles WHERE role_code='proprietaire'
    UNION ALL SELECT 'roles mandant', COUNT(*) FROM tiers_roles WHERE role_code='mandant'
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($stats as $s) echo sprintf("  %-28s : %d\n", $s['k'], $s['v']);
