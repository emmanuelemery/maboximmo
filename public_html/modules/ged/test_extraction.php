<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Test reproductible du PIPELINE D'EXTRACTION.
 * Fichier : modules/ged/test_extraction.php
 *
 * Lance gedExtractDocument() sur chaque PDF de tests/fixtures/ged/, compare au
 * .expected.json correspondant, calcule un score de précision par fixture +
 * global, et imprime un rapport détaillé champ par champ.
 *
 * Usage :
 *   php public_html/modules/ged/test_extraction.php
 *
 * Pré-requis :
 *   - ANTHROPIC_API_KEY définie (idéalement) — sinon fallback OPENAI_API_KEY
 *   - PDFs + .expected.json dans tests/fixtures/ged/
 *
 * Sortie : rapport humain + code retour 0 (PASS) ou 1 (FAIL).
 *
 * Critères de succès :
 *   - Score global ≥ 80%
 *   - Aucune hallucination sur 05_document_ambigu (fields_must_be_null respectés)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('test_extraction.php : exécutable uniquement en CLI.');
}

// ── Charge clés API (OpenAI + Anthropic) — premier trouvé gagne, pas de double-load ──
// On évite les warnings "Constant already defined" : un seul fichier par provider.
$root = dirname(__DIR__, 3);

$openaiCandidates = [
    $root . '/u630423897/maboximmo_openai_config.php',
    $root . '/u630423897/dev_maboximmo_openai_config.php',
    $root . '/openai_config.php',
    '/home/u630423897/maboximmo_openai_config.php',
];
foreach ($openaiCandidates as $f) {
    if (is_file($f) && is_readable($f) && !defined('OPENAI_API_KEY')) {
        require_once $f;
        break;
    }
}

$anthropicCandidates = [
    $root . '/u630423897/anthropic_config.php',
    $root . '/u630423897/dev_anthropic_config.php',
    $root . '/u630423897/maboximmo_anthropic_config.php',
    $root . '/anthropic_config.php',
    '/home/u630423897/anthropic_config.php',
];
foreach ($anthropicCandidates as $f) {
    if (is_file($f) && is_readable($f) && !defined('ANTHROPIC_API_KEY')) {
        require_once $f;
        break;
    }
}

require_once __DIR__ . '/ged_extraction.php';

$fixturesDir = $root . '/tests/fixtures/ged';
if (!is_dir($fixturesDir)) {
    fwrite(STDERR, "Dossier fixtures introuvable : {$fixturesDir}\n");
    exit(2);
}

// ── Vérifie qu'au moins une clé IA est dispo ────────────────────────────────
$hasAnthropic = (function_exists('ged_vision_anthropic_key') && ged_vision_anthropic_key() !== '');
$hasOpenAI    = (function_exists('ged_vision_openai_key')    && ged_vision_openai_key()    !== '');
if (!$hasAnthropic && !$hasOpenAI) {
    fwrite(STDERR,
        "❌ Aucune clé IA disponible. Définis ANTHROPIC_API_KEY ou OPENAI_API_KEY dans :\n" .
        "   u630423897/anthropic_config.php  (define('ANTHROPIC_API_KEY', '...'))\n" .
        "   u630423897/maboximmo_openai_config.php  (define('OPENAI_API_KEY', '...'))\n"
    );
    exit(2);
}

echo "╔══════════════════════════════════════════════════════════════════════╗" . PHP_EOL;
echo "║         GED — Test précision extraction sur fixtures réelles         ║" . PHP_EOL;
echo "╠══════════════════════════════════════════════════════════════════════╣" . PHP_EOL;
echo "║ Anthropic  : " . str_pad($hasAnthropic ? '✓ disponible' : '✗ absent', 56) . "║" . PHP_EOL;
echo "║ OpenAI     : " . str_pad($hasOpenAI    ? '✓ disponible' : '✗ absent', 56) . "║" . PHP_EOL;
echo "║ Modèle     : " . str_pad(GED_MODEL_EXTRACTION . ' (fallback ' . GED_MODEL_FALLBACK . ')', 56) . "║" . PHP_EOL;
echo "║ Fixtures   : " . str_pad($fixturesDir, 56) . "║" . PHP_EOL;
echo "╚══════════════════════════════════════════════════════════════════════╝" . PHP_EOL . PHP_EOL;

