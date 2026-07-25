<?php
/**
 * api/mail_history_view.php — Renvoie le contenu d'un mail envoyé (historique) pour l'afficher.
 *   GET ?id=<mail_history.id>  →  { ok, subject, body_html, sent_at, envoyeur, recipients[] }
 * Auth : utilisateur connecté. Le corps est stocké en texte (composeur) → rendu en HTML sûr.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) exit(json_encode(['ok'=>false, 'error'=>'id requis']));

try {
    $st = $pdo->prepare("SELECT h.id, h.subject, h.body, h.recipients_json, h.recipients_count, h.sent_at,
                                TRIM(CONCAT_WS(' ', u.prenom, u.nom)) AS envoyeur
                           FROM mail_history h LEFT JOIN users u ON u.id = h.sent_by
                          WHERE h.id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) exit(json_encode(['ok'=>false, 'error'=>'Mail introuvable']));

    $recips = [];
    $rj = json_decode((string)($r['recipients_json'] ?? ''), true);
    if (is_array($rj)) foreach ($rj as $x) { $e = is_array($x) ? (string)($x['email'] ?? '') : (string)$x; if ($e !== '') $recips[] = $e; }

    // Le corps peut être du texte (composeur) ou déjà de l'HTML. Heuristique : présence de balises.
    $body = (string)($r['body'] ?? '');
    $bodyHtml = (strip_tags($body) === $body)
        ? nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))   // texte brut → échappé + <br>
        : $body;                                                // déjà de l'HTML (généré à l'envoi)

    echo json_encode([
        'ok'         => true,
        'subject'    => (string)($r['subject'] ?? ''),
        'body_html'  => $bodyHtml,
        'sent_at'    => (string)($r['sent_at'] ?? ''),
        'envoyeur'   => (string)($r['envoyeur'] ?? ''),
        'recipients' => $recips,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
