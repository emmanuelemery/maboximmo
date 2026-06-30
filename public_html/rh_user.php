<?php
/*
 * rh_user.php  (v2 — layout_maboximmo)
 * Rôle     : Gestion Utilisateurs RH
 * Date     : 2026-04-09
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$roleId      = current_role_id();
$agenceScope = function_exists('can_manage_salaires_agence') ? can_manage_salaires_agence() : 0;
if ($roleId !== 1 && $agenceScope === 0) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Create service column if it doesn't exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'service'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN service VARCHAR(20) DEFAULT 'gestion' AFTER id_societe");
    }
} catch (Exception $e) {
    // Column might exist, continue
}

// Create Externe société if it doesn't exist
try {
    $stmt = $pdo->prepare("SELECT id FROM societes WHERE nom = ?");
    $stmt->execute(['Externe']);
    if ($stmt->rowCount() === 0) {
        $pdo->exec("INSERT INTO societes (nom, actif) VALUES ('Externe', 1)");
    }
} catch (Exception $e) {
    // Société might exist, continue
}

// Get filter values from URL
$filterSociete = !empty($_GET['societe']) ? (int)$_GET['societe'] : null;
$filterAgence = !empty($_GET['agence']) ? (int)$_GET['agence'] : null;
$filterActif = isset($_GET['actif']) && $_GET['actif'] !== '' ? (int)$_GET['actif'] : null;
$filterRole = !empty($_GET['role']) ? (int)$_GET['role'] : null;
$filterService = !empty($_GET['service']) ? (string)$_GET['service'] : null;
$filterQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Get all societes, agences, roles
$societes = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
$roles = $pdo->query("SELECT id, nom FROM roles WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Build SQL query
$sql = "SELECT u.*, COALESCE(u.service, 'gestion') as service, s.nom as societe_nom, a.nom_agence, r.nom as role_nom FROM users u
        LEFT JOIN societes s ON u.id_societe = s.id
        LEFT JOIN agences a ON u.id_agence = a.id
        LEFT JOIN roles r ON u.id_role = r.id
        WHERE 1=1";

$params = [];

// ── Périmètre RH : UNIQUEMENT les collaborateurs salariés des sociétés commerciales.
// On exclut les "tiers" MaBoxImmo : rôles non-staff (Propriétaire VIP, etc. → id_role>3),
// les comptes marqués externes, et les sociétés "PRESTATAIRES EXTERNES".
// NB : ne PAS filtrer sur est_salarie (gérants/dirigeants comme Emmanuel ont est_salarie=0).
$sql .= " AND u.id_role IN (1,2,3)
          AND (u.externe = 0 OR u.externe IS NULL)
          AND (s.nom IS NULL OR UPPER(s.nom) NOT LIKE '%EXTERNE%')";

// Recherche par nom / prénom
if ($filterQ !== '') {
    $sql .= " AND (u.nom LIKE ? OR u.prenom LIKE ? OR CONCAT(u.prenom,' ',u.nom) LIKE ? OR CONCAT(u.nom,' ',u.prenom) LIKE ?)";
    $like = '%' . $filterQ . '%';
    array_push($params, $like, $like, $like, $like);
}

// Gestionnaire agence : forcé sur son agence
if ($agenceScope > 0) {
    $sql .= " AND u.id_agence = ?";
    $params[] = $agenceScope;
}

if ($filterSociete !== null) {
    $sql .= " AND u.id_societe = ?";
    $params[] = $filterSociete;
}

if ($filterAgence !== null) {
    $sql .= " AND u.id_agence = ?";
    $params[] = $filterAgence;
}

if ($filterActif !== null) {
    $sql .= " AND u.actif = ?";
    $params[] = $filterActif;
}

if ($filterRole !== null) {
    $sql .= " AND u.id_role = ?";
    $params[] = $filterRole;
}

if ($filterService !== null) {
    $sql .= " AND COALESCE(u.service, 'gestion') = ?";
    $params[] = $filterService;
}

$sql .= " ORDER BY u.actif DESC, s.nom, a.nom_agence, u.nom, u.prenom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Services (multi) par collaborateur ───────────────────────────────
// Catalogue des 4 métiers. Un collaborateur peut en cumuler plusieurs.
$SERVICES = ['gestion'=>'Gestion','syndic'=>'Syndic','transaction'=>'Transaction','comptabilite'=>'Comptabilité'];
$userServices = [];
try {
    foreach ($pdo->query("SELECT id_user, service FROM user_services") as $r) {
        $userServices[(int)$r['id_user']][] = (string)$r['service'];
    }
} catch (Throwable $e) { /* table créée par migration 20260629_user_services */ }

