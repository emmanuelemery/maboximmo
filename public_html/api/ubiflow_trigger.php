<?php
declare(strict_types=1);

/**
 * POST /api/ubiflow_trigger.php
 *
 * Déclenche un dépôt Ubiflow depuis la UI (bouton du dashboard diffusion).
 * Support d'une ou plusieurs agences en une seule requête.
 *
 * Paramètres POST :
 *   - csrf_token      : token 'ubiflow_trigger'
 *   - slug            : 'chaponost' | 'lyon' | … | 'all'
 *   - force           : 0|1 (ignore la garde MD5)
 *
 * Auth : tout user authentifié de la société peut déclencher
 * (mais le serveur trace qui a cliqué via triggered_user).
 *
 * Réponse JSON :
 *   { ok, results: [{slug, ok, status, annonces, duration_ms, error?}, …] }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']); exit;
    }
    verify_csrf_any('ubiflow_trigger');

    require_once dirname(__DIR__) . '/config/ubiflow_agences.php';
    require_once dirname(__DIR__) . '/config/ubiflow_mapping.php';
    require_once dirname(__DIR__) . '/api/flux/ubiflow_ftp.php';

    $slugReq = strtolower(trim((string)($_POST['slug'] ?? '')));
    $force   = !empty($_POST['force']);
    $userId  = (int)($_SESSION['user_id'] ?? 0);

    // Détermine la liste des agences à traiter
    $targets = [];
    if ($slugReq === 'all' || $slugReq === '') {
        $targets = ubiflow_agences_actives();
    } else {
        $cfg = ubiflow_agence_get($slugReq);
        if ($cfg === null) {
            throw new RuntimeException("Agence inconnue : {$slugReq}");
        }
        if (empty($cfg['id_agence']) || empty($cfg['actif'])) {
            throw new RuntimeException("Agence '{$slugReq}' inactive ou sans id_agence");
        }
        $targets = [$slugReq => $cfg];
    }

    if (empty($targets)) {
        throw new RuntimeException('Aucune agence active à traiter');
    }

    $pdo = db();
    $results = [];
    $exportRoot = dirname(__DIR__) . '/api/flux/export';

    foreach ($targets as $slug => $cfg) {
        $idAgence = (int)$cfg['id_agence'];
        $loginFtp = (string)$cfg['login_ftp'];

        // ─── 1. Génération XML ───────────────────────────
        try {
            $sql  = ubiflow_sql_select_annonces($idAgence);
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $dom = new DOMDocument('1.0', 'utf-8');
            $dom->formatOutput = true;
            $dom->preserveWhiteSpace = false;
            $clientNode = $dom->createElement('client');
            $dom->appendChild($clientNode);

            $appendValue = static function (DOMDocument $dom, DOMElement $parent, string $name, $value): void {
                if ($value === null || $value === '') return;
                $s = (string) $value;
                $child = $dom->createElement($name);
                if (preg_match('/[<>&\r\n"\']/', $s)) {
                    $child->appendChild($dom->createCDATASection($s));
                } else {
                    $child->appendChild($dom->createTextNode($s));
                }
                $parent->appendChild($child);
            };
            $appendGroup = static function (DOMDocument $dom, DOMElement $parent, string $groupName, array $data) use ($appendValue): ?DOMElement {
                if (empty($data)) return null;
                $group = $dom->createElement($groupName);
                foreach ($data as $key => $value) {
                    $appendValue($dom, $group, (string) $key, $value);
                }
                $parent->appendChild($group);
                return $group;
            };

            $count = 0; $skipped = 0;
            foreach ($rows as $row) {
                $idAnnonce = (int) ($row['a_id'] ?? 0);
                if ($idAnnonce <= 0) continue;
                $photos = ubiflow_get_photos($pdo, $idAnnonce);
                $data   = build_ubiflow_annonce($row, $photos);
                if (!empty($data['_skipped'])) { $skipped++; continue; }

                $annonceNode = $dom->createElement('annonce');
                foreach ($data['annonce'] as $key => $value) {
                    $appendValue($dom, $annonceNode, (string) $key, $value);
                }
                if (!empty($data['photos'])) {
                    $photosNode = $dom->createElement('photos');
                    foreach ($data['photos'] as $url) {
                        $appendValue($dom, $photosNode, 'photo', $url);
                    }
                    $annonceNode->appendChild($photosNode);
                }
                $bienNode = $appendGroup($dom, $annonceNode, 'bien', $data['bien']);
                if ($bienNode !== null && !empty($data['diagnostiques'])) {
                    $appendGroup($dom, $bienNode, 'diagnostiques', $data['diagnostiques']);
                }
                $appendGroup($dom, $annonceNode, 'prestation', $data['prestation']);
                // Bloc <contact> : négociateur attribué (mobile + fixe + email)
                // → repris par LBC pour l'affichage du contact sur la page annonce.
                // (Idem inc/ubiflow_build.php qui duplique cette logique pour
                //  la diffusion unitaire depuis api/annonce_diffuser.php.)
                if (!empty($data['contact'])) {
                    $appendGroup($dom, $annonceNode, 'contact', $data['contact']);
                }
                $clientNode->appendChild($annonceNode);
                $count++;
            }

            $xml = $dom->saveXML();
            $xml = preg_replace(
                '/^<\?xml version="1\.0" encoding="UTF-8"\?>/',
                '<?xml version="1.0" encoding="utf-8"?>',
                $xml, 1
            );

            $exportDir = $exportRoot . '/' . $slug;
            if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
                throw new RuntimeException('mkdir failed: ' . $exportDir);
            }
            $outFile = $exportDir . '/' . $loginFtp . '.xml';
            if (@file_put_contents($outFile, $xml) === false) {
                throw new RuntimeException('write failed: ' . $outFile);
            }
        } catch (Throwable $e) {
            $results[] = [
                'slug'   => $slug,
                'ok'     => false,
                'status' => 'build_error',
                'error'  => 'Génération XML : ' . $e->getMessage(),
            ];
            continue;
        }

        // ─── 2. Deploy (lock + MD5 + log) ────────────────
        try {
            $deploy = ubiflow_deploy($slug, $outFile, [
                'triggered_by'   => 'manual',
                'triggered_user' => $userId,
                'force'          => $force,
            ]);
            $results[] = [
                'slug'        => $slug,
                'ok'          => !empty($deploy['ok']),
                'status'      => $deploy['status'] ?? '?',
                'annonces'    => $count,
                'skipped'     => $skipped,
                'duration_ms' => $deploy['duration_ms'] ?? null,
                'zip_md5'     => $deploy['md5'] ?? null,
                'error'       => $deploy['error'] ?? null,
                'skipped_reason' => $deploy['skipped_reason'] ?? null,
            ];
        } catch (Throwable $e) {
            $results[] = [
                'slug'   => $slug,
                'ok'     => false,
                'status' => 'ftp_error',
                'error'  => $e->getMessage(),
            ];
        }
    }

    $allOk = !in_array(false, array_column($results, 'ok'), true);
    echo json_encode([
        'ok'      => $allOk,
        'count'   => count($results),
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
