<?php
declare(strict_types=1);

/**
 * Déploiement Git manuel via interface admin.
 * Réservé au super admin (role_id = 1).
 *
 * Exécute `git pull origin develop` sur le serveur et affiche la sortie.
 * Utile quand le webhook automatique Hostinger ne pull plus.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin_or_super_admin();

$output = '';
$errorMsg = '';
$step = '';

// 1) Walk-up : remonte les répertoires parents de ce fichier jusqu'à trouver .git
$tried = [];
$repoRoot = null;
$cur = __DIR__;
for ($i = 0; $i < 10 && $cur && $cur !== '/' && strlen($cur) > 3; $i++) {
    $tried[] = $cur;
    if (file_exists($cur . '/.git')) { // dir OU fichier (worktrees)
        $repoRoot = $cur;
        break;
    }
    $parent = dirname($cur);
    if ($parent === $cur) break;
    $cur = $parent;
}

// 2) Fallback : demander à git lui-même
if (!$repoRoot && function_exists('shell_exec')) {
    $top = trim((string)@shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git rev-parse --show-toplevel 2>&1'));
    if ($top && is_dir($top) && file_exists($top . '/.git')) {
        $repoRoot = $top;
    }
    $tried[] = '(git rev-parse --show-toplevel) → ' . ($top ?: '—');
}

// 3) Candidats connus Hostinger
if (!$repoRoot) {
    $hinted = [
        '/home/u630423897/public_html',
        '/home/u630423897',
        '/home/u630423897/domains/dev.maboximmo.fr/public_html',
        '/home/u630423897/domains/dev.maboximmo.fr',
        '/home/u630423897/domains/maboximmo.fr/public_html',
        '/home/u630423897/domains/maboximmo.fr',
    ];
    foreach ($hinted as $c) {
        $tried[] = $c;
        if (is_dir($c) && file_exists($c . '/.git')) { $repoRoot = $c; break; }
    }
}

// Action : déclenchement du pull
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pull') {
    verify_csrf('admin_deploy');
    if (!$repoRoot) {
        $errorMsg = "Impossible de trouver la racine du repo Git. Chemins testés :\n" . implode("\n", array_filter($tried));
    } elseif (!function_exists('shell_exec')) {
        $errorMsg = "La fonction PHP shell_exec() est désactivée sur ce serveur.";
    } else {
        $step = 'running';
        $repoEsc = escapeshellarg($repoRoot);
        $commands = [
            "cd $repoEsc && git rev-parse --abbrev-ref HEAD 2>&1",
            "cd $repoEsc && git fetch origin 2>&1",
            "cd $repoEsc && git reset --hard origin/develop 2>&1",
            "cd $repoEsc && git log --oneline -5 2>&1",
        ];
        foreach ($commands as $cmd) {
            $output .= "\n$ " . $cmd . "\n";
            $result = @shell_exec($cmd);
            $output .= ($result ?: '(aucune sortie)') . "\n";
        }
        $step = 'done';
    }
}

$csrf = csrf_token('admin_deploy');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Déploiement Git — Admin</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 960px; margin: 30px auto; padding: 20px; color: #1a1816; }
        h1 { color: #36577d; margin-bottom: 6px; }
        .sub { color: #8a8680; margin-bottom: 24px; }
        .card { background: #fafbfc; border: 1px solid rgba(54,87,125,.12); border-radius: 10px; padding: 18px 22px; margin-bottom: 16px; }
        .kv { display: grid; grid-template-columns: 160px 1fr; gap: 8px; font-size: 13px; }
        .kv strong { color: #36577d; }
        button.primary { padding: 10px 20px; background: #36577d; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px; }
        button.primary:hover { background: #2b4466; }
        pre { background: #0e1825; color: #d6e4f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; line-height: 1.5; }
        .ok { color: #0ea572; font-weight: 700; }
        .err { color: #dc2626; font-weight: 700; }
        a.back { color: #36577d; text-decoration: none; font-size: 13px; }
    </style>
</head>
<body>
    <a class="back" href="admin_migrations.php">← Retour migrations</a>
    <h1>🚀 Déploiement Git manuel</h1>
    <p class="sub">Force un <code>git fetch + git reset --hard origin/develop</code> quand le webhook Hostinger ne synchronise plus.</p>

    <div class="card">
        <div class="kv">
            <strong>Racine repo détectée</strong>
            <span><?= $repoRoot ? htmlspecialchars($repoRoot) : '<span class="err">⚠️ Non trouvée</span>' ?></span>

            <?php if (!$repoRoot): ?>
            <strong>Chemins testés</strong>
            <span><pre style="margin:0;padding:8px 10px;background:#fff;color:#991b1b;font-size:11px;"><?= htmlspecialchars(implode("\n", array_filter($tried))) ?></pre></span>
            <?php endif; ?>

            <strong>PHP shell_exec()</strong>
            <span><?= function_exists('shell_exec') ? '<span class="ok">✓ Disponible</span>' : '<span class="err">✗ Désactivée</span>' ?></span>

            <strong>Utilisateur Apache/PHP</strong>
            <span><code><?= htmlspecialchars(trim((string)@shell_exec('whoami') ?: 'inconnu')) ?></code></span>

            <strong>Serveur / Host</strong>
            <span><code><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '?') ?></code></span>
        </div>
    </div>

    <?php if ($errorMsg): ?>
        <div class="card" style="background:#fef2f2;border-color:#fca5a5;">
            <strong class="err">❌ Erreur :</strong>
            <pre style="background:#fff;color:#991b1b;margin-top:8px;"><?= htmlspecialchars($errorMsg) ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($step === 'done'): ?>
        <div class="card" style="background:#ecfdf5;border-color:#a7f3d0;">
            <strong class="ok">✅ Déploiement terminé</strong> — la page v2 devrait maintenant refléter le dernier commit (Ctrl+F5 pour vider le cache navigateur).
        </div>
    <?php endif; ?>

    <?php if ($output): ?>
        <h2 style="font-size:15px;color:#36577d;margin-bottom:8px;">Sortie des commandes</h2>
        <pre><?= htmlspecialchars($output) ?></pre>
    <?php endif; ?>

    <?php if ($repoRoot && function_exists('shell_exec')): ?>
        <form method="post" style="margin-top:20px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="pull">
            <button type="submit" class="primary" onclick="return confirm('⚠️ Cela va écraser les modifications locales non commit\u00e9es sur le serveur. Continuer ?');">
                🚀 Forcer le pull depuis origin/develop
            </button>
        </form>
    <?php endif; ?>

    <div class="card" style="margin-top:28px;">
        <strong>💡 Note</strong>
        <p style="font-size:12px;margin-top:6px;">
            Cette page utilise <code>git reset --hard origin/develop</code> : toute modif locale non-commit sur le serveur
            sera perdue. L'opération est idempotente et ne crée pas de nouveau commit.
            Après déploiement, va appliquer les migrations BDD en attente :
            <a href="admin_migrations.php">admin_migrations.php</a>.
        </p>
    </div>
</body>
</html>
