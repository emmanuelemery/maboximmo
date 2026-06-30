<?php
declare(strict_types=1);
set_time_limit(120);
/**
 * api/rh_cv_upload.php — Dépôt d'un CV ou d'une lettre de motivation dans le dossier RH
 * du collaborateur, avec EXTRACTION du téléphone + email PERSONNELS (remplis si vides).
 *
 * POST multipart : { user_id, kind=cv|lettre_motivation, fichier (PDF/JPG/PNG), csrf_token }
 * Réponse JSON : { success, doc_id, url, extracted:{email,telephone}, applied:{...} }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_document_extractor.php'; // rhDxExtractText()
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'error'=>'POST requis'])); }
// Action RH interne : protégée par require_login (pas de CSRF strict — cohérent avec l'upload RH).

$pdo      = $GLOBALS['pdo'];
$meId     = (int)($_SESSION['user_id'] ?? 0) ?: null;
$targetId = isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$kind     = (($_POST['kind'] ?? '') === 'lettre_motivation') ? 'lettre_motivation' : 'cv';
if ($targetId <= 0) exit(json_encode(['success'=>false,'error'=>'user_id requis']));

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) exit(json_encode(['success'=>false,'error'=>'Aucun fichier reçu']));
$file = $_FILES['fichier'];
$finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
$okMimes = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
if (!isset($okMimes[$mime])) exit(json_encode(['success'=>false,'error'=>'Format non autorisé (PDF, JPG, PNG)']));
if ($file['size'] > 20*1024*1024) exit(json_encode(['success'=>false,'error'=>'Fichier trop volumineux (max 20 Mo)']));

// Stockage (même dossier que les autres docs RH)
$dir = __DIR__ . '/../uploads/rh_docs/' . $targetId . '/';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$name = 'rhdoc_' . $targetId . '_' . $kind . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $okMimes[$mime];
$dest = $dir . $name;
if (!move_uploaded_file($file['tmp_name'], $dest)) exit(json_encode(['success'=>false,'error'=>'Erreur enregistrement']));
$url = './uploads/rh_docs/' . $targetId . '/' . $name;

// Insertion rh_documents
$uRow = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id=?"); $uRow->execute([$targetId]); $u = $uRow->fetch(PDO::FETCH_ASSOC) ?: [];
$label = $kind === 'cv' ? 'CV' : 'Lettre de motivation';
$docId = 0;
try {
    $pdo->prepare("INSERT INTO rh_documents (id_user,id_societe,id_agence,categorie,type_document,label,filename,original_name,file_path,version,upload_date,uploaded_by,actif)
                   VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),?,1)")
        ->execute([$targetId, $u['id_societe']??null, $u['id_agence']??null, 'candidature', $kind, $label, $name, $file['name'], $dest, $meId]);
    $docId = (int)$pdo->lastInsertId();
} catch (Throwable $e) { /* table rh_documents : best-effort */ }

// Extraction texte (PDF natif ; OCR Vision possible côté extracteur)
$text = '';
try { $ext = rhDxExtractText($dest, $mime); $text = (string)($ext['text'] ?? ''); } catch (Throwable) {}

$email = ''; $tel = '';
if ($text !== '') {
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) $email = strtolower(trim($m[0]));
    // Téléphone FR : 0X.. ou +33, séparateurs variés
    if (preg_match('/(?:\+33|0033|0)\s*[1-9](?:[\s.\-]?\d{2}){4}/', $text, $mt)) {
        $tel = preg_replace('/[\s.\-]+/', ' ', trim($mt[0]));
    }
}

// Application au profil PERSO si vide (jamais d'écrasement)
$applied = [];
try {
    $cur = $pdo->prepare("SELECT email_perso, telephone FROM users WHERE id=?"); $cur->execute([$targetId]); $c = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
    $sets = []; $par = [];
    if ($email !== '' && empty($c['email_perso'])) { $sets[]='email_perso=?'; $par[]=$email; $applied['email_perso']=$email; }
    if ($tel   !== '' && empty($c['telephone']))   { $sets[]='telephone=?';   $par[]=$tel;   $applied['telephone']=$tel; }
    if ($sets) { $par[]=$targetId; $pdo->prepare("UPDATE users SET ".implode(',', $sets)." WHERE id=?")->execute($par); }
} catch (Throwable $e) { /* colonne email_perso non migrée : on ignore l'application */ }

echo json_encode([
    'success'  => true,
    'doc_id'   => $docId,
    'url'      => $url,
    'label'    => $label,
    'extracted'=> ['email'=>$email, 'telephone'=>$tel],
    'applied'  => $applied,
], JSON_UNESCAPED_UNICODE);
