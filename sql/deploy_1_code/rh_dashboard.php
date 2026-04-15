<?php
/*
 * rh_dashboard.php  (v3 — layout_maboximmo)
 * Rôle     : Dashboard RH
 * Date     : 2026-04-10
 */
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo     = $GLOBALS['pdo'] ?? null;
$userId  = current_user_id();
$roleId  = (int)current_role_id();
$userName = ($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '');

// Détection du rôle pour afficher conditionnellement certaines actions.
// - Admin : peut déclencher l'envoi du mail solde congés, valider les congés, etc.
// - Manager : peut valider les demandes de son équipe
// - User : vue lecture sur ses propres données
// Cette page est UNIQUE pour les 3 rôles — les différences sont conditionnelles.
$isRhAdmin = ($roleId === 1) || !empty($_SESSION['super_admin']);

// ── KPIs ─────────────────────────────────────────────────────────────
try {
    $pendingLeavesCount = (int)$pdo->query("SELECT COUNT(*) FROM rh_conges WHERE statut='en_attente'")->fetchColumn();
} catch (Exception $e) { $pendingLeavesCount = 0; }
try {
    $activeStaffCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE actif=1")->fetchColumn();
} catch (Exception $e) { $activeStaffCount = 0; }
$recruitingCount = 0;
$interviewsLeft  = 0;

// ── Agenda des tâches RH ─────────────────────────────────────────────
$taskColumns = [
    'sal_conges' => [
        'label'     => 'Salaires & Congés',
        'grouped'   => true,
        'subgroups' => [
            'salaires' => [
                'label' => 'Salaires',
                'tasks' => [
                    ['icon'=>'💰','title'=>'Saisie des salaires','freq'=>'À partir du 25','desc'=>'Commencer la saisie des fiches de paie à partir du 25.','link'=>'rh_salaires.php','label'=>'Gérer','badge'=>['text'=>'→ En cours','class'=>'badge-warning']],
                    ['icon'=>'📈','title'=>'Vérification barème IK','freq'=>'Chaque juillet','desc'=>'Vérifier le barème légal des indemnités kilométriques.','link'=>'rh_salaires.php','label'=>'Voir','badge'=>['text'=>'📅 Juillet','class'=>'badge-info']],
                ]
            ],
            'conges' => [
                'label' => 'Congés',
                'tasks' => [
                    ['icon'=>'📅','title'=>'Décompte mensuel','freq'=>'Chaque mois','desc'=>'Vérifier le décompte des jours de congés par collaborateur.','link'=>'rh_conges.php','label'=>'Voir','badge'=>['text'=>'✓ Auto','class'=>'badge-success']],
                    ['icon'=>'✓','title'=>'Validation demandes','freq'=>'À tout moment','desc'=>'Examiner et valider/refuser les demandes de congés en attente.','link'=>'rh_conges_validation.php','label'=>'Valider','badge'=>['text'=>$pendingLeavesCount.' attente','class'=>'badge-warning']],
                ]
            ],
        ]
    ],
    'entretiens' => [
        'label'   => 'Entretiens',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'👤','title'=>'Entretiens annuels','freq'=>'Annuel','desc'=>'Planifier et conduire les entretiens individuels annuels.','link'=>'rh_entretien_liste.php','label'=>'Planifier','badge'=>['text'=>'📋 À planifier','class'=>'badge-neutral']],
            ['icon'=>'🗓','title'=>'Entretiens professionnels','freq'=>'Tous les 2 ans','desc'=>'Réaliser l\'entretien professionnel obligatoire.','link'=>'rh_entretien_liste.php','label'=>'Suivi','badge'=>['text'=>'⚖️ Légal','class'=>'badge-info']],
            ['icon'=>'📝','title'=>'Compte-rendu & archivage','freq'=>'Après entretien','desc'=>'Saisir et archiver le compte-rendu signé.','link'=>'rh_entretien_liste.php','label'=>'Archiver','badge'=>['text'=>'✓ Requis','class'=>'badge-success']],
        ]
    ],
    'documents' => [
        'label'   => 'Documents',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'📄','title'=>'Documents obligatoires','freq'=>'Janvier','desc'=>'Collecter carte grise, assurance, mutuelle, certificat médical.','link'=>'rh_documents.php','label'=>'Documents','badge'=>['text'=>'📅 Janvier','class'=>'badge-danger']],
            ['icon'=>'🚗','title'=>'Assurance & Carte Grise','freq'=>'Janvier (avant le 31)','desc'=>'Récupérer attestations assurance auto et cartes grises.','link'=>'rh_documents.php','label'=>'Vérifier','badge'=>['text'=>'📅 Janvier','class'=>'badge-danger']],
            ['icon'=>'⚖️','title'=>'Documents légaux','freq'=>'Suivi continu','desc'=>'S\'assurer que tous les dossiers légaux sont à jour.','link'=>'rh_documents.php','label'=>'Documents','badge'=>['text'=>'⚠️ Vérifier','class'=>'badge-danger']],
        ]
    ],
    'emails' => [
        'label'   => 'Emails',
        'grouped' => false,
        'tasks'   => [
            [
                'icon'       => '📧',
                'title'      => 'Mail solde congés',
                'freq'       => 'Fin janvier — envoi auto, vous êtes en copie',
                'desc'       => 'Envoi automatique à tous les collaborateurs actifs avec leur solde personnel. Idempotent (1 seul mail par user et par année).',
                'link'       => 'rh_mails.php',
                'label'      => 'Voir mails',
                'badge'      => ['text'=>'📅 Janvier', 'class'=>'badge-danger'],
                'admin_only' => true,   // Actions spéciales côté admin (boutons Aperçu/Tester/Envoyer)
                'actions'    => 'rh_rappel_conges',
            ],
        ]
    ],
];
// Filtrage des tasks admin_only pour les non-admins : on ne les expose même
// pas dans le HTML rendu. Les boutons sensibles sont côté serveur aussi.
if (!$isRhAdmin) {
    foreach ($taskColumns as $cKey => &$col) {
        if (!empty($col['grouped'])) {
            foreach ($col['subgroups'] as $sKey => &$sg) {
                $sg['tasks'] = array_values(array_filter($sg['tasks'] ?? [], fn($t) => empty($t['admin_only'])));
            } unset($sg);
        } else {
            $col['tasks'] = array_values(array_filter($col['tasks'] ?? [], fn($t) => empty($t['admin_only'])));
        }
    } unset($col);
}

