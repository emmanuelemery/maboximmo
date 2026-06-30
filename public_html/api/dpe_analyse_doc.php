<?php
/**
 * api/dpe_analyse_doc.php — Reconnaissance/extraction DPE sur un document DÉJÀ en GED.
 *
 * EXTRACTION SEULE : ne touche pas la base. Renvoie les champs détectés pour
 * pré-remplir le modal ; l'utilisateur valide puis enregistre (api/dpe_diag_save.php).
 *
 * GET/POST :
 *   ged_document_id=INT
 *   mode = 'regex' (DÉFAUT, GRATUIT : texte + regex, aucun appel IA ni OCR)
 *        | 'ia'    (COMPLET, PAYANT : dpe_analyser → regex + IA + OCR Vision si scanné)
 *
 * Réponse : { ok:bool, fields:{...}, score:int, method:string, ia:bool, error:?string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/dpe_service.php';
require_once dirname(__DIR__) . '/inc/mandat_registre.php';   // mr_ged_doc_path()
require_once dirname(__DIR__) . '/inc/microsoft_graph.php';   // graph_download_file_content()
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$docId       = (int)($_POST['ged_document_id'] ?? $_GET['ged_document_id'] ?? 0);
$onedriveId  = trim((string)($_POST['onedrive_item_id'] ?? $_GET['onedrive_item_id'] ?? ''));

$path = '';
$tmpToCleanup = '';

if ($onedriveId !== '') {
    // ── Source OneDrive : téléchargement TEMPORAIRE (analyse seule, pas de GED) ──
    if (!graph_is_configured()) exit(json_encode(['ok' => false, 'error' => 'Microsoft Graph non configuré']));
    graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');
    try { $dl = graph_download_file_content($onedriveId); }
    catch (Throwable $e) { exit(json_encode(['ok' => false, 'error' => 'Téléchargement OneDrive : ' . $e->getMessage()])); }
    if (empty($dl['ok'])) exit(json_encode(['ok' => false, 'error' => $dl['error'] ?? 'Téléchargement impossible']));
    $tmpDir = dirname(__DIR__) . '/uploads/_dpe_tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    $path = $tmpDir . '/ana_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    if (file_put_contents($path, (string)$dl['content']) === false) {
        exit(json_encode(['ok' => false, 'error' => 'Écriture du fichier temporaire impossible']));
    }
    $tmpToCleanup = $path;
    register_shutdown_function(function () use ($tmpToCleanup) { if ($tmpToCleanup && is_file($tmpToCleanup)) @unlink($tmpToCleanup); });
} else {
    if ($docId <= 0) exit(json_encode(['ok' => false, 'error' => 'ged_document_id ou onedrive_item_id requis']));
    if (!function_exists('mr_ged_doc_path')) exit(json_encode(['ok' => false, 'error' => 'Résolveur de chemin GED indisponible']));
    $path = mr_ged_doc_path($pdo, $docId);
    if ($path === '' || !is_file($path)) {
        exit(json_encode(['ok' => false, 'error' => 'Fichier PDF introuvable pour ce document GED']));
    }
}

$mode = (($_POST['mode'] ?? $_GET['mode'] ?? 'regex') === 'ia') ? 'ia' : 'regex';

try {
    if ($mode === 'regex') {
        // ── GRATUIT : texte + regex uniquement, AUCUN appel IA / OCR ──
        $texte   = (string) BienImportParser::extractText($path);
        $textLen = mb_strlen(trim($texte));
        if ($textLen < 200) {
            // PDF scanné : illisible sans OCR (IA). On NE déclenche rien de payant ici.
            exit(json_encode([
                'ok' => false, 'ia' => false,
                'error' => 'PDF scanné (peu de texte) : extraction gratuite impossible. Utilisez « Compléter par IA » ou saisissez à la main.',
            ], JSON_UNESCAPED_UNICODE));
        }
        $rx     = DpeImportParser::parse($texte);
        $fields = (array)($rx['fields'] ?? []);
        echo json_encode([
            'ok'     => true,
            'fields' => $fields,
            'score'  => dpe_score($fields),
            'method' => 'regex',
            'ia'     => false,
            'error'  => null,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ── mode = ia : COMPLET (payant) — regex + IA + OCR Vision si scanné ──
    $res = dpe_analyser($path, 0); // bienId=0 → analyse pure, aucune écriture
    if (empty($res['ok'])) {
        exit(json_encode(['ok' => false, 'ia' => true, 'error' => $res['error'] ?? 'Extraction échouée']));
    }
    echo json_encode([
        'ok'     => true,
        'fields' => (array)($res['fields'] ?? []),
        'score'  => (int)($res['score'] ?? 0),
        'method' => (string)($res['method'] ?? ''),
        'ia'     => true,
        'error'  => null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
