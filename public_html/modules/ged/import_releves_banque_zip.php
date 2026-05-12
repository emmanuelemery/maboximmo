<?php
declare(strict_types=1);

/**
 * GED — Import ZIP relevés bancaires (UI)
 * Fichier : modules/ged/import_releves_banque_zip.php
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$roleId    = (int)current_role_id();
$userId    = (int)current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

$pageTitle = 'Import ZIP relevés banque';
$bodyClass = 'aged-body';
$robots    = 'noindex, nofollow';

$batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

// Batches récents
$recent = [];
try {
    $where = '1=1';
    $params = [];
    if (!$isAdmin && $societeId > 0) {
        $where .= ' AND (id_societe = :sid OR id_societe IS NULL)';
        $params['sid'] = $societeId;
    }
    $st = $pdo->prepare("
        SELECT id, statut, mois_annee_defaut, nb_zip, nb_pdf, nb_reconnus, nb_a_valider, nb_erreurs, created_at
        FROM ged_import_releves_batches
        WHERE {$where}
        ORDER BY created_at DESC
        LIMIT 12
    ");
    $st->execute($params);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $recent = [];
}

// Liste immeubles (pour corrections manuelles)
$immeubles = [];
try {
    $st = $pdo->prepare("
        SELECT id, reference_immeuble, nom_immeuble, ville, logiciel_comptable
        FROM immeubles
        WHERE (?=0 OR id_societe=?)
        ORDER BY reference_immeuble ASC, nom_immeuble ASC
        LIMIT 5000
    ");
    $sid = (!$isAdmin && $societeId > 0) ? $societeId : 0;
    $st->execute([$sid, $sid]);
    $immeubles = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $immeubles = [];
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$assetsRoot = dirname(__DIR__, 2) . '/assets';
$vGedCss = @filemtime($assetsRoot . '/css/ged.css') ?: time();
$vCss    = @filemtime($assetsRoot . '/css/ged_import_releves_zip.css') ?: time();
$vJs     = @filemtime($assetsRoot . '/js/ged_import_releves_zip.js') ?: time();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="<?= h($robots) ?>">
<meta name="csrf-token" content="<?= h(csrf_token('ged_import_releves_zip')) ?>">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="<?= app_url('/assets/css/ged.css') ?>?v=<?= $vGedCss ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/ged_import_releves_zip.css') ?>?v=<?= $vCss ?>">
</head>
<body class="<?= h($bodyClass) ?>">

<?php
$layoutTop = __DIR__ . '/../../inc/agency_layout_top.php';
if (is_file($layoutTop)) require $layoutTop;
?>

<div class="ged-imp-wrap" <?= $batchId > 0 ? 'data-batch-id="' . (int)$batchId . '"' : '' ?>>
    <div class="ged-imp-head">
        <div>
            <h1>🏦 Import ZIP — Relevés bancaires</h1>
            <div class="sub">ZIP → extraction PDF → reconnaissance immeuble → classement Drive + regroupement compta (SEPTEO/LOJJI/ICS/MaBoxImmo)</div>
        </div>
        <div class="ged-imp-actions-top">
            <a class="aged-btn" href="<?= app_url('/modules/ged/ged_dashboard.php') ?>">↩️ Retour GED</a>
        </div>
    </div>

    <div class="ged-imp-card">
        <h2>1) Importer des ZIP</h2>
        <?php if (!class_exists('ZipArchive')): ?>
            <div class="empty" style="border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:12px;padding:12px 14px;margin-bottom:12px;">
                <strong>ZipArchive indisponible</strong> — l’extension PHP <code>zip</code> n’est pas activée sur ce serveur, donc l’extraction des PDFs ne peut pas fonctionner.
                <div style="margin-top:6px;font-size:12px;color:#7f1d1d;">
                    Active l’extension <code>zip</code> côté PHP (Hostinger / php.ini), puis réessaie l’import.
                </div>
            </div>
        <?php endif; ?>
        <form id="releves-upload-form">
            <div class="row">
                <div class="col">
                    <label>Fichiers ZIP</label>
                    <input type="file" id="releves-zips" name="zips[]" accept=".zip" multiple required>
                    <div class="hint">Uniquement <strong>.zip</strong>. Les PDFs seront extraits et analysés.</div>
                </div>
                <div class="col">
                    <label>Mois/année (fallback)</label>
                    <input type="month" id="releves-default-period" name="mois_annee_defaut" placeholder="YYYY-MM">
                    <div class="hint">Optionnel : utilisé si la période n’est pas reconnue automatiquement.</div>
                </div>
            </div>
            <div class="row">
                <button class="aged-btn primary" type="submit">Importer et analyser</button>
                <span id="releves-upload-status" class="status"></span>
            </div>
        </form>
    </div>

    <div class="ged-imp-grid">
        <div class="ged-imp-card">
            <h2>2) Lots récents</h2>
            <?php if (!$recent): ?>
                <div class="empty">Aucun lot pour l’instant.</div>
            <?php else: ?>
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Statut</th>
                            <th>Période</th>
                            <th>ZIP</th>
                            <th>PDF</th>
                            <th>Reconnus</th>
                            <th>À valider</th>
                            <th>Erreurs</th>
                            <th>Créé</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $b): ?>
                        <tr>
                            <td><a href="<?= h(app_url('/modules/ged/import_releves_banque_zip.php')) ?>?batch_id=<?= (int)$b['id'] ?>">#<?= (int)$b['id'] ?></a></td>
                            <td><span class="badge b-<?= h((string)$b['statut']) ?>"><?= h((string)$b['statut']) ?></span></td>
                            <td><?= h((string)($b['mois_annee_defaut'] ?? '')) ?></td>
                            <td><?= (int)($b['nb_zip'] ?? 0) ?></td>
                            <td><?= (int)($b['nb_pdf'] ?? 0) ?></td>
                            <td><?= (int)($b['nb_reconnus'] ?? 0) ?></td>
                            <td><?= (int)($b['nb_a_valider'] ?? 0) ?></td>
                            <td><?= (int)($b['nb_erreurs'] ?? 0) ?></td>
                            <td><?= h((string)($b['created_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="ged-imp-card">
            <h2>3) Tableau de contrôle</h2>
            <?php if ($batchId <= 0): ?>
                <div class="empty">Ouvre un lot (colonne de gauche) pour afficher les PDFs extraits et les valider.</div>
            <?php else: ?>
                <div class="toolbar">
                    <button class="aged-btn primary" type="button" id="btn-validate-rec">Valider les reconnus</button>
                    <button class="aged-btn" type="button" id="btn-validate-all">Valider tout (inclut douteux)</button>
                    <button class="aged-btn" type="button" id="btn-reanalyze">Relancer analyse</button>
                    <button class="aged-btn danger" type="button" id="btn-reject">Rejeter non reconnus</button>
                    <span id="batch-status" class="status"></span>
                </div>
                <div class="table-wrap">
                    <table class="tbl" id="items-table">
                        <thead>
                            <tr>
                                <th>ZIP</th>
                                <th>PDF</th>
                                <th>Immeuble</th>
                                <th>Logiciel</th>
                                <th>Période</th>
                                <th>Banque</th>
                                <th>Confiance</th>
                                <th>Statut</th>
                                <th>Drive</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="10" class="empty">Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.GED_RELEVES_BATCH_ID = <?= (int)$batchId ?>;
window.GED_IMMEUBLES = <?= json_encode(array_map(function ($r) {
    return [
        'id' => (int)($r['id'] ?? 0),
        'ref' => (string)($r['reference_immeuble'] ?? ''),
        'nom' => (string)($r['nom_immeuble'] ?? ''),
        'ville' => (string)($r['ville'] ?? ''),
        'logiciel' => (string)($r['logiciel_comptable'] ?? ''),
    ];
}, $immeubles), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= app_url('/assets/js/ged_import_releves_zip.js') ?>?v=<?= $vJs ?>"></script>
</body>
</html>
