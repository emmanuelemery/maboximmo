<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = current_user_id();
$roleId = current_role_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// --- Chargement entretien ---
$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('Identifiant manquant.'); }

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

if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }

// Seul le collaborateur accède — ou admin/manager pour aperçu
$isCollab = ((int)$entretien['collaborateur_id'] === $userId);
$isManager = ($roleId <= 2 && ((int)$entretien['manager_id'] === $userId || $roleId === 1));

if (!$isCollab && !$isManager) {
    http_response_code(403); exit('Accès refusé.');
}

// Statut autorisé
$statutsOk = ['questionnaire_envoye', 'en_cours'];
if ($isCollab && !in_array($entretien['statut'], $statutsOk, true)) {
    if (in_array($entretien['statut'], ['termine', 'signe', 'archive'])) {
        http_response_code(403); exit('L\'auto-évaluation est clôturée. Consultez votre compte rendu.');
    }
    http_response_code(403); exit('L\'auto-évaluation n\'est pas encore disponible pour cet entretien.');
}
// Le manager, lui, peut préparer son évaluation TANT que l'entretien
// n'est pas archivé/signé — y compris avant que le collab ait démarré.
if ($isManager && !$isCollab && in_array($entretien['statut'], ['archive', 'signe'], true)) {
    http_response_code(403); exit('L\'entretien est clôturé.');
}

$alreadySubmitted = !empty($entretien['auto_eval_submitted_at']);

// --- Rubriques et critères (avec filtre visible_collaborateur) ---
$rubriques = [];
try {
    $stmtRub = $pdo->prepare("SELECT * FROM rh_entretien_rubriques WHERE actif=1 ORDER BY ordre");
    $stmtRub->execute();
    $rubRows = $stmtRub->fetchAll(PDO::FETCH_ASSOC);

    $stmtCrit = $pdo->prepare("SELECT * FROM rh_entretien_criteres WHERE actif=1 ORDER BY rubrique_id, ordre");
    $stmtCrit->execute();
    $allCrit = $stmtCrit->fetchAll(PDO::FETCH_ASSOC);

    $critParRub = [];
    foreach ($allCrit as $c) { $critParRub[(int)$c['rubrique_id']][] = $c; }

    foreach ($rubRows as $rub) {
        if (empty($rub['visible_collaborateur'])) continue; // ignorer rubriques manager_only
        $crits = array_filter($critParRub[(int)$rub['id']] ?? [], fn($c) => !empty($c['visible_collaborateur']));
        if (empty($crits)) continue;
        $rubriques[(int)$rub['ordre']] = array_merge($rub, ['criteres' => array_values($crits)]);
    }
} catch (PDOException $e) { /* fallback */ }

