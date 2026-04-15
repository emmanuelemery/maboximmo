<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_admin();

$appLayout = true;
$pageTitle = 'Créer un utilisateur';
$bodyClass = ''; 
$robots = 'noindex, nofollow';

$errors = [];
$success = '';

// Chargement listes
$roles = $pdo->query("SELECT id, nom FROM roles WHERE actif = 1 ORDER BY niveau_acces ASC, nom ASC")->fetchAll();
$societes = $pdo->query("SELECT id, nom FROM societes ORDER BY nom ASC")->fetchAll();
$agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence ASC")->fetchAll();

if (is_post()) {
    verify_csrf('admin_create_user');

    $nom = trim((string)post('nom'));
    $prenom = trim((string)post('prenom'));
    $email = trim((string)post('email'));
    $username = trim((string)post('username'));
    $id_role = (int)post('id_role');
    $id_societe = post('id_societe') !== '' ? (int)post('id_societe') : null;
    $id_agence = post('id_agence') !== '' ? (int)post('id_agence') : null;
    $telephone = trim((string)post('telephone'));
    $actif = post('actif') === '1' ? 1 : 0;
    $password = (string)post('password');

    if ($nom === '') $errors[] = "Le nom est obligatoire.";
    if ($email === '') $errors[] = "L'e-mail est obligatoire.";
    if ($id_role <= 0) $errors[] = "Le rôle est obligatoire.";
    if ($password === '') $errors[] = "Le mot de passe est obligatoire.";

    // unicité email
    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $st->execute([':email' => $email]);
        if ($st->fetchColumn()) {
            $errors[] = "Cet e-mail est déjà utilisé.";
        }
    }
    // unicité username si fourni
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
            (:id_role, :id_societe, :id_agence, :nom, :prenom, :username, :email, :telephone, :mot_de_passe, :actif, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_role' => $id_role,
            ':id_societe' => $id_societe,
            ':id_agence' => $id_agence,
            ':nom' => $nom,
            ':prenom' => $prenom !== '' ? $prenom : null,
            ':username' => $username !== '' ? $username : null,
            ':email' => $email,
            ':telephone' => $telephone !== '' ? $telephone : null,
            ':mot_de_passe' => $hash,
            ':actif' => $actif,
        ]);

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
            </div>
            <div class="topbar-right">
                <a class="btn-outline" href="<?= htmlspecialchars(app_url('/dev_organisation.php')) ?>">Retour</a>
            </div>
        </header>

        <div class="content-wrap">
            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?= h($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <form method="post">
                    <?= csrf_field('admin_create_user') ?>

                    <div class="form-grid">
                        <div>
                            <label>Nom *</label>
                            <input type="text" name="nom" required value="<?= h(post('nom')) ?>">
                        </div>
                        <div>
                            <label>Prénom</label>
                            <input type="text" name="prenom" value="<?= h(post('prenom')) ?>">
                        </div>
                        <div>
                            <label>E-mail *</label>
                            <input type="email" name="email" required value="<?= h(post('email')) ?>">
                        </div>
                        <div>
                            <label>Identifiant (optionnel)</label>
                            <input type="text" name="username" value="<?= h(post('username')) ?>">
                        </div>
                        <div>
                            <label>Téléphone</label>
                            <input type="text" name="telephone" value="<?= h(post('telephone')) ?>">
                        </div>
                        <div>
                            <label>Mot de passe *</label>
                            <input type="password" name="password" required>
                            <small>Le mot de passe sera stocké en hash (password_hash).</small>
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
                        <div>
                            <label>Société</label>
                            <select name="id_societe">
                                <option value="">--</option>
                                <?php foreach ($societes as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= ((string)post('id_societe') === (string)$s['id']) ? 'selected' : '' ?>>
                                        <?= h($s['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Agence</label>
                            <select name="id_agence">
                                <option value="">--</option>
                                <?php foreach ($agences as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>" <?= ((string)post('id_agence') === (string)$a['id']) ? 'selected' : '' ?>>
                                        <?= h($a['nom_agence']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Actif</label>
                            <select name="actif">
                                <option value="1" <?= (post('actif') !== '0') ? 'selected' : '' ?>>Oui</option>
                                <option value="0" <?= (post('actif') === '0') ? 'selected' : '' ?>>Non</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Créer l’utilisateur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>

<style>
.content-wrap{padding:24px}
.card{background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(16,24,40,.08);padding:20px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.form-grid label{display:block;font-weight:600;margin-bottom:6px}
.form-grid input,.form-grid select{width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:10px}
.form-actions{margin-top:16px}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:12px}
.alert-success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}
.alert-error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}
</style>

