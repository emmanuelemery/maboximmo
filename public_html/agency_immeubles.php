<?php
/**
 * agency_immeubles_v2.php — Liste des immeubles, UI alignée sur agency_proprietaires.php.
 * (Page parallèle de test ; remplacera agency_immeubles.php une fois validée.)
 *
 *  - Topbar : titre + actions (Nouveau immeuble, Importer un CRG).
 *  - Bandeau : KPIs (Immeubles · Lots · Syndic · Gestion) + boutons Société + Type de mandat.
 *  - Barre sticky : recherche (ville/nom/réf/adresse) · lettres (sur le nom de rue) · tri A→Z.
 *  - Cards entity_card → immeuble_360.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/entity_card.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$appLayout = true;
$pageTitle = 'Immeubles';
$robots    = 'noindex, nofollow';
$pdo       = $GLOBALS['pdo'];
$roleId    = current_role_id();
$isAdmin   = in_array($roleId, [1, 7], true);

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ── Nom de rue « propre » : 1er mot réel (hors numéro, type de voie, article) ── */
function imm_street_clean(string $adresse): string {
    $s = mb_strtolower(trim($adresse), 'UTF-8');
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    static $stop = ['a','au','aux','et','bis','ter','rue','avenue','av','bd','boulevard','place','pl','impasse','imp','chemin','chem','cours',
        'allee','allees','all','route','rte','quai','montee','mtee','passage','pass','square','sq','villa','clos','lotissement',
        'lot','residence','res','grande','grand','du','de','des','la','le','les','d','l'];
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $w) {
        if ($w === '' || ctype_digit($w) || in_array($w, $stop, true)) { if (!$out) continue; }
        if ($w !== '' && !ctype_digit($w)) $out[] = $w;
    }
    return implode(' ', $out);
}
function imm_street_letter(string $adresse): string {
    $c = imm_street_clean($adresse);
    $f = mb_strtoupper(mb_substr($c, 0, 1, 'UTF-8'), 'UTF-8');
    return preg_match('/[A-Z]/', $f) ? $f : '#';
}

/* ── Scope société ── */
$scopeSoc = isset($_GET['societe']) && ctype_digit((string)$_GET['societe']) ? (int)$_GET['societe'] : 0;
if (!$isAdmin) { $mySoc = (int)($_SESSION['id_societe'] ?? 0); if ($mySoc > 0) $scopeSoc = $mySoc; }

$conds = []; $params = [];
if ($scopeSoc > 0) { $conds[] = 'i.id_societe = ?'; $params[] = $scopeSoc; }
$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';

$immeubles = [];
try {
    $sql = "SELECT i.id, i.reference_immeuble, i.nom_immeuble, i.adresse_1, i.ville, i.code_postal,
                   i.type_immeuble, i.nb_lots, i.id_societe, i.categorie_mbi, i.code_crg,
                   (SELECT COUNT(*) FROM biens b WHERE b.id_immeuble = i.id) AS nb_biens,
                   (SELECT b.id FROM biens b WHERE b.id_immeuble = i.id ORDER BY b.id DESC LIMIT 1) AS first_bien_id,
                   EXISTS(SELECT 1 FROM biens b JOIN annonces a ON a.id_bien = b.id
                          WHERE b.id_immeuble = i.id) AS has_annonce
            FROM immeubles i $where";
    $st = $pdo->prepare($sql); $st->execute($params);
    $immeubles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ex) { error_log('[agency_immeubles_v2] ' . $ex->getMessage()); $immeubles = []; }

