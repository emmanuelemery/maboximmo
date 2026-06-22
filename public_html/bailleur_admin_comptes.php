<?php
/**
 * bailleur_admin_comptes.php — Gestion des comptes bailleurs
 * Création, modification, assignation propriétaires + modules
 * Accès : super admin uniquement
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

if (!is_super_admin()) { http_response_code(403); exit('Super admin uniquement.'); }

$pdo = $GLOBALS['pdo'];
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ── Modules disponibles ──────────────────────────────────
const MODULES = [
    'patrimoine' => ['label' => 'Patrimoine actif',    'icon' => '🏛️', 'desc' => 'Vue des immeubles et locataires'],
    'ged'        => ['label' => 'GED Documents',       'icon' => '📁', 'desc' => 'Accès aux CRGs et documents'],
    'revision'   => ['label' => 'Révision des loyers', 'icon' => '📈', 'desc' => 'Calcul IRL et courriers'],
    'bail'       => ['label' => 'Bail 360°',           'icon' => '📋', 'desc' => 'Fiche complète d\'un bail'],
];

// ── Rôle bailleur (id=9 PROPRIO) ────────────────────────
$roleBailleur = $pdo->query("SELECT id FROM roles WHERE code='PROPRIO' LIMIT 1")->fetchColumn();
if (!$roleBailleur) {
    // Fallback: chercher le rôle avec service bailleur
    $roleBailleur = $pdo->query("SELECT id FROM roles WHERE niveau_acces=3 ORDER BY id LIMIT 1")->fetchColumn();
}

// ── Propriétaires disponibles ────────────────────────────
$allProps = $pdo->query("
    SELECT p.id, COALESCE(p.societe, CONCAT(p.prenom,' ',p.nom)) AS label
    FROM proprietaires p
    WHERE EXISTS (SELECT 1 FROM crg_trimestres ct WHERE ct.id_proprietaire=p.id AND ct.parse_statut='ok')
    ORDER BY label
")->fetchAll(\PDO::FETCH_ASSOC);

$msg = ''; $msgType = '';

// ════════════════════════════════════════════════════════
// ACTIONS POST
// ════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Créer ou modifier un compte ──────────────────────
    if (in_array($action, ['create','update'])) {
        $uid        = (int)($_POST['user_id'] ?? 0);
        $nom        = trim($_POST['nom'] ?? '');
        $prenom     = trim($_POST['prenom'] ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $password   = trim($_POST['password'] ?? '');
        $actif      = isset($_POST['actif']) ? 1 : 0;
        $props      = array_map('intval', (array)($_POST['props'] ?? []));
        $modules    = array_intersect(array_keys(MODULES), (array)($_POST['modules'] ?? []));

        if (!$nom || !$email) {
            $msg = 'Nom et email obligatoires.'; $msgType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Email invalide.'; $msgType = 'error';
        } else {
            if ($action === 'create') {
                // Vérifier email unique
                $exists = $pdo->prepare("SELECT id FROM users WHERE email=?");
                $exists->execute([$email]);
                if ($exists->fetchColumn()) {
                    $msg = "L'email {$email} existe déjà."; $msgType = 'error';
                } else {
                    $hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
                    $pdo->prepare("
                        INSERT INTO users (id_role, nom, prenom, email, mot_de_passe, actif, force_password_change, super_admin)
                        VALUES (?, ?, ?, ?, ?, ?, 1, 0)
                    ")->execute([$roleBailleur, $nom, $prenom, $email, $hash, $actif]);
                    $uid = (int)$pdo->lastInsertId();
                    $msg = "Compte <strong>$prenom $nom</strong> créé (id=$uid)."; $msgType = 'success';
                }
            } else {
                // Modifier
                $sets = ["nom=?","prenom=?","email=?","actif=?"];
                $vals = [$nom, $prenom, $email, $actif];
                if ($password) { $sets[] = "mot_de_passe=?"; $vals[] = password_hash($password, PASSWORD_DEFAULT); }
                $vals[] = $uid;
                $pdo->prepare("UPDATE users SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
                $msg = "Compte mis à jour."; $msgType = 'success';
            }

            if ($uid && $msgType !== 'error') {
                // Synchroniser user_proprietaires
                $pdo->prepare("DELETE FROM user_proprietaires WHERE id_user=?")->execute([$uid]);
                foreach ($props as $i => $pid) {
                    if ($pid > 0) {
                        // Récupérer le label du proprio
                        $lbl = '';
                        foreach ($allProps as $ap) { if ((int)$ap['id'] === $pid) { $lbl = $ap['label']; break; } }
                        $pdo->prepare("INSERT INTO user_proprietaires (id_user, id_proprietaire, label, ordre) VALUES (?,?,?,?)")
                            ->execute([$uid, $pid, $lbl, $i]);
                    }
                }
                // Synchroniser user_bailleur_modules
                $pdo->prepare("DELETE FROM user_bailleur_modules WHERE id_user=?")->execute([$uid]);
                foreach ($modules as $mod) {
                    $pdo->prepare("INSERT IGNORE INTO user_bailleur_modules (id_user, module_code) VALUES (?,?)")
                        ->execute([$uid, $mod]);
                }
            }
        }
    }

    // ── Supprimer / désactiver ────────────────────────────
    if ($action === 'toggle_actif') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $cur = (int)$pdo->prepare("SELECT actif FROM users WHERE id=?")->execute([$uid]) ? $pdo->query("SELECT actif FROM users WHERE id=$uid")->fetchColumn() : 1;
        $new = $cur ? 0 : 1;
        $pdo->prepare("UPDATE users SET actif=? WHERE id=?")->execute([$new, $uid]);
        $msg = $new ? "Compte activé." : "Compte désactivé."; $msgType = 'success';
    }
}

// ── Liste des comptes bailleurs ──────────────────────────
$bailleurs = $pdo->query("
    SELECT u.id, u.nom, u.prenom, u.email, u.actif, u.last_login_at, r.nom AS role_nom,
           (SELECT COUNT(*) FROM user_proprietaires WHERE id_user=u.id) AS nb_props,
           (SELECT COUNT(*) FROM user_bailleur_modules WHERE id_user=u.id) AS nb_modules
    FROM users u
    JOIN roles r ON r.id=u.id_role
    WHERE r.niveau_acces <= 10 AND u.super_admin=0
      AND (r.code IN ('PROPRIO','PROPRIO_VIP','bailleur')
           OR EXISTS (SELECT 1 FROM user_proprietaires WHERE id_user=u.id))
    ORDER BY u.actif DESC, u.nom
")->fetchAll(\PDO::FETCH_ASSOC);

// ── Compte à éditer (GET ?edit=id) ──────────────────────
$editUser = null; $editProps = []; $editModules = [];
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editUser = $pdo->prepare("SELECT * FROM users WHERE id=?")->execute([$eid])
        ? $pdo->query("SELECT * FROM users WHERE id=$eid")->fetch(\PDO::FETCH_ASSOC) : null;
    if ($editUser) {
        $stE = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
        $stE->execute([$eid]);
        $editProps = $stE->fetchAll(\PDO::FETCH_COLUMN);
        $stM = $pdo->prepare("SELECT module_code FROM user_bailleur_modules WHERE id_user=?");
        $stM->execute([$eid]);
        $editModules = $stM->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($editModules)) $editModules = ['patrimoine','ged']; // défaut
    }
}

// ── Layout ───────────────────────────────────────────────
$pageTitle    = 'Comptes Bailleurs';
$pageSubtitle = 'Ma Box Bailleur · Administration';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_admin_comptes';

$extraCss = <<<CSS
<style>
.page-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
.page-header h2{margin:0;color:#1a237e;font-size:1.15em;}

/* TABLE COMPTES */
table.comptes{border-collapse:collapse;width:100%;background:white;border-radius:10px;
              box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
table.comptes thead tr{background:#1a237e;color:white;}
table.comptes th{padding:10px 14px;text-align:left;font-size:.82em;white-space:nowrap;}
table.comptes td{padding:9px 14px;border-bottom:1px solid #f0f0f0;font-size:.85em;vertical-align:middle;}
table.comptes tr:last-child td{border-bottom:none;}
table.comptes tr:hover td{background:#fafbff;}
.badge-actif{background:#c8e6c9;color:#1b5e20;padding:2px 8px;border-radius:10px;font-size:.78em;font-weight:bold;}
.badge-inactif{background:#ffcdd2;color:#b71c1c;padding:2px 8px;border-radius:10px;font-size:.78em;font-weight:bold;}
.btn-sm{border:none;border-radius:6px;padding:4px 10px;font-size:.78em;cursor:pointer;font-weight:bold;text-decoration:none;display:inline-block;}
.btn-edit{background:#e8eaf6;color:#1a237e;} .btn-edit:hover{background:#c5cae9;}
.btn-toggle{background:#fff3e0;color:#e65100;} .btn-toggle:hover{background:#ffe0b2;}

/* FORMULAIRE */
.form-card{background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);
           padding:24px 28px;margin-bottom:20px;}
.form-card h3{margin:0 0 18px;color:#1a237e;font-size:.95em;padding-bottom:10px;border-bottom:1px solid #eee;}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:.8em;font-weight:600;color:#555;}
.form-group input,.form-group select{border:1px solid #ddd;border-radius:6px;padding:8px 10px;
  font-size:.88em;width:100%;}
.form-group input:focus,.form-group select:focus{outline:2px solid #3f51b5;border-color:#3f51b5;}
.form-full{grid-column:1/-1;}

/* MODULES CHECKBOXES */
.modules-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:4px;}
.module-check{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid #e8eaf6;
              border-radius:8px;cursor:pointer;transition:background .1s;}
.module-check:has(input:checked){background:#e8eaf6;border-color:#3f51b5;}
.module-check input{margin-top:2px;flex-shrink:0;}
.module-check .mc-icon{font-size:1.1em;}
.module-check .mc-info{display:flex;flex-direction:column;}
.module-check .mc-label{font-size:.83em;font-weight:600;color:#1a237e;}
.module-check .mc-desc{font-size:.74em;color:#888;}

/* PROPS CHECKBOXES */
.props-grid{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;}
.prop-check{display:flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid #e8eaf6;
            border-radius:20px;cursor:pointer;font-size:.8em;white-space:nowrap;}
.prop-check:has(input:checked){background:#e8eaf6;border-color:#3f51b5;color:#1a237e;font-weight:600;}

/* BOUTONS */
.btn-primary{background:#1a237e;color:white;border:none;border-radius:8px;padding:9px 20px;
             font-size:.88em;font-weight:bold;cursor:pointer;}
.btn-primary:hover{background:#283593;}
.btn-secondary{background:#e0e0e0;color:#333;border:none;border-radius:8px;padding:9px 18px;
               font-size:.88em;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-block;}
.btn-new{background:#2e7d32;color:white;border:none;border-radius:8px;padding:8px 16px;
         font-size:.84em;font-weight:bold;cursor:pointer;text-decoration:none;}
.btn-new:hover{background:#1b5e20;}

/* MSG */
.alert-success{background:#e8f5e9;border-left:4px solid #2e7d32;color:#1b5e20;padding:10px 14px;
               border-radius:6px;margin-bottom:16px;font-size:.88em;}
.alert-error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;padding:10px 14px;
             border-radius:6px;margin-bottom:16px;font-size:.88em;}
</style>
CSS;

require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<div style="padding:20px 28px;max-width:1100px;margin:0 auto;">

<div class="page-header">
  <h2>👥 Gestion des comptes bailleurs</h2>
  <a href="bailleur_admin_comptes.php?new=1" class="btn-new" style="margin-left:auto">+ Nouveau compte</a>
</div>

<?php if ($msg): ?>
<div class="alert-<?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- ══ FORMULAIRE création/édition ═══════════════════════ -->
<?php if (isset($_GET['new']) || $editUser): ?>
<div class="form-card">
  <h3><?= $editUser ? '✏️ Modifier le compte' : '➕ Nouveau compte bailleur' ?></h3>
  <form method="POST" action="bailleur_admin_comptes.php">
    <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
    <?php if ($editUser): ?>
    <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
    <?php endif; ?>

    <div class="form-grid">
      <div class="form-group">
        <label>Prénom</label>
        <input type="text" name="prenom" value="<?= e($editUser['prenom'] ?? '') ?>" placeholder="Thomas">
      </div>
      <div class="form-group">
        <label>Nom *</label>
        <input type="text" name="nom" value="<?= e($editUser['nom'] ?? '') ?>" placeholder="SABY" required>
      </div>
      <div class="form-group">
        <label>Email * (identifiant de connexion)</label>
        <input type="email" name="email" value="<?= e($editUser['email'] ?? '') ?>"
               placeholder="t.saby@groupe-sir.fr" required>
      </div>
      <div class="form-group">
        <label>Mot de passe <?= $editUser ? '(laisser vide = inchangé)' : '*' ?></label>
        <input type="password" name="password" placeholder="<?= $editUser ? 'Nouveau mot de passe…' : 'Mot de passe initial' ?>">
      </div>
      <div class="form-group" style="flex-direction:row;align-items:center;gap:8px;">
        <input type="checkbox" name="actif" id="chk-actif" value="1"
               <?= (!$editUser || $editUser['actif']) ? 'checked' : '' ?>>
        <label for="chk-actif" style="margin:0;cursor:pointer;">Compte actif (peut se connecter)</label>
      </div>
    </div>

    <!-- Modules autorisés -->
    <div class="form-group form-full" style="margin-top:16px;">
      <label>🔐 Modules autorisés</label>
      <div class="modules-grid">
        <?php foreach (MODULES as $code => $mod): ?>
        <label class="module-check">
          <input type="checkbox" name="modules[]" value="<?= $code ?>"
                 <?= in_array($code, $editModules) || (!$editUser && in_array($code, ['patrimoine','ged'])) ? 'checked' : '' ?>>
          <span class="mc-icon"><?= $mod['icon'] ?></span>
          <span class="mc-info">
            <span class="mc-label"><?= $mod['label'] ?></span>
            <span class="mc-desc"><?= $mod['desc'] ?></span>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Propriétaires assignés -->
    <div class="form-group form-full" style="margin-top:16px;">
      <label>🏢 Propriétaires / SCIs accessibles
        <span style="font-weight:normal;color:#888;margin-left:6px;">(cochez tous les biens visibles par ce bailleur)</span>
      </label>
      <div class="props-grid">
        <?php foreach ($allProps as $p): ?>
        <label class="prop-check">
          <input type="checkbox" name="props[]" value="<?= (int)$p['id'] ?>"
                 <?= in_array((int)$p['id'], $editProps) ? 'checked' : '' ?>>
          <?= e($p['label']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:20px;">
      <button type="submit" class="btn-primary">
        <?= $editUser ? '💾 Enregistrer les modifications' : '✅ Créer le compte' ?>
      </button>
      <a href="bailleur_admin_comptes.php" class="btn-secondary">Annuler</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ══ LISTE DES COMPTES ══════════════════════════════════ -->
<table class="comptes">
  <thead><tr>
    <th>BAILLEUR</th><th>EMAIL</th><th>STATUT</th>
    <th>PROPRIÉTAIRES</th><th>MODULES</th>
    <th>DERNIÈRE CONNEXION</th><th>ACTIONS</th>
  </tr></thead>
  <tbody>
  <?php if (empty($bailleurs)): ?>
  <tr><td colspan="7" style="text-align:center;padding:30px;color:#aaa;">
    Aucun compte bailleur — <a href="bailleur_admin_comptes.php?new=1">Créer le premier</a>
  </td></tr>
  <?php else: ?>
  <?php foreach ($bailleurs as $b):
      // Récupérer les props et modules
      $stP = $pdo->prepare("
          SELECT COALESCE(p.societe, CONCAT(p.prenom,' ',p.nom)) AS label
          FROM user_proprietaires up JOIN proprietaires p ON p.id=up.id_proprietaire
          WHERE up.id_user=? ORDER BY up.ordre LIMIT 5
      ");
      $stP->execute([$b['id']]);
      $bProps = $stP->fetchAll(\PDO::FETCH_COLUMN);

      $stM = $pdo->prepare("SELECT module_code FROM user_bailleur_modules WHERE id_user=?");
      $stM->execute([$b['id']]);
      $bMods = $stM->fetchAll(\PDO::FETCH_COLUMN);
  ?>
  <tr>
    <td>
      <strong><?= e(trim($b['prenom'].' '.$b['nom'])) ?></strong>
      <div style="font-size:.76em;color:#999"><?= e($b['role_nom']) ?></div>
    </td>
    <td style="color:#555"><?= e($b['email']) ?></td>
    <td>
      <?php if ($b['actif']): ?>
        <span class="badge-actif">✓ Actif</span>
      <?php else: ?>
        <span class="badge-inactif">✗ Inactif</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if (empty($bProps)): ?>
        <span style="color:#bbb;font-size:.8em">Aucun</span>
      <?php else: ?>
        <?php foreach ($bProps as $lbl): ?>
          <span style="background:#e8eaf6;color:#1a237e;padding:1px 7px;border-radius:10px;
                       font-size:.76em;display:inline-block;margin:1px;"><?= e($lbl) ?></span>
        <?php endforeach; ?>
        <?php if ((int)$b['nb_props'] > 5): ?>
          <span style="color:#888;font-size:.78em">+<?= (int)$b['nb_props']-5 ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </td>
    <td>
      <?php if (empty($bMods)): ?>
        <span style="color:#e65100;font-size:.78em">⚠ Aucun module</span>
      <?php else: ?>
        <?php foreach ($bMods as $mc): ?>
          <span title="<?= e(MODULES[$mc]['label'] ?? $mc) ?>" style="font-size:1em;">
            <?= MODULES[$mc]['icon'] ?? '•' ?>
          </span>
        <?php endforeach; ?>
      <?php endif; ?>
    </td>
    <td style="color:#888;font-size:.8em">
      <?= $b['last_login_at'] ? date('d/m/Y H:i', strtotime($b['last_login_at'])) : 'Jamais' ?>
    </td>
    <td style="white-space:nowrap;">
      <a href="bailleur_admin_comptes.php?edit=<?= (int)$b['id'] ?>" class="btn-sm btn-edit">✏️ Modifier</a>
      <a href="bailleur_impersonate.php?as=<?= (int)$b['id'] ?>" class="btn-sm"
         style="background:#fff3e0;color:#7a4010;margin-left:4px;"
         onclick="return confirm('Se connecter en tant que <?= e(trim($b['prenom'].' '.$b['nom'])) ?> ? Vous verrez son espace exactement comme lui.')"
         title="Se connecter en tant que ce bailleur">🎭 Tester</a>
      <form method="POST" action="bailleur_admin_comptes.php" style="display:inline;margin-left:4px;">
        <input type="hidden" name="action" value="toggle_actif">
        <input type="hidden" name="user_id" value="<?= (int)$b['id'] ?>">
        <button type="submit" class="btn-sm btn-toggle"
                onclick="return confirm('<?= $b['actif']?'Désactiver':'Activer' ?> ce compte ?')">
          <?= $b['actif'] ? '🔴 Désactiver' : '🟢 Activer' ?>
        </button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

</div>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
