<?php
/**
 * inc/rh_dashboard_pilot_view.php — Vue partagée des dashboards RH pilotage
 * (admin + manager). Attend en entrée :
 *   $creerItems, $decouvrirItems  : tableaux d'items (label,url,ico,color[,desc])
 *   $prenom, $societeNom          : pour le bandeau de bienvenue
 *   $rhWelcomeLead (optionnel)    : phrase d'accroche sous le titre
 *   rh_svg(), $icoArrow           : helper + icône flèche
 * Définit $layout_content puis inclut le layout.
 */
if (!isset($creerItems, $decouvrirItems)) { return; }
$rhWelcomeLead = $rhWelcomeLead ?? 'Créez rapidement au-dessus ou explorez les rubriques ci-dessous.';

$layout_extra_css = '<style>
.rh-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }
.rh-create-bar { background: linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border-radius: 22px; box-shadow: 8px 8px 22px rgba(196,192,186,0.55), -8px -8px 22px #fff; padding: 22px 24px; margin-bottom: 32px; }
.rh-create-label { font-family: "DM Mono", monospace; font-size: 10px; font-weight: 600; color: #9a9690; letter-spacing: 0.16em; text-transform: uppercase; margin-bottom: 14px; display: flex; align-items: center; gap: 10px; }
.rh-create-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.rh-create-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 150px)); gap: 12px; justify-content: center; }
.rh-create-btn { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 18px 10px 16px; border-radius: 16px; background: #fff; border: 2px solid transparent; text-decoration: none; color: #1a1816; transition: transform .18s cubic-bezier(0.22,1,0.36,1), box-shadow .18s, border-color .18s; box-shadow: 4px 4px 10px rgba(196,192,186,0.35), -4px -4px 10px #fff; position: relative; overflow: hidden; }
.rh-create-btn::before { content: "+"; position: absolute; top: 8px; right: 10px; font-size: 18px; font-weight: 300; color: #c8c4be; line-height: 1; }
.rh-create-btn:hover { transform: translateY(-4px); box-shadow: 8px 12px 20px rgba(196,192,186,0.55), -6px -6px 14px #fff; border-color: currentColor; }
.rh-create-btn:hover::before { color: currentColor; font-weight: 500; }
.rh-create-ico { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: inset 3px 3px 7px rgba(0,0,0,0.06), inset -3px -3px 7px rgba(255,255,255,0.9); }
.rh-create-lbl { font-family: "Sora", sans-serif; font-size: 13px; font-weight: 700; color: #2c2a28; letter-spacing: -0.01em; }
.rh-welcome { text-align: center; margin-bottom: 36px; padding: 0 20px; }
.rh-welcome-hand { font-size: 48px; line-height: 1; display: inline-block; animation: rhwave 2.4s ease-in-out 0.3s 2; transform-origin: 70% 70%; margin-bottom: 10px; }
@keyframes rhwave { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(14deg); } 40% { transform: rotate(-8deg); } 60% { transform: rotate(14deg); } 80% { transform: rotate(-4deg); } }
.rh-welcome-title { font-family: "Sora", sans-serif; font-size: 32px; font-weight: 700; color: #2d5f6b; letter-spacing: -0.02em; margin-bottom: 10px; }
.rh-welcome-sub { font-size: 15px; color: #6a6864; max-width: 580px; margin: 0 auto; line-height: 1.55; }
.rh-discover-label { font-family: "DM Mono", monospace; font-size: 10px; font-weight: 600; color: #9a9690; letter-spacing: 0.16em; text-transform: uppercase; margin-bottom: 16px; padding: 0 4px; display: flex; align-items: center; gap: 10px; }
.rh-discover-label::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, #d4d7de, transparent); }
.rh-discover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.rh-card { background: #fff; border-radius: 20px; padding: 22px 22px 20px; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 12px; box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff; transition: transform .22s cubic-bezier(0.22,1,0.36,1), box-shadow .22s; border-left: 4px solid transparent; min-height: 180px; position: relative; overflow: hidden; }
.rh-card::after { content: ""; position: absolute; inset: 0; border-radius: inherit; background: radial-gradient(ellipse at 100% 0%, rgba(107,142,111,0.06) 0%, transparent 60%); pointer-events: none; opacity: 0; transition: opacity .22s; }
.rh-card:hover { transform: translateY(-4px); box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff; }
.rh-card:hover::after { opacity: 1; }
.rh-card-head { display: flex; align-items: center; gap: 14px; }
.rh-card-ico { width: 48px; height: 48px; border-radius: 13px; display: flex; align-items: center; justify-content: center; box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9); flex-shrink: 0; }
.rh-card-title { font-family: "Sora", sans-serif; font-size: 17px; font-weight: 700; color: #2c2a28; letter-spacing: -0.01em; }
.rh-card-desc { font-size: 13px; color: #6a6864; line-height: 1.55; flex: 1; }
.rh-card-foot { display: flex; align-items: center; justify-content: flex-end; gap: 6px; font-family: "DM Mono", monospace; font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; transition: gap .18s; }
.rh-card:hover .rh-card-foot { gap: 10px; }
.rh-card-foot svg { width: 14px; height: 14px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .18s; }
.rh-card:hover .rh-card-foot svg { transform: translateX(4px); }
@media (max-width: 1100px) { .rh-discover-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .rh-create-grid { grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); } .rh-discover-grid { grid-template-columns: 1fr; } .rh-welcome-title { font-size: 26px; } }
</style>';

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

    <!-- Bienvenue -->
    <div class="rh-welcome">
        <div class="rh-welcome-hand">👋</div>
        <div class="rh-welcome-title">
            Bienvenue<?= $prenom !== '' ? ' ' . htmlspecialchars($prenom) : '' ?>
        </div>
        <div class="rh-welcome-sub">
            Bienvenue dans votre espace RH<?= $societeNom !== '' ? ' — ' . htmlspecialchars($societeNom) : '' ?>.
            <?= htmlspecialchars($rhWelcomeLead) ?>
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
require_once __DIR__ . '/layout_maboximmo.php';
