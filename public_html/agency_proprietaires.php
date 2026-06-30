<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/tenant_scope.php';
require_once __DIR__ . '/inc/entity_card.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$appLayout = true;
$pageTitle = 'Propriétaires';
$bodyClass = '';
$robots    = 'noindex, nofollow';

$pdo   = $GLOBALS['pdo'];
$ctx   = tenant_current_context();
$isAdmin = $ctx['is_super_admin'];

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ── Filtres GET ───────────────────────────────────────── */
$filterSearch = trim((string)($_GET['q']      ?? ''));
$filterType   = trim((string)($_GET['type']   ?? ''));
$filterStatut = trim((string)($_GET['statut'] ?? 'actif'));
$page         = 1;            // plus de pagination : on déroule toutes les cards
$perPage      = 1000000;
$offset       = 0;

/* ── Résolution scope société / agence via helper ──────── */
$agencesVisibles = tenant_agences_visibles($pdo);
$validAgenceIds  = array_map(static fn($a) => (int)$a['id'], $agencesVisibles);
$scope           = tenant_resolve_filter($validAgenceIds);
$scopeSoc        = (int)$scope['id_societe'];
$scopeAg         = (int)$scope['id_agence'];

/* ── Construction requête ──────────────────────────────── */
$where  = ["tr.role_code = 'proprietaire'"];
$params = [];

// Cloisonnement société : société directe du tiers, OU société d'un de ses biens,
// OU tiers legacy sans société (id_societe NULL) — sinon des propriétaires réels
// (ex. GROUPE SIR : société NULL mais 89 biens) disparaissent dès qu'on filtre une société.
if ($scopeSoc > 0) {
    $where[]  = "(t.id_societe = ? OR t.id_societe IS NULL OR EXISTS (SELECT 1 FROM biens bs JOIN proprietaires ps ON ps.id = bs.id_proprietaire WHERE ps.id_tiers = t.id AND bs.id_societe = ?))";
    $params[] = $scopeSoc;
    $params[] = $scopeSoc;
}
// Filtre agence optionnel (via tiers OU proprietaires legacy)
if ($scopeAg > 0) {
    // Propriétaire « de l'agence » = agence directe (tiers/proprio) OU au moins un bien géré par cette agence.
    $where[]  = "(t.id_agence = ? OR p.id_agence = ? OR EXISTS (SELECT 1 FROM biens bag WHERE bag.id_proprietaire = p.id AND bag.id_agence = ?))";
    $params[] = $scopeAg;
    $params[] = $scopeAg;
    $params[] = $scopeAg;
}

if ($filterSearch !== '') {
    $q = '%' . $filterSearch . '%';
    // Recherche élargie : propriétaire + ville + bien (ville/rue/réf) + immeuble (nom/ville/rue) + locataire.
    // LIKE = insensible à la casse et aux accents (collation utf8mb4_*_ci).
    $where[] = "(t.nom LIKE ? OR t.prenom LIKE ? OR t.email LIKE ? OR t.telephone LIKE ? OR t.raison_sociale LIKE ? OR t.ville LIKE ?
        OR EXISTS (SELECT 1 FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
                   WHERE b.id_proprietaire=p.id AND (b.ville LIKE ? OR b.adresse_1 LIKE ? OR b.reference_bien LIKE ?
                        OR i.ville LIKE ? OR i.adresse_1 LIKE ? OR i.nom_immeuble LIKE ?))
        OR EXISTS (SELECT 1 FROM bien_baux bb JOIN biens b2 ON b2.id=bb.id_bien
                   WHERE b2.id_proprietaire=p.id AND (bb.locataire_nom LIKE ? OR bb.locataire_raison_sociale LIKE ?
                        OR CONCAT_WS(' ',bb.locataire_prenom,bb.locataire_nom) LIKE ?)))";
    array_push($params, $q,$q,$q,$q,$q,$q,  $q,$q,$q,$q,$q,$q,  $q,$q,$q);
}
if ($filterType !== '') {
    $where[]  = "t.type_tiers = ?";
    $params[] = $filterType === 'morale' ? 'personne_morale' : 'personne_physique';
}
if ($filterStatut === 'actif') {
    $where[] = "t.actif = 1";
    $where[] = "(p.parti_gestion = 0 OR p.parti_gestion IS NULL)"; // exclut les « perdus »
} elseif ($filterStatut === 'archive') {
    $where[] = "t.actif = 0";
} elseif ($filterStatut === 'perdu') {
    $where[] = "p.parti_gestion = 1";
}
// 'tous' → pas de filtre

$whereStr = implode(' AND ', $where);

$baseJoin = "
    FROM tiers t
    INNER JOIN tiers_roles tr ON tr.id_tiers = t.id
    LEFT JOIN proprietaires p ON p.id_tiers = t.id
    LEFT JOIN agences ag ON ag.id = COALESCE(t.id_agence, p.id_agence)
";

