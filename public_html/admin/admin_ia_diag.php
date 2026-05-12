<?php
declare(strict_types=1);

/**
 * Diagnostic clé Anthropic + test rédaction Haiku.
 * Permet de vérifier en 1 page que :
 *   - la clé ANTHROPIC_API_KEY est définie côté local
 *   - Haiku répond correctement à un mini prompt
 *   - le module mbi_supports_redaction_ia retourne bien data structurée
 *
 * Réservé super admin.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

require_once __DIR__ . '/../inc/mbi_supports_score_ia.php';
require_once __DIR__ . '/../inc/mbi_supports_redaction_ia.php';

// ─── Sources de la clé ──────────────────────────────────────────────
$srcGlobals = $GLOBALS['ANTHROPIC_API_KEY'] ?? null;
$srcDefine  = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : null;
$srcEnv     = getenv('ANTHROPIC_API_KEY') ?: null;
$keyResolved = mbi_supports_ia_anthropic_key();
$hasKey      = $keyResolved !== '';

function maskKey(?string $k): string {
    if (!$k) return '<em>vide</em>';
    $len = strlen($k);
    if ($len < 12) return '••• (trop court : ' . $len . ' chars)';
    return htmlspecialchars(substr($k, 0, 8)) . '…' . htmlspecialchars(substr($k, -4)) . ' <span style="color:#94a3b8;">(' . $len . ' chars)</span>';
}

// ─── Test rédaction si clé présente ─────────────────────────────────
$test = null;
$testTimeMs = null;
if ($hasKey && isset($_GET['test'])) {
    $bienFake = [
        'designation'   => 'Appartement T2 lumineux',
        'ville'         => 'Lyon',
        'code_postal'   => '69007',
        'surface_habitable' => 52,
        'nb_pieces'     => 2,
        'loyer'         => 850,
        'depot_garantie'=> 850,
        'description'   => 'Bel appartement de 52 m² au 7 rue Lortet à Lyon 7ème, séjour 22 m², cuisine équipée, double exposition.',
    ];
    $agenceFake = ['nom_agence' => 'REGIE EMERY CHAPONOST', 'ville' => 'CHAPONOST'];
    $angle = (string)($_GET['angle'] ?? 'famille');

    $t0 = microtime(true);
    $test = mbi_supports_redaction_ia_generer($bienFake, [], $agenceFake, $angle, null, 'haiku');
    $testTimeMs = (int) round((microtime(true) - $t0) * 1000);
}

$appLayout = true;
$pageTitle = 'Diag IA';
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .ia { max-width: 1000px; margin: 0 auto; padding: 24px 20px; font-size: 13px; }
  .ia h1 { font-size: 22px; color:#0f172a; margin: 0 0 12px; }
  .ia-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; margin-bottom:14px; }
  .ia-card h2 { font-size:14px; color:#0f172a; margin: 0 0 10px; }
  .ia-grid { display:grid; grid-template-columns: 280px 1fr; gap:8px 14px; align-items:baseline; }
  .ia-grid > div:nth-child(odd) { color:#64748b; font-weight:600; }
  .ia-ok    { color:#166534; font-weight:700; }
  .ia-err   { color:#991b1b; font-weight:700; }
  .ia-warn  { color:#92400e; }
  .ia-key   { font-family: monospace; }
  .ia-pre   { background:#f8fafc; border:1px solid #e5e7eb; padding:10px 12px; border-radius:8px; font-family: monospace; font-size: 11px; max-height:300px; overflow:auto; white-space: pre-wrap; }
  .ia-btn   { display:inline-block; padding:9px 16px; background:#243B5C; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; font-size:12px; margin-right:8px; }
  .ia-btn:hover { background:#1e3050; }
</style>

<div class="ia">
  <h1>🤖 Diagnostic IA — Anthropic / Haiku</h1>

  <!-- 1. Clé API -->
  <div class="ia-card">
    <h2>1️⃣ Clé <code>ANTHROPIC_API_KEY</code></h2>
    <div class="ia-grid">
      <div>$GLOBALS['ANTHROPIC_API_KEY']</div><div class="ia-key"><?= maskKey($srcGlobals) ?></div>
      <div>defined('ANTHROPIC_API_KEY')</div><div class="ia-key"><?= maskKey($srcDefine) ?></div>
      <div>getenv('ANTHROPIC_API_KEY')</div><div class="ia-key"><?= maskKey($srcEnv) ?></div>
      <div>Clé résolue (utilisée par l'app)</div>
      <div class="ia-key"><strong class="<?= $hasKey ? 'ia-ok' : 'ia-err' ?>">
        <?= $hasKey ? '✅ ' . maskKey($keyResolved) : '❌ AUCUNE CLÉ DISPONIBLE' ?>
      </strong></div>
    </div>

    <?php if (!$hasKey): ?>
      <div style="margin-top:14px;padding:12px 14px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px;">
        <strong style="color:#991b1b;">Sans clé API, la rédaction Haiku ne tourne pas → les 4 affiches ont le même texte (fallback `bien.designation`).</strong>
        <p style="margin:8px 0 4px;font-size:12px;color:#7f1d1d;">
          Pour ajouter la clé en local XAMPP, 3 façons :
        </p>
        <ol style="font-size:12px;color:#7f1d1d;margin:4px 0 0;padding-left:20px;">
          <li>Édite <code>public_html/inc/bootstrap.php</code> et ajoute en haut :<br>
            <code style="background:#fff;padding:2px 6px;border-radius:4px;">define('ANTHROPIC_API_KEY', 'sk-ant-...');</code></li>
          <li>Ou édite <code>C:\xampp\php\php.ini</code> → ajoute <code>ANTHROPIC_API_KEY=sk-ant-...</code> sous <code>[ENV]</code></li>
          <li>Ou crée un fichier <code>config/secrets.php</code> chargé par bootstrap (cf. autres clés)</li>
        </ol>
      </div>
    <?php endif; ?>
  </div>

  <!-- 2. Test rédaction -->
  <div class="ia-card">
    <h2>2️⃣ Test rédaction Haiku (4 angles)</h2>
    <?php if (!$hasKey): ?>
      <div class="ia-warn">⚠️ Test impossible sans clé.</div>
    <?php else: ?>
      <div style="margin-bottom:14px;">
        <a class="ia-btn" href="?test=1&angle=famille">Tester angle FAMILLE</a>
        <a class="ia-btn" href="?test=1&angle=investisseur">Tester INVESTISSEUR</a>
        <a class="ia-btn" href="?test=1&angle=premium">Tester PREMIUM</a>
        <a class="ia-btn" href="?test=1&angle=premier_achat">Tester PRIMO</a>
      </div>

      <?php if ($test === null): ?>
        <div class="ia-warn">Clique un angle ci-dessus pour lancer un appel test (~3s).</div>
      <?php else: ?>
        <div class="ia-grid">
          <div>Statut</div>
          <div><strong class="<?= ($test['ok'] ?? false) ? 'ia-ok' : 'ia-err' ?>">
            <?= ($test['ok'] ?? false) ? '✅ OK' : '❌ ÉCHEC : ' . htmlspecialchars((string)($test['erreur'] ?? '?')) ?>
          </strong></div>
          <div>Modèle utilisé</div><div><code><?= htmlspecialchars((string)($test['modele'] ?? '?')) ?></code></div>
          <div>Coût (centimes)</div><div><?= (int)($test['cout_centimes'] ?? 0) ?> ¢</div>
          <div>Durée appel</div><div><?= $testTimeMs ?> ms</div>
          <div>Angle testé</div><div><?= htmlspecialchars((string)($_GET['angle'] ?? '?')) ?></div>
        </div>

        <?php if (!empty($test['data'])): ?>
          <h3 style="margin-top:18px;font-size:13px;">📝 Contenu retourné</h3>
          <div class="ia-grid" style="font-size:12px;">
            <div>Accroche</div><div style="font-style:italic;color:#0f172a;">« <?= htmlspecialchars((string)($test['data']['accroche'] ?? '')) ?> »</div>
            <div>Paragraphe</div><div><?= nl2br(htmlspecialchars((string)($test['data']['paragraphe'] ?? ''))) ?></div>
            <div>Atouts</div><div><ul style="margin:0;padding-left:18px;">
              <?php foreach ((array)($test['data']['atouts'] ?? []) as $a): ?>
                <li><?= htmlspecialchars((string)$a) ?></li>
              <?php endforeach; ?>
            </ul></div>
            <div>Titre court</div><div><?= htmlspecialchars((string)($test['data']['titre_court'] ?? '')) ?></div>
          </div>
        <?php endif; ?>

        <h3 style="margin-top:18px;font-size:13px;">🔧 Réponse brute (debug)</h3>
        <div class="ia-pre"><?= htmlspecialchars(json_encode($test, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- 3. Liens utiles -->
  <div class="ia-card">
    <h2>3️⃣ Si tout est OK</h2>
    <p style="margin:0 0 8px;color:#475569;">Si la clé est OK et que les 4 angles renvoient des textes différents, le problème vient probablement du <strong>cache OPcache PHP</strong> sur ton XAMPP — le bootstrap actuel ne recharge pas la clé. Solution :</p>
    <ul style="color:#475569;margin:0;">
      <li>Redémarre Apache après ajout de la clé</li>
      <li>Si OPcache est actif : <code>opcache_reset()</code> ou redémarrage Apache</li>
    </ul>
  </div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
