<?php
/**
 * bien_doc_360.php
 *
 * Vue 360° d'un document Transaction/Gestion rattaché à un bien.
 * Wraps la logique métier de doc_upload_review.php dans le layout fiche 360° standard
 * (sidebar + topbar + breadcrumb + header + cards) avec le PDF en colonne gauche.
 *
 * Paramètres GET :
 *   - bien_id    : ID du bien (requis)
 *   - doc_id     : ID fluxbox_documents (requis)
 *   - card_id    : ID fluxbox_cartes (optionnel)
 *   - source     : transaction | fluxbox | direct (défaut: direct)
 *   - ctx_type   : BIEN (V1)
 *   - extract_ia : 1 → trigger Sonnet puis redirige
 *
 * Réf : Sprint 6 A1 — refonte style fiche 360° (2026-05-24)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/feature_flags.php';
require_once __DIR__ . '/inc/entity_matcher.php';
require_once __DIR__ . '/inc/ged_doc_naming_v3.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$pdo = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['id_user'] ?? 0);
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1);

// ─── Paramètres ──
$source  = (string)($_GET['source']   ?? 'direct');
$ctxType = strtoupper((string)($_GET['ctx_type'] ?? 'BIEN'));
$bienId  = (int)($_GET['bien_id']  ?? 0);
$docId   = (int)($_GET['doc_id']   ?? 0);
$cardId  = (int)($_GET['card_id']  ?? 0);
$doExtractIa = (int)($_GET['extract_ia'] ?? 0) === 1;

if (!in_array($source, ['transaction', 'fluxbox', 'direct'], true)) $source = 'direct';
if (!in_array($ctxType, ['BIEN', 'IMB', 'TIERS'], true))            $ctxType = 'BIEN';
if ($bienId <= 0 || $docId <= 0) { http_response_code(400); exit('bien_id et doc_id requis.'); }

if (!function_exists('bd360_html')) {
    function bd360_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// ═════════════════════════════════════════════════════════════════════
// PRÉFETCH MÉTIER
// ═════════════════════════════════════════════════════════════════════
$st = $pdo->prepare("SELECT b.*, i.adresse_1 AS imm_adresse, i.code_postal AS imm_cp,
                            i.ville AS imm_ville, i.nom_immeuble AS imm_nom,
                            i.id_societe AS imm_societe, i.id_agence AS imm_agence
                     FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit("Bien #$bienId introuvable."); }

// Fix B3 (2026-05-26) : résolution intelligente du document.
// Si doc_id existe → utilise directement.
// Sinon si card_id fourni → résout via fluxbox_cartes.document_id (à jour après backfill tenant).
// Sinon si doc_id ressemble à un card_id qui existe → fallback automatique.
$doc = null;
if ($docId > 0) {
    $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Fallback 1 : card_id fourni → lit le document_id à jour depuis la carte
if (!$doc && $cardId > 0) {
    $st = $pdo->prepare("SELECT d.* FROM fluxbox_cartes c JOIN fluxbox_documents d ON d.id = c.document_id WHERE c.id = ?");
    $st->execute([$cardId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($doc) {
        $docId = (int)$doc['id'];
        error_log("[bien_doc_360] B3 fallback : doc_id résolu via card_id=$cardId → doc_id=$docId");
    }
}
// Fallback 2 : doc_id fourni est en fait un card_id (URL legacy)
if (!$doc && $docId > 0) {
    $st = $pdo->prepare("SELECT d.* FROM fluxbox_cartes c JOIN fluxbox_documents d ON d.id = c.document_id WHERE c.id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($doc) {
        error_log("[bien_doc_360] B3 fallback : doc_id=$docId était en fait un card_id → doc résolu #{$doc['id']}");
        $cardId = $docId;
        $docId = (int)$doc['id'];
    }
}
if (!$doc) {
    http_response_code(404);
    exit("Document introuvable (doc_id=$docId, card_id=$cardId). Vérifie l'URL ou utilise card_id à la place.");
}
$fileName = (string)$doc['fichier_nom'];

// ─── Propriétaire / tiers ──
$proprietaire = null;
if (!empty($bien['id_proprietaire'])) {
    $st = $pdo->prepare("SELECT id, id_tiers, type_personne, nom, prenom, societe, email, telephone FROM proprietaires WHERE id = ?");
    $st->execute([(int)$bien['id_proprietaire']]);
    $proprietaire = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$proprioTiersId = (int)($bien['id_tiers'] ?? ($proprietaire['id_tiers'] ?? 0));
$proprioTiers = null;
if ($proprioTiersId > 0) {
    $st = $pdo->prepare("SELECT id, id_societe, id_agence, type_tiers, nom, prenom, raison_sociale, email, telephone FROM tiers WHERE id = ?");
    $st->execute([$proprioTiersId]);
    $proprioTiers = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$proprioLabel = '';
if ($proprietaire) $proprioLabel = trim(($proprietaire['societe'] ?? '') ?: (($proprietaire['prenom'] ?? '') . ' ' . ($proprietaire['nom'] ?? '')));
elseif ($proprioTiers) $proprioLabel = trim(($proprioTiers['raison_sociale'] ?? '') ?: (($proprioTiers['prenom'] ?? '') . ' ' . ($proprioTiers['nom'] ?? '')));

// ─── Mandat / Bail / Annonce ──
$mandat = null; $mandatActif = null;
try {
    $st = $pdo->prepare("SELECT id, numero_mandat, type_mandat, nature_mandat, statut FROM mandats WHERE id_bien = ? ORDER BY (statut IN ('actif','en_cours','signe')) DESC, date_signature DESC LIMIT 1");
    $st->execute([$bienId]);
    $mandat = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($mandat && in_array((string)$mandat['statut'], ['actif','en_cours','signe',''], true)) $mandatActif = $mandat;
} catch (Throwable) {}
$bailActif = null;
try {
    $st = $pdo->prepare("SELECT id, type_bail, date_signature, statut, locataire_nom, loyer FROM baux WHERE id_bien = ? AND statut = 'actif' ORDER BY id DESC LIMIT 1");
    $st->execute([$bienId]);
    $bailActif = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}
$annonce = null;
try {
    $st = $pdo->prepare("SELECT id, type_transaction, statut, prix FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$bienId]);
    $annonce = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

// ─── Hydratation société/agence (cascade + défaut REGIE EMERY LYON) ──
$resolvedSocieteId = (int)($bien['id_societe'] ?? 0);
$resolvedAgenceId  = (int)($bien['id_agence']  ?? 0);
$resolvedSource    = $resolvedSocieteId ? 'bien' : null;
if ($resolvedSocieteId === 0 && !empty($bien['imm_societe'])) {
    $resolvedSocieteId = (int)$bien['imm_societe'];
    $resolvedAgenceId  = (int)$bien['imm_agence'];
    $resolvedSource    = 'immeuble';
}
if ($resolvedSocieteId === 0 && $proprioTiers && !empty($proprioTiers['id_societe'])) {
    $resolvedSocieteId = (int)$proprioTiers['id_societe'];
    $resolvedAgenceId  = (int)($proprioTiers['id_agence'] ?? 0);
    $resolvedSource    = 'tiers';
}
const BD360_DEFAULT_SOCIETE_ID = 1; // Régie EMERY
const BD360_DEFAULT_AGENCE_ID  = 3; // RE69-2 REGIE EMERY LYON
if ($resolvedSocieteId === 0) {
    $resolvedSocieteId = BD360_DEFAULT_SOCIETE_ID;
    $resolvedAgenceId  = BD360_DEFAULT_AGENCE_ID;
    $resolvedSource    = 'defaut_metier_REGIE_EMERY_LYON';
}
if (!$isAdmin) {
    $userSoc = (int)($_SESSION['id_societe'] ?? 0);
    if ($resolvedSocieteId > 0 && $resolvedSocieteId !== $userSoc) { http_response_code(403); exit('Accès interdit.'); }
}

$societeRaison = ''; $agenceCode = null; $agenceNom = '';
if ($resolvedSocieteId) {
    $societeRaison = (string)$pdo->query("SELECT raison_sociale FROM societes WHERE id = $resolvedSocieteId")->fetchColumn();
}
if ($resolvedAgenceId) {
    $a = $pdo->query("SELECT code_agence, nom_agence FROM agences WHERE id = $resolvedAgenceId")->fetch(PDO::FETCH_ASSOC) ?: [];
    $agenceCode = $a['code_agence'] ?? null;
    $agenceNom  = (string)($a['nom_agence']  ?? '');
}
$userMatricule = null; $userUsername = '';
if ($userId > 0) {
    $u = $pdo->query("SELECT matricule_paie, username FROM users WHERE id = $userId")->fetch(PDO::FETCH_ASSOC) ?: [];
    $userMatricule = $u['matricule_paie'] ?? null;
    $userUsername  = (string)($u['username']       ?? '');
}

// ─── Détection N1 contextuelle ──
$contexteN1 = '05_gestion_locative';
$contexteRaison = 'défaut (aucun signal de transaction)';
$mandatTypeLow = strtolower((string)($mandat['type_mandat'] ?? '') . ' ' . ($mandat['nature_mandat'] ?? ''));
if ($bailActif) {
    $contexteN1 = '05_gestion_locative';
    $contexteRaison = "bail actif #" . $bailActif['id'] . " sur ce bien";
} elseif ($mandatActif && (str_contains($mandatTypeLow, 'gestion') || str_contains($mandatTypeLow, 'location') || str_contains($mandatTypeLow, 'gerance'))) {
    $contexteN1 = '05_gestion_locative';
    $contexteRaison = "mandat de gestion #" . $mandatActif['id'];
} elseif ($mandatActif && (str_contains($mandatTypeLow, 'vente') || str_contains($mandatTypeLow, 'exclusif'))) {
    $contexteN1 = '06_transaction';
    $contexteRaison = "mandat de vente #" . $mandatActif['id'];
} elseif (!$mandatActif && $annonce && strtolower((string)$annonce['type_transaction']) === 'vente') {
    $contexteN1 = '06_transaction';
    $contexteRaison = "annonce vente #" . $annonce['id'];
}

// ─── Cache IA + extraction si demandée ──
$extraction = []; $cacheRow = null;
$hash = (string)$doc['hash_sha256'];
if ($doExtractIa && $hash !== '' && !empty($doc['fichier_chemin']) && is_file((string)$doc['fichier_chemin'])) {
    require_once __DIR__ . '/inc/transaction_doc_extract_ia.php';
    transaction_doc_extract_ia((string)$doc['fichier_chemin']);
    $cleanQs = $_GET; unset($cleanQs['extract_ia']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($cleanQs), true, 303);
    exit;
}
if ($hash !== '') {
    $st = $pdo->prepare("SELECT model, response_json, confidence, last_at FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
    $st->execute([$hash]);
    $cacheRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($cacheRow && !empty($cacheRow['response_json'])) $extraction = json_decode((string)$cacheRow['response_json'], true) ?: [];
}
$ia_type    = (string)($extraction['type_doc'] ?? $extraction['type'] ?? '');
$ia_label   = (string)($extraction['type_doc_label'] ?? '');
$ia_date    = (string)($extraction['date_signature'] ?? $extraction['date_doc'] ?? $extraction['date_debut_bail'] ?? '');
$ia_montant = (string)($extraction['loyer_mensuel_ht'] ?? $extraction['loyer_annuel_ht'] ?? $extraction['montant'] ?? '');
$ia_tiers   = (string)($extraction['proprietaire'] ?? $extraction['locataire'] ?? $extraction['bailleur_representant_nom'] ?? $extraction['tiers'] ?? '');
$ia_ref     = (string)($extraction['numero_mandat'] ?? $extraction['numero_dossier'] ?? $extraction['reference'] ?? '');
$ia_adresse = (string)($extraction['adresse_bien'] ?? '');
$ia_notes   = (string)($extraction['notes'] ?? '');
$ia_conf    = (int)($cacheRow['confidence'] ?? 0);

// ─── Détection type + raffinage ──
function bd360_detect_type_from_filename(string $fileName): string {
    $up = strtoupper(preg_replace('/[^A-Za-zÀ-ÿ0-9]/u', ' ', $fileName) ?? '');
    if (preg_match('/\b(ERNT|ERNMT|ERP|GEORISQUES|ETAT DES RISQUES|RISQUES NATURELS)\b/u', $up)) return 'erp_ernmt';
    if (preg_match('/\b(DPE|PERFORMANCE ENERGETIQUE)\b/u', $up)) return 'dpe';
    if (preg_match('/\bAMIANTE\b/u', $up)) return 'diagnostic_amiante';
    if (preg_match('/\b(PLOMB|CREP)\b/u', $up)) return 'diagnostic_plomb';
    if (preg_match('/\bELECTRI/u', $up)) return 'diagnostic_elec';
    if (preg_match('/\bGAZ\b/u', $up)) return 'diagnostic_gaz';
    if (preg_match('/\b(TERMITES?|MERULE)\b/u', $up)) return 'diagnostic_termites';
    if (preg_match('/\b(SURFACE CARREZ|CARREZ|BOUTIN)\b/u', $up)) return 'surface_carrez';
    if (preg_match('/\bMANDAT\b.*\b(GESTION|GERANCE|LOCATION)\b/u', $up)) return 'mandat_gestion';
    if (preg_match('/\bMANDAT\b.*\b(VENTE|EXCLUSIF|SIMPLE)\b/u', $up)) return 'mandat_vente';
    if (preg_match('/\bMANDAT\b/u', $up)) return 'mandat_simple';
    if (preg_match('/\b(COMPROMIS|PROMESSE)\b/u', $up)) return 'compromis';
    if (preg_match('/\b(ACTE AUTHENTIQUE|ACTE NOTARIE|ACTE)\b/u', $up)) return 'acte_authentique';
    if (preg_match('/\bBAIL\b/u', $up)) return 'bail_signe';
    if (preg_match('/\b(EDL|ETAT DES LIEUX).*\b(ENTREE|ARRIVEE)\b/u', $up)) return 'edl_entree';
    if (preg_match('/\b(EDL|ETAT DES LIEUX).*\b(SORTIE|DEPART)\b/u', $up)) return 'edl_sortie';
    if (preg_match('/\bQUITTANCE\b/u', $up)) return 'quittance';
    if (preg_match('/\bECHEANCE\b/u', $up)) return 'avis_echeance';
    if (preg_match('/\b(CAUTION|GARANT)\b/u', $up)) return 'caution_garant';
    if (preg_match('/\bFACTURE\b/u', $up)) return 'facture';
    if (preg_match('/\b(RELEVE|EXTRAIT BANCAIRE)\b/u', $up)) return 'releve_bancaire';
    if (preg_match('/\bRIB\b/u', $up)) return 'rib';
    if (preg_match('/\b(ATTESTATION ASSURANCE|MRH)\b/u', $up)) return 'attestation_assurance';
    return '';
}
function bd360_refine_ia_type(array $ext): string {
    $raw = strtolower(trim((string)($ext['type_doc'] ?? '')));
    $label = strtoupper((string)($ext['type_doc_label'] ?? '') . ' ' . ($ext['titre_court'] ?? ''));
    $generic = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
    if (!in_array($raw, $generic, true)) return preg_replace('/[^a-z_]/', '', $raw);
    if (preg_match('/\b(ERNT|ERNMT|ERP|ETAT DES RISQUES|GEORISQUES)\b/u', $label)) return 'erp_ernmt';
    if (preg_match('/\b(DPE|PERFORMANCE ENERGETIQUE)\b/u', $label)) return 'dpe';
    if (preg_match('/\bAMIANTE\b/u', $label)) return 'diagnostic_amiante';
    if (preg_match('/\b(PLOMB|CREP)\b/u', $label)) return 'diagnostic_plomb';
    if (preg_match('/\bELECTRI/u', $label)) return 'diagnostic_elec';
    if (preg_match('/\bGAZ\b/u', $label)) return 'diagnostic_gaz';
    if (preg_match('/\b(TERMITES?|MERULE)\b/u', $label)) return 'diagnostic_termites';
    if (preg_match('/\b(CARREZ|BOUTIN)\b/u', $label)) return 'surface_carrez';
    return $raw;
}
$detectedType = bd360_detect_type_from_filename($fileName);
$ia_type_norm = bd360_refine_ia_type($extraction);
$generic = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
if ($ia_type_norm === '' && $detectedType !== '') { $effectiveType = $detectedType; $typeOrigin = 'nom_fichier'; }
elseif (in_array($ia_type_norm, $generic, true) && $detectedType !== '') { $effectiveType = $detectedType; $typeOrigin = 'nom_fichier (IA générique)'; }
elseif ($ia_type_norm !== '' && $ia_conf >= 50) { $effectiveType = $ia_type_norm; $typeOrigin = 'IA (raffinée)'; }
else { $effectiveType = $detectedType ?: $ia_type_norm; $typeOrigin = $detectedType ? 'nom_fichier' : ($ia_type_norm ? 'IA (faible conf)' : 'aucun'); }

// ─── Mapping GED ──
$gedMappingTransaction = [
    'compromis' => ['02_acte', '01_compromis'],
    'promesse' => ['02_acte', '01_compromis'],
    'acte_authentique' => ['02_acte', '02_acte_authentique'],
    'mandat_exclusif' => ['01_mandat_vente', '01_mandat_exclusif'],
    'mandat_simple' => ['01_mandat_vente', '02_mandat_simple'],
    'mandat_vente' => ['01_mandat_vente', '01_mandat_exclusif'],
    'dpe' => ['03_diagnostics_transaction', '01_dpe'],
    'erp_ernmt' => ['03_diagnostics_transaction', '02_erp_ernmt'],
    'diagnostic_amiante' => ['03_diagnostics_transaction', null],
    'diagnostic_plomb' => ['03_diagnostics_transaction', null],
    'diagnostic_elec' => ['03_diagnostics_transaction', null],
    'diagnostic_gaz' => ['03_diagnostics_transaction', null],
    'diagnostic_termites' => ['03_diagnostics_transaction', null],
    'surface_carrez' => ['03_diagnostics_transaction', null],
    'dossier_acquereur' => ['04_acquereur', '01_dossier_acquereur'],
    'accord_de_pret' => ['04_acquereur', '02_accord_de_pret'],
];
$gedMappingGestion = [
    'mandat_gestion' => ['01_mandat_gestion', '01_mandat_signe'],
    'mandat_simple' => ['01_mandat_gestion', '01_mandat_signe'],
    'bail_signe' => ['02_bail', '01_bail_signe'],
    'edl_entree' => ['02_bail', '02_edl_entree'],
    'edl_sortie' => ['06_sortie_locataire', '02_edl_sortie'],
    'dpe' => ['03_bien', '01_dpe'],
    'erp_ernmt' => ['03_bien', '02_erp_ernmt'],
    'diagnostic_amiante' => ['03_bien', '03_amiante'],
    'diagnostic_plomb' => ['03_bien', '04_plomb_crep'],
    'diagnostic_elec' => ['03_bien', '05_electricite'],
    'diagnostic_gaz' => ['03_bien', '06_gaz'],
    'diagnostic_termites' => ['03_bien', '07_termites'],
    'surface_carrez' => ['03_bien', '08_surface_carrez'],
    'attestation_assurance' => ['03_bien', '09_assurance_proprietaire'],
    'diagnostic' => ['03_bien', null],
    'quittance' => ['03_loyers', '01_quittance'],
    'avis_echeance' => ['03_loyers', '02_avis_echeance'],
    'caution_garant' => ['02_bail', '03_caution_garant'],
    'preavis' => ['06_sortie_locataire', '01_preavis'],
    'attestation_caf' => ['07_caf_apl', '01_attestation_caf'],
];
$gedMapping = ($contexteN1 === '06_transaction') ? $gedMappingTransaction : $gedMappingGestion;
[$proposed_n2, $proposed_n3] = $gedMapping[$effectiveType] ?? [null, null];

function bd360_find_folder(PDO $pdo, ?string $n1, ?string $n2, ?string $n3): ?array {
    if (!$n1) return null;
    $st = $pdo->prepare("SELECT id, name_display FROM ged_folders WHERE slug = ? AND (parent_id IS NULL OR parent_id = 0) AND is_archived = 0 LIMIT 1");
    $st->execute([$n1]);
    $r1 = $st->fetch(PDO::FETCH_ASSOC); if (!$r1) return null;
    if (!$n2) return ['n1' => $r1, 'folder_id' => (int)$r1['id']];
    $st = $pdo->prepare("SELECT id, name_display FROM ged_folders WHERE slug = ? AND parent_id = ? AND is_archived = 0 LIMIT 1");
    $st->execute([$n2, $r1['id']]);
    $r2 = $st->fetch(PDO::FETCH_ASSOC); if (!$r2) return ['n1' => $r1, 'folder_id' => (int)$r1['id']];
    if (!$n3) return ['n1' => $r1, 'n2' => $r2, 'folder_id' => (int)$r2['id']];
    $st = $pdo->prepare("SELECT id, name_display FROM ged_folders WHERE slug = ? AND parent_id = ? AND is_archived = 0 LIMIT 1");
    $st->execute([$n3, $r2['id']]);
    $r3 = $st->fetch(PDO::FETCH_ASSOC);
    return ['n1' => $r1, 'n2' => $r2, 'n3' => $r3, 'folder_id' => $r3 ? (int)$r3['id'] : (int)$r2['id']];
}
$gedPath = bd360_find_folder($pdo, $contexteN1, $proposed_n2, $proposed_n3);

// ─── Tree complet pour cascade JS ──
$gedTree = [];
$rows = $pdo->query("SELECT id, parent_id, slug, name_display FROM ged_folders WHERE is_archived = 0 ORDER BY parent_id, slug")->fetchAll(PDO::FETCH_ASSOC);
$byParent = [];
foreach ($rows as $r) { $byParent[(int)($r['parent_id'] ?? 0)][] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name_display']]; }
foreach ($byParent[0] ?? [] as $n1) {
    $n1Node = ['slug' => $n1['slug'], 'name' => $n1['name'], 'id' => $n1['id'], 'children' => []];
    foreach ($byParent[$n1['id']] ?? [] as $n2) {
        $n2Node = ['slug' => $n2['slug'], 'name' => $n2['name'], 'id' => $n2['id'], 'children' => []];
        foreach ($byParent[$n2['id']] ?? [] as $n3) $n2Node['children'][] = ['slug' => $n3['slug'], 'name' => $n3['name'], 'id' => $n3['id']];
        $n1Node['children'][] = $n2Node;
    }
    $gedTree[] = $n1Node;
}

// ─── Tiers IA alternatif ──
$matchTiers = null;
if ($ia_tiers !== '') {
    try { $matchTiers = em_match_tiers($pdo, ['raison_sociale' => $ia_tiers, 'nom' => $ia_tiers]); } catch (Throwable) {}
}

// ─── Nom V3 preview ──
$nameV3Preview = gdn_v3_build([
    'societe_raison'  => $societeRaison,
    'agence_code'     => $agenceCode,
    'agence_nom'      => $agenceNom,
    'user_matricule'  => $userMatricule,
    'user_username'   => $userUsername,
    'user_id'         => $userId,
    'upload_date'     => 'now',
    'n1_slug'         => $contexteN1,
    'n2_slug'         => $proposed_n2,
    'n3_slug'         => $proposed_n3,
    'n4_slug'         => null,
    'date_doc'        => $ia_date,
    'entity_type'     => 'BIEN',
    'entity_id'       => $bienId,
    'type_doc'        => $effectiveType ?: 'document',
    'source_filename' => $fileName,
]);

// ─── URL PDF ──
$pdfUrl = null;
if (!empty($doc['fichier_chemin'])) {
    $abs = str_replace('\\', '/', realpath((string)$doc['fichier_chemin']) ?: (string)$doc['fichier_chemin']);
    $docRoot = str_replace('\\', '/', realpath(__DIR__) ?: '');
    if ($docRoot && str_starts_with($abs, $docRoot)) {
        $rel = substr($abs, strlen($docRoot));
        if (!str_starts_with($rel, '/')) $rel = '/' . $rel;
        $pdfUrl = '/MaBoxImmo2026/public_html' . $rel;
    } else {
        $pdfUrl = '/MaBoxImmo2026/public_html/uploads/' . basename((string)$doc['fichier_chemin']);
    }
}

// ─── Layout 360° ──
$pageTitle    = 'Document · ' . $fileName;
$pageSubtitle = 'Bien #' . $bienId . ' · Pipeline documentaire';
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<style>
/* ─── Layout spécifique bien_doc_360 : PDF gauche + form droite ─── */
.bd360-wrapper { display: grid; grid-template-columns: 3fr 5fr; gap: 14px; align-items: start; }
@media (max-width: 1100px) { .bd360-wrapper { grid-template-columns: 1fr; } }
.bd360-pdf-pane { background: #fff; border-radius: 10px; padding: 0; box-shadow: 2px 2px 6px #e3dfd8;
    position: sticky; top: 12px; height: calc(100vh - 80px); display: flex; flex-direction: column; }
