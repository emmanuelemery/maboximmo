<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_entretien_v3.php';
require_once __DIR__ . '/inc/rh_entretien_v4.php';
require_once __DIR__ . '/inc/rh_entretien_v5.php';
require_once __DIR__ . '/inc/rh_entretien_v6.php';
require_login();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// --- Chargement entretien ---
$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('Identifiant manquant.'); }

try {
    $stmt = $pdo->prepare("
        SELECT e.*,
               u.prenom AS collab_prenom, u.nom AS collab_nom,
               m.prenom AS manager_prenom, m.nom AS manager_nom
        FROM rh_entretiens e
        JOIN users u ON u.id = e.collaborateur_id
        JOIN users m ON m.id = e.manager_id
        WHERE e.id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); exit('Erreur base de données.');
}

if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }

// Vérifier que c'est bien le collaborateur de l'entretien
if ((int)$entretien['collaborateur_id'] !== $userId) {
    http_response_code(403); exit('Accès refusé : vous n\'êtes pas le collaborateur de cet entretien.');
}

// Vérifier que l'entretien n'est pas archivé/signé
if (in_array($entretien['statut'], ['archive', 'signe'], true)) {
    http_response_code(403); exit('Cet entretien est clôturé et ne peut plus être modifié.');
}

// --- Définition des 12 questions ---
const QUESTIONS = [
    1  => ['rubrique' => 'Bilan',         'question' => 'Comment évaluez-vous votre année globalement ?',               'type' => 'note_texte'],
    2  => ['rubrique' => 'Bilan',         'question' => 'Quelle est votre plus grande réussite cette période ?',         'type' => 'texte'],
    3  => ['rubrique' => 'Bilan',         'question' => 'Quelle difficulté principale avez-vous rencontrée ?',           'type' => 'texte'],
    4  => ['rubrique' => 'Performance',   'question' => 'Avez-vous atteint vos objectifs ?',                             'type' => 'radio_texte'],
    5  => ['rubrique' => 'Motivation',    'question' => 'Qu\'est-ce qui vous motive le plus dans votre poste ?',         'type' => 'texte'],
    6  => ['rubrique' => 'Motivation',    'question' => 'Qu\'est-ce qui vous freine actuellement ?',                     'type' => 'texte'],
    7  => ['rubrique' => 'Compétences',   'question' => 'Quelle compétence souhaitez-vous développer en priorité ?',     'type' => 'texte'],
    8  => ['rubrique' => 'Adaptabilité', 'question' => 'Comment avez-vous vécu les changements récents ?',              'type' => 'note_texte'],
    9  => ['rubrique' => 'Relationnel',   'question' => 'Comment se passe la collaboration avec votre équipe ?',         'type' => 'note_texte'],
    10 => ['rubrique' => 'Projection',    'question' => 'Où souhaitez-vous évoluer dans 1-2 ans ?',                      'type' => 'texte'],
    11 => ['rubrique' => 'Plan',          'question' => 'Quels objectifs vous fixez-vous pour la prochaine période ?',   'type' => 'texte'],
    12 => ['rubrique' => 'Libre',         'question' => 'Un message à partager avant l\'entretien ?',                    'type' => 'texte_optionnel'],
];

