<?php
declare(strict_types=1);
/**
 * api/financement_upload.php — Chargement DIRECT d'un document dans un dossier financier (T1.1).
 * « Le document remplit le dossier » : upload → GED (gus_commit_document) → lien FIN + id_dossier.
 * Le document est CONSERVÉ dans la GED générale ; on ne fait que le rattacher au dossier.
 *
 * multipart/form-data : file, id_dossier, categorie (code GED de la rubrique Financement), csrf.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/financement.php';         // + ged_document_links (gus_commit_document)
require_once __DIR__ . '/../inc/document_requests.php';   // dr_allowed_upload (validation sûre réutilisée)
require_login();
header('Content-Type: application/json; charset=utf-8');
function fo(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fo(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('financement');
$isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)current_role_id(), [1, 7], true);
if (!$isAdmin) fo(['ok' => false, 'error' => 'Réservé administrateurs.'], 403);

$pdo = $GLOBALS['pdo'];
$dossierId = (int)($_POST['id_dossier'] ?? 0);
$dossier = fin_scope_ok($pdo, $dossierId);
if (!$dossier) fo(['ok' => false, 'error' => 'Dossier hors périmètre.'], 403);

$cat = (string)($_POST['categorie'] ?? 'AUTRE');
if (!isset(fin_ged_categories()[$cat])) $cat = 'AUTRE';

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fo(['ok' => false, 'error' => 'Aucun fichier reçu.'], 400);
}
$f = $_FILES['file'];
if ((int)$f['size'] > 25 * 1024 * 1024) fo(['ok' => false, 'error' => 'Fichier trop volumineux (max 25 Mo).'], 400);
$chk = dr_allowed_upload((string)$f['name'], (string)($f['type'] ?? ''), (string)$f['tmp_name']);
if (empty($chk['ok'])) fo(['ok' => false, 'error' => $chk['error']], 400);

$soc = (int)($dossier['id_societe'] ?? fin_soc());
$age = $dossier['id_agence'] !== null ? (int)$dossier['id_agence'] : null;

// Fichier persistant (hors racine web servable, servi ensuite par api/ged_doc_serve.php)
$permDir = __DIR__ . '/../uploads/financement/' . $dossierId . '/';
if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
$ext = pathinfo((string)$f['name'], PATHINFO_EXTENSION);
$permName = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext ? '.' . $ext : '');
$permPath = $permDir . $permName;
if (!@move_uploaded_file($f['tmp_name'], $permPath)) fo(['ok' => false, 'error' => 'Échec de l\'enregistrement du fichier.'], 500);
$publicUrl = '/uploads/financement/' . $dossierId . '/' . $permName;
$dateDoc = date('Y-m-d');

try {
    $res = gus_commit_document($pdo,
        [
            'path_on_disk'  => $permPath,
            'name_original' => (string)$f['name'],
            'mime_type'     => (string)($f['type'] ?: 'application/octet-stream'),
            'size_bytes'    => (int)($f['size'] ?? (filesize($permPath) ?: 0)),
            'public_url'    => $publicUrl,
        ],
        [
            'document_type'   => $cat,                 // code GED de la rubrique Financement
            'source_module'   => 'FINANCEMENT',
            'security_level'  => 'interne',
            'societe_id'      => $soc,
            'agence_id'       => $age,
            'tenant_id'       => $soc,
            'created_by'      => fin_uid(),
            'storage_provider' => 'local',
            'name_display'    => (string)$f['name'],
            'metadata_extra'  => ['source' => 'financement', 'dossier_id' => $dossierId, 'categorie' => $cat, 'doc_date' => $dateDoc],
            'naming_ctx'      => ['type_doc' => $cat, 'entity_type' => 'FIN', 'entity_id' => $dossierId, 'date_doc' => $dateDoc, 'source_filename' => (string)$f['name']],
        ],
        // Lien vers le dossier financier (FIN) — la catégorie est rangée dans relation_type
        [['entity_type' => 'FIN', 'entity_id' => $dossierId, 'relation_type' => $cat]]
    );
} catch (Throwable $e) {
    fo(['ok' => false, 'error' => 'GED : ' . $e->getMessage()], 500);
}
if (empty($res['ok'])) fo(['ok' => false, 'error' => 'GED : ' . json_encode($res['errors'] ?? ['inconnu'], JSON_UNESCAPED_UNICODE)], 500);

AuditLog::log($pdo, 'GED_UPLOAD', 'fin_dossier', $dossierId, [], ['doc_id' => $res['doc_id'] ?? null, 'categorie' => $cat]);
fo(['ok' => true, 'doc_id' => $res['doc_id'] ?? null]);
