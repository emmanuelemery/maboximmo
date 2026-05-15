<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_manager_or_admin();

$appLayout = true;
$pageTitle = 'Créer un utilisateur';
$bodyClass = ''; 
$robots = 'noindex, nofollow';

$errors = [];
$success = '';

$societeId = current_societe_id();
$agenceId = current_agence_id();

if ($societeId === null || $agenceId === null) {
    $errors[] = 'Votre compte n’est pas rattaché à une agence.';
}

$roles = $pdo->query("
    SELECT id, nom
    FROM roles
    WHERE actif = 1 AND id IN (2,3,4,5)
    ORDER BY niveau_acces DESC, nom ASC
")->fetchAll();

if (is_post() && !$errors) {
    verify_csrf('agence_create_user');

    $nom = trim((string)post('nom'));
    $prenom = trim((string)post('prenom'));
    $email = trim((string)post('email'));
    $username = trim((string)post('username'));
    $id_role = (int)post('id_role');
    $telephone = trim((string)post('telephone'));
    $password = (string)post('password');

    if ($nom === '') $errors[] = "Le nom est obligatoire.";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'e-mail est obligatoire.";
    if ($id_role <= 0) $errors[] = "Le rôle est obligatoire.";
    if ($password === '') $errors[] = "Le mot de passe est obligatoire.";

    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $st->execute([':email' => $email]);
        if ($st->fetchColumn()) {
            $errors[] = "Cet e-mail est déjà utilisé.";
        }
    }

    if ($username !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $st->execute([':u' => $username]);
        if ($st->fetchColumn()) {
            $errors[] = "Cet identifiant est déjà utilisé.";
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users
            (id_role, id_societe, id_agence, nom, prenom, username, email, telephone, mot_de_passe, actif, date_creation, date_modification)
            VALUES
            (:id_role, :id_societe, :id_agence, :nom, :prenom, :username, :email, :telephone, :mot_de_passe, 1, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_role' => $id_role,
            ':id_societe' => $societeId,
            ':id_agence' => $agenceId,
            ':nom' => $nom,
            ':prenom' => $prenom !== '' ? $prenom : null,
            ':username' => $username !== '' ? $username : null,
            ':email' => $email,
            ':telephone' => $telephone !== '' ? $telephone : null,
            ':mot_de_passe' => $hash,
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        try {
            if (file_exists(__DIR__ . '/inc/ged_glossary.php')) {
                require_once __DIR__ . '/inc/ged_glossary.php';
                $label = trim(($prenom ?: '') . ' ' . $nom) ?: ('User#' . $newUserId);
                ged_glossary_sync_entity('user', $newUserId, $label, 'users', [
                    'user_id' => $newUserId,
                ], $pdo);
            }
        } catch (Throwable) {}

        $success = "Utilisateur créé avec succès.";
    }
}

include __DIR__ . '/inc/header.php';
?>

<div class="app-shell">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>

    <div class="main-panel">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Créer un utilisateur</h1>
                <p>Ajoutez un collaborateur à votre agence.</p>
            </div>
            <div class="topbar-right">
                <a class="btn" href="<?= h(app_url('/agence_portail.php')) ?>">Retour</a>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if ($success): ?>
                <div class="message success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="message error"><?= h(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <div class="card">
                <form method="post">
                    <?= csrf_field('agence_create_user') ?>

                    <div class="form-grid">
                        <div>
                            <label>Nom *</label>
                            <input type="text" name="nom" required value="<?= h((string)post('nom')) ?>">
                        </div>
                        <div>
                            <label>Prénom</label>
                            <input type="text" name="prenom" value="<?= h((string)post('prenom')) ?>">
                        </div>
                        <div>
                            <label>E-mail *</label>
                            <input type="email" name="email" required value="<?= h((string)post('email')) ?>">
                        </div>
                        <div>
                            <label>Identifiant (optionnel)</label>
                            <input type="text" name="username" value="<?= h((string)post('username')) ?>">
                        </div>
                        <div>
                            <label>Téléphone</label>
                            <input type="text" name="telephone" value="<?= h((string)post('telephone')) ?>">
                        </div>
                        <div>
                            <label>Mot de passe *</label>
                            <input type="password" name="password" required>
                        </div>
                        <div>
                            <label>Rôle *</label>
                            <select name="id_role" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= ((string)post('id_role') === (string)$r['id']) ? 'selected' : '' ?>>
                                        <?= h($r['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Créer l’utilisateur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>

<style>
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.form-grid label{display:block;font-weight:600;margin-bottom:6px}
.form-grid input,.form-grid select{width:100%;padding:10px;border:1px solid #ffffff;border-radius:10px}
.form-actions{margin-top:16px}
@media (max-width: 760px){
  .form-grid{grid-template-columns:1fr}
}
</style>

