<?php
declare(strict_types=1);

/**
 * ADMIN — Toggle 'Salarié oui/non' sur les users
 *
 * Permet à l'admin de gérer la liste des users qui apparaissent (ou pas)
 * dans les listes salaires / fiches de paie / exports PDF, sans avoir à
 * éditer la BDD.
 *
 * Migration source : 20260430_users_est_salarie.
 * Pré-désactivés par la migration : tous les users de "GROUPE SIR & SABY"
 * et "PRESTATAIRES EXTERNES" + Emery Pierre-Emmanuel.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if (!in_array((int)current_role_id(), [1], true) && !is_super_admin()) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs.');
}

$pdo = $GLOBALS['pdo'];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('admin_users_salarie');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE users SET est_salarie = NOT est_salarie WHERE id = ?")->execute([$id]);
        $success = 'Statut mis à jour pour user #' . $id;
    }
}

$rows = $pdo->query("
    SELECT u.id, u.nom, u.prenom, u.actif, u.est_salarie,
           s.nom AS societe_nom, ag.nom_agence
    FROM users u
    LEFT JOIN societes s ON s.id = u.id_societe
    LEFT JOIN agences ag ON ag.id = u.id_agence
    ORDER BY u.est_salarie DESC, s.nom ASC, u.nom ASC, u.prenom ASC
")->fetchAll(PDO::FETCH_ASSOC);

$bySoc = [];
foreach ($rows as $r) $bySoc[$r['societe_nom'] ?: '— sans société —'][] = $r;

$csrf = csrf_token('admin_users_salarie');
function ho($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — Users salariés / non-salariés</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap">
  <style>
    body { margin: 0; font-family: 'Manrope', sans-serif; background: #f8fafc; color: #0f172a; }
    .us-wrap { max-width: 1100px; margin: 0 auto; padding: 22px 20px; }
    .us-wrap h1 { margin: 0 0 6px; font-size: 20px; }
    .us-sub { color: #64748b; font-size: 13px; margin: 0 0 24px; }
    .us-flash { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
    .us-soc { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; margin: 22px 0 8px; }
    .us-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .us-table th, .us-table td { padding: 9px 12px; text-align: left; font-size: 12px; border-bottom: 1px solid #e5e7eb; }
    .us-table th { background: #f8fafc; color: #64748b; font-weight: 600; }
    .us-table tr.is-not-salarie { background: #fffbeb; }
    .us-pill { display: inline-flex; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; }
    .us-pill.yes { background: #dcfce7; color: #14532d; }
    .us-pill.no  { background: #fef3c7; color: #92400e; }
    .us-pill.inactive { background: #f1f5f9; color: #64748b; }
    .us-btn { padding: 5px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font-size: 11px; font-weight: 600; cursor: pointer; }
    .us-btn:hover { background: #f1f5f9; }
    .us-btn.warn { border-color: #f59e0b; color: #92400e; background: #fffbeb; }
    .us-banner { background: #ecfdf5; border: 1px solid #86efac; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; font-size: 13px; color: #065f46; }
  </style>
</head>
<body>
<div class="us-wrap">
  <h1>👥 Users — Salariés / Non-salariés</h1>
  <p class="us-sub">Gère le flag <code>est_salarie</code>. Les non-salariés disparaissent des listes salaires, fiches de paie et exports PDF.</p>

  <?php if ($success): ?><div class="us-flash">✓ <?= ho($success) ?></div><?php endif; ?>

  <div class="us-banner">
    <strong>Total :</strong> <?= count($rows) ?> users ·
    <strong>Salariés :</strong> <?= count(array_filter($rows, fn($r) => (int)$r['est_salarie'] === 1)) ?> ·
    <strong>Non-salariés :</strong> <?= count(array_filter($rows, fn($r) => (int)$r['est_salarie'] === 0)) ?>
  </div>

  <?php foreach ($bySoc as $socNom => $items): ?>
    <div class="us-soc"><?= ho($socNom) ?> · <?= count($items) ?> users</div>
    <table class="us-table">
      <thead>
        <tr>
          <th style="width:40px;">#</th>
          <th>Nom</th>
          <th>Prénom</th>
          <th>Agence</th>
          <th>Actif</th>
          <th>Salarié</th>
          <th style="width:1%;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $r): $estSal = (int)$r['est_salarie'] === 1; ?>
          <tr class="<?= $estSal ? '' : 'is-not-salarie' ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><strong><?= ho($r['nom']) ?></strong></td>
            <td><?= ho($r['prenom']) ?></td>
            <td><?= ho($r['nom_agence'] ?? '—') ?></td>
            <td>
              <?php if ((int)$r['actif'] === 1): ?>
                <span class="us-pill yes">Oui</span>
              <?php else: ?>
                <span class="us-pill inactive">Inactif</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($estSal): ?>
                <span class="us-pill yes">Oui</span>
              <?php else: ?>
                <span class="us-pill no">Non — exclu salaires</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= ho($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="us-btn <?= $estSal ? 'warn' : '' ?>">
                  <?= $estSal ? 'Exclure' : 'Inclure' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  <p style="margin-top:24px;font-size:11px;color:#94a3b8;">
    💡 Le flag est aussi présent dans la page de <a href="/public_html/admin_user_create.php">création d'utilisateur</a>.
  </p>
</div>
</body>
</html>
