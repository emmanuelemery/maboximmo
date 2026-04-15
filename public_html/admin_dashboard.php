<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = (int)current_role_id();
if ($roleId !== 1) {
    http_response_code(403); exit('Accès réservé aux administrateurs.');
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = $GLOBALS['pdo'];

// Stats rapides
try { $nbUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE actif=1")->fetchColumn(); } catch(Exception $e){ $nbUsers=0; }
try { $nbSocietes = (int)$pdo->query("SELECT COUNT(*) FROM societes")->fetchColumn(); } catch(Exception $e){ $nbSocietes=0; }
try { $nbAgences  = (int)$pdo->query("SELECT COUNT(*) FROM agences")->fetchColumn(); } catch(Exception $e){ $nbAgences=0; }
try { $lastUsers  = $pdo->query("SELECT prenom, nom, date_creation FROM users ORDER BY date_creation DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $lastUsers=[]; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration — MaBoxImmo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--bg-secondary);
            color: #1a1816;
            min-height: 100vh;
            display: flex;
        }

        /* ── Layout ── */
        .sb-content {
            margin-left: 220px;
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ── Topbar ── */
        .topbar {
            display: flex; align-items: center; gap: 10px;
            padding: 0 24px 0 20px; height: 56px;
            background: var(--bg-primary);
            box-shadow: 0 4px 12px rgba(196,192,186,0.45);
            flex-shrink: 0; position: sticky; top: 0; left: auto; right: auto; z-index: 100;
        }
        .topbar-back {
            width: 34px; height: 34px; border-radius: 10px; background: var(--bg-primary);
            box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; flex-shrink: 0;
        }
        .topbar-back:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
        .topbar-back svg { width:15px; height:15px; stroke:#9aaa84; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .topbar-breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-family: 'DM Mono', monospace; font-size: 13px;
            letter-spacing: 0.06em; color: #8a8680; margin-left: 50px;
        }
        .topbar-breadcrumb .active { color: #a85858; font-weight: 600; font-size: 14px; }
        .topbar-sep { color: #c8c4be; font-size: 16px; }
        .topbar-spacer { flex: 1; }
        .topbar-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: #a85858;
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 10px; color: #fff; font-weight: 500;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); flex-shrink: 0;
        }

        /* ── Main scroll ── */
        .main { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0 28px 40px; }

        /* ── Page head ── */
        .page-head {
            display: flex; align-items: center; justify-content: space-between;
            height: 80px; flex-shrink: 0;
            border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 28px;
        }
        .page-head-module { font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase; letter-spacing: 0.22em; color: #a8a49e; margin-bottom: 4px; }
        .page-head-title { font-size: 20px; font-weight: 700; color: #1a1816; }
        .admin-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 999px;
            background: rgba(168,88,88,0.1);
            border: 1px solid rgba(168,88,88,0.2);
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
            color: #a85858; letter-spacing: 0.08em; text-transform: uppercase;
        }

        /* ── KPI bar ── */
        .kpi-row { display: flex; gap: 16px; margin-bottom: 32px; }
        .kpi-card {
            flex: 1; background: var(--bg-primary); border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            padding: 18px 20px; display: flex; align-items: center; gap: 14px;
        }
        .kpi-icon {
            width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            box-shadow: inset 3px 3px 6px rgba(0,0,0,0.08), inset -3px -3px 6px rgba(255,255,255,0.7);
        }
        .kpi-icon svg { width: 20px; height: 20px; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .kpi-lbl { font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase; letter-spacing: 0.14em; color: #a8a49e; margin-bottom: 3px; }
        .kpi-val { font-family: 'DM Mono', monospace; font-size: 26px; font-weight: 500; line-height: 1; }

        /* ── Section title ── */
        .sec-head { display: flex; align-items: center; gap: 14px; margin: 0 0 16px; }
        .sec-txt {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
            letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
        }
        .sec-txt.red {
            background: linear-gradient(180deg, #c87870 0%, #a85858 50%, #883840 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .sec-txt.blue {
            background: linear-gradient(180deg, #6888a8 0%, #36577d 50%, #1e3a58 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .sec-txt.green {
            background: linear-gradient(180deg, #7a9060 0%, #4a6038 50%, #304828 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .sec-txt.amber {
            background: linear-gradient(180deg, #c47a30 0%, #9a5a18 50%, #7a4010 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .line-l { height: 1.5px; width: 28px; flex-shrink: 0; border-radius: 2px; }
        .line-r { height: 1.5px; flex: 1; border-radius: 2px; }
        .line-red  { background: linear-gradient(90deg, #a85858, #c87870, transparent); }
        .line-blue { background: linear-gradient(90deg, #36577d, #6888a8, transparent); }
        .line-green{ background: linear-gradient(90deg, #4a6038, #9ab870, transparent); }
        .line-amber{ background: linear-gradient(90deg, #9a5a18, #c47a30, transparent); }
        .line-l.red   { background: linear-gradient(90deg, transparent, #a85858); }
        .line-l.blue  { background: linear-gradient(90deg, transparent, #36577d); }
        .line-l.green { background: linear-gradient(90deg, transparent, #4a6038); }
        .line-l.amber { background: linear-gradient(90deg, transparent, #9a5a18); }

        /* ── Cards grille ── */
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 32px; }
        .adm-card {
            background: var(--bg-primary); border-radius: 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            padding: 20px 18px 16px;
            text-decoration: none; color: inherit;
            display: flex; flex-direction: column; gap: 10px;
            transition: box-shadow .18s, transform .14s;
            border-left: 4px solid transparent;
        }
        .adm-card:hover {
            box-shadow: 8px 8px 20px #bbb8b2, -8px -8px 18px var(--shadow-light);
            transform: translateY(-2px);
        }
        .adm-card:active { box-shadow: inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light); transform: none; }

        .adm-card.red   { border-left-color: #a85858; }
        .adm-card.blue  { border-left-color: #36577d; }
        .adm-card.green { border-left-color: #7a9060; }
        .adm-card.amber { border-left-color: #c47a30; }
        .adm-card.mauve { border-left-color: #7a6898; }

        .adm-card-ico {
            width: 40px; height: 40px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            box-shadow: inset 3px 3px 6px rgba(0,0,0,0.08), inset -3px -3px 6px rgba(255,255,255,0.6);
        }
        .adm-card-ico svg { width: 18px; height: 18px; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }

        .adm-card-label { font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700; color: #1a1816; }
        .adm-card-desc  { font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680; line-height: 1.5; letter-spacing: 0.02em; flex: 1; }
        .adm-card-arrow {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
            display: flex; align-items: center; gap: 4px; margin-top: 4px;
        }

        /* Couleurs icônes */
        .ico-red   { background: rgba(168,88,88,0.1); }
        .ico-red   svg { stroke: #a85858; }
        .ico-blue  { background: rgba(54,87,125,0.1); }
        .ico-blue  svg { stroke: #36577d; }
        .ico-green { background: rgba(122,144,96,0.1); }
        .ico-green svg { stroke: #7a9060; }
        .ico-amber { background: rgba(196,122,48,0.1); }
        .ico-amber svg { stroke: #c47a30; }
        .ico-mauve { background: rgba(122,104,152,0.1); }
        .ico-mauve svg { stroke: #7a6898; }

        .arr-red   { color: #a85858; }
        .arr-blue  { color: #36577d; }
        .arr-green { color: #7a9060; }
        .arr-amber { color: #c47a30; }
        .arr-mauve { color: #7a6898; }

        /* ── Derniers users ── */
        .recent-card {
            background: var(--bg-primary); border-radius: 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            overflow: hidden; margin-bottom: 32px;
        }
        .recent-row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 18px;
            border-bottom: 1px solid rgba(196,192,186,0.3);
        }
        .recent-row:last-child { border-bottom: none; }
        .recent-avatar {
            width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg, #6888a8, #36577d);
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 700; color: #fff;
        }
        .recent-name { font-size: 12px; font-weight: 600; color: #1a1816; flex: 1; }
        .recent-date { font-family: 'DM Mono', monospace; font-size: 10px; color: #a8a49e; }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_rh.php'; ?>

<div class="sb-content">

    <!-- TOPBAR -->
    <header class="topbar">
        <button class="topbar-back" onclick="history.back()" title="Retour">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button class="topbar-back" onclick="history.forward()" title="Avancer">
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <nav class="topbar-breadcrumb">
            <span>Admin</span>
            <span class="topbar-sep">›</span>
            <span class="active">Dashboard</span>
        </nav>
        <div class="topbar-spacer"></div>
        <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? '?', 0, 1) . substr($_SESSION['nom'] ?? '', 0, 1)) ?></div>
    </header>

    <!-- MAIN -->
    <main class="main">

        <!-- PAGE HEAD -->
        <div class="page-head">
            <div>
                <div class="page-head-module">Système</div>
                <div class="page-head-title">Administration</div>
            </div>
            <span class="admin-badge">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
                </svg>
                Admin Only
            </span>
        </div>

        <!-- KPI -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon ico-red">
                    <svg viewBox="0 0 24 24" stroke="#a85858"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Utilisateurs actifs</div>
                    <div class="kpi-val" style="color:#a85858"><?= $nbUsers ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ico-blue">
                    <svg viewBox="0 0 24 24" stroke="#36577d"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Sociétés</div>
                    <div class="kpi-val" style="color:#36577d"><?= $nbSocietes ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ico-green">
                    <svg viewBox="0 0 24 24" stroke="#7a9060"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Agences</div>
                    <div class="kpi-val" style="color:#7a9060"><?= $nbAgences ?></div>
                </div>
            </div>
        </div>

        <!-- STRUCTURE -->
        <div class="sec-head">
            <span class="line-l blue"></span>
            <span class="sec-txt blue">Structure</span>
            <span class="line-r line-blue"></span>
        </div>
        <div class="cards-grid">
            <a href="societe.php" class="adm-card blue">
                <div class="adm-card-ico ico-blue">
                    <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                </div>
                <div class="adm-card-label">Sociétés</div>
                <div class="adm-card-desc">Créer, modifier, désactiver les sociétés du groupe.</div>
                <div class="adm-card-arrow arr-blue">Gérer →</div>
            </a>
            <a href="agence_inscription.php" class="adm-card blue">
                <div class="adm-card-ico ico-blue">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <div class="adm-card-label">Agences</div>
                <div class="adm-card-desc">Inscription et gestion des agences rattachées aux sociétés.</div>
                <div class="adm-card-arrow arr-blue">Gérer →</div>
            </a>
            <a href="societe_super_admin.php" class="adm-card blue">
                <div class="adm-card-ico ico-blue">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div class="adm-card-label">Super Admin</div>
                <div class="adm-card-desc">Vue globale multi-sociétés réservée au super administrateur.</div>
                <div class="adm-card-arrow arr-blue">Accéder →</div>
            </a>
        </div>

        <!-- UTILISATEURS -->
        <div class="sec-head">
            <span class="line-l red"></span>
            <span class="sec-txt red">Utilisateurs</span>
            <span class="line-r line-red"></span>
        </div>
        <div class="cards-grid">
            <a href="rh_user.php" class="adm-card red">
                <div class="adm-card-ico ico-red">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div class="adm-card-label">Gestion Users</div>
                <div class="adm-card-desc">Liste complète des utilisateurs, rôles et accès.</div>
                <div class="adm-card-arrow arr-red">Gérer →</div>
            </a>
            <a href="rh_user_add.php" class="adm-card red">
                <div class="adm-card-ico ico-red">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                </div>
                <div class="adm-card-label">Créer un user</div>
                <div class="adm-card-desc">Ajouter un nouvel utilisateur et lui attribuer un rôle.</div>
                <div class="adm-card-arrow arr-red">Créer →</div>
            </a>
            <a href="admin_user_create.php" class="adm-card red">
                <div class="adm-card-ico ico-red">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                </div>
                <div class="adm-card-label">User Admin</div>
                <div class="adm-card-desc">Créer un utilisateur avec droits administrateur.</div>
                <div class="adm-card-arrow arr-red">Créer →</div>
            </a>
            <a href="rh_user_historiq.php" class="adm-card red">
                <div class="adm-card-ico ico-red">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="adm-card-label">Historique</div>
                <div class="adm-card-desc">Journal des modifications et actions utilisateurs.</div>
                <div class="adm-card-arrow arr-red">Voir →</div>
            </a>
        </div>

        <!-- PARAMÉTRAGE IMMOBILIER -->
        <div class="sec-head">
            <span class="line-l amber"></span>
            <span class="sec-txt amber">Paramétrage Immobilier</span>
            <span class="line-r line-amber"></span>
        </div>
        <div class="cards-grid">
            <a href="parametrage.php" class="adm-card amber">
                <div class="adm-card-ico ico-amber">
                    <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </div>
                <div class="adm-card-label">Vue d'ensemble</div>
                <div class="adm-card-desc">Accès global au module paramétrage immobilier.</div>
                <div class="adm-card-arrow arr-amber">Ouvrir →</div>
            </a>
            <a href="admin/param_vues.php" class="adm-card amber">
                <div class="adm-card-ico ico-amber">
                    <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div class="adm-card-label">Vues</div>
                <div class="adm-card-desc">Dégagée, panoramique, mer, jardin… Types de vue.</div>
                <div class="adm-card-arrow arr-amber">Gérer →</div>
            </a>
            <a href="admin/param_types_bien.php" class="adm-card amber">
                <div class="adm-card-ico ico-amber">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                </div>
                <div class="adm-card-label">Types de bien</div>
                <div class="adm-card-desc">Appartement, maison, villa, local… Catégories de biens.</div>
                <div class="adm-card-arrow arr-amber">Gérer →</div>
            </a>
            <a href="admin/param_dependances.php" class="adm-card amber">
                <div class="adm-card-ico ico-amber">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <div class="adm-card-label">Dépendances</div>
                <div class="adm-card-desc">Cave, terrasse, garage, piscine… Espaces extérieurs.</div>
                <div class="adm-card-arrow arr-amber">Gérer →</div>
            </a>
            <a href="admin/param_chauffage.php" class="adm-card amber">
                <div class="adm-card-ico ico-amber">
                    <svg viewBox="0 0 24 24"><path d="M12 2c0 6-8 6-8 12a8 8 0 0016 0c0-6-8-6-8-12z"/></svg>
                </div>
                <div class="adm-card-label">Chauffage & Énergie</div>
                <div class="adm-card-desc">Radiateur, PAC, gaz, solaire… Types de chauffage.</div>
                <div class="adm-card-arrow arr-amber">Gérer →</div>
            </a>
        </div>

        <!-- CONFIGURATION RH -->
        <div class="sec-head">
            <span class="line-l green"></span>
            <span class="sec-txt green">Configuration RH</span>
            <span class="line-r line-green"></span>
        </div>
        <div class="cards-grid">
            <a href="rh_entretien_config_societe.php" class="adm-card green">
                <div class="adm-card-ico ico-green">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                </div>
                <div class="adm-card-label">Config Entretiens</div>
                <div class="adm-card-desc">Rubriques, critères et modèles de grilles d'entretien.</div>
                <div class="adm-card-arrow arr-green">Configurer →</div>
            </a>
            <a href="rh_documents_config.php" class="adm-card green">
                <div class="adm-card-ico ico-green">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="adm-card-label">Config Documents</div>
                <div class="adm-card-desc">Types et catégories de documents RH autorisés.</div>
                <div class="adm-card-arrow arr-green">Configurer →</div>
            </a>
            <a href="rh_mail_templates.php" class="adm-card green">
                <div class="adm-card-ico ico-green">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div class="adm-card-label">Modèles Mail</div>
                <div class="adm-card-desc">Templates d'emails RH : convocations, notifications…</div>
                <div class="adm-card-arrow arr-green">Gérer →</div>
            </a>
            <a href="rh_entretien_admin.php" class="adm-card green">
                <div class="adm-card-ico ico-green">
                    <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="adm-card-label">Suivi Entretiens</div>
                <div class="adm-card-desc">Vue admin de tous les entretiens et leur avancement.</div>
                <div class="adm-card-arrow arr-green">Voir →</div>
            </a>
        </div>

        <!-- SYSTÈME -->
        <div class="sec-head">
            <span class="line-l" style="background:linear-gradient(90deg,transparent,#7a6898)"></span>
            <span class="sec-txt" style="background:linear-gradient(180deg,#9888b8 0%,#7a6898 50%,#5a4878 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Système</span>
            <span class="line-r" style="background:linear-gradient(90deg,#7a6898,#9888b8,transparent)"></span>
        </div>
        <div class="cards-grid">
            <a href="admin/admin_database.php" class="adm-card mauve">
                <div class="adm-card-ico ico-mauve">
                    <svg viewBox="0 0 24 24" stroke="#7a6898"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                </div>
                <div class="adm-card-label">Base de données</div>
                <div class="adm-card-desc">Explorateur de tables, requêtes et structure BDD.</div>
                <div class="adm-card-arrow arr-mauve">Ouvrir →</div>
            </a>
            <a href="admin_registres_access.php" class="adm-card mauve">
                <div class="adm-card-ico ico-mauve">
                    <svg viewBox="0 0 24 24" stroke="#7a6898"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div class="adm-card-label">Accès Registres</div>
                <div class="adm-card-desc">Gestion des accès aux registres et journaux système.</div>
                <div class="adm-card-arrow arr-mauve">Gérer →</div>
            </a>
        </div>

        <!-- DERNIERS UTILISATEURS -->
        <?php if (!empty($lastUsers)): ?>
        <div class="sec-head">
            <span class="line-l" style="background:linear-gradient(90deg,transparent,var(--shadow-dark))"></span>
            <span class="sec-txt" style="background:linear-gradient(180deg,#8a8680 0%,#6a6660 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Derniers inscrits</span>
            <span class="line-r" style="background:linear-gradient(90deg,var(--shadow-dark),transparent)"></span>
        </div>
        <div class="recent-card">
            <?php foreach ($lastUsers as $u): ?>
            <div class="recent-row">
                <div class="recent-avatar"><?= strtoupper(substr($u['prenom'], 0, 1) . substr($u['nom'], 0, 1)) ?></div>
                <span class="recent-name"><?= h($u['prenom'] . ' ' . $u['nom']) ?></span>
                <span class="recent-date"><?= $u['date_creation'] ? date('d/m/Y', strtotime($u['date_creation'])) : '—' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