// ── Découverte fixtures ─────────────────────────────────────────────────────
$pdfs = glob($fixturesDir . '/*.pdf') ?: [];
sort($pdfs);
if (empty($pdfs)) {
    fwrite(STDERR, "❌ Aucun PDF trouvé dans {$fixturesDir}\n");
    exit(2);
}

$globalPositive = 0; $globalNegative = 0;
$fixtureResults = [];
$hallucinationOnAmbigu = 0;

foreach ($pdfs as $pdf) {
    $base = basename($pdf, '.pdf');
    $expectedFile = $fixturesDir . '/' . $base . '.expected.json';

    echo str_repeat('─', 72) . PHP_EOL;
    echo "▶ {$base}.pdf" . PHP_EOL;

    if (!is_file($expectedFile)) {
        echo "  ⚠ pas de .expected.json — fixture ignorée." . PHP_EOL . PHP_EOL;
        continue;
    }
    $expected = json_decode((string)file_get_contents($expectedFile), true);
    if (!is_array($expected)) {
        echo "  ⚠ .expected.json malformé — ignoré." . PHP_EOL . PHP_EOL;
        continue;
    }
    if (!empty($expected['_doc'])) {
        echo "  📋 " . $expected['_doc'] . PHP_EOL;
    }

    // Appel pipeline d'extraction
    $start = microtime(true);
    try {
        $result = gedExtractDocument($pdf);
    } catch (Throwable $e) {
        echo "  ❌ Exception : " . $e->getMessage() . PHP_EOL . PHP_EOL;
        $globalNegative += 5;
        continue;
    }
    $elapsed = number_format((microtime(true) - $start), 1);

    if (!$result['ok']) {
        echo "  ❌ Pipeline en échec ({$elapsed}s)" . PHP_EOL;
        foreach ($result['errors'] as $e) echo "     - {$e}" . PHP_EOL;
        echo PHP_EOL;
        $globalNegative += 5;
        continue;
    }

    echo "  ⚙ engine={$result['path_engine']} model={$result['model_used']} ({$elapsed}s)" . PHP_EOL;
    $data = $result['data'];

    // ── Évaluation champ par champ ──────────────────────────────────────────
    $pos = 0; $neg = 0;
    $details = [];

    // 1. fields_strict : égalité (case-insensitive pour strings, tolérance numérique pour float)
    foreach ((array)($expected['fields_strict'] ?? []) as $k => $exp) {
        $got = $data[$k] ?? null;
        $ok = ged_test_value_match($exp, $got);
        if ($ok) {
            $pos++;
            $details[] = "    ✓ {$k} = " . ged_test_format($got);
        } else {
            $neg++;
            $details[] = "    ✗ {$k} : attendu " . ged_test_format($exp) . ", obtenu " . ged_test_format($got);
        }
    }

    // 2. fields_oneof : valeur ∈ liste
    foreach ((array)($expected['fields_oneof'] ?? []) as $k => $accepted) {
        $got = $data[$k] ?? null;
        $matched = false;
        foreach ((array)$accepted as $cand) {
            if (ged_test_value_match($cand, $got)) { $matched = true; break; }
        }
        if ($matched) {
            $pos++;
            $details[] = "    ✓ {$k} ∈ accepté = " . ged_test_format($got);
        } else {
            $neg++;
            $details[] = "    ✗ {$k} : attendu un de " . json_encode($accepted, JSON_UNESCAPED_UNICODE) . ", obtenu " . ged_test_format($got);
        }
    }

    // 3. fields_must_be_null : la valeur DOIT être null/empty (anti-hallucination)
    foreach ((array)($expected['fields_must_be_null'] ?? []) as $k) {
        $got = $data[$k] ?? null;
        $isNull = ($got === null || $got === '' || (is_array($got) && empty($got)));
        if ($isNull) {
            $pos++;
            $details[] = "    ✓ {$k} = null (anti-hallucination OK)";
        } else {
            $neg++;
            $details[] = "    ⚠ HALLUCINATION : {$k} aurait dû être null, obtenu " . ged_test_format($got);
            if (str_starts_with($base, '05_')) $hallucinationOnAmbigu++;
        }
    }

    // 4. confiance_globale dans bornes
    $cb = $expected['confiance_globale'] ?? null;
    if (is_array($cb)) {
        $conf = $data['confiance_globale'] ?? null;
        $confNum = is_numeric($conf) ? (float)$conf : null;
        $min = isset($cb['min']) && $cb['min'] !== null ? (float)$cb['min'] : null;
        $max = isset($cb['max']) && $cb['max'] !== null ? (float)$cb['max'] : null;
        $okConf = ($confNum !== null)
               && ($min === null || $confNum >= $min)
               && ($max === null || $confNum <= $max);
        if ($okConf) {
            $pos++;
            $details[] = "    ✓ confiance_globale = {$confNum} (∈ [{$min}, {$max}])";
        } else {
            $neg += 2; // pondère plus fort
            $details[] = "    ✗ confiance_globale = " . ged_test_format($conf) . " (attendu ∈ [{$min}, {$max}])";
        }
    }

    foreach ($details as $d) echo $d . PHP_EOL;

    $score = ($pos + $neg) > 0 ? round(100 * $pos / ($pos + $neg), 1) : 0;
    echo "  ▼ Score : {$pos} ✓ / " . ($pos + $neg) . " — {$score}%" . PHP_EOL . PHP_EOL;

    $globalPositive += $pos;
    $globalNegative += $neg;
    $fixtureResults[$base] = ['pos' => $pos, 'neg' => $neg, 'score' => $score];
}

