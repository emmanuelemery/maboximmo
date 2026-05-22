<?php
declare(strict_types=1);

/**
 * agency_dashboard_user.php — Dashboard "User" (collaborateur) pour le module Agency.
 * Objectif : point d'entrée simple, orienté tâches, sans sidebar surchargée.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$layout_title          = 'Dashboard Agency (User)';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

function adu_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

$icoCards  = '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h10"/>';
$icoFolder = '<path d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>';
$icoHome   = '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>';
$icoList   = '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h8"/>';
$icoCheck  = '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>';
$icoArrow  = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';

$creerItems = [
    ['label' => 'FluxBox',     'url' => 'fluxbox.php',           'ico' => $icoCards,  'color' => '#243B5C'],
    ['label' => 'GED',         'url' => 'ged_dashboard.php',     'ico' => $icoFolder, 'color' => '#2d5f6b'],
    ['label' => 'Biens',       'url' => 'bien_liste.php',        'ico' => $icoHome,   'color' => '#6b8e6f'],
    ['label' => 'Annonces',    'url' => 'annonce_liste.php',     'ico' => $icoList,   'color' => '#a85858'],
    ['label' => 'Mes tâches',  'url' => 'agency_taches.php',     'ico' => $icoCheck,  'color' => '#4a6038'],
];

$decouvrirItems = [
    ['label' => 'Traiter FluxBox', 'desc' => 'Validez les cartes une par une (téléchargements + mails).', 'url' => 'fluxbox.php',       'ico' => $icoCards,  'color' => '#243B5C'],
    ['label' => 'Consulter la GED','desc' => 'Recherchez et parcourez les documents (dossiers, entités).', 'url' => 'ged_consult.php',   'ico' => $icoFolder, 'color' => '#2d5f6b'],
    ['label' => 'Parcourir les biens','desc'=> 'Liste des biens et accès aux détails.',                   'url' => 'bien_liste.php',    'ico' => $icoHome,   'color' => '#6b8e6f'],
    ['label' => 'Suivre les annonces','desc'=> 'Vérifiez la diffusion et les mises à jour.',              'url' => 'annonce_liste.php', 'ico' => $icoList,   'color' => '#a85858'],
    ['label' => 'Mes tâches',        'desc'=> 'Vos actions à faire et suivi.',                            'url' => 'agency_taches.php', 'ico' => $icoCheck,  'color' => '#4a6038'],
];

$layout_extra_css = '<style>
.adu-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }
.adu-create-bar {
    background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%);
    border-radius: 22px;
    box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff;
    padding: 22px 24px;
    margin-bottom: 28px;
}
.adu-create-label {
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
.adu-create-label::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, #d4d7de, transparent);
}
.adu-create-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.adu-create-btn {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 18px 10px 16px; border-radius: 16px;
    background: #fff; border: 2px solid transparent; text-decoration: none;
    color: #1a1816;
    transition: transform .18s cubic-bezier(0.22,1,0.36,1), box-shadow .18s, border-color .18s;
    box-shadow: 4px 4px 10px rgba(196,192,186,0.35), -4px -4px 10px #fff;
}
.adu-create-btn:hover { transform: translateY(-2px); box-shadow: 8px 10px 18px rgba(196,192,186,0.45), -6px -6px 14px #fff; }
.adu-create-ico { width: 52px; height: 52px; border-radius: 16px; display:flex; align-items:center; justify-content:center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9); }
.adu-create-lbl { font-size: 13px; font-weight: 700; text-align:center; }

.adu-welcome { text-align: center; margin-bottom: 30px; padding: 0 20px; }
.adu-welcome-hand { font-size: 48px; line-height: 1; display:inline-block; margin-bottom: 10px; }
.adu-welcome-title { font-family:"Sora",sans-serif; font-size: 30px; font-weight: 800; color: #243B5C; letter-spacing: -0.02em; margin-bottom: 10px; }
.adu-welcome-sub { font-size: 15px; color: #6a6864; max-width: 620px; margin: 0 auto; line-height: 1.55; }

.adu-discover-label {
    font-family: "DM Mono", monospace;
    font-size: 10px; font-weight: 600; color: #9a9690;
    letter-spacing: 0.16em; text-transform: uppercase;
    margin-bottom: 16px; padding: 0 4px;
    display: flex; align-items: center; gap: 10px;
}
.adu-discover-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.adu-discover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.adu-card {
    background: #fff;
    border-radius: 20px;
    padding: 22px 22px 20px;
    text-decoration: none; color: inherit;
    display: flex; flex-direction: column; gap: 12px;
    box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff;
    transition: transform .22s cubic-bezier(0.22,1,0.36,1), box-shadow .22s;
    border-left: 4px solid transparent;
    min-height: 175px;
}
.adu-card:hover { transform: translateY(-4px); box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff; }
.adu-card-head { display:flex; align-items:center; gap: 14px; }
.adu-card-ico { width: 48px; height:48px; border-radius: 13px; display:flex; align-items:center; justify-content:center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9); }
.adu-card-title { font-family:"Sora",sans-serif; font-size: 17px; font-weight: 700; color:#2c2a28; letter-spacing: -0.01em; }
.adu-card-desc { font-size: 13px; color:#6a6864; line-height: 1.55; flex: 1; }
.adu-card-foot { display:flex; align-items:center; justify-content:flex-end; gap: 6px; font-family:"DM Mono",monospace; font-size: 11px; font-weight: 600;
    letter-spacing: 0.06em; text-transform: uppercase; transition: gap .18s; }
.adu-card:hover .adu-card-foot { gap: 10px; }
.adu-card-foot svg { width: 14px; height: 14px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition: transform .18s; }
.adu-card:hover .adu-card-foot svg { transform: translateX(4px); }

@media (max-width: 1100px) {
    .adu-create-grid { grid-template-columns: repeat(3, 1fr); }
    .adu-discover-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .adu-create-grid { grid-template-columns: repeat(2, 1fr); }
    .adu-discover-grid { grid-template-columns: 1fr; }
    .adu-welcome-title { font-size: 26px; }
}
</style>';

ob_start();
?>

<div class="adu-wrap">
    <div class="adu-create-bar">
        <div class="adu-create-label">Accès rapide</div>
        <div class="adu-create-grid">
            <?php foreach ($creerItems as $it): ?>
                <a href="<?= h($it['url']) ?>" class="adu-create-btn" style="color:<?= h($it['color']) ?>">
                    <div class="adu-create-ico" style="background:<?= h($it['color']) ?>14">
                        <?= adu_svg($it['ico'], $it['color'], 26) ?>
                    </div>
                    <div class="adu-create-lbl"><?= h($it['label']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="adu-welcome">
        <div class="adu-welcome-hand">👋</div>
        <div class="adu-welcome-title">
            Bienvenue<?= $prenom !== '' ? ' ' . h($prenom) : '' ?>
        </div>
        <div class="adu-welcome-sub">
            Voici votre espace Agency<?= $societeNom !== '' ? ' — ' . h($societeNom) : '' ?>.
            Utilisez les accès rapides ou explorez les rubriques.
        </div>
    </div>

    <div class="adu-discover-label">Explorer</div>
    <div class="adu-discover-grid">
        <?php foreach ($decouvrirItems as $it): ?>
            <a href="<?= h($it['url']) ?>" class="adu-card" style="border-left-color:<?= h($it['color']) ?>;color:<?= h($it['color']) ?>">
                <div class="adu-card-head">
                    <div class="adu-card-ico" style="background:<?= h($it['color']) ?>14">
                        <?= adu_svg($it['ico'], $it['color'], 22) ?>
                    </div>
                    <div class="adu-card-title"><?= h($it['label']) ?></div>
                </div>
                <div class="adu-card-desc"><?= h($it['desc']) ?></div>
                <div class="adu-card-foot">
                    Ouvrir <?= adu_svg($icoArrow, 'currentColor', 14) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>

