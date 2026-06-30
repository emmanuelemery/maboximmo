<?php
// transaction_index.php — Module Transaction V0 (2026-05-18)
// ─────────────────────────────────────────────────────────────────
// "Excel intelligent" : tableau dense des biens en commercialisation
// Réutilise : biens + annonces + leads_annonces + ged_documents + tiers
// Vue SQL   : vw_transactions (créée par migration_transaction_v0_2026-05-18.sql)
// Filtres   : GET simples (V0), pas d'AJAX.
// ─────────────────────────────────────────────────────────────────
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

// ── Scope multi-tenant (bypass role=1) ─────────────────────────────
$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSocieteSession = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAgenceSession  = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;

// ── Lecture filtres GET ────────────────────────────────────────────
$q                  = trim((string)($_GET['q'] ?? ''));
$fType              = 'vente';                                  // page Transactions = VENTES uniquement
$fUsage             = (string)($_GET['usage'] ?? 'all');        // habitation|professionnel|all
$fPrix              = (string)($_GET['prix'] ?? 'all');         // small|500k|1M|all
$fStatut            = (string)($_GET['statut'] ?? 'all');       // a_preparer|pret|commercialise|offre_recue|vendu|all
$fProprietaire      = isset($_GET['proprietaire_id']) ? (int)$_GET['proprietaire_id'] : 0;
$fPriorite          = (string)($_GET['priorite'] ?? 'all');     // haute|normale|differee|all
$fSociete           = isset($_GET['societe_id']) ? (int)$_GET['societe_id'] : 0;
$fAgence            = isset($_GET['agence_id'])  ? (int)$_GET['agence_id']  : 0;
$fArchives          = (string)($_GET['archives'] ?? '');       // '1' = afficher uniquement les archivés (vendus/retirés)

// ── Construction requête SUR la vue ────────────────────────────────
// Scope élargi pour Transaction : super admin + manager voient TOUTES les sociétés/agences.
// Les autres rôles restent scopés sur leur société.
$where = [];
$params = [];
$isManager = ($roleId === 1 || $roleId === 2);

if (!$isManager && $idSocieteSession !== null) {
    $where[] = '(v.id_societe = :id_societe OR v.id_societe IS NULL)';
    $params[':id_societe'] = $idSocieteSession;
    if ($idAgenceSession !== null) {
        $where[] = '(v.id_agence = :id_agence OR v.id_agence IS NULL)';
        $params[':id_agence'] = $idAgenceSession;
    }
}

// Filtres explicites société + agence (utilisables par tous, mais surtout pour managers)
if ($fSociete > 0) {
    $where[] = 'v.id_societe = :f_societe';
    $params[':f_societe'] = $fSociete;
}
if ($fAgence > 0) {
    $where[] = 'v.id_agence = :f_agence';
    $params[':f_agence'] = $fAgence;
}

if ($q !== '') {
    // Placeholders uniques par occurrence (EMULATE_PREPARES=false interdit la répétition)
    $where[] = '(v.reference_bien LIKE :q1 OR v.designation LIKE :q2 OR v.ville LIKE :q3 OR v.adresse_1 LIKE :q4 OR v.code_postal LIKE :q5'
             . ' OR EXISTS (SELECT 1 FROM proprietaires p WHERE p.id = v.id_proprietaire'
             . '   AND (p.nom LIKE :q6 OR p.prenom LIKE :q7 OR p.societe LIKE :q8'
             . '        OR CONCAT_WS(" ", p.prenom, p.nom) LIKE :q9)))';
    $like = '%' . $q . '%';
    $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
    $params[':q4'] = $like; $params[':q5'] = $like; $params[':q6'] = $like;
    $params[':q7'] = $like; $params[':q8'] = $like; $params[':q9'] = $like;
}

// RÈGLE MÉTIER : Transactions = VENTE obligatoirement (peu importe l'annonce).
// On filtre strictement sur le bien (type_commercialisation = 'vente'), avec ou sans annonce.
$where[] = "v.type_commercialisation = 'vente'";

if ($fUsage === 'habitation') {
    $where[] = "(v.usage_bien IN ('habitation','residentiel','principal','résidence principale','résidence secondaire') OR v.usage_bien IS NULL)";
} elseif ($fUsage === 'professionnel') {
    $where[] = "v.usage_bien IN ('professionnel','commercial','bureau','local','mixte','professionnel/commercial')";
}

if ($fPrix === 'small') {
    $where[] = '(v.prix_demande_initial < 500000 OR (v.prix_demande_initial IS NULL AND v.annonce_prix < 500000))';
} elseif ($fPrix === '500k') {
    $where[] = '(v.prix_demande_initial >= 500000 OR v.annonce_prix >= 500000)';
} elseif ($fPrix === '1M') {
    $where[] = '(v.prix_demande_initial >= 1000000 OR v.annonce_prix >= 1000000)';
}

if (in_array($fStatut, ['a_preparer','pret','commercialise','offre_recue','vendu'], true)) {
    // COLLATE explicite : la colonne de la vue (utf8mb4_unicode_ci) et le paramètre lié
    // (utf8mb4_general_ci selon la connexion) ont des collations différentes → l'égalité
    // déclenche « Illegal mix of collations ». On force une collation commune.
    $where[] = 'v.statut_transaction COLLATE utf8mb4_unicode_ci = :fstatut';
    $params[':fstatut'] = $fStatut;
}

// ── Archivés (vendus / retirés de la vente) ────────────────────────
// Par défaut on NE LES AFFICHE PAS. Le filtre « Archivés » permet de ne voir QU'eux.
// (un statut explicite choisi ci-dessus a priorité et n'est pas re-filtré.)
if ($fArchives === '1') {
    $where[] = "v.statut_transaction COLLATE utf8mb4_unicode_ci = 'vendu'";
} elseif ($fStatut === 'all') {
    $where[] = "v.statut_transaction COLLATE utf8mb4_unicode_ci <> 'vendu'";
}

if ($fProprietaire > 0) {
    $where[] = 'v.id_proprietaire = :fproprio';
    $params[':fproprio'] = $fProprietaire;
}

// ── Scope PORTEFEUILLE BAILLEUR (RGPD) ─────────────────────────────
// Accès « Biens en vente » du dashboard bailleur : ?scope=bailleur
// → on ne montre QUE les biens des propriétaires de l'utilisateur.
//   super admin  : respecte la sélection du dashboard (session bailleur_props)
//   bailleur      : ses user_proprietaires uniquement (toujours, même sans param)
$wantBailleurScope = (($_GET['scope'] ?? '') === 'bailleur');
$asBailleur = isset($_GET['bailleur']) ? (int)$_GET['bailleur'] : 0;   // "voir en tant que" depuis le hub
$hasBailleur = function_exists('hasServiceAccess') && hasServiceAccess($roleId, 'bailleur');
if ($wantBailleurScope || $asBailleur > 0 || ($hasBailleur && !$isSuperAdmin)) {
    if ($isSuperAdmin) {
        if ($asBailleur > 0) {
            // Périmètre = propriétaires assignés au bailleur "vu en tant que".
            $stB = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
            $stB->execute([$asBailleur]);
            $propScope = array_map('intval', $stB->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $propScope = array_map('intval', (array)($_SESSION['bailleur_props'] ?? []));
        }
        if (empty($propScope)) { $where[] = '0=1'; }   // bailleur vu sans propriétaire → rien (au lieu de tout)
    } else {
        $uid = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
        $stP = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
        $stP->execute([$uid]);
        $propScope = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!empty($propScope)) {
        $where[] = 'v.id_proprietaire IN (' . implode(',', $propScope) . ')';
    } elseif (!$isSuperAdmin) {
        $where[] = '0=1';   // bailleur sans propriétaire → rien
    }
}

// fCommercialisateur : V0 = pas de filtrage tant que tiers_roles.commercialisateur non câblé.
// On laisse le filtre dans l'UI pour V0.1.

$sql = 'SELECT v.* FROM vw_transactions v';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY COALESCE(v.bien_date_modification, v.bien_date_creation) DESC LIMIT 500';

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[transaction_index] ' . $e->getMessage());
    $rows = [];
    $sqlError = $e->getMessage();
}