// ── KPIs ─────────────────────────────────────────────────────────────
// On ne compte QUE les collaborateurs actifs.
$activeList = array_filter($users, fn($u) => (int)$u['actif'] === 1);
$totalUsers = count($activeList);
$svcCounts = array_fill_keys(array_keys($SERVICES), 0);
foreach ($activeList as $u) {
    foreach (($userServices[(int)$u['id']] ?? []) as $s) {
        if (isset($svcCounts[$s])) $svcCounts[$s]++;
    }
}

// ── Layout variables ─────────────────────────────────────────────────
$layout_title      = 'Collaborateurs';
$layout_page_title = ''; // titre déjà présent dans le fil d'Ariane de la topbar (pas de doublon)
$layout_module     = 'Ma Box RH';
$layout_sidebar    = 'rh_sidebar';

$layout_head_kpis = '
<div class="ph-kpi"><span class="ph-kpi-val">'.$totalUsers.'</span><span class="ph-kpi-lbl">Collaborateurs</span></div>
<div class="ph-kpi"><span class="ph-kpi-val" style="color:#4a6038">'.$svcCounts['gestion'].'</span><span class="ph-kpi-lbl">Gestion</span></div>
<div class="ph-kpi"><span class="ph-kpi-val" style="color:#7a4aa0">'.$svcCounts['syndic'].'</span><span class="ph-kpi-lbl">Syndic</span></div>
<div class="ph-kpi"><span class="ph-kpi-val" style="color:#b7791f">'.$svcCounts['transaction'].'</span><span class="ph-kpi-lbl">Transaction</span></div>
<div class="ph-kpi"><span class="ph-kpi-val" style="color:#2563eb">'.$svcCounts['comptabilite'].'</span><span class="ph-kpi-lbl">Comptabilité</span></div>';

