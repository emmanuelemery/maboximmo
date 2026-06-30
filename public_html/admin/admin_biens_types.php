<?php
/**
 * admin/admin_biens_types.php — Triage RAPIDE du type des biens.
 *
 * Balaye tous les biens (cards façon agency_biens) et, sous les liens documents,
 * 5 boutons : Maison · Appartement · Entrepôt · Bureau · Commerce. Un clic affecte
 * le type (biens.id_bien_type) en AJAX, sans recharger. Super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1 || $roleId === 7) || (function_exists('is_super_admin') && is_super_admin());
if (!$isAdmin) { http_response_code(403); exit('Réservé aux super administrateurs.'); }

// Scope société / agence. Super admin : TOUT par défaut (pas de filtre société tant
// qu'on n'en choisit pas une). Non-admin : verrouillé sur sa société.
$mySoc    = (int)($_SESSION['id_societe'] ?? 0);
$scopeSoc = isset($_GET['societe']) && ctype_digit((string)$_GET['societe']) ? (int)$_GET['societe'] : ($isAdmin ? 0 : $mySoc);
$scopeAg  = isset($_GET['agence'])  && ctype_digit((string)$_GET['agence'])  ? (int)$_GET['agence']  : 0;
$onlyUntyped = ($_GET['filtre'] ?? '') === 'atyper';

// Sélecteurs : sociétés + agences qui ONT des biens (pour ne pas deviner l'URL).
$societesOpt = []; $agencesOpt = [];
try {
    $societesOpt = $pdo->query("SELECT DISTINCT s.id, s.nom FROM biens b JOIN societes s ON s.id=b.id_societe ORDER BY s.nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $agencesOpt  = $pdo->query("SELECT DISTINCT a.id, a.nom_agence, a.ville, a.id_societe FROM biens b JOIN agences a ON a.id=b.id_agence ORDER BY a.nom_agence")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$conds = ["(b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu','perdu_gestion'))"];
$params = [];
if ($scopeSoc > 0) { $conds[] = 'b.id_societe = ?'; $params[] = $scopeSoc; }
if ($scopeAg  > 0) { $conds[] = 'b.id_agence = ?';  $params[] = $scopeAg;  }
if ($onlyUntyped)  { $conds[] = 'b.id_bien_type IS NULL'; }

// Types proposés (référentiel bien_types).
$TYPES = [6 => '🏠 Maison', 1 => '🏢 Appartement', 23 => '📦 Entrepôt', 20 => '💼 Bureau', 18 => '🏪 Commerce'];

$biens = [];
try {
    $sql = "SELECT b.id, b.reference_bien, b.designation, b.surface_habitable, b.id_bien_type,
                   COALESCE(NULLIF(i.nom_immeuble,''), i.adresse_1, b.adresse_1) AS titre,
                   COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
                   COALESCE(bt.libelle, bt2.label) AS type_actuel,
                   (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(bb.locataire_raison_sociale,''), NULLIF(TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)),'')) SEPARATOR ', ')
                      FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS locataires,
                   (SELECT GROUP_CONCAT(DISTINCT m.type_mandat SEPARATOR ',')
                      FROM mandats m WHERE m.id_bien = b.id AND m.statut = 'actif') AS mandats_actifs
            FROM biens b
            LEFT JOIN immeubles i      ON i.id  = b.id_immeuble
            LEFT JOIN bien_types bt     ON bt.id  = b.id_bien_type
            LEFT JOIN base_types_bien bt2 ON bt2.id = b.id_type_bien
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY b.id_bien_type IS NOT NULL, b.reference_bien ASC
            LIMIT 3000";
    $st = $pdo->prepare($sql); $st->execute($params);
    $biens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ex) { $biens = []; }

$nbTotal   = count($biens);
$nbUntyped = count(array_filter($biens, fn($b) => empty($b['id_bien_type'])));
$csrf = function_exists('csrf_token') ? csrf_token('admin_bien_type') : '';

$appLayout = true; $pageTitle = 'Triage des types de biens'; $bodyClass = ''; $robots = 'noindex, nofollow';
include __DIR__ . '/../inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/../inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  .mbi-main{ background:#fafbfc; }
  .bt-bar { position:sticky; top:0; z-index:50; display:flex; gap:14px; align-items:center; flex-wrap:wrap;
    padding:12px 16px; background:rgba(250,251,252,.95); backdrop-filter:blur(6px); border-bottom:1px solid #eee; }
  .bt-kpi { background:#fff; border-radius:12px; padding:8px 16px; box-shadow:3px 3px 8px #e3e6ec,-3px -3px 8px #fff; text-align:center; }
  .bt-kpi b { font-size:20px; color:#243B5C; display:block; line-height:1; } .bt-kpi span { font-size:10px; color:#8a8680; text-transform:uppercase; }
  .bt-tog { text-decoration:none; font-weight:700; font-size:13px; padding:9px 16px; border-radius:10px; border:1px solid #c4b5fd; background:#ede9fe; color:#5b21b6; }
  .bt-tog.active { background:#5b21b6; color:#fff; }
  .bt-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; padding:16px; }
  .bt-card { background:#fff; border:1px solid #ece7df; border-radius:14px; padding:14px 16px; box-shadow:3px 3px 8px #e3e6ec,-3px -3px 8px #fff; }
  .bt-card.untyped { border-left:4px solid #dc2626; }
  .bt-ref { font-family:'DM Mono',monospace; font-size:12px; color:#4878a6; font-weight:700; }
  .bt-title { font-size:15px; font-weight:800; color:#243B5C; margin:2px 0; }
  .bt-meta { font-size:12px; color:#8a8680; margin-bottom:8px; }
  .bt-cur { display:inline-block; font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; margin-bottom:8px; }
  .bt-cur.ok { background:#dcfce7; color:#15803d; } .bt-cur.no { background:#fee2e2; color:#b91c1c; }
  .bt-links { display:flex; gap:8px; margin-bottom:8px; }
  .bt-links a { font-size:11.5px; color:#4878a6; text-decoration:none; background:#f1f5f9; padding:4px 10px; border-radius:7px; }
  .bt-types { display:flex; gap:6px; flex-wrap:wrap; border-top:1px dashed #eee; padding-top:8px; }
  .bt-btn { cursor:pointer; border:1px solid #d8d2c8; background:#fff; border-radius:9px; padding:7px 11px; font-size:12.5px; font-weight:700; color:#3a3830; }
  .bt-btn:hover { background:#f6f4f0; }
  .bt-btn.active { background:#243B5C; color:#fff; border-color:#243B5C; }
  .bt-empty { padding:40px; text-align:center; color:#8a8680; }
</style>

<div class="mbi-main">
  <div class="bt-bar">
    <div class="bt-kpi"><b><?= $nbTotal ?></b><span>Biens</span></div>
    <div class="bt-kpi"><b style="color:#dc2626;"><?= $nbUntyped ?></b><span>Sans type</span></div>
    <?php $qsBase = array_filter(['societe'=>$scopeSoc ?: null, 'agence'=>$scopeAg ?: null]); ?>
    <a class="bt-tog <?= $onlyUntyped ? 'active' : '' ?>" href="?<?= e(http_build_query($qsBase + ['filtre'=>$onlyUntyped ? '' : 'atyper'])) ?>">
      <?= $onlyUntyped ? '✓ Sans type seulement' : 'Afficher : sans type seulement' ?>
    </a>
    <span style="font-size:12px;color:#8a8680;">Clique un type → enregistré aussitôt.</span>
  </div>

  <?php $f = $onlyUntyped ? ['filtre'=>'atyper'] : []; ?>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 16px;font-size:12.5px;">
    <span style="font-weight:700;color:#8a8680;">Société :</span>
    <a href="?<?= e(http_build_query($f)) ?>" style="text-decoration:none;padding:5px 12px;border-radius:8px;<?= $scopeSoc<=0?'background:#243B5C;color:#fff;':'background:#fff;color:#243B5C;box-shadow:2px 2px 6px #e3e6ec;' ?>">Toutes</a>
    <?php foreach ($societesOpt as $s): ?>
      <a href="?<?= e(http_build_query($f + ['societe'=>(int)$s['id']])) ?>" style="text-decoration:none;padding:5px 12px;border-radius:8px;<?= $scopeSoc===(int)$s['id']?'background:#243B5C;color:#fff;':'background:#fff;color:#243B5C;box-shadow:2px 2px 6px #e3e6ec;' ?>"><?= e($s['nom']) ?></a>
    <?php endforeach; ?>
    <?php if ($agencesOpt): ?>
      <span style="font-weight:700;color:#8a8680;margin-left:10px;">Agence :</span>
      <a href="?<?= e(http_build_query($f + ($scopeSoc>0?['societe'=>$scopeSoc]:[]))) ?>" style="text-decoration:none;padding:5px 12px;border-radius:8px;<?= $scopeAg<=0?'background:#4878a6;color:#fff;':'background:#fff;color:#4878a6;box-shadow:2px 2px 6px #e3e6ec;' ?>">Toutes</a>
      <?php foreach ($agencesOpt as $a): if ($scopeSoc>0 && (int)$a['id_societe']!==$scopeSoc) continue; ?>
        <a href="?<?= e(http_build_query($f + ($scopeSoc>0?['societe'=>$scopeSoc]:[]) + ['agence'=>(int)$a['id']])) ?>" style="text-decoration:none;padding:5px 12px;border-radius:8px;<?= $scopeAg===(int)$a['id']?'background:#4878a6;color:#fff;':'background:#fff;color:#4878a6;box-shadow:2px 2px 6px #e3e6ec;' ?>"><?= e($a['ville'] ?: $a['nom_agence']) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if (!$biens): ?>
    <div class="bt-empty">Aucun bien dans ce périmètre.</div>
  <?php else: ?>
  <div class="bt-grid">
    <?php foreach ($biens as $b):
        $cur = (int)($b['id_bien_type'] ?? 0);
        $titre = $b['titre'] ?: ($b['designation'] ?: ('Bien #' . $b['id']));
    ?>
    <div class="bt-card <?= $cur ? '' : 'untyped' ?>" id="card-<?= (int)$b['id'] ?>">
      <div class="bt-ref"><?= e($b['reference_bien'] ?: ('#' . $b['id'])) ?></div>
      <div class="bt-title"><?= e($titre) ?></div>
      <div class="bt-meta"><?= e($b['ville'] ?? '') ?><?= (float)$b['surface_habitable'] > 0 ? ' · ' . rtrim(rtrim(number_format((float)$b['surface_habitable'],2,'.',''),'0'),'.') . ' m²' : '' ?></div>
      <?php
        // Mandat(s) : Vente / Location / Gestion
        $mand = array_filter(array_map('trim', explode(',', (string)($b['mandats_actifs'] ?? ''))));
        $mandCol = ['vente'=>'#dc2626','location'=>'#2d5f6b','gestion'=>'#7c3aed'];
      ?>
      <?php if ($mand): ?>
        <div style="margin-bottom:6px;">
          <?php foreach ($mand as $mt): $c = $mandCol[strtolower($mt)] ?? '#6b7280'; ?>
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $c ?>18;color:<?= $c ?>;border:1px solid <?= $c ?>33;">📑 <?= e(ucfirst($mt)) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($b['designation'])): ?>
        <div style="font-size:12px;color:#5b5750;margin-bottom:6px;">📝 <?= e(mb_strimwidth((string)$b['designation'], 0, 90, '…')) ?></div>
      <?php endif; ?>
      <?php if (!empty($b['locataires'])): ?>
        <div style="font-size:12px;color:#2d5f6b;margin-bottom:6px;">🔑 <?= e($b['locataires']) ?></div>
      <?php endif; ?>
      <div class="bt-cur <?= $cur ? 'ok' : 'no' ?>" id="cur-<?= (int)$b['id'] ?>">
        <?= $cur ? '✓ ' . e($b['type_actuel'] ?? '') : '⚠️ ' . ($b['type_actuel'] ? e($b['type_actuel']) . ' (legacy)' : 'non typé') ?>
      </div>
      <div class="bt-links">
        <a href="<?= e(app_url('/bien_detail.php?edit=' . (int)$b['id'])) ?>" target="_blank">📝 Fiche</a>
        <a href="<?= e(app_url('/bien_detail.php?edit=' . (int)$b['id'] . '&tab=documents')) ?>" target="_blank">📁 Documents</a>
      </div>
      <div class="bt-types" data-id="<?= (int)$b['id'] ?>">
        <?php foreach ($TYPES as $tid => $lbl): ?>
          <button type="button" class="bt-btn <?= $cur === $tid ? 'active' : '' ?>" data-type="<?= $tid ?>" onclick="btSet(<?= (int)$b['id'] ?>,<?= $tid ?>,this)"><?= e($lbl) ?></button>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:6px;margin-top:8px;border-top:1px dashed #eee;padding-top:8px;">
        <button type="button" class="bt-stbtn" style="border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:9px;padding:6px 11px;font-size:12px;font-weight:700;cursor:pointer;" onclick="btStatut(<?= (int)$b['id'] ?>,'vendu',this)">💰 Vendu</button>
        <button type="button" class="bt-stbtn" style="border:1px solid #fed7aa;background:#fff7ed;color:#b45309;border-radius:9px;padding:6px 11px;font-size:12px;font-weight:700;cursor:pointer;" onclick="btStatut(<?= (int)$b['id'] ?>,'perdu_gestion',this)">🚪 Perte gestion</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var API = <?= json_encode(app_url('/api/admin_bien_set_type.php')) ?>;
  var CSRF = <?= json_encode($csrf) ?>;
  var LBL = <?= json_encode(array_map(fn($l) => trim(preg_replace('/^\S+\s/','',$l)), $TYPES), JSON_HEX_TAG) ?>;
  window.btSet = async function(idBien, idType, btn){
    var box = btn.parentElement;
    box.querySelectorAll('.bt-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    var fd = new FormData(); fd.append('id_bien', idBien); fd.append('id_bien_type', idType); fd.append('csrf_token', CSRF);
    try {
      var r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'}); var j = await r.json();
      var cur = document.getElementById('cur-' + idBien);
      var card = document.getElementById('card-' + idBien);
      if (j.ok) { if(cur){ cur.textContent = '✓ ' + (j.libelle||''); cur.className = 'bt-cur ok'; } if(card){ card.classList.remove('untyped'); } }
      else { if(cur){ cur.textContent = '✗ ' + (j.error||'échec'); cur.className = 'bt-cur no'; } btn.classList.remove('active'); }
    } catch(e){ btn.classList.remove('active'); alert('Réseau : ' + e); }
  };

  var APIST = <?= json_encode(app_url('/api/admin_bien_set_statut.php')) ?>;
  window.btStatut = async function(idBien, statut, btn){
    var lbl = statut === 'vendu' ? 'VENDU' : 'PERTE DE GESTION';
    if (!confirm('Marquer ce bien « ' + lbl + ' » ? Il sort de la liste active.')) return;
    btn.disabled = true;
    var fd = new FormData(); fd.append('id_bien', idBien); fd.append('statut', statut); fd.append('csrf_token', CSRF);
    try {
      var r = await fetch(APIST, {method:'POST', body:fd, credentials:'same-origin'}); var j = await r.json();
      var card = document.getElementById('card-' + idBien);
      if (j.ok && card) { card.style.transition='opacity .3s'; card.style.opacity='0'; setTimeout(function(){ card.remove(); }, 300); }
      else { btn.disabled = false; alert('✗ ' + (j.error || 'échec')); }
    } catch(e){ btn.disabled = false; alert('Réseau : ' + e); }
  };
})();
</script>
<?php include __DIR__ . '/../inc/footer.php'; ?>
