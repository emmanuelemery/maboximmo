<?php
/**
 * rh_dashboard_user.php — Dashboard du rôle « user » (Collaborateur).
 *
 * Point d'entrée après login pour les comptes id_role = 3.
 *
 * ORGANISATION (Phase 1) :
 *   - Modale véhicule au 1er login (si users.ik_declared_at IS NULL)
 *   - Bandeau KPI (Section C) : 4 compteurs dans le page-head
 *   - Section A : tâches permanentes dans l'ORDRE SÉQUENTIEL OBLIGATOIRE
 *       1. 📄 Documents   (toujours accessible)
 *       2. 👤 Profil      (débloqué après étape 1)
 *       3. 🏖️ Congés      (débloqué après étape 2)
 *       4. 🏢 Agence      (débloqué après étape 3)
 *     États visuels par carte :
 *       .done    → vert, check, non-interactive
 *       .active  → bleu animé, boutons actifs
 *       .locked  → gris 50%, tooltip, click bloqué
 *   - Section B : tâches mensuelles (visibles à partir du 20)
 *       Réinitialisation auto par comparaison users.monthly_flags_month
 *       avec le mois courant (pas de cron nécessaire).
 *
 * Actions POST directes :
 *   - action=valider_conges    → users.user_conges_validated_at = NOW()
 *   - action=confirmer_agence  → users.user_agence_confirmed_at = NOW()
 *   - action=declare_ik        → users.ik_enabled + ik_declared_at
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$userId = current_user_id();
$roleId = current_role_id();

// ─────────────────────────────────────────────────────────────
// Traitement POST
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'valider_conges') {
        $pdo->prepare("UPDATE users SET user_conges_validated_at = NOW() WHERE id = ?")
            ->execute([$userId]);
        $_SESSION['flash_ok'] = 'Vos congés ont été validés. Merci !';
    }
    elseif ($action === 'confirmer_agence') {
        $pdo->prepare("UPDATE users SET user_agence_confirmed_at = NOW() WHERE id = ?")
            ->execute([$userId]);
        $_SESSION['flash_ok'] = 'Les coordonnées agence ont été confirmées.';
    }
    elseif ($action === 'declare_ik') {
        // Modale véhicule : enregistre la réponse Oui/Non et fige
        // ik_declared_at pour que la modale ne réapparaisse plus.
        $ikEnabled = ((string)($_POST['ik_choice'] ?? '') === 'oui') ? 1 : 0;
        $pdo->prepare("UPDATE users SET ik_enabled = ?, ik_declared_at = NOW() WHERE id = ?")
            ->execute([$ikEnabled, $userId]);
        $_SESSION['flash_ok'] = $ikEnabled
            ? 'Véhicule personnel déclaré — les documents véhicule seront ajoutés à votre onboarding.'
            : 'Déclaration enregistrée — pas de véhicule personnel.';
    }

    header('Location: rh_dashboard_user.php');
    exit;
}

// ─────────────────────────────────────────────────────────────
// Chargement des données utilisateur
// ─────────────────────────────────────────────────────────────
$userFields = [
    'civilite','nom','prenom','email','telephone','telephone_pro',
    'date_naissance','lieu_naissance','nationalite','num_secu',
    'adresse','code_postal','ville','iban','bic',
    'contact_urgence_nom','contact_urgence_tel',
];
$sqlUser = "SELECT " . implode(',', $userFields) . ",
       user_conges_validated_at, user_agence_confirmed_at,
       ik_enabled, ik_declared_at, id_agence,
       onboarding_completed, onboarding_completed_at,
       salaire_submitted, frais_submitted, ik_submitted, monthly_flags_month
    FROM users WHERE id = ? LIMIT 1";
$stmt = $pdo->prepare($sqlUser);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$prenom = (string)($user['prenom'] ?? '');
$nom    = (string)($user['nom']    ?? '');

// ─── Reset auto des flags mensuels si le mois a changé ─────
$currentMonth = date('Y-m');
if (($user['monthly_flags_month'] ?? '') !== $currentMonth) {
    $pdo->prepare("
        UPDATE users
        SET salaire_submitted = 0, frais_submitted = 0, ik_submitted = 0,
            monthly_flags_month = ?
        WHERE id = ?
    ")->execute([$currentMonth, $userId]);
    $user['salaire_submitted'] = 0;
    $user['frais_submitted']   = 0;
    $user['ik_submitted']      = 0;
}

// ─── Calcul complétude profil (X champs / N champs) ────────
$profileTotal = count($userFields);
$profileFilled = 0;
foreach ($userFields as $f) {
    if (!empty($user[$f])) { $profileFilled++; }
}
$profilePct = $profileTotal > 0 ? (int)round(($profileFilled / $profileTotal) * 100) : 0;

// ─── Documents chargés ─────────────────────────────────────
// Source unique : rh_documents (table de référence RH collaborateur).
$stmt = $pdo->prepare("SELECT COUNT(*) FROM rh_documents WHERE id_user = ? AND actif = 1");
$stmt->execute([$userId]);
$nbDocs = (int)$stmt->fetchColumn();

// Pièces obligatoires : 5 de base + 3 si véhicule perso
$ikEnabled = (int)($user['ik_enabled'] ?? 0) === 1;
$requiredDocsCount = 5 + ($ikEnabled ? 3 : 0);

// ─── Nombre de congés depuis le 01/06/2025 ────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM conges WHERE id_user = ? AND date_debut >= '2025-06-01'");
$stmt->execute([$userId]);
$nbConges = (int)$stmt->fetchColumn();

// ─── États des 4 tâches permanentes ─────────────────────────
$docsPct         = $requiredDocsCount > 0 ? (int)round(($nbDocs / $requiredDocsCount) * 100) : 0;
$taskDocsDone    = $nbDocs >= 3;                   // étape 1 — minimum 3 documents
$taskProfilDone  = $profilePct >= 75;               // étape 2 — seuil aligné sur canExitProv
$taskCongesDone  = !empty($user['user_conges_validated_at']); // étape 3
$taskAgenceDone  = !empty($user['user_agence_confirmed_at']); // étape 4

// ─── Toutes les étapes sont accessibles (plus de verrouillage séquentiel)
$stepDocs   = $taskDocsDone   ? 'done' : 'active';
$stepProfil = $taskProfilDone ? 'done' : 'active';
$stepConges = $taskCongesDone ? 'done' : 'active';
$stepAgence = $taskAgenceDone ? 'done' : 'active';

// ─── Condition de sortie des pages provisoires ─────────────
// Congés validés + agence confirmée = onboarding terminé
$canExitProv = $taskCongesDone && $taskAgenceDone;
if ($canExitProv && (int)($user['onboarding_completed'] ?? 0) !== 1) {
    $pdo->prepare("UPDATE users SET onboarding_completed = 1, onboarding_completed_at = NOW() WHERE id = ?")
        ->execute([$userId]);
    $user['onboarding_completed'] = 1;
    $_SESSION['onboarding_completed'] = 1;
}
// Sync session si déjà complété en base
if ((int)($user['onboarding_completed'] ?? 0) === 1) {
    $_SESSION['onboarding_completed'] = 1;
}

// ─── Période mensuelle (Section B visible dès le 20) ───────
$today       = new DateTimeImmutable('today');
$jourDuMois  = (int)$today->format('j');
$moisLabel   = rhdu_mois_fr($today);
$dernierJour = $today->modify('last day of this month')->format('d/m/Y');
$sectionBVisible = $jourDuMois >= 20;

$taskSalaireDone = (int)($user['salaire_submitted'] ?? 0) === 1;
$taskFraisDone   = (int)($user['frais_submitted']   ?? 0) === 1;
$taskIkDone      = (int)($user['ik_submitted']      ?? 0) === 1;

// ─── Compteurs pour bandeau ────────────────────────────────
$tasksTotal = 4;
$tasksDone  = (int)$taskDocsDone + (int)$taskProfilDone + (int)$taskCongesDone + (int)$taskAgenceDone;
if ($sectionBVisible) {
    $tasksTotal += 2 + ($ikEnabled ? 1 : 0);
    $tasksDone  += (int)$taskSalaireDone + (int)$taskFraisDone + ($ikEnabled ? (int)$taskIkDone : 0);
}
$prochaineEcheance = $sectionBVisible ? $dernierJour : '—';

$flashOk = $_SESSION['flash_ok'] ?? '';
unset($_SESSION['flash_ok']);

// ─── Modale véhicule : visible si ik_declared_at IS NULL ───
$showVehicleModal = empty($user['ik_declared_at']);

/**
 * Formatte un mois/année en français (ex: "Avril 2026").
 */
