<?php
// rh_dashboard.php — Dashboard RH (aligné sur agency_dashboard : barre Créer + Explorer)
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

// Prénom uniquement pour le message de bienvenue (pas le nom)
$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

// Layout
$layout_title          = 'Dashboard RH';
$layout_module         = 'Ma Box RH';
$layout_sidebar        = 'rh_sidebar';
$layout_hide_page_head = true;

function rh_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

// Icônes
$icoUser     = '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>';
$icoUsers    = '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>';
$icoCalendar = '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>';
$icoChat     = '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>';
$icoCash     = '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>';
$icoFile     = '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>';
$icoCar      = '<path d="M5 17h14l-1.5-5H6.5z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>';
$icoMail     = '<path d="M4 4h16a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2z"/><polyline points="22,6 12,13 2,6"/>';
$icoCheck    = '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>';
$icoProfile  = '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M6 20a6 6 0 0112 0"/>';
$icoArrow    = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';

// ── Barre de création — raccourcis pour créer dans chaque module ──
$creerItems = [
    ['label' => 'Collaborateur', 'url' => 'rh_user_add.php',                       'ico' => $icoUser,     'color' => '#4878a6'],
    ['label' => 'Congé',         'url' => 'rh_conges.php?new=1',                   'ico' => $icoCalendar, 'color' => '#c97b2e'],
    ['label' => 'Entretien',     'url' => 'rh_entretien_ajouter.php',              'ico' => $icoChat,     'color' => '#7a6898'],
    ['label' => 'Salaire',       'url' => 'rh_salaires.php?new=1',                 'ico' => $icoCash,     'color' => '#6b8e6f'],
    ['label' => 'Document',      'url' => 'rh_documents.php?new=1',                'ico' => $icoFile,     'color' => '#2d5f6b'],
    ['label' => 'Indemnité KM',  'url' => 'rh_indemnite_km.php?new=1',             'ico' => $icoCar,      'color' => '#a85858'],
    ['label' => 'Mail RH',       'url' => 'rh_mails.php?new=1',                    'ico' => $icoMail,     'color' => '#7a6830'],
];

// ── Rubriques à explorer — modules RH principaux ──
$decouvrirItems = [
    ['label' => 'Collaborateurs', 'desc' => 'Fiches de votre équipe : coordonnées, poste, contrats et historique.',         'url' => 'rh_user.php',              'ico' => $icoUsers,    'color' => '#4878a6'],
    ['label' => 'Congés',         'desc' => 'Demandes en cours, soldes par collaborateur et historique des validations.',   'url' => 'rh_conges.php',            'ico' => $icoCalendar, 'color' => '#c97b2e'],
    ['label' => 'Validation congés','desc' => 'Examinez et validez les demandes de congés en attente.',                      'url' => 'rh_conges_validation.php', 'ico' => $icoCheck,    'color' => '#a85858'],
    ['label' => 'Entretiens',     'desc' => 'Planification, conduite et archivage des entretiens annuels et pro.',           'url' => 'rh_entretien_liste.php',   'ico' => $icoChat,     'color' => '#7a6898'],
    ['label' => 'Salaires',       'desc' => 'Saisie des fiches de paie, barèmes, suivi mensuel et historique.',              'url' => 'rh_salaires.php',          'ico' => $icoCash,     'color' => '#6b8e6f'],
    ['label' => 'Documents',      'desc' => 'Pièces administratives, contrats, assurances et documents obligatoires.',       'url' => 'rh_documents.php',         'ico' => $icoFile,     'color' => '#2d5f6b'],
    ['label' => 'Indemnités KM',  'desc' => 'Calcul et suivi des indemnités kilométriques selon le barème légal.',           'url' => 'rh_indemnite_km.php',      'ico' => $icoCar,      'color' => '#a85858'],
    ['label' => 'Mails RH',       'desc' => 'Modèles d\'emails, envois automatiques, notifications collaborateurs.',         'url' => 'rh_mails.php',             'ico' => $icoMail,     'color' => '#7a6830'],
    ['label' => 'Mon profil',     'desc' => 'Vos informations personnelles, soldes congés et fiches de paie.',               'url' => 'rh_profil.php',            'ico' => $icoProfile,  'color' => '#5a6e8a'],
];