const PROPOSITIONS = [
    1  => [
        'J\'ai atteint l\'essentiel de mes objectifs et je suis satisfait(e) de mon parcours sur cette période.',
        'Ce fut une bonne année dans l\'ensemble, avec quelques points de vigilance à travailler.',
        'La période a été mitigée : de belles réussites mais aussi des difficultés importantes.',
        'Ce fut une période difficile, avec des obstacles qui ont ralenti ma progression.',
        'Je suis fier(ère) de mon évolution et du chemin parcouru cette année.',
    ],
    2  => [
        'J\'ai atteint et dépassé mes objectifs de vente / production sur la période.',
        'J\'ai réussi à monter en compétences sur un domaine clé pour mon poste.',
        'J\'ai contribué à un projet collectif important qui a eu un impact positif pour l\'équipe.',
        'J\'ai su gérer une situation difficile ou un client complexe avec professionnalisme.',
        'J\'ai amélioré significativement mon organisation et ma gestion des priorités.',
        'J\'ai pris des initiatives qui ont été reconnues et valorisées.',
    ],
    3  => [
        'La charge de travail a été trop importante sur certaines périodes, difficile à absorber.',
        'J\'ai manqué de certaines compétences ou outils pour être pleinement efficace.',
        'Des tensions relationnelles ont impacté mon confort au travail.',
        'Le manque de clarté sur les priorités m\'a parfois mis(e) en difficulté.',
        'Des changements d\'organisation ou de méthodes ont été difficiles à intégrer.',
        'Des facteurs personnels ou de santé ont affecté ma performance.',
    ],
    4  => [
        'J\'ai atteint tous mes objectifs quantitatifs et qualitatifs.',
        'J\'ai atteint la majorité de mes objectifs, certains restent en cours.',
        'Des obstacles imprévus ont limité l\'atteinte de certains objectifs.',
        'Mes objectifs n\'étaient pas suffisamment clairs ou atteignables.',
        'Je me suis concentré(e) sur la qualité plutôt que sur la quantité.',
    ],
    5  => [
        'Le challenge et la variété des missions me stimulent au quotidien.',
        'La relation et l\'ambiance avec mes collègues sont une source majeure de motivation.',
        'La reconnaissance et la valorisation de mon travail comptent beaucoup pour moi.',
        'Les perspectives d\'évolution et de développement professionnel.',
        'L\'autonomie et la confiance que mes responsables m\'accordent.',
        'Le sens de ma mission et l\'impact positif de mon travail.',
        'Les relations avec les clients et la satisfaction de les aider.',
    ],
    6  => [
        'La charge de travail trop lourde par rapport aux ressources disponibles.',
        'Le manque de reconnaissance ou de retour sur mon travail.',
        'Un manque de perspectives claires d\'évolution à court terme.',
        'Des tensions relationnelles ou un manque de cohésion d\'équipe.',
        'Des processus ou outils inadaptés qui freinent mon efficacité.',
        'Un manque de clarté sur les priorités ou les orientations stratégiques.',
        'Des missions trop répétitives ou un sentiment de stagnation.',
    ],
    7  => [
        'Les outils numériques et les nouvelles technologies liés à mon métier.',
        'La communication et la prise de parole en public.',
        'La gestion de projet et la conduite du changement.',
        'Les techniques de négociation et la relation client.',
        'Le management, l\'animation d\'équipe et le leadership.',
        'L\'organisation, la gestion des priorités et la productivité.',
        'Une compétence technique spécifique à mon poste.',
    ],
    8  => [
        'J\'ai bien accueilli les changements et m\'y suis adapté(e) rapidement.',
        'Je me suis adapté(e), même si cela a demandé un effort d\'organisation.',
        'Certains changements ont été déstabilisants, j\'ai eu besoin d\'accompagnement.',
        'J\'ai eu du mal à m\'adapter à certains changements, cela a impacté mon efficacité.',
        'Les changements ont été bien expliqués et progressifs, ce qui a facilité la transition.',
    ],
    9  => [
        'Les relations avec mon équipe sont excellentes, basées sur la confiance et l\'entraide.',
        'L\'ambiance est globalement bonne, avec quelques points de friction ponctuels.',
        'La collaboration est correcte mais pourrait être améliorée sur certains aspects.',
        'Des tensions persistent avec certains collègues, ce qui pèse sur mon quotidien.',
        'Je me sens bien intégré(e) et j\'apprécie vraiment travailler avec mon équipe.',
    ],
    10 => [
        'Je souhaite évoluer vers plus de responsabilités managériales à moyen terme.',
        'Je souhaite approfondir mon expertise technique et devenir référent(e) sur mon domaine.',
        'Je m\'envisage sur un poste similaire mais avec un périmètre élargi.',
        'Je souhaite rester sur mon poste actuel et continuer à progresser dans mes fonctions.',
        'Je n\'ai pas encore de projet précis mais je suis ouvert(e) aux opportunités.',
        'Je souhaite changer de spécialité ou explorer un autre domaine dans l\'entreprise.',
    ],
    11 => [
        'Améliorer mes résultats sur mes indicateurs principaux.',
        'Développer une nouvelle compétence clé pour mon poste.',
        'Mieux organiser mon temps et mes priorités au quotidien.',
        'Renforcer ma contribution aux projets collectifs de l\'équipe.',
        'Travailler sur mon équilibre vie professionnelle / vie personnelle.',
        'Améliorer la qualité de ma communication avec mon manager et mes collègues.',
    ],
    12 => [],
];

