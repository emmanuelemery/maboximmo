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

// Admin and Manager only
if (!in_array($roleId, [1, 2])) {
    deny_access('Accès réservé aux administrateurs et managers.');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Create mois_clos table if it doesn't exist (runs once)
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'mois_clos'");
    if (!$checkTable->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `mois_clos` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `mois` TINYINT NOT NULL,
          `annee` SMALLINT NOT NULL,
          `clos_par` INT NOT NULL,
          `date_fermeture` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `commentaire` TEXT,
          UNIQUE KEY `unique_mois_annee` (`mois`, `annee`),
          KEY `idx_date` (`date_fermeture`),
          FOREIGN KEY (`clos_par`) REFERENCES `users`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    error_log("Table creation error: " . $e->getMessage());
}

// Check if a month is closed
function isMonthClosed($pdo, $month, $year) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM mois_clos WHERE mois = ? AND annee = ?");
        if ($stmt) {
            $stmt->execute([$month, $year]);
            return (bool)$stmt->fetch();
        }
    } catch (Exception $e) {
        error_log("Month check error: " . $e->getMessage());
    }
    return false;
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    verify_csrf_any();

    $congeId = (int)($_POST['cong_id'] ?? 0);
    $action = $_POST['action'];

    if (!$congeId) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'ID congé manquant']));
    }

    // Get the leave record
    $stmt = $pdo->prepare("SELECT c.*, u.id_agence FROM conges c JOIN users u ON c.id_user = u.id WHERE c.id = ?");
    $stmt->execute([$congeId]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leave) {
        http_response_code(404);
        exit(json_encode(['success' => false, 'message' => 'Congé introuvable']));
    }

    // Check authorization
    if ($roleId === 2 && $leave['id_agence'] !== $userAgenceId) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'Non autorisé']));
    }

    $month = (int)date('n', strtotime($leave['date_debut']));
    $year = (int)date('Y', strtotime($leave['date_debut']));

    if ($action === 'approve') {
        // Check if month is closed
        if (isMonthClosed($pdo, $month, $year)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Ce mois est fermé. Aucune modification n\'est possible.']));
        }

        $updateStmt = $pdo->prepare(
            "UPDATE conges SET statut = 'validé', date_validation = NOW(), id_validateur = ? WHERE id = ?"
        );
        $success = $updateStmt->execute([$userId, $congeId]);

        if ($success) {
            exit(json_encode(['success' => true, 'message' => 'Congé approuvé']));
        } else {
            http_response_code(500);
            exit(json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']));
        }

    } elseif ($action === 'reject') {
        // Check if month is closed
        if (isMonthClosed($pdo, $month, $year)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Ce mois est fermé. Aucune modification n\'est possible.']));
        }

        $raison = $_POST['raison'] ?? '';
        $updateStmt = $pdo->prepare(
            "UPDATE conges SET statut = 'refusé', date_validation = NOW(), id_validateur = ?, commentaire = ? WHERE id = ?"
        );
        $success = $updateStmt->execute([$userId, $raison, $congeId]);

        if ($success) {
            exit(json_encode(['success' => true, 'message' => 'Congé refusé']));
        } else {
            http_response_code(500);
            exit(json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']));
        }

    } elseif ($action === 'edit') {
        // Check if month is closed
        if (isMonthClosed($pdo, $month, $year)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Ce mois est fermé. Aucune modification n\'est possible.']));
        }

        // Only admin can edit leaves
        if ($roleId !== 1) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Seul l\'admin peut éditer les congés']));
        }

        $dateDebut = $_POST['date_debut'] ?? '';
        $dateFin = $_POST['date_fin'] ?? '';
        $motif = $_POST['motif'] ?? '';

        if (!$dateDebut || !$dateFin || !$motif) {
            http_response_code(400);
            exit(json_encode(['success' => false, 'message' => 'Données manquantes']));
        }

        $updateStmt = $pdo->prepare(
            "UPDATE conges SET date_debut = ?, date_fin = ?, motif = ? WHERE id = ?"
        );
        $success = $updateStmt->execute([$dateDebut, $dateFin, $motif, $congeId]);

        if ($success) {
            exit(json_encode(['success' => true, 'message' => 'Congé modifié']));
        } else {
            http_response_code(500);
            exit(json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']));
        }

    } elseif ($action === 'delete') {
        // Check if month is closed
        if (isMonthClosed($pdo, $month, $year)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Ce mois est fermé. Aucune modification n\'est possible.']));
        }

        // Only admin can delete leaves
        if ($roleId !== 1) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Seul l\'admin peut supprimer les congés']));
        }

        $deleteStmt = $pdo->prepare("UPDATE conges SET statut = 'archivé' WHERE id = ?");
        $success = $deleteStmt->execute([$congeId]);

        if ($success) {
            exit(json_encode(['success' => true, 'message' => 'Congé supprimé']));
        } else {
            http_response_code(500);
            exit(json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']));
        }

    } elseif ($action === 'close_month') {
        // Only admin can close months
        if ($roleId !== 1) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Seul l\'admin peut fermer un mois']));
        }

        $month = (int)($_POST['month'] ?? 0);
        $year = (int)($_POST['year'] ?? 0);

        if (!$month || !$year) {
            http_response_code(400);
            exit(json_encode(['success' => false, 'message' => 'Mois/année manquant']));
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO mois_clos (mois, annee, clos_par) VALUES (?, ?, ?)");
            $success = $stmt->execute([$month, $year, $userId]);

            if ($success) {
                exit(json_encode(['success' => true, 'message' => 'Mois fermé avec succès']));
            }
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                http_response_code(400);
                exit(json_encode(['success' => false, 'message' => 'Ce mois est déjà fermé']));
            }
            http_response_code(500);
            exit(json_encode(['success' => false, 'message' => 'Erreur lors de la fermeture du mois']));
        }
    }

    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Action non reconnue']));
}

