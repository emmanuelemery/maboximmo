<?php
// transaction_portefeuilles.php — Portefeuilles · Onglet ADMIN / GESTIONNAIRE (V1, 2026-06-05)
// Objectif : voir TOUS les biens d'un périmètre de bailleurs (SABY, GROUPE SIR, SCI…),
//            les sélectionner et fixer un prix proposé pour bâtir un portefeuille de vente.
//
// Rendu serveur (GET auto-submit, pas d'AJAX) — même logique que transaction_index.php (V0).
// LECTURE SEULE : aucune écriture sur biens / bien_prix à ce stade.
//
// Périmètre : VERROUILLÉ sur les propriétaires ci-dessous (cf. demande métier).
//   OPERA n'a aucun bien rattaché (tiers #756 existe mais 0 bien) → absent de la liste.
//   GROUPE SIR (#9) et SIR IMMO / GROUPE SIR (immo) (#21) sont listés séparément ici
//   (même bailleur réel, cf. mémoire, mais affichage par propriétaire conservé).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

// ── Périmètre VISIBLE par l'utilisateur (règle d'or : un bailleur ne voit que ses biens) ─────
require_once __DIR__ . '/inc/portefeuille_scope.php';
$scope        = pf_scope($pdo);
$PERIMETRE    = $scope['perimetre'];
$GROUP_MERGE  = $scope['merge'];
$perimetreIds = $scope['ids'];      // ids visibles par CET utilisateur (staff = tout ; bailleur = ses proprios)
$isStaff      = $scope['is_staff'];

// Fusion d'affichage : SIR IMMO (#21) et CB FINANCES (#1326) sont cumulés dans GROUPE SIR (#9).
// Les ids restent dans le périmètre/scope SQL, mais sont regroupés sous #9.
$groupKey = fn(int $pid) => $GROUP_MERGE[$pid] ?? $pid;

// ── Lecture filtres GET ────────────────────────────────────────────
$q        = trim((string)($_GET['q'] ?? ''));
$fType    = (string)($_GET['type'] ?? 'all');     // vente | location | all
$fStatut  = (string)($_GET['statut'] ?? 'all');   // a_vendre | pas_a_vendre | all
$fProprio = isset($_GET['proprietaire_id']) ? (int)$_GET['proprietaire_id'] : 0;

// ── Construction de la requête ─────────────────────────────────────
// Le périmètre est la borne dure : on ne sort JAMAIS de ces propriétaires.
$scopeIds = $perimetreIds;
if ($fProprio > 0 && in_array($fProprio, $perimetreIds, true)) {
    // On scope sur le propriétaire choisi + tout id fusionné dessous (ex: GROUPE SIR ⊃ SIR IMMO).
    $scopeIds = array_merge([$fProprio], array_keys($GROUP_MERGE, $fProprio, true));
}
// Borne dure : si l'utilisateur n'a aucun périmètre visible, on ne montre RIEN (0=1).
$inScope = !empty($scopeIds) ? implode(',', array_map('intval', $scopeIds)) : '0';

// SOURCE IDENTIQUE À « PATRIMOINE ACTIF » (Emmanuel 2026-06) : on ne liste QUE les
// biens présents dans le DERNIER trimestre CRG de chaque propriétaire du périmètre,
// exactement comme le patrimoine actif (même $base_sql). Garantit que « ce que je vois
// dans le patrimoine actif, je le vois dans le portefeuille à créer ». Élimine aussi
// les doublons d'import (0 CRG). Les « Portefeuilles enregistrés » restent historisés
// (table séparée, non impactée).
$where  = ["(
      b.id IN (
        SELECT crg.id_bien
        FROM crg_situations_locataires crg
        JOIN crg_trimestres ct ON ct.id = crg.id_crg
        WHERE ct.id_proprietaire IN ($inScope)
          AND (ct.parse_statut IS NULL OR ct.parse_statut <> 'erreur')
          AND (ct.annee, ct.trimestre) = (
                SELECT ct2.annee, ct2.trimestre FROM crg_trimestres ct2
                 WHERE ct2.id_proprietaire = ct.id_proprietaire
                   AND (ct2.parse_statut IS NULL OR ct2.parse_statut <> 'erreur')
                 ORDER BY ct2.annee DESC, ct2.trimestre DESC LIMIT 1)
      )
      OR (
        -- Biens GÉRÉS HORS CRG (pas encore de CRG / pas de locataire) mais VALORISÉS.
        -- Miroir exact du UNION 'hors_crg' de patrimoine_base_sql, pour que le portefeuille
        -- affiche EXACTEMENT ce que montre le Patrimoine actif (ex : VTE-2026-00x du GROUPE SIR).
        b.id_proprietaire IN ($inScope)
        AND NOT EXISTS (
            SELECT 1 FROM crg_situations_locataires c2
            JOIN crg_trimestres t2 ON t2.id = c2.id_crg
            WHERE c2.id_bien = b.id AND t2.id_proprietaire = b.id_proprietaire
              AND (t2.parse_statut IS NULL OR t2.parse_statut <> 'erreur')
        )
        AND COALESCE(
            (SELECT bp.montant FROM bien_prix bp
               WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1
               ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
            b.prix_demande_initial, 0) > 0
      )
    )"];
// Liste de TRANSACTION (pas le patrimoine) : on exclut totalement les vendus/archivés.
//  • niveau BIEN : statut + date de retrait / prix final
$where[] = '(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive","vendu"))';
$where[] = '(b.date_retrait_commercialisation IS NULL AND b.prix_final_vente IS NULL)';
//  • niveau IMMEUBLE : immeuble vendu (i.vendu) ou supprimé/fusionné/archivé
$where[] = '(i.vendu IS NULL OR i.vendu = 0)';
$where[] = '(i.statut_immeuble IS NULL OR i.statut_immeuble NOT IN ("supprime","archive","vendu"))';

// ── ANTI-DOUBLON (non destructif) ──────────────────────────────────────
// Masque les biens VIDES (0 CRG + 0 bail) qui DOUBLENT un vrai bien du même
// immeuble et de même surface (lui ayant des CRG). Ce sont les artefacts
// d'import (réf MSN-/TMP-…) qui apparaissaient comme « Lot xxxx — Vacant ».
$where[] = "NOT (
    (SELECT COUNT(*) FROM crg_situations_locataires c WHERE c.id_bien = b.id) = 0
    AND (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id) = 0
    AND b.surface_habitable > 0
    AND EXISTS (
        SELECT 1 FROM biens b2
        WHERE b2.id_immeuble = b.id_immeuble AND b2.id <> b.id
          AND b2.surface_habitable = b.surface_habitable
          AND (b2.statut_bien IS NULL OR b2.statut_bien <> 'supprime')
          AND EXISTS (SELECT 1 FROM crg_situations_locataires c2 WHERE c2.id_bien = b2.id)
    )
)";
$params = [];

