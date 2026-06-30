<?php
/**
 * admin/admin_proprietaires_doublons.php — Assistant de FUSION EN MASSE des propriétaires doublons.
 *
 * Détecte les groupes de TIERS PROPRIÉTAIRES en doublon (même SIREN, ou même raison sociale
 * normalisée, ou même nom+prénom) et propose la fusion en 1 clic par groupe : tout est
 * réaffecté au « maître » (le plus complet / le plus de biens), les coquilles sont absorbées.
 *
 * Réutilise l'endpoint de fusion existant `api/admin_tiers_merge_action.php` (transactionnel,
 * relink tiers_roles/proprietaires/baux/contacts… puis DELETE du tiers source). Super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1 || $roleId === 7) || (function_exists('is_super_admin') && is_super_admin());
if (!$isAdmin) { http_response_code(403); exit('Réservé aux super administrateurs.'); }

// ── Normalisation d'une raison sociale / d'un nom pour le regroupement ──
$normalize = static function (string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U','’'=>"'"]);
    // Retire les formes juridiques courantes (en mot entier)
    $s = preg_replace('/\b(SCI|SARL|SASU|SAS|EURL|SNC|SCCV|SCM|SCP|SA|GFA|SC|INDIVISION|SCOP)\b/u', ' ', $s);
    // Garde uniquement alphanum
    $s = preg_replace('/[^A-Z0-9]+/u', '', $s);
    return (string)$s;
};

// ── Charge les tiers PROPRIÉTAIRES (ceux qui possèdent au moins un bien) ──
$owners = [];
try {
    $sql = "SELECT t.id, t.raison_sociale, t.nom, t.prenom, t.nom_affichage, t.siren, t.email, t.telephone,
                   t.type_tiers, t.id_societe,
                   (SELECT COUNT(*) FROM biens b JOIN proprietaires p ON p.id = b.id_proprietaire WHERE p.id_tiers = t.id) AS nb_biens,
                   (SELECT COUNT(*) FROM proprietaires p2 WHERE p2.id_tiers = t.id) AS nb_proprio
            FROM tiers t
            WHERE t.actif = 1
              AND EXISTS (SELECT 1 FROM proprietaires p WHERE p.id_tiers = t.id)";
    $owners = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $owners = []; }

// ── Regroupement : clé = SIREN si présent, sinon raison sociale normalisée, sinon nom+prénom ──
$groups = [];
foreach ($owners as $o) {
    $siren = preg_replace('/\D+/', '', (string)($o['siren'] ?? ''));
    if ($siren !== '' && strlen($siren) >= 9) {
        $key = 'SIREN:' . $siren; $kind = 'siren';
    } else {
        $rs = trim((string)($o['raison_sociale'] ?? ''));
        if ($rs !== '') { $nk = $normalize($rs); $key = 'RS:' . $nk; $kind = 'raison'; }
        else {
            $nk = $normalize(trim((string)($o['nom'] ?? '') . (string)($o['prenom'] ?? '')));
            $key = 'NP:' . $nk; $kind = 'nomprenom';
        }
        if (($nk ?? '') === '' || strlen($nk) < 4) continue; // trop court → ignore
    }
    $groups[$key]['kind'] = $kind;
    $groups[$key]['rows'][] = $o;
}

// Ne garde que les groupes à doublon (≥ 2 tiers distincts).
$dupGroups = [];
foreach ($groups as $key => $g) {
    if (count($g['rows']) < 2) continue;
    // Maître recommandé : plus de biens → a un SIREN → id le plus petit (le plus ancien)
    usort($g['rows'], function ($a, $b) {
        $c = (int)$b['nb_biens'] <=> (int)$a['nb_biens']; if ($c) return $c;
        $c = (trim((string)$b['siren']) !== '') <=> (trim((string)$a['siren']) !== ''); if ($c) return $c;
        return (int)$a['id'] <=> (int)$b['id'];
    });
    $dupGroups[$key] = $g;
}

// Tri des groupes : ceux avec le plus de tiers d'abord.
uasort($dupGroups, fn($a, $b) => count($b['rows']) <=> count($a['rows']));

$kindLbl = ['siren'=>'🔴 Même SIREN','raison'=>'🟡 Même raison sociale','nomprenom'=>'🟡 Même nom + prénom'];

$labelOf = function (array $o): string {
    $l = trim((string)($o['nom_affichage'] ?? '')) ?: trim((string)($o['raison_sociale'] ?? '')) ?: trim((string)($o['nom'] ?? '') . ' ' . (string)($o['prenom'] ?? ''));
    return $l !== '' ? $l : ('Tiers #' . $o['id']);
};

$pageTitle    = 'Propriétaires — Doublons (fusion en masse)';
$pageSubtitle = 'Super admin · détection automatique + fusion en 1 clic par groupe';
$extraCss = <<<'CSS'
<style>
body { background:#f7f4ef; }
.pd-wrap { max-width:1200px; margin:0 auto; padding:8px 16px 60px; }
.pd-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.pd-kpi { background:#fff; border-radius:12px; padding:12px 18px; box-shadow:4px 4px 10px #d8d4ce,-4px -4px 10px #fff; text-align:center; }
.pd-kpi b { font-size:22px; color:#243B5C; display:block; }
.pd-kpi span { font-size:11px; color:#8a8680; text-transform:uppercase; letter-spacing:.05em; }
.pd-group { background:#fff; border-radius:12px; padding:14px 18px; margin-bottom:14px; box-shadow:3px 3px 8px #d8d4ce,-3px -3px 8px #fff; }
.pd-group.done { opacity:.5; }
.pd-gh { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.pd-gh .badge { font-size:11px; font-weight:700; color:#8a4b00; }
.pd-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f1ece4; font-size:13px; }
.pd-row:last-child { border-bottom:none; }
.pd-row.master { background:#f0fbf4; border-radius:8px; padding:8px 10px; }
.pd-name { flex:1; }
.pd-name b { color:#1f2937; }
.pd-meta { color:#8a8680; font-size:11.5px; }
.pd-tag { font-size:10px; font-weight:800; padding:2px 8px; border-radius:99px; }
.pd-tag.m { background:#16a34a22; color:#15803d; }
.pd-tag.s { background:#e2e8f0; color:#475569; }
.pd-btn { border:none; border-radius:9px; padding:9px 16px; font-weight:800; font-size:13px; cursor:pointer; }
.pd-btn.go { background:linear-gradient(135deg,#0f9d58,#0b8043); color:#fff; }
.pd-btn:disabled { opacity:.5; cursor:not-allowed; }
.pd-msg { font-size:12px; font-weight:700; margin-left:8px; }
.pd-empty { background:#fff; border-radius:12px; padding:30px; text-align:center; color:#15803d; }
a.pd-fiche { color:#4878a6; text-decoration:none; font-size:11.5px; }
</style>
CSS;
include __DIR__ . '/../inc/agency_layout_top.php';
$csrf = function_exists('csrf_token') ? csrf_token('admin_tiers_merge') : '';
?>
<div class="pd-wrap">
  <div class="pd-head">
    <div class="pd-kpi"><b><?= count($dupGroups) ?></b><span>Groupes en doublon</span></div>
    <div class="pd-kpi"><b><?= array_sum(array_map(fn($g) => count($g['rows']) - 1, $dupGroups)) ?></b><span>Tiers à absorber</span></div>
    <a class="tm-btn ghost" style="text-decoration:none;padding:10px 16px;border-radius:9px;background:#fff;color:#4878a6;box-shadow:3px 3px 8px #d8d4ce,-3px -3px 8px #fff;font-weight:700;" href="<?= h(app_url('/admin/admin_tiers_merge.php')) ?>">→ Outil de fusion détaillé (un par un)</a>
  </div>

  <?php if (!$dupGroups): ?>
    <div class="pd-empty">✅ Aucun doublon de propriétaire détecté.</div>
  <?php else: ?>
    <div style="font-size:12.5px;color:#8a8680;margin-bottom:12px;">
      Le <b>maître</b> (✅ vert) est conservé ; les autres sont <b>absorbés</b> (biens, baux, rôles, mandats réaffectés). Tu peux changer le maître avec le bouton radio. Action <b>irréversible</b>.
    </div>
    <?php foreach ($dupGroups as $key => $g):
        $gid = substr(md5($key), 0, 8);
    ?>
    <div class="pd-group" id="grp-<?= $gid ?>" data-master="<?= (int)$g['rows'][0]['id'] ?>">
      <div class="pd-gh">
        <span class="badge"><?= h($kindLbl[$g['kind']] ?? '🟡 Doublon') ?> · <?= count($g['rows']) ?> tiers</span>
        <span>
          <button type="button" class="pd-btn go" onclick="pdMerge('<?= $gid ?>')">⛶ Fusionner ce groupe</button>
          <span class="pd-msg" id="msg-<?= $gid ?>"></span>
        </span>
      </div>
      <?php foreach ($g['rows'] as $i => $o):
          $isMaster = $i === 0;
      ?>
        <div class="pd-row <?= $isMaster ? 'master' : '' ?>" data-id="<?= (int)$o['id'] ?>">
          <input type="radio" name="master-<?= $gid ?>" value="<?= (int)$o['id'] ?>" <?= $isMaster ? 'checked' : '' ?> onchange="pdSetMaster('<?= $gid ?>',<?= (int)$o['id'] ?>)" title="Désigner comme maître (conservé)">
          <span class="pd-tag <?= $isMaster ? 'm' : 's' ?>" id="tag-<?= $gid ?>-<?= (int)$o['id'] ?>"><?= $isMaster ? 'MAÎTRE' : 'absorbé' ?></span>
          <span class="pd-name">
            <b><?= h($labelOf($o)) ?></b>
            <span class="pd-meta"> · tiers #<?= (int)$o['id'] ?> · <?= (int)$o['nb_biens'] ?> bien(s)<?= trim((string)$o['siren']) !== '' ? ' · SIREN ' . h($o['siren']) : '' ?><?= $o['email'] ? ' · ' . h($o['email']) : '' ?></span>
          </span>
          <a class="pd-fiche" href="<?= h(app_url('/tiers_360.php?id=' . (int)$o['id'])) ?>" target="_blank">fiche ↗</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  var API = <?= json_encode(app_url('/api/admin_tiers_merge_action.php')) ?>;
  var CSRF = <?= json_encode($csrf) ?>;

  window.pdSetMaster = function(gid, id){
    var grp = document.getElementById('grp-'+gid);
    grp.dataset.master = id;
    grp.querySelectorAll('.pd-row').forEach(function(row){
      var rid = row.dataset.id, master = (String(rid) === String(id));
      row.classList.toggle('master', master);
      var tag = document.getElementById('tag-'+gid+'-'+rid);
      if (tag){ tag.textContent = master ? 'MAÎTRE' : 'absorbé'; tag.className = 'pd-tag ' + (master ? 'm' : 's'); }
    });
  };

  window.pdMerge = async function(gid){
    var grp = document.getElementById('grp-'+gid);
    var master = parseInt(grp.dataset.master, 10);
    var sources = [];
    grp.querySelectorAll('.pd-row').forEach(function(row){
      var id = parseInt(row.dataset.id, 10);
      if (id !== master) sources.push(id);
    });
    if (!sources.length) return;
    var msg = document.getElementById('msg-'+gid);
    if (!confirm('Fusionner ' + sources.length + ' tiers vers #' + master + ' ?\nAction irréversible.')) return;
    var btn = grp.querySelector('.pd-btn.go'); btn.disabled = true;
    msg.style.color = '#8a6d1b'; msg.textContent = 'Fusion…';
    var done = 0, errs = [];
    for (var i = 0; i < sources.length; i++){
      try {
        var fd = new FormData();
        fd.append('source_id', sources[i]); fd.append('destination_id', master); fd.append('csrf_token', CSRF);
        var r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
        var j = await r.json();
        if (j.ok) done++; else errs.push('#'+sources[i]+': '+(j.error||'échec'));
      } catch(e){ errs.push('#'+sources[i]+': réseau'); }
    }
    if (errs.length){ msg.style.color = '#c0392b'; msg.textContent = '✓ '+done+' · ✗ '+errs.length+' ('+errs[0]+')'; btn.disabled = false; }
    else { msg.style.color = '#15803d'; msg.textContent = '✓ '+done+' fusionné(s)'; grp.classList.add('done'); }
  };
})();
</script>
<?php include __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
