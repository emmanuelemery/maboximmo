<?php
// bail_360.php — Vue 360° d'un bail
// Bien + locataire + bailleur + loyer + échéances + documents + IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
if (!function_exists('mail_compose_url') && is_file(__DIR__ . '/inc/mail_button.php')) require_once __DIR__ . '/inc/mail_button.php';
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
    b.description AS bien_description, b.etage AS bien_etage, b.bien_en_copropriete, b.lot_tantiemes AS bien_lot_tantiemes_src, b.copro_nb_lots,
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
    require_once __DIR__ . '/inc/ged_doc_label.php';
    if (function_exists('gdl_documents_for_entity')) {
        foreach (gdl_documents_for_entity($pdo, 'BAIL', $bailId, ['limit' => 60]) as $d) {
            $docsById[(int)$d['id']] = $d;
        }
    }
} catch (Throwable $e) {}
try {
    $stD = $pdo->prepare("SELECT id, name_display, name_file, document_type, created_at
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
// Diagnostics du BIEN (DPE, DDT, amiante, ERP…) : ANNEXES OBLIGATOIRES du bail. Ils sont liés
// au bien, pas au bail — on les remonte ici pour qu'ils apparaissent dans les pièces du bail.
try {
    $bienIdForDiag = (int)($bail['id_bien'] ?? 0);
    if ($bienIdForDiag > 0 && function_exists('gdl_documents_for_entity')) {
        $diagRe = '/dpe|diag|ddt|amiante|plomb|erp|termite|electric|electr|gaz|carrez|mesurage|assainissement/i';
        foreach (gdl_documents_for_entity($pdo, 'BIEN', $bienIdForDiag, ['limit' => 40]) as $d) {
            $t = strtolower(trim((string)($d['document_type'] ?? '')));
            $nm = strtolower((string)($d['name_display'] ?? ''));
            if (preg_match($diagRe, $t) || preg_match($diagRe, $nm)) {
                $d['link_relation_type'] = 'main';       // pièce à part entière du bail
                $d['_from_bien'] = 1;                     // provenance (annexe du bien)
                if (!isset($docsById[(int)$d['id']])) $docsById[(int)$d['id']] = $d;
            }
        }
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
    ['label'=>'DPE',                     'sublabel'=>'Annexé au bail',               'fbx_type'=>'DPE',                   'types'=>['DPE','dpe','diag_dpe','DIAG_DPE','ddt','DDT']],
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
    // RIB de GESTION de l'agence (identique au PDF : cb_resolve …,'gestion'), jamais le compte société.
    require_once __DIR__ . '/inc/comptes_bancaires.php';
    $ribGBail = cb_resolve($pdo, (int)($bail['bien_soc'] ?? 0) ?: (int)($bail['id_societe'] ?? 0), ((int)($bail['bien_age'] ?? 0) ?: (int)($bail['id_agence'] ?? 0)) ?: null, 'gestion');
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
        'bien_copro'   => (!empty($bail['bien_en_copropriete']) ? 'bien en copropriété' . (!empty($bail['bien_lot_tantiemes_src']) ? ' (' . (int)$bail['bien_lot_tantiemes_src'] . ' / ' . (int)($bail['copro_nb_lots'] ?: 0) . ' tantièmes)' : '') : ''),
        'bien_tantiemes' => (string)($bail['bien_lot_tantiemes_src'] ?? ''),
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
            'rib_iban'  => (string)$ribGBail['iban'],
            'rib_bic'   => (string)$ribGBail['bic'],
            'rib_nom'   => (string)($ribGBail['banque'] ?: $ribGBail['titulaire']),
        ],
        'values' => [
            'bailleur_representant_nom'=>$bail['bailleur_representant_nom'] ?? null, 'bailleur_representant_qualite'=>$bail['bailleur_representant_qualite'] ?? null,
            'locataire_type'=>$bail['locataire_type'], 'locataire_raison_sociale'=>$bail['locataire_raison_sociale'],
            'locataire_siren'=>$bail['locataire_siren'], 'locataire_nom'=>$bail['locataire_nom'], 'locataire_prenom'=>$bail['locataire_prenom'],
            'locataire_email'=>$bail['locataire_email'], 'locataire_telephone'=>$bail['locataire_telephone'],
            'locataire_representant_nom'=>$bail['locataire_representant_nom'], 'locataire_representant_qualite'=>$bail['locataire_representant_qualite'],
            'locataire_representant_email'=>$bail['locataire_representant_email'] ?? null,
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
            'honoraires_pct_preneur'=>$bail['honoraires_pct_preneur'] ?? null, 'honoraires_pct_bailleur'=>$bail['honoraires_pct_bailleur'] ?? null,
            'droit_entree'=>$bail['droit_entree'] ?? null, 'taux_penalite'=>$bail['taux_penalite'] ?? null,
            'conditions_particulieres'=>$bail['conditions_particulieres'] ?? null, 'conditions_particulieres_loyer'=>$bail['conditions_particulieres_loyer'] ?? null,
            'bien_designation'=>$bail['bien_designation'] ?? null, 'en_copropriete'=>$bail['en_copropriete'] ?? 0,
            'lot_copropriete'=>$bail['lot_copropriete'] ?? null, 'lot_tantiemes'=>$bail['lot_tantiemes'] ?? null,
            'prorata_date_debut'=>$bail['prorata_date_debut'] ?? null,
            'travaux_realises_3ans'=>$bail['travaux_realises_3ans'] ?? null, 'travaux_prevus_3ans'=>$bail['travaux_prevus_3ans'] ?? null,
        ],
    ];
    echo '<script>window.BEL_PREFILL_EDIT = ' . json_encode($belEditPrefill, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) . ';</script>';
    if (($bail['bail_nature'] ?? '') === 'habitation') {
        // Bail HABITATION (loi 89-462) → modal + moteur FNAIM dédiés ; « Modifier » ouvre ce modal.
        require_once __DIR__ . '/inc/bail_habitation_edit_modal.php';
        bail_habitation_modal();
        // Descriptif structuré du bien (source unique) → repris en LECTURE SEULE dans le modal.
        require_once __DIR__ . '/inc/bien_descriptif.php';
        $bhRows = [];
        try {
            $qbh = $pdo->prepare("SELECT b.*, COALESCE(bt.libelle, tb.libelle) AS _type_bien_libelle
                FROM biens b LEFT JOIN bien_types bt ON bt.id=b.id_bien_type LEFT JOIN types_bien tb ON tb.id=b.id_type_bien
                WHERE b.id=? LIMIT 1");
            $qbh->execute([(int)$bail['bien_id']]);
            if ($bhStruct = $qbh->fetch(PDO::FETCH_ASSOC)) $bhRows = bien_descriptif_rows($bhStruct);
        } catch (Throwable) {}
        echo '<script>window.BAILHAB_VALUES = ' . json_encode($bail, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) . ';'
           . 'window.BAILHAB_DESCRIPTIF = ' . json_encode($bhRows, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) . ';'
           . 'window.BAILHAB_BIENID = ' . (int)$bail['bien_id'] . ';'
           . 'window.bailHabOnClose=function(){location.reload();};</script>';
        $belEditOnClick = 'bailHabOpenModal({bien_id:' . (int)$bail['bien_id'] . ', bail_id:' . (int)$bailId . ', values:window.BAILHAB_VALUES});return false;';
    } else {
        $belEditOnClick = 'bailOpenEditModal(window.BEL_PREFILL_EDIT);return false;';
        require_once __DIR__ . '/inc/bail_edit_modal.php';
        bail_edit_modal();
    }
    // Signataires enregistrés (pour la cérémonie + la clôture explicite).
    require_once __DIR__ . '/inc/bail_signature.php';
    $belSignataires = function_exists('bsig_list_for_bail') ? bsig_list_for_bail($pdo, $bailId) : [];
    $belSigTotal = count($belSignataires);
    $belSigDone  = count(array_filter($belSignataires, fn($s) => ($s['statut'] ?? '') === 'signe'));
    $belRoleLbl  = ['preneur'=>'Preneur','caution'=>'Garant','bailleur'=>'Bailleur','mandataire'=>'Mandataire'];
    // État par rôle (léger, sans les images) pour le modal : savoir si déjà signé + l'id.
    $belSignState = [];
    foreach ($belSignataires as $s) {
        $belSignState[$s['role_code']] = [
            'id'     => (int)$s['id'],
            'signed' => ($s['statut'] ?? '') === 'signe',
            'nom'    => (string)($s['nom_signataire'] ?? ''),
            'date'   => !empty($s['signed_at']) ? date('d/m/Y', strtotime((string)$s['signed_at'])) : '',
        ];
    }
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
            <button type="button" onclick="belSendProjet(<?= (int)$bailId ?>, this)" style="border:1.5px solid #84A7AB;background:#eef5f5;color:#3a5a5c;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">📄 Envoyer le projet (relecture)</button>
            <button type="button" id="bel-send-btn" onclick="belSendBail(<?= (int)$bailId ?>, this)" style="border:none;background:#5f8f93;color:#fff;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">📨 Envoyer pour signature</button>
            <button type="button" onclick="belSignOpen()" style="border:1.5px solid #5f8f93;background:#fff;color:#3a5a5c;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">✍️ Signer en présentiel</button>
            <?php if (($bail['statut'] ?? '') === 'envoye'): ?>
            <button type="button" onclick="belCancelSend(<?= (int)$bailId ?>, this)" title="Annule l'envoi, invalide les liens de signature et repasse le bail en projet pour renvoyer une nouvelle version" style="border:1.5px solid #e0a3a0;background:#fdeceb;color:#b5352e;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">↩️ Annuler l'envoi (nouvelle version)</button>
            <?php endif; ?>
            <?php if ($belSigTotal > 0): $belAllSigned = ($belSigDone === $belSigTotal); ?>
            <button type="button" onclick="belCloturer(<?= (int)$bailId ?>, this)" <?= $belAllSigned ? '' : 'disabled' ?>
                title="<?= $belAllSigned ? 'Clôturer : générer le bail signé, le classer en GED et l\'envoyer' : 'Toutes les parties doivent avoir signé avant de clôturer' ?>"
                style="border:none;background:<?= $belAllSigned ? '#15803d' : '#c4cec8' ?>;color:#fff;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:<?= $belAllSigned ? 'pointer' : 'not-allowed' ?>;white-space:nowrap;">✅ Clôturer la signature (<?= $belSigDone ?>/<?= $belSigTotal ?>)</button>
            <?php endif; ?>
        <?php else: ?>
            <span style="font-size:12px;color:#7a766f;font-style:italic;">Bail <?= h($stB[1]) ?> — figé (modif par avenant).</span>
        <?php endif; ?>
        <?php if ($belSigTotal > 0): ?>
        <div style="flex-basis:100%;display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;">
            <?php foreach ($belSignataires as $s):
                $sg  = ($s['statut'] ?? '') === 'signe';
                $sgAt  = !empty($s['signed_at']) ? date('d/m/Y à H\hi', strtotime((string)$s['signed_at'])) : '';
                $sntAt = !empty($s['sent_at'])   ? date('d/m/Y à H\hi', strtotime((string)$s['sent_at']))   : '';
                $roleLbl = h($belRoleLbl[$s['role_code']] ?? ucfirst((string)$s['role_code']));
                $nomLbl  = !empty($s['nom_signataire']) ? ' — ' . h($s['nom_signataire']) : '';
            ?>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:5px 11px;border-radius:20px;background:<?= $sg ? '#e7f6ec' : '#fbf3e6' ?>;color:<?= $sg ? '#15803d' : '#a26a1c' ?>;border:1px solid <?= $sg ? '#bfe6cc' : '#f0dcbf' ?>;">
                <?= $sg ? '✓' : '⏳' ?> <?= $roleLbl . $nomLbl ?>
                <?php if ($sg): ?>
                    <?php if ($sgAt): ?><span style="font-weight:600;opacity:.85;">· signé le <?= $sgAt ?></span><?php endif; ?>
                    <button type="button" onclick="belSignAnnuler(<?= (int)$s['id'] ?>, this)" title="Annuler / effacer cette signature" style="border:none;background:transparent;color:#c0392b;font-weight:900;cursor:pointer;padding:0 0 0 4px;line-height:1;font-size:13px;">✕</button>
                <?php else: ?>
                    <?php if ($sntAt): ?><span style="font-weight:600;opacity:.85;">· demandé le <?= $sntAt ?></span><?php endif; ?>
                    <?php if ($canEditProjet && ($bail['statut'] ?? '') === 'envoye'): ?>
                    <button type="button" onclick="belRelance(<?= (int)$bailId ?>, <?= (int)$s['id'] ?>, this)" title="Renvoyer l'email de demande de signature à ce signataire" style="border:1px solid #e6c98a;background:#fff8ec;color:#a26a1c;border-radius:14px;font-weight:800;cursor:pointer;padding:2px 9px 3px;line-height:1.2;font-size:11.5px;margin-left:2px;">📨 Relancer</button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canEditProjet): ?>
    <?php
    // ── Cérémonie de signature : emails préremplis (best-effort) + rôles ──
    $belCeremPre = ['preneur'=>'', 'caution'=>'', 'mandataire'=>'', 'bailleur'=>''];
    $belCeremPre['preneur'] = trim((string)($bail['locataire_representant_email'] ?? '')) ?: trim((string)($bail['locataire_email'] ?? ''));
    if (!empty($bail['garant_present'])) $belCeremPre['caution'] = trim((string)($bail['garant_email'] ?? ''));
    try { if (!empty($bail['bien_soc'])) { $qE=$pdo->prepare("SELECT email FROM societes WHERE id=? LIMIT 1"); $qE->execute([(int)$bail['bien_soc']]); $belCeremPre['mandataire']=trim((string)($qE->fetchColumn() ?: '')); } } catch (\Throwable $e) {}
    try { $qE=$pdo->prepare("SELECT tp.email FROM biens b LEFT JOIN proprietaires p ON p.id=b.id_proprietaire LEFT JOIN tiers tp ON tp.id=p.id_tiers WHERE b.id=? LIMIT 1"); $qE->execute([(int)$bail['id_bien']]); $belCeremPre['bailleur']=trim((string)($qE->fetchColumn() ?: '')); } catch (\Throwable $e) {}
    $belCeremRoles = [['role'=>'preneur','label'=>'Preneur','nom'=>($bail['locataire_raison_sociale'] ?: trim((string)($bail['locataire_prenom'] ?? '').' '.($bail['locataire_nom'] ?? '')))]];
    if (!empty($bail['garant_present'])) $belCeremRoles[] = ['role'=>'caution','label'=>'Garant / caution','nom'=>($bail['garant_raison_sociale'] ?: trim((string)($bail['garant_prenom'] ?? '').' '.($bail['garant_nom'] ?? '')))];
    $belCeremRoles[] = ['role'=>'mandataire','label'=>'Agence (mandataire)','nom'=>''];
    $belCeremRoles[] = ['role'=>'bailleur','label'=>'Bailleur','nom'=>($bail['bailleur_representant_nom'] ?? '')];
    ?>
    <div id="belCeremModal" style="display:none;position:fixed;inset:0;z-index:9600;background:rgba(15,18,24,.55);align-items:center;justify-content:center;padding:18px;">
      <div style="background:#fff;border-radius:14px;max-width:560px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;">
        <div style="background:#5f8f93;color:#fff;padding:14px 18px;font-weight:800;font-size:16px;">📨 Cérémonie de signature</div>
        <div style="padding:18px 20px;max-height:70vh;overflow:auto;">
          <p style="font-size:13px;color:#475569;margin:0 0 12px;">Vérifie les emails des signataires et complète ceux qui manquent (sans email, la partie ne reçoit pas le lien). Tous reçoivent leur lien ; le bail signé n'est distribué qu'une fois <b>toutes</b> les signatures recueillies.</p>
          <div id="belCeremList"></div>
          <p style="font-size:12px;color:#64748b;margin-top:10px;">Chaque mail contient le lien de signature, le <b>RIB pour le versement</b> et le <b>montant total à verser à la signature</b>.</p>
        </div>
        <div style="padding:12px 18px;border-top:1px solid #eef2f6;display:flex;justify-content:flex-end;gap:8px;">
          <button type="button" onclick="belCeremClose()" style="border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">Annuler</button>
          <button type="button" id="belCeremSendBtn" onclick="belCeremSend()" style="border:none;background:#5f8f93;color:#fff;border-radius:9px;padding:9px 18px;font-weight:800;cursor:pointer;">📨 Envoyer à tous</button>
        </div>
      </div>
    </div>
    <script>
    var API_BAIL_SEND = '<?= h(app_url('/api/bail_send.php')) ?>';
    var BEL_CEREM_ROLES = <?= json_encode($belCeremRoles, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var BEL_CEREM_PRE = <?= json_encode($belCeremPre, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    function belEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    // « Envoyer pour signature » ouvre le MODULE D'ENVOI DE MAIL complet en mode signature
    // (destinataires = signataires, template, bail PDF + DPE auto, lien personnalisé par personne).
    var MAILC = '<?= h(app_url('/mail_compose.php')) ?>';
    var BACK360 = '<?= h(app_url('/bail_360.php?id=' . $bailId)) ?>';
    window.belSendBail = function(bailId, btn){
        window.location = MAILC + '?ctx=BAIL&id=' + bailId + '&mode=signature&back=' + encodeURIComponent(BACK360);
    };
    // Annule l'envoi : invalide les liens de signature + repasse le bail en projet (nouvelle version).
    window.belCancelSend = function(bailId, btn){
        if(!confirm('Annuler l\'envoi pour signature ?\n\nLes liens de signature déjà envoyés seront INVALIDÉS (ils ne fonctionneront plus) et le bail repassera en projet. Tu pourras le modifier puis renvoyer une nouvelle version.')) return;
        var old=btn.textContent; btn.disabled=true; btn.textContent='⏳ Annulation…';
        fetch('<?= h(app_url('/api/bail_send_cancel.php')) ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({bail_id:bailId})})
          .then(function(r){return r.json();}).then(function(j){
            if(j&&j.ok){ location.reload(); }
            else { btn.disabled=false; btn.textContent=old; alert('❌ '+((j&&j.error)||'Échec de l\'annulation')); }
          }).catch(function(e){ btn.disabled=false; btn.textContent=old; alert('❌ Réseau : '+e); });
    };
    // Relance ciblée : renvoie l'email de demande de signature à UN signataire non signé.
    window.belRelance = function(bailId, sigId, btn){
        if(!confirm('Renvoyer l\'email de demande de signature à ce signataire ?')) return;
        var old=btn.textContent; btn.disabled=true; btn.textContent='⏳ Envoi…';
        fetch(API_BAIL_SEND,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({bail_id:bailId, sig_id:sigId})})
          .then(function(r){return r.json();}).then(function(j){
            if(j && j.ok){
                var e=(j.envois&&j.envois[0])||{};
                if(e.sent){ alert('✅ Relance envoyée'+(e.email?' à '+e.email:'')+'.'); location.reload(); }
                else { btn.disabled=false; btn.textContent=old; alert('⚠️ Email non envoyé'+(e.email?' ('+e.email+')':' — email manquant ?')+'.'); }
            } else { btn.disabled=false; btn.textContent=old; alert('❌ '+((j&&j.error)||'Échec de la relance')); }
          }).catch(function(e){ btn.disabled=false; btn.textContent=old; alert('❌ Réseau : '+e); });
    };
    function belCeremOpen(){
        var list = document.getElementById('belCeremList'); list.innerHTML='';
        BEL_CEREM_ROLES.forEach(function(r){
            var val = BEL_CEREM_PRE[r.role] || '';
            var row = document.createElement('div'); row.style.cssText='margin-bottom:11px;';
            row.innerHTML = '<label style="display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:3px;">'+belEsc(r.label)+(r.nom?' <span style="font-weight:600;color:#64748b;">— '+belEsc(r.nom)+'</span>':'')+'</label>'+
                '<input type="email" data-role="'+r.role+'" value="'+belEsc(val)+'" placeholder="email@exemple.fr" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box;">';
            list.appendChild(row);
        });
        document.getElementById('belCeremModal').style.display='flex';
    }
    window.belCeremClose = function(){ document.getElementById('belCeremModal').style.display='none'; };
    window.belCeremSend = function(){
        var emails = {};
        document.querySelectorAll('#belCeremList input[data-role]').forEach(function(i){ emails[i.getAttribute('data-role')] = i.value.trim(); });
        var btn = document.getElementById('belCeremSendBtn'); btn.disabled=true; var old=btn.textContent; btn.textContent='⏳ Envoi…';
        fetch(API_BAIL_SEND, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({bail_id: <?= (int)$bailId ?>, role_emails: emails})})
          .then(function(r){return r.json();}).then(function(j){
            if(j && j.ok){
                var lignes = (j.envois||[]).map(function(e){ return (e.sent?'✅':'⚠️')+' '+(e.role||'')+' '+(e.email||'(sans email)'); }).join('\n');
                alert('✅ '+j.message+'\n\n'+lignes); location.reload();
            } else { btn.disabled=false; btn.textContent=old; alert('❌ '+((j&&j.error)||'Échec de l\'envoi')); }
          }).catch(function(e){ btn.disabled=false; btn.textContent=old; alert('❌ Réseau : '+e); });
    };
    // Envoi du PROJET (filigrané) pour relecture — ne lance PAS la signature.
    window.belSendProjet = function(bailId, btn){
        var email = prompt('Envoyer le projet de bail (filigrané, pour relecture) à quel email ?', '');
        if (email === null) return;
        btn.disabled = true; var old = btn.textContent; btn.textContent = '⏳ Envoi…';
        fetch('<?= h(app_url('/api/bail_send_projet.php')) ?>', {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json'}, body: JSON.stringify({bail_id: bailId, email: email})})
          .then(function(r){return r.json();}).then(function(j){
            btn.disabled=false; btn.textContent=old;
            if(j && j.ok){ alert('✅ '+j.message); } else { alert('❌ '+((j&&j.error)||j.message||'Échec de l\'envoi')); }
          }).catch(function(e){ btn.disabled=false; btn.textContent=old; alert('❌ Réseau : '+e); });
    };
    // ── Annuler / effacer une signature déjà prise (avant clôture) ──
    window.belSignAnnuler = function(sigId, btn){
        if(!confirm('Annuler cette signature ? Le signataire pourra re-signer.')) return;
        if(btn) btn.disabled = true;
        fetch('<?= h(app_url('/api/bail_sign_annuler.php')) ?>', {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json'}, body: JSON.stringify({bail_id: <?= (int)$bailId ?>, sig_id: sigId})})
          .then(function(r){return r.json();}).then(function(j){
            if(j && j.ok){ location.reload(); }
            else { if(btn) btn.disabled=false; alert('❌ '+((j&&j.error)||'Annulation impossible')); }
          }).catch(function(e){ if(btn) btn.disabled=false; alert('❌ Réseau : '+e); });
    };
    // ── Clôture de la cérémonie de signature (action explicite de l'agent) ──
    window.belCloturer = function(){ document.getElementById('bel-clot-modal').style.display='flex'; };
    window.belClotClose = function(){ document.getElementById('bel-clot-modal').style.display='none'; };
    window.belClotConfirm = function(btn){
        var msg=document.getElementById('bel-clot-msg');
        btn.disabled=true; msg.style.color='#5f8f93'; msg.textContent='⏳ Clôture, génération du PDF signé et envoi…';
        fetch('<?= h(app_url('/api/bail_cloturer.php')) ?>', {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json'}, body: JSON.stringify({bail_id: <?= (int)$bailId ?>})})
          .then(function(r){return r.json();}).then(function(j){
            if(j && j.ok){ msg.style.color='#2d8a4e'; msg.textContent='✅ '+j.message; setTimeout(function(){ location.reload(); }, 1400); }
            else { btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'Clôture impossible'); }
          }).catch(function(e){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ Réseau : '+e; });
    };
    // ── Signature en présentiel (pad à l'écran) ──
    (function(){
      var BAIL_ID = <?= (int)$bailId ?>;
      var API_SIGN = '<?= h(app_url('/api/bail_sign_presentiel.php')) ?>';
      // Noms des signataires repris du bail → pré-remplissage automatique du champ « Nom ».
      var BEL_SIGN_NAMES = {
        preneur:    <?= json_encode($bail['locataire_raison_sociale'] ?: trim((string)($bail['locataire_prenom'] ?? '').' '.($bail['locataire_nom'] ?? '')), JSON_UNESCAPED_UNICODE) ?>,
        caution:    <?= json_encode($bail['garant_raison_sociale'] ?: trim((string)($bail['garant_prenom'] ?? '').' '.($bail['garant_nom'] ?? '')), JSON_UNESCAPED_UNICODE) ?>,
        bailleur:   <?= json_encode($proprietaireNom !== '—' ? $proprietaireNom : '', JSON_UNESCAPED_UNICODE) ?>,
        mandataire: <?= json_encode(trim((string)($bail['bailleur_representant_nom'] ?? '')), JSON_UNESCAPED_UNICODE) ?>
      };
      // État par rôle (déjà signé ?) — pour ré-afficher la signature/photo au retour.
      var BEL_SIGN_STATE = <?= json_encode($belSignState, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
      var API_GET = '<?= h(app_url('/api/bail_sign_get.php')) ?>';
      window.belSignFillName = function(){
        var role=document.getElementById('bel-sign-role').value;
        var nomEl=document.getElementById('bel-sign-nom');
        // n'écrase pas une saisie manuelle existante
        if(nomEl && !nomEl.value.trim()) nomEl.value = BEL_SIGN_NAMES[role] || '';
      };
      // Changement de signataire → remplace le nom par celui du rôle + affiche le bon panneau
      // (formulaire de signature OU récap « déjà signé » avec possibilité d'effacer).
      window.belSignRoleChange = function(role){
        var nomEl=document.getElementById('bel-sign-nom');
        if(nomEl) nomEl.value = BEL_SIGN_NAMES[role] || '';
        belApplyRolePanel(role);
      };
      var belSignedFlag=false;   // au moins une signature enregistrée dans cette séance → reload à la fermeture
      var belDoneSigId=0;        // id de la signature affichée dans le panneau « déjà signé »
      // Affiche le panneau adapté au rôle : déjà signé (récap + effacer) ou formulaire vierge.
      function belApplyRolePanel(role){
        var st=BEL_SIGN_STATE[role], done=document.getElementById('bel-sign-done'), form=document.getElementById('bel-sign-form');
        if(st && st.signed){
          belDoneSigId = st.id;
          document.getElementById('bel-done-title').textContent = '✓ Déjà signé' + (st.nom?' par '+st.nom:'') + (st.date?' le '+st.date:'');
          var sig=document.getElementById('bel-done-sig'), ph=document.getElementById('bel-done-photo');
          var jw=document.getElementById('bel-done-justif-wrap'), jd=document.getElementById('bel-done-justif');
          sig.src=''; ph.src=''; if(jw) jw.style.display='none'; if(jd) jd.style.display='none';
          // Récupère tracé + photo à la demande. La photo (justificatif) reste MASQUÉE
          // jusqu'au clic sur « Voir le justificatif » (preuve privée, jamais affichée d'office).
          fetch(API_GET,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({bail_id:BAIL_ID, sig_id:st.id})})
            .then(function(r){return r.json();}).then(function(j){
              if(j&&j.ok){ if(j.signature_data){ sig.src=j.signature_data; } if(j.photo_preuve){ ph.src=j.photo_preuve; if(jw) jw.style.display='block'; } }
            }).catch(function(){});
          done.hidden=false; form.hidden=true;
        } else {
          belDoneSigId=0; done.hidden=true; form.hidden=false;
        }
      }
      // Bascule d'affichage du justificatif privé (photo-preuve).
      window.belToggleJustif = function(){
        var jd=document.getElementById('bel-done-justif'), btn=document.getElementById('bel-done-justif-btn');
        if(!jd) return;
        var show = jd.style.display==='none';
        jd.style.display = show ? 'block' : 'none';
        if(btn) btn.textContent = show ? '🔒 Masquer le justificatif de la signature électronique' : '🔒 Voir le justificatif de la signature électronique';
      };
      // Effacer la signature affichée (tracé + photo) → le rôle redevient signable.
      window.belDoneErase = function(){
        if(!belDoneSigId) return;
        if(!confirm('Effacer la signature et la photo de ce signataire ? Il pourra re-signer.')) return;
        fetch('<?= h(app_url('/api/bail_sign_annuler.php')) ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({bail_id:BAIL_ID, sig_id:belDoneSigId})})
          .then(function(r){return r.json();}).then(function(j){
            if(j&&j.ok){
              belSignedFlag=true;   // la bannière devra se rafraîchir à la fermeture
              var role=document.getElementById('bel-sign-role').value;
              if(BEL_SIGN_STATE[role]) BEL_SIGN_STATE[role].signed=false;
              belApplyRolePanel(role);   // repasse en mode formulaire
              var msg=document.getElementById('bel-sign-msg'); if(msg){ msg.style.color='#2d8a4e'; msg.textContent='✅ Signature effacée — tu peux re-signer.'; }
            } else { alert('❌ '+((j&&j.error)||'Effacement impossible')); }
          }).catch(function(e){ alert('❌ Réseau : '+e); });
      };
      var canvas, ctx, drawing=false, hasDrawn=false, padInit=false;
      window.belSignOpen = function(){
        document.getElementById('bel-sign-modal').style.display='flex';
        belSignFillName();
        belApplyRolePanel(document.getElementById('bel-sign-role').value);
        // reset caméra/photo
        photoData=null; belCamStop();
        var pv=document.getElementById('bel-cam-preview'); if(pv) pv.style.display='none';
        var vv=document.getElementById('bel-cam-video'); if(vv) vv.style.display='none';
        var cs=document.getElementById('bel-cam-start'); if(cs) cs.style.display='';
        var sh=document.getElementById('bel-cam-shot'); if(sh) sh.style.display='none';
        var rt=document.getElementById('bel-cam-retake'); if(rt) rt.style.display='none';
        // Attend le layout du modal puis (re)dimensionne le canvas et branche les Pointer Events.
        requestAnimationFrame(function(){ setTimeout(initPad, 20); });
      };
      window.belSignClose = function(){
        belCamStop(); document.getElementById('bel-sign-modal').style.display='none';
        // Si des signatures ont été prises → recharge pour rafraîchir la bannière (statuts + bouton Clôturer).
        if(belSignedFlag) location.reload();
      };
      // ── Photo-preuve : caméra PC/téléphone (getUserMedia) ──
      var photoData=null, camStream=null;
      function belCamStop(){ if(camStream){ camStream.getTracks().forEach(function(t){t.stop();}); camStream=null; } }
      window.belCamStart=function(){
        var v=document.getElementById('bel-cam-video'), msg=document.getElementById('bel-sign-msg');
        if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){ msg.style.color='#c62828'; msg.textContent='📷 Caméra non disponible sur ce navigateur.'; return; }
        navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false})
          .then(function(s){ camStream=s; v.srcObject=s; v.style.display='block';
            document.getElementById('bel-cam-start').style.display='none';
            document.getElementById('bel-cam-shot').style.display='';
            document.getElementById('bel-cam-preview').style.display='none';
            document.getElementById('bel-cam-retake').style.display='none'; })
          .catch(function(e){ msg.style.color='#c62828'; msg.textContent='📷 Caméra bloquée par le navigateur. Autorise-la (icône caméra dans la barre d\'adresse) ou utilise « 📁 Prendre / choisir une photo ».'; });
      };
      // Plan B : photo via l'appareil natif (téléphone) ou sélecteur de fichier (PC) — sans getUserMedia.
      window.belCamFile=function(inp){
        var f=inp.files&&inp.files[0]; if(!f) return;
        var msg=document.getElementById('bel-sign-msg');
        var rd=new FileReader();
        rd.onload=function(ev){
          var im=new Image();
          im.onload=function(){
            var max=640, w=im.width, h=im.height;
            if(w>max||h>max){ if(w>=h){ h=Math.round(h*max/w); w=max; } else { w=Math.round(w*max/h); h=max; } }
            var c=document.createElement('canvas'); c.width=w; c.height=h;
            c.getContext('2d').drawImage(im,0,0,w,h);
            photoData=c.toDataURL('image/jpeg',0.72);
            var pv=document.getElementById('bel-cam-preview'); pv.src=photoData; pv.style.display='block';
            document.getElementById('bel-cam-video').style.display='none';
            document.getElementById('bel-cam-retake').style.display='';
            if(msg){ msg.style.color='#2d8a4e'; msg.textContent='✅ Photo ajoutée.'; }
          };
          im.src=ev.target.result;
        };
        rd.readAsDataURL(f);
      };
      window.belCamCapture=function(){
        var v=document.getElementById('bel-cam-video');
        var c=document.createElement('canvas'); c.width=v.videoWidth||320; c.height=v.videoHeight||240;
        c.getContext('2d').drawImage(v,0,0,c.width,c.height);
        photoData=c.toDataURL('image/jpeg',0.72);
        var img=document.getElementById('bel-cam-preview'); img.src=photoData; img.style.display='block';
        v.style.display='none'; belCamStop();
        document.getElementById('bel-cam-shot').style.display='none';
        document.getElementById('bel-cam-retake').style.display='';
      };
      window.belCamRetake=function(){ photoData=null; document.getElementById('bel-cam-preview').style.display='none'; belCamStart(); };
      function initPad(){
        canvas = document.getElementById('bel-sign-pad'); if(!canvas) return;
        // Taille réelle (CSS px) — indispensable pour un mapping correct des coordonnées.
        var r = canvas.getBoundingClientRect();
        if (r.width < 5) { setTimeout(initPad, 60); return; }   // pas encore layouté
        canvas.width = Math.round(r.width); canvas.height = Math.round(r.height);
        ctx = canvas.getContext('2d'); ctx.lineWidth=2.6; ctx.lineJoin='round'; ctx.lineCap='round'; ctx.strokeStyle='#1f2937';
        hasDrawn=false;
        if (padInit) return;   // ne branche les listeners qu'une fois
        padInit = true;
        canvas.style.touchAction = 'none';   // empêche le scroll pendant qu'on signe
        function pos(e){ var b=canvas.getBoundingClientRect(); return { x:(e.clientX-b.left)*(canvas.width/b.width), y:(e.clientY-b.top)*(canvas.height/b.height) }; }
        canvas.addEventListener('pointerdown', function(e){
          drawing=true; try{ canvas.setPointerCapture(e.pointerId); }catch(_){}
          var p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y);
          // point initial visible même sur un simple tap
          ctx.lineTo(p.x+0.1,p.y+0.1); ctx.stroke(); hasDrawn=true; e.preventDefault();
        });
        canvas.addEventListener('pointermove', function(e){
          if(!drawing) return; var p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); e.preventDefault();
        });
        var stop=function(e){ drawing=false; e && e.preventDefault && e.preventDefault(); };
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        canvas.addEventListener('pointerleave', function(){ drawing=false; });
      }
      window.belSignClear = function(){ if(ctx) ctx.clearRect(0,0,canvas.width,canvas.height); hasDrawn=false; };
      window.belSignSubmit = function(btn){
        var role=document.getElementById('bel-sign-role').value;
        var nom=document.getElementById('bel-sign-nom').value.trim();
        var appr=document.getElementById('bel-sign-appr').checked;
        var msg=document.getElementById('bel-sign-msg');
        if(!nom){ msg.textContent='Nom du signataire requis.'; return; }
        if(!appr){ msg.textContent='Merci de cocher « lu et approuvé ».'; return; }
        if(!hasDrawn){ msg.textContent='Merci de signer dans le cadre.'; return; }
        var data = canvas.toDataURL('image/png');
        btn.disabled=true; msg.style.color='#5f8f93'; msg.textContent='⏳ Enregistrement…';
        fetch(API_SIGN,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({bail_id:BAIL_ID, role:role, nom:nom, signature:data, photo:photoData||''})})
          .then(function(r){return r.json();}).then(function(j){
            if(j&&j.ok){
              belSignedFlag=true;
              // Mémorise l'état signé de ce rôle (retour sur ce rôle → affiche sa signature/photo).
              if(j.sig_id){ BEL_SIGN_STATE[role]={id:j.sig_id, signed:true, nom:nom, date:''}; }
              msg.style.color='#2d8a4e';
              msg.textContent='✅ Signé ('+(j.signes||'?')+'/'+(j.total||'?')+'). Choisis le signataire suivant, ou ferme pour clôturer.';
              // Reset pour le signataire SUIVANT (sans fermer le modal, sans clôturer).
              belSignClear();
              photoData=null;
              var pv=document.getElementById('bel-cam-preview'); if(pv) pv.style.display='none';
              document.getElementById('bel-cam-retake').style.display='none';
              document.getElementById('bel-cam-start').style.display='';
              document.getElementById('bel-sign-appr').checked=false;
              document.getElementById('bel-sign-nom').value='';
              btn.disabled=false;
            }
            else { btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'Échec'); }
          }).catch(function(e){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ Réseau : '+e; });
      };
    })();
    </script>
    <div id="bel-sign-modal" style="display:none;position:fixed;inset:0;z-index:9600;background:rgba(15,18,24,.55);align-items:center;justify-content:center;padding:18px;">
      <div style="background:#fff;border-radius:16px;width:min(560px,96vw);padding:22px 24px;box-shadow:0 24px 60px rgba(0,0,0,.35);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <h3 style="margin:0;font-size:17px;color:#243B5C;">✍️ Signature en présentiel</h3>
          <button type="button" onclick="belSignClose()" style="border:none;background:#eceef1;border-radius:50%;width:30px;height:30px;cursor:pointer;font-weight:700;">✕</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <label style="font-size:12px;font-weight:700;color:#475569;">Signataire
            <select id="bel-sign-role" onchange="belSignRoleChange(this.value)" style="width:100%;margin-top:4px;padding:9px;border:1px solid #cbd8da;border-radius:8px;">
              <option value="preneur">Preneur</option>
              <?php if (!empty($bail['garant_present'])): ?><option value="caution">Garant (caution)</option><?php endif; ?>
              <option value="bailleur">Bailleur</option>
              <option value="mandataire">Représentant du bailleur (mandataire)</option>
            </select></label>
          <label style="font-size:12px;font-weight:700;color:#475569;">Nom et prénom
            <input type="text" id="bel-sign-nom" placeholder="Ex. Jean Dupont" style="width:100%;margin-top:4px;padding:9px;border:1px solid #cbd8da;border-radius:8px;"></label>
        </div>
        <!-- Panneau « déjà signé » : affiché quand on revient sur un signataire validé -->
        <div id="bel-sign-done" hidden style="border:1px solid #bfe6cc;background:#f2fbf5;border-radius:10px;padding:12px 14px;margin-bottom:10px;">
          <div id="bel-done-title" style="font-size:13px;font-weight:800;color:#15803d;margin-bottom:8px;">✓ Déjà signé</div>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <img id="bel-done-sig" alt="signature" style="max-height:70px;max-width:200px;border:1px solid #d6e0e0;border-radius:6px;background:#fff;">
          </div>
          <!-- Justificatif privé : la photo-preuve n'est JAMAIS dans le bail signé ni montrée
               au preneur. Consultable uniquement en interne, à la demande, via ce bouton. -->
          <div id="bel-done-justif-wrap" style="display:none;margin-top:10px;">
            <button type="button" id="bel-done-justif-btn" onclick="belToggleJustif()" style="border:1px solid #c9d6e5;background:#eef3f9;color:#243B5C;border-radius:8px;padding:7px 13px;font-weight:800;cursor:pointer;font-size:12.5px;">🔒 Voir le justificatif de la signature électronique</button>
            <div id="bel-done-justif" style="display:none;margin-top:10px;">
              <img id="bel-done-photo" alt="photo-preuve" style="max-height:120px;max-width:120px;border:1px solid #d6e0e0;border-radius:6px;">
              <div style="font-size:11px;color:#64748b;margin-top:4px;">Preuve interne — jamais imprimée dans le bail ni transmise au preneur.</div>
            </div>
          </div>
          <button type="button" onclick="belDoneErase()" style="margin-top:10px;border:1px solid #f0b8b0;background:#fdecea;color:#c0392b;border-radius:8px;padding:8px 14px;font-weight:800;cursor:pointer;font-size:12.5px;">🗑️ Effacer la signature et la photo (re-signer)</button>
        </div>
        <div id="bel-sign-form">
        <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">Signez dans le cadre 👇</div>
        <canvas id="bel-sign-pad" style="width:100%;height:180px;border:2px dashed #b7cdcf;border-radius:10px;background:#fbfdfd;touch-action:none;cursor:crosshair;"></canvas>
        <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;color:#3a5a5c;margin:12px 0;">
          <input type="checkbox" id="bel-sign-appr" style="margin-top:3px;transform:scale(1.2);">
          <span>J'ai lu et j'approuve les termes de ce bail commercial. Ma signature vaut engagement.</span>
        </label>
        <!-- Photo-preuve (optionnelle) : webcam PC ou caméra du téléphone -->
        <div style="border:1px dashed #cbd8da;border-radius:10px;padding:10px 12px;margin-bottom:12px;background:#fafcfc;">
          <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">📷 Photo-preuve du signataire <span style="font-weight:500;color:#94a3b8;">(optionnel — placée à côté de la signature)</span></div>
          <video id="bel-cam-video" autoplay playsinline muted style="display:none;width:100%;max-height:200px;border-radius:8px;background:#000;"></video>
          <img id="bel-cam-preview" alt="" style="display:none;max-height:150px;border-radius:8px;border:1px solid #d6e0e0;">
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
            <button type="button" id="bel-cam-start"  onclick="belCamStart()"   style="border:1px solid #cbd8da;background:#eef5f5;color:#3a5a5c;border-radius:8px;padding:7px 12px;font-weight:700;cursor:pointer;font-size:12.5px;">📷 Activer la caméra</button>
            <button type="button" id="bel-cam-shot"   onclick="belCamCapture()" style="display:none;border:none;background:#5f8f93;color:#fff;border-radius:8px;padding:7px 12px;font-weight:800;cursor:pointer;font-size:12.5px;">📸 Capturer</button>
            <button type="button" id="bel-cam-retake" onclick="belCamRetake()"  style="display:none;border:1px solid #cbd8da;background:#f4f9f9;color:#5b6b70;border-radius:8px;padding:7px 12px;font-weight:700;cursor:pointer;font-size:12.5px;">🔄 Reprendre</button>
            <label style="border:1px solid #cbd8da;background:#fff;color:#3a5a5c;border-radius:8px;padding:7px 12px;font-weight:700;cursor:pointer;font-size:12.5px;">📁 Prendre / choisir une photo
              <input type="file" id="bel-cam-file" accept="image/*" capture="user" onchange="belCamFile(this)" style="display:none;">
            </label>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <button type="button" onclick="belSignClear()" style="border:1px solid #cbd8da;background:#f4f9f9;color:#5b6b70;border-radius:9px;padding:9px 14px;font-weight:800;cursor:pointer;">🧹 Effacer</button>
          <button type="button" onclick="belSignSubmit(this)" style="border:none;background:#5f8f93;color:#fff;border-radius:9px;padding:9px 18px;font-weight:800;cursor:pointer;">✍️ Signer</button>
          <span id="bel-sign-msg" style="flex:1;font-size:12.5px;font-weight:700;"></span>
        </div>
        </div><!-- /bel-sign-form -->
      </div>
    </div>
    <!-- Modal de CLÔTURE : récap des signataires + validation explicite -->
    <div id="bel-clot-modal" style="display:none;position:fixed;inset:0;z-index:9600;background:rgba(15,18,24,.55);align-items:center;justify-content:center;padding:18px;">
      <div style="background:#fff;border-radius:16px;width:min(520px,96vw);padding:22px 24px;box-shadow:0 24px 60px rgba(0,0,0,.35);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <h3 style="margin:0;font-size:17px;color:#243B5C;">✅ Clôturer la signature du bail</h3>
          <button type="button" onclick="belClotClose()" style="border:none;background:#eceef1;border-radius:50%;width:30px;height:30px;cursor:pointer;font-weight:700;">✕</button>
        </div>
        <p style="font-size:13px;color:#475569;margin:0 0 10px;">Vérifiez que <b>toutes les parties</b> ont bien signé. La clôture est <b>définitive</b> : le bail devient signé, le PDF signé est classé en GED et envoyé aux signataires.</p>
        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px;">
          <?php foreach ($belSignataires as $s): $sg = ($s['statut'] ?? '') === 'signe'; ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 10px;border-radius:8px;background:<?= $sg ? '#e7f6ec' : '#fbf3e6' ?>;">
            <span style="font-weight:800;color:<?= $sg ? '#15803d' : '#a26a1c' ?>;"><?= $sg ? '✓ signé' : '⏳ en attente' ?></span>
            <span style="font-weight:700;color:#334155;"><?= h($belRoleLbl[$s['role_code']] ?? ucfirst((string)$s['role_code'])) ?></span>
            <span style="color:#64748b;"><?= !empty($s['nom_signataire']) ? h($s['nom_signataire']) : '' ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <button type="button" onclick="belClotClose()" style="border:1px solid #cbd8da;background:#f4f9f9;color:#5b6b70;border-radius:9px;padding:9px 16px;font-weight:800;cursor:pointer;">Annuler</button>
          <button type="button" onclick="belClotConfirm(this)" <?= ($belSigTotal > 0 && $belSigDone === $belSigTotal) ? '' : 'disabled' ?> style="border:none;background:#15803d;color:#fff;border-radius:9px;padding:9px 18px;font-weight:800;cursor:pointer;">✅ Valider et clôturer</button>
          <span id="bel-clot-msg" style="flex:1;font-size:12.5px;font-weight:700;"></span>
        </div>
      </div>
    </div>
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

    <?php
    // Bail signé déjà en GED ? → proposer l'EXTRACTION de ses données (cache-first, gratuit si déjà analysé).
    $bailHasSignedDoc = false;
    try {
        $stBsd = $pdo->prepare("SELECT COUNT(*) FROM ged_documents WHERE id_bail=? AND COALESCE(status,'active')='active' AND UPPER(document_type) IN ('BAIL_SIGNE','BAIL')");
        $stBsd->execute([(int)$bailId]);
        $bailHasSignedDoc = (int)$stBsd->fetchColumn() > 0;
    } catch (Throwable $e) {}
    ?>
    <?php if ($bailHasSignedDoc): ?>
    <!-- 📄 Bail signé attaché → extraction de ses données vers cette fiche (cache-first) -->
    <div class="f360-card" style="--acc:#84A7AB;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:space-between;">
            <div style="font-size:12.5px;color:#3a5a5c;font-weight:700;">📄 Bail signé attaché — extraire ses données (loyer, DG, dates, indice, clauses) vers cette fiche.</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" id="bailExtractBtn" style="border:none;background:#84A7AB;color:#fff;border-radius:999px;padding:8px 16px;font-size:12.5px;font-weight:800;cursor:pointer;">📄 Extraire les données</button>
                <button type="button" id="bailReextractBtn" title="Ré-analyse le PDF (appel IA payant) et écrase les valeurs actuelles" style="border:1px solid #84A7AB;background:#fff;color:#3a5a5c;border-radius:999px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">🔄 Ré-analyser &amp; écraser</button>
            </div>
        </div>
        <div id="bailExtractMsg" style="font-size:11.5px;color:#7a8a8c;margin-top:8px;"></div>
    </div>
    <script>
    (function(){
        var EP=<?= json_encode(app_url('/api/bail_reextract.php')) ?>, CSRF=<?= json_encode(function_exists('csrf_token') ? csrf_token('bail_reextract') : '') ?>, BAIL=<?= (int)$bailId ?>;
        var msg=document.getElementById('bailExtractMsg');
        function run(fresh,overwrite,btn){
            btn.disabled=true; msg.style.color='#7a8a8c';
            msg.textContent = fresh ? '⏳ Ré-analyse du PDF (IA)…' : '⏳ Extraction…';
            var fd=new FormData(); fd.append('id_bail',BAIL); fd.append('csrf_token',CSRF); fd.append('fresh',fresh?'1':'0'); fd.append('overwrite',overwrite?'1':'0');
            fetch(EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
                if(j&&j.ok){ msg.style.color='#2d8a4e'; msg.textContent='✅ '+j.message; if(j.reload){ setTimeout(function(){location.reload();},900);} else { btn.disabled=false; } }
                else { msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'Échec'); btn.disabled=false; }
            }).catch(function(e){ msg.style.color='#c62828'; msg.textContent='❌ Réseau : '+e; btn.disabled=false; });
        }
        var b1=document.getElementById('bailExtractBtn'), b2=document.getElementById('bailReextractBtn');
        if(b1) b1.addEventListener('click',function(){ run(false,false,b1); });
        if(b2) b2.addEventListener('click',function(){ if(!confirm('Ré-analyser le PDF (appel IA payant) et ÉCRASER les valeurs actuelles ?'))return; run(true,true,b2); });
    })();
    </script>
    <?php endif; ?>

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
        <h3>📂 Documents du bail <span class="count"><?= count($docs) ?></span>
            <button type="button" onclick="gedToggleArchives(this,'BAIL',<?= (int)$bailId ?>)" style="float:right;border:1px solid #e0d6c4;background:#fbf7ef;color:#a26a1c;border-radius:7px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">📦 Voir les archives</button></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/bien_documents_list.php?id=' . $bail['bien_id'])) ?>">→ Gérer les documents du bien</a></div>
        <?php else: foreach ($docs as $d): ?>
            <div onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>, window.BAIL_FIELDS)"
                 style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center; cursor:pointer;"
                 onmouseover="this.style.background='#faf8ff'" onmouseout="this.style.background='transparent'">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;">[<?= h($d['document_type']) ?>]</span>
                <span style="flex:1;" title="<?= h($d['name_display']) ?>"><?= h(ged_doc_tail_from_level($d, 'bail')) ?></span>
                <span style="color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                <button type="button" onclick="event.stopPropagation();gedDeleteDoc(<?= (int)$d['id'] ?>,<?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>,this)" title="Supprimer" style="border:none;background:transparent;color:#c0392b;cursor:pointer;font-size:13px;padding:0 2px;">🗑️</button>
                <span style="color:#5b21b6; font-size:11px; font-weight:700;">Ouvrir ›</span>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
    <?php require_once __DIR__ . '/inc/ged_delete_modal.php'; ?>

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
    if (function_exists('fiche360_mail_history')) fiche360_mail_history($pdo, 'bail:' . $bailId);
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
        ['icon'=>'📧','label'=>'Envoyer un document par mail','url'=>mail_compose_url('BAIL', $bailId, 'bail_360.php?id=' . $bailId)],
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

    // ── CONTACTS DU BAIL — CARTE UNIQUE ──
    // Regroupe bailleur + (représentant) + locataire + (représentant) + gestionnaire + acteurs
    // génériques. Remplace les anciennes cartes séparées BAILLEUR / LOCATAIRE (consigne : tout
    // dans une seule carte, chaque contact cliquable vers sa fiche tiers).
    $contactsBail = [];

    // Bailleur (propriétaire → fiche tiers)
    if (!empty($bail['proprio_id'])) {
        $contactsBail[] = [
            'icon' => '👤',
            'name' => $proprietaireNom,
            'ref'  => 'Bailleur' . (!empty($bail['proprio_tiers_id']) ? ' · tiers #' . $bail['proprio_tiers_id'] : ''),
            'url'  => !empty($bail['proprio_tiers_id']) ? app_url('/tiers_360.php?id=' . $bail['proprio_tiers_id']) : app_url('/agency_proprietaires.php?q=' . urlencode($proprietaireNom)),
        ];
        if (!empty($bail['bailleur_representant_nom'])) {
            $contactsBail[] = [
                'icon' => '👥',
                'name' => $bail['bailleur_representant_nom'] . ($bail['bailleur_representant_qualite'] ? ' (' . $bail['bailleur_representant_qualite'] . ')' : ''),
                'ref'  => 'Représentant bailleur' . ($bail['bailleur_representant_email'] ? ' · ' . $bail['bailleur_representant_email'] : ''),
                'url'  => $bail['bailleur_representant_email'] ? 'mailto:' . $bail['bailleur_representant_email'] : '#',
            ];
        }
    }

    // Locataire : sur un PROJET, le tiers promu (loc_tiers_id) n'existe pas encore → on pointe la
    // fiche du CANDIDAT (candidat_tiers_id). Corrige le lien qui renvoyait à l'accueil.
    $locTiersLink = (int)($bail['loc_tiers_id'] ?? 0) ?: (int)($bail['candidat_tiers_id'] ?? 0);
    $contactsBail[] = [
        'icon' => '🔑',
        'name' => $locataireNom,
        'ref'  => 'Locataire' . ($locTiersLink ? ' · tiers #' . $locTiersLink : ''),
        'url'  => $locTiersLink ? app_url('/tiers_360.php?id=' . $locTiersLink) : '#',
    ];
    if (!empty($bail['locataire_representant_nom'])) {
        $contactsBail[] = [
            'icon' => '👥',
            'name' => $bail['locataire_representant_nom'] . ($bail['locataire_representant_qualite'] ? ' (' . $bail['locataire_representant_qualite'] . ')' : ''),
            'ref'  => 'Représentant locataire' . ($bail['locataire_representant_email'] ? ' · ' . $bail['locataire_representant_email'] : ''),
            'url'  => $bail['locataire_representant_email'] ? 'mailto:' . $bail['locataire_representant_email'] : '#',
        ];
    }

    // Gestionnaire (société de gestion + agence)
    $gestNom = (string)($socRow['raison_sociale'] ?? '');
    if ($gestNom !== '') {
        $ageNom = (string)($ageRow['nom_agence'] ?? '');
        $contactsBail[] = [
            'icon' => '🏢',
            'name' => $gestNom . ($ageNom ? ' — ' . $ageNom : ''),
            'ref'  => 'Gestionnaire',
            'url'  => '#',
        ];
    }

    // Contacts génériques du bail (socle acteurs) — DÉFENSIF : ne casse jamais la colonne.
    $eaBailLinks = []; $eaBailBtn = '';
    try {
        if (is_file(__DIR__ . '/inc/entite_acteurs.php')) {
            require_once __DIR__ . '/inc/entite_acteurs.php';
            if (function_exists('entite_acteurs_links'))         $eaBailLinks = entite_acteurs_links($pdo, 'BAIL', $bailId, csrf_token('default'));
            if (function_exists('entite_acteurs_header_button')) $eaBailBtn   = entite_acteurs_header_button('ea_bail', 'BAIL', $bailId, csrf_token('default'));
        }
    } catch (\Throwable $e) { $eaBailBtn = ''; }
    $contactsBail = array_merge($contactsBail, is_array($eaBailLinks) ? $eaBailLinks : []);
    fiche360_attach('CONTACTS (' . count($contactsBail) . ')', $contactsBail, $eaBailBtn);

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
