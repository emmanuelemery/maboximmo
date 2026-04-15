<?php
// agency_registre_form.php — Création rapide / Pro d'un mandat + édition (layout_maboximmo)
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

$id      = (int)($_GET['id'] ?? 0);
$mode    = $_GET['mode'] ?? '';   // 'rapide' | 'pro' | '' (sélecteur affiché)
$m       = null;
$is_edit = false;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM agency_mandat WHERE id=?");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) { header('Location: agency_registres.php'); exit; }
    $is_edit = true;
    $mode    = $mode ?: 'pro'; // édition toujours en mode pro
}

$errors = [];

// ── Listes ────────────────────────────────────────────────────────────────────
$immeubles = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles WHERE statut_immeuble='actif' ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$mandants  = $pdo->query("SELECT id, raison_sociale FROM agency_mandant WHERE actif=1 ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC);
$etabs     = [];
if ($role_id === 1) {
    $etabs = $pdo->query("SELECT id, nom AS raison_sociale FROM etablissements ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC);
}

function nextNumeroRegistre(PDO $pdo, int $etab_id): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(numero_registre),0)+1 FROM agency_mandat WHERE id_etablissement=?");
    $stmt->execute([$etab_id]);
    return (int)$stmt->fetchColumn();
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_submit'])) {
    $form_mode = $_POST['_mode'] ?? 'rapide';

    // Valeurs communes
    $data = [
        'type_mandat'          => $_POST['type_mandat']             ?? 'syndic',
        'id_mandant'           => (int)($_POST['id_mandant']        ?? 0) ?: null,
        'mandant_nom'          => trim($_POST['mandant_nom']        ?? ''),
        'mandant_representant' => trim($_POST['mandant_representant'] ?? ''),
        'id_immeuble'          => (int)($_POST['id_immeuble']       ?? 0) ?: null,
        'immeuble_txt'         => trim($_POST['immeuble_txt']       ?? ''),
        'date_inscription'     => $_POST['date_inscription']        ?? date('Y-m-d'),
        'date_debut'           => $_POST['date_debut']              ?? date('Y-m-d'),
        'date_fin'             => ($_POST['date_fin']               ?? '') ?: null,
        'duree_mois'           => (int)($_POST['duree_mois']        ?? 0) ?: null,
        'renouvellement'       => $_POST['renouvellement']          ?? 'tacite',
        'preavis_mois'         => (int)($_POST['preavis_mois']      ?? 3),
        'honoraires_ht'        => str_replace(',', '.', $_POST['honoraires_ht'] ?? '0'),
        'tva_pct'              => str_replace(',', '.', $_POST['tva_pct'] ?? '20'),
        'statut'               => $_POST['statut']                  ?? 'actif',
        'date_resiliation'     => ($_POST['date_resiliation']       ?? '') ?: null,
        'motif_resiliation'    => trim($_POST['motif_resiliation']  ?? ''),
        'conditions'           => trim($_POST['conditions']         ?? ''),
        'observations'         => trim($_POST['observations']       ?? ''),
        'id_etablissement'     => $role_id === 1
                                    ? ((int)($_POST['id_etablissement'] ?? 0) ?: null)
                                    : ($etab_id ?: null),
        'id_createur'          => $user_id,
    ];

    // En mode rapide certains champs ne sont pas dans le form — valeurs par défaut
    if ($form_mode === 'rapide') {
        $data['renouvellement']   = 'tacite';
        $data['preavis_mois']     = 3;
        $data['tva_pct']          = 20;
        $data['conditions']       = '';
        $data['observations']     = '';
        $data['motif_resiliation'] = '';
        $data['date_resiliation']  = null;
    }

    if (!$data['mandant_nom'])    $errors[] = 'Le nom du mandant est obligatoire.';
    if (!$data['date_debut'])     $errors[] = 'La date de début est obligatoire.';
    if (!$data['date_inscription']) $errors[] = "La date d'inscription est obligatoire.";

    if (empty($errors)) {
        if ($is_edit) {
            $sql = "UPDATE agency_mandat SET
                type_mandat=:type_mandat, id_mandant=:id_mandant, mandant_nom=:mandant_nom,
                mandant_representant=:mandant_representant, id_immeuble=:id_immeuble,
                immeuble_txt=:immeuble_txt, date_inscription=:date_inscription,
                date_debut=:date_debut, date_fin=:date_fin, duree_mois=:duree_mois,
                renouvellement=:renouvellement, preavis_mois=:preavis_mois,
                honoraires_ht=:honoraires_ht, tva_pct=:tva_pct, statut=:statut,
                date_resiliation=:date_resiliation, motif_resiliation=:motif_resiliation,
                conditions=:conditions, observations=:observations,
                id_etablissement=:id_etablissement
                WHERE id=$id";
            $pdo->prepare($sql)->execute($data);
            header("Location: agency_registre_fiche.php?id=$id&saved=1");
            exit;
        } else {
            $etab_for_seq          = $data['id_etablissement'] ?? ($etab_id ?: null);
            $data['numero_registre'] = nextNumeroRegistre($pdo, (int)$etab_for_seq);
            $sql = "INSERT INTO agency_mandat
                (numero_registre,type_mandat,id_mandant,mandant_nom,mandant_representant,
                 id_immeuble,immeuble_txt,date_inscription,date_debut,date_fin,duree_mois,
                 renouvellement,preavis_mois,honoraires_ht,tva_pct,statut,
                 date_resiliation,motif_resiliation,conditions,observations,
                 id_etablissement,id_createur)
                VALUES
                (:numero_registre,:type_mandat,:id_mandant,:mandant_nom,:mandant_representant,
                 :id_immeuble,:immeuble_txt,:date_inscription,:date_debut,:date_fin,:duree_mois,
                 :renouvellement,:preavis_mois,:honoraires_ht,:tva_pct,:statut,
                 :date_resiliation,:motif_resiliation,:conditions,:observations,
                 :id_etablissement,:id_createur)";
            $pdo->prepare($sql)->execute($data);
            $new_id = (int)$pdo->lastInsertId();
            header("Location: agency_registre_fiche.php?id=$new_id&saved=1");
            exit;
        }
    }
    $m = $data;
    $m['id'] = $id;
    $mode = $form_mode; // rester sur le bon mode si erreur
}