// ── Résumé global ───────────────────────────────────────────────────────────
echo str_repeat('═', 72) . PHP_EOL;
echo "RÉSUMÉ" . PHP_EOL;
echo str_repeat('═', 72) . PHP_EOL;
foreach ($fixtureResults as $name => $r) {
    $bar = str_repeat('█', max(1, (int)round($r['score'] / 5)));
    printf("  %-40s %5.1f%%  %s\n", $name, $r['score'], $bar);
}

$globalScore = ($globalPositive + $globalNegative) > 0
    ? round(100 * $globalPositive / ($globalPositive + $globalNegative), 1)
    : 0;
echo PHP_EOL;
echo "Score global             : {$globalScore}% ({$globalPositive} ✓ / " . ($globalPositive + $globalNegative) . ")" . PHP_EOL;
echo "Hallucinations sur ambigu : {$hallucinationOnAmbigu}" . PHP_EOL;

$pass = ($globalScore >= 80.0) && ($hallucinationOnAmbigu === 0);
echo PHP_EOL;
echo $pass ? "✅ TEST PASS  (score ≥ 80% et 0 hallucination)" : "❌ TEST FAIL";
echo PHP_EOL;

exit($pass ? 0 : 1);

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Compare deux valeurs avec tolérances :
 *   - strings : trim + case-insensitive + accents normalisés
 *   - floats  : tolérance 0.01
 *   - null vs null : true
 *   - sinon : == strict
 */
function ged_test_value_match($expected, $got): bool
{
    if ($expected === null) {
        return $got === null || $got === '';
    }
    if ($got === null) return false;

    if (is_float($expected) || is_float($got)) {
        return is_numeric($got) && abs((float)$got - (float)$expected) <= 0.01;
    }
    if (is_int($expected)) {
        return is_numeric($got) && (int)$got === $expected;
    }
    if (is_string($expected) && is_string($got)) {
        return ged_test_str_norm($expected) === ged_test_str_norm($got);
    }
    return $expected == $got;
}

function ged_test_str_norm(string $s): string
{
    $s = trim($s);
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($tr) && $tr !== '') $s = $tr;
    }
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', '', $s) ?? $s;
    return $s;
}

function ged_test_format($v): string
{
    if ($v === null) return 'null';
    if (is_string($v)) return '"' . mb_substr($v, 0, 80) . '"';
    if (is_array($v))  return json_encode($v, JSON_UNESCAPED_UNICODE);
    return (string)$v;
}
