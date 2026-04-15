<?php
http_response_code(404);
$pageTitle = 'Page introuvable';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Page introuvable — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Sora', system-ui, sans-serif;
    background: #f8f7f5;
    color: #2d2a26;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
}
.card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 6px 6px 20px rgba(0,0,0,0.08), -4px -4px 12px rgba(255,255,255,0.9);
    padding: 48px 40px;
    max-width: 480px;
    text-align: center;
}
.code { font-size: 72px; font-weight: 700; color: #c97b2e; line-height: 1; margin-bottom: 8px; }
h1 { font-size: 22px; font-weight: 600; margin-bottom: 12px; }
p { font-size: 14px; color: #6a6660; line-height: 1.6; margin-bottom: 24px; }
.btn {
    display: inline-block;
    padding: 10px 28px;
    background: #c97b2e;
    color: #fff;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: background .2s;
}
.btn:hover { background: #a8622a; }
</style>
</head>
<body>
<div class="card">
    <div class="code">404</div>
    <h1>Page introuvable</h1>
    <p>La page que vous recherchez n'existe pas ou a été déplacée.</p>
    <a href="/" class="btn">Retour à l'accueil</a>
</div>
</body>
</html>
