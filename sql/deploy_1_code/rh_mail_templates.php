<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_templates (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        titre VARCHAR(100) NOT NULL,
        categorie VARCHAR(50) NOT NULL,
        sujet VARCHAR(255) NOT NULL,
        corps LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_categorie (categorie)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$templates = $pdo->query("SELECT id, titre, categorie, sujet FROM mail_templates ORDER BY categorie, titre")->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($templates as $t) {
    $grouped[$t['categorie']][] = $t;
}

$categories = ['Bienvenue', 'Salaires', 'Congés', 'Alertes', 'Communication', 'Autre'];
$current_page = 'mails';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── Layout variables ── */
$layout_title   = 'Modèles de Mail';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '
<a href="rh_mails.php" class="ph-btn">
  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
  Envoyer un mail
</a>';

$layout_extra_css = <<<'CSS'
<style>
    /* ── Layout 3 colonnes ── */
    .main { flex:1; overflow:hidden; display:flex; flex-direction:column; }
    .tpl-layout {
      display: grid;
      grid-template-columns: 2fr 4fr 3fr;
      gap: 14px;
      padding: 10px 12px;
      flex: 1;
      overflow: hidden;
    }

    /* ── Colonne liste ── */
    .tpl-list-col {
      display: flex; flex-direction: column; gap: 10px;
      overflow-y: auto; padding: 6px 8px 6px 4px;
    }
    .tpl-list-col::-webkit-scrollbar { width: 4px; }
    .tpl-list-col::-webkit-scrollbar-thumb { background: var(--shadow-dark); border-radius: 3px; }

    .tpl-list-card {
      background: var(--bg-primary); border-radius: 16px;
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      overflow: hidden;
    }
    .tpl-list-header {
      padding: 12px 14px; border-bottom: 1px solid rgba(196,192,186,0.3);
    }
    .tpl-cat-title {
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.18em; color: #a8a49e; padding: 8px 14px 4px;
    }
    .tpl-item {
      padding: 8px 14px; cursor: pointer; border-bottom: 1px solid rgba(196,192,186,0.2);
      font-family: 'Sora', sans-serif; font-size: 12px; color: #6a6660;
      transition: background 0.12s;
      display: flex; align-items: center; gap: 8px;
    }
    .tpl-item:last-child { border-bottom: none; }
    .tpl-item:hover { background: rgba(255,255,255,0.5); color: #3a3632; }
    .tpl-item.active {
      background: rgba(54,87,125,0.08); color: #36577d; font-weight: 600;
      border-left: 3px solid #36577d;
    }
    .tpl-item-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--shadow-dark); flex-shrink:0; }
    .tpl-item.active .tpl-item-dot { background: #36577d; }
    .tpl-empty { padding: 16px 14px; font-family:'DM Mono',monospace; font-size:11px; color:#a8a49e; text-align:center; }

    /* ── Colonne formulaire ── */
    .tpl-form-col {
      display: flex; flex-direction: column; gap: 12px;
      overflow-y: auto; padding: 6px 6px;
    }
    .tpl-form-col::-webkit-scrollbar { width: 4px; }
    .tpl-form-col::-webkit-scrollbar-thumb { background: var(--shadow-dark); border-radius: 3px; }

    .tpl-card {
      background: var(--bg-primary); border-radius: 16px;
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      padding: 18px;
    }
    .tpl-card-title {
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.18em; color: #a8a49e; margin-bottom: 14px;
    }
    .form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
    .form-group:last-child { margin-bottom: 0; }
    .form-label { font-family:'DM Mono',monospace; font-size:10px; text-transform:uppercase; letter-spacing:0.10em; color:#8a8680; font-weight:500; }
    .form-control {
      background: var(--bg-primary); border: none; border-radius: 10px;
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      padding: 9px 14px; font-family:'Sora',sans-serif; font-size:13px; color:#1a1816;
      outline: none; width: 100%;
    }
    .form-control:focus { box-shadow: inset 4px 4px 9px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light), 0 0 0 2px rgba(54,87,125,0.2); }
    textarea.form-control { min-height: 180px; resize: vertical; line-height: 1.6; }
    select.form-control { cursor: pointer; }

    /* Boutons */
    .btn-primary { padding:9px 20px; border:none; border-radius:12px; background:#36577d; color:var(--bg-secondary); font-family:'Sora',sans-serif; font-size:12px; font-weight:600; cursor:pointer; box-shadow:3px 3px 8px rgba(54,87,125,0.35); }
    .btn-secondary { padding:9px 16px; border:none; border-radius:12px; background:var(--bg-primary); color:#6a6660; font-family:'Sora',sans-serif; font-size:12px; font-weight:600; cursor:pointer; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
    .btn-danger { padding:9px 16px; border:none; border-radius:12px; background:var(--bg-primary); color:#8a5040; font-family:'Sora',sans-serif; font-size:12px; font-weight:600; cursor:pointer; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); }
    .btn-group { display:flex; gap:8px; flex-wrap:wrap; }

    /* Nouveau btn pill dans liste */
    .btn-new-pill {
      display:flex; align-items:center; gap:6px; width:100%;
      padding:8px 14px; border:none; border-radius:999px; cursor:pointer;
      background:var(--bg-primary); box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light);
      font-family:'Sora',sans-serif; font-size:12px; font-weight:600; color:#3a7a6a;
    }
    .btn-new-pill:active { box-shadow:inset 3px 3px 7px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light); }

    /* ── Colonne IA ── */
    .tpl-ia-col {
      display: flex; flex-direction: column; gap: 12px;
      overflow-y: auto; padding: 6px 4px 6px 2px;
    }
    .tpl-ia-col::-webkit-scrollbar { width: 4px; }
    .tpl-ia-col::-webkit-scrollbar-thumb { background: var(--shadow-dark); border-radius: 3px; }

    .ia-card { background:var(--bg-primary); border-radius:16px; box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light); padding:16px; display:flex; flex-direction:column; gap:10px; }
    .ia-card-title { font-family:'DM Mono',monospace; font-size:9px; text-transform:uppercase; letter-spacing:0.18em; color:#a8a49e; }
    .ia-textarea {
      background:var(--bg-primary); border:none; border-radius:10px;
      box-shadow:inset 3px 3px 7px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light);
      padding:10px 12px; font-family:'Sora',sans-serif; font-size:12px; color:#1a1816;
      outline:none; resize:vertical; line-height:1.6; width:100%; min-height:80px;
    }
    .ia-generate-btn {
      padding:9px 16px; border:none; border-radius:12px;
      background:linear-gradient(135deg,#3a7a6a,#36577d);
      color:var(--bg-secondary); font-family:'Sora',sans-serif; font-size:12px; font-weight:700;
      cursor:pointer; display:flex; align-items:center; gap:7px; justify-content:center; width:100%;
    }
    .ia-generate-btn:disabled { opacity:0.55; cursor:not-allowed; }
    .ia-status { font-family:'DM Mono',monospace; font-size:11px; padding:7px 10px; border-radius:8px; }
    .ia-status.loading { background:rgba(54,87,125,0.08); color:#36577d; }
    .ia-status.ok { background:rgba(58,122,106,0.1); color:#3a7a6a; }
    .ia-status.err { background:rgba(138,80,64,0.1); color:#8a5040; }

    /* Toast */
    .toast { position:fixed; bottom:24px; right:24px; padding:12px 18px; border-radius:12px; font-family:'Sora',sans-serif; font-size:13px; font-weight:600; z-index:9999; box-shadow:4px 4px 14px rgba(26,24,22,0.2); animation:toastIn 0.2s ease; }
    .toast.ok { background:#e2ede6; color:#3a7a6a; }
    .toast.err { background:#faeaea; color:#8a5040; }
    @keyframes toastIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
</style>
CSS;

$layout_extra_js = <<<'JS'
<script>
function toast(msg, type = 'ok') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

function newTemplate() {
    document.getElementById('template-id').value = '';
    document.getElementById('titre').value = '';
    document.getElementById('categorie').value = '';
    document.getElementById('sujet').value = '';
    document.getElementById('corps').value = '';
    document.getElementById('delete-btn').style.display = 'none';
    document.getElementById('form-mode-title').textContent = 'Nouveau modèle';
    document.querySelectorAll('.tpl-item').forEach(el => el.classList.remove('active'));
}

function loadTemplate(id) {
    fetch('api/get_mail_template.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return toast('Erreur chargement', 'err');
            const t = data.template;
            document.getElementById('template-id').value = t.id;
            document.getElementById('titre').value = t.titre;
            document.getElementById('categorie').value = t.categorie;
            document.getElementById('sujet').value = t.sujet;
            document.getElementById('corps').value = t.corps;
            document.getElementById('delete-btn').style.display = '';
            document.getElementById('form-mode-title').textContent = 'Modifier — ' + t.titre;
            document.querySelectorAll('.tpl-item').forEach(el => el.classList.remove('active'));
            document.getElementById('tpl-item-' + id)?.classList.add('active');
        });
}

function saveTemplate() {
    const id        = document.getElementById('template-id').value || null;
    const titre     = document.getElementById('titre').value.trim();
    const categorie = document.getElementById('categorie').value;
    const sujet     = document.getElementById('sujet').value.trim();
    const corps     = document.getElementById('corps').value.trim();

    if (!titre || !categorie || !sujet || !corps) return toast('Tous les champs sont requis', 'err');

    fetch('api/save_mail_template.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id, titre, categorie, sujet, corps})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { toast('✓ Modèle enregistré'); setTimeout(() => location.reload(), 1200); }
        else toast('Erreur : ' + (data.message || 'Impossible d\'enregistrer'), 'err');
    })
    .catch(err => toast('Erreur réseau : ' + err.message, 'err'));
}

function deleteTemplate() {
    const id = document.getElementById('template-id').value;
    if (!id) return toast('Aucun modèle sélectionné', 'err');
    if (!confirm('Supprimer ce modèle ?')) return;

    fetch('api/delete_mail_template.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { toast('✓ Modèle supprimé'); setTimeout(() => location.reload(), 1200); }
        else toast('Erreur : ' + (data.message || 'Impossible de supprimer'), 'err');
    });
}

// ── IA ───────────────────────────────────────────────────────────────────────
function callIA(prompt, btnId, statusId, onSuccess) {
    const btn    = document.getElementById(btnId);
    const status = document.getElementById(statusId);
    btn.disabled = true;
    status.style.display = 'block';
    status.className = 'ia-status loading';
    status.textContent = '⏳ Génération en cours…';

    const isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    fetch(isLocal ? 'api/chatgpt_draft.php?debug=1' : 'api/chatgpt_draft.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({prompt})
    })
    .then(r => { if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t); }); return r.json(); })
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            onSuccess(data);
            status.className = 'ia-status ok';
            status.textContent = '✓ Contenu généré';
            setTimeout(() => { status.style.display = 'none'; }, 3000);
        } else {
            status.className = 'ia-status err';
            status.textContent = '❌ ' + (data.message || 'Erreur');
        }
    })
    .catch(err => { btn.disabled = false; status.className = 'ia-status err'; status.textContent = '❌ ' + err.message; });
}

