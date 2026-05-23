<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_admin_or_super_admin();

$layout_title          = 'Dashboard Super Admin';
$layout_module         = 'Super Admin';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$prenom = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$tools = [
    ['label' => 'Toutes les sociétés',     'url' => 'societe_super_admin.php',             'icon' => '🏢', 'color' => '#36577d'],
    ['label' => 'Base de données',         'url' => 'admin/admin_database.php',           'icon' => '🗄', 'color' => '#7a6830'],
    ['label' => 'Agences / Codes',         'url' => 'admin/admin_agences_codes.php',      'icon' => '🏷️', 'color' => '#6b8e6f'],
    ['label' => 'Init paramétrage',        'url' => 'admin/init_parametrage_societes.php','icon' => '⚙️', 'color' => '#7a6830'],
    ['label' => 'Rattrapage honoraires',   'url' => 'admin/admin_honoraires_recalc.php',  'icon' => '⚖️', 'color' => '#a85858'],
    ['label' => 'URL barème honoraires',   'url' => 'admin/admin_bareme.php',             'icon' => '📜', 'color' => '#c97b2e'],
    ['label' => 'Flux XML Ubiflow',        'url' => 'admin/admin_flux_ubiflow.php',       'icon' => '📡', 'color' => '#2d5f6b'],
    ['label' => 'Import GED V2',           'url' => 'super_admin_ged_import.php',         'icon' => '📥', 'color' => '#4878a6'],
    ['label' => 'Niveaux N1→N6',           'url' => 'super_admin_ged_niveaux.php',        'icon' => '🗂️', 'color' => '#6b8e6f'],
    ['label' => 'Modèle général',          'url' => 'super_admin_ged_modele.php',         'icon' => '🌐', 'color' => '#7a6898'],
    ['label' => 'Coffre accès',            'url' => 'super_admin_coffre_acces.php',       'icon' => '🔒', 'color' => '#7a6830'],
    ['label' => 'Guide GED',               'url' => 'super_admin_ged_guide.php',          'icon' => '📖', 'color' => '#5a6e8a'],
    ['label' => 'Glossaire GED',           'url' => 'admin/admin_ged_glossaire.php',      'icon' => '🏷️', 'color' => '#7a6898'],
    ['label' => 'Annonces (édition)',      'url' => 'admin/admin_annonces_table.php',     'icon' => '📋', 'color' => '#d4a843'],
    ['label' => 'Migrations BDD',          'url' => 'admin/admin_migrations.php',         'icon' => '🚀', 'color' => '#4a6038'],
    ['label' => 'Déploiement FTP',         'url' => 'admin/admin_deploy.php',             'icon' => '🛠️', 'color' => '#36577d'],
    ['label' => 'Optimiser photos',        'url' => 'admin/tools_photos_recompress.php',  'icon' => '🖼️', 'color' => '#2d5f6b'],
    ['label' => 'Design System',           'url' => 'design-system.php',                  'icon' => '🎨', 'color' => '#a85858'],
    ['label' => 'Debug session',           'url' => 'debug_session.php',                  'icon' => '🧪', 'color' => '#5a6e8a'],
    ['label' => 'Debug PDF export',        'url' => 'debug_pdf_export.php',               'icon' => '🧾', 'color' => '#c97b2e'],
    ['label' => 'Suppression propriétaires','url' => 'admin/admin_proprietaires_suppression.php', 'icon' => '🗑', 'color' => '#b91c1c'],
    ['label' => 'Réaffectation société',    'url' => 'admin/admin_reaffectation_societe.php',      'icon' => '↔️', 'color' => '#d97706'],
];

$layout_extra_css = '<style>
.sa-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }

/* ── Barre de groupe (same model as rh_dashboard) ─────────────────── */
.sa-group-bar {
    background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%);
    border-radius: 22px;
    box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff;
    padding: 20px 22px;
    margin-bottom: 20px;
}
.sa-group-label {
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
.sa-group-label::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, #d4d7de, transparent);
}
.sa-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
}
.sa-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 16px 10px 14px;
    border-radius: 16px;
    background: #fff;
    border: 2px solid transparent;
    text-decoration: none;
    color: #1a1816;
    transition: transform .18s cubic-bezier(0.22,1,0.36,1), box-shadow .18s, border-color .18s;
    box-shadow: 4px 4px 10px rgba(196,192,186,0.35), -4px -4px 10px #fff;
    position: relative;
    overflow: hidden;
    min-height: 120px;
}
.sa-btn:hover {
    transform: translateY(-2px);
    box-shadow: 8px 10px 18px rgba(196,192,186,0.45), -6px -6px 14px #fff;
}
.sa-btn:focus { outline: none; border-color: rgba(54, 87, 125, 0.30); }
.sa-ico {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9);
    flex-shrink: 0;
    font-size: 22px;
    line-height: 1;
}
.sa-lbl {
    font-family: "Sora", sans-serif;
    font-size: 13px;
    font-weight: 700;
    color: #2c2a28;
    letter-spacing: -0.01em;
    text-align: center;
}
.sa-hint {
    font-family: "DM Mono", monospace;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #8a8680;
    opacity: 0.85;
}

/* ── Welcome banner ─────────────────────────────────────────────── */
.sa-welcome { text-align: center; margin-bottom: 22px; padding: 0 20px; }
.sa-welcome-hand { font-size: 46px; line-height: 1; display: inline-block; margin-bottom: 10px; }
.sa-welcome-title {
    font-family: "Sora", sans-serif; font-size: 30px; font-weight: 800;
    color: #36577d; letter-spacing: -0.02em; margin-bottom: 10px;
}
.sa-welcome-sub {
    font-size: 14px; color: #6a6864;
    max-width: 680px; margin: 0 auto; line-height: 1.55;
}
.sa-warn {
    margin: 14px auto 0;
    max-width: 780px;
    padding: 10px 12px;
    border-radius: 12px;
    background: #fff7ed;
    border: 1px solid rgba(234, 88, 12, 0.20);
    color: #7c2d12;
    font-size: 12px;
}

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 1100px) {
    .sa-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 640px) {
    .sa-grid { grid-template-columns: repeat(2, 1fr); }
    .sa-welcome-title { font-size: 26px; }
}
</style>';

ob_start();
?>

<div class="sa-wrap">
    <div class="sa-welcome">
        <div class="sa-welcome-hand">🧰</div>
        <div class="sa-welcome-title">
            Super Admin<?= $prenom !== '' ? ' · ' . h($prenom) : '' ?>
        </div>
        <div class="sa-welcome-sub">
            Accès aux outils sensibles (BDD + données brutes)<?= $societeNom !== '' ? ' — ' . h($societeNom) : '' ?>.
            Utilisez les rubriques ci-dessous pour éviter les sidebars trop longues.
        </div>
        <div class="sa-warn">⚠️ Ces pages peuvent afficher/modifier des données brutes (BDD, exports, scripts). À réserver au Super Admin.</div>
    </div>

    <div class="sa-group-bar">
        <div class="sa-group-label">Outils</div>
        <div class="sa-grid">
            <?php foreach ($tools as $it): ?>
                <a href="<?= h($it['url']) ?>" class="sa-btn" title="<?= h($it['label']) ?>">
                    <div class="sa-ico" style="background:<?= h($it['color']) ?>14; color:<?= h($it['color']) ?>;">
                        <?= h($it['icon']) ?>
                    </div>
                    <div class="sa-lbl"><?= h($it['label']) ?></div>
                    <div class="sa-hint">Ouvrir</div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