.bd360-pdf-header { padding: 10px 14px; background: #f4f1ec; border-radius: 10px 10px 0 0;
    font-size: 11px; color: #5a5650; border-bottom: 1px solid #e3dfd8; }
.bd360-pdf-header b { color: #2c2a28; }
.bd360-pdf-frame { flex: 1; border: none; border-radius: 0 0 10px 10px; }
.bd360-pdf-empty { padding: 24px; color: #7a766f; text-align: center; }

.bd360-meta-row { display: flex; flex-wrap: wrap; gap: 6px; font-size: 11px; color: #5a5650; margin: 8px 0; }
.bd360-pill { display: inline-block; padding: 2px 9px; background: #f4f1ec; border-radius: 99px; font-size: 11px; }
.bd360-pill-ok   { background: #d9f0db; color: #2d6a35; }
.bd360-pill-warn { background: #fef3c7; color: #92400e; }
.bd360-pill-info { background: #dbeafe; color: #1e40af; }
.bd360-pill-violet { background: #ede9fe; color: #5b21b6; }

.bd360-field { margin-bottom: 10px; }
.bd360-field label { display: block; font-size: 10.5px; color: #7a766f; text-transform: uppercase;
    letter-spacing: 0.04em; margin-bottom: 4px; font-weight: 600; }
.bd360-field input, .bd360-field select, .bd360-field textarea { width: 100%; padding: 8px 12px;
    background: #fafaf6; border: 1.5px solid #e3dfd8; color: #2c2a28; border-radius: 6px;
    font-family: inherit; font-size: 13px; }
.bd360-field input:focus, .bd360-field select:focus, .bd360-field textarea:focus {
    border-color: #D4A047; outline: none; background: #fff; }
.bd360-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* Mini-cards GED (style adapté charte MBI claire) */
.bd360-cards-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.bd360-cards-empty { color: #9a9690; font-size: 11px; font-style: italic; padding: 8px 4px; }
.bd360-card-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-width: 80px; min-height: 64px; padding: 8px 10px;
    background: #fff; border: 1.5px solid #e3dfd8; border-radius: 8px;
    color: #2c2a28; cursor: pointer; font-family: inherit; font-size: 11px;
    text-align: center; line-height: 1.2; transition: all 0.15s;
    box-shadow: 1px 1px 3px #e3dfd8;
}
.bd360-card-btn:hover { border-color: #D4A047; background: #fefcf5; transform: translateY(-1px); box-shadow: 2px 2px 6px #e3dfd8; }
.bd360-card-btn.is-selected {
    background: #fef3c7; color: #92400e; border-color: #D4A047; font-weight: 700;
    box-shadow: 0 2px 8px rgba(212,160,71,0.3);
}
.bd360-card-btn .bd360-card-icon { font-size: 22px; line-height: 1; margin-bottom: 4px; }
.bd360-card-btn .bd360-card-label { font-size: 10.5px; max-width: 100px; word-break: break-word; }
.bd360-card-btn .bd360-card-code  { font-size: 9px; color: #9a9690; margin-top: 2px; }
.bd360-card-btn.is-selected .bd360-card-code { color: #92400e; }
.bd360-level-title { font-size: 10.5px; color: #7a766f; text-transform: uppercase;
    letter-spacing: 0.04em; margin: 10px 0 6px; font-weight: 600; }

.bd360-naming { background: #fafaf6; padding: 12px 16px; border-radius: 8px; border: 1px dashed #7c3aed;
    font-family: 'DM Mono', 'Courier New', monospace; font-size: 12.5px; color: #2c2a28; word-break: break-all; }

.bd360-actions { display: flex; gap: 10px; justify-content: space-between; align-items: center;
    background: #fff; padding: 14px 16px; border-radius: 10px; box-shadow: 2px 2px 6px #e3dfd8;
    position: sticky; bottom: 12px; z-index: 10; }

/* ─── Rattachements compacts (details/summary) ─── */
.bd360-attach-group { background: #fff; border-radius: 10px; padding: 8px 14px; margin-bottom: 12px; box-shadow: 2px 2px 6px #e3dfd8; }
.bd360-attach-group h3 { margin: 4px 0 8px; font-size: 13px; color: #2c2a28; display: flex; align-items: center; gap: 8px; }
.bd360-attach-group h3 .count { background: #f4f1ec; color: #5a5650; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 99px; margin-left: auto; }
.bd360-attach-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f4f1ec; font-size: 12.5px; }
.bd360-attach-row:last-child { border-bottom: none; }
.bd360-attach-row .ico { width: 28px; text-align: center; font-size: 18px; }
.bd360-attach-row .lbl { color: #5a5650; min-width: 90px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; }
.bd360-attach-row .val { flex: 1; color: #2c2a28; }
.bd360-attach-row .val small { color: #9a9690; }
.bd360-attach-row .edit-btn { background: transparent; border: 1px solid #e3dfd8; color: #5a5650;
    font-size: 11px; padding: 3px 9px; border-radius: 99px; cursor: pointer; font-family: inherit; transition: all 0.15s; }
.bd360-attach-row .edit-btn:hover { background: #fef3c7; border-color: #D4A047; color: #92400e; }
.bd360-attach-row .edit-btn.is-open { background: #fef3c7; border-color: #D4A047; color: #92400e; }
.bd360-attach-edit { display: none; padding: 8px 0 4px 38px; }
.bd360-attach-edit.is-open { display: block; }
.bd360-attach-edit .bd360-field { margin-bottom: 6px; }
.bd360-info-box { background: #eef4fb; color: #1e40af; padding: 10px 14px; border-radius: 6px;
    font-size: 12px; margin-bottom: 12px; border-left: 3px solid #60a5fa; }
.bd360-warn-box { background: #fef3c7; color: #92400e; padding: 10px 14px; border-radius: 6px;
    font-size: 12px; margin-bottom: 12px; border-left: 3px solid #f59e0b; }

.tr-btn-info { background: #60a5fa; color: #fff; }
.tr-btn-info:hover { background: #3b82f6; }
</style>

<?php
// ─── Breadcrumb ──
$chaine = [];
if ($proprioTiersId > 0) $chaine[] = ['icon' => '👤', 'label' => $proprioLabel ?: ('Tiers #'.$proprioTiersId), 'url' => app_url('/tiers_360.php?id='.$proprioTiersId)];
if (!empty($bien['id_immeuble'])) $chaine[] = ['icon' => '🏢', 'label' => $bien['imm_nom'] ?: ($bien['imm_adresse'] ?: 'Immeuble'), 'url' => app_url('/immeuble_360.php?id='.(int)$bien['id_immeuble'])];
$chaine[] = ['icon' => '🏠', 'label' => 'Bien #' . $bienId, 'url' => app_url('/bien_360.php?id=' . $bienId)];
$chaine[] = ['icon' => '📄', 'label' => 'Ce document', 'url' => null];
fiche360_breadcrumb($chaine, 'Patrimoine');

// ─── Header ──
$badgeN1 = $contexteN1 === '06_transaction'
    ? ['label' => 'TRANSACTION', 'class' => 'vendu']
    : ['label' => 'GESTION LOCATIVE', 'class' => 'loue'];

fiche360_header(
    '📄',
    $fileName,
    $badgeN1,
    'Pipeline documentaire · ' . round((int)$doc['taille_octets']/1024) . ' Ko · ' . ($doc['mime_type'] ?? '?'),
    [
        ['icon' => '🔑',  'text' => 'hash ' . substr($hash, 0, 12) . '…'],
        ['icon' => '🏢',  'text' => 'société #' . $resolvedSocieteId . ' ' . $societeRaison],
        ['icon' => '🏬',  'text' => 'agence #' . $resolvedAgenceId . ' ' . ($agenceCode ?: '?')],
    ],
    [
        ['label' => '← Retour bien', 'url' => app_url('/bien_360.php?id=' . $bienId), 'class' => 'tr-btn'],
        ['label' => '📁 Documents', 'url' => app_url('/bien_documents_list.php?id=' . $bienId), 'class' => 'tr-btn'],
    ]
);
?>

<div class="bd360-wrapper">

    <!-- ─── COLONNE GAUCHE : PDF ─── -->
    <div class="bd360-pdf-pane">
        <div class="bd360-pdf-header">
            📄 <b><?= bd360_html($fileName) ?></b>
            · <?= bd360_html((string)($doc['mime_type'] ?? '?')) ?>
            · <?= number_format((int)$doc['taille_octets']/1024) ?> Ko
        </div>
        <?php if ($pdfUrl): ?>
            <iframe class="bd360-pdf-frame" src="<?= bd360_html($pdfUrl) ?>#zoom=page-fit" title="PDF"></iframe>
        <?php else: ?>
            <div class="bd360-pdf-empty">
                📄 URL PDF non résolue.<br>
                <code style="font-size:10px;"><?= bd360_html((string)$doc['fichier_chemin']) ?></code>
            </div>
        <?php endif; ?>
    </div>

    <!-- ─── COLONNE DROITE : FORM ─── -->
    <div>

        <form method="POST" action="api/transaction_upload_commit.php" id="bd360Form">
            <input type="hidden" name="doc_id" value="<?= (int)$docId ?>">
            <input type="hidden" name="bien_id" value="<?= (int)$bienId ?>">
            <input type="hidden" name="card_id" value="<?= (int)$cardId ?>">
            <input type="hidden" name="source" value="<?= bd360_html($source) ?>">
            <input type="hidden" name="ctx_type" value="<?= bd360_html($ctxType) ?>">
            <input type="hidden" name="csrf_token" value="<?= bd360_html($_SESSION['csrf_token'] ?? '') ?>">

            <!-- Card 0 : Contexte métier -->
            <div class="f360-card">
                <h3>🧭 Contexte métier détecté</h3>
                <div class="bd360-meta-row">
                    <span class="bd360-pill bd360-pill-info">N1 = <?= bd360_html($contexteN1) ?></span>
                    <span class="bd360-pill bd360-pill-ok"><?= bd360_html($contexteRaison) ?></span>
                </div>
                <?php if ($bailActif): ?>
                    <div style="font-size:12px;color:#5a5650;">
                        🔑 Bail <b>#<?= (int)$bailActif['id'] ?></b> actif · locataire <b><?= bd360_html((string)$bailActif['locataire_nom']) ?></b> · loyer <?= bd360_html((string)$bailActif['loyer']) ?>€
                    </div>
                <?php endif; ?>
                <?php if ($mandatActif): ?>
                    <div style="font-size:12px;color:#5a5650;">
                        📋 Mandat <b>#<?= (int)$mandatActif['id'] ?></b> · type <b><?= bd360_html((string)$mandatActif['type_mandat']) ?></b>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card 1 : Extraction IA -->
            <div class="f360-card">
                <h3>📊 Extraction IA <?php if ($ia_conf > 0): ?><span class="count">conf. <?= $ia_conf ?>%</span><?php endif; ?></h3>
                <?php if (!$cacheRow): ?>
                    <div class="bd360-warn-box">
                        ⚠️ Pas d'extraction IA en cache.
                        <?php if ($detectedType): ?>
                            Type déduit du nom de fichier : <b><?= bd360_html($detectedType) ?></b>.
                        <?php endif; ?>
                    </div>
                    <a class="tr-btn tr-btn-info" href="?<?= bd360_html(http_build_query(array_merge($_GET, ['extract_ia' => 1]))) ?>"
                       onclick="this.innerText='⏳ Extraction en cours… (5-10s)';">
                        🤖 Extraire IA maintenant (Sonnet, ~5-10s)
                    </a>
                <?php else: ?>
                    <div class="bd360-info-box">
                        ✅ <b><?= bd360_html((string)$cacheRow['model']) ?></b> · <?= bd360_html((string)$cacheRow['last_at']) ?>
                    </div>
                    <?php if ($ia_label !== ''): ?>
                        <div style="font-size:12px;margin-bottom:8px;"><b>Label IA :</b> <?= bd360_html($ia_label) ?></div>
                    <?php endif; ?>
                    <?php if ($ia_adresse !== ''): ?>
                        <div style="font-size:12px;color:#5a5650;">📍 <b>Adresse extraite :</b> <?= bd360_html($ia_adresse) ?></div>
                    <?php endif; ?>
                    <?php if ($ia_notes !== ''): ?>
                        <div style="font-size:11.5px;color:#7a766f;margin-top:6px;">📝 <?= bd360_html($ia_notes) ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="bd360-field-row" style="margin-top:12px;">
                    <div class="bd360-field">
                        <label>Type de document · <span class="bd360-pill bd360-pill-violet"><?= bd360_html($typeOrigin) ?></span></label>
                        <select name="type_doc">
                            <?php
                            $types = ['', 'compromis','acte_authentique','promesse',
                                'mandat_exclusif','mandat_simple','mandat_vente','mandat_gestion',
                                'dpe','erp_ernmt',
                                'diagnostic_amiante','diagnostic_plomb','diagnostic_elec','diagnostic_gaz','diagnostic_termites',
                                'surface_carrez', 'dossier_acquereur','accord_de_pret','decompte_vendeur',
                                'bail_signe','avenant_mandat','edl_entree','edl_sortie',
                                'caution_garant','quittance','avis_echeance','preavis',
                                'attestation_caf','facture','releve_bancaire','rib','attestation_assurance','autre'];
                            foreach ($types as $t):
                                $sel = ($t === $effectiveType) ? 'selected' : '';
                            ?>
                                <option value="<?= bd360_html($t) ?>" <?= $sel ?>><?= bd360_html($t === '' ? '— choisir —' : $t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bd360-field">
                        <label>Date document</label>
                        <input type="date" name="date_doc" value="<?= bd360_html($ia_date) ?>">
                    </div>
                </div>
                <div class="bd360-field-row">
                    <div class="bd360-field">
                        <label>Montant (€)</label>
                        <input type="text" name="montant" value="<?= bd360_html($ia_montant) ?>" placeholder="ex. 285000">
                    </div>
                    <div class="bd360-field">
                        <label>Référence</label>
                        <input type="text" name="reference" value="<?= bd360_html($ia_ref) ?>" placeholder="N° doc, contrat…">
                    </div>
                </div>
            </div>

            <!-- Cards 2-5 fusionnées : 🔗 Rattachements compacts (cf breadcrumb) -->
            <div class="bd360-attach-group">
                <h3>🔗 Rattachements <span class="count">cf. chaîne Patrimoine ↑ — cliquer ✏ pour corriger</span></h3>

                <!-- BIEN -->
                <div class="bd360-attach-row">
                    <span class="ico">🏠</span>
                    <span class="lbl">Bien</span>
                    <span class="val">
                        <b>#<?= (int)$bien['id'] ?></b>
                        <?= bd360_html((string)($bien['adresse_1'] ?: $bien['imm_adresse'] ?: '?')) ?>
                        <?php if (!empty($bien['code_postal'])): ?>· <?= bd360_html((string)$bien['code_postal']) ?> <?= bd360_html((string)($bien['ville'] ?? '')) ?><?php endif; ?>
                        <small> · soc #<?= $resolvedSocieteId ?> <?= bd360_html($societeRaison) ?> · ag #<?= $resolvedAgenceId ?> <?= bd360_html($agenceCode ?: '?') ?></small>
                    </span>
                    <button type="button" class="edit-btn" data-target="bd360EditBien">✏ modifier</button>
                </div>
                <div class="bd360-attach-edit" id="bd360EditBien">
                    <div class="bd360-field">
                        <label>ID bien lié (link_bien_id)</label>
                        <input type="number" name="link_bien_id" value="<?= (int)$bien['id'] ?>" min="1">
                    </div>
                </div>
                <input type="hidden" name="link_bien_relation" value="main">

                <!-- IMMEUBLE -->
                <div class="bd360-attach-row">
                    <span class="ico">🏢</span>
                    <span class="lbl">Immeuble</span>
                    <span class="val">
                        <?php if (!empty($bien['id_immeuble'])): ?>
                            <b>#<?= (int)$bien['id_immeuble'] ?></b> · <?= bd360_html((string)$bien['imm_adresse']) ?>
                            <small> · auto via biens.id_immeuble</small>
                        <?php else: ?>
                            <small style="color:#92400e;">⚠ aucun immeuble lié</small>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="edit-btn" data-target="bd360EditImm">✏ modifier</button>
                </div>
                <div class="bd360-attach-edit" id="bd360EditImm">
                    <div class="bd360-field">
                        <label>ID immeuble lié (0 = détacher)</label>
                        <input type="number" name="link_immeuble_id" value="<?= (int)($bien['id_immeuble'] ?? 0) ?>" min="0">
                    </div>
                </div>
                <input type="hidden" name="link_immeuble_relation" value="reference">

                <!-- PROPRIO / TIERS -->
                <div class="bd360-attach-row">
                    <span class="ico">👤</span>
                    <span class="lbl">Propriétaire</span>
                    <span class="val">
                        <?php if ($proprietaire || $proprioTiers): ?>
                            <?php if ($proprietaire): ?><b>#<?= (int)$proprietaire['id'] ?></b> · <?= bd360_html($proprioLabel) ?><?php endif; ?>
                            <?php if ($proprioTiers): ?><small> · tiers #<?= (int)$proprioTiers['id'] ?> <?= bd360_html((string)($proprioTiers['raison_sociale'] ?: trim(($proprioTiers['prenom']??'').' '.($proprioTiers['nom']??'')))) ?></small><?php endif; ?>
                        <?php else: ?>
                            <small style="color:#92400e;">⚠ aucun propriétaire/tiers</small>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="edit-btn" data-target="bd360EditTiers">✏ modifier</button>
                </div>
                <div class="bd360-attach-edit" id="bd360EditTiers">
                    <div class="bd360-field">
                        <label>ID tiers lié au document (0 = détacher)</label>
                        <input type="number" name="link_tiers_id" value="<?= (int)$proprioTiersId ?>" min="0">
                    </div>
                    <?php if ($matchTiers && !empty($matchTiers['matches'])): ?>
                        <div style="font-size:11px;color:#5a5650;margin-top:4px;">
                            🔍 IA a aussi détecté "<?= bd360_html($ia_tiers) ?>" — autres candidats :
                            <?php foreach (array_slice($matchTiers['matches'], 0, 3) as $m):
                                $nm = $m['raison_sociale'] ?: trim(($m['nom'] ?? '').' '.($m['prenom'] ?? ''));
                            ?>
                                #<?= (int)$m['id'] ?> <?= bd360_html((string)$nm) ?> ·
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="link_tiers_relation" value="reference">

                <!-- MANDAT -->
                <div class="bd360-attach-row">
                    <span class="ico">📋</span>
                    <span class="lbl">Mandat</span>
                    <span class="val">
                        <?php if ($mandatActif): ?>
                            <b>#<?= (int)$mandatActif['id'] ?></b> · type <?= bd360_html((string)$mandatActif['type_mandat']) ?>
                            <small> · <?= bd360_html((string)$mandatActif['statut']) ?></small>
                        <?php else: ?>
                            <small>aucun mandat actif</small>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="edit-btn" data-target="bd360EditMandat">✏ modifier</button>
                </div>
                <div class="bd360-attach-edit" id="bd360EditMandat">
                    <div class="bd360-field">
                        <label>ID mandat lié (0 = aucun)</label>
                        <input type="number" name="link_mandat_id" value="<?= (int)($mandatActif['id'] ?? 0) ?>" min="0">
                    </div>
                </div>
                <input type="hidden" name="link_mandat_relation" value="annexe">

                <!-- BAIL -->
                <div class="bd360-attach-row">
                    <span class="ico">🔑</span>
                    <span class="lbl">Bail</span>
                    <span class="val">
                        <?php if ($bailActif): ?>
                            <b>#<?= (int)$bailActif['id'] ?></b> · locataire <?= bd360_html((string)$bailActif['locataire_nom']) ?>
                            <small> · loyer <?= bd360_html((string)$bailActif['loyer']) ?>€</small>
                        <?php else: ?>
                            <small>aucun bail actif</small>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="edit-btn" data-target="bd360EditBail">✏ modifier</button>
                </div>
                <div class="bd360-attach-edit" id="bd360EditBail">
                    <div class="bd360-field">
                        <label>ID bail lié (0 = aucun)</label>
                        <input type="number" name="link_bail_id" value="<?= (int)($bailActif['id'] ?? 0) ?>" min="0">
                    </div>
                </div>
                <input type="hidden" name="link_bail_relation" value="annexe">
            </div>

            <!-- Card 6 : Classement GED (3 niveaux du modal — ged_cascade async) -->
            <div class="f360-card">
                <h3>📁 Classement GED <span class="count">référentiel modal · cliquer pour choisir</span></h3>
                <div style="font-size:11.5px;color:#7a766f;margin-bottom:8px;">
                    Niveaux 1-2-3 du référentiel <code>ged_level_codes</code> (cohérent avec le modal FluxBox).
                    Pré-sélection auto selon contexte : <code>N1=<?= bd360_html($contexteN1) ?></code>, type=<code><?= bd360_html($effectiveType ?: '—') ?></code>.
                </div>

                <div class="bd360-level-title">🧭 N1 — Métier</div>
                <div class="bd360-cards-row" id="bd360N1Row"><div class="bd360-cards-empty">⏳ Chargement…</div></div>

                <div class="bd360-level-title">📂 N2 — Domaine</div>
                <div class="bd360-cards-row" id="bd360N2Row"><div class="bd360-cards-empty">— choisir un N1 d'abord —</div></div>

                <div class="bd360-level-title">📑 N3 — Sous-domaine (optionnel)</div>
                <div class="bd360-cards-row" id="bd360N3Row"><div class="bd360-cards-empty">— choisir un N2 d'abord —</div></div>

                <div id="bd360FolderResolved" class="bd360-info-box" style="display:none;margin-top:10px;">
                    ✅ Classement : <span id="bd360FolderPathLabel"></span>
                </div>

                <!-- Codes ged_level_codes (envoyés au commit + naming V3.1) -->
                <input type="hidden" name="ged_n1_code" id="bd360N1Hidden" value="">
                <input type="hidden" name="ged_n2_code" id="bd360N2Hidden" value="">
                <input type="hidden" name="ged_n3_code" id="bd360N3Hidden" value="">
                <!-- Slugs ged_folders pré-calculés (rétrocompat commit) -->
                <input type="hidden" name="ged_n1_slug" value="<?= bd360_html($contexteN1) ?>">
                <input type="hidden" name="ged_n2_slug" value="<?= bd360_html((string)$proposed_n2) ?>">
                <input type="hidden" name="ged_n3_slug" value="<?= bd360_html((string)$proposed_n3) ?>">
                <input type="hidden" name="ged_folder_id" id="bd360FolderIdHidden" value="<?= (int)($gedPath['folder_id'] ?? 0) ?>">
            </div>

            <!-- Card 7 : Commentaire user -->
            <div class="f360-card">
                <h3>💬 Commentaire utilisateur (optionnel)</h3>
                <div class="bd360-field">
                    <textarea name="user_comment" rows="2" placeholder="Note libre : raison du classement, contexte, anomalie observée…"></textarea>
                </div>
            </div>

            <!-- Card 8 : Nom V3 preview -->
            <div class="f360-card">
                <h3>🏷️ Nom de fichier V3 (preview dynamique)</h3>
                <div class="bd360-naming" id="bd360NamePreview"><?= bd360_html($nameV3Preview) ?></div>
                <div style="font-size:11px;color:#7a766f;margin-top:6px;" id="bd360NameMeta">
                    Format FluxBox V3 · longueur <?= strlen($nameV3Preview) ?> chars
                </div>
                <input type="hidden" name="name_v3" id="bd360NameHidden" value="<?= bd360_html($nameV3Preview) ?>">
            </div>

            <!-- Card 9 : Hooks métier -->
            <div class="f360-card">
                <h3>⚙️ Hooks métier (opt-in)</h3>
                <div style="font-size:11.5px;color:#7a766f;margin-bottom:6px;">
                    Si coché, les hooks mettront à jour <b>mandats.date_signature</b>, <b>biens.dpe_classe</b> etc. (uniquement si conf. ≥ 90 %).
                </div>
                <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="apply_metier_hooks" value="1" style="width:auto;">
                    Appliquer les hooks métier au commit (décoché par défaut — safety)
                </label>
            </div>

            <!-- Actions sticky -->
            <div class="bd360-actions">
                <a href="<?= bd360_html(app_url('/bien_360.php?id='.$bienId)) ?>" class="tr-btn">↩ Annuler</a>
                <button type="button" id="bd360BtnRecalc" class="tr-btn tr-btn-info">🔄 Recalculer le nom</button>
                <button type="submit" name="action" value="commit" class="tr-btn tr-btn-primary"
                        onclick="return confirm('💾 VALIDER & PERSISTER en BDD ?\n\nCela créera 1 ged_documents + N ged_document_links.');">
                    ✅ Valider tout (commit atomique)
                </button>
            </div>
        </form>

    </div>
</div>

<script>
(function() {
    'use strict';

    // ─── Cascade GED 3 niveaux via API ged_cascade (référentiel modal FluxBox) ──
    const API_GED_CASCADE = <?= json_encode(rtrim(app_url('/'), '/') . '/api/fluxbox_action.php', JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode((string)($_SESSION['csrf_token'] ?? '')) ?>;

    // Icônes par code N1 (aligné avec METIER_DEF du modal)
    const N1_ICONS = {
        '01_AGENCE':'🏬','02_RH':'👥','06_COMPTABILITE':'💰','01_DIRECTION':'⚙️',
        '07_JURIDIQUE_CONTENTIEUX':'⚖️','08_MARKETING_COMMUNICATION':'📣',
        '09_MODELES_DOCUMENTS':'📄','10_REFERENTIEL':'📚','12_ARCHIVES':'📦',
        '99_SYSTEME':'🛠️','11_MAILS_COMMUNICATIONS':'📧',
        '03_GESTION_LOCATIVE':'🏠','04_SYNDIC':'🏢','05_TRANSACTION':'🤝',
        '13_FOURNISSEURS':'🚚',
    };
    function n2Icon(c) { const l=(c||'').toLowerCase(); if(l.includes('mandat'))return'📋'; if(l.includes('bail'))return'🔑'; if(l.includes('bien'))return'🏠'; if(l.includes('loyer'))return'💶'; if(l.includes('acte'))return'✍️'; if(l.includes('diag'))return'🩺'; if(l.includes('assur'))return'🛡️'; if(l.includes('sinistr'))return'⚠️'; if(l.includes('sortie'))return'🚪'; if(l.includes('caf'))return'🏛️'; if(l.includes('proprio'))return'👤'; if(l.includes('fiscal'))return'💸'; if(l.includes('acquereur'))return'🤝'; if(l.includes('banque'))return'🏦'; return'📁'; }
    function n3Icon(c) { const l=(c||'').toLowerCase(); if(l.includes('dpe'))return'⚡'; if(l.includes('erp')||l.includes('ernmt'))return'🌍'; if(l.includes('amiante'))return'🧪'; if(l.includes('plomb'))return'🧱'; if(l.includes('elec'))return'💡'; if(l.includes('gaz'))return'🔥'; if(l.includes('termit'))return'🐛'; if(l.includes('carrez'))return'📐'; if(l.includes('assur'))return'🛡️'; if(l.includes('bail'))return'🔑'; if(l.includes('mandat'))return'📋'; if(l.includes('quittance'))return'🧾'; if(l.includes('caution'))return'🤝'; if(l.includes('edl'))return'📸'; return'📑'; }

    const n1Row = document.getElementById('bd360N1Row');
    const n2Row = document.getElementById('bd360N2Row');
    const n3Row = document.getElementById('bd360N3Row');
    const n1H = document.getElementById('bd360N1Hidden');
    const n2H = document.getElementById('bd360N2Hidden');
    const n3H = document.getElementById('bd360N3Hidden');
    const folderResolv = document.getElementById('bd360FolderResolved');
    const folderPathLbl = document.getElementById('bd360FolderPathLabel');

    // Pré-sélection contexte → mapping slug ged_folders → code ged_level_codes
    // (slug N1 ged_folders = '05_gestion_locative', code N1 ged_level_codes = '03_GESTION_LOCATIVE')
    const N1_SLUG_TO_CODE = {
        '05_gestion_locative': '03_GESTION_LOCATIVE',
        '06_transaction':      '05_TRANSACTION',
        '04_syndic':           '04_SYNDIC',
        '07_comptabilite':     '06_COMPTABILITE',
        '13_fournisseurs':     '13_FOURNISSEURS',
        '03_rh':               '02_RH',
        '01_direction':        '01_DIRECTION',
    };
    const INITIAL_N1_CODE = N1_SLUG_TO_CODE[<?= json_encode($contexteN1) ?>] || '';

    // Labels par code (cache local pour reconstituer le chemin)
    const labelByCode = {};

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    async function gedCascadeFetch(level, parents) {
        const body = { action: 'ged_cascade', level, csrf: CSRF_TOKEN, ...parents };
        const res = await fetch(API_GED_CASCADE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const j = await res.json();
        return (j.ok && j.data && j.data.items) ? j.data.items : [];
    }

    function renderGrid(container, items, hiddenInput, selectedCode, iconFn, onChange, emptyMsg) {
        container.innerHTML = '';
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="bd360-cards-empty">' + esc(emptyMsg || '— aucun élément —') + '</div>';
            return;
        }
        // Fix P2 (2026-05-26) : data-level pour distinguer les niveaux (N1/N2/N3) au comptage QA
        const level = container.dataset.metaLevel || '?';
        items.forEach(it => {
            labelByCode[it.code] = it.label || it.code;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bd360-card-btn bd360-card-btn-level-' + level + (it.code === selectedCode ? ' is-selected' : '');
            btn.dataset.code = it.code;
            btn.dataset.level = level;
            const labelClean = (it.label || it.code).replace(/^\d+[a-z]?\s*-\s*/, '');
            btn.innerHTML = `<span class="bd360-card-icon">${iconFn(it.code)}</span><span class="bd360-card-label">${esc(labelClean)}</span><span class="bd360-card-code">${esc(it.code)}</span>`;
            btn.addEventListener('click', () => {
                container.querySelectorAll('.bd360-card-btn').forEach(b => b.classList.remove('is-selected'));
                btn.classList.add('is-selected');
                hiddenInput.value = it.code;
                onChange();
            });
            container.appendChild(btn);
        });
        if (selectedCode) hiddenInput.value = selectedCode;
    }

    function refreshFolderLabel() {
        const path = [
            n1H.value ? (labelByCode[n1H.value] || n1H.value) : null,
            n2H.value ? (labelByCode[n2H.value] || n2H.value) : null,
            n3H.value ? (labelByCode[n3H.value] || n3H.value) : null,
        ].filter(Boolean).join(' › ');
        if (path) {
            folderResolv.style.display = '';
            folderPathLbl.textContent = path;
        } else folderResolv.style.display = 'none';
    }

    async function onN1Click() {
        n2H.value = ''; n3H.value = '';
        const items = await gedCascadeFetch(2, { n1: n1H.value });
        renderGrid(n2Row, items, n2H, '', n2Icon, onN2Click, 'Aucun domaine seedé pour ce métier');
        n3Row.innerHTML = '<div class="bd360-cards-empty">— choisir un N2 d\'abord —</div>';
        refreshFolderLabel();
        recomputeName();
    }
    async function onN2Click() {
        n3H.value = '';
        const items = await gedCascadeFetch(3, { n1: n1H.value, n2: n2H.value });
        renderGrid(n3Row, items, n3H, '', n3Icon, onN3Click, 'Aucun sous-domaine seedé');
        refreshFolderLabel();
        recomputeName();
    }
    function onN3Click() {
        refreshFolderLabel();
        recomputeName();
    }

    // ─── Init cascade : load N1 puis pré-sélectionner depuis contexte ──
    (async function init() {
        const n1Items = await gedCascadeFetch(1, {});
        renderGrid(n1Row, n1Items, n1H, INITIAL_N1_CODE, (c) => N1_ICONS[c] || '📁', onN1Click, 'Aucun métier seedé');
        if (INITIAL_N1_CODE) {
            n1H.value = INITIAL_N1_CODE;
            await onN1Click();
        }
        refreshFolderLabel();
    })();

    // Recompute name V3
    const preview = document.getElementById('bd360NamePreview');
    const meta    = document.getElementById('bd360NameMeta');
    const hidden  = document.getElementById('bd360NameHidden');
    const typeSel = document.querySelector('select[name="type_doc"]');
    const dateInp = document.querySelector('input[name="date_doc"]');
    let timer = null;
    function recomputeName(immediate) {
        clearTimeout(timer);
        const fn = async () => {
            const params = new URLSearchParams({
                bien_id: '<?= (int)$bienId ?>', doc_id: '<?= (int)$docId ?>',
                type_doc: typeSel ? typeSel.value : '',
                date_doc: dateInp ? dateInp.value : '',
                n1_slug: n1H.value, n2_slug: n2H.value, n3_slug: n3H.value,
                entity_type: 'BIEN', entity_id: '<?= (int)$bienId ?>',
            });
            preview.style.opacity = '0.5';
            try {
                const r = await fetch('api/ged_doc_naming_preview.php?' + params, {credentials:'same-origin'});
                const j = await r.json();
                if (j.error) throw new Error(j.error);
                preview.textContent = j.name_v3;
                hidden.value = j.name_v3;
                meta.innerHTML = 'Format FluxBox V3 · longueur ' + j.name_v3.length + ' chars · <span style="color:#2d6a35;">à jour</span>';
            } catch (e) {
                meta.innerHTML = 'Erreur : ' + esc(e.message);
            } finally { preview.style.opacity = '1'; }
        };
        if (immediate) fn(); else timer = setTimeout(fn, 250);
    }
    if (typeSel) typeSel.addEventListener('change', () => recomputeName());
    if (dateInp) dateInp.addEventListener('change', () => recomputeName());
    document.getElementById('bd360BtnRecalc')?.addEventListener('click', () => recomputeName(true));

    // ─── Toggle des éditeurs de rattachement (Bien/Immeuble/Tiers/Mandat/Bail) ──
    document.querySelectorAll('.bd360-attach-row .edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const target = document.getElementById(targetId);
            if (!target) return;
            const isOpen = target.classList.toggle('is-open');
            btn.classList.toggle('is-open', isOpen);
            btn.textContent = isOpen ? '✕ fermer' : '✏ modifier';
        });
    });
})();
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
