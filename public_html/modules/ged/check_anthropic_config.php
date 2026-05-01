<?php
declare(strict_types=1);

/**
 * GED — Diagnostic Anthropic config sans révéler la clé.
 * Détecte : double define, valeur tronquée, ligne brisée, mauvais fichier chargé.
 *
 * Usage : php public_html/modules/ged/check_anthropic_config.php
 */

if (PHP_SAPI !== 'cli') exit('CLI only');

// Cherche TOUS les fichiers possibles (ne charge encore aucun)
$root = dirname(__DIR__, 3);
$candidates = [
    $root . '/u630423897/anthropic_config.php',
    $root . '/u630423897/dev_anthropic_config.php',
    $root . '/u630423897/maboximmo_anthropic_config.php',
    $root . '/anthropic_config.php',
    $root . '/public_html/anthropic_config.php',
    '/home/u630423897/anthropic_config.php',
];

echo "═══ Diagnostic Anthropic config ═══" . PHP_EOL . PHP_EOL;

$found = [];
foreach ($candidates as $f) {
    if (is_file($f) && is_readable($f)) {
        $size = filesize($f);
        $found[] = $f;
        echo "📄 Trouvé : {$f}" . PHP_EOL;
        echo "   Taille : {$size} octets" . PHP_EOL;

        // Compte les define() pour ANTHROPIC_API_KEY (sans afficher la valeur)
        $content = file_get_contents($f);
        $countDef = preg_match_all('/define\s*\(\s*[\'"]ANTHROPIC_API_KEY[\'"]/', (string)$content);
        $countDefModel = preg_match_all('/define\s*\(\s*[\'"]ANTHROPIC_MODEL[\'"]/', (string)$content);
        echo "   define(ANTHROPIC_API_KEY) : {$countDef} occurrence(s)" . ($countDef > 1 ? ' ⚠️ DOUBLE !' : '') . PHP_EOL;
        echo "   define(ANTHROPIC_MODEL)   : {$countDefModel} occurrence(s)" . PHP_EOL;

        // Détecte les lignes potentiellement brisées (clé sur plusieurs lignes)
        if (preg_match('/define\s*\(\s*[\'"]ANTHROPIC_API_KEY[\'"]\s*,\s*[\'"]([^\'"]*)$/m', (string)$content)) {
            echo "   ⚠️ La string commence mais ne termine pas sur la même ligne (line break dans la clé ?)" . PHP_EOL;
        }

        // PHP open tag présent ?
        if (!str_contains((string)$content, '<?php')) {
            echo "   ⚠️ Pas de balise <?php au début !" . PHP_EOL;
        }

        echo PHP_EOL;
    }
}

if (empty($found)) {
    echo "❌ Aucun fichier anthropic_config.php trouvé dans :" . PHP_EOL;
    foreach ($candidates as $f) echo "   - {$f}" . PHP_EOL;
    exit(1);
}

if (count($found) > 1) {
    echo "⚠️ PLUSIEURS fichiers trouvés. Bootstrap charge le premier de la liste — vérifie que c'est le bon." . PHP_EOL . PHP_EOL;
}

// Charge le premier (comme le ferait bootstrap)
require_once $found[0];

echo "─── Constantes effectivement chargées ───" . PHP_EOL;
if (!defined('ANTHROPIC_API_KEY')) {
    echo "❌ ANTHROPIC_API_KEY NON DÉFINIE après chargement" . PHP_EOL;
    exit(1);
}

$key = (string)ANTHROPIC_API_KEY;
$len = strlen($key);
echo "ANTHROPIC_API_KEY :" . PHP_EOL;
echo "  Longueur : {$len}" . PHP_EOL;

$starts = substr($key, 0, 12);  // "sk-ant-api03" public, pas un secret
$startsOK = str_starts_with($key, 'sk-ant-api03-');
echo "  Préfixe sk-ant-api03- : " . ($startsOK ? '✓' : '❌ INVALIDE (devrait commencer par sk-ant-api03-)') . PHP_EOL;
echo "  Caractères suspects : " . (preg_match('/\s/', $key) ? "⚠️ contient un espace ou retour-ligne !" : '✓ aucun') . PHP_EOL;

if ($len < 80) {
    echo "  ❌ TROP COURTE : une vraie clé Anthropic fait 95-110 caractères. Tu as {$len}." . PHP_EOL;
    echo "     → Ré-ouvre ton fichier, vérifie que la clé tient sur UNE SEULE LIGNE" . PHP_EOL;
    echo "       et qu'il n'y a qu'UN SEUL define('ANTHROPIC_API_KEY', ...)." . PHP_EOL;
} elseif ($len > 200) {
    echo "  ⚠️ TROP LONGUE ({$len}) — possible doublon ou guillemet manquant." . PHP_EOL;
} else {
    echo "  ✓ Longueur cohérente avec une clé Anthropic valide" . PHP_EOL;
}

if (defined('ANTHROPIC_MODEL')) {
    echo "ANTHROPIC_MODEL : " . ANTHROPIC_MODEL . PHP_EOL;
} else {
    echo "ANTHROPIC_MODEL : non défini (fallback claude-sonnet-4-6 dans bootstrap.php)" . PHP_EOL;
}

echo PHP_EOL;
echo $startsOK && $len >= 80 && $len <= 200 ? "✅ Config Anthropic valide" : "❌ Config Anthropic à corriger" . PHP_EOL;