// Fallback si tables inexistantes
if (empty($rubriques)) {
    $rubriques = [
        2  => ['id'=>2,'nom'=>'Bilan collaborateur','ordre'=>2,'criteres'=>[
            ['id'=>4,'label'=>'Bilan général de la période','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>5,'label'=>'Réalisations principales','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>6,'label'=>'Difficultés rencontrées','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
        ]],
        4  => ['id'=>4,'nom'=>'Performance','ordre'=>4,'criteres'=>[
            ['id'=>10,'label'=>'Atteinte des objectifs','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>11,'label'=>'Qualité du travail','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>12,'label'=>'Respect des délais','type_champ'=>'etoiles','visible_collaborateur'=>1],
        ]],
        5  => ['id'=>5,'nom'=>'Comportement & Relationnel','ordre'=>5,'criteres'=>[
            ['id'=>13,'label'=>'Relationnel équipe','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>14,'label'=>'Respect des règles','type_champ'=>'etoiles','visible_collaborateur'=>1],
            ['id'=>15,'label'=>'Communication','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
        ]],
        6  => ['id'=>6,'nom'=>'Motivation','ordre'=>6,'criteres'=>[
            ['id'=>16,'label'=>'Niveau de motivation actuel','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>17,'label'=>'Sources de motivation','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
        ]],
        7  => ['id'=>7,'nom'=>'Adaptabilité','ordre'=>7,'criteres'=>[
            ['id'=>19,'label'=>'Capacité d\'adaptation au changement','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>20,'label'=>'Gestion du stress','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
            ['id'=>21,'label'=>'Polyvalence','type_champ'=>'etoiles','visible_collaborateur'=>1],
        ]],
        8  => ['id'=>8,'nom'=>'Compétences','ordre'=>8,'criteres'=>[
            ['id'=>22,'label'=>'Maîtrise du poste','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
        ]],
        10 => ['id'=>10,'nom'=>'Plan d\'action','ordre'=>10,'criteres'=>[
            ['id'=>28,'label'=>'Objectifs période suivante','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
        ]],
        11 => ['id'=>11,'nom'=>'Synthèse','ordre'=>11,'criteres'=>[
            ['id'=>31,'label'=>'Points clés retenus','type_champ'=>'etoiles_texte','visible_collaborateur'=>1],
        ]],
    ];
}

// --- Commentaires rubrique (1 par rubrique et par role) ---
// Même principe de confidentialité que les notes : un collaborateur ne
// voit jamais le commentaire manager, un manager ne voit jamais le
// commentaire collaborateur (la comparaison se fait le jour de l'entretien
// dans une autre vue).
$rubriqueComments = [
    'collab'  => [],   // [rubrique_id => texte]
    'manager' => [],
];
try {
    $stmtRC = $pdo->prepare("
        SELECT rubrique_id, commentaire_collaborateur, commentaire_manager
        FROM rh_entretien_rubriques_comments
        WHERE entretien_id = ?
    ");
    $stmtRC->execute([$entretienId]);
    foreach ($stmtRC->fetchAll(PDO::FETCH_ASSOC) as $rc) {
        $rid = (int)$rc['rubrique_id'];
        if ($isCollab && !empty($rc['commentaire_collaborateur'])) {
            $rubriqueComments['collab'][$rid] = (string)$rc['commentaire_collaborateur'];
        }
        if ($isManager && !empty($rc['commentaire_manager'])) {
            $rubriqueComments['manager'][$rid] = (string)$rc['commentaire_manager'];
        }
    }
} catch (PDOException $e) { /* table absente */ }

// --- Réponses existantes : auto-évaluation (collab) + évaluation manager ---
// IMPORTANT : les notes manager ne sont JAMAIS envoyées au front quand
// l'utilisateur courant est le collaborateur, pour garantir la confidentialité
// jusqu'au jour de l'entretien. Le filtre se fait ici au niveau PHP.
$autoEval    = [];   // note_collaborateur (visible par collab + manager)
$managerEval = [];   // note_manager       (visible UNIQUEMENT par manager)
try {
    $s = $pdo->prepare("
        SELECT rubrique_id, critere_id, note_collaborateur, note_manager
        FROM rh_entretien_reponses
        WHERE entretien_id = ?
    ");
    $s->execute([$entretienId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int)$r['rubrique_id'];
        $cid = (int)$r['critere_id'];
        if ($r['note_collaborateur'] !== null) {
            $autoEval[$rid][$cid] = (int)$r['note_collaborateur'];
        }
        // Guard confidentialité : n'alimenter $managerEval que si l'utilisateur
        // courant est le manager (ou admin). Un simple collaborateur ne doit
        // JAMAIS voir les notes manager, même via source HTML ou devtools.
        if ($isManager && $r['note_manager'] !== null) {
            $managerEval[$rid][$cid] = (int)$r['note_manager'];
        }
    }
} catch (PDOException $e) { /* colonnes pas encore créées */ }

// Descriptions courtes par critère (id => texte)
$critDescriptions = [
    // Bilan collaborateur
    4  => 'Charge de travail, équilibre personnel, satisfaction globale sur la période écoulée.',
    5  => 'Projets livrés, objectifs atteints, contributions concrètes dont vous êtes fier(e).',
    6  => 'Obstacles rencontrés, situations tendues, et comment vous les avez surmontés.',
    // Performance
    10 => 'Vos objectifs ont-ils été atteints partiellement, totalement, ou dépassés ?',
    11 => 'Rigueur, fiabilité, niveau d\'erreurs, soin apporté à vos livrables.',
    12 => 'Tenez-vous les échéances fixées ? Anticipez-vous les risques de retard ?',
    // Comportement & Relationnel
    13 => 'Ambiance avec vos collègues, entraide, gestion des désaccords au quotidien.',
    14 => 'Respect des horaires, procédures internes, règles de vie collective.',
    15 => 'Clarté de vos échanges, information des bonnes personnes, au bon moment.',
    // Motivation
    16 => 'Votre niveau d\'énergie et d\'enthousiasme aujourd\'hui dans votre poste.',
    17 => 'Ce qui vous donne envie de vous impliquer : projets, autonomie, équipe, reconnaissance…',
    // Adaptabilité
    19 => 'Réaction face aux changements d\'organisation, d\'outils ou de priorités.',
    20 => 'Maintien de l\'efficacité en période de pression, d\'incertitude ou d\'imprévu.',
    21 => 'Capacité à sortir de votre périmètre habituel quand la situation le demande.',
    // Compétences
    22 => 'Niveau de maîtrise des savoir-faire techniques et métier requis pour votre poste.',
    // Plan d'action
    28 => 'Ambitions, axes de progrès et priorités que vous souhaitez vous fixer.',
    // Synthèse
    31 => 'Les 2 ou 3 enseignements majeurs que vous retenez de cette période.',
];

// Compter total critères et remplis
$totalCriteres = 0; $critRemplis = 0;
foreach ($rubriques as $rOrdre => $r) {
    foreach ($r['criteres'] as $c) {
        if (in_array($c['type_champ'] ?? '', ['etoiles','etoiles_texte'])) {
            $totalCriteres++;
            if (isset($autoEval[$rOrdre][$c['id']])) $critRemplis++;
        }
    }
}
$pct = $totalCriteres > 0 ? round($critRemplis / $totalCriteres * 100) : 0;

$csrfToken = csrf_token();

function noteLabel(int $n): string {
    $labels = ['','1 — Très insuffisant','2 — Insuffisant','3 — Correct','4 — Bien','5 — Excellent'];
    return $labels[$n] ?? '';
}

/* ── Layout variables ── */
$layout_title   = 'Auto-évaluation — ' . h($entretien['collab_prenom'].' '.$entretien['collab_nom']);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    .ae-main {
        display: flex; flex-direction: column;
        overflow: hidden;
    }

    /* Progress strip in page-head area */
    .progress-strip {
        display: flex; align-items: center; gap: 10px;
    }
    .progress-bar-wrap {
        width: 140px; height: 5px; border-radius: 999px;
        background: var(--bg-primary);
        box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        overflow: hidden;
    }
    .progress-bar-fill {
        height: 100%; border-radius: 999px;
        background: linear-gradient(90deg, var(--btn-save-from), var(--btn-save-to));
        transition: width .4s ease;
    }
    .progress-label {
        font-family: 'DM Mono', monospace; font-size: 10px;
        color: #8a8680; white-space: nowrap; letter-spacing: 0.04em;
    }
    .save-dot {
        width: 8px; height: 8px; border-radius: 50%;
        background: #c8c4be; transition: background .3s;
    }
    .save-dot.saving { background: var(--btn-save-from); }
    .save-dot.saved  { background: #7a9060; }
    .save-dot.error  { background: #8a5040; }

    /* ── MAIN ── */
    .ae-scroll {
        flex: 1; overflow-y: auto; overflow-x: hidden;
        padding: 0 24px 40px;
    }

    /* ── Section title ── */
    .sec-head { display: flex; align-items: center; gap: 14px; margin: 0 0 16px; }
    .sec-txt {
        font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
        letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
        background: linear-gradient(180deg, #7a9060 0%, #4a6038 40%, #304828 70%, #607848 100%);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .line-l { height: 1.5px; width: 28px; flex-shrink: 0; background: linear-gradient(90deg, transparent 0%, #304828 40%, #9ab870 100%); border-radius: 2px; }
    .line-r { height: 1.5px; flex: 1; background: linear-gradient(90deg, #9ab870 0%, #607848 30%, #4a6038 55%, transparent 100%); border-radius: 2px; }

    /* ── Content max-width centré ── */
    .ae-content { max-width: 800px; margin: 0 auto; }

    /* ── Palette 5 couleurs cycliques (variables CSS cascadées aux enfants) ── */
    .ae-rubrique:nth-child(5n+1) { --rub-color:#36577d; --rub-bg:rgba(54,87,125,0.08);   --rub-shd:#bec8d4; --rub-hdr:rgba(54,87,125,0.12);  --rub-sep:rgba(54,87,125,0.15); }
    .ae-rubrique:nth-child(5n+2) { --rub-color:#7a9060; --rub-bg:rgba(122,144,96,0.09);  --rub-shd:#bec8b8; --rub-hdr:rgba(122,144,96,0.14); --rub-sep:rgba(122,144,96,0.18); }
    .ae-rubrique:nth-child(5n+3) { --rub-color:#b86c28; --rub-bg:rgba(184,108,40,0.08);  --rub-shd:#ccc0b4; --rub-hdr:rgba(184,108,40,0.12); --rub-sep:rgba(184,108,40,0.16); }
    .ae-rubrique:nth-child(5n+4) { --rub-color:#a85858; --rub-bg:rgba(168,88,88,0.08);   --rub-shd:#c8bcbc; --rub-hdr:rgba(168,88,88,0.12);  --rub-sep:rgba(168,88,88,0.15); }
    .ae-rubrique:nth-child(5n+0) { --rub-color:#7a6898; --rub-bg:rgba(122,104,152,0.08); --rub-shd:#c4bec8; --rub-hdr:rgba(122,104,152,0.12);--rub-sep:rgba(122,104,152,0.15); }

    /* Application palette */
    .ae-rubrique {
        background: color-mix(in srgb, var(--rub-color, #36577d) 8%, var(--bg-primary)) !important;
        box-shadow: 8px 8px 18px var(--rub-shd, var(--shadow-dark)), -8px -8px 18px var(--shadow-light) !important;
        border-left: 4px solid var(--rub-color, #36577d) !important;
    }
    .ae-rubrique-header {
        background: color-mix(in srgb, var(--rub-color, #36577d) 12%, var(--bg-primary)) !important;
        border-bottom-color: var(--rub-sep, rgba(196,192,186,0.3)) !important;
    }
    .ae-rubrique-header h3 { color: var(--rub-color, #1a1816) !important; }
    .ae-rubrique-badge { color: var(--rub-color, #36577d) !important; }

    /* Critères : alternance légère dans la teinte de la rubrique */
    .ae-critere:nth-child(odd)  { background: var(--rub-bg, rgba(54,87,125,0.05)); }
    .ae-critere:nth-child(even) { background: transparent; }

    /* Étoiles actives couleur de la rubrique */
    .ae-star.active {
        color: var(--rub-color, #f59e0b) !important;
        filter: drop-shadow(1px 1px 3px color-mix(in srgb, var(--rub-color, #f59e0b) 50%, transparent)) !important;
    }

    /* ── INTRO BANNER ── */
    .ae-intro {
        background: rgba(54,87,125,0.08);
        box-shadow: inset 3px 3px 10px rgba(54,87,125,0.12);
        border-left: 5px solid #36577d;
        border-radius: 16px; padding: 20px 22px;
        margin-bottom: 24px; display: flex; gap: 16px; align-items: flex-start;
    }
    .ae-intro-ico {
        width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
        background: var(--bg-primary);
        box-shadow: inset 3px 3px 6px rgba(54,87,125,0.15), inset -3px -3px 8px var(--shadow-light);
        display: flex; align-items: center; justify-content: center; font-size: 20px;
    }
    .ae-intro h2 {
        font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700;
        color: #2f587d; margin: 0 0 6px;
    }
    .ae-intro p {
        font-size: 12px; color: #4a6678; line-height: 1.6; margin: 0;
    }
    .ae-intro strong { color: #2f587d; }

    /* ── SUBMITTED BANNER ── */
    .ae-submitted-banner {
        background: rgba(122,144,96,0.1);
        box-shadow: inset 3px 3px 8px rgba(122,144,96,0.15);
        border-left: 5px solid #7a9060;
        border-radius: 16px; padding: 16px 20px;
        display: flex; align-items: center; gap: 14px;
        font-size: 13px; color: #2a4020; margin-bottom: 24px;
    }
    .ae-submitted-banner .check-ico {
        width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
        background: var(--bg-primary);
        box-shadow: inset 3px 3px 6px rgba(122,144,96,0.2), inset -3px -3px 8px var(--shadow-light);
        display: flex; align-items: center; justify-content: center; font-size: 18px;
    }

    /* ── RUBRIQUE CARD ── */
    .ae-rubrique {
        border-radius: 20px; overflow: hidden;
        margin-bottom: 16px;
        transition: box-shadow .2s;
    }
    .ae-rubrique-header {
        padding: 14px 20px;
        display: flex; align-items: center; justify-content: space-between;
        cursor: pointer; user-select: none;
        border-bottom: 1px solid rgba(196,192,186,0.3);
    }
    .ae-rubrique.collapsed .ae-rubrique-header { border-bottom: none; }
    .ae-rubrique-header h3 {
        margin: 0; font-family: 'Sora', sans-serif;
        font-size: 13px; font-weight: 700; color: #1a1816;
    }
    .ae-rubrique-chevron {
        font-family: 'DM Mono', monospace; font-size: 11px;
        color: #8a8680; transition: transform .2s;
    }
    .ae-rubrique.collapsed .ae-rubrique-chevron { transform: rotate(-90deg); }
    .ae-rubrique.collapsed .ae-rubrique-body { display: none; }

    .ae-rubrique-badge {
        font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
        padding: 3px 9px; border-radius: 999px; letter-spacing: 0.08em;
        background: rgba(255,255,255,0.45);
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.08), inset -2px -2px 5px rgba(255,255,255,0.6);
    }
    .ae-rubrique-badge.done { opacity: 0.75; }

    /* ── CRITÈRE ── */
    .ae-critere {
        padding: 16px 20px;
        border-bottom: 1px solid rgba(196,192,186,0.2);
    }
    .ae-critere:last-child { border-bottom: none; }
    .ae-critere-label {
        font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
        color: #1a1816; margin-bottom: 12px;
    }
    .ae-critere-desc {
        font-family: 'DM Mono', monospace;
        font-size: 10px;
        font-style: italic;
        color: #a8a49e;
        font-weight: 400;
        letter-spacing: 0.03em;
        margin-top: 4px;
        line-height: 1.55;
    }

    /* ── ÉTOILES ── */
    .ae-stars { display: flex; gap: 6px; margin-bottom: 6px; align-items: center; }
    .ae-star {
        font-size: 28px; background: none; border: none;
        cursor: pointer; color: #ffffff;
        transition: color .15s, transform .1s;
        line-height: 1; padding: 0;
        filter: drop-shadow(1px 1px 2px var(--shadow-dark)) drop-shadow(-1px -1px 3px var(--shadow-light));
    }
    /* .ae-star.active couleur gérée par --rub-color via palette */
    .ae-star:hover { transform: scale(1.18); }
    .ae-star-hint {
        font-family: 'DM Mono', monospace; font-size: 9px;
        color: #a8a49e; min-height: 14px; letter-spacing: 0.06em;
    }

    /* ── SUBMIT ── */
    .ae-submit-wrap { padding: 28px 0 8px; display: flex; justify-content: center; }
    .ae-submit-card {
        background: var(--bg-primary);
        box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
        border-radius: 20px; padding: 28px 48px;
        display: flex; flex-direction: column; align-items: center; gap: 14px;
        text-align: center; width: fit-content;
    }
    .btn-ae-submit {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 0 32px; height: 46px; border-radius: 999px; border: none;
        font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700;
        cursor: pointer; transition: box-shadow .2s;
        background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to)); color: #fff;
        box-shadow: 5px 5px 12px rgba(249,115,22,0.4), -2px -2px 7px rgba(255,255,255,0.5);
    }
    .btn-ae-submit:hover { box-shadow: 6px 6px 16px rgba(249,115,22,0.5), -2px -2px 7px rgba(255,255,255,0.6); }
    .btn-ae-submit:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
    .ae-submit-hint {
        font-family: 'DM Mono', monospace; font-size: 9px;
        color: #a8a49e; letter-spacing: 0.06em; line-height: 1.5;
    }
    .ae-manager-note {
        font-family: 'DM Mono', monospace; font-size: 10px;
        color: #8a8680; letter-spacing: 0.06em;
    }

    /* READ-ONLY — bloque uniquement les étoiles du scope collab.
       Les étoiles manager restent interactives même quand l'auto-éval du
       collab est soumise, pour que le manager puisse continuer à préparer
       son évaluation jusqu'à l'entretien. */
    .ae-readonly .ae-stars[data-scope="collab"] .ae-star { pointer-events: none; }
    .ae-readonly .ae-stars[data-scope="collab"] .ae-star:hover { transform: none; }

    /* Vue manager : les étoiles collab sont NON éditables (lecture seule).
       Le manager ne doit jamais pouvoir modifier l'auto-évaluation du
       collaborateur, même si elle n'est pas encore soumise. */
    .ae-mgr-view .ae-stars[data-scope="collab"] .ae-star {
        pointer-events: none;
        cursor: default;
    }
    .ae-mgr-view .ae-stars[data-scope="collab"] .ae-star:hover { transform: none; }
    .ae-collab-readonly-label {
        font-family: 'DM Mono', monospace;
        font-size: 9px;
        color: #a8a49e;
        letter-spacing: 0.06em;
        margin-bottom: 4px;
    }

    /* ══════════════════════════════════════════════════════════
       Bloc manager — évaluation confidentielle (visible manager only)
       Rendu UNIQUEMENT quand $isManager, invisible pour le collab.
       ══════════════════════════════════════════════════════════ */
    .ae-manager-eval {
        margin-top: 10px;
        padding: 10px 12px 8px;
        border-radius: 12px;
        background: rgba(106,76,168,0.08);
        border-left: 3px solid #6a4ca8;
        box-shadow: inset 2px 2px 6px rgba(106,76,168,0.10), inset -2px -2px 6px rgba(255,255,255,0.5);
    }
    .ae-manager-eval-label {
        display: flex; align-items: center; gap: 8px;
        font-family: 'DM Mono', monospace;
        font-size: 9px; font-weight: 700;
        color: #6a4ca8; letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 6px;
    }
    .ae-manager-eval-label .lock-ico {
        font-size: 11px;
    }
    .ae-manager-eval .ae-stars { margin-bottom: 4px; }
    /* Les étoiles manager ont leur propre couleur (violet), indépendante
       de la couleur cyclique de la rubrique, pour une distinction claire. */
    .ae-manager-eval .ae-star {
        font-size: 22px;   /* un poil plus petit que le collab */
        color: #ffffff;
    }
    .ae-manager-eval .ae-star.active {
        color: #6a4ca8 !important;
        filter: drop-shadow(1px 1px 3px rgba(106,76,168,0.5)) !important;
    }
    .ae-manager-eval .ae-star:hover { transform: scale(1.15); }
    .ae-manager-eval .ae-star-hint {
        font-size: 9px;
        color: #8a7aa8;
    }

    /* ══════════════════════════════════════════════════════════
       Commentaires RUBRIQUE (1 par rubrique, 1 par rôle)
       ══════════════════════════════════════════════════════════ */
    .ae-rub-comments {
        padding: 16px 20px 18px;
        border-top: 1px solid rgba(196,192,186,0.2);
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .ae-rub-comment {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .ae-rub-comment-label {
        font-family: 'DM Mono', monospace;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .ae-rub-comment textarea {
        width: 100%;
        min-height: 64px;
        max-height: 240px;
        padding: 10px 12px;
        border-radius: 10px;
        border: none;
        font-family: 'Sora', sans-serif;
        font-size: 12px;
        line-height: 1.5;
        color: #2a2622;
        resize: vertical;
        outline: none;
        transition: box-shadow .2s;
    }
    .ae-rub-comment textarea:focus {
        box-shadow: inset 3px 3px 7px rgba(0,0,0,0.12),
                    inset -3px -3px 7px rgba(255,255,255,0.6),
                    0 0 0 2px rgba(54,87,125,0.2);
    }
    /* Textarea COLLAB : fond neutre (couleur de la rubrique en alpha faible) */
    .ae-rub-comment-collab textarea {
        background: color-mix(in srgb, var(--rub-color, #36577d) 6%, var(--bg-primary));
        box-shadow: inset 3px 3px 7px rgba(0,0,0,0.08),
                    inset -3px -3px 7px rgba(255,255,255,0.6);
    }
    .ae-rub-comment-collab .ae-rub-comment-label {
        color: var(--rub-color, #36577d);
    }
    /* Textarea MANAGER : fond violet, accord avec les étoiles manager */
    .ae-rub-comment-manager textarea {
        background: rgba(106,76,168,0.06);
        box-shadow: inset 3px 3px 7px rgba(106,76,168,0.14),
                    inset -3px -3px 7px rgba(255,255,255,0.55);
    }
    .ae-rub-comment-manager .ae-rub-comment-label {
        color: #6a4ca8;
    }
    .ae-rub-comment-saving {
        font-family: 'DM Mono', monospace;
        font-size: 9px;
        color: #a8a49e;
        margin-left: auto;
    }
    .ae-rub-comment-saving.saving { color: #b86c28; }
    .ae-rub-comment-saving.saved  { color: #7a9060; }
    .ae-rub-comment-saving.error  { color: #a85858; }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const ENTRETIEN_ID = document.getElementById('rubriquesWrap')?.dataset.entretienId || 0;
const CSRF        = document.querySelector('meta[name="csrf-token"]')?.content || '';
let   totalCrit   = parseInt(document.getElementById('rubriquesWrap')?.dataset.totalCrit || 0);
let   remplis     = parseInt(document.getElementById('rubriquesWrap')?.dataset.remplis || 0);

const STAR_LABELS = ['', '1 — Très insuffisant', '2 — Insuffisant', '3 — Correct', '4 — Bien', '5 — Excellent'];

function noteLabel(n) { return STAR_LABELS[n] || ''; }

// Collapse / expand rubriques
function toggleRubrique(rOrdre) {
    document.getElementById('rub_' + rOrdre)?.classList.toggle('collapsed');
}

// Clic sur une étoile — collab ou manager, détecté via data-scope du container
async function setAutoEvalStar(btn) {
    const container = btn.closest('.ae-stars');
    const rub   = parseInt(container.dataset.rub);
    const crit  = parseInt(container.dataset.crit);
    const scope = container.dataset.scope === 'manager' ? 'manager' : 'collab';
    const val   = parseInt(btn.dataset.val);
    const prev  = parseInt(container.dataset.note) || 0;

    const newVal = (prev === val) ? 0 : val; // deuxième clic = désélectionner

    // Mise à jour visuelle locale
    container.dataset.note = newVal;
    container.querySelectorAll('.ae-star').forEach(s => {
        s.classList.toggle('active', parseInt(s.dataset.val) <= newVal);
    });
    const hintId = (scope === 'manager' ? 'hint_mgr_' : 'hint_') + rub + '_' + crit;
    const hintEl = document.getElementById(hintId);
    if (hintEl) hintEl.textContent = newVal > 0 ? STAR_LABELS[newVal] : '';

    // Compteur global : UNIQUEMENT pour le scope collab (le compteur de
    // progression de la page reflète l'auto-évaluation du collaborateur).
    // Le scope manager a sa propre progression calculée à part.
    if (scope === 'collab') {
        const wasSet = (prev > 0);
        const isSet  = (newVal > 0);
        if (!wasSet && isSet) remplis++;
        if (wasSet  && !isSet) remplis = Math.max(0, remplis - 1);
        updateProgress();
        updateRubBadge(rub);
    }

    await saveNote(rub, crit, newVal || null, scope);
}

async function saveNote(rubriqueId, critereId, note, scope = 'collab') {
    const dot = document.getElementById('saveDot');
    dot.className = 'save-dot saving';
    try {
        const r = await fetch('api/rh_entretien_save_auto_eval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({
                entretien_id: ENTRETIEN_ID,
                rubrique_id:  rubriqueId,
                critere_id:   critereId,
                note,
                scope
            })
        });
        const d = await r.json();
        dot.className = d.ok ? 'save-dot saved' : 'save-dot error';
        setTimeout(() => dot.className = 'save-dot', 2000);
    } catch(e) {
        dot.className = 'save-dot error';
    }
}

function updateProgress() {
    const pct = totalCrit > 0 ? Math.round(remplis / totalCrit * 100) : 0;
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressLabel').textContent = remplis + ' / ' + totalCrit + ' critères';
}

function updateRubBadge(rOrdre) {
    const rubEl = document.getElementById('rub_' + rOrdre);
    if (!rubEl) return;
    // Filtre : uniquement les étoiles collab (scope="collab"). Le badge ne
    // compte pas les étoiles manager qui cohabitent dans la même rubrique
    // en mode manager — elles ont leur propre feedback visuel.
    const stars = rubEl.querySelectorAll('.ae-stars[data-scope="collab"]');
    let done = 0;
    stars.forEach(s => { if (parseInt(s.dataset.note) > 0) done++; });
    const badge = document.getElementById('rubBadge_' + rOrdre);
    if (badge) {
        badge.textContent = done + '/' + stars.length;
        badge.className = 'ae-rubrique-badge' + (done === stars.length && stars.length > 0 ? ' done' : '');
    }
}

// ══════════════════════════════════════════════════════════
// Commentaires rubrique — auto-save debouncé
// Chaque textarea [data-rub-comment] est lié à une rubrique + un scope
// (collab ou manager). On debounce 600ms après la dernière frappe.
// ══════════════════════════════════════════════════════════
const rcTimers = new Map(); // clé = "scope_rub" → timer

function rcKey(scope, rub) { return scope + '_' + rub; }

function rcSetStatus(scope, rub, status) {
    const el = document.getElementById('rcSave_' + scope + '_' + rub);
    if (!el) return;
    el.className = 'ae-rub-comment-saving' + (status ? ' ' + status : '');
    if (status === 'saving') el.textContent = '… enregistrement';
    else if (status === 'saved') el.textContent = '✓ enregistré';
    else if (status === 'error') el.textContent = '⚠ erreur';
    else el.textContent = '';
}

async function saveRubriqueComment(rubId, scope, texte) {
    rcSetStatus(scope, rubId, 'saving');
    try {
        const r = await fetch('api/rh_entretien_save_auto_eval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({
                entretien_id: ENTRETIEN_ID,
                rubrique_id:  rubId,
                scope:        scope,
                rubrique_commentaire: texte
            })
        });
        const d = await r.json();
        rcSetStatus(scope, rubId, d.ok ? 'saved' : 'error');
        setTimeout(() => rcSetStatus(scope, rubId, ''), 2500);
    } catch(e) {
        rcSetStatus(scope, rubId, 'error');
    }
}

document.addEventListener('input', (ev) => {
    const ta = ev.target.closest('[data-rub-comment]');
    if (!ta || ta.readOnly) return;
    const rub   = parseInt(ta.dataset.rub);
    const scope = ta.dataset.scope === 'manager' ? 'manager' : 'collab';
    const key   = rcKey(scope, rub);
    if (rcTimers.has(key)) clearTimeout(rcTimers.get(key));
    rcTimers.set(key, setTimeout(() => {
        saveRubriqueComment(rub, scope, ta.value);
    }, 600));
});

async function submitAutoEval() {
    if (remplis < totalCrit) {
        const ok = confirm(`Il reste ${totalCrit - remplis} critère(s) non évalué(s). Soumettre quand même ?`);
        if (!ok) return;
    }
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true; btn.textContent = '⏳ Envoi…';
    try {
        const r = await fetch('api/rh_entretien_save_auto_eval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ entretien_id: ENTRETIEN_ID, submit: true })
        });
        const d = await r.json();
        if (d.ok) {
            location.reload();
        } else {
            alert('Erreur : ' + (d.error || 'inconnue'));
            btn.disabled = false; btn.textContent = '✅ Soumettre mon auto-évaluation';
        }
    } catch(e) {
        alert('Erreur réseau.'); btn.disabled = false; btn.textContent = '✅ Soumettre mon auto-évaluation';
    }
}
</script>
EXTRAJS;

/* ── Build page content ── */
ob_start();
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="ae-main">
    <div class="ae-scroll">
    <div class="ae-content">

        <!-- Progress strip -->
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:16px;">
            <div class="progress-strip">
                <div class="progress-bar-wrap">
                    <div class="progress-bar-fill" id="progressFill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="progress-label" id="progressLabel"><?= $critRemplis ?> / <?= $totalCriteres ?> critères</div>
                <div class="save-dot" id="saveDot"></div>
            </div>
        </div>

        <!-- Intro / Soumis -->
        <?php if ($isManager && !$isCollab): ?>
        <div class="ae-intro" style="background:rgba(106,76,168,0.07);box-shadow:inset 3px 3px 10px rgba(106,76,168,0.12);border-left-color:#6a4ca8;">
            <div class="ae-intro-ico" style="box-shadow:inset 3px 3px 6px rgba(106,76,168,0.15), inset -3px -3px 8px var(--shadow-light);">🔒</div>
            <div>
                <h2 style="color:#6a4ca8;">Vue manager — Préparation de l'entretien</h2>
                <p style="color:#5a4a78;">
                    L'auto-évaluation du collaborateur est affichée en <strong>lecture seule</strong> — vous ne pouvez pas la modifier. Utilisez les étoiles violettes et le commentaire <strong>« Mon évaluation »</strong> sous chaque rubrique pour préparer votre propre évaluation. Vos notes et commentaires sont <strong>confidentiels</strong> jusqu'à l'entretien ; ils serviront à la comparaison côte-à-côte avec le collaborateur ce jour-là.
                </p>
            </div>
        </div>
        <?php elseif (!$alreadySubmitted): ?>
        <div class="ae-intro">
            <div class="ae-intro-ico">⭐</div>
            <div>
                <h2>Votre auto-évaluation</h2>
                <p>Évaluez-vous honnêtement sur chaque critère en attribuant de 1 à 5 étoiles. Vous pouvez aussi laisser un commentaire libre par rubrique. Votre manager remplira également sa propre évaluation de son côté. <strong>Vos réponses restent confidentielles jusqu'au jour de l'entretien</strong>, où la comparaison sera faite ensemble.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="ae-submitted-banner">
            <div class="check-ico">✅</div>
            <div>
                <strong>Auto-évaluation soumise</strong> le <?= h((new DateTime($entretien['auto_eval_submitted_at']))->format('d/m/Y à H:i')) ?>.
                Vos étoiles ont été enregistrées et seront comparées avec celles de votre manager le jour de l'entretien.
            </div>
        </div>
        <?php endif; ?>

        <!-- Section title -->
        <div class="sec-head">
            <div class="line-l"></div>
            <span class="sec-txt">Critères d'évaluation</span>
            <div class="line-r"></div>
        </div>

        <!-- Rubriques -->
        <?php
          $wrapClasses = [];
          if ($alreadySubmitted)       $wrapClasses[] = 'ae-readonly';
          if ($isManager && !$isCollab) $wrapClasses[] = 'ae-mgr-view';
        ?>
        <div id="rubriquesWrap" class="<?= h(implode(' ', $wrapClasses)) ?>"
             data-entretien-id="<?= $entretienId ?>"
             data-total-crit="<?= $totalCriteres ?>"
             data-remplis="<?= $critRemplis ?>">
        <?php foreach ($rubriques as $rOrdre => $r): ?>
            <?php
            $rubDone = 0; $rubTotal = 0;
            foreach ($r['criteres'] as $c) {
                if (in_array($c['type_champ'] ?? '', ['etoiles','etoiles_texte'])) {
                    $rubTotal++;
                    if (isset($autoEval[$rOrdre][$c['id']])) $rubDone++;
                }
            }
            ?>
            <div class="ae-rubrique" id="rub_<?= $rOrdre ?>">
                <div class="ae-rubrique-header" onclick="toggleRubrique(<?= $rOrdre ?>)">
                    <h3><?= h($r['nom']) ?></h3>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="ae-rubrique-badge <?= $rubDone === $rubTotal && $rubTotal > 0 ? 'done' : '' ?>"
                              id="rubBadge_<?= $rOrdre ?>"><?= $rubDone ?>/<?= $rubTotal ?></span>
                        <span class="ae-rubrique-chevron">▼</span>
                    </div>
                </div>
                <div class="ae-rubrique-body">
                    <?php foreach ($r['criteres'] as $c):
                        if (!in_array($c['type_champ'] ?? '', ['etoiles','etoiles_texte','texte'])) continue;
                        $noteActuelle  = $autoEval[$rOrdre][$c['id']] ?? 0;
                        $noteManager   = $managerEval[$rOrdre][$c['id']] ?? 0;
                    ?>
                    <div class="ae-critere" id="crit_<?= $rOrdre ?>_<?= $c['id'] ?>">
                        <div class="ae-critere-label">
                            <?= h($c['label']) ?>
                            <?php if (!empty($critDescriptions[(int)$c['id']])): ?>
                            <div class="ae-critere-desc"><?= h($critDescriptions[(int)$c['id']]) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- ── Étoiles du collaborateur (visible par tous, éditables uniquement par le collab) ── -->
                        <div class="ae-stars" data-rub="<?= $rOrdre ?>" data-crit="<?= $c['id'] ?>"
                             data-note="<?= $noteActuelle ?>" data-scope="collab">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button" class="ae-star <?= $noteActuelle >= $s ? 'active' : '' ?>"
                                    data-val="<?= $s ?>"
                                    onclick="setAutoEvalStar(this)"
                                    title="<?= $s ?> étoile<?= $s > 1 ? 's' : '' ?>">★</button>
                            <?php endfor; ?>
                        </div>
                        <div class="ae-star-hint" id="hint_<?= $rOrdre ?>_<?= $c['id'] ?>">
                            <?= $noteActuelle > 0 ? noteLabel($noteActuelle) : '' ?>
                        </div>

                        <?php if ($isManager): ?>
                        <!-- ── Évaluation manager (confidentielle — invisible pour le collab) ── -->
                        <div class="ae-manager-eval">
                            <div class="ae-manager-eval-label">
                                <span class="lock-ico">🔒</span>
                                Mon évaluation (manager — confidentielle)
                            </div>
                            <div class="ae-stars" data-rub="<?= $rOrdre ?>" data-crit="<?= $c['id'] ?>"
                                 data-note="<?= $noteManager ?>" data-scope="manager">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                <button type="button" class="ae-star <?= $noteManager >= $s ? 'active' : '' ?>"
                                        data-val="<?= $s ?>"
                                        onclick="setAutoEvalStar(this)"
                                        title="Évaluation manager — <?= $s ?> étoile<?= $s > 1 ? 's' : '' ?>">★</button>
                                <?php endfor; ?>
                            </div>
                            <div class="ae-star-hint" id="hint_mgr_<?= $rOrdre ?>_<?= $c['id'] ?>">
                                <?= $noteManager > 0 ? noteLabel($noteManager) : '' ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <!-- ══ COMMENTAIRES RUBRIQUE (1 par rôle) ══════════════════ -->
                    <div class="ae-rub-comments">
                        <?php if ($isCollab):
                            $collabText = $rubriqueComments['collab'][(int)$r['id']] ?? '';
                        ?>
                        <div class="ae-rub-comment ae-rub-comment-collab">
                            <div class="ae-rub-comment-label">
                                💬 Mon commentaire sur cette rubrique
                                <span class="ae-rub-comment-saving" id="rcSave_collab_<?= $rOrdre ?>"></span>
                            </div>
                            <textarea
                                data-rub-comment
                                data-rub="<?= (int)$r['id'] ?>"
                                data-scope="collab"
                                placeholder="Notes libres, contexte, points à aborder avec votre manager…"
                                <?= $alreadySubmitted ? 'readonly' : '' ?>><?= h($collabText) ?></textarea>
                        </div>
                        <?php endif; ?>

                        <?php if ($isManager):
                            $managerText = $rubriqueComments['manager'][(int)$r['id']] ?? '';
                        ?>
                        <div class="ae-rub-comment ae-rub-comment-manager">
                            <div class="ae-rub-comment-label">
                                🔒 Mon commentaire (manager — confidentiel)
                                <span class="ae-rub-comment-saving" id="rcSave_manager_<?= $rOrdre ?>"></span>
                            </div>
                            <textarea
                                data-rub-comment
                                data-rub="<?= (int)$r['id'] ?>"
                                data-scope="manager"
                                placeholder="Points à aborder, exemples concrets, axes d'amélioration à évoquer le jour de l'entretien…"><?= h($managerText) ?></textarea>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Bouton soumettre -->
        <?php if (!$alreadySubmitted && $isCollab): ?>
        <div class="ae-submit-wrap">
            <div class="ae-submit-card">
                <button type="button" class="btn-ae-submit" id="btnSubmit" onclick="submitAutoEval()">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Soumettre mon auto-évaluation
                </button>
                <div class="ae-submit-hint">Vous pourrez relire vos réponses après soumission, mais ne pourrez plus les modifier.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isManager):
            // Compte des notes manager déjà saisies (pour feedback dans le footer)
            $mgrTotal = 0; $mgrDone = 0;
            foreach ($rubriques as $rOrdre => $r) {
                foreach ($r['criteres'] as $c) {
                    if (in_array($c['type_champ'] ?? '', ['etoiles','etoiles_texte'])) {
                        $mgrTotal++;
                        if (isset($managerEval[$rOrdre][$c['id']])) $mgrDone++;
                    }
                }
            }
            $mgrPct = $mgrTotal > 0 ? round($mgrDone / $mgrTotal * 100) : 0;
        ?>
        <div class="ae-submit-wrap">
            <div class="ae-submit-card" style="border:1px solid rgba(106,76,168,0.25);background:rgba(106,76,168,0.04);">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">🔒</span>
                    <div style="text-align:left;">
                        <div style="font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#6a4ca8;">Votre évaluation manager</div>
                        <div class="ae-manager-note" style="color:#8a7aa8;">
                            <?= $mgrDone ?> / <?= $mgrTotal ?> critère<?= $mgrTotal > 1 ? 's' : '' ?> évalué<?= $mgrDone > 1 ? 's' : '' ?>
                            (<?= $mgrPct ?>%)
                        </div>
                    </div>
                </div>
                <div class="ae-submit-hint" style="max-width:440px;">
                    Vos notes sont <strong>confidentielles</strong> et ne seront jamais vues par le collaborateur.
                    Elles servent à préparer la comparaison le jour de l'entretien.
                    Chaque clic est sauvegardé automatiquement.
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- .ae-content -->
    </div><!-- .ae-scroll -->
</div><!-- .ae-main -->
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
