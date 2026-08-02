<?php
/**
 * api/bail_reextract.php — (Ré)extraction des données d'un bail depuis son PDF signé DÉJÀ en GED.
 *
 * Le bail signé peut déjà être attaché (upload manuel, classement OneDrive, ou création du bail
 * par l'import CRG) SANS que ses données structurées aient été extraites. Cet endpoint retrouve
 * le PDF du bail signé en GED, le ré-analyse (bail_analyse_pdf) et remplit `bien_baux`.
 *
 * POST : id_bail (= bien_baux.id, requis), overwrite (0|1), csrf_token (form 'bail_reextract').
 *   - overwrite=0 : remplit uniquement les champs vides (défaut, ne touche pas la saisie manuelle).
 *   - overwrite=1 : écrase depuis le PDF (force la mise à jour).
 * Sécurité : login + CSRF + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
set_time_limit(120);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ged_access.php';          // ged_internal_path()
require_once __DIR__ . '/../inc/bail_analyse_service.php';// bail_analyse_pdf() + apply
require_once __DIR__ . '/../inc/bail_cautions.php';       // bail_analyse_apply_cautions()
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('bail_reextract');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

$bailId    = isset($_POST['id_bail']) && ctype_digit((string)$_POST['id_bail']) ? (int)$_POST['id_bail'] : 0;
$overwrite = !empty($_POST['overwrite']) && (string)$_POST['overwrite'] === '1';
$fresh     = !empty($_POST['fresh'])     && (string)$_POST['fresh']     === '1'; // 1 = forcer une IA payante
if ($bailId <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bail requis']); exit; }

// Le bail doit exister — on lit aussi la metadata (cache d'analyse déjà payé).
$st = $pdo->prepare("SELECT id, metadata FROM bien_baux WHERE id = ? LIMIT 1");
$st->execute([$bailId]);
$bailRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$bailRow) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Bail introuvable']); exit; }

// ── CACHE-FIRST (anti-coût IA) : réutiliser l'analyse déjà stockée si elle existe ──
$data = null; $source = null; $docId = 0;
if (!$fresh) {
    $meta = json_decode((string)($bailRow['metadata'] ?? ''), true) ?: [];
    if (!empty($meta['analyse_ia_bail']['data']) && is_array($meta['analyse_ia_bail']['data'])) {
        $data = $meta['analyse_ia_bail']['data'];
        $source = 'cache';
    }
}

// ── Sinon (pas de cache, ou fresh=1) : retrouver le PDF du bail signé et analyser (payant) ──
if ($data === null) {
    $st = $pdo->prepare("SELECT id FROM ged_documents
                         WHERE id_bail = ? AND COALESCE(status,'active') = 'active'
                           AND UPPER(document_type) IN ('BAIL_SIGNE','BAIL')
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$bailId]);
    $docId = (int)($st->fetchColumn() ?: 0);
    if ($docId <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Aucun bail signé (PDF) attaché à ce bail. Charge-le d\'abord.']);
        exit;
    }
    // Résolution du chemin physique (copie durable GED) via le point de passage central.
    $pdfPath = ged_internal_path($docId, 'ocr');
    if (!$pdfPath || !is_file($pdfPath)) {
        echo json_encode(['ok'=>false,'error'=>'PDF du bail introuvable/illisible (doc GED #'.$docId.')']);
        exit;
    }
    // Analyse IA (OpenAI, ~10-40 s) — le SEUL cas payant.
    $an = bail_analyse_pdf($pdfPath);
    if (empty($an['ok']) || empty($an['data'])) {
        echo json_encode(['ok'=>false,'error'=>$an['error'] ?? 'Analyse indisponible', 'doc_id'=>$docId]);
        exit;
    }
    $data = $an['data']; $source = 'ia';
    // Reconnexion PDO fraîche : l'appel OpenAI peut avoir dépassé le wait_timeout MySQL (Hostinger).
    if (function_exists('db_reconnect_fresh')) {
        try { $pdo = db_reconnect_fresh(); } catch (Throwable $e) { error_log('[bail_reextract] reconnect: '.$e->getMessage()); }
    }
}

$filled = bail_analyse_apply_to_bien_baux($pdo, $bailId, $data, $overwrite);

// Cautions → tiers reliés au bail (rôle 'caution', scopé bail). Non destructif, idempotent.
$cautionsAjoutees = [];
try {
    $cautionsAjoutees = bail_analyse_apply_cautions($pdo, $bailId, $data);
} catch (Throwable $e) { error_log('[bail_reextract] cautions: '.$e->getMessage()); }

// Descriptif du bien : rempli UNIQUEMENT si vide (jamais d'écrasement), comme à l'upload.
try {
    $bienId = (int)($pdo->query("SELECT id_bien FROM bien_baux WHERE id=".(int)$bailId)->fetchColumn() ?: 0);
    $descr  = trim((string)($an['data']['bien']['description'] ?? ''));
    if ($descr !== '' && $bienId > 0) {
        $cur = $pdo->prepare("SELECT description FROM biens WHERE id=?"); $cur->execute([$bienId]);
        if (trim((string)($cur->fetchColumn() ?: '')) === '') {
            $pdo->prepare("UPDATE biens SET description=?, date_modification=NOW() WHERE id=?")->execute([$descr, $bienId]);
            $filled[] = 'bien.description';
        }
    }
} catch (Throwable $e) { error_log('[bail_reextract] descr: '.$e->getMessage()); }

// Libellés lisibles des colonnes remplies (pour l'UI).
$labels = [
    'bail_nature'=>'nature', 'loyer_mensuel_hc'=>'loyer HC', 'charges_mensuelles'=>'charges',
    'depot_garantie'=>'dépôt de garantie', 'date_signature'=>'date de signature',
    'date_prise_effet'=>'date d\'effet', 'date_fin'=>'date de fin', 'indice_type'=>'indice (IRL…)',
    'indice_trimestre'=>'trimestre indice', 'indice_valeur'=>'valeur indice',
    'date_revision_jour_mois'=>'date de révision', 'locataire_nom'=>'locataire', 'locataire_prenom'=>'prénom',
    'conditions_particulieres'=>'clauses particulières', 'bien.description'=>'descriptif du bien',
];
$filledLabels = array_values(array_unique(array_map(fn($c) => $labels[$c] ?? $c, $filled)));

$srcTag = $source === 'cache' ? '💾 cache (gratuit) · ' : '🧠 IA · ';
$nbCautions = count($cautionsAjoutees);
$cautionMsg = $nbCautions ? ' · 🛡️ ' . $nbCautions . ' caution(s) : ' . implode(', ', $cautionsAjoutees) : '';
$baseMsg = count($filledLabels)
    ? ($srcTag . ($overwrite ? 'Ré-extrait — ' : 'Extrait — ') . count($filledLabels) . ' champ(s) : ' . implode(', ', $filledLabels))
    : ($srcTag . 'Rien à compléter (bail déjà renseigné).');
echo json_encode([
    'ok'            => true,
    'id_bail'       => $bailId,
    'doc_id'        => $docId,
    'source'        => $source,          // 'cache' (gratuit) | 'ia' (payant)
    'overwrite'     => $overwrite,
    'n'             => count($filledLabels),
    'champs'        => $filled,
    'champs_labels' => $filledLabels,
    'cautions'      => $cautionsAjoutees,
    'message'       => $baseMsg . $cautionMsg,
    'reload'        => (count($filledLabels) > 0 || $nbCautions > 0),
], JSON_UNESCAPED_UNICODE);
