<?php
declare(strict_types=1);

/**
 * FluxBox — orchestrateur du nommage Variante A
 *
 * Pont entre :
 *   - fluxbox_cartes / fluxbox_documents (BDD)
 *   - inc/fluxbox_vision_variant_a.php   (extraction IA)
 *   - inc/fluxbox_naming_variant_a.php   (résolution + build)
 *   - inc/ged_glossary.php               (codes courts)
 *
 * Cycle de vie d'une carte côté nommage :
 *   1. carte créée  → naming_status = 'pending'
 *   2. analyze()    → Vision + matching → status = ready | needs_review
 *      ↳ écrit naming_extracted_json, naming_resolved_json, naming_proposed, naming_status, naming_review_reason
 *   3. admin valide les segments manquants (UI cartes à valider)
 *      ↳ écrit naming_resolved_json, recalcule naming_proposed, status = ready
 *   4. promote_to_ged() lit naming_proposed et l'utilise comme name_canonical
 *      ↳ écrit naming_applied_at
 *
 * API publique :
 *   fluxbox_va_analyze_carte(int $carteId, ?PDO $pdo = null, array $opts = []): array
 *   fluxbox_va_rebuild_name(int $carteId, ?PDO $pdo = null): array
 *   fluxbox_va_get_context_codes(int $userId, ?int $societeId, ?int $agenceId, PDO $pdo): array
 */

require_once __DIR__ . '/ged_glossary.php';
require_once __DIR__ . '/fluxbox_naming_variant_a.php';
require_once __DIR__ . '/fluxbox_vision_variant_a.php';
require_once __DIR__ . '/ged_doc_naming_v3.php'; // V3.1 universel (validé 2026-05-24)

if (!function_exists('fluxbox_va_get_context_codes')) {
    /**
     * Résout les codes courts (société, agence, user) depuis le glossaire.
     *
     * @return array{
     *   societe_code:string,
     *   agence_code:string,
     *   user_code:string,
     *   societe_id:?int,
     *   agence_id:?int,
     *   user_id:?int,
     * }
     */
    function fluxbox_va_get_context_codes(?int $userId, ?int $societeId, ?int $agenceId, PDO $pdo): array
    {
        $ctx = [
            'societe_code' => '',
            'agence_code'  => '',
            'user_code'    => '',
            'societe_id'   => $societeId,
            'agence_id'    => $agenceId,
            'user_id'      => $userId,
        ];

        if ($societeId) {
            $row = ged_glossary_get('societe', (int)$societeId, $pdo);
            if ($row && !empty($row['code'])) $ctx['societe_code'] = (string)$row['code'];
        }
        if ($agenceId) {
            $row = ged_glossary_get('agence', (int)$agenceId, $pdo);
            if ($row && !empty($row['code'])) $ctx['agence_code'] = (string)$row['code'];
        }
        if ($userId) {
            $row = ged_glossary_get('user', (int)$userId, $pdo);
            if ($row && !empty($row['code'])) $ctx['user_code'] = (string)$row['code'];
        }

        return $ctx;
    }
}

