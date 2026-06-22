<?php
/**
 * creancier_scan.php — Charger / réviser un document créancier.
 *
 * REPREND LE MODÈLE de la page review « dossier vente » (doc_upload_review.php) :
 * PDF à gauche (visualisation pure, sans nav de pages) · champs extraits/éditables
 * à droite · persistance BDD (creancier_doc_analyse). Standalone (pas de sidebar).
 *
 * Détecte si le dossier (débiteur) existe déjà ou non → rattache ou crée, puis classe en GED.
 *
 * GET :
 *   - (aucun)        → zone de dépôt d'un PDF (scan global).
 *   - ?analyse_id=N  → review du document analysé : PDF + champs + validation.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

function csc_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); exit('Accès réservé aux managers.'); }

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$analyseId = (int)($_GET['analyse_id'] ?? 0);
$preDossier = (int)($_GET['id_dossier'] ?? 0); // contexte dossier (depuis le cockpit) — optionnel

$csrfAnalyse = csrf_token('default');
$csrfUpdate  = csrf_token('creancier_doc_update');
$csrfValider = csrf_token('creancier_doc_valider');
$base = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';

// ─── Review state : charge l'analyse + données ───────────────────────────
$an = null; $data = []; $dossierMatch = null; $debiteur = '';
if ($analyseId > 0) {
    $st = $pdo->prepare("SELECT * FROM creancier_doc_analyse WHERE id = ? LIMIT 1");
    $st->execute([$analyseId]);
    $an = $st->fetch(PDO::FETCH_ASSOC);
    if (!$an) { http_response_code(404); exit('Analyse introuvable.'); }
    $data = json_decode((string)$an['donnees_json'], true) ?: [];
    $debiteur = trim((string)($data['debiteur_mentionne'] ?? ''));

    // Détection dossier : existant (rattaché ou retrouvé) ou nouveau.
    if ((int)$an['id_dossier'] > 0) {
        $d = $pdo->prepare("SELECT id, code, libelle FROM creancier_dossier WHERE id = ?");
        $d->execute([(int)$an['id_dossier']]);
        $dossierMatch = $d->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$dossierMatch) {
        $num = (string)$an['extr_numero_dossier'];
        $soc = $an['id_societe'];
        if ($num !== '') {
            $d = $pdo->prepare("SELECT id, code, libelle FROM creancier_dossier WHERE numero_dossier_adverse = ? AND (:soc IS NULL OR id_societe IS NULL OR id_societe = :soc) LIMIT 1");
            $d->bindValue(1, $num); $d->bindValue(':soc', $soc); $d->execute();
            $dossierMatch = $d->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$dossierMatch && $debiteur !== '') {
            $like = '%' . $debiteur . '%';
            $d = $pdo->prepare("SELECT DISTINCT dd.id, dd.code, dd.libelle FROM creancier_dossier dd
                LEFT JOIN creancier_dossier_lien l ON l.id_dossier=dd.id
                LEFT JOIN societes s ON l.entity_type='SOCIETE' AND s.id=l.entity_id
                LEFT JOIN tiers t ON l.entity_type='TIERS' AND t.id=l.entity_id
                WHERE (:soc IS NULL OR dd.id_societe IS NULL OR dd.id_societe=:soc)
                  AND (dd.libelle LIKE :q OR s.raison_sociale LIKE :q OR s.nom LIKE :q OR t.raison_sociale LIKE :q OR t.nom_affichage LIKE :q)
                LIMIT 1");
            $d->bindValue(':q', $like); $d->bindValue(':soc', $soc); $d->execute();
            $dossierMatch = $d->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
}

$crea  = (string)($an['extr_creancier_nom'] ?? ($data['creancier']['nom'] ?? ''));
$pro   = (string)($an['extr_pro_nom'] ?? ($data['professionnel']['nom'] ?? ''));
$num   = (string)($an['extr_numero_dossier'] ?? '');
$objet = (string)($an['extr_objet'] ?? '');
$mtP   = $an['extr_montant_principal'] ?? '';
$mtT   = $an['extr_montant_total'] ?? '';
$typeDoc = (string)($an['type_doc'] ?? 'autre');
$flags = $an && $an['review_flags'] ? explode("\n", (string)$an['review_flags']) : [];
$TYPES = ['conclusions','jugement','commandement','acte_saisie','correspondance','mail','autre'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>📄 Charger un document · Créanciers</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {
  --mbi-or:#D4A047; --mbi-navy:#243B5C; --bg:#0f172a; --panel:#1e293b; --line:#334155;
  --text:#f1f5f9; --muted:#94a3b8; --ok:#84a98c; --warn:#fde68a; --ko:#f87171; --info:#60a5fa;
}
* { box-sizing:border-box; }
body { margin:0; font-family:"DM Mono","JetBrains Mono",monospace; background:var(--bg); color:var(--text); font-size:12.5px; }
header { display:flex; align-items:center; gap:10px; padding:10px 18px; background:#11203b; border-bottom:2px solid var(--mbi-or); flex-wrap:wrap; }
header h1 { font-size:14px; margin:0; color:var(--warn); }
header .badge { padding:2px 8px; border-radius:4px; font-size:10px; font-weight:700; background:#dc2626; color:#fff; }
header nav { margin-left:auto; display:flex; gap:10px; }
header nav a { color:var(--muted); text-decoration:none; font-size:11px; border:1px solid var(--line); padding:4px 10px; border-radius:4px; }
header nav a:hover { color:var(--text); border-color:var(--mbi-or); }
.layout { display:grid; grid-template-columns:3fr 5fr; height:calc(100vh - 47px); }
.pane { overflow-y:auto; }
.pane-pdf { background:#0a1424; }
.pane-form { background:var(--panel); padding:16px 20px; border-left:1px solid var(--line); }
.pdf-header { padding:8px 14px; background:var(--panel); border-bottom:1px solid var(--line); font-size:11px; color:var(--muted); }
.pdf-viewer { position:relative; height:calc(100% - 35px); }
.pdf-viewer iframe { position:absolute; inset:0; width:100%; height:100%; border:none; }
.section { background:#0f172a; border-radius:6px; padding:12px 16px; margin-bottom:12px; border-left:4px solid var(--line); }
.section.s-ia { border-left-color:var(--info); }
.section.s-dossier { border-left-color:var(--mbi-or); }
.section h2 { font-size:12px; margin:0 0 10px; color:var(--info); }
.section.s-dossier h2 { color:var(--mbi-or); }
.field { margin-bottom:8px; }
.field label { display:block; font-size:10px; color:var(--muted); margin-bottom:3px; text-transform:uppercase; letter-spacing:.3px; }
.field input, .field select, .field textarea { width:100%; padding:6px 10px; background:#0a1424; border:1px solid var(--line); color:var(--text); border-radius:3px; font-family:inherit; font-size:12px; }
.field input:focus, .field select:focus { border-color:var(--mbi-or); outline:none; }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.actions { position:sticky; bottom:0; padding:14px 0; background:var(--panel); border-top:1px solid var(--line); display:flex; gap:10px; justify-content:space-between; margin-top:16px; }
.btn { padding:10px 18px; border:none; border-radius:4px; font-family:inherit; font-size:12.5px; cursor:pointer; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
.btn-primary { background:var(--ok); color:#1a1a1a; } .btn-primary:hover { background:#6b8a72; }
.btn-ghost { background:transparent; color:var(--muted); border:1px solid var(--line); }
.warn-box { background:#422006; color:#fde68a; padding:8px 12px; border-radius:3px; font-size:11px; margin-bottom:12px; border-left:3px solid var(--warn); white-space:pre-line; }
.box-existant { background:#082f1f; border-left:3px solid var(--ok); padding:10px 12px; border-radius:3px; }
.box-nouveau  { background:#2a1a06; border-left:3px solid var(--warn); padding:10px 12px; border-radius:3px; }
.radio-row { display:flex; align-items:center; gap:8px; margin:6px 0; font-size:12px; }
.radio-row input { width:auto; }
.drop-wrap { display:flex; align-items:center; justify-content:center; height:calc(100vh - 47px); }
.drop { border:2px dashed var(--line); border-radius:12px; padding:40px 50px; text-align:center; color:var(--muted); background:var(--panel); }
.drop input { margin-top:14px; color:var(--text); }
.msg { font-size:12px; margin-top:12px; }
.meta { font-size:11px; color:var(--muted); }
</style>
</head>
<body>
<header>
  <h1>📄 Charger un document — Créanciers</h1>
  <span class="badge">CONFIDENTIEL</span>
  <nav>
    <a href="<?= csc_h($base) ?>creancier_liste.php">← Dossiers</a>
    <?php if ($dossierMatch): ?><a href="<?= csc_h($base) ?>creancier_dossier360.php?id_dossier=<?= (int)$dossierMatch['id'] ?>">Dossier 360</a><?php endif; ?>
  </nav>
</header>

<?php if (!$an): /* ─── État 1 : dépôt ─── */ ?>
<div class="drop-wrap">
  <div class="drop">
    📄 Dépose un PDF (conclusions, jugement, commandement, courrier, mail…)
    <div><input type="file" id="scFile" accept="application/pdf"></div>
    <button type="button" id="scBtn" class="btn btn-primary" style="margin-top:14px">Analyser le document</button>
    <div id="scMsg" class="msg"></div>
  </div>
