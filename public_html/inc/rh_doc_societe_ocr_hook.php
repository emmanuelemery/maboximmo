<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * inc/rh_doc_societe_ocr_hook.php
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Hook OCR Sonnet + réplication automatique vers agences.* pour les
 * documents officiels de la rubrique "Société" uploadés via la page
 * rh_documents.php.
 *
 * Types pris en charge (depuis rh_doc_types) :
 *   kbis              → KBIS (greffe, n° RCS, date d'émission)
 *   carte_pro         → Carte professionnelle CPI (CCI, n°, validité)
 *   garant_financier  → Garantie financière (assureur, n°, plafond, validité)
 *   rcp               → RC pro (assureur, n° contrat, validité)
 *   bareme_honoraires → Barème honoraires (date de mise à jour)
 *
 * Workflow :
 *   1. rh_doc_upload.php uploade le PDF/image et fait l'INSERT dans rh_documents
 *   2. Si rubrique='societe' et type ∈ {5 types} → on hook ici
 *   3. Appel agence_doc_ocr_extraire() → extraction Sonnet → champs structurés
 *   4. UPDATE rh_documents SET numero=..., emetteur=..., date_validite=..., ...
 *   5. Réplication vers TOUTES les agences de cette société :
 *      UPDATE agences SET carte_pro_*, kbis_*, garant_*, etc.
 *      WHERE id_societe = $idSociete
 *
 * Les supports MBI (affiches, fiches, critic_engine) lisent agences.* et
 * trouvent automatiquement les valeurs à jour. Source unique côté société.
 *
 * API publique :
 *   rh_doc_societe_hook_apres_upload(PDO $pdo, int $rhDocId): array
 *     → { ok, ocr_ok, ocr_erreur, type_mappe, agences_repliquees }
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/agence_doc_officiel_ocr.php';

if (!function_exists('rh_doc_societe_hook_apres_upload')) {

    /**
     * Mapping rh_doc_types.type_key → mon type OCR Sonnet (cf. agence_doc_ocr_extraire).
     * Retourne null si le type ne déclenche pas d'OCR.
     */
    function rh_doc_societe_type_to_ocr(string $typeDoc): ?string
    {
        return match ($typeDoc) {
            'kbis'              => 'kbis',
            'carte_pro'         => 'carte_pro',
            'garant_financier'  => 'garant_financier',
            'rcp'               => 'rc_pro',
            'bareme_honoraires' => 'bareme_honoraires',
            default             => null,
        };
    }

    function rh_doc_societe_hook_apres_upload(PDO $pdo, int $rhDocId): array
    {
        $resultat = [
            'ok'                  => false,
            'ocr_ok'              => false,
            'ocr_erreur'          => null,
            'type_mappe'          => null,
            'agences_repliquees'  => 0,
        ];

        // Charge le doc fraîchement uploadé
        try {
            $st = $pdo->prepare("SELECT id, id_societe, categorie, sous_categorie, type_document, file_path
                                 FROM rh_documents WHERE id = :id LIMIT 1");
            $st->execute([':id' => $rhDocId]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $resultat['ocr_erreur'] = 'db_select: ' . $e->getMessage();
            return $resultat;
        }
        if (!$doc) {
            $resultat['ocr_erreur'] = 'doc_introuvable';
            return $resultat;
        }
        if ($doc['categorie'] !== 'societe') {
            // Pas un doc société → on ne fait rien
            return $resultat;
        }

        // Détermine le type OCR depuis sous_categorie ou type_document
        $rhType = (string)($doc['sous_categorie'] ?? $doc['type_document'] ?? '');
        $ocrType = rh_doc_societe_type_to_ocr($rhType);
        if ($ocrType === null) {
            // Type société mais hors scope OCR (ex: convention, assurance_soc, entete)
            return $resultat;
        }
        $resultat['type_mappe'] = $ocrType;

        $filePath = (string)($doc['file_path'] ?? '');
        if ($filePath === '' || !is_file($filePath)) {
            $resultat['ocr_erreur'] = 'fichier_introuvable: ' . $filePath;
            return $resultat;
        }

        // OCR Sonnet
        $ocr = agence_doc_ocr_extraire($filePath, $ocrType);
        $resultat['ocr_ok']     = (bool)($ocr['ok'] ?? false);
        $resultat['ocr_erreur'] = $ocr['erreur'] ?? null;

        // UPDATE rh_documents avec les champs OCR (qu'il y ait succès ou non,
        // pour tracer l'audit). Si OCR KO, ocr_at reste NULL.
        $data = $ocr['data'] ?? [];
        try {
            $up = $pdo->prepare("
                UPDATE rh_documents SET
                  numero            = :numero,
                  emetteur          = :emetteur,
                  montant_garantie  = :montant,
                  date_emission     = :date_em,
                  date_validite     = :date_val,
                  ocr_modele        = :ocr_modele,
                  ocr_confidence    = :ocr_conf,
                  ocr_cout_centimes = :ocr_cout,
                  ocr_json          = :ocr_json,
                  ocr_at            = :ocr_at
                WHERE id = :id
            ");
            $up->execute([
                ':numero'     => $data['numero']    ?? null,
                ':emetteur'   => $data['emetteur']  ?? null,
                ':montant'    => $data['montant_garantie'] ?? null,
                ':date_em'    => $data['date_emission']    ?? null,
                ':date_val'   => $data['date_validite']    ?? null,
                ':ocr_modele' => $ocr['modele'] ?? null,
                ':ocr_conf'   => $ocr['confidence'] ?? 0,
                ':ocr_cout'   => $ocr['cout_centimes'] ?? 0,
                ':ocr_json'   => $ocr['raw_json'] ? json_encode($ocr['raw_json'], JSON_UNESCAPED_UNICODE) : null,
                ':ocr_at'     => $resultat['ocr_ok'] ? date('Y-m-d H:i:s') : null,
                ':id'         => $rhDocId,
            ]);
        } catch (Throwable $e) {
            error_log('[rh_doc_societe_hook UPDATE rh_documents] ' . $e->getMessage());
        }

        // Si OCR OK → réplication vers agences.*
        if ($resultat['ocr_ok']) {
            $idSoc = (int)($doc['id_societe'] ?? 0);
            if ($idSoc > 0) {
                $resultat['agences_repliquees'] = rh_doc_societe_repliquer_vers_agences(
                    $pdo, $idSoc, $rhDocId, $ocrType
                );
            }
        }

        $resultat['ok'] = true;
        return $resultat;
    }

    /**
     * Lit le doc le plus récent (le rh_doc_id qu'on vient d'updater) et
     * UPDATE toutes les agences de la société avec les valeurs OCR.
     *
     * @return int Nombre d'agences mises à jour
     */
    function rh_doc_societe_repliquer_vers_agences(PDO $pdo, int $idSociete, int $rhDocId, string $ocrType): int
    {
        try {
            $st = $pdo->prepare("SELECT numero, emetteur, montant_garantie, date_emission, date_validite, file_path
                                 FROM rh_documents WHERE id = :id LIMIT 1");
            $st->execute([':id' => $rhDocId]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) { return 0; }
        if (!$doc) return 0;

        $patch = [];
        switch ($ocrType) {
            case 'carte_pro':
                $patch['carte_pro_numero']   = $doc['numero'];
                $patch['carte_pro_cci']      = $doc['emetteur'];
                $patch['carte_pro_validite'] = $doc['date_validite'];
                break;
            case 'kbis':
                $patch['kbis_numero'] = $doc['numero'];
                $patch['kbis_date']   = $doc['date_emission'];
                break;
            case 'garant_financier':
                $patch['garant_financier'] = $doc['emetteur'];
                $patch['garant_validite']  = $doc['date_validite'];
                $patch['garant_montant']   = $doc['montant_garantie'];
                break;
            case 'rc_pro':
                $patch['rc_pro']          = $doc['emetteur'];
                $patch['rc_pro_validite'] = $doc['date_validite'];
                break;
            case 'bareme_honoraires':
                if (!empty($doc['file_path'])) {
                    // file_path est absolu, on le convertit en chemin relatif depuis public_html
                    $rel = preg_replace('#^.*?/public_html/#', '/', (string)$doc['file_path']) ?: $doc['file_path'];
                    $patch['bareme_url_doc'] = $rel;
                }
                break;
            default:
                return 0;
        }
        if (empty($patch)) return 0;

        // Filtre les colonnes existantes
        try {
            $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agences'");
            $st->execute();
            $existing = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable) { $existing = []; }
        $patch = array_intersect_key($patch, array_flip($existing));
        if (empty($patch)) return 0;

        try {
            $sets = [];
            $params = [':id_societe' => $idSociete];
            foreach ($patch as $col => $val) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
                $sets[] = "`{$col}` = :v_{$col}";
                $params[":v_{$col}"] = $val;
            }
            $sql = "UPDATE agences SET " . implode(', ', $sets) . " WHERE id_societe = :id_societe";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('[rh_doc_societe_repliquer UPDATE agences] ' . $e->getMessage());
            return 0;
        }
    }
}
