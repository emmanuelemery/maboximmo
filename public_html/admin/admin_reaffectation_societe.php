<?php
/**
 * admin/admin_reaffectation_societe.php
 *
 * Script one-shot SUPER-ADMIN de réaffectation en masse :
 * tous les biens d'une société source → société/agence cible.
 *
 * Tables cascade : biens (id_societe, id_agence) + ged_documents (societe_id, agence_id)
 * pour les docs dont la societe_id = source.
 *
 * Workflow :
 *  1. Sélection source (société) + cible (société + agence)
 *  2. Bouton « Prévisualiser » : compte les enregistrements concernés (token TTL 10min)
 *  3. Bouton « Exécuter » : UPDATE en transaction
 *
 * Demande user 2026-05-23 : "comment faire car les biens ont été créés sous
 * LOCA IMMO mais c'est tout pour REGIE EMERY LYON ???"
 *
 * Page jetable — supprimer après usage si plus nécessaire.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$action = (string)($_POST['action'] ?? '');

// Liste des sociétés (pour les dropdowns)
$societes = [];
try {
    $societes = $pdo->query("SELECT id, nom FROM societes ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $societes = [];
}

// Helper JSON exit
$jsend = static function (array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

// ─── ENDPOINT : liste des agences d'une société (AJAX) ───
if ($action === 'list_agences') {
    $sid = (int)($_POST['societe_id'] ?? 0);
    $ags = [];
    if ($sid > 0) {
        try {
            $st = $pdo->prepare("SELECT id, nom_agence FROM agences WHERE id_societe = ? ORDER BY nom_agence ASC");
            $st->execute([$sid]);
            $ags = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {}
    }
    $jsend(['ok' => true, 'agences' => $ags]);
}

// ─── ENDPOINT : preview ───
if ($action === 'preview') {
    $src = (int)($_POST['societe_source'] ?? 0);
    $dst = (int)($_POST['societe_dest'] ?? 0);
    $dstAge = (int)($_POST['agence_dest'] ?? 0);
    if ($src <= 0 || $dst <= 0 || $dstAge <= 0) {
        $jsend(['ok' => false, 'error' => 'IDs source/cible/agence requis et > 0']);
    }
    if ($src === $dst) {
        $jsend(['ok' => false, 'error' => 'Source et cible identiques.']);
    }
    // Compte biens
    $nbBiens = (int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_societe = $src")->fetchColumn();
    // Compte ged_documents
    $nbDocs = 0;
    try {
        $nbDocs = (int)$pdo->query("SELECT COUNT(*) FROM ged_documents WHERE societe_id = $src")->fetchColumn();
    } catch (Throwable) {}
    // Échantillons (5 biens + nom société)
    $samples = [];
    try {
        $st = $pdo->prepare("SELECT id, reference_bien, designation, ville FROM biens WHERE id_societe = ? ORDER BY id DESC LIMIT 5");
        $st->execute([$src]);
        $samples = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
    $nomSrc = ''; $nomDst = ''; $nomAge = '';
    foreach ($societes as $s) {
        if ((int)$s['id'] === $src) $nomSrc = $s['nom'];
        if ((int)$s['id'] === $dst) $nomDst = $s['nom'];
    }
    try {
        $stAg = $pdo->prepare("SELECT nom_agence FROM agences WHERE id = ?");
        $stAg->execute([$dstAge]);
        $nomAge = (string)$stAg->fetchColumn();
    } catch (Throwable) {}

    $token = hash('sha256', "$src|$dst|$dstAge|" . ($_SESSION['user_id'] ?? 0));
    $_SESSION['reaffectation_token'] = ['hash' => $token, 'expires' => time() + 600, 'src' => $src, 'dst' => $dst, 'age' => $dstAge];

    $jsend([
        'ok'        => true,
        'mode'      => 'preview',
        'societe_source' => ['id' => $src, 'nom' => $nomSrc],
        'societe_dest'   => ['id' => $dst, 'nom' => $nomDst],
        'agence_dest'    => ['id' => $dstAge, 'nom' => $nomAge],
        'nb_biens'  => $nbBiens,
        'nb_docs'   => $nbDocs,
        'samples'   => $samples,
        'token'     => $token,
    ]);
}

// ─── ENDPOINT : execute ───
if ($action === 'execute') {
    $stored = $_SESSION['reaffectation_token'] ?? null;
    if (!is_array($stored) || empty($stored['hash']) || $stored['expires'] < time()) {
        $jsend(['ok' => false, 'error' => 'Token expiré — refais une Prévisualisation.']);
    }
    $submitted = (string)($_POST['token'] ?? '');
    if (!hash_equals($stored['hash'], $submitted)) {
        $jsend(['ok' => false, 'error' => 'Token invalide.']);
    }
    $src = (int)$stored['src'];
    $dst = (int)$stored['dst'];
    $dstAge = (int)$stored['age'];

    $deleted = []; // misnommé : c'est les UPDATE counts
    try {
        $pdo->beginTransaction();
        // 1. Biens
        $stB = $pdo->prepare("UPDATE biens SET id_societe = ?, id_agence = ? WHERE id_societe = ?");
        $stB->execute([$dst, $dstAge, $src]);
        $deleted['biens'] = $stB->rowCount();
        // 2. GED documents
        try {
            $stD = $pdo->prepare("UPDATE ged_documents SET societe_id = ?, agence_id = ? WHERE societe_id = ?");
            $stD->execute([$dst, $dstAge, $src]);
            $deleted['ged_documents'] = $stD->rowCount();
        } catch (Throwable $e) {
            error_log('[reaffectation ged_documents] ' . $e->getMessage());
            $deleted['ged_documents_error'] = $e->getMessage();
        }
        $pdo->commit();
        unset($_SESSION['reaffectation_token']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $jsend(['ok' => false, 'error' => 'Échec UPDATE : ' . $e->getMessage(), 'partial' => $deleted]);
    }

    error_log(sprintf(
        '[reaffectation_societe] OK user=%d src=%d dst=%d age=%d biens=%d docs=%d',
        (int)($_SESSION['user_id'] ?? 0), $src, $dst, $dstAge,
        $deleted['biens'] ?? 0, $deleted['ged_documents'] ?? 0
    ));

    $jsend(['ok' => true, 'updated' => $deleted]);
}

// ─── PAGE (GET) ───
$pageTitle    = 'Réaffectation société';
$pageSubtitle = 'Super admin — déplacer des biens d\'une société à une autre';
ob_start();
?>
<style>
.ras-wrap   { max-width:1000px; margin:0 auto; padding:20px; }
.ras-header { background:linear-gradient(135deg,#fef3c7,#fde68a); border-left:4px solid #d97706; padding:18px 22px; border-radius:10px; margin-bottom:20px; }
.ras-header h1 { margin:0 0 6px; color:#7c2d12; font-size:22px; }
.ras-header p  { margin:0; color:#9a3412; font-size:13px; }
.ras-card  { background:#fff; border-radius:12px; padding:20px 22px; margin-bottom:18px; box-shadow:4px 4px 12px #e3dfd8; }
.ras-card h3 { margin:0 0 14px; color:#2c2a28; font-size:15px; }
.ras-row   { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
.ras-row label { font-size:12px; color:#64748b; font-weight:600; display:block; margin-bottom:4px; }
.ras-row select { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit; font-size:13px; }
.ras-actions { display:flex; gap:10px; align-items:center; margin:16px 0; flex-wrap:wrap; }
.ras-btn { padding:9px 16px; border-radius:6px; font-weight:700; cursor:pointer; border:none; font-family:inherit; font-size:13px; }
.ras-btn.preview { background:#0ea5e9; color:#fff; }
.ras-btn.execute { background:#94a3b8; color:#fff; cursor:not-allowed; }
.ras-btn.execute.ready { background:#dc2626; cursor:pointer; }
.ras-back { background:#fff; color:#475569; border:1px solid #cbd5e1; padding:9px 16px; border-radius:6px; text-decoration:none; font-size:13px; }
.ras-result { background:#0f172a; color:#f1f5f9; padding:14px 18px; border-radius:8px; font-family:'DM Mono',monospace; font-size:12px; max-height:500px; overflow-y:auto; white-space:pre-wrap; }
</style>

<div class="ras-wrap">

  <div class="ras-header">
    <h1>↔️ Réaffectation société</h1>
    <p>
      Déplace <strong>tous les biens</strong> d'une société source vers une société + agence cible.
      <br>Cascade : <code>biens</code> (id_societe + id_agence) + <code>ged_documents</code> (societe_id + agence_id).
      <br><strong>Ne touche PAS</strong> aux annonces, mandats, propriétaires (hériteront via leur bien lié).
    </p>
  </div>

  <div class="ras-card">
    <h3>1️⃣ Source → Cible</h3>
    <div class="ras-row">
      <div>
        <label>Société SOURCE (les biens qui sont dedans)</label>
        <select id="ras-src">
          <option value="">— Choisir —</option>
          <?php foreach ($societes as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nom']) ?> (#<?= (int)$s['id'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Société CIBLE</label>
        <select id="ras-dst">
          <option value="">— Choisir —</option>
          <?php foreach ($societes as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nom']) ?> (#<?= (int)$s['id'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="ras-row">
      <div></div>
      <div>
        <label>Agence CIBLE (sera affectée à tous les biens)</label>
        <select id="ras-age">
          <option value="">— Choisir d'abord une société cible —</option>
        </select>
      </div>
    </div>
  </div>

  <div class="ras-card">
    <h3>2️⃣ Actions</h3>
    <div class="ras-actions">
      <button type="button" class="ras-btn preview" id="ras-preview-btn">🔍 Prévisualiser</button>
      <button type="button" class="ras-btn execute" id="ras-execute-btn" disabled>⚙️ Exécuter la réaffectation</button>
      <a class="ras-back" href="<?= htmlspecialchars(app_url('/super_admin_dashboard.php')) ?>">← Retour dashboard</a>
    </div>
  </div>

  <div class="ras-card">
    <h3>3️⃣ Résultat</h3>
    <div class="ras-result" id="ras-result">Choisis source + cible + agence puis 🔍 Prévisualiser.</div>
  </div>

</div>

<script>
(function(){
  const endpoint = window.location.href; // POST sur self
  let token = null;
  const $ = id => document.getElementById(id);
  const selSrc = $('ras-src'), selDst = $('ras-dst'), selAge = $('ras-age');
  const btnPrev = $('ras-preview-btn'), btnExec = $('ras-execute-btn'), result = $('ras-result');

  async function postJson(action, params) {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(params || {}).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }

  // Au changement de société cible, recharge les agences
  selDst.addEventListener('change', async () => {
    selAge.innerHTML = '<option value="">⏳ Chargement…</option>';
    const j = await postJson('list_agences', { societe_id: selDst.value });
    selAge.innerHTML = '<option value="">— Choisir —</option>';
    (j.agences || []).forEach(a => {
      const opt = document.createElement('option');
      opt.value = a.id;
      opt.textContent = a.nom_agence + ' (#' + a.id + ')';
      selAge.appendChild(opt);
    });
  });

  btnPrev.addEventListener('click', async () => {
    btnPrev.disabled = true; btnPrev.textContent = '⏳ Préview…';
    btnExec.disabled = true; btnExec.classList.remove('ready');
    result.textContent = 'Chargement…';
    try {
      const j = await postJson('preview', {
        societe_source: selSrc.value,
        societe_dest:   selDst.value,
        agence_dest:    selAge.value,
      });
      result.textContent = JSON.stringify(j, null, 2);
      if (j.ok) {
        token = j.token;
        btnExec.disabled = false; btnExec.classList.add('ready');
      }
    } catch (e) {
      result.textContent = '❌ ' + e.message;
    }
    btnPrev.disabled = false; btnPrev.textContent = '🔍 Prévisualiser';
  });

  btnExec.addEventListener('click', async () => {
    if (!token) return;
    const srcLabel = selSrc.options[selSrc.selectedIndex].textContent;
    const dstLabel = selDst.options[selDst.selectedIndex].textContent;
    if (!confirm('⚠️ RÉAFFECTATION DÉFINITIVE\n\nDe : ' + srcLabel + '\nVers : ' + dstLabel + '\n\nConfirmer ?')) return;
    btnExec.disabled = true; btnExec.textContent = '⏳ Exécution…';
    try {
      const j = await postJson('execute', { token });
      result.textContent = JSON.stringify(j, null, 2);
      if (j.ok) {
        btnExec.textContent = '✅ Réaffecté';
        token = null;
      } else {
        btnExec.disabled = false; btnExec.textContent = '⚙️ Exécuter la réaffectation';
      }
    } catch (e) {
      result.textContent = '❌ ' + e.message;
      btnExec.disabled = false; btnExec.textContent = '⚙️ Exécuter la réaffectation';
    }
  });
})();
</script>

<?php
$layout_content = ob_get_clean();
require_once dirname(__DIR__) . '/inc/layout_maboximmo.php';
?>
