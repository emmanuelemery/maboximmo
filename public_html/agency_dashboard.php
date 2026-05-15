<?php
// agency_dashboard.php — Dashboard d'accueil simplifié (layout_maboximmo)
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$prenom = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

// Stats FluxBox retirées : la vue FluxBox/cartes est maintenant sur fluxbox.php (home)
// Cette page reste la vue métier classique (création + exploration).

// ── Layout ──────────────────────────────────────────────────────────
$layout_title          = 'Dashboard Agency';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

function ad_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

// Icônes
$icoHome    = '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>';
$icoBuild   = '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h6"/>';
$icoCal     = '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>';
$icoUser    = '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>';
$icoUsers2  = '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>';
$icoFile    = '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>';
$icoDollar  = '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>';
$icoCheck   = '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>';
$icoMegaph  = '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/>';
$icoInvoice = '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>';
$icoHandshake = '<path d="M11 17l-5-5 3-3 4 4 2-2-3-3 3-3 5 5-9 7z"/>';
$icoArchive = '<rect x="2" y="4" width="20" height="5" rx="1"/><path d="M4 9v10a2 2 0 002 2h12a2 2 0 002-2V9"/><line x1="10" y1="13" x2="14" y2="13"/>';
$icoArrow   = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';

// Barre de création — 7 actions rapides
$creerItems = [
    ['label' => 'Bien',        'url' => 'bien_detail.php',                  'ico' => $icoHome,    'color' => '#6b8e6f'],
    ['label' => 'Immeuble',    'url' => 'agency_immeuble_form.php',         'ico' => $icoBuild,   'color' => '#4878a6'],
    ['label' => 'Réunion',     'url' => 'agency_reunion_form.php',          'ico' => $icoCal,     'color' => '#7a6830'],
    ['label' => 'Propriétaire','url' => 'agency_proprietaires.php?new=1',   'ico' => $icoUser,    'color' => '#c97b2e'],
    ['label' => 'Tâche',       'url' => 'agency_tache_detail.php?new=1',    'ico' => $icoCheck,   'color' => '#4a6d4e'],
    ['label' => 'Annonce',     'url' => 'annonce_nouvelle.php',             'ico' => $icoMegaph,  'color' => '#a85858'],
    ['label' => 'Facture',     'url' => 'agency_facture_form.php',          'ico' => $icoInvoice, 'color' => '#2d5f6b'],
];

// Rubriques à découvrir — 8 cards (sidebar agency sauf Dashboard)
$decouvrirItems = [
    ['label' => 'Immeubles',     'desc' => 'Vos immeubles en portefeuille, leurs lots et leur suivi.',       'url' => 'agency_immeubles.php',   'ico' => $icoBuild,  'color' => '#4878a6'],
    ['label' => 'Propriétaires', 'desc' => 'Vos propriétaires bailleurs, fiches et historiques.',             'url' => 'agency_proprietaires.php','ico' => $icoUser,  'color' => '#c97b2e'],
    ['label' => 'Mandants',      'desc' => 'Les mandants de vos copropriétés et leurs coordonnées.',          'url' => 'agency_mandants.php',    'ico' => $icoUsers2, 'color' => '#7a6898'],
    ['label' => 'Mandats',       'desc' => 'Contrats de syndic : périodes, honoraires, renouvellements.',     'url' => 'agency_mandats.php',     'ico' => $icoHandshake, 'color' => '#6b8e6f'],
    ['label' => 'Réunions / AG', 'desc' => 'Prochaines assemblées, PV, planning et préparation.',             'url' => 'agency_reunions.php',    'ico' => $icoCal,    'color' => '#7a6830'],
    ['label' => 'Tâches',        'desc' => 'Vos tâches et actions de suivi pour chaque immeuble.',            'url' => 'agency_taches.php',      'ico' => $icoCheck,  'color' => '#4a6d4e'],
    ['label' => 'Contrats',      'desc' => 'Contrats fournisseurs : maintenance, entretien, prestations.',    'url' => 'agency_contrats.php',    'ico' => $icoFile,   'color' => '#2d5f6b'],
    ['label' => 'Factures',      'desc' => 'Factures émises et reçues, suivi financier.',                    'url' => 'agency_factures.php',    'ico' => $icoInvoice,'color' => '#a85858'],
    ['label' => 'Registres',     'desc' => 'Archives et registres réglementaires de vos immeubles.',          'url' => 'agency_registres.php',   'ico' => $icoArchive,'color' => '#5a6e8a'],
];

$layout_extra_css = '<style>
.ad-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }

