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
        $proposed = fluxbox_va_build_name($resolution['resolved'], $ext);

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
        $proposed = fluxbox_va_build_name($resolved, $ext);

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
