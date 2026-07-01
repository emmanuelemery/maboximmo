<?php
/**
 * api/dpe_reextract_free.php — Réapplique GRATUITEMENT l'extraction DPE déjà réalisée.
 *
 * « Gratuit car déjà fait » : AUCUN nouvel appel IA/OCR payant. On réutilise
 * l'extraction déjà stockée (même résultat que l'onglet Documents), et on la
 * persiste dans dpe_diags + biens via apply_dpe_extracted_to_bien() — exactement
 * le même chemin que l'analyse des Documents. Résultat : l'onglet « Détails DPE »
 * reprend automatiquement toutes les infos et la complétude remonte.
 *
 * Sources des champs, par priorité :
 *   1) ged_documents.metadata.extra.ia_result_last.fields  (extraction IA gpt-4o — LA bonne)
 *   2) dpe_diags.champs_extraits_json                       (cache IA alternatif)
 *   3) regex sur le PDF (BienImportParser + DpeImportParser)  (repli, si aucun cache)
 *
 * POST : { id_bien:int, ged_document_id:int, csrf_token }
 * Réponse : { ok:bool, count?:int, method?:string, error?:string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/dpe_service.php';
require_once dirname(__DIR__) . '/inc/bien_apply_extracted.php';   // apply_dpe_extracted_to_bien()
require_once dirname(__DIR__) . '/inc/mandat_registre.php';        // mr_ged_doc_path()
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

try {
    $fields    = [];
    $method    = '';
    $publicUrl = '';

    // ── 1) PRIORITÉ : l'extraction IA déjà stockée sur le doc GED (la meilleure) ──
    try {
        $stM = $pdo->prepare("SELECT metadata FROM ged_documents WHERE id = ? LIMIT 1");
        $stM->execute([$docId]);
        $meta = json_decode((string)($stM->fetchColumn() ?: ''), true) ?: [];
        $publicUrl = (string)($meta['public_url'] ?? '');
        $iaFields  = $meta['extra']['ia_result_last']['fields'] ?? null;
        if (is_array($iaFields) && $iaFields) {
            foreach ($iaFields as $k => $v) {
                if ($v === '' || $v === null) continue;
                $fields[$k] = $v;
            }
            if ($fields) $method = 'ia_ged';
        }
    } catch (Throwable $e) { /* pas de metadata → étape suivante */ }

    // ── 2) FALLBACK : cache IA dans dpe_diags.champs_extraits_json ──
    if (!$fields) {
        try {
            $stJ = $pdo->prepare(
                "SELECT champs_extraits_json FROM dpe_diags
                 WHERE id_bien = ? AND champs_extraits_json IS NOT NULL AND champs_extraits_json <> ''
                 ORDER BY date_creation DESC, id DESC LIMIT 1"
            );
            $stJ->execute([$bienId]);
            $decoded = json_decode((string)($stJ->fetchColumn() ?: ''), true);
            if (is_array($decoded) && $decoded) {
                foreach ($decoded as $k => $v) {
                    if ($v === '' || $v === null) continue;
                    $fields[$k] = $v;
                }
                if ($fields) $method = 'ia_cache';
            }
        } catch (Throwable $e) { /* → regex */ }
    }

    // ── 3) FALLBACK : regex gratuit sur le PDF (aucun appel IA) ──
    if (!$fields) {
        if (!function_exists('mr_ged_doc_path')) exit(json_encode(['ok' => false, 'error' => 'Résolveur GED indisponible.']));
        $path = mr_ged_doc_path($pdo, $docId);
        if ($path === '' || !is_file($path)) {
            exit(json_encode(['ok' => false, 'error' => 'Aucune extraction en cache et PDF introuvable sur le serveur.']));
        }
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

    // ── Persistance : MÊME fonction que l'analyse Documents → dpe_diags + biens ──
    $userId = function_exists('current_user_id') ? (int)current_user_id() : null;
    $report = apply_dpe_extracted_to_bien($pdo, $bienId, $fields, ($publicUrl !== '' ? $publicUrl : null), $userId);

    echo json_encode([
        'ok'     => true,
        'count'  => count($fields),
        'method' => $method,   // 'ia_ged' | 'ia_cache' | 'regex_gratuit'
        'report' => is_array($report) ? $report : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dpe_reextract_free] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
