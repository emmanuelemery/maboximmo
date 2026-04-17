<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/db.php';
$pdo = db();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPwd  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($newPwd) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($newPwd !== $confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } elseif ($newPwd === 'MaBoxImmoNEW_1') {
        $error = 'Vous ne pouvez pas réutiliser le mot de passe provisoire.';
    } else {
        $hash = password_hash($newPwd, PASSWORD_BCRYPT);
        // Compat : met à jour force_password_change seulement si la colonne existe
        $hasFpcCol = false;
        try {
            $hasFpcCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'force_password_change'")->fetchColumn();
        } catch (Throwable) { $hasFpcCol = false; }
        $sql = $hasFpcCol
            ? "UPDATE users SET mot_de_passe = ?, force_password_change = 0 WHERE id = ?"
            : "UPDATE users SET mot_de_passe = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$hash, $userId]);
        $success = true;
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Changement de mot de passe — MaBoxImmo</title><style>
:root{--ink:#2d2a26;--muted:#5a6a5a;--accent:#4a6038;--accent2:#4878a6;--stroke:rgba(255,255,255,0.15)}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f2027;font-family:'Sora',system-ui,sans-serif;color:var(--ink);padding:24px;position:relative;overflow:hidden}
body::before{content:"";position:fixed;inset:0;background:
    radial-gradient(ellipse 60% 50% at 50% 50%, rgba(160,210,240,0.35), transparent 65%),
    radial-gradient(ellipse 45% 35% at 48% 48%, rgba(190,230,255,0.25), transparent 55%),
    radial-gradient(ellipse 75% 55% at 55% 45%, rgba(100,180,220,0.18), transparent 70%);
pointer-events:none}
.container{position:relative;z-index:1;width:100%;max-width:420px;background:rgba(255,255,255,0.95);backdrop-filter:blur(20px);border:1px solid var(--stroke);border-radius:20px;padding:36px 32px;box-shadow:0 20px 50px rgba(0,0,0,0.35)}
.logo{width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#4a6038,#2c5364);display:grid;place-items:center;margin:0 auto 16px;box-shadow:0 8px 24px rgba(74,96,56,0.3)}
h1{font-size:18px;font-weight:700;color:#1a2a1a;text-align:center;margin-bottom:6px}
.subtitle{font-size:13px;color:var(--muted);text-align:center;margin-bottom:22px}
.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px;font-size:12px;margin-bottom:16px}
.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:14px;font-size:13px;margin-bottom:16px;text-align:center}
form{display:grid;gap:12px}
label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:3px;text-transform:uppercase;letter-spacing:0.04em}
input{width:100%;border:1px solid #dde5dd;background:rgba(248,250,248,0.8);color:var(--ink);border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;transition:all 0.2s}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(74,96,56,0.12);background:#fff}
button{width:100%;border:none;border-radius:12px;padding:12px;font-size:15px;font-weight:700;cursor:pointer;color:#fff;background:linear-gradient(135deg,#4a6038,#2c5364);box-shadow:0 6px 20px rgba(44,83,100,0.3);font-family:inherit;transition:all 0.2s}
button:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(44,83,100,0.4)}
.rules{font-size:11px;color:var(--muted);line-height:1.6;margin-top:4px}
</style></head><body><div class="container">
<div class="logo"><svg width="38" height="44" viewBox="0 0 55 55"><defs><radialGradient id="pg" cx="35%" cy="35%"><stop offset="0%" stop-color="#FFD479"/><stop offset="70%" stop-color="#D4A843"/><stop offset="100%" stop-color="#B8922E"/></radialGradient></defs><g><path d="M 27.5 8 C 19 8 12 15 12 23 C 12 33 27.5 50 27.5 50 C 27.5 50 43 33 43 23 C 43 15 36 8 27.5 8 Z" fill="url(#pg)"/><circle cx="27.5" cy="23" r="7.5" fill="none" stroke="white" stroke-width="1.5" opacity="0.9"/></g></svg></div>
<h1>Changement de mot de passe</h1>
<p class="subtitle">Votre mot de passe provisoire doit être remplacé.</p>
<?php if ($success): ?>
<div class="success">Mot de passe modifié avec succès. Vous allez être redirigé...</div>
<script>setTimeout(function(){ window.location.href = 'landing.php'; }, 2000);</script>
<?php else: ?>
<?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<form method="post" novalidate>
<div><label for="new_password">Nouveau mot de passe</label><input type="password" id="new_password" name="new_password" placeholder="Minimum 8 caractères" required autofocus></div>
<div><label for="confirm_password">Confirmer</label><input type="password" id="confirm_password" name="confirm_password" placeholder="Retapez le mot de passe" required></div>
<div class="rules">Min. 8 caractères. Ne peut pas être le mot de passe provisoire.</div>
<button type="submit">Valider mon nouveau mot de passe</button>
</form>
<?php endif; ?>
</div></body></html>
