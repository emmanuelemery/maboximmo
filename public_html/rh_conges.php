<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$roleId   = current_role_id();
$userId   = current_user_id();
$congeAgenceScope = can_manage_salaires_agence();

// "Admin société" = role 1 (super admin) OU gestion_salaires=1 sur son agence.
// Eux seuls peuvent valider/exporter. Les autres users voient le calendrier
// global et peuvent créer un congé + consulter leur historique d'agence.
$isCongesAdmin = ($roleId === 1) || ($congeAgenceScope > 0);

// Récupère l'agence du user connecté pour le filtre historique côté non-admin.
$userAgenceId = 0;
$stUa = $pdo->prepare("SELECT id_agence FROM users WHERE id = ? LIMIT 1");
$stUa->execute([$userId]);
$userAgenceId = (int)($stUa->fetchColumn() ?: 0);

// ── Filtres URL ─────────────────────────────────────────────────────────
$curY  = (int)date('Y');
$curM  = (int)date('m');
$filterMonth   = !empty($_GET['month'])  ? (int)$_GET['month']  : $curM;
$filterYear    = !empty($_GET['year'])   ? (int)$_GET['year']   : $curY;
$filterSociete = ($roleId === 1 && !empty($_GET['societe']) && $_GET['societe'] !== 'toutes')
                   ? (int)$_GET['societe'] : 'toutes';
$filterAgence  = (!empty($_GET['agence']) && $_GET['agence'] !== 'toutes')
                   ? (int)$_GET['agence']  : 'toutes';