/* ── Barre de création ─────────────────────────────────────── */
.ad-create-bar {
    background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%);
    border-radius: 22px;
    box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff;
    padding: 22px 24px;
    margin-bottom: 32px;
}
.ad-create-label {
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
.ad-create-label::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, #d4d7de, transparent);
}
.ad-create-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 12px;
}
.ad-create-btn {
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
.ad-create-btn::before {
    content: "+";
    position: absolute;
    top: 8px;
    right: 10px;
    font-size: 18px;
    font-weight: 300;
    color: #c8c4be;
    line-height: 1;
}
.ad-create-btn:hover {
    transform: translateY(-4px);
    box-shadow: 8px 12px 20px rgba(196,192,186,0.55), -6px -6px 14px #fff;
}
.ad-create-ico {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: inset 3px 3px 7px rgba(0,0,0,0.06), inset -3px -3px 7px rgba(255,255,255,0.9);
}
.ad-create-btn:hover { border-color: currentColor; }
.ad-create-btn:hover::before { color: currentColor; font-weight: 500; }
.ad-create-lbl {
    font-family: "Sora", sans-serif;
    font-size: 13px;
    font-weight: 700;
    color: #2c2a28;
    letter-spacing: -0.01em;
}

/* ── Welcome banner ─────────────────────────────────────── */
.ad-welcome {
    text-align: center;
    margin-bottom: 36px;
    padding: 0 20px;
}
.ad-welcome-hand {
    font-size: 48px;
    line-height: 1;
    display: inline-block;
    animation: wave 2.4s ease-in-out 0.3s 2;
    transform-origin: 70% 70%;
    margin-bottom: 10px;
}
@keyframes wave {
    0%, 100% { transform: rotate(0); }
    20% { transform: rotate(14deg); }
    40% { transform: rotate(-8deg); }
    60% { transform: rotate(14deg); }
    80% { transform: rotate(-4deg); }
}
.ad-welcome-title {
    font-family: "Sora", sans-serif;
    font-size: 32px;
    font-weight: 700;
    color: #2d5f6b;
    letter-spacing: -0.02em;
    margin-bottom: 10px;
}
.ad-welcome-sub {
    font-size: 15px;
    color: #6a6864;
    max-width: 580px;
    margin: 0 auto;
    line-height: 1.55;
}

/* ── Section découverte ─────────────────────────────────────── */
.ad-discover-label {
    font-family: "DM Mono", monospace;
    font-size: 10px;
    font-weight: 600;
    color: #9a9690;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 4px;
}
.ad-discover-label::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, #d4d7de, transparent);
}
.ad-discover-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
}
.ad-card {
    background: #fff;
    border-radius: 20px;
    padding: 22px 22px 20px;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    gap: 12px;
    box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff;
    transition: transform .22s cubic-bezier(0.22,1,0.36,1), box-shadow .22s;
    border-left: 4px solid transparent;
    min-height: 180px;
    position: relative;
    overflow: hidden;
}
.ad-card::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: radial-gradient(ellipse at 100% 0%, rgba(107,142,111,0.06) 0%, transparent 60%);
    pointer-events: none;
    opacity: 0;
    transition: opacity .22s;
}
.ad-card:hover {
    transform: translateY(-4px);
    box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff;
}
.ad-card:hover::after { opacity: 1; }
.ad-card-head {
    display: flex;
    align-items: center;
    gap: 14px;
}
.ad-card-ico {
    width: 48px;
    height: 48px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
    flex-shrink: 0;
}
.ad-card-title {
    font-family: "Sora", sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: #2c2a28;
    letter-spacing: -0.01em;
}
.ad-card-desc {
    font-size: 13px;
    color: #6a6864;
    line-height: 1.55;
    flex: 1;
}
.ad-card-foot {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    font-family: "DM Mono", monospace;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    transition: gap .18s;
}
.ad-card:hover .ad-card-foot { gap: 10px; }
.ad-card-foot svg { width: 14px; height: 14px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .18s; }
.ad-card:hover .ad-card-foot svg { transform: translateX(4px); }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width: 1100px) {
    .ad-create-grid { grid-template-columns: repeat(4, 1fr); }
    .ad-discover-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .ad-create-grid { grid-template-columns: repeat(2, 1fr); }
    .ad-discover-grid { grid-template-columns: 1fr; }
    .ad-welcome-title { font-size: 26px; }
}
</style>';

// ── Contenu ─────────────────────────────────────────────────────────
ob_start();
?>

<div class="ad-wrap">

    <!-- Bandeau retour FluxBox (cette page est maintenant secondaire) -->
    <div style="margin-bottom:18px;padding:10px 16px;background:#f8fafc;border-left:3px solid #243B5C;border-radius:8px;font-size:13px;color:#475569;">
        💡 Vue métier classique. Pour traiter vos flux entrants (téléchargements + mails),
        retournez sur <a href="./fluxbox.php" style="color:#243B5C;font-weight:600;">🃏 FluxBox</a>.
    </div>

    <!-- Barre de création rapide -->
    <div class="ad-create-bar">
        <div class="ad-create-label">Créer</div>
        <div class="ad-create-grid">
            <?php foreach ($creerItems as $it): ?>
            <a href="<?= htmlspecialchars($it['url']) ?>" class="ad-create-btn" style="color:<?= $it['color'] ?>">
                <div class="ad-create-ico" style="background:<?= $it['color'] ?>14">
                    <?= ad_svg($it['ico'], $it['color'], 26) ?>
                </div>
                <div class="ad-create-lbl"><?= htmlspecialchars($it['label']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Bienvenue -->
    <div class="ad-welcome">
        <div class="ad-welcome-hand">👋</div>
        <div class="ad-welcome-title">
            Bienvenue<?= $prenom !== '' ? ' ' . htmlspecialchars($prenom) : '' ?>
        </div>
        <div class="ad-welcome-sub">
            Voici votre espace <?= htmlspecialchars($societeNom ?: 'Agency') ?>. Créez rapidement au-dessus ou explorez les rubriques ci-dessous.
        </div>
    </div>

    <!-- Découverte -->
    <div class="ad-discover-label">Explorer</div>
    <div class="ad-discover-grid">
        <?php foreach ($decouvrirItems as $it): ?>
        <a href="<?= htmlspecialchars($it['url']) ?>" class="ad-card" style="border-left-color:<?= $it['color'] ?>;color:<?= $it['color'] ?>">
            <div class="ad-card-head">
                <div class="ad-card-ico" style="background:<?= $it['color'] ?>14">
                    <?= ad_svg($it['ico'], $it['color'], 22) ?>
                </div>
                <div class="ad-card-title"><?= htmlspecialchars($it['label']) ?></div>
            </div>
            <div class="ad-card-desc"><?= htmlspecialchars($it['desc']) ?></div>
            <div class="ad-card-foot">
                Ouvrir <?= ad_svg($icoArrow, 'currentColor', 14) ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
