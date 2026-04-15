<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = $GLOBALS['pdo'];
$msg = ''; $msgType = ''; $validToken = false; $done = false;

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($token !== '') {
    // Vérifier le token
    $stmt = $pdo->prepare("SELECT rt.*, u.email, u.prenom FROM reset_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.token = ? AND rt.used = 0 AND rt.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resetRow) {
        $validToken = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $pass1 = $_POST['password'] ?? '';
            $pass2 = $_POST['password_confirm'] ?? '';

            if (strlen($pass1) < 8) {
                $msg = 'Le mot de passe doit contenir au moins 8 caractères.'; $msgType = 'err';
            } elseif ($pass1 !== $pass2) {
                $msg = 'Les mots de passe ne correspondent pas.'; $msgType = 'err';
            } else {
                $hash = password_hash($pass1, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET mot_de_passe = ? WHERE id = ?")->execute([$hash, $resetRow['user_id']]);
                $pdo->prepare("UPDATE reset_tokens SET used = 1 WHERE id = ?")->execute([$resetRow['id']]);
                $done = true;
                $msg = 'Mot de passe modifié avec succès ! Vous pouvez maintenant vous connecter.'; $msgType = 'ok';
            }
        }
    } else {
        $msg = 'Ce lien est invalide ou a expiré. Veuillez refaire une demande.'; $msgType = 'err';
    }
} else {
    $msg = 'Lien invalide.'; $msgType = 'err';
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nouveau mot de passe — MaBoxImmo</title>
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
.strength{height:4px;border-radius:2px;background:#eee;margin-top:4px;overflow:hidden}
.strength-bar{height:100%;border-radius:2px;transition:width 0.3s}
</style>
</head>
<body>
<div class="container">
    <div class="logo">MI</div>
    <h1><?= $done ? 'Mot de passe modifié' : 'Nouveau mot de passe' ?></h1>
    <?php if (!$done && $validToken): ?>
        <p class="sub">Choisissez votre nouveau mot de passe (minimum 8 caractères).</p>
    <?php endif; ?>
    <?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= e($msg) ?></div><?php endif; ?>

    <?php if ($validToken && !$done): ?>
    <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div>
            <label for="password">Nouveau mot de passe</label>
            <input type="password" id="password" name="password" minlength="8" required placeholder="Minimum 8 caractères" oninput="checkStrength(this.value)">
            <div class="strength"><div class="strength-bar" id="strengthBar"></div></div>
        </div>
        <div>
            <label for="password_confirm">Confirmer</label>
            <input type="password" id="password_confirm" name="password_confirm" minlength="8" required placeholder="Retapez le mot de passe">
        </div>
        <button type="submit">Enregistrer le mot de passe →</button>
    </form>
    <script>
    function checkStrength(p) {
        var s = 0, bar = document.getElementById('strengthBar');
        if (p.length >= 8) s++;
        if (p.length >= 12) s++;
        if (/[A-Z]/.test(p)) s++;
        if (/[0-9]/.test(p)) s++;
        if (/[^A-Za-z0-9]/.test(p)) s++;
        var w = [0,20,40,60,80,100][s];
        var c = ['#dc2626','#dc2626','#d97706','#d97706','#16a34a','#16a34a'][s];
        bar.style.width = w + '%';
        bar.style.background = c;
    }
    </script>
    <?php endif; ?>

    <div class="back"><a href="login.php">← Retour à la connexion</a></div>
</div>
</body>
</html>
