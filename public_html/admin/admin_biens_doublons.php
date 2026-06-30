<?php
/**
 * admin/admin_biens_doublons.php — Assistant de fusion des BIENS en doublon (même référence).
 *
 * Détecte les biens partageant la même `reference_bien` et propose la fusion en 1 clic
 * par groupe : le « maître » (le plus complet) est conservé, les coquilles sont absorbées
 * (baux, annonces, dossier, GED réaffectés ; source soft-deleted). Super admin.
 *
 * Réutilise l'endpoint existant `api/admin_biens_merge_action.php`.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1 || $roleId === 7) || (function_exists('is_super_admin') && is_super_admin());
if (!$isAdmin) { http_response_code(403); exit('Réservé aux super administrateurs.'); }

// ── Biens partageant une même référence (vrais doublons) ──
$rows = [];
try {
    $sql = "SELECT b.id, b.reference_bien, b.designation, b.surface_habitable, b.statut_bien,
                   b.id_immeuble, b.id_proprietaire,
                   COALESCE(NULLIF(i.nom_immeuble,''), i.adresse_1) AS immeuble,
                   COALESCE(NULLIF(t.nom_affichage,''), NULLIF(t.raison_sociale,''), p.societe, CONCAT_WS(' ',t.nom,t.prenom)) AS proprio,
                   (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id) AS nb_baux,
                   (SELECT COUNT(*) FROM annonces a  WHERE a.id_bien = b.id)  AS nb_annonces
            FROM biens b
            LEFT JOIN immeubles i     ON i.id = b.id_immeuble
            LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
            LEFT JOIN tiers t         ON t.id = p.id_tiers
            WHERE b.reference_bien <> '' AND b.reference_bien IS NOT NULL
              AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
              AND b.reference_bien IN (
                  SELECT reference_bien FROM biens
                  WHERE reference_bien <> '' AND reference_bien IS NOT NULL
                    AND (statut_bien IS NULL OR statut_bien NOT IN ('supprime','archive'))
                  GROUP BY reference_bien HAVING COUNT(*) > 1)
            ORDER BY b.reference_bien, b.id";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

// ── Regroupement par référence + maître recommandé (le plus complet) ──
$groups = [];
foreach ($rows as $r) { $groups[$r['reference_bien']]['rows'][] = $r; }
foreach ($groups as $ref => &$g) {
    usort($g['rows'], function ($a, $b) {
        $score = fn($x) => ((float)$x['surface_habitable'] > 0 ? 4 : 0)
                         + ((int)$x['nb_baux'] > 0 ? 3 : 0)
                         + ((int)$x['nb_annonces'] > 0 ? 2 : 0)
                         + (!empty($x['id_proprietaire']) ? 1 : 0);
        $c = $score($b) <=> $score($a); if ($c) return $c;
        return (int)$a['id'] <=> (int)$b['id']; // sinon le plus ancien
    });
}
unset($g);

$labelOf = fn($r) => trim((string)($r['designation'] ?? '')) !== '' ? $r['designation'] : ($r['reference_bien'] ?: ('Bien #' . $r['id']));

$pageTitle    = 'Biens — Doublons (même référence)';
$pageSubtitle = 'Super admin · fusion en 1 clic par groupe (le maître est conservé)';
$extraCss = <<<'CSS'
<style>
body { background:#f7f4ef; }
.pd-wrap { max-width:1100px; margin:0 auto; padding:8px 16px 60px; }
.pd-kpi { display:inline-block; background:#fff; border-radius:12px; padding:12px 18px; box-shadow:4px 4px 10px #d8d4ce,-4px -4px 10px #fff; text-align:center; margin-bottom:14px; }
.pd-kpi b { font-size:22px; color:#243B5C; display:block; }
.pd-kpi span { font-size:11px; color:#8a8680; text-transform:uppercase; letter-spacing:.05em; }
.pd-group { background:#fff; border-radius:12px; padding:14px 18px; margin-bottom:14px; box-shadow:3px 3px 8px #d8d4ce,-3px -3px 8px #fff; }
.pd-group.done { opacity:.5; }
.pd-gh { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.pd-gh .ref { font-family:'DM Mono',monospace; font-weight:800; color:#243B5C; }
.pd-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f1ece4; font-size:13px; }
.pd-row:last-child { border-bottom:none; }
.pd-row.master { background:#f0fbf4; border-radius:8px; padding:8px 10px; }
.pd-name { flex:1; } .pd-name b { color:#1f2937; }
.pd-meta { color:#8a8680; font-size:11.5px; }
.pd-tag { font-size:10px; font-weight:800; padding:2px 8px; border-radius:99px; }
.pd-tag.m { background:#16a34a22; color:#15803d; } .pd-tag.s { background:#e2e8f0; color:#475569; }
.pd-btn { border:none; border-radius:9px; padding:9px 16px; font-weight:800; font-size:13px; cursor:pointer; background:linear-gradient(135deg,#0f9d58,#0b8043); color:#fff; }
.pd-btn:disabled { opacity:.5; cursor:not-allowed; }
.pd-msg { font-size:12px; font-weight:700; margin-left:8px; }
.pd-empty { background:#fff; border-radius:12px; padding:30px; text-align:center; color:#15803d; }
a.pd-fiche { color:#4878a6; text-decoration:none; font-size:11.5px; }
</style>
CSS;
include __DIR__ . '/../inc/agency_layout_top.php';
?>
<div class="pd-wrap">
  <div class="pd-kpi"><b><?= count($groups) ?></b><span>Références en doublon</span></div>

  <?php if (!$groups): ?>
    <div class="pd-empty">✅ Aucun bien en doublon de référence.</div>
  <?php else: ?>
    <div style="font-size:12.5px;color:#8a8680;margin-bottom:12px;">
      Le <b>maître</b> (✅ vert, le plus complet) est conservé ; les autres sont <b>absorbés</b>
      (baux, annonces, dossier, GED réaffectés ; bien source mis en « supprimé »). Change le maître avec le bouton radio. <b>Irréversible.</b>
    </div>
    <?php foreach ($groups as $ref => $g):
        $gid = substr(md5($ref), 0, 8);
    ?>
    <div class="pd-group" id="grp-<?= $gid ?>" data-master="<?= (int)$g['rows'][0]['id'] ?>">
      <div class="pd-gh">
        <span class="ref">🏠 <?= h($ref) ?> · <?= count($g['rows']) ?> biens</span>
        <span>
          <button type="button" class="pd-btn" onclick="pdMerge('<?= $gid ?>')">⛶ Fusionner ce groupe</button>
          <span class="pd-msg" id="msg-<?= $gid ?>"></span>
        </span>
      </div>
      <?php foreach ($g['rows'] as $i => $r): $isMaster = $i === 0; ?>
        <div class="pd-row <?= $isMaster ? 'master' : '' ?>" data-id="<?= (int)$r['id'] ?>">
          <input type="radio" name="master-<?= $gid ?>" value="<?= (int)$r['id'] ?>" <?= $isMaster ? 'checked' : '' ?> onchange="pdSetMaster('<?= $gid ?>',<?= (int)$r['id'] ?>)" title="Désigner comme maître (conservé)">
          <span class="pd-tag <?= $isMaster ? 'm' : 's' ?>" id="tag-<?= $gid ?>-<?= (int)$r['id'] ?>"><?= $isMaster ? 'MAÎTRE' : 'absorbé' ?></span>
          <span class="pd-name">
            <b><?= h($labelOf($r)) ?></b>
            <span class="pd-meta"> · bien #<?= (int)$r['id'] ?><?= (float)$r['surface_habitable'] > 0 ? ' · ' . rtrim(rtrim(number_format((float)$r['surface_habitable'],2,'.',''),'0'),'.') . ' m²' : ' · sans surface' ?><?= (int)$r['nb_baux'] > 0 ? ' · ' . (int)$r['nb_baux'] . ' bail' : '' ?><?= (int)$r['nb_annonces'] > 0 ? ' · ' . (int)$r['nb_annonces'] . ' annonce' : '' ?><?= $r['immeuble'] ? ' · ' . h($r['immeuble']) : '' ?><?= $r['proprio'] ? ' · ' . h($r['proprio']) : '' ?></span>
          </span>
          <a class="pd-fiche" href="<?= h(app_url('/bien_360.php?id=' . (int)$r['id'])) ?>" target="_blank">fiche ↗</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  var API = <?= json_encode(app_url('/api/admin_biens_merge_action.php')) ?>;
  window.pdSetMaster = function(gid, id){
    var grp = document.getElementById('grp-'+gid); grp.dataset.master = id;
    grp.querySelectorAll('.pd-row').forEach(function(row){
      var rid = row.dataset.id, master = (String(rid) === String(id));
      row.classList.toggle('master', master);
      var tag = document.getElementById('tag-'+gid+'-'+rid);
      if (tag){ tag.textContent = master ? 'MAÎTRE' : 'absorbé'; tag.className = 'pd-tag ' + (master ? 'm' : 's'); }
    });
  };
  window.pdMerge = async function(gid){
    var grp = document.getElementById('grp-'+gid);
    var master = parseInt(grp.dataset.master, 10), sources = [];
    grp.querySelectorAll('.pd-row').forEach(function(row){ var id = parseInt(row.dataset.id,10); if (id !== master) sources.push(id); });
    if (!sources.length) return;
    var msg = document.getElementById('msg-'+gid);
    if (!confirm('Fusionner ' + sources.length + ' bien(s) vers #' + master + ' ?\nAction irréversible.')) return;
    var btn = grp.querySelector('.pd-btn'); btn.disabled = true; msg.style.color = '#8a6d1b'; msg.textContent = 'Fusion…';
    var done = 0, errs = [];
    for (var i = 0; i < sources.length; i++){
      try {
        var fd = new FormData(); fd.append('source_id', sources[i]); fd.append('destination_id', master);
        var r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'}); var j = await r.json();
        if (j.ok) done++; else errs.push('#'+sources[i]+': '+(j.error||'échec'));
      } catch(e){ errs.push('#'+sources[i]+': réseau'); }
    }
    if (errs.length){ msg.style.color = '#c0392b'; msg.textContent = '✓ '+done+' · ✗ '+errs.length+' ('+errs[0]+')'; btn.disabled = false; }
    else { msg.style.color = '#15803d'; msg.textContent = '✓ '+done+' fusionné(s)'; grp.classList.add('done'); }
  };
})();
</script>
<?php include __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
