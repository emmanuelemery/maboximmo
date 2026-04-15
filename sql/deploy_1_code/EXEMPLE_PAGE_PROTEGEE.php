<?php
/**
 * EXEMPLE: Comment protéger une page avec le système simplifié
 *
 * Copier ce code au début de chaque page de service
 */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';

// 1. Vérifier que l'utilisateur est connecté
require_login();

// 2. Récupérer le rôle
$roleId = current_role_id();
$userId = current_user_id();

// 3. Protéger la page (redirection si pas d'accès)
// ⚠️ Remplacer 'rh' par le service: 'syndic', 'agency', 'proprietaire'
requireServiceAccess($roleId, 'rh');

// À partir d'ici, on sait que l'utilisateur a accès au service RH

$pdo = $GLOBALS['pdo'] ?? null;
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page Protégée - MaBoxImmo</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root{--bg: var(--bg-secondary);--bg-soft:#ffffff;--sidebar:#ffffff;--ink:#d0e4ff;--muted:#7a91a8;--accent:#4878a6;--stroke:#f0f1f3;--sidebar-w:250px}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}

        .mbi-sidebar{position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;background:var(--sidebar);border-right:1px solid var(--stroke);overflow-y:auto;padding:12px 0}
        .mbi-sidebar-head{padding:10px 12px;border-bottom:1px solid var(--stroke);margin-bottom:12px}
        .mbi-sidebar-brand strong{font-size:14px;display:block;color:var(--ink)}
        .mbi-sidebar-brand span{font-size:11px;color:var(--muted)}
        .mbi-sidebar-section{padding:0 12px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);margin:12px 0 6px}
        .mbi-nav{list-style:none}
        .mbi-nav li a{display:flex;align-items:center;gap:4px;padding:6px 12px;color:var(--muted);text-decoration:none;font-size:15px;transition:all 0.2s}
        .mbi-nav li a:hover{color:var(--ink);background:#ffffff}
        .mbi-nav li a.active{color:var(--accent);background:rgba(72,120,166,0.08)}

        .mbi-main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column}
        .mbi-topbar{height:64px;background:var(--bg-soft);border-bottom:1px solid var(--stroke);display:flex;align-items:center;padding:0 30px}
        .mbi-topbar h1{font-size:18px;color:var(--ink)}
        .mbi-content{flex:1;padding:30px;overflow-y:auto}

        @media(max-width:900px){.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}}
    </style>
</head>
<body>

<!-- SIDEBAR DYNAMIQUE (remplace les 4 anciennes) -->
<?php require_once __DIR__ . '/inc/sidebar.php'; ?>

<main class="mbi-main">
    <div class="mbi-topbar">
        <h1>Page Protégée RH</h1>
    </div>

    <div class="mbi-content">
        <h2>Bienvenue!</h2>
        <p>Cette page n'est accessible que si votre rôle a accès au service RH.</p>

        <!-- EXEMPLE: Afficher les services accessibles par l'utilisateur -->
        <h3 style="margin-top:30px">Vos services accessibles:</h3>
        <ul>
            <?php
            $services = getAvailableServices($roleId);
            foreach ($services as $slug => $config):
            ?>
            <li><?=htmlspecialchars($config['nom'])?> (<?=$slug?>)</li>
            <?php endforeach; ?>
        </ul>

        <!-- EXEMPLE: Afficher du contenu conditionnel -->
        <h3 style="margin-top:30px">Accès avancé:</h3>
        <?php if (hasServiceAccess($roleId, 'agency')): ?>
            <p>✓ Vous avez accès à Agency - fonctionnalités avancées disponibles</p>
        <?php else: ?>
            <p>✗ Accès à Agency non disponible pour votre rôle</p>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
