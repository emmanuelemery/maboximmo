<?php
declare(strict_types=1);

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Agent GED MaBoxImmo — Handler AJAX
 * Fichier : modules/agent/agent_ged_action.php
 *
 * Endpoint POST appelé depuis agent_ged.js avec les actions :
 *   - validate    : marque l'analyse comme validée
 *   - reject      : marque l'analyse comme rejetée
 *   - reanalyze   : relance l'analyse OpenAI sur le doc
 *   - ocr_free    : placeholder relance OCR Tesseract local
 *   - ocr_premium : placeholder OCR Mindee/Vision (à brancher)
 *
 * Sécurité :
 *   - require_login() obligatoire
 *   - prepared statements via fonctions de agent_functions.php
 *   - Réponse JSON systématique (status + message)
 * ───────────────────────────────────────────────────────────────────────────── */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_login();
require_once __DIR__ . '/../ged/agent_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, must-revalidate, private');

/**
 * Réponse JSON standardisée + arrêt du script.
 */
function aged_respond(bool $ok, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// Validation requête
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    aged_respond(false, 'Méthode non autorisée');
}

$action     = isset($_POST['action'])      ? (string)$_POST['action']      : '';
$analysisId = isset($_POST['analysis_id']) ? (int)$_POST['analysis_id']    : 0;
$documentId = isset($_POST['document_id']) ? (int)$_POST['document_id']    : 0;
$service    = isset($_POST['service'])     ? (string)$_POST['service']     : '';
$ocrText    = isset($_POST['ocr_text'])    ? (string)$_POST['ocr_text']    : '';
$docTable   = isset($_POST['document_table']) ? (string)$_POST['document_table'] : '';

if ($action === '') {
    aged_respond(false, 'action manquante');
}

$userId = current_user_id();
if ($userId <= 0) {
    http_response_code(401);
    aged_respond(false, 'Utilisateur non identifié');
}

try {
    switch ($action) {
        // ── Validation humaine ──────────────────────────────────────────
        case 'validate':
            if ($analysisId <= 0) aged_respond(false, 'analysis_id manquant');
            $ok = validateGedAnalysis($analysisId, $userId);
            aged_respond($ok, $ok ? 'Analyse validée' : 'Analyse introuvable ou déjà validée');

        case 'reject':
            if ($analysisId <= 0) aged_respond(false, 'analysis_id manquant');
            $ok = rejectGedAnalysis($analysisId, $userId);
            aged_respond($ok, $ok ? 'Analyse rejetée' : 'Analyse introuvable');

        // ── Re-analyse via OpenAI ───────────────────────────────────────
        case 'reanalyze':
            // Cas 1 : on relance depuis une analyse existante (récupère son ocr_text)
            if ($analysisId > 0) {
                $pdo = agent_get_pdo();
                $stmt = $pdo->prepare("SELECT document_id, document_table, ocr_text FROM agent_ged_analyses WHERE id = :id");
                $stmt->execute(['id' => $analysisId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) aged_respond(false, 'Analyse introuvable');
                $documentId = (int)($row['document_id'] ?? 0);
                $docTable   = (string)($row['document_table'] ?? '');
                $ocrText    = (string)($row['ocr_text'] ?? '');
            }
            if ($ocrText === '') {
                aged_respond(false, 'Pas de texte OCR — lance d\'abord un OCR sur ce document');
            }
            $forced = in_array($service, ['syndic', 'gestion', 'transaction', 'agence', 'direction'], true) ? $service : null;
            $res = analyzeGedDocument($documentId, $docTable, $ocrText, $forced);
            aged_respond(true, 'Analyse IA terminée', [
                'analysis_id' => $res['analysis_id'],
                'result'      => $res['result'],
            ]);

        // ── OCR gratuit (Tesseract) — placeholder ────────────────────────
        case 'ocr_free':
            // À brancher : appel à un binaire Tesseract local OU à la file OCR
            // existante de MaBoxImmo si elle existe.
            aged_respond(false, 'OCR gratuit non encore branché — intégration Tesseract à venir.');

        // ── OCR premium (Mindee / Vision) — placeholder ──────────────────
        case 'ocr_premium':
            // À brancher : runPremiumOcr($documentId) une fois la clé Mindee
            // configurée et l'adapter écrit. Pour l'instant : message clair.
            try {
                $r = runPremiumOcr($documentId);
                aged_respond(true, 'OCR premium terminé', $r);
            } catch (Throwable $e) {
                aged_respond(false, $e->getMessage());
            }

        default:
            aged_respond(false, 'Action inconnue : ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    // Erreur non prévue : on log mais on ne dévoile pas le détail à l'utilisateur
    // sauf en mode dev (APP_DEBUG).
    error_log('[agent_ged_action] ' . $e->getMessage());
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Erreur interne';
    aged_respond(false, $msg);
}
