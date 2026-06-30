<?php
// transaction_portefeuilles_selection.php — Constituer un portefeuille de vente.
// Sélection des biens « à vendre », calcul multi-sens (prix/m² ⇄ prix ⇄ rentabilité),
// honoraires (% charge acquéreur) + net vendeur, puis enregistrement (portefeuilles).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

require_once __DIR__ . '/inc/portefeuille_scope.php';
$scope        = pf_scope($pdo);
$PERIMETRE    = $scope['perimetre'];
$GROUP_MERGE  = $scope['merge'];
$perimetreIds = $scope['ids'];      // règle d'or : un bailleur ne voit que ses propriétaires
$groupKey     = fn(int $pid) => $GROUP_MERGE[$pid] ?? $pid;

// Mode reprise : ?portefeuille=ID → on charge un portefeuille existant pour le modifier.
$loadId = (int)($_GET['portefeuille'] ?? 0);

// Module en iframe (hub) : propage embed + « voir en tant que » bailleur sur les liens internes.
$embed      = (int)($_GET['embed'] ?? 0);
$bailleurId = (int)($_GET['bailleur'] ?? 0);
$navQS      = ($embed ? '&embed=1' : '') . ($bailleurId > 0 ? '&bailleur=' . $bailleurId : '');
$listUrl    = app_url('/transaction_portefeuilles_liste.php' . ($navQS ? '?' . ltrim($navQS, '&') : ''));
$newUrl     = app_url('/transaction_portefeuilles_selection.php' . ($navQS ? '?' . ltrim($navQS, '&') : ''));

// Filtres
$q        = trim((string)($_GET['q'] ?? ''));
$fProprio = isset($_GET['proprietaire_id']) ? (int)$_GET['proprietaire_id'] : 0;
$fScope   = (string)($_GET['scope'] ?? 'tous');   // portefeuille = patrimoine actif complet (tous), pas seulement "à vendre"
// En reprise, on montre tout le périmètre pour retrouver tous les biens enregistrés.
if ($loadId > 0) { $fScope = 'tous'; $fProprio = 0; $q = ''; }

$scopeIds = $perimetreIds;
if ($fProprio > 0 && in_array($fProprio, $perimetreIds, true)) {
    $scopeIds = array_merge([$fProprio], array_keys($GROUP_MERGE, $fProprio, true));
}
$inScope = !empty($scopeIds) ? implode(',', array_map('intval', $scopeIds)) : '0';

$where  = ["b.id_proprietaire IN ($inScope)", '(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))', 'b.a_proposer = 1'];
$params = [];
if ($fScope !== 'tous') {
    $where[] = 'b.type_commercialisation IN ("vente","location")';
}
if ($q !== '') {
    $where[] = '(b.reference_bien LIKE :q1 OR b.designation LIKE :q2 OR COALESCE(NULLIF(b.adresse_1,""), i.adresse_1) LIKE :q3 OR COALESCE(NULLIF(b.ville,""), i.ville) LIKE :q4)';
    $like = '%' . $q . '%';
    $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like;
}

// Filtres surface + type (WHERE) et prix (HAVING, car prix_courant est calculé)
$num = fn($k) => (isset($_GET[$k]) && $_GET[$k] !== '') ? (float)str_replace([' ', ','], ['', '.'], (string)$_GET[$k]) : null;
$fSurfMin = $num('surf_min'); $fSurfMax = $num('surf_max');
$fPrixMin = $num('prix_min'); $fPrixMax = $num('prix_max');
$fType    = (string)($_GET['type'] ?? '');   // habitation | pro
if ($loadId > 0) { $fSurfMin = $fSurfMax = $fPrixMin = $fPrixMax = null; $fType = ''; }

if ($fSurfMin !== null) { $where[] = 'COALESCE(b.surface_habitable, b.surface_carrez) >= :sfmin'; $params[':sfmin'] = $fSurfMin; }
if ($fSurfMax !== null) { $where[] = 'COALESCE(b.surface_habitable, b.surface_carrez) <= :sfmax'; $params[':sfmax'] = $fSurfMax; }
if ($fType === 'habitation') {
    $where[] = "LOWER(COALESCE(NULLIF(tb.categorie,''), b.usage_bien)) = 'habitation'";
} elseif ($fType === 'pro') {
    $where[] = "(COALESCE(NULLIF(tb.categorie,''), b.usage_bien) IS NULL OR LOWER(COALESCE(NULLIF(tb.categorie,''), b.usage_bien)) <> 'habitation')";
}
$having = [];
if ($fPrixMin !== null) { $having[] = 'prix_courant >= :pxmin'; $params[':pxmin'] = $fPrixMin; }
if ($fPrixMax !== null) { $having[] = 'prix_courant <= :pxmax'; $params[':pxmax'] = $fPrixMax; }
$havingSql = $having ? "\nHAVING " . implode(' AND ', $having) : '';

$sql = "
SELECT b.id, b.reference_bien, b.designation, COALESCE(b.surface_habitable, b.surface_carrez) AS surface_habitable, b.type_commercialisation, b.id_proprietaire,
    COALESCE(NULLIF(tb.categorie,''), b.usage_bien) AS categorie, tb.libelle AS type_libelle,
    COALESCE(NULLIF(p.societe,''), NULLIF(CONCAT_WS(' ', p.prenom, p.nom),'')) AS proprio_nom,
    COALESCE(NULLIF(b.adresse_1,''), i.adresse_1)   AS adresse_1,
    COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS code_postal,
    COALESCE(NULLIF(b.ville,''), i.ville)            AS ville,
    COALESCE(
        (SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
        b.prix_demande_initial, b.prix_vente_estime,
        (SELECT a.prix FROM annonces a WHERE a.id_bien=b.id ORDER BY a.id DESC LIMIT 1)
    ) AS prix_courant,
    COALESCE(
        (SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1),
        b.loyer_hc
    ) AS loyer_mensuel,
    (SELECT NULLIF(TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)),'')
       FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif' ORDER BY bb.id DESC LIMIT 1) AS locataire_nom,
    (SELECT MAX(CASE WHEN ls.statut IN ('debiteur_actif','irrecoverable') THEN 1 ELSE 0 END)
       FROM locataires_statuts ls WHERE ls.id_bien=b.id AND (ls.archive=0 OR ls.archive IS NULL)) AS ls_debiteur,
    (SELECT SUM(ls.montant_creance) FROM locataires_statuts ls
       WHERE ls.id_bien=b.id AND ls.statut IN ('debiteur_actif','irrecoverable') AND (ls.archive=0 OR ls.archive IS NULL)) AS ls_creance,
    (SELECT cs.total_impaye FROM crg_situations_locataires cs JOIN crg_trimestres ct ON ct.id=cs.id_crg
       WHERE cs.id_bien=b.id ORDER BY ct.annee DESC, ct.trimestre DESC, cs.id DESC LIMIT 1) AS crg_impaye
FROM biens b
LEFT JOIN immeubles i ON i.id = b.id_immeuble
LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
WHERE " . implode(' AND ', $where) . $havingSql . "
ORDER BY b.id_proprietaire, COALESCE(NULLIF(b.ville,''), i.ville), COALESCE(NULLIF(b.adresse_1,''), i.adresse_1), b.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groupes = [];
foreach ($rows as $r) {
    $pid = $groupKey((int)$r['id_proprietaire']);
    if (!isset($groupes[$pid])) {
        $groupes[$pid] = ['label' => $PERIMETRE[$pid] ?? ((string)($r['proprio_nom'] ?? '') ?: ('Propriétaire #' . $pid)), 'biens' => []];
    }
    $prix  = $r['prix_courant'] !== null ? (float)$r['prix_courant'] : null;
    $surf  = $r['surface_habitable'] !== null ? (float)$r['surface_habitable'] : null;
    $loyer = $r['loyer_mensuel'] !== null ? (float)$r['loyer_mensuel'] : null;
    $groupes[$pid]['biens'][] = [
        'id' => (int)$r['id'], 'ref' => (string)($r['reference_bien'] ?? ''),
        'adresse' => (string)($r['adresse_1'] ?? ''), 'cp' => (string)($r['code_postal'] ?? ''),
        'ville' => (string)($r['ville'] ?? ''), 'surf' => $surf, 'prix' => $prix,
        'loyer' => $loyer, 'loyer_an' => $loyer !== null ? $loyer * 12 : 0,
        'locataire' => (string)($r['locataire_nom'] ?? ''),
        'aVendre' => in_array($r['type_commercialisation'], ['vente', 'location'], true),
        'categorie' => (string)($r['categorie'] ?? ''), 'type_libelle' => (string)($r['type_libelle'] ?? ''),
        'debiteur' => ((int)($r['ls_debiteur'] ?? 0) === 1) || ($r['crg_impaye'] !== null && (float)$r['crg_impaye'] > 0),
        'creance' => ($r['ls_creance'] !== null && (float)$r['ls_creance'] > 0) ? (float)$r['ls_creance']
                     : (($r['crg_impaye'] !== null && (float)$r['crg_impaye'] > 0) ? (float)$r['crg_impaye'] : null),
    ];
}
uksort($groupes, fn($a, $b) => array_search($a, $perimetreIds, true) <=> array_search($b, $perimetreIds, true));
$propFiltre = [];
foreach ($perimetreIds as $pidv) { if (isset($GROUP_MERGE[$pidv])) continue; $propFiltre[$pidv] = $PERIMETRE[$pidv] ?? ('Propriétaire #' . $pidv); }
$nbBiens = count($rows);

