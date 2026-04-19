<?php
// sidebar_rh.php — Sidebar V2 MaBoxImmo / Module RH
// Inclusion : include 'sidebar_rh.php';

// Détection automatique par nom de fichier (rh_rubrique_action.php)
if (!isset($current_page)) {
    $basename = basename($_SERVER['PHP_SELF'], '.php');
    $parts = explode('_', $basename);
    $rubrique = $parts[1] ?? '';
    $rubrique_map = [
        'salaire'   => 'salaires',
        'salaires'  => 'salaires',
        'conge'     => 'conges',
        'conges'    => 'conges',
        'entretien' => 'entretiens',
        'indemnite' => 'frais',
        'document'  => 'documents',
        'documents' => 'documents',
        'mail'      => 'emails',
        'mails'     => 'emails',
        'dashboard' => 'dashboard',
        'admin'     => 'admin',
    ];
    $current_page = $rubrique_map[$rubrique] ?? ($_GET['page'] ?? 'dashboard');
    // Détection page admin_dashboard.php
    if ($basename === 'admin_dashboard') $current_page = 'admin';
    if ($basename === 'admin_migrations') $current_page = 'admin_migrations';
    if ($basename === 'admin_bailleurs') $current_page = 'admin_bailleurs';
    if ($basename === 'admin_referentiel') $current_page = 'admin_referentiel';
    if ($basename === 'tiers_nouveau') $current_page = 'tiers_nouveau';
}

// Rôle courant (pour section Administrer)
$sb_role_id = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['role_id'] ?? 0);

// Session data
$agency_name  = $_SESSION['societe_nom']  ?? 'Agence';
$user_name    = ($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '');
$user_name    = trim($user_name) ?: ($_SESSION['username'] ?? 'Utilisateur');
$user_role    = $_SESSION['role_label']   ?? 'Collaborateur';
$initials     = '';
$parts_name   = explode(' ', trim($user_name));
if (count($parts_name) >= 2) {
    $initials = strtoupper(substr($parts_name[0], 0, 1) . substr($parts_name[1], 0, 1));
} else {
    $initials = strtoupper(substr($user_name, 0, 2));
}
?>
<style>
/* ── Sidebar V2 ──────────────────────────────── */
.sb {
    position: fixed;
    left: 10px;
    top: 10px;
    bottom: 10px;
    width: 200px;
    background: #ede8e0;
    border-radius: 18px;
    box-shadow: 8px 8px 22px #c8c4be, -8px -8px 18px var(--shadow-light);
    display: flex;
    flex-direction: column;
    z-index: 200;
    overflow: hidden;
}