// Get current month/year
$currentMonth = (int)date('n');
$currentYear = (int)date('Y');
$monthClosed = isMonthClosed($pdo, $currentMonth, $currentYear);

// Get pending leaves
$sql = "SELECT c.*, u.id, u.prenom, u.nom, u.id_agence, a.nom_agence
        FROM conges c
        JOIN users u ON c.id_user = u.id
        LEFT JOIN agences a ON u.id_agence = a.id
        WHERE c.statut = 'en_attente'";

$params = [];

// Apply role-based filter
if ($roleId === 2 && $userAgenceId) {
    $sql .= " AND u.id_agence = ?";
    $params[] = $userAgenceId;
}

$sql .= " ORDER BY c.date_demande DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pendingLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// French motifs
$motifLabels = [
    'absence_injustifiee_deduite' => 'Absence injustifiée',
    'absence_justifiee_non_deduite' => 'Absence justifiée',
    'absence_justifiee_deduite_heures' => 'Absence justifiée (heures)',
    'maladie_justifiee_non_deduite' => 'Maladie justifiée',
    'maladie_non_justifiee_deduite' => 'Maladie non justifiée',
    'maladie_justifiee_deduite' => 'Maladie justifiée (déduite)',
    'rtt' => 'RTT',
    'conges_payes' => 'Congés payés',
    'autre_legal_non_deduit' => 'Autre légal',
    'autre_legal_deduit' => 'Autre légal (déduit)',
];