// ── Documents GED déjà chargés par bien (pour la checklist « à joindre ») ──────
// On n'affiche QUE les docs réellement présents. Détection du libellé métier par
// document_type (DPE, Bail, Diagnostics…) ; les autres gardent leur nom GED.
$docsByBien = [];
$bienIds = [];
foreach ($groupes as $g) { foreach ($g['biens'] as $bb) { $bienIds[(int)$bb['id']] = true; } }
$bienIds = array_keys($bienIds);
if ($bienIds) {
    // Libellé métier déduit du document_type (sinon nom GED brut).
    $docLabel = static function (?string $t): ?string {
        $t = strtoupper(trim((string)$t));
        if ($t === '') return null;
        if (str_contains($t, 'DPE')) return 'DPE';
        if (str_contains($t, 'BAIL')) return 'Bail';
        if (str_contains($t, 'ERP') || str_contains($t, 'ERNMT') || str_contains($t, 'DIAG')) return 'Diagnostics / DDT';
        if (str_contains($t, 'PLAN')) return 'Plan';
        if (str_contains($t, 'TAXE') || str_contains($t, 'FONCIER')) return 'Taxe foncière';
        if (str_contains($t, 'CARREZ') || str_contains($t, 'SURFACE') || str_contains($t, 'BOUTIN')) return 'Surface';
        if (str_contains($t, 'LOCATIF') || $t === 'ETAT_LOCATIF') return 'État locatif';
        return null;
    };
    try {
        $ph = implode(',', array_fill(0, count($bienIds), '?'));
        // Direct (gd.id_bien) + via liens (ged_document_links) → dédoublonné par doc id.
        $sqlDocs = "
            SELECT bid, id, name_display, document_type FROM (
                SELECT gd.id_bien AS bid, gd.id, gd.name_display, gd.document_type
                  FROM ged_documents gd
                 WHERE gd.status='active' AND gd.id_bien IN ($ph)
                UNION
                SELECT gdl.entity_id AS bid, gd.id, gd.name_display, gd.document_type
                  FROM ged_document_links gdl
                  JOIN ged_documents gd ON gd.id = gdl.document_id
                 WHERE gd.status='active' AND gdl.entity_type='BIEN' AND gdl.entity_id IN ($ph)
            ) t
            ORDER BY bid, id";
        $stDocs = $pdo->prepare($sqlDocs);
        $stDocs->execute(array_merge($bienIds, $bienIds));
        foreach ($stDocs->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $bid = (int)$d['bid'];
            $did = (int)$d['id'];
            if (isset($docsByBien[$bid][$did])) continue;
            $label = $docLabel($d['document_type']) ?? (trim((string)$d['name_display']) ?: 'Document');
            $docsByBien[$bid][$did] = ['id' => $did, 'label' => $label, 'type' => (string)($d['document_type'] ?? '')];
        }
    } catch (Throwable $e) { $docsByBien = []; }
}

// Mode reprise : charge le portefeuille existant (entête + valeurs par bien) pour pré-remplissage JS.
$loaded = null;
if ($loadId > 0) {
    $pfh = $pdo->prepare("SELECT id, nom, type_destinataire, destinataire_nom, destinataire_prenom, destinataire_email, destinataire_tel, critere_prix_min, critere_prix_max, critere_surf_min, critere_surf_max, critere_type FROM portefeuilles WHERE id = ?");
    $pfh->execute([$loadId]);
    if ($pf = $pfh->fetch(PDO::FETCH_ASSOC)) {
        $bl = $pdo->prepare("SELECT id_bien, prix_vente, prix_m2, rendement, honoraires_pct, honoraires_montant, net_vendeur, droits_mutation_pct, droits_mutation_montant, prix_acte_en_main FROM portefeuille_biens WHERE id_portefeuille = ?");
        $bl->execute([$loadId]);
        $bmap = [];
        foreach ($bl as $r) {
            $bmap[(int)$r['id_bien']] = [
                'pv' => $r['prix_vente'], 'pm' => $r['prix_m2'], 'rt' => $r['rendement'],
                'hp' => $r['honoraires_pct'], 'hm' => $r['honoraires_montant'], 'nv' => $r['net_vendeur'],
                'dmp' => $r['droits_mutation_pct'], 'dm' => $r['droits_mutation_montant'], 'aem' => $r['prix_acte_en_main'],
                'dj'  => null,
            ];
        }
        // Sélection de docs joints : lecture SÉPARÉE et TOLÉRANTE (la colonne peut
        // ne pas exister si la migration 20260623 n'est pas encore appliquée → on n'échoue pas).
        try {
            $dj = $pdo->prepare("SELECT id_bien, documents_joints FROM portefeuille_biens WHERE id_portefeuille = ? AND documents_joints IS NOT NULL");
            $dj->execute([$loadId]);
            foreach ($dj as $r) {
                $bid = (int)$r['id_bien'];
                if (isset($bmap[$bid])) $bmap[$bid]['dj'] = json_decode((string)$r['documents_joints'], true) ?: [];
            }
        } catch (Throwable $e) { /* colonne absente : on ignore, comportement historique */ }
        $loaded = ['id' => (int)$pf['id'], 'nom' => $pf['nom'], 'type' => $pf['type_destinataire'],
            'dest' => $pf['destinataire_nom'], 'prenom' => $pf['destinataire_prenom'], 'email' => $pf['destinataire_email'], 'tel' => $pf['destinataire_tel'],
            'pxmin' => $pf['critere_prix_min'], 'pxmax' => $pf['critere_prix_max'], 'sfmin' => $pf['critere_surf_min'], 'sfmax' => $pf['critere_surf_max'],
            'type_bien' => $pf['critere_type'] ?? null,
            'biens' => $bmap];
    }
}

