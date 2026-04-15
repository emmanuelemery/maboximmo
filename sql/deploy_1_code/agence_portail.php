<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Espace Agence';
$bodyClass = ''; 
$robots = 'noindex, nofollow';

$errors = [];
$success = '';

$societeId = current_societe_id();
$agenceId = current_agence_id();

if ($societeId === null || $agenceId === null) {
    $errors[] = 'Votre compte n’est pas rattaché à une société et une agence.';
}

$societe = null;
$agence = null;
$registres = null;
$registresError = '';

if (!$errors) {
    $stmtSociete = $pdo->prepare("SELECT * FROM societes WHERE id = :id LIMIT 1");
    $stmtSociete->execute([':id' => $societeId]);
    $societe = $stmtSociete->fetch(PDO::FETCH_ASSOC);

    $stmtAgence = $pdo->prepare("SELECT * FROM agences WHERE id = :id LIMIT 1");
    $stmtAgence->execute([':id' => $agenceId]);
    $agence = $stmtAgence->fetch(PDO::FETCH_ASSOC);
}

if (is_post() && !$errors) {
    $action = trim((string)post('action', ''));
    if ($action === 'theme_switch') {
        verify_csrf('theme_switch');
        $theme = trim((string)post('theme', 'light'));
        if (!in_array($theme, ['light', 'dark'], true)) {
            $theme = 'light';
        }
        $_SESSION['ui_theme'] = $theme;
        redirect(app_url('/dashboard.php'));
    }

    if ($action === 'registres_request') {
        verify_csrf('registres_request');
        $modePaiement = trim((string)post('mode_paiement', ''));
        $montantRaw = trim((string)post('montant', ''));
        $montant = $montantRaw !== '' ? (float)str_replace(',', '.', $montantRaw) : null;

        if (!in_array($modePaiement, ['virement', 'cb'], true)) {
            $errors[] = 'Veuillez choisir un mode de paiement valide.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO registres_acces (id_societe, statut, mode_paiement, montant, devise, demande_le)
                    VALUES (:id_societe, 'pending', :mode_paiement, :montant, 'EUR', NOW())
                    ON DUPLICATE KEY UPDATE
                        statut = 'pending',
                        mode_paiement = VALUES(mode_paiement),
                        montant = VALUES(montant),
                        devise = 'EUR',
                        demande_le = NOW(),
                        valide_le = NULL
                ");
                $stmt->execute([
                    ':id_societe' => $societeId,
                    ':mode_paiement' => $modePaiement,
                    ':montant' => $montant,
                ]);
                $success = 'Votre demande d’accès REGISTRE a bien été enregistrée.';
            } catch (Throwable $e) {
                $errors[] = 'Impossible d’enregistrer la demande REGISTRE (table non initialisée ?).';
            }
        }
    }
}

if (!$errors) {
    try {
        $stmtReg = $pdo->prepare("SELECT * FROM registres_acces WHERE id_societe = :id LIMIT 1");
        $stmtReg->execute([':id' => $societeId]);
        $registres = $stmtReg->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $registresError = 'Module REGISTRE non initialisé. Appliquez le patch SQL.';
    }
}

$statutRegistres = $registres['statut'] ?? 'inactive';
$currentTheme = $_SESSION['ui_theme'] ?? 'light';

include __DIR__ . '/inc/header.php';
?>

