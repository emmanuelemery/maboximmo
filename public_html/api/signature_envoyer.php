<?php
/**
 * api/signature_envoyer.php — ENVOYER un document de la GED en signature.
 *
 * POST JSON : { doc_id, signataires:[{role_code, nom, email?, tel?}, …] } → { ok, envois[], … }
 *
 * ⚠️ ON NE SUPPOSE JAMAIS QUE L'AGENCE SIGNE. Trois cas coexistent, et ils sont tous
 * normaux (arbitrage d'Emmanuel, 23/08) : je signe seul (un devis que je valide), je
 * signe avec d'autres (une convention), ou je ne signe pas du tout (un contrat que je
 * fais signer à un salarié). Les signataires sont donc ceux que l'agent DÉSIGNE, et
 * l'agence n'est de la partie que si une zone lui est attribuée.
 *
 * On réutilise les primitives de la cérémonie du bail — `bsig_build_url()`,
 * `bsig_mark_sent()`, `send_mail()`, `sms_envoyer()` — et non une mécanique parallèle :
 * c'est ce qui fait que le suivi en huit étapes, la relance qui réarme et l'expiration
 * qui se dit fonctionnent ici sans une ligne de plus.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/ged_access.php';
require_once dirname(__DIR__) . '/inc/bail_signature.php';
require_once dirname(__DIR__) . '/inc/signature_etat.php';
require_once dirname(__DIR__) . '/inc/mailer.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
if (function_exists('verify_csrf_any')) verify_csrf_any('signature_zones');

$pdo    = $GLOBALS['pdo'];
$body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$docId  = (int)($body['doc_id'] ?? 0);
$userId = function_exists('current_user_id') ? (int)current_user_id() : 0;
$socId  = (int)($_SESSION['id_societe'] ?? 0);
$ko = static function (string $m, int $c = 400) { http_response_code($c); exit(json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE)); };
if ($docId <= 0) $ko('doc_id requis');

try {
    $g = GedAccess::grant($docId, 'preview');
    if (empty($g['path']) || !is_file($g['path'])) $ko('Document inaccessible', 403);
} catch (Throwable $e) { $ko('Document inaccessible : ' . $e->getMessage(), 403); }

$st = $pdo->prepare("SELECT name_display FROM ged_documents WHERE id = ? LIMIT 1");
$st->execute([$docId]);
$nomDoc = (string)($st->fetchColumn() ?: ('Document #' . $docId));

/* Les rôles réellement attendus par le document. Envoyer à quelqu'un qui n'a aucune
   zone à remplir, c'est lui demander de signer dans le vide. */
