<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$user = check_auth_gestion('SIR');
$pdo  = $GLOBALS['pdo'];

if (!function_exists('e')) {
    function e(?string $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$current_page = 'patrimoine_sir';
$nav_context  = 'sir';

/* ── Entity switch (GET → SESSION) ───────────────────── */
if (isset($_GET['entite']) && in_array($_GET['entite'], ['SIR', 'SABY', 'GROUPE'], true)) {
    $_SESSION['sir_entite'] = $_GET['entite'];
    header('Location: ' . app_url('/gestion/patrimoine_sir.php'));
    exit;
}

/* ── Propriétaire IDs for active entity ──────────────── */
$prop_ids = get_sir_proprietaire_ids((int)$user['id']);
if (empty($prop_ids)) {
    $immeubles = [];
} else {
    $placeholders = implode(',', array_fill(0, count($prop_ids), '?'));

    /* ── Immeubles with occupation stats ─────────────── */
    $sqlImm = "
        SELECT i.id, i.nom_immeuble, i.adresse_1, i.ville, i.code_postal, i.type_immeuble,
               COUNT(DISTINCT csl.id) AS nb_lots,
               SUM(CASE WHEN csl.statut_trimestre = 'occupé' THEN 1 ELSE 0 END) AS nb_occupes,
               SUM(CASE WHEN csl.statut_trimestre = 'vacant' THEN 1 ELSE 0 END) AS nb_vacants,
               SUM(CASE WHEN csl.statut_trimestre = 'parti-débiteur' THEN 1 ELSE 0 END) AS nb_partis
        FROM immeubles i
        LEFT JOIN biens b ON b.id_immeuble = i.id
        LEFT JOIN crg_situations_locataires csl ON csl.id_bien = b.id
            AND csl.id_crg = (
                SELECT ct2.id FROM crg_trimestres ct2
                WHERE ct2.id_proprietaire IN ($placeholders) AND ct2.parse_statut = 'ok'
                ORDER BY ct2.annee DESC, ct2.trimestre DESC LIMIT 1
            )
        WHERE i.id IN (
            SELECT DISTINCT b2.id_immeuble FROM biens b2 WHERE b2.id_proprietaire IN ($placeholders)
        )
        GROUP BY i.id
        ORDER BY i.ville, i.nom_immeuble
    ";
    $paramsImm = array_merge($prop_ids, $prop_ids);
    $stmtImm = $pdo->prepare($sqlImm);
    $stmtImm->execute($paramsImm);
    $immeubles = $stmtImm->fetchAll(PDO::FETCH_ASSOC);

    /* ── Latest CRG id ───────────────────────────────── */
    $stmtLastCrg = $pdo->prepare("
        SELECT id FROM crg_trimestres
        WHERE id_proprietaire IN ($placeholders) AND parse_statut = 'ok'
        ORDER BY annee DESC, trimestre DESC LIMIT 1
    ");
    $stmtLastCrg->execute($prop_ids);
    $lastCrgId = (int)$stmtLastCrg->fetchColumn();

    /* ── Lots per immeuble ───────────────────────────── */
    $lotsByImmeuble = [];
    if ($lastCrgId > 0) {
        $stmtLots = $pdo->prepare("
            SELECT b.id_immeuble,
                   csl.numero_lot, csl.type_bien, csl.categorie_bien,
                   csl.statut_trimestre, csl.loyer_appele, csl.total_impaye, csl.solde_anterieur
            FROM crg_situations_locataires csl
            LEFT JOIN biens b ON b.id = csl.id_bien
            WHERE csl.id_crg = ? AND b.id_immeuble IS NOT NULL
            ORDER BY csl.numero_lot
        ");
        $stmtLots->execute([$lastCrgId]);
        while ($row = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
            $lotsByImmeuble[(int)$row['id_immeuble']][] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Patrimoine — Groupe SIR — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/gestion.css') ?>">
<style>
  .pat-accordion { margin-bottom: 8px; }
  .pat-accordion summary {
    cursor: pointer;
    list-style: none;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    transition: background .15s;
  }
  .pat-accordion summary:hover { background: var(--bg-tertiary, #f5f5f5); }
  .pat-accordion summary::-webkit-details-marker { display: none; }
  .pat-accordion summary::before {
    content: '▸';
    display: inline-block;
    transition: transform .2s;
    font-size: 12px;
    color: var(--text-tertiary);
  }
  .pat-accordion[open] summary::before { transform: rotate(90deg); }
  .pat-accordion[open] summary {
    border-radius: 10px 10px 0 0;
    border-bottom-color: transparent;
  }
  .pat-body {
    border: 1px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 10px 10px;
    padding: 0;
    overflow-x: auto;
    background: var(--bg-secondary);
  }
  .pat-body table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .pat-body thead tr {
    border-bottom: 1px solid var(--border-color);
    color: var(--text-tertiary);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: .07em;
  }
  .pat-body th, .pat-body td { padding: 10px 14px; }
  .pat-body th { text-align: left; }
  .pat-body td.num { text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 12px; }
  .pat-body tbody tr { border-bottom: 1px solid var(--border-color); }
  .pat-body tbody tr:last-child { border-bottom: none; }

  .badge-sm {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.4;
  }
  .badge-occupe  { background: #e8f5e9; color: #2e7d32; }
  .badge-vacant  { background: #fff3e0; color: #e65100; }
  .badge-parti   { background: #fce4ec; color: #c62828; }
  .badge-hab     { background: #e3f2fd; color: #1565c0; }
  .badge-com     { background: #f3e5f5; color: #7b1fa2; }
  .badge-autre   { background: #eceff1; color: #546e6f; }
  .text-red      { color: #c62828; font-weight: 600; }
  .summary-badges { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }
  .summary-addr  { color: var(--text-tertiary); font-size: 12px; font-weight: 400; }
</style>
</head>
<body class="theme-sir">

<?php include __DIR__ . '/../sidebar_bailleur.php'; ?>
<?php include __DIR__ . '/inc/nav_gestion.php'; ?>

<main style="margin-left:260px;padding:32px 40px;">
  <h1 style="font-size:22px;font-weight:600;margin-bottom:24px;color:var(--text-primary);">Patrimoine — Groupe SIR</h1>

<?php if (empty($immeubles)): ?>
  <p style="color:var(--text-tertiary);">Aucun immeuble trouvé pour cette entité.</p>
<?php else: ?>
  <?php foreach ($immeubles as $imm):
      $lots = $lotsByImmeuble[(int)$imm['id']] ?? [];
      $nbLots    = (int)$imm['nb_lots'];
      $nbOcc     = (int)$imm['nb_occupes'];
      $nbVac     = (int)$imm['nb_vacants'];
      $nbPart    = (int)$imm['nb_partis'];
  ?>
  <details class="pat-accordion">
    <summary>
      <span><?= e((string)($imm['nom_immeuble'] ?? 'Sans nom')) ?></span>
      <span class="summary-addr"><?= e((string)($imm['adresse_1'] ?? '')) ?>, <?= e((string)($imm['code_postal'] ?? '')) ?> <?= e((string)($imm['ville'] ?? '')) ?></span>
      <span class="summary-badges">
        <span class="badge-sm badge-hab"><?= $nbLots ?> lot<?= $nbLots > 1 ? 's' : '' ?></span>
        <?php if ($nbOcc > 0): ?><span class="badge-sm badge-occupe"><?= $nbOcc ?> occ.</span><?php endif; ?>
        <?php if ($nbVac > 0): ?><span class="badge-sm badge-vacant"><?= $nbVac ?> vac.</span><?php endif; ?>
        <?php if ($nbPart > 0): ?><span class="badge-sm badge-parti"><?= $nbPart ?> parti</span><?php endif; ?>
      </span>
    </summary>
    <div class="pat-body">
    <?php if (empty($lots)): ?>
      <p style="padding:16px;color:var(--text-tertiary);font-size:13px;">Aucun lot rattaché à cet immeuble dans le dernier CRG.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Lot</th>
            <th>Type</th>
            <th>Catégorie</th>
            <th>Statut</th>
            <th style="text-align:right;">Loyer/mois</th>
            <th style="text-align:right;">Impayé</th>
            <th style="text-align:right;">Solde ant.</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lots as $lot):
            $cat = $lot['categorie_bien'] ?? 'habitation';
            $statut = $lot['statut_trimestre'] ?? 'vacant';
            $impaye = (float)($lot['total_impaye'] ?? 0);
            $soldeAnt = (float)($lot['solde_anterieur'] ?? 0);
            $loyer = (float)($lot['loyer_appele'] ?? 0);

            $catLabel = match ($cat) {
                'commercial' => 'Locataire commercial',
                'habitation' => 'Locataire habitation',
                default      => ucfirst($cat),
            };
            $catBadge = match ($cat) {
                'commercial' => 'badge-com',
                'habitation' => 'badge-hab',
                default      => 'badge-autre',
            };
            $statutBadge = match ($statut) {
                'occupé'          => 'badge-occupe',
                'vacant'          => 'badge-vacant',
                'parti-débiteur'  => 'badge-parti',
                default           => 'badge-autre',
            };
        ?>
          <tr>
            <td><?= e((string)($lot['numero_lot'] ?? '—')) ?></td>
            <td><?= e((string)($lot['type_bien'] ?? '—')) ?></td>
            <td><span class="badge-sm <?= $catBadge ?>"><?= e($catLabel) ?></span></td>
            <td><span class="badge-sm <?= $statutBadge ?>"><?= e(ucfirst($statut)) ?></span></td>
            <td class="num"><?= number_format($loyer, 2, ',', ' ') ?> &euro;</td>
            <td class="num<?= $impaye > 0 ? ' text-red' : '' ?>"><?= number_format($impaye, 2, ',', ' ') ?> &euro;</td>
            <td class="num"><?= number_format($soldeAnt, 2, ',', ' ') ?> &euro;</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div>
  </details>
  <?php endforeach; ?>
<?php endif; ?>

</main>
</body>
</html>
