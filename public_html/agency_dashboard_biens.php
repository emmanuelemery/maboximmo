<?php
declare(strict_types=1);

/**
 * agency_dashboard_biens.php — Dashboard "Biens" (liste, création, annonces, diffusion, import…)
 * Même modèle UI que agency_dashboard.php (barre Créer + Explorer).
 */

$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$roleId = (int)current_role_id();
$isAdminOrSup = function_exists('is_admin_or_super_admin') ? is_admin_or_super_admin() : in_array($roleId, [1, 7], true);

$layout_title          = 'Dashboard Agency — Biens';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

function adb_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

// Icônes
$icoHome    = '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>';
$icoBuild   = '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h6"/>';
$icoSearch  = '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>';
$icoMegaph  = '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/>';
$icoUpload  = '<path d="M12 3v12"/><path d="M7 8l5-5 5 5"/><path d="M21 21H3"/>';
$icoTable   = '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>';
$icoArrow   = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';

// Barre de création — biens & diffusion
$creerItems = [
    ['label' => 'Nouveau bien',      'url' => 'bien_detail.php',              'ico' => $icoHome,   'color' => '#6b8e6f'],
    ['label' => 'Liste des biens',   'url' => 'bien_liste.php',               'ico' => $icoBuild,  'color' => '#4878a6'],
    ['label' => 'Import intelligent','url' => 'bien_intake.php',              'ico' => $icoUpload, 'color' => '#7a6830'],
    ['label' => 'Annonces',          'url' => 'annonce_liste.php',            'ico' => $icoMegaph, 'color' => '#a85858'],
    ['label' => 'Diffusion',         'url' => 'agency_dashboard_diffusion.php','ico' => $icoMegaph,'color' => '#2d5f6b'],
];

// Explorer — vues et outils existants liés aux biens
$decouvrirItems = [
    ['label' => 'Biens',        'desc' => 'Liste complète + filtres + accès au détail.',                 'url' => 'bien_liste.php',                'ico' => $icoBuild,  'color' => '#4878a6'],
    ['label' => 'Créer / éditer','desc' => 'Créer un brouillon et compléter documents, DPE, annonce…',    'url' => 'bien_detail.php',               'ico' => $icoHome,   'color' => '#6b8e6f'],
    ['label' => 'Recherche',    'desc' => 'Recherche rapide (legacy) sur les biens.',                    'url' => 'bien_recherche.php',            'ico' => $icoSearch, 'color' => '#7a6898'],
    ['label' => 'Annonces',     'desc' => 'Liste des annonces (édition via bien_detail section annonce).','url' => 'annonce_liste.php',             'ico' => $icoMegaph, 'color' => '#a85858'],
    ['label' => 'Diffusion',    'desc' => 'Dashboard Ubiflow : KPIs, envois, retards, historique.',      'url' => 'agency_dashboard_diffusion.php','ico' => $icoMegaph, 'color' => '#2d5f6b'],
    ['label' => 'Import IA',    'desc' => 'Upload DPE/mandats et extraction assistée (intake).',          'url' => 'bien_intake.php',               'ico' => $icoUpload, 'color' => '#7a6830'],
];

if ($isAdminOrSup) {
    $decouvrirItems[] = ['label' => 'Diag biens (admin)', 'desc' => 'Diagnostics et contrôles techniques côté admin.', 'url' => 'admin/admin_bien_diag.php', 'ico' => $icoTable, 'color' => '#36577d'];
}

$layout_extra_css = '<style>
.adb-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }

.adb-create-bar {
    background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%);
    border-radius: 22px;
    box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff;
    padding: 22px 24px;
    margin-bottom: 32px;
}
.adb-create-label {
    font-family: "DM Mono", monospace;
    font-size: 10px;
    font-weight: 600;
    color: #9a9690;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.adb-create-label::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, #d4d7de, transparent);
}
.adb-create-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.adb-create-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 18px 10px 16px;
    border-radius: 16px;
    background: #fff;
    border: 2px solid transparent;
    text-decoration: none;
    color: #1a1816;
    transition: transform .18s cubic-bezier(0.22,1,0.36,1), box-shadow .18s, border-color .18s;
    box-shadow: 4px 4px 10px rgba(196,192,186,0.35), -4px -4px 10px #fff;
    position: relative;
    overflow: hidden;
}
.adb-create-btn:hover { transform: translateY(-2px); box-shadow: 8px 10px 18px rgba(196,192,186,0.45), -6px -6px 14px #fff; }
.adb-create-ico {
    width: 52px; height: 52px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
}
.adb-create-lbl { font-size: 13px; font-weight: 700; text-align:center; }

.adb-welcome { text-align: center; margin-bottom: 36px; padding: 0 20px; }
.adb-welcome-hand { font-size: 48px; line-height: 1; display: inline-block; margin-bottom: 10px; }
.adb-welcome-title {
    font-family: "Sora", sans-serif; font-size: 32px; font-weight: 700;
    color: #6b8e6f; letter-spacing: -0.02em; margin-bottom: 10px;
}
.adb-welcome-sub {
    font-size: 15px; color: #6a6864;
    max-width: 680px; margin: 0 auto; line-height: 1.55;
}

.adb-discover-label {
    font-family: "DM Mono", monospace;
    font-size: 10px; font-weight: 600; color: #9a9690;
    letter-spacing: 0.16em; text-transform: uppercase;
    margin-bottom: 16px; padding: 0 4px;
    display: flex; align-items: center; gap: 10px;
}
.adb-discover-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.adb-discover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.adb-card {
    background: #fff;
    border-radius: 20px;
    padding: 22px 22px 20px;
    text-decoration: none; color: inherit;
    display: flex; flex-direction: column; gap: 12px;
    box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff;
    transition: transform .22s cubic-bezier(0.22,1,0.36,1), box-shadow .22s;
    border-left: 4px solid transparent;
    min-height: 180px;
}
.adb-card:hover { transform: translateY(-4px); box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff; }
.adb-card-head { display: flex; align-items: center; gap: 14px; }
.adb-card-ico {
    width: 48px; height: 48px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
    flex-shrink: 0;
}
.adb-card-title {
    font-family: "Sora", sans-serif; font-size: 17px; font-weight: 700;
    color: #2c2a28; letter-spacing: -0.01em;
}
.adb-card-desc { font-size: 13px; color: #6a6864; line-height: 1.55; flex: 1; }
.adb-card-foot {
    display: flex; align-items: center; justify-content: flex-end; gap: 6px;
    font-family: "DM Mono", monospace; font-size: 11px; font-weight: 600;
    letter-spacing: 0.06em; text-transform: uppercase;
    transition: gap .18s;
}
.adb-card:hover .adb-card-foot { gap: 10px; }
.adb-card-foot svg { width: 14px; height: 14px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .18s; }
.adb-card:hover .adb-card-foot svg { transform: translateX(4px); }

@media (max-width: 1100px) {
    .adb-create-grid { grid-template-columns: repeat(3, 1fr); }
    .adb-discover-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .adb-create-grid { grid-template-columns: repeat(2, 1fr); }
    .adb-discover-grid { grid-template-columns: 1fr; }
    .adb-welcome-title { font-size: 26px; }
}
</style>';

ob_start();
?>

<div class="adb-wrap">

    <div class="adb-create-bar">
        <div class="adb-create-label">Créer</div>
        <div class="adb-create-grid">
            <?php foreach ($creerItems as $it): ?>
                <a href="<?= htmlspecialchars($it['url']) ?>" class="adb-create-btn" style="color:<?= $it['color'] ?>">
                    <div class="adb-create-ico" style="background:<?= $it['color'] ?>14">
                        <?= adb_svg($it['ico'], $it['color'], 26) ?>
                    </div>
                    <div class="adb-create-lbl"><?= htmlspecialchars($it['label']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="adb-welcome">
        <div class="adb-welcome-hand">🏘️</div>
        <div class="adb-welcome-title">
            Biens<?= $prenom !== '' ? ' · ' . htmlspecialchars($prenom) : '' ?>
        </div>
        <div class="adb-welcome-sub">
            Pilotez vos biens : création, édition, annonces et diffusion<?= $societeNom !== '' ? ' — ' . htmlspecialchars($societeNom) : '' ?>.
        </div>
    </div>

    <div class="adb-discover-label">Explorer</div>
    <div class="adb-discover-grid">
        <?php foreach ($decouvrirItems as $it): ?>
            <a href="<?= htmlspecialchars($it['url']) ?>" class="adb-card" style="border-left-color:<?= $it['color'] ?>;color:<?= $it['color'] ?>">
                <div class="adb-card-head">
                    <div class="adb-card-ico" style="background:<?= $it['color'] ?>14">
                        <?= adb_svg($it['ico'], $it['color'], 22) ?>
                    </div>
                    <div class="adb-card-title"><?= htmlspecialchars($it['label']) ?></div>
                </div>
                <div class="adb-card-desc"><?= htmlspecialchars($it['desc']) ?></div>
                <div class="adb-card-foot">
                    Ouvrir <?= adb_svg($icoArrow, 'currentColor', 14) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>