<div class="app-shell">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>

    <div class="main-panel">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Espace agence</h1>
                <p>Paramètres clés, accès Registre et actions rapides.</p>
            </div>
            <div class="topbar-right">
                <a class="btn btn-primary" href="<?= h(app_url('/bien_ajouter.php')) ?>">Créer une annonce</a>
            </div>
        </header>

        <main class="content-wrapper">
            <?php if ($success !== ''): ?>
                <div class="message success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="message error"><?= h(implode(' ', $errors)) ?></div>
            <?php endif; ?>
            <?php if ($registresError !== ''): ?>
                <div class="message error"><?= h($registresError) ?></div>
            <?php endif; ?>

            <section class="card">
                <h3>Vos informations</h3>
                <div class="info-grid">
                    <div class="info-block">
                        <span>Société</span>
                        <strong><?= h((string)($societe['nom'] ?? '')) ?></strong>
                        <div><?= h((string)($societe['email'] ?? '')) ?></div>
                        <div><?= h((string)($societe['telephone'] ?? '')) ?></div>
                    </div>
                    <div class="info-block">
                        <span>Agence</span>
                        <strong><?= h((string)($agence['nom_agence'] ?? '')) ?></strong>
                        <div><?= h((string)($agence['email'] ?? '')) ?></div>
                        <div><?= h((string)($agence['telephone'] ?? '')) ?></div>
                    </div>
                    <div class="info-block">
                        <span>Actions</span>
                        <div class="action-row">
                            <a class="btn btn-primary" href="<?= h(app_url('/agence_user_create.php')) ?>">Créer un utilisateur</a>
                            <a class="btn" href="<?= h(app_url('/bien_ajouter.php')) ?>">Créer une annonce</a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card" style="margin-top:18px;">
                <h3>Variantes de couleur</h3>
                <p class="muted">Aperçu des boutons pastel (bleu pétrol, vert pétrol, beige moyen, doré).</p>
                <div class="action-row">
                    <button type="button" class="btn btn-variant-blue">Bleu pétrol</button>
                    <button type="button" class="btn btn-variant-green">Vert pétrol</button>
                    <button type="button" class="btn btn-variant-beige">Beige moyen</button>
                    <button type="button" class="btn btn-variant-gold">Doré</button>
                </div>
            </section>

            <section class="card" style="margin-top:18px;">
                <h3>Accès REGISTRE (payant)</h3>
                <p class="muted">Choisissez un mode de paiement pour activer l’accès. Un admin validera ensuite votre accès.</p>

                <?php if ($statutRegistres === 'active'): ?>
                    <div class="status-badge ok">Actif</div>
                    <form method="post" action="<?= h(app_url('/sso_registres.php')) ?>">
                        <?= csrf_field('sso_registres') ?>
                        <input type="hidden" name="target" value="/dashboard.php">
                        <button type="submit" class="btn btn-primary">Accéder au Registre</button>
                    </form>
                <?php elseif ($statutRegistres === 'pending'): ?>
                    <div class="status-badge pending">Demande en cours</div>
                    <p class="muted">Nous avons bien reçu votre demande. Vous serez notifié dès validation.</p>
                <?php elseif ($statutRegistres === 'refused'): ?>
                    <div class="status-badge refused">Refusé</div>
                    <p class="muted">Contactez le support pour réactiver votre demande.</p>
                <?php else: ?>
                    <div class="status-badge off">Inactif</div>
                    <form method="post" class="reg-form">
                        <?= csrf_field('registres_request') ?>
                        <input type="hidden" name="action" value="registres_request">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Mode de paiement *</label>
                                <select name="mode_paiement" required>
                                    <option value="">-- Choisir --</option>
                                    <option value="virement">Virement</option>
                                    <option value="cb">Carte bancaire</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Montant (optionnel)</label>
                                <input type="text" name="montant" placeholder="Ex: 199.00">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Demander l’accès REGISTRE</button>
                        <div class="hint">
                            Pour un virement, utilisez vos coordonnées bancaires habituelles. Pour le paiement CB, un conseiller vous contactera.
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>

<style>
.info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.info-block{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;display:grid;gap:6px}
.info-block span{font-size:12px;text-transform:uppercase;color:var(--text-soft);letter-spacing:.06em}
.info-block strong{font-size:18px}
.action-row{display:flex;gap:10px;flex-wrap:wrap}
.muted{color:var(--text-soft);margin:8px 0 16px}
.status-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;font-weight:700;font-size:12px;margin-bottom:12px}
.status-badge.ok{background:rgba(124,245,214,0.18);color:#0b2b22;border:1px solid rgba(124,245,214,0.35)}
.status-badge.pending{background:rgba(255,212,121,0.2);color:#5a3f00;border:1px solid rgba(255,212,121,0.35)}
.status-badge.refused{background:rgba(255,122,122,0.18);color:#5f1c1c;border:1px solid rgba(255,122,122,0.35)}
.status-badge.off{background:rgba(15,23,42,0.08);color:var(--text-soft);border:1px solid var(--border)}
.reg-form{display:grid;gap:12px}
.reg-form .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.reg-form label{display:block;font-weight:600;margin-bottom:6px}
.reg-form input,.reg-form select{width:100%;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text)}
.hint{font-size:12px;color:var(--text-soft)}
.theme-switch{display:flex;align-items:center;gap:8px;margin-right:12px;background:var(--surface);border:1px solid var(--border);padding:6px 10px;border-radius:999px}
.theme-switch label{font-size:12px;color:var(--text-soft);font-weight:600}
.theme-switch select{border:none;background:transparent;color:var(--text);font-weight:600;outline:none}
@media (max-width: 900px){
  .info-grid{grid-template-columns:1fr}
  .reg-form .form-grid{grid-template-columns:1fr}
  .topbar-right{flex-direction:column;align-items:flex-start;gap:10px}
}
</style>







