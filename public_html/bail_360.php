<?php
// bail_360.php — Vue 360° d'un bail
// Bien + locataire + bailleur + loyer + échéances + documents + IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$bailId = (int)($_GET['id'] ?? 0);
if ($bailId <= 0) {
    header('Location: ' . app_url('/bien_baux_liste.php'));
    exit;
}

// ─── Charge le bail + bien + locataire ──
$sql = "SELECT bb.*,
    b.id AS bien_id, b.reference_bien, b.designation, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
    b.code_postal AS bien_cp, b.surface_habitable, b.numero_lot, b.id_immeuble,
    b.description AS bien_description, b.etage AS bien_etage, b.bien_en_copropriete, b.lot_tantiemes, b.copro_nb_lots,
    b.id_societe AS bien_soc, b.id_agence AS bien_age,
    i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
    p.id AS proprio_id, p.id_tiers AS proprio_tiers_id,
    COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
    COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom,
    tl.id AS loc_tiers_id, tl.nom_affichage AS loc_tiers_nom, tl.raison_sociale AS loc_raison_sociale,
    tl.email AS loc_email, tl.telephone AS loc_telephone
    FROM bien_baux bb
    INNER JOIN biens b      ON b.id = bb.id_bien
    LEFT JOIN immeubles i   ON i.id = b.id_immeuble
    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
    LEFT JOIN tiers tp      ON tp.id = p.id_tiers
    LEFT JOIN tiers tl      ON tl.id = bb.id_tiers_locataire
    WHERE bb.id = ? LIMIT 1";
try {
    $st = $pdo->prepare($sql); $st->execute([$bailId]);
    $bail = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[bail_360] chargement bail #' . $bailId . ' : ' . $e->getMessage());
    http_response_code(500);
    exit('<pre style="font:13px/1.5 monospace;padding:24px;color:#b91c1c;">'
        . 'Erreur de chargement de la fiche bail (#' . (int)$bailId . ").\n\n"
        . htmlspecialchars($e->getMessage())
        . "\n\nProbable colonne absente sur cette base. Transmets cette ligne pour correction.</pre>");
}
if (!$bail) {
    http_response_code(404);
    exit('Bail introuvable.');
}

