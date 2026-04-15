<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

// Admin only
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $id_societe = !empty($_POST['id_societe']) ? (int)$_POST['id_societe'] : null;
        $id_agence = !empty($_POST['id_agence']) ? (int)$_POST['id_agence'] : null;
        $id_role = !empty($_POST['id_role']) ? (int)$_POST['id_role'] : 3;

        // Validation
        if (!$prenom || !$nom || !$email) {
            $error = 'Prénom, Nom et Email sont obligatoires';
        } elseif (strlen($password) < 6) {
            $error = 'Le mot de passe doit contenir au minimum 6 caractères';
        } else {
            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                $error = 'Cet email existe déjà';
            } else {
                // Insert user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (prenom, nom, email, telephone, username, mot_de_passe, id_societe, id_agence, id_role, actif, date_creation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$prenom, $nom, $email, $telephone, $username, $hashedPassword, $id_societe, $id_agence, $id_role]);
                $newUserId = $pdo->lastInsertId();

                // Create salary model
                $salStmt = $pdo->prepare("INSERT INTO salaires (id_user, mois_reference, salaire_modele) VALUES (?, '0000-00-00', 1)");
                $salStmt->execute([$newUserId]);

                $message = '✓ Utilisateur créé avec succès!';
            }
        }
    } catch (Exception $e) {
        $error = 'Erreur: ' . $e->getMessage();
    }
}

$societes = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
$roles = $pdo->query("SELECT id, nom FROM roles WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ── Layout variables ── */
$layout_title    = 'Ajouter Utilisateur';
$layout_module   = 'Ma Box RH';
$layout_sidebar  = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '
<button onclick="window.history.back()" class="ph-btn">← Retour</button>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.container{max-width:800px;margin:0 auto}
.back-link{color:var(--accent);text-decoration:none;font-size:12px;margin-bottom:16px;display:inline-block;cursor:pointer}
.card{background:#ffffff;border:1px solid var(--stroke);border-radius:12px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:16px}
.form-group{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.form-group.full{grid-template-columns:1fr}
label{font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:6px}
input,select,textarea{width:100%;padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-family:inherit;font-size:12px}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
.btn{padding:8px 14px;background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s}
.btn:hover{background:rgba(72,120,166,0.2)}
.btn-success{background:rgba(16,185,129,0.2);border-color:rgba(16,185,129,0.4);color:#4a6038}
.btn-success:hover{background:rgba(16,185,129,0.3)}
.btn-reset{padding:8px 14px;background:rgba(34,197,94,0.2);border:1px solid rgba(34,197,94,0.5);color:#4ade80;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s}
.btn-reset:hover{background:rgba(34,197,94,0.3);border-color:rgba(34,197,94,0.7)}
.message{padding:12px 16px;border-radius:8px;margin-bottom:16px}
.message.success{background:rgba(16,185,129,0.2);border:1px solid rgba(16,185,129,0.4);color:#4a6038}
.message.error{background:rgba(239,68,68,0.2);border:1px solid rgba(239,68,68,0.4);color:#fca5a5}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
EXTRAJS;

/* ── Page content ── */
ob_start();
?>
<div class="container">
    <?php if ($message): ?>
        <div class="message success"><?=$message?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?=$error?></div>
    <?php endif; ?>

    <form method="POST" style="display:contents">
        <div class="card">
            <div class="card-title">Informations Utilisateur</div>
            <div class="form-row">
                <div>
                    <label>Prénom *</label>
                    <input type="text" name="prenom" required>
                </div>
                <div>
                    <label>Nom *</label>
                    <input type="text" name="nom" required>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>Email *</label>
                    <input type="email" name="email" required>
                </div>
                <div>
                    <label>Téléphone</label>
                    <input type="tel" name="telephone">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>Identifiant Connexion</label>
                    <input type="text" name="username">
                </div>
                <div>
                    <label>Mot de Passe *</label>
                    <input type="password" name="password" required minlength="6">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>Société</label>
                    <select name="id_societe">
                        <option value="">Aucune</option>
                        <?php foreach($societes as $s): ?>
                            <option value="<?=$s['id']?>"><?=h($s['nom'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Agence</label>
                    <select name="id_agence">
                        <option value="">Aucune</option>
                        <?php foreach($agences as $a): ?>
                            <option value="<?=$a['id']?>"><?=h($a['nom_agence'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label>Rôle</label>
                <select name="id_role">
                    <?php foreach($roles as $r): ?>
                        <option value="<?=$r['id']?>" <?=($r['id']==3?'selected':'')?>><?=h($r['nom'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Modèle Salaire</div>
            <p style="color:var(--muted);font-size:12px;margin-bottom:12px">Un modèle de salaire vide sera créé automatiquement. Vous pourrez le compléter après la création de l'utilisateur.</p>
        </div>

        <div class="card" style="display:flex;gap:12px;padding-top:16px">
            <button type="submit" name="add_user" value="1" class="btn btn-success">✓ Créer l'utilisateur</button>
            <button type="reset" class="btn-reset">🔄 Réinitialiser</button>
            <button type="button" onclick="window.history.back()" class="btn">Annuler</button>
        </div>
    </form>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