// --- Chargement des réponses existantes ---
$reponsesExistantes = [];
try {
    $stmtR = $pdo->prepare("SELECT question_id, note, texte, radio_choix FROM rh_entretien_questionnaire WHERE entretien_id = ?");
    $stmtR->execute([$entretienId]);
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reponsesExistantes[(int)$r['question_id']] = $r;
    }
} catch (PDOException $e) { /* table peut ne pas encore exister */ }

$success = '';
$errors  = [];

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        foreach (QUESTIONS as $qId => $q) {
            $note       = null;
            $texte      = null;
            $radioChoix = null;

            if ($q['type'] === 'note_texte') {
                $note  = isset($_POST['note_' . $qId]) ? max(1, min(5, (int)$_POST['note_' . $qId])) : null;
                $texte = trim($_POST['texte_' . $qId] ?? '');
            } elseif ($q['type'] === 'radio_texte') {
                $radioChoix = in_array($_POST['radio_' . $qId] ?? '', ['oui', 'partiellement', 'non'], true)
                              ? $_POST['radio_' . $qId]
                              : null;
                $texte = trim($_POST['texte_' . $qId] ?? '');
            } else {
                $texte = trim($_POST['texte_' . $qId] ?? '');
            }

            $stmtU = $pdo->prepare("
                INSERT INTO rh_entretien_questionnaire
                    (entretien_id, question_id, rubrique, note, texte, radio_choix, created_at, updated_at)
                VALUES
                    (:entretien_id, :question_id, :rubrique, :note, :texte, :radio_choix, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    note        = VALUES(note),
                    texte       = VALUES(texte),
                    radio_choix = VALUES(radio_choix),
                    updated_at  = NOW()
            ");
            $stmtU->execute([
                ':entretien_id' => $entretienId,
                ':question_id'  => $qId,
                ':rubrique'     => $q['rubrique'],
                ':note'         => $note,
                ':texte'        => $texte !== '' ? $texte : null,
                ':radio_choix'  => $radioChoix,
            ]);
        }

        // Mettre à jour le statut si planifié
        if ($entretien['statut'] === 'planifie') {
            $stmtS = $pdo->prepare("UPDATE rh_entretiens SET statut = 'questionnaire_envoye', updated_at = NOW() WHERE id = ?");
            $stmtS->execute([$entretienId]);
        }

        $pdo->commit();

        // V5: enfilement + traitement automatique
        try {
            if (function_exists('rh_entretien_v5_enqueue_job')) {
                rh_entretien_v5_enqueue_job($pdo, $entretienId, 'recalcul_incoherences', 'Recalcul incoherences suite questionnaire collaborateur');
            }
            if (function_exists('rh_entretien_v5_process_queue')) {
                rh_entretien_v5_process_queue($pdo, $entretienId);
            }
        } catch (Throwable $e) { /* non bloquant */ }
        $success = 'Vos réponses ont bien été enregistrées. Votre manager en a été informé.';

        // Recharger les réponses pour affichage
        $stmtR = $pdo->prepare("SELECT question_id, note, texte, radio_choix FROM rh_entretien_questionnaire WHERE entretien_id = ?");
        $stmtR->execute([$entretienId]);
        $reponsesExistantes = [];
        foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reponsesExistantes[(int)$r['question_id']] = $r;
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = 'Erreur lors de l\'enregistrement. Veuillez réessayer.';
    }
}

// --- Grouper les questions par rubrique ---
$rubriques = [];
foreach (QUESTIONS as $qId => $q) {
    $rubriques[$q['rubrique']][] = $qId;
}

