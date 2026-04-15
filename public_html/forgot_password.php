<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = $GLOBALS['pdo'];
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $msg = 'Veuillez saisir votre adresse email.'; $msgType = 'err';
    } else {
        // Toujours afficher le même message (sécurité : ne pas révéler si l'email existe)
        $msg = 'Si un compte existe avec cette adresse, un email de réinitialisation a été envoyé.'; $msgType = 'ok';

        $stmt = $pdo->prepare("SELECT id, email, prenom, nom FROM users WHERE email = ? AND actif = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Générer un token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Invalider les anciens tokens
            $pdo->prepare("UPDATE reset_tokens SET used = 1 WHERE user_id = ? AND used = 0")->execute([$user['id']]);

            // Insérer le nouveau
            $pdo->prepare("INSERT INTO reset_tokens (user_id, token, expires_at, used) VALUES (?, ?, ?, 0)")
                ->execute([$user['id'], $token, $expires]);

            // Construire l'URL de reset
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
            $base = function_exists('app_base_path') ? app_base_path() : '';
            $resetUrl = $proto . '://' . $host . $base . '/reset_password.php?token=' . $token;

            // Envoyer l'email
            try {
                require_once __DIR__ . '/inc/mailer.php';
                $prenom = $user['prenom'] ?? '';
                $html = '
                <div style="font-family:Sora,Arial,sans-serif;max-width:500px;margin:0 auto;padding:30px;">
                    <div style="text-align:center;margin-bottom:24px;">
                        <div style="display:inline-block;width:50px;height:50px;border-radius:14px;background:linear-gradient(135deg,#c97b2e,#8a5040);color:#fff;font-size:20px;font-weight:800;line-height:50px;">MI</div>
                    </div>
                    <h2 style="text-align:center;color:#2d2a26;font-size:18px;">Réinitialisation de mot de passe</h2>
                    <p style="color:#666;font-size:14px;">Bonjour ' . e($prenom) . ',</p>
                    <p style="color:#666;font-size:14px;">Vous avez demandé la réinitialisation de votre mot de passe MaBoxImmo. Cliquez sur le bouton ci-dessous :</p>
                    <div style="text-align:center;margin:24px 0;">
                        <a href="' . e($resetUrl) . '" style="display:inline-block;padding:12px 32px;background-color:#c97b2e;background:linear-gradient(135deg,#c97b2e,#8a5040);color:#ffffff;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;">Réinitialiser mon mot de passe</a>
                        <p style="margin-top:12px;font-size:11px;color:#aaa;">Si le bouton ne fonctionne pas, copiez ce lien : <br><a href="' . e($resetUrl) . '" style="color:#c97b2e;word-break:break-all;">' . e($resetUrl) . '</a></p>
                    </div>
                    <p style="color:#999;font-size:12px;">Ce lien expire dans 1 heure. Si vous n\'avez pas fait cette demande, ignorez cet email.</p>
                    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
                    <p style="color:#ccc;font-size:10px;text-align:center;">MaBoxImmo — Plateforme immobilière professionnelle</p>
                </div>';

                send_mail($user['email'], 'Réinitialisation mot de passe — MaBoxImmo', $html, [], true);
            } catch (Throwable $ex) {
                error_log('[forgot_password] Erreur envoi mail: ' . $ex->getMessage());
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mot de passe oublié — MaBoxImmo</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--ink:#2d2a26;--muted:#6a6660;--accent:#c97b2e;--stroke:rgba(0,0,0,0.08)}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8f7f5;font-family:'Sora',system-ui,sans-serif;color:var(--ink);padding:24px}
.container{width:100%;max-width:420px;background:#fff;border:1px solid var(--stroke);border-radius:18px;padding:36px 32px;box-shadow:4px 4px 16px rgba(0,0,0,0.06),-4px -4px 12px rgba(255,255,255,0.8)}
.logo{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#c97b2e,#8a5040);display:grid;place-items:center;font-size:22px;font-weight:800;color:#fff;margin:0 auto 16px;box-shadow:3px 3px 10px rgba(201,123,46,0.2)}
h1{font-size:18px;font-weight:700;text-align:center;margin-bottom:6px}
.sub{font-size:13px;color:var(--muted);text-align:center;margin-bottom:22px}
form{display:grid;gap:12px}
label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px;display:block}
input{width:100%;border:1px solid #e5e5e5;background:#fafafa;color:var(--ink);border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(201,123,46,0.1);background:#fff}
button{width:100%;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;color:#fff;background:linear-gradient(135deg,#c97b2e,#8a5040);box-shadow:0 6px 20px rgba(201,123,46,0.25);font-family:inherit}
button:hover{transform:translateY(-2px)}
.msg{padding:12px;border-radius:10px;font-size:13px;margin-bottom:14px}
.msg.ok{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}
.msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.back{text-align:center;margin-top:16px;font-size:12px}
.back a{color:var(--accent);text-decoration:none;font-weight:600}
</style>
</head>
<body>
<div class="container">
    <div class="logo">MI</div>
    <h1>Mot de passe oublié</h1>
    <p class="sub">Saisissez votre adresse email pour recevoir un lien de réinitialisation.</p>
    <?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= e($msg) ?></div><?php endif; ?>
    <form method="post">
        <div><label for="email">Email</label><input type="email" id="email" name="email" placeholder="votre@email.fr" required autofocus></div>
        <button type="submit">Envoyer le lien →</button>
    </form>
    <div class="back"><a href="login.php">← Retour à la connexion</a></div>
</div>
</body>
</html>
