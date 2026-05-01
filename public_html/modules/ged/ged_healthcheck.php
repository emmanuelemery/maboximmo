<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Health-check complet de la stack.
 * Fichier : modules/ged/ged_healthcheck.php
 *
 * Usage :
 *   - Web : https://dev.maboximmo.fr/modules/ged/ged_healthcheck.php (admin only)
 *   - CLI : php public_html/modules/ged/ged_healthcheck.php
 *
 * Vérifie en cascade :
 *   1. Configs API chargées (OpenAI, Anthropic, Drive)
 *   2. PDO + tables GED + colonnes storage + VIEW de compat
 *   3. Driver storage local (round-trip 100 octets)
 *   4. Driver Drive (round-trip 100 octets)
 *   5. Appel IA Anthropic (ping Claude Sonnet 4.6 sur 1 phrase)
 *   6. Appel IA OpenAI (ping GPT-4o sur 1 phrase)
 *
 * Aucun secret n'est révélé — uniquement OK/NOT-OK + premières lettres anonymisées.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// Sécurité : web = admin uniquement
if (!$isCli) {
    require_login();
    $roleId = (int)current_role_id();
    if (!in_array($roleId, [1, 7, 8], true)) {
        http_response_code(403);
        exit('Healthcheck : admin uniquement (rôles 1, 7, 8).');
    }
}

require_once __DIR__ . '/ged_storage.php';
require_once __DIR__ . '/ged_storage_local.php';

$tests = [];
$pass = 0; $fail = 0;

function hc(string $label, bool $ok, string $detail = ''): void {
    global $tests, $pass, $fail;
    $tests[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    if ($ok) $pass++; else $fail++;
}

// ─── 1. Configs API ──────────────────────────────────────────────────────────
hc('OpenAI key (OPENAI_API_KEY défini)',
    defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '',
    defined('OPENAI_API_KEY') ? 'longueur ' . strlen(OPENAI_API_KEY) : 'absent'
);

hc('Anthropic key (ANTHROPIC_API_KEY défini)',
    defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '',
    defined('ANTHROPIC_API_KEY') ? 'longueur ' . strlen(ANTHROPIC_API_KEY) : 'absent'
);

$driveCfg = [
    dirname(__DIR__, 3) . '/u630423897/maboximmo_drive_config.php',
    '/home/u630423897/maboximmo_drive_config.php',
];
foreach ($driveCfg as $f) {
    if (is_file($f) && is_readable($f)) { require_once $f; break; }
}
$driveOk = defined('GOOGLE_DRIVE_CLIENT_ID')
        && defined('GOOGLE_DRIVE_CLIENT_SECRET')
        && defined('GOOGLE_DRIVE_REFRESH_TOKEN')
        && defined('GOOGLE_DRIVE_ROOT_FOLDER_ID')
        && GOOGLE_DRIVE_CLIENT_ID    !== '' && !str_starts_with((string)GOOGLE_DRIVE_CLIENT_ID,    'PASTE_')
        && GOOGLE_DRIVE_CLIENT_SECRET!== '' && !str_starts_with((string)GOOGLE_DRIVE_CLIENT_SECRET,'PASTE_')
        && GOOGLE_DRIVE_REFRESH_TOKEN!== '' && !str_starts_with((string)GOOGLE_DRIVE_REFRESH_TOKEN,'PASTE_')
        && GOOGLE_DRIVE_ROOT_FOLDER_ID!=='' && !str_starts_with((string)GOOGLE_DRIVE_ROOT_FOLDER_ID,'PASTE_');
hc('Drive config (4 constantes principales)', $driveOk,
    defined('GOOGLE_DRIVE_DRIVE_ID') ? 'avec DRIVE_ID (Shared Drive)' : 'sans DRIVE_ID (My Drive)');

// ─── 2. PDO + schéma BDD ─────────────────────────────────────────────────────
$pdo = $GLOBALS['pdo'] ?? null;
hc('PDO connecté', $pdo instanceof PDO);

if ($pdo instanceof PDO) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM ged_analyses")->fetchColumn();
        hc('Table ged_analyses accessible', true, "{$count} ligne(s)");
    } catch (Throwable $e) {
        hc('Table ged_analyses accessible', false, $e->getMessage());
    }
    try {
        $vCount = (int)$pdo->query("SELECT COUNT(*) FROM agent_ged_analyses")->fetchColumn();
        hc('VIEW agent_ged_analyses (compat)', true, "{$vCount} ligne(s)");
    } catch (Throwable $e) {
        hc('VIEW agent_ged_analyses (compat)', false, $e->getMessage());
    }
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM ged_analyses")->fetchAll(PDO::FETCH_COLUMN);
        $needed = ['sha256','storage_driver','storage_file_id','storage_folder_id',
                   'objet_type','objet_id','ref_societe','ref_agence','nom_renomme','version_doc'];
        $missing = array_diff($needed, $cols);
        hc('Colonnes storage présentes (migration 002)', empty($missing),
            empty($missing) ? '10/10' : 'MANQUE: ' . implode(',', $missing));
    } catch (Throwable $e) {
        hc('Colonnes storage', false, $e->getMessage());
    }
}

