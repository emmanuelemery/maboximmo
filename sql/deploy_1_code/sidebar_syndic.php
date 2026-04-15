<?php
// sidebar_syndic.php — Sidebar V2 MaBoxImmo / Module Syndic (Bleu-Gris)
// Inclusion : include 'sidebar_syndic.php';

// Détection automatique par nom de fichier (syndic_rubrique_action.php)
if (!isset($current_page)) {
    $basename = basename($_SERVER['PHP_SELF'], '.php');
    $parts = explode('_', $basename);
    $rubrique = $parts[1] ?? '';
    $rubrique_map = [
        'dashboard'  => 'dashboard',
        'immeubles'  => 'immeubles',
        'immeuble'   => 'immeubles',
        'mandats'    => 'mandats',
        'mandat'     => 'mandats',
        'mandants'   => 'mandants',
        'mandant'    => 'mandants',
        'reunions'   => 'reunions',
        'reunion'    => 'reunions',
        'retour'     => 'reunions',
        'taches'     => 'taches',
        'tache'      => 'taches',
        'contrats'   => 'contrats',
        'contrat'    => 'contrats',
        'factures'   => 'factures',
        'facture'    => 'factures',
        'registres'  => 'registres',
        'registre'   => 'registres',
    ];
    $current_page = $rubrique_map[$rubrique] ?? 'dashboard';
}

$sb_role_id  = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['role_id'] ?? 0);
$agency_name = $_SESSION['societe_nom'] ?? 'Agence';
$user_name   = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) ?: ($_SESSION['username'] ?? 'Utilisateur');
$user_role   = $_SESSION['role_label'] ?? 'Collaborateur';
$parts_n     = explode(' ', $user_name);
$initials    = strtoupper(substr($parts_n[0] ?? '?', 0, 1) . substr($parts_n[1] ?? '', 0, 1));

