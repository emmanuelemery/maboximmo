<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$current_page = 'dashboard_proprio';
$nav_context  = 'proprio';

// ── Propriétaire courant ────────────────────────────────────────
// Super admin (role_id=1) peut consulter n'importe quel bailleur via ?id_proprietaire=X
$idProp = (int) ($_SESSION['id_proprietaire'] ?? 0);
$isAdminView = false;
if ((int)current_role_id() === 1 && isset($_GET['id_proprietaire'])) {
    $idProp = (int) $_GET['id_proprietaire'];
    $isAdminView = true;
}

if (!$idProp) {
    // Fallback : pas de compte propriétaire associé
    $noProprio = true;
} else {
    $noProprio = false;

    // Info propriétaire
    $stmtProp = $pdo->prepare("SELECT * FROM proprietaires WHERE id = ?");
    $stmtProp->execute([$idProp]);
    $proprio = $stmtProp->fetch();
    if (!$proprio) { $noProprio = true; }
}

if (!$noProprio) {
    // ── KPIs ────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM immeubles WHERE id_proprietaire = ?");
    $stmt->execute([$idProp]);
    $nbImmeubles = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE id_proprietaire = ?");
    $stmt->execute([$idProp]);
    $nbBiens = (int) $stmt->fetchColumn();

    // Taux d'occupation
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN statut_occupation = 'occupé' THEN 1 ELSE 0 END) AS occupes
        FROM biens WHERE id_proprietaire = ?
    ");
    $stmt->execute([$idProp]);
    $occ = $stmt->fetch();
    $tauxOccupation = ($occ['total'] > 0)
        ? round(($occ['occupes'] / $occ['total']) * 100, 1)
        : 0;

    // Dernier CRG
    $stmt = $pdo->prepare("
        SELECT * FROM crg_trimestres
        WHERE id_proprietaire = ? AND parse_statut = 'ok'
        ORDER BY annee DESC, trimestre DESC
        LIMIT 1
    ");
    $stmt->execute([$idProp]);
    $lastCrg = $stmt->fetch();

    // Total impayés du dernier CRG
    $totalImpayes = 0;
    $situations   = [];
    if ($lastCrg) {
        $stmt = $pdo->prepare("SELECT SUM(total_impaye) FROM crg_situations_locataires WHERE id_crg = ?");
        $stmt->execute([$lastCrg['id']]);
        $totalImpayes = (float) ($stmt->fetchColumn() ?: 0);

        // Situations locataires
        $stmt = $pdo->prepare("
            SELECT locataire_nom, numero_lot, type_bien, loyer_appele,
                   total_impaye, statut_trimestre
            FROM crg_situations_locataires
            WHERE id_crg = ?
            ORDER BY locataire_nom
        ");
        $stmt->execute([$lastCrg['id']]);
        $situations = $stmt->fetchAll();
    }

    // Historique CRG (8 derniers)
    $stmt = $pdo->prepare("
        SELECT annee, trimestre, total_debits, total_credits
        FROM crg_trimestres
        WHERE id_proprietaire = ? AND parse_statut = 'ok'
        ORDER BY annee DESC, trimestre DESC
        LIMIT 8
    ");
    $stmt->execute([$idProp]);
    $crgHistorique = $stmt->fetchAll();
}

/**
 * Format monétaire
 */
function money(float $v): string {
    return number_format($v, 2, ',', ' ') . ' &euro;';
}

$username = h($_SESSION['username'] ?? 'Utilisateur');
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Proprietaire — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/layout.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/gestion.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Sora', sans-serif; background: var(--bg-secondary, #f5f6fa); color: #1a1816; min-height: 100vh; display: flex; }
    .shell { width: 100%; min-height: 100vh; }
    .sb-content { margin-left: 220px; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; padding: 15px 36px 40px; gap: 0; overflow-y: auto; overflow-x: hidden; }
    .page-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; }
    .page-head h1 { font-size: 22px; font-weight: 700; }
    .page-head .subtitle { color: #6b7a8d; font-size: 14px; margin-top: 2px; }

    .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .kpi-card { background: #fff; border: 1px solid #e4e7ec; border-radius: 14px; padding: 20px 22px; }
    .kpi-label { font-size: 12px; color: #6b7a8d; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
    .kpi-val { font-size: 26px; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
    .kpi-val.danger { color: #d63031; }
    .kpi-val.success { color: #00b894; }

    .section-title { font-size: 16px; font-weight: 600; margin: 24px 0 12px; }

    table.gest-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 10px; overflow: hidden; border: 1px solid #e4e7ec; }
    table.gest-table th { background: #f7f8fa; text-align: left; padding: 10px 14px; font-weight: 600; color: #4a5568; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
    table.gest-table td { padding: 10px 14px; border-top: 1px solid #eef0f4; }
    table.gest-table tr:hover td { background: #fafbfd; }

    .badge-gest { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-gest.occupé { background: #d4edda; color: #155724; }
    .badge-gest.parti-débiteur { background: #f8d7da; color: #721c24; }
    .badge-gest.vacant { background: #e2e3e5; color: #383d41; }

    .btn-ia { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border: none; border-radius: 10px; font-weight: 600; font-size: 13px; text-decoration: none; cursor: pointer; margin-top: 12px; transition: transform .15s; }
    .btn-ia:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102,126,234,.3); }

    .empty-msg { padding: 40px; text-align: center; color: #6b7a8d; font-size: 14px; background: #fff; border-radius: 14px; border: 1px solid #e4e7ec; }
  </style>
</head>
<body>
<div class="shell">
  <?php include __DIR__ . '/sidebar_bailleur.php'; ?>

  <div class="sb-content">
    <?php include __DIR__ . '/gestion/inc/nav_gestion.php'; ?>

    <div class="main">

<?php if ($noProprio): ?>
      <div class="empty-msg">
        <p><strong>Aucun compte proprietaire associe.</strong></p>
        <p>Veuillez contacter votre administrateur pour relier votre compte utilisateur a un proprietaire.</p>
      </div>
<?php else: ?>

      <!-- Header -->
      <div class="page-head">
        <div>
          <h1>Tableau de bord proprietaire</h1>
          <div class="subtitle">Bienvenue <?= $username ?> &mdash; <?= h($proprio['nom'] ?? '') ?></div>
        </div>
        <a href="<?= app_url('/gestion/assistant_ia.php') ?>" class="btn-ia">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
          Assistant IA
        </a>
      </div>

      <!-- KPIs -->
      <div class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-label">Immeubles</div>
          <div class="kpi-val"><?= $nbImmeubles ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Biens / Lots</div>
          <div class="kpi-val"><?= $nbBiens ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Taux d'occupation</div>
          <div class="kpi-val<?= $tauxOccupation >= 80 ? ' success' : '' ?>"><?= $tauxOccupation ?> %</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Total impayes</div>
          <div class="kpi-val<?= $totalImpayes > 0 ? ' danger' : '' ?>"><?= money($totalImpayes) ?></div>
        </div>
      </div>

      <!-- Dernier CRG — Situations locataires -->
<?php if ($lastCrg): ?>
      <h2 class="section-title">Dernier CRG &mdash; T<?= $lastCrg['trimestre'] ?> <?= $lastCrg['annee'] ?></h2>

  <?php if ($situations): ?>
      <table class="gest-table">
        <thead>
          <tr>
            <th>Locataire</th>
            <th>Lot</th>
            <th>Type</th>
            <th style="text-align:right">Loyer appele</th>
            <th style="text-align:right">Impaye</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($situations as $sit): ?>
          <tr>
            <td><?= h($sit['locataire_nom']) ?></td>
            <td><?= h($sit['numero_lot'] ?? '-') ?></td>
            <td><?= h($sit['type_bien'] ?? '-') ?></td>
            <td style="text-align:right"><?= money((float)$sit['loyer_appele']) ?></td>
            <td style="text-align:right;<?= (float)$sit['total_impaye'] > 0 ? 'color:#d63031;font-weight:600' : '' ?>">
              <?= money((float)$sit['total_impaye']) ?>
            </td>
            <td><span class="badge-gest <?= h($sit['statut_trimestre']) ?>"><?= h($sit['statut_trimestre']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
  <?php else: ?>
      <div class="empty-msg">Aucune situation locataire pour ce trimestre.</div>
  <?php endif; ?>
<?php else: ?>
      <div class="empty-msg">Aucun CRG disponible pour le moment.</div>
<?php endif; ?>

      <!-- Historique CRG -->
<?php if (!empty($crgHistorique)): ?>
      <h2 class="section-title">Historique des CRG</h2>
      <table class="gest-table">
        <thead>
          <tr>
            <th>Periode</th>
            <th style="text-align:right">Total debits</th>
            <th style="text-align:right">Total credits</th>
            <th style="text-align:right">Solde</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($crgHistorique as $h): ?>
          <?php $solde = (float)$h['total_credits'] - (float)$h['total_debits']; ?>
          <tr>
            <td>T<?= $h['trimestre'] ?> <?= $h['annee'] ?></td>
            <td style="text-align:right"><?= money((float)$h['total_debits']) ?></td>
            <td style="text-align:right"><?= money((float)$h['total_credits']) ?></td>
            <td style="text-align:right;<?= $solde < 0 ? 'color:#d63031' : 'color:#00b894' ?>;font-weight:600">
              <?= money($solde) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
<?php endif; ?>

<?php endif; /* end noProprio */ ?>

    </div><!-- .main -->
  </div><!-- .sb-content -->
</div><!-- .shell -->
</body>
</html>
