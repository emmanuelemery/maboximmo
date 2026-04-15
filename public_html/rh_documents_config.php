<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_doc_types.php';

require_login();

// Admin uniquement
if (current_role_id() !== 1) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur DB'); }

function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rubriquesMeta  = rh_doc_rubriques_meta();
$typesByRubrique = rh_doc_types_load($pdo);
$dispoLabels     = ['public' => 'Public', 'manager' => 'Manager', 'admin' => 'Admin'];

$csrfToken    = csrf_token();
$current_page = 'documents';

/* ── Layout variables ── */
$layout_title   = 'Documents — Gestion des listes';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ── Main ── */
    .main { flex:1; overflow-y:auto; padding:0 36px 60px; display:flex; flex-direction:column; gap:20px; }

    /* ── Grille rubriques ── */
    .rubriques-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:20px; }

    /* ── Card rubrique ── */
    .rub-card { background:var(--bg-primary); border-radius:18px; box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light); overflow:hidden; display:flex; flex-direction:column; }
    .rub-card-head { display:flex; align-items:center; gap:10px; padding:14px 18px 12px; border-bottom:1px solid rgba(196,192,186,0.3); }
    .rub-card-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
    .rub-card-title { font-size:13px; font-weight:700; color:#1a1816; flex:1; }
    .rub-card-count { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; }
    .rub-card-body { padding:12px 16px; flex:1; display:flex; flex-direction:column; gap:8px; }
    .rub-card-foot { padding:10px 16px 14px; display:flex; justify-content:flex-end; }

    /* ── Ligne type de document ── */
    .type-row { display:flex; align-items:center; gap:10px; padding:9px 12px; background:var(--bg-primary); border-radius:12px; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); }
    .type-row-label { flex:1; min-width:0; }
    .type-name { font-size:12px; font-weight:600; color:#1a1816; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .type-name-input { font-size:12px; font-weight:600; color:#1a1816; background:transparent; border:none; border-bottom:1px solid rgba(74,96,56,0.4); outline:none; width:100%; font-family:'Sora',sans-serif; padding:1px 0; }
    .type-name-input:focus { border-bottom-color:#4a6038; }
    .type-badges { display:flex; align-items:center; gap:6px; margin-top:3px; flex-wrap:wrap; }
    .type-actions { display:flex; align-items:center; gap:5px; flex-shrink:0; }

    /* Toggle obligatoire */
    .tog-btn { height:22px; padding:0 8px; border-radius:999px; border:none; cursor:pointer; font-family:'Sora',sans-serif; font-size:9px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; transition:all 0.15s; white-space:nowrap; }
    .tog-btn.oblig-on  { background:rgba(138,80,64,0.15); color:#8a5040; box-shadow:inset 2px 2px 4px rgba(138,80,64,0.15); }
    .tog-btn.oblig-off { background:var(--bg-primary); color:#a8a49e; box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); }
    /* Toggle dispo 3 états */
    .dispo-btn { height:22px; padding:0 9px; border-radius:999px; border:none; cursor:pointer; font-family:'Sora',sans-serif; font-size:9px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; transition:all 0.15s; white-space:nowrap; }
    .dispo-btn.dispo-public   { background:rgba(74,96,56,0.12);  color:#4a6038; box-shadow:inset 2px 2px 4px rgba(74,96,56,0.12); }
    .dispo-btn.dispo-manager  { background:rgba(122,104,48,0.15); color:#7a6830; box-shadow:inset 2px 2px 4px rgba(122,104,48,0.15); }
    .dispo-btn.dispo-admin    { background:rgba(54,87,125,0.15); color:#36577d; box-shadow:inset 2px 2px 4px rgba(54,87,125,0.15); }

    /* Icône delete */
    .doc-icon-btn { width:26px; height:26px; border-radius:7px; background:var(--bg-primary); box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; }
    .doc-icon-btn:active { box-shadow:inset 2px 2px 4px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); }
    .doc-icon-btn.del svg { stroke:#8a5040; }
    .doc-icon-btn svg { width:12px; height:12px; stroke:#8a8680; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }

    /* Badge système */
    .badge-sys { font-size:8px; padding:1px 5px; border-radius:999px; background:rgba(138,134,128,0.15); color:#a8a49e; font-weight:600; white-space:nowrap; }

    /* ── Bouton ajouter ── */
    .btn-add-type { display:flex; align-items:center; gap:6px; padding:7px 14px; background:var(--bg-primary); border:none; border-radius:10px; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); font-family:'Sora',sans-serif; font-size:11px; font-weight:600; color:#4a6038; cursor:pointer; }
    .btn-add-type:active { box-shadow:inset 2px 2px 6px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); }
    .btn-add-type svg { width:13px; height:13px; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; }

    /* ── Formulaire inline ajout ── */
    .add-form { display:none; flex-direction:column; gap:8px; padding:10px 12px; background:rgba(74,96,56,0.04); border-radius:12px; border:1px dashed rgba(74,96,56,0.25); }
    .add-form.visible { display:flex; }
    .add-form-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .add-input { flex:1; min-width:120px; background:var(--bg-primary); border:none; border-radius:8px; box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); padding:6px 12px; font-family:'Sora',sans-serif; font-size:12px; color:#1a1816; outline:none; }
    .add-input:focus { box-shadow:inset 3px 3px 7px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light),0 0 0 2px rgba(74,96,56,0.2); }
    .add-check { display:flex; align-items:center; gap:5px; font-size:11px; color:#6a6660; font-weight:500; cursor:pointer; white-space:nowrap; }
    .add-check input[type=checkbox] { width:14px; height:14px; accent-color:#4a6038; cursor:pointer; }
    .add-form-btns { display:flex; gap:8px; justify-content:flex-end; }
    .btn-save-type { padding:6px 16px; background:#4a6038; color:var(--bg-secondary); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:11px; font-weight:600; cursor:pointer; box-shadow:2px 2px 6px rgba(74,96,56,0.3); }
    .btn-cancel-type { padding:6px 12px; background:var(--bg-primary); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:11px; color:#8a8680; cursor:pointer; box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); }

    /* ── Toast ── */
    #cfg-toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
    .toast-item { background:var(--bg-primary); border-radius:12px; box-shadow:6px 6px 16px rgba(26,24,22,0.18); padding:12px 18px; font-size:12px; font-weight:600; border-left:4px solid #4a6038; color:#1a1816; animation:toastIn 0.25s ease; }
    .toast-item.err { border-left-color:#8a5040; color:#8a5040; }
    @keyframes toastIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Basculer Obligatoire ──────────────────────────────────────────────────────
async function toggleOblig(id, btn) {
    const r = await apiCall({ action: 'toggle_obligatoire', id });
    if (r.ok) {
        const on = r.obligatoire === 1;
        btn.className = 'tog-btn ' + (on ? 'oblig-on' : 'oblig-off');
        btn.textContent = on ? 'Obligatoire' : 'Facultatif';
        showToast(on ? 'Marqué obligatoire' : 'Marqué facultatif');
    } else showToast(r.error || 'Erreur', true);
}

// ── Cycler la disponibilité public → manager → admin → public ─────────────────
const DISPO_CYCLE  = ['public', 'manager', 'admin'];
const DISPO_LABELS = { public: 'Public', manager: 'Manager', admin: 'Admin' };

async function cycleDispo(id, btn) {
    const cur  = btn.dataset.dispo || 'public';
    const next = DISPO_CYCLE[(DISPO_CYCLE.indexOf(cur) + 1) % 3];
    const r = await apiCall({ action: 'set_dispo', id, dispo: next });
    if (r.ok) {
        btn.dataset.dispo = next;
        btn.className = 'dispo-btn dispo-' + next;
        btn.textContent = DISPO_LABELS[next];
        showToast('Disponibilité : ' + DISPO_LABELS[next]);
    } else showToast(r.error || 'Erreur', true);
}

// ── Supprimer un type ─────────────────────────────────────────────────────────
async function deleteType(id, btn) {
    if (!confirm('Supprimer ce type de document ? Les documents déjà chargés ne seront pas supprimés.')) return;
    const r = await apiCall({ action: 'delete', id });
    if (r.ok) {
        const row = btn.closest('.type-row');
        if (row) row.remove();
        updateCount(btn.closest('.rub-card'));
        showToast('Type supprimé');
    } else showToast(r.error || 'Erreur suppression', true);
}

// ── Afficher / masquer formulaire ajout ───────────────────────────────────────
function showAdd(rub) {
    document.getElementById('add-form-' + rub).classList.add('visible');
    document.getElementById('add-btn-' + rub).style.display = 'none';
    document.getElementById('add-label-' + rub).focus();
}
function cancelAdd(rub) {
    document.getElementById('add-form-' + rub).classList.remove('visible');
    document.getElementById('add-btn-' + rub).style.display = '';
    document.getElementById('add-label-' + rub).value = '';
    document.getElementById('add-oblig-' + rub).checked = false;
    document.getElementById('add-dispo-' + rub).value   = 'public';
}

// ── Enregistrer un nouveau type ───────────────────────────────────────────────
async function saveNew(rub) {
    const label       = document.getElementById('add-label-' + rub).value.trim();
    const obligatoire = document.getElementById('add-oblig-' + rub).checked;
    const dispo       = document.getElementById('add-dispo-' + rub).value;
    if (!label) { showToast('Saisir un nom de document', true); return; }

    const r = await apiCall({ action: 'save', rubrique: rub, label, obligatoire, dispo });
    if (r.ok) {
        // Injecter la nouvelle ligne avant le formulaire d'ajout
        const form = document.getElementById('add-form-' + rub);
        const newRow = buildTypeRow(r.id, rub, label, obligatoire, dispo, false);
        form.before(newRow);
        cancelAdd(rub);
        updateCount(form.closest('.rub-card'));
        showToast('Type ajouté');
    } else showToast(r.error || 'Erreur', true);
}

// ── Construire une ligne type en JS ──────────────────────────────────────────
function buildTypeRow(id, rub, label, obligatoire, dispo, systeme) {
    const row = document.createElement('div');
    row.className = 'type-row';
    row.dataset.id = id;
    row.dataset.rubrique = rub;
    row.innerHTML = `
        <div class="type-row-label">
            <div class="type-name" title="${esc(label)}">${esc(label)}</div>
        </div>
        <div class="type-actions">
            <button class="tog-btn ${obligatoire ? 'oblig-on' : 'oblig-off'}"
                    onclick="toggleOblig(${id}, this)">
                ${obligatoire ? 'Obligatoire' : 'Facultatif'}
            </button>
            <button class="dispo-btn dispo-${dispo}" data-dispo="${dispo}"
                    onclick="cycleDispo(${id}, this)">
                ${DISPO_LABELS[dispo] || 'Public'}
            </button>
            <button class="doc-icon-btn del" title="Supprimer" onclick="deleteType(${id}, this)">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
        </div>`;
    return row;
}

function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Mettre à jour le compteur ─────────────────────────────────────────────────
function updateCount(card) {
    if (!card) return;
    const nb = card.querySelectorAll('.type-row').length;
    const cnt = card.querySelector('.rub-card-count');
    if (cnt) cnt.textContent = nb + ' type' + (nb !== 1 ? 's' : '');
}

// ── API helper ────────────────────────────────────────────────────────────────
async function apiCall(body) {
    try {
        const r = await fetch('api/rh_doc_type_save.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
            },
            body: JSON.stringify(body),
        });
        return await r.json();
    } catch { return { ok: false, error: 'Erreur réseau' }; }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, isErr = false) {
    const el = document.createElement('div');
    el.className = 'toast-item' + (isErr ? ' err' : '');
    el.textContent = msg;
    document.getElementById('cfg-toast').appendChild(el);
    setTimeout(() => el.remove(), 3200);
}

// Entrée clavier dans le champ label
document.querySelectorAll('.add-input').forEach(inp => {
    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            const rub = inp.id.replace('add-label-', '');
            saveNew(rub);
        }
        if (e.key === 'Escape') {
            const rub = inp.id.replace('add-label-', '');
            cancelAdd(rub);
        }
    });
});
</script>
EXTRAJS;

/* ── Page content ── */
ob_start();
?>
    <!-- Grille rubriques -->
    <div class="rubriques-grid">
    <?php foreach ($rubriquesMeta as $rubKey => $rub):
        $types  = $typesByRubrique[$rubKey] ?? [];
        $nbTypes = count($types);
    ?>
    <div class="rub-card" data-rubrique="<?= h($rubKey) ?>">
      <div class="rub-card-head">
        <div class="rub-card-dot" style="background:<?= h($rub['color']) ?>"></div>
        <div class="rub-card-title"><?= h($rub['label']) ?></div>
        <div class="rub-card-count"><?= $nbTypes ?> type<?= $nbTypes !== 1 ? 's' : '' ?></div>
      </div>
      <div class="rub-card-body" id="body-<?= h($rubKey) ?>">
        <?php if (empty($types)): ?>
          <div style="font-size:11px;color:#a8a49e;font-style:italic;padding:4px 0">Aucun type défini</div>
        <?php endif; ?>
        <?php foreach ($types as $t): ?>
        <div class="type-row" data-id="<?= (int)$t['id'] ?>" data-rubrique="<?= h($rubKey) ?>">
          <div class="type-row-label">
            <div class="type-name" title="<?= h($t['label']) ?>"><?= h($t['label']) ?></div>
            <?php if (!empty($t['systeme'])): ?>
            <div style="margin-top:3px"><span class="badge-sys">Système</span></div>
            <?php endif; ?>
          </div>
          <div class="type-actions">
            <button class="tog-btn <?= (int)$t['obligatoire'] ? 'oblig-on' : 'oblig-off' ?>"
                    onclick="toggleOblig(<?= (int)$t['id'] ?>, this)"
                    title="Cliquer pour basculer obligatoire / facultatif">
              <?= (int)$t['obligatoire'] ? 'Obligatoire' : 'Facultatif' ?>
            </button>
            <?php $dispo = $t['dispo'] ?? 'public'; ?>
            <button class="dispo-btn dispo-<?= h($dispo) ?>"
                    data-dispo="<?= h($dispo) ?>"
                    onclick="cycleDispo(<?= (int)$t['id'] ?>, this)"
                    title="Disponibilité : Public / Manager / Admin — cliquer pour changer">
              <?= h($dispoLabels[$dispo] ?? 'Public') ?>
            </button>
            <?php if (empty($t['systeme'])): ?>
            <button class="doc-icon-btn del" title="Supprimer" onclick="deleteType(<?= (int)$t['id'] ?>, this)">
              <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
            <?php else: ?>
            <div style="width:26px"></div><!-- placeholder pour aligner -->
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Formulaire ajout inline -->
        <div class="add-form" id="add-form-<?= h($rubKey) ?>">
          <div class="add-form-row">
            <input type="text" class="add-input" id="add-label-<?= h($rubKey) ?>" placeholder="Nom du document…" maxlength="200">
          </div>
          <div class="add-form-row">
            <label class="add-check">
              <input type="checkbox" id="add-oblig-<?= h($rubKey) ?>"> Obligatoire
            </label>
            <label class="add-check" style="gap:6px">
              Disponibilité :
              <select id="add-dispo-<?= h($rubKey) ?>" style="height:22px;border:none;border-radius:8px;background:var(--bg-primary);box-shadow:inset 2px 2px 4px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light);font-family:'Sora',sans-serif;font-size:10px;color:#1a1816;padding:0 8px;cursor:pointer;outline:none;">
                <option value="public">Public</option>
                <option value="manager">Manager</option>
                <option value="admin">Admin</option>
              </select>
            </label>
          </div>
          <div class="add-form-btns">
            <button class="btn-cancel-type" onclick="cancelAdd('<?= h($rubKey) ?>')">Annuler</button>
            <button class="btn-save-type" onclick="saveNew('<?= h($rubKey) ?>')">Ajouter</button>
          </div>
        </div>
      </div>
      <div class="rub-card-foot">
        <button class="btn-add-type" id="add-btn-<?= h($rubKey) ?>" onclick="showAdd('<?= h($rubKey) ?>')">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Ajouter un type
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div id="cfg-toast"></div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