$layout_head_actions = '
<button onclick="window.location.href=\'rh_user_add.php\'" class="ph-btn primary">+ Ajouter collaborateur</button>';

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* Override dark → white (cohérence avec les autres pages MaBoxImmo) */
    :root{--bg-primary:#ffffff !important;--bg-secondary:#f7f8fa !important;--shadow-dark:#d4d7de !important;--shadow-light:#ffffff !important;--stroke:rgba(196,192,186,0.3);--ink:#1a1816;--muted:#8a8680;--accent:#4878a6}
    .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
    .filter-group{display:flex;flex-direction:column;gap:6px}
    .filter-group label{font-size:12px;font-weight:600;color:#8a8680}
    .filter-group select{padding:8px 12px;background:#ffffff;border:1px solid #d4d7de;border-radius:8px;color:#1a1816;font-family:inherit;cursor:pointer}
    .card{background:#ffffff;border:1px solid #d4d7de;border-radius:14px;overflow:hidden;margin-bottom:20px;box-shadow:4px 4px 12px #d4d7de,-4px -4px 12px #fff}
    .card-head{padding:16px;border-bottom:1px solid rgba(196,192,186,0.3);font-weight:600;color:#1a1816}
    .user-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:20px}
    .user-card{background:#ffffff;border:1px solid #d4d7de;border-radius:14px;overflow:hidden;transition:all 0.2s;box-shadow:4px 4px 12px #d4d7de,-4px -4px 12px #fff}
    .user-card:hover{border-color:#4878a6;box-shadow:6px 6px 16px #d4d7de,-6px -6px 16px #fff;transform:translateY(-2px)}
    .user-card-head{padding:16px;background:#f7f8fa;border-bottom:1px solid rgba(196,192,186,0.3);display:flex;justify-content:space-between;align-items:center;gap:12px}
    .user-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:white;font-size:18px;cursor:pointer;border:2px solid #d4d7de;transition:all 0.2s;flex-shrink:0}
    .user-avatar:hover{border-color:#4878a6}
    .user-info{flex:1;min-width:0}
    .user-card-name{font-weight:600;color:#2f587d;font-size:14px}
    .user-name-link{color:inherit;text-decoration:none}
    .user-name-link:hover{text-decoration:underline}
    .user-card-meta{font-size:11px;color:#8a8680;margin-top:2px}
    .user-status{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:#e8efe0;color:#4a6038}
    .color-picker-modal{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#ffffff;border:1px solid #d4d7de;border-radius:14px;padding:20px;z-index:1000;box-shadow:0 10px 40px rgba(0,0,0,0.15)}
    .color-picker-modal.active{display:block}
    .color-picker-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:999}
    .color-picker-overlay.active{display:block}
    .color-grid{display:grid;grid-template-columns:repeat(10,1fr);gap:10px;margin-bottom:15px;max-height:300px;overflow-y:auto}
    .color-option{width:40px;height:40px;border-radius:50%;cursor:pointer;border:3px solid transparent;transition:all 0.2s}
    .color-option:hover{border-color:#4878a6}
    .color-option.selected{border-color:#2f587d;box-shadow:0 0 10px rgba(72,120,166,0.3)}
    .user-card-body{padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .user-field{display:flex;flex-direction:column;gap:4px}
    .user-field label{font-size:10px;font-weight:600;color:#8a8680;text-transform:uppercase}
    .user-field input,.user-field select{padding:6px 8px;background:#ffffff;border:1px solid #d4d7de;border-radius:6px;color:#1a1816;font-family:inherit;font-size:11px}
    .user-field input:focus,.user-field select:focus{outline:none;border-color:#4878a6;box-shadow:0 0 0 2px rgba(72,120,166,0.15)}
    .user-field.full{grid-column:1/-1}
    .user-actions{padding:12px 16px;background:#f7f8fa;border-top:1px solid rgba(196,192,186,0.3);display:flex;gap:8px}
    .btn-user{flex:1;padding:6px 10px;background:#ffffff;border:1px solid #4878a6;color:#4878a6;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;transition:all 0.2s;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:4px;box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff}
    .btn-user:hover{background:#4878a6;color:#fff}
    .btn-archive{background:#fff;border-color:#c97b2e;color:#c97b2e}
    .btn-archive:hover{background:#c97b2e;color:#fff}
    .btn-restore{background:#fff;border-color:#4a6038;color:#4a6038}
    .btn-restore:hover{background:#4a6038;color:#fff}
    .reset-btn{padding:4px 8px;background:#e8efe0;border:1px solid rgba(74,96,56,0.3);color:#4a6038;border-radius:6px;cursor:pointer;font-weight:600;font-size:16px;text-decoration:none;display:flex;align-items:flex-end;justify-content:center;transition:all 0.2s;height:100%;min-height:20px;width:fit-content}
    .reset-btn:hover{background:#d8e8c8;border-color:#4a6038}
    .service-badge{display:inline-block;padding:4px 10px;border-radius:12px;font-size:10px;font-weight:600;margin-left:8px;text-transform:uppercase}
    .service-gestion{background:#e8efe0;color:#4a6038;border:1px solid rgba(74,96,56,0.3)}
    .service-syndic{background:#f0e8f8;color:#7a4aa0;border:1px solid rgba(122,74,160,0.3)}
    .service-btn{padding:8px 12px;border:1.5px solid #d4d7de;border-radius:6px;background:#ffffff;color:#8a8680;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all 0.2s}
    .service-btn:hover{border-color:#4878a6;color:#2f587d}
    /* Chips multi-services */
    .svc-chips{display:flex;gap:6px;flex-wrap:wrap}
    .svc-chip{padding:6px 12px;border:1.5px solid #d4d7de;border-radius:999px;background:#fff;color:#8a8680;font-family:inherit;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s}
    .svc-chip:hover{border-color:#4878a6}
    .svc-chip.on.svc-gestion{background:#e8efe0;border-color:#4a6038;color:#4a6038}
    .svc-chip.on.svc-syndic{background:#f0e8f8;border-color:#7a4aa0;color:#7a4aa0}
    .svc-chip.on.svc-transaction{background:#fdf3d8;border-color:#b7791f;color:#b7791f}
    .svc-chip.on.svc-comptabilite{background:#e3edfd;border-color:#2563eb;color:#2563eb}
    .svc-badge-gestion{background:#e8efe0;color:#4a6038;border:1px solid rgba(74,96,56,.3)}
    .svc-badge-syndic{background:#f0e8f8;color:#7a4aa0;border:1px solid rgba(122,74,160,.3)}
    .svc-badge-transaction{background:#fdf3d8;color:#b7791f;border:1px solid rgba(183,121,31,.3)}
    .svc-badge-comptabilite{background:#e3edfd;color:#2563eb;border:1px solid rgba(37,99,235,.3)}
    .user-card.inactive{border-color:#c97b2e;background:#fef8f0}
    .user-card.inactive .user-card-head{background:#fdf2e6}
    .user-list{display:none;width:100%}
    .user-list.active{display:block}
    /* KPIs agrandis (lisibilité) */
    .mbi-page-head .ph-kpi{padding:10px 18px;border-radius:12px}
    .mbi-page-head .ph-kpi-val{font-size:28px}
    .mbi-page-head .ph-kpi-lbl{font-size:11px}
    /* Boutons d'action agrandis */
    .mbi-page-head .ph-right{padding:16px 18px;gap:12px;grid-template-columns:1fr}
    .ph-btn{height:auto;min-height:42px;width:auto;min-width:160px;padding:11px 20px;font-size:14px;border-radius:10px}
    .ph-btn svg{width:15px;height:15px}
    /* Champ recherche */
    .filter-group input{padding:9px 12px;background:#fff;border:1px solid #d4d7de;border-radius:8px;color:#1a1816;font-family:inherit;font-size:14px;width:100%}
    .filter-group input:focus{outline:none;border-color:#4878a6;box-shadow:0 0 0 2px rgba(72,120,166,0.15)}
    .btn-search{padding:9px 18px;background:#4878a6;border:none;color:#fff;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;height:38px}
    .btn-search:hover{opacity:.9}
    .user-list-table{width:100%;border-collapse:collapse;background:#ffffff;border:1px solid #d4d7de;border-radius:14px;overflow:hidden;box-shadow:4px 4px 12px #d4d7de,-4px -4px 12px #fff}
    .user-list-table thead{background:#f7f8fa}
    .user-list-table th{padding:12px 16px;text-align:left;color:#4a6038;font-weight:700;font-size:12px;border-bottom:1px solid rgba(196,192,186,0.3);text-transform:uppercase}
    .user-list-table td{padding:12px 16px;border-bottom:1px solid rgba(196,192,186,0.2);font-size:13px;color:#1a1816}
    .user-list-table tr:hover{background:rgba(72,120,166,0.04);cursor:pointer}
    .user-list-table tr.inactive{background:#fef8f0}
    .user-list-table tr.inactive:hover{background:#fdf2e6}
    .user-list-table tr.inactive td{color:#8a5040}
    .view-toggle{display:flex;gap:8px;align-items:center}
    .view-toggle button{padding:8px 14px;background:#ffffff;border:1px solid #d4d7de;color:#4878a6;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s;box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff}
    .view-toggle button.active{background:#4878a6;border-color:#4878a6;color:#fff}
    .view-toggle button:not(.active):hover{border-color:#4878a6}
    @media(max-width:900px){.user-cards{grid-template-columns:1fr}}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const CSRF_HEADERS = CSRF_TOKEN ? {'X-CSRF-Token': CSRF_TOKEN} : {};
const debounceTimers = {};

document.querySelectorAll('.field-input').forEach(input => {
    input.addEventListener('change', debounceFieldSave);
    input.addEventListener('input', debounceFieldSave);
});

// Multi-services : toggle d'un chip → INSERT/DELETE dans user_services
function toggleService(el, userId, service) {
    const willBeOn = !el.classList.contains('on');
    el.disabled = true;
    fetch('api/user_service_toggle.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({ id_user: userId, service: service, on: willBeOn ? 1 : 0 })
    })
    .then(r => r.json())
    .then(j => { if (j && j.success) { el.classList.toggle('on', willBeOn); } else { alert((j && j.message) || 'Erreur'); } })
    .catch(() => alert('Erreur réseau'))
    .finally(() => { el.disabled = false; });
}

// Service button handlers
document.querySelectorAll('.service-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const userId = this.dataset.userId;
        const service = this.dataset.service;
        const form = this.closest('.user-form');

        // Update hidden input
        form.querySelector('input[name="service"]').value = service;

        // Save to database and reload
        fetch('api/update_user.php', {
            method: 'POST',
            headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
            body: JSON.stringify({userId, field: 'service', value: service})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Reload page and scroll to user
                window.location.hash = 'user-' + userId;
                location.reload();
            } else {
                alert('Erreur: ' + (data.message || 'Impossible de mettre à jour le service'));
            }
        })
        .catch(err => console.error(err));
    });
});

function debounceFieldSave(e) {
    const input = e.target;
    const form = input.closest('.user-form');
    const userId = form.dataset.userId;
    const fieldName = input.dataset.field;

    clearTimeout(debounceTimers[userId + '_' + fieldName]);
    debounceTimers[userId + '_' + fieldName] = setTimeout(() => {
        saveField(userId, fieldName, input.value);
    }, 500);
}

function saveField(userId, fieldName, value) {
    fetch('api/update_user.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({userId, field: fieldName, value})
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) console.error('Save failed');
    })
    .catch(err => console.error(err));
}

function archiveUser(userId, newStatus) {
    if (!confirm(`Êtes-vous sûr de vouloir ${newStatus ? 'restaurer' : 'archiver'} cet utilisateur?`)) return;

    fetch('api/update_user.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({userId, field: 'actif', value: newStatus})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Erreur: ' + (data.message || 'Impossible de modifier le statut'));
    })
    .catch(err => alert('Erreur: ' + err.message));
}

function openUserDocs(userId) {
    window.location.href = 'rh_documents.php?user_id=' + userId + '&return_to=' + encodeURIComponent('rh_user.php');
}

function openUserHistory(userId) {
    window.location.href = 'rh_user_historiq.php?user_id=' + userId;
}

function openColorPicker(userId, currentColor) {
    const input = document.createElement('input');
    input.type = 'color';
    input.value = currentColor || '#FF6B6B';
    input.onchange = () => saveUserColor(userId, input.value);
    input.click();
}

function saveUserColor(userId, color) {
    fetch('api/update_user.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({userId, field: 'couleur', value: color})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Erreur: ' + (data.message || 'Impossible de changer la couleur'));
        }
    })
    .catch(err => alert('Erreur: ' + err.message));
}

function openPasswordModal(userId) {
    const password = prompt('Entrez le nouveau mot de passe pour cet utilisateur:');
    if (!password) return;
    if (password.length < 6) {
        alert('Le mot de passe doit contenir au minimum 6 caractères');
        return;
    }

    fetch('api/update_user_password.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({userId, password})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Mot de passe mis à jour avec succès');
        } else {
            alert('Erreur: ' + (data.message || 'Impossible de mettre à jour le mot de passe'));
        }
    })
    .catch(err => alert('Erreur: ' + err.message));
}

function sendMailToUser(userId) {
    const message = prompt('Message à envoyer à l\'utilisateur:');
    if (!message) return;

    fetch('api/send_mail_to_user.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({userId, message})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Email envoyé avec succès');
        } else {
            alert('Erreur: ' + (data.message || 'Impossible d\'envoyer l\'email'));
        }
    })
    .catch(err => alert('Erreur: ' + err.message));
}

function switchView(view) {
    const cardsView = document.getElementById('user-cards');
    const listView = document.getElementById('user-list');
    const cardBtn = document.getElementById('view-cards-btn');
    const listBtn = document.getElementById('view-list-btn');

    if (view === 'cards') {
        cardsView.style.display = 'grid';
        listView.classList.remove('active');
        cardBtn.classList.add('active');
        listBtn.classList.remove('active');
    } else {
        cardsView.style.display = 'none';
        listView.classList.add('active');
        cardBtn.classList.remove('active');
        listBtn.classList.add('active');
    }
}

function openUserCard(userId) {
    // Switch to cards view
    switchView('cards');

    // Scroll to user card
    setTimeout(() => {
        const userCard = document.querySelector(`#user-${userId}`);
        if (userCard) {
            userCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            userCard.style.boxShadow = '0 0 20px rgba(72,120,166,0.3)';
            setTimeout(() => {
                userCard.style.boxShadow = '';
            }, 2000);
        }
    }, 100);
}

// Auto-scroll to highlighted user
window.addEventListener('load', function() {
    const params = new URLSearchParams(window.location.search);
    const highlightUserId = params.get('highlight_user');
    if (highlightUserId) {
        const userCard = document.querySelector(`[data-user-id="${highlightUserId}"]`);
        if (userCard) {
            setTimeout(() => {
                userCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                userCard.style.boxShadow = '0 0 20px rgba(72,120,166,0.3)';
                setTimeout(() => {
                    userCard.style.boxShadow = '';
                }, 2000);
            }, 100);
        }
    }
});
</script>
EXTRAJS;

// ── Content ──────────────────────────────────────────────────────────
ob_start();
?>
        <div class="filters">
            <form method="GET" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;width:100%">
                <div class="filter-group">
                    <label>Agence</label>
                    <select name="agence" onchange="this.form.submit()" <?=($agenceScope>0?'disabled':'')?>>
                        <option value="">Toutes les agences</option>
                        <?php foreach($agences as $a): ?>
                            <option value="<?=$a['id']?>" <?=($filterAgence==$a['id']?'selected':'')?>><?=h($a['nom_agence'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex:1;min-width:240px">
                    <label>Recherche par nom</label>
                    <input type="text" name="q" value="<?=h($filterQ)?>" placeholder="Nom ou prénom…" autocomplete="off">
                </div>
                <button type="submit" class="btn-search">🔍 Rechercher</button>
                <a href="rh_user.php" class="reset-btn" title="Réinitialiser">↻</a>
            </form>
        </div>

        <div class="user-cards" id="user-cards">
            <?php
            $defaultColors = [
                '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#6C5CE7', '#A29BFE', '#F1A7A7', '#74B9FF', '#81ECEC',
                '#FDCB6E', '#6C7A89', '#FF9FF3', '#54A0FF', '#48DBFB', '#1DD1A1', '#EE5A6F', '#F368E0', '#FF6B9D', '#C44569',
                '#00D2D3', '#FF8C94', '#A8E6CF', '#FFD3B6', '#FFAAA5', '#FF8B94', '#A0D995', '#C7CEEA', '#FFA07A', '#20B2AA',
                '#9370DB', '#FFB6C1', '#87CEEB', '#DDA0DD', '#F0E68C', '#98FB98', '#FFB347', '#87CEFA', '#DEB887', '#FF69B4',
                '#BA55D3', '#CD5C5C', '#6495ED', '#00CED1', '#9932CC', '#FF1493', '#00FA9A', '#FFD700', '#32CD32', '#4169E1'
            ];
            foreach($users as $user):
                // Utiliser la couleur de la BDD ou une couleur par défaut
                $userColor = (!empty($user['couleur']) && $user['couleur'] !== null)
                    ? $user['couleur']
                    : $defaultColors[$user['id'] % count($defaultColors)];
                $userInitials = strtoupper(substr($user['prenom'] ?? '', 0, 1) . substr($user['nom'] ?? '', 0, 1));
            ?>
            <div class="user-card <?=!$user['actif']?'inactive':''?>" id="user-<?=$user['id']?>">
                <div class="user-card-head">
                    <div class="user-avatar" onclick="openColorPicker(<?=$user['id']?>, '<?=$userColor?>')" style="background-color: <?=$userColor?>" title="Cliquer pour changer la couleur"><?=$userInitials?></div>
                    <div class="user-info">
                        <div class="user-card-name"><a class="user-name-link" href="rh_profil.php?id=<?= (int)$user['id'] ?>&tab=rh"><?=h($user['nom'])?> <?=h($user['prenom']??'')?></a></div>
                        <div class="user-card-meta">ID: <?=$user['id']?> • <?=h($user['username'] ?? 'N/A')?></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                        <?php foreach (($userServices[(int)$user['id']] ?? []) as $sk): if(!isset($SERVICES[$sk])) continue; ?>
                            <span class="service-badge svc-badge-<?=$sk?>"><?=h($SERVICES[$sk])?></span>
                        <?php endforeach; ?>
                        <span class="user-status"><?=$user['actif'] ? '✓ Actif' : '✕ Inactif'?></span>
                    </div>
                </div>

                <form class="user-form" data-user-id="<?=$user['id']?>">
                    <div class="user-card-body">
                        <div class="user-field">
                            <label>Email</label>
                            <input type="email" name="email" value="<?=h($user['email'] ?? '')?>" data-field="email" class="field-input">
                        </div>

                        <div class="user-field">
                            <label>Téléphone</label>
                            <input type="tel" name="telephone" value="<?=h($user['telephone'] ?? '')?>" data-field="telephone" class="field-input">
                        </div>

                        <div class="user-field">
                            <label>Rôle</label>
                            <select name="id_role" data-field="id_role" class="field-input">
                                <?php foreach($roles as $r): ?>
                                    <option value="<?=$r['id']?>" <?=($user['id_role']==$r['id']?'selected':'')?>><?=h($r['nom'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="user-field">
                            <label>Agence</label>
                            <select name="id_agence" data-field="id_agence" class="field-input">
                                <option value="">Aucune</option>
                                <?php foreach($agences as $a): ?>
                                    <option value="<?=$a['id']?>" <?=($user['id_agence']==$a['id']?'selected':'')?>><?=h($a['nom_agence'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="user-field">
                            <label>Société</label>
                            <select name="id_societe" data-field="id_societe" class="field-input">
                                <option value="">Aucune</option>
                                <?php foreach($societes as $s): ?>
                                    <option value="<?=$s['id']?>" <?=($user['id_societe']==$s['id']?'selected':'')?>><?=h($s['nom'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="user-field full">
                            <label>Services (plusieurs possibles)</label>
                            <div class="svc-chips">
                                <?php $uServ = $userServices[(int)$user['id']] ?? []; foreach ($SERVICES as $sk => $sl): $on = in_array($sk, $uServ, true); ?>
                                <button type="button" class="svc-chip svc-<?=$sk?> <?=$on?'on':''?>" data-service="<?=$sk?>" onclick="toggleService(this, <?=$user['id']?>, '<?=$sk?>')"><?=$sl?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="user-actions" style="flex-wrap:wrap">
                        <button type="button" class="btn-user" onclick="openUserDocs(<?=$user['id']?>)" style="flex:1">📄 Docs</button>
                        <button type="button" class="btn-user" onclick="openUserHistory(<?=$user['id']?>)" style="flex:1">📊 Historique</button>
                        <button type="button" class="btn-user" onclick="openPasswordModal(<?=$user['id']?>)" style="flex:1">🔐 MDP</button>
                        <button type="button" class="btn-user" onclick="sendMailToUser(<?=$user['id']?>)" style="flex:1">✉️ Mail</button>
                        <?php if($user['actif']): ?>
                            <button type="button" class="btn-user btn-archive" onclick="archiveUser(<?=$user['id']?>, 0)" style="flex:1">🗂️ Archive</button>
                        <?php else: ?>
                            <button type="button" class="btn-user btn-restore" onclick="archiveUser(<?=$user['id']?>, 1)" style="flex:1">↩️ Restaurer</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if(empty($users)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
            <p>Aucun utilisateur trouvé avec les filtres appliqués</p>
        </div>
        <?php endif; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
