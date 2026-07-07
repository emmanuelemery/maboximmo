<?php
/**
 * api/bail_send.php — Envoie le projet de bail aux signataires (preneur + garant) pour
 * signature en ligne : crée les tokens (bail_signatures), envoie le lien sécurisé + le PDF
 * en pièce jointe, passe le bail en `envoye`. Clone fonctionnel de transaction_dossier_mandat_send.
 *
 * POST JSON : { bail_id }  →  { ok, envois:[{role,nom,email,url,sent,statut}] }
 * Auth : user + scope société (bypass admin, exception bailleur rôles 9/10).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bail_signature.php';
require_once dirname(__DIR__) . '/inc/bail_commercial_pdf.php';
require_once dirname(__DIR__) . '/inc/mailer.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$userId=(int)($_SESSION['user_id']??0); $userSoc=(int)($_SESSION['id_societe']??0); $isAdmin=((int)($_SESSION['id_role']??0)===1);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$bailId=(int)($body['bail_id']??0);
if ($bailId<=0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));

$st=$pdo->prepare("SELECT bb.id, bb.statut, bb.id_societe, bb.numero_bail, b.id_proprietaire, b.reference_bien
    FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?");
$st->execute([$bailId]); $bail=$st->fetch(PDO::FETCH_ASSOC);
if (!$bail){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($bail['id_societe']) && (int)$bail['id_societe']!==$userSoc){
    $ok=false;
    if (in_array((int)($_SESSION['id_role']??0),[9,10],true) && (int)($bail['id_proprietaire']??0)>0){
        $c=$pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId,(int)$bail['id_proprietaire']]); $ok=(bool)$c->fetchColumn();
    }
    if(!$ok){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}
if (!in_array($bail['statut'], ['projet','envoye'], true)) {
    exit(json_encode(['ok'=>false,'error'=>'Ce bail est '.$bail['statut'].' : envoi possible uniquement sur un projet.'], JSON_UNESCAPED_UNICODE));
}

try {
    $sigs = bsig_create_for_signataires($pdo, $bailId, $userId);
    if (!$sigs) exit(json_encode(['ok'=>false,'error'=>'Aucun signataire — renseigne l\'email du preneur.'], JSON_UNESCAPED_UNICODE));

    // PDF du projet (filigrané) en pièce jointe.
    $pdfAttach = [];
    try {
        $tmp = bail_commercial_build_pdf($pdo, $bailId, true);
        $clean = sys_get_temp_dir() . '/Bail_' . preg_replace('/[^A-Za-z0-9_-]/','', (string)($bail['numero_bail'] ?: $bailId)) . '.pdf';
        if (@copy($tmp, $clean)) { $pdfAttach = [$clean]; @unlink($tmp); } else { $pdfAttach = [$tmp]; }
    } catch (Throwable $e) { error_log('[bail_send pdf] '.$e->getMessage()); }

    $refBien = $bail['reference_bien'] ?: ('#'.$bailId);
    $envois = [];
    foreach ($sigs as $s) {
        $url = bsig_build_url((string)$s['token']);
        $email = $s['destinataire_email'] ?: null;
        $nom = $s['nom'] ?? ($s['nom_signataire'] ?? 'Madame, Monsieur');
        $sent = false;
        if (($s['statut'] ?? 'pending') === 'pending' && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Signature de votre bail commercial — ' . $refBien;
            $mbody =
                "<p>Bonjour " . htmlspecialchars((string)$nom) . ",</p>" .
                "<p>Votre bail commercial concernant le local <strong>" . htmlspecialchars($refBien) . "</strong> est prêt à être signé en ligne.</p>" .
                "<p>Vous trouverez le projet de bail en pièce jointe. Merci de cliquer sur le lien sécurisé ci-dessous pour le consulter et le signer :</p>" .
                "<p><a href=\"" . htmlspecialchars($url) . "\" style=\"display:inline-block;padding:12px 22px;background:#84A7AB;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;\">Consulter et signer le bail</a></p>" .
                "<p style=\"font-size:12px;color:#666;\">Ou copiez ce lien : " . htmlspecialchars($url) . "</p>" .
                "<p style=\"font-size:12px;color:#666;\">Votre signature sera horodatée et tracée (adresse IP) à des fins de preuve.</p>";
            try {
                $sent = send_mail($email, $subject, $mbody, $pdfAttach, true);
                if ($sent) bsig_mark_sent($pdo, (int)$s['id']);
            } catch (Throwable $e) { error_log('[bail_send mail] '.$e->getMessage()); }
        }
        $envois[] = ['role'=>$s['role_code'] ?? '', 'nom'=>$nom, 'email'=>$email, 'url'=>$url, 'sent'=>$sent, 'statut'=>$s['statut'] ?? 'pending'];
    }

    // Passe le bail en « envoye ».
    if ($bail['statut'] === 'projet') {
        $pdo->prepare("UPDATE bien_baux SET statut='envoye', sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$bailId]);
    }

    echo json_encode(['ok'=>true, 'envois'=>$envois, 'message'=>'Projet de bail envoyé pour signature.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[bail_send] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
