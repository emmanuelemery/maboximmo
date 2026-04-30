<?php
declare(strict_types=1);

/**
 * PARAMÉTRAGE — Référentiel `bien_types` (lecture seule + toggle actif)
 *
 * Migration 20260430_bien_types : la nouvelle table `bien_types` est un
 * référentiel UNIFIÉ pour les passerelles (Le Bon Coin via Ubiflow, SeLoger
 * via CSV Poliris, FNAIM via XML IRIS). La granularité est dictée par les
 * codes Ubiflow `code_type` — pas par chaque société.
 *
 * Cette page expose la liste en lecture seule. Le seul levier accordé à
 * l'admin est le toggle actif/inactif (pour masquer un type dans les
 * dropdowns sans le supprimer).
 *
 * L'ancienne page `admin/param_types_bien.php` continue de fonctionner sur
 * `societe_types_bien` (référentiel par société) et reste accessible pour
 * permettre un rollback. Les deux tables coexistent jusqu'à validation.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if (!in_array((int)current_role_id(), [1], true) && !is_super_admin()) {
    http_response_code(403);
    exit('Accès refusé.');
}

$pdo = $GLOBALS['pdo'];

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('param_bien_types');
    $action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_actif' && $id > 0) {
        $pdo->prepare("UPDATE bien_types SET actif = NOT actif WHERE id = ?")->execute([$id]);
        $success = 'Statut mis à jour.';
    }
}

$rows = $pdo->query(
    "SELECT id, code, libelle, categorie, code_type_ubiflow, type_seloger, sous_type_seloger,
            balise_fnaim, categorie_fnaim, icone, ordre_affichage, actif
     FROM bien_types
     ORDER BY ordre_affichage ASC, libelle ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Stats : combien de biens utilisent chaque type
$stats = [];
try {
    $statsQ = $pdo->query("SELECT id_bien_type, COUNT(*) AS n FROM biens WHERE id_bien_type IS NOT NULL GROUP BY id_bien_type");
    foreach ($statsQ as $r) $stats[(int)$r['id_bien_type']] = (int)$r['n'];
} catch (Throwable) {}

$byCat = [];
foreach ($rows as $r) {
    $byCat[$r['categorie']][] = $r;
}

$csrf = csrf_token('param_bien_types');
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paramétrage — Référentiel bien_types — MaBoxImmo</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/public_html/css/theme-rh.css">
  <?php include dirname(__DIR__) . '/inc/theme-init.php'; ?>
  <style>
    .bt-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px; }
    .bt-wrap h1 { margin: 0 0 6px; font-size: 22px; color: var(--text-primary, #0f172a); }
    .bt-sub  { font-size: 13px; color: var(--text-secondary, #64748b); margin: 0 0 24px; }
    .bt-flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
    .bt-cat { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; margin: 24px 0 8px; }
    .bt-table { width: 100%; border-collapse: collapse; background: var(--surface, #fff); border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .bt-table th, .bt-table td { padding: 10px 12px; text-align: left; font-size: 12px; border-bottom: 1px solid var(--border, #e5e7eb); }
    .bt-table th { background: var(--surface-alt, #f8fafc); color: var(--text-secondary, #64748b); font-weight: 600; }
    .bt-table tr.is-inactive { opacity: .5; }
    .bt-code { font-family: monospace; font-size: 11px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #0f172a; }
    .bt-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .bt-badge.ubi { background: #dbeafe; color: #1e40af; }
    .bt-badge.sl  { background: #fef3c7; color: #92400e; }
    .bt-badge.fn  { background: #f3e8ff; color: #6b21a8; }
    .bt-stat { font-size: 11px; color: #64748b; }
    .bt-btn { padding: 5px 10px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font-size: 11px; font-weight: 600; cursor: pointer; }
    .bt-btn:hover { background: #f1f5f9; }
    .bt-btn.warn { border-color: #f59e0b; color: #92400e; }
    .bt-banner { background: #ecfdf5; border: 1px solid #86efac; border-radius: 10px; padding: 14px 16px; margin-bottom: 22px; font-size: 13px; color: #065f46; }
    .bt-banner code { background: #d1fae5; padding: 1px 6px; border-radius: 4px; }
  </style>
</head>
<body>
  <?php include dirname(__DIR__) . '/inc/sidebar.php'; ?>
  <main class="mbi-main">
    <div class="mbi-topbar">
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="/public_html/parametrage.php" style="text-decoration:none;color:#64748b;font-size:13px;">← Paramétrage</a>
        <h1 style="margin:0;font-size:18px;">🗂️ Référentiel bien_types</h1>
      </div>
    </div>

    <div class="bt-wrap">
      <p class="bt-sub">Référentiel unique pour la diffusion vers les passerelles Le Bon Coin (Ubiflow), SeLoger (CSV Poliris) et FNAIM (XML IRIS). 27 codes alignés sur la granularité Ubiflow.</p>

      <?php if ($success): ?><div class="bt-flash"><?= h($success) ?></div><?php endif; ?>

      <div class="bt-banner">
        <strong>📡 Source de vérité pour la diffusion.</strong>
        Cette table dicte les codes envoyés à chaque passerelle :
        <span class="bt-badge ubi">Ubiflow code_type</span>
        <span class="bt-badge sl">SeLoger type</span>
        <span class="bt-badge fn">FNAIM balise</span>
        — modifier ces mappings impacte directement les annonces diffusées sur Le Bon Coin et autres portails.
      </div>

      <?php foreach ($byCat as $cat => $items): ?>
        <div class="bt-cat"><?= h(ucfirst($cat)) ?> · <?= count($items) ?> entrées</div>
        <table class="bt-table">
          <thead>
            <tr>
              <th style="width:1%;"></th>
              <th>Code interne</th>
              <th>Libellé</th>
              <th>Ubiflow / LBC</th>
              <th>SeLoger</th>
              <th>FNAIM</th>
              <th style="text-align:right;">Biens</th>
              <th style="width:1%;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $r): $n = $stats[(int)$r['id']] ?? 0; ?>
              <tr class="<?= $r['actif'] ? '' : 'is-inactive' ?>">
                <td><i class="<?= h($r['icone'] ?: 'fa-solid fa-tag') ?>"></i></td>
                <td><span class="bt-code"><?= h($r['code']) ?></span></td>
                <td><strong><?= h($r['libelle']) ?></strong></td>
                <td><span class="bt-badge ubi"><?= h((string)$r['code_type_ubiflow']) ?></span></td>
                <td>
                  <?= h($r['type_seloger'] ?? '—') ?>
                  <?php if (!empty($r['sous_type_seloger'])): ?>
                    <span style="color:#94a3b8;"> / <?= h($r['sous_type_seloger']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= h($r['balise_fnaim'] ?? '—') ?>
                  <?php if (!empty($r['categorie_fnaim'])): ?>
                    <span style="color:#94a3b8;"> · cat <?= (int)$r['categorie_fnaim'] ?></span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;"><span class="bt-stat"><?= $n ?></span></td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="toggle_actif">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="bt-btn <?= $r['actif'] ? '' : 'warn' ?>"><?= $r['actif'] ? 'Désactiver' : 'Activer' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>

      <p style="margin-top:24px;font-size:11px;color:#94a3b8;">
        Total : <?= count($rows) ?> entrées · Modifications du référentiel via migration BDD uniquement.
        L'ancienne table <code>societe_types_bien</code> reste accessible via <a href="/public_html/admin/param_types_bien.php" style="color:#0369a1;">l'ancienne page</a> (rollback).
      </p>
    </div>
  </main>
</body>
</html>
