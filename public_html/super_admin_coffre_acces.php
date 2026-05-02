<?php
declare(strict_types=1);

/**
 * Ma Box Immo — COFFRE ACCES sécurisé (page super admin)
 *
 * Stocke des credentials sensibles chiffrés AES-256-GCM.
 * Affichage masqué par défaut, bouton "👁️ Voir" pour déchiffrer (avec log).
 *
 * INTERDICTION absolue : ne jamais déposer de mot de passe en GED standard.
 *
 * Réservé super admin (id_role = 1).
 * Layout MBI standard (header.php).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/secure_credentials_functions.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé super admin.</h1>');
}

$pdo = coffre_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$flash = null;

// ─── Vérif config ────────────────────────────────────────────
$keyOk = false;
$keyError = null;
try {
    $k = coffre_master_key();
    if (strlen($k) === 32) $keyOk = true;
} catch (Throwable $e) { $keyError = $e->getMessage(); }

$appLayout = true;
$pageTitle = 'Coffre accès sécurisé';
$bodyClass = '';
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/ged_help_button.php'; // V2.5 — bouton "Guide GED" topbar
?>
<link rel="stylesheet" href="/css/ged_import.css?v=<?= @filemtime(__DIR__ . '/css/ged_import.css') ?: time() ?>">
<style>
  .coffre-wrap { max-width: 1300px; margin: 0 auto; padding: 24px 20px; }
  .coffre-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .coffre-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 18px; }
  .coffre-warn { background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 16px;
    border-radius: 8px; color: #991b1b; font-size: 13px; margin-bottom: 16px; }
  .coffre-config-warn { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px;
    border-radius: 8px; color: #92400e; font-size: 12px; margin-bottom: 16px; }
  .coffre-grid { display: grid; grid-template-columns: 1fr 380px; gap: 18px; }
  @media (max-width: 1100px) { .coffre-grid { grid-template-columns: 1fr; } }
  .coffre-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 20px; }
  .coffre-card h2 { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 14px;
    padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
  .coffre-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .coffre-table th, .coffre-table td { padding: 8px 10px; text-align: left;
    border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .coffre-table th { background: #f8fafc; font-weight: 700; color: #475569;
    font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
  .coffre-cell-value {
    font-family: monospace; font-size: 12px; color: #94a3b8;
    background: #f8fafc; padding: 4px 8px; border-radius: 4px; display: inline-block;
  }
  .coffre-cell-value.is-revealed { color: #0f172a; background: #fef3c7; font-weight: 700; }
  .coffre-badge-cat {
    display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px;
    font-weight: 700; text-transform: uppercase;
  }
  .coffre-badge-cat.compte       { background: #dbeafe; color: #1e40af; }
  .coffre-badge-cat.identifiant  { background: #ede9fe; color: #5b21b6; }
  .coffre-badge-cat.contrat      { background: #fce7f3; color: #9d174d; }
  .coffre-badge-cat.banque       { background: #d1fae5; color: #065f46; }
  .coffre-badge-cat.fiscal       { background: #fef3c7; color: #92400e; }
  .coffre-badge-cat.autre        { background: #f1f5f9; color: #475569; }
  .coffre-form-row { display: grid; grid-template-columns: 1fr; gap: 8px; margin-bottom: 10px; }
  .coffre-form-row label { display: block; font-size: 11px; font-weight: 600;
    color: #475569; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; }
  .coffre-form-row input, .coffre-form-row textarea, .coffre-form-row select {
    width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; font-family: inherit; box-sizing: border-box;
  }
  .coffre-form-row textarea { font-family: monospace; min-height: 60px; }
  .coffre-actions a { font-size: 11px; text-decoration: none; margin-right: 8px; }
  .coffre-actions a.coffre-reveal { color: #0369a1; }
  .coffre-actions a.coffre-copy   { color: #16a34a; }
  .coffre-actions a.coffre-delete { color: #dc2626; }
  .coffre-empty { color: #94a3b8; font-style: italic; padding: 30px; text-align: center; }
</style>

<div class="coffre-wrap">
  <h1>🔐 Coffre accès sécurisé</h1>
  <p class="sub">Stockage chiffré AES-256-GCM des identifiants sensibles. Réservé super admin. Tout accès est journalisé.</p>

  <div class="coffre-warn">
    <strong>⚠️ INTERDICTION ABSOLUE :</strong> aucun mot de passe ne doit être déposé dans la GED standard
    (ged_documents). Toutes les credentials passent par ce coffre chiffré.
  </div>

  <?php if (!$keyOk): ?>
    <div class="coffre-config-warn">
      ⚠️ <strong>COFFRE_MASTER_KEY non configurée</strong>.
      Crée le fichier <code>config/coffre.php</code> avec :<br>
      <code>&lt;?php define('COFFRE_MASTER_KEY', '&lt;64 caractères hex aléatoires&gt;');</code><br>
      Génère une clé : <code>openssl rand -hex 32</code> (Linux/Mac) ou via un générateur en ligne.
      <?php if ($keyError): ?><br><small>Détail : <?= $h($keyError) ?></small><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="coffre-grid">
    <!-- ─── Liste credentials ─────────────────────────── -->
    <div class="coffre-card">
      <h2>📋 Credentials (chiffrés)</h2>

      <div style="display:flex;gap:8px;margin-bottom:12px">
        <select id="coffre-filter-cat">
          <option value="">— Toutes catégories —</option>
          <option value="compte">Compte</option>
          <option value="identifiant">Identifiant</option>
          <option value="contrat">Contrat</option>
          <option value="banque">Banque</option>
          <option value="fiscal">Fiscal</option>
          <option value="autre">Autre</option>
        </select>
        <input type="search" id="coffre-search" placeholder="🔍 Rechercher..." style="flex:1">
      </div>

      <div id="coffre-list-wrap">
        <table class="coffre-table">
          <thead>
            <tr>
              <th>Label</th>
              <th>Cat.</th>
              <th>Indication</th>
              <th>Valeur</th>
              <th>Dernier accès</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="coffre-tbody">
            <tr><td colspan="6" class="coffre-empty">Chargement…</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ─── Formulaire création ───────────────────────── -->
    <div class="coffre-card">
      <h2 id="coffre-form-title">➕ Nouveau credential</h2>
      <form id="coffre-form">
        <input type="hidden" id="coffre-form-id" value="">

        <div class="coffre-form-row">
          <label>Label *</label>
          <input type="text" id="coffre-input-label" required placeholder="ex: Banque CIC compte courant SCI">
        </div>
        <div class="coffre-form-row">
          <label>Catégorie</label>
          <select id="coffre-input-category">
            <option value="autre">Autre</option>
            <option value="compte">Compte</option>
            <option value="identifiant">Identifiant</option>
            <option value="contrat">Contrat</option>
            <option value="banque">Banque</option>
            <option value="fiscal">Fiscal</option>
          </select>
        </div>
        <div class="coffre-form-row">
          <label>Indication (visible, non chiffrée)</label>
          <input type="text" id="coffre-input-hint" placeholder="ex: ****1234, identifiant @entreprise.fr">
        </div>
        <div class="coffre-form-row">
          <label>Valeur secrète (sera chiffrée) *</label>
          <textarea id="coffre-input-plaintext" required placeholder="Mot de passe / token / numéro / certificat..."></textarea>
        </div>
        <div class="coffre-form-row">
          <label>Partager avec (id users séparés par virgule)</label>
          <input type="text" id="coffre-input-shared" placeholder="ex: 8,12,15">
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="gimp-btn gimp-btn-primary" style="flex:1">💾 Enregistrer</button>
          <button type="button" id="coffre-form-reset" class="gimp-btn gimp-btn-ghost">↺</button>
        </div>
      </form>
    </div>
  </div>

  <p style="text-align:center;margin-top:18px;font-size:12px;color:#64748b">
    🔍 <a href="/api/secure_credentials.php?action=logs" target="_blank" style="color:#0369a1">Voir le journal d'accès (JSON brut, 200 dernières lignes)</a>
  </p>
</div>

<script>
(function () {
  const API = '/api/secure_credentials.php';
  const $ = (s) => document.querySelector(s);
  let currentItems = [];

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g,
      c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }

  async function api(action, opts) {
    opts = opts || {};
    const url = API + (opts.method === 'POST' ? '' : '?action=' + encodeURIComponent(action) + (opts.qs || ''));
    const init = { method: opts.method || 'GET', credentials: 'same-origin' };
    if (opts.method === 'POST') {
      const fd = opts.formData || new FormData();
      if (!fd.has('action')) fd.append('action', action);
      init.body = fd;
    }
    const r = await fetch(url, init);
    const txt = await r.text();
    let j;
    try { j = JSON.parse(txt); } catch (_) {
      throw new Error('Réponse non-JSON : ' + txt.slice(0, 160));
    }
    if (!j.ok) throw new Error(j.error || 'Erreur API');
    return j;
  }

  async function reloadList() {
    const cat = $('#coffre-filter-cat').value;
    const search = $('#coffre-search').value;
    const qs = '&category=' + encodeURIComponent(cat) + '&search=' + encodeURIComponent(search);
    try {
      const j = await api('list', { qs: qs });
      currentItems = j.items;
      renderTable(j.items);
    } catch (err) {
      $('#coffre-tbody').innerHTML = '<tr><td colspan="6" class="coffre-empty" style="color:#dc2626">❌ ' + escapeHtml(err.message) + '</td></tr>';
    }
  }

  function renderTable(items) {
    const tb = $('#coffre-tbody');
    if (!items.length) {
      tb.innerHTML = '<tr><td colspan="6" class="coffre-empty">Aucun credential. Crée-en un avec le formulaire à droite.</td></tr>';
      return;
    }
    tb.innerHTML = items.map(it => `
      <tr data-id="${it.id}">
        <td><strong>${escapeHtml(it.label)}</strong></td>
        <td><span class="coffre-badge-cat ${escapeHtml(it.category)}">${escapeHtml(it.category)}</span></td>
        <td><small style="color:#64748b">${escapeHtml(it.hint || '—')}</small></td>
        <td><span class="coffre-cell-value" id="coffre-val-${it.id}">●●●●●●●●●●</span></td>
        <td><small style="color:#94a3b8">${escapeHtml(it.last_accessed_at || '—')}</small></td>
        <td class="coffre-actions">
          <a href="#" class="coffre-reveal"  data-id="${it.id}">👁️ Voir</a>
          <a href="#" class="coffre-copy"    data-id="${it.id}">📋 Copier</a>
          <a href="#" class="coffre-delete"  data-id="${it.id}">🗑️ Supprimer</a>
        </td>
      </tr>`).join('');
  }

  async function reveal(id, copy) {
    const cell = $('#coffre-val-' + id);
    if (cell.classList.contains('is-revealed') && !copy) {
      // Toggle hide
      cell.textContent = '●●●●●●●●●●';
      cell.classList.remove('is-revealed');
      return;
    }
    try {
      const fd = new FormData(); fd.append('action', 'reveal'); fd.append('id', id);
      const j = await api('reveal', { method: 'POST', formData: fd });
      cell.textContent = j.plaintext;
      cell.classList.add('is-revealed');
      if (copy && navigator.clipboard) {
        await navigator.clipboard.writeText(j.plaintext);
        const orig = cell.textContent;
        cell.textContent = '📋 copié !';
        setTimeout(() => { cell.textContent = orig; }, 1200);
      }
      // Auto-masquer après 30s
      setTimeout(() => {
        if (cell.classList.contains('is-revealed')) {
          cell.textContent = '●●●●●●●●●●';
          cell.classList.remove('is-revealed');
        }
      }, 30000);
    } catch (err) {
      alert('❌ ' + err.message);
    }
  }

  async function deleteItem(id) {
    if (!confirm('Supprimer ce credential (soft delete) ?')) return;
    try {
      const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
      await api('delete', { method: 'POST', formData: fd });
      reloadList();
    } catch (err) { alert('❌ ' + err.message); }
  }

  async function submitForm(evt) {
    evt.preventDefault();
    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('label',     $('#coffre-input-label').value);
    fd.append('category',  $('#coffre-input-category').value);
    fd.append('hint',      $('#coffre-input-hint').value);
    fd.append('plaintext', $('#coffre-input-plaintext').value);
    const sharedRaw = $('#coffre-input-shared').value;
    if (sharedRaw.trim()) {
      sharedRaw.split(',').forEach(uid => {
        const n = parseInt(uid.trim(), 10);
        if (n > 0) fd.append('shared_with[]', n);
      });
    }
    try {
      await api('create', { method: 'POST', formData: fd });
      $('#coffre-form').reset();
      reloadList();
    } catch (err) { alert('❌ ' + err.message); }
  }

  document.addEventListener('DOMContentLoaded', () => {
    reloadList();
    $('#coffre-form').addEventListener('submit', submitForm);
    $('#coffre-form-reset').addEventListener('click', () => $('#coffre-form').reset());
    $('#coffre-filter-cat').addEventListener('change', reloadList);
    $('#coffre-search').addEventListener('input', () => {
      clearTimeout(window._cofTimer);
      window._cofTimer = setTimeout(reloadList, 300);
    });

    document.body.addEventListener('click', (e) => {
      const r = e.target.closest('.coffre-reveal');
      if (r) { e.preventDefault(); reveal(parseInt(r.dataset.id, 10), false); return; }
      const c = e.target.closest('.coffre-copy');
      if (c) { e.preventDefault(); reveal(parseInt(c.dataset.id, 10), true);  return; }
      const d = e.target.closest('.coffre-delete');
      if (d) { e.preventDefault(); deleteItem(parseInt(d.dataset.id, 10));    return; }
    });
  });
})();
</script>
