<?php
/**
 * bailleur_partages.php — Partage du patrimoine par lien à jeton (lecture seule).
 *
 * Un partage = accès public tokenisé (SANS login) au patrimoine d'un COMPTE
 * BAILLEUR existant, destiné à un tiers externe (banquier, associé, famille…).
 * On choisit : le compte bailleur (= périmètre propriétaires + scénarios),
 * le gestionnaire affiché (coordonnées), le scénario partagé, et les colonnes
 * autorisées (prix de vente / loyer / locataire / descriptif). Consentement au
 * 1er accès + expiration + révocation (pattern p.php / document_requests).
 *
 * Accès : super admin / admin bailleur uniquement.
 * Brique 1/2 (la page publique = patrimoine_partage.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

// Accès : admins bailleur (super admin / 1 / 2 / 8) OU un bailleur externe, mais alors SCOPÉ
// à son seul patrimoine (il ne peut créer / voir / révoquer que des partages de SON compte).
$ppIsCagedBailleur = function_exists('is_caged_bailleur') && is_caged_bailleur();
if (!can_admin_bailleur() && !$ppIsCagedBailleur) { http_response_code(403); exit('Accès réservé aux administrateurs.'); }
$ppScopeUid = $ppIsCagedBailleur ? (int)current_user_id() : 0; // 0 = admin (tous comptes)

$pdo = $GLOBALS['pdo'];
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function pp_gen_token(): string { return bin2hex(random_bytes(24)); } // 48 hex

$msg = ''; $msgType = '';

// ── Comptes bailleurs (= périmètres partageables) ────────────────────
$bailleurs = $pdo->query("
    SELECT u.id, u.nom, u.prenom, u.email,
           (SELECT COUNT(*) FROM user_proprietaires WHERE id_user=u.id) AS nb_props
    FROM users u
    JOIN roles r ON r.id=u.id_role
    WHERE u.super_admin=0
      AND (r.code IN ('PROPRIO','PROPRIO_VIP','bailleur')
           OR EXISTS (SELECT 1 FROM user_proprietaires WHERE id_user=u.id))
    ORDER BY u.nom, u.prenom
")->fetchAll(\PDO::FETCH_ASSOC);
// Bailleur scopé : il ne peut partager que SON propre compte.
if ($ppScopeUid) $bailleurs = array_values(array_filter($bailleurs, fn($b) => (int)$b['id'] === $ppScopeUid));

// ── Gestionnaires (staff dont on affiche les coordonnées) ────────────
$gestionnaires = $pdo->query("
    SELECT u.id, u.nom, u.prenom, u.email, u.telephone
    FROM users u
    JOIN roles r ON r.id=u.id_role
    WHERE u.actif=1 AND (u.super_admin=1 OR r.niveau_acces <= 7)
    ORDER BY u.nom, u.prenom
")->fetchAll(\PDO::FETCH_ASSOC);

// ── Scénarios de prix de vente disponibles ───────────────────────────
$scenarios = $pdo->query("
    SELECT scenario_code,
           MAX(COALESCE(NULLIF(scenario_label,''), scenario_code)) AS label
    FROM bien_prix
    WHERE type_valeur='prix_vente' AND is_courant=1
    GROUP BY scenario_code
    ORDER BY (scenario_code='courant') DESC, label
")->fetchAll(\PDO::FETCH_ASSOC);
if (empty($scenarios)) { $scenarios = [['scenario_code'=>'courant','label'=>'Courant']]; }

// ════════════════════════════════════════════════════════════════════
// ACTIONS POST
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $idBailleur = $ppScopeUid ?: (int)($_POST['id_user_bailleur'] ?? 0); // bailleur scopé → forcé à son compte
        $idGest     = (int)($_POST['id_user_gestionnaire'] ?? 0) ?: null;
        $idTiers    = (int)($_POST['id_tiers_destinataire'] ?? 0) ?: null;
        $destNom    = trim($_POST['destinataire_nom'] ?? '');
        $destMail   = strtolower(trim($_POST['destinataire_email'] ?? '')) ?: null;
        // Scénarios multi : liste blanche des codes cochés → CSV (1er = défaut)
        $scenCodes  = array_values(array_unique(array_filter(array_map(
            fn($c) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$c)),
            (array)($_POST['scenario_codes'] ?? [])
        ))));
        if (empty($scenCodes)) { $scenCodes = ['courant']; }
        $scenario   = implode(',', $scenCodes);
        $mPrix      = isset($_POST['montrer_prix_vente']) ? 1 : 0;
        $mCrea      = isset($_POST['montrer_creanciers']) ? 1 : 0;
        $mFin       = isset($_POST['montrer_financements']) ? 1 : 0;
        $mLoyer     = isset($_POST['montrer_loyer']) ? 1 : 0;
        $mLoc       = isset($_POST['montrer_locataire']) ? 1 : 0;
        $mDesc      = isset($_POST['montrer_descriptif']) ? 1 : 0;
        $niveauAcces = ($_POST['niveau_acces'] ?? 'lecture') === 'contribution' ? 'contribution' : 'lecture';
        $expireDays = (int)($_POST['expire_days'] ?? 0);
        $expireAt   = $expireDays > 0 ? (new DateTime("+{$expireDays} days"))->format('Y-m-d H:i:s') : null;
        // Mode test : créer le partage SANS activer le lien public (aperçu seulement).
        $actif      = isset($_POST['mode_test']) ? 0 : 1;

        if (!$idBailleur || !$destNom) {
            $msg = 'Compte bailleur et nom du destinataire obligatoires.'; $msgType = 'error';
        } elseif ($destMail !== null && !filter_var($destMail, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Email destinataire invalide.'; $msgType = 'error';
        } else {
            $token = pp_gen_token();
            $pdo->prepare("
                INSERT INTO patrimoine_partages
                    (token, id_user_bailleur, id_user_gestionnaire, id_tiers_destinataire,
                     destinataire_nom, destinataire_email,
                     scenario_code, montrer_prix_vente, montrer_creanciers, montrer_financements, montrer_loyer, montrer_locataire, montrer_descriptif,
                     niveau_acces, expire_at, actif, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $token, $idBailleur, $idGest, $idTiers, $destNom, $destMail,
                $scenario, $mPrix, $mCrea, $mFin, $mLoyer, $mLoc, $mDesc,
                $niveauAcces, $expireAt, $actif, (int)current_user_id()
            ]);
            $msg = $actif
                ? 'Partage créé et lien actif. Copiez-le dans la liste ci-dessous.'
                : 'Partage créé en MODE TEST (lien inactif). Utilisez « 👁 Aperçu » ; activez-le via « Réactiver » quand prêt.';
            $msgType = 'success';
        }
    }

    if ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE patrimoine_partages SET actif=0, revoked_at=NOW() WHERE id=?" . ($ppScopeUid ? " AND id_user_bailleur=" . $ppScopeUid : ""))->execute([$id]);
        $msg = 'Partage révoqué.'; $msgType = 'success';
    }
    if ($action === 'reactivate') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE patrimoine_partages SET actif=1, revoked_at=NULL WHERE id=?" . ($ppScopeUid ? " AND id_user_bailleur=" . $ppScopeUid : ""))->execute([$id]);
        $msg = 'Partage réactivé.'; $msgType = 'success';
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM patrimoine_partages WHERE id=?" . ($ppScopeUid ? " AND id_user_bailleur=" . $ppScopeUid : ""))->execute([$id]);
        $msg = 'Partage supprimé définitivement.'; $msgType = 'success';
    }

    // ── Post/Redirect/Get : évite qu'un F5 ne re-soumette le formulaire
    //    (sinon un partage se recrée à chaque rechargement).
    $_SESSION['pp_flash'] = ['msg' => $msg, 'type' => $msgType];
    header('Location: bailleur_partages.php');
    exit;
}

// Flash après redirection
if (!empty($_SESSION['pp_flash'])) {
    $msg = (string)$_SESSION['pp_flash']['msg'];
    $msgType = (string)$_SESSION['pp_flash']['type'];
    unset($_SESSION['pp_flash']);
}

// ── Liste des partages ───────────────────────────────────────────────
$partages = $pdo->query("
    SELECT pp.*,
           CONCAT(COALESCE(ub.prenom,''),' ',COALESCE(ub.nom,'')) AS bailleur_nom,
           CONCAT(COALESCE(ug.prenom,''),' ',COALESCE(ug.nom,'')) AS gest_nom
    FROM patrimoine_partages pp
    LEFT JOIN users ub ON ub.id = pp.id_user_bailleur
    LEFT JOIN users ug ON ug.id = pp.id_user_gestionnaire
    " . ($ppScopeUid ? "WHERE pp.id_user_bailleur = " . $ppScopeUid : "") . "
    ORDER BY pp.actif DESC, pp.created_at DESC
")->fetchAll(\PDO::FETCH_ASSOC);

$publicBase = rtrim(app_url('/'), '/') . '/patrimoine_partage.php?t=';

// ── Layout ───────────────────────────────────────────────────────────
$pageTitle     = 'Partages patrimoine';
$pageSubtitle  = 'Ma Box Bailleur · Administration';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_partages';

$extraCss = <<<CSS
<style>
.page-header{display:flex;align-items:center;gap:12px;margin-bottom:8px;}
.page-header h2{margin:0;color:#1a237e;font-size:1.15em;}
.page-intro{color:#777;font-size:.85em;margin-bottom:18px;max-width:900px;}
.alert-success{background:#e8f5e9;border-left:4px solid #2e7d32;color:#1b5e20;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:.88em;}
.alert-error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:.88em;}

.form-card{background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:24px 28px;margin-bottom:22px;}
.form-card h3{margin:0 0 18px;color:#1a237e;font-size:.95em;padding-bottom:10px;border-bottom:1px solid #eee;}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:.8em;font-weight:600;color:#555;}
.form-group input,.form-group select{border:1px solid #ddd;border-radius:6px;padding:8px 10px;font-size:.88em;width:100%;box-sizing:border-box;}
.form-group input:focus,.form-group select:focus{outline:2px solid #3f51b5;border-color:#3f51b5;}
.form-full{grid-column:1/-1;}
.cols-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
.col-check{display:flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid #e8eaf6;border-radius:8px;cursor:pointer;font-size:.82em;}
.col-check:has(input:checked){background:#e8eaf6;border-color:#3f51b5;color:#1a237e;font-weight:600;}
.btn-primary{background:#1a237e;color:white;border:none;border-radius:8px;padding:10px 22px;font-size:.9em;font-weight:bold;cursor:pointer;}
.btn-primary:hover{background:#283593;}

table.pp{border-collapse:collapse;width:100%;background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
table.pp thead tr{background:#1a237e;color:white;}
table.pp th{padding:10px 14px;text-align:left;font-size:.8em;white-space:nowrap;}
table.pp td{padding:9px 14px;border-bottom:1px solid #f0f0f0;font-size:.84em;vertical-align:middle;}
table.pp tr:hover td{background:#fafbff;}
.badge-actif{background:#c8e6c9;color:#1b5e20;padding:2px 8px;border-radius:10px;font-size:.76em;font-weight:bold;}
.badge-off{background:#ffcdd2;color:#b71c1c;padding:2px 8px;border-radius:10px;font-size:.76em;font-weight:bold;}
.badge-exp{background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:10px;font-size:.76em;font-weight:bold;}
.pill{background:#e8eaf6;color:#1a237e;padding:1px 7px;border-radius:10px;font-size:.74em;display:inline-block;margin:1px;}
.linkbox{display:flex;gap:6px;align-items:center;}
.linkbox input{border:1px solid #ddd;border-radius:6px;padding:5px 8px;font-size:.75em;width:130px;color:#555;background:#f7f8fc;}
.pp-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
td.actions-cell{white-space:normal;}
td.actions-cell .btn-sm{margin:2px 2px 0 0;}
td.actions-cell form{display:inline;}
.btn-sm{border:none;border-radius:6px;padding:5px 10px;font-size:.76em;cursor:pointer;font-weight:bold;text-decoration:none;display:inline-block;}
.btn-copy{background:#e8eaf6;color:#1a237e;} .btn-copy:hover{background:#c5cae9;}
.btn-open{background:#e3f2fd;color:#0d47a1;} .btn-open:hover{background:#bbdefb;}
.btn-revoke{background:#ffebee;color:#b71c1c;} .btn-toggle{background:#e8f5e9;color:#1b5e20;}
.ac-results{position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #ddd;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.12);z-index:50;max-height:280px;overflow-y:auto;margin-top:2px;}
.ac-item{padding:8px 12px;cursor:pointer;font-size:.84em;border-bottom:1px solid #f2f2f2;}
.ac-item:hover{background:#eef1ff;}
.ac-item .ac-lbl{font-weight:600;color:#1a237e;}
.ac-item .ac-meta{font-size:.85em;color:#999;}
.ac-empty{padding:10px 12px;color:#aaa;font-size:.82em;}
</style>
CSS;

require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<div style="padding:20px 28px;max-width:1200px;margin:0 auto;">

<div class="page-header"><h2>🔗 Partages patrimoine par lien</h2></div>
<div class="page-intro">
  Générez un lien sécurisé (sans mot de passe) donnant à un tiers extérieur — banquier, associé, notaire, famille —
  une vue <strong>lecture seule</strong> du patrimoine d'un compte bailleur. Vous choisissez le scénario de prix partagé
  et les colonnes visibles. Le lien est révocable et peut expirer ; un écran de consentement s'affiche au 1<sup>er</sup> accès.
</div>

<?php if ($msg): ?><div class="alert-<?= $msgType ?>"><?= e($msg) ?></div><?php endif; ?>

<!-- ══ CRÉATION ═══════════════════════════════════════════ -->
<div class="form-card">
  <h3>➕ Nouveau partage</h3>
  <form method="POST" action="bailleur_partages.php">
    <input type="hidden" name="action" value="create">
    <?= csrf_field() ?>

    <div class="form-grid">
      <div class="form-group">
        <label>Compte bailleur (périmètre partagé) *</label>
        <select name="id_user_bailleur" required>
          <option value="">— Choisir —</option>
          <?php foreach ($bailleurs as $b): ?>
          <option value="<?= (int)$b['id'] ?>">
            <?= e(trim($b['prenom'].' '.$b['nom'])) ?> — <?= (int)$b['nb_props'] ?> propriétaire(s)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Gestionnaire affiché (coordonnées)</label>
        <select name="id_user_gestionnaire">
          <option value="">— Aucun —</option>
          <?php foreach ($gestionnaires as $g): ?>
          <option value="<?= (int)$g['id'] ?>"><?= e(trim($g['prenom'].' '.$g['nom'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group form-full" style="position:relative;">
        <label>Destinataire (recherche dans les tiers) *</label>
        <input type="hidden" name="id_tiers_destinataire" id="pp-tiers-id">
        <input type="hidden" name="destinataire_email" id="pp-tiers-email">
        <input type="text" name="destinataire_nom" id="pp-tiers-search" autocomplete="off"
               placeholder="Taper un nom, une société, un email…" required>
        <div id="pp-tiers-results" class="ac-results" style="display:none;"></div>
        <div id="pp-tiers-picked" style="display:none;margin-top:4px;font-size:.8em;color:#1b5e20;"></div>
      </div>
      <div class="form-group">
        <label>Niveau d'accès</label>
        <select name="niveau_acces">
          <option value="lecture">🔒 Lecture seule (défaut)</option>
          <option value="contribution">✍️ Contribution — dépôt de documents & complétion (avocat)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Expiration</label>
        <select name="expire_days">
          <option value="0">Jamais</option>
          <option value="7">7 jours</option>
          <option value="30" selected>30 jours</option>
          <option value="90">90 jours</option>
        </select>
      </div>
    </div>

    <div class="form-group form-full" style="margin-top:16px;">
      <label>Scénarios de prix partagés (le tiers choisit lequel afficher)</label>
      <div class="cols-grid">
        <?php foreach ($scenarios as $i => $s): ?>
        <label class="col-check">
          <input type="checkbox" name="scenario_codes[]" value="<?= e($s['scenario_code']) ?>"
                 <?= $s['scenario_code']==='courant' || $i===0 ? 'checked' : '' ?>>
          <?= e($s['label']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-group form-full" style="margin-top:16px;">
      <label>Colonnes visibles par le tiers</label>
      <div class="cols-grid">
        <label class="col-check"><input type="checkbox" name="montrer_descriptif" checked> 📝 Descriptif sommaire</label>
        <label class="col-check"><input type="checkbox" name="montrer_locataire" checked> 👤 Nom du locataire</label>
        <label class="col-check"><input type="checkbox" name="montrer_loyer" checked> 💶 Loyer</label>
        <label class="col-check"><input type="checkbox" name="montrer_prix_vente"> 🏷️ Prix de vente</label>
        <label class="col-check"><input type="checkbox" name="montrer_creanciers"> ⚖️ Dossiers créanciers (avocat)</label>
        <label class="col-check"><input type="checkbox" name="montrer_financements"> 💶 Financements (comptable)</label>
      </div>
    </div>

    <label class="col-check" style="margin-top:16px;display:inline-flex;">
      <input type="checkbox" name="mode_test"> 🧪 Mode test — créer sans activer le lien (aperçu seulement)
    </label>

    <div style="margin-top:20px;">
      <button type="submit" class="btn-primary">🔗 Générer le lien de partage</button>
    </div>
  </form>
</div>

<!-- ══ LISTE ══════════════════════════════════════════════ -->
<div class="pp-scroll">
<table class="pp">
  <thead><tr>
    <th>DESTINATAIRE</th><th>PÉRIMÈTRE</th><th>SCÉNARIO / COLONNES</th>
    <th>STATUT</th><th>CONSULTATIONS</th><th>LIEN</th><th>ACTIONS</th>
  </tr></thead>
  <tbody>
  <?php if (empty($partages)): ?>
  <tr><td colspan="7" style="text-align:center;padding:30px;color:#aaa;">Aucun partage pour le moment.</td></tr>
  <?php else: foreach ($partages as $p):
      $url = $publicBase . e($p['token']);
      $expired = $p['expire_at'] && strtotime($p['expire_at']) < time();
  ?>
  <tr>
    <td>
      <strong><?= e($p['destinataire_nom']) ?></strong>
      <?php if ($p['destinataire_email']): ?><div style="font-size:.78em;color:#999"><?= e($p['destinataire_email']) ?></div><?php endif; ?>
      <?php if ($p['gest_nom'] && trim($p['gest_nom'])): ?><div style="font-size:.74em;color:#bbb">gest. <?= e(trim($p['gest_nom'])) ?></div><?php endif; ?>
    </td>
    <td><?= e(trim($p['bailleur_nom']) ?: '—') ?></td>
    <td>
      <?php foreach (array_filter(explode(',', (string)$p['scenario_code'])) as $sc): ?>
        <span class="pill"><?= e($sc) ?></span>
      <?php endforeach; ?>
      <div style="margin-top:3px;">
        <?php if ($p['montrer_descriptif']): ?><span class="pill">📝</span><?php endif; ?>
        <?php if ($p['montrer_locataire']): ?><span class="pill">👤</span><?php endif; ?>
        <?php if ($p['montrer_loyer']): ?><span class="pill">💶</span><?php endif; ?>
        <?php if ($p['montrer_prix_vente']): ?><span class="pill">🏷️ prix</span><?php endif; ?>
        <?php if (!empty($p['montrer_creanciers'])): ?><span class="pill">⚖️ créanciers</span><?php endif; ?>
        <?php if (!empty($p['montrer_financements'])): ?><span class="pill">💶 financements</span><?php endif; ?>
        <?php if (($p['niveau_acces'] ?? 'lecture') === 'contribution'): ?><span class="pill" style="background:#e8f5e9;color:#1b5e20;">✍️ contribution</span><?php endif; ?>
      </div>
    </td>
    <td>
      <?php if (!$p['actif'] && $p['revoked_at']): ?><span class="badge-off">Révoqué</span>
      <?php elseif (!$p['actif']): ?><span class="badge-exp">🧪 Test (inactif)</span>
      <?php elseif ($expired): ?><span class="badge-exp">Expiré</span>
      <?php else: ?><span class="badge-actif">Actif</span><?php endif; ?>
      <?php if ($p['expire_at']): ?><div style="font-size:.72em;color:#999">exp. <?= date('d/m/Y', strtotime($p['expire_at'])) ?></div><?php endif; ?>
    </td>
    <td style="color:#666;font-size:.82em">
      <?= (int)$p['nb_consultations'] ?>×
      <?php if ($p['derniere_consultation']): ?><div style="font-size:.72em;color:#999"><?= date('d/m/Y H:i', strtotime($p['derniere_consultation'])) ?></div><?php endif; ?>
    </td>
    <td>
      <div class="linkbox">
        <input type="text" readonly value="<?= $url ?>" id="lk<?= (int)$p['id'] ?>">
        <button type="button" class="btn-sm btn-copy" onclick="ppCopy(<?= (int)$p['id'] ?>)">📋</button>
      </div>
    </td>
    <td class="actions-cell">
      <a href="patrimoine_partage.php?preview=<?= (int)$p['id'] ?>" target="_blank" class="btn-sm btn-open">👁 Aperçu</a>
      <a href="<?= $url ?>" target="_blank" class="btn-sm btn-open">↗ Ouvrir</a>
      <form method="POST" action="bailleur_partages.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <?php if ($p['actif']): ?>
          <input type="hidden" name="action" value="revoke">
          <button type="submit" class="btn-sm btn-revoke" onclick="return confirm('Révoquer ce partage ? Le lien cessera de fonctionner.')">🚫 Révoquer</button>
        <?php else: ?>
          <input type="hidden" name="action" value="reactivate">
          <button type="submit" class="btn-sm btn-toggle">✓ Réactiver</button>
        <?php endif; ?>
      </form>
      <form method="POST" action="bailleur_partages.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <button type="submit" class="btn-sm btn-revoke" title="Supprimer définitivement"
                onclick="return confirm('Supprimer DÉFINITIVEMENT ce partage ? Le lien sera invalidé et la ligne effacée. Action irréversible.')">🗑️</button>
      </form>
    </td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div><!-- /pp-scroll -->

</div>

<script>
// ── Autocomplete recherche tiers (api/tiers_lookup.php) ──────────────
(function(){
  var inp=document.getElementById('pp-tiers-search');
  var box=document.getElementById('pp-tiers-results');
  var idF=document.getElementById('pp-tiers-id');
  var mailF=document.getElementById('pp-tiers-email');
  var picked=document.getElementById('pp-tiers-picked');
  if(!inp) return;
  var timer=null, base='<?= e(rtrim(app_url('/'),'/')) ?>/api/tiers_lookup.php';
  function esc(s){return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  inp.addEventListener('input', function(){
    idF.value=''; mailF.value=''; picked.style.display='none';
    var q=inp.value.trim();
    if(q.length<2){ box.style.display='none'; return; }
    clearTimeout(timer);
    timer=setTimeout(function(){
      fetch(base+'?limit=10&q='+encodeURIComponent(q))
        .then(function(r){return r.json();})
        .then(function(d){
          var items=(d&&d.items)||[];
          if(!items.length){ box.innerHTML='<div class="ac-empty">Aucun tiers trouvé.</div>'; box.style.display='block'; return; }
          box.innerHTML=items.map(function(it){
            var meta=[it.email,it.ville].filter(Boolean).join(' · ');
            return '<div class="ac-item" data-id="'+it.id+'" data-lbl="'+esc(it.label)+'" data-mail="'+esc(it.email||'')+'">'
                 + '<div class="ac-lbl">'+esc(it.label)+'</div>'
                 + (meta?'<div class="ac-meta">'+esc(meta)+'</div>':'')+'</div>';
          }).join('');
          box.style.display='block';
          box.querySelectorAll('.ac-item').forEach(function(el){
            el.addEventListener('click', function(){
              idF.value=el.dataset.id; inp.value=el.dataset.lbl; mailF.value=el.dataset.mail;
              picked.textContent='✓ Tiers sélectionné : '+el.dataset.lbl+(el.dataset.mail?' ('+el.dataset.mail+')':'');
              picked.style.display='block'; box.style.display='none';
            });
          });
        });
    }, 220);
  });
  document.addEventListener('click', function(ev){ if(!box.contains(ev.target)&&ev.target!==inp) box.style.display='none'; });
})();

function ppCopy(id){
  var el=document.getElementById('lk'+id);
  el.select(); el.setSelectionRange(0,99999);
  navigator.clipboard.writeText(el.value).then(function(){
    var b=el.nextElementSibling; var t=b.textContent; b.textContent='✓'; setTimeout(function(){b.textContent=t;},1200);
  });
}
</script>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
