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

$q = trim((string)($_GET['q'] ?? ''));

// ── Source : architecture TIERS (Phase 3 migré 2026-04-19) ──
// On liste les tiers avec rôle "proprietaire" et on garde le pointeur legacy
// `proprietaires.id` via LEFT JOIN pour continuer à ouvrir dashboard_proprietaire.php?id_proprietaire=X.
$sql = "
    SELECT t.id AS id_tiers,
           COALESCE(NULLIF(t.nom_affichage, ''),
                    NULLIF(t.raison_sociale, ''),
                    TRIM(CONCAT_WS(' ', t.prenom, t.nom))) AS label,
           t.nom, t.prenom, t.raison_sociale, t.email, t.ville, t.type_tiers,
           t.actif,
           p.id AS id_proprietaire, p.code_compte, p.type_dashboard, p.id_agence,
           a.nom_agence,
           (SELECT COUNT(*) FROM immeubles WHERE id_proprietaire = p.id) AS nb_immeubles,
           (SELECT COUNT(*) FROM biens       WHERE id_proprietaire = p.id) AS nb_biens
    FROM tiers t
    INNER JOIN tiers_roles tr ON tr.id_tiers = t.id AND tr.role_code = 'proprietaire'
    LEFT JOIN proprietaires p ON p.id_tiers = t.id
    LEFT JOIN agences a       ON a.id = p.id_agence
";
$params = [];
if ($q !== '') {
    $sql .= " WHERE t.nom LIKE :q OR t.prenom LIKE :q OR t.raison_sociale LIKE :q OR t.email LIKE :q OR t.ville LIKE :q ";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " GROUP BY t.id
          ORDER BY t.actif DESC, t.nom ASC, t.raison_sociale ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bailleurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    $bailleurs = [];
}

$nbTotal    = count($bailleurs);
$nbActifs   = 0;
$nbStandard = 0;
$nbGroupe   = 0;
$nbAvecCompteLegacy = 0;
foreach ($bailleurs as $b) {
    if ((int)$b['actif'] === 1) $nbActifs++;
    if (($b['type_dashboard'] ?? '') === 'groupe_sir') $nbGroupe++;
    else $nbStandard++;
    if (!empty($b['id_proprietaire'])) $nbAvecCompteLegacy++;
}