try {
    $stmtCount = $pdo->prepare("SELECT COUNT(DISTINCT t.id) {$baseJoin} WHERE {$whereStr}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $sql = "
        SELECT t.id AS id_tiers,
               p.id AS id_proprio_legacy,
               COALESCE(NULLIF(t.nom_affichage, ''),
                        NULLIF(t.raison_sociale, ''),
                        TRIM(CONCAT_WS(' ', t.prenom, t.nom))) AS label,
               t.civilite, t.nom, t.prenom, t.raison_sociale, t.email, t.telephone,
               t.code_postal, t.ville, t.actif, t.type_tiers,
               ag.nom_agence AS agence_nom,
               (SELECT COUNT(*) FROM biens b   WHERE b.id_proprietaire = p.id) AS nb_biens,
               (SELECT COUNT(*) FROM mandats m WHERE m.id_proprietaire = p.id) AS nb_mandats,
               (SELECT GROUP_CONCAT(DISTINCT tr.role_code ORDER BY tr.role_code SEPARATOR ',')
                  FROM tiers_roles tr WHERE tr.id_tiers = t.id AND tr.actif = 1) AS roles_actifs,
               (SELECT b2.reference_bien FROM biens b2
                  WHERE b2.id_proprietaire = p.id
                  ORDER BY b2.id DESC LIMIT 1) AS first_bien_ref,
               (SELECT b3.id FROM biens b3
                  WHERE b3.id_proprietaire = p.id
                  ORDER BY b3.id DESC LIMIT 1) AS first_bien_id,
               (SELECT TRIM(CONCAT_WS(', ', COALESCE(NULLIF(im.adresse_1,''), b4.adresse_1), COALESCE(NULLIF(im.ville,''), b4.ville)))
                  FROM biens b4 LEFT JOIN immeubles im ON im.id = b4.id_immeuble
                  WHERE b4.id_proprietaire = p.id
                  ORDER BY b4.id DESC LIMIT 1) AS first_bien_adresse,
               (SELECT gd.id FROM ged_documents gd JOIN ged_document_links gdl ON gdl.document_id=gd.id
                  AND gdl.entity_type='TIERS' AND gdl.entity_id=t.id
                 WHERE gd.status='active' AND LOWER(gd.document_type) LIKE '%mandat%' ORDER BY gd.id DESC LIMIT 1) AS mandat_doc_id
        {$baseJoin}
        WHERE {$whereStr}
        GROUP BY t.id
        ORDER BY t.actif DESC, t.nom ASC, t.prenom ASC, t.raison_sociale ASC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $proprietaires = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Propriétaires ORPHELINS (sans tiers) ──────────────────────────────
    // Certains propriétaires issus de l'import CRG n'ont pas d'id_tiers (ex.
    // « SARL GROUPE SIR (immo) » #21). Étant tiers-first, le module ne les voyait
    // pas → ils existaient dans le patrimoine mais pas ici. On les RÉ-AFFICHE
    // (lecture seule côté tiers), sans modifier les données.
    $oWhere  = ['p.id_tiers IS NULL', 'EXISTS (SELECT 1 FROM biens b WHERE b.id_proprietaire = p.id)'];
    $oParams = [];
    if ($scopeAg > 0)                 { $oWhere[] = '(p.id_agence = ? OR EXISTS (SELECT 1 FROM biens bag WHERE bag.id_proprietaire = p.id AND bag.id_agence = ?) OR p.id_agence IS NULL)'; $oParams[] = $scopeAg; $oParams[] = $scopeAg; }
    if ($filterSearch !== '')         { $oq = '%' . $filterSearch . '%';
                                        $oWhere[] = "(p.societe LIKE ? OR p.nom LIKE ? OR p.prenom LIKE ? OR p.email LIKE ? OR p.ville LIKE ?
        OR EXISTS (SELECT 1 FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
                   WHERE b.id_proprietaire=p.id AND (b.ville LIKE ? OR b.adresse_1 LIKE ? OR b.reference_bien LIKE ?
                        OR i.ville LIKE ? OR i.adresse_1 LIKE ? OR i.nom_immeuble LIKE ?))
        OR EXISTS (SELECT 1 FROM bien_baux bb JOIN biens b2 ON b2.id=bb.id_bien
                   WHERE b2.id_proprietaire=p.id AND (bb.locataire_nom LIKE ? OR bb.locataire_raison_sociale LIKE ?
                        OR CONCAT_WS(' ',bb.locataire_prenom,bb.locataire_nom) LIKE ?)))";
                                        array_push($oParams, $oq,$oq,$oq,$oq,$oq,  $oq,$oq,$oq,$oq,$oq,$oq,  $oq,$oq,$oq); }
    if ($filterType === 'morale')     { $oWhere[] = "p.type_personne = 'morale'"; }
    elseif ($filterType === 'physique'){ $oWhere[] = "(p.type_personne <> 'morale' OR p.type_personne IS NULL)"; }
    if ($filterStatut === 'actif')    { $oWhere[] = 'p.actif = 1'; $oWhere[] = '(p.parti_gestion = 0 OR p.parti_gestion IS NULL)'; }
    elseif ($filterStatut === 'archive'){ $oWhere[] = 'p.actif = 0'; }
    elseif ($filterStatut === 'perdu') { $oWhere[] = 'p.parti_gestion = 1'; }
    $oSql = "
        SELECT NULL AS id_tiers, p.id AS id_proprio_legacy,
               COALESCE(NULLIF(p.societe,''), TRIM(CONCAT_WS(' ', p.prenom, p.nom))) AS label,
               p.civilite, p.nom, p.prenom, p.societe AS raison_sociale, p.email, p.telephone,
               p.code_postal, p.ville, p.actif,
               CASE WHEN p.type_personne='morale' THEN 'personne_morale' ELSE 'personne_physique' END AS type_tiers,
               NULL AS agence_nom,
               (SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire = p.id) AS nb_biens,
               (SELECT COUNT(*) FROM mandats m WHERE m.id_proprietaire = p.id) AS nb_mandats,
               'proprietaire' AS roles_actifs,
               (SELECT b2.reference_bien FROM biens b2 WHERE b2.id_proprietaire = p.id ORDER BY b2.id DESC LIMIT 1) AS first_bien_ref,
               (SELECT b3.id FROM biens b3 WHERE b3.id_proprietaire = p.id ORDER BY b3.id DESC LIMIT 1) AS first_bien_id,
               (SELECT TRIM(CONCAT_WS(', ', COALESCE(NULLIF(im.adresse_1,''), b4.adresse_1), COALESCE(NULLIF(im.ville,''), b4.ville)))
                  FROM biens b4 LEFT JOIN immeubles im ON im.id = b4.id_immeuble
                  WHERE b4.id_proprietaire = p.id ORDER BY b4.id DESC LIMIT 1) AS first_bien_adresse,
               NULL AS mandat_doc_id,
               1 AS orphelin
        FROM proprietaires p
        WHERE " . implode(' AND ', $oWhere) . "
        ORDER BY p.societe ASC, p.nom ASC";
    $oStmt = $pdo->prepare($oSql);
    $oStmt->execute($oParams);
    $orphelins = $oStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($orphelins) {
        $proprietaires = array_merge($proprietaires, $orphelins);
        $total += count($orphelins);
    }
    // Tri alphabétique sur le NOM (pas le prénom) — sociétés triées sur la raison sociale.
    $alphaDir = entity_card_sort_dir();
    $sortKey = function(array $r): string {
        $nom = trim((string)($r['nom'] ?? ''));
        if ($nom === '') $nom = trim((string)($r['raison_sociale'] ?? '')) ?: trim((string)($r['label'] ?? ''));
        return mb_strtolower($nom . ' ' . (string)($r['prenom'] ?? ''), 'UTF-8');
    };
    usort($proprietaires, function($a, $b) use ($alphaDir, $sortKey) {
        $c = strcmp($sortKey($a), $sortKey($b));
        return $alphaDir === 'desc' ? -$c : $c;
    });
} catch (Throwable $ex) {
    error_log('[agency_proprietaires] ' . $ex->getMessage());
    $proprietaires = [];
    $total = 0;
    $totalPages = 1;
}

