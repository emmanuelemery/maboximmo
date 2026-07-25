<?php
// immeuble_360.php — Vue 360° d'un immeuble
// Lots, propriétaires, syndic, documents, mentions, IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
if (!function_exists('mail_compose_url') && is_file(__DIR__ . '/inc/mail_button.php')) require_once __DIR__ . '/inc/mail_button.php';
require_login();

$immId = (int)($_GET['id'] ?? 0);
if ($immId <= 0) {
    header('Location: ' . app_url('/bien_liste.php'));
    exit;
}

// ─── Charge l'immeuble ──
try {
    $st = $pdo->prepare("SELECT * FROM immeubles WHERE id = ? LIMIT 1");
    $st->execute([$immId]);
    $imm = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[immeuble_360] chargement immeuble #' . $immId . ' : ' . $e->getMessage());
    http_response_code(500);
    exit('<pre style="font:13px/1.5 monospace;padding:24px;color:#b91c1c;">'
        . 'Erreur de chargement de la fiche immeuble (#' . (int)$immId . ").\n\n"
        . htmlspecialchars($e->getMessage())
        . "\n\nProbable colonne absente sur cette base. Transmets cette ligne pour correction.</pre>");
}
if (!$imm) {
    http_response_code(404);
    exit('Immeuble introuvable.');
}

$adresseComplete = trim((string)($imm['adresse_1'] ?? '') . ' ' . ($imm['code_postal'] ?? '') . ' ' . ($imm['ville'] ?? ''));
$nomAffichage    = $imm['nom_immeuble'] ?: ($imm['adresse_1'] ?: 'Immeuble #' . $immId);

