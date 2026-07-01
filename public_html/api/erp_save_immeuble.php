<?php
/**
 * api/erp_save_immeuble.php — Dépôt AUTOMATIQUE du rapport ERP (État des Risques,
 * Géorisques) dans la GED, rattaché à l'IMMEUBLE (partagé par tous les lots) et
 * visible sur le BIEN.
 *
 * Déclenché à la 1re recherche Géorisques réussie (côté client). Règle stricte :
 * UN SEUL ERP par immeuble → si un rapport ERP est déjà rattaché à l'immeuble (ou
 * au bien), on ne recrée rien (le PDF Géorisques change à chaque appel : le dédup
 * par hash ne suffit pas, on dédup par présence d'un doc ERP sur l'entité).
 *
 * POST : { id_bien:int, lat:float, lng:float, csrf_token }
 * Réponse : { ok:bool, saved?:bool, already?:bool, doc_id?:int, url?:string, error?:string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_document_links.php';   // gus_commit_document()
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('ajouter_bien');

$pdo    = $GLOBALS['pdo'];
$bienId = (int)($_POST['id_bien'] ?? 0);
$lat    = (float)($_POST['lat'] ?? 0);
$lng    = (float)($_POST['lng'] ?? 0);
if ($bienId <= 0)                exit(json_encode(['ok' => false, 'error' => 'Bien manquant.']));
if ($lat === 0.0 || $lng === 0.0) exit(json_encode(['ok' => false, 'error' => 'Géolocalisation manquante.']));

// ── Contexte bien + scope société ──
$stB = $pdo->prepare("SELECT id_societe, id_agence, id_immeuble, reference_bien FROM biens WHERE id = ? LIMIT 1");
$stB->execute([$bienId]);
$bien = $stB->fetch(PDO::FETCH_ASSOC);
if (!$bien) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable.']));

$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$bien['id_societe'] !== $idSoc) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Hors périmètre.']));
}

$immeubleId = (int)($bien['id_immeuble'] ?? 0);

// ── Dédup : un rapport ERP déjà rattaché à l'immeuble (ou au bien) ? ──
try {
    $sql = "SELECT d.id
              FROM ged_documents d
              JOIN ged_document_links l ON l.document_id = d.id
             WHERE UPPER(d.document_type) IN ('ETAT_RISQUES','DIAG_ERP','ERP')
               AND COALESCE(d.status,'active') = 'active'
               AND ( (UPPER(l.entity_type) IN ('IMB','IMMEUBLE') AND l.entity_id = :imm)
                  OR (UPPER(l.entity_type) IN ('BIEN','B')       AND l.entity_id = :bien) )
             LIMIT 1";
    $stDup = $pdo->prepare($sql);
    $stDup->execute([':imm' => $immeubleId, ':bien' => $bienId]);
    if ($stDup->fetchColumn()) {
        exit(json_encode(['ok' => true, 'already' => true, 'saved' => false]));
    }
} catch (Throwable $e) {
    error_log('[erp_save_immeuble] dedup: ' . $e->getMessage());
}

// ── Téléchargement du rapport ERP officiel (Géorisques, gratuit) ──
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
if ($pdf === false || $code !== 200 || stripos($type, 'pdf') === false || strlen((string)$pdf) < 1000) {
    exit(json_encode(['ok' => false, 'error' => 'Rapport ERP indisponible (Géorisques).']));
}

// ── Stockage disque ──
$uploadDir = dirname(__DIR__) . '/uploads/biens_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
$safeName  = 'etat_risques_imm' . $immeubleId . '_b' . $bienId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$destPath  = $uploadDir . $safeName;
$publicUrl = '/uploads/biens_docs/' . $safeName;
if (@file_put_contents($destPath, $pdf) === false) {
    exit(json_encode(['ok' => false, 'error' => 'Écriture du fichier impossible.']));
}

// ── Liens GED : IMMEUBLE (partagé) + BIEN (visible dans l'onglet ERP du lot) ──
$links = [['entity_type' => 'BIEN', 'entity_id' => $bienId, 'relation_type' => 'main']];
if ($immeubleId > 0) {
    $links[] = ['entity_type' => 'IMB', 'entity_id' => $immeubleId, 'relation_type' => 'main'];
}

try {
    $res = gus_commit_document(
        $pdo,
        [
            'path_on_disk'  => $destPath,
            'name_original' => 'Etat_des_Risques_ERP.pdf',
            'mime_type'     => 'application/pdf',
            'size_bytes'    => strlen((string)$pdf),
            'public_url'    => $publicUrl,
        ],
        [
            'document_type'  => 'ETAT_RISQUES',
            'source_module'  => '05_TRANSACTION',
            'security_level' => 'interne',
            'societe_id'     => $bien['id_societe'] !== null ? (int)$bien['id_societe'] : null,
            'agence_id'      => $bien['id_agence']  !== null ? (int)$bien['id_agence']  : null,
            'tenant_id'      => $bien['id_societe'] !== null ? (int)$bien['id_societe'] : null,
            'created_by'     => (int)current_user_id() ?: null,
            'naming_ctx'     => [
                'n1_slug'         => '06_transaction',
                'n2_slug'         => 'biens',
                'n3_slug'         => 'etat_risques',
                'type_doc'        => 'ETAT_RISQUES',
                'entity_type'     => $immeubleId > 0 ? 'IMMEUBLE' : 'BIEN',
                'entity_id'       => $immeubleId > 0 ? $immeubleId : $bienId,
                'source_filename' => 'Etat_des_Risques_ERP.pdf',
                'ext'             => 'pdf',
            ],
        ],
        $links
    );
    if (empty($res['ok'])) {
        @unlink($destPath);
        exit(json_encode(['ok' => false, 'error' => 'Dépôt GED échoué : ' . implode(' · ', $res['errors'] ?? [])]));
    }
    echo json_encode([
        'ok'     => true,
        'saved'  => true,
        'doc_id' => (int)($res['doc_id'] ?? 0),
        'url'    => $publicUrl,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[erp_save_immeuble] commit: ' . $e->getMessage());
    @unlink($destPath);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