if (!function_exists('fluxbox_va_load_carte_with_doc')) {
    /**
     * Charge une carte + son document attaché.
     * @return array|null  ['carte'=>..., 'doc'=>...] ou null si introuvable
     */
    function fluxbox_va_load_carte_with_doc(int $carteId, PDO $pdo): ?array
    {
        $st = $pdo->prepare("
            SELECT c.*, d.fichier_nom AS doc_fichier_nom, d.fichier_chemin AS doc_fichier_chemin,
                   d.mime_type AS doc_mime_type, d.hash_sha256 AS doc_hash
            FROM fluxbox_cartes c
            LEFT JOIN fluxbox_documents d ON d.id = c.document_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $st->execute([$carteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $carte = $row;
        $doc = null;
        if (!empty($row['document_id'])) {
            $doc = [
                'id'             => (int)$row['document_id'],
                'fichier_nom'    => (string)$row['doc_fichier_nom'],
                'fichier_chemin' => (string)$row['doc_fichier_chemin'],
                'mime_type'      => (string)$row['doc_mime_type'],
                'hash_sha256'    => (string)$row['doc_hash'],
            ];
        }
        // Nettoie les colonnes jointes du tableau carte
        foreach (['doc_fichier_nom','doc_fichier_chemin','doc_mime_type','doc_hash'] as $k) unset($carte[$k]);

        return ['carte' => $carte, 'doc' => $doc];
    }
}

if (!function_exists('fluxbox_va_analyze_carte')) {
    /**
     * Lance Vision + matching glossaire pour une carte et persiste le résultat.
     *
     * @param array $opts {
     *   modele?: string,            // 'haiku'|'sonnet'|model_id (défaut Sonnet)
     *   skip_vision?: bool,         // true = ne pas rappeler Vision si déjà extracted
     *   n1_code?: string,           // override n1 (sinon depuis proposition_json)
     *   n2_code?: string,           // override n2
     *   force?: bool,               // ignore le statut applied et recalcule
     * }
     * @return array {
     *   ok:bool,
     *   carte_id:int,
     *   naming_status:string,
     *   naming_review_reason:?string,
     *   naming_proposed:?string,
     *   missing:array,
     *   confidence:int,
     *   modele:?string,
     *   cout_centimes:int,
     *   erreur:?string,
     * }
     */
    function fluxbox_va_analyze_carte(int $carteId, ?PDO $pdo = null, array $opts = []): array
    {
        if ($pdo === null) $pdo = ged_glossary_pdo();

        $loaded = fluxbox_va_load_carte_with_doc($carteId, $pdo);
        if ($loaded === null) {
            return ['ok'=>false,'carte_id'=>$carteId,'naming_status'=>'pending','naming_review_reason'=>null,
                    'naming_proposed'=>null,'missing'=>[],'confidence'=>0,'modele'=>null,'cout_centimes'=>0,
                    'erreur'=>'carte_introuvable'];
        }
        $carte = $loaded['carte'];
        $doc   = $loaded['doc'];

        // Si déjà appliqué et pas de force, ne pas refaire
        if (!empty($carte['naming_applied_at']) && empty($opts['force'])) {
            return ['ok'=>true,'carte_id'=>$carteId,
                    'naming_status'=>(string)$carte['naming_status'],
                    'naming_review_reason'=>$carte['naming_review_reason'],
                    'naming_proposed'=>$carte['naming_proposed'],
                    'missing'=>[],'confidence'=>0,'modele'=>null,'cout_centimes'=>0,
                    'erreur'=>'already_applied'];
        }

        // 1. Extraction Vision (sauf si skip_vision et qu'on a déjà un extracted_json)
        $extracted = null;
        $modele = null;
        $cost = 0;
        $confidence = 0;

        if (!empty($opts['skip_vision']) && !empty($carte['naming_extracted_json'])) {
            $extracted = json_decode((string)$carte['naming_extracted_json'], true);
        } elseif ($doc !== null && $doc['fichier_chemin'] !== '') {
            $vres = fluxbox_vision_extract_variant_a(
                (string)$doc['fichier_chemin'],
                $opts['modele'] ?? null
            );
            if (!$vres['ok']) {
                return ['ok'=>false,'carte_id'=>$carteId,
                        'naming_status'=>'pending','naming_review_reason'=>null,
                        'naming_proposed'=>null,'missing'=>[],'confidence'=>0,
                        'modele'=>$vres['modele'],'cout_centimes'=>$vres['cout_centimes'],
                        'erreur'=>'vision_failed: ' . (string)$vres['erreur']];
            }
            $extracted = $vres['extracted'];
            $modele = $vres['modele'];
            $cost = (int)$vres['cout_centimes'];
            $confidence = (int)$vres['confidence'];
        } else {
            return ['ok'=>false,'carte_id'=>$carteId,'naming_status'=>'pending','naming_review_reason'=>null,
                    'naming_proposed'=>null,'missing'=>[],'confidence'=>0,'modele'=>null,'cout_centimes'=>0,
                    'erreur'=>'no_document_attached'];
        }

        // 2. Contexte applicatif (codes session + n1/n2 depuis proposition)
        $tenantId = (int)$carte['tenant_id'];
        $userId   = isset($carte['created_by']) ? (int)$carte['created_by'] : null;

        $societeId = null;
        $agenceId  = null;
        if ($userId) {
            $stU = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ? LIMIT 1");
            $stU->execute([$userId]);
            $u = $stU->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $societeId = !empty($u['id_societe']) ? (int)$u['id_societe'] : null;
                $agenceId  = !empty($u['id_agence'])  ? (int)$u['id_agence']  : null;
            }
        }

        $ctxCodes = fluxbox_va_get_context_codes($userId, $societeId, $agenceId, $pdo);

        // n1/n2 depuis proposition_json (ou opts override)
        $n1 = (string)($opts['n1_code'] ?? '');
        $n2 = (string)($opts['n2_code'] ?? '');
        if ($n1 === '' || $n2 === '') {
            $prop = !empty($carte['proposition_json']) ? json_decode((string)$carte['proposition_json'], true) : null;
            if (is_array($prop) && isset($prop['classement']) && is_array($prop['classement'])) {
                if ($n1 === '') $n1 = (string)($prop['classement']['n1'] ?? '');
                if ($n2 === '') $n2 = (string)($prop['classement']['n2'] ?? '');
            }
        }

        $ctx = [
            'societe_code' => $ctxCodes['societe_code'],
            'agence_code'  => $ctxCodes['agence_code'],
            'user_code'    => $ctxCodes['user_code'],
            'n1_code'      => $n1,
            'n2_code'      => $n2,
        ];

        // 3. Résolution glossaire
        $resolution = fluxbox_va_resolve_extracted($extracted ?: [], $ctx, $pdo);

        // 4. Construction du nom proposé
        $ext = pathinfo((string)($doc['fichier_nom'] ?? ''), PATHINFO_EXTENSION) ?: 'pdf';
        $proposedLegacy = fluxbox_va_build_name($resolution['resolved'], $ext);

        // ─── NEW V3.1 (validé Emery 2026-05-24 / Fix P0-1 2026-05-26) ──
        // On utilise V3.1 DÈS QU'IL RETOURNE UN NOM, même sans entity (segment 10 = '-').
        // Sinon les 35 docs récents tombent sur le legacy "SOC_AGE_7000005" qui est cassé.
        $v3_1 = fluxbox_va_compute_v3_1_name($carteId, $pdo);
        $proposed = !empty($v3_1['name']) ? $v3_1['name'] : $proposedLegacy;

        // 5. Persiste
        $stUpd = $pdo->prepare("
            UPDATE fluxbox_cartes SET
                naming_extracted_json = :ext_json,
                naming_resolved_json  = :res_json,
                naming_status         = :status,
                naming_review_reason  = :reason,
                naming_proposed       = :name
            WHERE id = :id
        ");
        $stUpd->execute([
            ':ext_json' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
            ':res_json' => json_encode($resolution['resolved'], JSON_UNESCAPED_UNICODE),
            ':status'   => $resolution['status'],
            ':reason'   => $resolution['review_reason'],
            ':name'     => $proposed,
            ':id'       => $carteId,
        ]);

        return [
            'ok'                  => true,
            'carte_id'            => $carteId,
            'naming_status'       => $resolution['status'],
            'naming_review_reason'=> $resolution['review_reason'],
            'naming_proposed'     => $proposed,
            'missing'             => $resolution['missing'],
            'confidence'          => $confidence,
            'modele'              => $modele,
            'cout_centimes'       => $cost,
            'erreur'              => null,
        ];
    }
}

if (!function_exists('fluxbox_va_rebuild_name')) {
    /**
     * Recalcule le nom proposé depuis le naming_resolved_json existant
     * (après que l'admin a complété un segment manquant).
     * Ne rappelle PAS Vision.
     */
    function fluxbox_va_rebuild_name(int $carteId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_glossary_pdo();

        $st = $pdo->prepare("SELECT * FROM fluxbox_cartes WHERE id = ? LIMIT 1");
        $st->execute([$carteId]);
        $carte = $st->fetch(PDO::FETCH_ASSOC);
        if (!$carte) {
            return ['ok'=>false,'carte_id'=>$carteId,'erreur'=>'carte_introuvable',
                    'naming_status'=>'pending','naming_proposed'=>null];
        }

        $resolved = !empty($carte['naming_resolved_json'])
            ? (json_decode((string)$carte['naming_resolved_json'], true) ?: [])
            : [];

        // Re-check si tout est rempli
        $missing = [];
        if (empty($resolved['banque_code']))     $missing[] = 'banque_unknown';
        if (empty($resolved['compte4']))         $missing[] = 'compte4_missing';
        if (empty($resolved['periode_mm_yyyy'])) $missing[] = 'periode_missing';
        // immeuble optionnel selon contexte — on ne le force pas ici

        $status = empty($missing) ? 'ready' : 'needs_review';
        $reason = empty($missing) ? null : (count($missing) === 1 ? $missing[0] : 'multiple');

        // Nom proposé
        $stDoc = $pdo->prepare("SELECT fichier_nom FROM fluxbox_documents WHERE id = ? LIMIT 1");
        $stDoc->execute([(int)($carte['document_id'] ?? 0)]);
        $docNom = (string)($stDoc->fetchColumn() ?: '');
        $ext = pathinfo($docNom, PATHINFO_EXTENSION) ?: 'pdf';
        $proposedLegacy = fluxbox_va_build_name($resolved, $ext);
        // Fix P0-1 (2026-05-26) : V3.1 prioritaire même sans entité
        $v3_1 = fluxbox_va_compute_v3_1_name($carteId, $pdo);
        $proposed = !empty($v3_1['name']) ? $v3_1['name'] : $proposedLegacy;

        $stUpd = $pdo->prepare("
            UPDATE fluxbox_cartes SET
                naming_status        = :status,
                naming_review_reason = :reason,
                naming_proposed      = :name
            WHERE id = :id
        ");
        $stUpd->execute([
            ':status' => $status,
            ':reason' => $reason,
            ':name'   => $proposed,
            ':id'     => $carteId,
        ]);

        return [
            'ok' => true,
            'carte_id' => $carteId,
            'naming_status' => $status,
            'naming_review_reason' => $reason,
            'naming_proposed' => $proposed,
            'missing' => $missing,
        ];
    }
}

if (!function_exists('fluxbox_va_detect_n1_from_context')) {
    /**
     * Détection N1 métier contextuelle pour une entité métier donnée.
     * Aligné sur la logique de bien_doc_360.php (validée 2026-05-24).
     */
    function fluxbox_va_detect_n1_from_context(?string $entityType, ?int $entityId, PDO $pdo): array {
        $contexteN1 = '05_gestion_locative';
        $raison = 'défaut (pas de signal)';
        if (!$entityType || !$entityId) return ['n1' => $contexteN1, 'raison' => $raison];
        $type = strtoupper($entityType);
        if ($type === 'BIEN') {
            // bail actif → gestion
            try {
                $st = $pdo->prepare("SELECT id FROM baux WHERE id_bien = ? AND statut = 'actif' ORDER BY id DESC LIMIT 1");
                $st->execute([$entityId]);
                if ($id = $st->fetchColumn()) return ['n1' => '05_gestion_locative', 'raison' => 'bail actif #' . $id];
            } catch (Throwable) {}
            // mandat actif
            try {
                $st = $pdo->prepare("SELECT id, type_mandat, nature_mandat FROM mandats WHERE id_bien = ? ORDER BY (statut IN ('actif','en_cours','signe')) DESC, date_signature DESC LIMIT 1");
                $st->execute([$entityId]);
                $m = $st->fetch(PDO::FETCH_ASSOC);
                if ($m) {
                    $low = strtolower(($m['type_mandat'] ?? '') . ' ' . ($m['nature_mandat'] ?? ''));
                    if (str_contains($low, 'gestion') || str_contains($low, 'location') || str_contains($low, 'gerance')) {
                        return ['n1' => '05_gestion_locative', 'raison' => 'mandat gestion #' . $m['id']];
                    }
                    if (str_contains($low, 'vente') || str_contains($low, 'exclusif')) {
                        return ['n1' => '06_transaction', 'raison' => 'mandat vente #' . $m['id']];
                    }
                }
            } catch (Throwable) {}
            // annonce vente sans mandat
            try {
                $st = $pdo->prepare("SELECT id, type_transaction FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
                $st->execute([$entityId]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                if ($a && strtolower((string)$a['type_transaction']) === 'vente') {
                    return ['n1' => '06_transaction', 'raison' => 'annonce vente #' . $a['id']];
                }
            } catch (Throwable) {}
        } elseif ($type === 'IMB' || $type === 'IMMEUBLE') {
            // « syndic » n'est qu'un rôle : par défaut un doc d'immeuble se classe sur l'immeuble (gestion).
            return ['n1' => '05_gestion_locative', 'raison' => 'doc immeuble'];
        } elseif ($type === 'TIERS') {
            return ['n1' => '02_referentiel', 'raison' => 'doc tiers'];
        } elseif ($type === 'BAIL') {
            return ['n1' => '05_gestion_locative', 'raison' => 'doc bail (gestion)'];
        } elseif ($type === 'MANDAT' || $type === 'MDT') {
            return ['n1' => '06_transaction', 'raison' => 'doc mandat'];
        } elseif ($type === 'CREANCIER_DOSSIER') {
            return ['n1' => '12_contentieux', 'raison' => 'dossier créancier'];
        }
        return ['n1' => $contexteN1, 'raison' => $raison];
    }
}

if (!function_exists('fluxbox_va_detect_type_from_filename')) {
    /**
     * Détection type_doc par mots-clés du nom de fichier (avant IA).
     * Aligné sur bien_doc_360.php.
     */
    function fluxbox_va_detect_type_from_filename(string $fileName): string {
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
}

if (!function_exists('fluxbox_va_refine_ia_type')) {
    /**
     * Raffine le type_doc IA générique (diagnostic/document) via le label.
     * Aligné sur bien_doc_360.php.
     */
    function fluxbox_va_refine_ia_type(array $ext): string {
        $raw = strtolower(trim((string)($ext['type_doc'] ?? '')));
        $label = strtoupper((string)($ext['type_doc_label'] ?? '') . ' ' . ($ext['titre_court'] ?? ''));
        $generic = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
        if (!in_array($raw, $generic, true)) return (string)preg_replace('/[^a-z_]/', '', $raw);
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
}

if (!function_exists('fluxbox_va_compute_v3_1_name')) {
    /**
     * Calcule le nom V3.1 (11 segments) pour une carte FluxBox.
     * Détecte automatiquement entité (BIEN/IMB/TIERS), N1 contextuel et type.
     *
     * @return array{name: ?string, ctx: array, n1: string, type: string, entity_type: string, entity_id: ?int}
     */
    function fluxbox_va_compute_v3_1_name(int $carteId, PDO $pdo): array {
        $loaded = fluxbox_va_load_carte_with_doc($carteId, $pdo);
        if (!$loaded || !$loaded['carte']) {
            return ['name' => null, 'ctx' => [], 'n1' => '', 'type' => '', 'entity_type' => '', 'entity_id' => null];
        }
        $carte = $loaded['carte'];
        $doc   = $loaded['doc'];
        $fileName = (string)($doc['fichier_nom'] ?? '');

        // ─── 1. Détecter entité depuis proposition_json ou source_meta du doc ──
        $entityType = null; $entityId = null; $forcedTypeDoc = '';
        $prop = !empty($carte['proposition_json']) ? json_decode((string)$carte['proposition_json'], true) : null;
        if (is_array($prop)) {
            if (!empty($prop['forced_type_doc'])) $forcedTypeDoc = (string)$prop['forced_type_doc'];
            // Contexte créancier imposé (depuis le cockpit) → prioritaire.
            if (!empty($prop['creancier_dossier_id'])) { $entityType = 'CREANCIER_DOSSIER'; $entityId = (int)$prop['creancier_dossier_id']; }
            elseif (!empty($prop['bail_id']))     { $entityType = 'BAIL'; $entityId = (int)$prop['bail_id']; }
            elseif (!empty($prop['bien_id']))     { $entityType = 'BIEN';  $entityId = (int)$prop['bien_id']; }
            elseif (!empty($prop['immeuble_id'])) { $entityType = 'IMB'; $entityId = (int)$prop['immeuble_id']; }
            elseif (!empty($prop['tiers_id']))    { $entityType = 'TIERS'; $entityId = (int)$prop['tiers_id']; }
        }
        // Fallback : source_meta du document
        if (!$entityType && $doc && !empty($doc['id'])) {
            try {
                $st = $pdo->prepare("SELECT source_meta FROM fluxbox_documents WHERE id = ?");
                $st->execute([(int)$doc['id']]);
                $meta = json_decode((string)$st->fetchColumn(), true);
                if (is_array($meta)) {
                    if ($forcedTypeDoc === '' && !empty($meta['forced_type_doc'])) $forcedTypeDoc = (string)$meta['forced_type_doc'];
                    if (!empty($meta['creancier_dossier_id'])) { $entityType = 'CREANCIER_DOSSIER'; $entityId = (int)$meta['creancier_dossier_id']; }
                    elseif (!empty($meta['bail_id']))     { $entityType = 'BAIL'; $entityId = (int)$meta['bail_id']; }
                    elseif (!empty($meta['bien_id']))     { $entityType = 'BIEN';  $entityId = (int)$meta['bien_id']; }
                    elseif (!empty($meta['immeuble_id'])) { $entityType = 'IMB'; $entityId = (int)$meta['immeuble_id']; }
                    elseif (!empty($meta['tiers_id']))    { $entityType = 'TIERS'; $entityId = (int)$meta['tiers_id']; }
                }
            } catch (Throwable) {}
        }

        // ─── 1b. [2026-05-25] Fallback / Upgrade : matching IA adresse → bien ──
        // 2 cas :
        //   A. Pas d'entité → tente matching IA adresse → entité=BIEN si match ≥ 70
        //   B. Entité=TIERS (mode dossier proprio) → tente matching IA bien chez ce tiers
        //      → upgrade vers entité=BIEN (plus précis) si match ≥ 70. Tiers conservé pour link.
        $tiersIdContext = ($entityType === 'TIERS') ? $entityId : null; // pour conserver le link tiers
        if ((!$entityType || $entityType === 'TIERS') && $doc && !empty($doc['hash_sha256'])) {
            try {
                $st = $pdo->prepare("SELECT response_json FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
                $st->execute([(string)$doc['hash_sha256']]);
                $iaExt = json_decode((string)$st->fetchColumn(), true) ?: [];
                $iaAdr = (string)($iaExt['adresse_bien'] ?? $iaExt['adresse'] ?? '');
                $iaCp  = (string)($iaExt['code_postal']  ?? '');
                $iaCity = (string)($iaExt['ville']       ?? '');
                if ($iaAdr !== '' || $iaCity !== '') {
                    require_once __DIR__ . '/entity_matcher.php';
                    $m = em_match_bien($pdo, [
                        'adresse'      => $iaAdr,
                        'code_postal'  => $iaCp,
                        'ville'        => $iaCity,
                        'numero_mandat'=> (string)($iaExt['numero_mandat'] ?? ''),
                    ]);
                    if (!empty($m['found']) && (int)($m['confidence'] ?? 0) >= 70 && !empty($m['best']['id'])) {
                        // Si mode dossier proprio (tiers prefill) : vérifier que le bien matché
                        // appartient bien au tiers du prefill — sinon ignorer (faux positif probable)
                        $bienId = (int)$m['best']['id'];
                        $accept = true;
                        if ($tiersIdContext) {
                            $stCheck = $pdo->prepare("SELECT 1 FROM biens b LEFT JOIN proprietaires p ON p.id = b.id_proprietaire WHERE b.id = ? AND (b.id_tiers = ? OR p.id_tiers = ?) LIMIT 1");
                            $stCheck->execute([$bienId, $tiersIdContext, $tiersIdContext]);
                            $accept = (bool)$stCheck->fetchColumn();
                        }
                        if ($accept) {
                            $entityType = 'BIEN';
                            $entityId   = $bienId;
                        }
                    }
                }
            } catch (Throwable) {}
        }

        // ─── 2. N1 contextuel ──
        $n1Detect = fluxbox_va_detect_n1_from_context($entityType, $entityId, $pdo);
        $contexteN1 = $n1Detect['n1'];

        // ─── 3. Société/agence depuis l'entité (cascade) ──
        $resolvedSocieteId = 0; $resolvedAgenceId = 0;
        if ($entityType === 'BIEN' && $entityId) {
            try {
                $st = $pdo->prepare("SELECT b.id_societe, b.id_agence, i.id_societe AS imm_soc, i.id_agence AS imm_age
                    FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ?");
                $st->execute([$entityId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $resolvedSocieteId = (int)($r['id_societe'] ?? 0) ?: (int)($r['imm_soc'] ?? 0);
                $resolvedAgenceId  = (int)($r['id_agence']  ?? 0) ?: (int)($r['imm_age'] ?? 0);
            } catch (Throwable) {}
        } elseif (($entityType === 'IMB' || $entityType === 'IMMEUBLE') && $entityId) {
            try {
                $st = $pdo->prepare("SELECT id_societe, id_agence FROM immeubles WHERE id = ?");
                $st->execute([$entityId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $resolvedSocieteId = (int)($r['id_societe'] ?? 0);
                $resolvedAgenceId  = (int)($r['id_agence']  ?? 0);
            } catch (Throwable) {}
        } elseif ($entityType === 'TIERS' && $entityId) {
            try {
                $st = $pdo->prepare("SELECT id_societe, id_agence FROM tiers WHERE id = ?");
                $st->execute([$entityId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $resolvedSocieteId = (int)($r['id_societe'] ?? 0);
                $resolvedAgenceId  = (int)($r['id_agence']  ?? 0);
            } catch (Throwable) {}
        } elseif ($entityType === 'BAIL' && $entityId) {
            try {
                $st = $pdo->prepare("SELECT b.id_societe, b.id_agence, i.id_societe AS imm_soc, i.id_agence AS imm_age
                    FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien
                    LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE bb.id = ?");
                $st->execute([$entityId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $resolvedSocieteId = (int)($r['id_societe'] ?? 0) ?: (int)($r['imm_soc'] ?? 0);
                $resolvedAgenceId  = (int)($r['id_agence']  ?? 0) ?: (int)($r['imm_age'] ?? 0);
            } catch (Throwable) {}
        } elseif ($entityType === 'CREANCIER_DOSSIER' && $entityId) {
            try {
                $st = $pdo->prepare("SELECT id_societe, id_agence FROM creancier_dossier WHERE id = ?");
                $st->execute([$entityId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $resolvedSocieteId = (int)($r['id_societe'] ?? 0);
                $resolvedAgenceId  = (int)($r['id_agence']  ?? 0);
            } catch (Throwable) {}
        }
        // Défaut REGIE EMERY LYON si rien
        if ($resolvedSocieteId === 0) { $resolvedSocieteId = 1; $resolvedAgenceId = 3; }

        // ─── 4. Libellés société/agence/user ──
        $societeRaison = ''; $agenceCode = null; $agenceNom = '';
        try {
            $st = $pdo->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
            $st->execute([$resolvedSocieteId]);
            $societeRaison = (string)$st->fetchColumn();
        } catch (Throwable) {}
        try {
            $st = $pdo->prepare("SELECT code_agence, nom_agence FROM agences WHERE id = ?");
            $st->execute([$resolvedAgenceId]);
            $a = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $agenceCode = $a['code_agence'] ?? null;
            $agenceNom  = (string)($a['nom_agence']  ?? '');
        } catch (Throwable) {}

        $userId = isset($carte['created_by']) ? (int)$carte['created_by'] : null;
        $userMatricule = null; $userUsername = '';
        if ($userId) {
            try {
                $st = $pdo->prepare("SELECT matricule_paie, username FROM users WHERE id = ?");
                $st->execute([$userId]);
                $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $userMatricule = $u['matricule_paie'] ?? null;
                $userUsername  = (string)($u['username']       ?? '');
            } catch (Throwable) {}
        }

        // ─── 5. Type doc : détecté nom + IA raffinée ──
        $detectedType = fluxbox_va_detect_type_from_filename($fileName);
        $extracted = !empty($carte['naming_extracted_json'])
            ? (json_decode((string)$carte['naming_extracted_json'], true) ?: [])
            : [];
        // Compléter avec ia_extract_cache si dispo (Sonnet retour structuré)
        if (empty($extracted) && $doc && !empty($doc['hash_sha256'])) {
            try {
                $st = $pdo->prepare("SELECT response_json, confidence FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
                $st->execute([$doc['hash_sha256']]);
                $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($c['response_json'])) {
                    $extracted = json_decode((string)$c['response_json'], true) ?: [];
                    $extracted['__confidence'] = (int)$c['confidence'];
                }
            } catch (Throwable) {}
        }
        $iaType = fluxbox_va_refine_ia_type($extracted);
        $iaConf = (int)($extracted['__confidence'] ?? $extracted['confidence'] ?? 0);
        $generic = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
        if ($iaType === '' && $detectedType !== '') $effectiveType = $detectedType;
        elseif (in_array($iaType, $generic, true) && $detectedType !== '') $effectiveType = $detectedType;
        elseif ($iaType !== '' && $iaConf >= 50) $effectiveType = $iaType;
        else $effectiveType = $detectedType ?: $iaType;

        // Type pré-déclaré par l'utilisateur (boutons « Documents fréquents ») → prioritaire.
        if ($forcedTypeDoc !== '') $effectiveType = $forcedTypeDoc;

        // ─── 6. N2/N3 GED depuis mapping ──
        $gedMappingTransaction = [
            'compromis' => ['02_acte', '01_compromis'], 'promesse' => ['02_acte', '01_compromis'],
            'acte_authentique' => ['02_acte', '02_acte_authentique'],
            'mandat_exclusif' => ['01_mandat_vente', '01_mandat_exclusif'],
            'mandat_simple' => ['01_mandat_vente', '02_mandat_simple'],
            'mandat_vente' => ['01_mandat_vente', '01_mandat_exclusif'],
            'mandat' => ['01_mandat_vente', '01_mandat_exclusif'],
            'offre_achat' => ['02_acte', '01_compromis'],
            'acte_vente' => ['02_acte', '02_acte_authentique'],
            'estimation' => ['01_mandat_vente', '01_mandat_exclusif'],
            'dpe' => ['03_diagnostics_transaction', '01_dpe'],
            'erp_ernmt' => ['03_diagnostics_transaction', '02_erp_ernmt'],
        ];
        // NB : slugs alignés sur les dossiers seedés par admin_ged_seed_quicktypes.php.
        // Le N1 réel vient de detect_n1 (tiers→02_referentiel, immeuble→04_syndic, sinon gestion).
        $gedMappingGestion = [
            // Tiers / propriétaire (N1 02_referentiel)
            'mandat_gestion' => ['01_tiers', '04_mandat_gestion'],
            'mandat_simple'  => ['01_tiers', '04_mandat_gestion'],
            'piece_identite' => ['01_tiers', '01_piece_identite'],
            'cni'            => ['01_tiers', '01_piece_identite'],
            'passeport'      => ['01_tiers', '01_piece_identite'],
            'titre_sejour'   => ['01_tiers', '01_piece_identite'],
            'rib'            => ['01_tiers', '02_rib'],
            'attestation_propriete' => ['01_tiers', '03_attestation_propriete'],
            // Bail (N1 05_gestion_locative)
            'bail_signe' => ['02_bail', '01_bail_signe'],
            'bail'       => ['02_bail', '01_bail_signe'],
            'bail_projet'=> ['02_bail', '01_bail_signe'],
            'demande_renouvellement' => ['02_bail', '01_bail_signe'],
            'edl_entree' => ['02_bail', '02_edl_entree'],
            'etat_lieux' => ['02_bail', '02_edl_entree'],
            'caution_garant' => ['02_bail', '03_caution_garant'],
            'edl_sortie' => ['02_bail', '04_edl_sortie'],
            // Tiers — types génériques rattachés au preneur/propriétaire
            'mandat'          => ['01_tiers', '04_mandat_gestion'],
            'kbis'            => ['01_tiers', '03_attestation_propriete'],
            'attestation'     => ['01_tiers', '03_attestation_propriete'],
            'releve_bancaire' => ['01_tiers', '02_rib'],
            'courrier'        => ['01_tiers', '03_attestation_propriete'],
            'jugement'        => ['01_tiers', '03_attestation_propriete'],
            'cv'              => ['01_tiers', '01_piece_identite'],
            // Bien — diagnostics & taxe (N1 05_gestion_locative)
            'dpe' => ['03_bien', '01_dpe'], 'erp_ernmt' => ['03_bien', '02_erp_ernmt'],
            'diagnostic_amiante' => ['03_bien', '03_amiante'],
            'diagnostic_plomb' => ['03_bien', '04_plomb_crep'],
            'diagnostic_elec' => ['03_bien', '05_electricite'],
            'diagnostic_gaz' => ['03_bien', '06_gaz'],
            'diagnostic_termites' => ['03_bien', '07_termites'],
            'surface_carrez' => ['03_bien', '08_surface_carrez'],
            'attestation_assurance' => ['03_bien', '09_assurance_proprietaire'],
            'taxe_fonciere' => ['03_bien', '10_taxe_fonciere'],
            // Loyers (N1 05_gestion_locative)
            'quittance' => ['03_loyers', '01_quittance'],
            'avis_echeance' => ['03_loyers', '02_avis_echeance'],
            // Immeuble (N1 05_gestion_locative — syndic = simple rôle)
            'reglement_copro'  => ['04_immeuble', '01_reglement_copro'],
            'pv_ag'            => ['04_immeuble', '02_pv_ag'],
            'carnet_entretien' => ['04_immeuble', '03_carnet_entretien'],
            'dtg'              => ['04_immeuble', '04_dtg'],
            'fiche_immeuble'   => ['04_immeuble', '05_fiche_immeuble'],
            'contrat'          => ['04_immeuble', '06_contrat'],
        ];
        $map = ($contexteN1 === '06_transaction') ? $gedMappingTransaction : $gedMappingGestion;
        // Recherche insensible à la casse (le type forcé peut arriver en majuscules : « CNI »).
        // Fallback générique « à classer » : un type non mappé ne bloque JAMAIS la validation
        // (il atterrit dans un dossier de tri, on affine ensuite). Fin des « N2/N3 requis ».
        [$n2Slug, $n3Slug] = $map[$effectiveType] ?? ($map[strtolower((string)$effectiveType)] ?? ['00_divers', '00_a_classer']);

        // ─── 7. Date doc depuis IA ──
        $iaDate = (string)($extracted['date_signature'] ?? $extracted['date_doc'] ?? $extracted['date_debut_bail'] ?? '');
        // Remplissage sûr du segment 9 (date du doc) : si l'IA n'a rien, on reprend la date
        // DÉTECTÉE à l'ingest (proposition_json.classement.date / target_date) — évite le « - ».
        if ($iaDate === '') {
            $propJson = json_decode((string)($carte['proposition_json'] ?? ''), true);
            if (is_array($propJson)) {
                $iaDate = (string)($propJson['classement']['date'] ?? $propJson['target_date'] ?? '');
            }
        }

        // ─── 8. BUILD V3.1 ──
        $ctx = [
            'societe_raison'  => $societeRaison,
            'agence_code'     => $agenceCode,
            'agence_nom'      => $agenceNom,
            'user_matricule'  => $userMatricule,
            'user_username'   => $userUsername,
            'user_id'         => $userId,
            'upload_date'     => 'now',
            'n1_slug'         => $contexteN1,
            'n2_slug'         => $n2Slug,
            'n3_slug'         => $n3Slug,
            'n4_slug'         => null,
            'date_doc'        => $iaDate,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'type_doc'        => $effectiveType ?: 'document',
            'source_filename' => $fileName,
        ];
        $name = gdn_v3_build($ctx);
        return ['name' => $name, 'ctx' => $ctx, 'n1' => $contexteN1, 'type' => $effectiveType, 'entity_type' => $entityType ?? '', 'entity_id' => $entityId];
    }
}

if (!function_exists('fluxbox_va_mark_applied')) {
    /**
     * Marque le nommage comme appliqué (appelé par fluxbox_promote_to_ged après
     * écriture de ged_documents).
     */
    function fluxbox_va_mark_applied(int $carteId, ?PDO $pdo = null): void
    {
        if ($pdo === null) $pdo = ged_glossary_pdo();
        $pdo->prepare("
            UPDATE fluxbox_cartes
            SET naming_status = 'applied', naming_applied_at = NOW()
            WHERE id = ? AND naming_status IN ('ready','needs_review','extracted')
        ")->execute([$carteId]);
    }
}