/* ── Dossiers CRÉANCIERS : tiers ayant au moins un dossier (badge d'alerte sur les cards) ── */
$creancierByTiers = [];
$tiersIdsListe = array_values(array_filter(array_map(fn($r) => (int)($r['id_tiers'] ?? 0), $proprietaires)));
if ($tiersIdsListe) {
    try {
        $in = implode(',', array_fill(0, count($tiersIdsListe), '?'));
        $stCre = $pdo->prepare("SELECT entity_id, COUNT(DISTINCT id_dossier) AS nb
                                FROM creancier_dossier_lien
                                WHERE entity_type = 'TIERS' AND entity_id IN ($in)
                                GROUP BY entity_id");
        $stCre->execute($tiersIdsListe);
        foreach ($stCre->fetchAll(PDO::FETCH_ASSOC) as $r) { $creancierByTiers[(int)$r['entity_id']] = (int)$r['nb']; }
    } catch (Throwable $e) { /* tables créanciers absentes → pas de badge */ }
}

/* ── KPIs (Propriétaires · Biens · Loués · Vacants) sur le périmètre affiché ── */
$kpiProprios = $total;
$kpiBiens = $kpiLoues = $kpiVacants = 0;
$proprioIds = array_values(array_filter(array_map(fn($r) => (int)($r['id_proprio_legacy'] ?? 0), $proprietaires)));
if ($proprioIds) {
    try {
        $in = implode(',', array_fill(0, count($proprioIds), '?'));
        $stB = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE id_proprietaire IN ($in)");
        $stB->execute($proprioIds); $kpiBiens = (int)$stB->fetchColumn();
        $stL = $pdo->prepare("SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire IN ($in)
                              AND EXISTS (SELECT 1 FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif')");
        $stL->execute($proprioIds); $kpiLoues = (int)$stL->fetchColumn();
        $kpiVacants = max(0, $kpiBiens - $kpiLoues);
    } catch (Throwable $e) { /* KPIs best-effort */ }
}

/* ── KPI « Perdus » (propriétaires partis de la gestion) — scope société/agence ── */
$kpiPerdus = 0;
try {
    $pw = ["tr.role_code='proprietaire'", "p.parti_gestion=1"];
    $pp = [];
    if ($scopeSoc > 0) { $pw[] = "t.id_societe = ?"; $pp[] = $scopeSoc; }
    if ($scopeAg > 0)  { $pw[] = "(t.id_agence = ? OR p.id_agence = ? OR EXISTS (SELECT 1 FROM biens bag WHERE bag.id_proprietaire = p.id AND bag.id_agence = ?))"; $pp[] = $scopeAg; $pp[] = $scopeAg; $pp[] = $scopeAg; }
    $stP = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tiers t
        INNER JOIN tiers_roles tr ON tr.id_tiers=t.id
        LEFT JOIN proprietaires p ON p.id_tiers=t.id
        WHERE " . implode(' AND ', $pw));
    $stP->execute($pp); $kpiPerdus = (int)$stP->fetchColumn();
} catch (Throwable $e) { /* best-effort */ }

/* ── Sélecteurs scopés : sociétés / agences QUI ONT des propriétaires ── */
$societesAvecProprio = [];
$agencesAvecProprio  = [];
try {
    $societesAvecProprio = $pdo->query(
        "SELECT DISTINCT s.id, s.nom FROM tiers t
           JOIN tiers_roles tr ON tr.id_tiers=t.id AND tr.role_code='proprietaire'
           JOIN societes s ON s.id=t.id_societe
          WHERE t.actif=1 ORDER BY s.nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $agSql = "SELECT DISTINCT ag.id, ag.nom_agence, ag.ville, ag.id_societe FROM tiers t
                JOIN tiers_roles tr ON tr.id_tiers=t.id AND tr.role_code='proprietaire'
                JOIN agences ag ON ag.id = COALESCE(t.id_agence, (SELECT p2.id_agence FROM proprietaires p2 WHERE p2.id_tiers=t.id LIMIT 1))
               WHERE t.actif=1";
    $agParams = [];
    if (!$isAdmin && $validAgenceIds) { $agSql .= " AND ag.id IN (" . implode(',', array_fill(0, count($validAgenceIds), '?')) . ")"; $agParams = $validAgenceIds; }
    $agSql .= " ORDER BY ag.nom_agence";
    $stAg = $pdo->prepare($agSql); $stAg->execute($agParams);
    $agencesAvecProprio = $stAg->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* selects best-effort */ }

/* ── Lots VACANTS par propriétaire (bien sans bail actif) → pour le toggle « Vacants » ── */
$vacantsByProprio = [];
if ($proprioIds) {
    try {
        $in = implode(',', array_fill(0, count($proprioIds), '?'));
        $stV = $pdo->prepare("SELECT b.id_proprietaire, b.id, b.reference_bien,
                                     TRIM(CONCAT_WS(', ', COALESCE(NULLIF(im.adresse_1,''), b.adresse_1), COALESCE(NULLIF(im.ville,''), b.ville))) AS adresse
                                FROM biens b LEFT JOIN immeubles im ON im.id = b.id_immeuble
                               WHERE b.id_proprietaire IN ($in)
                                 AND NOT EXISTS (SELECT 1 FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif')
                               ORDER BY b.reference_bien");
        $stV->execute($proprioIds);
        foreach ($stV->fetchAll(PDO::FETCH_ASSOC) as $r) { $vacantsByProprio[(int)$r['id_proprietaire']][] = $r; }
    } catch (Throwable $e) { /* best-effort */ }
}

include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  /* Fond de page dégradé diagonal (même que Transaction / FluxBox) : fait ressortir les cards */
  .mbi-main{
    background:
      linear-gradient(135deg,
        rgba(154,170,132,0.18) 0%,
        rgba(255,255,255,0)    35%,
        rgba(72,120,166,0.14)  60%,
        rgba(255,255,255,0)    85%,
        rgba(201,123,46,0.16)  100%
      ),
      #fafbfc;
    background-attachment:fixed;
  }
  /* KPIs */
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:var(--card,#fff); border-radius:14px; padding:10px 16px; min-width:96px;
    box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); text-align:center; }
  .pk-kpi-val { font-size:22px; font-weight:800; color:#243B5C; line-height:1.1; }
  .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; margin-top:3px; }
  /* Sélecteurs scope (société/agence) dans le bandeau */
  .pk-scope { display:flex; flex-direction:column; gap:8px; align-items:flex-start; }
  .pk-btnrow { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
  .pk-btnrow-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
    color:#8a8680; min-width:58px; }
  /* Boutons société/agence : même forme que les cartes KPI (blanc neumorphique), statiques (pas d'effet pressé) */
  .pk-sbtn { display:inline-flex; align-items:center; cursor:pointer; text-decoration:none;
    font-family:'Sora',sans-serif; font-size:11.5px; font-weight:700; color:#3a3830;
    background:var(--card,#fff); border-radius:14px; padding:7px 14px;
    box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); transition:color .12s, box-shadow .12s; }
  .pk-sbtn:hover { color:#243B5C; }
  .pk-sbtn.active { color:#fff; background:#243b5c; box-shadow:inset 2px 2px 6px rgba(0,0,0,.28); }
  .pk-sbtn.ag.active { background:#4878a6; }
  .b-blue { background:linear-gradient(180deg,#5a93c7,#4878a6); box-shadow:0 4px 0 #355a82,0 7px 14px rgba(0,0,0,.16); }
  /* Barre filtres sticky : 3 zones sur toute la largeur — recherche à gauche,
     lettres CENTRÉES sur la page, tri à droite. Pas de colonnes vides, pas de wrap des lettres. */
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

  /* ── Boutons 3D pastel (doux, comme la topbar) ──────────────── */
  .btn3d { cursor:pointer; border:1px solid transparent; font-family:'Sora',sans-serif; font-weight:800; font-size:12px;
    color:#3a3830; padding:9px 18px; border-radius:12px; letter-spacing:.02em; text-decoration:none;
    display:inline-flex; align-items:center; gap:6px; line-height:1;
    transition:transform .08s ease, box-shadow .08s ease, filter .12s; }
  .btn3d:hover { filter:brightness(1.03); }
  .btn3d:active, .btn3d.active { transform:translateY(2px) !important;
    box-shadow:0 1px 0 rgba(0,0,0,.06) !important; filter:saturate(1.15) brightness(.99); }
  .l-all  { background:#eef1f6; color:#4a5568; border-color:#dfe3ea; box-shadow:0 3px 0 #d6dae2,0 4px 8px rgba(0,0,0,.05); }
  .l-af   { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; box-shadow:0 3px 0 #cfe0fb,0 4px 8px rgba(0,0,0,.05); }
  .l-gl   { background:#e6f7f4; color:#0f766e; border-color:#b7e3dc; box-shadow:0 3px 0 #cdeae5,0 4px 8px rgba(0,0,0,.05); }
  .l-mp   { background:#ecfdf3; color:#15803d; border-color:#bbf7d0; box-shadow:0 3px 0 #cdefd9,0 4px 8px rgba(0,0,0,.05); }
  .l-qz   { background:#fff7ed; color:#b45309; border-color:#fed7aa; box-shadow:0 3px 0 #f6e2c6,0 4px 8px rgba(0,0,0,.05); }
  .b-navy { background:#eef2f8; color:#243b5c; border-color:#c7d2e0; box-shadow:0 3px 0 #d6deea,0 4px 8px rgba(0,0,0,.05); }
  .b-violet { background:#f5f0ff; color:#7c3aed; border-color:#ddd0fb; box-shadow:0 3px 0 #e6dcfb,0 4px 8px rgba(0,0,0,.05); }

  /* ── Sélecteurs Société / Agence en boutons 3D ──────────────── */
  .sel3d { appearance:none; -webkit-appearance:none; cursor:pointer; border:none; outline:none;
    font-family:'Sora',sans-serif; font-weight:800; font-size:12.5px; color:#fff;
    padding:10px 36px 10px 16px; border-radius:12px;
    transition:transform .08s ease, filter .12s; }
  .sel3d:hover { filter:brightness(1.07); }
  .sel3d:active { transform:translateY(2px); }
  .sel3d option { color:#1a1816; background:#fff; }
  .sel3d-navy { background:
      url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3'><path d='M6 9l6 6 6-6'/></svg>") no-repeat right 12px center,
      linear-gradient(180deg,#34527a,#243b5c); box-shadow:0 4px 0 #172538,0 7px 14px rgba(0,0,0,.16); }
  .sel3d-blue { background:
      url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3'><path d='M6 9l6 6 6-6'/></svg>") no-repeat right 12px center,
      linear-gradient(180deg,#5a93c7,#4878a6); box-shadow:0 4px 0 #355a82,0 7px 14px rgba(0,0,0,.16); }

  /* ── Bloc lots vacants (révélé par le toggle « Vacants ») ────── */
  .ec-vacants { margin-top:6px; padding-top:8px; border-top:1px dashed rgba(196,192,186,.6); display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
  .ec-vac-h { width:100%; font-size:11px; font-weight:700; color:#b45309; margin-bottom:2px; }
  .ec-vac-ref { font-family:'DM Mono',monospace; font-size:10.5px; font-weight:600; color:#b45309;
    background:#fff7ed; border:1px solid #fed7aa; border-radius:7px; padding:3px 8px; text-decoration:none; }
  .ec-vac-ref:hover { background:#ffedd5; }
  /* Picto Mandat (colonne badge) */
  .ap-badgecol { display:flex; flex-direction:column; gap:5px; align-items:flex-end; }
  .ap-pic { font-size:10px; font-weight:700; padding:3px 8px; border-radius:7px; border:1px solid #e2e6ec; white-space:nowrap; cursor:pointer; }
  .ap-pic.on  { background:#ecfdf3; color:#15803d; border-color:#bbf7d0; }
  .ap-pic.off { background:#f4f4f5; color:#b8b3ac; }
  .ap-pic:hover { filter:brightness(.97); }
  /* Styles spécifiques agency_proprietaires (table + modal) — le reste
     (topbar / page-head / bl-btn / bl-filters / bl-search / bl-select /
     bl-content / bl-pagination / bl-empty / bl-modal) vient de liste_layout.css
     donc strictement identique à bien_liste. */

  /* Table propriétaires */
  .ap-table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    background: var(--card); border-radius: var(--r-lg); overflow: hidden;
    box-shadow: var(--neu-out);
  }
  .ap-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
    color: var(--muted); padding: 12px 14px; text-align: left; background: var(--bg);
    border-bottom: 1px solid var(--stroke);
  }
  .ap-table td {
    padding: 12px 14px; font-size: 13px; border-bottom: 1px solid var(--stroke);
    vertical-align: middle; color: var(--ink);
  }
  .ap-table tr:last-child td { border-bottom: none; }
  .ap-table tr:hover td { background: rgba(54,87,125,0.03); }
  .ap-table tr.is-archived td { opacity: 0.55; background: rgba(138,134,128,0.04); }
  .ap-table a.ap-link { color: var(--accent); text-decoration: none; font-weight: 600; }
  .ap-table a.ap-link:hover { text-decoration: underline; }

  .ap-badge {
    display: inline-block; font-size: 10px; padding: 2px 8px;
    border-radius: 20px; font-weight: 600;
  }
  .ap-badge-physique { background: rgba(54,87,125,.12); color: var(--accent); }
  .ap-badge-morale   { background: rgba(122,144,96,.15); color: #4d6b3a; }
  .ap-badge-archived { background: rgba(138,134,128,.15); color: var(--muted); font-size: 10px; margin-left: 6px; }

  .ap-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; height: 20px; border-radius: 10px;
    font-size: 11px; font-weight: 700;
  }
  .ap-count-biens   { background: rgba(54,87,125,.12); color: var(--accent); }
  .ap-count-mandats { background: rgba(249,115,22,.12); color: #c05000; }
  .ap-count-zero    { background: rgba(138,134,128,.10); color: var(--muted); }

  .ap-actions { display: flex; gap: 4px; justify-content: flex-end; }
  .ap-action-btn {
    width: 30px; height: 30px; border-radius: 6px; border: none;
    background: var(--bg); cursor: pointer; color: var(--muted);
    display: flex; align-items: center; justify-content: center;
    text-decoration: none; font-size: 14px;
    transition: background .15s, color .15s;
  }
  .ap-action-btn:hover { background: var(--card); color: var(--ink); box-shadow: var(--neu-out); }
  .ap-action-btn.danger:hover { color: #dc2626; }

  /* Modal création propriétaire */
  .ap-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9999; align-items: center; justify-content: center; }
  .ap-modal-overlay.open { display: flex; }
  .ap-modal { background: var(--card); border-radius: 14px; padding: 24px; max-width: 600px; width: 92%; box-shadow: 0 12px 40px rgba(0,0,0,.15); }
  .ap-modal h3 { margin: 0 0 16px; font-size: 1.1rem; }
  .ap-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .ap-modal-field { display: flex; flex-direction: column; gap: 3px; }
  .ap-modal-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--muted); }
  .ap-modal-field input, .ap-modal-field select { padding: 8px 12px; border: 1px solid var(--stroke); border-radius: 8px; font-size: 13px; font-family: inherit; }
</style>

<div class="mbi-main">

  <!-- TOPBAR -->
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <span class="active" style="font-size:1.7rem;font-weight:800;">Propriétaires</span>
    </nav>
    <div class="topbar-spacer"></div>
    <!-- Actions de page (déplacées dans la topbar) -->
    <div style="display:flex;gap:8px;align-items:center;margin-right:10px;">
      <button type="button" class="bl-btn" onclick="document.getElementById('modal-new-proprio').classList.add('open')"
         style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:700;box-shadow:none;display:inline-flex;align-items:center;gap:6px;">➕ Nouveau</button>
      <a href="<?= htmlspecialchars(app_url('/agency_locataires.php')) ?>" class="bl-btn"
         style="background:#e6f3f5;color:#2d5f6b;border:1px solid #b3dce2;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         title="Voir la liste des locataires">🔑 Locataires</a>
      <a href="<?= htmlspecialchars(app_url('/admin/admin_proprietaires_doublons.php')) ?>" class="bl-btn"
         style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         title="Détecter et fusionner en masse les propriétaires en doublon">🔀 Doublons</a>
      <?php if ((int)($_SESSION['id_role'] ?? 0) === 1): ?>
      <a href="<?= htmlspecialchars(app_url('/admin/admin_proprietaires_suppression.php')) ?>" class="bl-btn"
         style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         title="Suppression de propriétaires (super-admin)">🗑 Nettoyage</a>
      <?php endif; ?>
    </div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <!-- PAGE HEAD : KPIs + sélecteurs société/agence (titre déjà dans la topbar) -->
  <div class="page-head" style="flex-wrap:wrap;gap:16px;align-items:center;">
    <!-- KPIs -->
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= number_format($kpiProprios, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Propriétaires</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val"><?= number_format($kpiBiens, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Biens</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#15803d;"><?= number_format($kpiLoues, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Loués</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#b45309;"><?= number_format($kpiVacants, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Vacants</div></div>
      <a href="?<?= e(http_build_query(array_merge($_GET, ['statut' => 'perdu']))) ?>" class="pk-kpi" style="text-decoration:none;<?= $filterStatut === 'perdu' ? 'outline:2px solid #dc2626;' : '' ?>" title="Voir les propriétaires partis de la gestion">
        <div class="pk-kpi-val" style="color:#dc2626;"><?= number_format($kpiPerdus, 0, ',', ' ') ?></div><div class="pk-kpi-lbl">Perdus</div></a>
    </div>

    <!-- Sélecteurs société / agence en BOUTONS 3D (agences en cascade de la société) -->
    <?php
      $scopeLink = function(array $ov) {
          $q = $_GET;
          foreach ($ov as $k => $v) { if ($v === null) unset($q[$k]); else $q[$k] = $v; }
          return '?' . http_build_query($q);
      };
      // Cascade : agences de la société sélectionnée (sinon toutes).
      $agShown = array_values(array_filter($agencesAvecProprio, fn($a) => $scopeSoc <= 0 || (int)$a['id_societe'] === $scopeSoc));
    ?>
    <div class="pk-scope">
      <?php if ($isAdmin && $societesAvecProprio): ?>
      <div class="pk-btnrow">
        <span class="pk-btnrow-lbl">Société</span>
        <a href="<?= e($scopeLink(['societe'=>null,'agence'=>null])) ?>" class="pk-sbtn <?= $scopeSoc<=0?'active':'' ?>">Toutes</a>
        <?php foreach ($societesAvecProprio as $s): ?>
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
    <?php if (empty($proprietaires)): ?>
      <div class="bl-empty">
        <div class="bl-empty-icon">👥</div>
        <h2>Aucun propriétaire trouvé</h2>
        <p><?= ($filterSearch || $filterType) ? 'Essaie d\'ajuster les filtres.' : 'Commence par créer un propriétaire.' ?></p>
        <button type="button" class="bl-btn bl-btn-primary" onclick="document.getElementById('modal-new-proprio').classList.add('open')">
          ➕ Nouveau propriétaire
        </button>
      </div>
    <?php else: ?>
      <?php $pickBien = !empty($_GET['pick_bien']); ?>
      <?php if ($pickBien): ?>
      <div class="message" style="background:#ede9fe;color:#5b21b6;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:600;">
        ➕ Création d'un bien / d'une annonce — choisissez d'abord le <strong>propriétaire</strong> (ou créez-le), puis le bien lui sera rattaché.
      </div>
      <?php endif; ?>
      <?php entity_card_assets(); ?>

      <!-- BARRE FILTRES STICKY (dans le même conteneur que la grille → colonnes alignées) -->
      <div class="pk-bar">
        <div class="pk-bar-search">
          <span class="search-icon">🔍</span>
          <input type="text" id="propSearch" placeholder="Rechercher un propriétaire…" oninput="propFilter()" autocomplete="off" autofocus>
        </div>
        <div class="pk-bar-center" id="letterFilter">
          <button type="button" class="btn3d l-all active" data-range=""    onclick="propSetRange(this)">Tous</button>
          <button type="button" class="btn3d l-af"        data-range="A-F" onclick="propSetRange(this)">A–F</button>
          <button type="button" class="btn3d l-gl"        data-range="G-L" onclick="propSetRange(this)">G–L</button>
          <button type="button" class="btn3d l-mp"        data-range="M-P" onclick="propSetRange(this)">M–P</button>
          <button type="button" class="btn3d l-qz"        data-range="Q-Z" onclick="propSetRange(this)">Q–Z</button>
        </div>
        <div class="pk-bar-right">
          <a href="?<?= e(http_build_query(array_merge($_GET, ['alpha' => entity_card_sort_dir() === 'desc' ? 'asc' : 'desc']))) ?>"
             class="btn3d b-navy" title="Inverser le tri alphabétique">🔤 <?= entity_card_sort_dir() === 'desc' ? '↓ Z→A' : '↑ A→Z' ?></a>
          <button type="button" id="vacToggle" class="btn3d b-violet" onclick="propToggleVac(this)"
                  title="N'afficher que les propriétaires ayant des lots vacants">🔑 Vacants</button>
          <?php if ($filterStatut === 'perdu'): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['statut' => 'actif']))) ?>" class="btn3d" style="background:#dc2626;color:#fff;" title="Revenir aux propriétaires actifs">← Actifs</a>
          <?php else: ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['statut' => 'perdu']))) ?>" class="btn3d" style="background:#fee2e2;color:#b91c1c;" title="Voir les propriétaires partis de la gestion">🚪 Perdus<?= $kpiPerdus ? ' (' . (int)$kpiPerdus . ')' : '' ?></a>
          <?php endif; ?>
        </div>
      </div>

      <div class="ec-grid">
        <?php foreach ($proprietaires as $p):
            $isArchived = (int)$p['actif'] === 0;
            $fullName   = (string)$p['label'];
            $isMorale   = $p['type_tiers'] === 'personne_morale';
            // Affichage « NOM Prénom » pour les particuliers (meilleur scan visuel) ; raison sociale sinon.
            $displayName = $fullName;
            if (!$isMorale) {
                $nomP = trim((string)($p['nom'] ?? '')); $prenomP = trim((string)($p['prenom'] ?? ''));
                if ($nomP !== '') $displayName = trim($nomP . ' ' . $prenomP);
            }
            $ficheUrl   = 'agency_proprietaire_fiche.php?id=' . (int)($p['id_proprio_legacy'] ?? 0);
            $isOrphelin = empty($p['id_tiers']);
            $url360     = $isOrphelin ? $ficheUrl : ('tiers_360.php?id=' . (int)$p['id_tiers']);
            $legacyId   = (int)($p['id_proprio_legacy'] ?? 0);
            $createUrl  = $legacyId > 0 ? 'bien_creation.php?id_proprietaire=' . $legacyId : '';
            // En mode « choisir un propriétaire » (création de bien) → la card crée le bien.
            $cardUrl    = (!empty($pickBien) && $createUrl) ? $createUrl : $url360;

            // Badge type
            // Picto Mandat (au-dessus du badge type) : présent → voir / absent → charger
            $tiersIdCard = (int)($p['id_tiers'] ?? 0);
            $mandatDoc   = (int)($p['mandat_doc_id'] ?? 0);
            $mandatPic = '';
            if ($mandatDoc > 0) {
                $mandatPic = '<span class="ap-pic on" title="Voir le mandat de gestion" onclick="apViewDoc(event,' . $mandatDoc . ',\'Mandat\')">📑 Mandat 👁</span>';
            } elseif ($tiersIdCard > 0) {
                $mandatPic = '<span class="ap-pic off" title="Charger le mandat de gestion" onclick="apUploadMandat(event,' . $tiersIdCard . ')">📑 Mandat ⬆</span>';
            }
            $badge = '<div class="ap-badgecol">' . $mandatPic
                   . '<span class="ap-badge ap-badge-' . ($isMorale ? 'morale' : 'physique') . '">'
                   . ($isMorale ? '🏢 Société' : '👤 Particulier') . '</span></div>';

            // Chips : bien(s) — adresse parlante, cliquable.
            //  • 1 bien  → adresse de l'immeuble, clic → bien_360 de ce bien.
            //  • N biens → « N biens », clic → proprio 360 (liste tous ses biens).
            $chips = [];
            $nb   = (int)$p['nb_biens'];
            $adr  = trim((string)($p['first_bien_adresse'] ?? ''));
            if ($nb === 1 && !empty($p['first_bien_id'])) {
                $lib = '🏠 1 bien' . ($adr !== '' ? ' · <span style="color:#4878a6;">' . e($adr) . '</span>' : '');
                $chips[] = '<a href="bien_360.php?id=' . (int)$p['first_bien_id'] . '" onclick="event.stopPropagation()"'
                         . ' style="text-decoration:none;color:inherit;" title="Ouvrir la fiche 360° du bien">' . $lib . '</a>';
            } elseif ($nb > 1) {
                $chips[] = '<a href="' . e($url360) . '" onclick="event.stopPropagation()"'
                         . ' style="text-decoration:none;color:inherit;" title="Voir les ' . $nb . ' biens du propriétaire">'
                         . '🏠 <strong>' . $nb . '</strong>&nbsp;biens</a>';
            } else {
                $chips[] = '🏠 <span style="color:#9a9690;">aucun bien</span>';
            }
            if ($isOrphelin)  $chips[] = '<span style="color:#92400e;">⚠️ sans tiers</span>';
            if ($isArchived)  $chips[] = '<span style="color:#7a766f;">📦 archivé</span>';

            // Alerte CRÉANCIERS : dossiers / saisies en cours → ouvre la liste filtrée
            $nbCre = $creancierByTiers[$tiersIdCard] ?? 0;
            if ($nbCre > 0) {
                $chips[] = '<a href="creancier_liste.php?tiers=' . $tiersIdCard . '" onclick="event.stopPropagation()"'
                         . ' style="text-decoration:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;'
                         . 'font-weight:700;border-radius:8px;padding:2px 8px;" title="Voir les dossiers créanciers / saisies">'
                         . '🚨 ' . $nbCre . ' créancier' . ($nbCre > 1 ? 's' : '') . '</a>';
            }

            // Représentant (sociétés) : contact nommé sous la raison sociale
            $rep = '';
            if ($isMorale) {
                $r = trim((string)($p['civilite'] ?? '') . ' ' . (string)($p['prenom'] ?? '') . ' ' . (string)($p['nom'] ?? ''));
                if ($r !== '' && strcasecmp($r, $fullName) !== 0) $rep = '👤 ' . e($r);
            }

            // Actions rapides : appeler (Teams) / écrire — uniquement si on a la donnée
            $actions = [];
            if (!empty($p['telephone'])) {
                $tel = preg_replace('/[^0-9+]/', '', (string)$p['telephone']);
                $actions[] = '<a class="ec-abtn" href="tel:' . e($tel) . '" title="Appeler ' . e($p['telephone']) . ' (Teams)">📞</a>';
            }
            if (!empty($p['email'])) {
                $actions[] = '<a class="ec-abtn" href="mailto:' . e($p['email']) . '" title="Écrire à ' . e($p['email']) . '">✉️</a>';
            }
            // Suppression (super-admin role_id=1 uniquement) : ouvre l'outil prérempli avec l'ID propriétaire.
            if ((int)($_SESSION['id_role'] ?? 0) === 1 && $legacyId > 0) {
                $actions[] = '<a class="ec-abtn" style="color:#b91c1c;" '
                           . 'href="admin/admin_proprietaires_suppression.php?mode=ids&ids=' . $legacyId . '" '
                           . 'title="Supprimer ce propriétaire (Proprio #' . $legacyId . ') — super-admin">🗑</a>';
            }

            // Lettre de classement = 1re lettre du NOM (sociétés : raison sociale), sans accent.
            $nomLetter = trim((string)($p['nom'] ?? '')) ?: trim((string)($p['raison_sociale'] ?? '')) ?: $fullName;
            $first = mb_strtoupper(mb_substr($nomLetter, 0, 1, 'UTF-8'), 'UTF-8');
            $first = strtr($first, ['À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U']);
            if (!preg_match('/[A-Z]/', $first)) $first = '#';

            // Lots vacants de ce propriétaire (réfs sélectionnables, masquées sauf en mode « Vacants »)
            $vac = $vacantsByProprio[(int)($p['id_proprio_legacy'] ?? 0)] ?? [];
            $extra = '';
            if ($vac) {
                $refs = '';
                foreach ($vac as $vb) {
                    $ad = trim((string)($vb['adresse'] ?? ''));
                    $refs .= '<a href="bien_360.php?id=' . (int)$vb['id'] . '" onclick="event.stopPropagation()" class="ec-vac-ref" '
                           . 'title="' . e($vb['reference_bien'] . ($ad !== '' ? ' — ' . $ad : '')) . '">'
                           . e($vb['reference_bien']) . '</a>';
                }
                $extra = '<div class="ec-vacants" style="display:none;"><div class="ec-vac-h">🔑 '
                       . count($vac) . ' lot' . (count($vac) > 1 ? 's' : '') . ' vacant' . (count($vac) > 1 ? 's' : '')
                       . ' <span style="font-weight:400;color:#9a9690;">(cliquer pour ouvrir)</span></div>' . $refs . '</div>';
            }

            entity_card([
                'accent'    => '#c97b2e',
                'url'       => $cardUrl,
                'ref'       => trim(
                                   ($tiersIdCard > 0 ? 'Tiers #' . $tiersIdCard : '')
                                   . ($legacyId > 0 ? ($tiersIdCard > 0 ? ' · ' : '') . '🆔 Proprio #' . $legacyId : '')
                               ),
                'title'     => $displayName,
                'badge'     => $badge,
                'chips'     => $chips,
                'foot_left' => $rep,
                'actions'   => $actions,
                'extra'     => $extra,
                'data'      => ['name' => mb_strtolower($displayName, 'UTF-8'), 'letter' => $first, 'vacant' => count($vac)],
            ]);
        endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="bl-pagination">
        <?php
          $base = array_filter([
            'q'       => $filterSearch,
            'type'    => $filterType,
            'statut'  => $filterStatut !== 'actif' ? $filterStatut : '',
            'societe' => $scopeSoc > 0 && $isAdmin ? $scopeSoc : '',
            'agence'  => $scopeAg  > 0 ? $scopeAg  : '',
          ], fn($v) => $v !== '');
          for ($i = 1; $i <= $totalPages; $i++):
            $qs = http_build_query($base + ['page' => $i]);
        ?>
          <?php if ($i === $page): ?>
            <span class="bl-page-btn current"><?= $i ?></span>
          <?php else: ?>
            <a href="?<?= e($qs) ?>" class="bl-page-btn"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- MODAL NOUVEAU PROPRIÉTAIRE -->
<div class="ap-modal-overlay" id="modal-new-proprio" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ap-modal">
    <h3>➕ Nouveau propriétaire</h3>
    <form id="form-new-proprio">
      <div class="ap-modal-grid">
        <div class="ap-modal-field">
          <label>Type</label>
          <select name="type_personne" id="np-type">
            <option value="physique">👤 Particulier</option>
            <option value="morale">🏢 Société</option>
          </select>
        </div>
        <div class="ap-modal-field">
          <label>Civilité</label>
          <select name="civilite">
            <option value="">—</option><option value="M.">M.</option><option value="Mme">Mme</option>
          </select>
        </div>
        <div class="ap-modal-field">
          <label>Nom *</label>
          <input type="text" name="nom" required>
        </div>
        <div class="ap-modal-field">
          <label>Prénom</label>
          <input type="text" name="prenom">
        </div>
        <div class="ap-modal-field">
          <label>Société / SCI</label>
          <input type="text" name="societe">
        </div>
        <div class="ap-modal-field">
          <label>Email</label>
          <input type="email" name="email">
        </div>
        <div class="ap-modal-field">
          <label>Téléphone</label>
          <input type="tel" name="telephone">
        </div>
        <div class="ap-modal-field">
          <label>Code postal</label>
          <input type="text" name="code_postal">
        </div>
        <div class="ap-modal-field" style="grid-column: 1 / -1;">
          <label>Adresse</label>
          <input type="text" name="adresse_1">
        </div>
        <div class="ap-modal-field" style="grid-column: 1 / -1;">
          <label>Ville</label>
          <input type="text" name="ville">
        </div>
      </div>
      <div id="np-doublon" style="display:none;margin:12px 0;padding:10px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="bl-btn bl-btn-ghost" onclick="document.getElementById('modal-new-proprio').classList.remove('open')">Annuler</button>
        <button type="submit" class="bl-btn bl-btn-primary" id="np-submit">Créer</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Modal création propriétaire (inchangé du flow existant) ──
document.getElementById('form-new-proprio').addEventListener('submit', async function(e) {
  e.preventDefault();
  const form = this;
  const btn = document.getElementById('np-submit');
  const doublonEl = document.getElementById('np-doublon');
  btn.disabled = true; btn.textContent = 'Création…';
  doublonEl.style.display = 'none';

  const data = {};
  new FormData(form).forEach((v, k) => { data[k] = v; });
  data.csrf_token = '<?= csrf_token("ajouter_bien") ?>';

  try {
    const resp = await fetch('api/proprietaire_creer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const r = await resp.json();
    if (!r.ok) {
      doublonEl.textContent = r.error || 'Erreur';
      doublonEl.style.display = 'block';
    } else if (r.existant) {
      doublonEl.innerHTML = '⚠️ Un propriétaire similaire existe déjà : <strong>' + r.nom + '</strong>. <a href="agency_proprietaire_fiche.php?id=' + r.id + '">Voir la fiche</a>';
      doublonEl.style.display = 'block';
    } else {
      window.location.href = 'agency_proprietaire_fiche.php?id=' + r.id;
    }
  } catch (err) {
    doublonEl.textContent = 'Erreur réseau : ' + err.message;
    doublonEl.style.display = 'block';
  } finally {
    btn.disabled = false; btn.textContent = 'Créer';
  }
});

// Ouverture auto modal via ?new=1
if (new URLSearchParams(window.location.search).get('new') === '1') {
  document.getElementById('modal-new-proprio').classList.add('open');
}

// ── Actions archiver / restaurer / supprimer ──
document.querySelectorAll('.ap-action-btn[data-action]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const action = btn.dataset.action;
    const row = btn.closest('tr');
    const idTiers = parseInt(row?.dataset.idTiers, 10) || 0;
    if (!idTiers) return;

    if (action === 'delete') {
      if (!confirm('⚠️ SUPPRESSION DÉFINITIVE\n\nCette action retirera le propriétaire de la base de données. Impossible si des biens / mandats y sont liés.\n\nConfirmer ?')) return;
      btn.disabled = true; btn.textContent = '⏳';
      try {
        const r = await fetch('api/tiers_delete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ id_tiers: idTiers, confirm: 'DELETE' }),
        });
        const j = await r.json();
        if (!j.ok) { alert('❌ ' + (j.error || 'Erreur')); btn.disabled = false; btn.textContent = '🗑️'; return; }
        row.style.transition = 'opacity .3s'; row.style.opacity = '0';
        setTimeout(() => row.remove(), 300);
      } catch (e) { alert('❌ ' + e.message); btn.disabled = false; btn.textContent = '🗑️'; }
      return;
    }

    // archive / restore
    const label = action === 'archive' ? 'Archiver ce propriétaire ?' : 'Restaurer ce propriétaire ?';
    if (!confirm(label)) return;
    btn.disabled = true; btn.textContent = '⏳';
    try {
      const r = await fetch('api/tiers_archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ id_tiers: idTiers, action }),
      });
      const j = await r.json();
      if (!j.ok) { alert('❌ ' + (j.error || 'Erreur')); btn.disabled = false; return; }
      window.location.reload();
    } catch (e) { alert('❌ ' + e.message); btn.disabled = false; }
  });

  // Copier l'ID tiers au clic (utile pour la page Suppression propriétaires)
  document.querySelectorAll('.ap-id-copy').forEach(el => {
    el.addEventListener('click', async (ev) => {
      ev.preventDefault();
      const id = el.dataset.copy;
      try {
        await navigator.clipboard.writeText(id);
        const orig = el.textContent;
        el.textContent = '✓ copié #' + id;
        el.style.background = '#d9f0db';
        el.style.color = '#2d6a35';
        setTimeout(() => {
          el.textContent = orig;
          el.style.background = '#ede9fe';
          el.style.color = '#5b21b6';
        }, 1200);
      } catch (e) { /* clipboard non dispo : ignorer */ }
    });
  });
});
</script>

<script>
// Tri générique des colonnes du tableau propriétaires (croissant / décroissant).
function apSort(th, col){
  var table = th.closest('table'), tb = table ? table.querySelector('tbody') : null;
  if(!tb) return;
  var dir = th.getAttribute('data-dir')==='asc' ? 'desc' : 'asc';
  table.querySelectorAll('th.ap-sort').forEach(function(h){ h.setAttribute('data-dir',''); var s=h.querySelector('.ap-si'); if(s) s.textContent='⇅'; });
  th.setAttribute('data-dir', dir);
  var si=th.querySelector('.ap-si'); if(si) si.textContent = dir==='asc' ? '▲' : '▼';
  function val(tr){
    var c=tr.children[col]; if(!c) return '';
    var t=(c.innerText||'').replace(/\s+/g,' ').trim();
    if(/^[\s\d.,€%+-]+$/.test(t) && t!==''){ var n=parseFloat(t.replace(/[^0-9.,-]/g,'').replace(',','.')); if(!isNaN(n)) return n; }
    return t.toLowerCase();
  }
  Array.prototype.slice.call(tb.querySelectorAll('tr')).sort(function(a,b){
    var va=val(a), vb=val(b);
    if(typeof va==='number' && typeof vb==='number') return dir==='asc'? va-vb : vb-va;
    return dir==='asc'? String(va).localeCompare(String(vb),'fr') : String(vb).localeCompare(String(va),'fr');
  }).forEach(function(r){ tb.appendChild(r); });
}
</script>

<script>
// ── Filtrage live des cards propriétaires : recherche (nom) + plage de lettres ──
var propRange = '';     // '', 'A-F', 'G-L', 'M-P', 'Q-Z'
var propVacOnly = false; // toggle « Vacants »
function propSetRange(btn){
  propRange = btn.getAttribute('data-range') || '';
  document.querySelectorAll('#letterFilter .btn3d').forEach(function(b){ b.classList.toggle('active', b===btn); });
  propFilter();
}
// Focus auto sur la recherche à l'arrivée (curseur prêt à taper un nom).
document.addEventListener('DOMContentLoaded', function(){ var s=document.getElementById('propSearch'); if(s){ s.focus(); } });
function propToggleVac(btn){
  propVacOnly = !propVacOnly;
  btn.classList.toggle('active', propVacOnly);
  propFilter();
}
function propInRange(letter){
  if(!propRange) return true;
  var p = propRange.split('-');           // ex. ['A','F']
  return letter >= p[0] && letter <= p[1]; // '#' (non alpha) → hors plage, visible seulement en "Tous"
}
function propFilter(){
  var q = (document.getElementById('propSearch').value || '').toLowerCase().trim();
  document.querySelectorAll('.ec-grid .ec-card').forEach(function(c){
    var name = c.getAttribute('data-name') || '';
    var letter = (c.getAttribute('data-letter') || '#');
    var hasVac = parseInt(c.getAttribute('data-vacant') || '0', 10) > 0;
    var ok = (q==='' || name.indexOf(q) !== -1) && propInRange(letter) && (!propVacOnly || hasVac);
    c.style.display = ok ? '' : 'none';
    var vbox = c.querySelector('.ec-vacants');           // révèle les réfs vacantes en mode Vacants
    if(vbox) vbox.style.display = (ok && propVacOnly) ? 'flex' : 'none';
  });
}
</script>

<script>
// ── Picto Mandat : voir le doc GED / charger un mandat (lié au propriétaire) ──
var AP_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token('proprio_doc') : '') ?>;
var apCtx = {tiers:0};
function apViewDoc(ev, docId, label){ ev.stopPropagation(); if(window.mvptModalView){ window.mvptModalView(docId, label); } else { window.location='bien_doc_360.php?doc_id='+docId; } }
function apUploadMandat(ev, tiersId){
  ev.stopPropagation(); apCtx={tiers:tiersId};
  document.getElementById('apUpMsg').textContent=''; document.getElementById('apUpFile').value='';
  document.getElementById('apUpModal').style.display='flex';
}
function apUploadSubmit(){
  var f=document.getElementById('apUpFile').files[0], m=document.getElementById('apUpMsg');
  if(!f){ m.style.color='#c62828'; m.textContent='Sélectionne un fichier.'; return; }
  m.style.color='#6b7280'; m.textContent='⏳ Chargement + classement GED…';
  var fd=new FormData(); fd.append('id_tiers',apCtx.tiers); fd.append('doc_type','MANDAT_GESTION');
  fd.append('csrf_token',AP_CSRF); fd.append('CSRF',AP_CSRF); fd.append('fichier',f);
  fetch('api/proprio_doc_upload.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(!j||!j.ok){ m.style.color='#c62828'; m.textContent='❌ '+((j&&j.error)||'Erreur'); return; }
    m.style.color='#2d8a4e'; m.textContent='✓ Mandat classé en GED. Actualisation…'; setTimeout(function(){location.reload();},800);
  }).catch(function(){ m.style.color='#c62828'; m.textContent='❌ Réseau'; });
}
</script>
<div id="apUpModal" style="display:none;position:fixed;inset:0;z-index:9500;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(460px,94vw);padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3 style="margin:0;font-size:16px;">📑 Charger le mandat de gestion</h3>
      <button type="button" onclick="document.getElementById('apUpModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:5px 10px;cursor:pointer;font-weight:700;">✕</button>
    </div>
    <div style="color:#6b7280;font-size:12.5px;margin-bottom:10px;">Classé en GED et lié automatiquement au <b>propriétaire</b>.</div>
    <input type="file" id="apUpFile" accept="application/pdf,image/*" style="font-size:13px;margin-bottom:12px;display:block;">
    <div id="apUpMsg" style="font-size:12.5px;font-weight:700;margin-bottom:10px;"></div>
    <button type="button" onclick="apUploadSubmit()" style="background:#243B5C;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;">⬆ Charger et classer</button>
  </div>
</div>
<?php require_once __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>

<?php /* Bloc « Nettoyage admin proprietaires » deplace 2026-05-23 vers admin/admin_proprietaires_suppression.php (accessible via super_admin_dashboard.php + bouton header). */ ?>

<?php include __DIR__ . '/inc/footer.php'; ?>
