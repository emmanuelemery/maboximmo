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

$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) {
    // ═══════════════════════════════════════════════════════════════
    // VIEW 1 : Liste des entretiens du collaborateur + préparation
    // ═══════════════════════════════════════════════════════════════
    $entretiens = [];
    try {
        $stmtL = $pdo->prepare("SELECT id, statut, date_planifiee, date_realisation, type_entretien FROM rh_entretiens WHERE collaborateur_id = ? ORDER BY date_planifiee DESC, id DESC");
        $stmtL->execute([$userId]);
        $entretiens = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $entretiens = [];
    }

    // Société pour charger le modèle de rubriques/questions
    $societeId = 0;
    try {
        $stmtSoc = $pdo->prepare("SELECT id_societe FROM users WHERE id = ? LIMIT 1");
        $stmtSoc->execute([$userId]);
        $societeId = (int)$stmtSoc->fetchColumn();
    } catch (PDOException $e) { $societeId = 0; }

    $rubriques = [];
    try {
        $rubriques = catalog_get_rubriques($pdo, $societeId);
    } catch (PDOException $e) { $rubriques = []; }

    $questionsByRub = [];
    foreach ($rubriques as $rub) {
        $rid = (int)$rub['id'];
        try {
            $questionsByRub[$rid] = catalog_get_questions($pdo, $societeId, $rid);
        } catch (PDOException $e) {
            $questionsByRub[$rid] = [];
        }
    }

    // Table de préparation collaborateur
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rh_entretien_prep_user (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_user INT NOT NULL,
            rubrique_id INT NOT NULL,
            question_id INT NOT NULL,
            note TINYINT NULL,
            commentaire TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_question (id_user, question_id),
            INDEX(id_user),
            INDEX(rubrique_id),
            INDEX(updated_at)
        )");
    } catch (Exception $e) {}

    // Chargement préparation existante
    $prep = [];
    $lastTs = 0;
    try {
        $stmtP = $pdo->prepare("SELECT question_id, note, commentaire, updated_at FROM rh_entretien_prep_user WHERE id_user = ?");
        $stmtP->execute([$userId]);
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = (int)$row['question_id'];
            $prep[$qid] = $row;
            $ts = strtotime((string)($row['updated_at'] ?? ''));
            if ($ts > $lastTs) $lastTs = $ts;
        }
    } catch (Exception $e) {}
    if ($lastTs <= 0) $lastTs = time();

    // --- Layout variables ---
    $_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Mes entretiens — ' . h($_userName);
    $layout_module  = 'Ma Box RH';
    $layout_sidebar = 'rh_sidebar';

    $layout_head_kpis = '<div class="ph-kpi"><div class="ph-kpi-value">' . count($entretiens) . '</div><div class="ph-kpi-label">Entretiens</div></div>';

    $layout_extra_css = <<<'EXTRACSS'