// ── Layout variables ─────────────────────────────────────────────────────
$layout_title   = 'Questionnaire préalable — Entretien annuel';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    *, *::before, *::after { box-sizing: border-box; }

    .qst-header {
        background: var(--bg, #fff);
        border-bottom: 1px solid var(--stroke, #e2e8f0);
        padding: 1.25rem 2rem;
        position: sticky;
        top: 0;
        z-index: 100;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .qst-header-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--ink, #1a1a2e);
    }

    .qst-header-sub {
        font-size: .8rem;
        color: var(--muted, #64748b);
        margin-top: .1rem;
    }

    .progress-wrap {
        background: var(--bg, #fff);
        border-bottom: 1px solid var(--stroke, #e2e8f0);
        padding: .75rem 2rem;
    }

    .progress-label {
        font-size: .75rem;
        color: var(--muted, #64748b);
        margin-bottom: .4rem;
    }

    .progress-bar-outer {
        background: var(--bg-soft, #f5f6fa);
        border-radius: 99px;
        height: 8px;
        overflow: hidden;
    }

    .progress-bar-inner {
        background: var(--accent, var(--btn-save-from));
        height: 100%;
        border-radius: 99px;
        transition: width .4s ease;
    }

    .progress-steps {
        display: flex;
        gap: .25rem;
        margin-top: .5rem;
        flex-wrap: wrap;
    }

    .progress-step {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .65rem;
        font-weight: 600;
        background: var(--bg-soft, #f5f6fa);
        color: var(--muted, #64748b);
        border: 2px solid var(--stroke, #e2e8f0);
        cursor: pointer;
        transition: all .2s;
    }

    .progress-step.done   { background: #3a7a6a; color: #fff; border-color: #3a7a6a; }
    .progress-step.active { background: var(--accent, var(--btn-save-from)); color: #fff; border-color: var(--accent, var(--btn-save-from)); }

    .qst-main {
        max-width: 780px;
        margin: 2rem auto;
        padding: 0 1.25rem 4rem;
    }

    .alert {
        padding: 1rem 1.25rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        font-size: .9rem;
    }

    .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
    .alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }

    .rubrique-section {
        margin-bottom: 2rem;
    }

    .rubrique-header {
        display: flex;
        align-items: center;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .rubrique-badge {
        background: var(--accent, var(--btn-save-from));
        color: #fff;
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        padding: .2rem .65rem;
        border-radius: 99px;
    }

    .rubrique-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--ink, #1a1a2e);
    }

    .question-card {
        background: var(--bg, #fff);
        border: 1px solid var(--stroke, #e2e8f0);
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
    }

    .question-num {
        font-size: .7rem;
        font-weight: 600;
        color: var(--accent, var(--btn-save-from));
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: .35rem;
    }

    .question-text {
        font-size: .95rem;
        font-weight: 600;
        color: var(--ink, #1a1a2e);
        margin-bottom: 1rem;
        line-height: 1.5;
    }

    .optional-tag {
        font-size: .7rem;
        color: var(--muted, #64748b);
        font-weight: 400;
        font-style: italic;
    }

    .stars-wrap {
        display: flex;
        gap: .4rem;
        margin-bottom: .75rem;
    }

    .star {
        font-size: 2rem;
        cursor: pointer;
        color: #d1d5db;
        transition: color .15s, transform .1s;
        line-height: 1;
        user-select: none;
    }

    .star:hover, .star.active { color: #f59e0b; }
    .star:hover { transform: scale(1.15); }

    .stars-label {
        font-size: .75rem;
        color: var(--muted, #64748b);
        margin-bottom: .5rem;
    }

    .radio-group {
        display: flex;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: .75rem;
    }

    .radio-opt {
        display: flex;
        align-items: center;
        gap: .4rem;
        cursor: pointer;
    }

    .radio-opt input { cursor: pointer; accent-color: var(--accent, var(--btn-save-from)); }
    .radio-opt span  { font-size: .9rem; }

    .q-textarea {
        width: 100%;
        border: 1px solid var(--stroke, #e2e8f0);
        border-radius: 8px;
        padding: .75rem 1rem;
        font-size: .9rem;
        font-family: inherit;
        resize: vertical;
        min-height: 90px;
        color: var(--ink, #1a1a2e);
        background: var(--bg-soft, #f5f6fa);
        transition: border-color .2s;
    }

    .propositions-wrap {
        margin-bottom: .65rem;
    }
    .propositions-label {
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--muted, #64748b);
        margin-bottom: .45rem;
    }
    .propositions-list {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }
    .prop-chip {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .4rem .75rem;
        border-radius: 999px;
        border: 1.5px solid var(--stroke, #e2e8f0);
        background: var(--bg, #fff);
        color: var(--ink, #1a1a2e);
        font-size: .78rem;
        cursor: pointer;
        transition: all .15s;
        line-height: 1.3;
        text-align: left;
        font-family: inherit;
    }
    .prop-chip:hover {
        border-color: var(--accent, var(--btn-save-from));
        background: #fff7ed;
        color: #c2410c;
    }
    .prop-chip.selected {
        border-color: var(--accent, var(--btn-save-from));
        background: var(--accent, var(--btn-save-from));
        color: #fff;
        font-weight: 600;
    }
    .prop-chip .chip-icon { font-size: .8rem; flex-shrink: 0; }

    .q-textarea:focus {
        outline: none;
        border-color: var(--accent, var(--btn-save-from));
        background: var(--bg, #fff);
    }

    .submit-area {
        background: var(--bg, #fff);
        border: 1px solid var(--stroke, #e2e8f0);
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        margin-top: 2rem;
    }

    .submit-area p {
        color: var(--muted, #64748b);
        font-size: .875rem;
        margin-bottom: 1rem;
    }

    .btn-submit {
        background: var(--accent, var(--btn-save-from));
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: .9rem 2.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .2s;
    }

    .btn-submit:hover { opacity: .88; }

    @media (max-width: 600px) {
        .qst-header { padding: 1rem; }
        .progress-wrap { padding: .75rem 1rem; }
        .qst-main { margin: 1rem auto; padding: 0 .75rem 3rem; }
    }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
// --- Propositions cliquables ---
function insertProp(btn, taId) {
    const ta   = document.getElementById(taId);
    if (!ta) return;
    const text = btn.textContent.replace(/^\+\s*/, '').trim();
    const already = btn.classList.contains('selected');

    if (already) {
        btn.classList.remove('selected');
        btn.querySelector('.chip-icon').textContent = '+';
        const lines = ta.value.split('\n').filter(l => l.trim() !== text);
        ta.value = lines.join('\n').trim();
    } else {
        btn.classList.add('selected');
        btn.querySelector('.chip-icon').textContent = '\u2713';
        ta.value = ta.value.trim() ? ta.value.trim() + '\n' + text : text;
    }
    updateProgress();
    markStepDone(parseInt(taId.replace('texte_', '')));
    ta.focus();
}

// --- Stars ---
function setStar(qId, val) {
    document.getElementById('noteInput_' + qId).value = val;
    const stars = document.querySelectorAll('#stars_' + qId + ' .star');
    stars.forEach(s => {
        s.classList.toggle('active', parseInt(s.dataset.val) <= val);
    });
    markStepDone(qId);
}

// --- Progress bar ---
function updateProgress() {
    let done = 0;
    for (let n = 1; n <= 12; n++) {
        if (isAnswered(n)) { done++; markStepDone(n); } else { markStepPending(n); }
    }
    const pct = Math.round((done / 12) * 100);
    document.getElementById('progressBar').style.width = Math.max(pct, 2) + '%';
    document.getElementById('progressLabel').textContent = done + ' / 12 question' + (done > 1 ? 's' : '') + ' renseignée' + (done > 1 ? 's' : '');
}

function isAnswered(n) {
    const note = document.getElementById('noteInput_' + n);
    if (note && note.value) return true;
    const radio = document.querySelector('input[name="radio_' + n + '"]:checked');
    if (radio) return true;
    const ta = document.querySelector('textarea[name="texte_' + n + '"]');
    if (ta && ta.value.trim().length > 2) return true;
    if (n === 12) return true;
    return false;
}

function markStepDone(n) {
    const el = document.querySelector('.progress-step[data-q="' + n + '"]');
    if (el) { el.classList.add('done'); el.classList.remove('active'); }
}
function markStepPending(n) {
    const el = document.querySelector('.progress-step[data-q="' + n + '"]');
    if (el) { el.classList.remove('done', 'active'); }
}

function scrollToQuestion(n) {
    const el = document.getElementById('q' + n);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Dynamic updates
document.getElementById('qstForm').addEventListener('change', updateProgress);
document.getElementById('qstForm').addEventListener('input', updateProgress);

// Intersection Observer
const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const id = entry.target.id.replace('q', '');
            const n = parseInt(id);
            if (n >= 1 && n <= 12) {
                document.querySelectorAll('.progress-step').forEach(s => s.classList.remove('active'));
                const step = document.querySelector('.progress-step[data-q="' + n + '"]');
                if (step && !step.classList.contains('done')) step.classList.add('active');
                document.getElementById('progressLabel').textContent = 'Question ' + n + ' / 12';
                document.getElementById('progressBar').style.width = Math.max(Math.round((n/12)*100), 2) + '%';
            }
        }
    });
}, { threshold: 0.5 });

for (let n = 1; n <= 12; n++) {
    const qEl = document.getElementById('q' + n);
    if (qEl) observer.observe(qEl);
}

// Init
updateProgress();
</script>
EXTRAJS;

ob_start();
?>

<header class="qst-header">
    <div>
        <div class="qst-header-title">Questionnaire préalable à l'entretien annuel</div>
        <div class="qst-header-sub">
            <?= h($entretien['collab_prenom'] . ' ' . $entretien['collab_nom']) ?> &bull;
            Manager : <?= h($entretien['manager_prenom'] . ' ' . $entretien['manager_nom']) ?>
            <?php if (!empty($entretien['date_entretien'])): ?>
                &bull; Entretien prévu le <?= h(date('d/m/Y', strtotime($entretien['date_entretien']))) ?>
            <?php endif; ?>
        </div>
    </div>
    <div style="font-size:.75rem;color:var(--muted);text-align:right;">
        12 questions<br>~10 minutes
    </div>
</header>

<!-- Progress bar -->
<div class="progress-wrap" id="progressWrap">
    <div class="progress-label" id="progressLabel">Question 1 / 12</div>
    <div class="progress-bar-outer">
        <div class="progress-bar-inner" id="progressBar" style="width:8.3%"></div>
    </div>
    <div class="progress-steps" id="progressSteps">
        <?php foreach (range(1, 12) as $n): ?>
            <?php
            $cls = 'progress-step';
            if (isset($reponsesExistantes[$n])) $cls .= ' done';
            ?>
            <div class="<?= $cls ?>" data-q="<?= $n ?>" onclick="scrollToQuestion(<?= $n ?>)"><?= $n ?></div>
        <?php endforeach; ?>
    </div>
</div>

<main class="qst-main">

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <form method="post" id="qstForm">
        <?= csrf_field() ?>

        <?php
        $colors = ['Bilan'=>'#6366f1','Performance'=>'var(--btn-save-from)','Motivation'=>'#ec4899','Compétences'=>'#0ea5e9','Adaptabilité'=>'#14b8a6','Relationnel'=>'#8b5cf6','Projection'=>'#f59e0b','Plan'=>'#3a7a6a','Libre'=>'#64748b'];
        foreach ($rubriques as $rubNom => $qIds):
            $color = $colors[$rubNom] ?? 'var(--accent)';
        ?>
        <section class="rubrique-section" id="rubrique-<?= h($rubNom) ?>">
            <div class="rubrique-header">
                <span class="rubrique-badge" style="background:<?= h($color) ?>"><?= h($rubNom) ?></span>
                <span class="rubrique-title"><?= h($rubNom) ?></span>
            </div>

            <?php foreach ($qIds as $qId):
                $q   = QUESTIONS[$qId];
                $rep = $reponsesExistantes[$qId] ?? [];
            ?>
            <div class="question-card" id="q<?= $qId ?>">
                <div class="question-num">Question <?= $qId ?></div>
                <div class="question-text">
                    <?= h($q['question']) ?>
                    <?php if ($q['type'] === 'texte_optionnel'): ?>
                        <span class="optional-tag">(optionnel)</span>
                    <?php endif; ?>
                </div>

                <?php
                $props = PROPOSITIONS[$qId] ?? [];
                ?>

                <?php if ($q['type'] === 'note_texte'): ?>
                    <div class="stars-label">Votre évaluation :</div>
                    <div class="stars-wrap" id="stars_<?= $qId ?>">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="star <?= ($rep['note'] ?? 0) >= $s ? 'active' : '' ?>"
                                  data-val="<?= $s ?>"
                                  data-group="<?= $qId ?>"
                                  onclick="setStar(<?= $qId ?>, <?= $s ?>)">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="note_<?= $qId ?>" id="noteInput_<?= $qId ?>"
                           value="<?= h($rep['note'] ?? '') ?>">
                    <?php if (!empty($props)): ?>
                    <div class="propositions-wrap">
                        <div class="propositions-label">Propositions (cliquez pour compléter)</div>
                        <div class="propositions-list">
                            <?php foreach ($props as $pi => $prop): ?>
                            <button type="button" class="prop-chip"
                                    data-target="texte_<?= $qId ?>"
                                    onclick="insertProp(this, 'texte_<?= $qId ?>')">
                                <span class="chip-icon">+</span><?= h($prop) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <textarea name="texte_<?= $qId ?>" id="texte_<?= $qId ?>" class="q-textarea"
                              placeholder="Précisez votre réponse (ou cliquez une proposition ci-dessus)..." rows="3"><?= h($rep['texte'] ?? '') ?></textarea>

                <?php elseif ($q['type'] === 'radio_texte'): ?>
                    <div class="radio-group">
                        <?php foreach (['oui' => 'Oui, totalement', 'partiellement' => 'Partiellement', 'non' => 'Non'] as $val => $label): ?>
                            <label class="radio-opt">
                                <input type="radio" name="radio_<?= $qId ?>" value="<?= $val ?>"
                                       <?= ($rep['radio_choix'] ?? '') === $val ? 'checked' : '' ?>>
                                <span><?= h($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($props)): ?>
                    <div class="propositions-wrap" style="margin-top:.5rem;">
                        <div class="propositions-label">Complétez votre réponse</div>
                        <div class="propositions-list">
                            <?php foreach ($props as $prop): ?>
                            <button type="button" class="prop-chip"
                                    onclick="insertProp(this, 'texte_<?= $qId ?>')">
                                <span class="chip-icon">+</span><?= h($prop) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <textarea name="texte_<?= $qId ?>" id="texte_<?= $qId ?>" class="q-textarea"
                              placeholder="Expliquez votre réponse..." rows="3"><?= h($rep['texte'] ?? '') ?></textarea>

                <?php else: ?>
                    <?php if (!empty($props)): ?>
                    <div class="propositions-wrap">
                        <div class="propositions-label">Propositions -- cliquez pour insérer, combinez librement</div>
                        <div class="propositions-list">
                            <?php foreach ($props as $prop): ?>
                            <button type="button" class="prop-chip"
                                    onclick="insertProp(this, 'texte_<?= $qId ?>')">
                                <span class="chip-icon">+</span><?= h($prop) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <textarea name="texte_<?= $qId ?>" id="texte_<?= $qId ?>" class="q-textarea"
                              placeholder="<?= $q['type'] === 'texte_optionnel' ? 'Votre message (optionnel)...' : 'Votre réponse (ou choisissez une proposition)...' ?>"
                              rows="3"><?= h($rep['texte'] ?? '') ?></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <div class="submit-area">
            <p>Vos réponses seront transmises à votre manager avant l'entretien.<br>
               Vous pouvez revenir modifier ce questionnaire jusqu'à la veille de l'entretien.</p>
            <button type="submit" class="btn-submit">Envoyer mes réponses</button>
        </div>

    </form>
</main>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
