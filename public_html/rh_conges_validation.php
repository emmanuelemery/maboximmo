<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$roleId       = current_role_id();
$userId       = current_user_id();
$userAgenceId = current_agence_id();

if (!in_array($roleId, [1, 2])) {
    deny_access('Accès réservé aux administrateurs et managers.');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Actions AJAX ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    verify_csrf_any();

    $congeId = (int)($_POST['cong_id'] ?? 0);
    $action  = $_POST['action'];

    if (!$congeId) { http_response_code(400); exit(json_encode(['success'=>false,'message'=>'ID manquant'])); }

    $stmt = $pdo->prepare("SELECT c.*, u.id_agence FROM conges c JOIN users u ON c.id_user = u.id WHERE c.id = ?");
    $stmt->execute([$congeId]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leave) { http_response_code(404); exit(json_encode(['success'=>false,'message'=>'Congé introuvable'])); }
    if ($roleId === 2 && $leave['id_agence'] !== $userAgenceId) { http_response_code(403); exit(json_encode(['success'=>false,'message'=>'Non autorisé'])); }

    if ($action === 'approve') {
        $pdo->prepare("UPDATE conges SET statut='validé', date_validation=NOW(), id_validateur=? WHERE id=?")->execute([$userId, $congeId]);
        exit(json_encode(['success'=>true,'message'=>'Congé approuvé']));
    }
    if ($action === 'reject') {
        $raison = $_POST['raison'] ?? '';
        $pdo->prepare("UPDATE conges SET statut='refusé', date_validation=NOW(), id_validateur=?, commentaire=? WHERE id=?")->execute([$userId, $raison, $congeId]);
        exit(json_encode(['success'=>true,'message'=>'Congé refusé']));
    }
    if ($action === 'edit') {
        if ($roleId !== 1) { http_response_code(403); exit(json_encode(['success'=>false,'message'=>'Admin uniquement'])); }
        $dd = $_POST['date_debut'] ?? ''; $df = $_POST['date_fin'] ?? ''; $mo = $_POST['motif'] ?? '';
        if (!$dd || !$df || !$mo) { http_response_code(400); exit(json_encode(['success'=>false,'message'=>'Données manquantes'])); }
        $pdo->prepare("UPDATE conges SET date_debut=?, date_fin=?, motif=? WHERE id=?")->execute([$dd, $df, $mo, $congeId]);
        exit(json_encode(['success'=>true,'message'=>'Congé modifié']));
    }
    if ($action === 'delete') {
        if ($roleId !== 1) { http_response_code(403); exit(json_encode(['success'=>false,'message'=>'Admin uniquement'])); }
        $pdo->prepare("UPDATE conges SET statut='archivé' WHERE id=?")->execute([$congeId]);
        exit(json_encode(['success'=>true,'message'=>'Congé supprimé']));
    }

    http_response_code(400); exit(json_encode(['success'=>false,'message'=>'Action inconnue']));
}

