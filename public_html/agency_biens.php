<?php
/**
 * agency_biens.php — Liste des biens, UI alignée sur agency_immeubles / agency_proprietaires.
 *   Topbar (titre + Nouveau) · KPIs (Biens · Loués · Vacants · En annonce) · boutons Société/Agence cascade.
 *   Barre sticky : recherche (réf/nom/adresse/ville/type) · lettres (sur le nom de rue) · tri A→Z.
 *   Cards : vignette photo · nom immeuble · type · loyer · surface · étage → bien_360.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/entity_card.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$appLayout = true;
$pageTitle = 'Biens';
$robots    = 'noindex, nofollow';
$pdo       = $GLOBALS['pdo'];
$roleId    = current_role_id();
$isAdmin   = in_array($roleId, [1, 7], true);

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ── Nom de rue « propre » (1er mot réel) + lettre de classement ── */
function bi_street_clean(string $adresse): string {
    $s = mb_strtolower(trim($adresse), 'UTF-8');
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    static $stop = ['a','au','aux','et','bis','ter','rue','avenue','av','bd','boulevard','place','pl','impasse','imp','chemin','chem','cours',
        'allee','allees','all','route','rte','quai','montee','mtee','passage','pass','square','sq','villa','clos','lotissement','lot','residence','res','grande','grand','du','de','des','la','le','les','d','l'];
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $w) { if ($w !== '' && !ctype_digit($w) && !in_array($w, $stop, true)) $out[] = $w; }
    return implode(' ', $out);
}
function bi_street_letter(string $a): string {
    $c = bi_street_clean($a); $f = mb_strtoupper(mb_substr($c, 0, 1, 'UTF-8'), 'UTF-8');
    return preg_match('/[A-Z]/', $f) ? $f : '#';
}

/* ── Scope société / agence ── Par DÉFAUT : TOUTES sociétés + TOUTES agences (0/0).
   Les non-admins sont re-verrouillés sur leur société juste en dessous. ── */
$mySoc    = (int)($_SESSION['id_societe'] ?? 0);
$scopeSoc = isset($_GET['societe']) && ctype_digit((string)$_GET['societe']) ? (int)$_GET['societe'] : 0;
$scopeAg  = isset($_GET['agence'])  && ctype_digit((string)$_GET['agence'])  ? (int)$_GET['agence']  : 0;
if (!$isAdmin && $mySoc > 0) $scopeSoc = $mySoc;   // non-admin : verrouillé sur sa société

$conds = []; $params = [];
// Vue « Archivés » (?archives=1) : montre les biens sortis du portefeuille (vendu / perte de
// gestion / archivé). Sinon vue normale = uniquement le portefeuille actif.
$vueArchives = !empty($_GET['archives']);
if ($vueArchives) {
    $conds[] = "b.statut_bien IN ('archive','vendu','perdu_gestion')";
} else {
    // N'affiche jamais les biens supprimés / archivés (sinon les doublons soft-deleted réapparaissent).
    $conds[] = "(b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu','perdu_gestion'))";
}
if ($scopeSoc > 0) { $conds[] = 'b.id_societe = ?'; $params[] = $scopeSoc; }
if ($scopeAg  > 0) { $conds[] = 'b.id_agence = ?';  $params[] = $scopeAg;  }
$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';

