<?php
/**
 * rh_salaire_user.php — Page teaser « Préparation du salaire » (rôle user).
 *
 * Page placeholder pour les futures fonctionnalités de validation mensuelle
 * des éléments de salaire. Affiche les avantages à venir pour donner envie
 * au collaborateur de revenir dès l'activation.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Préparation salaire — ' . htmlspecialchars($_userName, ENT_QUOTES, 'UTF-8');
$layout_module  = 'Mon espace · Collaborateur';
$layout_sidebar = 'rh_sidebar';
$layout_hide_page_head = true;

$layout_extra_css = <<<'CSS'
<style>
.teaser-card {
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 32px 34px;
    max-width: 780px; margin: 0 auto;
}
.teaser-hero {
    display: flex; align-items: center; gap: 20px;
    padding-bottom: 22px;
    border-bottom: 1px solid rgba(196,192,186,0.4);
}
.teaser-icon {
    width: 72px; height: 72px; border-radius: 18px;
    background: linear-gradient(135deg, #4878a6, #2f587d);
    display: flex; align-items: center; justify-content: center;
    font-size: 36px;
    box-shadow: 4px 4px 12px #c8cbd2, -4px -4px 12px #fff;
    flex-shrink: 0;
}
.teaser-hero h2 { font-size: 22px; color: #2f587d; margin-bottom: 6px; }
.teaser-hero p  { font-size: 13px; color: #8a8680; line-height: 1.55; }
.teaser-badge {
    display: inline-block; margin-top: 8px;
    padding: 4px 12px; border-radius: 999px;
    background: #f0e2da; color: #8a5040;
    font-family: 'DM Mono', monospace; font-size: 9px;
    font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
}
.teaser-body { padding-top: 22px; }
.teaser-body h3 {
    font-size: 13px; color: #4a6038; text-transform: uppercase;
    letter-spacing: .1em; margin-bottom: 14px; font-weight: 700;
}
.teaser-list { list-style: none; display: flex; flex-direction: column; gap: 12px; }
.teaser-list li {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 12px 14px;
    background: #e8efe0;
    border-radius: 12px;
    box-shadow: inset 2px 2px 5px rgba(180,190,170,0.4), inset -2px -2px 5px #fff;
}
.teaser-list .check {
    width: 22px; height: 22px; border-radius: 50%;
    background: #4a6038; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; flex-shrink: 0;
}
.teaser-list strong { color: #2f587d; font-size: 13px; display: block; margin-bottom: 3px; }
.teaser-list span  { color: #6a6660; font-size: 12px; line-height: 1.55; }
.teaser-cta {
    margin-top: 22px; padding-top: 20px;
    border-top: 1px solid rgba(196,192,186,0.4);
    display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
}
.teaser-btn {
    padding: 10px 18px; border-radius: 10px;
    background: #ffffff;
    box-shadow: 3px 3px 7px #d4d7de, -3px -3px 7px #fff;
    font-size: 12px; font-weight: 600; color: #6a6660;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.teaser-btn:hover { color: #2f587d; }
.teaser-note { font-size: 11px; color: #a8a49e; font-style: italic; }
</style>
CSS;

ob_start();
?>
<div class="teaser-card">
    <div class="teaser-hero">
        <div class="teaser-icon">💰</div>
        <div>
            <h2>Préparation de votre salaire</h2>
            <p>Validez chaque mois vos éléments de rémunération en toute transparence, directement depuis votre espace.</p>
            <span class="teaser-badge">🚀 Bientôt disponible</span>
        </div>
    </div>

    <div class="teaser-body">
        <h3>Ce que vous pourrez faire ici</h3>
        <ul class="teaser-list">
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Vérifier votre fiche de paie avant émission</strong>
                    <span>Heures, primes, absences : tout est visible avant validation par votre gestionnaire.</span>
                </div>
            </li>
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Signaler une anomalie en un clic</strong>
                    <span>Plus besoin d'emails : un bouton dédié ouvre une demande de révision directement auprès du RH.</span>
                </div>
            </li>
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Historique mensuel consultable</strong>
                    <span>Retrouvez à tout moment vos bulletins validés, triés par mois et téléchargeables en PDF.</span>
                </div>
            </li>
            <li>
                <div class="check">✓</div>
                <div>
                    <strong>Rappel automatique chaque 20 du mois</strong>
                    <span>Le tableau de bord vous alerte pour ne jamais manquer une validation.</span>
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
