<?php
/**
 * api/dpe_reextract_free.php — Relance une extraction DPE GRATUITE (regex, zéro IA)
 * sur un PDF DPE déjà présent en GED, puis enregistre les champs détectés sur le bien.
 *
 * « Gratuit car déjà fait » : aucun appel IA / OCR payant. On relit le PDF avec le
 * parser regex (BienImportParser + DpeImportParser) et on remappe via dpe_enregistrer().
 * Si le PDF est un scan (peu de texte), on refuse proprement (pas de bascule payante).
 *
 * POST : { id_bien:int, ged_document_id:int, csrf_token }
 * Réponse : { ok:bool, diag_id?:int, count?:int, score?:int, error?:string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/dpe_service.php';
require_once dirname(__DIR__) . '/inc/mandat_registre.php';   // mr_ged_doc_path()
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
$docId  = (int)($_POST['ged_document_id'] ?? 0);
if ($bienId <= 0) exit(json_encode(['ok' => false, 'error' => 'Bien manquant.']));
if ($docId  <= 0) exit(json_encode(['ok' => false, 'error' => 'Document DPE introuvable.']));

// Scope société : le bien doit exister (et rester dans le périmètre de l'utilisateur)
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$chk = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ? LIMIT 1");
$chk->execute([$bienId]);
$bSoc = $chk->fetchColumn();
if ($bSoc === false) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable.']));
if (!$isSuperAdmin && $societeId > 0 && (int)$bSoc !== $societeId) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Hors périmètre.']));
}

if (!function_exists('mr_ged_doc_path')) exit(json_encode(['ok' => false, 'error' => 'Résolveur GED indisponible.']));
$path = mr_ged_doc_path($pdo, $docId);
if ($path === '' || !is_file($path)) {
    exit(json_encode(['ok' => false, 'error' => 'Fichier PDF DPE introuvable sur le serveur.']));
}

try {
    $fields = [];
    $method = '';

    // ── 1) PRIORITÉ : réutiliser l'extraction IA DÉJÀ FAITE (stockée en base) ──
    //    C'est EXACTEMENT le résultat du puissant extracteur des Documents
    //    (gpt-4o vision, 32 champs) → même qualité, mais 0 € car déjà payé.
    try {
        $stJ = $pdo->prepare(
            "SELECT champs_extraits_json FROM dpe_diags
             WHERE id_bien = ? AND champs_extraits_json IS NOT NULL AND champs_extraits_json <> ''
             ORDER BY date_creation DESC, id DESC LIMIT 1"
        );
        $stJ->execute([$bienId]);
        $json = (string)($stJ->fetchColumn() ?: '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && $decoded) {
                foreach ($decoded as $k => $v) {
                    if ($v === '' || $v === null) continue;
                    $fields[$k] = $v;
                }
                if ($fields) $method = 'ia_cache';
            }
        }
    } catch (Throwable $e) { /* pas de cache → on tombera sur le regex */ }

    // ── 2) FALLBACK : regex gratuit (texte + regex, aucun appel IA) ──
    if (!$fields) {
        $texte = (string) BienImportParser::extractText($path);
        if (mb_strlen(trim($texte)) < 200) {
            exit(json_encode([
                'ok'    => false,
                'error' => 'PDF scanné (peu de texte) et aucune extraction IA en cache. Utilisez « Compléter par IA » ou saisissez à la main.',
            ], JSON_UNESCAPED_UNICODE));
        }
        $rx     = DpeImportParser::parse($texte);
        $fields = (array)($rx['fields'] ?? []);
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) unset($fields[$k]);
        }
        $method = 'regex_gratuit';
    }

    if (!$fields) {
        exit(json_encode(['ok' => false, 'error' => 'Aucun champ à réappliquer (ni cache IA ni regex).']));
    }

    $res = dpe_enregistrer($pdo, $bienId, $fields, [
        'method'          => $method,
        'score'           => dpe_score($fields),
        'ged_document_id' => $docId,
    ]);
    if (empty($res['ok'])) {
        exit(json_encode(['ok' => false, 'error' => $res['error'] ?? 'Enregistrement échoué.']));
    }
    echo json_encode([
        'ok'      => true,
        'diag_id' => (int)($res['diag_id'] ?? 0),
        'count'   => count($fields),
        'score'   => dpe_score($fields),
        'method'  => $method,   // 'ia_cache' (réutilise l'IA déjà faite) | 'regex_gratuit'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dpe_reextract_free] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
