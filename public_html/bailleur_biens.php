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
require_once __DIR__ . '/inc/entity_card.php';
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
               COALESCE(NULLIF(bt.libelle,''), NULLIF(btb.libelle,'')) AS type_lbl,
               i.nom_immeuble, i.adresse_1, i.ville,
               COALESCE(NULLIF(TRIM(p.societe),''), CONCAT(p.prenom,' ',p.nom)) AS prop_nom
        FROM biens b
        LEFT JOIN bien_types      bt  ON bt.id  = b.id_bien_type
        LEFT JOIN types_bien btb ON btb.id = b.id_type_bien
        LEFT JOIN immeubles       i   ON i.id   = b.id_immeuble
        LEFT JOIN proprietaires   p   ON p.id   = b.id_proprietaire
        WHERE b.id_proprietaire IN ({$in})
        ORDER BY prop_nom, i.ville, b.reference_bien
    ")->fetchAll(\PDO::FETCH_ASSOC);
}

$layout_title   = 'Mes biens';
$layout_module  = 'Ma Box Bailleur';
$layout_sidebar = 'sidebar_bailleur_module';

$base = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';
$nbLoues = 0; $nbLibres = 0;
foreach ($rows as $r) {
    $o = strtolower((string)($r['occupation_bien'] ?? ''));
    if (str_contains($o, 'lou') || str_contains($o, 'occup')) $nbLoues++;
    elseif ($o !== '') $nbLibres++;
}

ob_start();
?>
<style>
.bb-head { display:flex; align-items:center; gap:12px; margin-bottom:6px; flex-wrap:wrap; }
.bb-head h1 { font-size:20px; font-weight:800; color:#243B5C; margin:0; }
.bb-count { background:#0e7490; color:#fff; border-radius:99px; padding:2px 12px; font-size:13px; font-weight:800; }
.bb-kpi { font-size:12px; color:#5a5650; } .bb-kpi b{ color:#243B5C; }
.bb-search { width:100%; max-width:420px; padding:9px 14px; border:1px solid #d8d3cb; border-radius:10px;
  font-size:14px; background:#fff; margin:12px 0 16px; box-shadow: inset 2px 2px 5px #ece7e0; }
.bb-search:focus { outline:2px solid #0e7490; border-color:#0e7490; }
.bb-empty { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:50px; text-align:center; color:#64748b; }
.bb-empty .icon{ font-size:44px; display:block; margin-bottom:10px; }
.bi-occ { display:inline-block; padding:2px 9px; border-radius:99px; font-size:10.5px; font-weight:700; }
.bi-occ.loue { background:#e1f0e3; color:#2c6a3e; } .bi-occ.libre { background:#fdecec; color:#b3413b; }
.bi-line { font-size:12px; color:#5a5650; margin-top:4px; } .bi-line b{ color:#243B5C; }
</style>

<div class="bb-head">
  <h1>🏠 Mes biens</h1>
  <span class="bb-count"><?= count($rows) ?></span>
  <span class="bb-kpi">🟢 <b><?= $nbLoues ?></b> loué(s) · 🔴 <b><?= $nbLibres ?></b> libre(s)</span>
</div>

<?php if (empty($rows)): ?>
  <div class="bb-empty"><span class="icon">🏠</span>Aucun bien rattaché aux propriétaires accessibles.</div>
<?php else: ?>
  <input type="text" id="bbSearch" class="bb-search" placeholder="🔎 Rechercher un bien, immeuble, ville, propriétaire…" autocomplete="off" oninput="bbFilter()">
  <?php entity_card_assets(); ?>
  <div class="ec-grid" id="bbGrid">
    <?php foreach ($rows as $r):
      $bid   = (int)$r['id'];
      $occ   = strtolower((string)($r['occupation_bien'] ?? ''));
      $loue  = str_contains($occ, 'lou') || str_contains($occ, 'occup');
      $accent = $occ === '' ? '#84a98c' : ($loue ? '#2d5f6b' : '#b45309');
      $title = trim((string)($r['nom_immeuble'] ?: $r['adresse_1'])) ?: ('Bien ' . $bid);
      $ville = trim((string)$r['ville']);
      $sub   = $ville !== '' ? '📍 ' . e($ville) : '';
      $chips = [];
      if ((float)$r['loyer_hc'] > 0) $chips[] = '💶 <strong>' . number_format((float)$r['loyer_hc'], 0, ',', ' ') . '</strong>&nbsp;€';
      if ((float)$r['surface_habitable'] > 0) $chips[] = '📐 ' . rtrim(rtrim(number_format((float)$r['surface_habitable'], 1, ',', ' '), '0'), ',') . '&nbsp;m²';
      $badge = '<div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">'
             . ($r['type_lbl'] ? '<span style="background:#eef2f7;color:#475569;border-radius:6px;padding:2px 8px;font-size:10.5px;font-weight:700;">' . e($r['type_lbl']) . '</span>' : '')
             . ($occ !== '' ? '<span class="bi-occ ' . ($loue ? 'loue' : 'libre') . '">' . ($loue ? 'Loué' : 'Libre') . '</span>' : '')
             . '</div>';
      $extra = '';
      if (trim((string)$r['prop_nom']) !== '') $extra .= '<div class="bi-line">👤 <b>' . e($r['prop_nom']) . '</b></div>';
      $extra .= '<div class="bi-line" style="margin-top:8px;"><a href="' . e($base) . 'bien_detail.php?edit=' . $bid . '&section=descriptif" target="_top" onclick="event.stopPropagation()" style="color:#2d4a72;background:#f3f6fb;border:1px solid #e3ebf5;border-radius:9px;padding:5px 10px;font-weight:700;text-decoration:none;font-size:11.5px;">📝 Fiche du bien</a></div>';
      $searchTxt = mb_strtolower(trim(($r['reference_bien'] ?? '') . ' ' . $title . ' ' . $ville . ' ' . ($r['type_lbl'] ?? '') . ' ' . ($r['prop_nom'] ?? '')), 'UTF-8');
      entity_card([
        'accent' => $accent,
        'url'    => $base . 'bien_360.php?id=' . $bid,
        'ref'    => (string)($r['reference_bien'] ?: ''),
        'title'  => $title,
        'sub'    => $sub,
        'badge'  => $badge,
        'chips'  => $chips,
        'extra'  => $extra,
        'data'   => ['name' => $searchTxt],
      ]);
    endforeach; ?>
  </div>
  <div class="bb-empty" id="bbNoResult" style="display:none;margin-top:14px;">🔎 Aucun bien ne correspond à votre recherche.</div>
<?php endif; ?>

<script>
function bbFilter(){
  var q=(document.getElementById('bbSearch').value||'').trim().toLowerCase();
  var cards=document.querySelectorAll('#bbGrid .ec-card'), vis=0;
  cards.forEach(function(c){ var ok=(c.getAttribute('data-name')||'').indexOf(q)!==-1; c.style.display=ok?'':'none'; if(ok)vis++; });
  var nr=document.getElementById('bbNoResult'); if(nr) nr.style.display=(vis===0&&cards.length>0)?'block':'none';
}
</script>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