// ── KPIs topbar (sur les lignes filtrées) ──────────────────────────
$kpiTotal       = count($rows);
$kpiPret        = 0;
$kpiCommercial  = 0;
$kpiOffres      = 0;
$kpiVendus      = 0;
$caPotentiel    = 0.0;
foreach ($rows as $r) {
    switch ($r['statut_transaction']) {
        case 'pret':           $kpiPret++; break;
        case 'commercialise':  $kpiCommercial++; break;
        case 'offre_recue':    $kpiOffres++; break;
        case 'vendu':          $kpiVendus++; break;
    }
    $caPotentiel += (float)($r['prix_demande_initial'] ?? $r['annonce_prix'] ?? 0);
}

// ── Helper label propriétaire (physique ou société) ─────────────
$proprio_label = function (array $p): string {
    $nom    = trim((string)($p['nom']    ?? ''));
    $prenom = trim((string)($p['prenom'] ?? ''));
    $soc    = trim((string)($p['societe']?? ''));
    $human  = trim($prenom . ' ' . $nom);
    if ($human !== '') return $human;
    if ($soc !== '')   return $soc;
    return '#' . (int)($p['id'] ?? 0);
};

// ── Liste propriétaires pour le filtre (ceux ayant déjà un bien commercialisé) ──
try {
    $sqlProp = 'SELECT DISTINCT p.id, p.nom, p.prenom, p.societe
                FROM proprietaires p
                INNER JOIN biens b ON b.id_proprietaire = p.id
                WHERE b.type_commercialisation IS NOT NULL AND b.type_commercialisation <> ""';
    $paramsProp = [];
    if (!$isSuperAdmin && $idSocieteSession !== null) {
        $sqlProp .= ' AND (b.id_societe = :s OR b.id_societe IS NULL)';
        $paramsProp[':s'] = $idSocieteSession;
    }
    $sqlProp .= ' ORDER BY COALESCE(NULLIF(p.nom, ""), p.societe) LIMIT 500';
    $stP = $pdo->prepare($sqlProp);
    foreach ($paramsProp as $k=>$v) $stP->bindValue($k,$v,PDO::PARAM_INT);
    $stP->execute();
    $proprietaires = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $proprietaires = [];
}

// ── Listes sociétés & agences pour les filtres ─────────────────────
try {
    // Uniquement les sociétés réellement présentes dans la liste des ventes (vue transactions).
    $stmtS = $pdo->query('SELECT s.id, s.nom
                            FROM societes s
                            JOIN vw_transactions v ON v.id_societe = s.id
                           WHERE (v.type_commercialisation = "vente" OR v.type_transaction LIKE "vente%")
                           GROUP BY s.id, s.nom
                           ORDER BY s.nom');
    $societes = $stmtS->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[transaction_index societes] ' . $e->getMessage());
    $societes = [];
}
try {
    // Agences réellement présentes dans les ventes (vue transactions), filtrées sur la
    // société du BIEN (v.id_societe) — fiable même si agences.id_societe est NULL.
    $sqlA = 'SELECT DISTINCT a.id,
                    COALESCE(NULLIF(a.nom_agence, ""), CONCAT("Agence #", a.id)) AS nom,
                    a.ville, a.id_societe
             FROM agences a
             JOIN vw_transactions v ON v.id_agence = a.id
             WHERE v.type_commercialisation = "vente"';
    if ($fSociete > 0) $sqlA .= ' AND v.id_societe = ' . $fSociete;
    $sqlA .= ' ORDER BY 2';
    $agences = $pdo->query($sqlA)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[transaction_index agences] ' . $e->getMessage());
    $agences = [];
}

// ── Liste propriétaires pour modale "Ajouter un bien" (ceux ayant des biens NON encore commercialisés) ──
try {
    $sqlPropAll = 'SELECT p.id, p.nom, p.prenom, p.societe,
                          SUM(CASE WHEN b.type_commercialisation IS NULL OR b.type_commercialisation = ""
                                   THEN 1 ELSE 0 END) AS nb_biens_dispo,
                          COUNT(b.id) AS nb_biens
                   FROM proprietaires p
                   INNER JOIN biens b ON b.id_proprietaire = p.id
                   WHERE (b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))';
    $paramsPropAll = [];
    if (!$isSuperAdmin && $idSocieteSession !== null) {
        $sqlPropAll .= ' AND (b.id_societe = :s OR b.id_societe IS NULL)';
        $paramsPropAll[':s'] = $idSocieteSession;
    }
    $sqlPropAll .= ' GROUP BY p.id, p.nom, p.prenom, p.societe
                     HAVING nb_biens_dispo > 0
                     ORDER BY COALESCE(NULLIF(p.nom, ""), p.societe) LIMIT 1000';
    $stPA = $pdo->prepare($sqlPropAll);
    foreach ($paramsPropAll as $k=>$v) $stPA->bindValue($k,$v,PDO::PARAM_INT);
    $stPA->execute();
    $proprietairesAll = $stPA->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $proprietairesAll = [];
}

$pageTitle    = 'Transactions';
$pageSubtitle = 'Ma Box Agency · Commercialisation';
$bodyAttr     = 'data-theme-module="transaction"';