// ─── Biens / lots de l'immeuble ──
$biens = [];
try {
    $stB = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation, b.numero_lot, b.usage_bien,
        b.surface_habitable, b.etage, b.type_commercialisation, b.statut_occupation, b.statut_bien, b.prix_final_vente AS prix_vente,
        COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom,
        (SELECT COUNT(*) FROM annonces an WHERE an.id_bien = b.id AND (an.statut='publiee' OR an.etat_publication='diffusee')) AS nb_annonces_actives,
        (SELECT bb.bail_nature FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif' LIMIT 1) AS bail_nature,
        (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS nb_baux_actifs
        FROM biens b
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE b.id_immeuble = ?
        ORDER BY b.numero_lot ASC, b.id ASC
        LIMIT 200");
    $stB->execute([$immId]);
    $biens = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Propriétaires distincts (via les biens de l'immeuble) ──
$proprios = [];
try {
    $stP = $pdo->prepare("SELECT DISTINCT p.id, p.id_tiers,
        COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS nom,
        (SELECT COUNT(*) FROM biens WHERE id_proprietaire = p.id AND id_immeuble = ?) AS nb_biens
        FROM proprietaires p
        INNER JOIN biens b ON b.id_proprietaire = p.id
        WHERE b.id_immeuble = ?
        ORDER BY nb_biens DESC, nom ASC
        LIMIT 30");
    $stP->execute([$immId, $immId]);
    $proprios = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Documents GED de l'immeuble ──
// Source = GED centrale (ged_document_links, entity_type=IMB). Split par type de lien :
//   main      = documents PROPRES de l'immeuble (règlement copro, PV AG, TF multi-lots…)
//   reference = docs qui CITENT l'immeuble (docs de bail/bien, CRG…) → « Mentionné dans »
$docs = []; $mentions = []; $docsById = [];
try {
    if (is_file(__DIR__ . '/inc/ged_document_links.php')) require_once __DIR__ . '/inc/ged_document_links.php';
    require_once __DIR__ . '/inc/ged_doc_label.php';
    if (function_exists('gdl_documents_for_entity')) {
        foreach (gdl_documents_for_entity($pdo, 'IMB', $immId, ['limit' => 60]) as $d) {
            $docsById[(int)$d['id']] = $d;
        }
    }
} catch (Throwable $e) {}
try {
    $stD = $pdo->prepare("SELECT id, name_display, name_file, document_type, metadata, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND (
              id_immeuble = ?
              OR JSON_EXTRACT(metadata, '$.classement.immeuble_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'immeuble', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$immId, $immId, $immId]);
    foreach ($stD->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
        if (!isset($docsById[(int)$d['id']])) $docsById[(int)$d['id']] = $d;
    }
} catch (Throwable $e) {}
$byDate = fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
foreach (array_values($docsById) as $d) {
    if (($d['link_relation_type'] ?? 'main') === 'reference') $mentions[] = $d;
    else $docs[] = $d;
}
usort($docs, $byDate);     $docs = array_slice($docs, 0, 30);
usort($mentions, $byDate); $mentions = array_slice($mentions, 0, 10);
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

// ─── PV d'assemblée générale : regroupement par MILLÉSIME ──
// Un immeuble accumule N PV (AG ordinaire + AG extraordinaires) : jamais d'écrasement,
// chaque PV = une entrée datée. La « période » (mois/année, ex. « janvier 2025 ») distingue
// deux AG d'un même millésime ; le « libellé » porte la nature (ORDINAIRE / EXTRAORDINAIRE).
// Sources tolérantes (le type stocké varie : pv_ag / PV_ASSEMBLEE_GENERALE selon la route).
$pvTypeCodes = ['pv_ag', 'pv_assemblee_generale', 'ag_pv'];
$pvDocs = [];
foreach ($docs as $d) {
    if (!in_array(strtolower((string)($d['document_type'] ?? '')), $pvTypeCodes, true)) continue;
    $meta = [];
    if (!empty($d['metadata'])) { $meta = json_decode((string)$d['metadata'], true) ?: []; }
    $cl = is_array($meta['classement'] ?? null) ? $meta['classement'] : [];
    $ex = is_array($meta['extra'] ?? null) ? $meta['extra'] : [];
    // Période / date de l'AG : classement.date (= target_date/mois saisi) → extra.doc_date → dépôt.
    $pvDate = trim((string)($cl['date'] ?? $cl['period'] ?? $ex['doc_date'] ?? ''));
    if ($pvDate === '') $pvDate = (string)($d['created_at'] ?? '');
    $ts = strtotime($pvDate) ?: strtotime((string)($d['created_at'] ?? '')) ?: 0;
    // Libellé (ORDINAIRE / EXTRAORDINAIRE / texte) : user_label si présent, sinon nom affiché.
    $pvLibelle = trim((string)($cl['user_label'] ?? $cl['libelle'] ?? ''));
    $pvDocs[] = [
        'id'      => (int)$d['id'],
        'name'    => (string)($d['name_display'] ?? ''),
        'libelle' => $pvLibelle,
        'date'    => $pvDate,
        'ts'      => $ts,
        'annee'   => $ts ? (int)date('Y', $ts) : 0,
    ];
}
usort($pvDocs, fn($a, $b) => $b['ts'] <=> $a['ts']);
// Années affichées : les 3 dernières d'office + toute année ayant au moins un PV.
$pvCurY  = (int)date('Y');
$pvYears = [$pvCurY, $pvCurY - 1, $pvCurY - 2];
foreach ($pvDocs as $p) { if ($p['annee'] > 0 && !in_array($p['annee'], $pvYears, true)) $pvYears[] = $p['annee']; }
rsort($pvYears);
$pvByYear = [];
foreach ($pvDocs as $p) { $pvByYear[$p['annee'] ?: 0][] = $p; }

// ─── Checklist pièces immeuble ──
$piecesImm = [
    ['code'=>'REGLEMENT_COPRO',  'label'=>'Règlement de copropriété', 'sublabel'=>'Si copropriété'],
    ['code'=>'CARNET_ENTRETIEN', 'label'=>"Carnet d'entretien",       'sublabel'=>'Suivi équipements'],
    // PV d'AG : géré par la section dédiée « PV d'assemblée » (multi-millésimes), plus bas.
    ['code'=>'DIAG_PARTIES_COM', 'label'=>'Diagnostics parties communes','sublabel'=>'Amiante, plomb…'],
    ['code'=>'CADASTRE',         'label'=>'Extrait cadastral',        'sublabel'=>'Référence parcelle'],
];
// Mapping code pièce → type FluxBox (quicktype « immeuble ») pour pré-sélection au « + ».
$fbxTypeByCodeImm = [
    'REGLEMENT_COPRO'  => 'reglement_copro',
    'CARNET_ENTRETIEN' => 'carnet_entretien',
    'AG_PV'            => 'pv_ag',
];
$piecesItems = [];
foreach ($piecesImm as $p) {
    $piecesItems[] = [
        'label'    => $p['label'],
        'sublabel' => $p['sublabel'],
        'ok'       => isset($docsByType[$p['code']]),
        'add_url'  => app_url('/transaction_chargement.php'),
        'fbx_type' => $fbxTypeByCodeImm[$p['code']] ?? null,
    ];
}

// NB : « Mentionné dans » ($mentions) est désormais alimenté plus haut depuis
// ged_document_links (liens 'reference' sur l'immeuble). Ancienne requête retirée.

// ─── Infos syndic/gestion (immeubles_infos) ──
$infos = [];
try {
    $stI = $pdo->prepare("SELECT * FROM immeubles_infos WHERE id_immeuble = ? LIMIT 1");
    $stI->execute([$immId]);
    $infos = $stI->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Mandats (immeubles_gestion) : qui gère, à quel titre ──
$mandats = [];
try {
    $stG = $pdo->prepare("SELECT ig.*, s.nom AS societe_nom, e.nom AS agence_nom,
        CONCAT_WS(' ', ug.prenom, ug.nom) AS gestionnaire,
        CONCAT_WS(' ', ua.prenom, ua.nom) AS assistante,
        CONCAT_WS(' ', uc.prenom, uc.nom) AS comptable
        FROM immeubles_gestion ig
        LEFT JOIN societes s       ON s.id  = ig.id_societe
        LEFT JOIN etablissements e ON e.id  = ig.id_agence
        LEFT JOIN users ug ON ug.id = ig.id_gestionnaire
        LEFT JOIN users ua ON ua.id = ig.id_assistante
        LEFT JOIN users uc ON uc.id = ig.id_comptable
        WHERE ig.id_immeuble = ? ORDER BY ig.type");
    $stG->execute([$immId]);
    $mandats = $stG->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$mandatTypes = array_values(array_unique(array_filter(array_map(fn($m)=>$m['type'], $mandats))));

// ─── RÔLE SYNDIC — RÈGLE MÉTIER (Emmanuel 2026-07-03) ──────────────────────────
// Nous sommes SYNDIC UNIQUEMENT pour les immeubles dont la référence est un nombre
// à 4 chiffres dans 1000–3999 (« série 1000/2000/3000 »). Tous les autres = GESTION
// (mandat de gestion), même si un syndic externe existe (ex. NEYRET). La RÉFÉRENCE
// fait autorité, elle prime sur immeubles_gestion.type et categorie_mbi.
$refImmRaw   = trim((string)($imm['reference_immeuble'] ?? ''));
$refIsSyndic = ctype_digit($refImmRaw) && strlen($refImmRaw) === 4
             && (int)$refImmRaw >= 1000 && (int)$refImmRaw <= 3999;
$estSyndic   = $refIsSyndic;
$catDeduite  = $refIsSyndic ? 'SYNDIC' : 'GESTION';
// Réf non-syndic → on NE présente PAS de mandat SYNDIC pour nous (donnée immeubles_gestion
// parfois erronée : on est seulement gestionnaire, le syndic est externe — ex. NEYRET).
if (!$refIsSyndic) {
    $mandats     = array_values(array_filter($mandats, fn($m) => strtoupper((string)($m['type'] ?? '')) !== 'SYNDIC'));
    $mandatTypes = array_values(array_filter($mandatTypes, fn($t) => strtoupper((string)$t) !== 'SYNDIC'));
}

// ─── Mandats de GESTION LOCATIVE au niveau des LOTS (table mandats par bien) ──
// Un immeuble peut cumuler : syndic (copro) ET 1..n mandats de gestion sur ses lots.
$mandatsGestionLots = [];
try {
    $bidsImm = array_map(fn($b) => (int)$b['id'], $biens);
    if ($bidsImm) {
        $inImm = implode(',', array_fill(0, count($bidsImm), '?'));
        $stGL = $pdo->prepare("SELECT m.id, m.id_bien, m.type_mandat, m.statut, m.numero_mandat,
                m.date_debut, m.date_fin, b.reference_bien, b.numero_lot, p.nom AS proprio_nom,
                (SELECT bb.id_tiers_locataire FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif'
                     ORDER BY bb.date_prise_effet DESC LIMIT 1) AS loc_tiers_id,
                (SELECT COALESCE(NULLIF(bb.locataire_nom,''), TRIM(CONCAT_WS(' ', tl.prenom, tl.nom)))
                     FROM bien_baux bb LEFT JOIN tiers tl ON tl.id = bb.id_tiers_locataire
                     WHERE bb.id_bien=b.id AND bb.statut='actif'
                     ORDER BY bb.date_prise_effet DESC LIMIT 1) AS loc_nom
            FROM mandats m
            JOIN biens b ON b.id = m.id_bien
            LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
            WHERE m.id_bien IN ($inImm)
              AND LOWER(m.type_mandat) IN ('gerance','gestion','location')
              AND (m.statut='actif' OR m.statut='en_cours' OR m.statut IS NULL)
            ORDER BY b.numero_lot, m.id DESC");
        $stGL->execute($bidsImm);
        $mandatsGestionLots = $stGL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {}

// ─── Statut visuel ──
$nbBiens          = count($biens);
$nbBauxActifs     = array_sum(array_map(fn($b) => (int)$b['nb_baux_actifs'], $biens));
// Vacant = AUCUN bail actif ET non vendu. On NE se fie PAS à statut_occupation (enum corrompu
// en base → un lot loué apparaissait « vacant »). Le bail actif est la source fiable.
$nbBiensVacants   = count(array_filter($biens, fn($b) => (int)($b['nb_baux_actifs'] ?? 0) === 0 && empty($b['prix_vente'])));
$nbBiensVendus    = count(array_filter($biens, fn($b) => !empty($b['prix_vente'])));
$pieceManquantes  = count(array_filter($piecesItems, fn($p) => !$p['ok']));

if ($nbBiens === 0) {
    $statusColor = 'gray'; $statusIcon = '⚪'; $statusMsg = 'Immeuble créé · <strong>aucun lot rattaché</strong>.';
} elseif ($nbBauxActifs > 0) {
    $statusColor = 'green'; $statusIcon = '🏢';
    $statusMsg = "<strong>{$nbBauxActifs} bail" . ($nbBauxActifs > 1 ? 's' : '') . " actif" . ($nbBauxActifs > 1 ? 's' : '') . "</strong> sur {$nbBiens} lots.";
} else {
    $statusColor = 'gray'; $statusIcon = '🏢';
    $statusMsg = "{$nbBiens} lot(s) rattaché(s).";
}
$statusAlertes = $pieceManquantes > 0 ? $pieceManquantes . ' pièce(s) à charger' : '';

$pageTitle    = 'Immeuble · ' . ($imm['reference_immeuble'] ?: '#' . $immId);
$pageSubtitle = 'Vue 360° · ' . ($imm['ville'] ?? '');
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<?php
// ─── HEADER ──
$badge = null;
if ($nbBauxActifs > 0) $badge = ['label'=>'Loué','class'=>'loue'];
elseif ($nbBiensVacants > 0) $badge = ['label'=>'Vacant partiel','class'=>'vacant'];

$metas = [];
if (!empty($imm['nb_lots']))     $metas[] = ['icon'=>'🏘','text'=>$imm['nb_lots'] . ' lots'];
if (!empty($imm['nb_niveaux']))  $metas[] = ['icon'=>'🏗','text'=>$imm['nb_niveaux'] . ' niveaux'];
if (!empty($imm['annee_construction'])) $metas[] = ['icon'=>'📅','text'=>'Construit en ' . $imm['annee_construction']];
if (!empty($imm['type_immeuble'])) $metas[] = ['icon'=>'🏢','text'=>$imm['type_immeuble']];

fiche360_header(
    '🏢',
    $nomAffichage,
    $badge,
    $adresseComplete ?: 'Adresse non renseignée',
    $metas,
    [
        ['label'=>'✏️ Éditer','url'=>app_url('/agency_immeuble_form.php?id=' . $immId),'class'=>'tr-btn'],
        ['label'=>'📁 Documents','url'=>app_url('/immeuble_documents_list.php?id=' . $immId),'class'=>'tr-btn tr-btn-primary'],
    ]
);

?>

<style>
.i360-grid3 { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
@media (max-width:900px){ .i360-grid3 { grid-template-columns:1fr; } }
.i360-grid3 > div { min-width:0; }

/* Card Actions — fond bleu pétrole (charte) */
.i360-grid3 .f360-actions { background:linear-gradient(155deg,#34586b,#243f4d); }
.i360-grid3 .f360-actions a:hover { background:rgba(255,255,255,.10); }

/* Boutons d'action du header 360° — style doux (cartes blanches) */
.f360-header-actions { gap:10px; flex-wrap:wrap; }
.f360-header-actions .tr-btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 15px; border-radius:13px;
    border:1px solid #f0ede7; background:#fff; color:#4a5568;
    font-size:13px; font-weight:600; line-height:1; white-space:nowrap;
    text-decoration:none; cursor:pointer;
    box-shadow:0 2px 6px rgba(36,59,92,.08), 0 1px 2px rgba(36,59,92,.04);
    transition:transform .14s ease, box-shadow .14s ease, background .14s ease, color .14s ease;
}
.f360-header-actions .tr-btn:hover {
    color:#243B5C; background:#fbfaf7;
    transform:translateY(-1px);
    box-shadow:0 5px 14px rgba(36,59,92,.12), 0 2px 4px rgba(36,59,92,.06);
}
.f360-header-actions .tr-btn-primary { background:#f3f6fb; color:#2d4a72; border-color:#e3ebf5; }
.f360-header-actions .tr-btn-primary:hover { background:#eaf1fa; color:#243B5C; }
</style>

<?php require_once __DIR__ . '/inc/financement.php'; echo fin_related_block($pdo, 'IMMEUBLE', $immId); ?>
<div class="i360-grid3">

  <!-- ═══════ ZONE GAUCHE : Barre IA + onglets ═══════ -->
  <div style="min-width:0;">

    <?php fiche360_ia_bar('immeuble', $immId, "Demander à l'IA sur cet immeuble (lots, occupation, charges, syndic…)"); ?>

    <?php
    $H  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    // mini-helper d'affichage champ/valeur
    $fld = function(string $label, $val, string $suffix='') use ($H) {
        $val = trim((string)$val);
        if ($val === '') return '';
        return '<div style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid #f5f3ef;font-size:13px">'
             . '<span style="color:#7a766f;min-width:190px">'.$H($label).'</span>'
             . '<span style="font-weight:600">'.$H($val).$H($suffix).'</span></div>';
    };
    $oui = fn($v) => !empty($v) ? 'Oui' : '';
    ?>
    <style>
    .imm-inner{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}
    @media(max-width:1100px){.imm-inner{grid-template-columns:1fr}}
    .imm-inner>div{min-width:0}
    .imm-sub{display:inline-block;padding:6px 12px;margin:0 6px 12px 0;border-radius:8px;background:#eef2f7;color:#3a6890;font-weight:600;font-size:12px}
    </style>

    <div class="imm-inner">

      <!-- ░░░ SOUS-COLONNE GAUCHE : Identité + Syndic ░░░ -->
      <div>
      <div class="f360-card">
        <h3>🪪 Identité</h3>
        <?= $fld('Référence', $imm['reference_immeuble']) ?>
        <?= $fld('Nom', $imm['nom_immeuble']) ?>
        <?= $fld('Adresse', $imm['adresse_1']) ?>
        <?= $fld('Code postal', $imm['code_postal']) ?>
        <?= $fld('Ville', $imm['ville']) ?>
        <?= $fld('Type', $imm['type_immeuble']) ?>
        <?= $fld('GPS', (!empty($imm['latitude']) ? $imm['latitude'].', '.$imm['longitude'] : '')) ?>
        <?php if (!empty($imm['latitude'])): ?>
          <div style="padding:8px 0"><a href="https://www.google.com/maps?q=<?= $H($imm['latitude'].','.$imm['longitude']) ?>" target="_blank" rel="noopener">🗺 Voir sur la carte</a></div>
        <?php endif; ?>
      </div>

      <!-- Données publiques EXTRAITES (persistées à la création : cadastre, PLU, altitude, copro) -->
      <?php
        $pubFields =
            $fld('Parcelle cadastrale',   $imm['parcelle_reference'] ?? '')
          . $fld('Référence cadastrale',  $imm['reference_cadastrale'] ?? '')
          . $fld('Zone PLU',              $imm['zone_plu'] ?? '')
          . $fld('Altitude',              $imm['altitude'] ?? '', ' m')
          . $fld('Immatriculation copro', $imm['registre_copro_immatriculation'] ?? '')
          . $fld('Période construction',  $imm['registre_copro_periode'] ?? '')
          . $fld('Lots copropriété',      $imm['copro_nb_lots'] ?? '')
          . $fld('Registre copro (MAJ)',  substr((string)($imm['registre_copro_maj'] ?? ''), 0, 10));
        $pubMaj = array_filter([
            'Cadastre' => substr((string)($imm['enrichi_cadastre_le'] ?? ''), 0, 10),
            'Registre' => substr((string)($imm['enrichi_registre_le'] ?? ''), 0, 10),
            'Risques'  => substr((string)($imm['enrichi_risques_le']  ?? ''), 0, 10),
        ]);
      ?>
      <?php if ($pubFields !== ''): ?>
      <div class="f360-card">
        <h3>🌐 Données publiques <small style="font-weight:400;color:#94a3b8;">extraites automatiquement</small></h3>
        <?= $pubFields ?>
        <?php if ($pubMaj): ?>
          <div style="margin-top:8px;font-size:11px;color:#94a3b8;">
            Enrichi : <?= $H(implode(' · ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($pubMaj), array_values($pubMaj)))) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Infos publiques (Registre National des Copropriétés) — repliée + chargée à la demande -->
      <details class="f360-card" id="imm-public-card">
        <summary style="cursor:pointer;list-style:none;outline:none;">
          <h3 style="display:inline;">🏛️ Infos publiques <small style="font-weight:400;color:#94a3b8;">registre copropriété (RNC) · cliquer pour déplier</small></h3>
        </summary>
        <div id="imm-public-body" style="margin-top:10px;">
          <div style="color:#7a766f;font-size:13px;line-height:1.5;">
            Données officielles du Registre National des Copropriétés (immatriculation, lots, syndic…).
            <div style="margin-top:10px;">
              <button type="button" id="imm-public-load"
                      style="background:#243B5C;color:#fff;border:none;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">
                🏛️ Charger les infos publiques
              </button>
            </div>
          </div>
        </div>
      </details>

      <!-- Syndic (sous l'identité, colonne gauche) -->
      <div class="f360-card">
        <h3>📋 Mandats & gestion</h3>

        <!-- ░ SYNDIC (niveau immeuble : immeubles_gestion) ░ -->
        <?php if (!empty($mandats)): foreach ($mandats as $m): ?>
          <div style="border:1px solid #f0ece6;border-radius:10px;padding:12px;margin-bottom:10px">
            <div style="font-weight:700;color:#243B5C;margin-bottom:6px">
              <?= $H($m['type'] ?: 'MANDAT') ?>
              <span style="font-size:11px;color:#7a766f;font-weight:400">· <?= $H($m['statut']) ?></span>
            </div>
            <?= $fld('Société', $m['societe_nom']) ?>
            <?= $fld('Agence', $m['agence_nom']) ?>
            <?= $fld('Gestionnaire', $m['gestionnaire']) ?>
            <?= $fld('Assistante', $m['assistante']) ?>
            <?= $fld('Comptable', $m['comptable']) ?>
            <?= $fld('Début', $m['date_debut']) ?>
            <?= $fld('Fin', $m['date_fin']) ?>
          </div>
        <?php endforeach; elseif ($catDeduite === 'SYNDIC'): ?>
          <div style="background:#eff6ff;border-left:3px solid #1d4ed8;padding:10px 12px;border-radius:8px;font-size:12.5px;color:#1e3a8a;margin-bottom:10px;">
            🏛️ <strong>Immeuble classé SYNDIC</strong> (déduit du classement copropriété) — mais <strong>aucun mandat syndic enregistré</strong> dans le registre (<code>immeubles_gestion</code>).
            <div style="margin-top:8px;">
              <a class="tr-btn" href="<?= $H(app_url('/agency_immeuble_form.php?id=' . $immId)) ?>">📝 Formaliser le mandat syndic</a>
            </div>
          </div>
        <?php endif; ?>

        <!-- ░ GESTION LOCATIVE (niveau lots : table mandats) ░ -->
        <?php if (!empty($mandatsGestionLots)): ?>
          <div style="margin-top:6px;border-top:1px solid #f0ece6;padding-top:10px;">
            <div style="font-weight:700;color:#15803d;margin-bottom:8px;font-size:13px;">
              🔑 Gestion locative — <?= count($mandatsGestionLots) ?> lot<?= count($mandatsGestionLots) > 1 ? 's' : '' ?> sous mandat
            </div>
            <?php foreach ($mandatsGestionLots as $g):
              $tg = strtolower((string)$g['type_mandat']);
              [$gbg,$gfg,$gic] = match($tg){ 'location'=>['#dbeafe','#1e40af','🔑'], default=>['#d9f0db','#14532d','🏠'] };
            ?>
              <div style="display:flex;align-items:center;gap:10px;padding:7px 10px;background:#fafaf6;border-radius:8px;margin-bottom:6px;font-size:12px;">
                <span style="background:<?= $gbg ?>;color:<?= $gfg ?>;padding:2px 9px;border-radius:99px;font-weight:700;font-size:10.5px;min-width:78px;text-align:center;"><?= $gic ?> <?= $H(ucfirst($tg)) ?></span>
                <span style="flex:1;min-width:0;">
                  Lot <strong><?= $H($g['numero_lot'] ?: '—') ?></strong>
                  · <a href="<?= $H(app_url('/bien_360.php?id=' . (int)$g['id_bien'])) ?>" style="color:#4878a6;text-decoration:none;font-family:'DM Mono',monospace;"><?= $H($g['reference_bien'] ?: '#'.(int)$g['id_bien']) ?></a>
                  <?php $glNom = trim((string)($g['loc_nom'] ?? '')); $glTid = (int)($g['loc_tiers_id'] ?? 0); ?>
                  <?php if ($glNom !== ''): ?>
                    · 👤 <?php if ($glTid > 0): ?><a href="<?= $H(app_url('/tiers_360.php?id=' . $glTid)) ?>" style="color:#2d5f6b;text-decoration:none;font-weight:700;border-bottom:1px dotted #8fb3bb;"><?= $H($glNom) ?></a><?php else: ?><strong style="color:#2d5f6b;"><?= $H($glNom) ?></strong><?php endif; ?>
                  <?php else: ?>
                    · <span style="color:#b9b4ac;">🔓 vacant</span>
                  <?php endif; ?>
                  <?php if ($g['proprio_nom']): ?> <span style="color:#9a9690;">· prop. <?= $H($g['proprio_nom']) ?></span><?php endif; ?>
                </span>
                <span style="color:#7a766f;font-size:10.5px;"><?= $H(ucfirst((string)($g['statut'] ?: 'actif'))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- ░ Vraiment aucun mandat (ni syndic réel/déduit, ni gestion lot) ░ -->
        <?php if (empty($mandats) && $catDeduite !== 'SYNDIC' && empty($mandatsGestionLots)): ?>
          <div class="f360-empty"><div class="em-ico">📋</div>Aucun mandat enregistré pour cet immeuble.</div>
        <?php endif; ?>
      </div>
      <div class="f360-card">
        <h3>🗳 Assemblée générale</h3>
        <?= $fld('Dernière AG', $infos['date_ag_derniere'] ?? '') ?>
        <?= $fld('Prochaine AG', $infos['date_ag_prochaine'] ?? '') ?>
        <?= $fld('AG 2025', $infos['ag_2025'] ?? '') ?>
        <?= $fld('AG 2026', $infos['ag_2026'] ?? '') ?>
        <?= $fld('AG 2027', $infos['ag_2027'] ?? '') ?>

        <?php
        // ── PV d'assemblée générale — un emplacement par millésime, sans écrasement ──
        $pvN1 = $refIsSyndic ? '04_SYNDIC' : '03_GESTION_LOCATIVE';
        $pvPrefillBase = [
            'origin'        => 'immeuble_360',
            'immeuble_id'   => (int)$immId,
            'entite_id_bdd' => (int)$immId,
            'soc_id'        => (int)($imm['id_societe'] ?? 0),
            'age_id'        => (int)($imm['id_agence'] ?? 0),
            'n1'            => $pvN1,
            // Classement figé sous l'activité RÉELLE de l'immeuble (GESTION ici, pas SYNDIC) :
            // dossier immeuble / PV d'AG. « Syndic = simple rôle » (doctrine réf immeuble).
            'n2'            => '04_immeuble',
            'n3'            => '02_pv_ag',
            'entite_nom'    => (string)($imm['reference_immeuble'] ?: ($imm['nom_immeuble'] ?? '')),
            'forced_type_doc' => 'pv_ag',
        ];
        $pvBtn = function(array $extra, string $label, string $style) use ($pvPrefillBase, $H) {
            $pf = array_merge($pvPrefillBase, $extra);
            $json = htmlspecialchars(json_encode($pf, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            return '<button type="button" style="' . $style . '" data-pf="' . $json . '" '
                 . 'onclick="try{window.fbxOpenUploadModal(JSON.parse(this.dataset.pf));}catch(e){console.error(e);}return false;">'
                 . $H($label) . '</button>';
        };
        $pvBtnStyleMain = 'border:1px solid #cddbe0;background:#fff;color:#2d5f6b;border-radius:8px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer';
        $pvBtnStyleMini = 'border:1px dashed #b8c7cd;background:#f6fafb;color:#2d5f6b;border-radius:7px;padding:1px 8px;font-size:10.5px;font-weight:700;cursor:pointer';
        ?>
        <div style="margin-top:12px;border-top:1px solid #f0ece6;padding-top:10px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px">
            <strong style="font-size:12.5px;color:#243B5C">📑 PV d'assemblée <small style="color:#9a9690;font-weight:600"><?= count($pvDocs) ?> chargé<?= count($pvDocs) > 1 ? 's' : '' ?></small></strong>
            <?= $pvBtn([], '+ PV (autre année)', $pvBtnStyleMain) ?>
          </div>
          <?php foreach ($pvYears as $yr): $list = $pvByYear[$yr] ?? []; ?>
            <div style="margin-bottom:8px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
                <span style="font-weight:700;font-size:12px;color:<?= $list ? '#2f6b3f' : '#b45309' ?>"><?= $list ? '✓' : '⚠' ?> <?= (int)$yr ?></span>
                <?= $pvBtn(['doc_period_hint' => sprintf('%04d', $yr)], '+ charger', $pvBtnStyleMini) ?>
              </div>
              <?php if (!$list): ?>
                <div style="font-size:11px;color:#9a9690;padding-left:16px">— aucun PV pour cet exercice —</div>
              <?php else: foreach ($list as $pv): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:11.5px;padding:2px 0 2px 16px">
                  <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= $pv['libelle'] !== '' ? '<strong>' . $H($pv['libelle']) . '</strong> · ' : '' ?><?= $H($pv['name'] ?: 'PV') ?>
                  </span>
                  <span style="color:#9a9690;font-size:10px;white-space:nowrap"><?= $pv['ts'] ? $H(date('m/Y', $pv['ts'])) : '' ?></span>
                </div>
              <?php endforeach; endif; ?>
            </div>
          <?php endforeach; ?>
          <?php // Années plus anciennes possédant des PV mais hors des 3 dernières sont déjà incluses dans $pvYears. ?>
        </div>

        <div style="padding-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a class="tr-btn" href="<?= $H(app_url('/agency_reunions.php?id_immeuble=' . $immId)) ?>">📅 Réunions</a>
          <a class="tr-btn" href="<?= $H(app_url('/agency_immeuble_fiche.php?id=' . $immId . '#ag')) ?>">📑 Retour AG</a>
        </div>
      </div>
      <div class="f360-card">
        <h3>📄 Contrats & honoraires</h3>
        <?= $fld('Syndic actuel', $imm['syndic_actuel'] ?? '') ?>
        <?= $fld('Honoraires HT', $infos['honoraires_ht'] ?? '', ' €') ?>
        <?= $fld('Honoraires 2026', $infos['hono_2026'] ?? '', ' €') ?>
        <?= $fld('Honoraires 2027', $infos['hono_2027'] ?? '', ' €') ?>
        <?= $fld('Contrats', $infos['contrats'] ?? '') ?>
        <?= $fld('Codes d\'accès', $infos['codes'] ?? '') ?>
        <?= $fld('Boîte à clés', $infos['boite_cles'] ?? '') ?>
        <?= $fld('Débiteurs', $infos['debiteurs'] ?? '') ?>
      </div>
      </div><!-- ░░░ fin SOUS-COLONNE GAUCHE ░░░ -->

      <!-- ░░░ SOUS-COLONNE DROITE : Lots + Descriptif + Travaux + Documents ░░░ -->
      <div>
    <!-- Lots de l'immeuble -->
    <?php
    // Partition : actifs (visibles) vs sortis = archivés/vendus (repliés)
    $biensActifs = array_filter($biens, fn($b) => !in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));
    $biensSortis = array_filter($biens, fn($b) =>  in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));

    // Closure de rendu d'une ligne ; $mode = 'actif' | 'sorti'
    $renderRow = function(array $b, string $mode): string {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $statut    = $b['nb_baux_actifs'] > 0 ? 'Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? 'Vacant' : '—');
        $statutCol = $b['nb_baux_actifs'] > 0 ? '#2d6a35' : (($b['statut_occupation'] ?? '') === 'vacant' ? '#8a4c12' : '#7a766f');
        $aAnnonceActive = (int)($b['nb_annonces_actives'] ?? 0) > 0;
        $stBien = (string)($b['statut_bien'] ?? '');
        if ($mode === 'sorti') { $statut = $stBien === 'vendu' ? 'Vendu' : 'Archivé'; $statutCol = $stBien === 'vendu' ? '#2d6a35' : '#9a9690'; }
        $ref  = $h(app_url('/bien_360.php?id=' . $b['id']));
        $usageLabel = $b['usage_bien'] ?: ($b['bail_nature'] ?: '—');   // reprend la nature du bail (habitation) à défaut
        $etageLabel = ($b['etage'] !== null && $b['etage'] !== '') ? (((int)$b['etage'] === 0) ? 'RDC' : $h($b['etage'])) : '—';
        $cells = '<td style="padding:6px 4px;"><a href="'.$ref.'" style="color:#4878a6;text-decoration:none;font-family:\'DM Mono\',monospace;">'.$h($b['reference_bien'] ?: '#' . $b['id']).'</a></td>'
            . '<td style="padding:6px 4px;">'.$h($b['numero_lot'] ?: '—').'</td>'
            . '<td style="padding:6px 4px;">'.$h($usageLabel).'</td>'
            . '<td style="padding:6px 4px;text-align:right;">'.($b['surface_habitable'] ? number_format((float)$b['surface_habitable'],0).' m²' : '—').'</td>'
            . '<td style="padding:6px 4px;text-align:center;">'.$etageLabel.'</td>'
            . '<td style="padding:6px 4px;">'.$h($b['proprio_nom'] ?: '—').'</td>'
            . '<td style="padding:6px 4px;color:'.$statutCol.';font-weight:600;">'.$h($statut).'</td>';
        // colonne Action
        $act = '';
        if ($mode === 'actif') {
            if ($aAnnonceActive) {
                $act = '<span title="Annonce active : archivage bloqué" style="font-size:11px;color:#8a4c12;cursor:help;">🔒 Annonce active</span>';
            } else {
                $act = '<button type="button" class="bien-archive-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #e0d9cf;border-radius:6px;background:#fff;color:#8a4c12;cursor:pointer;">📦 Archiver</button>';
            }
        } else { // sorti
            if ($stBien === 'vendu') {
                $act = '<span style="font-size:11px;color:#2d6a35;font-weight:600;">✅ Vendu</span>';
            } elseif ($aAnnonceActive) {
                $act = '<span title="Annonce active" style="font-size:11px;color:#8a4c12;cursor:help;">🔒 Annonce active</span>';
            } else {
                $act = '<button type="button" class="bien-vendu-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #cfe0d2;border-radius:6px;background:#fff;color:#2d6a35;cursor:pointer;margin-right:4px;">✅ Vendu</button>'
                     . '<button type="button" class="bien-supprimer-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #e6c4c0;border-radius:6px;background:#fff;color:#a23b2e;cursor:pointer;">🗑 Supprimer</button>';
            }
        }
        $cells .= '<td style="padding:6px 4px;text-align:right;">'.$act.'</td>';
        return '<tr style="border-bottom:1px solid #f5f3ef;'.($mode==='sorti'?'opacity:.7;':'').'" data-bien-row="'.(int)$b['id'].'">'.$cells.'</tr>';
    };
    $thead = '<thead><tr style="text-align:left;color:#7a766f;border-bottom:1px solid #f0ece6;">'
        . '<th style="padding:6px 4px;">Réf.</th><th style="padding:6px 4px;">Lot</th><th style="padding:6px 4px;">Usage</th>'
        . '<th style="padding:6px 4px;text-align:right;">Surface</th><th style="padding:6px 4px;text-align:center;">Étage</th><th style="padding:6px 4px;">Propriétaire</th>'
        . '<th style="padding:6px 4px;">Statut</th><th style="padding:6px 4px;text-align:right;">Action</th></tr></thead>';
    ?>
    <div class="f360-card" id="imm-lots">
        <h3>🏘 Lots de l'immeuble <span class="count"><?= count($biensActifs) ?></span></h3>
        <?php if (empty($biensActifs)): ?>
            <div class="f360-empty"><div class="em-ico">🏘</div>Aucun bien actif rattaché à cet immeuble.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <?= $thead ?>
                <tbody><?php foreach ($biensActifs as $b) echo $renderRow($b, 'actif'); ?></tbody>
            </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($biensSortis)): ?>
        <details style="margin-top:14px;">
            <summary style="cursor:pointer; font-size:12px; color:#7a766f; font-weight:600; user-select:none;">
                📦 Biens archivés / vendus (<?= count($biensSortis) ?>) — déplier
            </summary>
            <div style="overflow-x:auto; margin-top:8px;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <?= $thead ?>
                <tbody><?php foreach ($biensSortis as $b) echo $renderRow($b, 'sorti'); ?></tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var CSRF = <?= json_encode(csrf_token('archiver_bien')) ?>;
        var URL_ARCHIVE = '<?= h(app_url('/api/bien_archiver.php')) ?>';
        var URL_ACTION  = '<?= h(app_url('/api/bien_archive_action.php')) ?>';

        function post(url, params, okCb, label){
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            Object.keys(params).forEach(function(k){ fd.append(k, params[k]); });
            return fetch(url, {method:'POST', body:fd, headers:{'X-CSRF-Token':CSRF}})
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (j.success){ okCb(j); }
                    else { alert(j.message || (label + ' impossible.')); }
                    return j;
                })
                .catch(function(){ alert('Erreur réseau.'); });
        }

        // Archiver (lots actifs)
        document.querySelectorAll('.bien-archive-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('Archiver ce bien ?')) return;
                btn.disabled = true; btn.textContent = '…';
                post(URL_ARCHIVE, {id_bien: btn.dataset.id}, function(){ location.reload(); }, 'Archivage')
                    .then(function(j){ if(!j || !j.success){ btn.disabled=false; btn.textContent='📦 Archiver'; } });
            });
        });

        // Marquer Vendu (le bien reste)
        document.querySelectorAll('.bien-vendu-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('Marquer ce bien comme VENDU ? (il reste dans le patrimoine)')) return;
                btn.disabled = true; btn.textContent = '…';
                post(URL_ACTION, {id_bien: btn.dataset.id, action:'vendu'}, function(){ location.reload(); }, 'Action')
                    .then(function(j){ if(!j || !j.success){ btn.disabled=false; btn.textContent='✅ Vendu'; } });
            });
        });

        // Supprimer (vers table d'archive biens_supprimes, disparaît)
        document.querySelectorAll('.bien-supprimer-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('SUPPRIMER définitivement ce bien ? (archivé dans biens_supprimes, réversible côté base)')) return;
                btn.disabled = true; btn.textContent = '…';
                post(URL_ACTION, {id_bien: btn.dataset.id, action:'supprimer'}, function(){
                    var row = document.querySelector('[data-bien-row="'+btn.dataset.id+'"]');
                    if (row) row.remove();
                }, 'Suppression')
                    .then(function(j){ if(!j || !j.success){ btn.disabled=false; btn.textContent='🗑 Supprimer'; } });
            });
        });
    })();
    </script>
    <!-- /lots -->

      <!-- Descriptif (déplacé en colonne droite) -->
      <div class="f360-card">
        <h3>📐 Descriptif</h3>
        <?= $fld('Année de construction', $imm['annee_construction']) ?>
        <?= $fld('Niveaux', $imm['nb_niveaux']) ?>
        <?= $fld('Bâtiments', $imm['nb_batiments']) ?>
        <?php $nbLotsEff = ((int)($imm['nb_lots'] ?? 0)) ?: ((int)($imm['copro_nb_lots'] ?? 0)); ?>
        <div class="f360-fld" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:6px 0;">
          <span style="min-width:150px;color:#64748b;font-size:12px;">Nombre de lots</span>
          <input type="number" id="imm-nb-lots" value="<?= $nbLotsEff ?: '' ?>" min="0"
                 placeholder="—" style="width:90px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
          <button type="button" id="imm-nb-lots-save"
                  style="border:none;background:#0e7490;color:#fff;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;cursor:pointer;">💾 Enregistrer</button>
          <span id="imm-nb-lots-status" style="font-size:11px;"></span>
        </div>
        <script>
        (function(){
          var btn=document.getElementById('imm-nb-lots-save'); if(!btn) return;
          btn.addEventListener('click', async function(){
            var inp=document.getElementById('imm-nb-lots');
            var st=document.getElementById('imm-nb-lots-status');
            st.textContent='⏳…'; st.style.color='#64748b';
            var fd=new FormData();
            fd.append('immeuble_id', '<?= (int)$immId ?>');
            fd.append('nb_lots', inp.value || '0');
            fd.append('csrf_token', <?= json_encode(csrf_token('immeuble_lots')) ?>);
            try{
              var r=await fetch(<?= json_encode(app_url('/api/immeuble_lots_save.php')) ?>, {method:'POST', body:fd, credentials:'same-origin'});
              var j=await r.json();
              st.textContent = j.ok ? '✅ Enregistré' : ('❌ '+(j.error||'Erreur'));
              st.style.color = j.ok ? '#059669' : '#dc2626';
            }catch(e){ st.textContent='❌ '+e.message; st.style.color='#dc2626'; }
          });
        })();
        </script>
        <?= $fld('Ascenseur', $oui($imm['presence_ascenseur'] ?? 0)) ?>
        <?= $fld('Gardien', $oui($imm['gardien'] ?? 0)) ?>
        <?= $fld('Chauffage collectif', $oui($imm['chauffage_collectif'] ?? 0)) ?>
        <?= $fld('Espace vert', $oui($imm['espace_vert'] ?? 0)) ?>
        <?= $fld('Lots copropriété', $imm['copro_nb_lots'] ?? '') ?>
        <?= $fld('Tantièmes total', $imm['copro_tantiemes_total'] ?? '') ?>
        <?= $fld('Budget prévisionnel copro', $imm['copro_budget_previsionnel_annuel'] ?? '', ' €') ?>
        <?php if (!trim((string)($imm['annee_construction'] ?? '')) && !($imm['nb_niveaux'] ?? 0)): ?>
          <div class="f360-empty" style="padding:10px"><div class="em-ico">📐</div>Descriptif à compléter.</div>
        <?php endif; ?>
      </div>
      <div class="f360-card">
        <h3>🔧 Travaux</h3>
        <?= $fld('Travaux en cours', $infos['travaux_cours'] ?? '') ?>
        <?= $fld('Travaux à prévoir', $infos['travaux_prevoir'] ?? '') ?>
        <?= $fld('Interventions en cours', $infos['interventions_cours'] ?? '') ?>
        <?= $fld('Sinistres', $infos['sinistres'] ?? '') ?>
        <?php if (empty($infos['travaux_cours']) && empty($infos['travaux_prevoir']) && empty($infos['interventions_cours']) && empty($infos['sinistres'])): ?>
          <div class="f360-empty" style="padding:10px"><div class="em-ico">🔧</div>Aucun travaux renseigné.</div>
        <?php endif; ?>
      </div>

    <!-- Documents de l'immeuble -->
    <div class="f360-card">
        <h3>📂 Documents de l'immeuble <span class="count"><?= count($docs) ?></span>
            <button type="button" onclick="gedToggleArchives(this,'IMB',<?= (int)$immId ?>)" style="float:right;border:1px solid #e0d6c4;background:#fbf7ef;color:#a26a1c;border-radius:7px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">📦 Voir les archives</button></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/immeuble_documents_list.php?id=' . $immId)) ?>">→ Gérer les documents</a></div>
        <?php else: foreach ($docs as $d): ?>
            <div style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center;">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;">[<?= h($d['document_type']) ?>]</span>
                <?php $dLbl = ged_doc_tail_from_level($d, 'immeuble'); ?>
                <span style="flex:1;"><a href="javascript:void(0)" onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>)" style="color:#243B5C; text-decoration:none; font-weight:600;" title="<?= h($d['name_display']) ?>">📄 <?= h($dLbl) ?></a></span>
                <span style="color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                <button type="button" onclick="gedDeleteDoc(<?= (int)$d['id'] ?>,<?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>,this)" title="Supprimer" style="border:none;background:transparent;color:#c0392b;cursor:pointer;font-size:13px;padding:0 2px;">🗑️</button>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php require_once __DIR__ . '/inc/ged_delete_modal.php'; ?>
    <?php if (!defined('MVPT_DOC_VIEWER_LOADED')) { define('MVPT_DOC_VIEWER_LOADED', 1); include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; } /* modale standard mvptModalView — ouvrir un doc depuis la fiche */ ?>

    <!-- Dossiers sources (archives OneDrive liées, non importées) — inclusion défensive -->
    <?php
    $gsfCardFile = __DIR__ . '/inc/ged_source_folders_card.php';
    if (is_file($gsfCardFile)) { require_once $gsfCardFile;
        if (function_exists('ged_source_folders_card')) { try {
            ged_source_folders_card($pdo, 'IMB', $immId, ['id_societe'=>(int)($imm['id_societe'] ?? 0), 'id_agence'=>(int)($imm['id_agence'] ?? 0)]);
        } catch (Throwable $e) {} } }
    ?>

    <!-- Mentionné dans -->
    <?php
    $mentionsForLayout = array_map(fn($m) => [
        'icon'  => '📄',
        'title' => $m['name_display'],
        'ref'   => $m['document_type'] . ' · ' . date('d/m/y', strtotime((string)$m['created_at'])),
        'url'   => null,
    ], $mentions);
    fiche360_mention_dans($mentionsForLayout);
    ?>

      </div><!-- ░░░ fin SOUS-COLONNE DROITE ░░░ -->
    </div><!-- fin .imm-inner -->

  </div>

  <!-- ═══════════════════ COLONNE DROITE — ACTIONS + CONTACTS ═══════════════════ -->
  <div style="min-width:0;">

    <?php
    // Métier (N1) induit par l'immeuble : réf syndic (commence par 1/2/3xxx) → SYNDIC,
    // tous les autres immeubles → GESTION. (Doctrine 2026-06-30 : jamais transaction par défaut.)
    $n1Imm = $refIsSyndic
        ? '04_SYNDIC'
        : '03_GESTION_LOCATIVE';
    // Propriétaire de l'immeuble : résolu SEULEMENT s'il est UNIQUE sur tous les lots
    // (un immeuble multi-propriétaires reste ambigu → on ne préremplit pas). Passé au modal
    // pour que le doc chargé depuis l'immeuble soit rattaché au bon propriétaire.
    $immProprioId = 0; $immProprioNom = ''; $immProprioTiersId = 0;
    try {
        $qp = $pdo->prepare("SELECT p.id AS pid, p.id_tiers AS tid,
                COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS pnom
              FROM biens b JOIN proprietaires p ON p.id = b.id_proprietaire
             WHERE b.id_immeuble = ? AND b.id_proprietaire IS NOT NULL AND b.id_proprietaire > 0
             GROUP BY p.id LIMIT 2");
        $qp->execute([$immId]);
        $rowsP = $qp->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rowsP) === 1) {
            $immProprioId      = (int)($rowsP[0]['pid'] ?? 0);
            $immProprioTiersId = (int)($rowsP[0]['tid'] ?? 0);
            $immProprioNom     = trim((string)($rowsP[0]['pnom'] ?? ''));
        }
    } catch (Throwable $e) {}
    // Panneau Actions — remonté EN HAUT de la colonne pour visibilité immédiate
    fiche360_actions_panel('Actions immeuble', [
        ['icon'=>'📤','label'=>'Charger des documents','url'=>'#','onclick'=>"window.fbxOpenUploadModal({origin:'immeuble_360', immeuble_id:" . (int)$immId . ", entite_id_bdd:" . (int)$immId . ", soc_id:" . (int)($imm['id_societe'] ?? 0) . ", age_id:" . (int)($imm['id_agence'] ?? 0) . ", n1:'" . $n1Imm . "', proprio_id:" . $immProprioId . ", proprio_tiers_id:" . $immProprioTiersId . ", proprio_nom:'" . addslashes($immProprioNom) . "', entite_nom:'" . addslashes((string)($imm['reference_immeuble'] ?: $nomAffichage)) . "'});return false;"],
        ['icon'=>'📧','label'=>'Envoyer un document par mail','url'=>mail_compose_url('IMB', $immId, 'immeuble_360.php?id=' . $immId)],
        ['icon'=>'🏛️','label'=>'Charger les infos publiques (RNC)','url'=>'#','onclick'=>'immLoadPublicInfo();return false;'],
        ['icon'=>'➕','label'=>'Ajouter un bien à cet immeuble','url'=>app_url('/bien_detail.php?id_immeuble=' . $immId)],
        ['icon'=>'📁','label'=>'Documents de l\'immeuble',     'url'=>app_url('/immeuble_documents_list.php?id=' . $immId)],
        ['icon'=>'✏️','label'=>'Éditer l\'immeuble',           'url'=>app_url('/agency_immeuble_form.php?id=' . $immId)],
        ['icon'=>'🗺','label'=>'Voir sur carte',
         'url'=>(!empty($imm['latitude']) && !empty($imm['longitude']))
                    ? 'https://www.google.com/maps?q=' . $imm['latitude'] . ',' . $imm['longitude']
                    : 'https://www.google.com/maps?q=' . urlencode($adresseComplete),
         'target'=>'_blank'],
    ]);

    $fbxPrefillImm = [
        'origin'        => 'immeuble_360',
        'immeuble_id'   => (int)$immId,
        'immeuble_nom'  => (string)($imm['nom_immeuble'] ?? ''),
        'entite_id_bdd' => (int)$immId,
        'soc_id'        => (int)($imm['id_societe'] ?? 0),
        'age_id'        => (int)($imm['id_agence'] ?? 0),
        'n1'            => (string)$n1Imm,
        'entite_nom'    => (string)($imm['reference_immeuble'] ?: $nomAffichage),
    ];
    fiche360_checklist('Pièces immeuble', $piecesItems, $fbxPrefillImm);

    // CONTACTS : propriétaires rattachés (→ fiche tiers 360° si rattaché, sinon recherche proprios)
    if (!empty($proprios)) {
        $contactLinks = [];
        foreach ($proprios as $p) {
            $contactLinks[] = [
                'icon' => '🏠',
                'name' => ($p['nom'] ?: 'Propriétaire #' . $p['id']) . ' — Propriétaire',
                'ref'  => $p['nb_biens'] . ' lot(s) dans cet immeuble',
                'url'  => !empty($p['id_tiers'])
                    ? app_url('/tiers_360.php?id=' . (int)$p['id_tiers'])
                    : app_url('/agency_proprietaires.php?q=' . urlencode($p['nom'] ?: '')),
            ];
        }
        // DÉFENSIF : ne casse jamais la colonne même si include/table/dépendance manque.
        $eaBtn = '';
        try {
            if (is_file(__DIR__ . '/inc/entite_acteurs.php')) {
                require_once __DIR__ . '/inc/entite_acteurs.php';
                if (function_exists('entite_acteurs_links'))         $contactLinks = array_merge($contactLinks, entite_acteurs_links($pdo, 'IMB', $immId, csrf_token('default')));
                if (function_exists('entite_acteurs_header_button')) $eaBtn = entite_acteurs_header_button('ea_imm', 'IMB', $immId, csrf_token('default'));
            }
        } catch (\Throwable $e) { $eaBtn = ''; }
        fiche360_attach('CONTACTS (' . count($contactLinks) . ')', $contactLinks, $eaBtn);
    }

    // Synthèse occupation
    fiche360_attach('OCCUPATION', [
        // Ancres vers la section « Lots » de la page (un href="#" nu provoquait une
        // déconnexion/retour racine .fr en prod). '#imm-lots' = simple fragment, sûr.
        ['icon'=>'🏘','name'=>$nbBiens . ' lot(s) rattaché(s)','ref'=>'','url'=>'#imm-lots'],
        ['icon'=>'✅','name'=>$nbBauxActifs . ' bail(x) actif(s)','ref'=>'','url'=>'#imm-lots'],
        ['icon'=>'🔓','name'=>$nbBiensVacants . ' lot(s) vacant(s)','ref'=>'','url'=>'#imm-lots'],
        ['icon'=>'💰','name'=>$nbBiensVendus . ' lot(s) vendu(s)','ref'=>'','url'=>'#'],
    ]);

    ?>

  </div>
</div>

<?= fiche360_js() ?>

<script>
// ─── Infos publiques (Registre National des Copropriétés) — chargement à la demande ───
(function(){
  var API = <?= json_encode(app_url('/api/registre_copro.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var SAVE_API = <?= json_encode(app_url('/api/immeuble_enrichir_save.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var CTX = {
    immId: <?= (int)($imm['id'] ?? 0) ?>,
    canSave: <?= ((function_exists('current_role_id') && in_array((int)current_role_id(), [1,7,9,10], true)) || (function_exists('is_super_admin') && is_super_admin())) ? 'true' : 'false' ?>,
    csrf: <?= json_encode(function_exists('csrf_token') ? csrf_token('immeuble_enrichir') : '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    cp:   <?= json_encode((string)($imm['code_postal'] ?? '')) ?>,
    voie: <?= json_encode((string)($imm['adresse_1'] ?? '')) ?>,
    lat:  <?= json_encode((string)($imm['latitude'] ?? '')) ?>,
    lng:  <?= json_encode((string)($imm['longitude'] ?? '')) ?>
  };

  // Persiste le RNC sur l'immeuble (colonnes registre_copro_*) → visible sur les fiches
  // bien liées. Auto SEULEMENT si le match est cohérent (même CP). Best-effort.
  function immPersistRegistre(j, body){
    if (!CTX.canSave || !CTX.immId || j.coherent === false) return;
    fetch(SAVE_API, {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        immeuble_id: CTX.immId, csrf: CTX.csrf,
        registre: { immatriculation: j.immatriculation||'', construction: j.construction||'',
                    date_maj: j.date_maj||'', nb_lots: j.nb_lots||0 },
        details: { registre: { title: 'Registre des copropriétés (RNC)', items: (j.infos||[]) } }
      })
    }).then(function(r){ return r.json(); }).then(function(s){
      if (s && s.ok){
        var tag = document.createElement('div');
        tag.style.cssText = 'margin-top:8px;font-size:11.5px;color:#2d8a4e;font-weight:700;';
        tag.textContent = '✓ Enregistré sur l\'immeuble — visible sur les biens liés.';
        body.appendChild(tag);
      }
    }).catch(function(){});
  }
  var esc = function(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; };

  window.immLoadPublicInfo = function(){
    var body = document.getElementById('imm-public-body');
    if (!body) return;
    // Déplie la card (details) + fait défiler + affiche le chargement
    var card = document.getElementById('imm-public-card');
    if (card && 'open' in card) card.open = true;
    card?.scrollIntoView({behavior:'smooth', block:'center'});
    body.innerHTML = '<div style="color:#7a766f;font-size:13px;padding:8px 0;">⏳ Interrogation du registre national…</div>';
    var qs = new URLSearchParams();
    if (CTX.cp)   qs.set('cp', CTX.cp);
    if (CTX.voie) qs.set('voie', CTX.voie);
    if (CTX.lat)  qs.set('lat', CTX.lat);
    if (CTX.lng)  qs.set('lng', CTX.lng);
    fetch(API + '?' + qs.toString(), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok){ body.innerHTML = '<div style="color:#c62828;font-size:13px;">❌ '+esc((j&&j.error)||'Erreur')+'</div>'; return; }
        if (!j.trouve){ body.innerHTML = '<div style="color:#8a6d1b;font-size:13px;">ℹ️ Aucune copropriété trouvée au registre ('+esc(j.raison||'')+').</div>'; return; }
        var html = '';
        if (j.avertissement){ html += '<div style="background:#fef3c7;border:1px solid #d97706;border-radius:8px;padding:8px 10px;color:#92400e;font-size:12px;margin-bottom:10px;">'+esc(j.avertissement)+'</div>'; }
        html += '<div style="display:flex;flex-direction:column;gap:6px;">';
        (j.infos||[]).forEach(function(it){
          html += '<div style="display:grid;grid-template-columns:150px 1fr;gap:8px;padding:5px 0;border-bottom:1px dashed #f0ece6;">'
               +   '<span style="font-size:11px;font-weight:700;color:#7a766f;">'+esc(it.label)+'</span>'
               +   '<span style="font-size:13px;color:#243B5C;font-weight:600;">'+esc(it.value)+'</span>'
               + '</div>';
        });
        html += '</div>';
        html += '<div style="margin-top:8px;font-size:10.5px;color:#94a3b8;">Source : Registre National des Copropriétés (ANAH / data.gouv.fr)'
             + (j.date_maj ? ' · maj '+esc(j.date_maj) : '') + '</div>';
        body.innerHTML = html;
        immPersistRegistre(j, body);   // ← persiste pour que les biens liés le voient
      })
      .catch(function(e){ body.innerHTML = '<div style="color:#c62828;font-size:13px;">❌ Réseau : '+esc(e)+'</div>'; });
  };
  document.getElementById('imm-public-load')?.addEventListener('click', window.immLoadPublicInfo);
})();
</script>

<!-- ── Cartes repliables : tout replié à l'ouverture (demande UX) ── -->
<style>
  .f360-card.f360-collapsed > *:not(h3):not(summary){ display:none !important; }
  .f360-card > h3.f360-collap-h{ cursor:pointer; user-select:none; }
  .f360-card > h3.f360-collap-h::before{ content:'▸ '; color:#b9b3a8; font-size:12px; font-weight:400; }
  .f360-card:not(.f360-collapsed) > h3.f360-collap-h::before{ content:'▾ '; }
</style>
<script>
(function(){
  function initCollapse(){
    document.querySelectorAll('.f360-card').forEach(function(card){
      if (card.tagName.toLowerCase() === 'details') return;      // <details> gèrent déjà leur repli
      var h = card.querySelector(':scope > h3');
      if (!h || h.classList.contains('f360-collap-h')) return;
      h.classList.add('f360-collap-h');
      card.classList.add('f360-collapsed');                      // tout replié à l'ouverture
      h.addEventListener('click', function(e){
        if (e.target.closest('button, a, input, select, textarea, label')) return; // ne pas toggler sur un contrôle
        card.classList.toggle('f360-collapsed');
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initCollapse);
  else initCollapse();
})();
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
