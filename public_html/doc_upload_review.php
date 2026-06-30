<?php
/**
 * doc_upload_review.php
 *
 * SPRINT 6 PHASE A1 — Page review unifiée du pipeline documentaire.
 *
 * REFACTOR MÉTIER 2026-05-24 (post-STOP bug ERNT bien #733) :
 *  - Préfetch contexte métier réel (mandat, bail, annonce, proprio, immeuble)
 *  - Détection N1 contextuelle :
 *      bail actif OU mandat de gestion          → 05_gestion_locative
 *      mandat de vente OU annonce vente seule   → 06_transaction
 *      sinon (défaut bien sans signal)          → 05_gestion_locative
 *  - Hydratation id_societe/id_agence en cascade (bien → immeuble → session)
 *  - Détection type_doc par mots-clés du nom de fichier (ERNT/ERP/DPE/...)
 *    AVANT extraction IA
 *  - Préremplissage propriétaire depuis bien.id_proprietaire / bien.id_tiers
 *    (l'IA n'écrase JAMAIS un proprio déjà lié au bien)
 *  - Bouton "Extraire IA maintenant" (sync, optionnel — 5-10s)
 *
 * Entrées (paramètre ?source=) :
 *   - transaction (défaut historique) : depuis bien_360 / bien_documents_list
 *   - fluxbox                          : depuis fluxbox / modal upload
 *   - direct                           : test / accès direct admin
 *
 * Paramètres GET :
 *   - source       : transaction | fluxbox | direct (défaut: direct)
 *   - bien_id      : ID du bien (requis pour ctx_type=BIEN)
 *   - doc_id       : ID fluxbox_documents
 *   - card_id      : ID fluxbox_cartes (optionnel)
 *   - ctx_type     : BIEN | IMB | TIERS (défaut: BIEN — V1)
 *   - extract_ia   : 1 → déclenche l'extraction IA puis redirige
 *
 * Sécurité : super admin OU agent agence du bien (vérif scope id_societe).
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/feature_flags.php';
require_once __DIR__ . '/inc/entity_matcher.php';
require_once __DIR__ . '/inc/ged_doc_naming_v3.php';
require_login();

$pdo = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['id_user'] ?? 0);
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1);

// ─── Paramètres ─────────────────────────────────────────────────────
$source  = (string)($_GET['source']   ?? 'direct');
$ctxType = strtoupper((string)($_GET['ctx_type'] ?? 'BIEN'));
$bienId  = (int)($_GET['bien_id']  ?? $_GET['ctx_id'] ?? 0);
$docId   = (int)($_GET['doc_id']   ?? 0);
$cardId  = (int)($_GET['card_id']  ?? 0);
$doExtractIa = (int)($_GET['extract_ia'] ?? 0) === 1;

if (!in_array($source, ['transaction', 'fluxbox', 'direct'], true)) $source = 'direct';
if (!in_array($ctxType, ['BIEN', 'IMB', 'TIERS'], true))            $ctxType = 'BIEN';

if ($bienId <= 0 && $docId <= 0) {
    http_response_code(400);
    exit('Paramètre manquant : bien_id (ou ctx_id) ou doc_id requis.');
}

function dur_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ═════════════════════════════════════════════════════════════════════
// PRÉFETCH MÉTIER — Lecture BDD du contexte AVANT toute logique IA
// ═════════════════════════════════════════════════════════════════════

// ─── Bien ───────────────────────────────────────────────────────────
$bien = null;
if ($bienId > 0) {
    $st = $pdo->prepare("SELECT b.*, i.adresse_1 AS imm_adresse, i.code_postal AS imm_cp,
                                i.ville AS imm_ville, i.google_place_id AS imm_place_id,
                                i.latitude AS imm_lat, i.longitude AS imm_lng,
                                i.nom_immeuble AS imm_nom, i.id_societe AS imm_societe,
                                i.id_agence AS imm_agence
                         FROM biens b
                         LEFT JOIN immeubles i ON i.id = b.id_immeuble
                         WHERE b.id = ?");
    $st->execute([$bienId]);
    $bien = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$bien) {
    http_response_code(404);
    exit("Bien #$bienId introuvable.");
}

// ─── Document ───────────────────────────────────────────────────────
$doc = null;
if ($docId > 0) {
    $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
} elseif ($cardId > 0) {
    $st = $pdo->prepare("SELECT d.* FROM fluxbox_cartes c
                         JOIN fluxbox_documents d ON d.id = c.document_id
                         WHERE c.id = ?");
    $st->execute([$cardId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$doc) {
    http_response_code(404);
    exit('Document introuvable.');
}
$docId = (int)$doc['id'];
$fileName = (string)$doc['fichier_nom'];

// ─── Propriétaire (préfetch BDD — PRIMORDIAL, pas dépendant de l'IA) ───
$proprietaire = null;
if (!empty($bien['id_proprietaire'])) {
    try {
        $st = $pdo->prepare("SELECT id, id_tiers, type_personne, civilite, nom, prenom, societe, email, telephone
                             FROM proprietaires WHERE id = ?");
        $st->execute([(int)$bien['id_proprietaire']]);
        $proprietaire = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}
$proprioTiers = null;
$proprioTiersId = (int)($bien['id_tiers'] ?? ($proprietaire['id_tiers'] ?? 0));
if ($proprioTiersId > 0) {
    try {
        $st = $pdo->prepare("SELECT id, id_societe, id_agence, type_tiers, civilite, nom, prenom, raison_sociale, email, telephone
                             FROM tiers WHERE id = ?");
        $st->execute([$proprioTiersId]);
        $proprioTiers = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}
$proprioLabel = '';
if ($proprietaire) {
    $proprioLabel = trim(($proprietaire['societe'] ?? '') ?: (($proprietaire['prenom'] ?? '') . ' ' . ($proprietaire['nom'] ?? '')));
} elseif ($proprioTiers) {
    $proprioLabel = trim(($proprioTiers['raison_sociale'] ?? '') ?: (($proprioTiers['prenom'] ?? '') . ' ' . ($proprioTiers['nom'] ?? '')));
}

// ─── Mandat (tous statuts — utile pour détecter origine) ─────────────
$mandat = null;
$mandatActif = null;
try {
    $st = $pdo->prepare("SELECT id, numero_mandat, type_mandat, nature_mandat, date_signature, date_debut, date_fin, statut
                         FROM mandats WHERE id_bien = ? ORDER BY (statut IN ('actif','en_cours','signe')) DESC, date_signature DESC LIMIT 1");
    $st->execute([$bienId]);
    $mandat = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($mandat && in_array((string)$mandat['statut'], ['actif', 'en_cours', 'signe', ''], true)) {
        $mandatActif = $mandat;
    }
} catch (Throwable) {}

// ─── Bail actif (table baux) ─────────────────────────────────────────
$bailActif = null;
try {
    $st = $pdo->prepare("SELECT id, type_bail, date_signature, date_debut, date_fin, statut, locataire_nom, loyer
                         FROM baux WHERE id_bien = ? AND statut = 'actif' ORDER BY id DESC LIMIT 1");
    $st->execute([$bienId]);
    $bailActif = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

// ─── Annonce (peut renseigner type_transaction si pas de mandat) ─────
$annonce = null;
try {
    $st = $pdo->prepare("SELECT id, type_transaction, statut, prix, loyer
                         FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$bienId]);
    $annonce = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

// ═════════════════════════════════════════════════════════════════════
// HYDRATATION SOCIÉTÉ / AGENCE — cascade de fallback
// ═════════════════════════════════════════════════════════════════════
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

// Règle métier 2026-05-24 (post-migration LOCA→REGIE, validée user) :
// Tous les biens orphelins (id_societe NULL) doivent être attribués à
// REGIE EMERY LYON par défaut, indépendamment de la session de l'utilisateur.
// La session peut rester sur LOCA IMMO (historique user) sans impacter le bien.
const DUR_DEFAULT_SOCIETE_ID = 1; // Régie EMERY
const DUR_DEFAULT_AGENCE_ID  = 3; // RE69-2 REGIE EMERY LYON

if ($resolvedSocieteId === 0) {
    $resolvedSocieteId = DUR_DEFAULT_SOCIETE_ID;
    $resolvedAgenceId  = DUR_DEFAULT_AGENCE_ID;
    $resolvedSource    = 'defaut_metier_REGIE_EMERY_LYON';
}

// Scope check (admin bypass)
if (!$isAdmin) {
    $userSoc = (int)($_SESSION['id_societe'] ?? 0);
    // Si bien sans société, on accepte (impossible à scoper). Sinon scope strict.
    if ($resolvedSocieteId > 0 && $resolvedSocieteId !== $userSoc) {
        http_response_code(403);
        exit('Accès interdit : bien hors de votre société.');
    }
}

// Lecture libellés société + agence
$societeRaison = '';
$agenceCode    = null;
$agenceNom     = '';
if ($resolvedSocieteId) {
    try {
        $st = $pdo->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
        $st->execute([$resolvedSocieteId]);
        $societeRaison = (string)$st->fetchColumn();
    } catch (Throwable) {}
}
if ($resolvedAgenceId) {
    try {
        $st = $pdo->prepare("SELECT code_agence, nom_agence FROM agences WHERE id = ?");
        $st->execute([$resolvedAgenceId]);
        $a = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $agenceCode = $a['code_agence'] ?? null;
        $agenceNom  = (string)($a['nom_agence']  ?? '');
    } catch (Throwable) {}
}

$userMatricule = null;
$userUsername  = '';
if ($userId > 0) {
    try {
        $st = $pdo->prepare("SELECT matricule_paie, username FROM users WHERE id = ?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $userMatricule = $u['matricule_paie'] ?? null;
        $userUsername  = (string)($u['username']       ?? '');
    } catch (Throwable) {}
}

// ═════════════════════════════════════════════════════════════════════
// DÉTECTION N1 MÉTIER CONTEXTUELLE
// ═════════════════════════════════════════════════════════════════════
/**
 * Règles métier (validées EMERY 2026-05-24, post-bug ERNT) :
 *  - bail actif sur le bien                 → 05_gestion_locative
 *  - mandat type 'gestion'/'location'       → 05_gestion_locative
 *  - mandat type 'vente'/'exclusif'/'simple' → 06_transaction
 *  - annonce type_transaction='vente' sans mandat → 06_transaction
 *  - défaut (aucun signal)                  → 05_gestion_locative (bien sans contexte = pas Transaction par défaut)
 */
