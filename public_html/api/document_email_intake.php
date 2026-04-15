<?php
declare(strict_types=1);
set_time_limit(60);

/**
 * POST /api/document_email_intake.php
 *
 * Relève la boîte mail documents@maboximmo.fr via IMAP,
 * récupère les pièces jointes et les importe dans la table documents.
 *
 * Réponse JSON : { ok, imported, errors[] }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Vérification login
if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}

// Vérification CSRF (POST ou header X-CSRF-Token)
$sessionToken = $_SESSION['_csrf_admin_documents'] ?? '';
$clientToken  = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$sessionToken || !$clientToken || !hash_equals($sessionToken, (string)$clientToken)) {
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide']);
    exit;
}

$roleId = function_exists('current_role_id') ? current_role_id() : 0;
if (!in_array($roleId, [1, 7, 8], true)) {
    echo json_encode(['ok' => false, 'error' => 'Accès réservé aux administrateurs']);
    exit;
}

$societeId = (int)($_SESSION['id_societe'] ?? 0);
$pdo = db();

// ── Configuration IMAP ──
$imapHost   = '{imap.hostinger.com:993/imap/ssl}INBOX';
$imapUser   = 'documents@maboximmo.fr';
$imapPass   = '';

// Le bootstrap a déjà chargé le fichier config (constantes définies)
if (defined('IMAP_PASSWORD_DOCS')) {
    $imapPass = IMAP_PASSWORD_DOCS;
} elseif (defined('SMTP_PASSWORD')) {
    $imapPass = SMTP_PASSWORD;
}

// Fallback : charger le config manuellement
if ($imapPass === '') {
    $configPaths = [
        '/home/u630423897/maboximmo_openai_config.php',
        dirname(__DIR__, 2) . '/u630423897/maboximmo_openai_config.php',
    ];
    foreach ($configPaths as $cp) {
        if (is_file($cp)) {
            @include_once $cp;
            break;
        }
    }
    if (defined('IMAP_PASSWORD_DOCS')) $imapPass = IMAP_PASSWORD_DOCS;
    elseif (defined('SMTP_PASSWORD'))  $imapPass = SMTP_PASSWORD;
}

if ($imapPass === '') {
    echo json_encode(['ok' => false, 'error' => 'Mot de passe IMAP non configuré. Ajoutez IMAP_PASSWORD_DOCS dans maboximmo_openai_config.php']);
    exit;
}

// ── Vérification extension IMAP ──
if (!function_exists('imap_open')) {
    echo json_encode(['ok' => false, 'error' => 'Extension PHP IMAP non installée sur le serveur. Activez-la dans le panel Hostinger (PHP > Extensions).']);
    exit;
}

try {
    $inbox = @imap_open($imapHost, $imapUser, $imapPass);
    if (!$inbox) {
        throw new RuntimeException('Connexion IMAP échouée : ' . (imap_last_error() ?: 'erreur inconnue'));
    }

    $emails = imap_search($inbox, 'UNSEEN');
    $imported = 0;
    $errors = [];
    $allowedExt = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','odt'];

    if ($emails) {
        foreach ($emails as $emailNum) {
            $overview = imap_fetch_overview($inbox, (string)$emailNum, 0);
            $subject  = isset($overview[0]->subject) ? imap_utf8($overview[0]->subject) : 'Sans objet';
            $from     = isset($overview[0]->from) ? imap_utf8($overview[0]->from) : 'inconnu';

            $structure = imap_fetchstructure($inbox, $emailNum);
            $attachments = [];

            if (isset($structure->parts)) {
                foreach ($structure->parts as $partIdx => $part) {
                    $filename = '';
                    if ($part->ifdparameters) {
                        foreach ($part->dparameters as $dp) {
                            if (strtolower($dp->attribute) === 'filename') {
                                $filename = imap_utf8($dp->value);
                            }
                        }
                    }
                    if ($filename === '' && $part->ifparameters) {
                        foreach ($part->parameters as $p) {
                            if (strtolower($p->attribute) === 'name') {
                                $filename = imap_utf8($p->value);
                            }
                        }
                    }
                    if ($filename !== '') {
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        if (in_array($ext, $allowedExt, true)) {
                            $attachments[] = [
                                'filename' => $filename,
                                'ext'      => $ext,
                                'partIdx'  => $partIdx + 1,
                                'encoding' => $part->encoding,
                            ];
                        }
                    }
                }
            }

            foreach ($attachments as $att) {
                try {
                    $data = imap_fetchbody($inbox, $emailNum, (string)$att['partIdx']);

                    switch ($att['encoding']) {
                        case 3: $data = base64_decode($data); break;
                        case 4: $data = quoted_printable_decode($data); break;
                    }

                    if (strlen($data) < 100) continue;

                    $destDir = dirname(__DIR__) . '/uploads/societes/' . $societeId . '/inbox';
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $att['ext'];
                    $destPath = $destDir . '/' . $safeName;
                    $relPath  = 'uploads/societes/' . $societeId . '/inbox/' . $safeName;

                    file_put_contents($destPath, $data);

                    $mime = match($att['ext']) {
                        'pdf'          => 'application/pdf',
                        'jpg', 'jpeg'  => 'image/jpeg',
                        'png'          => 'image/png',
                        'webp'         => 'image/webp',
                        'doc'          => 'application/msword',
                        'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'xls'          => 'application/vnd.ms-excel',
                        'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'odt'          => 'application/vnd.oasis.opendocument.text',
                        default        => 'application/octet-stream',
                    };

                    $titre = mb_substr(pathinfo($att['filename'], PATHINFO_FILENAME), 0, 200);

                    $stmt = $pdo->prepare("
                        INSERT INTO documents (id_societe, type_document, categorie_document,
                                              nom_fichier, chemin_fichier, mime_type, taille_octets,
                                              titre, description, date_creation)
                        VALUES (?, 'autre', 'administratif', ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $societeId,
                        $att['filename'],
                        $relPath,
                        $mime,
                        strlen($data),
                        $titre,
                        'Import email de ' . $from . ' — Objet : ' . $subject,
                    ]);
                    $imported++;
                } catch (Throwable $e) {
                    $errors[] = $att['filename'] . ' : ' . $e->getMessage();
                }
            }

            imap_setflag_full($inbox, (string)$emailNum, '\\Seen');
        }
    }

    imap_close($inbox);

    echo json_encode([
        'ok'       => true,
        'imported' => $imported,
        'errors'   => $errors,
    ]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
