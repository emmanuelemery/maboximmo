<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$username = htmlspecialchars($_SESSION['username'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ma Box Syndic - Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Manrope", sans-serif; background: #07111b; color: #e9f2ff; padding: 40px 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #ffffff; border: 1px solid rgba(255,255,255,0.09); border-radius: 20px; padding: 40px; backdrop-filter: blur(20px); }
        h1 { font-size: 28px; margin-bottom: 10px; }
        .subtitle { color: #8da0ba; margin-bottom: 30px; }
        .button { display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, rgba(102,217,255,0.95), rgba(28,77,255,0.85)); border: none; border-radius: 10px; color: #07121b; font-weight: 700; cursor: pointer; text-decoration: none; margin-top: 20px; }
        .button:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(72,120,166,0.15); }
    </style>
</head>
<body>
<div class="container">
    <h1>🏛️ Ma Box Syndic</h1>
    <p class="subtitle">Bienvenue <?=$username?></p>
    <p>Tableau de bord syndic en construction...</p>
    <a href="logout_agency.php" class="button">Déconnecter</a>
</div>
</body>
</html>
