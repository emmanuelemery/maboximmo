<?php
declare(strict_types=1);
/**
 * inc/erp_ingest.php — Ingestion AUTOMATIQUE du rapport ERP (État des Risques et
 * Pollutions, Géorisques) dans la GED centrale, lors de la recherche des données
 * publiques d'un immeuble.
 *
 * Source officielle gratuite :
 *   https://georisques.gouv.fr/api/v1/rapport_pdf?latlon=LNG,LAT
 *
 * Idempotent : gus_commit_document() déduplique par hash SHA-256 — ré-appeler
 * sur le même immeuble ne crée pas de doublon (il rattache juste les liens manquants).
 *
 * @return array { ok:bool, doc_id?:int, deduplicated?:bool, skipped?:bool, error?:string }
 */

require_once __DIR__ . '/ged_document_links.php';

if (!function_exists('erp_ingest_to_ged')) {
    /**
     * @param PDO   $pdo
     * @param int   $immeubleId  immeuble cible (lien GED principal)
     * @param float $lat
     * @param float $lng
     * @param array $opts  ['bien_id'=>int, 'user_id'=>int, 'societe_id'=>int, 'agence_id'=>int]
     */
    function erp_ingest_to_ged(PDO $pdo, int $immeubleId, float $lat, float $lng, array $opts = []): array
    {
        if ($immeubleId <= 0 || $lat == 0.0 || $lng == 0.0) {
            return ['ok' => false, 'skipped' => true, 'error' => 'immeuble/lat/lng manquants'];
        }

        // 1. Téléchargement du rapport officiel (Géorisques)
        $url = 'https://georisques.gouv.fr/api/v1/rapport_pdf?latlon=' . $lng . ',' . $lat;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['Accept: application/pdf'],
        ]);
        $pdf  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($pdf === false || $code !== 200 || stripos($type, 'pdf') === false || strlen($pdf) < 1000) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Rapport ERP indisponible (Géorisques)'];
        }

        // 2. Stockage disque
        $uploadDir = dirname(__DIR__) . '/uploads/immeubles_docs/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
        $safeName  = 'imm_' . $immeubleId . '_erp_' . date('Ymd') . '_' . substr(hash('sha256', $pdf), 0, 8) . '.pdf';
        $destPath  = $uploadDir . $safeName;
        $publicUrl = '/uploads/immeubles_docs/' . $safeName;
        if (@file_put_contents($destPath, $pdf) === false) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Écriture disque ERP impossible'];
        }

        // 3. Contexte société/agence depuis l'immeuble (cohérence cascade GED)
        $immCtx = [];
        try {
            $st = $pdo->prepare("SELECT i.id_societe, i.id_agence, i.nom_immeuble,
                                        s.raison_sociale AS soc_raison, a.code_agence, a.nom_agence
                                 FROM immeubles i
                                 LEFT JOIN societes s ON s.id = i.id_societe
                                 LEFT JOIN agences  a ON a.id = i.id_agence
                                 WHERE i.id = ? LIMIT 1");
            $st->execute([$immeubleId]);
            $immCtx = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {}

        $socId  = (int)($immCtx['id_societe'] ?? 0) ?: ((int)($opts['societe_id'] ?? 0) ?: 1);
        $ageId  = (int)($immCtx['id_agence']  ?? 0) ?: ((int)($opts['agence_id']  ?? 0) ?: 3);
        $userId = (int)($opts['user_id'] ?? 0) ?: null;
        $bienId = (int)($opts['bien_id'] ?? 0);
        $dateDoc = date('Y-m-d');

        // 4. Liens : immeuble (principal) + bien si fourni
        $links = [['entity_type' => 'IMB', 'entity_id' => $immeubleId, 'relation_type' => 'main']];
        if ($bienId > 0) $links[] = ['entity_type' => 'BIEN', 'entity_id' => $bienId, 'relation_type' => 'related'];

        try {
            $res = gus_commit_document(
                $pdo,
                [
                    'path_on_disk'  => $destPath,
                    'name_original' => 'Etat_des_Risques_ERP.pdf',
                    'mime_type'     => 'application/pdf',
                    'size_bytes'    => strlen($pdf),
                    'public_url'    => $publicUrl,
                ],
                [
                    'document_type'  => 'ETAT_RISQUES',
                    'source_module'  => '02_SYNDIC',
                    'security_level' => 'interne',
                    'societe_id'     => $socId,
                    'agence_id'      => $ageId,
                    'tenant_id'      => $socId,
                    'created_by'     => $userId,
                    'storage_provider' => 'local',
                    'name_display'   => 'État des Risques et Pollutions (ERP)',
                    'metadata_extra' => [
                        'source'        => 'georisques.gouv.fr',
                        'auto_ingest'   => true,
                        'latlon'        => $lng . ',' . $lat,
                        'doc_date'      => $dateDoc,
                        'classement'    => ['immeuble_id_bdd' => $immeubleId, 'date_doc' => $dateDoc],
                        'legacy_source' => 'erp_ingest',
                    ],
                    'naming_ctx' => [
                        'societe_raison' => $immCtx['soc_raison'] ?? 'Régie EMERY',
                        'agence_code'    => $immCtx['code_agence'] ?? 'RE69-2',
                        'agence_nom'     => $immCtx['nom_agence']  ?? 'LYON',
                        'user_id'        => $userId,
                        'n1_slug'        => '02_syndic',
                        'n2_slug'        => 'immeubles',
                        'n3_slug'        => 'etat_risques',
                        'type_doc'       => 'ETAT_RISQUES',
                        'entity_type'    => 'IMB',
                        'entity_id'      => $immeubleId,
                        'date_doc'       => $dateDoc,
                        'source_filename'=> 'Etat_des_Risques_ERP.pdf',
                    ],
                ],
                $links
            );
            if (empty($res['ok'])) {
                return ['ok' => false, 'error' => 'GED: ' . json_encode($res['errors'] ?? ['unknown'])];
            }
            return ['ok' => true, 'doc_id' => (int)($res['doc_id'] ?? 0), 'deduplicated' => (bool)($res['deduplicated'] ?? false)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'EXCEPTION ERP GED : ' . $e->getMessage()];
        }
    }
}
