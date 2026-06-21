<?php
declare(strict_types=1);

/**
 * net_admin_collaborateurs.php — Ma Box Net : équipe affichée sur la vitrine.
 * Toggle visible_net + fonction + téléphone pro + photo, par agence.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/net_context.php';
require_login();

if (!function_exists('h')) { function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$pdo = $GLOBALS['pdo'];
if (!net_admin_can()) { deny_access("Réservé aux managers."); }

$agences  = net_admin_agences($pdo);
$idAgence = (int)($_GET['ag'] ?? ($_POST['ag'] ?? 0));
if ($idAgence === 0 && $agences) { $idAgence = (int)$agences[0]['id']; }

$flash = ['ok' => false, 'err' => ''];

/** Enregistre la photo uploadée d'un collaborateur, renvoie l'url relative ou null. */
function net_save_photo(array $file, int $uid): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 4 * 1024 * 1024) return null; // 4 Mo max
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return null;
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
    if ($ext === null) return null;
    $dir = __DIR__ . '/images/collaborateurs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = 'u' . $uid . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return null;
    return 'images/collaborateurs/' . $name;
}

if (is_post()) {
    verify_csrf('net_admin_collab');
    if (!net_admin_agence_autorisee($pdo, $idAgence)) {
        $flash['err'] = "Agence hors de votre périmètre.";
    } else {
        $vis = (array)($_POST['visible'] ?? []);
        $fct = (array)($_POST['fonction'] ?? []);
        $tel = (array)($_POST['tel'] ?? []);
        // On ne touche QUE les users de cette agence (sécurité multi-tenant).
        $users = $pdo->prepare("SELECT id FROM users WHERE id_agence = ?");
        $users->execute([$idAgence]);
        $ids = $users->fetchAll(PDO::FETCH_COLUMN);
        $upd = $pdo->prepare("UPDATE users SET visible_net=?, fonction=?, telephone_pro=? WHERE id=? AND id_agence=?");
        foreach ($ids as $uid) {
            $uid = (int)$uid;
            $upd->execute([
                isset($vis[$uid]) ? 1 : 0,
                trim((string)($fct[$uid] ?? '')) ?: null,
                trim((string)($tel[$uid] ?? '')) ?: null,
                $uid, $idAgence,
            ]);
            // Photo éventuelle
            if (isset($_FILES['photo']['name'][$uid])) {
                $f = [
                    'name'     => $_FILES['photo']['name'][$uid],
                    'type'     => $_FILES['photo']['type'][$uid],
                    'tmp_name' => $_FILES['photo']['tmp_name'][$uid],
                    'error'    => $_FILES['photo']['error'][$uid],
                    'size'     => $_FILES['photo']['size'][$uid],
                ];
                $url = net_save_photo($f, $uid);
                if ($url !== null) {
                    $pdo->prepare("UPDATE users SET avatar_url=? WHERE id=? AND id_agence=?")->execute([$url, $uid, $idAgence]);
                }
            }
        }
        $flash['ok'] = true;
    }
}

