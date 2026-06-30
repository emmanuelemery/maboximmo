<?php
/**
 * admin/admin_ingestion_dashboard.php
 *
 * Tableau de bord ingestion FluxBox : voir tous les uploads récents avec
 * statut (auto-commit / review / erreur / doublon), entité concernée,
 * nom V3.1, IA confidence, classement GED, enrichissements appliqués.
 *
 * Réf : Sprint Objectif Ultime 2026-05-25 (étape 4/4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/fiche_360_layout.php';
require_login();

$pdo = $GLOBALS['pdo'];
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);

// Filtres GET
$filterDays = max(1, min(90, (int)($_GET['days'] ?? 7)));
$filterStatus = (string)($_GET['statut'] ?? 'all'); // all|pending|validated|auto

if (!function_exists('aid_html')) {
    function aid_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// ─── Lecture cartes récentes ──
$sql = "
    SELECT c.id AS carte_id, c.titre, c.statut, c.naming_status, c.naming_proposed,
           c.confiance_ia, c.created_at, c.proposition_json,
           d.id AS doc_id, d.fichier_nom, d.hash_sha256, d.taille_octets,
           ge.id AS ged_doc_id, ge.name_file AS ged_name,
           (SELECT confidence FROM ia_extract_cache WHERE hash_sha256 = d.hash_sha256 ORDER BY last_at DESC LIMIT 1) AS ia_cache_conf,
           (SELECT COUNT(*) FROM ged_document_links WHERE document_id = ge.id) AS nb_links
    FROM fluxbox_cartes c
    LEFT JOIN fluxbox_documents d ON d.id = c.document_id
    LEFT JOIN ged_documents ge ON ge.fluxbox_source_id = d.id AND ge.status = 'active'
    WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
";
$params = [':days' => $filterDays];
if ($filterStatus === 'pending')        { $sql .= " AND c.statut IN ('pending','in_progress')"; }
elseif ($filterStatus === 'validated')  { $sql .= " AND c.statut = 'validated'"; }
elseif ($filterStatus === 'auto')       { $sql .= " AND ge.id IS NOT NULL AND c.naming_applied_at IS NOT NULL"; }
$sql .= " ORDER BY c.created_at DESC LIMIT 200";

$st = $pdo->prepare($sql);
$st->execute($params);
$cards = $st->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'total' => count($cards),
    'auto_commited' => 0,
    'pending' => 0,
    'validated_manual' => 0,
    'with_links' => 0,
];
foreach ($cards as $c) {
    if ($c['ged_doc_id'] && $c['statut'] === 'validated' && $c['naming_status'] === 'applied') $stats['auto_commited']++;
    elseif ($c['statut'] === 'pending' || $c['statut'] === 'in_progress') $stats['pending']++;
    elseif ($c['statut'] === 'validated') $stats['validated_manual']++;
    if ($c['ged_doc_id'] && $c['nb_links'] > 0) $stats['with_links']++;
}

// Actions IA récentes (audit trail) — Fix P2 (2026-05-26) : affiche TOUS les types
// (classement_ged, workflow, auto_commit_ged, enrich_bien, enrich_mandat, etc.)
$auditRecent = [];
try {
    $st = $pdo->query("SELECT action_type, action_label, confiance, created_at, carte_id
                       FROM fluxbox_actions_ia
                       WHERE created_at >= DATE_SUB(NOW(), INTERVAL $filterDays DAY)
                       ORDER BY created_at DESC LIMIT 30");
    $auditRecent = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$pageTitle    = '📊 Dashboard Ingestion';
$pageSubtitle = 'Suivi des uploads + auto-commit GED — ' . $filterDays . ' derniers jours';
$extraCss     = fiche360_css();
include __DIR__ . '/../inc/agency_layout_top.php';
?>

<style>
.aid-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
.aid-stat { background: #fff; padding: 14px 16px; border-radius: 10px; box-shadow: 2px 2px 6px #e3dfd8; border-left: 4px solid #D4A047; }
.aid-stat .v { font-size: 26px; font-weight: 800; color: #2c2a28; line-height: 1; }
.aid-stat .l { font-size: 10.5px; color: #7a766f; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }
.aid-stat.green  { border-left-color: #2d6a35; }
.aid-stat.orange { border-left-color: #f59e0b; }
.aid-stat.blue   { border-left-color: #60a5fa; }

.aid-filter { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; padding: 10px 14px; background: #fff; border-radius: 10px; box-shadow: 2px 2px 6px #e3dfd8; }
.aid-filter a { padding: 4px 12px; border-radius: 99px; font-size: 12px; text-decoration: none; color: #5a5650; border: 1px solid #e3dfd8; }
.aid-filter a:hover { background: #fef3c7; border-color: #D4A047; color: #92400e; }
.aid-filter a.active { background: #D4A047; color: #fff; border-color: #D4A047; font-weight: 700; }

.aid-table { background: #fff; border-radius: 10px; padding: 14px 18px; margin-bottom: 14px; box-shadow: 2px 2px 6px #e3dfd8; }
.aid-table h3 { margin: 0 0 10px; font-size: 13px; }
.aid-table table { width: 100%; border-collapse: collapse; font-size: 12px; }
.aid-table th { text-align: left; padding: 8px 6px; border-bottom: 2px solid #e3dfd8; font-size: 10.5px; text-transform: uppercase; color: #7a766f; }
.aid-table td { padding: 8px 6px; border-bottom: 1px solid #f4f1ec; }
.aid-table tr:hover { background: #fafaf6; }
.aid-table .name { font-weight: 700; color: #2c2a28; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
.aid-table .name a { color: #1e40af; text-decoration: none; }
.aid-table .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; }
.aid-table .badge.auto { background: #d9f0db; color: #14532d; }
.aid-table .badge.pending { background: #fef3c7; color: #92400e; }
.aid-table .badge.validated { background: #dbeafe; color: #1e40af; }
.aid-table .badge.error { background: #fecaca; color: #7f1d1d; }
.aid-table .conf { font-weight: 700; }
.aid-table .conf.high { color: #14532d; }
.aid-table .conf.mid  { color: #92400e; }
.aid-table .conf.low  { color: #7f1d1d; }
.aid-table .meta { font-size: 10.5px; color: #7a766f; }
.aid-naming { font-family: 'DM Mono', monospace; font-size: 10.5px; color: #2c2a28; background: #fef3c7; padding: 1px 6px; border-radius: 4px; }

.aid-audit-row { display: flex; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f4f1ec; font-size: 11.5px; }
.aid-audit-row .when { color: #9a9690; font-size: 10.5px; min-width: 120px; }
.aid-audit-row .type { font-weight: 700; min-width: 110px; }
.aid-audit-row .label { flex: 1; }
</style>

<?php
fiche360_breadcrumb([
    ['icon' => '⚙️', 'label' => 'Admin', 'url' => app_url('/dashboard_admin.php')],
    ['icon' => '📊', 'label' => 'Ingestion Dashboard', 'url' => null],
], 'Administration');

fiche360_header(
    '📊',
    'Dashboard Ingestion FluxBox',
    ['label' => $stats['total'] . ' upload(s)', 'class' => 'actif'],
    'Suivi auto-commit + enrichissements · ' . $filterDays . ' derniers jours',
    [
        ['icon' => '🤖', 'text' => $stats['auto_commited'] . ' auto-commit'],
        ['icon' => '⏳', 'text' => $stats['pending'] . ' en attente'],
    ],
    [
        ['label' => '← Admin', 'url' => app_url('/dashboard_admin.php'), 'class' => 'tr-btn'],
    ]
);
?>

<div class="aid-stats">
    <div class="aid-stat green">
        <div class="v"><?= $stats['auto_commited'] ?></div>
        <div class="l">Auto-commit GED</div>
    </div>
    <div class="aid-stat orange">
        <div class="v"><?= $stats['pending'] ?></div>
        <div class="l">En attente review</div>
    </div>
    <div class="aid-stat blue">
        <div class="v"><?= $stats['validated_manual'] ?></div>
        <div class="l">Validés manuellement</div>
    </div>
    <div class="aid-stat">
        <div class="v"><?= $stats['total'] ?></div>
        <div class="l">Total uploads</div>
    </div>
</div>

<?php
// Fix B6 (2026-05-26) : URL explicite vers admin_ingestion_dashboard.php pour éviter
// les ambiguïtés JS click() qui interprétaient "?days=..." comme URL absolue racine.
$_aidBase = function_exists('app_url') ? app_url('/admin/admin_ingestion_dashboard.php') : 'admin_ingestion_dashboard.php';
?>
<div class="aid-filter">
    <span class="meta">Filtres :</span>
    <a href="<?= aid_html($_aidBase) ?>?days=1&statut=<?= aid_html($filterStatus) ?>" class="<?= $filterDays==1?'active':'' ?>">1 jour</a>
    <a href="<?= aid_html($_aidBase) ?>?days=7&statut=<?= aid_html($filterStatus) ?>" class="<?= $filterDays==7?'active':'' ?>">7 jours</a>
    <a href="<?= aid_html($_aidBase) ?>?days=30&statut=<?= aid_html($filterStatus) ?>" class="<?= $filterDays==30?'active':'' ?>">30 jours</a>
    <span style="margin: 0 8px; color: #c8c4be;">|</span>
    <a href="<?= aid_html($_aidBase) ?>?days=<?= $filterDays ?>&statut=all" class="<?= $filterStatus=='all'?'active':'' ?>">Tous</a>
    <a href="<?= aid_html($_aidBase) ?>?days=<?= $filterDays ?>&statut=auto" class="<?= $filterStatus=='auto'?'active':'' ?>">🤖 Auto</a>
    <a href="<?= aid_html($_aidBase) ?>?days=<?= $filterDays ?>&statut=pending" class="<?= $filterStatus=='pending'?'active':'' ?>">⏳ Pending</a>
    <a href="<?= aid_html($_aidBase) ?>?days=<?= $filterDays ?>&statut=validated" class="<?= $filterStatus=='validated'?'active':'' ?>">✓ Validés</a>
</div>

<div class="aid-table">
    <h3>📥 Derniers uploads (<?= count($cards) ?>)</h3>
    <table>
        <thead>
            <tr>
                <th>#carte</th>
                <th>Document</th>
                <th>Nom V3</th>
                <th>IA</th>
                <th>Statut</th>
                <th>GED</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cards as $c):
                $prop = !empty($c['proposition_json']) ? json_decode((string)$c['proposition_json'], true) : [];
                $bienId = $prop['bien_id'] ?? null;
                // Fix P1-2 (2026-05-26) : confiance_ia est stockée en % entier (92.00) PAS en décimal (0.92)
                $iaConf = max((int)round((float)($c['confiance_ia'] ?? 0)), (int)$c['ia_cache_conf']);
                $iaClass = $iaConf >= 80 ? 'high' : ($iaConf >= 50 ? 'mid' : 'low');
                $isAuto = $c['ged_doc_id'] && $c['statut'] === 'validated' && $c['naming_status'] === 'applied';
                $statusBadge = $isAuto ? 'auto' : ($c['statut'] === 'validated' ? 'validated' : ($c['statut'] === 'pending' ? 'pending' : 'error'));
                $statusLabel = $isAuto ? '🤖 auto' : ($c['statut'] === 'validated' ? '✓ validé' : ($c['statut'] === 'pending' ? '⏳ pending' : $c['statut']));
            ?>
                <tr>
                    <td>#<?= (int)$c['carte_id'] ?></td>
                    <td class="name" title="<?= aid_html((string)$c['fichier_nom']) ?>">
                        📄 <?= aid_html((string)$c['fichier_nom']) ?>
                        <div class="meta"><?= number_format((int)$c['taille_octets']/1024) ?> Ko · hash <?= aid_html(substr((string)$c['hash_sha256'], 0, 12)) ?>…</div>
                    </td>
                    <td>
                        <?php if (!empty($c['naming_proposed'])): ?>
                            <span class="aid-naming"><?= aid_html((string)$c['naming_proposed']) ?></span>
                        <?php else: ?>
                            <span class="meta">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="conf <?= $iaClass ?>"><?= $iaConf ?>%</span></td>
                    <td><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
                    <td>
                        <?php if ($c['ged_doc_id']): ?>
                            #<?= (int)$c['ged_doc_id'] ?> · <?= (int)$c['nb_links'] ?> lien(s)
                        <?php else: ?>
                            <span class="meta">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="meta"><?= aid_html(date('d/m H:i', strtotime((string)$c['created_at']))) ?></td>
                    <td>
                        <?php if ($bienId): ?>
                            <a class="tr-btn" style="font-size:10px;padding:3px 8px;" href="<?= aid_html(app_url('/bien_doc_360.php?bien_id=' . (int)$bienId . '&doc_id=' . (int)$c['doc_id'] . '&card_id=' . (int)$c['carte_id'])) ?>">📋 360°</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($cards)): ?>
                <tr><td colspan="8" class="meta" style="text-align:center;padding:24px;">Aucun upload dans la période</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="aid-table">
    <h3>📜 Audit trail IA / enrichissements (<?= count($auditRecent) ?> dernières actions)</h3>
    <?php foreach ($auditRecent as $a): ?>
        <div class="aid-audit-row">
            <span class="when"><?= aid_html(date('d/m H:i:s', strtotime((string)$a['created_at']))) ?></span>
            <span class="type">
                <?php
                $tBadge = match ($a['action_type']) {
                    'auto_commit_ged'  => '<span class="badge auto">🤖 auto-commit</span>',
                    'enrich_bien'      => '<span class="badge validated">✨ enrich bien</span>',
                    'enrich_mandat'    => '<span class="badge validated">✨ enrich mandat</span>',
                    'classement_ged'   => '<span class="badge validated">📁 classement</span>',
                    'workflow'         => '<span class="badge pending">⚙️ workflow</span>',
                    'user_validation'  => '<span class="badge validated">👤 validation user</span>',
                    default            => '<span class="badge">' . aid_html((string)$a['action_type']) . '</span>',
                };
                echo $tBadge;
                ?>
            </span>
            <span class="label"><?= aid_html((string)$a['action_label']) ?></span>
            <?php if ($a['carte_id']): ?>
                <span class="meta">carte #<?= (int)$a['carte_id'] ?></span>
            <?php endif; ?>
            <?php
            // Fix Bug 3 (2026-05-26) : confiance stockée en % entier (92.00), pas en décimal
            // Si valeur ≤ 1 → c'est probablement décimal (cas auto_commit_ged) → *100
            // Si valeur > 1 → déjà en % (cas classement_ged) → garder
            $confVal = (float)$a['confiance'];
            $confDisplay = ($confVal > 0 && $confVal <= 1) ? round($confVal * 100) : round($confVal);
            ?>
            <span class="meta">conf. <?= $confDisplay ?>%</span>
        </div>
    <?php endforeach; ?>
    <?php if (empty($auditRecent)): ?>
        <div class="meta" style="text-align:center;padding:16px;">Aucune action IA dans la période</div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