$layout_extra_css = '<style>
.rh-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }

/* ── Barre de création ─────────────────────────────────────── */
.rh-create-bar {
    background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%);
    border-radius: 22px;
    box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff;
    padding: 22px 24px;
    margin-bottom: 32px;
}
.rh-create-label {
    font-family: "DM Mono", monospace;
    font-size: 10px; font-weight: 600; color: #9a9690;
    letter-spacing: 0.16em; text-transform: uppercase;
    margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
}
.rh-create-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.rh-create-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; }
.rh-create-btn {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 18px 10px 16px;
    border-radius: 16px;
    background: #fff;
    border: 2px solid transparent;
    text-decoration: none; color: #1a1816;
    transition: transform .18s cubic-bezier(0.22,1,0.36,1), box-shadow .18s, border-color .18s;
    box-shadow: 4px 4px 10px rgba(196,192,186,0.35), -4px -4px 10px #fff;
    position: relative; overflow: hidden;
}
.rh-create-btn::before {
    content: "+"; position: absolute; top: 8px; right: 10px;
    font-size: 18px; font-weight: 300; color: #c8c4be; line-height: 1;
}
.rh-create-btn:hover {
    transform: translateY(-4px);
    box-shadow: 8px 12px 20px rgba(196,192,186,0.55), -6px -6px 14px #fff;
    border-color: currentColor;
}
.rh-create-btn:hover::before { color: currentColor; font-weight: 500; }
.rh-create-ico {
    width: 52px; height: 52px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 3px 3px 7px rgba(0,0,0,0.06), inset -3px -3px 7px rgba(255,255,255,0.9);
}
.rh-create-lbl {
    font-family: "Sora", sans-serif; font-size: 13px; font-weight: 700;
    color: #2c2a28; letter-spacing: -0.01em;
}

/* ── Welcome banner ─────────────────────────────────────── */
.rh-welcome { text-align: center; margin-bottom: 36px; padding: 0 20px; }
.rh-welcome-hand {
    font-size: 48px; line-height: 1; display: inline-block;
    animation: rhwave 2.4s ease-in-out 0.3s 2;
    transform-origin: 70% 70%; margin-bottom: 10px;
}
@keyframes rhwave {
    0%, 100% { transform: rotate(0); }
    20% { transform: rotate(14deg); }
    40% { transform: rotate(-8deg); }
    60% { transform: rotate(14deg); }
    80% { transform: rotate(-4deg); }
}
.rh-welcome-title {
    font-family: "Sora", sans-serif; font-size: 32px; font-weight: 700;
    color: #2d5f6b; letter-spacing: -0.02em; margin-bottom: 10px;
}
.rh-welcome-sub {
    font-size: 15px; color: #6a6864;
    max-width: 580px; margin: 0 auto; line-height: 1.55;
}