function generateWithAI() {
    const prompt = document.getElementById('ia-prompt').value.trim();
    if (!prompt) return toast('Décrivez le modèle à générer', 'err');
    callIA(prompt, 'ia-generate-btn', 'ia-status', data => {
        document.getElementById('sujet').value = data.sujet || '';
        document.getElementById('corps').value = data.corps || '';
    });
}

function refineWithAI() {
    const instructions = document.getElementById('ia-refine-prompt').value.trim();
    if (!instructions) return toast('Décrivez les modifications souhaitées', 'err');
    const currentSubject = document.getElementById('sujet').value;
    const currentBody    = document.getElementById('corps').value;
    if (!currentSubject && !currentBody) return toast('Aucun contenu à modifier', 'err');

    const prompt = `Modifie ce mail en JSON avec les clés "sujet" et "corps".\nsujet: ${currentSubject}\ncorps: ${currentBody}\nInstructions : ${instructions}`;
    callIA(prompt, 'ia-refine-btn', 'ia-refine-status', data => {
        document.getElementById('sujet').value = data.sujet || currentSubject;
        document.getElementById('corps').value = data.corps || currentBody;
        document.getElementById('ia-refine-prompt').value = '';
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') newTemplate(); });
</script>
JS;

/* ── Page content ── */
ob_start();
?>
  <main class="main">
    <div class="tpl-layout">

      <!-- LISTE -->
      <div class="tpl-list-col">
        <div class="tpl-list-card">
          <div class="tpl-list-header">
            <button class="btn-new-pill" onclick="newTemplate()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Nouveau modèle
            </button>
          </div>
          <?php if (empty($grouped)): ?>
            <div class="tpl-empty">Aucun modèle</div>
          <?php else: ?>
            <?php foreach ($grouped as $cat => $items): ?>
            <div class="tpl-cat-title"><?= h($cat) ?></div>
            <?php foreach ($items as $t): ?>
            <div class="tpl-item" id="tpl-item-<?= $t['id'] ?>" onclick="loadTemplate(<?= $t['id'] ?>)">
              <span class="tpl-item-dot"></span>
              <?= h($t['titre']) ?>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- FORMULAIRE -->
      <div class="tpl-form-col">
        <div class="tpl-card">
          <div class="tpl-card-title" id="form-mode-title">Nouveau modèle</div>
          <input type="hidden" id="template-id">

          <div class="form-group">
            <label class="form-label">Titre</label>
            <input type="text" id="titre" class="form-control" placeholder="Ex : Accueil nouveau membre">
          </div>
          <div class="form-group">
            <label class="form-label">Catégorie</label>
            <select id="categorie" class="form-control">
              <option value="">Choisir une catégorie</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Objet du mail</label>
            <input type="text" id="sujet" class="form-control" placeholder="Objet du mail…">
          </div>
          <div class="form-group">
            <label class="form-label">Corps du mail</label>
            <textarea id="corps" class="form-control" placeholder="Contenu du mail…"></textarea>
          </div>

          <div class="btn-group" style="margin-top:6px;">
            <button class="btn-secondary" onclick="newTemplate()">Nouveau</button>
            <button class="btn-primary" onclick="saveTemplate()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-1px;margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Enregistrer
            </button>
            <button class="btn-danger" id="delete-btn" onclick="deleteTemplate()" style="display:none;">Supprimer</button>
          </div>
        </div>
      </div>

      <!-- IA -->
      <div class="tpl-ia-col">
        <div class="ia-card">
          <div class="ia-card-title">✨ Générer avec l'IA</div>
          <label style="font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.13em;color:#a8a49e;">Décrivez le modèle à créer</label>
          <textarea class="ia-textarea" id="ia-prompt" placeholder="Ex : Un mail de bienvenue pour un nouvel employé, mentionner l'équipe et la date de début…"></textarea>
          <button class="ia-generate-btn" id="ia-generate-btn" onclick="generateWithAI()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Générer
          </button>
          <div id="ia-status" style="display:none;" class="ia-status"></div>
        </div>

        <div class="ia-card">
          <div class="ia-card-title">✏️ Modifier / Affiner</div>
          <label style="font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.13em;color:#a8a49e;">Instructions de modification</label>
          <textarea class="ia-textarea" id="ia-refine-prompt" placeholder="Ex : Rends le ton plus formel, ajoute une mention de délai…"></textarea>
          <button class="ia-generate-btn" id="ia-refine-btn" onclick="refineWithAI()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Modifier avec l'IA
          </button>
          <div id="ia-refine-status" style="display:none;" class="ia-status"></div>
        </div>
      </div>

    </div>
  </main>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