$contexteN1 = '05_gestion_locative';
$contexteRaison = 'défaut (aucun signal de transaction)';
$mandatTypeLow = strtolower((string)($mandat['type_mandat'] ?? '') . ' ' . ($mandat['nature_mandat'] ?? ''));

if ($bailActif) {
    $contexteN1 = '05_gestion_locative';
    $contexteRaison = "bail actif #" . $bailActif['id'] . " sur ce bien";
} elseif ($mandatActif && (str_contains($mandatTypeLow, 'gestion') || str_contains($mandatTypeLow, 'location') || str_contains($mandatTypeLow, 'gerance'))) {
    $contexteN1 = '05_gestion_locative';
    $contexteRaison = "mandat de gestion #" . $mandatActif['id'];
} elseif ($mandatActif && (str_contains($mandatTypeLow, 'vente') || str_contains($mandatTypeLow, 'exclusif') || str_contains($mandatTypeLow, 'simple'))) {
    $contexteN1 = '06_transaction';
    $contexteRaison = "mandat de vente #" . $mandatActif['id'];
} elseif (!$mandatActif && $annonce && strtolower((string)$annonce['type_transaction']) === 'vente') {
    $contexteN1 = '06_transaction';
    $contexteRaison = "annonce vente #" . $annonce['id'] . " (sans mandat)";
}

// ═════════════════════════════════════════════════════════════════════
// CACHE IA (lecture) + extraction si demandée
// ═════════════════════════════════════════════════════════════════════
$extraction = [];
$cacheRow = null;
$hash = (string)$doc['hash_sha256'];

// Si extract_ia=1 → trigger sync + redirect sans le param (anti-replay)
if ($doExtractIa && $hash !== '' && !empty($doc['fichier_chemin']) && is_file((string)$doc['fichier_chemin'])) {
    require_once __DIR__ . '/inc/transaction_doc_extract_ia.php';
    $iaRes = transaction_doc_extract_ia((string)$doc['fichier_chemin']);
    // Le module gère son propre cache via ia_extract_cache
    // Redirect sans le param pour relire le cache fraîchement écrit
    $cleanQs = $_GET;
    unset($cleanQs['extract_ia']);
    $redirUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($cleanQs);
    header('Location: ' . $redirUrl, true, 303);
    exit;
}

if ($hash !== '') {
    try {
        $st = $pdo->prepare("SELECT model, response_json, confidence, last_at
                             FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
        $st->execute([$hash]);
        $cacheRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cacheRow && !empty($cacheRow['response_json'])) {
            $extraction = json_decode((string)$cacheRow['response_json'], true) ?: [];
        }
    } catch (Throwable) {}
}

// Mapping IA — supporte les noms de clés retournés par transaction_doc_extract_ia (Sonnet)
// Voir inc/transaction_doc_extract_ia.php pour le prompt système réel.
$ia_type    = (string)($extraction['type_doc']    ?? $extraction['type']           ?? '');
$ia_label   = (string)($extraction['type_doc_label'] ?? '');
$ia_date    = (string)($extraction['date_signature']
                    ?? $extraction['date_doc']
                    ?? $extraction['date_debut_bail']
                    ?? $extraction['date']            ?? '');
$ia_montant = (string)($extraction['loyer_mensuel_ht']
                    ?? $extraction['loyer_annuel_ht']
                    ?? $extraction['montant']         ?? $extraction['amount']     ?? '');
// "Tiers" côté doc : proprietaire OU locataire OU bailleur OU émetteur
$ia_tiers   = (string)($extraction['proprietaire']
                    ?? $extraction['locataire']
                    ?? $extraction['bailleur_representant_nom']
                    ?? $extraction['tiers']
                    ?? $extraction['emetteur']        ?? '');
$ia_ref     = (string)($extraction['numero_mandat']
                    ?? $extraction['numero_dossier']
                    ?? $extraction['reference']       ?? $extraction['ref']        ?? '');
$ia_adresse = (string)($extraction['adresse_bien']    ?? '');
$ia_notes   = (string)($extraction['notes']           ?? '');
$ia_conf    = (int)($cacheRow['confidence'] ?? ($extraction['confidence'] ?? 0));

// ═════════════════════════════════════════════════════════════════════
// DÉTECTION TYPE_DOC PAR MOTS-CLÉS DU NOM DE FICHIER (avant IA)
// Permet de classer ERNT/ERP/DPE/etc. même si l'IA n'a pas tourné
// ═════════════════════════════════════════════════════════════════════
function dur_detect_type_from_filename(string $fileName): string {
    $up = strtoupper(preg_replace('/[^A-Za-zÀ-ÿ0-9]/u', ' ', $fileName) ?? '');
    // Diagnostics
    if (preg_match('/\b(ERNT|ERNMT|ERP|GEORISQUES|ETAT DES RISQUES|RISQUES NATURELS)\b/u', $up)) return 'erp_ernmt';
    if (preg_match('/\b(DPE|DIAGNOSTIC ENERGETIQUE|DIAG ENERG|PERFORMANCE ENERGETIQUE)\b/u', $up)) return 'dpe';
    if (preg_match('/\b(AMIANTE)\b/u', $up)) return 'diagnostic_amiante';
    if (preg_match('/\b(PLOMB|CREP)\b/u', $up)) return 'diagnostic_plomb';
    if (preg_match('/\b(ELECTRICITE|ELECTRIQUE)\b/u', $up)) return 'diagnostic_elec';
    if (preg_match('/\b(GAZ)\b/u', $up)) return 'diagnostic_gaz';
    if (preg_match('/\b(TERMITES?|MERULE)\b/u', $up)) return 'diagnostic_termites';
    if (preg_match('/\b(SURFACE CARREZ|CARREZ|BOUTIN|SURFACE HABITABLE)\b/u', $up)) return 'surface_carrez';
    // Mandats / actes
    if (preg_match('/\b(MANDAT)\b.*\b(GESTION|GERANCE|LOCATION)\b/u', $up)) return 'mandat_gestion';
    if (preg_match('/\b(MANDAT)\b.*\b(VENTE|EXCLUSIF|SIMPLE)\b/u', $up)) return 'mandat_vente';
    if (preg_match('/\bMANDAT\b/u', $up)) return 'mandat_simple';
    if (preg_match('/\b(COMPROMIS|PROMESSE)\b/u', $up)) return 'compromis';
    if (preg_match('/\b(ACTE AUTHENTIQUE|ACTE NOTARIE)\b/u', $up)) return 'acte_authentique';
    if (preg_match('/\bACTE\b/u', $up)) return 'acte_authentique';
    // Baux / location
    if (preg_match('/\bBAIL\b/u', $up)) return 'bail_signe';
    if (preg_match('/\b(ETAT DES LIEUX|EDL)\b.*\b(ENTREE|ARRIVEE)\b/u', $up)) return 'edl_entree';
    if (preg_match('/\b(ETAT DES LIEUX|EDL)\b.*\b(SORTIE|DEPART)\b/u', $up)) return 'edl_sortie';
    if (preg_match('/\b(QUITTANCE|RECU LOYER)\b/u', $up)) return 'quittance';
    if (preg_match('/\b(AVIS ECHEANCE|ECHEANCE)\b/u', $up)) return 'avis_echeance';
    if (preg_match('/\b(CAUTION|GARANT)\b/u', $up)) return 'caution_garant';
    // Comptable
    if (preg_match('/\b(FACTURE)\b/u', $up)) return 'facture';
    if (preg_match('/\b(RELEVE|EXTRAIT BANCAIRE)\b/u', $up)) return 'releve_bancaire';
    if (preg_match('/\bRIB\b/u', $up)) return 'rib';
    if (preg_match('/\b(ATTESTATION ASSURANCE|ASSURANCE HABITATION|MRH)\b/u', $up)) return 'attestation_assurance';
    return '';
}
$detectedType = dur_detect_type_from_filename($fileName);