$extraCss = <<<'CSS'
<style>
/* Fond de page dégradé diagonal (même que FluxBox) : fait ressortir les cards */
.agency-content{
  min-height:100vh;
  background:
    linear-gradient(135deg,
      rgba(154, 170, 132, 0.18) 0%,
      rgba(255, 255, 255, 0)   35%,
      rgba(72, 120, 166, 0.14) 60%,
      rgba(255, 255, 255, 0)   85%,
      rgba(201, 123, 46, 0.16) 100%
    ),
    #fafbfc !important;
  background-attachment:fixed !important;
}
/* ── Module Transaction V0 ──────────────────────────────────── */
.tr-kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:18px; }
.tr-kpi {
    background:#fff; border-radius:12px; padding:14px 16px;
    box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff;
    display:flex; flex-direction:column; gap:4px;
}
.tr-kpi-label { font-size:11px; color:#9a9690; text-transform:uppercase; letter-spacing:.04em; font-family:'DM Mono',monospace; }
.tr-kpi-value { font-size:22px; font-weight:800; color:#2c2a28; line-height:1; }
.tr-kpi-sub { font-size:11px; color:#7a766f; }

.tr-filters {
    background:#fff; border-radius:12px; padding:14px;
    box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff;
    margin-bottom:18px;
    display:flex; flex-wrap:wrap; gap:10px; align-items:center;
}
.tr-filters input[type=text], .tr-filters select {
    border:1px solid #e3dfd8; border-radius:8px; padding:7px 10px; font-size:13px;
    background:#fafafa; min-width:140px; font-family:inherit;
}
.tr-filters input[type=text] { min-width:240px; }
.tr-filters .tr-actions-right { margin-left:auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
/* Bouton CIRCUIT COMPLET — mis en avant (dégradé + halo animé) */
.tr-btn-circuit{
  display:flex; flex-direction:column; align-items:flex-start; line-height:1.15;
  text-decoration:none; color:#fff; font-weight:800; font-size:14px;
  padding:9px 18px; border-radius:12px;
  background:linear-gradient(135deg,#0f9d58,#0b8043 55%,#0f6cbd);
  box-shadow:0 6px 18px rgba(11,128,67,.35); transition:transform .12s, box-shadow .12s;
}
.tr-btn-circuit:hover{ transform:translateY(-1px); box-shadow:0 10px 26px rgba(11,128,67,.45); }
.tr-btn-circuit .tr-circuit-sub{ font-size:10px; font-weight:600; opacity:.9; letter-spacing:.02em; }
@media(max-width:640px){ .tr-btn-circuit{ width:100%; align-items:center; } }
.tr-btn {
    border:none; border-radius:8px; padding:8px 14px; cursor:pointer;
    font-size:13px; font-weight:600; font-family:inherit;
    background:#fff; color:#4878a6;
    box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff;
    transition: box-shadow .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
}
.tr-btn:hover { box-shadow:2px 2px 5px #c8c4be,-2px -2px 5px #fff; }
.tr-btn-primary { background:#4878a6; color:#fff; }
.tr-btn-primary:hover { background:#3a6890; }
.tr-btn-ghost { background:transparent; box-shadow:none; color:#7a766f; padding:6px 10px; }

.tr-table-wrap {
    background:#fff; border-radius:12px; overflow:auto;
    box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff;
}
.tr-table {
    width:100%; border-collapse:collapse; font-size:12.5px;
    font-family:'Sora',sans-serif;
}
.tr-table thead th {
    background:#f4f1ec; color:#5a5650; font-weight:700; font-size:11px;
    padding:9px 8px; text-align:left; text-transform:uppercase; letter-spacing:.03em;
    border-bottom:2px solid #e3dfd8; white-space:nowrap; position:sticky; top:0; z-index:2;
}
.tr-table tbody td {
    padding:8px; border-bottom:1px solid #f0ece6; vertical-align:middle;
}
.tr-table tbody tr:hover { background:#fafaf6; }
.tr-table .num { text-align:right; font-family:'DM Mono',monospace; font-size:12px; }
.tr-table .ref { font-family:'DM Mono',monospace; font-weight:600; color:#4878a6; }
.tr-table a { color:#4878a6; text-decoration:none; }
.tr-table a:hover { text-decoration:underline; }

/* Pastilles statut */
.tr-status { display:inline-block; padding:3px 8px; border-radius:99px; font-size:10.5px; font-weight:700; white-space:nowrap; }
.tr-status.a_preparer    { background:#e9e6e0; color:#5a5650; }
.tr-status.pret          { background:#d9f0db; color:#2d6a35; }
.tr-status.commercialise { background:#d6e6f5; color:#2c5687; }
.tr-status.offre_recue   { background:#ffe5c2; color:#8a4c12; }
.tr-status.vendu         { background:#e6dcf2; color:#4a2e7a; }

/* ── Priorité vente : bordure gauche colorée sur la ligne ───── */
.tr-table tbody tr.prio-haute    { box-shadow: inset 5px 0 0 0 #fbbf24; background: #fffdf5; }
.tr-table tbody tr.prio-normale  { box-shadow: inset 5px 0 0 0 #fb923c; background: #fffaf3; }
.tr-table tbody tr.prio-differee { box-shadow: inset 5px 0 0 0 #ef4444; background: #fef7f7; }
.tr-table tbody tr.prio-haute:hover, .tr-table tbody tr.prio-normale:hover, .tr-table tbody tr.prio-differee:hover { background: #fafaf6; }

.tr-prio-dot { display:inline-block; width:12px; height:12px; border-radius:50%; cursor:pointer; margin:0 1px; border:1px solid transparent; }
.tr-prio-dot.haute    { background:#fbbf24; }
.tr-prio-dot.normale  { background:#fb923c; }
.tr-prio-dot.differee { background:#ef4444; }
.tr-prio-dot.none     { background:#e3dfd8; }
.tr-prio-dot.active   { border-color:#2c2a28; transform: scale(1.2); }
.tr-prio-dot:hover    { border-color:#9a9690; }

.tr-badge-type {
    display:inline-block; padding:2px 7px; border-radius:6px; font-size:10.5px; font-weight:700;
}
.tr-badge-type.vente    { background:#fbe9e9; color:#a8323b; }
.tr-badge-type.location { background:#e1f0e3; color:#2c6a3e; }

.tr-docs { font-size:11px; }
.tr-docs.full    { color:#2d6a35; }
.tr-docs.partial { color:#a8741d; }
.tr-docs.empty   { color:#a8323b; }

.tr-actions-cell { white-space:nowrap; }
.tr-actions-cell button, .tr-actions-cell a {
    border:none; background:transparent; cursor:pointer; padding:3px 5px;
    font-size:13px; color:#7a766f; border-radius:5px;
}
.tr-actions-cell button:hover, .tr-actions-cell a:hover {
    background:#f0ece6; color:#4878a6;
}

/* Modals V0 */
.tr-modal-backdrop {
    position:fixed; inset:0; background:rgba(40,38,36,.55);
    display:none; align-items:center; justify-content:center;
    z-index:9000;
}
.tr-modal-backdrop.show { display:flex; }
.tr-modal {
    background:#fff; border-radius:14px; padding:24px;
    width:560px; max-width:92vw; max-height:88vh; overflow:auto;
    box-shadow:0 20px 50px rgba(0,0,0,.25);
}
.tr-modal h3 { margin:0 0 14px; font-size:17px; color:#2c2a28; }
.tr-modal-close {
    float:right; border:none; background:transparent; cursor:pointer; font-size:20px;
    color:#9a9690; line-height:1;
}
.tr-modal label { display:block; font-size:12px; font-weight:600; color:#5a5650; margin:10px 0 4px; }
.tr-modal input, .tr-modal select, .tr-modal textarea {
    width:100%; border:1px solid #e3dfd8; border-radius:8px; padding:8px 10px; font-size:13px;
    box-sizing:border-box; font-family:inherit; background:#fafafa;
}
.tr-modal textarea { min-height:70px; resize:vertical; }
.tr-modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }

.tr-search-results { max-height:280px; overflow-y:auto; border:1px solid #e3dfd8; border-radius:8px; margin-top:8px; }
.tr-search-results .item {
    padding:8px 10px; border-bottom:1px solid #f0ece6; cursor:pointer; font-size:13px;
}
.tr-search-results .item:hover { background:#fafaf6; }
.tr-search-results .item .ref { color:#4878a6; font-family:'DM Mono',monospace; font-weight:600; margin-right:6px; }
.tr-search-results .item .meta { color:#9a9690; font-size:11px; }

.tr-empty {
    padding:60px; text-align:center; color:#7a766f; font-size:14px;
}
.tr-empty .icon { font-size:48px; margin-bottom:12px; }

@media (max-width: 1100px) {
    .tr-kpis { grid-template-columns:repeat(3,1fr); }
}
@media (max-width: 800px) {
    .tr-kpis { grid-template-columns:repeat(2,1fr); }
    .tr-table thead { display:none; }
    .tr-table, .tr-table tbody, .tr-table tr, .tr-table td { display:block; width:100%; box-sizing:border-box; }
    .tr-table tbody tr { background:#fff; border-radius:10px; padding:12px; margin-bottom:10px; box-shadow:2px 2px 6px #ddd; border:none; }
    .tr-table tbody td { padding:4px 0; border:none; display:flex; justify-content:space-between; }
    .tr-table tbody td::before { content:attr(data-label); font-weight:700; color:#9a9690; font-size:11px; text-transform:uppercase; }
}
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
?>

<!-- ── KPIs topbar ────────────────────────────────────────────── -->
<div class="tr-kpis">
    <div class="tr-kpi">
        <div class="tr-kpi-label">📋 En tableau</div>
        <div class="tr-kpi-value"><?= (int)$kpiTotal ?></div>
        <div class="tr-kpi-sub">bien(s) listé(s)</div>
    </div>
    <div class="tr-kpi">
        <div class="tr-kpi-label">✅ Prêts</div>
        <div class="tr-kpi-value"><?= (int)$kpiPret ?></div>
        <div class="tr-kpi-sub">dossier complet</div>
    </div>
    <div class="tr-kpi">
        <div class="tr-kpi-label">📡 Commercialisés</div>
        <div class="tr-kpi-value"><?= (int)$kpiCommercial ?></div>
        <div class="tr-kpi-sub">diffusés portails</div>
    </div>
    <div class="tr-kpi">
        <div class="tr-kpi-label">💰 Offres</div>
        <div class="tr-kpi-value"><?= (int)$kpiOffres ?></div>
        <div class="tr-kpi-sub">offre(s) reçue(s)</div>
    </div>
    <div class="tr-kpi">
        <div class="tr-kpi-label">🏁 Vendus</div>
        <div class="tr-kpi-value"><?= (int)$kpiVendus ?></div>
        <div class="tr-kpi-sub">clôturés</div>
    </div>
    <div class="tr-kpi">
        <div class="tr-kpi-label">💶 CA potentiel</div>
        <div class="tr-kpi-value"><?= number_format($caPotentiel, 0, ',', ' ') ?> €</div>
        <div class="tr-kpi-sub">cumul prix demandés</div>
    </div>
</div>

<!-- ── Barre de filtres (auto-submit) ───────────────────────── -->
<form method="get" action="<?= h(app_url('/transaction_index.php')) ?>" class="tr-filters" id="tr-filters-form">
    <input type="text" name="q" placeholder="🔎 Adresse, ville, référence, propriétaire…" value="<?= h($q) ?>" data-autosubmit="text">
    <select name="usage" data-autosubmit>
        <option value="all" <?= $fUsage==='all'?'selected':'' ?>>Tous usages</option>
        <option value="habitation" <?= $fUsage==='habitation'?'selected':'' ?>>Habitation</option>
        <option value="professionnel" <?= $fUsage==='professionnel'?'selected':'' ?>>Professionnel</option>
    </select>
    <select name="prix" data-autosubmit>
        <option value="all"  <?= $fPrix==='all'?'selected':'' ?>>Tous prix</option>
        <option value="small" <?= $fPrix==='small'?'selected':'' ?>>&lt; 500 000 €</option>
        <option value="500k" <?= $fPrix==='500k'?'selected':'' ?>>≥ 500 000 €</option>
        <option value="1M"   <?= $fPrix==='1M'?'selected':'' ?>>≥ 1 000 000 €</option>
    </select>
    <select name="archives" data-autosubmit title="Les archivés (vendus / retirés) sont masqués par défaut">
        <option value="">Actifs (par défaut)</option>
        <option value="1" <?= $fArchives==='1'?'selected':'' ?>>📦 Archivés</option>
    </select>
    <?php if ($isManager): ?>
    <select name="societe_id" data-autosubmit title="Filtrer par société">
        <option value="0">Toutes sociétés</option>
        <?php foreach ($societes as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $fSociete===(int)$s['id']?'selected':'' ?>><?= h($s['nom'] ?: '#'.$s['id']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="agence_id" data-autosubmit title="Filtrer par agence">
        <option value="0">Toutes agences</option>
        <?php foreach ($agences as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $fAgence===(int)$a['id']?'selected':'' ?>><?= h(ucwords(mb_strtolower($a['ville'] ?: $a['nom']))) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <a class="tr-btn tr-btn-ghost" href="<?= h(app_url('/transaction_index.php')) ?>" title="Tout réinitialiser">↺ Reset</a>

    <div class="tr-actions-right">
        <a class="tr-btn" href="<?= h(app_url('/transaction_chargement.php')) ?>">📦 Chargement par lot</a>
        <a class="tr-btn" href="<?= h(app_url('/transaction_modeles.php')) ?>">📑 Modèles</a>
        <button type="button" class="tr-btn" onclick="trOpenAddBien()">➕ Ajouter un bien</button>
        <a class="tr-btn-circuit" href="<?= h(app_url('/transaction_dossier_nouveau.php')) ?>"
           title="Créer un dossier de vente complet : propriétaire → bien → estimation → mandat → signature">
           🗂️ Nouveau dossier de vente
           <span class="tr-circuit-sub">circuit complet · du contact à la signature</span>
        </a>
    </div>
</form>

<script>
// Filtrage automatique : selects = submit immédiat / input texte = debounce 400ms
(function(){
    const form = document.getElementById('tr-filters-form');
    if (!form) return;
    form.querySelectorAll('select[data-autosubmit]').forEach(sel => {
        sel.addEventListener('change', () => form.submit());
    });
    const textInput = form.querySelector('input[data-autosubmit="text"]');
    if (textInput) {
        let t;
        textInput.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(() => form.submit(), 400);
        });
        // Enter = submit immédiat
        textInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { clearTimeout(t); /* submit natif via form */ }
        });
    }
})();
</script>

<!-- ── Cards ───────────────────────────────────────────────── -->
<style>
.trx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;align-items:start;}
.trx-card{background:#fff;border:1px solid #ece7df;border-left:4px solid #c8c4be;border-radius:14px;
  padding:13px 15px;box-shadow:0 2px 6px rgba(36,59,92,.07);display:flex;flex-direction:column;gap:9px;
  transition:transform .14s ease, box-shadow .14s ease;}
.trx-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(36,59,92,.12);}
.trx-card.tvente{border-left-color:#d4a047;} .trx-card.tlocation{border-left-color:#4878a6;}
.trx-card.prio-haute{border-left-color:#eab308;} .trx-card.prio-differee{border-left-color:#dc2626;}
.trx-head{display:flex;align-items:flex-start;gap:8px;}
.trx-title{font-size:15px;font-weight:800;color:#243B5C;text-decoration:none;line-height:1.2;flex:1;min-width:0;}
.trx-title:hover{text-decoration:underline;}
.trx-ville{font-size:15px;font-weight:700;color:#2d5f6b;line-height:1.2;margin-top:1px;}
.trx-ref{font-family:'DM Mono',monospace;font-weight:500;color:#9a9690;font-size:10.5px;letter-spacing:.04em;margin-top:1px;}
.trx-status{font-size:9px;font-weight:800;padding:2px 7px;border-radius:99px;background:#f4f1ec;color:#5a5650;text-transform:uppercase;letter-spacing:.02em;white-space:nowrap;flex:none;}
.trx-status.commercialise{background:#dbeafe;color:#1e40af;}
.trx-status.offre_recue{background:#fef3c7;color:#92400e;} .trx-status.vendu{background:#e6dcf2;color:#5b21b6;}
.trx-proprio{font-size:12px;color:#2d5f6b;margin-top:8px;}
.trx-proprio a{color:#2d5f6b;font-weight:700;text-decoration:none;border-bottom:1px dotted #8fb3bb;}
.trx-user{font-size:11.5px;color:#7a766f;}
.trx-meta{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:11.5px;color:#5a5650;border-top:1px solid #f3efe9;padding-top:8px;}
.trx-meta b{color:#243B5C;}
.trx-meta .prix{font-size:15px;font-weight:800;color:#1f7a3d;}
.trx-prio-row{display:flex;gap:6px;align-items:center;}
.trx-prio-dot{width:13px;height:13px;border-radius:50%;cursor:pointer;border:2px solid #e2ddd3;display:inline-block;}
.trx-prio-dot.haute{background:#eab308;} .trx-prio-dot.normale{background:#f59e0b;}
.trx-prio-dot.differee{background:#dc2626;} .trx-prio-dot.none{background:#fff;}
.trx-prio-dot.active{box-shadow:0 0 0 2px #243B5C;}
.trx-links{display:flex;gap:8px;flex-wrap:wrap;}
.trx-link{font-size:11.5px;font-weight:700;text-decoration:none;color:#2d4a72;background:#f3f6fb;border:1px solid #e3ebf5;
  border-radius:9px;padding:6px 11px;display:inline-flex;align-items:center;gap:5px;cursor:pointer;}
.trx-link:hover{background:#eaf1fa;}
.trx-actions{display:flex;gap:6px;flex-wrap:wrap;border-top:1px solid #f3efe9;padding-top:11px;margin-top:10px;}
.trx-abtn{width:34px;height:34px;border-radius:10px;border:1px solid #ece7df;background:#fff;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;font-size:15px;text-decoration:none;color:#3a3830;
  box-shadow:0 1px 2px rgba(36,59,92,.05);transition:transform .1s,box-shadow .1s,background .1s;}
.trx-abtn:hover{background:#fbfaf7;transform:translateY(-1px);box-shadow:0 3px 8px rgba(36,59,92,.12);}
</style>
<div class="tr-table-wrap">
<?php if (!empty($sqlError ?? '')): ?>
    <div class="tr-empty">
        <div class="icon">⚠️</div>
        <strong>Erreur SQL</strong><br>
        <code style="font-size:11px;"><?= h($sqlError) ?></code><br><br>
        Vérifier que la migration <code>migration_transaction_v0_2026-05-18.sql</code> a bien été appliquée.
    </div>
<?php elseif (empty($rows)): ?>
    <div class="tr-empty">
        <div class="icon">📭</div>
        Aucun bien en commercialisation ne correspond à ces filtres.<br>
        Clique sur <strong>➕ Ajouter un bien</strong> pour démarrer.
    </div>
<?php else: ?>
    <div class="trx-grid">
        <?php foreach ($rows as $r):
            $bienId      = (int)$r['bien_id'];
            $type        = (string)($r['type_transaction'] ?: $r['type_commercialisation'] ?: '');
            $typeClass   = (stripos($type, 'vente') !== false) ? 'vente' : ((stripos($type, 'loc') !== false) ? 'location' : '');
            $typeLabel   = $typeClass === 'vente' ? 'Vente' : ($typeClass === 'location' ? 'Location' : ($type ?: '—'));
            $statut      = (string)$r['statut_transaction'];
            $statutLabel = [
                'a_preparer'=>'À préparer','pret'=>'Prêt','commercialise'=>'Commercialisé',
                'offre_recue'=>'Offre reçue','vendu'=>'Vendu',
            ][$statut] ?? $statut;
            $nbDocs      = (int)$r['nb_docs'];
            $docsClass   = $nbDocs >= 5 ? 'full' : ($nbDocs >= 1 ? 'partial' : 'empty');
            $docsIcon    = $nbDocs >= 5 ? '🟢' : ($nbDocs >= 1 ? '🟠' : '🔴');
            $loyerHc     = (float)($r['loyer_hc'] ?? 0);
            $loyerAn     = $loyerHc > 0 ? $loyerHc * 12 : (float)($r['annonce_loyer'] ?? 0) * 12;
            $prix        = (float)($r['prix_demande_initial'] ?? $r['annonce_prix'] ?? $r['prix_vente_estime'] ?? 0);
            $rdt         = (float)($r['rendement_brut'] ?? 0);
            if ($rdt <= 0 && $prix > 0 && $loyerAn > 0) {
                $rdt = round(($loyerAn / $prix) * 100, 2);
            }
            $proprioNom    = '';
            $proprioTiersId = 0;
            $proprioRep     = '';
            if (!empty($r['id_proprietaire'])) {
                // Lookup léger une fois par ligne — V0 acceptable (LIMIT 500 max)
                // Cache enrichi : nom + id_tiers + 1er représentant (pour mini-card FluxBox)
                static $proCache = [];
                $pid = (int)$r['id_proprietaire'];
                if (!isset($proCache[$pid])) {
                    $stP = $pdo->prepare('SELECT
                        COALESCE(NULLIF(p.societe,""), CONCAT_WS(" ", p.prenom, p.nom)) AS lbl,
                        p.id_tiers AS tiers_id,
                        (SELECT CONCAT_WS(" ", t2.prenom, t2.nom,
                                IF(tc.qualite IS NOT NULL AND tc.qualite <> "", CONCAT("(", tc.qualite, ")"), ""))
                            FROM tiers_contacts tc
                            INNER JOIN tiers t2 ON t2.id = tc.id_tiers_contact
                            WHERE tc.id_tiers_entite = p.id_tiers AND tc.actif = 1
                            ORDER BY tc.priorite ASC LIMIT 1) AS rep_label
                        FROM proprietaires p WHERE p.id = ? LIMIT 1');
                    try {
                        $stP->execute([$pid]);
                        $row = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
                        $proCache[$pid] = [
                            'nom'      => (string)($row['lbl'] ?? ''),
                            'tiers_id' => (int)($row['tiers_id'] ?? 0),
                            'rep'      => trim((string)($row['rep_label'] ?? '')),
                        ];
                    } catch (Throwable) {
                        $proCache[$pid] = ['nom'=>'', 'tiers_id'=>0, 'rep'=>''];
                    }
                }
                $proprioNom    = $proCache[$pid]['nom'];
                $proprioTiersId = $proCache[$pid]['tiers_id'];
                $proprioRep     = $proCache[$pid]['rep'];
            }
            $maj = $r['bien_date_modification'] ? date('d/m/y', strtotime((string)$r['bien_date_modification'])) : '—';
            $prio = (string)($r['priorite_vente'] ?? '');
            $rowClass = in_array($prio, ['haute','normale','differee'], true) ? 'prio-' . $prio : '';
            // Lookup nom immeuble + user responsable (id_user_actuel) — cache par bien
            static $bienExtraCache = [];
            if (!isset($bienExtraCache[$bienId])) {
                try {
                    $stX = $pdo->prepare("SELECT i.nom_immeuble, TRIM(CONCAT_WS(' ', u.prenom, u.nom)) AS user_nom
                        FROM biens b
                        LEFT JOIN immeubles i ON i.id = b.id_immeuble
                        LEFT JOIN users u ON u.id = b.id_user_actuel
                        WHERE b.id = ? LIMIT 1");
                    $stX->execute([$bienId]);
                    $rx = $stX->fetch(PDO::FETCH_ASSOC) ?: [];
                    $bienExtraCache[$bienId] = ['immeuble'=>trim((string)($rx['nom_immeuble'] ?? '')), 'user'=>trim((string)($rx['user_nom'] ?? ''))];
                } catch (Throwable) { $bienExtraCache[$bienId] = ['immeuble'=>'', 'user'=>'']; }
            }
            $immNom  = $bienExtraCache[$bienId]['immeuble'];
            $userNom = $bienExtraCache[$bienId]['user'];
        ?>
            <div class="trx-card t<?= h($typeClass ?: '') ?> <?= h($rowClass) ?>" data-bien="<?= $bienId ?>">
                <?php
                // Titre = NOM DE L'IMMEUBLE en priorité ; sinon désignation ; sinon la rue
                // seule (on retire CP / ville / pays en coupant à la 1re virgule).
                $titreCard = $immNom ?: ($r['designation'] ?: trim((string)(explode(',', (string)($r['adresse_1'] ?? ''))[0])));
                if ($titreCard === '') $titreCard = '—';
                ?>
                <div class="trx-head">
                    <a class="trx-title" href="<?= h(app_url('/bien_360.php?id=' . $bienId)) ?>" title="Fiche 360° du bien"><?= h($titreCard) ?> ↗</a>
                    <?php if (in_array($statut, ['commercialise','offre_recue','vendu'], true)): ?>
                        <span class="trx-status <?= h($statut) ?>" title="<?= $statut==='commercialise' ? 'Annonce active' : '' ?>"><?= $statut==='commercialise' ? '📡 ' : '' ?><?= h($statutLabel) ?></span>
                    <?php endif; ?>
                </div>
                <div class="trx-ville">📍 <?= h($r['ville'] ?: '—') ?></div>
                <div class="trx-ref">Réf. <?= h($r['reference_bien'] ?: '#' . $bienId) ?></div>
                <div class="trx-proprio">👤 <?php if ($proprioTiersId > 0): ?><a href="<?= h(app_url('/tiers_360.php?id=' . $proprioTiersId)) ?>"><?= h($proprioNom ?: '—') ?></a><?php else: ?><strong><?= h($proprioNom ?: '—') ?></strong><?php endif; ?><?php if (!empty($r['usage_bien'])): ?> <span style="color:#9a9690;">· <?= h($r['usage_bien']) ?></span><?php endif; ?></div>
                <?php if ($userNom !== ''): ?><div class="trx-user">🧑‍💼 <?= h($userNom) ?></div><?php endif; ?>
                <div class="trx-meta">
                    <?php if ($prix > 0): ?><span class="prix"><?= number_format($prix, 0, ',', ' ') ?> €</span><?php endif; ?>
                    <?php if ($r['surface_habitable']): ?><span>📐 <b><?= number_format((float)$r['surface_habitable'], 0) ?></b> m²</span><?php endif; ?>
                    <?php if ($loyerAn > 0): ?><span>🔑 <b><?= number_format($loyerAn, 0, ',', ' ') ?></b> €/an</span><?php endif; ?>
                    <?php if ($rdt > 0): ?><span>📈 <b><?= number_format($rdt, 2, ',', '') ?></b> %</span><?php endif; ?>
                    <span title="<?= $nbDocs ?> document(s) GED"><?= $docsIcon ?> <?= $nbDocs ?> doc</span>
                    <?php if ((int)$r['nb_offres_actives'] > 0): ?><span style="color:#a8741d;">💰 <b><?= (int)$r['nb_offres_actives'] ?></b><?php if ($r['meilleure_offre']): ?> · <?= number_format((float)$r['meilleure_offre'], 0, ',', ' ') ?> €<?php endif; ?></span><?php endif; ?>
                </div>
                <?php
                // N1 suggéré : priorité au mandat actif (la vérité métier), fallback bien.type_commercialisation.
                //  - mandats.type_mandat = 'gerance'                      → 03_GESTION_LOCATIVE (vraie gestion)
                //  - mandats.type_mandat = 'transaction' ou 'location'    → 05_TRANSACTION (location simple = mise en
                //                                                          relation locataire SANS gestion = transaction GED)
                //  - mandats.type_mandat = 'syndic'                       → 04_SYNDIC
                //  - pas de mandat actif → fallback bien.type_commercialisation
                //    ('gestion' → GESTION, 'vente' → TRANSACTION, 'location' = ambigu → '')
                // Priorité : gerance > syndic > transaction > location.
                // Si plusieurs mandats actifs simultanés (cas réel : gestion + vente en cours,
                // ou location + vente concurrents "premier arrivé"), on privilégie la GESTION
                // car c'est l'activité principale en cours (les autres = projets).
                static $_mandatTypeCache = [];
                if (!isset($_mandatTypeCache[$bienId])) {
                    try {
                        $stMandat = $pdo->prepare("SELECT type_mandat FROM mandats
                            WHERE id_bien = ? AND (statut = 'actif' OR statut = 'en_cours' OR statut IS NULL)
                            ORDER BY CASE type_mandat
                                WHEN 'gerance'     THEN 1
                                WHEN 'syndic'      THEN 2
                                WHEN 'transaction' THEN 3
                                WHEN 'location'    THEN 4
                                ELSE 9 END,
                                id DESC
                            LIMIT 1");
                        $stMandat->execute([$bienId]);
                        $_mandatTypeCache[$bienId] = (string)($stMandat->fetchColumn() ?: '');
                    } catch (Throwable) {
                        $_mandatTypeCache[$bienId] = '';
                    }
                }
                $mandatType = strtolower(trim($_mandatTypeCache[$bienId]));
                $typeCom    = strtolower(trim((string)($r['type_commercialisation'] ?? '')));
                $n1Suggested = match (true) {
                    $mandatType === 'gerance'                              => '03_GESTION_LOCATIVE',
                    in_array($mandatType, ['transaction', 'location'], true) => '05_TRANSACTION',
                    $mandatType === 'syndic'                               => '04_SYNDIC',
                    // Fallback sans mandat
                    $typeCom === 'gestion'                                 => '03_GESTION_LOCATIVE',
                    $typeCom === 'vente'                                   => '05_TRANSACTION',
                    default                                                => '',  // 'location' sans mandat = ambigu, IA décide
                };
                ?>
                <?php
                $adresseBien = trim(((string)($r['adresse_1'] ?? '')) . ' ' . ((string)($r['code_postal'] ?? '')) . ' ' . ((string)($r['ville'] ?? '')));
                ?>
                <div class="trx-links">
                    <a class="trx-link" href="<?= h(app_url('/transaction_dossier.php?id_bien=' . $bienId)) ?>" title="Dossier de vente (vendeur, acquéreur, notaire, docs, étapes)">🗂️ Dossier de vente</a>
                    <a class="trx-link" href="<?= h(app_url('/bien_detail.php?edit=' . $bienId)) ?>" title="Éditer la fiche bien">✏️ Éditer</a>
                </div>
                <div class="trx-actions">
                    <button class="trx-abtn" title="Voir l'historique" onclick="trOpenHistorique(<?= $bienId ?>)">📜</button>
                    <button class="trx-abtn" title="Ajouter une offre" onclick="trOpenOffre(<?= $bienId ?>, <?= (int)($r['annonce_id'] ?? 0) ?>)">💰</button>
                    <button class="trx-abtn" title="Charger un document (FluxBox V3 : nommage + IA + classement auto)"
                            onclick="trOpenDocFluxbox(<?= $bienId ?>, <?= (int)($r['id_societe'] ?? 0) ?>, <?= (int)($r['id_agence'] ?? 0) ?>, <?= (int)($r['id_proprietaire'] ?? 0) ?>, '<?= h($n1Suggested) ?>', '<?= h((string)($r['reference_bien'] ?? '')) ?>', '<?= h($adresseBien) ?>', '<?= h($proprioNom) ?>', <?= $proprioTiersId ?>, '<?= h($proprioRep) ?>')">📎</button>
                    <button class="trx-abtn" title="Envoyer le dossier" onclick="trOpenSend(<?= $bienId ?>)">✉️</button>
                    <button class="trx-abtn" title="Retirer de la vente (sort le bien du tableau + archive l'annonce — ne supprime pas le bien)"
                            onclick="trRetirerVente(<?= $bienId ?>, <?= (int)($r['id_proprietaire'] ?? 0) ?>, '<?= h((string)($r['reference_bien'] ?? '')) ?>')">↩️</button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
<?php endif; ?>
</div>

<!-- ═════════════════════════════════════════════════════════════════
     MODALES V0
═════════════════════════════════════════════════════════════════ -->

<!-- Modal : Ajouter un bien au tableau Transaction -->
<div class="tr-modal-backdrop" id="tr-modal-addbien">
    <div class="tr-modal">
        <button class="tr-modal-close" onclick="trClose('tr-modal-addbien')">×</button>
        <h3>➕ Ajouter un bien au tableau Transaction</h3>
        <p style="font-size:12px; color:#7a766f;">
            On ne recrée pas le bien : on sélectionne un bien existant. Le bien apparaîtra dans le tableau
            dès qu'il aura un <code>type_commercialisation</code> (vente ou location).
        </p>

        <label>Choisir par propriétaire</label>
        <select id="tr-addbien-proprio">
            <option value="0">— Tous propriétaires —</option>
            <?php foreach ($proprietairesAll as $p):
                $dispo = (int)($p['nb_biens_dispo'] ?? 0);
                // On n'affiche que les propriétaires qui ont au moins un bien NON encore commercialisé
                if ($dispo <= 0) continue;
            ?>
                <option value="<?= (int)$p['id'] ?>">
                    <?= h($proprio_label($p)) ?> (<?= $dispo ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:14px;">Ou recherche libre (référence, désignation, ville, adresse)</label>
        <input type="text" id="tr-addbien-search" placeholder="Tape au moins 3 caractères…">
        <div style="font-size:11px; color:#9a9690; margin-top:4px;">
            💡 Sélectionne un propriétaire pour voir directement <strong>tous ses biens</strong>, ou tape pour rechercher dans tout le portefeuille.
        </div>
        <div id="tr-addbien-results" class="tr-search-results" style="display:none;"></div>

        <div id="tr-addbien-confirm" style="display:none; margin-top:14px; padding:12px; background:#f9f7f3; border-radius:8px;">
            <div id="tr-addbien-confirm-info" style="font-size:13px;"></div>
            <label>Type de commercialisation</label>
            <select id="tr-addbien-type">
                <option value="vente">Vente</option>
                <option value="location">Location</option>
            </select>
            <div class="tr-modal-actions">
                <button class="tr-btn" onclick="trClose('tr-modal-addbien')">Annuler</button>
                <button class="tr-btn tr-btn-primary" onclick="trAddBienConfirm()">Ajouter au tableau</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Ajouter une offre -->
<div class="tr-modal-backdrop" id="tr-modal-offre">
    <div class="tr-modal">
        <button class="tr-modal-close" onclick="trClose('tr-modal-offre')">×</button>
        <h3>💰 Saisir une offre</h3>
        <form id="tr-form-offre">
            <input type="hidden" name="id_bien" id="tr-offre-bien">
            <input type="hidden" name="id_annonce" id="tr-offre-annonce">
            <label>Montant proposé (€) *</label>
            <input type="number" name="prix_propose" step="100" required>
            <label>Acquéreur — Nom *</label>
            <input type="text" name="nom" required>
            <label>Prénom</label>
            <input type="text" name="prenom">
            <label>Email</label>
            <input type="email" name="email">
            <label>Téléphone</label>
            <input type="text" name="telephone">
            <label>Financement</label>
            <select name="financement_type">
                <option value="inconnu">Inconnu</option>
                <option value="cash">Cash</option>
                <option value="emprunt">Emprunt</option>
                <option value="mixte">Mixte</option>
            </select>
            <label>Statut</label>
            <select name="statut_offre">
                <option value="recue">Reçue</option>
                <option value="transmise_vendeur">Transmise au vendeur</option>
                <option value="acceptee">Acceptée</option>
                <option value="refusee">Refusée</option>
                <option value="contre_offre">Contre-offre</option>
            </select>
            <label>Commentaire</label>
            <textarea name="message"></textarea>
            <div class="tr-modal-actions">
                <button type="button" class="tr-btn" onclick="trClose('tr-modal-offre')">Annuler</button>
                <button type="submit" class="tr-btn tr-btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Ajouter un document -->
<div class="tr-modal-backdrop" id="tr-modal-doc">
    <div class="tr-modal">
        <button class="tr-modal-close" onclick="trClose('tr-modal-doc')">×</button>
        <h3>📎 Ajouter un document</h3>
        <form id="tr-form-doc" enctype="multipart/form-data">
            <input type="hidden" name="id_bien" id="tr-doc-bien">
            <label>Type de document *</label>
            <select name="type_document" required>
                <option value="MANDAT_VENTE">Mandat de vente</option>
                <option value="MANDAT_LOCATION">Mandat de location</option>
                <option value="DIAG_DPE">Diagnostic DPE</option>
                <option value="DIAG_AMIANTE">Diagnostic amiante</option>
                <option value="DIAG_PLOMB">Diagnostic plomb</option>
                <option value="DIAG_ERP">État des risques</option>
                <option value="BAIL">Bail</option>
                <option value="TAXE_FONCIERE">Taxe foncière</option>
                <option value="PLAN">Plan</option>
                <option value="PHOTO">Photo</option>
                <option value="OFFRE_ACHAT">Offre d'achat</option>
                <option value="COMPROMIS">Compromis</option>
                <option value="ACTE_AUTHENTIQUE">Acte authentique</option>
                <option value="AUTRE">Autre</option>
            </select>
            <label>Visibilité</label>
            <select name="visibilite">
                <option value="interne">Interne (équipe seulement)</option>
                <option value="commercialisateur">Commercialisateur</option>
                <option value="proprietaire">Propriétaire</option>
                <option value="notaire">Notaire</option>
            </select>
            <label>Fichier *</label>
            <input type="file" name="fichier" required>
            <label>Commentaire</label>
            <textarea name="commentaire"></textarea>
            <div class="tr-modal-actions">
                <button type="button" class="tr-btn" onclick="trClose('tr-modal-doc')">Annuler</button>
                <button type="submit" class="tr-btn tr-btn-primary">Uploader</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Envoyer dossier -->
<div class="tr-modal-backdrop" id="tr-modal-send">
    <div class="tr-modal">
        <button class="tr-modal-close" onclick="trClose('tr-modal-send')">×</button>
        <h3>✉️ Envoyer le dossier</h3>
        <form id="tr-form-send">
            <input type="hidden" name="id_bien" id="tr-send-bien">
            <label>Email destinataire *</label>
            <input type="email" name="email" required placeholder="commercialisateur@agence.fr">
            <label>Nom du destinataire</label>
            <input type="text" name="destinataire_nom" placeholder="Agence partenaire / Notaire / …">
            <label>Documents à joindre</label>
            <div id="tr-send-docs" style="border:1px solid #e3dfd8; border-radius:8px; padding:8px; max-height:160px; overflow-y:auto; background:#fafafa; font-size:12px;">
                <em>Chargement…</em>
            </div>
            <label>Message complémentaire (optionnel)</label>
            <textarea name="message_extra" placeholder="Précisions, points d'attention…"></textarea>
            <div class="tr-modal-actions">
                <button type="button" class="tr-btn" onclick="trClose('tr-modal-send')">Annuler</button>
                <button type="submit" class="tr-btn tr-btn-primary">Envoyer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Historique -->
<div class="tr-modal-backdrop" id="tr-modal-histo">
    <div class="tr-modal" style="width:680px;">
        <button class="tr-modal-close" onclick="trClose('tr-modal-histo')">×</button>
        <h3>📜 Historique du bien</h3>
        <div id="tr-histo-content" style="font-size:13px;">Chargement…</div>
        <div class="tr-modal-actions">
            <button class="tr-btn" onclick="trClose('tr-modal-histo')">Fermer</button>
        </div>
    </div>
</div>

<script>
// ── Helpers modals ───────────────────────────────────────────
const APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;
function trOpen(id)  { document.getElementById(id).classList.add('show'); }
function trClose(id) { document.getElementById(id).classList.remove('show'); }

// ── Ajouter un bien : recherche + sélection ─────────────────
function trOpenAddBien() {
    document.getElementById('tr-addbien-search').value = '';
    document.getElementById('tr-addbien-proprio').value = '0';
    document.getElementById('tr-addbien-results').style.display = 'none';
    document.getElementById('tr-addbien-confirm').style.display = 'none';
    trOpen('tr-modal-addbien');
}
let trSelectedBien = null;
let trAddBienTimer = null;

async function trDoAddBienSearch() {
    const q       = document.getElementById('tr-addbien-search').value.trim();
    const proprio = parseInt(document.getElementById('tr-addbien-proprio').value, 10) || 0;
    // Rien à chercher
    if (proprio === 0 && q.length < 3) {
        document.getElementById('tr-addbien-results').style.display = 'none';
        return;
    }
    const params = new URLSearchParams();
    if (q.length >= 3) params.set('q', q);
    if (proprio > 0)   params.set('proprietaire_id', String(proprio));
    try {
        const res = await fetch(APP_BASE + '/api/transaction_bien_search.php?' + params.toString());
        const data = await res.json();
        const box = document.getElementById('tr-addbien-results');
        box.style.display = 'block';
        box.innerHTML = '';
        if (!data.items || !data.items.length) {
            box.innerHTML = '<div class="item" style="color:#9a9690;">Aucun bien trouvé pour ce filtre.</div>';
            return;
        }
        // Si propriétaire sélectionné → bandeau de contexte
        if (proprio > 0) {
            const sel = document.getElementById('tr-addbien-proprio');
            const lbl = sel.options[sel.selectedIndex].textContent.trim();
            const hdr = document.createElement('div');
            hdr.className = 'item';
            hdr.style.cssText = 'background:#f4f1ec; color:#5a5650; font-weight:700; cursor:default;';
            hdr.textContent = '📁 ' + data.items.length + ' bien(s) pour ' + lbl;
            box.appendChild(hdr);
        }
        data.items.forEach(b => {
            const item = document.createElement('div');
            item.className = 'item';
            const adresse = [b.adresse_1, [b.code_postal, b.ville].filter(Boolean).join(' ')]
                .filter(s => s && s.trim()).join(' — ');
            const adresseLine = adresse || '<em style="color:#bbb;">Adresse non renseignée</em>';
            const lotEtage = [b.numero_lot ? 'Lot ' + b.numero_lot : '', b.etage ? 'étage ' + b.etage : '']
                .filter(Boolean).join(' · ');
            item.innerHTML = '<span class="ref">'+ (b.reference_bien || '#'+b.id) +'</span> '
                           + (b.designation || '')
                           + '<div class="meta">📍 ' + adresseLine
                           + (lotEtage ? ' · ' + lotEtage : '')
                           + (b.type_commercialisation ? ' · déjà : '+b.type_commercialisation : '')
                           + '</div>';
            item.onclick = () => trSelectBien(b);
            box.appendChild(item);
        });
    } catch (err) { console.error(err); }
}

// Trigger sur changement de propriétaire (immédiat)
document.getElementById('tr-addbien-proprio').addEventListener('change', trDoAddBienSearch);

// Trigger sur saisie texte (debounce)
document.getElementById('tr-addbien-search').addEventListener('input', function(){
    clearTimeout(trAddBienTimer);
    trAddBienTimer = setTimeout(trDoAddBienSearch, 250);
});
function trSelectBien(b) {
    trSelectedBien = b;
    document.getElementById('tr-addbien-results').style.display = 'none';
    const info = document.getElementById('tr-addbien-confirm-info');
    info.innerHTML = '<strong>'+ (b.reference_bien || '#'+b.id) +'</strong> — '
                   + (b.designation || '') + '<br>'
                   + '<small style="color:#7a766f;">'+ (b.adresse_1 || '') + ' · ' + (b.ville || '') +'</small>';
    if (b.has_active_annonce) {
        info.innerHTML += '<br><br><span style="color:#a8741d; font-weight:600;">⚠️ Ce bien est déjà présent dans le tableau Transaction (annonce active).</span>';
    }
    document.getElementById('tr-addbien-confirm').style.display = 'block';
    if (b.type_commercialisation) {
        document.getElementById('tr-addbien-type').value = b.type_commercialisation;
    }
}
async function trAddBienConfirm() {
    if (!trSelectedBien) return;
    const type = document.getElementById('tr-addbien-type').value;
    try {
        const fd = new FormData();
        fd.append('id_bien', trSelectedBien.id);
        fd.append('type_commercialisation', type);
        const res = await fetch(APP_BASE + '/api/transaction_bien_add.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.ok) {
            location.href = APP_BASE + '/transaction_index.php?q=' + encodeURIComponent(trSelectedBien.reference_bien || '');
        } else {
            alert('Erreur : ' + (data.error || 'inconnue'));
        }
    } catch (err) { alert('Erreur réseau'); }
}

// ── Modal Offre ──────────────────────────────────────────────
function trOpenOffre(bienId, annonceId) {
    document.getElementById('tr-offre-bien').value = bienId;
    document.getElementById('tr-offre-annonce').value = annonceId;
    document.getElementById('tr-form-offre').reset();
    document.getElementById('tr-offre-bien').value = bienId;
    document.getElementById('tr-offre-annonce').value = annonceId;
    trOpen('tr-modal-offre');
}
document.getElementById('tr-form-offre').addEventListener('submit', async function(e){
    e.preventDefault();
    const fd = new FormData(this);
    try {
        const res = await fetch(APP_BASE + '/api/transaction_offre_save.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { alert('Erreur : ' + (data.error || 'inconnue')); }
    } catch (err) { alert('Erreur réseau'); }
});

// ── Modal Document : route via FluxBox V3 ────────────────────
// Le legacy trOpenDoc(bienId) ouvrait tr-modal-doc → POST transaction_doc_upload.
// Nouveau : ouvre la modal FluxBox avec contexte pré-rempli (société, agence,
// bien, propriétaire). FluxBox gère storage, nommage V3, IA, classement.
function trOpenDocFluxbox(bienId, socId, ageId, proprioId, n1, refBien, adresseBien, proprioNom, proprioTiersId, proprioRep) {
    if (typeof window.fbxOpenUploadModal !== 'function') {
        alert('Module FluxBox non chargé sur cette page. Recharge la page.');
        return;
    }
    window.fbxOpenUploadModal({
        bien_id:              bienId,
        soc_id:               socId    || 0,
        age_id:               ageId    || 0,
        proprio_id:           proprioId || 0,
        proprio_nom:          proprioNom || '',
        proprio_tiers_id:     proprioTiersId || 0,
        proprio_representant: proprioRep || '',
        n1:                   n1 || '',
        n2:                   'BIENS',
        n3:                   'BIEN',
        entite_nom:           refBien || ('Bien #' + bienId),
        entite_id_bdd:        bienId,
        entite_adresse:       adresseBien || '',
        origin:               'transaction_index'
    });
}
// Conservé en alias pour code legacy éventuel
function trOpenDoc(bienId) { trOpenDocFluxbox(bienId, 0, 0, 0, '', '', '', '', 0, ''); }
document.getElementById('tr-form-doc').addEventListener('submit', async function(e){
    e.preventDefault();
    const fd = new FormData(this);
    try {
        const res = await fetch(APP_BASE + '/api/transaction_doc_upload.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { alert('Erreur : ' + (data.error || 'inconnue')); }
    } catch (err) { alert('Erreur réseau'); }
});

// ── Modal Envoi dossier ──────────────────────────────────────
// Retirer un bien de la vente (annule un doublon) : sort du tableau + archive l'annonce. Ne supprime pas le bien.
async function trRetirerVente(bienId, propId, ref) {
    if (!confirm('Retirer ' + (ref || ('bien #' + bienId)) + ' de la commercialisation « vente » ?\n\n→ Le bien quitte le tableau Transaction\n→ Son annonce (brouillon) est archivée — plus de diffusion\n→ Le bien N\'EST PAS supprimé')) return;
    try {
        const fd = new FormData();
        fd.append('id_bien', bienId);
        fd.append('id_proprietaire', propId);
        const res = await fetch(APP_BASE + '/bailleur_retirer_vente.php', { method:'POST', body:fd });
        const d = await res.json();
        if (!d.ok) { alert('❌ ' + (d.error || 'Échec du retrait')); return; }
        alert('✓ Bien retiré de la vente' + (d.annonces_archivees ? ' · ' + d.annonces_archivees + ' annonce(s) archivée(s)' : ''));
        location.reload();
    } catch (e) { alert('❌ Erreur réseau'); }
}
async function trOpenSend(bienId) {
    document.getElementById('tr-send-bien').value = bienId;
    document.getElementById('tr-form-send').reset();
    document.getElementById('tr-send-bien').value = bienId;
    const box = document.getElementById('tr-send-docs');
    box.innerHTML = '<em>Chargement…</em>';
    trOpen('tr-modal-send');
    try {
        const res = await fetch(APP_BASE + '/api/transaction_bien_docs.php?id_bien=' + bienId);
        const data = await res.json();
        if (!data.items || !data.items.length) {
            box.innerHTML = '<em style="color:#9a9690;">Aucun document Transaction rattaché à ce bien.</em>';
            return;
        }
        box.innerHTML = '';
        data.items.forEach(d => {
            const lbl = document.createElement('label');
            lbl.style.display = 'flex'; lbl.style.alignItems = 'center'; lbl.style.gap='6px'; lbl.style.margin='4px 0';
            lbl.innerHTML = '<input type="checkbox" name="docs[]" value="'+d.id+'" checked> '
                          + '<span>'+ (d.name_display || d.name_file) +'</span>'
                          + ' <span style="color:#9a9690; font-size:11px;">('+ (d.document_type || '') +')</span>';
            box.appendChild(lbl);
        });
    } catch(err) { box.innerHTML = '<em>Erreur</em>'; }
}
document.getElementById('tr-form-send').addEventListener('submit', async function(e){
    e.preventDefault();
    const fd = new FormData(this);
    try {
        const res = await fetch(APP_BASE + '/api/transaction_send_dossier.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.ok) { alert('✉️ Dossier envoyé à ' + data.to); trClose('tr-modal-send'); }
        else { alert('Erreur : ' + (data.error || 'inconnue')); }
    } catch (err) { alert('Erreur réseau'); }
});

// ── Changement priorité de vente (1 clic) ───────────────────
async function trSetPriorite(bienId, prio) {
    try {
        const fd = new FormData();
        fd.append('id_bien', bienId);
        fd.append('priorite_vente', prio);
        const res = await fetch(APP_BASE + '/api/transaction_priorite_save.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { alert('Erreur : ' + (data.error || 'inconnue')); }
    } catch (err) { alert('Erreur réseau'); }
}

// ── Modal Historique ─────────────────────────────────────────
async function trOpenHistorique(bienId) {
    document.getElementById('tr-histo-content').innerHTML = 'Chargement…';
    trOpen('tr-modal-histo');
    try {
        const res = await fetch(APP_BASE + '/api/transaction_bien_historique.php?id_bien=' + bienId);
        const html = await res.text();
        document.getElementById('tr-histo-content').innerHTML = html;
    } catch (err) {
        document.getElementById('tr-histo-content').innerHTML = '<em>Aucun historique disponible pour l\'instant.</em>';
    }
}
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