?><!doctype html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme-rh.css">
    <?php include __DIR__ . '/inc/theme-init.php'; ?>
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Validation des Congés - MABOXIMMO</title>
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
        .mbi-content{flex:1;padding:30px;overflow-y:auto}
        .table-wrapper{background:#ffffff;border:1px solid var(--stroke);border-radius:14px;overflow:hidden}
        table{width:100%;border-collapse:collapse}
        th{background:rgba(72,120,166,0.08);padding:12px;text-align:left;font-size:12px;font-weight:600;color:var(--accent);border-bottom:1px solid var(--stroke)}
        td{padding:12px;border-bottom:1px solid var(--stroke);font-size:13px;color:var(--ink)}
        tr:hover{background:rgba(72,120,166,0.04)}
        .badge{display:inline-block;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600}
        .status-attente{background:rgba(255,165,0,0.2);color:#FFD700}
        .btn-group{display:flex;gap:6px;justify-content:flex-end}
        .btn{padding:6px 12px;border:1px solid;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
        .btn-approve{background:rgba(16,185,129,0.2);border-color:rgba(16,185,129,0.4);color:#4a6038}
        .btn-approve:hover{background:rgba(16,185,129,0.3)}
        .btn-reject{background:rgba(239,90,107,0.2);border-color:rgba(239,90,107,0.4);color:#FF6B7A}
        .btn-reject:hover{background:rgba(239,90,107,0.3)}
        .empty-state{text-align:center;padding:60px 30px;color:var(--muted)}
        .modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center}
        .modal.active{display:flex}
        .modal-content{background:var(--bg-soft);border:1px solid var(--stroke);border-radius:14px;padding:24px;max-width:400px;width:90%}
        .modal-header{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:16px;border-bottom:1px solid var(--stroke);padding-bottom:12px}
        .modal-body{margin-bottom:16px}
        .modal-field{display:flex;flex-direction:column;gap:6px}
        .modal-field label{font-size:12px;font-weight:600;color:var(--muted)}
        .modal-field textarea{padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit;resize:vertical;min-height:80px}
        .modal-footer{display:flex;gap:8px}
        .modal-footer button{flex:1;padding:8px;border:1px solid;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s}
        .modal-footer .btn-cancel{background:rgba(72,120,166,0.1);border-color:var(--accent);color:var(--accent)}
        .modal-footer .btn-cancel:hover{background:rgba(72,120,166,0.15)}
        .modal-footer .btn-confirm{background:rgba(239,90,107,0.2);border-color:rgba(239,90,107,0.4);color:#FF6B7A}
        .modal-footer .btn-confirm:hover{background:rgba(239,90,107,0.3)}
        .notification{position:fixed;top:20px;right:20px;background:rgba(16,185,129,0.2);border:1px solid rgba(16,185,129,0.4);color:#4a6038;padding:12px 16px;border-radius:8px;font-size:13px;z-index:2000;max-width:300px}
        .notification.error{background:rgba(239,90,107,0.2);border-color:rgba(239,90,107,0.4);color:#FF6B7A}
        @media(max-width:1024px){.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}.btn-group{flex-direction:column}}
    </style>
</head>
<body>

<?php include __DIR__ . '/inc/rh_sidebar.php'; ?>

<main class="mbi-main">
    <div class="mbi-topbar">
        <h1>✓ Validation des Congés</h1>
        <?php require_once __DIR__ . '/inc/role_switcher.php'; ?>
    </div>

    <div class="mbi-content">
        <!-- Close Month Section for Admin -->
        <?php if ($roleId === 1): ?>
        <div style="margin-bottom:24px;padding:16px;background:rgba(72,120,166,0.08);border:1px solid var(--stroke);border-radius:10px;display:flex;justify-content:space-between;align-items:center">
            <div>
                <div style="font-weight:600;margin-bottom:4px">Fermeture du mois actuel</div>
                <div style="font-size:12px;color:var(--muted)">Mois: <?=date('F Y')?><?=($monthClosed ? ' - ✓ FERMÉ' : ' - Ouvert')?></div>
            </div>
            <?php if (!$monthClosed): ?>
            <button class="btn btn-approve" onclick="closeMonth(<?=$currentMonth?>, <?=$currentYear?>)" style="background:rgba(16,185,129,0.3);border-color:rgba(16,185,129,0.6)">🔒 Fermer le mois</button>
            <?php else: ?>
            <span style="color:#4a6038;font-weight:600">Ce mois est fermé - Aucune modification possible</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($pendingLeaves)): ?>
            <div class="empty-state">
                <p>✓ Aucune demande de congé en attente de validation</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Employé</th>
                            <th>Dates</th>
                            <th>Motif</th>
                            <th>Demande</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingLeaves as $leave): ?>
                        <tr>
                            <td>
                                <strong><?=h($leave['prenom'] . ' ' . $leave['nom'])?></strong>
                                <?php if ($leave['nom_agence']): ?>
                                <div style="font-size:11px;color:var(--muted)">📍 <?=h($leave['nom_agence'])?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?=h(date('d/m/Y', strtotime($leave['date_debut'])))?> →
                                <?=h(date('d/m/Y', strtotime($leave['date_fin'])))?>
                            </td>
                            <td>
                                <strong><?=h($motifLabels[$leave['motif']] ?? $leave['motif'])?></strong>
                                <?php if ($leave['motif_detail']): ?>
                                <div style="font-size:11px;color:var(--muted)"><?=h($leave['motif_detail'])?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px;color:var(--muted)">
                                <?=h(date('d/m/Y H:i', strtotime($leave['date_demande'])))?><br>
                                <span class="badge status-attente">En attente</span>
                            </td>
                            <td>
                                <div class="btn-group" style="flex-direction:column;gap:8px">
                                    <div style="display:flex;gap:6px">
                                        <button class="btn btn-approve" onclick="approveLeave(<?=$leave['id']?>)" <?=($monthClosed ? 'disabled' : '')?> style="flex:1">✓ Approuver</button>
                                        <button class="btn btn-reject" onclick="openRejectModal(<?=$leave['id']?>)" <?=($monthClosed ? 'disabled' : '')?> style="flex:1">✕ Refuser</button>
                                    </div>
                                    <?php if ($roleId === 1): ?>
                                    <div style="display:flex;gap:6px">
                                        <button class="btn" onclick="openEditModal(<?=$leave['id']?>)" style="flex:1;background:rgba(255,212,121,0.2);border-color:rgba(255,212,121,0.4);color:#ffd479" <?=($monthClosed ? 'disabled' : '')?>>✎ Éditer</button>
                                        <button class="btn" onclick="deleteLeave(<?=$leave['id']?>)" style="flex:1;background:rgba(255,107,122,0.15);border-color:rgba(255,107,122,0.3);color:#ff9aab" <?=($monthClosed ? 'disabled' : '')?>>🗑 Supprimer</button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">Motif du refus</div>
        <div class="modal-body">
            <div class="modal-field">
                <label>Expliquez pourquoi ce congé est refusé:</label>
                <textarea id="rejectReason" placeholder="Saisissez le motif du refus..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeRejectModal()">Annuler</button>
            <button class="btn-confirm" onclick="confirmReject()">Confirmer le refus</button>
        </div>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">Éditer le congé</div>
        <div class="modal-body">
            <div class="modal-field">
                <label>Date début:</label>
                <input type="date" id="editDateDebut" style="padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit">
            </div>
            <div class="modal-field">
                <label>Date fin:</label>
                <input type="date" id="editDateFin" style="padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit">
            </div>
            <div class="modal-field">
                <label>Motif:</label>
                <select id="editMotif" style="padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit">
                    <option value="conges_payes">Congés payés</option>
                    <option value="rtt">RTT</option>
                    <option value="maladie_justifiee_non_deduite">Maladie justifiée</option>
                    <option value="maladie_non_justifiee_deduite">Maladie non justifiée</option>
                    <option value="maladie_justifiee_deduite">Maladie justifiée (déduite)</option>
                    <option value="absence_injustifiee_deduite">Absence injustifiée</option>
                    <option value="absence_justifiee_non_deduite">Absence justifiée</option>
                    <option value="autre_legal_non_deduit">Autre légal</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeEditModal()">Annuler</button>
            <button class="btn-confirm" onclick="confirmEdit()" style="background:rgba(255,212,121,0.3);border-color:rgba(255,212,121,0.5);color:#ffd479">Enregistrer</button>
        </div>
    </div>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="color:#ff9aab">Supprimer le congé</div>
        <div class="modal-body">
            <p style="color:var(--ink);margin-bottom:12px">Êtes-vous certain de vouloir supprimer ce congé?</p>
            <p style="color:var(--muted);font-size:12px">Cette action ne peut pas être annulée. Le congé sera archivé.</p>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeDeleteModal()">Annuler</button>
            <button class="btn-confirm" onclick="confirmDelete()" style="background:rgba(255,107,122,0.25);border-color:rgba(255,107,122,0.5);color:#ff9aab">Supprimer</button>
        </div>
    </div>
</div>

<script>
let rejectingLeaveId = null;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
function appendCsrf(formData) {
    if (CSRF_TOKEN) {
        formData.append('csrf_token', CSRF_TOKEN);
    }
    return formData;
}

function approveLeave(congeId) {
    if (!confirm('Êtes-vous sûr de vouloir approuver ce congé?')) return;

    const formData = new FormData();
    formData.append('action', 'approve');
    formData.append('cong_id', congeId);
    appendCsrf(formData);

    fetch('rh_conges_validation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('Congé approuvé avec succès', false);
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification('Erreur: ' + data.message, true);
        }
    })
    .catch(err => {
        console.error(err);
        showNotification('Erreur lors de la requête', true);
    });
}

function openRejectModal(congeId) {
    rejectingLeaveId = congeId;
    document.getElementById('rejectModal').classList.add('active');
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectReason').focus();
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
    rejectingLeaveId = null;
}

function confirmReject() {
    if (!rejectingLeaveId) return;

    const raison = document.getElementById('rejectReason').value.trim();
    if (!raison) {
        showNotification('Veuillez entrer un motif de refus', true);
        return;
    }

    const formData = new FormData();
    formData.append('action', 'reject');
    formData.append('cong_id', rejectingLeaveId);
    formData.append('raison', raison);
    appendCsrf(formData);

    fetch('rh_conges_validation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeRejectModal();
            showNotification('Congé refusé avec succès', false);
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification('Erreur: ' + data.message, true);
        }
    })
    .catch(err => {
        console.error(err);
        showNotification('Erreur lors de la requête', true);
    });
}