function sbSI(string $pg, string $cur, string $href, string $label, string $svg): void {
    $a = ($cur === $pg) ? 'active' : '';
    $c = ($cur === $pg) ? '#4878a6' : '#7a9ab8';
    echo '<a href="' . htmlspecialchars($href) . '" class="sb-item ' . $a . '">';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $svg . '</svg>';
    echo '<span class="sb-item-lbl">' . htmlspecialchars($label) . '</span>';
    echo '</a>';
}
?>
<style>
.sb{position:fixed;left:10px;top:10px;bottom:10px;width:200px;background:#ede8e0;border-radius:18px;box-shadow:8px 8px 22px #c8c4be,-8px -8px 18px #ffffff;display:flex;flex-direction:column;z-index:200;overflow:hidden}
.sb-head{padding:20px 16px 14px;flex-shrink:0}
.sb-logo{font-family:'Sora',sans-serif;font-size:17px;font-weight:700;color:#2c2a28;letter-spacing:-0.02em;line-height:1;margin-bottom:4px}
.sb-logo span{color:#4878a6}
.sb-tagline{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:10px}
.sb-agency{display:flex;align-items:center;gap:6px;background:#f7f8fa;border-radius:8px;padding:5px 8px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee}
.sb-agency-dot{width:7px;height:7px;border-radius:50%;background:#4878a6;flex-shrink:0}
.sb-agency-name{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;color:#4a4844;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-sep{height:1px;margin:0 14px;background:linear-gradient(90deg,transparent,#c8c4be,transparent);flex-shrink:0}
.sb-nav{flex:1;overflow-y:auto;padding:8px 10px;scrollbar-width:none}
.sb-nav::-webkit-scrollbar{display:none}
.sb-lbl{font-family:'DM Mono',monospace;font-size:9px;font-weight:600;color:#9a9690;letter-spacing:0.12em;text-transform:uppercase;padding:6px 8px 2px;display:block}
.sb-item{display:flex;align-items:center;gap:9px;height:38px;padding:0 10px;border-radius:999px;text-decoration:none;background:transparent;transition:background .18s,box-shadow .18s;margin-bottom:2px}
.sb-item:hover{background:rgba(0,0,0,.04)}
.sb-item.active{background:#ffffff;box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #ffffff}
.sb-item svg{width:15px;height:15px;flex-shrink:0;opacity:.75}
.sb-item.active svg{opacity:1}
.sb-item-lbl{font-family:'Sora',sans-serif;font-size:12px;font-weight:500;color:#6a6864;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-item.active .sb-item-lbl{color:#1a1816;font-weight:700}
.sb-footer{padding:10px 12px 14px;flex-shrink:0}
.sb-user{display:flex;align-items:center;gap:8px;padding:8px;border-radius:12px;background:#f7f8fa;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;margin-bottom:8px}
.sb-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6898bf,#4878a6);display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.sb-user-info{flex:1;min-width:0}
.sb-user-name{font-family:'Sora',sans-serif;font-size:11px;font-weight:600;color:#2c2a28;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-role{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;text-transform:uppercase;letter-spacing:0.08em}
.sb-gear{width:26px;height:26px;border-radius:50%;background:transparent;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#9a9690;flex-shrink:0;transition:color .15s}
.sb-gear:hover{color:#4a4844}
.sb-logout{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;height:34px;border-radius:999px;background:#ffffff;box-shadow:3px 3px 8px #c8c4be,-3px -3px 8px #ffffff;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;color:#c87870;text-decoration:none;transition:box-shadow .15s}
.sb-logout:hover{box-shadow:inset 2px 2px 6px #c8c4be,inset -2px -2px 5px #ffffff;color:#a85850}
</style>

<nav class="sb">
    <div class="sb-head">
        <div class="sb-logo">MaBox<span>Immo</span></div>
        <div class="sb-tagline">Syndic · Copropriété</div>
        <div class="sb-agency">
            <div class="sb-agency-dot"></div>
            <span class="sb-agency-name"><?= htmlspecialchars($agency_name) ?></span>
        </div>
    </div>

    <div class="sb-sep"></div>

    <div class="sb-nav">
        <span class="sb-lbl">Syndic</span>
        <?php sbSI('dashboard', $current_page, 'syndic_dashboard.php', 'Dashboard',
            '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'); ?>
        <?php sbSI('immeubles', $current_page, 'syndic_immeubles.php', 'Immeubles',
            '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'); ?>
        <?php sbSI('mandants', $current_page, 'syndic_mandants.php', 'Mandants',
            '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>'); ?>
        <?php sbSI('mandats', $current_page, 'syndic_mandats.php', 'Mandats',
            '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'); ?>

        <div class="sb-sep" style="margin:6px 0"></div>
        <span class="sb-lbl">Réunions</span>
        <?php sbSI('reunions', $current_page, 'syndic_reunions.php', 'Réunions / AG',
            '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'); ?>
        <?php sbSI('taches', $current_page, 'syndic_taches.php', 'Tâches',
            '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>'); ?>

        <div class="sb-sep" style="margin:6px 0"></div>
        <span class="sb-lbl">Finances</span>
        <?php sbSI('contrats', $current_page, 'syndic_contrats.php', 'Contrats',
            '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'); ?>
        <?php sbSI('factures', $current_page, 'syndic_factures.php', 'Factures',
            '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>'); ?>

        <div class="sb-sep" style="margin:6px 0"></div>
        <span class="sb-lbl">Archives</span>
        <?php sbSI('registres', $current_page, 'syndic_registres.php', 'Registres',
            '<path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>'); ?>

        <div class="sb-sep" style="margin:8px 0 4px"></div>
        <span class="sb-lbl">Navigation</span>
        <a href="rh_dashboard.php" class="sb-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="#9ab078" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
            <span class="sb-item-lbl">Module RH</span>
        </a>
        <a href="landing.php" class="sb-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="#9ab078" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span class="sb-item-lbl">Accueil</span>
        </a>
    </div>

    <div class="sb-sep"></div>

    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="sb-user-info">
                <div class="sb-user-name"><?= htmlspecialchars($user_name) ?></div>
                <div class="sb-user-role"><?= htmlspecialchars($user_role) ?></div>
            </div>
            <button class="sb-gear" title="Paramètres">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            </button>
        </div>
        <a href="logout.php" class="sb-logout">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Déconnexion
        </a>
    </div>
</nav>