/* ── Tri : par NOM DE VOIE (alpha) ou par N° DE VOIE (numérique), asc/desc ── */
$tri = (($_GET['tri'] ?? 'voie') === 'num') ? 'num' : 'voie';
$dir = (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
$immSortKey = function(array $r) use ($tri): string {
    $street = imm_street_clean((string)($r['adresse_1'] ?? ''));
    $num    = preg_match('/(\d+)/', (string)($r['adresse_1'] ?? ''), $m) ? (int)$m[1] : 0;
    return $tri === 'num'
        ? sprintf('%08d', $num) . ' ' . $street    // N° d'abord, puis voie
        : $street . ' ' . sprintf('%06d', $num);   // voie d'abord, puis N°
};
usort($immeubles, function($a, $b) use ($dir, $immSortKey) {
    $c = strcmp($immSortKey($a), $immSortKey($b));
    return $dir === 'desc' ? -$c : $c;
});

/* ── Catégorie d'un immeuble ──
 *   1) override manuel (categorie_mbi) prioritaire ;
 *   2) SYNDIC auto = réf copro (1000-1200 LYON / 2000-2200 MIONS / 3000-3200 VIENNE)
 *      + plusieurs lots + PAS créé par CRG ;
 *   3) GESTION par défaut. (TRANSACTION = uniquement via classement manuel des annonces). */
function imm_categorie(array $r): string {
    $m = strtoupper(trim((string)($r['categorie_mbi'] ?? '')));
    if (in_array($m, ['SYNDIC','GESTION','TRANSACTION'], true)) return $m;
    $ref = (string)($r['reference_immeuble'] ?? '');
    $isCrg = trim((string)($r['code_crg'] ?? '')) !== '';
    if (!$isCrg && (int)($r['nb_lots'] ?? 0) > 1 && ctype_digit($ref)) {
        $n = (int)$ref;
        if (($n >= 1000 && $n <= 1200) || ($n >= 2000 && $n <= 2200) || ($n >= 3000 && $n <= 3200)) return 'SYNDIC';
    }
    return 'GESTION';
}

/* ── KPIs : Immeubles · Lots · Syndic · Gestion · Transaction ── */
$kpiImm = count($immeubles);
$kpiLots = array_sum(array_map(fn($r) => (int)($r['nb_lots'] ?: $r['nb_biens']), $immeubles));
$kpiSyndic = $kpiGest = $kpiTrans = 0;
foreach ($immeubles as $r) {
    $c = imm_categorie($r);
    if ($c === 'SYNDIC') $kpiSyndic++; elseif ($c === 'GESTION') $kpiGest++; else $kpiTrans++;
}

/* ── Sociétés ayant des immeubles (boutons scope) ── */
$societesAvecImm = [];
try {
    $societesAvecImm = $pdo->query("SELECT DISTINCT s.id, s.nom FROM immeubles i JOIN societes s ON s.id = i.id_societe ORDER BY s.nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  /* Cards immeubles : moins de contenu que les proprios → plus compactes.
     Sélecteur .ec-grid .ec-card (spécificité > .ec-card du composant) + désactive
     l'égalisation de hauteur (grid-auto-rows:1fr) pour coller au contenu. */
  .ec-grid { grid-auto-rows:auto !important; align-items:start; }
  .ec-grid .ec-card { min-height:0; height:auto; gap:8px; padding:13px 16px; }
  /* Filtre central (lettres / tranches N°) : compact, sur une seule ligne */
  #letterFilter { flex-wrap:nowrap; gap:5px; }
  #letterFilter .btn3d { padding:6px 9px; font-size:10.5px; border-radius:9px; }
  .mbi-main{
    background:
      linear-gradient(135deg, rgba(154,170,132,0.18) 0%, rgba(255,255,255,0) 35%,
        rgba(72,120,166,0.14) 60%, rgba(255,255,255,0) 85%, rgba(124,152,133,0.18) 100%),
      #fafbfc;
    background-attachment:fixed;
  }
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:var(--card,#fff); border-radius:14px; padding:10px 16px; min-width:96px;
    box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); text-align:center; }
  .pk-kpi-val { font-size:22px; font-weight:800; color:#243B5C; line-height:1.1; }
  .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; margin-top:3px; }
  .pk-scope { display:flex; flex-direction:column; gap:8px; align-items:flex-start; }
  .pk-btnrow { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
  .pk-btnrow-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; min-width:58px; }
  .pk-sbtn { display:inline-flex; align-items:center; cursor:pointer; text-decoration:none;
    font-family:'Sora',sans-serif; font-size:11.5px; font-weight:700; color:#3a3830;
    background:var(--card,#fff); border-radius:14px; padding:7px 14px;
    box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); transition:color .12s, box-shadow .12s; }
  .pk-sbtn:hover { color:#243B5C; }
  .pk-sbtn.active { color:#fff; background:#243b5c; box-shadow:inset 2px 2px 6px rgba(0,0,0,.28); }
  /* 3 zones sur toute la largeur — recherche à gauche, lettres centrées sur la page, tri à droite. */
  .pk-bar { position:sticky; top:0; z-index:50; display:grid;
    grid-template-columns:1fr auto 1fr; align-items:center; gap:16px;
    padding:10px 2px; margin-bottom:14px; background:rgba(250,251,252,.92); backdrop-filter:blur(6px); }
  .pk-bar-search { justify-self:start; }
  .pk-bar-center { justify-self:center; }
  .pk-bar-right  { justify-self:end; }
  @media (max-width:860px){ .pk-bar{ grid-template-columns:1fr; } .pk-bar > div{ justify-self:center; } }
  .pk-bar-search { width:100%; max-width:280px; display:flex; align-items:center; gap:8px;
    background:var(--card,#fff); border-radius:999px; padding:8px 16px; box-sizing:border-box;
    box-shadow:var(--neu-out,3px 3px 8px #d4d7de,-3px -3px 8px #fff); }
  .pk-bar-search input { border:none !important; outline:none !important; box-shadow:none !important;
    background:transparent !important; width:100%; padding:0; margin:0;
    font-family:'Sora',sans-serif; font-size:15px; font-weight:700; color:#243B5C; }
  .pk-bar-search input::placeholder { font-weight:600; color:#9a9690; }
  .pk-bar-center { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
  .pk-bar-right { display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap; }
  .btn3d { cursor:pointer; border:1px solid transparent; font-family:'Sora',sans-serif; font-weight:800; font-size:12px;
    color:#3a3830; padding:9px 18px; border-radius:12px; letter-spacing:.02em; text-decoration:none;
    display:inline-flex; align-items:center; gap:6px; line-height:1; transition:transform .08s ease, box-shadow .08s ease, filter .12s; }
  .btn3d:hover { filter:brightness(1.03); }
  .btn3d:active, .btn3d.active { transform:translateY(2px) !important; box-shadow:0 1px 0 rgba(0,0,0,.06) !important; filter:saturate(1.15) brightness(.99); }
  .l-all  { background:#eef1f6; color:#4a5568; border-color:#dfe3ea; box-shadow:0 3px 0 #d6dae2,0 4px 8px rgba(0,0,0,.05); }
  .l-af   { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; box-shadow:0 3px 0 #cfe0fb,0 4px 8px rgba(0,0,0,.05); }
  .l-gl   { background:#e6f7f4; color:#0f766e; border-color:#b7e3dc; box-shadow:0 3px 0 #cdeae5,0 4px 8px rgba(0,0,0,.05); }
  .l-mp   { background:#ecfdf3; color:#15803d; border-color:#bbf7d0; box-shadow:0 3px 0 #cdefd9,0 4px 8px rgba(0,0,0,.05); }
  .l-qz   { background:#fff7ed; color:#b45309; border-color:#fed7aa; box-shadow:0 3px 0 #f6e2c6,0 4px 8px rgba(0,0,0,.05); }
  .b-navy { background:#eef2f8; color:#243b5c; border-color:#c7d2e0; box-shadow:0 3px 0 #d6deea,0 4px 8px rgba(0,0,0,.05); }
  .b-violet { background:#f5f0ff; color:#7c3aed; border-color:#ddd0fb; box-shadow:0 3px 0 #e6dcfb,0 4px 8px rgba(0,0,0,.05); }
  .im-mbadge { font-size:10px; font-weight:800; padding:2px 7px; border-radius:6px; }
  .im-m-syndic { background:#eff6ff; color:#1d4ed8; }
  .im-m-gestion { background:#ecfdf3; color:#15803d; }
  .im-m-transaction { background:#fff7ed; color:#b45309; }
  /* Classement manuel (immeubles avec annonce) */
  .im-cat { margin-top:8px; padding-top:8px; border-top:1px dashed rgba(196,192,186,.6); display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
  .im-cat-lbl { width:100%; font-size:10.5px; font-weight:700; color:#b45309; margin-bottom:2px; }
  .im-catb { cursor:pointer; border:1px solid #e2e2e2; background:#fff; border-radius:8px; padding:4px 9px;
    font-family:'Sora',sans-serif; font-size:10.5px; font-weight:700; color:#6a6660; }
  .im-catb:hover { filter:brightness(.97); }
  .im-cb-syndic.active      { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
  .im-cb-gestion.active     { background:#ecfdf3; color:#15803d; border-color:#bbf7d0; }
  .im-cb-transaction.active { background:#fff7ed; color:#b45309; border-color:#fed7aa; }
</style>

<div class="mbi-main">
  <!-- TOPBAR -->
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span class="active" style="font-size:1.7rem;font-weight:800;">Immeubles</span></nav>
    <div class="topbar-spacer"></div>
    <div style="display:flex;gap:8px;align-items:center;margin-right:10px;">
      <a href="<?= e(app_url('/agency_immeuble_form.php')) ?>" class="bl-btn"
         style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:700;box-shadow:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">➕ Nouveau</a>
      <a href="<?= e(app_url('/agency_immeubles.php?modal_crg=1')) ?>" class="bl-btn"
         style="background:#ecfdf3;color:#15803d;border:1px solid #bbf7d0;font-weight:800;box-shadow:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         title="Importer un CRG → crée immeuble, lots et propriétaires">📊 Importer un CRG</a>
    </div>
    <button type="button" class="topbar-icon-btn" title="Notifications"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <!-- PAGE HEAD : KPIs + société + type de mandat -->
  <div class="page-head" style="flex-wrap:wrap;gap:16px;align-items:center;">
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= number_format($kpiImm, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Immeubles</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val"><?= number_format($kpiLots, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Lots</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#1d4ed8;"><?= number_format($kpiSyndic, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Syndic</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#15803d;"><?= number_format($kpiGest, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Gestion</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#b45309;"><?= number_format($kpiTrans, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Transaction</div></div>
    </div>

    <?php $scopeLink = function(array $ov){ $q=$_GET; foreach($ov as $k=>$v){ if($v===null) unset($q[$k]); else $q[$k]=$v; } return '?'.http_build_query($q); }; ?>
    <div class="pk-scope">
      <?php if ($isAdmin && $societesAvecImm): ?>
      <div class="pk-btnrow">
        <span class="pk-btnrow-lbl">Société</span>
        <a href="<?= e($scopeLink(['societe'=>null])) ?>" class="pk-sbtn <?= $scopeSoc<=0?'active':'' ?>">Toutes</a>
        <?php foreach ($societesAvecImm as $s): ?>
          <a href="<?= e($scopeLink(['societe'=>(int)$s['id']])) ?>" class="pk-sbtn <?= $scopeSoc===(int)$s['id']?'active':'' ?>"><?= e($s['nom']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="pk-btnrow" id="mandatFilter">
        <span class="pk-btnrow-lbl">Mandat</span>
        <button type="button" class="btn3d l-all active" data-mandat=""            onclick="imSetMandat(this)">Tous</button>
        <button type="button" class="btn3d l-af"        data-mandat="SYNDIC"      onclick="imSetMandat(this)">🏛️ Syndic</button>
        <button type="button" class="btn3d l-mp"        data-mandat="GESTION"     onclick="imSetMandat(this)">🔑 Gestion</button>
        <button type="button" class="btn3d l-qz"        data-mandat="TRANSACTION" onclick="imSetMandat(this)">💼 Transaction</button>
      </div>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="bl-content">
    <?php if (empty($immeubles)): ?>
      <div class="bl-empty"><div class="bl-empty-icon">🏢</div><h2>Aucun immeuble trouvé</h2></div>
    <?php else: ?>
      <?php entity_card_assets(); ?>
      <div class="pk-bar">
        <div class="pk-bar-search">
          <span class="search-icon">🔍</span>
          <input type="text" id="immSearch" placeholder="Rechercher (ville, nom, réf, adresse)…" oninput="imFilter()" autocomplete="off" autofocus>
        </div>
        <div class="pk-bar-center" id="letterFilter">
          <?php if ($tri === 'num'): ?>
          <button type="button" class="btn3d l-all active" data-numrange=""           onclick="imSetBucket(this)">Tous</button>
          <button type="button" class="btn3d l-af"        data-numrange="0-20"        onclick="imSetBucket(this)">0–20</button>
          <button type="button" class="btn3d l-gl"        data-numrange="21-60"       onclick="imSetBucket(this)">21–60</button>
          <button type="button" class="btn3d l-mp"        data-numrange="61-110"      onclick="imSetBucket(this)">61–110</button>
          <button type="button" class="btn3d l-qz"        data-numrange="111-99999999" onclick="imSetBucket(this)">111+</button>
          <?php else: ?>
          <button type="button" class="btn3d l-all active" data-range=""    onclick="imSetBucket(this)">Tous</button>
          <button type="button" class="btn3d l-af"        data-range="A-F" onclick="imSetBucket(this)">A–F</button>
          <button type="button" class="btn3d l-gl"        data-range="G-L" onclick="imSetBucket(this)">G–L</button>
          <button type="button" class="btn3d l-mp"        data-range="M-P" onclick="imSetBucket(this)">M–P</button>
          <button type="button" class="btn3d l-qz"        data-range="Q-Z" onclick="imSetBucket(this)">Q–Z</button>
          <?php endif; ?>
        </div>
        <div class="pk-bar-right">
          <?php $dVoie = ($tri==='voie' && $dir==='asc') ? 'desc' : 'asc'; $dNum = ($tri==='num' && $dir==='asc') ? 'desc' : 'asc'; ?>
          <a href="<?= e($scopeLink(['tri'=>'voie','dir'=>$dVoie])) ?>" class="btn3d b-navy <?= $tri==='voie'?'active':'' ?>" title="Trier par nom de voie">🔤 <?= ($tri==='voie'&&$dir==='desc')?'Z→A':'A→Z' ?></a>
          <a href="<?= e($scopeLink(['tri'=>'num','dir'=>$dNum])) ?>" class="btn3d b-violet <?= $tri==='num'?'active':'' ?>" title="Trier par N° de voie">🔢 <?= ($tri==='num'&&$dir==='desc')?'9→1':'1→9' ?></a>
        </div>
      </div>

      <div class="ec-grid">
        <?php foreach ($immeubles as $im):
            $nb     = (int)($im['nb_lots'] ?: $im['nb_biens']);
            $cat    = imm_categorie($im);   // SYNDIC | GESTION | TRANSACTION (exclusif)
            $title  = trim((string)($im['nom_immeuble'] ?: $im['adresse_1']));
            $ville  = trim((string)$im['ville']);
            $adr    = trim((string)$im['adresse_1']);
            $sub    = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> '
                    . e(trim($adr . ($ville !== '' ? ' · ' . $ville : ''), ' ·'));
            $libM = ['SYNDIC'=>['syndic','🏛️ Syndic'],'GESTION'=>['gestion','🔑 Gestion'],'TRANSACTION'=>['transaction','💼 Transaction']];
            // Chip lots : cliquable vers bien_360 si l'immeuble n'a qu'UN bien ; sinon → immeuble (card).
            $lotsChip = '🏢 <strong>' . $nb . '</strong>&nbsp;lots';
            if ($nb === 1 && !empty($im['first_bien_id'])) {
                $lotsChip = '<a href="bien_360.php?id=' . (int)$im['first_bien_id'] . '" onclick="event.stopPropagation()" style="text-decoration:none;color:inherit;" title="Ouvrir la fiche 360° du bien">' . $lotsChip . '</a>';
            }
            $chips = [$lotsChip,
                      '<span class="im-mbadge im-m-'.$libM[$cat][0].'" data-catbadge>'.$libM[$cat][1].'</span>'];
            $searchTxt = mb_strtolower(trim($title . ' ' . $ville . ' ' . $im['reference_immeuble'] . ' ' . $adr), 'UTF-8');
            $numVoie   = preg_match('/(\d+)/', $adr, $mn) ? (int)$mn[1] : 0;
            entity_card([
                'accent' => '#7c9885',
                'url'    => 'immeuble_360.php?id=' . (int)$im['id'],
                'ref'    => (string)$im['reference_immeuble'],
                'title'  => $title,
                'sub'    => $sub,
                'chips'  => $chips,
                'data'   => ['name' => $searchTxt, 'letter' => imm_street_letter($adr), 'num' => $numVoie, 'mandat' => $cat],
            ]);
        endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
var imLetterRange = '', imNumRange = '', imMandat = '';
function imSetBucket(btn){
  document.querySelectorAll('#letterFilter .btn3d').forEach(b=>b.classList.toggle('active',b===btn));
  if(btn.hasAttribute('data-numrange')){ imNumRange = btn.getAttribute('data-numrange')||''; imLetterRange=''; }
  else { imLetterRange = btn.getAttribute('data-range')||''; imNumRange=''; }
  imFilter();
}
function imSetMandat(btn){ imMandat = btn.getAttribute('data-mandat')||''; document.querySelectorAll('#mandatFilter .btn3d').forEach(b=>b.classList.toggle('active',b===btn)); imFilter(); }
function imInBucket(c){
  if(imNumRange){ var p=imNumRange.split('-'); var n=parseInt(c.getAttribute('data-num')||'0',10); return n>=parseInt(p[0],10) && n<=parseInt(p[1],10); }
  if(imLetterRange){ var pr=imLetterRange.split('-'); var l=c.getAttribute('data-letter')||'#'; return l>=pr[0] && l<=pr[1]; }
  return true;
}
function imFilter(){
  var q=(document.getElementById('immSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('.ec-grid .ec-card').forEach(function(c){
    var name=c.getAttribute('data-name')||'', man=c.getAttribute('data-mandat')||'';
    var ok=(q===''||name.indexOf(q)!==-1) && imInBucket(c) && (imMandat===''||man.indexOf(imMandat)!==-1);
    c.style.display=ok?'':'none';
  });
}
document.addEventListener('DOMContentLoaded',function(){ var s=document.getElementById('immSearch'); if(s) s.focus(); });

// ── Classement manuel d'un immeuble (Syndic/Gestion/Transaction) ──
var IMM_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token('immeuble_cat') : '') ?>;
var IMM_LIB = {SYNDIC:['syndic','🏛️ Syndic'], GESTION:['gestion','🔑 Gestion'], TRANSACTION:['transaction','💼 Transaction']};
function imSetCat(ev, immId, cat, btn){
  ev.stopPropagation();
  var box = btn.closest('.im-cat'), card = btn.closest('.ec-card');
  var fd = new FormData(); fd.append('id_immeuble', immId); fd.append('categorie', cat); fd.append('csrf_token', IMM_CSRF);
  fetch('api/immeuble_categorie_save.php', {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){return r.json();}).then(function(j){
      if(!j || !j.ok){ alert('Erreur : '+((j&&j.error)||'?')); return; }
      box.querySelectorAll('.im-catb').forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      var badge = card.querySelector('[data-catbadge]');
      if(badge){ badge.className = 'im-mbadge im-m-'+IMM_LIB[cat][0]; badge.setAttribute('data-catbadge',''); badge.textContent = IMM_LIB[cat][1]; }
      card.setAttribute('data-mandat', cat);
    }).catch(function(){ alert('Erreur réseau'); });
}
</script>

<?php if (!empty($_GET['modal_crg'])): require_once __DIR__ . '/inc/csrf.php'; ?>
<!-- ══ MODALE IMPORT CRG (créer immeuble / lots / propriétaires) ══ -->
<input type="hidden" name="csrf_token" value="<?= e(function_exists('csrf_token') ? csrf_token('default') : '') ?>">
<div id="crg-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;">
  <div style="background:#fff;border-radius:16px;padding:32px;max-width:700px;width:100%;box-shadow:0 8px 30px rgba(0,0,0,0.2);">
    <div id="crg-step1">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h2 style="font-size:18px;font-weight:700;margin:0;">📊 Importer un CRG</h2>
        <a href="agency_immeubles.php" style="font-size:24px;text-decoration:none;color:#999;line-height:1;">&times;</a>
      </div>
      <p style="font-size:13px;color:#666;margin-bottom:20px;">Uploadez un Compte-Rendu de Gestion (PDF). L'IA extraira propriétaire, immeubles, lots, locataires et écritures.</p>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Propriétaire existant (optionnel)</label>
        <select id="crg-proprio" style="width:100%;padding:8px 12px;border:1px solid #d4d7de;border-radius:8px;font-size:13px;">
          <option value="0">— Nouveau (sera créé à partir du CRG) —</option>
          <?php foreach ($pdo->query("SELECT id, CONCAT(nom,' ',COALESCE(prenom,'')) AS label FROM proprietaires WHERE actif=1 ORDER BY nom") as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Fichier CRG (PDF)</label>
        <input type="file" id="crg-file" accept="application/pdf" style="font-size:13px;">
      </div>
      <div id="crg-error" style="display:none;padding:10px;background:#fef2f2;color:#991b1b;border-radius:8px;font-size:13px;margin-bottom:14px;"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <a href="agency_immeubles.php" style="padding:10px 20px;border:1px solid #d4d7de;border-radius:8px;text-decoration:none;color:#666;font-size:13px;font-weight:600;">Annuler</a>
        <button type="button" id="crg-analyze-btn" onclick="crgAnalyze()" style="padding:10px 20px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">🔍 Analyser le CRG</button>
      </div>
    </div>
    <div id="crg-step2" style="display:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="font-size:18px;font-weight:700;margin:0;">✅ Données extraites — Confirmation</h2>
        <a href="agency_immeubles.php" style="font-size:24px;text-decoration:none;color:#999;line-height:1;">&times;</a>
      </div>
      <div id="crg-preview" style="max-height:500px;overflow-y:auto;margin-bottom:16px;"></div>
      <div id="crg-error2" style="display:none;padding:10px;background:#fef2f2;color:#991b1b;border-radius:8px;font-size:13px;margin-bottom:14px;"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('crg-step1').style.display='';document.getElementById('crg-step2').style.display='none';" style="padding:10px 20px;border:1px solid #d4d7de;border-radius:8px;color:#666;font-size:13px;font-weight:600;cursor:pointer;background:#fff;">← Retour</button>
        <button type="button" id="crg-confirm-btn" onclick="crgConfirm()" style="padding:10px 20px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">✅ Confirmer l'import</button>
      </div>
    </div>
    <div id="crg-step3" style="display:none;text-align:center;padding:30px 0;">
      <div style="font-size:48px;margin-bottom:16px;">🎉</div>
      <h2 style="font-size:18px;font-weight:700;margin-bottom:8px;">Import terminé !</h2>
      <div id="crg-result" style="font-size:14px;color:#666;margin-bottom:20px;"></div>
      <a href="agency_immeubles.php" style="padding:10px 28px;background:#4a6038;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;">Voir les immeubles</a>
    </div>
  </div>
</div>
<script>
var crgCsrf = document.querySelector('input[name="csrf_token"]')?.value || '';
async function crgAnalyze(){
  var file=document.getElementById('crg-file').files[0];
  if(!file){ crgErr('crg-error','Sélectionnez un fichier PDF.'); return; }
  var btn=document.getElementById('crg-analyze-btn'); btn.disabled=true; btn.textContent='⏳ Analyse IA (30-60s)…';
  document.getElementById('crg-error').style.display='none';
  var fd=new FormData(); fd.append('action','parse'); fd.append('fichier_crg',file); fd.append('csrf_token',crgCsrf);
  try{
    var r=await fetch('api/import_crg.php',{method:'POST',headers:{'X-CSRF-Token':crgCsrf},body:fd}); var d=await r.json();
    if(!d.ok){ crgErr('crg-error',(d.error||'Erreur analyse.')+(d.raw?'\n\n'+d.raw:'')); btn.disabled=false; btn.textContent='🔍 Analyser le CRG'; return; }
    crgShowPreview(d.data); document.getElementById('crg-step1').style.display='none'; document.getElementById('crg-step2').style.display='';
  }catch(e){ crgErr('crg-error','Erreur réseau : '+e.message); }
  btn.disabled=false; btn.textContent='🔍 Analyser le CRG';
}
function crgShowPreview(data){
  var h=''; var p=data.proprietaire||{};
  h+='<div style="background:#f0fdf4;padding:12px;border-radius:8px;margin-bottom:12px;"><strong>👤 Propriétaire :</strong> '+esc(p.nom||'(non détecté)')+(p.adresse?' — '+esc(p.adresse):'')+'</div>';
  var per=data.periode||{};
  h+='<div style="background:#eff6ff;padding:12px;border-radius:8px;margin-bottom:12px;"><strong>📅 Période :</strong> '+(per.annee||'?')+' T'+(per.trimestre||'?')+(per.date_arrete?' — Arrêté au '+esc(per.date_arrete):'')+'</div>';
  h+='<div style="background:#fefce8;padding:12px;border-radius:8px;margin-bottom:12px;"><strong>💶 Soldes :</strong> Report: '+fmt(data.solde_report)+' | Débits: '+fmt(data.total_debits)+' | Crédits: '+fmt(data.total_credits)+'</div>';
  (data.immeubles||[]).forEach(function(imm){
    h+='<div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px;"><strong>🏢 '+esc(imm.nom||imm.code)+'</strong>';
    if(imm.adresse) h+='<br><span style="color:#888;font-size:12px;">'+esc(imm.adresse)+'</span>';
    h+='<div style="margin-top:8px;font-size:12px;">';
    (imm.lots||[]).forEach(function(lot){
      var locs=lot.locataires; if(!locs){ locs=lot.locataire_nom?[{nom:lot.locataire_nom,actif:(lot.statut==='occupe'||lot.statut==='occupé'),total_impaye:lot.total_impaye||0}]:[]; }
      var actifs=locs.filter(function(l){return l.actif;}).length;
      h+='<div style="padding:6px 0;border-bottom:1px solid #f3f4f6;"><div style="font-weight:600;">🔑 Lot '+esc(lot.numero_lot)+' <span style="color:#888;font-weight:400;">('+esc(lot.type_bien||'')+')</span> — <span style="color:'+(actifs>0?'#16a34a':'#dc2626')+';">'+(actifs>0?actifs+' actif'+(actifs>1?'s':''):'vacant')+'</span></div>';
      locs.forEach(function(l){ var col=l.actif?'#16a34a':'#9ca3af';
        h+='<div style="display:flex;justify-content:space-between;padding:2px 0 2px 16px;"><span style="color:'+col+';">'+(l.actif?'● ':'○ ')+esc(l.nom||'')+(l.actif?'':' <em style="color:#9ca3af;">(ancien)</em>')+'</span><span style="color:#dc2626;font-weight:600;">'+((l.total_impaye>0)?'⚠ '+fmt(l.total_impaye)+' impayé':'')+'</span></div>'; });
      h+='</div>';
    });
    h+='</div></div>';
  });
  document.getElementById('crg-preview').innerHTML=h;
}
async function crgConfirm(){
  var btn=document.getElementById('crg-confirm-btn'); btn.disabled=true; btn.textContent='⏳ Import…';
  document.getElementById('crg-error2').style.display='none';
  var fd=new FormData(); fd.append('action','confirm'); fd.append('id_proprietaire',document.getElementById('crg-proprio').value); fd.append('csrf_token',crgCsrf);
  try{
    var r=await fetch('api/import_crg.php',{method:'POST',headers:{'X-CSRF-Token':crgCsrf},body:fd}); var d=await r.json();
    if(!d.ok){ crgErr('crg-error2',d.error||'Erreur import.'); btn.disabled=false; btn.textContent='✅ Confirmer l\'import'; return; }
    document.getElementById('crg-step2').style.display='none'; document.getElementById('crg-step3').style.display=''; document.getElementById('crg-result').textContent=d.message;
  }catch(e){ crgErr('crg-error2','Erreur réseau : '+e.message); btn.disabled=false; btn.textContent='✅ Confirmer l\'import'; }
}
function crgErr(id,msg){ var el=document.getElementById(id); el.textContent=msg; el.style.display=''; }
function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fmt(n){ return (parseFloat(n)||0).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
</script>
<?php endif; ?>