function showNotification(message, isError = false) {
    const notif = document.createElement('div');
    notif.className = 'notification' + (isError ? ' error' : '');
    notif.textContent = message;
    document.body.appendChild(notif);

    setTimeout(() => notif.remove(), 4000);
}

// Edit functions
let editingLeaveId = null;
let editingLeaveData = null;

function openEditModal(congeId) {
    editingLeaveId = congeId;
    // Get leave data from the row
    const row = event.target.closest('tr');
    const dateText = row.querySelector('td:nth-child(2)').textContent;
    const motifText = row.querySelector('td:nth-child(3)').textContent;

    // Parse dates (format: "dd/mm/yyyy → dd/mm/yyyy")
    const dates = dateText.split('→').map(d => d.trim());
    if (dates.length === 2) {
        const [d1, m1, y1] = dates[0].split('/');
        const [d2, m2, y2] = dates[1].split('/');
        document.getElementById('editDateDebut').value = `${y1}-${m1}-${d1}`;
        document.getElementById('editDateFin').value = `${y2}-${m2}-${d2}`;
    }

    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    editingLeaveId = null;
}

function confirmEdit() {
    if (!editingLeaveId) return;

    const dateDebut = document.getElementById('editDateDebut').value;
    const dateFin = document.getElementById('editDateFin').value;
    const motif = document.getElementById('editMotif').value;

    if (!dateDebut || !dateFin || !motif) {
        showNotification('Veuillez remplir tous les champs', true);
        return;
    }

    const formData = new FormData();
    formData.append('action', 'edit');
    formData.append('cong_id', editingLeaveId);
    formData.append('date_debut', dateDebut);
    formData.append('date_fin', dateFin);
    formData.append('motif', motif);
    appendCsrf(formData);

    fetch('rh_conges_validation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeEditModal();
            showNotification('Congé modifié avec succès', false);
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification('Erreur: ' + data.message, true);
        }
    })
    .catch(err => {
        console.error(err);
        showNotification('Erreur lors de la requête', true);
    });
}

