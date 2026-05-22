<?php
declare(strict_types=1);

/**
 * agency_dashboard_metier.php — Dashboard "Métier" (Réunions, tâches, registres, factures…)
 * Même modèle UI que agency_dashboard.php (barre Créer + Explorer).
 */

$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$layout_title          = 'Dashboard Agency — Métier';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

function adm_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

// Icônes (style identique à agency_dashboard / rh_dashboard)
$icoCal     = '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>';
$icoCheck   = '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>';
$icoArchive = '<rect x="2" y="4" width="20" height="5" rx="1"/><path d="M4 9v10a2 2 0 002 2h12a2 2 0 002-2V9"/><line x1="10" y1="13" x2="14" y2="13"/>';
$icoInvoice = '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>';
$icoMegaph  = '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/>';
$icoArrow   = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';

// Barre de création — actions métier
$creerItems = [
    ['label' => 'Réunion',  'url' => 'agency_reunion_form.php',    'ico' => $icoCal,     'color' => '#7a6830'],
    ['label' => 'Tâche',    'url' => 'agency_tache_detail.php?new=1','ico' => $icoCheck,  'color' => '#4a6d4e'],
    ['label' => 'Registre', 'url' => 'agency_registre_form.php',   'ico' => $icoArchive, 'color' => '#5a6e8a'],
    ['label' => 'Facture',  'url' => 'agency_facture_form.php',    'ico' => $icoInvoice, 'color' => '#a85858'],
    ['label' => 'Diffusion','url' => 'agency_dashboard_diffusion.php','ico' => $icoMegaph,'color' => '#2d5f6b'],
];

// Explorer — vues métier
$decouvrirItems = [
    ['label' => 'Réunions',   'desc' => 'Planification, tenue, PV et suivi des réunions / AG.',     'url' => 'agency_reunions.php',          'ico' => $icoCal,     'color' => '#7a6830'],
    ['label' => 'Tâches',     'desc' => 'Suivi des tâches, relances et actions par immeuble.',       'url' => 'agency_taches.php',            'ico' => $icoCheck,   'color' => '#4a6d4e'],
    ['label' => 'Registres',  'desc' => 'Registres réglementaires, archives et documents structurés.','url' => 'agency_registres.php',         'ico' => $icoArchive, 'color' => '#5a6e8a'],
    ['label' => 'Factures',   'desc' => 'Factures émises et reçues, suivi financier.',              'url' => 'agency_factures.php',          'ico' => $icoInvoice, 'color' => '#a85858'],
    ['label' => 'Diffusion',  'desc' => 'Pilotage diffusion / supports / flux.',                    'url' => 'agency_dashboard_diffusion.php','ico' => $icoMegaph,  'color' => '#2d5f6b'],
];

$layout_extra_css = '<style>
.adm-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }

/* ── Barre de création ─────────────────────────────────────── */
.adm-create-bar {
    background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%);
    border-radius: 22px;
    box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff;
    padding: 22px 24px;
    margin-bottom: 32px;
}
.adm-create-label {
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
.adm-create-label::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, #d4d7de, transparent);
}
.adm-create-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.adm-create-btn {
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
.adm-create-btn:hover { transform: translateY(-2px); box-shadow: 8px 10px 18px rgba(196,192,186,0.45), -6px -6px 14px #fff; }
.adm-create-ico {
    width: 52px; height: 52px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
}
.adm-create-lbl { font-size: 13px; font-weight: 700; text-align:center; }

/* ── Welcome banner ─────────────────────────────────────── */
.adm-welcome { text-align: center; margin-bottom: 36px; padding: 0 20px; }
.adm-welcome-hand { font-size: 48px; line-height: 1; display: inline-block; margin-bottom: 10px; }
.adm-welcome-title {
    font-family: "Sora", sans-serif; font-size: 32px; font-weight: 700;
    color: #243B5C; letter-spacing: -0.02em; margin-bottom: 10px;
}
.adm-welcome-sub {
    font-size: 15px; color: #6a6864;
    max-width: 680px; margin: 0 auto; line-height: 1.55;
}

/* ── Section découverte ─────────────────────────────────────── */
.adm-discover-label {
    font-family: "DM Mono", monospace;
    font-size: 10px; font-weight: 600; color: #9a9690;
    letter-spacing: 0.16em; text-transform: uppercase;
    margin-bottom: 16px; padding: 0 4px;
    display: flex; align-items: center; gap: 10px;
}
.adm-discover-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.adm-discover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.adm-card {
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
.adm-card:hover { transform: translateY(-4px); box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff; }
.adm-card-head { display: flex; align-items: center; gap: 14px; }
.adm-card-ico {
    width: 48px; height: 48px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
    flex-shrink: 0;
}
.adm-card-title {
    font-family: "Sora", sans-serif; font-size: 17px; font-weight: 700;
    color: #2c2a28; letter-spacing: -0.01em;
}
.adm-card-desc { font-size: 13px; color: #6a6864; line-height: 1.55; flex: 1; }
.adm-card-foot {
    display: flex; align-items: center; justify-content: flex-end; gap: 6px;
    font-family: "DM Mono", monospace; font-size: 11px; font-weight: 600;
    letter-spacing: 0.06em; text-transform: uppercase;
    transition: gap .18s;
}
.adm-card:hover .adm-card-foot { gap: 10px; }
.adm-card-foot svg { width: 14px; height: 14px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .18s; }
.adm-card:hover .adm-card-foot svg { transform: translateX(4px); }

@media (max-width: 1100px) {
    .adm-create-grid { grid-template-columns: repeat(3, 1fr); }
    .adm-discover-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .adm-create-grid { grid-template-columns: repeat(2, 1fr); }
    .adm-discover-grid { grid-template-columns: 1fr; }
    .adm-welcome-title { font-size: 26px; }
}
</style>';

ob_start();
?>

<div class="adm-wrap">

    <div class="adm-create-bar">
        <div class="adm-create-label">Créer</div>
        <div class="adm-create-grid">
            <?php foreach ($creerItems as $it): ?>
                <a href="<?= htmlspecialchars($it['url']) ?>" class="adm-create-btn" style="color:<?= $it['color'] ?>">
                    <div class="adm-create-ico" style="background:<?= $it['color'] ?>14">
                        <?= adm_svg($it['ico'], $it['color'], 26) ?>
                    </div>
                    <div class="adm-create-lbl"><?= htmlspecialchars($it['label']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="adm-welcome">
        <div class="adm-welcome-hand">👋</div>
        <div class="adm-welcome-title">
            Métier<?= $prenom !== '' ? ' · ' . htmlspecialchars($prenom) : '' ?>
        </div>
        <div class="adm-welcome-sub">
            Pilotage métier Agency<?= $societeNom !== '' ? ' — ' . htmlspecialchars($societeNom) : '' ?>.
            Créez rapidement au-dessus ou explorez les rubriques ci-dessous.
        </div>
    </div>

    <div class="adm-discover-label">Explorer</div>
    <div class="adm-discover-grid">
        <?php foreach ($decouvrirItems as $it): ?>
            <a href="<?= htmlspecialchars($it['url']) ?>" class="adm-card" style="border-left-color:<?= $it['color'] ?>;color:<?= $it['color'] ?>">
                <div class="adm-card-head">
                    <div class="adm-card-ico" style="background:<?= $it['color'] ?>14">
                        <?= adm_svg($it['ico'], $it['color'], 22) ?>
                    </div>
                    <div class="adm-card-title"><?= htmlspecialchars($it['label']) ?></div>
                </div>
                <div class="adm-card-desc"><?= htmlspecialchars($it['desc']) ?></div>
                <div class="adm-card-foot">
                    Ouvrir <?= adm_svg($icoArrow, 'currentColor', 14) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>