/* Head */
.sb-head {
    padding: 20px 16px 14px;
    flex-shrink: 0;
}
.sb-logo {
    font-family: 'Sora', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: #2c2a28;
    letter-spacing: -0.02em;
    line-height: 1;
    margin-bottom: 4px;
}
.sb-logo span { color: #7a9060; }
.sb-tagline {
    font-family: 'DM Mono', monospace;
    font-size: 9px;
    color: #9a9690;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.sb-agency {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f7f8fa;
    border-radius: 8px;
    padding: 5px 8px;
    box-shadow: inset 2px 2px 5px #cac6c0, inset -2px -2px 5px #f8f4ee;
}
.sb-agency-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #7a9060;
    flex-shrink: 0;
}
.sb-agency-name {
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    font-weight: 500;
    color: #4a4844;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Separator */
.sb-sep {
    height: 1px;
    margin: 0 14px;
    background: linear-gradient(90deg, transparent, #c8c4be, transparent);
    flex-shrink: 0;
}

/* Nav scroll area */
.sb-nav {
    flex: 1;
    overflow-y: auto;
    padding: 8px 10px;
    scrollbar-width: none;
}
.sb-nav::-webkit-scrollbar { display: none; }

/* Section label */
.sb-lbl {
    font-family: 'DM Mono', monospace;
    font-size: 9px;
    font-weight: 600;
    color: #9a9690;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    padding: 6px 8px 2px;
    display: block;
}

/* Nav item */
.sb-item {
    display: flex;
    align-items: center;
    gap: 9px;
    height: 38px;
    padding: 0 10px;
    border-radius: 999px;
    text-decoration: none;
    background: transparent;
    transition: background 0.18s, box-shadow 0.18s;
    margin-bottom: 2px;
}
.sb-item:hover {
    background: rgba(0,0,0,0.04);
}
.sb-item.active {
    background: var(--bg-primary);
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px var(--shadow-light);
}
.sb-item svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    opacity: 0.7;
}
.sb-item.active svg { opacity: 1; }
.sb-item-lbl {
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 500;
    color: #6a6864;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sb-item.active .sb-item-lbl {
    color: #1a1816;
    font-weight: 700;
}

/* Footer */
.sb-footer {
    padding: 10px 12px 14px;
    flex-shrink: 0;
}
.sb-user {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 8px;
    border-radius: 12px;
    background: #f7f8fa;
    box-shadow: inset 2px 2px 5px #cac6c0, inset -2px -2px 5px #f8f4ee;
    margin-bottom: 8px;
}
.sb-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6888a8, #36577d);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'DM Mono', monospace;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.sb-user-info { flex: 1; min-width: 0; }
.sb-user-name {
    font-family: 'Sora', sans-serif;
    font-size: 11px;
    font-weight: 600;
    color: #2c2a28;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sb-user-role {
    font-family: 'DM Mono', monospace;
    font-size: 9px;
    color: #9a9690;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.sb-gear {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: transparent;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9a9690;
    flex-shrink: 0;
    transition: color 0.15s;
}
.sb-gear:hover { color: #4a4844; }
.sb-logout {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    height: 34px;
    border-radius: 999px;
    background: var(--bg-primary);
    box-shadow: 3px 3px 8px #c8c4be, -3px -3px 8px var(--shadow-light);
    border: none;
    cursor: pointer;
    font-family: 'Sora', sans-serif;
    font-size: 11px;
    font-weight: 600;
    color: #c87870;
    text-decoration: none;
    transition: box-shadow 0.15s;
}
.sb-logout:hover {
    box-shadow: inset 2px 2px 6px #c8c4be, inset -2px -2px 5px var(--shadow-light);
    color: #a85850;
}
</style>

<nav class="sb">
    <!-- Head -->
    <div class="sb-head">
        <div class="sb-logo">MaBox<span>Immo</span></div>
        <div class="sb-tagline">Gestion immobilière</div>
        <div class="sb-agency">
            <div class="sb-agency-dot"></div>
            <span class="sb-agency-name"><?= htmlspecialchars($agency_name) ?></span>
        </div>
    </div>

    <div class="sb-sep"></div>

    <!-- Nav -->
    <div class="sb-nav">
        <!-- Navigation -->
        <span class="sb-lbl">Navigation</span>

        <a href="agency_dashboard.php" class="sb-item <?= $current_page === 'agency' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'agency' ? '#7a9060' : '#9ab078' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <span class="sb-item-lbl">Ma Box Agency</span>
        </a>

        <a href="rh_dashboard.php" class="sb-item <?= $current_page === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'dashboard' ? '#36577d' : '#6888a8' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="sb-item-lbl">Ma Box RH</span>
        </a>

        <a href="net_dashboard.php" class="sb-item <?= $current_page === 'net' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'net' ? '#36577d' : '#6888a8' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>
            </svg>
            <span class="sb-item-lbl">Ma Box Net</span>
        </a>

        <div class="sb-sep" style="margin: 6px 0;"></div>

        <!-- Ressources Humaines -->
        <span class="sb-lbl">Ressources Humaines</span>

        <a href="rh_salaires.php" class="sb-item <?= $current_page === 'salaires' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'salaires' ? '#36577d' : '#6888a8' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
            </svg>
            <span class="sb-item-lbl">Salaires</span>
        </a>

        <a href="rh_conges.php" class="sb-item <?= $current_page === 'conges' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'conges' ? '#7a9060' : '#9ab078' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span class="sb-item-lbl">Congés</span>
        </a>

        <a href="rh_entretien_liste.php" class="sb-item <?= $current_page === 'entretiens' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'entretiens' ? '#36577d' : '#6888a8' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
            </svg>
            <span class="sb-item-lbl">Entretiens</span>
        </a>

        <a href="rh_documents.php" class="sb-item <?= $current_page === 'documents' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'documents' ? '#7a9060' : '#9ab078' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <span class="sb-item-lbl">Documents</span>
        </a>

        <a href="rh_indemnite_km.php" class="sb-item <?= $current_page === 'frais' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'frais' ? '#cc5c58' : '#c87870' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            <span class="sb-item-lbl">Frais KM</span>
        </a>

        <a href="rh_mails.php" class="sb-item <?= $current_page === 'emails' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'emails' ? '#cc5c58' : '#c87870' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            <span class="sb-item-lbl">Emails RH</span>
        </a>

        <?php if ($sb_role_id === 1): ?>
        <div class="sb-sep" style="margin: 8px 0 4px;"></div>

        <!-- Administrer -->
        <span class="sb-lbl" style="color:#b07068;letter-spacing:0.14em;">Administrer</span>

        <a href="admin_dashboard.php" class="sb-item <?= $current_page === 'admin' ? 'active' : '' ?>"
           style="<?= $current_page === 'admin' ? 'background:rgba(168,88,88,0.12);box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px var(--shadow-light);' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'admin' ? '#a85858' : '#c87870' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
            </svg>
            <span class="sb-item-lbl" style="<?= $current_page === 'admin' ? 'color:#a85858;font-weight:700;' : 'color:#b07068;' ?>">Administration</span>
        </a>
        <a href="admin/admin_migrations.php" class="sb-item <?= $current_page === 'admin_migrations' ? 'active' : '' ?>"
           style="<?= $current_page === 'admin_migrations' ? 'background:rgba(168,88,88,0.12);box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px var(--shadow-light);' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'admin_migrations' ? '#a85858' : '#c87870' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
            </svg>
            <span class="sb-item-lbl" style="<?= $current_page === 'admin_migrations' ? 'color:#a85858;font-weight:700;' : 'color:#b07068;' ?>">Migrations BDD</span>
        </a>
        <a href="admin_bailleurs.php" class="sb-item <?= $current_page === 'admin_bailleurs' ? 'active' : '' ?>"
           style="<?= $current_page === 'admin_bailleurs' ? 'background:rgba(168,88,88,0.12);box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px var(--shadow-light);' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'admin_bailleurs' ? '#a85858' : '#c87870' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <span class="sb-item-lbl" style="<?= $current_page === 'admin_bailleurs' ? 'color:#a85858;font-weight:700;' : 'color:#b07068;' ?>">Gestion Bailleurs</span>
        </a>
        <a href="admin_referentiel.php" class="sb-item <?= $current_page === 'admin_referentiel' ? 'active' : '' ?>"
           style="<?= $current_page === 'admin_referentiel' ? 'background:rgba(168,88,88,0.12);box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px var(--shadow-light);' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'admin_referentiel' ? '#a85858' : '#c87870' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>
            </svg>
            <span class="sb-item-lbl" style="<?= $current_page === 'admin_referentiel' ? 'color:#a85858;font-weight:700;' : 'color:#b07068;' ?>">Référentiel</span>
        </a>
        <a href="tiers_nouveau.php" class="sb-item <?= $current_page === 'tiers_nouveau' ? 'active' : '' ?>"
           style="<?= $current_page === 'tiers_nouveau' ? 'background:rgba(45,95,107,0.12);box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px var(--shadow-light);' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="<?= $current_page === 'tiers_nouveau' ? '#2d5f6b' : '#5a8a95' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>
            </svg>
            <span class="sb-item-lbl" style="<?= $current_page === 'tiers_nouveau' ? 'color:#2d5f6b;font-weight:700;' : 'color:#5a8a95;' ?>">Nouveau tiers</span>
        </a>
        <?php endif; ?>
    </div>

    <div class="sb-sep"></div>

    <!-- Footer -->
    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="sb-user-info">
                <div class="sb-user-name"><?= htmlspecialchars($user_name) ?></div>
                <div class="sb-user-role"><?= htmlspecialchars($user_role) ?></div>
            </div>
            <button class="sb-gear" title="Paramètres">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
                </svg>
            </button>
        </div>
        <a href="logout.php" class="sb-logout">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Déconnexion
        </a>
    </div>
</nav>