// ─── 3. Driver storage local ─────────────────────────────────────────────────
try {
    $base = dirname(__DIR__, 3) . '/storage/ged_hc_' . bin2hex(random_bytes(2));
    $local = new GedStorageLocal($base);
    $tmp = tempnam(sys_get_temp_dir(), 'gedhc_') . '.txt';
    file_put_contents($tmp, "healthcheck " . date('c'));
    $up = $local->upload($tmp, 'HC_' . date('Y-m-d') . '_RE_AGLYON_IMB-000000_HEALTHCHECK_V1.txt');
    $dl = $tmp . '.dl';
    $local->download($up['file_id'], $dl);
    $matches = (hash_file('sha256', $dl) === $up['sha256']);
    $local->delete($up['file_id']);
    @unlink($tmp); @unlink($dl);
    if (is_dir($base)) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $f) { if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname()); }
        @rmdir($base);
    }
    hc('Driver storage LOCAL (round-trip)', $matches);
} catch (Throwable $e) {
    hc('Driver storage LOCAL', false, $e->getMessage());
}

// ─── 4. Driver Drive (si config OK) ──────────────────────────────────────────
if ($driveOk) {
    try {
        require_once __DIR__ . '/ged_storage_google.php';
        $drv = new GedStorageGoogleDrive([
            'client_id'      => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret'  => GOOGLE_DRIVE_CLIENT_SECRET,
            'refresh_token'  => GOOGLE_DRIVE_REFRESH_TOKEN,
            'root_folder_id' => GOOGLE_DRIVE_ROOT_FOLDER_ID,
            'drive_id'       => defined('GOOGLE_DRIVE_DRIVE_ID') ? (string)GOOGLE_DRIVE_DRIVE_ID : null,
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'gedhcd_') . '.txt';
        file_put_contents($tmp, "drive healthcheck " . date('c'));
        $folder = $drv->ensureFolder('_HEALTHCHECK_' . date('Ymd'));
        $up = $drv->upload($tmp, 'hc_drive_' . bin2hex(random_bytes(2)) . '.txt', $folder);
        $dl = $tmp . '.dl';
        $drv->download($up['file_id'], $dl);
        $matches = (hash_file('sha256', $dl) === $up['sha256']);
        $drv->delete($up['file_id']);
        $drv->delete($folder); // cleanup folder de test
        @unlink($tmp); @unlink($dl);
        hc('Driver storage DRIVE (round-trip Shared Drive)', $matches);
    } catch (Throwable $e) {
        hc('Driver storage DRIVE', false, $e->getMessage());
    }
} else {
    hc('Driver storage DRIVE', false, 'config Drive incomplète, skip');
}

// ─── 5. Ping IA Claude (court & cheap) ───────────────────────────────────────
if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '') {
    try {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => 'claude-sonnet-4-6',
                'max_tokens' => 10,
                'messages'   => [['role' => 'user', 'content' => 'Réponds OK.']],
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = json_decode((string)$raw, true);
        $reply = $body['content'][0]['text'] ?? '';
        hc('Ping Claude Sonnet 4.6', $code === 200 && $reply !== '',
            $code === 200 ? 'reply: ' . substr($reply, 0, 30) : "HTTP {$code}");
    } catch (Throwable $e) {
        hc('Ping Claude Sonnet 4.6', false, $e->getMessage());
    }
} else {
    hc('Ping Claude Sonnet 4.6', false, 'ANTHROPIC_API_KEY absent');
}