function rhdu_mois_fr(DateTimeImmutable $d): string
{
    $mois = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet',
             'Août','Septembre','Octobre','Novembre','Décembre'];
    return $mois[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

/**
 * Rend une carte de tâche avec état séquentiel.
 *
 * @param string $state 'done' | 'active' | 'locked'
 * @param string $lockedReason Texte du tooltip si locked
 */
function rhdu_render_task(
    string $icon,
    string $title,
    string $desc,
    string $linkUrl,
    string $linkLabel,
    string $state,
    string $lockedReason = '',
    string $statusLabel = '',
    ?int   $progressPct = null,
    string $extraActionHtml = ''
): string {
    $statusLabel = $statusLabel !== ''
        ? $statusLabel
        : ($state === 'done' ? 'Complété' : ($state === 'active' ? 'À faire' : 'Verrouillé'));

    $progressHtml = '';
    if ($progressPct !== null) {
        $pct = max(0, min(100, $progressPct));
        $progressHtml = '<div class="task-progress"><div class="task-progress-bar" style="width:' . $pct . '%"></div></div><div class="task-progress-lbl">' . $pct . '% complété</div>';
    }

    // Si locked : désactive le lien et les actions supplémentaires
    if ($state === 'locked') {
        $linkHtml = '<span class="task-btn primary disabled" title="' . htmlspecialchars($lockedReason, ENT_QUOTES) . '">🔒 ' . htmlspecialchars($linkLabel, ENT_QUOTES) . '</span>';
        $extraActionHtml = ''; // pas d'actions en lock
    } else {
        $linkHtml = '<a href="' . htmlspecialchars($linkUrl, ENT_QUOTES) . '" class="task-btn primary">' . htmlspecialchars($linkLabel, ENT_QUOTES) . ' →</a>';
    }

    $tooltip = $state === 'locked' ? ' title="' . htmlspecialchars($lockedReason, ENT_QUOTES) . '"' : '';

    return <<<HTML
<article class="task-card {$state}"{$tooltip}>
  <div class="task-head">
    <div class="task-icon">{$icon}</div>
    <div class="task-title">{$title}</div>
    <span class="task-status {$state}">{$statusLabel}</span>
  </div>
  <div class="task-desc">{$desc}</div>
  {$progressHtml}
  <div class="task-actions">
    {$linkHtml}
    {$extraActionHtml}
  </div>
</article>
HTML;
}

// ─────────────────────────────────────────────────────────────
// Rendu
// ─────────────────────────────────────────────────────────────
$layout_title   = 'Tableau de bord — ' . htmlspecialchars(($prenom ?: '') . ' ' . ($nom ?: ''), ENT_QUOTES, 'UTF-8');
$layout_module  = 'Mon espace · Collaborateur';
$layout_sidebar = 'sidebar_user';
$layout_hide_page_head = true;

$layout_extra_css = <<<'CSS'
<style>
/* ═══════════════════════════════════════════════════════════════════════
   DASHBOARD USER — Cartes tâches avec états séquentiels.
   Tokens MaBoxImmo : bleu pétrole #2f587d, kaki #4a6038, amande #b8c8a8.
   ═══════════════════════════════════════════════════════════════════════ */
.flash-ok {
    padding: 12px 16px; margin-bottom: 16px;
    background: #e8efe0; color: #4a6038;
    border-radius: 12px;
    box-shadow: inset 2px 2px 5px rgba(180,190,170,0.5), inset -2px -2px 5px #fff;
    font-size: 13px; font-weight: 600;
}

.tasks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

/* ── Carte de base ── */
.task-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 18px 20px;
    display: flex; flex-direction: column; gap: 10px;
    transition: transform .15s, box-shadow .15s, opacity .2s;
    border-left: 4px solid transparent;
    position: relative;
}

