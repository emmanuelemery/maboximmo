<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

if (!function_exists('e')) {
    function e(?string $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$current_page = 'assistant_ia';

/* ── Détection du contexte nav depuis code_acces ─────── */
// Determine code_acces: from session or from DB
$codeAcces = strtolower(trim((string)($_SESSION['code_acces'] ?? '')));
if ($codeAcces === '') {
    // Fallback: check DB for this user
    $stmtCA = $pdo->prepare("SELECT code_acces FROM users WHERE id = ?");
    $stmtCA->execute([$_SESSION['user_id'] ?? 0]);
    $codeAcces = strtolower(trim((string)($stmtCA->fetchColumn() ?: '')));
    if ($codeAcces !== '') $_SESSION['code_acces'] = strtoupper($codeAcces);
}
$nav_context = match ($codeAcces) {
    'sir'    => 'sir',
    'proprio'=> 'proprio',
    'admin'  => 'admin',
    default  => 'admin',
};
$isSir = ($nav_context === 'sir');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Assistant IA — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/gestion.css') ?>">
</head>
<body class="<?= $isSir ? 'theme-sir' : '' ?>">

<?php include __DIR__ . '/../sidebar_bailleur.php'; ?>
<?php include __DIR__ . '/inc/nav_gestion.php'; ?>

<main style="margin-left:260px;padding:32px 40px;">
  <h1 style="font-size:22px;font-weight:600;margin-bottom:24px;color:var(--text-primary);">Assistant IA</h1>

  <div style="background:var(--bg-secondary, #fff);border:1px solid var(--border-color, rgba(0,0,0,.08));border-radius:12px;overflow:hidden;">
    <div class="chat-container">
      <div class="chat-messages" id="chatMessages">
        <div class="chat-msg ia">Bonjour, comment puis-je vous aider ?</div>
      </div>
      <div class="chat-input">
        <input type="text" id="chatInput" placeholder="Posez votre question..." style="
          flex:1;padding:10px 14px;border:1px solid var(--border-color, rgba(0,0,0,.1));
          border-radius:8px;font-size:13px;font-family:'Sora',sans-serif;
          background:var(--bg-tertiary, var(--gray-50));color:var(--text-primary, #111);">
        <button id="chatSend" style="
          padding:10px 18px;border:none;border-radius:8px;cursor:pointer;font-size:13px;
          font-family:'Sora',sans-serif;font-weight:500;
          background:var(--accent-color, var(--brand-primary, #d4a843));color:#fff;">
          Envoyer
        </button>
      </div>
    </div>
  </div>
</main>

<script>
(function(){
  const msgs   = document.getElementById('chatMessages');
  const input  = document.getElementById('chatInput');
  const btn    = document.getElementById('chatSend');
  const ctx    = <?= json_encode($codeAcces) ?>;

  function addMsg(text, cls) {
    const d = document.createElement('div');
    d.className = 'chat-msg ' + cls;
    d.textContent = text;
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    return d;
  }

  async function send() {
    const q = input.value.trim();
    if (!q) return;
    addMsg(q, 'user');
    input.value = '';
    const loading = addMsg('...', 'ia');

    try {
      const res = await fetch('<?= app_url('/api/ask_ia.php') ?>', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ question: q, contexte: ctx })
      });
      if (res.redirected || !res.ok) {
        loading.textContent = res.redirected ? 'Session expirée. Reconnectez-vous.' : 'Erreur serveur (' + res.status + ')';
        return;
      }
      const data = await res.json();
      loading.textContent = data.reponse || data.error || 'Erreur inconnue.';
    } catch (err) {
      loading.textContent = 'Erreur de connexion.';
    }
  }

  btn.addEventListener('click', send);
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); send(); }
  });
})();
</script>
</body>
</html>