$biens = [];
try {
    $sql = "SELECT b.id, b.reference_bien, b.etage, b.surface_habitable, b.nb_pieces, b.loyer_hc,
                   b.statut_bien,
                   b.id_societe, b.id_agence, b.id_immeuble, b.dpe_classe, b.dpe_reference_certificat,
                   i.nom_immeuble, i.adresse_1, i.ville,
                   COALESCE(bt.libelle, bt2.label) AS type_libelle,
                   COALESCE(NULLIF(p.societe,''), TRIM(CONCAT_WS(' ', p.prenom, p.nom))) AS proprio_nom,
                   p.id AS proprio_id, p.id_tiers AS proprio_tiers,
                   (SELECT bp.url_photo FROM biens_photos bp WHERE bp.id_bien = b.id ORDER BY bp.ordre ASC, bp.id ASC LIMIT 1) AS photo,
                   (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(bb.locataire_raison_sociale,''), TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom))) SEPARATOR ', ')
                      FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut='actif') AS locataires,
                   (SELECT bb.id FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut='actif' ORDER BY bb.id DESC LIMIT 1) AS bail_id,
                   (SELECT gd.id FROM ged_documents gd JOIN ged_document_links gdl ON gdl.document_id=gd.id
                      AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
                     WHERE gd.status='active' AND LOWER(gd.document_type) LIKE '%bail%' ORDER BY gd.id DESC LIMIT 1) AS bail_doc_id,
                   (SELECT gd.id FROM ged_documents gd JOIN ged_document_links gdl ON gdl.document_id=gd.id
                      AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
                     WHERE gd.status='active' AND LOWER(gd.document_type) LIKE '%dpe%' ORDER BY gd.id DESC LIMIT 1) AS dpe_doc_id,
                   EXISTS(SELECT 1 FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut='actif') AS has_bail,
                   EXISTS(SELECT 1 FROM annonces a WHERE a.id_bien = b.id
                          AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archive','archivee','archived','supprime'))) AS has_annonce,
                   /* Annonce ACTIVE + DIFFUSÉE sur les portails (Ubiflow → dont leboncoin) : pilote la couleur « on » du bouton Annonce */
                   EXISTS(SELECT 1 FROM annonces a WHERE a.id_bien = b.id
                          AND a.etat_publication = 'diffusee' AND COALESCE(a.visible_portails,0) = 1) AS annonce_diffusee,
                   /* Commercial attribué à l'annonce (annonces.id_user) */
                   (SELECT TRIM(CONCAT_WS(' ', u.prenom, u.nom))
                      FROM annonces a JOIN users u ON u.id = a.id_user
                     WHERE a.id_bien = b.id
                       AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archive','archivee','archived','supprime'))
                     ORDER BY a.id DESC LIMIT 1) AS annonce_user_nom,
                   EXISTS(SELECT 1 FROM mandats m WHERE m.id_bien = b.id AND m.statut='actif' AND m.type_mandat='vente')                  AS m_vente,
                   EXISTS(SELECT 1 FROM mandats m WHERE m.id_bien = b.id AND m.statut='actif' AND m.type_mandat IN ('location','gestion')) AS m_loc,
                   /* Prix de vente courant (patrimoine), repli annonce ; loyer mensuel (bail actif), repli annonce/bien */
                   COALESCE(
                     (SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
                     (SELECT COALESCE(a.prix_net_vendeur, a.prix) FROM annonces a WHERE a.id_bien=b.id AND COALESCE(a.type_transaction,'vente')<>'location' ORDER BY a.id DESC LIMIT 1)
                   ) AS prix_vente_aff,
                   COALESCE(
                     (SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif' ORDER BY bb.id DESC LIMIT 1),
                     (SELECT a.loyer FROM annonces a WHERE a.id_bien=b.id AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archive','archivee','archived','supprime')) ORDER BY a.id DESC LIMIT 1),
                     b.loyer_hc
                   ) AS loyer_mensuel_aff,
                   (SELECT dv.id FROM dossier_vente dv WHERE dv.id_bien = b.id AND dv.statut <> 'sans_suite'
                      ORDER BY (dv.etape NOT IN ('acte','solde')) DESC, dv.id DESC LIMIT 1) AS dv_id,
                   (SELECT dv.etape FROM dossier_vente dv WHERE dv.id_bien = b.id AND dv.statut <> 'sans_suite'
                      ORDER BY (dv.etape NOT IN ('acte','solde')) DESC, dv.id DESC LIMIT 1) AS dv_etape
            FROM biens b
            LEFT JOIN immeubles i  ON i.id  = b.id_immeuble
            LEFT JOIN bien_types      bt  ON bt.id  = b.id_bien_type
            LEFT JOIN base_types_bien bt2 ON bt2.id = b.id_type_bien
            LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
            $where";
    $st = $pdo->prepare($sql); $st->execute($params);
    $biens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ex) { error_log('[agency_biens] ' . $ex->getMessage()); $biens = []; }

/* ── Tri alphabétique croissant/décroissant sur le nom de rue (puis N°) ── */
$alphaDir = entity_card_sort_dir();
$biSortKey = function(array $r): string {
    $street = bi_street_clean((string)($r['adresse_1'] ?? ''));
    $num    = preg_match('/(\d+)/', (string)($r['adresse_1'] ?? ''), $m) ? (int)$m[1] : 0;
    return $street . ' ' . sprintf('%06d', $num);
};
usort($biens, function($a, $b) use ($alphaDir, $biSortKey) {
    $c = strcmp($biSortKey($a), $biSortKey($b));
    return $alphaDir === 'desc' ? -$c : $c;
});

/* ── KPIs : Biens · Loués · Vacants · En annonce ── */
$kpiBiens   = count($biens);
$kpiLoues   = count(array_filter($biens, fn($r) => !empty($r['has_bail'])));
// Vacant = bien EN GESTION LOCATIVE (mandat location/gestion actif) SANS bail actif.
// (On ne compte plus les biens en vente / vendus / garages sans mandat loc → fini les faux « vacants ».)
$kpiVacants = count(array_filter($biens, fn($r) => empty($r['has_bail']) && !empty($r['m_loc'])));
$kpiAnnonce = count(array_filter($biens, fn($r) => !empty($r['has_annonce'])));
// « Dossiers vente » = dossiers ACTIFS uniquement : on exclut les annulés (sans_suite,
// déjà retirés par la jointure) ET les ventes terminées (acte/solde = biens vendus).
$dvActiveOf = fn($r) => !empty($r['dv_id'])
    && !in_array(strtolower(trim((string)($r['dv_etape'] ?? ''))), ['acte', 'solde'], true);
$kpiDossier = count(array_filter($biens, $dvActiveOf));

/* ── Sociétés / agences ayant des biens (boutons scope) ── */
$societesAvecBiens = []; $agencesAvecBiens = [];
try {
    $societesAvecBiens = $pdo->query("SELECT DISTINCT s.id, s.nom FROM biens b JOIN societes s ON s.id=b.id_societe ORDER BY s.nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $agencesAvecBiens  = $pdo->query("SELECT DISTINCT ag.id, ag.ville, ag.nom_agence, ag.id_societe FROM biens b JOIN agences ag ON ag.id=b.id_agence ORDER BY ag.ville")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  /* Cards biens : hauteur naturelle compacte (pas d'étirement uniforme) */
  /* !important : entity_card_assets() ré-émet .ec-grid APRÈS ce <style> */
  /* grid-auto-rows:auto → chaque rangée prend sa propre hauteur (pas le 1fr global).
     align-items:stretch → les cards d'une MÊME rangée ont la même hauteur. */
  .ec-grid { grid-auto-rows:auto !important; align-items:stretch !important;
    /* largeur mini par card + plafond à 4 colonnes par ligne */
    grid-template-columns:repeat(auto-fill, minmax(max(260px, (100% - 48px)/4), 1fr)) !important; }
  .ec-card { min-height:0 !important; height:auto !important; align-self:stretch !important; padding:13px 15px; gap:7px; }
  .bi-badgecol { display:flex; flex-direction:column; gap:5px; align-items:stretch; width:112px; flex-shrink:0; }
  .bi-bcol { width:100%; box-sizing:border-box; text-align:center; }
  .bi-actb { font-size:10px; font-weight:700; padding:4px 9px; border-radius:7px; border:1px solid #d8dde4;
    background:#fff; color:#3a3830; text-decoration:none; white-space:nowrap; }
  .bi-actb:hover { background:#f3f5f8; color:#243B5C; }
  .bi-actb.on { background:#2d5f6b; color:#fff; border-color:#2d5f6b; }   /* annonce active + diffusée (bleu pétrole) */
  .bi-actb.on:hover { background:#1a44bd; color:#fff; }
  .bi-actb.dv { background:#c97b2e; color:#fff; border-color:#c97b2e; }    /* dossier de vente existant */
  .bi-actb.dv:hover { background:#b56c25; color:#fff; }
  .mbi-main{ background:linear-gradient(135deg, rgba(132,169,140,0.18) 0%, rgba(255,255,255,0) 35%, rgba(72,120,166,0.14) 60%, rgba(255,255,255,0) 85%, rgba(201,123,46,0.16) 100%), #fafbfc; background-attachment:fixed; }
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:var(--card,#fff); border-radius:14px; padding:10px 16px; min-width:96px; box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); text-align:center; }
  .pk-kpi-val { font-size:22px; font-weight:800; color:#243B5C; line-height:1.1; }
  .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; margin-top:3px; }
  .pk-scope { display:flex; flex-direction:column; gap:8px; align-items:flex-start; }
  .pk-btnrow { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
  .pk-btnrow-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; min-width:58px; }
  .pk-sbtn { display:inline-flex; align-items:center; cursor:pointer; text-decoration:none; font-family:'Sora',sans-serif; font-size:11.5px; font-weight:700; color:#3a3830; background:var(--card,#fff); border-radius:14px; padding:7px 14px; box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); transition:color .12s, box-shadow .12s; }
  .pk-sbtn:hover { color:#243B5C; }
  .pk-sbtn.active { color:#fff; background:#4878a6; box-shadow:inset 2px 2px 6px rgba(0,0,0,.28); }
  .pk-sbtn.ag.active { background:#4878a6; }
  /* 3 zones sur toute la largeur — recherche à gauche, lettres centrées sur la page, tri à droite. */
  .pk-bar { position:sticky; top:0; z-index:50; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:16px; padding:10px 2px; margin-bottom:14px; background:rgba(250,251,252,.92); backdrop-filter:blur(6px); }
  .pk-bar-left { justify-self:start; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .pk-bar-center { justify-self:center; }
  /* Toggle mandat (Vente / Location) à droite de la recherche */
  .pk-mandat-toggle { display:inline-flex; align-items:center; gap:3px; height:38px; box-sizing:border-box; background:var(--card,#fff); border-radius:999px; padding:0 4px; box-shadow:var(--neu-out,3px 3px 8px #d4d7de,-3px -3px 8px #fff); }
  .pk-mtb { display:inline-flex; align-items:center; height:30px; cursor:pointer; border:1px solid transparent; background:transparent; font-family:'Sora',sans-serif; font-weight:800; font-size:11.5px; color:#6b7280; padding:0 13px; border-radius:999px; line-height:1; transition:color .12s, background .12s; }
  .pk-mtb:hover { color:#243B5C; }
  .pk-mtb.active { color:#fff; }
  .pk-mtb.active[data-mandat=""]         { background:#4878a6; }
  .pk-mtb.mv.active { background:#dc2626; }
  .pk-mtb.ml.active { background:#2d5f6b; }
  .pk-bar-right  { justify-self:end; }
  @media (max-width:860px){ .pk-bar{ grid-template-columns:1fr; } .pk-bar > div{ justify-self:center; } }
  .pk-bar-search { width:100%; max-width:280px; height:38px; display:flex; align-items:center; gap:8px; background:var(--card,#fff); border-radius:999px; padding:0 16px; box-sizing:border-box; box-shadow:var(--neu-out,3px 3px 8px #d4d7de,-3px -3px 8px #fff); }
  .pk-bar-search input { border:none !important; outline:none !important; box-shadow:none !important; background:transparent !important; width:100%; padding:0; margin:0; font-family:'Sora',sans-serif; font-size:15px; font-weight:700; color:#243B5C; }
  .pk-bar-search input::placeholder { font-weight:600; color:#9a9690; }
  .pk-bar-center { display:flex; gap:5px; justify-content:center; flex-wrap:nowrap; }
  .pk-bar-center .btn3d { padding:6px 9px; font-size:10.5px; border-radius:9px; }
  .pk-bar-right { display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap; }
  .btn3d { cursor:pointer; border:1px solid transparent; font-family:'Sora',sans-serif; font-weight:800; font-size:12px; color:#3a3830; padding:9px 18px; border-radius:12px; letter-spacing:.02em; text-decoration:none; display:inline-flex; align-items:center; gap:6px; line-height:1; transition:transform .08s ease, box-shadow .08s ease, filter .12s; }
  .btn3d:hover { filter:brightness(1.03); }
  .btn3d:active, .btn3d.active { transform:translateY(2px) !important; box-shadow:0 1px 0 rgba(0,0,0,.06) !important; filter:saturate(1.15) brightness(.99); }
  .l-all  { background:#eef1f6; color:#4a5568; border-color:#dfe3ea; box-shadow:0 3px 0 #d6dae2,0 4px 8px rgba(0,0,0,.05); }
  .l-af   { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; box-shadow:0 3px 0 #cfe0fb,0 4px 8px rgba(0,0,0,.05); }
  .l-gl   { background:#e6f7f4; color:#0f766e; border-color:#b7e3dc; box-shadow:0 3px 0 #cdeae5,0 4px 8px rgba(0,0,0,.05); }
  .l-mp   { background:#ecfdf3; color:#15803d; border-color:#bbf7d0; box-shadow:0 3px 0 #cdefd9,0 4px 8px rgba(0,0,0,.05); }
  .l-qz   { background:#fff7ed; color:#b45309; border-color:#fed7aa; box-shadow:0 3px 0 #f6e2c6,0 4px 8px rgba(0,0,0,.05); }
  .b-navy { background:#eef2f8; color:#243b5c; border-color:#c7d2e0; box-shadow:0 3px 0 #d6deea,0 4px 8px rgba(0,0,0,.05); }
  .b-amber { background:#fdf2e3; color:#b45309; border-color:#f3d9aa; box-shadow:0 3px 0 #f3e2c2,0 4px 8px rgba(0,0,0,.05); }
  .b-orange { background:#fdf0e3; color:#c97b2e; border-color:#f0cda3; box-shadow:0 3px 0 #f1d8bd,0 4px 8px rgba(0,0,0,.05); }
  .b-orange.active { background:#c97b2e; color:#fff; border-color:#c97b2e; }
  .bi-typebadge { font-size:10px; font-weight:800; padding:2px 8px; border-radius:6px; background:#eef6f0; color:#3f6b4e; }
  .bi-mandat { display:inline-flex; align-self:flex-start; align-items:center; gap:4px; font-size:10.5px; font-weight:800; padding:2px 9px; border-radius:999px; letter-spacing:.02em; margin-bottom:2px; }
  .bi-meta { margin-top:6px; padding-top:7px; border-top:1px dashed rgba(196,192,186,.5); }
  .bi-line { font-size:12px; color:#4a4640; line-height:1.5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .bi-pics { display:flex; gap:6px; flex-wrap:wrap; margin-top:5px; }
  .bi-pic { font-size:10px; font-weight:700; padding:2px 8px; border-radius:7px; border:1px solid #e2e6ec; }
  .bi-pic.on  { background:#ecfdf3; color:#15803d; border-color:#bbf7d0; }
  .bi-pic.off { background:#f4f4f5; color:#b8b3ac; }
  .bi-pic.on, .bi-pic.up { cursor:pointer; }
  .bi-pic.on:hover, .bi-pic.up:hover { filter:brightness(.97); }
</style>

<div class="mbi-main">
  <!-- TOPBAR -->
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span class="active" style="font-size:1.7rem;font-weight:800;">Biens</span></nav>
    <div class="topbar-spacer"></div>
    <div style="display:flex;gap:8px;align-items:center;margin-right:10px;">
      <a href="<?= e(app_url('/bien_nouveau.php')) ?>" class="bl-btn" style="background:linear-gradient(135deg,#D4A047,#c97b2e);color:#fff;border:1px solid #c97b2e;font-weight:700;box-shadow:0 3px 0 #a9641f,0 5px 12px rgba(201,123,46,.35);text-decoration:none;display:inline-flex;align-items:center;gap:6px;" title="Création d'un bien hors gestion — pilote adresse-first">✨ Nouveau bien hors gestion</a>
    </div>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <!-- PAGE HEAD : KPIs + société/agence -->
  <div class="page-head" style="flex-wrap:wrap;gap:16px;align-items:center;">
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= number_format($kpiBiens, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Biens</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#15803d;"><?= number_format($kpiLoues, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Loués</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#b45309;"><?= number_format($kpiVacants, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Vacants</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#1d4ed8;"><?= number_format($kpiAnnonce, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">En annonce</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#c97b2e;"><?= number_format($kpiDossier, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Dossiers vente</div></div>
    </div>

    <?php
      $scopeLink = function(array $ov){ $q=$_GET; foreach($ov as $k=>$v){ if($v===null) unset($q[$k]); else $q[$k]=$v; } return '?'.http_build_query($q); };
      $agShown = array_values(array_filter($agencesAvecBiens, fn($a) => $scopeSoc <= 0 || (int)$a['id_societe'] === $scopeSoc));
    ?>
    <div class="pk-scope">
      <?php if ($isAdmin && $societesAvecBiens): ?>
      <div class="pk-btnrow">
        <span class="pk-btnrow-lbl">Société</span>
        <a href="<?= e($scopeLink(['societe'=>0,'agence'=>null])) ?>" class="pk-sbtn <?= $scopeSoc<=0?'active':'' ?>">Toutes</a>
        <?php foreach ($societesAvecBiens as $s): ?>
          <a href="<?= e($scopeLink(['societe'=>(int)$s['id'],'agence'=>null])) ?>" class="pk-sbtn <?= $scopeSoc===(int)$s['id']?'active':'' ?>"><?= e($s['nom']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($agShown): ?>
      <div class="pk-btnrow">
        <span class="pk-btnrow-lbl">Agence</span>
        <a href="<?= e($scopeLink(['agence'=>null])) ?>" class="pk-sbtn ag <?= $scopeAg<=0?'active':'' ?>">Toutes</a>
        <?php foreach ($agShown as $a): $ville = trim((string)($a['ville'] ?? '')) ?: (string)$a['nom_agence']; ?>
          <a href="<?= e($scopeLink(['agence'=>(int)$a['id']])) ?>" class="pk-sbtn ag <?= $scopeAg===(int)$a['id']?'active':'' ?>" title="<?= e($a['nom_agence']) ?>"><?= e($ville) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="bl-content">
    <?php if (empty($biens)): ?>
      <div class="bl-empty"><div class="bl-empty-icon">🏠</div><h2>Aucun bien trouvé</h2></div>
    <?php else: ?>
      <?php entity_card_assets(); ?>
      <div class="pk-bar">
        <div class="pk-bar-left">
          <div class="pk-bar-search">
            <span class="search-icon">🔍</span>
            <input type="text" id="biSearch" placeholder="Rechercher (réf, immeuble, adresse, type)…" oninput="biFilter()" autocomplete="off" autofocus>
          </div>
          <div class="pk-mandat-toggle" id="mandatToggle" title="Filtrer par type de mandat">
            <button type="button" class="pk-mtb active" data-mandat=""        onclick="biSetMandat(this)">Tous</button>
            <button type="button" class="pk-mtb mv"     data-mandat="vente"    onclick="biSetMandat(this)">🏷️ Vente</button>
            <button type="button" class="pk-mtb ml"     data-mandat="location" onclick="biSetMandat(this)">🔑 Location</button>
          </div>
        </div>
        <div class="pk-bar-center" id="letterFilter">
          <button type="button" class="btn3d l-all active" data-range=""    onclick="biSetRange(this)">Tous</button>
          <button type="button" class="btn3d l-af"        data-range="A-F" onclick="biSetRange(this)">A–F</button>
          <button type="button" class="btn3d l-gl"        data-range="G-L" onclick="biSetRange(this)">G–L</button>
          <button type="button" class="btn3d l-mp"        data-range="M-P" onclick="biSetRange(this)">M–P</button>
          <button type="button" class="btn3d l-qz"        data-range="Q-Z" onclick="biSetRange(this)">Q–Z</button>
        </div>
        <div class="pk-bar-right">
          <button type="button" id="vacToggle" class="btn3d b-amber" onclick="biToggleVac(this)" title="N'afficher que les biens vacants (sans bail actif)">🔑 Biens vacants</button>
          <button type="button" id="annToggle" class="btn3d b-navy" onclick="biToggleAnn(this)" title="N'afficher que les biens avec une annonce">📣 Mes annonces</button>
          <button type="button" id="dvToggle" class="btn3d b-orange" onclick="biToggleDv(this)" title="N'afficher que les biens ayant un dossier de vente">🤝 Dossiers vente</button>
          <a href="<?= e(app_url('/agency_biens.php' . ($vueArchives ? '' : '?archives=1'))) ?>" class="btn3d<?= $vueArchives ? ' active' : '' ?>" style="text-decoration:none;<?= $vueArchives ? 'background:#efe7f7;color:#6b4aa0;' : '' ?>" title="Biens sortis du portefeuille (vendus / perte de gestion / archivés)"><?= $vueArchives ? '← Portefeuille actif' : '🗂 Archivés' ?></a>
        </div>
      </div>

      <div class="ec-grid">
        <?php foreach ($biens as $b):
            $title = trim((string)($b['nom_immeuble'] ?: $b['adresse_1'])) ?: ('Bien ' . (int)$b['id']);
            $ville = trim((string)$b['ville']);
            $adr   = trim((string)$b['adresse_1']);
            $sub   = $ville !== '' ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> ' . e($ville) : '';
            // Photo (chemin relatif → URL servable)
            $photo = trim((string)($b['photo'] ?? ''));
            $thumb = $photo === '' ? '' : (preg_match('#^https?://#', $photo) ? $photo : asset_url('/' . ltrim($photo, '/')));
            // Chips : loyer, surface, étage, pièces
            $chips = [];
            $loyer = (float)($b['loyer_hc'] ?? 0);
            if ($loyer > 0) $chips[] = '💶 <strong>' . number_format($loyer, 0, ',', ' ') . '</strong>&nbsp;€';
            $surf = (float)($b['surface_habitable'] ?? 0);
            if ($surf > 0) $chips[] = '📐 ' . rtrim(rtrim(number_format($surf, 1, ',', ' '), '0'), ',') . '&nbsp;m²';
            $et = $b['etage'];
            if ($et !== null && $et !== '') $chips[] = '🪜 ' . ((int)$et === 0 ? 'RDC' : 'Ét. ' . (int)$et);
            if ((int)($b['nb_pieces'] ?? 0) > 0) $chips[] = '🚪 T' . (int)$b['nb_pieces'];
            $bid0 = (int)$b['id'];
            // Bouton « Annonce » coloré (sélectionné) UNIQUEMENT si l'annonce est
            // active ET diffusée sur les portails (Ubiflow → leboncoin). Une annonce
            // en brouillon / non diffusée laisse le bouton en neutre.
            $annOn = !empty($b['annonce_diffusee']);
            // Accent (trait gauche) + pastille selon le(s) mandat(s) actif(s) :
            //   location → bleu · vente → rouge · les deux → violet · sinon (gestion/aucun) → vert.
            $mVente = !empty($b['m_vente']); $mLoc = !empty($b['m_loc']);
            $mandLabel = ''; $accent = '#84a98c';
            if ($mVente && $mLoc)      { $accent = '#7c3aed'; $mandLabel = 'Vente + Location'; }
            elseif ($mVente)           { $accent = '#dc2626'; $mandLabel = 'Vente'; }
            elseif ($mLoc)             { $accent = '#2d5f6b'; $mandLabel = 'Location'; }
            // Dossier de vente (pivot transaction) : accent orange à droite + bouton dédié.
            $dvId    = (int)($b['dv_id'] ?? 0);
            $dvEtape = trim((string)($b['dv_etape'] ?? ''));
            $hasDv   = $dvId > 0;
            // Actif = ni annulé (déjà exclu) ni vendu (acte/solde) → pilote KPI + filtre.
            $dvActive = $hasDv && !in_array(strtolower($dvEtape), ['acte', 'solde'], true);
            $dvBtn = $hasDv
                ? '<a class="bi-actb bi-bcol dv" href="transaction_dossier.php?id=' . $dvId . '" onclick="event.stopPropagation()" title="Ouvrir le dossier de vente' . ($dvEtape !== '' ? ' (' . e($dvEtape) . ')' : '') . '">🤝 Dossier</a>'
                : '<a class="bi-actb bi-bcol" href="transaction_dossier.php?id_bien=' . $bid0 . '" onclick="event.stopPropagation()" title="Créer le dossier de vente">🤝 Dossier</a>';
            $badge = '<div class="bi-badgecol">'
                   . ($b['type_libelle'] ? '<span class="bi-typebadge bi-bcol">' . e($b['type_libelle']) . '</span>' : '<span class="bi-bcol" style="visibility:hidden;">—</span>')
                   . '<a class="bi-actb bi-bcol" href="bien_detail.php?edit=' . $bid0 . '" onclick="event.stopPropagation()" title="Descriptif du bien">📝 Fiche</a>'
                   . '<a class="bi-actb bi-bcol' . ($annOn ? ' on' : '') . '" href="bien_detail.php?edit=' . $bid0 . '&section=annonce" onclick="event.stopPropagation()" title="' . ($annOn ? 'Voir / éditer l\'annonce' : 'Créer une annonce') . '">📣 Annonce</a>'
                   . $dvBtn
                   . '</div>';
            // Vue « Archivés » : on remplace la colonne d'actions par le motif + désarchivage.
            if ($vueArchives) {
                $st = (string)($b['statut_bien'] ?? '');
                $stLabel = ['vendu' => '🏷️ Vendu', 'perdu_gestion' => '📉 Perte gestion', 'archive' => '🗂 Archivé'][$st] ?? $st;
                $stColor = ['vendu' => '#2d8a4e', 'perdu_gestion' => '#a8342a', 'archive' => '#6b4aa0'][$st] ?? '#6b7280';
                $badge = '<div class="bi-badgecol">'
                       . '<span class="bi-typebadge bi-bcol" style="background:' . $stColor . '18;color:' . $stColor . ';">' . $stLabel . '</span>'
                       . '<button type="button" class="bi-actb bi-bcol" onclick="event.stopPropagation();biDesarchiver(' . $bid0 . ')" title="Remettre ce bien dans le portefeuille actif">↩️ Désarchiver</button>'
                       . '</div>';
            }
            // Bloc : propriétaire · locataire(s) · pictos bail/DPE
            $proprio = trim((string)($b['proprio_nom'] ?? ''));
            $locs    = trim((string)($b['locataires'] ?? ''));
            $hasBail = !empty($b['has_bail']);
            $dpe     = strtoupper(trim((string)($b['dpe_classe'] ?? '')));
            $hasDpe  = $dpe !== '' || trim((string)($b['dpe_reference_certificat'] ?? '')) !== '';
            $dpeCol  = ['A'=>'#15803d','B'=>'#3da06e','C'=>'#84a98c','D'=>'#caa53a','E'=>'#d98326','F'=>'#d9602e','G'=>'#c0392b'][$dpe] ?? '#15803d';
            // Lien propriétaire → proprio 360 (tiers_360 sinon fiche legacy)
            $proprioUrl = !empty($b['proprio_tiers']) ? ('tiers_360.php?id=' . (int)$b['proprio_tiers'])
                        : (!empty($b['proprio_id']) ? ('agency_proprietaire_fiche.php?id=' . (int)$b['proprio_id']) : '');
            $bid = (int)$b['id'];
            $extra = '<div class="bi-meta">';
            if ($mandLabel !== '') {
                // Prix à droite : vente → prix de vente ; location → loyer mensuel ; les deux → les deux.
                $prixV = (float)($b['prix_vente_aff'] ?? 0);
                $loyM  = (float)($b['loyer_mensuel_aff'] ?? 0);
                $prixParts = [];
                if ($mVente && $prixV > 0) $prixParts[] = '🏷️ ' . number_format($prixV, 0, ',', ' ') . ' €';
                if ($mLoc   && $loyM  > 0) $prixParts[] = '🔑 ' . number_format($loyM, 0, ',', ' ') . ' €/mois';
                $prixHtml = $prixParts
                    ? '<span style="margin-left:auto;font-weight:800;white-space:nowrap;">' . implode(' · ', $prixParts) . '</span>'
                    : '';
                $extra .= '<div class="bi-mandat" style="display:flex;align-items:center;gap:8px;background:' . $accent . '14;color:' . $accent . ';border:1px solid ' . $accent . '33;">'
                        . '<span>📑 ' . e($mandLabel) . '</span>' . $prixHtml . '</div>';
            }
            if ($proprio !== '') {
                $extra .= '<div class="bi-line">👤 ' . ($proprioUrl !== ''
                    ? '<a href="' . e($proprioUrl) . '" onclick="event.stopPropagation()" style="color:#0e7490;text-decoration:none;font-weight:700;">' . e($proprio) . '</a>'
                    : '<b>' . e($proprio) . '</b>') . '</div>';
            }
            // Commercial attribué à l'annonce (annonces.id_user)
            $annUser = trim((string)($b['annonce_user_nom'] ?? ''));
            if ($annUser !== '') {
                $extra .= '<div class="bi-line">📣 <b style="color:#2d5f6b;">' . e($annUser) . '</b></div>';
            }
            if ($locs !== '') {
                $bailId = (int)($b['bail_id'] ?? 0);
                $extra .= '<div class="bi-line">🔑 ' . ($bailId > 0
                    ? '<a href="bail_360.php?id=' . $bailId . '" onclick="event.stopPropagation()" style="color:#2d5f6b;text-decoration:none;font-weight:700;">' . e($locs) . '</a>'
                    : e($locs)) . '</div>';
            }
            // Pictos = état du DOCUMENT GED : chargé → clic ouvre le viewer (+ champs) ; absent → clic ouvre l'upload.
            $bailDoc = (int)($b['bail_doc_id'] ?? 0);
            $dpeDoc  = (int)($b['dpe_doc_id'] ?? 0);
            $extra .= '<div class="bi-pics">'
                   . ($bailDoc > 0
                        ? '<span class="bi-pic on" title="Voir le bail" onclick="biViewDoc(event,' . $bailDoc . ',\'Bail\')">📄 Bail 👁</span>'
                        : '<span class="bi-pic off up" title="Charger le bail" onclick="biUpload(event,' . $bid . ',\'bail_signe\',\'Bail\')">📄 Bail ⬆</span>')
                   . ($dpeDoc > 0
                        ? '<span class="bi-pic on" style="color:' . $dpeCol . ';border-color:' . $dpeCol . '33;" title="Voir le DPE" onclick="biViewDoc(event,' . $dpeDoc . ',\'DPE\')">🌡️ DPE' . ($dpe !== '' ? ' ' . e($dpe) : '') . ' 👁</span>'
                        : '<span class="bi-pic off up" title="Charger le DPE" onclick="biUpload(event,' . $bid . ',\'dpe\',\'DPE\')">🌡️ DPE' . ($dpe !== '' ? ' ' . e($dpe) : '') . ' ⬆</span>')
                   . '</div></div>';
            $searchTxt = mb_strtolower(trim($b['reference_bien'] . ' ' . $title . ' ' . $adr . ' ' . $ville . ' ' . (string)$b['type_libelle'] . ' ' . $proprio . ' ' . $locs), 'UTF-8');
            entity_card([
                'accent'        => $accent,
                'accent_right'  => $dvActive ? '#c97b2e' : '',
                'accent_right_w'=> 3,
                'url'     => 'bien_360.php?id=' . $bid,
                'thumb'   => $thumb,
                'ref'     => (string)$b['reference_bien'],
                'title'   => $title,
                'sub'     => $sub,
                'badge'   => $badge,
                'chips'   => $chips,
                'extra'   => $extra,
                'data'    => ['name' => $searchTxt, 'letter' => bi_street_letter($adr), 'annonce' => !empty($b['has_annonce']) ? 1 : 0, 'vacant' => (empty($b['has_bail']) && !empty($b['m_loc'])) ? 1 : 0, 'dossier' => $dvActive ? 1 : 0, 'vente' => $mVente ? 1 : 0, 'loc' => $mLoc ? 1 : 0],
            ]);
        endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
var biRange = '', biAnnOnly = false, biVacOnly = false, biDvOnly = false, biMandat = '';
function biSetRange(btn){ biRange = btn.getAttribute('data-range')||''; document.querySelectorAll('#letterFilter .btn3d').forEach(b=>b.classList.toggle('active',b===btn)); biFilter(); }
function biSetMandat(btn){ biMandat = btn.getAttribute('data-mandat')||''; document.querySelectorAll('#mandatToggle .pk-mtb').forEach(b=>b.classList.toggle('active',b===btn)); biFilter(); }
function biToggleAnn(btn){ biAnnOnly = !biAnnOnly; btn.classList.toggle('active', biAnnOnly); biFilter(); }
function biToggleVac(btn){ biVacOnly = !biVacOnly; btn.classList.toggle('active', biVacOnly); biFilter(); }
function biToggleDv(btn){ biDvOnly = !biDvOnly; btn.classList.toggle('active', biDvOnly); biFilter(); }
function biInRange(l){ if(!biRange) return true; var p=biRange.split('-'); return l>=p[0] && l<=p[1]; }
// Normalisation : minuscules, sans accents, ponctuation → espaces, espaces compactés.
function biNorm(s){
  return (s||'').toString().toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g,'')   // enlève les accents
    .replace(/[^a-z0-9]+/g,' ')                          // tout séparateur (tiret, apostrophe…) → espace
    .replace(/\s+/g,' ').trim();
}
function biFilter(){
  // recherche par MOTS : chaque mot saisi doit se retrouver dans la fiche, quel que soit l'ordre
  var terms=biNorm(document.getElementById('biSearch').value).split(' ').filter(Boolean);
  document.querySelectorAll('.ec-grid .ec-card').forEach(function(c){
    if(c._n===undefined) c._n=biNorm(c.getAttribute('data-name')||''); // normalisé une seule fois puis mis en cache
    var name=c._n, letter=c.getAttribute('data-letter')||'#';
    var ann=(c.getAttribute('data-annonce')==='1'), vac=(c.getAttribute('data-vacant')==='1'), dv=(c.getAttribute('data-dossier')==='1');
    var vente=(c.getAttribute('data-vente')==='1'), loc=(c.getAttribute('data-loc')==='1');
    var mandatOk = (biMandat==='') || (biMandat==='vente' && vente) || (biMandat==='location' && loc);
    var textOk = terms.length===0 || terms.every(function(t){ return name.indexOf(t)!==-1; });
    var ok = textOk && biInRange(letter) && mandatOk && (!biAnnOnly || ann) && (!biVacOnly || vac) && (!biDvOnly || dv);
    c.style.display=ok?'':'none';
  });
}
document.addEventListener('DOMContentLoaded',function(){ var s=document.getElementById('biSearch'); if(s) s.focus(); });

// ── Pictos : voir un doc GED déjà chargé / charger un doc manquant ──
var BI_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token('dossier_estimation') : '') ?>;
var BI_ARCH_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token('archiver_bien') : '') ?>;
function biDesarchiver(id){
  if(!confirm('Remettre ce bien dans le portefeuille actif ?')) return;
  var fd=new FormData(); fd.append('id_bien',id); fd.append('csrf_token',BI_ARCH_CSRF);
  fetch(<?= json_encode(app_url('/api/bien_desarchiver.php')) ?>,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){return r.json();}).then(function(j){ if(j&&j.success){ location.reload(); } else { alert('❌ '+((j&&j.message)||'Échec')); } })
    .catch(function(e){ alert('❌ Réseau : '+e); });
}
var biUpCtx = {bien:0, docType:''};
function biViewDoc(ev, docId, label){ ev.stopPropagation(); if(window.mvptModalView){ window.mvptModalView(docId, label); } else { window.location='bien_doc_360.php?doc_id='+docId; } }
function biUpload(ev, bienId, docType, label){
  ev.stopPropagation(); biUpCtx={bien:bienId, docType:docType};
  document.getElementById('biUpTitle').textContent='Charger : '+label;
  document.getElementById('biUpMsg').textContent=''; document.getElementById('biUpFile').value='';
  document.getElementById('biUpModal').style.display='flex';
}
function biUploadSubmit(){
  var f=document.getElementById('biUpFile').files[0], m=document.getElementById('biUpMsg');
  if(!f){ m.style.color='#c62828'; m.textContent='Sélectionne un fichier.'; return; }
  m.style.color='#6b7280'; m.textContent='⏳ Chargement + classement GED…';
  var fd=new FormData(); fd.append('id_bien',biUpCtx.bien); fd.append('doc_type',biUpCtx.docType);
  fd.append('csrf_token',BI_CSRF); fd.append('CSRF',BI_CSRF); fd.append('fichier',f);
  fetch('api/bien_estimation_upload.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(!j||!j.ok){ m.style.color='#c62828'; m.textContent='❌ '+((j&&j.error)||'Erreur'); return; }
    m.style.color='#2d8a4e'; m.textContent='✓ Classé en GED. Actualisation…'; setTimeout(function(){location.reload();},800);
  }).catch(function(){ m.style.color='#c62828'; m.textContent='❌ Réseau'; });
}
</script>

<!-- Modal d'upload direct (GED pré-lié au bien) -->
<div id="biUpModal" style="display:none;position:fixed;inset:0;z-index:9500;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(460px,94vw);padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3 id="biUpTitle" style="margin:0;font-size:16px;">Charger un document</h3>
      <button type="button" onclick="document.getElementById('biUpModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:5px 10px;cursor:pointer;font-weight:700;">✕</button>
    </div>
    <div style="color:#6b7280;font-size:12.5px;margin-bottom:10px;">Le document sera classé en GED et lié automatiquement au <b>bien</b> (+ son immeuble et son propriétaire).</div>
    <input type="file" id="biUpFile" accept="application/pdf,image/*" style="font-size:13px;margin-bottom:12px;display:block;">
    <div id="biUpMsg" style="font-size:12.5px;font-weight:700;margin-bottom:10px;"></div>
    <button type="button" id="biUpBtn" onclick="biUploadSubmit()" style="background:#243B5C;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;">⬆ Charger et classer</button>
  </div>
</div>

<?php require_once __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