</div>
<script>
(function(){
  const csrf = <?= json_encode($csrfAnalyse) ?>;
  const btn = document.getElementById('scBtn'), file = document.getElementById('scFile'), msg = document.getElementById('scMsg');
  btn.addEventListener('click', async function(){
    if (!file.files[0]) { msg.textContent='Choisis un PDF.'; return; }
    btn.disabled=true; msg.style.color='#94a3b8'; msg.textContent='Analyse IA en cours… (5-10s)';
    const fd = new FormData(); fd.append('doc', file.files[0]); fd.append('csrf_token', csrf);
    <?php if ($preDossier > 0): ?>fd.append('id_dossier', <?= $preDossier ?>);<?php endif; ?>
    try {
      const r = await fetch('api/creancier_doc_analyze.php', {method:'POST', body:fd});
      const j = await r.json();
      if (j.ok) { location.href = 'creancier_scan.php?analyse_id=' + j.analyse_id; }
      else { msg.style.color='#f87171'; msg.textContent='✗ '+(j.error||'échec'); btn.disabled=false; }
    } catch(e){ msg.style.color='#f87171'; msg.textContent='✗ '+e; btn.disabled=false; }
  });
})();
</script>

<?php else: /* ─── État 2 : review 2 colonnes ─── */ ?>
<div class="layout">

  <!-- PDF (visualisation pure, sans nav) -->
  <section class="pane pane-pdf">
    <div class="pdf-header">📄 <b><?= csc_h((string)$an['file_name']) ?></b> · <?= csc_h((string)$an['file_mime']) ?> · <?= number_format((int)$an['file_size']/1024) ?> Ko</div>
    <div class="pdf-viewer">
      <iframe src="api/creancier_doc_preview.php?analyse_id=<?= $analyseId ?>#zoom=page-fit" title="PDF"></iframe>
    </div>
  </section>

  <!-- Champs extraits / éditables -->
  <section class="pane pane-form">
    <?php if ($flags): ?><div class="warn-box">⚠️ <?= csc_h(implode("\n", $flags)) ?></div><?php endif; ?>

    <form id="frm" onsubmit="return false">
      <input type="hidden" id="analyse_id" value="<?= $analyseId ?>">

      <div class="section s-ia">
        <h2>📊 Extraction IA · confiance <?= (int)round((float)$an['confidence']*100) ?>%</h2>
        <div class="field-row">
          <div class="field"><label>Type de document</label>
            <select id="f_type"><?php foreach ($TYPES as $t): ?><option value="<?= $t ?>" <?= $t===$typeDoc?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>N° de dossier</label><input type="text" id="f_num" value="<?= csc_h($num) ?>"></div>
        </div>
        <div class="field"><label>Créancier</label><input type="text" id="f_crea" value="<?= csc_h($crea) ?>"></div>
        <div class="field"><label>Débiteur (→ dossier)</label><input type="text" id="f_deb" value="<?= csc_h($debiteur) ?>"></div>
        <div class="field"><label>Professionnel (avocat / huissier)</label><input type="text" id="f_pro" value="<?= csc_h($pro) ?>"></div>
        <div class="field-row">
          <div class="field"><label>Montant principal (€)</label><input type="text" id="f_mtp" value="<?= csc_h((string)$mtP) ?>"></div>
          <div class="field"><label>Montant total (€)</label><input type="text" id="f_mtt" value="<?= csc_h((string)$mtT) ?>"></div>
        </div>
        <div class="field"><label>Objet / cause</label><input type="text" id="f_objet" value="<?= csc_h($objet) ?>"></div>
      </div>

      <div class="section s-dossier">
        <h2>🗂️ Dossier (débiteur) — classement GED</h2>
        <?php if ($dossierMatch): ?>
          <div class="box-existant">
            <label class="radio-row"><input type="radio" name="dossier_mode" value="existant" checked>
              Rattacher au dossier existant : <b><?= csc_h($dossierMatch['libelle']) ?> (<?= csc_h($dossierMatch['code']) ?>)</b></label>
            <input type="hidden" id="dossier_id" value="<?= (int)$dossierMatch['id'] ?>">
          </div>
          <label class="radio-row"><input type="radio" name="dossier_mode" value="nouveau"> Créer un nouveau dossier à la place</label>
        <?php else: ?>
          <div class="box-nouveau">⊕ Aucun dossier correspondant détecté — un <b>nouveau dossier</b> sera créé.</div>
          <input type="hidden" name="dossier_mode" value="nouveau">
        <?php endif; ?>
        <div id="newFields" style="<?= $dossierMatch ? 'display:none' : '' ?>">
          <div class="field"><label>Libellé du nouveau dossier</label><input type="text" id="f_newlib" value="<?= csc_h($debiteur) ?>"></div>
          <div class="field"><label>Code (auto si vide)</label><input type="text" id="f_newcode" value=""></div>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn btn-ghost" id="btnSave">💾 Enregistrer</button>
        <button type="button" class="btn btn-primary" id="btnValider">✓ Valider & classer en GED</button>
      </div>
    </form>
  </section>
