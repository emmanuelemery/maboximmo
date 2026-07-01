<?php
/**
 * bailleur_transactions.php — « Mes ventes » du bailleur.
 * Biens des propriétaires de l'utilisateur actuellement en vente (annonce vente la
 * plus récente). Version dédiée au module Bailleur (scopée, sans écrans internes).
 * Accès : super admin OU service bailleur.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403);
    exit('Accès réservé au module Bailleur.');
}
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

if ($isSuperAdmin) {
    $propIds = $pdo->query("SELECT id FROM proprietaires")->fetchAll(\PDO::FETCH_COLUMN);
} else {
    $st = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
    $st->execute([$userId]);
    $propIds = $st->fetchAll(\PDO::FETCH_COLUMN);
}
$propIds = array_values(array_filter(array_map('intval', $propIds)));

$rows = [];
if (!empty($propIds)) {
    $in = implode(',', $propIds);
    $rows = $pdo->query("
        SELECT b.id, b.reference_bien, a.prix, a.etat_publication, a.titre, a.date_creation,
               i.nom_immeuble, i.ville,
               COALESCE(NULLIF(TRIM(p.societe),''), CONCAT(p.prenom,' ',p.nom)) AS prop_nom
        FROM biens b
        JOIN annonces a ON a.id_bien = b.id AND a.type_transaction='vente'
        LEFT JOIN immeubles     i ON i.id = b.id_immeuble
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE b.id_proprietaire IN ({$in})
          AND a.id = (SELECT a2.id FROM annonces a2
                       WHERE a2.id_bien=b.id AND a2.type_transaction='vente'
                       ORDER BY a2.date_creation DESC, a2.id DESC LIMIT 1)
        ORDER BY prop_nom, b.reference_bien
    ")->fetchAll(\PDO::FETCH_ASSOC);
}

$etatLabels = [
    'brouillon' => ['Brouillon', '#64748b'],
    'diffusee'  => ['Diffusée',  '#047857'],
    'archivee'  => ['Archivée',  '#b45309'],
];

$layout_title   = 'Mes ventes';
$layout_module  = 'Ma Box Bailleur';
$layout_sidebar = 'sidebar_bailleur_module';

ob_start();
?>
<style>
.bt-head { display:flex; align-items:baseline; gap:12px; margin-bottom:16px; }
.bt-count { background:#eab308; color:#3b2f00; border-radius:99px; padding:2px 12px; font-size:13px; font-weight:800; }
.bt-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
.bt-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
.bt-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; color:#0f172a; }
.bt-table tr:last-child td { border-bottom:none; }
.bt-prix { font-weight:800; text-align:right; white-space:nowrap; }
.bt-badge { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:700; color:#fff; }
.bt-empty { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:40px; text-align:center; color:#64748b; }
.bt-link { color:#0e7490; text-decoration:none; font-weight:700; }
.bt-link:hover { text-decoration:underline; }
</style>

<div class="bt-head">
  <h1 style="font-size:20px;font-weight:800;">🎯 Mes ventes</h1>
  <span class="bt-count"><?= count($rows) ?></span>
</div>
<p style="color:#64748b;font-size:13px;margin-bottom:18px;">Biens de votre patrimoine actuellement en vente (annonce la plus récente).</p>

<?php if (empty($rows)): ?>
  <div class="bt-empty">Aucun bien en vente sur les propriétaires accessibles.</div>
<?php else: ?>
  <table class="bt-table">
    <thead>
      <tr><th>Référence</th><th>Titre</th><th>Immeuble</th><th>Propriétaire</th><th>État</th><th style="text-align:right;">Prix</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $et = (string)($r['etat_publication'] ?? '');
        [$etLbl, $etCol] = $etatLabels[$et] ?? [ucfirst($et ?: '—'), '#64748b'];
      ?>
        <tr>
          <td><strong><?= e($r['reference_bien'] ?: ('#' . $r['id'])) ?></strong></td>
          <td><?= e($r['titre'] ?: '—') ?></td>
          <td><?= e($r['nom_immeuble'] ?: '—') ?><?= $r['ville'] ? ' · ' . e($r['ville']) : '' ?></td>
          <td><?= e($r['prop_nom']) ?></td>
          <td><span class="bt-badge" style="background:<?= e($etCol) ?>;"><?= e($etLbl) ?></span></td>
          <td class="bt-prix"><?= $r['prix'] ? number_format((float)$r['prix'], 0, ',', ' ') . ' €' : '—' ?></td>
          <td><a class="bt-link" href="bien_detail.php?edit=<?= (int)$r['id'] ?>&section=annonce">Ouvrir →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
