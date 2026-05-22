<?php
// admin/admin_transaction_diag_ia.php — Diagnostic configuration IA pour module Transaction
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }

$tests = [];

// 1. Clé Anthropic
$keyGlobal = $GLOBALS['ANTHROPIC_API_KEY'] ?? '';
$keyConst  = defined('ANTHROPIC_API_KEY') ? (string)ANTHROPIC_API_KEY : '';
$keyEnv    = (string)(getenv('ANTHROPIC_API_KEY') ?: '');
$key = $keyGlobal ?: $keyConst ?: $keyEnv;
$tests['Clé Anthropic'] = [
    'ok' => $key !== '',
    'detail' => $key !== ''
        ? '✓ Présente (' . strlen($key) . ' chars, débute par "' . substr($key, 0, 8) . '...")'
        : '✗ ABSENTE. Configurer dans /home/u630423897/anthropic_config.php OU u630423897/anthropic_config.php OU define(\'ANTHROPIC_API_KEY\', \'sk-ant-...\') quelque part.',
];

// 2. Helpers IA présents
$tests['Fichier mbi_supports_score_ia.php'] = [
    'ok' => is_file(__DIR__ . '/../inc/mbi_supports_score_ia.php'),
    'detail' => is_file(__DIR__ . '/../inc/mbi_supports_score_ia.php') ? '✓ Présent' : '✗ Absent',
];
$tests['Fichier transaction_doc_extract_ia.php'] = [
    'ok' => is_file(__DIR__ . '/../inc/transaction_doc_extract_ia.php'),
    'detail' => is_file(__DIR__ . '/../inc/transaction_doc_extract_ia.php') ? '✓ Présent' : '✗ Absent',
];

// 3. Fonction disponible
require_once __DIR__ . '/../inc/transaction_doc_extract_ia.php';
$tests['Fonction transaction_doc_extract_ia()'] = [
    'ok' => function_exists('transaction_doc_extract_ia'),
    'detail' => function_exists('transaction_doc_extract_ia') ? '✓ Chargée' : '✗ Pas définie',
];
$tests['Fonction mbi_supports_ia_anthropic_key()'] = [
    'ok' => function_exists('mbi_supports_ia_anthropic_key'),
    'detail' => function_exists('mbi_supports_ia_anthropic_key') ? '✓ Chargée' : '✗ Pas définie',
];

// 4. PHP config
$tests['php.ini : max_execution_time'] = [
    'ok' => (int)ini_get('max_execution_time') >= 120 || (int)ini_get('max_execution_time') === 0,
    'detail' => ini_get('max_execution_time') . ' s (recommandé : 0 ou ≥ 120)',
];
$tests['php.ini : upload_max_filesize'] = [
    'ok' => true,
    'detail' => ini_get('upload_max_filesize'),
];
$tests['php.ini : post_max_size'] = [
    'ok' => true,
    'detail' => ini_get('post_max_size'),
];
$tests['Extension cURL'] = [
    'ok' => function_exists('curl_init'),
    'detail' => function_exists('curl_init') ? '✓ ' . (curl_version()['version'] ?? '?') : '✗ Absent',
];

// 5. Test live appel Anthropic minimal (si clé présente + GET ?test=1)
$liveTest = null;
if ($key !== '' && isset($_GET['test'])) {
    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 20,
        'messages'   => [['role' => 'user', 'content' => 'Réponds juste OK.']],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $liveTest = [
        'http_code' => $code,
        'curl_error' => $err,
        'ok' => $code === 200,
        'response' => $raw ? substr((string)$raw, 0, 600) : null,
    ];
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Diagnostic IA Transaction</title>
<style>
body { font-family: Sora, sans-serif; padding: 30px; max-width: 1000px; background: #f7f4ef; color: #2c2a28; }
.card { background:#fff; border-radius:12px; padding:22px; margin-bottom:18px; box-shadow:0 4px 14px rgba(0,0,0,.08); }
table { width:100%; border-collapse:collapse; font-size:13px; }
td { padding:9px 10px; border-bottom:1px solid #f0ece6; vertical-align:top; }
.ok { color:#2d6a35; font-weight:700; }
.err { color:#a8323b; font-weight:700; }
.btn { display:inline-block; background:#4878a6; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:700; }
pre { background:#f4f1ec; padding:10px; border-radius:6px; font-size:11px; overflow-x:auto; max-width:100%; white-space:pre-wrap; word-break:break-word; }
</style></head><body>

<h1>🔧 Diagnostic IA Transaction</h1>

<div class="card">
    <h2>Vérifications</h2>
    <table>
        <?php foreach ($tests as $name => $t): ?>
            <tr>
                <td style="width:30%;"><strong><?= htmlspecialchars($name) ?></strong></td>
                <td><span class="<?= $t['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($t['detail']) ?></span></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if ($key === ''): ?>
<div class="card" style="background:#fef2f2; border-left:5px solid #a8323b;">
    <h2 class="err">⚠️ Clé Anthropic absente</h2>
    <p>Le module IA ne peut pas tourner sans la clé. Crée un fichier nommé <code>anthropic_config.php</code> dans <strong>un dossier hors webroot</strong> avec :</p>
    <pre>&lt;?php
define('ANTHROPIC_API_KEY', 'sk-ant-api03-xxxxxxxxxxxxxxxxxx');
define('ANTHROPIC_MODEL', 'claude-sonnet-4-6');
</pre>
    <p>Emplacements détectés (premier trouvé gagne) :</p>
    <ul>
        <li><code>/home/u630423897/anthropic_config.php</code> (Hostinger)</li>
        <li><code>c:\xampp\htdocs\MaBoxImmo2026挄23897\anthropic_config.php</code> (local XAMPP recommandé)</li>
        <li><code>c:\xampp\htdocs\MaBoxImmo2026\anthropic_config.php</code> (root projet, hors public_html)</li>
    </ul>
</div>
<?php else: ?>
<div class="card">
    <h2>Test live appel Anthropic</h2>
    <p>Lance un appel minimal "Réponds juste OK" pour valider la connexion.</p>
    <p><a href="?test=1" class="btn">▶️ Tester l'appel API</a></p>
    <?php if ($liveTest !== null): ?>
        <h3 class="<?= $liveTest['ok'] ? 'ok' : 'err' ?>">
            HTTP <?= (int)$liveTest['http_code'] ?> — <?= $liveTest['ok'] ? '✓ API joignable' : '✗ Échec' ?>
        </h3>
        <?php if ($liveTest['curl_error']): ?>
            <p class="err">cURL : <?= htmlspecialchars($liveTest['curl_error']) ?></p>
        <?php endif; ?>
        <?php if ($liveTest['response']): ?>
            <pre><?= htmlspecialchars($liveTest['response']) ?></pre>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<p style="margin-top:18px;">
    <a href="<?= htmlspecialchars(app_url('/transaction_chargement.php')) ?>">← Retour Chargement</a>
</p>

</body></html>
