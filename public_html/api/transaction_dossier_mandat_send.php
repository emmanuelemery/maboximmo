<?php
// api/transaction_dossier_mandat_send.php — Envoie le mandat au(x) vendeur(s) pour signature en ligne.
// Crée les tokens (mandat_signatures) + email le lien sécurisé. POST : id_dossier
//   →  { ok, envois:[{nom,email,url,sent,statut}] }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/mandat_signature.php';
require_once __DIR__ . '/../inc/mailer.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
if ($idDossier <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier manquant']); exit; }

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }
if (empty($dossier['id_mandat'])) { echo json_encode(['ok'=>false,'error'=>'aucun mandat à envoyer — créez d\'abord le mandat']); exit; }

// Scope société.
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    $idMandat = (int)$dossier['id_mandat'];
    $sigs = msig_create_for_vendeurs($pdo, $idMandat, $idDossier, (int)current_user_id());
    if (!$sigs) { echo json_encode(['ok'=>false,'error'=>'aucun vendeur avec coordonnées sur le dossier']); exit; }

    // Référence bien pour le mail.
    $stB = $pdo->prepare("SELECT reference_bien, designation FROM biens b JOIN mandats m ON m.id_bien=b.id WHERE m.id=? LIMIT 1");
    $stB->execute([$idMandat]);
    $bienInfo = $stB->fetch(PDO::FETCH_ASSOC) ?: [];
    $refBien  = $bienInfo['reference_bien'] ?: ('#' . $idMandat);

    $envois = [];
    foreach ($sigs as $s) {
        $url = msig_build_url((string)$s['token']);
        $email = $s['destinataire_email'] ?: null;
        $sent = false;

        if ($s['statut'] === 'pending' && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Récupère le nom du signataire pour personnaliser.
            $stT = $pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''), raison_sociale, CONCAT_WS(' ',prenom,nom)) AS nom FROM tiers WHERE id=?");
            $stT->execute([(int)$s['id_tiers']]);
            $nomDest = $stT->fetchColumn() ?: 'Madame, Monsieur';

            $subject = 'Signature de votre mandat de vente — ' . $refBien;
            $body =
                "<p>Bonjour " . htmlspecialchars((string)$nomDest) . ",</p>" .
                "<p>Votre mandat de vente concernant le bien <strong>" . htmlspecialchars($refBien) . "</strong> est prêt à être signé en ligne.</p>" .
                "<p>Merci de cliquer sur le lien sécurisé ci-dessous pour le consulter et le signer :</p>" .
                "<p><a href=\"" . htmlspecialchars($url) . "\" style=\"display:inline-block;padding:12px 22px;background:#0f6cbd;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;\">Consulter et signer le mandat</a></p>" .
                "<p style=\"font-size:12px;color:#666;\">Ou copiez ce lien : " . htmlspecialchars($url) . "</p>" .
                "<p style=\"font-size:12px;color:#666;\">Votre signature sera horodatée et tracée (adresse IP) à des fins de preuve.</p>";

            try {
                $sent = send_mail($email, $subject, $body, [], true);
                if ($sent) msig_mark_sent($pdo, (int)$s['id']);
            } catch (Throwable $e) {
                error_log('[mandat_send mail] ' . $e->getMessage());
            }
        }

        $envois[] = [
            'nom'    => $s['destinataire_email'] ? $email : null,
            'email'  => $email,
            'url'    => $url,
            'sent'   => $sent,
            'statut' => $s['statut'],
        ];
    }

    // Historique : trace l'envoi du mandat dans Communications (mail_history).
    $sentEmails = array_values(array_filter(array_map(
        fn($e) => (!empty($e['sent']) && $e['email']) ? $e['email'] : null, $envois)));
    if ($sentEmails) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS mail_history (
                id INT AUTO_INCREMENT PRIMARY KEY, sent_by INT NOT NULL, subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL, recipient_type VARCHAR(50), recipients_count INT DEFAULT 0,
                recipients_json TEXT, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(sent_by), INDEX(sent_at))");
            $pdo->prepare("INSERT INTO mail_history (sent_by, subject, body, recipient_type, recipients_count, recipients_json)
                           VALUES (?,?,?,?,?,?)")
                ->execute([(int)current_user_id() ?: 0, 'Mandat de vente à signer — ' . $refBien,
                           'Lien de signature en ligne envoyé au(x) vendeur(s).',
                           'dossier_vente:' . $idDossier, count($sentEmails),
                           json_encode(['type'=>'mandat_signature','to'=>$sentEmails], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { error_log('[mandat_send history] ' . $e->getMessage()); }
    }

    echo json_encode(['ok'=>true, 'envois'=>$envois], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_mandat_send] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