// Raffinage IA : si le type_doc IA est générique ("diagnostic", "document", "autre"),
// on cherche dans le label IA ou ailleurs pour spécialiser. ERNMT/DPE/etc. dans le label.
function dur_refine_ia_type(array $extraction): string {
    $rawType = strtolower(trim((string)($extraction['type_doc'] ?? '')));
    $label   = strtoupper((string)($extraction['type_doc_label'] ?? '') . ' ' . ($extraction['titre_court'] ?? ''));
    $genericTypes = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
    if (!in_array($rawType, $genericTypes, true)) {
        return preg_replace('/[^a-z_]/', '', $rawType);
    }
    // Type générique → on regarde le label pour raffiner
    if (preg_match('/\b(ERNT|ERNMT|ERP|ETAT DES RISQUES|RISQUES NATURELS|GEORISQUES)\b/u', $label)) return 'erp_ernmt';
    if (preg_match('/\b(DPE|PERFORMANCE ENERGETIQUE)\b/u', $label))                return 'dpe';
    if (preg_match('/\b(AMIANTE)\b/u', $label))                                    return 'diagnostic_amiante';
    if (preg_match('/\b(PLOMB|CREP)\b/u', $label))                                 return 'diagnostic_plomb';
    if (preg_match('/\b(ELECTRICITE|ELECTRIQUE)\b/u', $label))                     return 'diagnostic_elec';
    if (preg_match('/\bGAZ\b/u', $label))                                          return 'diagnostic_gaz';
    if (preg_match('/\b(TERMITES?|MERULE)\b/u', $label))                           return 'diagnostic_termites';
    if (preg_match('/\b(SURFACE CARREZ|CARREZ|BOUTIN)\b/u', $label))               return 'surface_carrez';
    return $rawType; // garde le type générique si rien trouvé
}
$ia_type_norm = dur_refine_ia_type($extraction);

// Type final : priorité au plus spécifique entre IA-raffiné, nom_fichier
// Règle : si IA-raffiné == générique ET nom_fichier == spécifique → nom_fichier gagne
$genericTypes = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
$iaIsGeneric  = in_array($ia_type_norm, $genericTypes, true);
$nameIsSpec   = $detectedType !== '';

if ($ia_type_norm === '' && $detectedType !== '') {
    $effectiveType = $detectedType;
    $typeOrigin = 'nom_fichier';
} elseif ($iaIsGeneric && $nameIsSpec) {
    $effectiveType = $detectedType;
    $typeOrigin = 'nom_fichier (IA était générique)';
} elseif ($ia_type_norm !== '' && $ia_conf >= 50) {
    $effectiveType = $ia_type_norm;
    $typeOrigin = 'ia (raffinée)';
} else {
    $effectiveType = $detectedType ?: $ia_type_norm;
    $typeOrigin = $detectedType ? 'nom_fichier' : ($ia_type_norm ? 'ia (faible conf)' : 'aucun');
}

// ═════════════════════════════════════════════════════════════════════
// MAPPING GED — par contexte N1 + type doc
// ═════════════════════════════════════════════════════════════════════
$gedMappingTransaction = [
    'compromis'         => ['02_acte', '01_compromis'],
    'promesse'          => ['02_acte', '01_compromis'],
    'acte_authentique'  => ['02_acte', '02_acte_authentique'],
    'mandat_exclusif'   => ['01_mandat_vente', '01_mandat_exclusif'],
    'mandat_simple'     => ['01_mandat_vente', '02_mandat_simple'],
    'mandat_vente'      => ['01_mandat_vente', '01_mandat_exclusif'],
    'dpe'               => ['03_diagnostics_transaction', '01_dpe'],
    'erp_ernmt'         => ['03_diagnostics_transaction', '02_erp_ernmt'],
    'diagnostic_amiante'=> ['03_diagnostics_transaction', null],
    'diagnostic_plomb'  => ['03_diagnostics_transaction', null],
    'diagnostic_elec'   => ['03_diagnostics_transaction', null],
    'diagnostic_gaz'    => ['03_diagnostics_transaction', null],
    'diagnostic_termites'=>['03_diagnostics_transaction', null],
    'surface_carrez'    => ['03_diagnostics_transaction', null],
    'dossier_acquereur' => ['04_acquereur', '01_dossier_acquereur'],
    'accord_de_pret'    => ['04_acquereur', '02_accord_de_pret'],
    'decompte_vendeur'  => ['05_suivi_vente', '01_decompte_vendeur'],
    'quittance_honoraires' => ['05_suivi_vente', '02_quittance_honoraires'],
];
// Mapping gestion locative — squelette enrichi 2026-05-24 (seed v1.2 N2 03_bien).
// Les diagnostics caractérisent le BIEN (pas le bail) → désormais sous 03_bien.
$gedMappingGestion = [
    'mandat_gestion'    => ['01_mandat_gestion', '01_mandat_signe'],
    'mandat_simple'     => ['01_mandat_gestion', '01_mandat_signe'],
    'bail_signe'        => ['02_bail', '01_bail_signe'],
    'edl_entree'        => ['02_bail', '02_edl_entree'],
    'edl_sortie'        => ['06_sortie_locataire', '02_edl_sortie'],
    // ─── Diagnostics : N2 = 03_bien (créés par seed v1.2) ──
    'dpe'               => ['03_bien', '01_dpe'],
    'erp_ernmt'         => ['03_bien', '02_erp_ernmt'],
    'diagnostic_amiante'=> ['03_bien', '03_amiante'],
    'diagnostic_plomb'  => ['03_bien', '04_plomb_crep'],
    'diagnostic_elec'   => ['03_bien', '05_electricite'],
    'diagnostic_gaz'    => ['03_bien', '06_gaz'],
    'diagnostic_termites'=>['03_bien', '07_termites'],
    'surface_carrez'    => ['03_bien', '08_surface_carrez'],
    'attestation_assurance' => ['03_bien', '09_assurance_proprietaire'],
    'diagnostic'            => ['03_bien', null],  // fallback IA générique non raffinée
    // ─── Loyers / contrats / sortie / CAF ──
    'quittance'         => ['03_loyers', '01_quittance'],
    'avis_echeance'     => ['03_loyers', '02_avis_echeance'],
    'caution_garant'    => ['02_bail', '03_caution_garant'],
    'preavis'           => ['06_sortie_locataire', '01_preavis'],
    'restitution_depot_garantie' => ['06_sortie_locataire', '03_restitution_depot_garantie'],
    'attestation_caf'   => ['07_caf_apl', '01_attestation_caf'],
];
$gedMapping = ($contexteN1 === '06_transaction') ? $gedMappingTransaction : $gedMappingGestion;
[$proposed_n2, $proposed_n3] = $gedMapping[$effectiveType] ?? [null, null];

