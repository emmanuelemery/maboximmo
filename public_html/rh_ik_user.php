<?php
/**
 * rh_ik_user.php — Page teaser « Indemnités kilométriques » (rôle user).
 *
 * Placeholder pour la future déclaration mensuelle des IK. Visible dans le
 * dashboard user seulement si users.ik_enabled = 1.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Indemnités KM — ' . htmlspecialchars($_userName, ENT_QUOTES, 'UTF-8');
$layout_module  = 'Mon espace · Collaborateur';
$layout_sidebar = 'rh_sidebar';
$layout_hide_page_head = true;

$layout_extra_css = <<<'CSS'
<style>
.teaser-card { background:#ffffff; border-radius:18px; box-shadow:6px 6px 14px #d4d7de,-6px -6px 14px #fff; padding:32px 34px; max-width:780px; margin:0 auto; }
.teaser-hero { display:flex; align-items:center; gap:20px; padding-bottom:22px; border-bottom:1px solid rgba(196,192,186,0.4); }
.teaser-icon { width:72px; height:72px; border-radius:18px; background:linear-gradient(135deg,#4a6038,#304828); display:flex; align-items:center; justify-content:center; font-size:36px; box-shadow:4px 4px 12px #c8cbd2,-4px -4px 12px #fff; flex-shrink:0; }
.teaser-hero h2 { font-size:22px; color:#2f587d; margin-bottom:6px; }
.teaser-hero p { font-size:13px; color:#8a8680; line-height:1.55; }
.teaser-badge { display:inline-block; margin-top:8px; padding:4px 12px; border-radius:999px; background:#f0e2da; color:#8a5040; font-family:'DM Mono',monospace; font-size:9px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; }
.teaser-body { padding-top:22px; }
.teaser-body h3 { font-size:13px; color:#4a6038; text-transform:uppercase; letter-spacing:.1em; margin-bottom:14px; font-weight:700; }
.teaser-list { list-style:none; display:flex; flex-direction:column; gap:12px; }
.teaser-list li { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; background:#e8efe0; border-radius:12px; box-shadow:inset 2px 2px 5px rgba(180,190,170,0.4),inset -2px -2px 5px #fff; }
.teaser-list .check { width:22px; height:22px; border-radius:50%; background:#4a6038; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
.teaser-list strong { color:#2f587d; font-size:13px; display:block; margin-bottom:3px; }
.teaser-list span { color:#6a6660; font-size:12px; line-height:1.55; }
.teaser-cta { margin-top:22px; padding-top:20px; border-top:1px solid rgba(196,192,186,0.4); display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.teaser-btn { padding:10px 18px; border-radius:10px; background:#ffffff; box-shadow:3px 3px 7px #d4d7de,-3px -3px 7px #fff; font-size:12px; font-weight:600; color:#6a6660; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.teaser-btn:hover { color:#2f587d; }
.teaser-note { font-size:11px; color:#a8a49e; font-style:italic; }
</style>
CSS;

ob_start();
?>
<div class="teaser-card">
    <div class="teaser-hero">
        <div class="teaser-icon">🚗</div>
        <div>
            <h2>Vos indemnités kilométriques</h2>
            <p>Déclarez vos trajets professionnels en véhicule personnel et obtenez le remboursement au barème officiel, sans ressaisie.</p>
            <span class="teaser-badge">🚀 Bientôt disponible</span>
        </div>
    </div>

    <div class="teaser-body">
        <h3>Ce que vous pourrez faire ici</h3>
        <ul class="teaser-list">
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Saisie rapide : départ → arrivée = distance auto</strong>
                    <span>La distance est calculée via Google Maps, pas besoin de chercher sur un GPS.</span>
                </div>
            </li>
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Barème fiscal appliqué automatiquement</strong>
                    <span>Le montant à rembourser est calculé selon la puissance fiscale de votre véhicule, conformément au barème en vigueur.</span>
                </div>
            </li>
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Trajets récurrents enregistrables</strong>
                    <span>Gagnez du temps : vos visites régulières sont mémorisées et relancées en un clic.</span>
                </div>
            </li>
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Récapitulatif mensuel exportable</strong>
                    <span>Un PDF mensuel transmis au RH en fin de mois, avec total kilométrique et montant dû.</span>
                </div>
            </li>
        </ul>
    </div>

    <div class="teaser-cta">
        <a href="rh_dashboard_user.php" class="teaser-btn">← Retour au tableau de bord</a>
        <span class="teaser-note">Cette fonctionnalité sera activée prochainement.</span>
    </div>
</div>
<?php
$layout_content = ob_get_clean();
require __DIR__ . '/inc/layout_maboximmo.php';