<style>
    .prep-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
    .card{background:var(--bg-soft);border:1px solid var(--stroke);border-radius:14px;padding:16px 18px;box-shadow:0 2px 14px rgba(0,0,0,.2)}
    .row{display:flex;gap:8px;align-items:center}
    .list-card{position:sticky;top:16px}
    .section-title{font-size:12px;font-weight:800;text-transform:uppercase;color:var(--muted);letter-spacing:.5px;margin-bottom:10px}
    details.rubrique{margin-bottom:12px;border:1px solid var(--stroke);border-radius:10px;background:rgba(0,0,0,.15);padding:8px 10px}
    summary.rubrique-summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;font-weight:800;font-size:13px;color:var(--ink)}
    summary.rubrique-summary::-webkit-details-marker{display:none}
    .rubrique-progress{font-size:11px;color:var(--muted);font-weight:600}
    .rubrique-body{margin-top:8px}
    .question-card{border:1px solid var(--stroke);border-radius:10px;padding:10px 12px;background:rgba(0,0,0,.15);margin-bottom:10px}
    .question-title{font-size:12px;font-weight:700;color:var(--ink)}
    .question-desc{font-size:11px;color:var(--muted);margin-top:4px}
    .question-actions{display:grid;grid-template-columns:180px 1fr;gap:10px;margin-top:8px;align-items:start}
    .question-actions label{font-size:10px;text-transform:uppercase;color:var(--muted);letter-spacing:.4px}
    .q-stars{display:flex;gap:.35rem;align-items:center;margin-top:6px}
    .q-stars::before{content:'Évaluation :';font-size:.72rem;color:var(--muted);font-weight:600;margin-right:.25rem}
    .q-star{font-size:1.5rem;background:none;border:none;cursor:pointer;color:#d1d5db;line-height:1;padding:0;transition:color .1s, transform .1s}
    .q-star:hover,.q-star.q-star-active{color:#f59e0b}
    .q-star:hover{transform:scale(1.2)}
    .note-hidden{display:none}
    select, textarea{width:100%;background:rgba(255,255,255,.08);border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit}
    select{padding:6px 8px;font-size:12px}
    textarea{padding:8px 10px;font-size:12px;min-height:60px;resize:vertical}
    .save-badge{font-size:10px;color:var(--muted);margin-top:6px}
    .save-badge.ok{color:#4a6038}
    .list{display:grid;gap:10px}
    .item{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border:1px solid var(--stroke);border-radius:10px;background:rgba(0,0,0,.15)}
    .item-left{display:flex;flex-direction:column;gap:4px}
    .item-title{font-weight:700;font-size:13px}
    .item-meta{font-size:11px;color:var(--muted)}
    .status{font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.12);color:var(--muted)}
    .status.ok{color:#4a6038;border-color:rgba(124,245,214,.35);background:rgba(124,245,214,.12)}
    .status.todo{color:#fbbf24;border-color:rgba(251,191,36,.35);background:rgba(251,191,36,.12)}
    .status.info{color:#93c5fd;border-color:rgba(147,197,253,.35);background:rgba(147,197,253,.12)}
    .btn-ent{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;border:1px solid rgba(102,217,255,.35);background:rgba(102,217,255,.15);color:var(--accent);font-size:12px;font-weight:700;text-decoration:none}
    .btn-ent:hover{background:rgba(102,217,255,.25)}
    .empty{color:var(--muted);font-size:12px;padding:12px}
    @media(max-width:900px){.prep-grid{grid-template-columns:1fr}.list-card{position:static}}
</style>
EXTRACSS;

    $layout_extra_js = <<<EXTRAJS
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const PREP_SAVE_API = 'api/rh_entretien_prep_save.php';
const PREP_SYNC_API = 'api/rh_entretien_prep_sync.php';
let lastTs = {$lastTs};
const saveTimers = {};
const saveSeq = {};

function updateStars(qid, note) {
    const row = document.querySelector('.q-stars[data-qid="' + qid + '"]');
    if (!row) return;
    row.dataset.note = note ? String(note) : '';
    row.querySelectorAll('.q-star').forEach(st => {
        const v = parseInt(st.getAttribute('data-val') || '0');
        if (note && v <= note) {
            st.classList.add('q-star-active');
        } else {
            st.classList.remove('q-star-active');
        }
    });
}

function updateRubriqueProgress(rid) {
    const details = document.querySelector('.rubrique[data-rubrique-id="' + rid + '"]');
    if (!details) return;
    const cards = details.querySelectorAll('.question-card');
    const total = cards.length;
    let answered = 0;
    cards.forEach(card => {
        const noteEl = card.querySelector('.prep-note');
        const comEl = card.querySelector('.prep-comment');
        const noteVal = noteEl ? parseInt(noteEl.value || '0') : 0;
        const comVal = comEl ? comEl.value.trim() : '';
        if (noteVal > 0 || comVal !== '') answered++;
    });
    const remaining = Math.max(0, total - answered);
    const prog = details.querySelector('.rubrique-progress');
    if (prog) prog.textContent = answered + '/' + total + ' notées · reste ' + remaining;
}

function setStatus(qid, msg, ok=false) {
    const el = document.getElementById('save-q-' + qid);
    if (!el) return;
    el.textContent = msg;
    el.className = 'save-badge' + (ok ? ' ok' : '');
}

function scheduleSave(qid) {
    saveSeq[qid] = (saveSeq[qid] || 0) + 1;
    const seq = saveSeq[qid];
    clearTimeout(saveTimers[qid]);
    setStatus(qid, 'Enregistrement...');
    saveTimers[qid] = setTimeout(() => saveQuestion(qid, seq), 400);
}

function saveQuestion(qid, seq) {
    const card = document.querySelector('.question-card[data-question-id="' + qid + '"]');
    if (!card) return;
    const rid = parseInt(card.getAttribute('data-rubrique-id') || '0');
    const noteEl = card.querySelector('.prep-note');
    const comEl = card.querySelector('.prep-comment');
    const note = noteEl ? noteEl.value : '';
    const commentaire = comEl ? comEl.value : '';
    const payload = { question_id: qid, rubrique_id: rid, note: note === '' ? null : parseInt(note), commentaire, csrf_token: CSRF_TOKEN };
    fetch(PREP_SAVE_API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: Object.assign({'Content-Type':'application/json'}, CSRF_TOKEN ? {'X-CSRF-Token': CSRF_TOKEN} : {}),
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (saveSeq[qid] !== seq) return;
        if (data.ok) {
            if (data.timestamp) lastTs = data.timestamp;
            setStatus(qid, 'Enregistré', true);
            setTimeout(() => { if (saveSeq[qid] === seq) setStatus(qid, ''); }, 1500);
        } else {
            setStatus(qid, data.error || 'Erreur');
        }
    })
    .catch(() => {
        if (saveSeq[qid] !== seq) return;
        setStatus(qid, 'Erreur');
        setTimeout(() => { if (saveSeq[qid] === seq) setStatus(qid, ''); }, 2000);
    });
}

document.querySelectorAll('.prep-note').forEach(el => {
    el.addEventListener('change', () => scheduleSave(parseInt(el.dataset.qid)));
});
document.querySelectorAll('.q-stars .q-star').forEach(star => {
    star.addEventListener('click', () => {
        const row = star.closest('.q-stars');
        if (!row) return;
        const qid = parseInt(row.getAttribute('data-qid') || '0');
        const val = parseInt(star.getAttribute('data-val') || '0');
        const input = row.parentElement.querySelector('.prep-note');
        if (input) input.value = String(val);
        updateStars(qid, val);
        scheduleSave(qid);
        const card = row.closest('.question-card');
        const rid = parseInt(card?.getAttribute('data-rubrique-id') || '0');
        if (rid) updateRubriqueProgress(rid);
    });
});
document.querySelectorAll('.prep-comment').forEach(el => {
    el.addEventListener('input', () => {
        scheduleSave(parseInt(el.dataset.qid));
        const card = el.closest('.question-card');
        const rid = parseInt(card?.getAttribute('data-rubrique-id') || '0');
        if (rid) updateRubriqueProgress(rid);
    });
});
document.querySelectorAll('.rubrique[data-rubrique-id]').forEach(el => {
    const rid = parseInt(el.getAttribute('data-rubrique-id') || '0');
    if (rid) updateRubriqueProgress(rid);
});

setInterval(() => {
    fetch(PREP_SYNC_API + '?ts=' + encodeURIComponent(lastTs))
        .then(r => r.json())
        .then(data => {
            if (!data.ok || !Array.isArray(data.items)) return;
            if (data.timestamp) lastTs = data.timestamp;
            data.items.forEach(it => {
                const qid = parseInt(it.question_id);
                const card = document.querySelector('.question-card[data-question-id="' + qid + '"]');
                if (!card) return;
                const noteEl = card.querySelector('.prep-note');
                const comEl = card.querySelector('.prep-comment');
                const active = document.activeElement;
                if (noteEl && active !== noteEl) {
                    noteEl.value = it.note === null ? '' : String(it.note);
                    updateStars(qid, it.note);
                }
                if (comEl && active !== comEl) {
                    comEl.value = it.commentaire || '';
                }
                const rid = parseInt(card.getAttribute('data-rubrique-id') || '0');
                if (rid) updateRubriqueProgress(rid);
            });
        })
        .catch(() => {});
}, 3000);
</script>
EXTRAJS;

    ob_start();
    ?>
                <div class="prep-grid">
                    <div class="card">
                        <div class="section-title">Préparation de l'entretien annuel</div>
                        <?php if (empty($rubriques)): ?>
                            <div class="empty">Aucune rubrique disponible pour préparer l'entretien.</div>
                        <?php else: ?>
                            <?php foreach ($rubriques as $rub):
                                $rid = (int)$rub['id'];
                                $qs = $questionsByRub[$rid] ?? [];
                            ?>
                            <?php
                                $totalQ = count($qs);
                                $answered = 0;
                                foreach ($qs as $qCount) {
                                    $qidCount = (int)$qCount['id'];
                                    $valCount = $prep[$qidCount] ?? ['note' => null, 'commentaire' => ''];
                                    $noteCount = (int)($valCount['note'] ?? 0);
                                    $comCount = trim((string)($valCount['commentaire'] ?? ''));
                                    if ($noteCount > 0 || $comCount !== '') $answered++;
                                }
                                $remaining = max(0, $totalQ - $answered);
                            ?>
                            <details class="rubrique" data-rubrique-id="<?= $rid ?>">
                                <summary class="rubrique-summary">
                                    <span><?= h($rub['nom'] ?? 'Rubrique') ?></span>
                                    <span class="rubrique-progress"><?= $answered ?>/<?= $totalQ ?> notées · reste <?= $remaining ?></span>
                                </summary>
                                <div class="rubrique-body">
                                    <?php if (empty($qs)): ?>
                                        <div class="empty">Aucune question pour cette rubrique.</div>
                                    <?php else: ?>
                                        <?php foreach ($qs as $q):
                                            $qid = (int)$q['id'];
                                            $val = $prep[$qid] ?? ['note' => null, 'commentaire' => ''];
                                            $label = trim((string)($q['question_text'] ?? '')) ?: trim((string)($q['label'] ?? ''));
                                            $desc = trim((string)($q['description'] ?? ''));
                                            $noteVal = (int)($val['note'] ?? 0);
                                        ?>
                                        <div class="question-card" data-question-id="<?= $qid ?>" data-rubrique-id="<?= $rid ?>">
                                            <div class="question-title"><?= h($label ?: 'Question') ?></div>
                                            <?php if ($desc): ?><div class="question-desc"><?= h($desc) ?></div><?php endif; ?>
                                            <div class="question-actions">
                                                <div>
                                                    <label>Note</label>
                                                    <div class="q-stars" data-qid="<?= $qid ?>" data-note="<?= $noteVal ?>">
                                                        <?php for ($n=1; $n<=5; $n++): ?>
                                                            <button type="button" class="q-star <?= ($noteVal >= $n) ? 'q-star-active' : '' ?>" data-val="<?= $n ?>">★</button>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <input type="hidden" class="prep-note note-hidden" data-qid="<?= $qid ?>" value="<?= $noteVal > 0 ? $noteVal : '' ?>">
                                                </div>
                                                <div>
                                                    <label>Commentaire</label>
                                                    <textarea class="prep-comment" data-qid="<?= $qid ?>" placeholder="Vos remarques pour préparer l'entretien..."><?= h((string)($val['commentaire'] ?? '')) ?></textarea>
                                                    <div class="save-badge" id="save-q-<?= $qid ?>"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="card list-card">
                        <div class="section-title">Mes entretiens</div>
                        <?php if (empty($entretiens)): ?>
                            <div class="empty">Aucun entretien disponible pour le moment.</div>
                        <?php else: ?>
                            <div class="list">
                                <?php foreach ($entretiens as $e):
                                    $statut = (string)($e['statut'] ?? '');
                                    $dateRef = $e['date_realisation'] ?: $e['date_planifiee'];
                                    $dateStr = $dateRef ? date('d/m/Y', strtotime($dateRef)) : 'Date à définir';
                                    if ($statut === 'questionnaire_envoye') {
                                        $label = 'Auto-évaluation';
                                        $url = 'rh_entretien_auto_eval.php?id=' . (int)$e['id'];
                                        $statusClass = 'info';
                                    } elseif (in_array($statut, ['termine','signe'], true)) {
                                        $label = 'Compte rendu';
                                        $url = 'rh_entretien_vue_collaborateur.php?id=' . (int)$e['id'];
                                        $statusClass = 'ok';
                                    } else {
                                        $label = 'Entretien en cours';
                                        $url = 'rh_entretien_vue_collaborateur.php?id=' . (int)$e['id'];
                                        $statusClass = 'todo';
                                    }
                                ?>
                                <div class="item">
                                    <div class="item-left">
                                        <div class="item-title"><?= h($label) ?></div>
                                        <div class="item-meta"><?= h($dateStr) ?><?= !empty($e['type_entretien']) ? ' · ' . h((string)$e['type_entretien']) : '' ?></div>
                                    </div>
                                    <div class="row">
                                        <span class="status <?= $statusClass ?>"><?= h($statut ?: 'en_cours') ?></span>
                                        <a class="btn-ent" href="<?= h($url) ?>">Voir</a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
    <?php
    $layout_content = ob_get_clean();
    require_once __DIR__ . '/inc/layout_maboximmo.php';
    exit;
}

// ═══════════════════════════════════════════════════════════════
// VIEW 2 : Détail d'un entretien (compte rendu / vue live)
// ═══════════════════════════════════════════════════════════════

// Charger l'entretien
try {
    $stmt = $pdo->prepare("
        SELECT e.*,
               u.prenom AS collab_prenom, u.nom AS collab_nom, u.poste AS collab_poste
        FROM rh_entretiens e
        JOIN users u ON u.id = e.collaborateur_id
        WHERE e.id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); exit('Erreur base de données.');
}

if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }

// Vérifier que l'utilisateur connecté est bien le collaborateur
if ((int)$entretien['collaborateur_id'] !== $userId) {
    http_response_code(403); exit('Accès réservé au collaborateur de cet entretien.');
}

// Vérifier statut
$statutsAutorisés = ['en_cours', 'termine', 'signe'];
if (!in_array($entretien['statut'], $statutsAutorisés, true)) {
    if ($entretien['statut'] === 'questionnaire_envoye') {
        // Rediriger vers l'auto-évaluation
        header('Location: rh_entretien_auto_eval.php?id=' . $entretienId);
        exit;
    }
    http_response_code(403); exit('Le compte rendu n\'est pas encore disponible pour cet entretien.');
}

$isCompteRendu = in_array($entretien['statut'], ['termine', 'signe'], true);

$rubriqueCourante = (int)($entretien['rubrique_en_cours'] ?? 1) ?: 1;

// Définition des rubriques (sans rubrique 9 manager_only)
$rubriquesDetail = [
    1  => ['nom' => 'Ouverture'],
    2  => ['nom' => 'Bilan collaborateur'],
    3  => ['nom' => 'Valorisation'],
    4  => ['nom' => 'Performance'],
    5  => ['nom' => 'Comportement'],
    6  => ['nom' => 'Motivation'],
    7  => ['nom' => 'Adaptabilité'],
    8  => ['nom' => 'Compétences'],
    10 => ['nom' => 'Plan d\'action'],
    11 => ['nom' => 'Synthèse'],
];

$rubriqueCouranteNom = $rubriquesDetail[$rubriqueCourante]['nom'] ?? 'En cours';

// Charger les réponses visibles par le collaborateur
$reponses = [];
try {
    $stmtR = $pdo->prepare("
        SELECT r.critere_id, r.rubrique_id, r.texte_final, r.note,
               r.visible_collaborateur, r.validee_collaborateur,
               r.desaccord_collaborateur, r.remarque_collaborateur,
               r.saved_at
        FROM rh_entretien_reponses r
        WHERE r.entretien_id = ?
          AND r.visible_collaborateur = 1
          AND r.rubrique_id != 9
        ORDER BY r.rubrique_id, r.critere_id
    ");
    $stmtR->execute([$entretienId]);
    $reponses = $stmtR->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table peut ne pas exister */ }

// Charger le plan d'actions visible
$actions = [];
try {
    $stmtA = $pdo->prepare("
        SELECT id, libelle, responsable, echeance, statut, visible_collaborateur
        FROM rh_entretien_actions
        WHERE entretien_id = ? AND visible_collaborateur = 1
        ORDER BY id
    ");
    $stmtA->execute([$entretienId]);
    $actions = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// Dernier timestamp de mise à jour
$lastUpdate = 0;
foreach ($reponses as $r) {
    $ts = strtotime($r['saved_at'] ?? '');
    if ($ts > $lastUpdate) $lastUpdate = $ts;
}
if ($lastUpdate === 0) $lastUpdate = time();

// --- Layout variables ---
$collabFullName = h($entretien['collab_prenom'] . ' ' . $entretien['collab_nom']);
$layout_title   = 'Entretien — ' . ($isCompteRendu ? 'Compte rendu' : 'Vue collaborateur');
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$_kpiItems = [];
$_kpiItems[] = '<div class="ph-kpi"><div class="ph-kpi-value">' . $collabFullName . '</div><div class="ph-kpi-label">Collaborateur</div></div>';
if ($isCompteRendu) {
    $_kpiItems[] = '<div class="ph-kpi"><div class="ph-kpi-value" style="color:#3a7a6a">Définitif</div><div class="ph-kpi-label">Statut</div></div>';
    if (!empty($entretien['score_global'])) {
        $_kpiItems[] = '<div class="ph-kpi"><div class="ph-kpi-value">' . number_format((float)$entretien['score_global'], 1) . '/5</div><div class="ph-kpi-label">Note globale</div></div>';
    }
} else {
    $_kpiItems[] = '<div class="ph-kpi"><div class="ph-kpi-value" id="rubrique-nom-kpi">' . h($rubriqueCouranteNom) . '</div><div class="ph-kpi-label">Rubrique en cours</div></div>';
}
$_kpiItems[] = '<div class="ph-kpi"><div class="ph-kpi-value">' . count($reponses) . '</div><div class="ph-kpi-label">Réponses</div></div>';
$layout_head_kpis = implode('', $_kpiItems);

$_actionItems = [];
if ($isCompteRendu) {
    $_actionItems[] = '<a class="ph-btn" href="rh_entretien_auto_eval.php?id=' . $entretienId . '">Voir mon auto-évaluation</a>';
}
$layout_head_actions = implode('', $_actionItems);

$_critereImplode = implode(',', array_column($reponses, 'critere_id'));
$_actionImplode  = implode(',', array_column($actions, 'id'));

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* EN-TETE INLINE */
    .vc-banner {
        background:linear-gradient(90deg,#3a7a6a,#2a6a5a);color:#fff;padding:.65rem 1.25rem;font-size:.82rem;display:flex;align-items:center;gap:.75rem;border-radius:10px;margin-bottom:16px;
    }

    .section-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #4a6038;
        text-transform: uppercase;
        letter-spacing: .05em;
        margin: 24px 0 12px;
        padding-bottom: 6px;
        border-bottom: 1px solid var(--stroke, #e0e0e0);
    }

    /* CARDS REPONSE */
    .reponse-card {
        background: var(--bg-soft, #fff);
        border: 1px solid var(--stroke, #e0e0e0);
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 12px;
        position: relative;
        transition: box-shadow .2s;
    }
    .reponse-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .reponse-card.fade-in {
        animation: fadeIn .4s ease-in-out;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .reponse-card .card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }
    .reponse-card .critere-label {
        font-weight: 600;
        font-size: 1rem;
    }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .badge-valid  { background: #dcfce7; color: #15803d; }
    .badge-desacc { background: #fff7ed; color: #8a5040; }
    .badge-rubrique { background: var(--bg-soft, #eef2ff); color: var(--ink, #1a1a2e); }

    .reponse-texte {
        font-size: 1rem;
        line-height: 1.6;
        color: var(--ink, #1a1a2e);
    }
    .reponse-remarque {
        margin-top: 8px;
        font-size: 0.9rem;
        color: var(--muted, #666);
        background: var(--bg-soft, #f8f9fa);
        padding: 8px 12px;
        border-radius: 6px;
        border-left: 3px solid #8a5040;
    }

    /* ACTIONS */
    .action-card {
        background: var(--bg-soft, #fff);
        border: 1px solid var(--stroke, #e0e0e0);
        border-radius: 8px;
        padding: 14px 18px;
        margin-bottom: 10px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }
    .action-card .action-icon {
        font-size: 1.3rem;
        flex-shrink: 0;
        margin-top: 2px;
    }
    .action-card .action-body .action-libelle {
        font-weight: 600;
        font-size: 1rem;
    }
    .action-card .action-body .action-meta {
        font-size: 0.85rem;
        color: var(--muted, #888);
        margin-top: 2px;
    }

    /* INTERACTIONS FOOTER */
    .vc-interact {
        background: var(--bg-soft, #fff);
        border: 1px solid var(--stroke, #e0e0e0);
        border-radius: 10px;
        padding: 16px 24px;
        margin-top: 24px;
    }
    .interact-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--muted, #666);
        margin-bottom: 10px;
    }
    .interact-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .btn-interact {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all .2s;
    }
    .btn-interact:disabled { opacity: .5; cursor: default; }
    .btn-valider  { background: #3a7a6a; color: #fff; }
    .btn-valider:hover:not(:disabled)  { background: #2a6a5a; }
    .btn-desaccord { background: #8a5040; color: #fff; }
    .btn-desaccord:hover:not(:disabled) { background: #7a4030; }
    .btn-remarque  { background: var(--bg-soft, #e0e7ff); color: var(--ink, #1a1a2e); }
    .btn-remarque:hover:not(:disabled)  { background: #c7d2fe; }

    .interact-form {
        display: none;
        margin-top: 12px;
    }
    .interact-form textarea {
        width: 100%;
        min-height: 80px;
        padding: 10px;
        border: 1px solid var(--stroke, #e0e0e0);
        border-radius: 8px;
        font-size: 0.95rem;
        resize: vertical;
        font-family: inherit;
    }
    .interact-form .form-actions {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }
    .btn-sm {
        padding: 7px 14px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .btn-send { background: #8a5040; color: #fff; }
    .btn-cancel { background: var(--bg-soft, #eee); color: var(--ink, #1a1a2e); }

    .toast {
        position: fixed;
        bottom: 90px;
        right: 20px;
        background: #1a1a2e;
        color: #fff;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 0.9rem;
        z-index: 9999;
        opacity: 0;
        transition: opacity .3s;
        pointer-events: none;
    }
    .toast.show { opacity: 1; }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--muted, #999);
        font-size: 0.95rem;
    }

    /* Sélecteur de critère pour interaction */
    .critere-select {
        padding: 8px 12px;
        border: 1px solid var(--stroke, #ddd);
        border-radius: 8px;
        font-size: 0.9rem;
        background: var(--bg-soft, #fff);
        min-width: 220px;
    }
</style>
EXTRACSS;

$_isCompteRenduJs = $isCompteRendu ? 'true' : 'false';
$layout_extra_js = <<<EXTRAJS
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const CSRF_HEADERS = CSRF_TOKEN ? {'X-CSRF-Token': CSRF_TOKEN} : {};
const ENTRETIEN_ID     = {$entretienId};
const IS_COMPTE_RENDU  = {$_isCompteRenduJs};
let lastUpdate         = {$lastUpdate};
let knownCriteres  = new Set([{$_critereImplode}]);
let knownActions   = new Set([{$_actionImplode}]);

// Polling AJAX toutes les 3 secondes (désactivé pour compte rendu définitif)
if (!IS_COMPTE_RENDU) setInterval(function () {
    fetch('api/rh_entretien_sync.php?id=' + ENTRETIEN_ID + '&ts=' + lastUpdate)
        .then(r => r.json())
        .then(data => {
            if (data.updated) {
                updateVueCollab(data);
                lastUpdate = data.timestamp;
            }
        })
        .catch(() => { /* silencieux */ });
}, 3000);

function updateVueCollab(data) {
    if (data.rubrique_nom) {
        const el = document.getElementById('rubrique-nom-kpi');
        if (el) el.textContent = data.rubrique_nom;
    }

    if (Array.isArray(data.reponses)) {
        const emptyEl = document.getElementById('empty-reponses');
        if (emptyEl && data.reponses.length > 0) emptyEl.style.display = 'none';

        data.reponses.forEach(rep => {
            const existing = document.getElementById('card-' + rep.critere_id);
            if (existing) {
                const texteEl = existing.querySelector('.reponse-texte');
                if (texteEl) texteEl.textContent = rep.texte_final;
                updateBadgesCard(existing, rep);
                let remarqueEl = existing.querySelector('.reponse-remarque');
                if (rep.remarque_collaborateur) {
                    if (!remarqueEl) {
                        remarqueEl = document.createElement('div');
                        remarqueEl.className = 'reponse-remarque';
                        existing.appendChild(remarqueEl);
                    }
                    remarqueEl.textContent = 'Votre remarque : ' + rep.remarque_collaborateur;
                }
            } else {
                const card = buildRepCard(rep);
                const container = document.getElementById('reponses-container');
                container.appendChild(card);
                knownCriteres.add(rep.critere_id);
                const sel = document.getElementById('critere-select');
                const opt = document.createElement('option');
                opt.value = rep.critere_id;
                opt.textContent = 'Critère ' + rep.critere_id;
                sel.appendChild(opt);
            }
        });
    }

    if (Array.isArray(data.actions)) {
        data.actions.forEach(action => {
            if (!knownActions.has(action.id)) {
                knownActions.add(action.id);
                const container = document.getElementById('actions-container');
                const sectionTitle = document.getElementById('actions-section-title');
                if (sectionTitle) sectionTitle.style.display = '';
                container.appendChild(buildActionCard(action));
            }
        });
    }
}

function updateBadgesCard(cardEl, rep) {
    cardEl.querySelectorAll('.badge-valid, .badge-desacc').forEach(b => b.remove());
    const header = cardEl.querySelector('.card-header');
    if (rep.validee_collaborateur) {
        const b = document.createElement('span');
        b.className = 'badge badge-valid';
        b.textContent = '✓ Validé';
        header.appendChild(b);
    }
    if (rep.desaccord_collaborateur) {
        const b = document.createElement('span');
        b.className = 'badge badge-desacc';
        b.textContent = '⚠ Désaccord';
        header.appendChild(b);
    }
}

function buildRepCard(rep) {
    const card = document.createElement('div');
    card.className = 'reponse-card fade-in';
    card.dataset.critere = rep.critere_id;
    card.id = 'card-' + rep.critere_id;
    card.innerHTML =
        '<div class="card-header">' +
            '<span class="critere-label">Critère ' + rep.critere_id + '</span>' +
            '<span class="badge badge-rubrique">Rubrique ' + (rep.rubrique_id || '') + '</span>' +
        '</div>' +
        '<div class="reponse-texte">' + escHtml(rep.texte_final) + '</div>';
    updateBadgesCard(card, rep);
    return card;
}

function buildActionCard(action) {
    const div = document.createElement('div');
    div.className = 'action-card fade-in';
    div.id = 'action-' + action.id;
    let meta = '';
    if (action.responsable) meta += 'Responsable : ' + escHtml(action.responsable) + ' &nbsp;|&nbsp; ';
    if (action.echeance)    meta += 'Échéance : ' + escHtml(action.echeance);
    div.innerHTML =
        '<div class="action-icon">📋</div>' +
        '<div class="action-body">' +
            '<div class="action-libelle">' + escHtml(action.libelle) + '</div>' +
            '<div class="action-meta">' + meta + '</div>' +
        '</div>';
    return div;
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

function openForm(type) {
    closeForm('desaccord');
    closeForm('remarque');
    document.getElementById('form-' + type).style.display = 'block';
}
function closeForm(type) {
    const f = document.getElementById('form-' + type);
    if (f) f.style.display = 'none';
}

function actionCollab(type) {
    const critereId = parseInt(document.getElementById('critere-select').value);
    if (!critereId) { showToast('Sélectionnez un critère.'); return; }

    const payload = {
        entretien_id: ENTRETIEN_ID,
        critere_id:   critereId,
        action:       type
    };

    if (type === 'desaccord') {
        const txt = document.getElementById('txt-desaccord').value.trim();
        if (!txt) { showToast('Saisissez votre désaccord.'); return; }
        payload.texte = txt;
    } else if (type === 'remarque') {
        const txt = document.getElementById('txt-remarque').value.trim();
        if (!txt) { showToast('Saisissez votre remarque.'); return; }
        payload.texte = txt;
    }

    fetch('api/rh_entretien_collab_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: Object.assign({ 'Content-Type': 'application/json' }, CSRF_HEADERS),
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Votre retour a été enregistré.');
            closeForm('desaccord');
            closeForm('remarque');
            document.getElementById('txt-desaccord').value = '';
            document.getElementById('txt-remarque').value  = '';

            const card = document.getElementById('card-' + critereId);
            if (card) {
                updateBadgesCard(card, {
                    validee_collaborateur:  type === 'valider'   ? 1 : 0,
                    desaccord_collaborateur: type === 'desaccord' ? 1 : 0,
                    remarque_collaborateur: type === 'remarque' ? payload.texte : null
                });
                if (type === 'remarque') {
                    let remarqueEl = card.querySelector('.reponse-remarque');
                    if (!remarqueEl) {
                        remarqueEl = document.createElement('div');
                        remarqueEl.className = 'reponse-remarque';
                        card.appendChild(remarqueEl);
                    }
                    remarqueEl.textContent = 'Votre remarque : ' + payload.texte;
                }
            }
        } else {
            showToast(data.error || 'Erreur lors de l\'envoi.');
        }
    })
    .catch(() => showToast('Erreur réseau, réessayez.'));
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}
</script>
EXTRAJS;

ob_start();
?>

<?php if ($isCompteRendu): ?>
<!-- BANNIÈRE COMPTE RENDU -->
<div class="vc-banner">
    <span style="font-size:1.1rem;">📋</span>
    <div>
        <strong>Compte rendu d'entretien</strong> —
        Entretien du <?= h($entretien['date_realisation'] ? (new DateTime($entretien['date_realisation']))->format('d/m/Y') : ($entretien['date_planifiee'] ?? '—')) ?> ·
        Ce document vous est conservé définitivement.
        <?php if (!empty($entretien['score_global'])): ?>
            Note globale : <strong><?= number_format((float)$entretien['score_global'], 1) ?>/5</strong>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Réponses validées -->
<div class="section-title"><?= $isCompteRendu ? 'Compte rendu — Évaluations' : 'Échanges validés' ?></div>
<div id="reponses-container">
    <?php if (empty($reponses)): ?>
        <div class="empty-state" id="empty-reponses">
            Les réponses validées apparaîtront ici au fil de l'entretien.
        </div>
    <?php else: ?>
        <?php foreach ($reponses as $rep): ?>
        <div class="reponse-card" data-critere="<?= (int)$rep['critere_id'] ?>" id="card-<?= (int)$rep['critere_id'] ?>">
            <div class="card-header">
                <span class="critere-label">Critère <?= (int)$rep['critere_id'] ?></span>
                <span class="badge badge-rubrique">Rubrique <?= (int)$rep['rubrique_id'] ?></span>
                <?php if ($rep['validee_collaborateur']): ?>
                    <span class="badge badge-valid">✓ Validé</span>
                <?php endif; ?>
                <?php if ($rep['desaccord_collaborateur']): ?>
                    <span class="badge badge-desacc">⚠ Désaccord</span>
                <?php endif; ?>
            </div>
            <div class="reponse-texte"><?= h($rep['texte_final']) ?></div>
            <?php if (!empty($rep['remarque_collaborateur'])): ?>
                <div class="reponse-remarque">Votre remarque : <?= h($rep['remarque_collaborateur']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Plan d'actions -->
<?php if (!empty($actions)): ?>
<div class="section-title">Plan d'actions</div>
<div id="actions-container">
    <?php foreach ($actions as $action): ?>
    <div class="action-card" id="action-<?= (int)$action['id'] ?>">
        <div class="action-icon">📋</div>
        <div class="action-body">
            <div class="action-libelle"><?= h($action['libelle']) ?></div>
            <div class="action-meta">
                <?php if (!empty($action['responsable'])): ?>
                    Responsable : <?= h($action['responsable']) ?> &nbsp;|&nbsp;
                <?php endif; ?>
                <?php if (!empty($action['echeance'])): ?>
                    Échéance : <?= h($action['echeance']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="section-title" style="display:none" id="actions-section-title">Plan d'actions</div>
<div id="actions-container"></div>
<?php endif; ?>

<!-- ZONE INTERACTION -->
<div class="vc-interact">
    <div class="interact-title">Votre interaction sur un critère :</div>
    <div class="interact-buttons">
        <select class="critere-select" id="critere-select">
            <option value="">-- Sélectionner un critère --</option>
            <?php foreach ($reponses as $rep): ?>
            <option value="<?= (int)$rep['critere_id'] ?>">
                Critère <?= (int)$rep['critere_id'] ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button class="btn-interact btn-valider" onclick="actionCollab('valider')" id="btn-valider">
            ✓ Valider
        </button>
        <button class="btn-interact btn-desaccord" onclick="openForm('desaccord')" id="btn-desaccord">
            ⚠ Désaccord
        </button>
        <button class="btn-interact btn-remarque" onclick="openForm('remarque')" id="btn-remarque">
            + Remarque
        </button>
    </div>

    <div class="interact-form" id="form-desaccord">
        <textarea id="txt-desaccord" maxlength="200" placeholder="Expliquez votre désaccord (200 caractères max)…"></textarea>
        <div class="form-actions">
            <button class="btn-sm btn-send" onclick="actionCollab('desaccord')">Envoyer</button>
            <button class="btn-sm btn-cancel" onclick="closeForm('desaccord')">Annuler</button>
        </div>
    </div>

    <div class="interact-form" id="form-remarque">
        <textarea id="txt-remarque" maxlength="200" placeholder="Votre remarque (200 caractères max)…"></textarea>
        <div class="form-actions">
            <button class="btn-sm btn-send" onclick="actionCollab('remarque')">Envoyer</button>
            <button class="btn-sm btn-cancel" onclick="closeForm('remarque')">Annuler</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
