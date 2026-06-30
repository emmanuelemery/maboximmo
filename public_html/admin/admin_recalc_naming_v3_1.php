<?php
/**
 * admin/admin_recalc_naming_v3_1.php
 *
 * Page admin pour recalculer les noms V3.1 cassés en BDD :
 *  - fluxbox_cartes.naming_proposed commençant par SOC_AGE_ (ancien format)
 *  - ged_documents.name_file commençant par SOC_AGE_ ou par -_-_ (segments société/agence non résolus)
 *
 * Dry-run obligatoire avant Apply. Réservé super admin (id_role=1).
 *
 * Réf : Sprint Pipeline Auto 2026-05-26 (post-correction code V3.1)
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ged_doc_naming_v3.php';
require_once __DIR__ . '/../inc/fluxbox_va_orchestrator.php';
require_login();

$pdo = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$mode  = (string)($_GET['mode']  ?? 'dryrun'); // dryrun | apply
$scope = (string)($_GET['scope'] ?? 'all');    // all | cartes | docs
$log = [];
$totals = ['cartes_fixed' => 0, 'cartes_skipped' => 0, 'docs_fixed' => 0, 'docs_skipped' => 0, 'errors' => 0];

// ─── 1. fluxbox_cartes : recalcul via fluxbox_va_compute_v3_1_name() ──
if (in_array($scope, ['all', 'cartes'], true)) {
    $cartes = $pdo->query("SELECT id, naming_proposed FROM fluxbox_cartes
                           WHERE naming_proposed LIKE 'SOC_AGE_%' OR naming_proposed LIKE 'soc_age_%'
                              OR naming_proposed LIKE '-_-_%'
                              OR naming_proposed IS NULL OR naming_proposed = ''
                           ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $log[] = "── " . count($cartes) . " cartes à recalculer (naming_proposed cassé/vide) ──";
    foreach ($cartes as $c) {
        $cid = (int)$c['id'];
        $old = (string)$c['naming_proposed'];
        try {
            $v3 = fluxbox_va_compute_v3_1_name($cid, $pdo);
            $new = $v3['name'] ?? '';
            if ($new === '') { $totals['cartes_skipped']++; continue; }
            if ($new === $old) { $totals['cartes_skipped']++; continue; }
            if ($mode === 'apply') {
                $pdo->prepare("UPDATE fluxbox_cartes SET naming_proposed = ? WHERE id = ?")
                    ->execute([$new, $cid]);
                $log[] = "  ✅ carte #$cid : $new";
            } else {
                $log[] = "  [DRY] carte #$cid : $new  (était : " . ($old ?: 'VIDE') . ")";
            }
            $totals['cartes_fixed']++;
        } catch (Throwable $e) {
            $log[] = "  ❌ carte #$cid : " . $e->getMessage();
            $totals['errors']++;
        }
    }
}

// ─── 2. ged_documents : recalcul name_file via gdn_v3_build() ──
if (in_array($scope, ['all', 'docs'], true)) {
    $docs = $pdo->query("SELECT ge.id, ge.name_file, ge.document_type, ge.tenant_id, ge.societe_id, ge.agence_id,
                                ge.fluxbox_source_id, l.entity_id AS bien_id
                         FROM ged_documents ge
                         LEFT JOIN ged_document_links l ON l.document_id = ge.id AND l.entity_type = 'BIEN'
                         WHERE ge.status = 'active'
                           AND (ge.name_file LIKE 'SOC_AGE_%' OR ge.name_file LIKE '-_-_%')
                         GROUP BY ge.id
                         ORDER BY ge.id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $log[] = "";
    $log[] = "── " . count($docs) . " ged_documents à recalculer (name_file SOC_AGE_ ou -_-_) ──";

    foreach ($docs as $d) {
        $did = (int)$d['id'];
        $old = (string)$d['name_file'];
        try {
            $bid = (int)($d['bien_id'] ?? 0);
            $soc = (int)($d['societe_id'] ?? 1) ?: 1;
            $age = (int)($d['agence_id']  ?? 3) ?: 3;

            $stSoc = $pdo->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
            $stSoc->execute([$soc]);
            $socName = (string)$stSoc->fetchColumn();
            $stAge = $pdo->prepare("SELECT code_agence FROM agences WHERE id = ?");
            $stAge->execute([$age]);
            $ageCode = (string)$stAge->fetchColumn();

            // N1 contextuel
            $n1 = '05_gestion_locative';
            if ($bid) {
                $stBail = $pdo->prepare("SELECT 1 FROM baux WHERE id_bien = ? AND statut='actif' LIMIT 1");
                $stBail->execute([$bid]);
                if (!$stBail->fetchColumn()) {
                    $stM = $pdo->prepare("SELECT type_mandat FROM mandats WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
                    $stM->execute([$bid]);
                    $mt = strtolower((string)$stM->fetchColumn());
                    if (str_contains($mt, 'vente') || str_contains($mt, 'exclusif')) $n1 = '06_transaction';
                }
            }

            $new = gdn_v3_build([
                'societe_raison' => $socName ?: 'Régie EMERY',
                'agence_code'    => $ageCode ?: 'RE69-2',
                'user_id'        => 8,
                'n1_slug'        => $n1,
                'type_doc'       => $d['document_type'] ?: 'document',
                'entity_type'    => $bid ? 'BIEN' : null,
                'entity_id'      => $bid ?: null,
                'source_filename'=> $old,
            ]);

            if ($new === $old) { $totals['docs_skipped']++; continue; }

            if ($mode === 'apply') {
                $pdo->prepare("UPDATE ged_documents SET name_file = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new, $did]);
                $log[] = "  ✅ doc #$did : $new";
            } else {
                $log[] = "  [DRY] doc #$did : $new  (était : $old)";
            }
            $totals['docs_fixed']++;
        } catch (Throwable $e) {
            $log[] = "  ❌ doc #$did : " . $e->getMessage();
            $totals['errors']++;
        }
    }
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.rcnv-wrap { max-width: 1100px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; }
.rcnv-wrap h1 { color: #243B5C; font-size: 22px; margin-bottom: 4px; }
.rcnv-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin: 16px 0; }
.rcnv-stat { background: #fff; padding: 12px; border-radius: 8px; box-shadow: 2px 2px 6px #e3dfd8; text-align: center; border-left: 4px solid #D4A047; }
.rcnv-stat .v { font-size: 24px; font-weight: 800; color: #2c2a28; }
.rcnv-stat .l { font-size: 10px; color: #7a766f; text-transform: uppercase; }
.rcnv-actions { display: flex; gap: 10px; margin: 16px 0; }
.rcnv-btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; }
.rcnv-btn-dry { background: #60a5fa; color: #fff; }
.rcnv-btn-apply { background: #2d6a35; color: #fff; }
.rcnv-btn-back { background: #e3dfd8; color: #2c2a28; }
.rcnv-log { background: #0f172a; color: #d1d5db; padding: 16px; border-radius: 8px; font-size: 12px; line-height: 1.5; max-height: 600px; overflow-y: auto; white-space: pre-wrap; }
.rcnv-mode { background: <?= $mode === 'apply' ? '#fef3c7' : '#dbeafe' ?>; padding: 10px 16px; border-radius: 8px; font-weight: 700; margin-bottom: 12px; }
</style>

<div class="rcnv-wrap">
    <h1>🔄 Recalcul noms V3.1 cassés</h1>
    <p style="color: #7a766f; font-size: 13px;">
        Fixe les <code>fluxbox_cartes.naming_proposed</code> et <code>ged_documents.name_file</code>
        qui commencent par <code>SOC_AGE_</code> ou <code>-_-_</code> (segments société/agence non résolus).
        Utilise <code>fluxbox_va_compute_v3_1_name()</code> et <code>gdn_v3_build()</code> avec cascade fallback Régie EMERY/LYON.
    </p>

    <div class="rcnv-mode">
        Mode actuel : <?= $mode === 'apply' ? '🔥 APPLY (écriture BDD)' : '👁️ DRY-RUN (lecture seule)' ?>
        · Scope : <?= $h($scope) ?>
    </div>

    <div class="rcnv-stats">
        <div class="rcnv-stat"><div class="v"><?= $totals['cartes_fixed'] ?></div><div class="l">Cartes fixées</div></div>
        <div class="rcnv-stat"><div class="v"><?= $totals['cartes_skipped'] ?></div><div class="l">Cartes skip</div></div>
        <div class="rcnv-stat"><div class="v"><?= $totals['docs_fixed'] ?></div><div class="l">Docs fixés</div></div>
        <div class="rcnv-stat"><div class="v"><?= $totals['docs_skipped'] ?></div><div class="l">Docs skip</div></div>
        <div class="rcnv-stat" style="border-left-color: <?= $totals['errors'] > 0 ? '#dc2626' : '#2d6a35' ?>;">
            <div class="v"><?= $totals['errors'] ?></div><div class="l">Erreurs</div>
        </div>
    </div>

    <div class="rcnv-actions">
        <a href="?mode=dryrun&scope=all" class="rcnv-btn rcnv-btn-dry">👁️ Dry-run TOUT</a>
        <a href="?mode=dryrun&scope=cartes" class="rcnv-btn rcnv-btn-dry">👁️ Dry-run cartes seulement</a>
        <a href="?mode=dryrun&scope=docs" class="rcnv-btn rcnv-btn-dry">👁️ Dry-run docs seulement</a>
        <?php if ($mode === 'apply'): ?>
            <span style="color: #dc2626; font-weight: 700; padding: 10px;">⚠️ APPLY exécuté ci-dessus</span>
        <?php else: ?>
            <a href="?mode=apply&scope=<?= $h($scope) ?>" class="rcnv-btn rcnv-btn-apply"
               onclick="return confirm('⚠️ APPLY va modifier la BDD. Le backup mysqldump est-il fait ?');">
                🔥 APPLY (modifier BDD)
            </a>
        <?php endif; ?>
        <a href="admin_migrations.php" class="rcnv-btn rcnv-btn-back">← Retour migrations</a>
    </div>

    <div class="rcnv-log"><?php foreach ($log as $line) echo $h($line) . "\n"; ?></div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
