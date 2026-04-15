<?php
/**
 * inc/rh_user_banner.php — Bandeau d'annonce global pour le rôle user.
 *
 * Inclus automatiquement en tête de contenu par `inc/layout_maboximmo.php`
 * uniquement si `current_role_id() === 3`. Affiche :
 *
 *   - Avant onboarding complet : le message de bienvenue MaBoxImmo +
 *     rappel des 4 étapes à compléter (impossible à masquer).
 *   - Une fois l'onboarding terminé : un message de félicitations pendant
 *     24h, puis le bandeau disparaît totalement et définitivement.
 *
 * La détection « onboarding terminé » est gérée côté dashboard (qui bascule
 * users.onboarding_completed = 1 dès que les 4 tâches sont en 'done').
 * Ici on ne fait que lire le flag + son timestamp.
 */
declare(strict_types=1);

if (!function_exists('current_role_id') || current_role_id() !== 3) {
    return; // garde-fou : ne jamais afficher aux autres rôles
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { return; }

$__rhbUserId = (int)($_SESSION['user_id'] ?? 0);
if ($__rhbUserId <= 0) { return; }

try {
    $stmt = $pdo->prepare("
        SELECT onboarding_completed, onboarding_completed_at
        FROM users WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$__rhbUserId]);
    $__rhbRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $__rhbEx) {
    return;
}

$__rhbDone = (int)($__rhbRow['onboarding_completed'] ?? 0) === 1;
$__rhbDoneAt = $__rhbRow['onboarding_completed_at'] ?? null;

// Si onboarding terminé depuis plus de 24h → bandeau disparaît définitivement
if ($__rhbDone && $__rhbDoneAt) {
    $__rhbSeconds = time() - strtotime($__rhbDoneAt);
    if ($__rhbSeconds > 86400) {
        return;
    }
}
?>
<style>
/* ═══════════════════════════════════════════════════════════════════════
   BANDEAU ANNONCE USER — visible pour role=3 uniquement.
   Un seul point de modification du style global.
   ═══════════════════════════════════════════════════════════════════════ */
.rh-user-banner {
    background: linear-gradient(135deg, #2f587d 0%, #4878a6 50%, #36577d 100%);
    color: #fff;
    border-radius: 16px;
    padding: 22px 28px;
    margin-bottom: 20px;
    box-shadow: 6px 6px 20px rgba(47,88,125,0.25), -2px -2px 10px rgba(255,255,255,0.6);
    position: relative;
    overflow: hidden;
}
.rh-user-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 85% 15%, rgba(255,212,121,0.18) 0%, transparent 45%),
        radial-gradient(circle at 15% 85%, rgba(124,245,214,0.15) 0%, transparent 50%);
    pointer-events: none;
}
.rh-user-banner > * { position: relative; z-index: 1; }

.rh-user-banner h2 {
    font-size: 26px; font-weight: 800;
    margin-bottom: 10px;
    letter-spacing: -0.02em;
}
.rh-user-banner h2 .brand-highlight {
    font-size: 32px;
    font-weight: 900;
    color: #ffd479;
    text-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.rh-user-banner .rh-banner-intro {
    font-size: 13px; line-height: 1.6;
    color: rgba(255,255,255,0.92);
    margin-bottom: 14px;
    max-width: 780px;
}
.rh-user-banner .rh-banner-intro strong { color: #ffd479; font-weight: 700; }

.rh-banner-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 10px;
    margin: 14px 0 10px;
}
.rh-banner-step {
    background: #ffffff;
    border: 1px solid #f0f1f3;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 12px;
    display: flex; align-items: center; gap: 10px;
    backdrop-filter: blur(4px);
}
.rh-banner-step-ico {
    font-size: 18px; flex-shrink: 0;
}
.rh-banner-step-txt {
    font-weight: 600; color: rgba(255,255,255,0.95);
}
.rh-banner-step-num {
    font-family: 'DM Mono', monospace;
    font-size: 9px;
    color: rgba(255,212,121,0.85);
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    display: block;
    margin-bottom: 1px;
}

.rh-user-banner .rh-banner-signature {
    font-size: 11px;
    color: rgba(255,255,255,0.65);
    font-style: italic;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #ffffff;
}

/* ── Variante félicitations (post-onboarding, 24h) ── */
.rh-user-banner.congrats {
    background: linear-gradient(135deg, #4a6038 0%, #7a9060 50%, #3a7a6a 100%);
}
.rh-user-banner.congrats .rh-banner-intro strong { color: #ffe892; }
</style>

<?php if ($__rhbDone): ?>
<!-- MODE FÉLICITATIONS (24h) -->
<div class="rh-user-banner congrats">
    <h2>🎉 Bravo — Votre onboarding <span class="brand-highlight">MaBoxImmo</span> est terminé&nbsp;!</h2>
    <p class="rh-banner-intro">
        Toutes les étapes sont validées, votre espace est pleinement opérationnel. <strong>Merci pour votre réactivité.</strong>
        Vous pouvez maintenant utiliser MaBoxImmo au quotidien pour vos congés, documents, salaires et frais.
    </p>
    <p class="rh-banner-signature">— L'équipe MaBoxImmo</p>
</div>

<?php else: ?>
<!-- MODE BIENVENUE -->
<div class="rh-user-banner">
    <h2>🚀 Bienvenue sur <span class="brand-highlight">MaBoxImmo</span> — Votre nouvel espace RH</h2>
    <p class="rh-banner-intro">
        Depuis aujourd'hui, nous disons au revoir à <em>Registres.Syndicoffice</em> et bonjour à <strong>MaBoxImmo</strong>, votre nouveau compagnon RH au quotidien. Plus intuitif, plus complet, taillé pour votre activité — congés, documents, salaire, frais… <strong>tout au même endroit</strong>.
    </p>
    <p class="rh-banner-intro" style="margin-top:-4px">
        ⚡ <strong>Une seule condition</strong> pour débloquer le déploiement complet : chaque collaborateur doit valider les tâches <strong>dans l'ordre indiqué</strong>. Ces étapes permettent de synchroniser vos données et de garantir un démarrage sans accroc.
    </p>

    <div class="rh-banner-steps">
        <div class="rh-banner-step">
            <div class="rh-banner-step-ico">📄</div>
            <div>
                <span class="rh-banner-step-num">Étape 1</span>
                <span class="rh-banner-step-txt">Documents déposés</span>
            </div>
        </div>
        <div class="rh-banner-step">
            <div class="rh-banner-step-ico">👤</div>
            <div>
                <span class="rh-banner-step-num">Étape 2</span>
                <span class="rh-banner-step-txt">Profil complété à 100%</span>
            </div>
        </div>
        <div class="rh-banner-step">
            <div class="rh-banner-step-ico">🏖️</div>
            <div>
                <span class="rh-banner-step-num">Étape 3</span>
                <span class="rh-banner-step-txt">Congés confirmés</span>
            </div>
        </div>
        <div class="rh-banner-step">
            <div class="rh-banner-step-ico">🏢</div>
            <div>
                <span class="rh-banner-step-num">Étape 4</span>
                <span class="rh-banner-step-txt">Coordonnées agence vérifiées</span>
            </div>
        </div>
    </div>

    <p class="rh-banner-signature">
        📋 Chaque validation compte — merci pour votre réactivité et bienvenue dans votre nouvel espace RH. — L'équipe MaBoxImmo
    </p>
</div>
<?php endif; ?>