$collabs = [];
if ($idAgence > 0) {
    $st = $pdo->prepare("SELECT id, prenom, nom, fonction, email, telephone_pro, avatar_url, visible_net
                         FROM users WHERE id_agence = ? AND actif = 1 ORDER BY nom, prenom");
    $st->execute([$idAgence]);
    $collabs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$layout_title          = 'Collaborateurs';
$layout_module         = 'Ma Box Net';
$layout_sidebar        = 'sidebar_net';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.na-wrap{max-width:1040px;margin:0 auto;padding:18px 0 50px}
.na-title{font-family:"Sora",sans-serif;font-size:24px;font-weight:800;color:#36577d;margin-bottom:16px}
.na-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.na-bar select{padding:9px 12px;border:1px solid #d6d3cc;border-radius:10px;font-size:14px;background:#fff}
.na-alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
.na-alert-ok{background:#e7f6ec;color:#1f7a44}
.na-alert-err{background:#fdeaea;color:#b3261e}
.na-row{display:grid;grid-template-columns:54px 1.3fr 1.4fr 1fr 150px 90px;gap:12px;align-items:center;background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:10px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.na-row .ph{width:46px;height:46px;border-radius:50%;object-fit:cover;background:#243B5C;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
.na-row .nm{font-weight:700;font-size:14px}
.na-row .em{font-size:12px;color:#888}
.na-row input[type=text]{width:100%;padding:8px 10px;border:1px solid #d6d3cc;border-radius:8px;font-size:13px}
.na-row .vis{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;font-weight:600}
.na-rowhead{display:grid;grid-template-columns:54px 1.3fr 1.4fr 1fr 150px 90px;gap:12px;padding:0 14px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#9a9690}
.na-actions{margin-top:18px}
.na-btn{padding:11px 22px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;background:#36577d;color:#fff}
@media(max-width:760px){.na-row,.na-rowhead{grid-template-columns:1fr 1fr}.na-rowhead{display:none}}
</style>';

ob_start();
?>
<div class="na-wrap">
  <div class="na-title">👥 Collaborateurs sur la vitrine</div>

  <?php if (!$agences): ?>
    <div class="na-alert na-alert-err">Aucune agence dans votre périmètre.</div>
  <?php else: ?>

  <?php if ($flash['ok']): ?><div class="na-alert na-alert-ok">✅ Enregistré.</div>
  <?php elseif ($flash['err'] !== ''): ?><div class="na-alert na-alert-err"><?= h($flash['err']) ?></div><?php endif; ?>

  <form method="get" class="na-bar">
    <label for="ag" style="font-weight:700;color:#555">Agence :</label>
    <select id="ag" name="ag" onchange="this.form.submit()">
      <?php foreach ($agences as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $idAgence ? 'selected' : '' ?>><?= h((string)$a['nom_agence']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!$collabs): ?>
    <div class="na-alert na-alert-err">Aucun collaborateur actif sur cette agence.</div>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field('net_admin_collab') ?>
    <input type="hidden" name="ag" value="<?= $idAgence ?>">

    <div class="na-rowhead">
      <span>Photo</span><span>Nom</span><span>Fonction</span><span>Tél. pro</span><span>Changer photo</span><span>Vitrine</span>
    </div>

    <?php foreach ($collabs as $c): $uid = (int)$c['id']; ?>
      <div class="na-row">
        <?php if (!empty($c['avatar_url'])): ?>
          <img class="ph" src="<?= h(asset_url('/' . ltrim((string)$c['avatar_url'], '/'))) ?>?v=<?= time() ?>" alt="">
        <?php else: ?>
          <div class="ph"><?= h(mb_strtoupper(mb_substr((string)$c['prenom'],0,1) . mb_substr((string)$c['nom'],0,1))) ?></div>
        <?php endif; ?>
        <div>
          <div class="nm"><?= h(trim((string)$c['prenom'] . ' ' . (string)$c['nom'])) ?></div>
          <div class="em"><?= h((string)$c['email']) ?></div>
        </div>
        <div><input type="text" name="fonction[<?= $uid ?>]" value="<?= h((string)$c['fonction']) ?>" placeholder="Négociateur…"></div>
        <div><input type="text" name="tel[<?= $uid ?>]" value="<?= h((string)$c['telephone_pro']) ?>" placeholder="04 …"></div>
        <div><input type="file" name="photo[<?= $uid ?>]" accept="image/jpeg,image/png,image/webp"></div>
        <div class="vis"><label><input type="checkbox" name="visible[<?= $uid ?>]" value="1" <?= (int)$c['visible_net'] === 1 ? 'checked' : '' ?>> Afficher</label></div>
      </div>
    <?php endforeach; ?>

    <div class="na-actions"><button type="submit" class="na-btn">Enregistrer</button></div>
  </form>
  <?php endif; ?>

  <?php endif; ?>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
