<?php
/**
 * bailleur_biens.php — « Mes biens » du bailleur.
 * Liste des biens rattachés aux propriétaires de l'utilisateur (user_proprietaires),
 * version dédiée au module Bailleur (scopée, sans fonctions internes).
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

// ── Propriétaires accessibles ──────────────────────────────
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
    // Type via COALESCE(bien_types, base_types_bien) — cf. pièges des 4 tables de type
    $rows = $pdo->query("
        SELECT b.id, b.reference_bien, b.surface_habitable, b.loyer_hc,
               b.occupation_bien,
               COALESCE(NULLIF(bt.libelle,''), NULLIF(btb.label,'')) AS type_lbl,
               i.nom_immeuble, i.adresse_1, i.ville,
               COALESCE(NULLIF(TRIM(p.societe),''), CONCAT(p.prenom,' ',p.nom)) AS prop_nom
        FROM biens b
        LEFT JOIN bien_types      bt  ON bt.id  = b.id_bien_type
        LEFT JOIN base_types_bien btb ON btb.id = b.id_type_bien
        LEFT JOIN immeubles       i   ON i.id   = b.id_immeuble
        LEFT JOIN proprietaires   p   ON p.id   = b.id_proprietaire
        WHERE b.id_proprietaire IN ({$in})
        ORDER BY prop_nom, i.ville, b.reference_bien
    ")->fetchAll(\PDO::FETCH_ASSOC);
}

$layout_title   = 'Mes biens';
$layout_module  = 'Ma Box Bailleur';
$layout_sidebar = 'sidebar_bailleur_module';

ob_start();
?>
<style>
.bb-head { display:flex; align-items:baseline; gap:12px; margin-bottom:16px; }
.bb-count { background:#0e7490; color:#fff; border-radius:99px; padding:2px 12px; font-size:13px; font-weight:800; }
.bb-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
.bb-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
.bb-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; color:#0f172a; }
.bb-table tr:last-child td { border-bottom:none; }
.bb-occ { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:700; }
.bb-occ.loue { background:#ecfdf5; color:#047857; }
.bb-occ.libre { background:#fef2f2; color:#b91c1c; }
.bb-empty { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:40px; text-align:center; color:#64748b; }
.bb-link { color:#0e7490; text-decoration:none; font-weight:700; }
.bb-link:hover { text-decoration:underline; }
</style>

<div class="bb-head">
  <h1 style="font-size:20px;font-weight:800;">🏠 Mes biens</h1>
  <span class="bb-count"><?= count($rows) ?></span>
</div>

<?php if (empty($rows)): ?>
  <div class="bb-empty">Aucun bien rattaché aux propriétaires accessibles.</div>
<?php else: ?>
  <table class="bb-table">
    <thead>
      <tr><th>Référence</th><th>Type</th><th>Immeuble / Adresse</th><th>Propriétaire</th><th>Surface</th><th>Loyer HC</th><th>Occupation</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $occ = strtolower((string)($r['occupation_bien'] ?? ''));
        $loue = str_contains($occ, 'lou') || str_contains($occ, 'occup');
      ?>
        <tr>
          <td><strong><?= e($r['reference_bien'] ?: ('#' . $r['id'])) ?></strong></td>
          <td><?= e($r['type_lbl'] ?: '—') ?></td>
          <td><?= e($r['nom_immeuble'] ?: $r['adresse_1'] ?: '—') ?><?= $r['ville'] ? ' · ' . e($r['ville']) : '' ?></td>
          <td><?= e($r['prop_nom']) ?></td>
          <td><?= $r['surface_habitable'] ? e(rtrim(rtrim(number_format((float)$r['surface_habitable'], 2, ',', ' '), '0'), ',')) . ' m²' : '—' ?></td>
          <td><?= $r['loyer_hc'] ? number_format((float)$r['loyer_hc'], 0, ',', ' ') . ' €' : '—' ?></td>
          <td><?php if ($occ !== ''): ?><span class="bb-occ <?= $loue ? 'loue' : 'libre' ?>"><?= $loue ? 'Loué' : 'Libre' ?></span><?php else: ?>—<?php endif; ?></td>
          <td><a class="bb-link" href="bien_detail.php?edit=<?= (int)$r['id'] ?>&section=descriptif">Ouvrir →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