/* ── Section découverte ─────────────────────────────────────── */
.rh-discover-label {
    font-family: "DM Mono", monospace;
    font-size: 10px; font-weight: 600; color: #9a9690;
    letter-spacing: 0.16em; text-transform: uppercase;
    margin-bottom: 16px; padding: 0 4px;
    display: flex; align-items: center; gap: 10px;
}
.rh-discover-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.rh-discover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.rh-card {
    background: #fff;
    border-radius: 20px;
    padding: 22px 22px 20px;
    text-decoration: none; color: inherit;
    display: flex; flex-direction: column; gap: 12px;
    box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff;
    transition: transform .22s cubic-bezier(0.22,1,0.36,1), box-shadow .22s;
    border-left: 4px solid transparent;
    min-height: 180px;
    position: relative; overflow: hidden;
}
.rh-card::after {
    content: ""; position: absolute; inset: 0;
    border-radius: inherit;
    background: radial-gradient(ellipse at 100% 0%, rgba(107,142,111,0.06) 0%, transparent 60%);
    pointer-events: none; opacity: 0;
    transition: opacity .22s;
}
.rh-card:hover {
    transform: translateY(-4px);
    box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff;
}
.rh-card:hover::after { opacity: 1; }
.rh-card-head { display: flex; align-items: center; gap: 14px; }
.rh-card-ico {
    width: 48px; height: 48px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
    flex-shrink: 0;
}
.rh-card-title {
    font-family: "Sora", sans-serif; font-size: 17px; font-weight: 700;
    color: #2c2a28; letter-spacing: -0.01em;
}
.rh-card-desc { font-size: 13px; color: #6a6864; line-height: 1.55; flex: 1; }
.rh-card-foot {
    display: flex; align-items: center; justify-content: flex-end; gap: 6px;
    font-family: "DM Mono", monospace; font-size: 11px; font-weight: 600;
    letter-spacing: 0.06em; text-transform: uppercase;
    transition: gap .18s;
}
.rh-card:hover .rh-card-foot { gap: 10px; }
.rh-card-foot svg { width: 14px; height: 14px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .18s; }
.rh-card:hover .rh-card-foot svg { transform: translateX(4px); }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width: 1100px) {
    .rh-create-grid { grid-template-columns: repeat(4, 1fr); }
    .rh-discover-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .rh-create-grid { grid-template-columns: repeat(2, 1fr); }
    .rh-discover-grid { grid-template-columns: 1fr; }
    .rh-welcome-title { font-size: 26px; }
}
</style>';

// ── Contenu ─────────────────────────────────────────────────────────
ob_start();
?>

<div class="rh-wrap">

    <!-- Barre de création rapide -->
    <div class="rh-create-bar">
        <div class="rh-create-label">Créer</div>
        <div class="rh-create-grid">
            <?php foreach ($creerItems as $it): ?>
            <a href="<?= htmlspecialchars($it['url']) ?>" class="rh-create-btn" style="color:<?= $it['color'] ?>">
                <div class="rh-create-ico" style="background:<?= $it['color'] ?>14">
                    <?= rh_svg($it['ico'], $it['color'], 26) ?>
                </div>
                <div class="rh-create-lbl"><?= htmlspecialchars($it['label']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Bienvenue (prénom uniquement) -->
    <div class="rh-welcome">
        <div class="rh-welcome-hand">👋</div>
        <div class="rh-welcome-title">
            Bienvenue<?= $prenom !== '' ? ' ' . htmlspecialchars($prenom) : '' ?>
        </div>
        <div class="rh-welcome-sub">
            Bienvenue dans votre espace RH<?= $societeNom !== '' ? ' — ' . htmlspecialchars($societeNom) : '' ?>.
            Créez rapidement au-dessus ou explorez les rubriques ci-dessous.
        </div>
    </div>

    <!-- Découverte -->
    <div class="rh-discover-label">Explorer</div>
    <div class="rh-discover-grid">
        <?php foreach ($decouvrirItems as $it): ?>
        <a href="<?= htmlspecialchars($it['url']) ?>" class="rh-card" style="border-left-color:<?= $it['color'] ?>;color:<?= $it['color'] ?>">
            <div class="rh-card-head">
                <div class="rh-card-ico" style="background:<?= $it['color'] ?>14">
                    <?= rh_svg($it['ico'], $it['color'], 22) ?>
                </div>
                <div class="rh-card-title"><?= htmlspecialchars($it['label']) ?></div>
            </div>
            <div class="rh-card-desc"><?= htmlspecialchars($it['desc']) ?></div>
            <div class="rh-card-foot">
                Ouvrir <?= rh_svg($icoArrow, 'currentColor', 14) ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