$v = fn(string $k, $default = '') => htmlspecialchars($m[$k] ?? $default);
$page_title = $is_edit ? 'Modifier le mandat' : ($mode === 'rapide' ? 'Nouveau mandat — Rapide' : ($mode === 'pro' ? 'Nouveau mandat — Pro' : 'Nouveau mandat'));

// ── Layout ──────────────────────────────────────────────────────────
$layout_title   = $page_title;
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$_modeLabel = $is_edit ? 'Édition' : ($mode === 'rapide' ? 'Rapide' : ($mode === 'pro' ? 'Complet' : 'Choix'));

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.($is_edit?str_pad((string)($m['numero_registre']??0),4,'0',STR_PAD_LEFT):'NEW').'</div><div class="ph-kpi-lbl">N°</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.htmlspecialchars($_modeLabel).'</div><div class="ph-kpi-lbl">Mode</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($immeubles).'</div><div class="ph-kpi-lbl">Immeubles</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($mandants).'</div><div class="ph-kpi-lbl">Mandants</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:'.(count($errors)?'#8a5040':'#3a7a6a').'">'.count($errors).'</div><div class="ph-kpi-lbl">Erreurs</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">—</div><div class="ph-kpi-lbl">—</div></div>
';

$_backHref = $is_edit ? ('agency_registre_fiche.php?id='.$id) : 'agency_registres.php';
$layout_head_actions = '
    <a href="'.$_backHref.'" class="ph-btn">Retour</a>
    <a href="agency_registres.php" class="ph-btn">Registre</a>
    <a href="#" class="ph-btn dispo">dispo</a>
    <a href="#" class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.mode-picker{max-width:720px;margin:0 auto}