if ($fType === 'vente' || $fType === 'location') {
    $where[] = 'b.type_commercialisation = :ftype';
    $params[':ftype'] = $fType;
}

if ($fStatut === 'a_vendre') {
    $where[] = 'b.type_commercialisation IN ("vente","location")';
} elseif ($fStatut === 'pas_a_vendre') {
    $where[] = '(b.type_commercialisation IS NULL OR b.type_commercialisation NOT IN ("vente","location"))';
}

if ($q !== '') {
    $where[] = '(b.reference_bien LIKE :q1 OR b.designation LIKE :q2
                 OR COALESCE(NULLIF(b.adresse_1,""), i.adresse_1) LIKE :q3
                 OR COALESCE(NULLIF(b.ville,""), i.ville) LIKE :q4)';
    $like = '%' . $q . '%';
    $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like;
}

// Prix courant : bien_prix (is_courant) → prix_demande_initial → prix_vente_estime → annonce.
// Loyer mensuel : bail le plus récent/actif (bien_baux) → loyer_hc.
$sql = "
SELECT
    b.id,
    b.reference_bien,
    b.designation,
    b.surface_habitable,
    b.type_commercialisation,
    b.id_proprietaire,
    -- Propriétaire EFFECTIF : biens.id_proprietaire sinon résolu via CRG (FOCH/SIR/SABY
    -- ont souvent id_proprietaire NULL → on récupère le proprio du dernier CRG du bien).
    COALESCE(b.id_proprietaire,
        (SELECT ct.id_proprietaire FROM crg_situations_locataires cs
           JOIN crg_trimestres ct ON ct.id = cs.id_crg
          WHERE cs.id_bien = b.id ORDER BY ct.annee DESC, ct.trimestre DESC LIMIT 1)
    ) AS eff_proprietaire,
    COALESCE(NULLIF(p.societe,''), NULLIF(CONCAT_WS(' ', p.prenom, p.nom),'')) AS proprio_nom,
    COALESCE(NULLIF(b.adresse_1,''), i.adresse_1)   AS adresse_1,
    COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS code_postal,
    COALESCE(NULLIF(b.ville,''), i.ville)            AS ville,
    COALESCE(
        (SELECT bp.montant FROM bien_prix bp
          WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1
          ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
        b.prix_demande_initial,
        b.prix_vente_estime,
        (SELECT a.prix FROM annonces a WHERE a.id_bien=b.id ORDER BY a.id DESC LIMIT 1)
    ) AS prix_courant,
    COALESCE(
        (SELECT bb.loyer_mensuel_hc FROM bien_baux bb
          WHERE bb.id_bien=b.id
          ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1),
        b.loyer_hc
    ) AS loyer_mensuel,
    -- Statut débiteur : locataires_statuts (marqueur explicite) OU impayés CRG du dernier trimestre.
    (SELECT MAX(CASE WHEN ls.statut IN ('debiteur_actif','irrecoverable') THEN 1 ELSE 0 END)
       FROM locataires_statuts ls WHERE ls.id_bien=b.id AND (ls.archive=0 OR ls.archive IS NULL)) AS ls_debiteur,
    (SELECT SUM(ls.montant_creance) FROM locataires_statuts ls
       WHERE ls.id_bien=b.id AND ls.statut IN ('debiteur_actif','irrecoverable') AND (ls.archive=0 OR ls.archive IS NULL)) AS ls_creance,
    (SELECT cs.total_impaye FROM crg_situations_locataires cs
       JOIN crg_trimestres ct ON ct.id=cs.id_crg
       WHERE cs.id_bien=b.id ORDER BY ct.annee DESC, ct.trimestre DESC, cs.id DESC LIMIT 1) AS crg_impaye,
    (SELECT cs.statut_trimestre FROM crg_situations_locataires cs
       JOIN crg_trimestres ct ON ct.id=cs.id_crg
       WHERE cs.id_bien=b.id ORDER BY ct.annee DESC, ct.trimestre DESC, cs.id DESC LIMIT 1) AS crg_statut,
    -- Nom du locataire : bail courant, sinon dernier CRG.
    COALESCE(
       (SELECT NULLIF(bb.locataire_nom,'') FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.locataire_nom IS NOT NULL AND bb.locataire_nom<>''
          ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1),
       (SELECT NULLIF(cs.locataire_nom,'') FROM crg_situations_locataires cs JOIN crg_trimestres ct ON ct.id=cs.id_crg
          WHERE cs.id_bien=b.id AND cs.locataire_nom IS NOT NULL AND cs.locataire_nom<>''
          ORDER BY ct.annee DESC, ct.trimestre DESC, cs.id DESC LIMIT 1)
    ) AS locataire_nom
FROM biens b
LEFT JOIN immeubles i ON i.id = b.id_immeuble
LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
WHERE " . implode(' AND ', $where) . "
ORDER BY b.id_proprietaire, COALESCE(NULLIF(b.ville,''), i.ville), COALESCE(NULLIF(b.adresse_1,''), i.adresse_1), b.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── Agrégation par propriétaire + KPIs ─────────────────────────────
$groupes      = [];   // id_proprietaire => ['label'=>, 'biens'=>[], 'ca'=>float]
$kpiAVendre   = 0;
$kpiPasVendre = 0;
$kpiPatrimoine = 0.0;
$valAVendre   = 0.0;   // total prix des biens à vendre
$valPasVendre = 0.0;   // total prix des biens pas à vendre
$rdtSum = 0.0; $rdtN = 0;

foreach ($rows as $r) {
    // Propriétaire effectif (CRG si id_proprietaire NULL/désaligné) pour aligner sur le patrimoine
    $realPid = (int)($r['eff_proprietaire'] ?: $r['id_proprietaire']);
    $pid   = $groupKey($realPid);                     // clé de regroupement (fusion SIR)
    $prix  = $r['prix_courant'] !== null ? (float)$r['prix_courant'] : null;
    $surf  = $r['surface_habitable'] !== null ? (float)$r['surface_habitable'] : null;
    $loyer = $r['loyer_mensuel'] !== null ? (float)$r['loyer_mensuel'] : null;

    $aVendre = in_array($r['type_commercialisation'], ['vente', 'location'], true);
    if ($aVendre) { $kpiAVendre++; if ($prix) $valAVendre += $prix; }
    else          { $kpiPasVendre++; if ($prix) $valPasVendre += $prix; }

    $prixM2 = ($prix && $surf && $surf > 0) ? $prix / $surf : null;
    $rdt    = ($prix && $loyer && $prix > 0) ? ($loyer * 12 / $prix) * 100 : null;
    if ($prix) { $kpiPatrimoine += $prix; }
    if ($rdt !== null) { $rdtSum += $rdt; $rdtN++; }

    // Statut locataire : débiteur / à jour / vacant.
    $creance   = ($r['ls_creance'] !== null && (float)$r['ls_creance'] > 0) ? (float)$r['ls_creance'] : null;
    $impaye    = ($r['crg_impaye'] !== null && (float)$r['crg_impaye'] > 0) ? (float)$r['crg_impaye'] : null;
    $estDebiteur = ((int)($r['ls_debiteur'] ?? 0) === 1) || ($r['crg_statut'] === 'parti-débiteur') || ($impaye !== null);
    $estVacant   = ($r['crg_statut'] === 'vacant') || ($loyer === null && !$estDebiteur);
    if ($estDebiteur)      { $locStatut = 'debiteur'; }
    elseif ($estVacant)    { $locStatut = 'vacant'; }
    else                   { $locStatut = 'ajour'; }
    $locMontant = $creance ?? $impaye;

    if (!isset($groupes[$pid])) {
        $groupes[$pid] = [
            'label' => $PERIMETRE[$pid] ?? ((string)($r['proprio_nom'] ?? '') ?: ('Propriétaire #' . $pid)),
            'biens' => [],
            'ca'    => 0.0,
            'av'    => 0,
        ];
    }
    if ($aVendre) { $groupes[$pid]['av']++; }
    $groupes[$pid]['biens'][] = [
        'id'      => (int)$r['id'],
        'ref'     => (string)($r['reference_bien'] ?? ''),
        'desig'   => (string)($r['designation'] ?? ''),
        'surf'    => $surf,
        'ville'   => (string)($r['ville'] ?? ''),
        'cp'      => (string)($r['code_postal'] ?? ''),
        'adresse' => (string)($r['adresse_1'] ?? ''),
        // Nom du vrai propriétaire, affiché s'il diffère du groupe (ex: SIR IMMO, CB FINANCES sous GROUPE SIR).
        'sous_proprio' => ($realPid !== $pid)
            ? (string)($r['proprio_nom'] ?? ($PERIMETRE[$realPid] ?? ''))
            : '',
        'prix'    => $prix,
        'prix_m2' => $prixM2,
        'loyer'   => $loyer,
        'rdt'     => $rdt,
        'aVendre' => $aVendre,
        'locStatut'  => $locStatut,   // debiteur | vacant | ajour
        'locMontant' => $locMontant,  // créance / impayé si débiteur
        'locataire'  => (string)($r['locataire_nom'] ?? ''),
    ];
    if ($prix) { $groupes[$pid]['ca'] += $prix; }
}

// Ordre des groupes : suit l'ordre déclaré du périmètre.
uksort($groupes, function ($a, $b) use ($perimetreIds) {
    return array_search($a, $perimetreIds, true) <=> array_search($b, $perimetreIds, true);
});

$rdtMoyen = $rdtN > 0 ? $rdtSum / $rdtN : null;

// ── Liste propriétaires pour le filtre (limitée au scope visible de l'utilisateur) ───
// On masque les ids fusionnés (ex: SIR IMMO est inclus dans GROUPE SIR).
$propFiltre = [];
foreach ($perimetreIds as $pidv) {
    if (isset($GROUP_MERGE[$pidv])) continue;
    $propFiltre[$pidv] = $PERIMETRE[$pidv] ?? ('Propriétaire #' . $pidv);
}

$pageTitle    = 'Portefeuilles · Admin';
$pageSubtitle = 'Ma Box Agency · Constitution de portefeuilles';
$bodyAttr     = 'data-theme-module="transaction"';

$extraCss = <<<'CSS'
<style>
/* ════════ Portefeuilles V2 — refonte visuelle ════════ */
:root{
  --pf-ink:#2c2a28; --pf-soft:#7a766f; --pf-faint:#a8a39a;
  --pf-bg:#f4f1ec; --pf-line:#ece7df; --pf-blue:#4878a6;
  --pf-green:#2d8a4e; --pf-green-bg:#e3f3e8; --pf-red:#c0453f; --pf-red-bg:#fbe9e8;
  --pf-purple:#6b4aa0; --pf-card:#ffffff;
  --pf-sh:0 1px 2px rgba(44,42,40,.04),0 6px 20px rgba(44,42,40,.06);
}
.pf-page{ max-width:1180px; }

/* En-tête */
.pf-hero{ display:flex; align-items:center; gap:16px; margin-bottom:20px; }
.pf-hero-ic{ width:52px; height:52px; border-radius:16px; display:grid; place-items:center; font-size:24px;
  background:linear-gradient(135deg,#4878a6,#6b4aa0); color:#fff; box-shadow:0 6px 16px rgba(72,120,166,.35); flex:none; }
.pf-hero h1{ margin:0; font-size:22px; color:var(--pf-ink); font-weight:800; letter-spacing:-.01em; }
.pf-hero p{ margin:2px 0 0; font-size:13px; color:var(--pf-soft); }
.pf-hero .spacer{ flex:1; }

.pf-btn{ border-radius:11px; padding:11px 18px; border:1px solid var(--pf-line); background:#fff; color:var(--pf-ink);
  cursor:pointer; font-size:13.5px; font-weight:600; display:inline-flex; align-items:center; gap:7px; text-decoration:none; transition:.15s; }
.pf-btn:hover{ background:var(--pf-bg); transform:translateY(-1px); }
.pf-btn-primary{ background:linear-gradient(135deg,#4878a6,#3d6691); color:#fff; border:none; box-shadow:0 4px 14px rgba(72,120,166,.35); }
.pf-btn-primary:hover{ filter:brightness(1.05); }
.pf-btn-primary:disabled{ background:#c3ccd4; box-shadow:none; cursor:not-allowed; transform:none; filter:none; }
.pf-btn-sm{ padding:7px 12px; font-size:12.5px; border-radius:9px; }

/* KPIs */
.pf-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
.pf-kpi{ position:relative; background:var(--pf-card); border-radius:16px; padding:16px 18px 16px 20px; box-shadow:var(--pf-sh);
  overflow:hidden; display:flex; flex-direction:column; gap:5px; }
.pf-kpi::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--c,#ccc); }
.pf-kpi .ic{ position:absolute; right:14px; top:14px; font-size:22px; opacity:.85; }
.pf-kpi .lbl{ font-size:11px; color:var(--pf-soft); text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
.pf-kpi .val{ font-size:26px; font-weight:800; color:var(--pf-ink); line-height:1.05; letter-spacing:-.02em; }
.pf-kpi .sub{ font-size:11.5px; color:var(--pf-faint); }
.pf-kpi.k-green{ --c:var(--pf-green); } .pf-kpi.k-red{ --c:var(--pf-red); }
.pf-kpi.k-blue{ --c:var(--pf-blue); } .pf-kpi.k-purple{ --c:var(--pf-purple); }

/* Barre filtres */
.pf-filters{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; background:var(--pf-card);
  border-radius:14px; padding:12px 14px; box-shadow:var(--pf-sh); margin-bottom:20px; }
.pf-search{ position:relative; flex:1; min-width:240px; }
.pf-search input{ width:100%; padding:10px 12px 10px 38px; border-radius:10px; border:1px solid var(--pf-line);
  background:var(--pf-bg); font-size:13.5px; }
.pf-search input:focus{ outline:2px solid var(--pf-blue); background:#fff; }
.pf-search .mag{ position:absolute; left:12px; top:50%; transform:translateY(-50%); opacity:.5; }
.pf-filters select{ padding:10px 12px; border-radius:10px; border:1px solid var(--pf-line); background:#fff; font-size:13px; cursor:pointer; }
.pf-filters .sep{ width:1px; align-self:stretch; background:var(--pf-line); margin:0 2px; }

/* Groupe propriétaire */
.pf-group{ background:var(--pf-card); border-radius:16px; box-shadow:var(--pf-sh); margin-bottom:16px; overflow:hidden; }
.pf-group-head{ display:flex; align-items:center; gap:14px; padding:14px 18px; cursor:pointer; user-select:none;
  border-bottom:1px solid transparent; transition:background .15s; }
.pf-group-head:hover{ background:#faf8f5; }
.pf-group:not(.collapsed) .pf-group-head{ border-bottom-color:var(--pf-line); }
.pf-avatar{ width:42px; height:42px; border-radius:12px; flex:none; display:grid; place-items:center;
  font-weight:800; font-size:14px; color:#fff; letter-spacing:.02em;
  background:linear-gradient(135deg,#5b86b0,#7a5ba6); }
.pf-group-id{ flex:1; min-width:0; }
.pf-group-title{ font-weight:800; color:var(--pf-ink); font-size:15.5px; }
.pf-chips{ display:flex; flex-wrap:wrap; gap:7px; margin-top:5px; }
.pf-chip{ font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; background:var(--pf-bg); color:var(--pf-soft); }
.pf-chip.green{ background:var(--pf-green-bg); color:var(--pf-green); }
.pf-chip.blue{ background:#e7eff6; color:var(--pf-blue); }
.pf-caret{ transition:transform .2s; color:var(--pf-faint); font-size:13px; }
.pf-group.collapsed .pf-caret{ transform:rotate(-90deg); }
.pf-group.collapsed .pf-group-body{ display:none; }
.pf-selectall{ display:flex; align-items:center; gap:6px; font-size:12px; color:var(--pf-soft); cursor:pointer; padding:5px 10px;
  border:1px solid var(--pf-line); border-radius:9px; background:#fff; }
.pf-selectall:hover{ background:var(--pf-bg); }
.pf-group-body{ padding:6px 10px 10px; }

/* Carte bien */
.pf-bien{ display:flex; gap:14px; align-items:center; padding:13px 12px; border-radius:12px; transition:background .12s; }
.pf-bien + .pf-bien{ border-top:1px solid var(--pf-line); }
.pf-bien:hover{ background:#faf8f5; }
.pf-main{ flex:1; min-width:0; }
.pf-l1{ font-size:14px; color:var(--pf-ink); display:flex; flex-wrap:wrap; align-items:center; gap:7px; }
.pf-adr{ font-weight:800; }
.pf-open{ color:var(--pf-ink); text-decoration:none; transition:color .12s; }
.pf-open:hover{ color:var(--pf-blue); text-decoration:underline; }
.pf-open .pf-open-ic{ font-size:11px; color:var(--pf-blue); opacity:.65; }
.pf-open:hover .pf-open-ic{ opacity:1; }
.pf-dot{ color:var(--pf-faint); }
.pf-ref{ font-family:'DM Mono',monospace; color:var(--pf-blue); font-weight:700; font-size:12px; background:#eef3f8; padding:1px 7px; border-radius:6px; }
.pf-surf{ font-family:'DM Mono',monospace; font-size:12.5px; color:var(--pf-soft); }
.pf-sousprop{ display:inline-block; background:#efe7f7; color:var(--pf-purple); font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:99px; }
.pf-l2{ display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; align-items:center; }
.pf-stat{ display:flex; flex-direction:column; line-height:1.15; background:var(--pf-bg); border-radius:9px; padding:5px 10px; min-width:74px; }
.pf-stat .k{ font-size:9.5px; color:var(--pf-faint); text-transform:uppercase; letter-spacing:.03em; font-weight:700; }
.pf-stat .v{ font-size:13px; font-weight:700; color:var(--pf-ink); font-family:'DM Mono',monospace; }
.pf-stat .v.muted{ color:var(--pf-faint); font-weight:600; }
.pf-stat.hl{ background:#eef5fb; } .pf-stat.hl .v{ color:var(--pf-blue); }
.pf-stat.rdt .v{ color:var(--pf-green); }

/* Badge locataire (débiteur / à jour / vacant) */
.pf-tenant{ font-size:11.5px; font-weight:700; color:#2c2a28; background:#f0ece4; padding:2px 9px; border-radius:99px; }
.pf-loc{ font-size:11px; font-weight:800; padding:2px 9px; border-radius:99px; }
.pf-loc.deb{ background:var(--pf-red-bg); color:var(--pf-red); }
.pf-loc.ok{ background:var(--pf-green-bg); color:var(--pf-green); }
.pf-loc.vac{ background:var(--pf-bg); color:var(--pf-faint); }

/* Statut : 2 boutons (À vendre / Pas à vendre), inactif à 50% — à 50px du prix */
.pf-status{ flex:none; display:flex; flex-direction:column; gap:7px; margin-right:50px; }
.pf-status.saving{ opacity:.5; pointer-events:none; }
.pf-sbtn{ cursor:pointer; border:1.5px solid; font-family:inherit; font-size:12px; font-weight:800;
  padding:8px 14px; border-radius:10px; white-space:nowrap; text-align:center; transition:.15s; width:128px; }
.pf-sbtn.av{ background:var(--pf-green-bg); color:var(--pf-green); border-color:var(--pf-green); }
.pf-sbtn.nav{ background:var(--pf-red-bg); color:var(--pf-red); border-color:var(--pf-red); }
.pf-sbtn:hover{ filter:brightness(.96); transform:translateY(-1px); }
/* Le non sélectionné : transparent à 50% */
.pf-status[data-av="1"] .pf-sbtn.nav{ opacity:.3; }
.pf-status[data-av="0"] .pf-sbtn.av{ opacity:.3; }
/* Le sélectionné : plein + léger relief */
.pf-status[data-av="1"] .pf-sbtn.av{ box-shadow:0 2px 8px rgba(45,138,78,.28); }
.pf-status[data-av="0"] .pf-sbtn.nav{ box-shadow:0 2px 8px rgba(192,69,63,.28); }

/* Prix portefeuille */
.pf-price{ flex:none; width:160px; background:var(--pf-bg); border-radius:12px; padding:9px 11px; }
.pf-price label{ font-size:9.5px; color:var(--pf-soft); text-transform:uppercase; letter-spacing:.03em; font-weight:700; display:block; margin-bottom:3px; }
.pf-price-row{ display:flex; align-items:center; gap:5px; }
.pf-price input{ width:100%; padding:7px 9px; border-radius:8px; border:1px solid var(--pf-line); text-align:right;
  font-family:'DM Mono',monospace; font-size:14px; font-weight:700; background:#fff; color:var(--pf-ink); }
.pf-price input:focus{ outline:2px solid var(--pf-blue); }
.pf-price .eur{ color:var(--pf-faint); font-weight:700; }
.pf-rdt-live{ font-size:10.5px; color:var(--pf-green); font-family:'DM Mono',monospace; min-height:13px; margin-top:4px; text-align:right; font-weight:700; }

.pf-empty{ padding:60px; text-align:center; color:var(--pf-soft); background:var(--pf-card); border-radius:16px; box-shadow:var(--pf-sh); }
.pf-note{ font-size:12.5px; color:#7d6f3a; background:#fdf8e7; border:1px solid #f0e4b0; border-radius:12px; padding:11px 16px; margin-bottom:18px; display:flex; gap:9px; align-items:flex-start; }

@media (max-width:980px){
  .pf-kpis{ grid-template-columns:repeat(2,1fr); }
  .pf-bien{ flex-wrap:wrap; }
  .pf-price{ width:100%; }
}
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';

/** Échappement court. */
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
/** Euro avec séparateur de milliers par espaces (1 635 000 €). */
$eurSp = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', ' ') . ' €';
?>

<div class="pf-page">

<!-- ── Hero ─────────────────────────────────────────────────────── -->
<div class="pf-hero">
    <div class="pf-hero-ic">📁</div>
    <div>
        <h1>Portefeuilles</h1>
        <p>Sélectionnez des biens et fixez leur prix pour constituer un portefeuille de vente.</p>
    </div>
    <div class="spacer"></div>
    <button type="button" class="pf-btn pf-btn-primary" onclick="pfCreate()">➕ Nouveau portefeuille</button>
</div>

<div class="pf-note">
    <span>ℹ️</span>
    <span>Périmètre verrouillé sur <b><?= $e(count($propFiltre)) ?> bailleurs</b> (SABY, SCI FOCH, GROUPE SIR <small>incl. SIR IMMO</small>, SCI SMH, SIRES, ELYSEE I/II, EVEREST, HIMMALAYA…). La plupart des biens étant en <b>gestion locative</b>, le prix de vente est souvent à renseigner — le champ « prix portefeuille » reste éditable.</span>
</div>

<!-- ── KPIs ─────────────────────────────────────────────────────── -->
<div class="pf-kpis">
    <div class="pf-kpi k-green">
        <span class="ic">✅</span>
        <span class="lbl">Valeur à vendre</span>
        <span class="val"><?= $e($eurSp($valAVendre)) ?></span>
        <span class="sub"><b id="kpi-avendre"><?= (int)$kpiAVendre ?></b> bien(s) à vendre</span>
    </div>
    <div class="pf-kpi k-red">
        <span class="ic">🏠</span>
        <span class="lbl">Valeur pas à vendre</span>
        <span class="val"><?= $e($eurSp($valPasVendre)) ?></span>
        <span class="sub"><b id="kpi-pasvendre"><?= (int)$kpiPasVendre ?></b> bien(s) en gestion</span>
    </div>
    <div class="pf-kpi k-blue">
        <span class="ic">🏛️</span>
        <span class="lbl">Total patrimonial</span>
        <span class="val"><?= $e($eurSp($kpiPatrimoine)) ?></span>
        <span class="sub">sur prix connus</span>
    </div>
    <div class="pf-kpi k-purple">
        <span class="ic">📈</span>
        <span class="lbl">Rendement moyen</span>
        <span class="val"><?= $rdtMoyen !== null ? $e(number_format($rdtMoyen, 1, ',', ' ')) . ' %' : '—' ?></span>
        <span class="sub"><?= (int)$rdtN ?> bien(s) calculable(s)</span>
    </div>
</div>

<!-- ── Filtres (auto-submit) ────────────────────────────────────── -->
<form method="get" action="<?= $e(app_url('/transaction_portefeuilles.php')) ?>" class="pf-filters" id="pf-filters">
    <div class="pf-search">
        <span class="mag">🔎</span>
        <input type="text" name="q" placeholder="Rechercher une adresse, ville, référence…" value="<?= $e($q) ?>" data-autosubmit="text">
    </div>
    <select name="type" data-autosubmit>
        <option value="all"<?= $fType === 'all' ? ' selected' : '' ?>>Type : tous</option>
        <option value="vente"<?= $fType === 'vente' ? ' selected' : '' ?>>Vente</option>
        <option value="location"<?= $fType === 'location' ? ' selected' : '' ?>>Location</option>
    </select>
    <select name="proprietaire_id" data-autosubmit>
        <option value="0">Propriétaire : tous</option>
        <?php foreach ($propFiltre as $pid => $lbl): ?>
            <option value="<?= (int)$pid ?>"<?= $fProprio === (int)$pid ? ' selected' : '' ?>><?= $e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="statut" data-autosubmit>
        <option value="all"<?= $fStatut === 'all' ? ' selected' : '' ?>>Statut : tous</option>
        <option value="a_vendre"<?= $fStatut === 'a_vendre' ? ' selected' : '' ?>>À vendre</option>
        <option value="pas_a_vendre"<?= $fStatut === 'pas_a_vendre' ? ' selected' : '' ?>>Pas à vendre</option>
    </select>
    <div class="sep"></div>
    <a class="pf-btn pf-btn-sm" href="<?= $e(app_url('/transaction_portefeuilles.php')) ?>">↺ Réinit.</a>
    <a class="pf-btn pf-btn-sm pf-btn-primary" style="margin-left:auto" href="<?= $e(app_url('/transaction_portefeuilles_selection.php#dest')) ?>">➕ Créer une liste</a>
</form>

<!-- ── Liste groupée par propriétaire ───────────────────────────── -->
<?php if (empty($groupes)): ?>
    <div class="pf-empty">🔍 Aucun bien ne correspond aux filtres.</div>
<?php else: ?>
    <?php foreach ($groupes as $pid => $g):
        // Initiales pour l'avatar.
        $words = preg_split('/\s+/', trim((string)$g['label']));
        $ini = strtoupper(mb_substr($words[0] ?? '', 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0] ?? '', 1, 1)));
    ?>
        <div class="pf-group" data-group="<?= (int)$pid ?>">
            <div class="pf-group-head" onclick="pfToggleGroup(this)">
                <span class="pf-caret">▼</span>
                <div class="pf-avatar"><?= $e($ini) ?></div>
                <div class="pf-group-id">
                    <div class="pf-group-title"><?= $e($g['label']) ?></div>
                    <div class="pf-chips">
                        <span class="pf-chip"><?= count($g['biens']) ?> bien<?= count($g['biens']) > 1 ? 's' : '' ?></span>
                        <?php if ($g['av'] > 0): ?><span class="pf-chip green">✓ <?= (int)$g['av'] ?> à vendre</span><?php endif; ?>
                        <span class="pf-chip blue">CA <?= $e($eurSp($g['ca'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="pf-group-body">
                <?php foreach ($g['biens'] as $b): ?>
                    <?php
                        $defPrix = $b['prix'] !== null ? (int)round($b['prix']) : '';
                        $adr = trim($b['adresse']); $loc = trim($b['cp'] . ' ' . $b['ville']);
                    ?>
                    <div class="pf-bien" data-bien="<?= (int)$b['id'] ?>">
                        <div class="pf-main">
                            <div class="pf-l1">
                                <a class="pf-adr pf-open" href="<?= $e(app_url('/bien_360.php?id=' . (int)$b['id'])) ?>" target="_blank" rel="noopener" title="Ouvrir la fiche du bien (nouvel onglet)"><?= $e($adr !== '' ? $adr : ($loc !== '' ? $loc : 'Adresse non renseignée')) ?> <span class="pf-open-ic">↗</span></a>
                                <?php if ($adr !== '' && $loc !== ''): ?><span class="pf-dot">·</span> <span style="color:var(--pf-soft);font-size:13px;"><?= $e($loc) ?></span><?php endif; ?>
                                <?php if ($b['ref'] !== ''): ?> <span class="pf-ref"><?= $e($b['ref']) ?></span><?php endif; ?>
                                <?php if ($b['surf']): ?> <span class="pf-surf"><?= $e(fmt_m2($b['surf'], 0)) ?></span><?php endif; ?>
                                <?php if ($b['desig'] !== ''): ?> <span class="pf-dot">·</span> <span style="color:var(--pf-soft);font-size:12.5px;"><?= $e($b['desig']) ?></span><?php endif; ?>
                                <?php if ($b['sous_proprio'] !== ''): ?> <span class="pf-sousprop"><?= $e($b['sous_proprio']) ?></span><?php endif; ?>
                                <?php if ($b['locataire'] !== ''): ?> <span class="pf-tenant" title="Locataire">👤 <?= $e($b['locataire']) ?></span><?php endif; ?>
                                <?php if ($b['locStatut'] === 'debiteur'): ?>
                                    <span class="pf-loc deb">⚠ Débiteur<?= $b['locMontant'] ? ' · ' . $e($eurSp($b['locMontant'])) : '' ?></span>
                                <?php elseif ($b['locStatut'] === 'ajour'): ?>
                                    <span class="pf-loc ok">● Locataire à jour</span>
                                <?php else: ?>
                                    <span class="pf-loc vac">○ Vacant</span>
                                <?php endif; ?>
                            </div>
                            <div class="pf-l2">
                                <div class="pf-stat hl">
                                    <span class="k">Prix courant</span>
                                    <span class="v <?= $b['prix'] === null ? 'muted' : '' ?>"><?= $b['prix'] !== null ? $e($eurSp($b['prix'])) : '—' ?></span>
                                </div>
                                <div class="pf-stat">
                                    <span class="k">Prix / m²</span>
                                    <span class="v <?= $b['prix_m2'] === null ? 'muted' : '' ?>"><?= $b['prix_m2'] !== null ? $e($eurSp($b['prix_m2'])) : '—' ?></span>
                                </div>
                                <div class="pf-stat">
                                    <span class="k">Loyer / mois</span>
                                    <span class="v <?= $b['loyer'] === null ? 'muted' : '' ?>"><?= $b['loyer'] !== null ? $e($eurSp($b['loyer'])) : '—' ?></span>
                                </div>
                                <div class="pf-stat rdt">
                                    <span class="k">Rendement</span>
                                    <span class="v <?= $b['rdt'] === null ? 'muted' : '' ?>"><?= $b['rdt'] !== null ? $e(number_format($b['rdt'], 1, ',', ' ')) . ' %' : '—' ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="pf-status" data-id="<?= (int)$b['id'] ?>" data-av="<?= $b['aVendre'] ? 1 : 0 ?>">
                            <button type="button" class="pf-sbtn av" onclick="pfSetStatut(this,1)">✓ À vendre</button>
                            <button type="button" class="pf-sbtn nav" onclick="pfSetStatut(this,0)">✕ Pas à vendre</button>
                        </div>
                        <div class="pf-price">
                            <label>Prix portefeuille</label>
                            <div class="pf-price-row">
                                <input type="text" inputmode="numeric" class="pf-price-input"
                                       data-id="<?= (int)$b['id'] ?>"
                                       data-loyer="<?= $b['loyer'] !== null ? (int)round($b['loyer']) : 0 ?>"
                                       value="<?= $defPrix !== '' ? $e(number_format((int)$defPrix, 0, ',', ' ')) : '' ?>"
                                       placeholder="— — —"
                                       oninput="pfOnPrice(this)" onblur="pfFmtPrice(this)">
                                <span class="eur">€</span>
                            </div>
                            <div class="pf-rdt-live" id="rdt-<?= (int)$b['id'] ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /.pf-page -->

<script>
const PF_CSRF = <?= json_encode(csrf_token('portefeuille_statut')) ?>;

// ── Fixer le statut à vendre / pas à vendre (2 boutons, persisté) ──
async function pfSetStatut(btn, target) {
    const box = btn.closest('.pf-status');
    const id = box.dataset.id;
    if (box.dataset.av === String(target)) return;   // déjà cet état
    const prev = parseInt(box.dataset.av, 10);
    box.classList.add('saving');
    try {
        const fd = new FormData();
        fd.append('id_bien', id);
        fd.append('a_vendre', String(target));
        const res = await fetch(<?= json_encode(app_url('/api/portefeuille_bien_statut.php')) ?>, {
            method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': PF_CSRF }, body: fd
        });
        if (res.redirected && /login\.php/.test(res.url)) { alert('Session expirée — reconnectez-vous puis réessayez.'); return; }
        let j;
        try { j = await res.json(); }
        catch (_) { alert('Réponse inattendue du serveur (HTTP ' + res.status + '). Rechargez la page (Ctrl+F5).'); return; }
        if (!j.success) { alert(j.message || 'Erreur lors du changement de statut.'); return; }
        box.dataset.av = String(target);   // CSS bascule l'opacité des 2 boutons
        // MAJ des KPIs (delta)
        const av = document.getElementById('kpi-avendre');
        const nav = document.getElementById('kpi-pasvendre');
        if (av && nav && prev !== target) {
            const d = target === 1 ? 1 : -1;
            av.textContent = (parseInt(av.textContent, 10) || 0) + d;
            nav.textContent = (parseInt(nav.textContent, 10) || 0) - d;
        }
    } catch (e) {
        alert('Erreur réseau — statut non modifié.');
    } finally {
        box.classList.remove('saving');
    }
}

// ── Auto-submit des filtres ───────────────────────────────────────
(function () {
    const form = document.getElementById('pf-filters');
    if (!form) return;
    form.querySelectorAll('[data-autosubmit]').forEach(el => {
        if (el.dataset.autosubmit === 'text') {
            let t;
            el.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => form.submit(), 450); });
        } else {
            el.addEventListener('change', () => form.submit());
        }
    });
})();

// ── Format € FR ───────────────────────────────────────────────────
function pfEuro(v) {
    return (Math.round(v) || 0).toLocaleString('fr-FR') + ' €';
}

// ── Recalcul rendement live à partir du prix saisi ────────────────
function pfPriceVal(input){ return parseFloat(String(input.value).replace(/\s/g,'').replace(',','.')) || 0; }
function pfOnPrice(input) {
    const loyer = parseFloat(input.dataset.loyer) || 0;
    const prix  = pfPriceVal(input);
    const out   = document.getElementById('rdt-' + input.dataset.id);
    if (out) {
        out.textContent = (loyer > 0 && prix > 0)
            ? 'Renta : ' + ((loyer * 12 / prix) * 100).toFixed(1).replace('.', ',') + ' %'
            : '';
    }
}
// Reformate avec espaces de milliers à la sortie du champ
function pfFmtPrice(input) {
    const v = pfPriceVal(input);
    input.value = v > 0 ? Math.round(v).toLocaleString('fr-FR') : '';
}

function pfToggleGroup(head) { head.closest('.pf-group').classList.toggle('collapsed'); }

// ── Création portefeuille → page de sélection (étape suivante) ─────
function pfCreate() {
    window.location.href = 'transaction_portefeuilles_selection.php';
}

// Init rendement live au chargement
document.querySelectorAll('.pf-price-input').forEach(pfOnPrice);
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
