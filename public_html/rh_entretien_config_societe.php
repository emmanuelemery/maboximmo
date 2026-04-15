<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_entretien_catalog.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs et managers.');
}

$societeId = current_societe_id();
if (!$societeId) {
    http_response_code(400);
    exit('Aucune société associée à votre compte.');
}

$tab = $_GET['tab'] ?? 'config';

// Charger le nom de la société
$societeName = '';
try {
    $stmtSoc = $pdo->prepare("SELECT nom FROM societes WHERE id = ? LIMIT 1");
    $stmtSoc->execute([$societeId]);
    $societeName = (string)($stmtSoc->fetchColumn() ?: '');
} catch (PDOException $e) { /* ignoré */ }

// Charger toutes les rubriques (vue globale + config société)
$allRubriques = [];
try {
    $stmtAllRub = $pdo->prepare(
        "SELECT r.*,
                COALESCE(sr.actif, 1) AS soc_actif,
                sr.ordre AS soc_ordre
         FROM rh_entretien_rubriques r
         LEFT JOIN rh_entretien_societe_rubriques sr ON sr.rubrique_id = r.id AND sr.societe_id = ?
         WHERE r.actif = 1 AND (r.societe_id IS NULL OR r.societe_id = ?)
         ORDER BY r.ordre"
    );
    $stmtAllRub->execute([$societeId, $societeId]);
    $allRubriques = $stmtAllRub->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// Charger toutes les questions avec config société
$allQuestions = [];
try {
    $stmtAllQ = $pdo->prepare(
        "SELECT q.*,
                r.nom AS rubrique_nom,
                COALESCE(sq.actif, 1) AS soc_actif,
                sq.ordre AS soc_ordre
         FROM rh_entretien_questions_user q
         JOIN rh_entretien_rubriques r ON r.id = q.rubrique_id
         LEFT JOIN rh_entretien_societe_questions sq ON sq.question_id = q.id AND sq.societe_id = ?
         WHERE q.actif = 1 AND (q.societe_id IS NULL OR q.societe_id = ?)
         ORDER BY r.ordre, q.ordre"
    );
    $stmtAllQ->execute([$societeId, $societeId]);
    foreach ($stmtAllQ->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $allQuestions[(int)$q['rubrique_id']][] = $q;
    }
} catch (PDOException $e) { /* ignoré */ }

// Charger toutes les options avec config société
$allOptions = [];
try {
    $stmtAllO = $pdo->prepare(
        "SELECT o.*,
                COALESCE(so.actif, 1) AS soc_actif
         FROM rh_entretien_question_options o
         LEFT JOIN rh_entretien_societe_options so ON so.option_id = o.id AND so.societe_id = ?
         WHERE o.actif = 1 AND (o.societe_id IS NULL OR o.societe_id = ?)
         ORDER BY o.question_id, o.ordre"
    );
    $stmtAllO->execute([$societeId, $societeId]);
    foreach ($stmtAllO->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $allOptions[(int)$o['question_id']][] = $o;
    }
} catch (PDOException $e) { /* ignoré */ }

// Charger les suggestions
$suggestions = [];
try {
    $suggestions = catalog_get_suggestions($pdo, $societeId);
} catch (PDOException $e) { /* ignoré */ }

$csrfToken = csrf_token();

// ============================================================
// LAYOUT VARIABLES
// ============================================================
$layout_title       = 'Configuration Entretiens — ' . $societeName;
$layout_module      = 'Ma Box RH';
$layout_sidebar     = 'rh_sidebar';
$layout_head_kpis   = '';
$layout_head_actions = '<a href="rh_entretien_liste.php" class="ph-btn ph-btn-outline" style="font-size:13px">&#8592; Retour liste</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
        .mbi-sidebar{position:fixed;left:0;top:0;width:250px;height:100vh;background:var(--sidebar);border-right:1px solid var(--stroke);overflow-y:auto;padding:12px 0}
        .mbi-sidebar-head{padding:10px 12px;border-bottom:1px solid var(--stroke);margin-bottom:12px}
        .mbi-sidebar-brand strong{font-size:14px;display:block}
        .mbi-sidebar-brand span{font-size:11px;color:var(--muted)}
        .mbi-sidebar-section{padding:12px;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--ink);margin:16px 8px 10px;background:rgba(72,120,166,0.06);border-left:3px solid rgba(72,120,166,0.2);border-radius:4px;letter-spacing:0.5px}
        .mbi-nav{list-style:none}
        .mbi-nav li a{display:flex;align-items:center;gap:4px;padding:6px 12px;color:var(--muted);text-decoration:none;font-size:15px;transition:all 0.2s}
        .mbi-nav li a:hover{color:var(--ink);background:#ffffff}
        .mbi-nav li a.active{color:var(--accent);background:rgba(72,120,166,0.08)}
        .mbi-main{margin-left:250px;flex:1;display:flex;flex-direction:column}
        .mbi-topbar{height:64px;background:var(--bg-soft);border-bottom:1px solid var(--stroke);display:flex;align-items:center;justify-content:space-between;padding:0 30px}
        .mbi-topbar h1{font-size:18px;color:var(--ink)}
        .mbi-content{flex:1;padding:30px;overflow-y:auto}
        @media(max-width:900px){.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}}

        /* Tabs */
        .cfg-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--stroke);padding-bottom:0}
        .cfg-tab{padding:10px 20px;font-size:13px;font-weight:600;border:none;background:transparent;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;font-family:inherit}
        .cfg-tab.active{color:var(--accent);border-bottom-color:var(--accent)}

        /* Accordion rubriques */
        .rubrique-card{background:#ffffff;border:1px solid var(--stroke);border-radius:12px;margin-bottom:12px;overflow:hidden}
        .rubrique-header{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;transition:background .15s}
        .rubrique-header:hover{background:rgba(72,120,166,0.04)}
        .rubrique-title{flex:1;font-weight:600;font-size:14px;color:var(--ink)}
        .rubrique-meta{font-size:11px;color:var(--muted);margin-left:8px}
        .rubrique-body{display:none;padding:0 18px 16px;border-top:1px solid var(--stroke)}
        .rubrique-body.open{display:block}

        /* Toggle switch */
        .toggle{position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0}
        .toggle input{opacity:0;width:0;height:0;position:absolute}
        .toggle-track{position:absolute;inset:0;background:#ffffff;border-radius:22px;transition:background .2s;cursor:pointer}
        .toggle input:checked + .toggle-track{background:rgba(102,217,255,0.45)}
        .toggle-track::after{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:3px;left:3px;transition:transform .2s;box-shadow:0 1px 4px #f7f8fa}
        .toggle input:checked + .toggle-track::after{transform:translateX(18px)}

        /* Question list */
        .question-row{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--stroke-soft)}
        .question-row:last-child{border-bottom:none}
        .question-info{flex:1}
        .question-label{font-size:13px;font-weight:600;color:var(--ink)}
        .question-text{font-size:11px;color:var(--muted);margin-top:2px}
        .question-expand{font-size:11px;color:var(--accent);cursor:pointer;background:none;border:none;padding:0;font-family:inherit;margin-top:4px}

        /* Options */
        .options-list{background:rgba(0,0,0,0.15);border-radius:8px;padding:10px 12px;margin-top:8px}
        .option-row{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12px;color:var(--ink)}
        .option-row:not(:last-child){border-bottom:1px solid var(--stroke-soft)}

        /* Buttons */
        .btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;border:none;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit;transition:all .15s}
        .btn-primary{background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:var(--accent)}
        .btn-primary:hover{background:rgba(102,217,255,0.33)}
        .btn-success{background:rgba(124,245,214,0.2);border:1px solid rgba(124,245,214,0.4);color:#4a6038}
        .btn-success:hover{background:rgba(124,245,214,0.33)}
        .btn-danger{background:rgba(255,107,122,0.15);border:1px solid rgba(255,107,122,0.4);color:#ff6b7a}
        .btn-sm{padding:4px 10px;font-size:11px}
        .btn-add-q{margin-top:12px;padding:6px 14px;background:rgba(255,212,121,0.12);border:1px dashed rgba(255,212,121,0.4);color:#ffd479;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit;transition:all .15s}
        .btn-add-q:hover{background:rgba(255,212,121,0.22)}

        /* Suggestions */
        .suggestion-card{background:#ffffff,0.85);border:1px solid var(--stroke);border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:flex-start;gap:12px}
        .suggestion-info{flex:1}
        .suggestion-label{font-weight:600;font-size:13px;color:var(--ink)}
        .suggestion-text{font-size:11px;color:var(--muted);margin-top:3px}
        .suggestion-rub{font-size:10px;color:var(--accent);margin-top:4px;text-transform:uppercase;font-weight:600}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center}
        .modal-overlay.open{display:flex}
        .modal-box{background:var(--bg-soft);border:1px solid var(--stroke);border-radius:14px;padding:24px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto}
        .modal-box h3{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:18px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
        .form-group input,.form-group textarea{width:100%;background:#ffffff;border:1px solid var(--stroke);border-radius:7px;padding:8px 12px;color:var(--ink);font-family:inherit;font-size:13px}
        .form-group textarea{min-height:70px;resize:vertical}
        .form-group input:focus,.form-group textarea:focus{outline:none;border-color:rgba(72,120,166,0.3)}
        .options-builder{background:rgba(0,0,0,0.15);border-radius:8px;padding:12px;margin-top:6px}
        .option-input-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}
        .option-input-row input{flex:1;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;padding:6px 10px;color:var(--ink);font-family:inherit;font-size:12px}
        .option-input-row .rm-opt{background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;line-height:1;padding:0 4px}
        .option-input-row .rm-opt:hover{color:#ff6b7a}
        .add-opt-btn{background:none;border:1px dashed rgba(255,255,255,0.2);border-radius:6px;padding:5px 12px;color:var(--muted);cursor:pointer;font-size:11px;font-family:inherit;width:100%;margin-top:4px}
        .add-opt-btn:hover{border-color:rgba(72,120,166,0.25);color:var(--accent)}
        .modal-actions{display:flex;gap:10px;margin-top:18px;justify-content:flex-end}
        .toast{position:fixed;bottom:24px;right:24px;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
        .toast.show{opacity:1;transform:translateY(0)}
        .toast.ok{background:rgba(124,245,214,0.2);border:1px solid rgba(124,245,214,0.5);color:#4a6038}
        .toast.err{background:rgba(255,107,122,0.2);border:1px solid rgba(255,107,122,0.5);color:#ff6b7a}
        .empty-state{text-align:center;color:var(--muted);padding:40px 20px;font-size:13px;font-style:italic}
        .badge-count{display:inline-block;background:rgba(72,120,166,0.1);border:1px solid rgba(72,120,166,0.2);color:var(--accent);font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:6px}
    </style>
EXTRACSS;

ob_start();
?>

        <!-- TABS -->
        <div class="cfg-tabs">
            <button class="cfg-tab <?= $tab === 'config' ? 'active' : '' ?>"
                    onclick="switchTab('config')">
                📋 Configuration rubriques &amp; questions
            </button>
            <button class="cfg-tab <?= $tab === 'suggestions' ? 'active' : '' ?>"
                    onclick="switchTab('suggestions')">
                💡 Suggestions
                <?php if (!empty($suggestions)): ?>
                <span class="badge-count"><?= count($suggestions) ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- TAB CONFIG -->
        <div id="tab-config" class="tab-pane" style="<?= $tab !== 'config' ? 'display:none' : '' ?>">
            <?php if (empty($allRubriques)): ?>
                <div class="empty-state">Aucune rubrique trouvée. Vérifiez que la table rh_entretien_rubriques est peuplée.</div>
            <?php else: foreach ($allRubriques as $rub):
                $rubId      = (int)$rub['id'];
                $rubActif   = (bool)(int)($rub['soc_actif'] ?? 1);
                $qList      = $allQuestions[$rubId] ?? [];
                $qActifCount = count(array_filter($qList, fn($q) => (bool)(int)($q['soc_actif'] ?? 1)));
            ?>
            <div class="rubrique-card" id="rub-<?= $rubId ?>">
                <div class="rubrique-header" onclick="toggleAccordion(<?= $rubId ?>)">
                    <label class="toggle" onclick="event.stopPropagation()" title="Activer/désactiver cette rubrique">
                        <input type="checkbox" data-action="toggle_rubrique" data-id="<?= $rubId ?>"
                               <?= $rubActif ? 'checked' : '' ?>
                               onchange="toggleItem(this,'rubrique_id',<?= $rubId ?>)">
                        <span class="toggle-track"></span>
                    </label>
                    <span class="rubrique-title">
                        <?= h($rub['nom']) ?>
                        <span class="rubrique-meta"><?= count($qList) ?> question(s)</span>
                    </span>
                    <span id="arrow-<?= $rubId ?>" style="color:var(--muted);font-size:12px;transition:transform .2s">▼</span>
                </div>

                <div class="rubrique-body" id="body-<?= $rubId ?>">
                    <?php if (empty($qList)): ?>
                        <p style="font-size:12px;color:var(--muted);margin:12px 0">Aucune question pour cette rubrique.</p>
                    <?php else: foreach ($qList as $q):
                        $qId    = (int)$q['id'];
                        $qActif = (bool)(int)($q['soc_actif'] ?? 1);
                        $opts   = $allOptions[$qId] ?? [];
                    ?>
                    <div class="question-row" id="q-<?= $qId ?>">
                        <label class="toggle" title="Activer/désactiver cette question">
                            <input type="checkbox" data-action="toggle_question" data-id="<?= $qId ?>"
                                   <?= $qActif ? 'checked' : '' ?>
                                   onchange="toggleItem(this,'question_id',<?= $qId ?>)">
                            <span class="toggle-track"></span>
                        </label>
                        <div class="question-info">
                            <div class="question-label"><?= h($q['label']) ?></div>
                            <?php if (!empty($q['question_text'])): ?>
                            <div class="question-text"><?= h($q['question_text']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($opts)): ?>
                            <button class="question-expand" onclick="toggleOptions(<?= $qId ?>)">
                                ▸ <?= count($opts) ?> option(s) — cliquer pour voir
                            </button>
                            <div class="options-list" id="opts-<?= $qId ?>" style="display:none">
                                <?php foreach ($opts as $opt):
                                    $optId    = (int)$opt['id'];
                                    $optActif = (bool)(int)($opt['soc_actif'] ?? 1);
                                ?>
                                <div class="option-row">
                                    <label class="toggle" style="width:32px;height:18px;" title="Activer/désactiver cette option">
                                        <input type="checkbox" data-action="toggle_option" data-id="<?= $optId ?>"
                                               <?= $optActif ? 'checked' : '' ?>
                                               onchange="toggleItem(this,'option_id',<?= $optId ?>)">
                                        <span class="toggle-track" style="border-radius:18px"></span>
                                    </label>
                                    <span><?= h($opt['label']) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>

                    <button class="btn-add-q" onclick="openAddQuestion(<?= $rubId ?>)">
                        + Ajouter une question
                    </button>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- TAB SUGGESTIONS -->
        <div id="tab-suggestions" class="tab-pane" style="<?= $tab !== 'suggestions' ? 'display:none' : '' ?>">
            <?php if (empty($suggestions)): ?>
                <div class="empty-state">Aucune suggestion disponible pour le moment.<br>Les questions partagées par d'autres sociétés apparaîtront ici.</div>
            <?php else: ?>
            <p style="font-size:12px;color:var(--muted);margin-bottom:16px">
                Ces questions ont été partagées par d'autres sociétés. Adoptez celles qui correspondent à vos besoins.
            </p>
            <?php foreach ($suggestions as $s):
                $sId = (int)$s['id'];
            ?>
            <div class="suggestion-card" id="sug-<?= $sId ?>">
                <div class="suggestion-info">
                    <div class="suggestion-label"><?= h($s['label']) ?></div>
                    <?php if (!empty($s['question_text'])): ?>
                    <div class="suggestion-text"><?= h($s['question_text']) ?></div>
                    <?php endif; ?>
                    <div class="suggestion-rub"><?= h($s['rubrique_nom'] ?? '') ?></div>
                </div>
                <button class="btn btn-success btn-sm" onclick="adoptSuggestion(<?= $sId ?>, this)">
                    ✓ Adopter
                </button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>


<!-- MODAL AJOUT QUESTION -->
<div class="modal-overlay" id="modalAddQuestion">
    <div class="modal-box">
        <h3>➕ Ajouter une question</h3>
        <input type="hidden" id="modalRubriqueId" value="">

        <div class="form-group">
            <label>Libellé (affiché dans la liste) *</label>
            <input type="text" id="newQLabel" placeholder="ex: Satisfaction globale au poste">
        </div>
        <div class="form-group">
            <label>Texte de la question</label>
            <textarea id="newQText" placeholder="ex: Comment évaluez-vous votre satisfaction globale dans votre poste actuel ?"></textarea>
        </div>
        <div class="form-group">
            <label>Description / consigne</label>
            <textarea id="newQDesc" placeholder="Aide à l'animateur..."></textarea>
        </div>
        <div class="form-group">
            <label>Options de réponse</label>
            <div class="options-builder" id="optionsBuilder">
                <!-- options dynamiques -->
            </div>
            <button class="add-opt-btn" onclick="addOptionInput()">+ Ajouter une option</button>
        </div>

        <div class="modal-actions">
            <button class="btn" style="background:rgba(255,255,255,.07);border:1px solid var(--stroke);color:var(--muted)"
                    onclick="closeModal()">Annuler</button>
            <button class="btn btn-primary" onclick="submitAddQuestion()">Enregistrer</button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<?php
$layout_content = ob_get_clean();

$csrfToken_json = json_encode($csrfToken);

$layout_extra_js = <<<EXTRAJS
<script>
const CSRF = {$csrfToken_json};
const API  = 'api/rh_entretien_catalog_save.php';

// ── Tab switching ──────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.cfg-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).style.display = '';
    document.querySelectorAll('.cfg-tab').forEach(b => {
        if (b.getAttribute('onclick').includes("'" + name + "'")) b.classList.add('active');
    });
}

// ── Accordion ─────────────────────────────────────────────────
function toggleAccordion(rubId) {
    const body  = document.getElementById('body-' + rubId);
    const arrow = document.getElementById('arrow-' + rubId);
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    arrow.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Toggle options list ────────────────────────────────────────
function toggleOptions(qId) {
    const el = document.getElementById('opts-' + qId);
    const btn = el.previousElementSibling;
    const hidden = el.style.display === 'none';
    el.style.display = hidden ? 'block' : 'none';
    btn.textContent = hidden ? '▾ ' + el.querySelectorAll('.option-row').length + ' option(s) — cliquer pour masquer'
                             : '▸ ' + el.querySelectorAll('.option-row').length + ' option(s) — cliquer pour voir';
}

// ── Toggle rubrique / question / option ───────────────────────
function toggleItem(checkbox, field, id) {
    const action = field === 'rubrique_id' ? 'toggle_rubrique'
                 : field === 'question_id' ? 'toggle_question'
                 : 'toggle_option';
    apiCall({ action, [field]: id, actif: checkbox.checked })
        .then(() => showToast(checkbox.checked ? 'Activé' : 'Désactivé', 'ok'))
        .catch(() => {
            checkbox.checked = !checkbox.checked;
            showToast('Erreur lors de la sauvegarde', 'err');
        });
}

// ── Modal ajout question ───────────────────────────────────────
function openAddQuestion(rubId) {
    document.getElementById('modalRubriqueId').value = rubId;
    document.getElementById('newQLabel').value = '';
    document.getElementById('newQText').value = '';
    document.getElementById('newQDesc').value = '';
    document.getElementById('optionsBuilder').innerHTML = '';
    // Pré-ajouter 2 options
    addOptionInput(); addOptionInput();
    document.getElementById('modalAddQuestion').classList.add('open');
}
function closeModal() {
    document.getElementById('modalAddQuestion').classList.remove('open');
}

function addOptionInput() {
    const builder = document.getElementById('optionsBuilder');
    const row = document.createElement('div');
    row.className = 'option-input-row';
    row.innerHTML = '<input type="text" placeholder="Libellé de l\\'option">'
        + '<button class="rm-opt" onclick="this.parentElement.remove()" title="Supprimer">×</button>';
    builder.appendChild(row);
    row.querySelector('input').focus();
}

function submitAddQuestion() {
    const rubId = parseInt(document.getElementById('modalRubriqueId').value);
    const label = document.getElementById('newQLabel').value.trim();
    const questionText = document.getElementById('newQText').value.trim();
    const description = document.getElementById('newQDesc').value.trim();
    if (!label) { showToast('Le libellé est requis', 'err'); return; }

    const options = [...document.querySelectorAll('#optionsBuilder .option-input-row input')]
        .map(i => i.value.trim()).filter(Boolean);

    apiCall({ action: 'add_question', rubrique_id: rubId, label, question_text: questionText, description, options })
        .then(data => {
            showToast('Question ajoutée avec succès', 'ok');
            closeModal();
            setTimeout(() => location.reload(), 800);
        })
        .catch(err => showToast(err.message || 'Erreur', 'err'));
}

// ── Adopt suggestion ───────────────────────────────────────────
function adoptSuggestion(questionId, btn) {
    btn.disabled = true;
    btn.textContent = '…';
    apiCall({ action: 'adopt_suggestion', question_id: questionId })
        .then(() => {
            showToast('Question adoptée !', 'ok');
            const card = document.getElementById('sug-' + questionId);
            if (card) card.style.opacity = '0.4';
            btn.textContent = '✓ Adoptée';
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = '✓ Adopter';
            showToast(err.message || 'Erreur', 'err');
        });
}

// ── API helper ─────────────────────────────────────────────────
function apiCall(payload) {
    return fetch(API, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF,
        },
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Erreur API');
        return data;
    });
}

// ── Toast ──────────────────────────────────────────────────────
let toastTimer = null;
function showToast(msg, type = 'ok') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
}

// Close modal on overlay click
document.getElementById('modalAddQuestion').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
EXTRAJS;

require_once __DIR__ . '/inc/layout_maboximmo.php';
