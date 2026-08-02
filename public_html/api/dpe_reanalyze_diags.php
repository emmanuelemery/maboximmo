<?php
/**
 * api/dpe_reanalyze_diags.php — (Ré)analyse le DPE d'un bien pour peupler la COUVERTURE des
 * diagnostics du dossier (dpe_diags), afin que la checklist « Documents de base » coche les
 * diags contenus dans le dossier groupé (ERP/plomb/amiante/gaz/élec/termites/Carrez).
 *
 * ANTI-DOUBLE-PAIEMENT :
 *   - force=0 (défaut) : GRATUIT. Réutilise l'extraction déjà stockée (metadata GED
 *     `ia_result_last` → cache `dpe_diags.champs_extraits_json`), la (re)persiste. Aucun appel IA.
 *     Si aucun cache exploitable → { ok:false, need_ia:true } (on NE paie PAS sans accord).
 *   - force=1 : PAYANT (1 fois). Analyse IA complète (nouveau prompt → `diagnostics_inclus`),
 *     puis persiste → couverture FIABLE. Le cache obtenu rend les appels suivants gratuits.
 *
 * POST : { id_bien:int, ged_document_id:int, force:0|1, csrf_token (form 'ajouter_bien') }
 * Sécurité : login + CSRF + scope société.
 */
declare(strict_types=1);
set_time_limit(180);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/dpe_service.php';            // dpe_analyser(), dpe_enregistrer()
require_once dirname(__DIR__) . '/inc/bien_apply_extracted.php';   // apply_dpe_extracted_to_bien()
require_once dirname(__DIR__) . '/inc/bien_diag_coverage.php';     // bien_diag_coverage()
require_once dirname(__DIR__) . '/inc/mandat_registre.php';        // mr_ged_doc_path()
require_login();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
verify_csrf_any('ajouter_bien');

$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$bienId = (int)($_POST['id_bien'] ?? 0);
$docId  = (int)($_POST['ged_document_id'] ?? 0);
$force  = ((string)($_POST['force'] ?? '0') === '1');
if ($bienId <= 0) exit(json_encode(['ok'=>false,'error'=>'Bien manquant.']));
if ($docId  <= 0) exit(json_encode(['ok'=>false,'error'=>'Document DPE introuvable.']));

// Scope société
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$chk = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ? LIMIT 1");
$chk->execute([$bienId]);
$bSoc = $chk->fetchColumn();
if ($bSoc === false) exit(json_encode(['ok'=>false,'error'=>'Bien introuvable.']));
if (!$isSuperAdmin && $societeId > 0 && (int)$bSoc !== $societeId) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre.'])); }

/** Cherche une extraction déjà payée (metadata GED → cache dpe_diags). */
$cacheFields = function() use ($pdo, $docId, $bienId): array {
    try {
        $stM = $pdo->prepare("SELECT metadata FROM ged_documents WHERE id = ? LIMIT 1");
        $stM->execute([$docId]);
        $meta = json_decode((string)($stM->fetchColumn() ?: ''), true) ?: [];
        $ia   = $meta['extra']['ia_result_last']['fields'] ?? null;
        if (is_array($ia) && $ia) return ['fields'=>$ia, 'url'=>(string)($meta['public_url'] ?? ''), 'src'=>'ia_ged'];
    } catch (Throwable $e) {}
    try {
        $stJ = $pdo->prepare("SELECT champs_extraits_json FROM dpe_diags
                              WHERE id_bien = ? AND champs_extraits_json IS NOT NULL AND CHAR_LENGTH(champs_extraits_json) > 2
                              ORDER BY est_diag_principal DESC, date_creation DESC, id DESC LIMIT 1");
        $stJ->execute([$bienId]);
        $dec = json_decode((string)($stJ->fetchColumn() ?: ''), true);
        if (is_array($dec) && $dec) return ['fields'=>$dec, 'url'=>'', 'src'=>'ia_cache'];
    } catch (Throwable $e) {}
    return [];
};

try {
    $method = ''; $publicUrl = '';

    if (!$force) {
        // ── GRATUIT : réutiliser l'extraction déjà payée ──
        $c = $cacheFields();
        if (!$c) {
            exit(json_encode([
                'ok'       => false,
                'need_ia'  => true,
                'message'  => 'Aucune extraction en cache pour ce DPE. Lancer l\'analyse IA (payante, 1 seule fois) ?',
            ], JSON_UNESCAPED_UNICODE));
        }
        $fields = $c['fields']; $publicUrl = $c['url']; $method = $c['src'];
    } else {
        // ── PAYANT (1×) : analyse IA complète → diagnostics_inclus fiable ──
        $pdfPath = function_exists('mr_ged_doc_path') ? (string)mr_ged_doc_path($pdo, $docId) : '';
        if ($pdfPath === '' || !is_file($pdfPath)) exit(json_encode(['ok'=>false,'error'=>'PDF du DPE introuvable (doc #'.$docId.').']));
        $an = dpe_analyser($pdfPath, $bienId);
        if (empty($an['ok']) || empty($an['fields'])) exit(json_encode(['ok'=>false,'error'=>$an['error'] ?? 'Analyse IA indisponible.']));
        $fields = $an['fields']; $method = $an['method'] ?: 'ia';
        if (function_exists('db_reconnect_fresh')) { try { $pdo = db_reconnect_fresh(); } catch (Throwable $e) {} }
    }

    // Persiste dans dpe_diags + biens (même chemin que l'analyse Documents).
    apply_dpe_extracted_to_bien($pdo, $bienId, $fields, $publicUrl ?: null, $userId ?: null);

    // Couverture résultante (pour le message + le rechargement checklist).
    $cov = bien_diag_coverage($pdo, $bienId);
    $labels = ['DIAG_DPE'=>'DPE','DIAG_ERP'=>'ERP','DIAG_PLOMB'=>'plomb','DIAG_AMIANTE'=>'amiante',
               'DIAG_GAZ'=>'gaz','DIAG_ELEC'=>'électricité','DIAG_TERMITES'=>'termites','SURFACE_CARREZ'=>'Carrez/Boutin'];
    $covLbls = array_values(array_map(fn($c)=>$labels[$c] ?? $c, array_keys($cov['covered'])));

    echo json_encode([
        'ok'        => true,
        'source'    => $force ? 'ia' : 'cache',   // 'cache' = gratuit | 'ia' = payant
        'method'    => $method,
        'coverage'  => array_keys($cov['covered']),
        'coverage_source' => $cov['source'],      // 'explicit' (diagnostics_inclus) | 'inference'
        'message'   => ($force ? '🧠 IA · ' : '💾 cache (gratuit) · ')
                       . ($covLbls ? count($covLbls).' diag(s) couvert(s) par le dossier : '.implode(', ', $covLbls)
                                   : 'aucun diagnostic complémentaire détecté dans le dossier'),
        'reload'    => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dpe_reanalyze_diags] '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Erreur : '.$e->getMessage()]);
}
