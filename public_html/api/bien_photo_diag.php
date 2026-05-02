<?php
declare(strict_types=1);

/**
 * GET /api/bien_photo_diag.php
 *
 * Endpoint de diagnostic rapide pour la feature "Critique IA photos".
 * Vérifie en quelques secondes :
 *   1. Colonnes BDD critique (migration_biens_photos_critique_2026-05-02.sql)
 *   2. Clé ANTHROPIC_API_KEY chargée
 *   3. Dépendances PHP (ged_vision.php, bien_photo_analyser.php)
 *   4. Répertoire uploads/biens/ accessible
 *
 * Réservé aux utilisateurs connectés (read-only, ne fait aucun appel IA).
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$diag = [
    'ok'     => true,
    'checks' => [],
    'fixes'  => [],
];

function add(string $name, bool $ok, string $detail, ?string $fix = null): void
{
    global $diag;
    $diag['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $diag['ok'] = false;
        if ($fix) $diag['fixes'][] = $fix;
    }
}

// 1) Colonnes critique en BDD
try {
    $pdo = $GLOBALS['pdo'];
    $st = $pdo->query("
        SELECT COLUMN_NAME, COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'biens_photos'
          AND COLUMN_NAME IN ('critique_niveau','critique_points_forts','critique_points_faibles','critique_conseil','critique_ia_date','analyse_statut','analyse_erreur')
    ");
    $cols = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $expected = ['critique_niveau','critique_points_forts','critique_points_faibles','critique_conseil','critique_ia_date','analyse_statut','analyse_erreur'];
    $missing  = array_diff($expected, array_keys($cols));
    if (empty($missing)) {
        add('Migration BDD critique', true, 'Toutes les colonnes présentes (' . count($cols) . '/7)');
    } else {
        add('Migration BDD critique', false,
            'Colonnes manquantes : ' . implode(', ', $missing),
            'Exécute sql/migration_biens_photos_critique_2026-05-02.sql sur la BDD dev (phpMyAdmin → onglet SQL).'
        );
    }
} catch (Throwable $e) {
    add('Migration BDD critique', false, 'Erreur SQL : ' . $e->getMessage(), 'Vérifie la connexion BDD.');
}

// 2) Colonnes IA legacy (commercial)
try {
    $st = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'biens_photos'
          AND COLUMN_NAME IN ('categorie','description_ia','description_ia_date')
    ");
    $cols2 = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    $missing2 = array_diff(['categorie','description_ia','description_ia_date'], $cols2);
    if (empty($missing2)) {
        add('Migration BDD commercial (legacy)', true, 'OK');
    } else {
        add('Migration BDD commercial (legacy)', false, 'Manque : ' . implode(', ', $missing2));
    }
} catch (Throwable $e) {
    add('Migration BDD commercial (legacy)', false, $e->getMessage());
}

// 3) Clé Anthropic
$anthropicKey = $GLOBALS['ANTHROPIC_API_KEY'] ?? (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
$keyLen = strlen((string)$anthropicKey);
$keyOk  = $keyLen > 20 && !str_contains((string)$anthropicKey, 'VOTRE_CLE');
add('Clé ANTHROPIC_API_KEY', $keyOk,
    $keyOk ? "Chargée ({$keyLen} chars, début: " . substr((string)$anthropicKey, 0, 12) . '…)' : 'Manquante ou placeholder',
    $keyOk ? null : 'Renseigne ANTHROPIC_API_KEY dans /home/u630423897/anthropic_config.php (ou équivalent hors webroot).'
);

// 4) Clé OpenAI (fallback)
$openaiKey = $GLOBALS['OPENAI_API_KEY'] ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
$openaiOk  = strlen((string)$openaiKey) > 20;
add('Clé OPENAI_API_KEY (fallback)', $openaiOk,
    $openaiOk ? 'Chargée' : 'Absente — si Claude échoue, pas de fallback'
);

// 5) Fichiers PHP requis
$files = [
    'inc/bien_photo_analyser.php' => dirname(__DIR__) . '/inc/bien_photo_analyser.php',
    'modules/ged/ged_vision.php'  => dirname(__DIR__) . '/modules/ged/ged_vision.php',
    'inc/ged_ai_models.php'       => dirname(__DIR__) . '/inc/ged_ai_models.php',
];
foreach ($files as $label => $path) {
    add("Fichier {$label}", is_file($path), is_file($path) ? 'OK' : 'INTROUVABLE : ' . $path);
}

// 6) Répertoire uploads
$uploadsDir = dirname(__DIR__) . '/uploads/biens';
add('Répertoire uploads/biens', is_dir($uploadsDir), is_dir($uploadsDir) ? 'OK' : 'Manquant : ' . $uploadsDir);

// 7) Test parsing JSON Anthropic (sans appel réel)
$canCallVision = function_exists('gedVisionExtract');
if (!$canCallVision) {
    @require_once dirname(__DIR__) . '/inc/bien_photo_analyser.php';
    $canCallVision = function_exists('gedVisionExtract');
}
add('Fonction gedVisionExtract() chargeable', $canCallVision, $canCallVision ? 'OK' : 'Échec require');

echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
