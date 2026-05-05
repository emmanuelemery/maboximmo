<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * api/agence_doc_officiel_upload.php — Upload d'un document officiel
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Reçoit un fichier (carte pro, KBIS, garant, RC, barème), le stocke,
 * déclenche l'OCR Claude Sonnet, écrit en BDD avec statut='actif' (en
 * archivant l'ancien doc actif du même type), et réplique les champs
 * OCR vers la table `agences` pour rester compat avec les supports.
 *
 * POST :
 *   id_agence        (int)
 *   type_document    (string : carte_pro|kbis|garant_financier|rc_pro|bareme_honoraires)
 *   fichier          (multipart file : pdf/jpg/png/webp ≤ 10 Mo)
 *   commentaire      (optionnel)
 *
 * Réponse JSON :
 *   { ok, doc_id, statut, ocr: {numero,emetteur,date_validite,confidence,…},
 *     repliquage_agences: bool, fichier_path }
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
require_once dirname(__DIR__) . '/inc/agence_doc_officiel_ocr.php';
require_once dirname(__DIR__) . '/inc/agence_doc_officiel_repliquer.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

// CSRF — token 'ajouter_bien' (générique partagé avec bien_detail / formulaires
// d'agence) — la modale et la page docs officiels utilisent ce form_id.
if (function_exists('verify_csrf_any')) {
    verify_csrf_any('ajouter_bien');
}