$current_page = 'bailleur_admin';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion Bailleurs — MaBoxImmo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; background: var(--bg-secondary); color: #1a1816; min-height: 100vh; display: flex; }

        .sb-content { margin-left: 220px; flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        .topbar {
            display: flex; align-items: center; gap: 10px;
            padding: 0 24px 0 20px; height: 56px;
            background: var(--bg-primary);
            box-shadow: 0 4px 12px rgba(196,192,186,0.45);
            flex-shrink: 0; position: sticky; top: 0; z-index: 100;
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

        .main { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0 28px 40px; }

        .page-head {
            display: flex; align-items: center; justify-content: space-between;
            height: 80px; flex-shrink: 0;
            border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 24px;
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

        .kpi-row { display: flex; gap: 16px; margin-bottom: 24px; }
        .kpi-card {
            flex: 1; background: var(--bg-primary); border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            padding: 18px 20px; display: flex; align-items: center; gap: 14px;
        }
        .kpi-icon {
            width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            box-shadow: inset 3px 3px 6px rgba(0,0,0,0.08), inset -3px -3px 6px rgba(255,255,255,0.7);
            background: rgba(54,87,125,0.1);
        }
        .kpi-icon svg { width: 20px; height: 20px; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; stroke: #36577d; }
        .kpi-lbl { font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase; letter-spacing: 0.14em; color: #a8a49e; margin-bottom: 3px; }
        .kpi-val { font-family: 'DM Mono', monospace; font-size: 26px; font-weight: 500; line-height: 1; color: #2d5f6b; }

        .toolbar {
            display: flex; align-items: center; gap: 12px; margin-bottom: 18px;
        }
        .search-wrap {
            flex: 1; max-width: 420px; position: relative;
        }
        .search-wrap input {
            width: 100%; padding: 10px 14px 10px 36px; border-radius: 12px;
            border: 1px solid rgba(196,192,186,0.5);
            background: var(--bg-primary);
            font-family: 'Sora', sans-serif; font-size: 13px; color: #1a1816;
            box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 7px var(--shadow-light);
            outline: none;
        }
        .search-wrap input:focus { border-color: #36577d; }
        .search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; stroke: #a8a49e; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .b-table {
            background: var(--bg-primary); border-radius: 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            overflow: hidden;
        }
        .b-row {
            display: grid;
            grid-template-columns: 44px 2fr 1.5fr 1fr 0.8fr 0.8fr 140px;
            gap: 14px;
            align-items: center;
            padding: 12px 18px;
            border-bottom: 1px solid rgba(196,192,186,0.3);
        }
        .b-row:last-child { border-bottom: none; }
        .b-row.head {
            background: rgba(196,192,186,0.12);
            font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.14em;
            text-transform: uppercase; color: #a8a49e;
            padding-top: 10px; padding-bottom: 10px;
        }
        .b-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #6888a8, #36577d);
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 700; color: #fff;
        }
        .b-avatar.inactif { background: linear-gradient(135deg, #c8c4be, #a8a49e); }
        .b-name { font-size: 13px; font-weight: 600; color: #2d5f6b; }
        .b-sub  { font-family: 'DM Mono', monospace; font-size: 10px; color: #a8a49e; margin-top: 2px; }
        .b-meta { font-size: 12px; color: #5a5650; }
        .b-type {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
        }
        .b-type.standard { background: rgba(54,87,125,0.1); color: #36577d; }
        .b-type.groupe   { background: rgba(196,122,48,0.1); color: #9a5a18; }
        .b-count { font-family: 'DM Mono', monospace; font-size: 14px; font-weight: 600; color: #2d5f6b; text-align: center; }

        .b-btn {
            padding: 7px 14px; border-radius: 10px;
            background: #2d5f6b; color: #fff;
            font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            transition: filter .15s;
            justify-content: center;
        }
        .b-btn:hover { filter: brightness(1.1); }
        .b-btn:active { box-shadow: inset 2px 2px 5px rgba(0,0,0,0.25); }

        .empty {
            padding: 60px 20px; text-align: center; color: #8a8680;
            font-family: 'DM Mono', monospace; font-size: 12px;
        }

        @media (max-width: 1100px) {
            .b-row { grid-template-columns: 44px 1.5fr 1fr 120px; }
            .b-row > .hide-sm { display: none; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_rh.php'; ?>

<div class="sb-content">

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
            <span class="active">Gestion Bailleurs</span>
        </nav>
        <div class="topbar-spacer"></div>
        <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? '?', 0, 1) . substr($_SESSION['nom'] ?? '', 0, 1)) ?></div>
    </header>

    <main class="main">

        <div class="page-head">
            <div>
                <div class="page-head-module">Système · Administration</div>
                <div class="page-head-title">Gestion des Bailleurs</div>
            </div>
            <div class="admin-badge">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Super Admin
            </div>
        </div>

        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Total bailleurs</div>
                    <div class="kpi-val"><?= (int)$nbTotal ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Actifs</div>
                    <div class="kpi-val"><?= (int)$nbActifs ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Dashboards standards</div>
                    <div class="kpi-val"><?= (int)$nbStandard ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M2 9h20M8 3v18"/></svg>
                </div>
                <div>
                    <div class="kpi-lbl">Groupe SIR</div>
                    <div class="kpi-val"><?= (int)$nbGroupe ?></div>
                </div>
            </div>
        </div>

        <form class="toolbar" method="get" action="<?= h(app_url('/bailleur_admin.php')) ?>">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Rechercher un bailleur (nom, société, email, ville)…">
            </div>
            <?php if ($q !== ''): ?>
                <a href="<?= h(app_url('/bailleur_admin.php')) ?>" style="font-family:'DM Mono',monospace;font-size:11px;color:#a85858;text-decoration:none;">× Reset</a>
            <?php endif; ?>
        </form>

        <div class="b-table">
            <div class="b-row head">
                <div></div>
                <div>Bailleur</div>
                <div class="hide-sm">Coordonnées</div>
                <div class="hide-sm">Agence</div>
                <div class="hide-sm">Immeubles</div>
                <div class="hide-sm">Biens</div>
                <div style="text-align:center;">Actions</div>
            </div>

            <?php if (empty($bailleurs)): ?>
                <div class="empty">Aucun bailleur trouvé.</div>
            <?php else: ?>
                <?php foreach ($bailleurs as $b):
                    $label = trim((string)($b['label'] ?: '—'));
                    $initials = strtoupper(substr($label, 0, 2));
                    $typeClass = (($b['type_dashboard'] ?? '') === 'groupe_sir') ? 'groupe' : 'standard';
                    $typeLabel = (($b['type_dashboard'] ?? '') === 'groupe_sir') ? 'Groupe SIR' : 'Standard';
                    $typeTiersLabel = match ($b['type_tiers'] ?? '') {
                        'personne_morale'  => 'Personne morale',
                        'syndicat_coprop'  => 'Syndicat copro',
                        'entite_juridique' => 'Entité juridique',
                        'indivision'       => 'Indivision',
                        default            => 'Personne physique',
                    };
                    $hasLegacy = !empty($b['id_proprietaire']);
                ?>
                <div class="b-row">
                    <div class="b-avatar <?= (int)$b['actif'] === 0 ? 'inactif' : '' ?>"><?= h($initials ?: '??') ?></div>
                    <div>
                        <div class="b-name"><?= h($label) ?></div>
                        <div class="b-sub">
                            <?= h($typeTiersLabel) ?>
                            <?php if ($hasLegacy): ?> · <span class="b-type <?= $typeClass ?>"><?= h($typeLabel) ?></span><?php endif; ?>
                            · <span style="color:#a8a49e;">Tiers #<?= (int)$b['id_tiers'] ?></span>
                            <?= (int)$b['actif'] === 0 ? ' · <span style="color:#a85858;">Inactif</span>' : '' ?>
                        </div>
                    </div>
                    <div class="b-meta hide-sm">
                        <?= h($b['email'] ?: '—') ?><br>
                        <span style="color:#a8a49e;font-size:11px;"><?= h($b['ville'] ?: '') ?></span>
                    </div>
                    <div class="b-meta hide-sm"><?= h($b['nom_agence'] ?: '—') ?></div>
                    <div class="b-count hide-sm"><?= (int)$b['nb_immeubles'] ?></div>
                    <div class="b-count hide-sm"><?= (int)$b['nb_biens'] ?></div>
                    <div>
                        <?php if ($hasLegacy): ?>
                            <a href="<?= h(app_url('/bailleur_dashboard_v2.php?props[]=' . (int)$b['id_proprietaire'])) ?>" class="b-btn">
                                Dashboard →
                            </a>
                        <?php else: ?>
                            <span class="b-btn" style="background:#c8c4be;cursor:default;opacity:0.6;" title="Ce tiers n'a pas encore de compte propriétaire lié (sera créé à la Phase 5)">Tiers only</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>
</div>

</body>
</html>