/* ── État DONE : complété, vert, check ── */
.task-card.done {
    border-left-color: #4a6038;
    background: linear-gradient(90deg, #e8efe0 0%, #ffffff 40%);
}
.task-card.done .task-title {
    text-decoration: line-through;
    text-decoration-color: rgba(74,96,56,0.4);
    color: #4a6038;
}
.task-card.done::after {
    content: '✓';
    position: absolute; top: 14px; right: 18px;
    width: 26px; height: 26px; border-radius: 50%;
    background: #4a6038; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 800;
    box-shadow: 2px 2px 5px #c8cbd2, -2px -2px 5px #fff;
}

/* ── État ACTIVE : en cours, bleu animé ── */
.task-card.active {
    border-left-color: #4878a6;
    animation: activePulse 2.5s ease-in-out infinite;
}
.task-card.active:hover {
    transform: translateY(-3px);
    box-shadow: 8px 8px 18px #c8cbd2, -8px -8px 18px #fff;
}
@keyframes activePulse {
    0%, 100% { box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff, inset 0 0 0 0 rgba(72,120,166,0); }
    50%      { box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff, inset 4px 0 0 0 rgba(72,120,166,0.25); }
}

/* ── État LOCKED : verrouillé, gris, tooltip ── */
.task-card.locked {
    border-left-color: #c8c4be;
    opacity: .5;
    cursor: not-allowed;
    filter: grayscale(30%);
}
.task-card.locked::before {
    content: '🔒';
    position: absolute; top: 14px; right: 18px;
    font-size: 18px;
}

/* ── Contenu carte ── */
.task-head { display: flex; align-items: center; gap: 12px; padding-right: 36px; }
.task-icon { font-size: 26px; line-height: 1; }
.task-title {
    flex: 1; font-size: 14px; font-weight: 700; color: #2f587d; line-height: 1.3;
}
.task-status {
    font-family: 'DM Mono', monospace; font-size: 9px;
    font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
    padding: 4px 10px; border-radius: 999px; white-space: nowrap;
    box-shadow: 2px 2px 5px rgba(180,185,175,0.4), -2px -2px 5px #fff;
}
.task-status.done   { color: #4a6038; background: #e8efe0; }
.task-status.active { color: #2f587d; background: rgba(72,120,166,0.12); }
.task-status.locked { color: #8a8680; background: #e4e6ec; }

.task-desc { font-size: 12px; color: #6a6660; line-height: 1.55; }

.task-progress {
    width: 100%; height: 8px; border-radius: 6px;
    background: #e4e6ec;
    box-shadow: inset 2px 2px 4px #d4d7de, inset -2px -2px 4px #fff;
    overflow: hidden;
}
.task-progress-bar {
    height: 100%; border-radius: 6px;
    background: linear-gradient(90deg, #4878a6 0%, #4a6038 100%);
    transition: width .35s;
}
.task-progress-lbl {
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #8a8680; letter-spacing: .04em;
}

/* ── Boutons ── */
.task-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto; padding-top: 8px; }
.task-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 7px 14px; border-radius: 8px; border: none;
    background: #ffffff;
    box-shadow: 2px 2px 5px #d4d7de, -2px -2px 5px #fff;
    font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 600;
    color: #6a6660; cursor: pointer; text-decoration: none;
    transition: box-shadow .15s;
}
.task-btn:hover { box-shadow: 3px 3px 7px #d4d7de, -3px -3px 7px #fff; color: #2f587d; }
.task-btn:active { box-shadow: inset 2px 2px 4px #d4d7de, inset -2px -2px 4px #fff; }
.task-btn.primary { background: #4878a6; color: #fff; }
.task-btn.primary:hover { opacity: .92; color: #fff; }
.task-btn.success { background: #4a6038; color: #fff; }
.task-btn.success:hover { opacity: .92; color: #fff; }
.task-btn.disabled {
    background: #e4e6ec; color: #8a8680; cursor: not-allowed;
    box-shadow: inset 1px 1px 3px #d4d7de, inset -1px -1px 3px #fff;
}
.task-btn.disabled:hover { color: #8a8680; box-shadow: inset 1px 1px 3px #d4d7de, inset -1px -1px 3px #fff; }
.inline-form { display: inline; }

.section-sub {
    font-size: 11px; color: #8a8680; margin-top: -8px; margin-bottom: 14px;
    font-style: italic;
}

/* ═══ MODALE VÉHICULE (1er login) ═══ */
.ik-modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(15, 24, 34, 0.72);
    backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999;
    animation: fadeIn .25s ease-out;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.ik-modal {
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 8px 8px 24px rgba(0,0,0,0.45), -4px -4px 14px #f7f8fa;
    padding: 32px 36px;
    max-width: 520px; width: 90%;
    text-align: center;
    animation: modalIn .35s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes modalIn {
    from { transform: scale(0.9) translateY(10px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}
.ik-modal-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: linear-gradient(135deg, #4878a6, #2f587d);
    display: flex; align-items: center; justify-content: center;
    font-size: 38px; margin: 0 auto 18px;
    box-shadow: 4px 4px 14px #c8cbd2, -4px -4px 14px #fff;
}
.ik-modal h2 {
    font-size: 19px; font-weight: 800; color: #2f587d;
    margin-bottom: 10px;
}
.ik-modal p {
    font-size: 13px; color: #6a6660; line-height: 1.6;
    margin-bottom: 22px;
}
.ik-modal-actions {
    display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
}
.ik-btn {
    padding: 12px 22px; border-radius: 12px; border: none;
    font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700;
    cursor: pointer; min-width: 200px;
    box-shadow: 3px 3px 8px #d4d7de, -3px -3px 8px #fff;
    transition: transform .15s, box-shadow .15s;
}
.ik-btn:hover { transform: translateY(-2px); }
.ik-btn:active { box-shadow: inset 2px 2px 5px #d4d7de, inset -2px -2px 5px #fff; }
.ik-btn.yes { background: #4a6038; color: #fff; }
.ik-btn.no  { background: #ffffff; color: #6a6660; }
.ik-modal-note {
    margin-top: 18px; padding-top: 14px;
    border-top: 1px solid rgba(196,192,186,0.4);
    font-size: 11px; color: #8a8680; font-style: italic;
}
</style>
CSS;

ob_start();
?>

<?php
// Bandeau d'annonce user (bienvenue MaBoxImmo + 4 étapes + félicitations 24h)
// — affiché UNIQUEMENT sur le dashboard, plus sur les autres pages user.
@include __DIR__ . '/inc/rh_user_banner.php';
?>

<?php if ($flashOk): ?>
<div class="flash-ok">✓ <?= htmlspecialchars($flashOk, ENT_QUOTES) ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     SECTION A — Tâches permanentes (ordre séquentiel obligatoire)
════════════════════════════════════════════════════════════ -->
<div class="section-header">
    <div class="section-title">
        <div class="line-l"></div>
        <div class="sec-txt">Mon onboarding · 4 étapes</div>
        <div class="line-r"></div>
    </div>
</div>

<div class="tasks-grid">
<?php

// ÉTAPE 1 — 📄 DOCUMENTS (toujours accessible)
echo rhdu_render_task(
    '📄',
    '1. Déposer vos documents obligatoires',
    "Carte d'identité, RIB, justificatif de domicile, carte vitale et mutuelle"
        . ($ikEnabled ? " — plus les documents de votre véhicule personnel." : "."),
    './rh_documents_prov.php',
    'Charger mes documents',
    $stepDocs,
    '',
    $taskDocsDone ? $nbDocs . ' / ' . $requiredDocsCount . ' documents' : ($nbDocs > 0 ? 'En cours (' . $nbDocs . '/' . $requiredDocsCount . ')' : 'À déposer')
);

// ÉTAPE 2 — 👤 PROFIL (débloqué après étape 1)
echo rhdu_render_task(
    '👤',
    '2. Compléter votre profil à 75%',
    "Les informations extraites de vos documents remplissent automatiquement votre profil.",
    './rh_profil_prov.php',
    'Compléter mon profil',
    $stepProfil,
    'Complétez d\'abord l\'étape 1 : Documents',
    $taskProfilDone ? 'Complet' : ($profilePct > 0 ? 'En cours' : 'À compléter'),
    $profilePct
);

// ÉTAPE 3 — 🏖️ CONGÉS (débloqué après étape 2)
$congesExtra = ($stepConges === 'active' && !$taskCongesDone)
    ? '<form method="post" class="inline-form">
        <input type="hidden" name="action" value="valider_conges">
        <button type="submit" class="task-btn success">✓ Je valide</button>
       </form>'
    : '';
echo rhdu_render_task(
    '🏖️',
    '3. Valider vos congés depuis le 01/06/2025',
    "Confirmez les congés enregistrés entre le 01/06/2025 et aujourd'hui ({$nbConges} entrée" . ($nbConges > 1 ? 's' : '') . ") pour partir sur des bases communes.",
    './rh_conges_historiq_prov.php',
    'Voir mon historique',
    $stepConges,
    'Complétez d\'abord l\'étape 2 : Profil',
    $taskCongesDone ? 'Validé' : 'À valider',
    null,
    $congesExtra
);

// ÉTAPE 4 — 🏢 AGENCE (débloqué après étape 3)
$agenceExtra = ($stepAgence === 'active' && !$taskAgenceDone)
    ? '<form method="post" class="inline-form">
        <input type="hidden" name="action" value="confirmer_agence">
        <button type="submit" class="task-btn success" title="Valider sans ouvrir la page">✓ Je confirme</button>
       </form>'
    : '';
echo rhdu_render_task(
    '🏢',
    '4. Vérifier les coordonnées de votre agence',
    "Confirmez ou complétez les informations de contact de votre agence.",
    './rh_agence_prov.php',
    'Voir mon agence',
    $stepAgence,
    'Complétez d\'abord l\'étape 3 : Congés',
    $taskAgenceDone ? 'Validé' : 'À vérifier',
    null,
    $agenceExtra
);
?>
</div>

<?php if ($sectionBVisible): ?>
<!-- ══════════════════════════════════════════════════════════
     SECTION B — Clôture du mois (visible à partir du 20)
════════════════════════════════════════════════════════════ -->
<div class="section-header">
    <div class="section-title">
        <div class="line-l"></div>
        <div class="sec-txt">📅 Clôture de <?= htmlspecialchars($moisLabel, ENT_QUOTES) ?></div>
        <div class="line-r"></div>
    </div>
</div>
<div class="section-sub">Ces éléments sont à soumettre avant le <?= htmlspecialchars($dernierJour, ENT_QUOTES) ?>.</div>

<div class="tasks-grid">
<?php
// Section B : toutes 'active' en permanence (pas de séquentialité mensuelle)
echo rhdu_render_task(
    '💰',
    'Valider vos éléments de salaire — ' . $moisLabel,
    "Vérifiez et confirmez vos informations de rémunération pour ce mois.",
    './rh_salaire_user.php',
    'Préparer mon salaire',
    $taskSalaireDone ? 'done' : 'active',
    '',
    $taskSalaireDone ? 'Soumis' : 'À soumettre'
);

echo rhdu_render_task(
    '🧾',
    'Déclarer vos frais — ' . $moisLabel,
    "Soumettez vos justificatifs de frais professionnels engagés ce mois-ci.",
    './rh_frais_user.php',
    'Déclarer mes frais',
    $taskFraisDone ? 'done' : 'active',
    '',
    $taskFraisDone ? 'Soumis' : 'À soumettre'
);

if ($ikEnabled) {
    echo rhdu_render_task(
        '🚗',
        'Déclarer vos indemnités kilométriques — ' . $moisLabel,
        "Renseignez vos trajets professionnels effectués en véhicule personnel.",
        './rh_ik_user.php',
        'Déclarer mes IK',
        $taskIkDone ? 'done' : 'active',
        '',
        $taskIkDone ? 'Déclaré' : 'À déclarer'
    );
}
?>
</div>
<?php endif; ?>

<?php if ($showVehicleModal): ?>
<!-- ══════════════════════════════════════════════════════════
     MODALE VÉHICULE — affichée au 1er login uniquement
════════════════════════════════════════════════════════════ -->
<div class="ik-modal-backdrop">
    <div class="ik-modal">
        <div class="ik-modal-icon">🚗</div>
        <h2>Utilisez-vous un véhicule personnel dans le cadre de votre travail&nbsp;?</h2>
        <p>Cette information détermine vos pièces obligatoires (permis, carte grise, assurance) et vos droits aux indemnités kilométriques.</p>
        <div class="ik-modal-actions">
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="declare_ik">
                <input type="hidden" name="ik_choice" value="oui">
                <button type="submit" class="ik-btn yes">✅ Oui, j'utilise mon véhicule</button>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="declare_ik">
                <input type="hidden" name="ik_choice" value="non">
                <button type="submit" class="ik-btn no">❌ Non, pas de véhicule</button>
            </form>
        </div>
        <div class="ik-modal-note">
            Vous pourrez modifier cette déclaration plus tard depuis votre profil.
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
require __DIR__ . '/inc/layout_maboximmo.php';