$proprietaireNom = $bail['proprio_tiers_nom'] ?: $bail['proprio_nom_legacy'] ?: '—';
$locataireNom    = $bail['loc_tiers_nom'] ?: $bail['loc_raison_sociale']
                  ?: ($bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'] . ' ' . $bail['locataire_nom']))
                  ?: '—';
$bienLabel       = $bail['reference_bien'] ?: $bail['designation'] ?: 'Bien #' . $bail['bien_id'];
$bienAdresse     = trim((string)($bail['bien_adresse'] ?? '') . ' ' . ($bail['bien_cp'] ?? '') . ' ' . ($bail['bien_ville'] ?? ''));

// ─── Documents GED du bail ──
// Source PRINCIPALE = GED centrale via ged_document_links (c'est là qu'écrit le pipeline
// FluxBox de classement : entity_type=BAIL). On merge avec la requête legacy (id_bail /
// metadata / linked_entities) pour les docs classés par d'anciens chemins. Dédup par id.
$docs = [];
$docsById = [];
try {
    if (is_file(__DIR__ . '/inc/ged_document_links.php')) {
        require_once __DIR__ . '/inc/ged_document_links.php';
    }
    if (function_exists('gdl_documents_for_entity')) {
        foreach (gdl_documents_for_entity($pdo, 'BAIL', $bailId, ['limit' => 60]) as $d) {
            $docsById[(int)$d['id']] = $d;
        }
    }
} catch (Throwable $e) {}
try {
    $stD = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND (
              id_bail = ?
              OR JSON_EXTRACT(metadata, '$.classement.bail_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'bail', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$bailId, $bailId, $bailId]);
    foreach ($stD->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
        if (!isset($docsById[(int)$d['id']])) $docsById[(int)$d['id']] = $d;
    }
} catch (Throwable $e) {}
// Split par type de lien : main = documents PROPRES du bail ; reference = docs qui CITENT
// le bail (CRG, quittances…) → alimentent la card « Mentionné dans ». Legacy (sans lien) = main.
$docs = []; $mentions = [];
foreach (array_values($docsById) as $d) {
    if (($d['link_relation_type'] ?? 'main') === 'reference') $mentions[] = $d;
    else $docs[] = $d;
}
$byDate = fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
usort($docs, $byDate);     $docs = array_slice($docs, 0, 30);
usort($mentions, $byDate); $mentions = array_slice($mentions, 0, 10);
// Index des document_type présents (normalisés en minuscules).
$docsByType = [];
foreach ($docs as $d) {
    $t = strtolower(trim((string)($d['document_type'] ?? '')));
    if ($t !== '') $docsByType[$t] = ($docsByType[$t] ?? 0) + 1;
}

// ─── Checklist pièces bail ──
// 'fbx_type' = code GLOSSAIRE canonique (ged_level_codes) pré-sélectionné au « + »
//   → le modal FluxBox affiche la vraie pastille du glossaire (respect du référentiel).
// 'types'    = codes acceptés pour valider la pièce (glossaire EN TÊTE + variantes
//   legacy minuscules, pour ne pas « décrocher » les docs déjà classés à l'ancienne).
$pieces = [
    ['label'=>'Bail signé',              'sublabel'=>'Document principal',           'fbx_type'=>'BAIL',                  'types'=>['BAIL','bail_signe','bail']],
    ['label'=>"État des lieux d'entrée", 'sublabel'=>"Obligatoire à la prise d'effet",'fbx_type'=>'EDL_ENTREE',            'types'=>['EDL_ENTREE','edl_entree','etat_lieux_entree']],
    ['label'=>"Attestation d'assurance", 'sublabel'=>'Locataire — annuel',           'fbx_type'=>'ATTESTATIONS_ASSURANCE','types'=>['ATTESTATIONS_ASSURANCE','attestation_assurance','att_assurance','assurance']],
    ['label'=>'DPE',                     'sublabel'=>'Annexé au bail',               'fbx_type'=>'DPE',                   'types'=>['DPE','dpe']],
    ['label'=>'Acte de caution',         'sublabel'=>'Si garant',                    'fbx_type'=>'ACTES_CAUTION',         'types'=>['ACTES_CAUTION','caution_garant','caution']],
    ['label'=>"État des lieux de sortie",'sublabel'=>'Si bail terminé',              'fbx_type'=>'EDL_SORTIE',            'types'=>['EDL_SORTIE','edl_sortie','etat_lieux_sortie']],
];
$piecesItems = [];
foreach ($pieces as $p) {
    $ok = false;
    foreach ($p['types'] as $t) { if (isset($docsByType[$t])) { $ok = true; break; } }
    $piecesItems[] = [
        'label'    => $p['label'],
        'sublabel' => $p['sublabel'],
        'ok'       => $ok,
        'add_url'  => app_url('/bail_360.php?id=' . $bailId),
        // Type pré-sélectionné au clic sur « + » = code glossaire canonique.
        'fbx_type' => $p['fbx_type'] ?? $p['types'][0],
    ];
}
// Prefill FluxBox de la fiche (contexte bail complet) — réutilisé par la checklist.
$fbxPrefillBail = [
    'origin'           => 'bail_360',
    'bail_id'          => (int)$bailId,
    'bail_locataire'   => (string)$locataireNom,
    'bien_id'          => (int)$bail['bien_id'],
    'immeuble_id'      => (int)($bail['id_immeuble'] ?? 0),
    'immeuble_nom'     => (string)($bail['nom_immeuble'] ?? ''),
    'soc_id'           => (int)($bail['bien_soc'] ?? 0),
    'age_id'           => (int)($bail['bien_age'] ?? 0),
    'proprio_id'       => (int)($bail['proprio_id'] ?? 0),
    'proprio_nom'      => (string)$proprietaireNom,
    'proprio_tiers_id' => (int)($bail['proprio_tiers_id'] ?? 0),
    'entite_id_bdd'    => (int)$bail['bien_id'],
    'entite_nom'       => (string)$bienLabel,
    'entite_adresse'   => (string)$bienAdresse,
    'card_label'       => 'DOCUMENT POUR LE BAIL',
    'n1'               => '03_GESTION_LOCATIVE',
];

// NB : « Mentionné dans » ($mentions) est désormais alimenté plus haut depuis
// ged_document_links (liens 'reference' sur le bail). L'ancienne requête id_bail/
// linked_entities (colonnes non alimentées par le pipeline) a été retirée.

// ─── Statut visuel intelligent ──
$today = date('Y-m-d');
$dateFin = (string)($bail['date_fin'] ?? '');
$joursAvantFin = $dateFin ? (int)((strtotime($dateFin) - strtotime($today)) / 86400) : null;

if ($bail['statut'] === 'actif') {
    if ($joursAvantFin !== null && $joursAvantFin < 0) {
        $statusColor = 'red'; $statusIcon = '⚠️';
        $statusMsg = '<strong>Bail expiré</strong> depuis le ' . h($dateFin) . ' · à régulariser.';
    } elseif ($joursAvantFin !== null && $joursAvantFin < 90) {
        $statusColor = 'orange'; $statusIcon = '⏰';
        $statusMsg = "<strong>Bail actif</strong> · expire dans <strong>{$joursAvantFin} jours</strong> (préavis à anticiper).";
    } else {
        $statusColor = 'green'; $statusIcon = '✅';
        $statusMsg = '<strong>Bail actif</strong> · jusqu\'au ' . h($dateFin ?: '—') . '.';
    }
} elseif ($bail['statut'] === 'termine') {
    $statusColor = 'gray'; $statusIcon = '🏁';
    $statusMsg = '<strong>Bail terminé</strong> · sortie le ' . h($bail['date_sortie'] ?? '—') . '.';
} else {
    $statusColor = 'gray'; $statusIcon = '⚪';
    $statusMsg = 'Bail · statut <strong>' . h($bail['statut'] ?? '—') . '</strong>.';
}
$nbPiecesOk      = array_sum(array_map(fn($p) => $p['ok'] ? 1 : 0, $piecesItems));
$piecesManquantes = count($piecesItems) - $nbPiecesOk;
$statusAlertes   = $piecesManquantes > 0 ? $piecesManquantes . ' pièce(s) à charger' : '';

$pageTitle    = 'Bail · ' . ($bail['bail_nature'] ?? 'bail') . ' #' . $bailId;
$pageSubtitle = 'Vue 360° · ' . $bienLabel;
$extraCss     = fiche360_css() . '<style>
/* Bouton d\'action d\'en-tête : même style doux que bien_360 / immeuble_360 */
.f360-header-actions .tr-btn-primary { background:#f3f6fb; color:#2d4a72; border-color:#e3ebf5; }
.f360-header-actions .tr-btn-primary:hover { background:#eaf1fa; color:#243B5C; }
</style>';
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<?php
// ─── BREADCRUMB ──
$chaine = [];
if (!empty($bail['proprio_tiers_id'])) {
    $chaine[] = ['icon'=>'👤','label'=>$proprietaireNom,'url'=>app_url('/tiers_360.php?id=' . $bail['proprio_tiers_id'])];
}
if (!empty($bail['id_immeuble'])) {
    $chaine[] = ['icon'=>'🏢','label'=>($bail['nom_immeuble'] ?: $bail['imm_adresse']),'url'=>app_url('/immeuble_360.php?id=' . $bail['id_immeuble'])];
}
$chaine[] = ['icon'=>'🏠','label'=>$bienLabel,'url'=>app_url('/bien_360.php?id=' . $bail['bien_id'])];
$chaine[] = ['icon'=>'📋','label'=>'Bail #' . $bailId,'url'=>null];
fiche360_breadcrumb($chaine, 'Hiérarchie');

// ─── HEADER ──
$badge = match($bail['statut'] ?? '') {
    'actif'   => ['label'=>'Actif','class'=>'actif'],
    'termine' => ['label'=>'Terminé','class'=>'vendu'],
    'projet'  => ['label'=>'Projet','class'=>'loue'],
    'envoye'  => ['label'=>'Envoyé à signer','class'=>'loue'],
    'signe'   => ['label'=>'Signé','class'=>'actif'],
    'avenant' => ['label'=>'Avenant','class'=>'loue'],
    default   => null,
};
$isProjetBail = in_array($bail['statut'] ?? '', ['projet','envoye','signe','avenant'], true);

$metas = [];
if (!empty($bail['bail_nature']))      $metas[] = ['icon'=>'📋','text'=>$bail['bail_nature']];
if (!empty($bail['date_prise_effet'])) $metas[] = ['icon'=>'📅','text'=>'Effet ' . date('d/m/Y', strtotime((string)$bail['date_prise_effet']))];
if (!empty($bail['date_fin']))         $metas[] = ['icon'=>'🏁','text'=>'Fin ' . date('d/m/Y', strtotime((string)$bail['date_fin']))];
if (!empty($bail['loyer_mensuel_hc'])) $metas[] = ['icon'=>'💰','text'=>number_format((float)$bail['loyer_mensuel_hc'], 0, ',', ' ') . ' €/mois'];

fiche360_header(
    '📋',
    'Bail ' . ($bail['bail_nature'] ?? '') . ' · ' . $locataireNom,
    $badge,
    $bienAdresse ?: 'Adresse non renseignée',
    $metas,
    [
        ['label'=>'✏️ Éditer le bien','url'=>app_url('/bien_detail.php?edit=' . $bail['bien_id']),'class'=>'tr-btn'],
        ['label'=>'📁 Documents du bien','url'=>app_url('/bien_documents_list.php?id=' . $bail['bien_id']),'class'=>'tr-btn tr-btn-primary'],
    ]
);

// ─── BANDEAU PROJET DE BAIL (workflow type mandat) ──
if ($isProjetBail) {
    $socRow = [];
    try { if (!empty($bail['bien_soc'])) { $q=$pdo->prepare("SELECT raison_sociale,nom,forme_juridique,capital_social,siren,siret,adresse_1,code_postal,ville,carte_pro_numero,numero_carte_t,carte_pro_cci,cci_carte_t,assurance_rcp,garantie_financiere,rib_emetteur_iban,rib_emetteur_bic,rib_emetteur_nom FROM societes WHERE id=?"); $q->execute([(int)$bail['bien_soc']]); $socRow=$q->fetch(PDO::FETCH_ASSOC) ?: []; } } catch (Throwable) {}
    $ageRow = [];
    try { if (!empty($bail['bien_age'])) { $q=$pdo->prepare("SELECT nom_agence,adresse_1,code_postal,ville,rcs,iban,bic,banque_nom FROM agences WHERE id=?"); $q->execute([(int)$bail['bien_age']]); $ageRow=$q->fetch(PDO::FETCH_ASSOC) ?: []; } } catch (Throwable) {}
    $candLabel = $bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'] . ' ' . $bail['locataire_nom']) ?: 'Candidat à définir';
    $stMap = ['projet'=>['🟡','Projet','#8a6d1b','#fef7e6'],'envoye'=>['📨','Envoyé à signer','#1d4ed8','#eef3ff'],'signe'=>['✅','Signé','#2d8a4e','#eef7f0'],'avenant'=>['📝','Avenant','#7c3aed','#f5f0ff']];
    $stB = $stMap[$bail['statut']] ?? ['•','—','#5b6b70','#f2f4f5'];
    $canEditProjet = in_array($bail['statut'], ['projet','envoye'], true);

    $belEditPrefill = [
        'bail_id'      => (int)$bailId,
        'bien_id'      => (int)$bail['bien_id'],
        'origin'       => 'bail_360',
        'proprio_nom'  => (string)$proprietaireNom,
        'immeuble_nom' => (string)($bail['nom_immeuble'] ?: $bail['imm_adresse'] ?: ''),
        'bien_ref'     => (string)$bienLabel,
        'bien_adresse' => (string)$bienAdresse,
        'bien_surface' => (float)($bail['surface_habitable'] ?? 0),
        'bien_lot'     => (string)($bail['numero_lot'] ?? ''),
        'bien_etage'   => (($bail['bien_etage'] ?? null) !== null && $bail['bien_etage'] !== '' ? ((int)$bail['bien_etage'] === 0 ? 'rez-de-chaussée' : (int)$bail['bien_etage'] . 'ᵉ étage') : ''),
        'bien_copro'   => (!empty($bail['bien_en_copropriete']) ? 'bien en copropriété' . (!empty($bail['lot_tantiemes']) ? ' (' . (int)$bail['lot_tantiemes'] . ' / ' . (int)($bail['copro_nb_lots'] ?: 0) . ' tantièmes)' : '') : ''),
        'bien_description' => (string)($bail['bien_description'] ?? ''),
        'gestionnaire' => [
            'raison'    => (string)(($socRow['raison_sociale'] ?? '') ?: ($socRow['nom'] ?? '')),
            'forme'     => (string)($socRow['forme_juridique'] ?? ''),
            'capital'   => $socRow['capital_social'] ?? null,
            'siren'     => (string)(($socRow['siren'] ?? '') ?: ($socRow['siret'] ?? '')),
            'adresse'   => trim((string)($socRow['adresse_1'] ?? '') . ' ' . ($socRow['code_postal'] ?? '') . ' ' . ($socRow['ville'] ?? '')),
            'carte'     => (string)(($socRow['carte_pro_numero'] ?? '') ?: ($socRow['numero_carte_t'] ?? '')),
            'carte_cci' => (string)(($socRow['carte_pro_cci'] ?? '') ?: ($socRow['cci_carte_t'] ?? '')),
            'rcp'       => (string)($socRow['assurance_rcp'] ?? ''),
            'garantie'  => (string)($socRow['garantie_financiere'] ?? ''),
            'age_nom'   => (string)($ageRow['nom_agence'] ?? ''),
            'age_adresse'=> trim((string)($ageRow['adresse_1'] ?? '') . ' ' . ($ageRow['code_postal'] ?? '') . ' ' . ($ageRow['ville'] ?? '')),
            'rib_iban'  => (string)(($socRow['rib_emetteur_iban'] ?? '') ?: ($ageRow['iban'] ?? '')),
            'rib_bic'   => (string)(($socRow['rib_emetteur_bic'] ?? '') ?: ($ageRow['bic'] ?? '')),
            'rib_nom'   => (string)(($socRow['rib_emetteur_nom'] ?? '') ?: ($ageRow['banque_nom'] ?? '')),
        ],
        'values' => [
            'locataire_type'=>$bail['locataire_type'], 'locataire_raison_sociale'=>$bail['locataire_raison_sociale'],
            'locataire_siren'=>$bail['locataire_siren'], 'locataire_nom'=>$bail['locataire_nom'], 'locataire_prenom'=>$bail['locataire_prenom'],
            'locataire_email'=>$bail['locataire_email'], 'locataire_telephone'=>$bail['locataire_telephone'],
            'locataire_representant_nom'=>$bail['locataire_representant_nom'], 'locataire_representant_qualite'=>$bail['locataire_representant_qualite'],
            'locataire_adresse'=>$bail['locataire_adresse'] ?? null, 'locataire_date_naissance'=>$bail['locataire_date_naissance'] ?? null,
            'locataire_lieu_naissance'=>$bail['locataire_lieu_naissance'] ?? null, 'locataire_nationalite'=>$bail['locataire_nationalite'] ?? null,
            'garant_present'=>$bail['garant_present'] ?? 0, 'garant_type'=>$bail['garant_type'] ?? null,
            'garant_nom'=>$bail['garant_nom'] ?? null, 'garant_prenom'=>$bail['garant_prenom'] ?? null,
            'garant_raison_sociale'=>$bail['garant_raison_sociale'] ?? null, 'garant_siren'=>$bail['garant_siren'] ?? null,
            'garant_adresse'=>$bail['garant_adresse'] ?? null, 'garant_date_naissance'=>$bail['garant_date_naissance'] ?? null,
            'garant_lieu_naissance'=>$bail['garant_lieu_naissance'] ?? null, 'garant_email'=>$bail['garant_email'] ?? null,
            'garant_telephone'=>$bail['garant_telephone'] ?? null, 'garant_montant_max'=>$bail['garant_montant_max'] ?? null,
            'garant_duree_ans'=>$bail['garant_duree_ans'] ?? null, 'garant_solidaire'=>$bail['garant_solidaire'] ?? 1,
            'destination_activite'=>$bail['destination_activite'], 'date_prise_effet'=>$bail['date_prise_effet'],
            'duree_mois'=>$bail['duree_mois'], 'duree_ferme_ans'=>$bail['duree_ferme_ans'],
            'loyer_mensuel_hc'=>$bail['loyer_mensuel_hc'], 'charges_mensuelles'=>$bail['charges_mensuelles'],
            'indice_type'=>$bail['indice_type'], 'indice_trimestre'=>$bail['indice_trimestre'], 'indice_valeur'=>$bail['indice_valeur'],
            'nb_termes_garantie'=>$bail['nb_termes_garantie'], 'erp_local'=>$bail['erp_local'] ?? 0,
            'option_achat'=>$bail['option_achat'] ?? 0, 'option_achat_prix'=>$bail['option_achat_prix'] ?? null, 'option_achat_delai_mois'=>$bail['option_achat_delai_mois'] ?? null,
            'tva_applicable'=>$bail['tva_applicable'] ?? 1, 'periodicite_paiement'=>$bail['periodicite_paiement'] ?? 'mensuelle',
            'provision_tf_mensuelle'=>$bail['provision_tf_mensuelle'] ?? null, 'honoraires_gestion_tech_pct'=>$bail['honoraires_gestion_tech_pct'] ?? null,
            'honoraires_bailleur_ttc'=>$bail['honoraires_bailleur_ttc'] ?? null, 'honoraires_locataire_ttc'=>$bail['honoraires_locataire_ttc'] ?? null,
            'conditions_particulieres'=>$bail['conditions_particulieres'] ?? null, 'conditions_particulieres_loyer'=>$bail['conditions_particulieres_loyer'] ?? null,
        ],
    ];
    echo '<script>window.BEL_PREFILL_EDIT = ' . json_encode($belEditPrefill, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) . ';</script>';
    $belEditOnClick = 'bailOpenEditModal(window.BEL_PREFILL_EDIT);return false;';
    require_once __DIR__ . '/inc/bail_edit_modal.php';
    bail_edit_modal();
    ?>
    <div style="background:<?= $stB[3] ?>;border:1px solid <?= $stB[2] ?>33;border-left:4px solid <?= $stB[2] ?>;border-radius:12px;padding:14px 18px;margin:8px 0 14px;display:flex;flex-wrap:wrap;align-items:center;gap:14px;">
        <div style="flex:1;min-width:220px;">
            <div style="font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:<?= $stB[2] ?>;"><?= $stB[0] ?> Projet de bail commercial — <?= h($stB[1]) ?></div>
            <div style="font-size:14px;font-weight:700;color:#2c2a28;margin-top:3px;">Candidat : <?= h($candLabel) ?> · <?= h($bail['numero_bail'] ?: ('#' . $bailId)) ?></div>
            <div style="font-size:12px;color:#7a766f;margin-top:2px;">
                <?= $bail['loyer_mensuel_hc'] ? number_format((float)$bail['loyer_mensuel_hc']*12, 0, ',', ' ') . ' €/an HT · ' : '' ?>
                <?= $bail['duree_ferme_ans'] ? (int)$bail['duree_ferme_ans'] . ' ans fermes · ' : '' ?>
                indice <?= h($bail['indice_type'] ?: 'ILC') ?><?= !empty($bail['option_achat']) ? ' · avec option d\'achat' : '' ?>
            </div>
        </div>
        <span style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <a id="bel-pdf-link" href="<?= h(app_url('/api/bail_pdf.php?id=' . $bailId)) ?>" target="_blank" rel="noopener" style="border:1.5px solid #84A7AB;background:#eef5f5;color:#3a5a5c;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;text-decoration:none;">🖨️ Générer le bail (PDF)</a>
            <label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#5b6b70;font-weight:600;cursor:pointer;white-space:nowrap;">
                <input type="checkbox" id="bel-pdf-final" onchange="var l=document.getElementById('bel-pdf-link'); l.href='<?= h(app_url('/api/bail_pdf.php?id=' . $bailId)) ?>'+(this.checked?'&final=1':'');"> Version définitive (sans filigrane)
            </label>
        </span>
        <?php if ($canEditProjet): ?>
            <button type="button" onclick="<?= h($belEditOnClick) ?>" style="border:1.5px solid #5f8f93;background:#fff;color:#3a5a5c;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">✏️ Modifier le projet</button>
            <button type="button" id="bel-send-btn" onclick="belSendBail(<?= (int)$bailId ?>, this)" style="border:none;background:#5f8f93;color:#fff;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">📨 Envoyer pour signature</button>
        <?php else: ?>
            <span style="font-size:12px;color:#7a766f;font-style:italic;">Bail <?= h($stB[1]) ?> — figé (modif par avenant).</span>
        <?php endif; ?>
    </div>
    <?php if ($canEditProjet): ?>
    <script>
    window.belSendBail = function(bailId, btn){
        if(!confirm('Envoyer le projet de bail au preneur' + ' (et au garant) pour signature en ligne ?')) return;
        btn.disabled = true; var old = btn.textContent; btn.textContent = '⏳ Envoi…';
        fetch('<?= h(app_url('/api/bail_send.php')) ?>', {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json'}, body: JSON.stringify({bail_id: bailId})})
          .then(function(r){return r.json();}).then(function(j){
            if(j && j.ok){
                var lignes = (j.envois||[]).map(function(e){ return (e.sent?'✅':'⚠️')+' '+(e.role||'')+' '+(e.email||'(sans email)'); }).join('\n');
                alert('✅ '+j.message+'\n\n'+lignes);
                location.reload();
            } else { btn.disabled=false; btn.textContent=old; alert('❌ '+((j&&j.error)||'Échec de l\'envoi')); }
          }).catch(function(e){ btn.disabled=false; btn.textContent=old; alert('❌ Réseau : '+e); });
    };
    </script>
    <?php endif; ?>
    <?php
}

// ─── RÉSUMÉ IA du bail (synthèse générée à l'analyse du document, persistée sur le bail) ──
$resumeIa = trim((string)($bail['resume_ia'] ?? ''));
if ($resumeIa !== '') {
    echo '<div style="background:linear-gradient(180deg,#f5f0ff 0%,#faf7ff 100%);border:1px solid #d8c9f0;border-left:4px solid #7c3aed;border-radius:12px;padding:14px 18px;margin:8px 0 14px;box-shadow:0 1px 3px rgba(124,58,237,.08)">'
       . '<div style="font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#6b21a8;margin-bottom:6px">🧠 Résumé du bail</div>'
       . '<div style="font-size:14px;line-height:1.55;color:#334155">' . h($resumeIa) . '</div>'
       . '</div>';
}
fiche360_ia_bar('bail', $bailId, "Demander à l'IA sur ce bail (loyer, échéances, conformité, indexation…)");
fiche360_status_banner($statusMsg, $statusColor, $statusIcon, $statusAlertes);
?>

<div class="f360-grid">

  <!-- ═══════════════════ COLONNE PRINCIPALE ═══════════════════ -->
  <div>

    <!-- Synthèse financière + dates -->
    <div class="f360-card">
        <h3>💰 Synthèse financière</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px;">
            <div><div style="font-size:10px; color:#9a9690;">LOYER HC</div><strong><?= number_format((float)$bail['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">CHARGES</div><strong><?= number_format((float)$bail['charges_mensuelles'], 0, ',', ' ') ?> €/mois</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">LOYER CC</div><strong><?= number_format((float)$bail['loyer_mensuel_hc'] + (float)$bail['charges_mensuelles'], 0, ',', ' ') ?> €/mois</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">DÉPÔT DE GARANTIE</div><strong><?= number_format((float)$bail['depot_garantie'], 0, ',', ' ') ?> €</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">INDICE</div><strong><?= h($bail['indice_type'] ?? '—') ?> <?= h($bail['indice_trimestre'] ?? '') ?></strong></div>
            <div><div style="font-size:10px; color:#9a9690;">PÉRIODICITÉ</div><strong><?= h($bail['periodicite_paiement'] ?? 'mensuelle') ?></strong></div>
        </div>
    </div>

    <!-- Clauses & assurance -->
    <div class="f360-card">
        <h3>🛡 Clauses & assurance</h3>
        <div style="font-size:12px; line-height:1.7;">
            <?php if (!is_null($bail['renonciation_recours_reciproque'] ?? null)): ?>
                <div>• <strong>Renonciation à recours réciproque :</strong> <?= $bail['renonciation_recours_reciproque'] ? '✅ Oui' : '❌ Non' ?></div>
            <?php endif; ?>
            <?php if (!empty($bail['assurance_surprimes_a_charge'])): ?>
                <div>• <strong>Surprimes d'assurance à charge :</strong> <?= h($bail['assurance_surprimes_a_charge']) ?></div>
            <?php endif; ?>
            <?php if (!is_null($bail['assurance_justification_annuelle'] ?? null)): ?>
                <div>• <strong>Justification annuelle d'assurance :</strong> <?= $bail['assurance_justification_annuelle'] ? '✅ Obligatoire' : '⚪ Non précisée' ?></div>
            <?php endif; ?>
            <?php if (!empty($bail['clause_resolutoire'])): ?>
                <div style="margin-top:8px; padding:8px 10px; background:#fff7ed; border-left:3px solid #f59e0b; border-radius:6px;">
                    <strong>📜 Clause résolutoire :</strong> <?= h(mb_substr((string)$bail['clause_resolutoire'], 0, 300)) ?><?= mb_strlen((string)$bail['clause_resolutoire']) > 300 ? '…' : '' ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($bail['conditions_particulieres'])): ?>
                <div style="margin-top:8px; padding:10px 12px; background:#f9f7ff; border-left:3px solid #7c3aed; border-radius:6px;">
                    <strong>📋 Conditions particulières :</strong> <?= h(mb_substr((string)$bail['conditions_particulieres'], 0, 500)) ?><?= mb_strlen((string)$bail['conditions_particulieres']) > 500 ? '…' : '' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chargement de documents : uniformisé via FluxBox (bouton « Charger des documents »
         du panneau Actions). La card d'upload dédiée a été retirée (2026-07-03). -->

    <!-- Documents du bail -->
    <div class="f360-card">
        <h3>📂 Documents du bail <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/bien_documents_list.php?id=' . $bail['bien_id'])) ?>">→ Gérer les documents du bien</a></div>
        <?php else: foreach ($docs as $d): ?>
            <div onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>, window.BAIL_FIELDS)"
                 style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center; cursor:pointer;"
                 onmouseover="this.style.background='#faf8ff'" onmouseout="this.style.background='transparent'">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;">[<?= h($d['document_type']) ?>]</span>
                <span style="flex:1;"><?= h($d['name_display']) ?></span>
                <span style="color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                <span style="color:#5b21b6; font-size:11px; font-weight:700;">Ouvrir ›</span>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>

    <!-- Dossiers sources (archives OneDrive liées, non importées) — inclusion défensive -->
    <?php
    $gsfCardFile = __DIR__ . '/inc/ged_source_folders_card.php';
    if (is_file($gsfCardFile)) { require_once $gsfCardFile;
        if (function_exists('ged_source_folders_card')) { try {
            ged_source_folders_card($pdo, 'BAIL', $bailId, ['id_societe'=>(int)($bail['bien_soc'] ?? 0), 'id_agence'=>(int)($bail['bien_age'] ?? 0)]);
        } catch (Throwable $e) {} } }
    ?>
    <?php
    // ── Champs extraits du bail (panneau gauche du modal) ──
    $eur = fn($v) => ($v === null || $v === '' || (float)$v == 0.0) ? null : number_format((float)$v, 0, ',', ' ') . ' €';
    $meta = json_decode((string)($bail['metadata'] ?? ''), true) ?: [];
    $ia   = $meta['analyse_ia_bail']['data'] ?? [];
    $rev  = $ia['revision'] ?? [];
    $loyerCC = ((float)($bail['loyer_mensuel_hc'] ?? 0) + (float)($bail['charges_mensuelles'] ?? 0));
    $bailFields = array_values(array_filter([
        ['section' => 'Finances'],
        ['label' => 'Loyer HC',           'value' => $eur($bail['loyer_mensuel_hc'] ?? null)],
        ['label' => 'Charges',            'value' => $eur($bail['charges_mensuelles'] ?? null)],
        ['label' => 'Loyer CC',           'value' => $eur($loyerCC ?: null)],
        ['label' => 'Dépôt de garantie',  'value' => $eur($bail['depot_garantie'] ?? null)],
        ['label' => 'Indice',             'value' => trim((string)($bail['indice_type'] ?? '') . ' ' . (string)($bail['indice_trimestre'] ?? '')) ?: null],
        ['label' => 'Valeur indice',      'value' => $bail['indice_valeur'] ?? null],
        ['label' => 'Périodicité',        'value' => $bail['periodicite_paiement'] ?? null],
        ['section' => 'Bail'],
        ['label' => 'Nature',             'value' => $bail['bail_nature'] ?? null],
        ['label' => 'Locataire',          'value' => $locataireNom ?: null],
        ['label' => 'Prise d\'effet',     'value' => $bail['date_prise_effet'] ?? null],
        ['label' => 'Fin',                'value' => $bail['date_fin'] ?? null],
        ['label' => 'Durée (extraite)',   'value' => $ia['conditions']['duree_bail'] ?? null],
        ['section' => 'Révision (extraite)'],
        ['label' => 'Type',               'value' => $rev['type'] ?? null],
        ['label' => 'Trimestre réf.',     'value' => $rev['trimestre_reference'] ?? null],
        ['label' => 'Année réf.',         'value' => $rev['annee_reference'] ?? null],
        ['label' => 'Indice réf.',        'value' => $rev['indice_reference'] ?? null],
        ['section' => 'Clauses'],
        ['label' => 'Clause résolutoire', 'value' => !empty($bail['clause_resolutoire']) ? 'Oui' : null],
        ['label' => 'Conditions particulières', 'value' => $bail['conditions_particulieres'] ?? null],
        ['label' => 'Diagnostics mentionnés', 'value' => !empty($ia['diagnostics_mentionnes']) ? implode(', ', (array)$ia['diagnostics_mentionnes']) : null],
    ], fn($f) => isset($f['section']) || ($f['value'] !== null && trim((string)$f['value']) !== '')));
    // Retire les sections devenues orphelines (sans champ derrière).
    $clean = []; $n = count($bailFields);
    foreach ($bailFields as $idx => $f) {
        if (isset($f['section'])) {
            $hasNext = ($idx + 1 < $n) && !isset($bailFields[$idx + 1]['section']);
            if ($hasNext) $clean[] = $f;
        } else { $clean[] = $f; }
    }
    ?>
    <script>window.BAIL_FIELDS = <?= json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

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

  </div>

  <!-- ═══════════════════ COLONNE LATÉRALE ═══════════════════ -->
  <div>

    <?php
    // Prefill FluxBox : on passe le CHEMIN complet propriétaire → immeuble → bien → bail
    // (comme sur bien_360) pour que la modale affiche la filiation complète avec les IDs.
    $fbxProprioNomJs   = addslashes((string)$proprietaireNom);
    $fbxProprioId      = (int)($bail['proprio_id'] ?? 0);
    $fbxProprioTiersId = (int)($bail['proprio_tiers_id'] ?? 0);
    $fbxImmeubleNomJs  = addslashes((string)($bail['nom_immeuble'] ?? ''));
    $fbxBienRefJs      = addslashes((string)$bienLabel);        // réf du bien (nom propre de la card)
    $fbxBienAdrJs      = addslashes((string)$bienAdresse);      // adresse du bien seule
    $fbxLocataireJs    = addslashes((string)$locataireNom);
    // Panneau Actions — EN HAUT de la colonne (convention 360°)
    fiche360_actions_panel('Actions bail', [
        ['icon'=>'📤','label'=>'Charger des documents','url'=>'#','onclick'=>"window.fbxOpenUploadModal({origin:'bail_360', bail_id:" . (int)$bailId . ", bail_locataire:'" . $fbxLocataireJs . "', bien_id:" . (int)$bail['bien_id'] . ", immeuble_id:" . (int)($bail['id_immeuble'] ?? 0) . ", immeuble_nom:'" . $fbxImmeubleNomJs . "', soc_id:" . (int)($bail['bien_soc'] ?? 0) . ", age_id:" . (int)($bail['bien_age'] ?? 0) . ", proprio_id:" . $fbxProprioId . ", proprio_nom:'" . $fbxProprioNomJs . "', proprio_tiers_id:" . $fbxProprioTiersId . ", entite_id_bdd:" . (int)$bail['bien_id'] . ", entite_nom:'" . $fbxBienRefJs . "', entite_adresse:'" . $fbxBienAdrJs . "', card_label:'DOCUMENT POUR LE BAIL', n1:'03_GESTION_LOCATIVE'});return false;"],
        ['icon'=>'📨','label'=>'Demander un document (locataire)','url'=>app_url('/document_request_new.php?ctx=BAIL&id=' . $bailId . '&back=' . urlencode('bail_360.php?id=' . $bailId))],
        ['icon'=>'📥','label'=>'Importer docs du bail (OneDrive)','url'=>'javascript:odClasserOpen()'],
        ['icon'=>'📂','label'=>'Ouvrir le dossier OneDrive','url'=>'javascript:odOpenFolder()'],
        ['icon'=>'✏️','label'=>'Éditer le bien',           'url'=>app_url('/bien_detail.php?edit=' . $bail['bien_id'])],
        ['icon'=>'📁','label'=>'Documents du bien',        'url'=>app_url('/bien_documents_list.php?id=' . $bail['bien_id'])],
        ['icon'=>'📋','label'=>'Voir la fiche bien 360°',  'url'=>app_url('/bien_360.php?id=' . $bail['bien_id'])],
        ['icon'=>'🎯','label'=>'Retour au tableau Baux',   'url'=>app_url('/bien_baux_liste.php')],
    ]);

    fiche360_checklist('Pièces bail', $piecesItems, $fbxPrefillBail);

    // Bien concerné
    fiche360_attach('BIEN CONCERNÉ', [[
        'icon' => '🏠',
        'name' => $bienLabel,
        'ref'  => ($bail['bien_ville'] ?? '') . ($bail['surface_habitable'] ? ' · ' . number_format((float)$bail['surface_habitable'], 0) . ' m²' : ''),
        'url'  => app_url('/bien_360.php?id=' . $bail['bien_id']),
    ]]);

    // Bailleur (propriétaire)
    if (!empty($bail['proprio_id'])) {
        fiche360_attach('BAILLEUR', [[
            'icon' => '👤',
            'name' => $proprietaireNom,
            'ref'  => !empty($bail['proprio_tiers_id']) ? 'tiers #' . $bail['proprio_tiers_id'] : 'propriétaire #' . $bail['proprio_id'],
            'url'  => !empty($bail['proprio_tiers_id']) ? app_url('/tiers_360.php?id=' . $bail['proprio_tiers_id']) : app_url('/agency_proprietaires.php?q=' . urlencode($proprietaireNom)),
        ]]);
        if (!empty($bail['bailleur_representant_nom'])) {
            fiche360_attach('REPRÉSENTANT BAILLEUR', [[
                'icon' => '👥',
                'name' => $bail['bailleur_representant_nom'] . ($bail['bailleur_representant_qualite'] ? ' (' . $bail['bailleur_representant_qualite'] . ')' : ''),
                'ref'  => $bail['bailleur_representant_email'] ?: $bail['bailleur_representant_telephone'] ?: '',
                'url'  => $bail['bailleur_representant_email'] ? 'mailto:' . $bail['bailleur_representant_email'] : '#',
            ]]);
        }
    }

    // Locataire
    fiche360_attach('LOCATAIRE', [[
        'icon' => '🔑',
        'name' => $locataireNom,
        'ref'  => !empty($bail['loc_tiers_id']) ? 'tiers #' . $bail['loc_tiers_id'] : '',
        'url'  => !empty($bail['loc_tiers_id']) ? app_url('/tiers_360.php?id=' . $bail['loc_tiers_id']) : '#',
    ]]);
    if (!empty($bail['locataire_representant_nom'])) {
        fiche360_attach('REPRÉSENTANT LOCATAIRE', [[
            'icon' => '👥',
            'name' => $bail['locataire_representant_nom'] . ($bail['locataire_representant_qualite'] ? ' (' . $bail['locataire_representant_qualite'] . ')' : ''),
            'ref'  => $bail['locataire_representant_email'] ?: $bail['locataire_representant_telephone'] ?: '',
            'url'  => $bail['locataire_representant_email'] ? 'mailto:' . $bail['locataire_representant_email'] : '#',
        ]]);
    }

    ?>

  </div>
</div>

<?= fiche360_js() ?>
<!-- Ancien uploader `bail_doc_upload.php` (hors glossaire) retiré le 2026-07-06.
     Tout le chargement passe désormais par le modal FluxBox unique
     (window.fbxOpenUploadModal), déclenché par « Charger des documents » (Actions bail)
     et par les « + » de la checklist Pièces bail (type glossaire pré-sélectionné). -->

<!-- ── Modal classement OneDrive → GED (scope BAIL : bail + EDL entrée) ── -->
<div id="odModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Documents du bail — <?= h($locataireNom) ?></h3>
      <button type="button" onclick="document.getElementById('odModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <div id="odBody" style="flex:1;overflow:auto;padding:16px 18px;font-size:13px;"><div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div></div>
    <div style="padding:12px 18px;border-top:1px solid #eef0f2;display:flex;gap:10px;align-items:center;">
      <button type="button" id="odCommitBtn" onclick="odClasserCommit()" disabled
              style="background:#2d8a4e;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;opacity:.5;">✓ Valider et classer</button>
      <span id="odMsg" style="font-size:12.5px;font-weight:700;"></span>
    </div>
  </div>
</div>
<script>
(function(){
  var BAILID=<?= (int)$bailId ?>, CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function post(action){var fd=new FormData();fd.append('csrf_token',CSRF);fd.append('id_bail',BAILID);fd.append('action',action);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  window.odOpenFolder=function(){
    var w=window.open('','_blank'); if(w)w.document.write('Ouverture du dossier OneDrive…');
    post('folder_url').then(function(j){
      if(j&&j.ok&&j.url){ if(w){w.location.href=j.url;}else{window.location.href=j.url;} }
      else { if(w)w.close(); alert('❌ '+((j&&j.error)||'Dossier OneDrive introuvable')); }
    }).catch(function(e){ if(w)w.close(); alert('❌ Réseau : '+e); });
  };
  window.odClasserOpen=function(){
    document.getElementById('odModal').style.display='flex';
    document.getElementById('odCommitBtn').disabled=true; document.getElementById('odCommitBtn').style.opacity=.5;
    document.getElementById('odMsg').textContent='';
    document.getElementById('odBody').innerHTML='<div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div>';
    post('scan').then(function(j){
      if(!j||!j.ok){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ '+esc((j&&j.error)||'Erreur')+(j&&j.base?'<br><small>base: '+esc(j.base)+'</small>':'')+'</div>';return;}
      var rows=(j.items||[]).map(function(it){
        var col=it.status==='certain'?'#2d8a4e':(it.status==='pile'?'#8a6d1b':'#c62828');
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;margin-top:2px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">→ ce bail</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason)+'</td></tr>';
      }).join('');
      var nbCertain=(j.items||[]).filter(function(x){return x.status==='certain';}).length;
      document.getElementById('odBody').innerHTML=
        '<div style="margin-bottom:8px;color:#6b7280;">Dossier <b>'+esc(j.folder)+'</b> · <b>'+nbCertain+'</b> doc(s) du bail (bail signé + EDL entrée).</div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(rows||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun bail/EDL entrée trouvé pour ce locataire.</td></tr>')+'</tbody></table>';
      var b=document.getElementById('odCommitBtn'); if(nbCertain>0){b.disabled=false;b.style.opacity=1;}
    }).catch(function(e){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>';});
  };
  window.odClasserCommit=function(){
    var b=document.getElementById('odCommitBtn'),m=document.getElementById('odMsg');
    b.disabled=true;b.style.opacity=.5;m.style.color='#6b7280';m.textContent='⏳ Classement en cours…';
    post('commit').then(function(j){
      if(!j||!j.ok){m.style.color='#c62828';m.textContent='❌ '+esc((j&&j.error)||'Erreur');return;}
      m.style.color='#2d8a4e';m.textContent='✓ '+j.classes+' document(s) classé(s) en GED'+(j.erreurs&&j.erreurs.length?(' · '+j.erreurs.length+' erreur(s)'):'')+'. Recharge la page.';
    }).catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau : '+esc(e);});
  };
})();
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