$pageTitle    = 'Constituer un portefeuille';
$pageSubtitle = 'Ma Box Agency · Portefeuilles';
$bodyAttr     = 'data-theme-module="transaction"';
$extraCss = <<<'CSS'
<style>
:root{ --pf-ink:#2c2a28; --pf-soft:#7a766f; --pf-faint:#a8a39a; --pf-bg:#f4f1ec; --pf-line:#ece7df;
  --pf-blue:#4878a6; --pf-green:#2d8a4e; --pf-green-bg:#e3f3e8; --pf-red:#c0453f; --pf-red-bg:#fbe9e8;
  --pf-purple:#6b4aa0; --pf-amber:#a8741d; --pf-sh:0 1px 2px rgba(44,42,40,.04),0 6px 20px rgba(44,42,40,.06); }
/* Masque les boutons « Charger » (upload) sur cette page : ils gênent la lecture du bandeau bas.
   - #fbx-upload-open  = bouton de la topbar
   - #fbx-upload-fab   = bouton flottant en bas à droite (chevauchait « Enregistrer ») */
#fbx-upload-open, #fbx-upload-fab{ display:none !important; }
.pf-page{ max-width:1280px; padding-bottom:180px; }
.pf-hero{ display:flex; align-items:center; gap:16px; margin-bottom:18px; }
.pf-hero-ic{ width:52px; height:52px; border-radius:16px; display:grid; place-items:center; font-size:24px;
  background:linear-gradient(135deg,#4878a6,#6b4aa0); color:#fff; box-shadow:0 6px 16px rgba(72,120,166,.35); flex:none; }
.pf-hero h1{ margin:0; font-size:22px; color:var(--pf-ink); font-weight:800; }
.pf-hero p{ margin:2px 0 0; font-size:13px; color:var(--pf-soft); }
.pf-hero .spacer{ flex:1; }
.pf-btn{ border-radius:11px; padding:11px 18px; border:1px solid var(--pf-line); background:#fff; color:var(--pf-ink);
  cursor:pointer; font-size:13.5px; font-weight:600; display:inline-flex; align-items:center; gap:7px; text-decoration:none; transition:.15s; }
.pf-btn:hover{ background:var(--pf-bg); }
.pf-btn-primary{ background:linear-gradient(135deg,#2d8a4e,#23703f); color:#fff; border:none; box-shadow:0 4px 14px rgba(45,138,78,.32); }
.pf-btn-primary:disabled{ background:#bcd0c2; box-shadow:none; cursor:not-allowed; }
.pf-btn-sm{ padding:8px 13px; font-size:12.5px; border-radius:9px; }

/* Top bar regroupée : titre + honoraires + filtres */
.pf-topbar{ background:#fff; border-radius:14px; box-shadow:var(--pf-sh); padding:12px 14px; margin-bottom:18px; display:flex; flex-direction:column; gap:10px; position:sticky; top:0; z-index:30; }
.pf-tb-row{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.pf-topbar .pf-hero-ic{ width:40px; height:40px; font-size:20px; border-radius:12px; }
.pf-tb-lbl{ font-size:13px; font-weight:800; color:var(--pf-ink); white-space:nowrap; }
.pf-tb-title{ flex:1; min-width:240px; padding:10px 14px; border-radius:10px; border:1px solid var(--pf-line); background:var(--pf-bg); font-size:15px; font-weight:700; color:var(--pf-ink); }
.pf-tb-title:focus{ outline:2px solid var(--pf-blue); background:#fff; }
.pf-filters{ display:flex; flex-wrap:wrap; gap:8px; align-items:center; border-top:1px solid var(--pf-line); padding-top:10px; }
.pf-search{ position:relative; flex:1; min-width:220px; }
.pf-search input{ width:100%; padding:10px 12px 10px 36px; border-radius:10px; border:1px solid var(--pf-line); background:var(--pf-bg); font-size:13.5px; }
.pf-search .mag{ position:absolute; left:11px; top:50%; transform:translateY(-50%); opacity:.5; }
.pf-filters select{ padding:10px 12px; border-radius:10px; border:1px solid var(--pf-line); background:#fff; font-size:13px; cursor:pointer; }
.pf-frange{ display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--pf-soft); font-weight:700; background:var(--pf-bg); border:1px solid var(--pf-line); border-radius:10px; padding:5px 10px; }
.pf-frange input{ width:64px; padding:6px 8px; border-radius:7px; border:1px solid var(--pf-line); background:#fff; font-size:12.5px; text-align:right; font-family:'DM Mono',monospace; }
.pf-frange .dash{ color:var(--pf-faint); }

.pf-group{ background:#fff; border-radius:16px; box-shadow:var(--pf-sh); margin-bottom:16px; overflow:hidden; }
.pf-group-head{ display:flex; align-items:center; gap:13px; padding:13px 18px; cursor:pointer; user-select:none; }
.pf-group-head:hover{ background:#faf8f5; }
.pf-avatar{ width:40px; height:40px; border-radius:11px; flex:none; display:grid; place-items:center; font-weight:800; font-size:13px; color:#fff; background:linear-gradient(135deg,#5b86b0,#7a5ba6); }
.pf-group-title{ font-weight:800; color:var(--pf-ink); font-size:15px; flex:1; }
.pf-chip{ font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; background:var(--pf-bg); color:var(--pf-soft); }
.pf-chip.green{ background:var(--pf-green-bg); color:var(--pf-green); }
.pf-caret{ transition:transform .2s; color:var(--pf-faint); }
.pf-group.collapsed .pf-caret{ transform:rotate(-90deg); }
.pf-group.collapsed .pf-group-body{ display:none; }
.pf-group-body{ padding:6px 12px 12px; }

.pf-bien{ border-radius:12px; padding:13px; transition:background .12s; }
.pf-bien + .pf-bien{ border-top:1px solid var(--pf-line); }
.pf-bien.on{ background:#eef5fb; box-shadow:inset 3px 0 0 var(--pf-blue); }
.pf-bhead{ display:flex; align-items:center; gap:12px; }
.pf-btn360{ flex:none; font-size:12px; font-weight:700; color:var(--pf-blue); background:#eef3f8; border:1px solid #cfe0ee; border-radius:9px; padding:7px 12px; text-decoration:none; white-space:nowrap; transition:.12s; }
.pf-btn360:hover{ background:var(--pf-blue); color:#fff; }
.pf-btnrm{ flex:none; font-size:12px; font-weight:700; color:var(--pf-red); background:#fbe9e8; border:1px solid #e6b3b0; border-radius:9px; padding:7px 12px; cursor:pointer; white-space:nowrap; transition:.12s; }
.pf-btnrm:hover{ background:var(--pf-red); color:#fff; }
.pf-check input{ width:20px; height:20px; cursor:pointer; accent-color:var(--pf-green); }
.pf-binfo{ flex:1; min-width:0; }
.pf-l1{ font-size:14px; display:flex; flex-wrap:wrap; align-items:center; gap:7px; }
.pf-adr{ font-weight:800; color:var(--pf-ink); text-decoration:none; }
.pf-adr:hover{ color:var(--pf-blue); text-decoration:underline; }
.pf-adr .ic{ font-size:11px; color:var(--pf-blue); opacity:.6; }
.pf-ref{ font-family:'DM Mono',monospace; color:var(--pf-blue); font-weight:700; font-size:12px; background:#eef3f8; padding:1px 7px; border-radius:6px; }
.pf-surf{ font-family:'DM Mono',monospace; font-size:12.5px; color:var(--pf-soft); }
.pf-loc{ font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:99px; }
.pf-loc.deb{ background:var(--pf-red-bg); color:var(--pf-red); } .pf-loc.ok{ background:var(--pf-green-bg); color:var(--pf-green); }
.pf-badge-av{ font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:99px; background:var(--pf-green-bg); color:var(--pf-green); }
.pf-badge-nav{ font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:99px; background:var(--pf-bg); color:var(--pf-faint); }

/* Calculateur */
.pf-calc{ display:grid; grid-template-columns:repeat(6,1fr); gap:8px; margin-top:12px; padding-left:32px; }
.pf-fld{ background:var(--pf-bg); border-radius:10px; padding:7px 9px; }
.pf-fld label{ font-size:9.5px; color:var(--pf-soft); text-transform:uppercase; letter-spacing:.03em; font-weight:700; display:block; margin-bottom:3px; }
.pf-fld .inrow{ display:flex; align-items:center; gap:4px; }
.pf-fld input{ width:100%; border:1px solid var(--pf-line); border-radius:7px; padding:6px 7px; text-align:right; font-family:'DM Mono',monospace; font-size:13px; font-weight:700; background:#fff; color:var(--pf-ink); }
.pf-fld input:focus{ outline:2px solid var(--pf-blue); }
/* Documents à joindre (uniquement ceux déjà chargés en GED) */
.pf-docs{ display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:10px; padding-left:32px; }
.pf-docs-lbl{ font-size:11px; font-weight:800; color:var(--pf-soft); text-transform:uppercase; letter-spacing:.03em; }
.pf-doc{ display:inline-flex; align-items:center; gap:6px; background:var(--pf-green-bg); color:var(--pf-green);
  border:1px solid #bfe3cc; border-radius:8px; padding:4px 10px; font-size:12.5px; font-weight:700; cursor:pointer; }
.pf-doc input{ accent-color:var(--pf-green); cursor:pointer; }
.pf-fld .u{ font-size:11px; color:var(--pf-faint); font-weight:700; }
/* Prix de vente = doré retenu (mis en avant) */
.pf-fld.k-pv{ background:#f7edd4; border:1.5px solid #d9b85a; }
.pf-fld.k-pv label{ color:#8a6a12; font-weight:800; }
.pf-fld.k-pv input{ color:#7a5c08; font-size:15px; font-weight:800; background:#fffdf5; border-color:#d9b85a; }
.pf-fld.k-pv .u{ color:#a8851f; }
/* Honoraires = bleu pétrole */
.pf-fld.k-ho{ background:#dff0f1; border:1.5px solid #6fb3ba; }
.pf-fld.k-ho label{ color:#0e6b75; font-weight:800; }
.pf-fld.k-ho input{ color:#0b5860; font-weight:800; background:#f3fbfb; border-color:#6fb3ba; }
.pf-fld.k-ho .u{ color:#0e6b75; }
/* Net vendeur = doré 100% (le résultat retenu, le plus visible) */
.pf-fld.k-nv{ background:#f6d873; border:1.5px solid #c79a1f; }
.pf-fld.k-nv label{ color:#6b4f06; font-weight:800; }
.pf-fld.k-nv input{ color:#5c4404; font-size:15px; font-weight:800; background:#fffdf2; border-color:#c79a1f; }
.pf-fld.k-nv .u{ color:#8a6a12; }
.pf-fld.k-rt input{ color:var(--pf-purple); }
/* Droits de mutation (gris-orangé) + Prix acte en main (ardoise, mis en avant pour le pro) */
.pf-fld.k-dm{ background:#f3ece2; border:1px solid #d9c3a6; }
.pf-fld.k-dm label{ color:#8a6a3a; font-weight:700; }
.pf-fld.k-dm input{ color:#7a5a2e; border-color:#d9c3a6; }
.pf-fld.k-aem{ background:#e7ecf2; border:1.5px solid #8aa0bb; }
.pf-fld.k-aem label{ color:#3a4f6b; font-weight:800; }
.pf-fld.k-aem input{ color:#2c4360; font-size:15px; font-weight:800; background:#fbfcfe; border-color:#8aa0bb; }

/* Barre d'enregistrement sticky */
.pf-savebar{ position:fixed; left:248px; right:0; bottom:0; z-index:60; background:linear-gradient(135deg,#322f2c,#221f1d); color:#fff;
  padding:10px 18px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; box-shadow:0 -8px 28px rgba(0,0,0,.3); }
@media (max-width:900px){ .pf-savebar{ left:0; } }
.pf-savebar .sb{ display:flex; flex-direction:column; }
.pf-savebar .sb .k{ font-size:9.5px; text-transform:uppercase; letter-spacing:.05em; color:#b8b2ab; font-weight:700; }
.pf-savebar .sb .v{ font-size:17px; font-weight:800; font-family:'DM Mono',monospace; }
.pf-savebar input, .pf-savebar select{ padding:8px 11px; border-radius:9px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff; font-size:13px; }
.pf-savebar input::placeholder{ color:#b8b2ab; }
/* Panneau destinataire */
.pf-destpanel{ background:#fff; border-radius:16px; box-shadow:var(--pf-sh); padding:16px 18px; margin-top:18px; }
.pf-destpanel h3{ margin:0 0 12px; font-size:15px; color:var(--pf-ink); }
.pf-destpanel h3 small{ font-weight:500; color:var(--pf-soft); font-size:12px; }
.pf-destgrid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
.pf-destgrid .fd label{ display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.03em; font-weight:700; color:var(--pf-soft); margin-bottom:4px; }
.pf-destgrid .fd input, .pf-destgrid .fd select{ width:100%; padding:9px 11px; border-radius:9px; border:1px solid var(--pf-line); background:var(--pf-bg); font-size:13.5px; }
.pf-destgrid .fd input:focus, .pf-destgrid .fd select:focus{ outline:2px solid var(--pf-blue); background:#fff; }
.pf-destgrid .rg{ display:flex; align-items:center; gap:6px; }
.pf-destgrid .rg input{ text-align:right; font-family:'DM Mono',monospace; }
.pf-destgrid .rg span{ color:var(--pf-faint); }
.pf-savebar .grow{ flex:1; }
.pf-savebar .pf-btn{ background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2); }
.pf-empty{ padding:60px; text-align:center; color:var(--pf-soft); background:#fff; border-radius:16px; box-shadow:var(--pf-sh); }
.pf-note{ font-size:12.5px; color:#7d6f3a; background:#fdf8e7; border:1px solid #f0e4b0; border-radius:12px; padding:11px 16px; margin-bottom:18px; }
.pf-titlebar{ background:#fff; border-radius:14px; box-shadow:var(--pf-sh); padding:14px 18px; margin-bottom:18px; display:flex; align-items:center; gap:14px; }
.pf-titlebar label{ font-size:13px; font-weight:800; color:var(--pf-ink); white-space:nowrap; }
.pf-titlebar input{ flex:1; padding:11px 14px; border-radius:10px; border:1px solid var(--pf-line); background:var(--pf-bg); font-size:15px; font-weight:700; color:var(--pf-ink); }
.pf-titlebar input:focus{ outline:2px solid var(--pf-blue); background:#fff; }
.pf-hono-all{ display:flex; align-items:center; gap:6px; background:#dff0f1; border:1px solid #6fb3ba; border-radius:10px; padding:6px 10px; white-space:nowrap; }
.pf-hono-all label{ font-size:11px; color:#0e6b75; font-weight:800; }
.pf-hono-all input{ flex:none; width:52px; padding:6px 8px; border-radius:7px; border:1px solid #6fb3ba; background:#f3fbfb; color:#0b5860; font-weight:800; font-family:'DM Mono',monospace; text-align:right; font-size:13px; }
.pf-hono-all .u{ color:#0e6b75; font-weight:700; }
.pf-type{ font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:99px; }
.pf-type.hab{ background:#e7eff6; color:var(--pf-blue); }
.pf-type.pro{ background:#efe7f7; color:var(--pf-purple); }
@media(max-width:1080px){ .pf-calc{ grid-template-columns:repeat(3,1fr);} }
@media(max-width:640px){ .pf-calc{ grid-template-columns:repeat(2,1fr); padding-left:0;} }
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eurSp = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', ' ') . ' €';
/** Badge type de bien : habitation vs professionnel (libellé précis en tooltip). */
$typeBadge = function (string $cat, string $lib) use ($e): string {
    $c = strtolower(trim($cat));
    $isHab = ($c === 'habitation');
    $cls = $isHab ? 'hab' : 'pro';
    $ic  = $isHab ? '🏠' : '🏢';
    $txt = $isHab ? 'Habitation' : 'Professionnel';
    $title = $lib !== '' ? ' title="' . $e($lib) . '"' : '';
    return '<span class="pf-type ' . $cls . '"' . $title . '>' . $ic . ' ' . $txt . '</span>';
};

// ── Catégories de biens RÉELLEMENT présentes (pour le filtre « Type de bien ») ──
// Le filtre doit filtrer pour de vrai : on liste les catégories existantes plutôt
// qu'un binaire Habitation/Pro qui rangeait à tort « investissement » en Pro.
$CAT_META = [
    'habitation'    => ['🏠', 'Habitation'],
    'investissement'=> ['📈', 'Investissement'],
    'professionnel' => ['🏢', 'Professionnel'],
    'commerce'      => ['🏬', 'Commerce'],
    'annexe'        => ['🅿️', 'Annexe'],
];
$catsPresent = [];
foreach ($groupes as $g) {
    foreach ($g['biens'] as $bb) {
        $c = strtolower(trim((string)($bb['categorie'] ?? '')));
        if ($c === '') continue;
        if (!isset($catsPresent[$c])) {
            $catsPresent[$c] = $CAT_META[$c] ?? ['🏷️', ucfirst($c)];
        }
    }
}
?>
<?php if ($embed): ?><style>.pf-savebar{ left:0 !important; }</style><?php endif; ?>
<div class="pf-page">

<!-- BARRE HAUT : navigation portefeuilles uniquement -->
<div class="pf-topbar">
  <div class="pf-tb-row">
    <div class="pf-hero-ic">🧮</div>
    <div style="flex:1;font-weight:800;font-size:15px;color:var(--pf-ink);">Constituer un portefeuille</div>
    <a class="pf-btn pf-btn-sm" href="<?= $e($listUrl) ?>">📚 Liste des portefeuilles</a>
    <a class="pf-btn pf-btn-sm" href="<?= $e($newUrl) ?>">➕ Nouveau portefeuille</a>
  </div>
</div><!-- /.pf-topbar -->

<style>
.pf-profil-btns{ display:flex; gap:6px; flex-wrap:wrap; }
.pf-profil, .pf-typebtn{ cursor:pointer; border:1px solid var(--pf-line); background:var(--pf-bg); color:var(--pf-ink);
    border-radius:9px; padding:8px 12px; font-size:12.5px; font-weight:700; }
.pf-profil.on{ background:var(--pf-blue); color:#fff; border-color:var(--pf-blue); }
.pf-typebtn.on{ background:#1a237e; color:#fff; border-color:#1a237e; }
.pf-cardhead{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--pf-line); }
.pf-cardhead .pf-tb-title{ flex:1; min-width:240px; }
</style>
<!-- Acheteur & critères de recherche : FILTRE UNIQUE de la liste (les critères filtrent en direct) -->
<div class="pf-destpanel" id="dest">
    <div class="pf-cardhead">
        <input type="text" id="pf-nom" class="pf-tb-title" placeholder="📁 Titre de la sélection — ex. : Portefeuille commerces Lyon 7 · juin 2026" maxlength="160">
        <div class="pf-hono-all"><label>Hono. sur tous</label><input type="text" id="hono-all" value="6"><span class="u">%</span><button type="button" class="pf-btn pf-btn-sm" onclick="pfApplyHonoAll()">Appliquer</button></div>
        <button type="button" class="pf-btn pf-btn-sm pf-btn-primary" onclick="pfSave()">💾 Enregistrer</button>
        <button type="button" class="pf-btn pf-btn-sm" id="pf-preview" onclick="pfApercu()" style="background:#1a237e;color:#fff;border:none;">👁️ Aperçu</button>
        <button type="button" class="pf-btn pf-btn-sm" id="pf-send" onclick="pfEnvoyer()" style="background:#1f5fc0;color:#fff;border:none;">📧 Envoyer</button>
    </div>
    <h3>👤 Acheteur & critères <small>— ces critères filtrent la liste ci-dessous ; le titre se génère automatiquement</small></h3>
    <!-- Ligne 1 : identité du destinataire -->
    <div class="pf-destgrid">
        <div class="fd"><label>Prénom</label><input type="text" id="dest-prenom" oninput="pfBuildName()"></div>
        <div class="fd"><label>Nom</label><input type="text" id="dest-nom" oninput="pfBuildName()" placeholder="DUPONT"></div>
        <div class="fd"><label>Téléphone</label><input type="text" id="dest-tel" placeholder="06 00 00 00 00"></div>
        <div class="fd"><label>Email</label><input type="email" id="dest-email" placeholder="contact@exemple.fr"></div>
    </div>
    <!-- Ligne 2 : type de portefeuille (profil acheteur) + type de bien -->
    <div class="pf-destgrid" style="margin-top:12px;">
        <div class="fd"><label>Type de portefeuille · profil de l'acheteur</label>
            <input type="hidden" id="pf-type" value="">
            <div class="pf-profil-btns">
                <button type="button" class="pf-profil" data-profil="investisseur" onclick="pfSetProfil('investisseur')">📈 Investisseur</button>
                <button type="button" class="pf-profil" data-profil="commercialisateur" onclick="pfSetProfil('commercialisateur')">🏷️ Commercialisateur</button>
                <button type="button" class="pf-profil" data-profil="occupant" onclick="pfSetProfil('occupant')" title="Achète pour occuper lui-même ou sa famille (résidence principale)">🏡 Occupant</button>
            </div>
        </div>
        <div class="fd"><label>Type de bien recherché</label>
            <input type="hidden" id="crit-type" value="">
            <div class="pf-profil-btns">
                <button type="button" class="pf-typebtn on" data-t="" onclick="pfSetType('')">Tous</button>
                <?php foreach ($catsPresent as $cKey => $meta): ?>
                <button type="button" class="pf-typebtn" data-t="<?= $e($cKey) ?>" onclick="pfSetType('<?= $e($cKey) ?>')"><?= $meta[0] ?> <?= $e($meta[1]) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Ligne 3 : filtres chiffrés (prix, surface, rentabilité) -->
    <div class="pf-destgrid" style="margin-top:12px;">
        <div class="fd"><label>Critère prix (€)</label><div class="rg"><input type="text" id="crit-prix-min" placeholder="min" oninput="pfApplyCriteria();pfBuildName()"><span>–</span><input type="text" id="crit-prix-max" placeholder="max" oninput="pfApplyCriteria();pfBuildName()"></div></div>
        <div class="fd"><label>Critère surface (m²)</label><div class="rg"><input type="text" id="crit-surf-min" placeholder="min" oninput="pfApplyCriteria();pfBuildName()"><span>–</span><input type="text" id="crit-surf-max" placeholder="max" oninput="pfApplyCriteria();pfBuildName()"></div></div>
        <div class="fd"><label>Renta. net vendeur attendue (%)</label><div class="rg"><input type="text" id="crit-rnv-min" placeholder="min. attendu" oninput="pfApplyCriteria()"><span style="color:var(--pf-faint);font-size:11px;">et +</span></div></div>
        <div class="fd"><label>Renta. acte en main attendue (%)</label><div class="rg"><input type="text" id="crit-raem-min" placeholder="min. attendu" oninput="pfApplyCriteria()"><span style="color:var(--pf-faint);font-size:11px;">et +</span></div></div>
    </div>
    <div class="pf-filters" id="pf-filters" style="margin-top:14px;border-top:1px solid var(--pf-line);padding-top:12px;">
        <span style="font-size:12px;color:var(--pf-soft);">Sélection :</span>
        <button type="button" class="pf-btn pf-btn-sm" onclick="pfSelectAll(true)">☑ Tout</button>
        <button type="button" class="pf-btn pf-btn-sm" onclick="pfSelectAll(false)">☐ Aucun</button>
        <span style="font-size:12px;color:var(--pf-faint);margin:0 4px;">·&nbsp; Afficher :</span>
        <button type="button" class="pf-btn pf-btn-sm pf-btn-primary" data-selfilter="all" onclick="pfFilterSel('all')">Tous <b id="cnt-all">(0)</b></button>
        <button type="button" class="pf-btn pf-btn-sm" data-selfilter="sel" onclick="pfFilterSel('sel')">✓ Sélectionnés <b id="cnt-sel">(0)</b></button>
        <button type="button" class="pf-btn pf-btn-sm" data-selfilter="uns" onclick="pfFilterSel('uns')">Non sélectionnés <b id="cnt-uns">(0)</b></button>
        <span id="pf-filter-count" style="font-size:12px;color:var(--pf-soft);margin-left:8px;"></span>
    </div>
</div>

<?php if (empty($groupes)): ?>
    <div class="pf-empty">Aucun bien <?= $fScope !== 'tous' ? 'marqué « à vendre »' : '' ?> dans le périmètre. <a href="<?= $e(app_url('/transaction_portefeuilles.php')) ?>">Marquez des biens à vendre →</a></div>
<?php else: ?>
    <div class="pf-flatlist">
    <?php foreach ($groupes as $pid => $g): ?>
        <?php foreach ($g['biens'] as $b):
            $proprioNom = (string)($g['label'] ?? '');
                    $adr = trim($b['adresse']); $loc = trim($b['cp'] . ' ' . $b['ville']);
                    $pv = $b['prix'] !== null ? (int)round($b['prix']) : '';
                    $isPro = strtolower((string)$b['categorie']) !== 'habitation';
                    // Frais d'acquisition par défaut : droits de mutation 7,8% (pro) /
                    // frais de notaire 8% (habitation ancien) — éditables par bien.
                    $dmpDef = $isPro ? '7.8' : '8';
                    $fraisLbl = $isPro ? 'Droits mutation' : 'Frais notaire';
                ?>
                    <div class="pf-bien<?= $isPro ? ' is-pro' : '' ?>" data-bien="<?= (int)$b['id'] ?>"
                         data-surface="<?= $b['surf'] !== null ? $e($b['surf']) : 0 ?>"
                         data-loyeran="<?= (int)round($b['loyer_an']) ?>"
                         data-pro="<?= $isPro ? 1 : 0 ?>" data-cat="<?= $e(strtolower(trim((string)$b['categorie']))) ?>" data-dmp="<?= $e($dmpDef) ?>">
                        <div class="pf-bhead">
                            <span class="pf-check"><input type="checkbox" class="pf-cb" onchange="pfOnCheck(this)"></span>
                            <div class="pf-binfo">
                                <div class="pf-l1">
                                    <a class="pf-adr" href="<?= $e(app_url('/bien_360.php?id=' . (int)$b['id'])) ?>" target="_blank" rel="noopener"><?= $e($adr !== '' ? $adr : ($loc !== '' ? $loc : 'Bien #' . $b['id'])) ?> <span class="ic">↗</span></a>
                                    <?php if ($loc !== '' && $adr !== ''): ?><span style="color:var(--pf-soft);font-size:13px;"><?= $e($loc) ?></span><?php endif; ?>
                                    <?php if ($b['ref'] !== ''): ?><span class="pf-ref"><?= $e($b['ref']) ?></span><?php endif; ?>
                                    <?php if ($b['surf']): ?><span class="pf-surf"><?= $e(fmt_m2($b['surf'], 0)) ?></span><?php endif; ?>
                                    <?= $typeBadge($b['categorie'], $b['type_libelle']) ?>
                                    <?php if ($proprioNom !== ''): ?><span class="pf-owner" style="background:#ede7f6;color:#5e35b1;font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:8px;">👤 <?= $e($proprioNom) ?></span><?php endif; ?>
                                    <?php if (!empty($b['locataire'])): ?><span class="pf-tenant" style="background:#e8f5e9;color:#2e7d32;font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:8px;">🔑 <?= $e($b['locataire']) ?></span><?php endif; ?>
                                    <?php if ($b['loyer'] !== null): ?><span class="pf-loc ok">Loyer <?= $e($eurSp($b['loyer'])) ?>/mois</span><?php else: ?><span class="pf-loc">Pas de loyer</span><?php endif; ?>
                                    <?php if ($b['debiteur']): ?><span class="pf-loc deb">⚠ Impayé<?= $b['creance'] ? ' · ' . $e($eurSp($b['creance'])) : '' ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="pf-calc">
                            <div class="pf-fld"><label>Prix / m²</label><div class="inrow"><input type="text" class="c-pm" oninput="pfCalc(this,'pm')"><span class="u">€</span></div></div>
                            <div class="pf-fld k-pv"><label>Prix de vente (FAI)</label><div class="inrow"><input type="text" class="c-pv" value="<?= $e($pv) ?>" oninput="pfCalc(this,'pv')"><span class="u">€</span></div></div>
                            <div class="pf-fld k-ho"><label>Honoraires</label><div class="inrow"><input type="text" class="c-hp" value="6" oninput="pfCalc(this,'hp')"><span class="u">%</span></div></div>
                            <div class="pf-fld k-ho"><label>Honoraires</label><div class="inrow"><input type="text" class="c-hm" oninput="pfCalc(this,'hm')"><span class="u">€</span></div></div>
                            <div class="pf-fld k-nv"><label>Net vendeur</label><div class="inrow"><input type="text" class="c-nv" oninput="pfCalc(this,'nv')"><span class="u">€</span></div></div>
                            <div class="pf-fld k-rnv"><label>Renta. net vendeur</label><div class="inrow"><input type="text" class="c-rnv" oninput="pfCalc(this,'rnv')"><span class="u">%</span></div></div>
                            <div class="pf-fld k-dm"><label><?= $e($fraisLbl) ?></label><div class="inrow"><input type="text" class="c-dmp" value="<?= $e($dmpDef) ?>" oninput="pfCalc(this,'dmp')"><span class="u">%</span></div></div>
                            <div class="pf-fld k-dm"><label><?= $e($fraisLbl) ?></label><div class="inrow"><input type="text" class="c-dm" oninput="pfCalc(this,'dm')"><span class="u">€</span></div></div>
                            <div class="pf-fld k-aem"><label>Prix acte en main</label><div class="inrow"><input type="text" class="c-aem" oninput="pfCalc(this,'aem')"><span class="u">€</span></div></div>
                            <div class="pf-fld k-rt"><label>Renta. acte en main</label><div class="inrow"><input type="text" class="c-rt" oninput="pfCalc(this,'rt')"><span class="u">%</span></div></div>
                        </div>
                        <?php $bdocs = $docsByBien[(int)$b['id']] ?? []; ?>
                        <?php if ($bdocs): ?>
                        <div class="pf-docs">
                            <span class="pf-docs-lbl">📎 Documents à joindre :</span>
                            <?php foreach ($bdocs as $dc): ?>
                            <label class="pf-doc" title="<?= $e($dc['type']) ?>">
                                <input type="checkbox" class="pf-doc-cb" value="<?= (int)$dc['id'] ?>" checked>
                                <span><?= $e($dc['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
    <?php endforeach; ?>
    </div><!-- /.pf-flatlist -->
<?php endif; ?>

</div><!-- /.pf-page -->

<!-- Barre d'enregistrement -->
<div class="pf-savebar">
    <div class="sb"><span class="k">Sélectionnés</span><span class="v" id="sb-count">0</span></div>
    <div class="sb"><span class="k">Total prix de vente</span><span class="v" id="sb-pv">0 €</span></div>
    <div class="sb"><span class="k">Total net vendeur</span><span class="v" id="sb-nv">0 €</span></div>
    <div class="sb"><span class="k">Total honoraires</span><span class="v" id="sb-ho">0 €</span></div>
    <div class="grow"></div>
    <button type="button" class="pf-btn pf-btn-primary" id="pf-save" disabled onclick="pfSave()">💾 Enregistrer le portefeuille</button>
</div>

<script>
const PF_CSRF = <?= json_encode(csrf_token('portefeuille_save')) ?>;
const PF_LOAD = <?= json_encode($loaded) ?>;
const PF_VIEWAS = <?= (int)($scope['view_as'] ?? 0) ?>;   // staff « voir en tant que » : id_user bailleur
const PF_EMBED  = <?= (int)$embed ?>;   // 1 = ouvert en iframe dans le hub
const PF_ENVOYER_URL = <?= json_encode(app_url('/api/portefeuille_envoyer.php')) ?>;
const round = (n) => Math.round(n);
function fEuro(n){ return (round(n)||0).toLocaleString('fr-FR') + ' €'; }
function val(el){ return parseFloat(String(el.value).replace(/[ ]/g,'').replace(',', '.')) || 0; }

// ── Calcul multi-sens ──────────────────────────────────────────────
// Habitation : pas de droits (DMP=0) → Acte en main = Prix FAI, rentabilité sur FAI.
// Professionnel : droits de mutation DMP% sur (net vendeur + honoraires) = sur le Prix FAI,
//   Prix acte en main = FAI × (1 + DMP/100), rentabilité ACTE EN MAIN = loyer annuel / AEM.
function pfCalc(input, field){
    const row = input.closest('.pf-bien');
    const S  = parseFloat(row.dataset.surface) || 0;
    const La = parseFloat(row.dataset.loyeran) || 0;
    const pm=row.querySelector('.c-pm'), pv=row.querySelector('.c-pv'), rt=row.querySelector('.c-rt'),
          hp=row.querySelector('.c-hp'), hm=row.querySelector('.c-hm'), nv=row.querySelector('.c-nv'),
          rnv=row.querySelector('.c-rnv'),
          dmp=row.querySelector('.c-dmp'), dm=row.querySelector('.c-dm'), aem=row.querySelector('.c-aem');
    let PM=val(pm), FAI=val(pv), RT=val(rt), HP=val(hp), HM=val(hm), NV=val(nv), RNV=rnv?val(rnv):0;
    let DMP = dmp ? val(dmp) : (parseFloat(row.dataset.dmp) || 0);
    let DM = dm ? val(dm) : 0, AEM = aem ? val(aem) : 0;

    // 1) Déterminer le Prix FAI selon le champ modifié
    if(field==='pm'){ FAI = S>0 ? PM*S : 0; }
    else if(field==='nv'){ FAI = NV*(1 + HP/100); }            // net + honoraires (HP fixe)
    else if(field==='rnv'){ NV = RNV>0 ? La*100/RNV : 0; FAI = NV*(1 + HP/100); } // renta = NET VENDEUR
    else if(field==='hm'){ NV = FAI - HM; HP = NV>0 ? HM/NV*100 : 0; } // FAI fixe
    else if(field==='rt'){ AEM = RT>0 ? La*100/RT : 0; FAI = AEM/(1 + DMP/100); } // renta = ACTE EN MAIN
    else if(field==='aem'){ FAI = AEM/(1 + DMP/100); }
    else if(field==='dm'){ DMP = FAI>0 ? DM/FAI*100 : 0; }     // droits € → recalcule le taux
    // field 'pv' / 'hp' / 'dmp' : FAI inchangé (saisi directement ou taux modifié)

    // 2) Dérivés depuis FAI + HP + DMP
    if(field!=='hm'){ NV = FAI/(1 + HP/100); HM = FAI - NV; }
    DM  = FAI * DMP/100;
    AEM = FAI + DM;
    PM  = S>0 ? FAI/S : 0;
    RNV = NV>0  ? La/NV*100  : 0;     // rentabilité NET VENDEUR
    RT  = AEM>0 ? La/AEM*100 : 0;     // rentabilité ACTE EN MAIN (frais de notaire / droits inclus)

    // 3) Réécriture (sauf champ en cours)
    if(field!=='pm') pm.value = PM>0 ? round(PM) : '';
    if(field!=='pv') pv.value = FAI>0 ? round(FAI) : '';
    if(field!=='rt') rt.value = RT>0 ? RT.toFixed(2) : '';
    if(rnv && field!=='rnv') rnv.value = RNV>0 ? RNV.toFixed(2) : '';
    if(field!=='hp') hp.value = (FAI>0 && HP>0) ? HP.toFixed(2) : hp.value;
    if(field!=='hm') hm.value = HM>0 ? round(HM) : '';
    if(field!=='nv') nv.value = NV>0 ? round(NV) : '';
    if(dmp && field!=='dmp') dmp.value = DMP>0 ? DMP.toFixed(2) : dmp.value;
    if(dm  && field!=='dm')  dm.value  = DM>0  ? round(DM)  : '';
    if(aem && field!=='aem') aem.value = AEM>0 ? round(AEM) : '';
    pfTotals();
}

// Applique le même taux d'honoraires à tous les biens (modifiable ensuite individuellement)
function pfApplyHonoAll(){
    const v = document.getElementById('hono-all').value.trim();
    if(v==='') return;
    document.querySelectorAll('.pf-bien').forEach(row=>{
        const hp = row.querySelector('.c-hp');
        hp.value = v;
        pfCalc(hp, 'hp');
    });
}

// Retire un bien de la liste de sélection (vue courante)
function pfOnCheck(cb){ cb.closest('.pf-bien').classList.toggle('on', cb.checked); pfTotals(); }
function pfSelectAll(s){ document.querySelectorAll('.pf-cb').forEach(cb=>{ const row=cb.closest('.pf-bien'); if(s && row.style.display==='none') return; cb.checked=s; row.classList.toggle('on',s); }); pfTotals(); }

// ── Profil / type de bien / critères = FILTRE UNIQUE de la liste (en direct) ──
function pfNum(id){ var v=document.getElementById(id); if(!v) return null; var s=String(v.value).replace(/[ ]/g,'').replace(',','.'); var n=parseFloat(s); return isNaN(n)?null:n; }
function pfSetProfil(p){ var h=document.getElementById('pf-type'); if(h) h.value=p; document.querySelectorAll('.pf-profil').forEach(function(b){ b.classList.toggle('on', b.dataset.profil===p); }); if(typeof pfBuildName==='function') pfBuildName(); }
function pfSetType(t){ var h=document.getElementById('crit-type'); if(h) h.value=t; document.querySelectorAll('.pf-typebtn').forEach(function(b){ b.classList.toggle('on', b.dataset.t===t); }); pfApplyCriteria(); }
function pfRowNum(row, sel){ var el=row.querySelector(sel); return el?(parseFloat(String(el.value).replace(/[ ]/g,'').replace(',','.'))||0):0; }
function pfApplyCriteria(){
    var pmin=pfNum('crit-prix-min'), pmax=pfNum('crit-prix-max'), smin=pfNum('crit-surf-min'), smax=pfNum('crit-surf-max');
    var rnvMin=pfNum('crit-rnv-min'), raeMin=pfNum('crit-raem-min');
    var type=(document.getElementById('crit-type')||{}).value||'';
    var hasPrix=(pmin!==null||pmax!==null), hasSurf=(smin!==null||smax!==null);
    var hasRnv=(rnvMin!==null), hasRae=(raeMin!==null);
    var shown=0;
    document.querySelectorAll('.pf-bien').forEach(function(row){
        var S=parseFloat(row.dataset.surface)||0;
        var P=pfRowNum(row,'.c-pv');
        var RNV=pfRowNum(row,'.c-rnv'), RAE=pfRowNum(row,'.c-rt');
        var cat=row.dataset.cat||'';
        var ok=true;
        // Prix : si on filtre par prix, un bien SANS prix est exclu
        if(hasPrix){ if(P<=0){ ok=false; } else { if(pmin!==null && P<pmin) ok=false; if(pmax!==null && P>pmax) ok=false; } }
        // Surface : si on filtre par surface, un bien SANS surface est exclu
        if(hasSurf){ if(S<=0){ ok=false; } else { if(smin!==null && S<smin) ok=false; if(smax!==null && S>smax) ok=false; } }
        // Rentabilité net vendeur / acte en main : bien sans renta calculée (pas de loyer ou pas de prix) exclu
        if(hasRnv){ if(RNV<=0 || RNV<rnvMin){ ok=false; } }
        if(hasRae){ if(RAE<=0 || RAE<raeMin){ ok=false; } }
        // Type de bien : filtre sur la catégorie RÉELLE du bien (exact). '' = tous.
        if(type!=='' && cat!==type) ok=false;
        row.dataset.crit = ok ? '1' : '0';   // marqueur « passe les critères » (indépendant du filtre sélection)
    });
    pfApplyVisibility();
}
// Au chargement : pré-calcule toutes les lignes ayant un prix pour que les rentabilités
// (net vendeur / acte en main) soient disponibles avant tout filtrage.
function pfInitCalc(){
    document.querySelectorAll('.pf-bien').forEach(function(row){
        var pv=row.querySelector('.c-pv');
        if(pv && String(pv.value).trim()!=='') pfCalc(pv,'pv');
    });
}
// Visibilité combinée : critères ET filtre sélection (tous / sélectionnés / non sélectionnés).
function pfApplyVisibility(){
    var mode = window._selFilter || 'all';
    var shown = 0;
    document.querySelectorAll('.pf-bien').forEach(function(row){
        var crit = row.dataset.crit !== '0';
        var on = row.classList.contains('on');
        var selOk = mode==='all' || (mode==='sel' && on) || (mode==='uns' && !on);
        var vis = crit && selOk;
        row.style.display = vis ? '' : 'none';
        if(vis) shown++;
    });
    document.querySelectorAll('.pf-group').forEach(function(g){
        var v=0; g.querySelectorAll('.pf-bien').forEach(function(r){ if(r.style.display!=='none') v++; });
        g.style.display=v?'':'none';
    });
    var c=document.getElementById('pf-filter-count'); if(c) c.textContent=shown+' bien(s) affiché(s)';
    pfUpdateFilterCounts();
}
document.addEventListener('DOMContentLoaded', function(){ try{ pfInitCalc(); pfApplyCriteria(); }catch(e){} });

// ── Persistance du BROUILLON en cours (anti-perte au changement d'onglet) ──
const PF_DRAFT_KEY = 'pf_draft_selection';
function pfSnapshot(){
    const g = id => (document.getElementById(id)?.value || '');
    const biens = {};
    document.querySelectorAll('.pf-bien').forEach(function(row){
        const cb = row.querySelector('.pf-cb');
        if (cb && cb.checked){
            const gv = s => { const el = row.querySelector(s); return el ? el.value : ''; };
            biens[row.dataset.bien] = { pv: gv('.c-pv'), hp: gv('.c-hp'), dmp: gv('.c-dmp') };
        }
    });
    return { nom:g('pf-nom'), type:g('pf-type'), prenom:g('dest-prenom'), nom_d:g('dest-nom'),
        email:g('dest-email'), tel:g('dest-tel'), pxmin:g('crit-prix-min'), pxmax:g('crit-prix-max'),
        sfmin:g('crit-surf-min'), sfmax:g('crit-surf-max'), type_bien:g('crit-type'), biens:biens };
}
let pfPersistT;
function pfPersist(){ clearTimeout(pfPersistT); pfPersistT = setTimeout(function(){ try{ localStorage.setItem(PF_DRAFT_KEY, JSON.stringify(pfSnapshot())); }catch(e){} }, 400); }
function pfClearDraft(){ try{ localStorage.removeItem(PF_DRAFT_KEY); }catch(e){} }
function pfRestore(){
    let d; try{ d = JSON.parse(localStorage.getItem(PF_DRAFT_KEY) || 'null'); }catch(e){ d = null; }
    if (!d) return;
    const sf = (id,v)=>{ const el=document.getElementById(id); if(el && v!=null && v!=='') el.value=v; };
    sf('pf-nom',d.nom); sf('dest-prenom',d.prenom); sf('dest-nom',d.nom_d); sf('dest-email',d.email); sf('dest-tel',d.tel);
    sf('crit-prix-min',d.pxmin); sf('crit-prix-max',d.pxmax); sf('crit-surf-min',d.sfmin); sf('crit-surf-max',d.sfmax);
    if (d.type) pfSetProfil(d.type);
    if (d.type_bien) pfSetType(d.type_bien);
    if (d.nom) pfNameManual = true;
    Object.entries(d.biens || {}).forEach(function(ent){
        const id = ent[0], v = ent[1];
        const row = document.querySelector('.pf-bien[data-bien="'+id+'"]'); if(!row) return;
        const sv = (s,val)=>{ const el=row.querySelector(s); if(el && val!=null && val!=='') el.value=val; };
        sv('.c-hp',v.hp); sv('.c-dmp',v.dmp); sv('.c-pv',v.pv);
        const pv=row.querySelector('.c-pv'); if(pv && pv.value) pfCalc(pv,'pv');
        const cb=row.querySelector('.pf-cb'); if(cb){ cb.checked=true; row.classList.add('on'); }
    });
    pfTotals(); pfApplyCriteria();
}
document.addEventListener('input',  pfPersist, true);
document.addEventListener('change', pfPersist, true);
// Restaure le brouillon au chargement (sauf en reprise d'un portefeuille enregistré)
document.addEventListener('DOMContentLoaded', function(){ if (!PF_LOAD) { try{ pfRestore(); }catch(e){} } });
function pfToggleGroup(h){ h.closest('.pf-group').classList.toggle('collapsed'); }

// ── Titre du portefeuille auto-généré depuis le destinataire + critères ──
let pfNameManual = false;
(function(){ const n=document.getElementById('pf-nom'); if(n) n.addEventListener('input',()=>{ pfNameManual = (n.value.trim()!==''); }); })();
function gv(id){ const el=document.getElementById(id); return el ? el.value.trim() : ''; }
function pfBuildName(){
    if (pfNameManual) return;                      // l'utilisateur a saisi un titre manuel → on n'écrase pas
    const who  = (gv('dest-nom').toUpperCase() + ' ' + gv('dest-prenom')).trim();
    const surf = [gv('crit-surf-min'), gv('crit-surf-max')].filter(Boolean).join('-');
    const prix = [gv('crit-prix-min'), gv('crit-prix-max')].filter(Boolean).join('-');
    const parts = [];
    if (who)  parts.push(who);
    if (surf) parts.push(surf + ' m²');
    if (prix) parts.push(prix + ' €');
    const t = parts.join(' · ');
    const n = document.getElementById('pf-nom');
    if (n && t) n.value = t;
}

function pfTotals(){
    let n=0, tpv=0, tnv=0, tho=0;
    document.querySelectorAll('.pf-cb:checked').forEach(cb=>{
        const row=cb.closest('.pf-bien'); n++;
        tpv += val(row.querySelector('.c-pv'));
        tnv += val(row.querySelector('.c-nv'));
        tho += val(row.querySelector('.c-hm'));
    });
    document.getElementById('sb-count').textContent = n;
    document.getElementById('sb-pv').textContent = fEuro(tpv);
    document.getElementById('sb-nv').textContent = fEuro(tnv);
    document.getElementById('sb-ho').textContent = fEuro(tho);
    document.getElementById('pf-save').disabled = (n===0);
    pfApplyVisibility();
}
// Compteurs des boutons : ne comptent QUE les biens qui passent les critères de filtre.
function pfUpdateFilterCounts(){
    var tot=0, sel=0;
    document.querySelectorAll('.pf-bien').forEach(function(row){
        if(row.dataset.crit==='0') return;        // exclut ceux masqués par les critères
        tot++; if(row.classList.contains('on')) sel++;
    });
    var set=function(id,v){ var el=document.getElementById(id); if(el) el.textContent='('+v+')'; };
    set('cnt-all', tot); set('cnt-sel', sel); set('cnt-uns', tot-sel);
}

async function pfSave(){
    const items=[];
    document.querySelectorAll('.pf-cb:checked').forEach(cb=>{
        const row=cb.closest('.pf-bien');
        items.push({
            id_bien:parseInt(row.dataset.bien,10),
            prix_vente:val(row.querySelector('.c-pv')),
            prix_m2:val(row.querySelector('.c-pm')),
            rendement:val(row.querySelector('.c-rt')),
            honoraires_pct:val(row.querySelector('.c-hp')),
            honoraires_montant:val(row.querySelector('.c-hm')),
            net_vendeur:val(row.querySelector('.c-nv')),
            droits_mutation_pct: row.querySelector('.c-dmp') ? val(row.querySelector('.c-dmp')) : null,
            droits_mutation_montant: row.querySelector('.c-dm') ? val(row.querySelector('.c-dm')) : null,
            prix_acte_en_main: row.querySelector('.c-aem') ? val(row.querySelector('.c-aem')) : null,
            documents_joints: Array.from(row.querySelectorAll('.pf-doc-cb:checked')).map(c=>parseInt(c.value,10)).filter(Boolean)
        });
    });
    if(!items.length){ alert('Sélectionnez au moins un bien.'); return; }
    const nom=document.getElementById('pf-nom').value.trim();
    if(!nom){ alert('Donnez un nom au portefeuille.'); document.getElementById('pf-nom').focus(); return; }
    const btn=document.getElementById('pf-save'); btn.disabled=true; btn.textContent='…';
    try{
        const fd=new FormData();
        const g = id => (document.getElementById(id)?.value || '').trim();
        fd.append('nom',nom);
        fd.append('type_destinataire', g('pf-type'));
        fd.append('destinataire_nom', g('dest-nom'));
        fd.append('destinataire_prenom', g('dest-prenom'));
        fd.append('destinataire_email', g('dest-email'));
        fd.append('destinataire_tel', g('dest-tel'));
        fd.append('critere_prix_min', g('crit-prix-min'));
        fd.append('critere_prix_max', g('crit-prix-max'));
        fd.append('critere_surf_min', g('crit-surf-min'));
        fd.append('critere_surf_max', g('crit-surf-max'));
        fd.append('critere_type', g('crit-type'));
        fd.append('items',JSON.stringify(items));
        if (PF_LOAD && PF_LOAD.id) fd.append('id_portefeuille', PF_LOAD.id);
        const res=await fetch(<?= json_encode(app_url('/api/portefeuille_save.php')) ?>,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':PF_CSRF},body:fd});
        const j=await res.json();
        if(!j.success){ alert(j.message||'Erreur.'); btn.disabled=false; btn.textContent='💾 Enregistrer'; return; }
        pfClearDraft();   // le brouillon local n'est plus nécessaire une fois enregistré
        alert('✅ Portefeuille enregistré : '+j.nb_biens+' bien(s) · '+fEuro(j.total_prix_vente));
        // On RESTE sur la sélection (rechargée comme portefeuille enregistré, modifiable + envoyable).
        var pid = j.id_portefeuille || (PF_LOAD && PF_LOAD.id) || 0;
        var url = 'transaction_portefeuilles_selection.php?portefeuille='+pid+'&saved=1';
        if (PF_VIEWAS > 0) url += '&bailleur='+PF_VIEWAS;   // garde le « voir en tant que »
        if (PF_EMBED) url += '&embed=1';                    // garde le contexte iframe (hub)
        window.location.href=url;
    }catch(e){ alert('Erreur réseau.'); btn.disabled=false; btn.textContent='💾 Enregistrer'; }
}

// Envoi du portefeuille (création d'un lien personnel + email au destinataire).
async function pfEnvoyer(){
    const id = PF_LOAD && PF_LOAD.id;
    if(!id){ alert('Enregistrez d\'abord le portefeuille (💾), puis envoyez-le.'); return; }
    const email = (document.getElementById('dest-email')?.value || '').trim();
    const dureeStr = prompt('Durée de validité du lien (jours) :', '30');
    if(dureeStr === null) return;
    const duree = Math.max(1, Math.min(365, parseInt(dureeStr,10) || 30));
    const withMail = !!email;
    if(!confirm('Créer le lien personnel sécurisé (valable '+duree+' jours)'
        + (withMail ? '\net l\'envoyer par email à : '+email : '\n(aucun email destinataire — le lien sera juste créé)')
        + ' ?')) return;
    const btn=document.getElementById('pf-send'); btn.disabled=true; const old=btn.textContent; btn.textContent='Envoi…';
    try{
        const fd=new FormData();
        fd.append('id_portefeuille', id);
        fd.append('duree', String(duree));
        fd.append('envoyer_mail', withMail ? '1' : '0');
        if(email) fd.append('email', email);
        const res=await fetch(PF_ENVOYER_URL,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':PF_CSRF},body:fd});
        const j=await res.json();
        if(!j.success){ alert(j.message||'Erreur.'); return; }
        let msg = (j.mail_sent ? '✅ Portefeuille envoyé par email à '+email+'.\n\n' : '✅ Lien personnel créé.\n')
                + (j.mail_sent ? '' : (j.mail_error ? '⚠️ Email non envoyé : '+j.mail_error+'\n' : ''))
                + '\nLien d\'accès :\n'+j.url;
        try{ await navigator.clipboard.writeText(j.url); msg += '\n\n(Lien copié dans le presse-papiers.)'; }catch(e){}
        alert(msg);
    }catch(e){ alert('Erreur réseau.'); }
    finally{ btn.disabled=false; btn.textContent=old; }
}

// Filtre d'affichage : tous / sélectionnés / non sélectionnés (pour revoir prix & honoraires avant action).
function pfFilterSel(mode){
    window._selFilter = mode;
    document.querySelectorAll('[data-selfilter]').forEach(b=>b.classList.toggle('pf-btn-primary', b.dataset.selfilter===mode));
    pfApplyVisibility();
}

// Aperçu interne du site (page publique en mode preview, réservé staff).
function pfApercu(){
    const id = PF_LOAD && PF_LOAD.id;
    if(!id){ alert('Enregistrez d\'abord le portefeuille (💾), puis prévisualisez.'); return; }
    window.open(<?= json_encode(app_url('/p.php?preview=')) ?> + id, '_blank');
}

// Auto-submit filtres
document.querySelectorAll('#pf-filters [data-autosubmit]').forEach(el=>{
    if(el.dataset.autosubmit==='text'){ let t; el.addEventListener('input',()=>{clearTimeout(t);t=setTimeout(()=>el.form.submit(),450);}); }
    else el.addEventListener('change',()=>el.form.submit());
});
// Init : calcule à partir du prix courant pré-rempli
document.querySelectorAll('.pf-bien').forEach(row=>{ const pv=row.querySelector('.c-pv'); if(pv && pv.value) pfCalc(pv,'pv'); });
pfUpdateFilterCounts();

// Mode reprise : pré-remplit titre/destinataire/critères + valeurs enregistrées par bien, et coche.
if (PF_LOAD) {
    pfNameManual = true;   // en reprise, on garde le titre enregistré (pas d'écrasement auto)
    const setf = (id,v)=>{ const el=document.getElementById(id); if(el && v!=null) el.value = String(v).replace(/\.00$/,''); };
    setf('pf-nom', PF_LOAD.nom || '');
    if (PF_LOAD.type) pfSetProfil(PF_LOAD.type);
    setf('dest-nom', PF_LOAD.dest || ''); setf('dest-prenom', PF_LOAD.prenom || '');
    setf('dest-email', PF_LOAD.email || ''); setf('dest-tel', PF_LOAD.tel || '');
    setf('crit-prix-min', PF_LOAD.pxmin); setf('crit-prix-max', PF_LOAD.pxmax);
    setf('crit-surf-min', PF_LOAD.sfmin); setf('crit-surf-max', PF_LOAD.sfmax);
    if (PF_LOAD.type_bien) pfSetType(PF_LOAD.type_bien);
    const setv = (row,sel,v)=>{ const el=row.querySelector(sel); if(el && v!=null && v!=='') el.value = String(v).replace(/\.0+$/,''); };
    Object.entries(PF_LOAD.biens || {}).forEach(([id,v])=>{
        const row = document.querySelector('.pf-bien[data-bien="'+id+'"]');
        if(!row) return;
        setv(row,'.c-hp',v.hp); setv(row,'.c-dmp',v.dmp); setv(row,'.c-pv',v.pv);
        const pv=row.querySelector('.c-pv'); if(pv && pv.value) pfCalc(pv,'pv');   // dérive nv/hm/dm/aem/rt
        const cb=row.querySelector('.pf-cb'); if(cb){ cb.checked=true; row.classList.add('on'); }
        // Restaure la sélection de docs joints (si enregistrée) : ne coche que ceux retenus.
        if (Array.isArray(v.dj)) {
            const keep = new Set(v.dj.map(Number));
            row.querySelectorAll('.pf-doc-cb').forEach(c=>{ c.checked = keep.has(parseInt(c.value,10)); });
        }
    });
    pfTotals();
    pfApplyCriteria();   // applique les critères chargés SANS masquer les biens cochés
    const btn=document.getElementById('pf-save'); if(btn) btn.textContent='💾 Mettre à jour';
}

// Arrivée via « Créer une liste » (#dest) : on amène l'utilisateur au formulaire destinataire.
if ((location.hash || '').indexOf('dest') >= 0) {
    const p = document.getElementById('dest');
    if (p) { p.scrollIntoView({behavior:'smooth', block:'center'}); const n=document.getElementById('dest-nom'); if(n) setTimeout(()=>n.focus(), 300); }
}
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