$st = $pdo->prepare("SELECT DISTINCT role_code FROM signature_zones
                      WHERE ged_document_id = ? AND role_code IS NOT NULL AND role_code <> ''");
$st->execute([$docId]);
$rolesAttendus = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (!$rolesAttendus) $ko('Aucune zone n\'est attribuée à un signataire : ouvre « ✍️ Signer » et attribue-les.');

$demandes = is_array($body['signataires'] ?? null) ? $body['signataires'] : [];
if (!$demandes) $ko('Aucun signataire indiqué.');

$envois = []; $bloques = []; $crees = 0;
foreach ($demandes as $d) {
    $role = trim((string)($d['role_code'] ?? ''));
    $nom  = trim((string)($d['nom'] ?? ''));
    $mail = trim((string)($d['email'] ?? ''));
    $tel  = trim((string)($d['tel'] ?? ''));
    /* ⚠️ Un signataire sans aucune zone est écarté — mais il est DIT. Il était d'abord
       ignoré en silence : l'agent l'avait saisi, croyait l'avoir convoqué, et attendait
       une signature qui ne pouvait pas venir. Écarter est juste ; se taire ne l'est pas. */
    if ($role === '') { $bloques[] = ['role'=>'—', 'nom'=>$nom, 'raison'=>'aucun rôle indiqué']; continue; }
    if (!in_array($role, $rolesAttendus, true)) {
        $bloques[] = ['role'=>$role, 'nom'=>$nom,
                      'raison'=>'aucune zone ne lui est attribuée sur ce document — rien à signer'];
        continue;
    }
    if ($nom === '') { $bloques[] = ['role'=>$role, 'raison'=>'nom manquant']; continue; }

    $mailOk = $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false;
    $telOk  = false;
    if ($tel !== '' && function_exists('sms_normaliser_numero')) $telOk = !empty(sms_normaliser_numero($tel)['ok']);
    /* Le seul vrai blocage : ni mail ni mobile. Il est NOMMÉ, jamais tu — c'est la règle
       posée le 20/08 après le bail #660, où un envoi échouait en silence. */
    if (!$mailOk && !$telOk) { $bloques[] = ['role'=>$role, 'nom'=>$nom, 'raison'=>'ni email valide ni mobile']; continue; }

    try {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO bail_signatures
            (id_bail, objet_type, objet_id, role_code, id_societe, token, statut,
             destinataire_email, destinataire_tel, nom_signataire, vague, vague_ouverte_at, id_user_created, created_at)
            VALUES (NULL, 'ged', ?, ?, ?, ?, 'pending', ?, ?, ?, 1, NOW(), ?, NOW())")
            ->execute([$docId, $role, $socId ?: null, $token, $mailOk ? $mail : null,
                       $telOk ? $tel : null, $nom, $userId ?: null]);
        $sigId = (int)$pdo->lastInsertId();
        $crees++;
    } catch (Throwable $e) {
        error_log('[signature_envoyer insert] ' . $e->getMessage());
        $bloques[] = ['role'=>$role, 'nom'=>$nom, 'raison'=>'création impossible']; continue;
    }

    // ⚠️ La page des DOCUMENTS, pas celle du bail : cf. bsig_build_url().
    $url = bsig_build_url($token, '/p/doc_signature.php');
    $okMail = false; $okSms = false; $errSms = null;

    if ($mailOk) {
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $corps = '<p>Bonjour ' . $h($nom) . ',</p>'
               . '<p>Un document vous est adressé pour signature : <strong>' . $h($nomDoc) . '</strong>.</p>'
               . '<p><a href="' . $h($url) . '" style="display:inline-block;padding:12px 22px;background:#5f8f93;'
               . 'color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">Consulter et signer</a></p>'
               . '<p style="font-size:12px;color:#666;">Ou copiez ce lien : ' . $h($url) . '</p>'
               . '<p style="font-size:12px;color:#666;">Vous pourrez lire l\'intégralité du document avant de signer. '
               . 'Lien valable 48 heures ; votre signature est horodatée et tracée à des fins de preuve.</p>';
        if (function_exists('comm_contexte')) comm_contexte('GED', $docId, null);
        try { $okMail = (bool)send_mail($mail, 'À signer : ' . $nomDoc, $corps, [], true); }
        catch (Throwable $e) { error_log('[signature_envoyer mail] ' . $e->getMessage()); }
        if ($okMail) bsig_mark_sent($pdo, $sigId);
    }

    if ($telOk && function_exists('sms_envoyer')) {
        $msg = 'MaBoxImmo : document a signer : ' . $url;
        try {
            $r = sms_envoyer($pdo, $tel, $msg, [
                'type' => 'SIGNATURE', 'id_societe' => $socId,
                'objet_type' => 'BAIL_SIGNATURE', 'objet_id' => $sigId,   // trace technique : la frise la lit
                'journal_objet_type' => 'GED', 'journal_objet_id' => $docId,
                'destinataire_nom'   => $nom,
            ]);
            $okSms = !empty($r['ok']);
            if (!$okSms) $errSms = (string)($r['error'] ?? 'échec inconnu');
            if ($okSms && !$okMail) bsig_mark_sent($pdo, $sigId);   // le SMS aussi arme la fenêtre
        } catch (Throwable $e) { $errSms = $e->getMessage(); }
    }

    $envois[] = ['role'=>$role, 'nom'=>$nom, 'email'=>$mailOk ? $mail : null, 'tel'=>$telOk ? $tel : null,
                 'mail'=>$okMail, 'sms'=>$okSms, 'sms_error'=>$errSms];
}

if (!$crees) $ko('Aucun signataire n\'a pu être créé. ' . ($bloques ? $bloques[0]['raison'] : ''));

sig_etat_marquer($pdo, $docId, ['etat' => 'en_cours', 'envoye_le' => date('Y-m-d H:i:s'), 'signataires' => $crees]);

echo json_encode([
    'ok'      => true,
    'envois'  => $envois,
    'bloques' => $bloques,
    'message' => $crees . ' lien(s) de signature émis'
               . ($bloques ? ' — ' . count($bloques) . ' signataire(s) n\'ont RIEN reçu, voir le détail.' : '.'),
], JSON_UNESCAPED_UNICODE);