// ─── 6. Ping IA OpenAI ───────────────────────────────────────────────────────
if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . OPENAI_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => 'gpt-4o-mini',
                'max_tokens' => 5,
                'messages'   => [['role' => 'user', 'content' => 'Reply OK.']],
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = json_decode((string)$raw, true);
        $reply = $body['choices'][0]['message']['content'] ?? '';
        hc('Ping OpenAI GPT-4o-mini (fallback)', $code === 200 && $reply !== '',
            $code === 200 ? 'reply: ' . substr($reply, 0, 30) : "HTTP {$code}");
    } catch (Throwable $e) {
        hc('Ping OpenAI', false, $e->getMessage());
    }
} else {
    hc('Ping OpenAI', false, 'OPENAI_API_KEY absent');
}

// ─── Sortie ──────────────────────────────────────────────────────────────────
$total = $pass + $fail;
$score = $total > 0 ? round(100 * $pass / $total, 1) : 0;
$globalOk = ($fail === 0);

if ($isCli) {
    echo "═══ GED Healthcheck ═══" . PHP_EOL;
    foreach ($tests as $t) {
        echo ($t['ok'] ? '[PASS] ' : '[FAIL] ') . $t['label'];
        if ($t['detail'] !== '') echo ' — ' . $t['detail'];
        echo PHP_EOL;
    }
    echo "─────────────────────────" . PHP_EOL;
    echo "Score : {$pass}/{$total} ({$score}%) — " . ($globalOk ? '✅ TOUT OK' : '❌ ' . $fail . ' KO') . PHP_EOL;
    exit($globalOk ? 0 : 1);
}

http_response_code($globalOk ? 200 : 503);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>GED Healthcheck — MaBoxImmo</title>
<meta name="robots" content="noindex, nofollow">
<style>
    body { font: 14px/1.5 -apple-system, system-ui, sans-serif; background: #f5f6fa; color: #1f2937; padding: 20px; max-width: 900px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 6px; }
    .sub { color: #6b7280; margin-bottom: 20px; }
    .card { background: #fff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 12px; }
    .row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
    .row:last-child { border-bottom: 0; }
    .badge { display: inline-block; min-width: 50px; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; color: #fff; text-align: center; }
    .pass { background: #10b981; }
    .fail { background: #ef4444; }
    .label { flex: 1; }
    .detail { color: #6b7280; font-size: 12px; font-family: ui-monospace, monospace; }
    .summary { padding: 16px 20px; border-radius: 10px; font-size: 18px; font-weight: 600; }
    .summary.ok  { background: #d1fae5; color: #065f46; }
    .summary.bad { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
    <h1>🏥 GED Healthcheck</h1>
    <div class="sub">Validation complète de la stack GED MaBoxImmo · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></div>

    <div class="card">
        <?php foreach ($tests as $t): ?>
            <div class="row">
                <span class="badge <?= $t['ok'] ? 'pass' : 'fail' ?>"><?= $t['ok'] ? 'PASS' : 'FAIL' ?></span>
                <span class="label"><?= htmlspecialchars($t['label']) ?></span>
                <span class="detail"><?= htmlspecialchars($t['detail']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="summary <?= $globalOk ? 'ok' : 'bad' ?>">
        <?= $globalOk ? '✅ TOUT OK' : '❌ ' . $fail . ' test(s) KO' ?>
        — <?= $pass ?>/<?= $total ?> (<?= $score ?>%)
    </div>

    <p style="color:#6b7280;font-size:12px;margin-top:20px">
        ℹ️ Ce script ne révèle aucun secret — uniquement les longueurs et premiers caractères des clés.
        Inutile de le supprimer en prod, l'accès est restreint aux rôles admin (1, 7, 8).
    </p>
</body>
</html>
