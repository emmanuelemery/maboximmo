<?php
declare(strict_types=1);
/**
 * inc/photos_ingest.php — Ingestion AUTOMATIQUE des photos publiques (Google Places)
 * d'un lieu dans la GED centrale, lors de la recherche des données publiques.
 *
 * Idempotent : gus_commit_document() déduplique par hash SHA-256 — ré-appeler
 * sur le même immeuble ne recrée pas les mêmes photos.
 *
 * @return array { ok:bool, count:int, doc_ids:int[], skipped?:bool, error?:string }
 */

require_once __DIR__ . '/ged_document_links.php';

if (!function_exists('photos_ingest_to_ged')) {
    /**
     * @param PDO    $pdo
     * @param int    $immeubleId
     * @param string $placeId   Google place_id
     * @param array  $opts ['bien_id'=>int,'user_id'=>int,'societe_id'=>int,'agence_id'=>int,'max'=>int]
     */
    function photos_ingest_to_ged(PDO $pdo, int $immeubleId, string $placeId, array $opts = []): array
    {
        $key = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
        if ($immeubleId <= 0 || $placeId === '' || $key === '') {
            return ['ok' => false, 'skipped' => true, 'count' => 0, 'doc_ids' => [], 'error' => 'place_id/clé/immeuble manquants'];
        }
        $max = max(1, min(8, (int)($opts['max'] ?? 6)));

        // 1. Références photo du lieu
        $url = 'https://maps.googleapis.com/maps/api/place/details/json?'
             . http_build_query(['place_id' => $placeId, 'fields' => 'photo', 'key' => $key]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $r = curl_exec($ch);
        curl_close($ch);
        $d = is_string($r) ? json_decode($r, true) : null;
        if (!is_array($d) || ($d['status'] ?? '') !== 'OK') {
            return ['ok' => true, 'count' => 0, 'doc_ids' => [], 'skipped' => true];
        }
        $refs = [];
        foreach (($d['result']['photos'] ?? []) as $ph) {
            if (!empty($ph['photo_reference'])) $refs[] = (string)$ph['photo_reference'];
            if (count($refs) >= $max) break;
        }
        if (!$refs) return ['ok' => true, 'count' => 0, 'doc_ids' => [], 'skipped' => true];

        // 2. Contexte société/agence
        $immCtx = [];
        try {
            $st = $pdo->prepare("SELECT i.id_societe, i.id_agence,
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

        $uploadDir = dirname(__DIR__) . '/uploads/biens_photos/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

        $docIds = [];
        foreach ($refs as $i => $ref) {
            $purl = 'https://maps.googleapis.com/maps/api/place/photo?'
                  . http_build_query(['maxwidth' => 1200, 'photo_reference' => $ref, 'key' => $key]);
            $cp = curl_init($purl);
            curl_setopt_array($cp, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $img  = curl_exec($cp);
            $ctype = (string)curl_getinfo($cp, CURLINFO_CONTENT_TYPE);
            $code  = (int)curl_getinfo($cp, CURLINFO_HTTP_CODE);
            curl_close($cp);
            if ($img === false || $code !== 200 || stripos($ctype, 'image') === false || strlen($img) < 2000) continue;

            $ext  = stripos($ctype, 'png') !== false ? 'png' : 'jpg';
            $name = 'imm_' . $immeubleId . '_gphoto_' . ($i + 1) . '_' . substr(hash('sha256', $img), 0, 8) . '.' . $ext;
            $dest = $uploadDir . $name;
            $pub  = '/uploads/biens_photos/' . $name;
            if (@file_put_contents($dest, $img) === false) continue;

            $links = [['entity_type' => 'IMB', 'entity_id' => $immeubleId, 'relation_type' => 'main']];
            if ($bienId > 0) $links[] = ['entity_type' => 'BIEN', 'entity_id' => $bienId, 'relation_type' => 'related'];

            try {
                $res = gus_commit_document(
                    $pdo,
                    [
                        'path_on_disk'  => $dest,
                        'name_original' => 'Photo_publique_' . ($i + 1) . '.' . $ext,
                        'mime_type'     => 'image/' . ($ext === 'png' ? 'png' : 'jpeg'),
                        'size_bytes'    => strlen($img),
                        'public_url'    => $pub,
                    ],
                    [
                        'document_type'  => 'PHOTO',
                        'source_module'  => '03_GESTION',
                        'security_level' => 'interne',
                        'societe_id'     => $socId,
                        'agence_id'      => $ageId,
                        'tenant_id'      => $socId,
                        'created_by'     => $userId,
                        'storage_provider' => 'local',
                        'name_display'   => 'Photo publique #' . ($i + 1),
                        'metadata_extra' => [
                            'source'        => 'google_places',
                            'auto_ingest'   => true,
                            'doc_date'      => $dateDoc,
                            'classement'    => ['immeuble_id_bdd' => $immeubleId, 'date_doc' => $dateDoc],
                            'legacy_source' => 'photos_ingest',
                        ],
                        'naming_ctx' => [
                            'societe_raison' => $immCtx['soc_raison'] ?? 'Régie EMERY',
                            'agence_code'    => $immCtx['code_agence'] ?? 'RE69-2',
                            'agence_nom'     => $immCtx['nom_agence']  ?? 'LYON',
                            'user_id'        => $userId,
                            'n1_slug'        => '03_gestion',
                            'n2_slug'        => 'photos',
                            'n3_slug'        => 'photo',
                            'type_doc'       => 'PHOTO',
                            'entity_type'    => $bienId > 0 ? 'BIEN' : 'IMB',
                            'entity_id'      => $bienId > 0 ? $bienId : $immeubleId,
                            'date_doc'       => $dateDoc,
                            'source_filename'=> 'Photo_publique_' . ($i + 1) . '.' . $ext,
                        ],
                    ],
                    $links
                );
                if (!empty($res['ok'])) $docIds[] = (int)($res['doc_id'] ?? 0);
            } catch (Throwable) { /* on continue avec les autres photos */ }
        }

        return ['ok' => true, 'count' => count($docIds), 'doc_ids' => $docIds];
    }
}
