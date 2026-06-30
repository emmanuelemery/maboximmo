<?php
/**
 * admin/admin_migrate_legacy_to_ged.php
 *
 * Migration douce des tables legacy (biens_documents, bailleur_documents,
 * immeubles_documents) vers la GED CENTRALE UNIQUE (ged_documents + ged_document_links).
 *
 * Idempotent : skip si un ged_documents.metadata.extra.legacy_*_id correspondant
 * existe déjà (déduplication par référence croisée).
 *
 * Modes :
 *  - audit (default) : compte rows + estimation
 *  - dryrun : simule la migration sans INSERT
 *  - apply  : applique réellement (avec confirmation)
 *
 * Réservé super admin (id_role=1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_login();

$pdo = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$mode  = (string)($_GET['mode']  ?? 'audit');
$table = (string)($_GET['table'] ?? 'all'); // biens_documents | bailleur_documents | immeubles_documents | all
$limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));

$report = [];
$totals = ['scanned' => 0, 'already_migrated' => 0, 'created' => 0, 'errors' => 0, 'skipped_no_file' => 0];

function _migrate_biens_documents(PDO $pdo, int $limit, string $mode, array &$report, array &$totals): void {
    try {
        $rows = $pdo->query("SELECT bd.id, bd.id_bien, bd.type_document, bd.libelle, bd.url_fichier, bd.nom_original,
                                    bd.mime_type, bd.taille_octets, bd.date_document, bd.date_upload, bd.id_user_upload,
                                    b.id_societe, b.id_agence, b.id_proprietaire, b.id_immeuble,
                                    p.id_tiers AS proprio_tiers_id,
                                    s.raison_sociale AS soc_raison, a.code_agence, a.nom_agence
                             FROM biens_documents bd
                             LEFT JOIN biens b           ON b.id = bd.id_bien
                             LEFT JOIN proprietaires p   ON p.id = b.id_proprietaire
                             LEFT JOIN societes s        ON s.id = b.id_societe
                             LEFT JOIN agences  a        ON a.id = b.id_agence
                             ORDER BY bd.id ASC LIMIT $limit")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $report[] = "❌ biens_documents query: " . $e->getMessage();
        return;
    }

    foreach ($rows as $r) {
        $totals['scanned']++;
        $legacyId = (int)$r['id'];

        // Idempotence : skip si déjà migré (FIX 2026-05-25 — JSON_UNQUOTE pour matcher correctement
        // les valeurs JSON typées number/string avec un bind PDO INT)
        $st = $pdo->prepare("SELECT id FROM ged_documents
                              WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.extra.legacy_biens_doc_id')) = ? LIMIT 1");
        $st->execute([(string)$legacyId]);
        if ($st->fetchColumn()) {
            $totals['already_migrated']++;
            continue;
        }

        $absPath = dirname(__DIR__) . '/' . ltrim((string)$r['url_fichier'], '/');
        if (!is_file($absPath)) {
            $report[] = "⚠️ biens_documents #$legacyId : fichier absent ({$r['url_fichier']})";
            $totals['skipped_no_file']++;
            continue;
        }

        if ($mode === 'dryrun') {
            $report[] = "  [DRY] biens_documents #$legacyId → bien #" . (int)$r['id_bien'] . " ({$r['type_document']})";
            $totals['created']++;
            continue;
        }

        $socId = (int)($r['id_societe'] ?? 0) ?: 1;
        $links = [['entity_type' => 'BIEN', 'entity_id' => (int)$r['id_bien'], 'relation_type' => 'main']];
        if (!empty($r['proprio_tiers_id'])) $links[] = ['entity_type' => 'TIERS', 'entity_id' => (int)$r['proprio_tiers_id'], 'relation_type' => 'annexe'];
        if (!empty($r['id_immeuble']))     $links[] = ['entity_type' => 'IMB',   'entity_id' => (int)$r['id_immeuble'],     'relation_type' => 'annexe'];

        try {
            $res = gus_commit_document(
                $pdo,
                [
                    'path_on_disk'  => $absPath,
                    'name_original' => (string)($r['nom_original'] ?: basename($absPath)),
                    'mime_type'     => (string)($r['mime_type'] ?: 'application/pdf'),
                    'size_bytes'    => (int)($r['taille_octets'] ?: 0),
                    'public_url'    => (string)$r['url_fichier'],
                ],
                [
                    'document_type'  => strtoupper((string)$r['type_document']),
                    'source_module'  => '05_TRANSACTION',
                    'security_level' => 'interne',
                    'societe_id'     => $socId,
                    'agence_id'      => (int)($r['id_agence'] ?? 0) ?: 3,
                    'tenant_id'      => $socId,
                    'created_by'     => (int)($r['id_user_upload'] ?? 0) ?: null,
                    'storage_provider' => 'local',
                    'metadata_extra' => [
                        'titre_legacy'  => $r['libelle'] ?? null,
                        'classement'    => ['bien_id_bdd' => (int)$r['id_bien'], 'date_doc' => $r['date_document']],
                        'legacy_source' => 'migration_admin (Sprint 7D)',
                        'legacy_biens_doc_id' => $legacyId,
                        'legacy_date_upload'  => $r['date_upload'],
                    ],
                    'naming_ctx' => [
                        'societe_raison' => $r['soc_raison'] ?? 'Régie EMERY',
                        'agence_code'    => $r['code_agence'] ?? 'RE69-2',
                        'agence_nom'     => $r['nom_agence']  ?? 'LYON',
                        'user_id'        => $r['id_user_upload'],
                        'n1_slug'        => '05_gestion_locative',
                        'n2_slug'        => 'biens',
                        'n3_slug'        => strtolower((string)$r['type_document']),
                        'type_doc'       => strtoupper((string)$r['type_document']),
                        'entity_type'    => 'BIEN',
                        'entity_id'      => (int)$r['id_bien'],
                        'date_doc'       => $r['date_document'],
                        'source_filename'=> $r['nom_original'],
                    ],
                ],
                $links
            );
            if (!empty($res['ok'])) {
                $report[] = "  ✅ biens_documents #$legacyId → ged #{$res['doc_id']}";
                $totals['created']++;
            } else {
                $report[] = "  ❌ biens_documents #$legacyId : " . json_encode($res['errors'] ?? []);
                $totals['errors']++;
            }
        } catch (Throwable $e) {
            $report[] = "  ❌ biens_documents #$legacyId EXC: " . $e->getMessage();
            $totals['errors']++;
        }
    }
}

function _migrate_bailleur_documents(PDO $pdo, int $limit, string $mode, array &$report, array &$totals): void {
    try {
        // proprietaires n'a pas id_societe → passe par tiers
        $rows = $pdo->query("SELECT bd.id, bd.id_proprietaire, bd.id_immeuble, bd.id_bien, bd.type_document,
                                    bd.titre, bd.nom_fichier, bd.chemin_fichier, bd.annee, bd.trimestre,
                                    bd.taille, bd.mime_type, bd.uploaded_by, bd.date_upload, bd.commentaire,
                                    p.id_tiers, t.id_societe,
                                    s.raison_sociale AS soc_raison, a.code_agence, a.nom_agence
                             FROM bailleur_documents bd
                             LEFT JOIN proprietaires p ON p.id = bd.id_proprietaire
                             LEFT JOIN tiers         t ON t.id = p.id_tiers
                             LEFT JOIN societes      s ON s.id = t.id_societe
                             LEFT JOIN agences       a ON a.id = COALESCE(t.id_agence, p.id_agence)
                             ORDER BY bd.id ASC LIMIT $limit")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $report[] = "❌ bailleur_documents query: " . $e->getMessage();
        return;
    }

    foreach ($rows as $r) {
        $totals['scanned']++;
        $legacyId = (int)$r['id'];

        $st = $pdo->prepare("SELECT id FROM ged_documents
                              WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.extra.legacy_bailleur_doc_id')) = ? LIMIT 1");
        $st->execute([(string)$legacyId]);
        if ($st->fetchColumn()) {
            $totals['already_migrated']++;
            continue;
        }

        $absPath = dirname(__DIR__) . '/' . ltrim((string)$r['chemin_fichier'], '/');
        if (!is_file($absPath)) {
            $report[] = "⚠️ bailleur_documents #$legacyId : fichier absent ({$r['chemin_fichier']})";
            $totals['skipped_no_file']++;
            continue;
        }

        if ($mode === 'dryrun') {
            $report[] = "  [DRY] bailleur_documents #$legacyId → tiers #" . (int)($r['id_tiers'] ?? 0) . " ({$r['type_document']})";
            $totals['created']++;
            continue;
        }

        $tiersId = (int)($r['id_tiers'] ?? 0);
        if ($tiersId === 0) {
            $report[] = "⚠️ bailleur_documents #$legacyId : proprio sans id_tiers (skip)";
            $totals['errors']++;
            continue;
        }
        $socId = (int)($r['id_societe'] ?? 0) ?: 1;
        $links = [['entity_type' => 'TIERS', 'entity_id' => $tiersId, 'relation_type' => 'main']];
        if (!empty($r['id_immeuble'])) $links[] = ['entity_type' => 'IMB',  'entity_id' => (int)$r['id_immeuble'], 'relation_type' => 'annexe'];
        if (!empty($r['id_bien']))     $links[] = ['entity_type' => 'BIEN', 'entity_id' => (int)$r['id_bien'],     'relation_type' => 'annexe'];

        try {
            $res = gus_commit_document(
                $pdo,
                [
                    'path_on_disk'  => $absPath,
                    'name_original' => (string)($r['nom_fichier'] ?: basename($absPath)),
                    'mime_type'     => (string)($r['mime_type'] ?: 'application/octet-stream'),
                    'size_bytes'    => (int)($r['taille'] ?: 0),
                    'public_url'    => (string)$r['chemin_fichier'],
                ],
                [
                    'document_type'  => strtoupper((string)$r['type_document']),
                    'source_module'  => '03_GESTION_LOCATIVE',
                    'security_level' => 'interne',
                    'societe_id'     => $socId,
                    'tenant_id'      => $socId,
                    'created_by'     => (int)($r['uploaded_by'] ?? 0) ?: null,
                    'storage_provider' => 'local',
                    'metadata_extra' => [
                        'titre_user'   => $r['titre'] ?? null,
                        'commentaire'  => $r['commentaire'] ?? null,
                        'annee'        => $r['annee'] ?? null,
                        'trimestre'    => $r['trimestre'] ?? null,
                        'classement'   => ['tiers_proprio_id' => $tiersId, 'immeuble_id_bdd' => $r['id_immeuble'], 'bien_id_bdd' => $r['id_bien']],
                        'legacy_source' => 'migration_admin (Sprint 7D)',
                        'legacy_bailleur_doc_id' => $legacyId,
                        'legacy_bailleur_proprio_id' => $r['id_proprietaire'],
                        'legacy_date_upload' => $r['date_upload'],
                    ],
                    'naming_ctx' => [
                        'societe_raison' => $r['soc_raison'] ?? 'Régie EMERY',
                        'agence_code'    => $r['code_agence'] ?? 'RE69-2',
                        'agence_nom'     => $r['nom_agence']  ?? 'LYON',
                        'user_id'        => $r['uploaded_by'],
                        'n1_slug'        => '03_gestion_locative',
                        'n2_slug'        => 'proprietaires',
                        'n3_slug'        => strtolower((string)$r['type_document']),
                        'type_doc'       => strtoupper((string)$r['type_document']),
                        'entity_type'    => 'TIERS',
                        'entity_id'      => $tiersId,
                        'source_filename'=> $r['nom_fichier'],
                    ],
                ],
                $links
            );
            if (!empty($res['ok'])) {
                $report[] = "  ✅ bailleur_documents #$legacyId → ged #{$res['doc_id']}";
                $totals['created']++;
            } else {
                $report[] = "  ❌ bailleur_documents #$legacyId : " . json_encode($res['errors'] ?? []);
                $totals['errors']++;
            }
        } catch (Throwable $e) {
            $report[] = "  ❌ bailleur_documents #$legacyId EXC: " . $e->getMessage();
            $totals['errors']++;
        }
    }
}

function _migrate_immeubles_documents(PDO $pdo, int $limit, string $mode, array &$report, array &$totals): void {
    try {
        $rows = $pdo->query("SELECT id, id_immeuble, id_societe, id_agence, type_document, sous_type,
                                    nom_fichier, url_fichier, taille_octets, mime_type, id_user_created
                             FROM immeubles_documents
                             ORDER BY id ASC LIMIT $limit")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $report[] = "❌ immeubles_documents query: " . $e->getMessage();
        return;
    }
    foreach ($rows as $r) {
        $totals['scanned']++;
        $legacyId = (int)$r['id'];

        $st = $pdo->prepare("SELECT id FROM ged_documents
                              WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.extra.legacy_immeubles_doc_id')) = ? LIMIT 1");
        $st->execute([(string)$legacyId]);
        if ($st->fetchColumn()) {
            $totals['already_migrated']++;
            continue;
        }

        $absPath = dirname(__DIR__) . '/' . ltrim((string)$r['url_fichier'], '/');
        if (!is_file($absPath)) {
            $report[] = "⚠️ immeubles_documents #$legacyId : fichier absent";
            $totals['skipped_no_file']++;
            continue;
        }
        if ($mode === 'dryrun') {
            $report[] = "  [DRY] immeubles_documents #$legacyId → imb #" . (int)$r['id_immeuble'];
            $totals['created']++;
            continue;
        }
        $socId = (int)($r['id_societe'] ?? 0) ?: 1;
        try {
            $res = gus_commit_document(
                $pdo,
                [
                    'path_on_disk'  => $absPath,
                    'name_original' => (string)($r['nom_fichier'] ?: basename($absPath)),
                    'mime_type'     => (string)($r['mime_type'] ?: 'application/pdf'),
                    'size_bytes'    => (int)($r['taille_octets'] ?: 0),
                    'public_url'    => (string)$r['url_fichier'],
                ],
                [
                    'document_type'  => strtoupper((string)($r['sous_type'] ?: $r['type_document'])),
                    'source_module'  => '02_SYNDIC',
                    'security_level' => 'interne',
                    'societe_id'     => $socId,
                    'agence_id'      => (int)($r['id_agence'] ?? 0) ?: 3,
                    'tenant_id'      => $socId,
                    'created_by'     => (int)($r['id_user_created'] ?? 0) ?: null,
                    'storage_provider' => 'local',
                    'metadata_extra' => [
                        'classement'   => ['immeuble_id_bdd' => (int)$r['id_immeuble']],
                        'legacy_source' => 'migration_admin (Sprint 7D)',
                        'legacy_immeubles_doc_id' => $legacyId,
                    ],
                    'naming_ctx' => [
                        'user_id'        => $r['id_user_created'],
                        'n1_slug'        => '02_syndic',
                        'n2_slug'        => 'immeubles',
                        'n3_slug'        => strtolower((string)$r['type_document']),
                        'type_doc'       => strtoupper((string)($r['sous_type'] ?: $r['type_document'])),
                        'entity_type'    => 'IMB',
                        'entity_id'      => (int)$r['id_immeuble'],
                        'source_filename'=> $r['nom_fichier'],
                    ],
                ],
                [['entity_type' => 'IMB', 'entity_id' => (int)$r['id_immeuble'], 'relation_type' => 'main']]
            );
            if (!empty($res['ok'])) {
                $report[] = "  ✅ immeubles_documents #$legacyId → ged #{$res['doc_id']}";
                $totals['created']++;
            } else {
                $report[] = "  ❌ immeubles_documents #$legacyId : " . json_encode($res['errors'] ?? []);
                $totals['errors']++;
            }
        } catch (Throwable $e) {
            $report[] = "  ❌ immeubles_documents #$legacyId EXC: " . $e->getMessage();
            $totals['errors']++;
        }
    }
}

// ─── Compteurs audit ───
$counts = ['biens_documents' => 0, 'bailleur_documents' => 0, 'immeubles_documents' => 0];
foreach (array_keys($counts) as $t) {
    try {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    } catch (Throwable) {}
}

// ─── Exécution si dryrun/apply ───
if (in_array($mode, ['dryrun', 'apply'], true)) {
    if ($mode === 'apply' && ($_POST['confirm'] ?? '') !== 'MIGRATE') {
        $report[] = "⚠️ APPLY refusé : confirmation textuelle 'MIGRATE' manquante.";
        $mode = 'audit';
    } else {
        $report[] = "── Mode : " . strtoupper($mode) . " · table=$table · limit=$limit ──";
        if ($table === 'all' || $table === 'biens_documents')     _migrate_biens_documents($pdo, $limit, $mode, $report, $totals);
        if ($table === 'all' || $table === 'bailleur_documents')  _migrate_bailleur_documents($pdo, $limit, $mode, $report, $totals);
        if ($table === 'all' || $table === 'immeubles_documents') _migrate_immeubles_documents($pdo, $limit, $mode, $report, $totals);
    }
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.mig-wrap { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; }
.mig-wrap h1 { color: #243B5C; font-size: 22px; margin-bottom: 4px; }
.mig-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin: 16px 0; }
.mig-stat { background: #fff; padding: 12px; border-radius: 8px; text-align: center; border-left: 4px solid #D4A047; }
.mig-stat .v { font-size: 22px; font-weight: 800; color: #2c2a28; }
.mig-stat .l { font-size: 10px; color: #7a766f; text-transform: uppercase; }
.mig-actions { display: flex; gap: 10px; margin: 16px 0; flex-wrap: wrap; align-items: center; }
.mig-btn { padding: 10px 20px; border: 0; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; text-decoration: none; }
.mig-dry { background: #60a5fa; color: #fff; }
.mig-apply { background: #16a34a; color: #fff; }
.mig-back { background: #e3dfd8; color: #2c2a28; }
.mig-log { background: #0f172a; color: #d1d5db; padding: 14px; border-radius: 8px; font-size: 11px; line-height: 1.5; white-space: pre-wrap; max-height: 600px; overflow-y: auto; }
.mig-counts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 12px 0; }
.mig-count { background: #fff; padding: 10px 14px; border-radius: 6px; font-size: 13px; }
</style>
<div class="mig-wrap">
    <h1>📦 Migration douce legacy → GED CENTRALE</h1>
    <p style="color: #7a766f; font-size: 13px;">
        Migration idempotente des tables legacy vers <code>ged_documents</code> + <code>ged_document_links</code>.
        Marqueur croisé <code>metadata.extra.legacy_*_doc_id</code> pour éviter les doublons.
        Fichiers physiques préservés sur disque, juste référencés dans la GED.
    </p>

    <div class="mig-counts">
        <div class="mig-count"><strong>biens_documents</strong> : <?= number_format($counts['biens_documents'], 0, '.', ' ') ?> rows</div>
        <div class="mig-count"><strong>bailleur_documents</strong> : <?= number_format($counts['bailleur_documents'], 0, '.', ' ') ?> rows</div>
        <div class="mig-count"><strong>immeubles_documents</strong> : <?= number_format($counts['immeubles_documents'], 0, '.', ' ') ?> rows</div>
    </div>

    <?php if (in_array($mode, ['dryrun', 'apply'], true) && !empty($report)): ?>
    <div class="mig-stats">
        <div class="mig-stat"><div class="v"><?= $totals['scanned'] ?></div><div class="l">Scannés</div></div>
        <div class="mig-stat"><div class="v"><?= $totals['already_migrated'] ?></div><div class="l">Déjà migrés</div></div>
        <div class="mig-stat" style="border-left-color: #16a34a;"><div class="v"><?= $totals['created'] ?></div><div class="l">Créés</div></div>
        <div class="mig-stat" style="border-left-color: #f59e0b;"><div class="v"><?= $totals['skipped_no_file'] ?></div><div class="l">Fichier absent</div></div>
        <div class="mig-stat" style="border-left-color: <?= $totals['errors'] > 0 ? '#dc2626' : '#2d6a35' ?>;"><div class="v"><?= $totals['errors'] ?></div><div class="l">Erreurs</div></div>
    </div>
    <?php endif; ?>

    <form method="GET" class="mig-actions">
        <label>Table : </label>
        <select name="table">
            <?php foreach (['all','biens_documents','bailleur_documents','immeubles_documents'] as $t): ?>
                <option value="<?= $t ?>" <?= $table === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
        <label>Limit : </label>
        <input type="number" name="limit" value="<?= (int)$limit ?>" min="1" max="500" style="width:80px;padding:4px;">
        <button type="submit" name="mode" value="dryrun" class="mig-btn mig-dry">👁️ Dry-run</button>
    </form>

    <?php if (in_array($mode, ['dryrun']) && $totals['created'] > 0): ?>
    <form method="POST" action="?mode=apply&table=<?= $h($table) ?>&limit=<?= (int)$limit ?>"
          onsubmit="return confirm('⚠️ APPLY va créer ' + <?= (int)$totals['created'] ?> + ' ged_documents. Continuer ?')">
        <input type="hidden" name="confirm" value="MIGRATE">
        <button type="submit" class="mig-btn mig-apply">🔥 APPLY (créer <?= (int)$totals['created'] ?> docs en GED)</button>
        <a href="admin_audit_documents_entite.php" class="mig-btn mig-back">→ Page audit par entité</a>
    </form>
    <?php endif; ?>

    <?php if (!empty($report)): ?>
    <h3 style="font-size: 14px; color: #243B5C; margin-top: 20px;">📋 Journal</h3>
    <div class="mig-log"><?php foreach ($report as $l) echo $h($l) . "\n"; ?></div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 14px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047; font-size: 12px;">
        <strong>📚 Note</strong> — Une fois la migration appliquée, les rows legacy restent en BDD (audit/rollback possible).
        La purge définitive des tables legacy se fera dans Sprint 7E/F après validation finale.
    </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