function dur_find_folder(PDO $pdo, ?string $n1_slug, ?string $n2_slug, ?string $n3_slug): ?array {
    if (!$n1_slug) return null;
    try {
        $st = $pdo->prepare("SELECT id, name_display FROM ged_folders
                              WHERE slug = ? AND (parent_id IS NULL OR parent_id = 0) AND is_archived = 0 LIMIT 1");
        $st->execute([$n1_slug]);
        $n1 = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n1) return null;
        if (!$n2_slug) return ['n1' => $n1, 'n2' => null, 'n3' => null, 'folder_id' => (int)$n1['id']];

        $st = $pdo->prepare("SELECT id, name_display FROM ged_folders
                              WHERE slug = ? AND parent_id = ? AND is_archived = 0 LIMIT 1");
        $st->execute([$n2_slug, $n1['id']]);
        $n2 = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n2) return ['n1' => $n1, 'n2' => null, 'n3' => null, 'folder_id' => (int)$n1['id']];
        if (!$n3_slug) return ['n1' => $n1, 'n2' => $n2, 'n3' => null, 'folder_id' => (int)$n2['id']];

        $st = $pdo->prepare("SELECT id, name_display FROM ged_folders
                              WHERE slug = ? AND parent_id = ? AND is_archived = 0 LIMIT 1");
        $st->execute([$n3_slug, $n2['id']]);
        $n3 = $st->fetch(PDO::FETCH_ASSOC);
        return ['n1' => $n1, 'n2' => $n2, 'n3' => $n3, 'folder_id' => $n3 ? (int)$n3['id'] : (int)$n2['id']];
    } catch (Throwable) { return null; }
}
$gedPath = dur_find_folder($pdo, $contexteN1, $proposed_n2, $proposed_n3);

// ─── Tree complet GED pour cascade JS (N1/N2/N3) ────────────────────
$gedTree = [];
try {
    $rows = $pdo->query("SELECT id, parent_id, slug, name_display FROM ged_folders WHERE is_archived = 0 ORDER BY parent_id, slug")->fetchAll(PDO::FETCH_ASSOC);
    $byParent = [];
    foreach ($rows as $r) {
        $pid = (int)($r['parent_id'] ?? 0);
        $byParent[$pid][] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name_display']];
    }
    foreach ($byParent[0] ?? [] as $n1) {
        $n1Node = ['slug' => $n1['slug'], 'name' => $n1['name'], 'id' => $n1['id'], 'children' => []];
        foreach ($byParent[$n1['id']] ?? [] as $n2) {
            $n2Node = ['slug' => $n2['slug'], 'name' => $n2['name'], 'id' => $n2['id'], 'children' => []];
            foreach ($byParent[$n2['id']] ?? [] as $n3) {
                $n2Node['children'][] = ['slug' => $n3['slug'], 'name' => $n3['name'], 'id' => $n3['id']];
            }
            $n1Node['children'][] = $n2Node;
        }
        $gedTree[] = $n1Node;
    }
} catch (Throwable) {}

// ─── Tiers IA (en plus du proprio préfetch) ──────────────────────────
$matchTiers = null;
if ($ia_tiers !== '') {
    try { $matchTiers = em_match_tiers($pdo, ['raison_sociale' => $ia_tiers, 'nom' => $ia_tiers]); }
    catch (Throwable $e) { $matchTiers = ['found' => false, 'error' => $e->getMessage()]; }
}

// ─── Immeuble (déjà préfetch via JOIN) ───────────────────────────────
$matchImmeuble = null;
if (!empty($bien['id_immeuble'])) {
    $matchImmeuble = [
        'found' => true,
        'best'  => [
            'id' => (int)$bien['id_immeuble'],
            'adresse_1' => $bien['imm_adresse'],
            'code_postal' => $bien['imm_cp'],
            'ville' => $bien['imm_ville'],
            'score' => 100,
        ],
        'confidence' => 100,
        'match_type' => 'inherited_from_bien',
    ];
}

// ═════════════════════════════════════════════════════════════════════
// NOM V3 PREVIEW — utilise N1 contextuel, pas le défaut
// ═════════════════════════════════════════════════════════════════════
$namingCtx = [
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
];
$nameV3Preview = gdn_v3_build($namingCtx);

// ─── URL PDF ────────────────────────────────────────────────────────
$pdfUrl = null;
if (!empty($doc['fichier_chemin'])) {
    $path = (string)$doc['fichier_chemin'];
    $abs = realpath($path) ?: $path;
    $abs = str_replace('\\', '/', $abs);
    $docRoot = str_replace('\\', '/', realpath(__DIR__) ?: '');
    if ($docRoot && str_starts_with($abs, $docRoot)) {
        $rel = substr($abs, strlen($docRoot));
        if (!str_starts_with($rel, '/')) $rel = '/' . $rel;
        $pdfUrl = '/MaBoxImmo2026/public_html' . $rel;
    } else {
        $pdfUrl = '/MaBoxImmo2026/public_html/uploads/' . basename($path);
    }
}

// ─── Navigation retour ──────────────────────────────────────────────
$backUrl = match ($source) {
    'fluxbox'      => '/MaBoxImmo2026/public_html/fluxbox.php',
    'transaction'  => '/MaBoxImmo2026/public_html/bien_360.php?id=' . (int)$bienId,
    default        => '/MaBoxImmo2026/public_html/bien_360.php?id=' . (int)$bienId,
};
$backLabel = match ($source) {
    'fluxbox'      => '← Retour FluxBox',
    default        => '← Retour au bien #' . (int)$bienId,
};
$sourceBadge = match ($source) {
    'fluxbox'      => ['label' => 'FLUXBOX', 'color' => 'var(--chargement)'],
    'transaction'  => ['label' => 'TRANSACTION', 'color' => 'var(--transaction)'],
    default        => ['label' => 'DIRECT', 'color' => 'var(--info)'],
};
$contexteN1Badge = match ($contexteN1) {
    '05_gestion_locative' => ['label' => 'GESTION LOCATIVE', 'color' => '#0e7490'],
    '06_transaction'      => ['label' => 'TRANSACTION',      'color' => '#eab308'],
    default               => ['label' => 'AUTRE',            'color' => '#94a3b8'],
};

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>📤 Pipeline documentaire · Bien #<?= (int)$bienId ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047; --mbi-navy: #243B5C;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171; --info: #60a5fa;
            --bien: #84a98c; --immeuble: #7c9885; --proprio: #0e7490;
            --transaction: #eab308; --chargement: #7c3aed; --gestion: #0e7490;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DM Mono", "JetBrains Mono", monospace; background: var(--bg); color: var(--text); font-size: 12.5px; }
        header { display: flex; align-items: center; gap: 10px; padding: 10px 18px; background: #11203b; border-bottom: 2px solid var(--mbi-or); flex-wrap: wrap; }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .badge { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
        header .badge-source { color: #1a1a1a; }
        header .badge-n1 { color: #fff; }
        header nav { margin-left: auto; display: flex; gap: 10px; }
        header nav a { color: var(--muted); text-decoration: none; font-size: 11px; border: 1px solid var(--line); padding: 4px 10px; border-radius: 4px; }
        header nav a:hover { color: var(--text); border-color: var(--mbi-or); }

        /* Ratio PDF / form : 37.5% / 62.5% (PDF réduit de 25% vs 50/50) pour lisibilité formulaire */
        .layout { display: grid; grid-template-columns: 3fr 5fr; height: calc(100vh - 47px); }
        .pane { overflow-y: auto; }
        .pane-pdf { background: #0a1424; }
        .pane-form { background: var(--panel); padding: 16px 20px; border-left: 1px solid var(--line); }
        .pdf-header { padding: 8px 14px; background: var(--panel); border-bottom: 1px solid var(--line); font-size: 11px; color: var(--muted); }
        .pdf-viewer { position: relative; height: calc(100% - 35px); }
        .pdf-viewer iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: none; }
        .pdf-empty { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--muted); padding: 24px; text-align: center; }

        .section { background: #0f172a; border-radius: 6px; padding: 12px 16px; margin-bottom: 12px; border-left: 4px solid var(--line); }
        .section.s-ctx { border-left-color: var(--gestion); }
        .section.s-ia { border-left-color: var(--info); }
        .section.s-bien { border-left-color: var(--bien); }
        .section.s-proprio { border-left-color: var(--proprio); }
        .section.s-immeuble { border-left-color: var(--immeuble); }
        .section.s-mandat { border-left-color: var(--mbi-or); }
        .section.s-classement { border-left-color: var(--mbi-or); }
        .section.s-naming { border-left-color: var(--chargement); }
        .section h2 { font-size: 12px; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .section.s-ctx h2 { color: var(--gestion); }
        .section.s-ia h2 { color: var(--info); }
        .section.s-bien h2 { color: var(--bien); }
        .section.s-proprio h2 { color: var(--proprio); }
        .section.s-immeuble h2 { color: var(--immeuble); }
        .section.s-mandat h2 { color: var(--mbi-or); }
        .section.s-classement h2 { color: var(--mbi-or); }
        .section.s-naming h2 { color: var(--chargement); }

        .field { margin-bottom: 8px; }
        .field label { display: block; font-size: 10px; color: var(--muted); margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.3px; }
        .field input, .field select, .field textarea { width: 100%; padding: 6px 10px; background: #0a1424; border: 1px solid var(--line); color: var(--text); border-radius: 3px; font-family: inherit; font-size: 12px; }
        .field input:focus, .field select:focus { border-color: var(--mbi-or); outline: none; }
        .field input[readonly] { background: #1e293b; opacity: 0.7; cursor: not-allowed; }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        .match-card { background: #1e293b; padding: 8px 12px; border-radius: 4px; margin-top: 6px; font-size: 11px; border-left: 3px solid var(--ok); }
        .match-card.warn { border-left-color: var(--warn); }
        .match-card.ko { border-left-color: var(--ko); }
        .match-card .score { display: inline-block; padding: 1px 6px; background: var(--ok); color: #1a1a1a; border-radius: 3px; font-weight: 700; font-size: 9.5px; margin-right: 6px; }
        .match-card.warn .score { background: var(--warn); }
        .match-card.ko .score { background: var(--ko); color: #fff; }

        .naming-preview { background: #0a1424; padding: 10px 14px; border-radius: 4px; border: 1px dashed var(--chargement); font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--warn); word-break: break-all; }
        .actions { position: sticky; bottom: 0; padding: 14px 0; background: var(--panel); border-top: 1px solid var(--line); display: flex; gap: 10px; justify-content: space-between; margin-top: 16px; }
        .btn { padding: 10px 18px; border: none; border-radius: 4px; font-family: inherit; font-size: 12.5px; cursor: pointer; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--ok); color: #1a1a1a; }
        .btn-primary:hover { background: #6b8a72; }
        .btn-info { background: var(--info); color: #1a1a1a; }
        .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .btn-ghost:hover { color: var(--text); border-color: var(--mbi-or); }
        .info-box { background: #082f49; color: #bae6fd; padding: 8px 12px; border-radius: 3px; font-size: 10.5px; margin-bottom: 12px; border-left: 3px solid var(--info); }
        .warn-box { background: #422006; color: #fde68a; padding: 8px 12px; border-radius: 3px; font-size: 10.5px; margin-bottom: 12px; border-left: 3px solid var(--warn); }
        .ko-box { background: #450a0a; color: #fca5a5; padding: 8px 12px; border-radius: 3px; font-size: 10.5px; margin-bottom: 12px; border-left: 3px solid var(--ko); }
        .meta { font-size: 10.5px; color: var(--muted); }
        .meta b { color: var(--text); }
        .checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 11px; }
        .checkbox-row input { width: auto; }
        .pill { display: inline-block; padding: 1px 7px; background: #334155; border-radius: 10px; font-size: 10px; color: var(--text); margin-right: 4px; }
        .pill-ok { background: #16653a; }
        .pill-warn { background: #713f12; color: var(--warn); }
        .pill-info { background: #082f49; color: #bae6fd; }

        /* ─── Mini-cards GED (style aligné fluxbox_upload_modal) ─── */
        .dur-cards-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .dur-cards-empty { color: var(--muted); font-size: 11px; font-style: italic; padding: 8px 4px; }
        .dur-card-btn {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            min-width: 78px; min-height: 62px; padding: 8px 10px;
            background: #1e293b; border: 1.5px solid var(--line); border-radius: 6px;
            color: var(--text); cursor: pointer; font-family: inherit; font-size: 10.5px;
            text-align: center; line-height: 1.2; transition: all 0.15s;
        }
        .dur-card-btn:hover { border-color: var(--mbi-or); background: #2d3a4e; transform: translateY(-1px); }
        .dur-card-btn.is-selected {
            background: var(--mbi-or); color: #1a1a1a; border-color: var(--mbi-or);
            font-weight: 700; box-shadow: 0 2px 8px rgba(212,160,71,0.3);
        }
        .dur-card-btn .dur-card-icon { font-size: 22px; line-height: 1; margin-bottom: 3px; }
        .dur-card-btn .dur-card-label { font-size: 10px; max-width: 90px; word-break: break-word; }
        .dur-card-btn .dur-card-code  { font-size: 9px; color: var(--muted); opacity: 0.7; margin-top: 2px; }
        .dur-card-btn.is-selected .dur-card-code { color: #5c4a1f; opacity: 0.8; }
        .dur-level-title { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.3px; margin: 8px 0 4px; }
    </style>
</head>
<body>

<header>
    <h1>📤 Pipeline documentaire unifié</h1>
    <span class="badge" style="background:var(--ok);color:#1a1a1a;">SPRINT 6 · A1</span>
    <span class="badge badge-source" style="background: <?= dur_html($sourceBadge['color']) ?>;">source: <?= dur_html($sourceBadge['label']) ?></span>
    <span class="badge badge-n1" style="background: <?= dur_html($contexteN1Badge['color']) ?>;">N1: <?= dur_html($contexteN1Badge['label']) ?></span>
    <nav>
        <a href="<?= dur_html($backUrl) ?>"><?= dur_html($backLabel) ?></a>
        <a href="bien_documents_list.php?id=<?= (int)$bienId ?>">📁 Documents du bien</a>
    </nav>
</header>

<div class="layout">

    <!-- ─── PDF PANE ─── -->
    <section class="pane pane-pdf">
        <div class="pdf-header">
            📄 <b><?= dur_html($fileName) ?></b>
            · <?= dur_html((string)($doc['mime_type'] ?? '?')) ?>
            · <?= number_format((int)$doc['taille_octets']/1024) ?> Ko
            · hash <code><?= dur_html(substr($hash, 0, 16)) ?>…</code>
        </div>
        <div class="pdf-viewer">
            <?php if ($pdfUrl): ?>
                <iframe src="<?= dur_html($pdfUrl) ?>#zoom=page-fit" title="PDF"></iframe>
            <?php else: ?>
                <div class="pdf-empty">
                    <div>
                        <p>📄 URL HTTP non résolue.</p>
                        <p style="font-size: 10px; color: var(--warn);"><code><?= dur_html((string)$doc['fichier_chemin']) ?></code></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ─── FORM PANE ─── -->
    <section class="pane pane-form">

        <div class="info-box">
            🏠 <b>Bien cible</b> : #<?= (int)$bien['id'] ?>
            <?php if (!empty($bien['adresse_1'])): ?>
                · <?= dur_html((string)$bien['adresse_1']) ?>
            <?php endif; ?>
            <?php if (!empty($bien['code_postal'])): ?>
                · <?= dur_html((string)$bien['code_postal']) ?> <?= dur_html((string)($bien['ville'] ?? '')) ?>
            <?php endif; ?>
            <?php if (!empty($bien['imm_adresse']) && empty($bien['adresse_1'])): ?>
                <span class="meta">(adresse héritée de l'immeuble : <?= dur_html((string)$bien['imm_adresse']) ?>)</span>
            <?php endif; ?>
        </div>

        <form method="POST" action="api/transaction_upload_commit.php" id="reviewForm">
            <input type="hidden" name="doc_id" value="<?= (int)$docId ?>">
            <input type="hidden" name="bien_id" value="<?= (int)$bienId ?>">
            <input type="hidden" name="card_id" value="<?= (int)$cardId ?>">
            <input type="hidden" name="source" value="<?= dur_html($source) ?>">
            <input type="hidden" name="ctx_type" value="<?= dur_html($ctxType) ?>">
            <input type="hidden" name="csrf_token" value="<?= dur_html($_SESSION['csrf_token'] ?? '') ?>">

            <!-- 0. CONTEXTE MÉTIER DÉTECTÉ -->
            <div class="section s-ctx">
                <h2>🧭 0. Contexte métier détecté (depuis BDD)</h2>
                <div class="meta">
                    <span class="pill pill-info">N1 = <?= dur_html($contexteN1) ?></span>
                    <span class="pill pill-ok"><?= dur_html($contexteRaison) ?></span>
                </div>
                <div class="meta" style="margin-top:6px;">
                    <?php if ($bailActif): ?>
                        🔑 Bail <b>#<?= (int)$bailActif['id'] ?></b> actif ·
                        locataire <?= dur_html((string)$bailActif['locataire_nom']) ?>
                        · loyer <?= dur_html((string)$bailActif['loyer']) ?>€
                    <?php endif; ?>
                    <?php if ($mandatActif): ?>
                        📋 Mandat #<?= (int)$mandatActif['id'] ?> ·
                        type <b><?= dur_html((string)$mandatActif['type_mandat']) ?></b>
                        · <?= dur_html((string)$mandatActif['statut']) ?>
                    <?php endif; ?>
                    <?php if (!$bailActif && !$mandatActif && $annonce): ?>
                        📡 Annonce #<?= (int)$annonce['id'] ?> ·
                        type_transaction <b><?= dur_html((string)$annonce['type_transaction']) ?></b>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 1. EXTRACTION IA -->
            <div class="section s-ia">
                <h2>📊 1. Extraction IA <?php if ($ia_conf > 0): ?>· confiance <?= $ia_conf ?>%<?php endif; ?></h2>
                <?php if (!$cacheRow): ?>
                    <div class="warn-box">
                        ⚠️ Pas d'extraction IA en cache pour ce document. Le type a été
                        <?php if ($detectedType): ?>
                            détecté <b>automatiquement depuis le nom de fichier</b> : <code><?= dur_html($detectedType) ?></code>
                        <?php else: ?>
                            <b>non détecté</b> — sélectionne manuellement ci-dessous ou lance l'IA.
                        <?php endif; ?>
                    </div>
                    <p style="margin: 6px 0 12px;">
                        <a class="btn btn-info" href="?<?= dur_html(http_build_query(array_merge($_GET, ['extract_ia' => 1]))) ?>"
                           onclick="this.innerText='⏳ Extraction en cours… (5-10s)';">
                            🤖 Extraire IA maintenant (Sonnet, ~5-10s)
                        </a>
                    </p>
                <?php else: ?>
                    <div class="info-box">
                        ✅ Extraction IA en cache · modèle <?= dur_html((string)$cacheRow['model']) ?>
                        · <?= dur_html((string)$cacheRow['last_at']) ?>
                        · confiance <b><?= $ia_conf ?>%</b>
                    </div>
                    <?php if ($ia_label !== ''): ?>
                        <div class="match-card" style="margin-bottom:8px;">
                            <span class="score">IA</span>
                            <b>Label IA :</b> <?= dur_html($ia_label) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($ia_adresse !== '' || $ia_notes !== ''): ?>
                        <div class="meta" style="background:#0a1424;padding:8px 12px;border-radius:3px;margin-bottom:8px;">
                            <?php if ($ia_adresse !== ''): ?>
                                📍 <b>Adresse extraite :</b> <?= dur_html($ia_adresse) ?><br>
                            <?php endif; ?>
                            <?php if ($ia_notes !== ''): ?>
                                📝 <b>Notes IA :</b> <?= dur_html($ia_notes) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="field-row">
                    <div class="field">
                        <label>Type de document <?= $typeOrigin === 'nom_fichier' ? '<span class="pill pill-warn">déduit du nom</span>' : ($typeOrigin === 'ia' ? '<span class="pill pill-ok">IA</span>' : '') ?></label>
                        <select name="type_doc">
                            <?php
                            $types = ['',
                                'compromis','acte_authentique','promesse',
                                'mandat_exclusif','mandat_simple','mandat_vente','mandat_gestion',
                                'dpe','erp_ernmt',
                                'diagnostic_amiante','diagnostic_plomb','diagnostic_elec','diagnostic_gaz','diagnostic_termites',
                                'surface_carrez',
                                'dossier_acquereur','accord_de_pret','decompte_vendeur','quittance_honoraires',
                                'bail_signe','avenant_mandat','edl_entree','edl_sortie',
                                'caution_garant','quittance','avis_echeance','preavis',
                                'restitution_depot_garantie','attestation_caf',
                                'facture','releve_bancaire','rib','attestation_assurance',
                                'autre',
                            ];
                            foreach ($types as $t):
                                $sel = ($t === $effectiveType) ? 'selected' : '';
                            ?>
                                <option value="<?= dur_html($t) ?>" <?= $sel ?>>
                                    <?= dur_html($t === '' ? '— choisir —' : $t) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Date document</label>
                        <input type="date" name="date_doc" value="<?= dur_html($ia_date) ?>">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>Montant (€)</label>
                        <input type="text" name="montant" value="<?= dur_html($ia_montant) ?>" placeholder="ex. 285000">
                    </div>
                    <div class="field">
                        <label>Référence</label>
                        <input type="text" name="reference" value="<?= dur_html($ia_ref) ?>" placeholder="N° doc, contrat...">
                    </div>
                </div>
                <div class="field">
                    <label>Confiance IA (calculée)</label>
                    <input type="number" name="confidence_ia" value="<?= $ia_conf ?>" min="0" max="100">
                </div>
            </div>

            <!-- 2. BIEN (éditable) -->
            <div class="section s-bien">
                <h2>🏠 2. Bien cible <span class="pill pill-info">modifiable</span></h2>
                <div class="meta" style="margin-bottom:6px;">
                    Bien actuel : <b>#<?= (int)$bien['id'] ?></b> ·
                    <?= dur_html((string)($bien['adresse_1'] ?: $bien['imm_adresse'] ?: '?')) ?>
                    · société <?= $resolvedSocieteId ? '#'.$resolvedSocieteId.' '.dur_html($societeRaison).' <span class="pill pill-info">via '.dur_html($resolvedSource).'</span>' : '<span class="pill pill-warn">non résolue</span>' ?>
                    · agence <?= $resolvedAgenceId ? '#'.$resolvedAgenceId.' '.dur_html($agenceNom) : '<span class="pill pill-warn">non résolue</span>' ?>
                </div>
                <div class="field">
                    <label>ID bien lié (link_bien_id)</label>
                    <input type="number" name="link_bien_id" value="<?= (int)$bien['id'] ?>" min="1" data-entity="bien">
                </div>
                <input type="hidden" name="link_bien_relation" value="main">
            </div>

            <!-- 3. IMMEUBLE (éditable) -->
            <div class="section s-immeuble">
                <h2>🏢 3. Immeuble <span class="pill pill-info">modifiable</span></h2>
                <?php if ($matchImmeuble && $matchImmeuble['found']): ?>
                    <div class="meta" style="margin-bottom:6px;">
                        Immeuble actuel : <b>#<?= (int)$matchImmeuble['best']['id'] ?></b> ·
                        <?= dur_html((string)$matchImmeuble['best']['adresse_1']) ?>
                        <span class="pill pill-ok">auto via biens.id_immeuble</span>
                    </div>
                <?php else: ?>
                    <div class="meta" style="margin-bottom:6px;">⚠️ Bien sans immeuble lié.</div>
                <?php endif; ?>
                <div class="field">
                    <label>ID immeuble lié (link_immeuble_id, 0 = aucun)</label>
                    <input type="number" name="link_immeuble_id" value="<?= (int)($matchImmeuble['best']['id'] ?? 0) ?>" min="0" data-entity="immeuble">
                </div>
                <input type="hidden" name="link_immeuble_relation" value="reference">
            </div>

            <!-- 4. PROPRIÉTAIRE / TIERS (éditable) -->
            <div class="section s-proprio">
                <h2>👤 4. Propriétaire / Tiers <span class="pill pill-info">modifiable</span></h2>
                <?php if ($proprietaire || $proprioTiers): ?>
                    <div class="meta" style="margin-bottom:6px;">
                        Propriétaire actuel :
                        <?php if ($proprietaire): ?>
                            proprio <b>#<?= (int)$proprietaire['id'] ?></b> · <?= dur_html($proprioLabel ?: '?') ?>
                        <?php endif; ?>
                        <?php if ($proprioTiers): ?>
                            · tiers <b>#<?= (int)$proprioTiers['id'] ?></b> ·
                            <?= dur_html((string)($proprioTiers['raison_sociale'] ?: trim(($proprioTiers['prenom']??'').' '.($proprioTiers['nom']??'')))) ?>
                        <?php endif; ?>
                        <span class="pill pill-ok">préfetch BDD</span>
                    </div>
                <?php else: ?>
                    <div class="meta" style="margin-bottom:6px;">⚠️ Bien sans propriétaire ni tiers en BDD.</div>
                <?php endif; ?>
                <div class="field">
                    <label>ID tiers lié au document (link_tiers_id, 0 = aucun)</label>
                    <input type="number" name="link_tiers_id" value="<?= (int)$proprioTiersId ?>" min="0" data-entity="tiers">
                </div>
                <input type="hidden" name="link_tiers_relation" value="reference">

                <?php if ($matchTiers && !empty($matchTiers['matches'])): ?>
                    <div class="warn-box" style="margin-top:8px;">
                        🔍 IA a aussi détecté "<?= dur_html($ia_tiers) ?>" :
                        <?php foreach (array_slice($matchTiers['matches'], 0, 3) as $m):
                            $name = $m['raison_sociale'] ?: trim(($m['nom'] ?? '').' '.($m['prenom'] ?? ''));
                        ?>
                            #<?= (int)$m['id'] ?> <?= dur_html((string)$name) ?> (score <?= (int)($m['score'] ?? 0) ?>) ·
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 5. MANDAT + BAIL (éditables, 2 inputs séparés) -->
            <div class="section s-mandat">
                <h2>📋 5. Mandat / Bail <span class="pill pill-info">modifiable</span></h2>
                <?php if ($mandatActif): ?>
                    <div class="meta" style="margin-bottom:6px;">
                        Mandat actif détecté : <b>#<?= (int)$mandatActif['id'] ?></b> ·
                        <?= dur_html((string)($mandatActif['numero_mandat'] ?? '?')) ?>
                        · type <b><?= dur_html((string)$mandatActif['type_mandat']) ?></b>
                        · <?= dur_html((string)$mandatActif['statut']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($bailActif): ?>
                    <div class="meta" style="margin-bottom:6px;">
                        Bail actif détecté : <b>#<?= (int)$bailActif['id'] ?></b> ·
                        locataire <?= dur_html((string)$bailActif['locataire_nom']) ?>
                        · loyer <?= dur_html((string)$bailActif['loyer']) ?>€
                    </div>
                <?php endif; ?>
                <?php if (!$mandatActif && !$bailActif): ?>
                    <div class="meta" style="margin-bottom:6px;">⚠️ Aucun mandat ni bail actif sur ce bien.</div>
                <?php endif; ?>
                <div class="field-row">
                    <div class="field">
                        <label>ID mandat lié (0 = aucun)</label>
                        <input type="number" name="link_mandat_id" value="<?= (int)($mandatActif['id'] ?? 0) ?>" min="0" data-entity="mandat">
                    </div>
                    <div class="field">
                        <label>ID bail lié (0 = aucun)</label>
                        <input type="number" name="link_bail_id" value="<?= (int)($bailActif['id'] ?? 0) ?>" min="0" data-entity="bail">
                    </div>
                </div>
                <input type="hidden" name="link_mandat_relation" value="annexe">
                <input type="hidden" name="link_bail_relation" value="annexe">
            </div>

            <!-- 6. CLASSEMENT GED (cascade N1→N2→N3 en mini-cards) -->
            <div class="section s-classement">
                <h2>📁 6. Classement GED <span class="pill pill-info">cliquer pour choisir</span></h2>
                <div class="meta" style="margin-bottom:8px;">
                    N1 déduit du contexte = <code><?= dur_html($contexteN1) ?></code>.
                    N2/N3 proposés depuis type_doc <code><?= dur_html($effectiveType ?: '—') ?></code>.
                    Clique pour modifier.
                </div>

                <div class="dur-level-title">🧭 N1 — Métier</div>
                <div class="dur-cards-row" id="gedN1Row"></div>

                <div class="dur-level-title">📂 N2 — Domaine</div>
                <div class="dur-cards-row" id="gedN2Row"><div class="dur-cards-empty">— choisir un N1 d'abord —</div></div>

                <div class="dur-level-title">📑 N3 — Type documentaire (optionnel)</div>
                <div class="dur-cards-row" id="gedN3Row"><div class="dur-cards-empty">— choisir un N2 d'abord —</div></div>

                <div id="gedFolderResolved" class="match-card" style="display:none; margin-top:8px;">
                    <span class="score">✓</span>
                    folder_id = <span id="gedFolderIdLabel"></span> · <span id="gedFolderPathLabel"></span>
                </div>
                <input type="hidden" name="ged_n1_slug" id="gedN1Hidden" value="<?= dur_html($contexteN1) ?>">
                <input type="hidden" name="ged_n2_slug" id="gedN2Hidden" value="<?= dur_html((string)$proposed_n2) ?>">
                <input type="hidden" name="ged_n3_slug" id="gedN3Hidden" value="<?= dur_html((string)$proposed_n3) ?>">
                <input type="hidden" name="ged_folder_id" id="gedFolderIdHidden" value="<?= (int)($gedPath['folder_id'] ?? 0) ?>">
            </div>

            <!-- 6.5 COMMENTAIRE UTILISATEUR -->
            <div class="section s-ctx">
                <h2>💬 6.5. Commentaire utilisateur (optionnel)</h2>
                <div class="field">
                    <textarea name="user_comment" rows="3" placeholder="Note libre : raison du classement manuel, contexte, anomalie observée…"></textarea>
                </div>
            </div>

            <!-- 7. NOM V3 PREVIEW -->
            <div class="section s-naming">
                <h2>🏷️ 7. Nom de fichier V3 (preview dynamique)</h2>
                <div class="naming-preview" id="nameV3PreviewBox"><?= dur_html($nameV3Preview) ?></div>
                <div class="meta" style="margin-top:6px;" id="nameV3MetaBox">
                    Format FluxBox V3 (11 segments) · longueur <?= strlen($nameV3Preview) ?> chars · <span style="color:var(--info);">dynamique</span>
                </div>
                <input type="hidden" name="name_v3" id="nameV3HiddenInput" value="<?= dur_html($nameV3Preview) ?>">
            </div>

            <!-- 8. HOOKS -->
            <div class="section s-mandat">
                <h2>⚙️ 8. Mise à jour automatique des tables métier (opt-in)</h2>
                <div class="meta">
                    Si coché, les hooks Transaction mettront à jour
                    <b>mandats.date_signature</b>, <b>biens.dpe_classe</b> etc.
                    selon le type doc (uniquement si confiance ≥ 90 %).
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="apply_metier_hooks" id="hooks-chk" value="1">
                    <label for="hooks-chk">Appliquer les hooks métier au commit (décoché par défaut — safety)</label>
                </div>
            </div>

            <div class="actions">
                <a href="<?= dur_html($backUrl) ?>" class="btn btn-ghost">↩ Annuler</a>
                <button type="button" id="btnRecalcName" class="btn btn-info">🔄 Recalculer le nom</button>
                <button type="submit" name="action" value="commit" class="btn btn-primary"
                        onclick="return confirm('💾 VALIDER & PERSISTER en BDD ?\n\nCela va créer 1 ged_documents + N ged_document_links.\n\nContinuer ?');">
                    ✅ Valider tout (commit atomique)
                </button>
            </div>
        </form>

        <details style="margin-top: 14px; color: var(--muted);">
            <summary style="cursor:pointer; font-size: 10.5px;">🔍 Debug : payload IA + contexte BDD préfetch</summary>
            <pre style="font-size: 10px; background: #0a1424; padding: 8px; border-radius: 3px; overflow-x: auto;"><?= dur_html(json_encode([
                'source'   => $source, 'ctx_type' => $ctxType,
                'bien_id'  => $bienId, 'doc_id' => $docId, 'card_id' => $cardId,
                'contexte_n1' => $contexteN1, 'contexte_raison' => $contexteRaison,
                'resolved_societe_id' => $resolvedSocieteId, 'resolved_agence_id' => $resolvedAgenceId,
                'resolved_source' => $resolvedSource,
                'proprio_id' => $proprietaire['id'] ?? null,
                'tiers_id'   => $proprioTiers['id'] ?? null,
                'bail_actif_id' => $bailActif['id'] ?? null,
                'mandat_actif_id' => $mandatActif['id'] ?? null,
                'annonce_id' => $annonce['id'] ?? null,
                'detected_type' => $detectedType, 'effective_type' => $effectiveType,
                'type_origin' => $typeOrigin,
                'extraction_ia' => $extraction,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>

    </section>
</div>

<script>
(function() {
    'use strict';

    // ─── Arbre GED complet (chargé serveur-side) ──
    const GED_TREE = <?= json_encode($gedTree, JSON_UNESCAPED_UNICODE) ?>;
    const INITIAL = {
        n1: <?= json_encode($contexteN1) ?>,
        n2: <?= json_encode((string)($proposed_n2 ?? '')) ?>,
        n3: <?= json_encode((string)($proposed_n3 ?? '')) ?>,
    };

    // Icônes par slug N1 (mappées sur les slugs ged_folders réels)
    const N1_ICONS = {
        '00_a_classer_ia':         '📥',
        '01_direction':            '⚙️',
        '02_referentiel':          '📚',
        '03_rh':                   '👥',
        '04_syndic':               '🏢',
        '05_gestion_locative':     '🏠',
        '06_transaction':          '🤝',
        '07_comptabilite':         '💰',
        '08_juridique_contentieux':'⚖️',
        '09_marketing_communication':'📣',
        '10_modeles_documents':    '📄',
        '11_mails_communications': '📧',
        '12_archives':             '📦',
        '13_fournisseurs':         '🚚',
        '98_referentiel_tech':     '🔧',
        '99_a_classer_ia':         '🚦',
        '99_parametrage_ged':      '🛠️',
        '99_systeme':              '🖥️',
    };
    // Icônes pour quelques N2/N3 courants (fallback à 📁/📑)
    function n2Icon(slug) {
        if (!slug) return '📁';
        if (slug.includes('mandat')) return '📋';
        if (slug.includes('bail'))   return '🔑';
        if (slug.includes('bien'))   return '🏠';
        if (slug.includes('loyer'))  return '💶';
        if (slug.includes('acte'))   return '✍️';
        if (slug.includes('diagn'))  return '🩺';
        if (slug.includes('assur'))  return '🛡️';
        if (slug.includes('compt'))  return '🧮';
        if (slug.includes('sinistr'))return '⚠️';
        if (slug.includes('sortie')) return '🚪';
        if (slug.includes('caf'))    return '🏛️';
        if (slug.includes('proprio'))return '👤';
        if (slug.includes('fiscal')) return '💸';
        if (slug.includes('acquereur'))return '🤝';
        if (slug.includes('suivi'))  return '📊';
        return '📁';
    }
    function n3Icon(slug) {
        if (!slug) return '📑';
        if (slug.includes('dpe'))      return '⚡';
        if (slug.includes('erp') || slug.includes('ernmt')) return '🌍';
        if (slug.includes('amiante'))  return '🧪';
        if (slug.includes('plomb'))    return '🧱';
        if (slug.includes('elec'))     return '💡';
        if (slug.includes('gaz'))      return '🔥';
        if (slug.includes('termit'))   return '🐛';
        if (slug.includes('carrez'))   return '📐';
        if (slug.includes('assur'))    return '🛡️';
        if (slug.includes('bail'))     return '🔑';
        if (slug.includes('mandat'))   return '📋';
        if (slug.includes('quittance'))return '🧾';
        if (slug.includes('caution'))  return '🤝';
        if (slug.includes('edl'))      return '📸';
        return '📑';
    }

    const n1Row = document.getElementById('gedN1Row');
    const n2Row = document.getElementById('gedN2Row');
    const n3Row = document.getElementById('gedN3Row');
    const n1Hidden = document.getElementById('gedN1Hidden');
    const n2Hidden = document.getElementById('gedN2Hidden');
    const n3Hidden = document.getElementById('gedN3Hidden');
    const folderHidden  = document.getElementById('gedFolderIdHidden');
    const folderResolv  = document.getElementById('gedFolderResolved');
    const folderIdLabel = document.getElementById('gedFolderIdLabel');
    const folderPathLbl = document.getElementById('gedFolderPathLabel');

    function findN1(slug) { return GED_TREE.find(n => n.slug === slug); }
    function findN2(n1Node, slug) { return n1Node ? n1Node.children.find(n => n.slug === slug) : null; }
    function findN3(n2Node, slug) { return n2Node ? n2Node.children.find(n => n.slug === slug) : null; }

    /**
     * Rend une grille de cards dans `container`. Chaque item devient un bouton card.
     * Au clic, met à jour `hiddenInput.value`, marque .is-selected, appelle onChange().
     */
    function renderCardsGrid(container, items, hiddenInput, selectedSlug, iconFn, onChange) {
        container.innerHTML = '';
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="dur-cards-empty">— aucun élément disponible —</div>';
            return;
        }
        items.forEach(it => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dur-card-btn' + (it.slug === selectedSlug ? ' is-selected' : '');
            btn.dataset.slug = it.slug;
            btn.dataset.id   = it.id;
            // Strip préfixe NN_ pour le label affiché
            const labelClean = (it.name || it.slug).replace(/^\d+[a-z]?\s*-\s*/, '');
            btn.innerHTML = `
                <span class="dur-card-icon">${iconFn(it.slug)}</span>
                <span class="dur-card-label">${escapeHtml(labelClean)}</span>
                <span class="dur-card-code">${escapeHtml(it.slug)}</span>
            `;
            btn.addEventListener('click', () => {
                container.querySelectorAll('.dur-card-btn').forEach(b => b.classList.remove('is-selected'));
                btn.classList.add('is-selected');
                hiddenInput.value = it.slug;
                onChange();
            });
            container.appendChild(btn);
        });
    }
    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function refreshFolderResolved() {
        const n1Node = findN1(n1Hidden.value);
        const n2Node = findN2(n1Node, n2Hidden.value);
        const n3Node = findN3(n2Node, n3Hidden.value);
        const folderId = n3Node?.id || n2Node?.id || n1Node?.id || 0;
        folderHidden.value = folderId;
        if (folderId) {
            folderResolv.style.display = '';
            folderIdLabel.textContent = folderId;
            const path = [n1Node?.name, n2Node?.name, n3Node?.name].filter(Boolean).join(' / ');
            folderPathLbl.textContent = path;
        } else {
            folderResolv.style.display = 'none';
        }
    }

    function onN1Click() {
        const n1Node = findN1(n1Hidden.value);
        n2Hidden.value = '';
        n3Hidden.value = '';
        renderCardsGrid(n2Row, n1Node ? n1Node.children : [], n2Hidden, '', n2Icon, onN2Click);
        n3Row.innerHTML = '<div class="dur-cards-empty">— choisir un N2 d\'abord —</div>';
        refreshFolderResolved();
        recomputeName();
    }
    function onN2Click() {
        const n1Node = findN1(n1Hidden.value);
        const n2Node = findN2(n1Node, n2Hidden.value);
        n3Hidden.value = '';
        renderCardsGrid(n3Row, n2Node ? n2Node.children : [], n3Hidden, '', n3Icon, onN3Click);
        refreshFolderResolved();
        recomputeName();
    }
    function onN3Click() {
        refreshFolderResolved();
        recomputeName();
    }

    // ─── INIT cascade au chargement ──
    renderCardsGrid(n1Row, GED_TREE, n1Hidden, INITIAL.n1, (s) => N1_ICONS[s] || '📁', onN1Click);
    n1Hidden.value = INITIAL.n1;
    const initN1 = findN1(INITIAL.n1);
    if (initN1) {
        renderCardsGrid(n2Row, initN1.children, n2Hidden, INITIAL.n2, n2Icon, onN2Click);
        n2Hidden.value = INITIAL.n2;
        const initN2 = findN2(initN1, INITIAL.n2);
        if (initN2) {
            renderCardsGrid(n3Row, initN2.children, n3Hidden, INITIAL.n3, n3Icon, onN3Click);
            n3Hidden.value = INITIAL.n3;
        }
    }
    refreshFolderResolved();

    // ─── Recompute nom V3 ──
    const previewBox = document.getElementById('nameV3PreviewBox');
    const metaBox    = document.getElementById('nameV3MetaBox');
    const hiddenInp  = document.getElementById('nameV3HiddenInput');
    const typeSel    = document.querySelector('select[name="type_doc"]');
    const dateInp    = document.querySelector('input[name="date_doc"]');
    const bienIdInp  = document.querySelector('input[name="link_bien_id"]');

    const docId   = <?= (int)$docId ?>;
    let debounceTimer = null;

    function recomputeName(immediate) {
        clearTimeout(debounceTimer);
        const fn = async () => {
            const params = new URLSearchParams({
                bien_id:  bienIdInp ? bienIdInp.value : '<?= (int)$bienId ?>',
                doc_id:   docId,
                type_doc: typeSel ? typeSel.value : '',
                date_doc: dateInp ? dateInp.value : '',
                n1_slug:  n1Sel.value,
                n2_slug:  n2Sel.value,
            });
            previewBox.style.opacity = '0.5';
            try {
                const r = await fetch('api/ged_doc_naming_preview.php?' + params.toString(), {credentials:'same-origin'});
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const j = await r.json();
                if (j.error) throw new Error(j.error);
                previewBox.textContent = j.name_v3;
                hiddenInp.value = j.name_v3;
                metaBox.innerHTML = 'Format FluxBox V3 · longueur ' + j.name_v3.length + ' chars · <span style="color:var(--ok);">à jour</span>';
            } catch (e) {
                metaBox.innerHTML = 'Format FluxBox V3 · <span style="color:var(--ko);">erreur preview : ' + e.message + '</span>';
            } finally {
                previewBox.style.opacity = '1';
            }
        };
        if (immediate) fn();
        else debounceTimer = setTimeout(fn, 250);
    }

    if (typeSel)   typeSel.addEventListener('change', () => recomputeName());
    if (dateInp)   dateInp.addEventListener('change', () => recomputeName());
    if (bienIdInp) bienIdInp.addEventListener('change', () => recomputeName());

    document.getElementById('btnRecalcName')?.addEventListener('click', () => recomputeName(true));
})();
</script>

</body>
</html>