.mode-picker-title{font-size:22px;font-weight:700;color:#1a1816;text-align:center;margin-bottom:6px}
.mode-picker-sub{font-size:13px;color:#9a9690;text-align:center;margin-bottom:32px}
.mode-cards{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.mode-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:20px;box-shadow:8px 8px 22px var(--shadow-dark,#d4d7de),-8px -8px 18px var(--shadow-light,#fff);padding:32px 28px;cursor:pointer;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:14px;transition:box-shadow .2s,transform .15s}
.mode-card:hover{box-shadow:10px 10px 28px #c0bcb6,-10px -10px 22px var(--shadow-light,#fff);transform:translateY(-2px)}
.mode-card.rapide .icon-wrap{background:linear-gradient(135deg,#8eb4d3,#4878a6)}
.mode-card.pro    .icon-wrap{background:linear-gradient(135deg,#6898bf,#3a5f8a)}
.icon-wrap{width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 18px rgba(72,120,166,.3)}
.mode-card-title{font-size:18px;font-weight:700;color:#1a1816}
.mode-card-badge{font-family:'DM Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;padding:3px 10px;border-radius:999px}
.mode-card.rapide .mode-card-badge{background:#e8f0f8;color:#4878a6}
.mode-card.pro    .mode-card-badge{background:#dce8f4;color:#3a5f8a}
.mode-card-desc{font-size:12px;color:#6a6864;text-align:center;line-height:1.6}
.mode-card-fields{width:100%;background:#e0dbd4;border-radius:10px;padding:10px 14px}
.mode-card-fields ul{list-style:none;padding:0;display:flex;flex-direction:column;gap:5px}
.mode-card-fields li{font-size:11px;color:#4a4844;display:flex;align-items:center;gap:6px}
.mode-card-fields li::before{content:'';width:6px;height:6px;border-radius:50%;background:#4878a6;flex-shrink:0}
.mode-card.pro .mode-card-fields li::before{background:#3a5f8a}
.mode-card-cta{margin-top:4px;padding:10px 28px;border-radius:999px;border:none;font-family:'Sora',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:box-shadow .15s}
.mode-card.rapide .mode-card-cta{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:2px 4px 12px rgba(72,120,166,.35)}
.mode-card.pro    .mode-card-cta{background:linear-gradient(135deg,#4a6a90,#3a5f8a);color:#fff;box-shadow:2px 4px 12px rgba(58,95,138,.35)}
.back-row{text-align:center;margin-top:22px}
.back-link{font-size:12px;color:#9a9690;text-decoration:none}
.back-link:hover{color:#4878a6}
.form-wrap{max-width:820px}
.mode-tag{font-family:'DM Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;padding:3px 10px;border-radius:999px}
.mode-tag.rapide{background:#e8f0f8;color:#4878a6}
.mode-tag.pro{background:#dce8f4;color:#3a5f8a}
.form-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:24px 26px;margin-bottom:18px}
.section-title{font-size:11px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #e4e6ec;display:flex;align-items:center;gap:8px}
.section-title svg{opacity:.5}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.c3{grid-template-columns:1fr 1fr 1fr}
.form-grid.c1{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-wrap label{font-size:12px;font-weight:600;color:#6a6864}
.form-wrap label .opt{font-weight:400;color:#b0aaa4;font-size:10px}
.form-wrap input,.form-wrap select,.form-wrap textarea{border:none;border-radius:10px;padding:10px 14px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:13px;color:#2c2a28;outline:none;width:100%}
.form-wrap input:focus,.form-wrap select:focus,.form-wrap textarea:focus{box-shadow:inset 3px 3px 7px #b8b4ae,inset -3px -3px 6px var(--shadow-light,#fff),0 0 0 2px rgba(72,120,166,.18)}
.form-wrap textarea{min-height:88px;resize:vertical}
.form-wrap input[readonly]{color:#9a9690;cursor:default}
.hint{font-size:10px;color:#9a9690}
.tabs{display:flex;gap:6px;margin-bottom:22px}
.tab-btn{padding:9px 20px;border-radius:999px;border:none;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;cursor:pointer;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;transition:box-shadow .15s,color .15s;display:flex;align-items:center;gap:7px;white-space:nowrap}
.tab-btn:hover{box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 4px var(--shadow-light,#fff);color:#4878a6}
.tab-btn.active{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);color:#4878a6}
.tab-btn .tab-dot{width:7px;height:7px;border-radius:50%;background:#e4e6ec;transition:background .15s}
.tab-btn.active .tab-dot{background:#4878a6}
.tab-btn.done .tab-dot{background:#3a7a6a}
.tab-panel{display:none;flex-direction:column;gap:18px}
.tab-panel.active{display:flex}
.alert-error{background:#fdecea;border-radius:10px;padding:12px 16px;font-size:13px;color:#8a5040;margin-bottom:18px}
.alert-error ul{padding-left:18px;margin-top:6px}
.resil-section{display:none}
.resil-section.show{display:grid}
.recap-card{background:linear-gradient(135deg,rgba(104,152,191,.08),rgba(72,120,166,.05));border:1px solid rgba(72,120,166,.15);border-radius:12px;padding:16px 20px;margin-bottom:18px}
.recap-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.recap-item label{font-size:10px;color:#9a9690;text-transform:uppercase;letter-spacing:.06em;display:block}
.recap-item .rval{font-size:14px;font-weight:700;color:#1a1816}
.recap-item .rval.blue{color:#4878a6}
.form-actions{display:flex;gap:12px;align-items:center;margin-top:10px}
.btn-save{padding:12px 32px;border-radius:999px;background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;border:none;cursor:pointer;box-shadow:3px 6px 16px rgba(72,120,166,.35);transition:box-shadow .15s}
.btn-save:hover{box-shadow:3px 8px 22px rgba(72,120,166,.45)}
.btn-cancel{padding:12px 22px;border-radius:999px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;border:none;cursor:pointer;text-decoration:none}
.btn-cancel:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff)}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function prefillMandant(sel) {
    const nom = sel.options[sel.selectedIndex].dataset.nom || '';
    if (nom) document.querySelector('[name=mandant_nom]').value = nom;
}
function prefillImmeuble(sel) {
    const nom = sel.options[sel.selectedIndex].dataset.nom || '';
    if (nom) document.querySelector('[name=immeuble_txt]').value = nom;
}
function toggleResil(v) {
    const b = document.getElementById('resil_bloc');
    if (b) b.classList.toggle('show', v === 'resilie');
}
function fmtEur(v) { return isNaN(v) ? '—' : v.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
function calcRapide() {
    const ht  = parseFloat(document.getElementById('r_ht')?.value)  || 0;
    const tva = parseFloat(document.getElementById('r_tva')?.value) || 0;
    const el  = document.getElementById('r_ttc');
    if (el) el.value = fmtEur(ht * (1 + tva/100));
}
calcRapide();
let currentTab = 1;
function switchTab(n) {
    [1,2,3].forEach(i => {
        const panel = document.getElementById('panel'+i);
        const btn   = document.getElementById('tab'+i);
        if (panel) panel.classList.toggle('active', i===n);
        if (btn) {
            btn.classList.toggle('active', i===n);
            btn.classList.toggle('done',   i<n);
        }
    });
    currentTab = n;
    if (n===3) updateRecap();
}
function calcDateFin() {
    const debut  = document.getElementById('p_debut')?.value;
    const duree  = parseInt(document.getElementById('p_duree')?.value) || 0;
    const finEl  = document.getElementById('p_fin');
    if (!debut || !duree || !finEl) return;
    const d = new Date(debut);
    d.setMonth(d.getMonth() + duree);
    finEl.value = d.toISOString().slice(0,10);
}
function calcPro() {
    const ht  = parseFloat(document.getElementById('p_ht')?.value)  || 0;
    const tva = parseFloat(document.getElementById('p_tva')?.value) || 0;
    const ttc = ht * (1 + tva/100);
    const ttcEl = document.getElementById('p_ttc');
    const mEl   = document.getElementById('p_mensuel');
    if (ttcEl) ttcEl.value = fmtEur(ttc);
    if (mEl)   mEl.value   = fmtEur(ht/12);
}
calcPro();
function fmtDateFr(s) {
    if (!s) return '—';
    const [y,m,d] = s.split('-');
    return d+'/'+m+'/'+y;
}
function updateRecap() {
    const f = document.getElementById('pro_form');
    if (!f) return;
    const g = (name) => f.querySelector('[name='+name+']')?.value || '—';
    const typeMap = {syndic:'Syndic',gerance:'Gérance',transaction:'Transaction',location:'Location',autre:'Autre'};
    document.getElementById('rc_mandant').textContent  = g('mandant_nom');
    document.getElementById('rc_immeuble').textContent = g('immeuble_txt') !== '—' ? g('immeuble_txt') : g('id_immeuble') !== '—' ? '(lié)' : '—';
    document.getElementById('rc_type').textContent     = typeMap[g('type_mandat')] || g('type_mandat');
    document.getElementById('rc_debut').textContent    = fmtDateFr(g('date_debut'));
    document.getElementById('rc_fin').textContent      = fmtDateFr(g('date_fin'));
    const ht  = parseFloat(document.getElementById('p_ht')?.value)  || 0;
    const tva = parseFloat(document.getElementById('p_tva')?.value) || 0;
    document.getElementById('rc_ttc').textContent = fmtEur(ht*(1+tva/100));
}
</script>
EXTRAJS;

// Édition : tous les panneaux visibles (script ajouté à la volée)
if ($is_edit) {
    $layout_extra_js .= '<script>[1,2,3].forEach(i => { const p = document.getElementById("panel"+i); if (p) p.classList.add("active"); });</script>';
}

ob_start();
?>

<?php
// ────────────────────────────────────────────────────────────────────────────
// ÉCRAN DE SÉLECTION DU MODE (nouveau mandat uniquement)
// ────────────────────────────────────────────────────────────────────────────
if (!$is_edit && !$mode):
?>

<div class="mode-picker">
  <div class="mode-picker-title">Comment voulez-vous créer ce mandat ?</div>
  <div class="mode-picker-sub">Choisissez le niveau de détail adapté à votre besoin</div>

  <div class="mode-cards">

    <!-- Rapide -->
    <a href="?mode=rapide" class="mode-card rapide">
      <div class="icon-wrap">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
      </div>
      <div class="mode-card-title">Création rapide</div>
      <span class="mode-card-badge">~1 minute</span>
      <div class="mode-card-desc">Saisie minimaliste pour inscrire un mandat en quelques secondes. Idéal pour une première prise en compte, à compléter plus tard.</div>
      <div class="mode-card-fields">
        <ul>
          <li>Type de mandat &amp; mandant</li>
          <li>Immeuble associé</li>
          <li>Dates début / fin</li>
          <li>Honoraires HT</li>
          <li>Statut initial</li>
        </ul>
      </div>
      <button class="mode-card-cta" type="button">Créer rapidement →</button>
    </a>

    <!-- Pro -->
    <a href="?mode=pro" class="mode-card pro">
      <div class="icon-wrap">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      </div>
      <div class="mode-card-title">Création complète</div>
      <span class="mode-card-badge">Dossier complet</span>
      <div class="mode-card-desc">Formulaire en 3 étapes pour un dossier mandat complet dès la création. Toutes les informations légales et contractuelles.</div>
      <div class="mode-card-fields">
        <ul>
          <li>Étape 1 — Mandant &amp; bien</li>
          <li>Étape 2 — Durée, renouvellement, préavis</li>
          <li>Étape 3 — Honoraires &amp; conditions</li>
          <li>Lien mandant existant (fichier)</li>
          <li>Conditions particulières &amp; résiliation</li>
          <li>Observations internes</li>
        </ul>
      </div>
      <button class="mode-card-cta" type="button">Créer le dossier complet →</button>
    </a>

  </div>

  <div class="back-row">
    <a href="agency_registres.php" class="back-link">← Retour au registre</a>
  </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────────
// FORMULAIRE RAPIDE
// ────────────────────────────────────────────────────────────────────────────
elseif ($mode === 'rapide' && !$is_edit):
?>
<div class="form-wrap">

<?php if ($errors): ?>
<div class="alert-error"><strong>Erreur(s) :</strong><ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="_submit" value="1">
<input type="hidden" name="_mode" value="rapide">

<div class="form-card">
  <div class="section-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    Mandant &amp; bien
  </div>
  <div class="form-grid">
    <div class="form-group">
      <label>Type de mandat</label>
      <select name="type_mandat">
        <option value="syndic"      <?= ($m['type_mandat']??'syndic')==='syndic'?'selected':'' ?>>Syndic</option>
        <option value="gerance"     <?= ($m['type_mandat']??'')==='gerance'?'selected':'' ?>>Gérance</option>
        <option value="transaction" <?= ($m['type_mandat']??'')==='transaction'?'selected':'' ?>>Transaction</option>
        <option value="location"    <?= ($m['type_mandat']??'')==='location'?'selected':'' ?>>Location</option>
        <option value="autre"       <?= ($m['type_mandat']??'')==='autre'?'selected':'' ?>>Autre</option>
      </select>
    </div>
    <div class="form-group">
      <label>Statut</label>
      <select name="statut">
        <option value="actif"     <?= ($m['statut']??'actif')==='actif'?'selected':'' ?>>Actif</option>
        <option value="suspendu"  <?= ($m['statut']??'')==='suspendu'?'selected':'' ?>>Suspendu</option>
        <option value="archive"   <?= ($m['statut']??'')==='archive'?'selected':'' ?>>Archivé</option>
      </select>
    </div>
    <div class="form-group full">
      <label>Nom du mandant *</label>
      <input type="text" name="mandant_nom" value="<?= $v('mandant_nom') ?>" placeholder="Ex. : Copropriété Les Hauts du Parc" required>
    </div>
    <div class="form-group">
      <label>Immeuble <span class="opt">(optionnel)</span></label>
      <select name="id_immeuble" onchange="if(this.value){document.querySelector('[name=immeuble_txt]').value=this.options[this.selectedIndex].dataset.nom||''}">
        <option value="">— Saisie libre —</option>
        <?php foreach ($immeubles as $im): ?>
        <option value="<?= $im['id'] ?>" data-nom="<?= htmlspecialchars($im['nom']) ?>" <?= ($m['id_immeuble']??'')==$im['id']?'selected':'' ?>>
          <?= htmlspecialchars($im['nom']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Immeuble <span class="opt">(saisie libre)</span></label>
      <input type="text" name="immeuble_txt" value="<?= $v('immeuble_txt') ?>" placeholder="Si non référencé">
    </div>
    <?php if ($role_id===1 && $etabs): ?>
    <div class="form-group full">
      <label>Établissement</label>
      <select name="id_etablissement">
        <option value="">— Sélectionner —</option>
        <?php foreach ($etabs as $e): ?><option value="<?= $e['id'] ?>" <?= ($m['id_etablissement']??'')==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="form-card">
  <div class="section-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Dates &amp; honoraires
  </div>
  <div class="form-grid c3">
    <div class="form-group">
      <label>Date d'inscription *</label>
      <input type="date" name="date_inscription" value="<?= $v('date_inscription', date('Y-m-d')) ?>" required>
    </div>
    <div class="form-group">
      <label>Date de début *</label>
      <input type="date" name="date_debut" value="<?= $v('date_debut', date('Y-m-d')) ?>" required>
    </div>
    <div class="form-group">
      <label>Date de fin <span class="opt">(optionnel)</span></label>
      <input type="date" name="date_fin" value="<?= $v('date_fin') ?>">
    </div>
    <div class="form-group">
      <label>Honoraires HT / an (€)</label>
      <input type="number" name="honoraires_ht" id="r_ht" value="<?= $v('honoraires_ht','0') ?>" step="0.01" min="0" oninput="calcRapide()">
    </div>
    <div class="form-group">
      <label>TVA (%)</label>
      <input type="number" name="tva_pct" id="r_tva" value="<?= $v('tva_pct','20') ?>" step="0.01" min="0" max="100" oninput="calcRapide()">
    </div>
    <div class="form-group">
      <label>Total TTC</label>
      <input type="text" id="r_ttc" readonly placeholder="—">
    </div>
  </div>
</div>

<div class="form-actions">
  <button type="submit" class="btn-save">Inscrire au registre</button>
  <a href="agency_registres.php" class="btn-cancel">Annuler</a>
  <a href="?mode=pro" style="font-size:12px;color:#9a9690;text-decoration:none;margin-left:8px">Passer en mode complet →</a>
</div>
</form>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────────
// FORMULAIRE PRO (nouveau ou édition)
// ────────────────────────────────────────────────────────────────────────────
else:
?>
<div class="form-wrap">

<?php if ($errors): ?>
<div class="alert-error"><strong>Erreur(s) :</strong><ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
<?php endif; ?>

<?php if (!$is_edit): ?>
<!-- Onglets -->
<div class="tabs" id="pro_tabs">
  <button type="button" class="tab-btn active" id="tab1" onclick="switchTab(1)">
    <span class="tab-dot"></span>Mandant &amp; bien
  </button>
  <button type="button" class="tab-btn" id="tab2" onclick="switchTab(2)">
    <span class="tab-dot"></span>Durée &amp; conditions
  </button>
  <button type="button" class="tab-btn" id="tab3" onclick="switchTab(3)">
    <span class="tab-dot"></span>Honoraires &amp; notes
  </button>
</div>
<?php endif; ?>

<form method="POST" id="pro_form">
<input type="hidden" name="_submit" value="1">
<input type="hidden" name="_mode" value="pro">

<!-- ═══════════════════════════════════════════════════════
     ONGLET 1 — Mandant & bien
═══════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="panel1">

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Identification du mandat
    </div>
    <div class="form-grid c3">
      <div class="form-group">
        <label>Type de mandat</label>
        <select name="type_mandat">
          <?php foreach (['syndic'=>'Syndic','gerance'=>'Gérance','transaction'=>'Transaction','location'=>'Location','autre'=>'Autre'] as $tv=>$tl): ?>
          <option value="<?= $tv ?>" <?= ($m['type_mandat']??'syndic')===$tv?'selected':'' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Date d'inscription *</label>
        <input type="date" name="date_inscription" value="<?= $v('date_inscription', date('Y-m-d')) ?>" required>
      </div>
      <?php if ($role_id===1 && $etabs): ?>
      <div class="form-group">
        <label>Établissement</label>
        <select name="id_etablissement">
          <option value="">— Sélectionner —</option>
          <?php foreach ($etabs as $e): ?><option value="<?= $e['id'] ?>" <?= ($m['id_etablissement']??'')==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      Mandant
    </div>
    <div class="form-grid">
      <?php if ($mandants): ?>
      <div class="form-group">
        <label>Lier à un mandant existant <span class="opt">(optionnel)</span></label>
        <select name="id_mandant" onchange="prefillMandant(this)">
          <option value="">— Saisie libre —</option>
          <?php foreach ($mandants as $md): ?>
          <option value="<?= $md['id'] ?>" data-nom="<?= htmlspecialchars($md['raison_sociale']) ?>"
            <?= ($m['id_mandant']??'')==$md['id']?'selected':'' ?>><?= htmlspecialchars($md['raison_sociale']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Pré-remplit le nom automatiquement</span>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Dénomination du mandant *</label>
        <input type="text" name="mandant_nom" value="<?= $v('mandant_nom') ?>" placeholder="Ex. : Copropriété Les Hauts du Parc" required>
      </div>
      <div class="form-group full">
        <label>Représentant / Président du Conseil Syndical</label>
        <input type="text" name="mandant_representant" value="<?= $v('mandant_representant') ?>" placeholder="Nom et prénom du représentant légal ou du président CS">
      </div>
    </div>
  </div>

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Bien immobilier
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label>Immeuble référencé <span class="opt">(optionnel)</span></label>
        <select name="id_immeuble" onchange="prefillImmeuble(this)">
          <option value="">— Saisie libre —</option>
          <?php foreach ($immeubles as $im): ?>
          <option value="<?= $im['id'] ?>" data-nom="<?= htmlspecialchars($im['nom']) ?>" <?= ($m['id_immeuble']??'')==$im['id']?'selected':'' ?>>
            <?= htmlspecialchars($im['nom']) ?><?= $im['reference']?' ('.$im['reference'].')':'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Adresse / nom immeuble <span class="opt">(saisie libre)</span></label>
        <input type="text" name="immeuble_txt" value="<?= $v('immeuble_txt') ?>" placeholder="Si non lié à un immeuble du fichier">
      </div>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════
     ONGLET 2 — Durée & conditions
═══════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel2">

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Durée du mandat
    </div>
    <div class="form-grid c3">
      <div class="form-group">
        <label>Date de début *</label>
        <input type="date" name="date_debut" value="<?= $v('date_debut', date('Y-m-d')) ?>" required id="p_debut" oninput="calcDateFin()">
      </div>
      <div class="form-group">
        <label>Durée <span class="opt">(mois)</span></label>
        <input type="number" name="duree_mois" id="p_duree" value="<?= $v('duree_mois') ?>" min="1" max="120" placeholder="Ex. : 36" oninput="calcDateFin()">
      </div>
      <div class="form-group">
        <label>Date de fin <span class="opt">(calculée ou libre)</span></label>
        <input type="date" name="date_fin" id="p_fin" value="<?= $v('date_fin') ?>">
        <span class="hint">Se calcule si durée renseignée</span>
      </div>
    </div>
  </div>

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Renouvellement &amp; préavis
    </div>
    <div class="form-grid c3">
      <div class="form-group">
        <label>Renouvellement</label>
        <select name="renouvellement">
          <option value="tacite"  <?= ($m['renouvellement']??'tacite')==='tacite'?'selected':'' ?>>Tacite reconduction</option>
          <option value="express" <?= ($m['renouvellement']??'')==='express'?'selected':'' ?>>Exprès</option>
          <option value="sans"    <?= ($m['renouvellement']??'')==='sans'?'selected':'' ?>>Sans renouvellement</option>
        </select>
      </div>
      <div class="form-group">
        <label>Préavis <span class="opt">(mois)</span></label>
        <input type="number" name="preavis_mois" value="<?= $v('preavis_mois','3') ?>" min="0" max="24">
      </div>
      <div class="form-group">
        <label>Statut initial</label>
        <select name="statut" id="p_statut" onchange="toggleResil(this.value)">
          <?php foreach (['actif'=>'Actif','suspendu'=>'Suspendu','resilie'=>'Résilié','expire'=>'Expiré','archive'=>'Archivé'] as $sv=>$sl): ?>
          <option value="<?= $sv ?>" <?= ($m['statut']??'actif')===$sv?'selected':'' ?>><?= $sl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <!-- Résiliation -->
    <div class="form-grid resil-section <?= ($m['statut']??'')==='resilie'?'show':'' ?>" id="resil_bloc" style="margin-top:14px">
      <div class="form-group">
        <label>Date de résiliation</label>
        <input type="date" name="date_resiliation" value="<?= $v('date_resiliation') ?>">
      </div>
      <div class="form-group">
        <label>Motif de résiliation</label>
        <input type="text" name="motif_resiliation" value="<?= $v('motif_resiliation') ?>" placeholder="Changement de syndic, accord amiable…">
      </div>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════
     ONGLET 3 — Honoraires & notes
═══════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel3">

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Honoraires annuels
    </div>
    <div class="form-grid c3">
      <div class="form-group">
        <label>Honoraires HT (€/an)</label>
        <input type="number" name="honoraires_ht" id="p_ht" value="<?= $v('honoraires_ht','0') ?>" step="0.01" min="0" oninput="calcPro()">
      </div>
      <div class="form-group">
        <label>TVA (%)</label>
        <input type="number" name="tva_pct" id="p_tva" value="<?= $v('tva_pct','20') ?>" step="0.01" min="0" max="100" oninput="calcPro()">
      </div>
      <div class="form-group">
        <label>Total TTC (calculé)</label>
        <input type="text" id="p_ttc" readonly placeholder="—">
      </div>
      <div class="form-group">
        <label>Mensualité HT estimée</label>
        <input type="text" id="p_mensuel" readonly placeholder="—">
      </div>
    </div>
  </div>

  <div class="form-card">
    <div class="section-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Conditions particulières &amp; observations
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label>Conditions particulières <span class="opt">(optionnel)</span></label>
        <textarea name="conditions" placeholder="Clauses spécifiques, dérogations contractuelles, engagements particuliers…"><?= $v('conditions') ?></textarea>
      </div>
      <div class="form-group">
        <label>Observations internes <span class="opt">(non imprimées)</span></label>
        <textarea name="observations" placeholder="Notes internes, historique, contexte de la prise en gestion…"><?= $v('observations') ?></textarea>
      </div>
    </div>
  </div>

  <?php if (!$is_edit): ?>
  <!-- Récap avant validation -->
  <div class="recap-card" id="recap_bloc">
    <div style="font-size:11px;font-weight:700;color:#9a9690;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">Récapitulatif avant inscription</div>
    <div class="recap-grid">
      <div class="recap-item"><label>Mandant</label><div class="rval" id="rc_mandant">—</div></div>
      <div class="recap-item"><label>Immeuble</label><div class="rval" id="rc_immeuble">—</div></div>
      <div class="recap-item"><label>Type</label><div class="rval" id="rc_type">—</div></div>
      <div class="recap-item"><label>Début</label><div class="rval" id="rc_debut">—</div></div>
      <div class="recap-item"><label>Fin</label><div class="rval" id="rc_fin">—</div></div>
      <div class="recap-item"><label>Honoraires TTC/an</label><div class="rval blue" id="rc_ttc">—</div></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn-save"><?= $is_edit ? 'Enregistrer les modifications' : 'Inscrire au registre' ?></button>
    <a href="<?= $is_edit ? 'agency_registre_fiche.php?id='.$id : 'agency_registres.php' ?>" class="btn-cancel">Annuler</a>
  </div>
</div>

</form>
</div>
<?php endif; // fin mode pro ?>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
