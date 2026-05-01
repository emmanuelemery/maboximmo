<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Handler AJAX du dashboard (rename de agent_ged_action.php)
 * Fichier : modules/ged/ged_dashboard_action.php
 *
 * Endpoint POST appelé depuis ged.js avec les actions :
 *   - validate / reject / reanalyze / ocr_free / ocr_premium
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_login();
require_once __DIR__ . '/ged_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, must-revalidate, private');

function ged_respond(bool $ok, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ged_respond(false, 'Méthode non autorisée');
}

$action     = isset($_POST['action'])         ? (string)$_POST['action']         : '';
$analysisId = isset($_POST['analysis_id'])    ? (int)$_POST['analysis_id']       : 0;
$documentId = isset($_POST['document_id'])    ? (int)$_POST['document_id']       : 0;
$service    = isset($_POST['service'])        ? (string)$_POST['service']        : '';
$ocrText    = isset($_POST['ocr_text'])       ? (string)$_POST['ocr_text']       : '';
$docTable   = isset($_POST['document_table']) ? (string)$_POST['document_table'] : '';

if ($action === '') ged_respond(false, 'action manquante');

$userId = current_user_id();
if ($userId <= 0) {
    http_response_code(401);
    ged_respond(false, 'Utilisateur non identifié');
}

try {
    switch ($action) {
        case 'validate':
            if ($analysisId <= 0) ged_respond(false, 'analysis_id manquant');
            $ok = gedValidateAnalysis($analysisId, $userId);
            ged_respond($ok, $ok ? 'Analyse validée' : 'Analyse introuvable ou déjà validée');

        case 'reject':
            if ($analysisId <= 0) ged_respond(false, 'analysis_id manquant');
            $ok = gedRejectAnalysis($analysisId, $userId);
            ged_respond($ok, $ok ? 'Analyse rejetée' : 'Analyse introuvable');

        case 'reanalyze':
            if ($analysisId > 0) {
                $pdo = ged_get_pdo();
                $stmt = $pdo->prepare("SELECT document_id, document_table, ocr_text FROM ged_analyses WHERE id = :id");
                $stmt->execute(['id' => $analysisId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) ged_respond(false, 'Analyse introuvable');
                $documentId = (int)($row['document_id'] ?? 0);
                $docTable   = (string)($row['document_table'] ?? '');
                $ocrText    = (string)($row['ocr_text'] ?? '');
            }
            if ($ocrText === '') {
                ged_respond(false, 'Pas de texte OCR — lance d\'abord un OCR sur ce document');
            }
            $forced = in_array($service, ['syndic', 'gestion', 'transaction', 'agence', 'direction'], true) ? $service : null;
            $res = gedAnalyzeDocument($documentId, $docTable, $ocrText, $forced);
            ged_respond(true, 'Analyse IA terminée', [
                'analysis_id' => $res['analysis_id'],
                'result'      => $res['result'],
            ]);

        case 'ocr_free':
            ged_respond(false, 'OCR gratuit non encore branché — intégration Tesseract à venir.');

        case 'ocr_premium':
            try {
                $r = gedRunPremiumOcr($documentId);
                ged_respond(true, 'OCR premium terminé', $r);
            } catch (Throwable $e) {
                ged_respond(false, $e->getMessage());
            }

        default:
            ged_respond(false, 'Action inconnue : ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    error_log('[ged_dashboard_action] ' . $e->getMessage());
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Erreur interne';
    ged_respond(false, $msg);
}