$pdo          = $GLOBALS['pdo'];
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$agenceIdSess = (int)($_SESSION['id_agence']  ?? 0);
$userId       = (int)($_SESSION['user_id']    ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role']    ?? 0) === 1;

$idAgence = isset($_POST['id_agence']) && ctype_digit((string)$_POST['id_agence']) ? (int)$_POST['id_agence'] : 0;
$typeDoc  = (string)($_POST['type_document'] ?? '');
$comment  = isset($_POST['commentaire']) ? mb_substr(trim((string)$_POST['commentaire']), 0, 2000) : null;

$typesValides = ['carte_pro','kbis','garant_financier','rc_pro','bareme_honoraires'];
if ($idAgence <= 0 || !in_array($typeDoc, $typesValides, true)) {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres invalides']));
}

// Scope : super admin OK partout, sinon agence courante uniquement
try {
    $st = $pdo->prepare("SELECT id_societe FROM agences WHERE id = ? LIMIT 1");
    $st->execute([$idAgence]);
    $idSocAgence = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {
    exit(json_encode(['ok' => false, 'error' => 'Agence introuvable']));
}
if (!$isSuperAdmin) {
    if ($agenceIdSess > 0 && $agenceIdSess !== $idAgence) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope agence']));
    }
    if ($societeId > 0 && $idSocAgence > 0 && $idSocAgence !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }
}

// Validation fichier
if (empty($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    exit(json_encode(['ok' => false, 'error' => 'Fichier manquant ou invalide']));
}
$tmp   = (string)$_FILES['fichier']['tmp_name'];
$size  = (int)$_FILES['fichier']['size'];
$mime  = mime_content_type($tmp) ?: 'application/octet-stream';
$nameOriginal = (string)$_FILES['fichier']['name'];

$mimesOk = ['application/pdf','image/jpeg','image/png','image/webp'];
if (!in_array($mime, $mimesOk, true)) {
    exit(json_encode(['ok' => false, 'error' => 'Type de fichier non autorisé : ' . $mime]));
}
if ($size > 10 * 1024 * 1024) {
    exit(json_encode(['ok' => false, 'error' => 'Fichier > 10 Mo']));
}

// Hash pour détection doublon stricte (même fichier réuploadé)
$hash = hash_file('sha256', $tmp);

// Stockage sur disque (V1 : local uniquement, GED en V2)
$dirRel  = '/uploads/agences/' . $idAgence . '/documents_officiels/';
$dirAbs  = dirname(__DIR__) . $dirRel;
if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
    exit(json_encode(['ok' => false, 'error' => 'mkdir failed']));
}
$ext = match ($mime) {
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    default           => 'bin',
};
$nomFichier = $typeDoc . '_' . date('Ymd_His') . '_' . substr($hash, 0, 8) . '.' . $ext;
$cheminAbs  = $dirAbs . $nomFichier;
$cheminRel  = $dirRel . $nomFichier;

if (!@move_uploaded_file($tmp, $cheminAbs)) {
    exit(json_encode(['ok' => false, 'error' => 'Echec déplacement fichier']));
}

// OCR Claude Sonnet
$ocr = agence_doc_ocr_extraire($cheminAbs, $typeDoc);

// Insertion BDD + archivage de l'ancien actif (même type)
try {
    $pdo->beginTransaction();

    // Archive l'ancien actif (s'il y en a un)
    $upd = $pdo->prepare("UPDATE agences_documents_officiels
                          SET statut = 'remplace', updated_at = NOW()
                          WHERE id_agence = :a AND type_document = :t AND statut = 'actif'");
    $upd->execute([':a' => $idAgence, ':t' => $typeDoc]);

    $ins = $pdo->prepare("
        INSERT INTO agences_documents_officiels
          (id_agence, type_document, fichier_path, fichier_mime, fichier_taille, fichier_hash,
           numero, emetteur, montant_garantie, date_emission, date_validite,
           ocr_modele, ocr_confidence, ocr_cout_centimes, ocr_json, ocr_at,
           statut, uploaded_by, commentaire)
        VALUES
          (:id_agence, :type, :path, :mime, :taille, :hash,
           :numero, :emetteur, :montant, :date_em, :date_val,
           :ocr_modele, :ocr_conf, :ocr_cout, :ocr_json, :ocr_at,
           'actif', :uploaded_by, :commentaire)
    ");
    $data = $ocr['data'] ?? [];
    $ins->execute([
        ':id_agence'   => $idAgence,
        ':type'        => $typeDoc,
        ':path'        => $cheminRel,
        ':mime'        => $mime,
        ':taille'      => $size,
        ':hash'        => $hash,
        ':numero'      => $data['numero']    ?? null,
        ':emetteur'    => $data['emetteur']  ?? null,
        ':montant'     => $data['montant_garantie'] ?? null,
        ':date_em'     => $data['date_emission']    ?? null,
        ':date_val'    => $data['date_validite']    ?? null,
        ':ocr_modele'  => $ocr['modele'] ?? null,
        ':ocr_conf'    => $ocr['confidence'] ?? 0,
        ':ocr_cout'    => $ocr['cout_centimes'] ?? 0,
        ':ocr_json'    => $ocr['raw_json'] ? json_encode($ocr['raw_json'], JSON_UNESCAPED_UNICODE) : null,
        ':ocr_at'      => $ocr['ok'] ? date('Y-m-d H:i:s') : null,
        ':uploaded_by' => $userId,
        ':commentaire' => $comment,
    ]);
    $docId = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($cheminAbs);
    error_log('[agence_doc_officiel_upload] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'db_insert_failed: ' . $e->getMessage()]));
}

// Réplique vers agences.*
$replique = agence_doc_repliquer_vers_agences($pdo, $idAgence, $typeDoc);

exit(json_encode([
    'ok'                  => true,
    'doc_id'              => $docId,
    'statut'              => 'actif',
    'fichier_path'        => $cheminRel,
    'ocr_ok'              => (bool)($ocr['ok'] ?? false),
    'ocr_erreur'          => $ocr['erreur'] ?? null,
    'ocr_modele'          => $ocr['modele'] ?? null,
    'ocr_cout_centimes'   => $ocr['cout_centimes'] ?? 0,
    'ocr'                 => $ocr['data'] ?? null,
    'repliquage_agences'  => $replique,
], JSON_UNESCAPED_UNICODE));
