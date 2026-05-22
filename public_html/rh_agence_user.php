<?php
/**
 * rh_agence_user.php — Page « Mon agence » pour le rôle user.
 *
 * Affiche les coordonnées de l'agence rattachée au collaborateur.
 * - Champs d'inscription (nom, adresse, ville, SIRET…) → lecture seule
 * - Champs éditables (téléphone secondaire, email contact, horaires,
 *   commentaire libre) → formulaire POST
 * - Bouton « Je valide les coordonnées » → met à jour
 *   users.user_agence_confirmed_at et revient sur le dashboard avec flash.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$userId    = current_user_id();
$stmt      = $pdo->prepare("SELECT id_agence FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$idAgence  = (int)($stmt->fetchColumn() ?: 0);

if ($idAgence <= 0) {
    $_SESSION['flash_ok'] = 'Aucune agence n\'est rattachée à votre compte.';
    header('Location: rh_dashboard_user.php');
    exit;
}

$flashMsg = '';

// ─── Traitement POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'confirmer') {
        $stmt = $pdo->prepare("UPDATE users SET user_agence_confirmed_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['flash_ok'] = 'Les coordonnées agence ont été confirmées.';
        header('Location: rh_dashboard_user.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $telephone      = trim((string)($_POST['telephone']    ?? ''));
        $emailContact   = trim((string)($_POST['email_contact'] ?? ''));
        $adresse1       = trim((string)($_POST['adresse_1']    ?? ''));
        $adresse2       = trim((string)($_POST['adresse_2']    ?? ''));
        $codePostal     = trim((string)($_POST['code_postal']  ?? ''));
        $ville          = trim((string)($_POST['ville']        ?? ''));
        $horaires       = trim((string)($_POST['horaires']     ?? ''));
        $commentaires   = trim((string)($_POST['commentaires'] ?? ''));

        $stmt = $pdo->prepare("
            UPDATE agences SET
                telephone      = :tel,
                email_contact  = :em,
                adresse_1      = :a1,
                adresse_2      = :a2,
                code_postal    = :cp,
                ville          = :ville,
                horaires       = :hor,
                commentaires   = :com,
                date_modification = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':tel'   => $telephone,
            ':em'    => $emailContact,
            ':a1'    => $adresse1,
            ':a2'    => $adresse2,
            ':cp'    => $codePostal,
            ':ville' => $ville,
            ':hor'   => $horaires,
            ':com'   => $commentaires,
            ':id'    => $idAgence,
        ]);
        $flashMsg = 'Modifications enregistrées.';
    }
}

// ─── Lecture agence ────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM agences WHERE id = ? LIMIT 1");
$stmt->execute([$idAgence]);
$agence = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ─── Rendu layout MaBoxImmo ────────────────────────────────
$layout_title   = 'Mon agence';
$layout_module  = 'Mon espace · Collaborateur';
$layout_sidebar = 'rh_sidebar';

$layout_extra_css = <<<'CSS'
<style>
.ag-form-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 22px 26px;
    margin-bottom: 20px;
}
.ag-form-card h3 {
    font-size: 13px; font-weight: 700; color: #2f587d;
    letter-spacing: .04em; text-transform: uppercase;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(196,192,186,0.35);
}
.ag-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px 18px;
}
.ag-field { display: flex; flex-direction: column; gap: 4px; }
.ag-field label {
    font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase; color: #8a8680;
}
.ag-field .ag-ro {
    padding: 9px 12px;
    background: #f0f1f3;
    border-radius: 8px;
    box-shadow: inset 2px 2px 5px #d4d7de, inset -2px -2px 5px #fff;
    font-size: 12px; color: #4a4640;
    min-height: 34px;
}
.ag-field input, .ag-field textarea {
    padding: 9px 12px;
    background: #ffffff;
    box-shadow: inset 3px 3px 6px #d4d7de, inset -3px -3px 8px #fff;
    border: none; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
    outline: none;
}
.ag-field textarea { min-height: 80px; resize: vertical; }
.ag-field.full { grid-column: 1 / -1; }
.ag-actions {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-top: 8px;
}
.ag-btn {
    padding: 10px 18px; border-radius: 10px; border: none;
    background: #ffffff;
    box-shadow: 3px 3px 7px #d4d7de, -3px -3px 7px #fff;
    font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
    color: #6a6660; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
}
.ag-btn:hover { color: #2f587d; }
.ag-btn:active { box-shadow: inset 2px 2px 4px #d4d7de, inset -2px -2px 4px #fff; }
.ag-btn.primary { background: #4878a6; color: #fff; }
.ag-btn.success { background: #4a6038; color: #fff; }
.flash-ok {
    padding: 12px 16px; margin-bottom: 16px;
    background: #e8efe0; color: #4a6038;
    border-radius: 12px;
    box-shadow: inset 2px 2px 5px rgba(180,190,170,0.5), inset -2px -2px 5px #fff;
    font-size: 13px; font-weight: 600;
}
</style>
CSS;

ob_start();
?>

<?php if ($flashMsg): ?>
<div class="flash-ok">✓ <?= $e($flashMsg) ?></div>
<?php endif; ?>

<!-- Bloc lecture seule — informations d'inscription -->
<div class="ag-form-card">
    <h3>Informations d'inscription (lecture seule)</h3>
    <div class="ag-grid">
        <div class="ag-field">
            <label>Nom de l'agence</label>
            <div class="ag-ro"><?= $e($agence['nom_agence'] ?? '—') ?></div>
        </div>
        <div class="ag-field">
            <label>Code interne</label>
            <div class="ag-ro"><?= $e($agence['code_interne'] ?? $agence['code_agence'] ?? '—') ?></div>
        </div>
        <div class="ag-field">
            <label>Type</label>
            <div class="ag-ro"><?= $e($agence['type_agence'] ?? '—') ?></div>
        </div>
        <div class="ag-field">
            <label>SIRET</label>
            <div class="ag-ro"><?= $e($agence['siret'] ?? $agence['siren_siret'] ?? '—') ?></div>
        </div>
        <div class="ag-field">
            <label>RCS</label>
            <div class="ag-ro"><?= $e($agence['rcs'] ?? '—') ?></div>
        </div>
        <div class="ag-field">
            <label>TVA intracom.</label>
            <div class="ag-ro"><?= $e($agence['tva_intracom'] ?? '—') ?></div>
        </div>
    </div>
</div>

<!-- Bloc éditable -->
<form method="post" class="ag-form-card">
    <h3>Coordonnées modifiables</h3>
    <input type="hidden" name="action" value="enregistrer">
    <div class="ag-grid">
        <div class="ag-field">
            <label>Téléphone</label>
            <input type="text" name="telephone" value="<?= $e($agence['telephone'] ?? '') ?>" placeholder="01 23 45 67 89">
        </div>
        <div class="ag-field">
            <label>Email de contact</label>
            <input type="email" name="email_contact" value="<?= $e($agence['email_contact'] ?? $agence['email'] ?? '') ?>" placeholder="contact@agence.fr">
        </div>
        <div class="ag-field">
            <label>Adresse ligne 1</label>
            <input type="text" name="adresse_1" value="<?= $e($agence['adresse_1'] ?? '') ?>">
        </div>
        <div class="ag-field">
            <label>Adresse ligne 2</label>
            <input type="text" name="adresse_2" value="<?= $e($agence['adresse_2'] ?? '') ?>">
        </div>
        <div class="ag-field">
            <label>Code postal</label>
            <input type="text" name="code_postal" value="<?= $e($agence['code_postal'] ?? '') ?>">
        </div>
        <div class="ag-field">
            <label>Ville</label>
            <input type="text" name="ville" value="<?= $e($agence['ville'] ?? '') ?>">
        </div>
        <div class="ag-field full">
            <label>Horaires</label>
            <textarea name="horaires" placeholder="Lun-Ven : 9h-12h / 14h-18h"><?= $e($agence['horaires'] ?? '') ?></textarea>
        </div>
        <div class="ag-field full">
            <label>Commentaire libre</label>
            <textarea name="commentaires"><?= $e($agence['commentaires'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="ag-actions" style="margin-top:16px">
        <button type="submit" class="ag-btn primary">💾 Enregistrer les modifications</button>
    </div>
</form>

<!-- Bloc validation -->
<form method="post" class="ag-form-card">
    <h3>Validation des coordonnées</h3>
    <p style="font-size:12px;color:#6a6660;margin-bottom:14px;line-height:1.55">
        En validant, vous confirmez avoir vérifié les informations ci-dessus.
        Cette action marque la tâche « Vérifier les coordonnées » comme complétée sur votre tableau de bord.
    </p>
    <input type="hidden" name="action" value="confirmer">
    <div class="ag-actions">
        <button type="submit" class="ag-btn success">✓ Je valide les coordonnées</button>
        <a href="rh_dashboard_user.php" class="ag-btn">← Retour au tableau de bord</a>
    </div>
</form>

<?php
$layout_content = ob_get_clean();
require __DIR__ . '/inc/layout_maboximmo.php';