</div>

<script>
(function(){
  const csrfUpdate = <?= json_encode($csrfUpdate) ?>;
  const csrfValider = <?= json_encode($csrfValider) ?>;
  const id = document.getElementById('analyse_id').value;
  const $ = i => document.getElementById(i);
  const val = i => ($(i) ? $(i).value : '');

  function fieldsFD(fd){
    fd.append('analyse_id', id);
    fd.append('type_doc', val('f_type')); fd.append('numero_dossier', val('f_num'));
    fd.append('creancier', val('f_crea')); fd.append('debiteur', val('f_deb')); fd.append('pro', val('f_pro'));
    fd.append('montant_principal', val('f_mtp')); fd.append('montant_total', val('f_mtt'));
    fd.append('objet', val('f_objet'));
  }

  async function save(){
    const fd = new FormData(); fieldsFD(fd); fd.append('csrf_token', csrfUpdate);
    const r = await fetch('api/creancier_doc_update.php', {method:'POST', body:fd});
    return r.json();
  }

  $('btnSave').addEventListener('click', async function(){
    this.disabled=true; const t=this.textContent; this.textContent='…';
    try { const j = await save(); this.textContent = j.ok ? '✓ Enregistré' : '✗'; if(!j.ok) alert(j.error||'échec'); }
    catch(e){ alert(e); } finally { setTimeout(()=>{this.disabled=false;this.textContent=t;},1200); }
  });

  function dossierMode(){
    const r = document.querySelector('input[name="dossier_mode"]:checked') || document.querySelector('input[name="dossier_mode"]');
    return r ? r.value : 'nouveau';
  }
  document.querySelectorAll('input[name="dossier_mode"]').forEach(function(r){
    r.addEventListener('change', function(){ const nf=$('newFields'); if(nf) nf.style.display = dossierMode()==='nouveau' ? '' : 'none'; });
  });

  $('btnValider').addEventListener('click', async function(){
    this.disabled=true; const t=this.textContent; this.textContent='…';
    try {
      await save(); // persiste les champs édités avant le commit
      const fd = new FormData(); fd.append('analyse_id', id); fd.append('csrf_token', csrfValider);
      if (dossierMode()==='nouveau') { fd.append('nouveau_dossier','1'); fd.append('libelle', val('f_newlib')); fd.append('code', val('f_newcode')); }
      else { fd.append('id_dossier', val('dossier_id')); }
      const r = await fetch('api/creancier_doc_valider.php', {method:'POST', body:fd});
      const j = await r.json();
      if (j.ok) { location.href = 'creancier_dossier360.php?id_dossier=' + j.id_dossier; }
      else { alert('Erreur : '+(j.error||'échec')); this.disabled=false; this.textContent=t; }
    } catch(e){ alert(e); this.disabled=false; this.textContent=t; }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