// ── Filtres société / agence ─────────────────────────────────────────────
$societes = [];
$allAgences = [];
if ($roleId === 1) {
    $societes   = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $allAgences = $pdo->query("SELECT id, nom_agence, id_societe FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
}

$filterSociete = ($roleId === 1 && !empty($_GET['societe']) && $_GET['societe'] !== 'toutes')
                   ? (int)$_GET['societe'] : 'toutes';
$filterAgence  = (!empty($_GET['agence']) && $_GET['agence'] !== 'toutes')
                   ? (int)$_GET['agence']  : 'toutes';

$agencesFiltered = [];
if ($filterSociete !== 'toutes') {
    foreach ($allAgences as $ag) {
        if ((int)$ag['id_societe'] === (int)$filterSociete) $agencesFiltered[] = $ag;
    }
} else {
    $agencesFiltered = $allAgences;
}

// ── Page data ────────────────────────────────────────────────────────────
$sql = "SELECT c.*, u.id AS uid, u.prenom, u.nom, u.couleur, u.id_agence,
               a.nom_agence, s.nom AS nom_societe
        FROM conges c
        JOIN users u ON c.id_user = u.id
        LEFT JOIN agences  a ON u.id_agence  = a.id
        LEFT JOIN societes s ON u.id_societe = s.id
        WHERE c.statut = 'en_attente'";
$params = [];
if ($roleId === 2 && $userAgenceId)  { $sql .= " AND u.id_agence = ?";  $params[] = $userAgenceId; }
if ($filterSociete !== 'toutes')     { $sql .= " AND u.id_societe = ?"; $params[] = $filterSociete; }
if ($filterAgence  !== 'toutes')     { $sql .= " AND u.id_agence = ?";  $params[] = $filterAgence; }
$sql .= " ORDER BY c.date_demande ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pendingLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

$motifLabels = [
    'conges_payes'                    => 'Congés payés',
    'rtt'                             => 'RTT',
    'maladie_justifiee_non_deduite'   => 'Maladie justifiée',
    'maladie_non_justifiee_deduite'   => 'Maladie non justifiée',
    'maladie_justifiee_deduite'       => 'Maladie (déduite)',
    'absence_injustifiee_deduite'     => 'Absence injustifiée',
    'absence_justifiee_non_deduite'   => 'Absence justifiée',
    'absence_justifiee_deduite_heures'=> 'Absence (heures)',
    'autre_legal_non_deduit'          => 'Autre légal',
    'autre_legal_deduit'              => 'Autre légal (déduit)',
];

$colorPalette = ['#6b9fce','#7ab08c','#c97b6e','#b08abf','#d4a95a','#5b9aaa','#9a7060','#7a8fb0'];
function getUserColor($leave, $palette) {
    if (!empty($leave['couleur'])) return $leave['couleur'];
    return $palette[($leave['uid'] ?? 0) % count($palette)];
}

// ── KPI counts ───────────────────────────────────────────────────────────
$countPending  = count($pendingLeaves);
$countToday    = 0;
$countCP       = 0;
$countRTT      = 0;
$countMaladie  = 0;
$countAutre    = 0;
foreach ($pendingLeaves as $l) {
    if (date('Y-m-d', strtotime($l['date_demande'])) === date('Y-m-d')) $countToday++;
    if ($l['motif'] === 'conges_payes') $countCP++;
    elseif ($l['motif'] === 'rtt') $countRTT++;
    elseif (str_starts_with($l['motif'], 'maladie')) $countMaladie++;
    else $countAutre++;
}

// ── Layout variables ─────────────────────────────────────────────────────
$layout_title   = 'Validation Congés';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">' . $countPending . '</div><div class="ph-kpi-lbl">En attente</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $countToday . '</div><div class="ph-kpi-lbl">Auj.</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $countCP . '</div><div class="ph-kpi-lbl">CP</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $countRTT . '</div><div class="ph-kpi-lbl">RTT</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $countMaladie . '</div><div class="ph-kpi-lbl">Maladie</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $countAutre . '</div><div class="ph-kpi-lbl">Autre</div></div>';

$layout_head_actions = '
<a class="ph-btn" href="rh_conges.php"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Congés</a>
<a class="ph-btn" href="rh_dashboard.php"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> Dashboard</a>
<a class="ph-btn" href="rh_salaires.php"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Salaires</a>
<a class="ph-btn dispo">Export</a>';

$layout_extra_css = <<<'CSS'
<style>
    meta[name="csrf-token"] { display: none; }

    /* ── Chip attente ── */
    .chip-attente {
        display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px;
        border-radius: 999px; background: rgba(122,104,48,0.12);
        font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500;
        color: #7a6830; letter-spacing: 0.06em;
    }

    /* ── Scope pills ── */
    .ph-scope { display: flex; flex-direction: column; gap: 11px; justify-content: center; min-width: 0; }
    .ph-scope-row { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
    .ph-scope-label { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.10em; color: var(--shadow-dark); width: 46px; flex-shrink: 0; text-align: right; }
    .ph-scope-btns { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .ph-scope-pill {
        height: 24px; padding: 0 12px; border-radius: 999px; background: var(--bg-primary);
        box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
        font-family: 'Sora', sans-serif; font-size: 10px; font-weight: 500; color: #8a8680;
        text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap;
        transition: box-shadow 0.12s, color 0.12s;
    }
    .ph-scope-pill:hover { color: #36577d; }
    .ph-scope-pill.active { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); color: #36577d; font-weight: 700; }

    /* ── Cartes demandes ── */
    .leaves-grid { display: flex; flex-direction: column; gap: 10px; }

    .leave-card {
        background: var(--bg-primary); border-radius: 16px;
        box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        padding: 16px 20px; display: flex; align-items: center; gap: 20px;
    }
    .leave-card:nth-child(odd)  { background: #dde4ed; }
    .leave-card:nth-child(even) { background: #e0e8dc; }

    /* Bande couleur user */
    .leave-color-bar { width: 4px; border-radius: 4px; align-self: stretch; flex-shrink: 0; min-height: 48px; }

    /* Infos */
    .leave-info { flex: 1; min-width: 0; display: grid; grid-template-columns: 1fr 1fr 1fr auto; align-items: center; gap: 16px; }
    .leave-who { display: flex; flex-direction: column; gap: 2px; }
    .leave-name { font-size: 13px; font-weight: 600; color: #1a1816; }
    .leave-agence { font-size: 10px; font-family: 'DM Mono', monospace; color: #a8a49e; letter-spacing: 0.08em; margin-top: 2px; }
    .leave-dates { display: flex; flex-direction: column; gap: 3px; }
    .leave-date-range { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500; color: #36577d; }
    .leave-demi { font-size: 10px; color: #a8a49e; font-family: 'DM Mono', monospace; }
    .leave-motif-badge {
        display: inline-flex; padding: 3px 10px; border-radius: 999px;
        font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.1em;
        background: var(--bg-primary); box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        color: #36577d; white-space: nowrap;
    }
    .leave-demand { font-size: 10px; font-family: 'DM Mono', monospace; color: #a8a49e; white-space: nowrap; }
    .leave-comment { font-size: 11px; color: #6a6660; margin-top: 4px; font-style: italic; }

    /* Actions */
    .leave-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

    /* Boutons action */
    .v2-btn {
        display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 14px;
        border-radius: 999px; border: none; cursor: pointer;
        font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 600; letter-spacing: 0.03em;
        background: var(--bg-primary); color: #36577d; text-decoration: none;
        box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        transition: box-shadow 0.12s, color 0.12s; white-space: nowrap;
    }
    .v2-btn:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .v2-btn:disabled { opacity: 0.38; cursor: not-allowed; pointer-events: none; }
    .v2-btn.success { color: #4a6038; }
    .v2-btn.danger  { color: #8a5040; }
    .v2-btn.warn    { color: #7a6830; }

    /* ── Empty state ── */
    .empty-state {
        background: var(--bg-primary); border-radius: 20px;
        box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
        padding: 60px 30px; text-align: center;
    }
    .empty-icon { font-size: 40px; margin-bottom: 14px; }
    .empty-title { font-size: 16px; font-weight: 600; color: #4a6038; margin-bottom: 6px; }
    .empty-sub { font-size: 12px; color: #a8a49e; font-family: 'DM Mono', monospace; }

    /* ── Modal ── */
    .modal-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(26,24,22,0.5); z-index: 1000;
        align-items: center; justify-content: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: var(--bg-primary); border-radius: 20px;
        box-shadow: 12px 12px 28px var(--shadow-dark), -12px -12px 28px var(--shadow-light);
        padding: 24px; width: 90%; max-width: 440px; max-height: 90vh; overflow-y: auto;
    }
    .modal-title {
        font-family: 'Sora'; font-size: 15px; font-weight: 700; color: #1a1816;
        margin-bottom: 18px; padding-bottom: 12px;
        border-bottom: 1px solid rgba(196,192,186,0.4);
    }
    .modal-title.danger { color: #8a5040; }
    .modal-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
    .modal-field label { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.16em; text-transform: uppercase; color: #a8a49e; }
    .modal-field textarea,
    .modal-field input[type=date],
    .modal-field select {
        background: var(--bg-primary); box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
        border: none; border-radius: 10px;
        font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
        padding: 8px 12px; outline: none; width: 100%;
    }
    .modal-field textarea { min-height: 80px; resize: vertical; }
    .modal-footer { display: flex; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(196,192,186,0.35); }
    .modal-footer .v2-btn { flex: 1; justify-content: center; height: 38px; }
    .modal-note { font-size: 11px; color: #8a8680; line-height: 1.5; margin-bottom: 12px; }

    /* ── Toast ── */
    .toast {
        position: fixed; bottom: 24px; right: 24px; z-index: 2000;
        background: var(--bg-primary); border-radius: 12px;
        box-shadow: 6px 6px 16px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        padding: 12px 18px; display: flex; align-items: center; gap: 10px;
        font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 500; color: #1a1816;
        transform: translateY(80px); opacity: 0;
        transition: transform 0.3s ease, opacity 0.3s ease;
        pointer-events: none;
    }
    .toast.show { transform: translateY(0); opacity: 1; }
    .toast-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .toast.success .toast-dot { background: #4a6038; }
    .toast.error   .toast-dot { background: #8a5040; }

    .mbi-page-head .ph-kpi-strip { grid-template-columns: repeat(6, 80px); }

    @media (max-width: 900px) { .leave-info { grid-template-columns: 1fr; } }
</style>
CSS;

$layout_extra_js = <<<'JS'
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Toast ────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    document.getElementById('toast-msg').textContent = msg;
    t.className = 'toast ' + type;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Post helper ──────────────────────────────────────────────────────────
function postAction(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    fd.append('csrf_token', CSRF);
    return fetch('rh_conges_validation.php', { method: 'POST', body: fd }).then(r => r.json());
}

// ── Valider ──────────────────────────────────────────────────────────────
function approveLeave(id) {
    postAction({ action: 'approve', cong_id: id })
        .then(d => {
            if (d.success) { showToast('Congé validé', 'success'); setTimeout(() => location.reload(), 700); }
            else showToast(d.message, 'error');
        })
        .catch(() => showToast('Erreur réseau', 'error'));
}

// ── Refus ────────────────────────────────────────────────────────────────
let rejectId = null;
function openRejectModal(id) {
    rejectId = id;
    document.getElementById('reject-reason').value = '';
    document.getElementById('modal-reject').classList.add('active');
    setTimeout(() => document.getElementById('reject-reason').focus(), 50);
}
function closeReject() { document.getElementById('modal-reject').classList.remove('active'); rejectId = null; }
function confirmReject() {
    const raison = document.getElementById('reject-reason').value.trim();
    if (!raison) { showToast('Veuillez saisir un motif de refus', 'error'); return; }
    postAction({ action: 'reject', cong_id: rejectId, raison })
        .then(d => {
            if (d.success) { closeReject(); showToast('Congé refusé', 'success'); setTimeout(() => location.reload(), 700); }
            else showToast(d.message, 'error');
        })
        .catch(() => showToast('Erreur réseau', 'error'));
}

// ── Édition ──────────────────────────────────────────────────────────────
let editId = null;
function openEditModal(id, debut, fin, motif) {
    editId = id;
    document.getElementById('edit-debut').value  = debut;
    document.getElementById('edit-fin').value    = fin;
    document.getElementById('edit-motif').value  = motif;
    document.getElementById('modal-edit').classList.add('active');
}
function closeEdit() { document.getElementById('modal-edit').classList.remove('active'); editId = null; }
function confirmEdit() {
    const dd = document.getElementById('edit-debut').value;
    const df = document.getElementById('edit-fin').value;
    const mo = document.getElementById('edit-motif').value;
    if (!dd || !df || !mo) { showToast('Remplissez tous les champs', 'error'); return; }
    postAction({ action: 'edit', cong_id: editId, date_debut: dd, date_fin: df, motif: mo })
        .then(d => {
            if (d.success) { closeEdit(); showToast('Congé modifié', 'success'); setTimeout(() => location.reload(), 700); }
            else showToast(d.message, 'error');
        })
        .catch(() => showToast('Erreur réseau', 'error'));
}

// ── Suppression ──────────────────────────────────────────────────────────
let deleteId = null;
function deleteLeave(id) { deleteId = id; document.getElementById('modal-delete').classList.add('active'); }
function closeDelete() { document.getElementById('modal-delete').classList.remove('active'); deleteId = null; }
function confirmDelete() {
    postAction({ action: 'delete', cong_id: deleteId })
        .then(d => {
            if (d.success) { closeDelete(); showToast('Congé supprimé', 'success'); setTimeout(() => location.reload(), 700); }
            else showToast(d.message, 'error');
        })
        .catch(() => showToast('Erreur réseau', 'error'));
}

// ── Fermeture modales ────────────────────────────────────────────────────
['modal-reject','modal-edit','modal-delete'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => { if (e.target === e.currentTarget) e.target.classList.remove('active'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') ['modal-reject','modal-edit','modal-delete'].forEach(id => document.getElementById(id).classList.remove('active'));
});
// Ctrl+Enter pour confirmer refus
document.getElementById('reject-reason').addEventListener('keydown', e => { if (e.ctrlKey && e.key === 'Enter') confirmReject(); });
</script>
JS;

// ── HTML content ─────────────────────────────────────────────────────────
ob_start();
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">

<!-- PAGE HEAD EXTRA: scope filters + role switcher -->
<div style="display:flex;align-items:flex-start;gap:24px;margin-bottom:16px">
    <div style="flex-shrink:0">
        <?php if (!empty($pendingLeaves)): ?>
        <span class="chip-attente"><?= count($pendingLeaves) ?> en attente</span>
        <?php endif; ?>
    </div>
    <!-- Scope société / agence -->
    <?php if ($roleId === 1): ?>
    <div class="ph-scope">
        <div class="ph-scope-row">
            <span class="ph-scope-label">Sté</span>
            <div class="ph-scope-btns">
                <a href="?<?= http_build_query(array_merge($_GET, ['societe'=>'toutes','agence'=>'toutes'])) ?>"
                   class="ph-scope-pill <?= $filterSociete === 'toutes' ? 'active' : '' ?>">Toutes</a>
                <?php foreach ($societes as $s): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['societe'=>$s['id'],'agence'=>'toutes'])) ?>"
                   class="ph-scope-pill <?= (string)$filterSociete === (string)$s['id'] ? 'active' : '' ?>">
                    <?= h($s['nom']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($filterSociete !== 'toutes' && !empty($agencesFiltered)): ?>
        <div class="ph-scope-row">
            <span class="ph-scope-label">Agc</span>
            <div class="ph-scope-btns">
                <a href="?<?= http_build_query(array_merge($_GET, ['agence'=>'toutes'])) ?>"
                   class="ph-scope-pill <?= $filterAgence === 'toutes' ? 'active' : '' ?>">Toutes</a>
                <?php foreach ($agencesFiltered as $ag): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['agence'=>$ag['id']])) ?>"
                   class="ph-scope-pill <?= (string)$filterAgence === (string)$ag['id'] ? 'active' : '' ?>">
                    <?= h($ag['nom_agence']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Liste demandes ── -->
<div class="section-header">
    <div class="section-title">
        <span class="line-l"></span>
        <span class="sec-txt">Demandes en attente</span>
        <span class="line-r"></span>
    </div>
</div>

<?php if (empty($pendingLeaves)): ?>
<div class="empty-state">
    <div class="empty-icon">&#10003;</div>
    <div class="empty-title">Aucune demande en attente</div>
    <div class="empty-sub">Toutes les demandes ont été traitées</div>
</div>
<?php else: ?>
<div class="leaves-grid">
    <?php foreach ($pendingLeaves as $leave):
        $color = getUserColor($leave, $colorPalette);
        $demiD = $leave['demi_journee_debut'] !== 'non' ? ' (' . $leave['demi_journee_debut'] . ')' : '';
        $demiF = $leave['demi_journee_fin']   !== 'non' ? ' (' . $leave['demi_journee_fin']   . ')' : '';
    ?>
    <div class="leave-card">
        <div class="leave-color-bar" style="background:<?= h($color) ?>"></div>
        <div class="leave-info">
            <!-- Qui -->
            <div class="leave-who">
                <div class="leave-name"><?= h($leave['prenom'] . ' ' . $leave['nom']) ?></div>
                <?php if ($leave['nom_agence']): ?>
                <div class="leave-agence"><?= h($leave['nom_agence']) ?></div>
                <?php endif; ?>
            </div>
            <!-- Dates -->
            <div class="leave-dates">
                <div class="leave-date-range">
                    <?= h(date('d/m/Y', strtotime($leave['date_debut']))) ?><?= h($demiD) ?>
                    &rarr;
                    <?= h(date('d/m/Y', strtotime($leave['date_fin']))) ?><?= h($demiF) ?>
                </div>
                <?php if ($demiD || $demiF): ?>
                <div class="leave-demi">Demi-journées</div>
                <?php endif; ?>
            </div>
            <!-- Motif -->
            <div>
                <span class="leave-motif-badge"><?= h($motifLabels[$leave['motif']] ?? $leave['motif']) ?></span>
                <?php if (!empty($leave['commentaire'])): ?>
                <div class="leave-comment">"<?= h($leave['commentaire']) ?>"</div>
                <?php endif; ?>
            </div>
            <!-- Date demande -->
            <div class="leave-demand">
                Demandé le<br>
                <?= h(date('d/m/Y', strtotime($leave['date_demande']))) ?>
            </div>
        </div>

        <!-- Actions -->
        <div class="leave-actions">
            <button class="v2-btn success" onclick="approveLeave(<?= (int)$leave['id'] ?>)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                Valider
            </button>
            <button class="v2-btn danger" onclick="openRejectModal(<?= (int)$leave['id'] ?>)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Refuser
            </button>
            <?php if ($roleId === 1): ?>
            <button class="v2-btn warn" onclick="openEditModal(<?= (int)$leave['id'] ?>, '<?= h($leave['date_debut']) ?>', '<?= h($leave['date_fin']) ?>', '<?= h($leave['motif']) ?>')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Éditer
            </button>
            <button class="v2-btn danger" onclick="deleteLeave(<?= (int)$leave['id'] ?>)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                Suppr.
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- MODAL REFUS -->
<div class="modal-overlay" id="modal-reject">
    <div class="modal-box">
        <div class="modal-title danger">Refuser le congé</div>
        <div class="modal-field">
            <label>Motif du refus *</label>
            <textarea id="reject-reason" placeholder="Expliquez pourquoi ce congé est refusé..."></textarea>
        </div>
        <div class="modal-footer">
            <button class="v2-btn" onclick="closeReject()">Annuler</button>
            <button class="v2-btn danger" onclick="confirmReject()">Confirmer le refus</button>
        </div>
    </div>
</div>

<!-- MODAL ÉDITION -->
<div class="modal-overlay" id="modal-edit">
    <div class="modal-box">
        <div class="modal-title">Modifier le congé</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="modal-field">
                <label>Date début</label>
                <input type="date" id="edit-debut">
            </div>
            <div class="modal-field">
                <label>Date fin</label>
                <input type="date" id="edit-fin">
            </div>
        </div>
        <div class="modal-field">
            <label>Type</label>
            <select id="edit-motif">
                <option value="conges_payes">Congés payés</option>
                <option value="rtt">RTT</option>
                <option value="maladie_justifiee_non_deduite">Maladie justifiée</option>
                <option value="maladie_non_justifiee_deduite">Maladie non justifiée</option>
                <option value="maladie_justifiee_deduite">Maladie (déduite)</option>
                <option value="absence_injustifiee_deduite">Absence injustifiée</option>
                <option value="absence_justifiee_non_deduite">Absence justifiée</option>
                <option value="absence_justifiee_deduite_heures">Absence (heures)</option>
                <option value="autre_legal_non_deduit">Autre légal</option>
                <option value="autre_legal_deduit">Autre légal (déduit)</option>
            </select>
        </div>
        <div class="modal-footer">
            <button class="v2-btn" onclick="closeEdit()">Annuler</button>
            <button class="v2-btn warn" onclick="confirmEdit()">Enregistrer</button>
        </div>
    </div>
</div>

<!-- MODAL SUPPRESSION -->
<div class="modal-overlay" id="modal-delete">
    <div class="modal-box">
        <div class="modal-title danger">Supprimer le congé</div>
        <p class="modal-note">Cette action ne peut pas être annulée. Le congé sera archivé et n'apparaîtra plus dans les listes.</p>
        <div class="modal-footer">
            <button class="v2-btn" onclick="closeDelete()">Annuler</button>
            <button class="v2-btn danger" onclick="confirmDelete()">Supprimer</button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast">
    <span class="toast-dot"></span>
    <span id="toast-msg"></span>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