// ── Layout variables ─────────────────────────────────────────────────
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title    = 'Dashboard RH — ' . h($_userName);
$layout_module   = 'Ma Box RH';
$layout_sidebar  = 'rh_sidebar';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.$pendingLeavesCount.'</div><div class="ph-kpi-lbl">Congés att.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.$activeStaffCount.'</div><div class="ph-kpi-lbl">Effectif</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.$recruitingCount.'</div><div class="ph-kpi-lbl">Recrut.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.$interviewsLeft.'</div><div class="ph-kpi-lbl">Entretiens</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">0</div><div class="ph-kpi-lbl">Docs exp.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">0</div><div class="ph-kpi-lbl">Mails</div></div>
';

$layout_head_actions = '
    <a href="rh_user_add.php" class="ph-btn primary" title="Collaborateur">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Collab.
    </a>
    <a href="rh_conges.php?action=new" class="ph-btn primary" title="Congé">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
        Congé
    </a>
    <a href="rh_documents.php" class="ph-btn" title="Document">Docs</a>
    <a href="rh_mails.php" class="ph-btn" title="Mail">Mails</a>
';

$layout_extra_css = '<style>
/* ── Dashboard spécifique ── */
.dashboard-row {
    display: grid;
    grid-template-columns: 1fr 1.4fr;
    gap: 28px;
    align-items: start;
}
.dashboard-col { display: flex; flex-direction: column; }

