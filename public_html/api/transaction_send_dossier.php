<?php
// api/transaction_send_dossier.php — Envoi du dossier à un commercialisateur/notaire (V0)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien   = (int)(post('id_bien') ?? 0);
$email    = trim((string)(post('email') ?? ''));
$nom      = trim((string)(post('destinataire_nom') ?? ''));
$msgExtra = trim((string)(post('message_extra') ?? ''));
$docsIds  = $_POST['docs'] ?? [];

if ($idBien <= 0)                                         { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL))           { echo json_encode(['ok'=>false,'error'=>'email invalide']); exit; }
if (!is_array($docsIds))                                  { $docsIds = []; }
$docsIds = array_values(array_unique(array_map('intval', $docsIds)));

try {
    // Récupère le bien
    $stmt = $pdo->prepare('SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.code_postal, b.ville,
                                  b.surface_habitable, b.nb_pieces, b.prix_demande_initial,
                                  b.id_societe FROM biens WHERE b.id = ? LIMIT 1');
    $stmt->execute([$idBien]);
    $bien = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bien) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }

    // Scope
    $roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
    $idSoc  = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
    if ($roleId !== 1 && $idSoc !== null && $bien['id_societe'] !== null && (int)$bien['id_societe'] !== $idSoc) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
    }

    // Collecte les PJ
    $attachments = [];
    if (!empty($docsIds)) {
        $in = implode(',', array_fill(0, count($docsIds), '?'));
        $st = $pdo->prepare("SELECT id, name_display, name_file
                             FROM ged_documents
                             WHERE id IN ($in) AND status='active' AND source_module='05_TRANSACTION'
                               AND JSON_EXTRACT(metadata,'$.classement.bien_id_bdd') = ?");
        $params = array_merge($docsIds, [$idBien]);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $p = __DIR__ . '/../uploads/ged/transaction/' . $idBien . '/' . $r['name_file'];
            if (is_file($p) && is_readable($p)) {
                $attachments[] = $p;
            }
        }
    }

    // Mail
    $adresse = trim((string)$bien['adresse_1'] . ' — ' . $bien['code_postal'] . ' ' . $bien['ville']);
    $subject = 'Dossier de commercialisation — ' . ($bien['reference_bien'] ?: '#' . $idBien) . ' — ' . $bien['ville'];
    $body  = "Bonjour" . ($nom ? ' ' . $nom : '') . ",\n\n";
    $body .= "Vous trouverez ci-joint les éléments du dossier de commercialisation du bien suivant :\n";
    $body .= "  Référence : " . ($bien['reference_bien'] ?: '#' . $idBien) . "\n";
    $body .= "  Adresse   : " . $adresse . "\n";
    if ($bien['surface_habitable']) $body .= "  Surface   : " . number_format((float)$bien['surface_habitable'], 1, ',', ' ') . " m²\n";
    if ($bien['nb_pieces'])         $body .= "  Pièces    : " . (int)$bien['nb_pieces'] . "\n";
    if ($bien['prix_demande_initial']) $body .= "  Prix      : " . number_format((float)$bien['prix_demande_initial'], 0, ',', ' ') . " €\n";
    $body .= "\nMerci de nous transmettre vos retours ou offres directement par retour de mail.\n\n";
    if ($msgExtra !== '') $body .= "—\n" . $msgExtra . "\n\n";
    $body .= "Cordialement,\nL'équipe MaBoxImmo";

    if (!function_exists('send_mail')) {
        require_once __DIR__ . '/../inc/mailer.php';
    }
    $sent = send_mail($email, $subject, $body, $attachments, false);
    if (!$sent) {
        echo json_encode(['ok'=>false, 'error'=>'envoi mail échoué', 'debug'=>'voir error_log']);
        exit;
    }

    // Trace dans l'historique : pose un lead "info" pour audit (optionnel mais utile)
    try {
        $stAnn = $pdo->prepare('SELECT MAX(id) FROM annonces WHERE id_bien = ?');
        $stAnn->execute([$idBien]);
        $annId = (int)($stAnn->fetchColumn() ?: 0);
        if ($annId > 0) {
            $log = $pdo->prepare('INSERT INTO leads_annonces
                (id_annonce, id_bien, source, source_detail, type_contact,
                 nom, email, message, statut, date_creation, date_modification)
                VALUES (?, ?, "interne", "transaction_send_dossier", "envoi_dossier",
                 ?, ?, ?, "envoye", NOW(), NOW())');
            $log->execute([$annId, $idBien, $nom ?: 'commercialisateur',
                $email, 'Envoi dossier — ' . count($attachments) . ' doc(s)']);
        }
    } catch (Throwable $e) { /* non bloquant */ }

    echo json_encode(['ok'=>true, 'to'=>$email, 'nb_attachments'=>count($attachments)]);
} catch (Throwable $e) {
    error_log('[transaction_send_dossier] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
