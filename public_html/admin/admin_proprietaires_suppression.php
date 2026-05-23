<?php
/**
 * admin/admin_proprietaires_suppression.php
 *
 * Outil super-admin de suppression de propriétaires.
 *
 * Modes :
 *  - Par IDs (saisis manuellement)
 *  - Tous ceux SANS bien lié (créés depuis date X)
 *
 * Workflow : Prévisualiser → puis Supprimer (token renvoyé par preview).
 * Force=1 : supprime aussi les biens en cascade (TRÈS destructif, désactivé par défaut).
 *
 * Endpoint backend : api/proprietaire_delete_cascade.php (déjà existant).
 *
 * Déplacé depuis la modal de agency_proprietaires.php vers une page dédiée
 * (demande user 2026-05-23) — l'opération est trop sensible pour la liste
 * standard et doit vivre dans le dashboard super admin.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

$pageTitle    = 'Suppression propriétaires';
$pageSubtitle = 'Super admin — outil de nettoyage destructif';

ob_start();
?>
<style>
.psup-wrap   { max-width:1000px; margin:0 auto; padding:20px; }
.psup-header { background:linear-gradient(135deg,#fef2f2,#fee2e2); border-left:4px solid #b91c1c; padding:18px 22px; border-radius:10px; margin-bottom:20px; }
.psup-header h1 { margin:0 0 6px; color:#7f1d1d; font-size:22px; }
.psup-header p  { margin:0; color:#9a3412; font-size:13px; }

.psup-card { background:#fff; border-radius:12px; padding:20px 22px; margin-bottom:18px;
    box-shadow:4px 4px 12px #e3dfd8; }
.psup-card h3 { margin:0 0 14px; color:#2c2a28; font-size:15px; }

.psup-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.psup-row label { font-size:12px; color:#64748b; font-weight:600; display:block; margin-bottom:4px; }
.psup-row select, .psup-row input { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit; font-size:13px; }

.psup-actions { display:flex; gap:10px; align-items:center; margin:16px 0; flex-wrap:wrap; }
.psup-btn { padding:9px 16px; border-radius:6px; font-weight:700; cursor:pointer; border:none; font-family:inherit; font-size:13px; }
.psup-btn.preview { background:#0ea5e9; color:#fff; }
.psup-btn.delete  { background:#94a3b8; color:#fff; cursor:not-allowed; }
.psup-btn.delete.ready { background:#dc2626; cursor:pointer; }
.psup-btn.preview:hover { background:#0284c7; }
.psup-btn.delete.ready:hover { background:#b91c1c; }
.psup-back { background:#fff; color:#475569; border:1px solid #cbd5e1; padding:9px 16px; border-radius:6px; text-decoration:none; }

.psup-force-label { display:flex; align-items:center; gap:6px; font-size:13px; color:#475569; background:#fef3c7; padding:6px 10px; border-radius:6px; border:1px solid #fde68a; }

.psup-result { background:#0f172a; color:#f1f5f9; padding:14px 18px; border-radius:8px; font-family:'DM Mono',monospace; font-size:12px; max-height:500px; overflow-y:auto; white-space:pre-wrap; }
</style>

<div class="psup-wrap">

  <div class="psup-header">
    <h1>🗑 Suppression de propriétaires</h1>
    <p>
      Outil de nettoyage <strong>destructif</strong>. Filtre les propriétaires candidats, prévisualise leurs dépendances (biens, baux, documents…), puis supprime.
      <br>Mode par défaut : suppression simple (échoue si dépendances). Activer <strong>force=1</strong> pour cascade biens (TRÈS destructif).
    </p>
  </div>

  <div class="psup-card">
    <h3>1️⃣ Filtre de sélection</h3>

    <div class="psup-row">
      <div>
        <label>Mode de sélection</label>
        <select id="psup-mode">
          <option value="no_biens">Tous ceux SANS bien lié (créés depuis date X)</option>
          <option value="ids">Par IDs (saisis ci-dessous)</option>
        </select>
      </div>
      <div id="psup-date-wrap">
        <label>Date min — propriétaires créés depuis</label>
        <input type="date" id="psup-date" value="<?= date('Y-m-d', strtotime('-365 days')) ?>">
      </div>
    </div>

    <div id="psup-ids-wrap" style="display:none;">
      <label style="font-size:12px; color:#64748b; font-weight:600; display:block; margin-bottom:4px;">IDs propriétaires (séparés par virgule)</label>
      <input type="text" id="psup-ids" placeholder="ex: 12, 34, 56" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:'DM Mono',monospace; font-size:13px;">
    </div>
  </div>

  <div class="psup-card">
    <h3>2️⃣ Actions</h3>
    <div class="psup-actions">
      <button type="button" class="psup-btn preview" id="psup-preview-btn">🔍 Prévisualiser</button>
      <button type="button" class="psup-btn delete"  id="psup-delete-btn" disabled>🗑 Supprimer maintenant</button>
      <label class="psup-force-label">
        <input type="checkbox" id="psup-force"> force=1 (supprimer biens en cascade)
      </label>
      <a class="psup-back" href="<?= htmlspecialchars(app_url('/super_admin_dashboard.php')) ?>">← Retour dashboard</a>
    </div>
  </div>

  <div class="psup-card">
    <h3>3️⃣ Résultat</h3>
    <div class="psup-result" id="psup-result">Cliquez sur 🔍 Prévisualiser pour voir les propriétaires candidats.</div>
  </div>

</div>

<script>
(function(){
  const csrf = '<?= csrf_token('proprietaire_delete_cascade') ?>';
  const endpoint = '<?= htmlspecialchars(app_url('/api/proprietaire_delete_cascade.php')) ?>';
  let lastToken = null;

  const btnPrev   = document.getElementById('psup-preview-btn');
  const btnDel    = document.getElementById('psup-delete-btn');
  const result    = document.getElementById('psup-result');
  const inputIds  = document.getElementById('psup-ids');
  const inputDate = document.getElementById('psup-date');
  const selectMode= document.getElementById('psup-mode');
  const wrapIds   = document.getElementById('psup-ids-wrap');
  const wrapDate  = document.getElementById('psup-date-wrap');
  const cbForce   = document.getElementById('psup-force');

  selectMode.addEventListener('change', () => {
    wrapIds.style.display  = selectMode.value === 'ids' ? 'block' : 'none';
    wrapDate.style.display = selectMode.value === 'no_biens' ? 'block' : 'none';
  });

  function buildFormData(action) {
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', action);
    if (selectMode.value === 'ids') {
      fd.append('ids', inputIds.value || '');
    } else {
      fd.append('filter_mode', 'no_biens');
      fd.append('date_min', inputDate.value || '');
    }
    if (action === 'delete' && lastToken) fd.append('token', lastToken);
    if (action === 'delete' && cbForce.checked) fd.append('force', '1');
    return fd;
  }

  btnPrev.addEventListener('click', async () => {
    btnPrev.disabled = true; btnPrev.textContent = '⏳ Préview…';
    btnDel.disabled  = true; btnDel.classList.remove('ready');
    result.textContent = 'Chargement…';
    try {
      const r = await fetch(endpoint, { method:'POST', body: buildFormData('preview') });
      const j = await r.json();
      result.textContent = JSON.stringify(j, null, 2);
      if (j.ok && j.count > 0) {
        lastToken = j.token;
        btnDel.disabled = false; btnDel.classList.add('ready');
        const deps = j.count_by_table || {};
        const hasBiens = (deps.biens || 0) > 0;
        const orphanDeps = Object.keys(deps).filter(k => k !== 'biens');

        // Cas 1 : des BIENS sont rattachés → DANGER, jamais d'auto-cochage
        if (hasBiens) {
          cbForce.checked = false;
          const warn = document.createElement('div');
          warn.style.cssText = 'background:#fee2e2;color:#7f1d1d;padding:12px 14px;border-radius:8px;margin-top:8px;font-size:13px;border-left:4px solid #b91c1c;';
          warn.innerHTML = '🚨 <strong>' + deps.biens + ' bien(s) seront supprimés en cascade</strong> si tu coches force=1. Vérifie que ces biens ne doivent pas être réaffectés à un autre propriétaire d\'abord. <strong>Cochage manuel obligatoire.</strong>';
          result.parentElement.insertBefore(warn, result);
          setTimeout(() => warn.remove(), 15000);
        }
        // Cas 2 : seulement des orphelins (CRG, baux, mandats historiques) → auto-cochage OK
        else if (orphanDeps.length > 0 && !cbForce.checked) {
          cbForce.checked = true;
          const note = document.createElement('div');
          note.style.cssText = 'background:#fef3c7;color:#92400e;padding:8px 12px;border-radius:6px;margin-top:8px;font-size:12px;';
          note.innerHTML = '⚠️ Orphelins détectés (' + orphanDeps.join(', ') + ') — <strong>force=1 coché automatiquement</strong> (nettoyage de données historiques, aucun bien touché).';
          result.parentElement.insertBefore(note, result);
          setTimeout(() => note.remove(), 6000);
        }
      }
    } catch (e) {
      result.textContent = '❌ ' + e.message;
    }
    btnPrev.disabled = false; btnPrev.textContent = '🔍 Prévisualiser';
  });

  btnDel.addEventListener('click', async () => {
    if (!confirm('⚠️ Suppression DÉFINITIVE — Confirmer ?')) return;
    btnDel.disabled = true; btnDel.textContent = '⏳ Suppression…';
    try {
      const r = await fetch(endpoint, { method:'POST', body: buildFormData('delete') });
      const j = await r.json();
      result.textContent = JSON.stringify(j, null, 2);
      if (j.ok) {
        btnDel.textContent = '✅ Supprimé';
        lastToken = null;
      } else {
        btnDel.disabled = false; btnDel.textContent = '🗑 Supprimer maintenant';
      }
    } catch (e) {
      result.textContent = '❌ ' + e.message;
      btnDel.disabled = false; btnDel.textContent = '🗑 Supprimer maintenant';
    }
  });
})();
</script>

<?php
$layout_content = ob_get_clean();
require_once dirname(__DIR__) . '/inc/layout_maboximmo.php';
?>
