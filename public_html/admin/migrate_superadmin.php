<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

/**
 * Migration — Ajouter le rôle Super Admin (id=7)
 *
 * Accès: /admin/migrate_superadmin.php
 * Protégé: require_admin() (Admin ou Super Admin)
 */

require_login();

// Permettre l'accès aux Admin (rôle 1) et Super Admin (rôle 7)
$roleId = current_role_id();
if (!in_array($roleId, [1, 7], true)) {
    http_response_code(403);
    exit('Accès refusé. Seul un Admin ou Super Admin peut exécuter cette migration.');
}

$pdo = db();
$output = [];

try {
    // Vérifier si le rôle existe déjà
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
    $stmt->execute([7]);
    $exists = $stmt->fetch();

    if ($exists) {
        $output[] = '✓ Le rôle Super Admin (id=7) existe déjà.';
    } else {
        // Insérer le rôle
        $stmt = $pdo->prepare("
            INSERT INTO roles (id, nom, actif, niveau_acces)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            7,
            'Super Admin',
            1,
            10  // Niveau d'accès maximal
        ]);

        $output[] = '✅ Rôle Super Admin (id=7) créé avec succès!';
    }

    // Vérifier la structure de la table roles
    $stmt = $pdo->query("DESCRIBE roles");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $output[] = '  Colonnes de la table roles: ' . implode(', ', $columns);

} catch (Exception $e) {
    $output[] = '❌ Erreur: ' . $e->getMessage();
    http_response_code(500);
}

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Migration Super Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #1a2a3a;
            --bg-soft: #ffffff;
            --ink: #d0e4ff;
            --muted: #7a91a8;
            --accent: #4878a6;
        }

        body {
            font-family: "Manrope", system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            background: var(--bg-soft);
            border: 1px solid #ffffff;
            border-radius: 16px;
            padding: 40px;
            max-width: 600px;
            box-shadow: 0 20px 60px #f7f8fa;
        }

        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--accent);
        }

        .output {
            background: rgba(0,0,0,0.2);
            border: 1px solid #ffffff;
            border-radius: 8px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 24px;
            max-height: 300px;
            overflow-y: auto;
        }

        .output-line {
            margin: 6px 0;
            color: var(--muted);
        }

        .output-line.success {
            color: #4a6038;
        }

        .output-line.error {
            color: #ff9aab;
        }

        .back-link {
            display: inline-block;
            padding: 10px 20px;
            background: rgba(72,120,166,0.1);
            border: 1px solid rgba(72,120,166,0.2);
            border-radius: 6px;
            color: var(--accent);
            text-decoration: none;
            transition: all 0.2s;
        }

        .back-link:hover {
            background: rgba(72,120,166,0.15);
        }

        .info {
            background: rgba(124,245,214,0.1);
            border: 1px solid rgba(124,245,214,0.2);
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            color: #4a6038;
            font-size: 13px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙ Migration — Super Admin</h1>

        <div class="output">
            <?php foreach ($output as $line): ?>
                <div class="output-line <?php echo (str_contains($line, '✅') || str_contains($line, '✓')) ? 'success' : ''; ?> <?php echo str_contains($line, '❌') ? 'error' : ''; ?>">
                    <?php echo e($line); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <a href="/MaBoxImmo2026/public_html/admin/admin_database.php" class="back-link">← Retour à l'admin BDD</a>

        <div class="info">
            <strong>À faire ensuite:</strong>
            <br>1. Créer un utilisateur avec id_role = 7 en BDD
            <br>2. Se connecter avec ce compte
            <br>3. Accéder à /admin/admin_database.php pour gérer les tables
        </div>
    </div>
</body>
</html>

<?php
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