// Charger sociétés et agences
$societes = [];
$allAgences = [];
if ($roleId === 1) {
    $societes   = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $allAgences = $pdo->query("SELECT id, nom_agence, id_societe FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($congeAgenceScope > 0) {
    $allAgences = $pdo->query("SELECT id, nom_agence, id_societe FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
}
// Agences filtrées par société sélectionnée
$agencesFiltered = [];
if ($filterSociete !== 'toutes') {
    foreach ($allAgences as $ag) {
        if ((int)$ag['id_societe'] === (int)$filterSociete) $agencesFiltered[] = $ag;
    }
} else {
    $agencesFiltered = $allAgences;
}

// ── Plage calendrier ────────────────────────────────────────────────────
$firstDayOfMonth = new DateTime(sprintf('%04d-%02d-01', $filterYear, $filterMonth));
$lastDayOfMonth  = clone $firstDayOfMonth;
$lastDayOfMonth->modify('last day of this month');

$weekStart = clone $firstDayOfMonth;
$dow = (int)$weekStart->format('w');
if ($dow === 0)      $weekStart->modify('-2 days');
elseif ($dow !== 1)  $weekStart->modify('-' . ($dow - 1) . ' days');

$weekEnd = clone $lastDayOfMonth;
$dow = (int)$weekEnd->format('w');
if ($dow === 0)      $weekEnd->modify('+5 days');
elseif ($dow !== 5)  $weekEnd->modify('+' . (5 - $dow) . ' days');

// ── Requête congés ───────────────────────────────────────────────────────
$sql = "SELECT c.*, u.id as user_id, u.prenom, u.nom, u.couleur,
               s.id as id_societe, a.id as id_agence
        FROM conges c
        JOIN users u ON c.id_user = u.id
        LEFT JOIN societes s ON u.id_societe = s.id
        LEFT JOIN agences  a ON u.id_agence  = a.id
        WHERE c.date_debut <= ? AND c.date_fin >= ?
          AND c.statut != 'archivé'";

$params = [$weekEnd->format('Y-m-d'), $weekStart->format('Y-m-d')];

// Calendrier visible globalement : aucun filtre forcé sur l'agence/société du
// user connecté. Tout le monde voit qui est en congé partout (planification
// transverse). Seuls les filtres GET volontaires s'appliquent, et uniquement
// pour les admins société (les non-admins n'ont pas accès aux selects).
if ($isCongesAdmin) {
    if ($filterSociete !== 'toutes') { $sql .= " AND u.id_societe = ?"; $params[] = $filterSociete; }
    if ($filterAgence  !== 'toutes') { $sql .= " AND u.id_agence = ?";  $params[] = $filterAgence;  }
}

$sql .= " ORDER BY c.date_debut";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Users pour le formulaire de création ────────────────────────────────
if ($roleId === 1) {
    $formUsers = $pdo->query("SELECT id, prenom, nom FROM users WHERE actif = 1 ORDER BY prenom, nom")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($congeAgenceScope > 0) {
    $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE actif = 1 AND id_agence = ? ORDER BY prenom, nom");
    $st->execute([$congeAgenceScope]);
    $formUsers = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE id = ? AND actif = 1");
    $st->execute([$userId]);
    $formUsers = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Helpers ──────────────────────────────────────────────────────────────
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$colorPalette = [
    '#6b9fce','#7ab08c','#c97b6e','#b08abf','#d4a95a',
    '#5b9aaa','#9a7060','#7a8fb0','#8faa70','#c08878',
];
function getUserColor($leave, $palette) {
    if (!empty($leave['couleur'])) return $leave['couleur'];
    return $palette[$leave['user_id'] % count($palette)];
}
function getMotifLabel($motif) {
    return ['conges_payes'=>'Congé','rtt'=>'RTT','maladie_justifiee_non_deduite'=>'Maladie',
            'maladie_non_justifiee_deduite'=>'Maladie','maladie_justifiee_deduite'=>'Maladie',
            'absence_injustifiee_deduite'=>'Absence','absence_justifiee_non_deduite'=>'Absence',
            'absence_justifiee_deduite_heures'=>'Absence',
            'autre_legal_non_deduit'=>'Autre','autre_legal_deduit'=>'Autre'][$motif] ?? $motif;
}

// ── Mois navigation ──────────────────────────────────────────────────────
$prevM = $filterMonth - 1; $prevY = $filterYear;
if ($prevM < 1)  { $prevM = 12; $prevY--; }
$nextM = $filterMonth + 1; $nextY = $filterYear;
if ($nextM > 12) { $nextM = 1;  $nextY++; }

$moisFr = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
           7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];

// ── Build leavesByDate ───────────────────────────────────────────────────
$leavesByDate = [];
foreach ($leaves as $leave) {
    $cur = new DateTime($leave['date_debut']);
    $end = new DateTime($leave['date_fin']);
    while ($cur <= $end) {
        $ds  = $cur->format('Y-m-d');
        $dow = (int)$cur->format('w');
        if ($dow >= 1 && $dow <= 5) {
            $leavesByDate[$ds][] = $leave;
        }
        $cur->modify('+1 day');
    }
}

// ── KPI rapides ──────────────────────────────────────────────────────────
$kpiTotal    = count($leaves);
$kpiValide   = count(array_filter($leaves, fn($l) => $l['statut'] === 'validé'));
$kpiAttente  = count(array_filter($leaves, fn($l) => $l['statut'] === 'en_attente'));
$kpiRefuse   = count(array_filter($leaves, fn($l) => $l['statut'] === 'refusé'));

// ═══════════════════════════════════════════════════════════════════════════
// LAYOUT VARIABLES
// ═══════════════════════════════════════════════════════════════════════════

$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title  = 'Congés — ' . h($_userName);
$layout_module = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = <<<HTML
<div class="ph-kpi">
    <div class="ph-kpi-val">{$kpiTotal}</div>
    <div class="ph-kpi-lbl">Total mois</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val" style="color:#3a7a6a">{$kpiValide}</div>
    <div class="ph-kpi-lbl">Validés</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val" style="color:#7a6830">{$kpiAttente}</div>
    <div class="ph-kpi-lbl">En attente</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val" style="color:#8a5040">{$kpiRefuse}</div>
    <div class="ph-kpi-lbl">Refusés</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val">{$filterMonth}</div>
    <div class="ph-kpi-lbl">Mois</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val">{$filterYear}</div>
    <div class="ph-kpi-lbl">Année</div>
</div>
HTML;

// Bouton "+ Congé" et "Historique" visibles pour TOUS les users.
// "PDF mois" et "Validation" réservés à l'admin société.
$layout_head_actions = '<button class="ph-btn primary" onclick="openAddModal()">+ Congé</button>';
if ($isCongesAdmin) {
    $layout_head_actions .= '
<button class="ph-btn" onclick="exportMoisPDF()">PDF mois</button>
<a class="ph-btn" href="rh_conges_validation.php">Validation' . ($kpiAttente > 0 ? ' <span style="color:#8a5040;font-weight:700">(' . $kpiAttente . ')</span>' : '') . '</a>';
} else {
    $layout_head_actions .= '
<span class="ph-btn dispo"></span>
<span class="ph-btn dispo"></span>';
}
$layout_head_actions .= '
<a class="ph-btn" href="rh_conges_historiq.php">Historique</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
    meta[name=csrf-token] { display: none; }

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

    /* ── Action strip ── */
    .action-strip { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
    .action-strip-btns { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-left: auto; }
    .cal-nav-inline { display: flex; align-items: center; gap: 8px; }
    .cal-nav-inline .cal-nav-btn {
        width: 30px; height: 30px; border-radius: 9px; background: var(--bg-primary);
        box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
        color: #6a6660; font-size: 16px; text-decoration: none; transition: box-shadow 0.12s;
    }
    .cal-nav-inline .cal-nav-btn:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .cal-nav-inline .cal-nav-title {
        font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500;
        color: #36577d; letter-spacing: 0.06em; min-width: 120px; text-align: center;
    }

    /* ── Boutons action V2 ── */
    .v2-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 0 16px; height: 34px; border-radius: 999px; border: none; cursor: pointer;
        font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 600; letter-spacing: 0.04em;
        background: var(--bg-primary); color: #36577d; text-decoration: none;
        box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
        transition: box-shadow 0.12s, color 0.12s;
    }
    .v2-btn:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .v2-btn.success { color: #4a6038; }
    .v2-btn.danger  { color: #8a5040; }
    .v2-btn.primary { color: #36577d; font-weight: 700; }

    /* ── Calendar card ── */
    .cal-card {
        background: var(--bg-primary); border-radius: 20px;
        box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
        display: flex; flex-direction: column;
        overflow: hidden; flex: 1; min-height: 0;
    }

    /* ── Grille calendrier ── */
    .cal-container { overflow: auto; flex: 1; padding: 14px 16px; }
    .cal-grid {
        display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; min-width: 700px;
    }
    .cal-day-hdr {
        text-align: center; padding: 8px 4px; font-family: 'DM Mono', monospace;
        font-size: 9px; font-weight: 500; letter-spacing: 0.18em; text-transform: uppercase;
        color: #7a9060; border-bottom: 1.5px solid rgba(122,144,96,0.3);
    }
    .cal-cell {
        background: #ebe8e2; border-radius: 10px; padding: 8px;
        min-height: 90px; display: flex; flex-direction: column; gap: 4px;
        box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        position: relative;
    }
    .cal-cell.today { background: #deeada; box-shadow: 3px 3px 7px #bec8ba, -3px -3px 8px var(--shadow-light); }
    .cal-cell.other-month { opacity: 0.55; }
    .cal-cell-date {
        font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
        color: #6a6660; padding-bottom: 4px;
        border-bottom: 1px solid rgba(196,192,186,0.35);
        margin-bottom: 2px;
    }
    .cal-cell.today .cal-cell-date { color: #4a6038; font-weight: 700; }

    /* ── Badges congés ── */
    .leave-badge {
        display: flex; align-items: center; padding: 3px 6px; border-radius: 6px;
        font-size: 10px; font-weight: 600; color: #fff; cursor: pointer;
        gap: 4px; transition: transform 0.1s, box-shadow 0.1s;
        overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
        border: 1px solid rgba(0,0,0,0.12);
    }
    .leave-badge:hover { transform: scale(1.04); box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
    .leave-badge.pending {
        background-image: repeating-linear-gradient(
            135deg, rgba(255,255,255,0.28) 0 5px, rgba(255,255,255,0) 5px 10px
        );
        background-blend-mode: overlay;
    }
    .leave-badge .lbl-motif { font-size: 8px; opacity: 0.85; flex-shrink: 0; }

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
        padding: 24px; width: 90%; max-width: 480px; max-height: 88vh;
        overflow-y: auto; position: relative;
    }
    .modal-title {
        font-family: 'Sora'; font-size: 15px; font-weight: 700; color: #1a1816;
        margin-bottom: 18px; padding-bottom: 12px;
        border-bottom: 1px solid rgba(196,192,186,0.4);
    }
    .modal-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
    .modal-field label {
        font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
        letter-spacing: 0.16em; text-transform: uppercase; color: #a8a49e;
    }
    .modal-field select,
    .modal-field input[type=date],
    .modal-field textarea {
        background: var(--bg-primary);
        box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
        border: none; border-radius: 10px;
        font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
        padding: 8px 12px; outline: none; width: 100%;
    }
    .modal-field textarea { min-height: 60px; resize: vertical; }
    .modal-field .admin-note { background: rgba(74,96,56,0.08); border-radius: 8px; padding: 4px 8px; font-size: 9px; color: #7a9060; font-family: 'DM Mono', monospace; }
    .modal-footer { display: flex; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(196,192,186,0.35); }
    .modal-footer .v2-btn { flex: 1; justify-content: center; height: 38px; }

    /* Détail congé */
    .detail-row { margin-bottom: 12px; }
    .detail-label { font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.14em; text-transform: uppercase; color: #a8a49e; margin-bottom: 3px; }
    .detail-value { font-size: 13px; color: #1a1816; }
    .status-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 12px; border-radius: 999px; font-size: 11px; font-weight: 600;
        font-family: 'DM Mono', monospace; letter-spacing: 0.08em;
    }
    .status-valide  { background: rgba(74,96,56,0.12);  color: #4a6038; }
    .status-attente { background: rgba(196,122,48,0.12); color: #7a6830; }
    .status-refuse  { background: rgba(138,80,64,0.12);  color: #8a5040; }
    .admin-comment-box {
        background: rgba(74,96,56,0.08); border-left: 3px solid #7a9060;
        border-radius: 0 10px 10px 0; padding: 8px 12px; margin-top: 8px;
    }
    .admin-comment-box .detail-label { color: #7a9060; }

    /* ── Responsive ── */
    @media (max-width: 900px) {
        .action-strip { flex-direction: column; }
    }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const leaveData  = JSON.parse(document.getElementById('leave-data-json').textContent);
const isAdmin    = JSON.parse(document.getElementById('is-admin-json').textContent);
const csrfToken  = document.querySelector('meta[name=csrf-token]')?.content || '';

// ── Export PDF mois ─────────────────────────────────────────────────────
function exportMoisPDF() {
    const month = document.getElementById('f-month').value;
    const year  = document.getElementById('f-year').value;
    window.open('exporter_conges_mois_pdf.php?mois=' + month + '&annee=' + year, '_blank');
}

// ── Modal détail ─────────────────────────────────────────────────────────
function showDetail(id) {
    const leave = leaveData[id];
    if (!leave) return;

    const statusMap = {
        'validé'    : ['status-valide',  'Validé'],
        'en_attente': ['status-attente', 'En attente'],
        'refusé'    : ['status-refuse',  'Refusé'],
    };
    const [statusCls, statusLbl] = statusMap[leave.statut] ?? ['status-attente', leave.statut];

    const motifLabels = {
        conges_payes:'Congés payés', rtt:'RTT',
        maladie_justifiee_non_deduite:'Maladie (justifiée, non déduite)',
        maladie_justifiee_deduite:'Maladie (justifiée, déduite)',
        maladie_non_justifiee_deduite:'Maladie (non justifiée, déduite)',
        absence_justifiee_non_deduite:'Absence (justifiée, non déduite)',
        absence_justifiee_deduite_heures:'Absence (justifiée, heures)',
        absence_injustifiee_deduite:'Absence injustifiée (déduite)',
        autre_legal_non_deduit:'Autre légal (non déduit)',
        autre_legal_deduit:'Autre légal (déduit)',
    };

    const demiDebut = leave.demi_journee_debut !== 'non' ? ` <span style="color:#a8a49e;font-size:11px">(${leave.demi_journee_debut})</span>` : '';
    const demiFin   = leave.demi_journee_fin   !== 'non' ? ` <span style="color:#a8a49e;font-size:11px">(${leave.demi_journee_fin})</span>`   : '';

    document.getElementById('detail-body').innerHTML = `
        <div class="detail-row">
            <div class="detail-label">Employé</div>
            <div class="detail-value">${leave.prenom} ${leave.nom}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Période</div>
            <div class="detail-value">${leave.date_debut}${demiDebut} → ${leave.date_fin}${demiFin}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Type</div>
            <div class="detail-value">${motifLabels[leave.motif] ?? leave.motif}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Statut</div>
            <span class="status-pill ${statusCls}">${statusLbl}</span>
        </div>
        <div class="detail-row">
            <div class="detail-label">Date de demande</div>
            <div class="detail-value">${leave.date_demande ?? '—'}</div>
        </div>
        ${leave.commentaire ? `<div class="detail-row"><div class="detail-label">Commentaire</div><div class="detail-value">${leave.commentaire}</div></div>` : ''}
        ${(leave.commentaire_admin && isAdmin) ? `
        <div class="admin-comment-box">
            <div class="detail-label">📌 Commentaire admin</div>
            <div class="detail-value" style="margin-top:4px">${leave.commentaire_admin}</div>
        </div>` : ''}
    `;

    const footer = document.getElementById('detail-footer');
    footer.innerHTML = `
        <button class="v2-btn" onclick="closeDetail()">Fermer</button>
        <button class="v2-btn success" onclick="editLeave(${id})">✏️ Modifier</button>
        <button class="v2-btn" onclick="mailLeave(${id})">📧 Mail</button>
        <button class="v2-btn danger" onclick="pdfLeave(${id})">📄 PDF</button>
    `;

    document.getElementById('modal-detail').classList.add('active');
}

function closeDetail() {
    document.getElementById('modal-detail').classList.remove('active');
}
function editLeave(id) {
    window.location.href = 'rh_conges_edit.php?id=' + id;
}
function mailLeave(id) {
    const leave = leaveData[id];
    if (!leave) return;
    const subject = encodeURIComponent(`Congé du ${leave.date_debut} au ${leave.date_fin}`);
    window.location.href = `api/send_mail_leave.php?id=${id}&subject=${subject}`;
}
function pdfLeave(id) {
    window.open('exporter_conges_pdf.php?id=' + id, '_blank');
}

// ── Modal créer ──────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('modal-add').classList.add('active');
}
function closeAdd() {
    document.getElementById('modal-add').classList.remove('active');
    document.getElementById('add-form').reset();
    const btn = document.getElementById('add-submit-btn');
    btn.disabled = false;
    btn.textContent = 'Créer le congé';
}

document.getElementById('add-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('add-submit-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Création…';

    const data = Object.fromEntries(new FormData(this));
    fetch('api/create_conge.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': csrfToken},
        body: JSON.stringify(data)
    })
    .then(r => {
        if (!r.ok) return r.text().then(t => { throw new Error(`HTTP ${r.status}: ${t}`); });
        return r.json();
    })
    .then(d => {
        if (d.success) {
            closeAdd();
            setTimeout(() => location.reload(), 300);
        } else {
            alert('❌ ' + (d.message || 'Erreur inconnue'));
            btn.disabled = false;
            btn.textContent = 'Créer le congé';
        }
    })
    .catch(err => {
        alert('❌ ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Créer le congé';
    });
});

// ── Fermeture modale au clic overlay ────────────────────────────────────
document.getElementById('modal-detail').addEventListener('click', e => { if (e.target === e.currentTarget) closeDetail(); });
document.getElementById('modal-add').addEventListener('click', e => { if (e.target === e.currentTarget) closeAdd(); });

// Échap
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeDetail(); closeAdd(); }
});
</script>
EXTRAJS;

// ═══════════════════════════════════════════════════════════════════════════
// CONTENT
// ═══════════════════════════════════════════════════════════════════════════
ob_start();
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<script type="application/json" id="leave-data-json"><?= json_encode(array_column($leaves, null, 'id'), JSON_HEX_TAG) ?></script>
<script type="application/json" id="is-admin-json"><?= ($roleId === 1 || $congeAgenceScope > 0 ? 'true' : 'false') ?></script>

<!-- Scope société / agence -->
<?php if ($roleId === 1): ?>
<div class="ph-scope" style="margin-bottom:16px">
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
<?php elseif ($congeAgenceScope > 0 && !empty($agencesFiltered)): ?>
<div class="ph-scope" style="margin-bottom:16px">
    <div class="ph-scope-row">
        <span class="ph-scope-label">Agc</span>
        <div class="ph-scope-btns">
            <?php foreach ($agencesFiltered as $ag): ?>
            <span class="ph-scope-pill active"><?= h($ag['nom_agence']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Section Calendrier -->
<div class="section-header">
    <div class="section-title">
        <span class="line-l"></span>
        <span class="sec-txt">Calendrier des congés</span>
        <span class="line-r"></span>
    </div>
</div>

<div class="action-strip">
    <!-- Navigation mois (gauche) -->
    <input type="hidden" id="f-month" value="<?= $filterMonth ?>">
    <input type="hidden" id="f-year"  value="<?= $filterYear ?>">
    <div class="cal-nav-inline">
        <a href="?<?= http_build_query(array_merge($_GET, ['month'=>$prevM,'year'=>$prevY])) ?>"
           class="cal-nav-btn" title="Mois précédent">&#8249;</a>
        <span class="cal-nav-title"><?= $moisFr[$filterMonth] ?> <?= $filterYear ?></span>
        <a href="?<?= http_build_query(array_merge($_GET, ['month'=>$nextM,'year'=>$nextY])) ?>"
           class="cal-nav-btn" title="Mois suivant">&#8250;</a>
    </div>
    <!-- Boutons actions (droite via margin-left:auto) -->
    <div class="action-strip-btns">
        <button class="v2-btn success" onclick="openAddModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Créer un congé
        </button>
        <?php if ($isCongesAdmin): ?>
        <button class="v2-btn" onclick="exportMoisPDF()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Export mois PDF
        </button>
        <a class="v2-btn" href="rh_conges_validation.php">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            Validation
            <?php if ($kpiAttente > 0): ?>
            <span style="background:#8a5040;color:#fff;border-radius:999px;font-size:9px;padding:1px 6px;font-family:'DM Mono',monospace;"><?= $kpiAttente ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a class="v2-btn" href="rh_conges_historiq.php">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Historique
        </a>
    </div>
</div>


<div class="cal-card">
    <div class="cal-container">
        <div class="cal-grid">
            <?php
            $dayNames = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi'];
            foreach ($dayNames as $dn): ?>
            <div class="cal-day-hdr"><?= $dn ?></div>
            <?php endforeach; ?>

            <?php
            $current = clone $weekStart;
            while ($current <= $weekEnd):
                $ds      = $current->format('Y-m-d');
                $dayNum  = (int)$current->format('d');
                $dow     = (int)$current->format('w');
                $isToday = $ds === date('Y-m-d');
                $inMonth = $current->format('Y-m') === sprintf('%04d-%02d', $filterYear, $filterMonth);

                if ($dow >= 1 && $dow <= 5):
                    $dayLeaves = $leavesByDate[$ds] ?? [];
                    $cellClass = 'cal-cell' . ($isToday ? ' today' : '') . (!$inMonth ? ' other-month' : '');
            ?>
            <div class="<?= $cellClass ?>">
                <div class="cal-cell-date"><?= $dayNum ?></div>
                <?php foreach ($dayLeaves as $leave):
                    $color      = getUserColor($leave, $colorPalette);
                    $name       = h($leave['prenom'] . ' ' . $leave['nom']);
                    $motifLbl   = getMotifLabel($leave['motif']);
                    $isPending  = $leave['statut'] === 'en_attente';
                    $isRefuse   = $leave['statut'] === 'refusé';
                    // Teinte par statut
                    $badgeColor = $color;
                    if ($isRefuse) { $badgeColor = '#c97b6e'; }
                    // Demi-journée
                    $demi = '';
                    if ($ds === $leave['date_debut'] && $leave['demi_journee_debut'] !== 'non') {
                        $demi = $leave['demi_journee_debut'] === 'matin' ? ' · Matin' : ' · AM';
                    } elseif ($ds === $leave['date_fin'] && $leave['demi_journee_fin'] !== 'non') {
                        $demi = $leave['demi_journee_fin'] === 'matin' ? ' · Matin' : ' · AM';
                    }
                    list($r,$g,$b) = sscanf($badgeColor, "#%02x%02x%02x") + [0,0,0];
                    $darkColor = sprintf("#%02x%02x%02x", max(0,$r-30), max(0,$g-30), max(0,$b-30));
                ?>
                <div class="leave-badge <?= $isPending ? 'pending' : '' ?>"
                     style="background-color:<?= $badgeColor ?>;border-color:<?= $darkColor ?>"
                     onclick="showDetail(<?= (int)$leave['id'] ?>)"
                     title="<?= $name ?> · <?= $leave['date_debut'] ?> → <?= $leave['date_fin'] ?><?= $demi ?>">
                    <span style="overflow:hidden;text-overflow:ellipsis;flex:1"><?= $name ?></span>
                    <span class="lbl-motif"><?= $motifLbl ?><?= $demi ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php
                endif;
                $current->modify('+1 day');
            endwhile;
            ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════ MODAL DÉTAIL ═══════════════════════════ -->
<div class="modal-overlay" id="modal-detail">
    <div class="modal-box">
        <div class="modal-title">Détail du congé</div>
        <div id="detail-body"></div>
        <div class="modal-footer" id="detail-footer">
            <button class="v2-btn" onclick="closeDetail()">Fermer</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════ MODAL CRÉER ════════════════════════════ -->
<div class="modal-overlay" id="modal-add">
    <div class="modal-box">
        <div class="modal-title">Créer un congé</div>
        <form id="add-form">
            <div class="modal-field">
                <label>Employé *</label>
                <select name="id_user" required>
                    <option value="">— choisir —</option>
                    <?php foreach ($formUsers as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($u['id'] === $userId) ? 'selected' : '' ?>>
                        <?= h($u['prenom'] . ' ' . $u['nom']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="modal-field">
                    <label>Date début *</label>
                    <input type="date" name="date_debut" required>
                </div>
                <div class="modal-field">
                    <label>Date fin *</label>
                    <input type="date" name="date_fin" required>
                </div>
            </div>
            <div class="modal-field">
                <label>Type *</label>
                <select name="motif" required>
                    <option value="">— choisir —</option>
                    <option value="conges_payes">Congés payés</option>
                    <option value="rtt">RTT</option>
                    <option value="maladie_justifiee_non_deduite">Maladie (justifiée, non déduite)</option>
                    <option value="maladie_justifiee_deduite">Maladie (justifiée, déduite)</option>
                    <option value="maladie_non_justifiee_deduite">Maladie (non justifiée, déduite)</option>
                    <option value="absence_justifiee_non_deduite">Absence (justifiée, non déduite)</option>
                    <option value="absence_justifiee_deduite_heures">Absence (justifiée, en heures)</option>
                    <option value="absence_injustifiee_deduite">Absence injustifiée (déduite)</option>
                    <option value="autre_legal_non_deduit">Autre légal (non déduit)</option>
                    <option value="autre_legal_deduit">Autre légal (déduit)</option>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="modal-field">
                    <label>Demi-j. début</label>
                    <select name="demi_journee_debut">
                        <option value="non">Journée complète</option>
                        <option value="matin">Matin (congé le matin)</option>
                        <option value="apres-midi">Après-midi (congé l'AM)</option>
                    </select>
                </div>
                <div class="modal-field">
                    <label>Demi-j. fin</label>
                    <select name="demi_journee_fin">
                        <option value="non">Journée complète</option>
                        <option value="matin">Matin (congé le matin)</option>
                        <option value="apres-midi">Après-midi (congé l'AM)</option>
                    </select>
                </div>
            </div>
            <div class="modal-field">
                <label>Commentaire</label>
                <textarea name="commentaire" placeholder="Optionnel…"></textarea>
            </div>
            <?php if ($roleId === 1): ?>
            <div class="modal-field">
                <label>Commentaire admin <span class="admin-note">non exporté en PDF</span></label>
                <textarea name="commentaire_admin" placeholder="Note interne…"></textarea>
            </div>
            <?php endif; ?>
            <div class="modal-footer">
                <button type="button" class="v2-btn" onclick="closeAdd()">Annuler</button>
                <button type="submit" class="v2-btn success" id="add-submit-btn">Créer le congé</button>
            </div>
        </form>
    </div>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
