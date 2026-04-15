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

$roleId = current_role_id();
$userId = current_user_id();
$userAgenceId = current_agence_id();

// Get filter values from URL
$filterMonth = !empty($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$filterYear = !empty($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$congeAgenceScope = can_manage_salaires_agence(); // >0 si gestionnaire agence

$filterSociete = !empty($_GET['societe']) && $roleId === 1 ? (int)$_GET['societe'] : null;
$filterAgence  = !empty($_GET['agence']) ? (int)$_GET['agence'] : null;

// Charger societes et agences pour les filtres
$societes = [];
$agences  = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
if ($roleId === 1) {
    $societes = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
}

// French holidays
$holidays = [
    '01-01' => 'Jour de l\'an',
    '05-01' => 'Fête du Travail',
    '05-08' => 'Fête de la Victoire',
    '07-14' => 'Fête nationale',
    '08-15' => 'Assomption',
    '11-01' => 'Toussaint',
    '11-11' => 'Armistice',
    '12-25' => 'Noël',
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Palette de couleurs pour les utilisateurs
$colorPalette = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
    '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#ABEBC6',
    '#F1948A', '#A9DFBF', '#D7BDE2', '#F5B7B1', '#F9E79F',
    '#FADBD8', '#D5F4E6', '#EAFAF1', '#FCF3CF', '#FEF9E7'
];

// Get leaves for the month
$sql = "SELECT c.*, u.id as user_id, u.prenom, u.nom, u.couleur, s.id as id_societe, a.id as id_agence
        FROM conges c
        JOIN users u ON c.id_user = u.id
        LEFT JOIN societes s ON u.id_societe = s.id
        LEFT JOIN agences a ON u.id_agence = a.id
        WHERE c.date_debut <= ? AND c.date_fin >= ?
        AND c.statut != 'archivé'";

// Extend date range to show transition weeks
$firstDayOfMonth = new DateTime(sprintf('%04d-%02d-01', $filterYear, $filterMonth));
$lastDayOfMonth = new DateTime(sprintf('%04d-%02d-01', $filterYear, $filterMonth));
$lastDayOfMonth->modify('last day of this month');

// Get first Monday before month start
$weekStart = clone $firstDayOfMonth;
$dayOfWeek = (int)$weekStart->format('w');
if ($dayOfWeek === 0) {
    $weekStart->modify('-2 days'); // Sunday -> previous Friday
} elseif ($dayOfWeek !== 1) {
    $weekStart->modify('-' . ($dayOfWeek - 1) . ' days'); // Back to Monday
}

// Get last Friday after month end
$weekEnd = clone $lastDayOfMonth;
$dayOfWeek = (int)$weekEnd->format('w');
if ($dayOfWeek === 0) {
    $weekEnd->modify('+5 days'); // Sunday -> next Friday
} elseif ($dayOfWeek !== 5) {
    $weekEnd->modify('+' . (5 - $dayOfWeek) . ' days'); // Forward to Friday
}

$params = [
    $weekEnd->format('Y-m-d'),
    $weekStart->format('Y-m-d')
];

// Filtres calendrier : tous les users voient tout, filtre optionnel
if ($filterSociete !== null) {
    $sql .= " AND u.id_societe = ?";
    $params[] = $filterSociete;
}
if ($filterAgence !== null) {
    $sql .= " AND u.id_agence = ?";
    $params[] = $filterAgence;
}

$sql .= " ORDER BY c.date_debut";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate calendar date range
$firstDay = new DateTime(sprintf('%04d-%02d-01', $filterYear, $filterMonth));
$lastDay = new DateTime(sprintf('%04d-%02d-01', $filterYear, $filterMonth));
$lastDay->modify('last day of this month');
$daysInMonth = (int)$lastDay->format('d');

// Function to get user color
function getUserColor($leave, $colorPalette) {
    if (!empty($leave['couleur']) && $leave['couleur'] !== null) {
        return $leave['couleur'];
    }
    return $colorPalette[$leave['user_id'] % count($colorPalette)];
}

// Function to translate motif to French
function getMotifLabel($motif) {
    $labels = [
        'conges_payes' => 'Congé',
        'rtt' => 'RTT',
        'maladie_justifiee_non_deduite' => 'Maladie',
        'maladie_non_justifiee_deduite' => 'Maladie',
        'maladie_justifiee_deduite' => 'Maladie',
        'absence_injustifiee_deduite' => 'Absence',
        'absence_justifiee_non_deduite' => 'Absence',
        'absence_justifiee_deduite_heures' => 'Absence',
        'autre_legal_non_deduit' => 'Autre',
        'autre_legal_deduit' => 'Autre'
    ];
    return $labels[$motif] ?? $motif;
}

// Function to get demi-journée display text
function getDemiJourneeInfo($dateDebut, $demiJourneeDebut, $demiJourneeFin) {
    $today = date('Y-m-d');
    if ($dateDebut === $today) {
        if ($demiJourneeDebut === 'matin') {
            return ' - Matin';
        } elseif ($demiJourneeDebut === 'apres-midi') {
            return ' - Après-midi';
        }
    }
    return '';
}

?><!doctype html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme-rh.css">
    <?php include __DIR__ . '/inc/theme-init.php'; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Congés - My Box Agency</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
        .mbi-sidebar{position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;background:var(--sidebar);border-right:1px solid var(--stroke);overflow-y:auto;padding:12px 0}
        .mbi-sidebar-head{padding:10px 12px;border-bottom:1px solid var(--stroke);margin-bottom:12px}
        .mbi-sidebar-brand strong{font-size:14px;display:block}
        .mbi-sidebar-brand span{font-size:11px;color:var(--muted)}
        .mbi-sidebar-section{padding:12px;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--ink);margin:16px 8px 10px;background:rgba(72,120,166,0.06);border-left:3px solid rgba(72,120,166,0.2);border-radius:4px;letter-spacing:0.5px}
        .mbi-nav{list-style:none}
        .mbi-nav li a{display:flex;align-items:center;gap:4px;padding:6px 12px;color:var(--muted);text-decoration:none;font-size:15px;transition:all 0.2s}
        .mbi-nav li a:hover{color:var(--ink);background:#ffffff}
        .mbi-nav li a.active{color:var(--accent);background:rgba(72,120,166,0.08)}
        .mbi-main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column}
        .mbi-topbar{height:64px;background:var(--bg-soft);border-bottom:1px solid var(--stroke);display:flex;align-items:center;justify-content:space-between;padding:0 30px}
        .mbi-topbar h1{font-size:18px;color:var(--ink)}
        .mbi-content{flex:1;padding:30px;overflow-y:hidden}
        .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
        .filter-group{display:flex;flex-direction:column;gap:6px}
        .filter-group label{font-size:12px;font-weight:600;color:var(--ink)}
        .filter-group select{padding:8px 12px;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:var(--ink);font-family:inherit;cursor:pointer;font-size:14px;font-weight:500}
        .filter-group select:hover{background:rgba(0,0,0,0.5);border-color:rgba(255,255,255,0.3)}
        .filter-group select:focus{outline:none;background:rgba(0,0,0,0.5);border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
        .reset-btn{padding:8px 16px;background:rgba(16,185,129,0.35);border:1px solid rgba(124,245,214,0.6);color:#ffffff;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;text-decoration:none;transition:all 0.2s;align-self:flex-end}
        .reset-btn:hover{background:rgba(16,185,129,0.5);border-color:#4a6038}
        .calendar-wrapper{background:#1a2535;border:1px solid #ffffff;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;max-height:calc(100vh - 260px)}
        .calendar-header{padding:16px;border-bottom:1px solid #ffffff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;background:rgba(72,120,166,0.04);position:sticky;top:0;z-index:5}
        .calendar-nav{display:flex;gap:8px;align-items:center}
        .calendar-actions{display:flex;gap:8px;align-items:center;margin-left:auto}
        .calendar-nav button{padding:8px 16px;background:#DAA520;border:1px solid #DAA520;color:#1a2a3a;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s}
        .calendar-nav button:hover{background:#E5B830;border-color:#E5B830}
        .calendar-nav span{font-weight:600;color:#fff;min-width:120px;text-align:center}
        .calendar-container{overflow-x:auto;padding:16px;overflow-y:auto;flex:1}
        .calendar-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;min-width:100%;grid-auto-flow:row}
        .calendar-day-header{text-align:center;padding:12px 8px;font-size:13px;font-weight:700;color:#e0e8ff;text-transform:uppercase;border-bottom:2px solid rgba(72,120,166,0.2);background:rgba(72,120,166,0.06)}
        .calendar-cell{background:#243046;border:1px solid #ffffff;border-radius:6px;padding:8px;min-height:100px;display:flex;flex-direction:column;gap:4px;position:relative}
        .calendar-cell.today{background:#2a3a20;border-color:rgba(255,165,0,0.5)}
        .calendar-cell.other-month{background:#1e2a3a;border-color:#ffffff}
        .calendar-cell-date{font-size:13px;font-weight:700;color:#e0e8ff;padding:4px 0;border-bottom:1px solid #ffffff}
        .calendar-badge{display:flex;align-items:center;padding:4px 6px;border-radius:4px;font-size:11px;font-weight:600;color:#ffffff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;transition:all 0.2s;border:1px solid;box-shadow:0 1px 2px rgba(0,0,0,0.15)}
        .calendar-badge:hover{transform:scale(1.05);box-shadow:0 2px 4px #f7f8fa}
        .calendar-badge.pending{opacity:0.85;background-image:repeating-linear-gradient(135deg,rgba(255,255,255,0.35) 0 6px,rgba(255,255,255,0) 6px 12px);background-blend-mode:overlay}
        .calendar-empty{padding:40px;text-align:center;color:var(--muted);font-size:14px}
        @media(max-width:768px){.calendar-grid{grid-template-columns:repeat(2,1fr);gap:6px}.calendar-cell{min-height:60px;padding:6px;font-size:10px}.calendar-badge{font-size:10px}}
        .modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center}
        .modal.active{display:flex}
        .modal-content{background:var(--bg-soft);border:1px solid var(--stroke);border-radius:14px;padding:24px;max-width:400px;width:90%;max-height:80vh;overflow-y:auto}
        .modal-header{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:16px;border-bottom:1px solid var(--stroke);padding-bottom:12px}
        .modal-row{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
        .modal-label{font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase}
        .modal-value{color:var(--ink);font-size:14px}
        .modal-status{display:inline-block;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;margin-top:4px;width:fit-content}
        .status-attente{background:rgba(255,165,0,0.2);color:#FFD700}
        .status-valide{background:rgba(16,185,129,0.2);color:#4a6038}
        .status-refuse{background:rgba(239,90,107,0.2);color:#FF6B7A}
        .modal-footer{margin-top:16px;border-top:1px solid var(--stroke);padding-top:16px;display:flex;gap:8px}
        .btn-close{flex:1;padding:8px;background:#f7f8fa;border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s}
        .btn-close:hover{background:rgba(0,0,0,0.5);border-color:var(--accent)}
        .btn-export{padding:10px 16px;background:rgba(239,68,68,0.35);border:1px solid rgba(255,107,107,0.6);color:#ffffff;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;transition:all 0.2s}
        .btn-export:hover{background:rgba(239,68,68,0.5);border-color:#ff6b7a}
        .btn-add{padding:10px 16px;background:rgba(16,185,129,0.35);border:1px solid rgba(124,245,214,0.6);color:#ffffff;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;transition:all 0.2s}
        .btn-add:hover{background:rgba(16,185,129,0.5);border-color:#4a6038}
        @media(max-width:1024px){.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}.gantt-day{flex:0 0 30px;font-size:10px}.gantt-bar-container{flex:0 0 30px}.gantt-bar{font-size:9px}}
        @media(max-width:768px){.calendar-header{flex-direction:column;align-items:stretch;position:sticky;top:0;z-index:5}.calendar-actions{width:100%;justify-content:flex-end}}
    </style>
</head>
<body>
<?php include __DIR__ . '/inc/rh_sidebar.php'; ?>

<main class="mbi-main">
    <div class="mbi-topbar" style="justify-content:space-between;">
        <h1>📅 Gestion des Congés</h1>
        <div style="display:flex;gap:16px;align-items:center;">
            <?php require_once __DIR__ . '/inc/role_switcher.php'; ?>
        </div>
    </div>

    <div class="mbi-content">
        <form method="GET" style="display:contents">
            <div class="filters">
                <div class="filter-group">
                    <label>Mois</label>
                    <select name="month" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?=$m?>" <?=($filterMonth === $m ? 'selected' : '')?>>
                                <?=sprintf('%02d', $m)?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Année</label>
                    <select name="year" onchange="this.form.submit()">
                        <?php for ($y = (int)date('Y') - 2; $y <= (int)date('Y') + 2; $y++): ?>
                            <option value="<?=$y?>" <?=($filterYear === $y ? 'selected' : '')?>>
                                <?=$y?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php if ($roleId === 1 && !empty($societes)): ?>
                <div class="filter-group">
                    <label>Société</label>
                    <select name="societe" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <?php foreach($societes as $s): ?>
                            <option value="<?=$s['id']?>" <?=($filterSociete===$s['id']?'selected':'')?>><?=h($s['nom'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($agences)): ?>
                <div class="filter-group">
                    <label>Agence</label>
                    <select name="agence" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <?php foreach($agences as $a): ?>
                            <option value="<?=$a['id']?>" <?=($filterAgence===$a['id']?'selected':'')?>><?=h($a['nom_agence'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <a href="rh_conges.php" class="reset-btn">🔄 Réinitialiser</a>
            </div>
        </form>

        <div class="calendar-wrapper">
            <div class="calendar-header">
                <div class="calendar-nav">
                    <form method="GET" style="display:flex;gap:8px;align-items:center">
                        <input type="hidden" name="societe" value="<?=$filterSociete?>">
                        <input type="hidden" name="agence" value="<?=$filterAgence?>">
                        <button type="submit" name="month" value="<?=$filterMonth - 1 > 0 ? $filterMonth - 1 : 12?>" name="year" value="<?=$filterMonth - 1 > 0 ? $filterYear : $filterYear - 1?>">← Précédent</button>
                        <?php $moisFr=[1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']; ?>
                        <span><?=$moisFr[$filterMonth]?> <?=$filterYear?></span>
                        <button type="submit" name="month" value="<?=$filterMonth + 1 <= 12 ? $filterMonth + 1 : 1?>" name="year" value="<?=$filterMonth + 1 <= 12 ? $filterYear : $filterYear + 1?>">Suivant →</button>
                    </form>
                </div>
                <div class="calendar-actions">
                    <?php if ($roleId === 1): ?>
                    <button onclick="exportMoisPDF()" class="btn-export">
                        📄 Export Mois
                    </button>
                    <?php endif; ?>
                    <button onclick="openAddLeaveModal()" class="btn-add">
                        ➕ Créer
                    </button>
                </div>
            </div>

            <div class="calendar-container">
                <div class="calendar-grid">
                        <?php
                        // Build a map of leaves by date
                        $leavesByDate = [];
                        foreach ($leaves as $leave) {
                            $current = new DateTime($leave['date_debut']);
                            $endDate = new DateTime($leave['date_fin']);

                            while ($current <= $endDate) {
                                $dateStr = $current->format('Y-m-d');
                                $dayOfWeek = (int)$current->format('w');

                                // Only include Monday-Friday (1-5)
                                if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
                                    if (!isset($leavesByDate[$dateStr])) {
                                        $leavesByDate[$dateStr] = [];
                                    }
                                    $leavesByDate[$dateStr][] = $leave;
                                }

                                $current->modify('+1 day');
                            }
                        }

                        // Draw day headers
                        $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
                        foreach ($dayNames as $dayName) {
                            echo '<div class="calendar-day-header">' . $dayName . '</div>';
                        }

                        // Generate calendar grid: Monday-Friday only, including transition weeks
                        $current = clone $weekStart;

                        // Draw all cells from weekStart to weekEnd
                        while ($current <= $weekEnd) {
                            $dateStr = $current->format('Y-m-d');
                            $dayNum = (int)$current->format('d');
                            $isToday = $dateStr === date('Y-m-d');
                            $dayOfWeek = (int)$current->format('w');
                            $isInMonth = $current->format('Y-m') === sprintf('%04d-%02d', $filterYear, $filterMonth);

                            // Only display Monday-Friday
                            if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
                                $dayLeaves = $leavesByDate[$dateStr] ?? [];

                                $cellClass = 'calendar-cell' . ($isToday ? ' today' : '') . (!$isInMonth ? ' other-month' : '');
                                echo "<div class=\"$cellClass\">";

                                echo "<div class=\"calendar-cell-date" . (!$isInMonth ? " style=\"color:#7a90b0;\"" : "") . "\">$dayNum</div>";

                                // Display leave badges
                                foreach ($dayLeaves as $leave) {
                                    $badgeColor = getUserColor($leave, $colorPalette);
                                    $displayName = h($leave['prenom'] . ' ' . $leave['nom']);
                                    $motifLabel = getMotifLabel($leave['motif']);
                                    $badgeClass = 'calendar-badge' . (($leave['statut'] === 'en_attente') ? ' pending' : '');

                                    // Add demi-journée info
                                    $demiInfo = '';
                                    if ($dateStr === $leave['date_debut'] && $leave['demi_journee_debut'] !== 'non') {
                                        $demiInfo = $leave['demi_journee_debut'] === 'matin' ? ' - Matin' : ' - Après-midi';
                                    } elseif ($dateStr === $leave['date_fin'] && $leave['demi_journee_fin'] !== 'non') {
                                        $demiInfo = $leave['demi_journee_fin'] === 'matin' ? ' - Matin' : ' - Après-midi';
                                    }

                                    // Make border darker
                                    list($r, $g, $b) = sscanf($badgeColor, "#%02x%02x%02x");
                                    $darkColor = sprintf("#%02x%02x%02x", max(0, $r-30), max(0, $g-30), max(0, $b-30));

                                    echo "<div class=\"$badgeClass\"
                                            style=\"background-color: $badgeColor; border-color: $darkColor;\"
                                            title=\"$displayName - {$leave['date_debut']} à {$leave['date_fin']}$demiInfo\"
                                            onclick=\"showLeaveDetail({$leave['id']})\">
                                            $displayName <span style=\"font-size:9px;\">($motifLabel)$demiInfo</span>
                                        </div>";
                                }

                                echo '</div>';
                            }

                            $current->modify('+1 day');
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<div id="leaveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">Détail du congé</div>
        <div id="leaveDetails"></div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeLeaveModal()">Fermer</button>
        </div>
    </div>
</div>
<script>
const leaveData = <?=json_encode(array_column($leaves, null, 'id'))?>;
const isAdmin = <?=($roleId === 1 || can_manage_salaires_agence() > 0 ? 'true' : 'false')?>;

// Define all functions globally (not inside DOMContentLoaded)
function showLeaveDetail(leaveId) {
    const leave = leaveData[leaveId];
    if (!leave) return;

    const modal = document.getElementById('leaveModal');
    const details = document.getElementById('leaveDetails');

    let statusClass = 'status-attente';
    if (leave.statut === 'validé') statusClass = 'status-valide';
    else if (leave.statut === 'refusé') statusClass = 'status-refuse';

    const employeeName = `${leave.prenom || ''} ${leave.nom || ''}`.trim();

    // Add is_admin flag to leave data for template
    leave.is_admin = isAdmin;

    details.innerHTML = `
        <div class="modal-row">
            <div class="modal-label">Employé</div>
            <div class="modal-value">${employeeName}</div>
        </div>
        <div class="modal-row">
            <div class="modal-label">Période</div>
            <div class="modal-value">${leave.date_debut} au ${leave.date_fin}</div>
        </div>
        <div class="modal-row">
            <div class="modal-label">Motif</div>
            <div class="modal-value">${leave.motif}</div>
        </div>
        <div class="modal-row">
            <div class="modal-label">Statut</div>
            <div class="modal-status ${statusClass}">${leave.statut}</div>
        </div>
        <div class="modal-row">
            <div class="modal-label">Date de demande</div>
            <div class="modal-value">${leave.date_demande}</div>
        </div>
        ${leave.commentaire ? `
        <div class="modal-row">
            <div class="modal-label">Commentaire</div>
            <div class="modal-value">${leave.commentaire}</div>
        </div>
        ` : ''}
        ${leave.commentaire_admin && leave.is_admin ? `
        <div class="modal-row" style="background:rgba(255,165,0,0.1);padding:8px;border-radius:6px;border-left:3px solid #FFA500;">
            <div class="modal-label" style="color:#FFD700;">📌 Commentaire Admin</div>
            <div class="modal-value" style="color:#FFD700;">${leave.commentaire_admin}</div>
        </div>
        ` : ''}
        <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;">
            <button onclick="editLeave(${leaveId})" style="flex:1;min-width:100px;padding:10px;background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:#4878a6;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s;">
                ✏️ Modifier
            </button>
            <button onclick="sendMailLeave(${leaveId})" style="flex:1;min-width:100px;padding:10px;background:rgba(59,130,246,0.2);border:1px solid rgba(59,130,246,0.4);color:#60a5fa;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s;">
                📧 Mail
            </button>
            <button onclick="downloadLeavePDF(${leaveId})" style="flex:1;min-width:100px;padding:10px;background:rgba(239,68,68,0.2);border:1px solid rgba(239,68,68,0.4);color:#ff6b6b;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s;">
                📄 PDF
            </button>
        </div>
    `;

    modal.classList.add('active');
}

function editLeave(leaveId) {
    window.location.href = 'rh_conges_edit.php?id=' + leaveId;
}

function sendMailLeave(leaveId) {
    const leave = leaveData[leaveId];
    if (!leave) return;

    const subject = encodeURIComponent(`Congé du ${leave.date_debut} au ${leave.date_fin}`);
    window.location.href = `api/send_mail_leave.php?id=${leaveId}&subject=${subject}`;
}

function downloadLeavePDF(leaveId) {
    window.location.href = `exporter_conges_pdf.php?id=${leaveId}`;
}

function exportMoisPDF() {
    const month = document.querySelector('select[name="month"]').value;
    const year = document.querySelector('select[name="year"]').value;
    window.location.href = `exporter_conges_mois_pdf.php?mois=${month}&annee=${year}`;
}

function closeLeaveModal() {
    document.getElementById('leaveModal').classList.remove('active');
}

// Modal Ajouter congé
function openAddLeaveModal() {
    document.getElementById('addLeaveModal').classList.add('active');
}

function closeAddLeaveModal() {
    document.getElementById('addLeaveModal').classList.remove('active');
    const form = document.getElementById('addLeaveForm');
    form.reset();
    // Reset button
    form.querySelector('button[type="submit"]').disabled = false;
    form.querySelector('button[type="submit"]').textContent = '✅ Créer le congé';
}

// Attach event listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {

document.getElementById('leaveModal').addEventListener('click', function(e) {
    if (e.target === this) closeLeaveModal();
});

document.getElementById('addLeaveModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddLeaveModal();
});

document.getElementById('addLeaveForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳ Création...';

    const formData = new FormData(this);
    const dataObj = Object.fromEntries(formData);
    console.log('FormData entries:', dataObj);
    console.log('JSON body:', JSON.stringify(dataObj));

    const _csrfConge = document.querySelector('meta[name=csrf-token]')?.content || '';
    fetch('api/create_conge.php', {
        method: 'POST',
        body: JSON.stringify(dataObj),
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': _csrfConge}
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => {
                console.error('HTTP Error:', r.status, text);
                throw new Error(`HTTP ${r.status}: ${text}`);
            });
        }
        return r.json();
    })
    .then(data => {
        if (data.success) {
            closeAddLeaveModal();
            // Small delay then reload to ensure data is saved
            setTimeout(() => location.reload(), 500);
        } else {
            alert('❌ Erreur: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.textContent = '✅ Créer le congé';
        }
    })
    .catch(err => {
        console.error('Form submission error:', err);
        alert('❌ Erreur: ' + err.message);
        submitBtn.disabled = false;
        submitBtn.textContent = '✅ Créer le congé';
    });
});

}); // End DOMContentLoaded
</script>

<!-- Modal Ajouter congés -->
<div id="addLeaveModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">➕ Ajouter un congé</div>
        <form id="addLeaveForm">
            <div class="modal-row">
                <label class="modal-label">Employé *</label>
                <select name="id_user" required style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;">
                    <option value="">Choisir un employé</option>
                    <?php
                    $congeAgenceScope = can_manage_salaires_agence();
                    if ($roleId === 1) {
                        $users = $pdo->query("SELECT id, prenom, nom FROM users WHERE actif = 1 ORDER BY prenom, nom")->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($congeAgenceScope > 0) {
                        $stmt = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE actif = 1 AND id_agence = ? ORDER BY prenom, nom");
                        $stmt->execute([$congeAgenceScope]);
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $stmt = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE id = ? AND actif = 1");
                        $stmt->execute([$userId]);
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    foreach ($users as $u) {
                        $selected = ($u['id'] === $userId) ? 'selected' : '';
                        echo "<option value='{$u['id']}' $selected>" . h($u['prenom'] . ' ' . $u['nom']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="modal-row">
                <label class="modal-label">Date début *</label>
                <input type="date" name="date_debut" required style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;">
            </div>
            <div class="modal-row">
                <label class="modal-label">Date fin *</label>
                <input type="date" name="date_fin" required style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;">
            </div>
            <div class="modal-row">
                <label class="modal-label">Type *</label>
                <select name="motif" required style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;">
                    <option value="">Choisir un type</option>
                    <option value="conges_payes">Congés payés</option>
                    <option value="rtt">RTT</option>
                    <option value="maladie_justifiee_non_deduite">Maladie (justifiée)</option>
                    <option value="absence_justifiee_deduite_heures">Absence (justifiée)</option>
                    <option value="autre_legal_non_deduit">Autre (légal)</option>
                </select>
            </div>
            <div class="modal-row">
                <label class="modal-label">Demi-journée début</label>
                <select name="demi_journee_debut" style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;">
                    <option value="non">Journée complète</option>
                    <option value="matin" selected>Matin (travail après-midi)</option>
                    <option value="apres-midi">Après-midi (travail matin)</option>
                </select>
            </div>
            <div class="modal-row">
                <label class="modal-label">Demi-journée fin</label>
                <select name="demi_journee_fin" style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;">
                    <option value="non">Journée complète</option>
                    <option value="matin">Matin (travail après-midi)</option>
                    <option value="apres-midi" selected>Après-midi (travail matin)</option>
                </select>
            </div>
            <div class="modal-row">
                <label class="modal-label">Commentaire</label>
                <textarea name="commentaire" style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;min-height:60px;"></textarea>
            </div>
            <?php if ($roleId === 1): ?>
            <div class="modal-row">
                <label class="modal-label">Commentaire Admin (non exporté en PDF)</label>
                <textarea name="commentaire_admin" style="padding:8px;border:1px solid var(--stroke);border-radius:6px;background:#ffffff;color:var(--ink);font-size:12px;min-height:60px;"></textarea>
            </div>
            <?php endif; ?>
            <div class="modal-footer">
                <button type="button" class="btn-close" onclick="closeAddLeaveModal()">Annuler</button>
                <button type="submit" style="flex:1;padding:8px;background:rgba(16,185,129,0.2);border:1px solid rgba(16,185,129,0.4);color:#4a6038;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s;">
                    ✅ Créer le congé
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
