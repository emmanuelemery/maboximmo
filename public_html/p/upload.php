<?php
declare(strict_types=1);
/**
 * p/upload.php — Endpoint upload sécurisé côté propriétaire (lien magique)
 *
 * Accepte : POST multipart avec t (token), id_bien, files[]
 * Sécurité : token valide + non expiré + bien appartenant au contact lié au token
 * Stockage : uploads/investisseur_externe/YYYY/MM/
 * Trace : biens_documents avec uploaded_by_externe=1 + id_partage_source
 * Notification : email au gestionnaire MaBoxImmo
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/investisseur_partage.php';
require_once __DIR__ . '/../inc/investisseur_contacts.php';

$pdo = $GLOBALS['pdo'];

function back_with_msg(string $token, string $msg, bool $ok = true): void {
    $url = (function_exists('app_url') ? app_url('/p/investisseur.php') : '/p/investisseur.php') . '?t=' . $token . '&u=' . ($ok ? '1' : '0') . '&m=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

$token = (string)($_POST['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); die('Lien invalide.'); }

$p = inv_partage_load_by_token($pdo, $token);
if (!$p || !inv_partage_is_valid($p)) { http_response_code(403); die('Lien expiré ou révoqué.'); }

// Vérification mot de passe si présent
if (!empty($p['password_hash'])) {
    $sessKey = 'inv_pub_auth_' . substr((string)$p['token'], 0, 16);
    if (empty($_SESSION[$sessKey])) { http_response_code(403); die('Session non authentifiée. Retournez à la page et saisissez le mot de passe.'); }
}

$idBien = (int)($_POST['id_bien'] ?? 0);
if ($idBien <= 0) back_with_msg($token, 'Bien non spécifié', false);

// Vérif que ce bien appartient bien à un proprio du contact lié au partage
$idContact = (int)($p['id_contact_externe'] ?? 0);
if ($idContact <= 0) back_with_msg($token, 'Lien sans contact rattaché (upload impossible)', false);
$autorisedIds = inv_contact_biens_ids($pdo, $idContact);
if (!in_array($idBien, $autorisedIds, true)) back_with_msg($token, 'Bien non autorisé sur ce lien', false);

// Validation fichiers
$files = $_FILES['files'] ?? null;
if (!$files || empty($files['name'][0])) back_with_msg($token, 'Aucun fichier sélectionné', false);

$allowedExt = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','eml','msg','txt','odt','ods'];
$maxSize = 10 * 1024 * 1024; // 10 Mo
$uploadBase = __DIR__ . '/../uploads/investisseur_externe/' . date('Y/m');
if (!is_dir($uploadBase)) @mkdir($uploadBase, 0755, true);

$nbFiles = count($files['name']);
$savedCount = 0;
$errors = [];
$insertSql = "INSERT INTO biens_documents
    (id_bien, type_document, libelle, url_fichier, nom_original, mime_type, taille_octets,
     date_upload, uploaded_by_externe, id_partage_source, visible_proprietaire)
    VALUES (:id_bien, 'uploaded_by_proprio', :lbl, :url, :orig, :mime, :size,
            NOW(), 1, :id_partage, 1)";
$ins = $pdo->prepare($insertSql);

for ($i = 0; $i < $nbFiles; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = $files['name'][$i] . ' : erreur upload';
        continue;
    }
    $origName = (string)$files['name'][$i];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $errors[] = $origName . ' : extension refusée';
        continue;
    }
    if ($files['size'][$i] > $maxSize) {
        $errors[] = $origName . ' : trop lourd (>10 Mo)';
        continue;
    }
    $safe = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-z0-9._-]/i', '_', $origName);
    $destAbs = $uploadBase . '/' . $safe;
    if (!move_uploaded_file($files['tmp_name'][$i], $destAbs)) {
        $errors[] = $origName . ' : échec enregistrement';
        continue;
    }
    $relPath = 'uploads/investisseur_externe/' . date('Y/m') . '/' . $safe;
    try {
        $ins->bindValue(':id_bien', $idBien, PDO::PARAM_INT);
        $ins->bindValue(':lbl', pathinfo($origName, PATHINFO_FILENAME));
        $ins->bindValue(':url', $relPath);
        $ins->bindValue(':orig', $origName);
        $ins->bindValue(':mime', (string)$files['type'][$i]);
        $ins->bindValue(':size', (int)$files['size'][$i], PDO::PARAM_INT);
        $ins->bindValue(':id_partage', (int)$p['id'], PDO::PARAM_INT);
        $ins->execute();
        $savedCount++;
    } catch (Throwable $e) {
        @unlink($destAbs);
        $errors[] = $origName . ' : ' . $e->getMessage();
    }
}

// Notification email au user qui a créé le lien
if ($savedCount > 0 && !empty($p['id_user_crea'])) {
    try {
        require_once __DIR__ . '/../inc/mailer.php';
        $st = $pdo->prepare("SELECT email, prenom, nom FROM users WHERE id = :id");
        $st->bindValue(':id', (int)$p['id_user_crea'], PDO::PARAM_INT);
        $st->execute();
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && !empty($u['email'])) {
            $st = $pdo->prepare("SELECT designation, reference_bien FROM biens WHERE id = :id");
            $st->bindValue(':id', $idBien, PDO::PARAM_INT);
            $st->execute();
            $b = $st->fetch(PDO::FETCH_ASSOC);
            $bienLabel = $b ? trim(($b['designation'] ?? '') . ' (' . ($b['reference_bien'] ?? '#' . $idBien) . ')') : 'bien #' . $idBien;
            $destName = $p['destinataire_nom'] ?: $p['destinataire_email'];
            $body = '<p>Bonjour ' . htmlspecialchars((string)$u['prenom']) . ',</p>'
                  . '<p><strong>' . htmlspecialchars($destName) . '</strong> a ajouté ' . $savedCount . ' document' . ($savedCount > 1 ? 's' : '') . ' via son lien MaBoxImmo.</p>'
                  . '<p><strong>Bien :</strong> ' . htmlspecialchars($bienLabel) . '</p>'
                  . '<p>Vous les retrouverez dans la fiche du bien côté MaBoxImmo.</p>';
            send_mail((string)$u['email'], '📥 Nouveaux documents ajoutés — ' . $destName, $body, [], true);
        }
    } catch (Throwable $e) {
        // Silencieux : l'upload a réussi, juste la notif qui a foiré
    }
}

$msg = $savedCount . ' document' . ($savedCount > 1 ? 's' : '') . ' envoyé' . ($savedCount > 1 ? 's' : '') . ' avec succès.';
if (!empty($errors)) $msg .= ' ' . count($errors) . ' erreur(s) : ' . implode(' — ', $errors);
back_with_msg($token, $msg, $savedCount > 0);