// Delete functions
let deletingLeaveId = null;

function deleteLeave(congeId) {
    deletingLeaveId = congeId;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    deletingLeaveId = null;
}

function confirmDelete() {
    if (!deletingLeaveId) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('cong_id', deletingLeaveId);
    appendCsrf(formData);

    fetch('rh_conges_validation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeDeleteModal();
            showNotification('Congé supprimé avec succès', false);
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification('Erreur: ' + data.message, true);
        }
    })
    .catch(err => {
        console.error(err);
        showNotification('Erreur lors de la requête', true);
    });
}

// Close month function
function closeMonth(month, year) {
    if (!confirm(`Êtes-vous sûr de vouloir fermer le mois de ${new Date(year, month-1).toLocaleDateString('fr-FR', {month: 'long', year: 'numeric'})}? Aucune modification ne sera possible après.`)) return;

    const formData = new FormData();
    formData.append('action', 'close_month');
    formData.append('month', month);
    formData.append('year', year);
    appendCsrf(formData);

    fetch('rh_conges_validation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('Mois fermé avec succès', false);
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification('Erreur: ' + data.message, true);
        }
    })
    .catch(err => {
        console.error(err);
        showNotification('Erreur lors de la requête', true);
    });
}

document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

document.getElementById('rejectReason').addEventListener('keypress', function(e) {
    if (e.ctrlKey && e.key === 'Enter') confirmReject();
});
</script>

</body>
</html>