/* KPI 2x2 */
.kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 4px; }
.kpi-card {
    background: var(--bg-primary, #ffffff);
    border-radius: 16px;
    box-shadow: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #fff);
    padding: 14px 16px;
    display: flex; align-items: center; gap: 14px;
}
.kpi-bullet { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.kpi-body { flex: 1; }
.kpi-label { font-size: 12px; font-weight: 600; color: #1a1816; line-height: 1.3; }
.kpi-delta { font-family: "DM Mono", monospace; font-size: 9.5px; color: #a8a49e; margin-top: 3px; letter-spacing: .08em; }
.kpi-value { font-family: "DM Mono", monospace; font-size: 26px; font-weight: 500; flex-shrink: 0; line-height: 1; }

/* Alertes */
.alerts-list { display: flex; flex-direction: column; gap: 10px; }
.alert-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    background: var(--bg-primary, #ffffff);
    border-radius: 14px;
    box-shadow: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #fff);
    cursor: pointer; border: none; width: 100%; text-align: left;
    transition: box-shadow 0.15s; font-family: inherit;
}
.alert-item:active { box-shadow: inset 5px 5px 12px var(--shadow-dark, #d4d7de), inset -5px -5px 12px var(--shadow-light, #fff); }
.alert-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.alert-body { flex: 1; min-width: 0; }
.alert-title { font-size: 12px; font-weight: 600; color: #1a1816; line-height: 1.3; }
.alert-sub { font-size: 10.5px; color: #8a8680; margin-top: 2px; }
.alert-time { font-family: "DM Mono", monospace; font-size: 9px; color: #a8a49e; letter-spacing: .1em; flex-shrink: 0; }

/* Agenda tâches */
.agenda-header { display: flex; align-items: center; gap: 12px; margin: 28px 0 16px; }
.agenda-header .section-title { flex: 1; margin: 0; }

.tasks-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; align-items: start; }
.tasks-col { display: flex; flex-direction: column; gap: 10px; }
.tasks-subgroup { display: flex; flex-direction: column; gap: 10px; }
.tasks-subgroup-title { font-family: "DM Mono", monospace; font-size: 9px; font-weight: 500; letter-spacing: .22em; text-transform: uppercase; color: #4a6038; padding: 0 4px 6px; border-bottom: 1px solid rgba(196,192,186,0.5); }
.tasks-subgroup-sep { height: 1px; background: linear-gradient(90deg, transparent, #c8c4be 20%, #c8c4be 80%, transparent); margin: 4px 0 10px; }

.task-card {
    background: var(--bg-primary, #ffffff);
    border-radius: 20px;
    box-shadow: 8px 8px 18px var(--shadow-dark, #d4d7de), -8px -8px 18px var(--shadow-light, #fff);
    padding: 14px 16px 12px;
    display: flex; flex-direction: column; gap: 8px;
}
.task-header { display: flex; align-items: flex-start; gap: 10px; }
.task-icon-wrap {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--bg-primary, #ffffff);
    box-shadow: inset 3px 3px 8px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff);
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0; margin-top: 2px;
}
.task-title { font-size: 13px; font-weight: 600; color: #2f587d; line-height: 1.3; }
.task-freq { font-size: 10px; color: #a0a09a; margin-top: 1px; font-family: "DM Mono", monospace; letter-spacing: .06em; }
.task-divider { height: 1px; background: linear-gradient(90deg, transparent, #ccc8c2 20%, #ccc8c2 80%, transparent); }
.task-desc { font-size: 11px; color: #5a5650; line-height: 1.5; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.task-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.card-btn {
    padding: 0 12px; height: 28px; border-radius: 999px; cursor: pointer; border: none; outline: none;
    font-family: "Sora", sans-serif; font-size: 11px; letter-spacing: .06em;
    background: var(--bg-primary, #ffffff);
    box-shadow: 3px 3px 8px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
    font-weight: 500; color: #8a8680; text-decoration: none; display: inline-flex; align-items: center;
}

/* Vue liste */
.tasks-grid-list { display: none; grid-template-columns: repeat(4, 1fr); gap: 16px; align-items: start; }
.tasks-grid-list.active { display: grid; }
.tasks-grid.hidden { display: none; }
.tasks-list-col { display: flex; flex-direction: column; gap: 6px; }
.tasks-list-col-header { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .18em; color: #4a6038; font-family: "DM Mono", monospace; padding: 0 6px 8px; border-bottom: 1px solid rgba(196,192,186,0.4); margin-bottom: 4px; }
.task-row {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; min-height: 42px; border-radius: 10px;
    background: var(--bg-primary, #ffffff);
    box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff);
}
.task-row.done { opacity: 0.45; box-shadow: inset 3px 3px 7px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff); }
.task-row-icon { font-size: 16px; flex-shrink: 0; }
.task-row-title { flex: 1; font-size: 12px; font-weight: 600; color: #2f587d; line-height: 1.3; }
.task-row.done .task-row-title { text-decoration: line-through; color: #8a8680; }
.task-row .badge { font-size: 9px; padding: 2px 7px; flex-shrink: 0; }

/* Mini toggle */
.mini-toggle { display: flex; align-items: center; cursor: pointer; flex-shrink: 0; margin-left: 4px; }
.mini-toggle input { display: none; }
.mini-toggle-track {
    width: 34px; height: 18px; border-radius: 9px;
    background: var(--bg-primary, #ffffff);
    box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff);
    position: relative;
}
.mini-toggle-track::after {
    content: ""; position: absolute; width: 12px; height: 12px; border-radius: 50%;
    background: var(--bg-primary, #ffffff);
    box-shadow: 2px 2px 4px var(--shadow-dark, #d4d7de), -1px -1px 3px var(--shadow-light, #fff);
    top: 3px; left: 3px; transition: left 0.28s, background 0.25s;
}
.mini-toggle input:checked + .mini-toggle-track { background: #b2d4b7; box-shadow: inset 2px 2px 5px #8aac8f, inset -2px -2px 5px #d8f0dc; }
.mini-toggle input:checked + .mini-toggle-track::after { left: 19px; background: #4a6038; }

/* Badges tâches */
.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-family: "DM Mono", monospace; font-size: 9px; font-weight: 600; letter-spacing: .04em; }
.badge-success { background: #e0f0eb; color: #3a7a6a; }
.badge-warning { background: #f2edd8; color: #7a6830; }
.badge-danger  { background: #f5e8e3; color: #8a5040; }
.badge-info    { background: #dce8f2; color: #2f587d; }
.badge-neutral { background: #eceae6; color: #6a6660; }

@media (max-width: 1100px) {
    .dashboard-row { grid-template-columns: 1fr; }
    .tasks-grid, .tasks-grid-list { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 700px) {
    .tasks-grid, .tasks-grid-list { grid-template-columns: 1fr; }
}
</style>';

$layout_extra_js = '<script>
(function () {
    const btnCards  = document.getElementById("btn-cards");
    const btnList   = document.getElementById("btn-list");
    const viewCards = document.getElementById("view-cards");
    const viewList  = document.getElementById("view-list");

    function showCards() {
        viewCards.classList.remove("hidden");
        viewList.classList.remove("active");
        btnCards.classList.add("active"); btnCards.setAttribute("aria-pressed","true");
        btnList.classList.remove("active"); btnList.setAttribute("aria-pressed","false");
    }
    function showList() {
        viewCards.classList.add("hidden");
        viewList.classList.add("active");
        btnList.classList.add("active"); btnList.setAttribute("aria-pressed","true");
        btnCards.classList.remove("active"); btnCards.setAttribute("aria-pressed","false");
    }
    btnCards.addEventListener("click", showCards);
    btnList.addEventListener("click", showList);
    showList();

    function sortCol(col) {
        const rows = Array.from(col.querySelectorAll(".task-row"));
        const undone = rows.filter(r => r.dataset.done !== "true");
        const done = rows.filter(r => r.dataset.done === "true");
        [...undone, ...done].forEach(r => col.appendChild(r));
    }
    document.querySelectorAll(".mini-toggle input").forEach(function(cb) {
        cb.addEventListener("change", function() {
            const row = this.closest(".task-row");
            const col = this.closest(".tasks-list-col");
            row.dataset.done = this.checked ? "true" : "false";
            row.classList.toggle("done", this.checked);
            sortCol(col);
        });
    });
})();
</script>';

// ── Contenu ──────────────────────────────────────────────────────────
ob_start();
?>

<!-- BLOC 1 : KPIs + Alertes -->
<div class="dashboard-row">
    <div class="dashboard-col">
        <div class="section-header">
            <div class="section-title">
                <div class="line-l"></div>
                <span class="sec-txt">Chiffres clés</span>
                <div class="line-r"></div>
            </div>
        </div>
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-bullet" style="background:#8a5040"></div>
                <div class="kpi-body"><div class="kpi-label">Congés en attente</div><div class="kpi-delta">⚑ à valider</div></div>
                <div class="kpi-value" style="color:#8a5040"><?= $pendingLeavesCount ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-bullet" style="background:#2f587d"></div>
                <div class="kpi-body"><div class="kpi-label">Effectif actif</div><div class="kpi-delta">collaborateurs</div></div>
                <div class="kpi-value" style="color:#2f587d"><?= $activeStaffCount ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-bullet" style="background:#3a7a6a"></div>
                <div class="kpi-body"><div class="kpi-label">Recrutements</div><div class="kpi-delta">postes en cours</div></div>
                <div class="kpi-value" style="color:#3a7a6a"><?= $recruitingCount ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-bullet" style="background:#7a6830"></div>
                <div class="kpi-body"><div class="kpi-label">Entretiens restants</div><div class="kpi-delta">à planifier</div></div>
                <div class="kpi-value" style="color:#7a6830"><?= $interviewsLeft ?></div>
            </div>
        </div>
    </div>

    <div class="dashboard-col">
        <div class="section-header">
            <div class="section-title">
                <div class="line-l"></div>
                <span class="sec-txt">À traiter aujourd'hui</span>
                <div class="line-r"></div>
            </div>
        </div>
        <div class="alerts-list">
            <button class="alert-item">
                <span class="alert-dot" style="background:#8a5040"></span>
                <div class="alert-body"><div class="alert-title">Congés à valider — <?= $pendingLeavesCount ?> demandes</div><div class="alert-sub">En attente de validation</div></div>
                <span class="alert-time">urgent</span>
            </button>
            <button class="alert-item">
                <span class="alert-dot" style="background:#7a6830"></span>
                <div class="alert-body"><div class="alert-title">Entretien à tenir — cette semaine</div><div class="alert-sub">Entretien annuel à planifier</div></div>
                <span class="alert-time">J−4</span>
            </button>
            <button class="alert-item">
                <span class="alert-dot" style="background:#8a5040"></span>
                <div class="alert-body"><div class="alert-title">Document à renouveler</div><div class="alert-sub">Attestation assurance à vérifier</div></div>
                <span class="alert-time">urgent</span>
            </button>
            <button class="alert-item">
                <span class="alert-dot" style="background:#3a7a6a"></span>
                <div class="alert-body"><div class="alert-title">Fin de période d'essai</div><div class="alert-sub">Décision renouvellement à prendre</div></div>
                <span class="alert-time">J−25</span>
            </button>
        </div>
    </div>
</div>

<!-- BLOC 2 : Agenda des tâches RH -->
<div class="section-header">
    <div class="view-toggle" id="view-toggle">
        <button class="view-btn active" id="btn-list" title="Vue liste">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="1" y1="4" x2="15" y2="4"/><line x1="1" y1="8" x2="15" y2="8"/><line x1="1" y1="12" x2="15" y2="12"/></svg>
        </button>
        <button class="view-btn" id="btn-cards" title="Vue cartes">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/></svg>
        </button>
    </div>
    <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Agenda des tâches RH</span>
        <div class="line-r"></div>
    </div>
</div>

<!-- Vue CARDS -->
<div class="tasks-grid" id="view-cards">
    <?php foreach ($taskColumns as $col): ?>
    <div class="tasks-col">
        <?php if (!empty($col['grouped'])): ?>
            <?php foreach ($col['subgroups'] as $sgKey => $sg): ?>
            <div class="tasks-subgroup">
                <div class="tasks-subgroup-title"><?= htmlspecialchars($sg['label']) ?></div>
                <?php foreach ($sg['tasks'] as $task): ?>
                <article class="task-card">
                    <div class="task-header">
                        <div class="task-icon-wrap"><?= $task['icon'] ?></div>
                        <div style="flex:1"><div class="task-title"><?= htmlspecialchars($task['title']) ?></div><div class="task-freq"><?= htmlspecialchars($task['freq']) ?></div></div>
                        <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                    </div>
                    <div class="task-divider"></div>
                    <p class="task-desc"><?= htmlspecialchars($task['desc']) ?></p>
                    <div class="task-footer"><a href="<?= htmlspecialchars($task['link']) ?>" class="card-btn"><?= htmlspecialchars($task['label']) ?> →</a></div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php if ($sgKey !== array_key_last($col['subgroups'])): ?><div class="tasks-subgroup-sep"></div><?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($col['tasks'] as $task): ?>
            <article class="task-card">
                <div class="task-header">
                    <div class="task-icon-wrap"><?= $task['icon'] ?></div>
                    <div style="flex:1"><div class="task-title"><?= htmlspecialchars($task['title']) ?></div><div class="task-freq"><?= htmlspecialchars($task['freq']) ?></div></div>
                    <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                </div>
                <div class="task-divider"></div>
                <p class="task-desc"><?= htmlspecialchars($task['desc']) ?></p>
                <?php if (($task['actions'] ?? '') === 'rh_rappel_conges' && $isRhAdmin): ?>
                    <!-- Actions admin spéciales : aperçu / simulation / envoi réel.
                         Feedback via toast flottant (rhRappelToast) — voir bloc JS. -->
                    <div class="task-footer" style="gap:6px;flex-wrap:wrap;">
                        <button type="button" class="card-btn" style="background:#6a4ca8;color:#fff;border:none;cursor:pointer;" onclick="rhRappelPreview()">👁️ Aperçu</button>
                        <button type="button" class="card-btn" style="background:#7a9060;color:#fff;border:none;cursor:pointer;" onclick="rhRappelRun(true)">🧪 Tester</button>
                        <button type="button" class="card-btn" style="background:linear-gradient(135deg,#e67e22,#d35400);color:#fff;border:none;cursor:pointer;" onclick="rhRappelRun(false)">📧 Envoyer</button>
                    </div>
                <?php else: ?>
                <div class="task-footer"><a href="<?= htmlspecialchars($task['link']) ?>" class="card-btn"><?= htmlspecialchars($task['label']) ?> →</a></div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Vue LISTE -->
<div class="tasks-grid-list" id="view-list">
    <?php foreach ($taskColumns as $colKey => $col): ?>
    <div class="tasks-list-col" data-col="<?= $colKey ?>">
        <div class="tasks-list-col-header"><?= htmlspecialchars($col['label']) ?></div>
        <?php if (!empty($col['grouped'])): ?>
            <?php foreach ($col['subgroups'] as $sg): ?>
            <div style="font-family:'DM Mono',monospace;font-size:8px;letter-spacing:.18em;text-transform:uppercase;color:#a8a49e;padding:6px 6px 4px;margin-top:4px"><?= htmlspecialchars($sg['label']) ?></div>
            <?php foreach ($sg['tasks'] as $task): ?>
            <div class="task-row" data-done="false">
                <span class="task-row-icon"><?= $task['icon'] ?></span>
                <span class="task-row-title"><?= htmlspecialchars($task['title']) ?></span>
                <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                <label class="mini-toggle"><input type="checkbox"><span class="mini-toggle-track"></span></label>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($col['tasks'] as $task): ?>
            <div class="task-row" data-done="false">
                <span class="task-row-icon"><?= $task['icon'] ?></span>
                <span class="task-row-title"><?= htmlspecialchars($task['title']) ?></span>
                <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                <?php if (($task['actions'] ?? '') === 'rh_rappel_conges' && $isRhAdmin): ?>
                    <!-- Actions admin : icônes compactes pour la vue liste -->
                    <div style="display:flex;gap:4px;margin-left:auto;">
                        <button type="button" onclick="rhRappelPreview()" title="Aperçu du mail"
                                style="background:#6a4ca8;color:#fff;border:none;width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:13px;">👁️</button>
                        <button type="button" onclick="rhRappelRun(true)" title="Tester (simulation)"
                                style="background:#7a9060;color:#fff;border:none;width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:13px;">🧪</button>
                        <button type="button" onclick="rhRappelRun(false)" title="Envoyer maintenant"
                                style="background:linear-gradient(135deg,#e67e22,#d35400);color:#fff;border:none;width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:13px;">📧</button>
                    </div>
                    <?php // Le feedback est partagé avec la vue cards (même id #rhRappelResult) ?>
                <?php else: ?>
                <label class="mini-toggle"><input type="checkbox"><span class="mini-toggle-track"></span></label>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($isRhAdmin): ?>
<!-- ══ MODAL APERÇU DU MAIL DE RAPPEL CONGÉS (admin-only) ══════════════ -->
<div id="rhRappelModal" style="display:none;position:fixed;inset:0;background:rgba(26,24,22,0.6);z-index:9999;padding:30px 20px;overflow-y:auto;">
    <div style="background:#fff;max-width:780px;margin:0 auto;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.35);overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 22px;background:linear-gradient(135deg,#6a4ca8,#8a6cc8);color:#fff;">
            <div>
                <div style="font-size:16px;font-weight:700;">👁️ Aperçu du mail de rappel congés</div>
                <div style="font-size:11px;opacity:0.85;margin-top:2px;" id="rhRappelModalSub">Chargement…</div>
            </div>
            <button type="button" onclick="rhRappelCloseModal()" style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;">×</button>
        </div>
        <div style="padding:18px 22px;background:#f5f1ea;border-bottom:1px solid #e2dcd2;">
            <div style="display:grid;grid-template-columns:90px 1fr;gap:6px 10px;font-size:12px;color:#4a4640;">
                <div style="font-weight:700;color:#8a8680;">Destinataire</div><div id="rhRappelTo">—</div>
                <div style="font-weight:700;color:#8a8680;">En copie</div><div id="rhRappelCc" style="color:#6a4ca8;font-weight:600;">—</div>
                <div style="font-weight:700;color:#8a8680;">Sujet</div><div id="rhRappelSubject" style="font-weight:700;color:#2a2622;">—</div>
            </div>
            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <label style="font-size:11px;font-weight:700;color:#8a8680;text-transform:uppercase;letter-spacing:0.06em;">Aperçu pour :</label>
                <select id="rhRappelUserSelect" onchange="rhRappelPreview(parseInt(this.value))" style="flex:1;min-width:220px;padding:6px 10px;border:1px solid #d4ccc0;border-radius:6px;font-size:12px;background:#fff;cursor:pointer;">
                    <option value="">Chargement…</option>
                </select>
            </div>
        </div>
        <iframe id="rhRappelIframe" style="width:100%;min-height:560px;border:none;background:#f5f1ea;" sandbox="allow-same-origin"></iframe>
        <div style="padding:14px 22px;background:#f5f1ea;border-top:1px solid #e2dcd2;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="font-size:11px;color:#8a8680;">💡 Ceci est l'exact contenu qui sera envoyé. Chaque user recevra sa propre version avec ses soldes personnels.</div>
            <div style="display:flex;gap:8px;">
                <button type="button" onclick="rhRappelCloseModal()" style="padding:8px 16px;border-radius:8px;border:1px solid #d4ccc0;background:#fff;cursor:pointer;font-size:12px;">Fermer</button>
                <button type="button" onclick="rhRappelCloseModal();rhRappelRun(false);" style="padding:8px 16px;border-radius:8px;border:none;background:linear-gradient(135deg,#e67e22,#d35400);color:#fff;cursor:pointer;font-size:12px;font-weight:700;">📧 Envoyer à tous</button>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    const CSRF_RAPPEL = '<?= htmlspecialchars(csrf_token("rh_rappel"), ENT_QUOTES) ?>';

    window.rhRappelPreview = async function(userId) {
        const modal  = document.getElementById('rhRappelModal');
        const iframe = document.getElementById('rhRappelIframe');
        const sub    = document.getElementById('rhRappelModalSub');
        const select = document.getElementById('rhRappelUserSelect');

        modal.style.display = 'block';
        sub.textContent = 'Chargement…';
        iframe.srcdoc = '<div style="padding:40px;text-align:center;font-family:Arial;color:#8a8680;">⏳ Chargement de l\'aperçu…</div>';

        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_RAPPEL);
            fd.append('preview', '1');
            if (userId && Number.isInteger(userId) && userId > 0) fd.append('user', String(userId));

            const r = await fetch('api/rh_conges_rappel_trigger.php', { method:'POST', body:fd, credentials:'same-origin' });
            const d = await r.json();
            if (!d.ok) throw new Error(d.error || 'Aperçu indisponible');

            if (select && select.options.length <= 1) {
                select.innerHTML = '';
                (d.all_users || []).forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = String(u.id);
                    opt.textContent = u.label;
                    select.appendChild(opt);
                });
            }
            if (select) select.value = String(d.user.id);

            document.getElementById('rhRappelTo').textContent = d.to || '—';
            document.getElementById('rhRappelCc').textContent = d.cc || '(aucun CC)';
            document.getElementById('rhRappelSubject').textContent = d.subject || '—';
            sub.textContent = 'Exemple personnalisé pour ' + (d.user.nom || '?')
                + ' · solde ' + (d.user.solde_restant ?? '?') + ' j · à prendre ' + (d.user.conge_a_prendre ?? '?') + ' j';

            iframe.srcdoc = d.html;
        } catch (err) {
            iframe.srcdoc = '<div style="padding:40px;text-align:center;font-family:Arial;color:#a85858;">❌ ' + (err.message || 'Erreur') + '</div>';
            sub.textContent = '❌ ' + (err.message || 'Erreur');
        }
    };

    window.rhRappelCloseModal = function() {
        document.getElementById('rhRappelModal').style.display = 'none';
    };

    document.getElementById('rhRappelModal')?.addEventListener('click', (ev) => {
        if (ev.target.id === 'rhRappelModal') rhRappelCloseModal();
    });

    // Toast flottant pour feedback (marche en vue cards ET en vue liste)
    function rhRappelToast(msg, level) {
        const id = 'rhRappelToast';
        let el = document.getElementById(id);
        if (!el) {
            el = document.createElement('div');
            el.id = id;
            el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:10000;padding:14px 18px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.25);font-family:Sora,sans-serif;font-size:13px;font-weight:600;max-width:360px;line-height:1.4;transition:opacity .3s;';
            document.body.appendChild(el);
        }
        const colors = {
            loading: 'background:#f5f1ea;color:#8a8680;border-left:4px solid #8a8680;',
            success: 'background:#edf5e7;color:#2a4020;border-left:4px solid #7a9060;',
            error:   'background:#fef2f2;color:#7a2020;border-left:4px solid #a85858;',
        };
        el.style.cssText += colors[level] || colors.loading;
        el.innerHTML = msg;
        el.style.opacity = '1';
        if (level !== 'loading') {
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 6000);
        }
    }

    window.rhRappelRun = async function(dryRun) {
        if (!dryRun) {
            if (!confirm('Confirmer l\'envoi du mail de rappel à TOUS les collaborateurs actifs ?\n\nVous serez automatiquement mis en copie de chaque mail.\n\nIdempotence : les users déjà traités cette année ne recevront rien.')) return;
        }
        rhRappelToast('⏳ ' + (dryRun ? 'Simulation en cours…' : 'Envoi en cours…'), 'loading');
        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_RAPPEL);
            if (dryRun) fd.append('dry_run', '1');
            const r = await fetch('api/rh_conges_rappel_trigger.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const d = await r.json();
            if (!d.ok && d.errors === undefined) throw new Error(d.error || 'Erreur inconnue');
            const level = (d.errors > 0) ? 'error' : 'success';
            const icon  = dryRun ? '🧪' : '📧';
            const title = dryRun ? 'Simulation terminée' : 'Envoi terminé';
            rhRappelToast(
                icon + ' <strong>' + title + '</strong><br>'
                + (d.sent || 0) + ' mail(s) &nbsp;·&nbsp; '
                + (d.skipped || 0) + ' ignoré(s) &nbsp;·&nbsp; '
                + (d.errors || 0) + ' erreur(s)'
                + (d.cc ? '<br><span style="font-size:11px;opacity:0.75;">CC : ' + d.cc + '</span>' : ''),
                level
            );
        } catch (e) {
            rhRappelToast('❌ ' + (e.message || 'Erreur réseau'), 'error');
        }
    };
})();
</script>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
