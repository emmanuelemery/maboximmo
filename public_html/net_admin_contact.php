<?php
declare(strict_types=1);

/**
 * net_admin_contact.php — Ma Box Net : coordonnées & horaires par agence.
 * Édite les colonnes de `agences` affichées sur la page Contact de la vitrine.
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

if (is_post()) {
    verify_csrf('net_admin_contact');
    if (!net_admin_agence_autorisee($pdo, $idAgence)) {
        $flash['err'] = "Agence hors de votre périmètre.";
    } else {
        try {
            $st = $pdo->prepare(
                "UPDATE agences SET
                   telephone = ?, email = ?, adresse_1 = ?, adresse_2 = ?,
                   code_postal = ?, ville = ?, horaires = ?
                 WHERE id = ?"
            );
            $st->execute([
                trim((string)post('telephone', '')) ?: null,
                trim((string)post('email', '')) ?: null,
                trim((string)post('adresse_1', '')) ?: null,
                trim((string)post('adresse_2', '')) ?: null,
                trim((string)post('code_postal', '')) ?: null,
                trim((string)post('ville', '')) ?: null,
                trim((string)post('horaires', '')) ?: null,
                $idAgence,
            ]);
            $flash['ok'] = true;
        } catch (Throwable $e) {
            $flash['err'] = APP_DEBUG ? $e->getMessage() : "Enregistrement impossible.";
        }
    }
}

$row = null;
if ($idAgence > 0) {
    $st = $pdo->prepare("SELECT * FROM agences WHERE id = ? LIMIT 1");
    $st->execute([$idAgence]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$layout_title          = 'Coordonnées & horaires';
$layout_module         = 'Ma Box Net';
$layout_sidebar        = 'sidebar_net';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.na-wrap{max-width:860px;margin:0 auto;padding:18px 0 50px}
.na-title{font-family:"Sora",sans-serif;font-size:24px;font-weight:800;color:#36577d;margin-bottom:16px}
.na-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.na-bar select{padding:9px 12px;border:1px solid #d6d3cc;border-radius:10px;font-size:14px;background:#fff}
.na-card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
.na-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.na-field{margin-bottom:4px}
.na-field.full{grid-column:1/-1}
.na-field label{display:block;font-size:12.5px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.na-field input[type=text],.na-field textarea{width:100%;padding:11px 13px;border:1px solid #d6d3cc;border-radius:10px;font-size:14px;font-family:inherit}
.na-field textarea{min-height:120px;line-height:1.6}
.na-hint{font-size:12px;color:#8a8680;margin-top:5px}
.na-actions{margin-top:18px}
.na-btn{padding:11px 20px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer}
.na-btn-primary{background:#36577d;color:#fff}
.na-alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
.na-alert-ok{background:#e7f6ec;color:#1f7a44}
.na-alert-err{background:#fdeaea;color:#b3261e}
@media(max-width:640px){.na-grid{grid-template-columns:1fr}}
</style>';

ob_start();
?>
<div class="na-wrap">
  <div class="na-title">📍 Coordonnées & horaires</div>

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

  <form method="post" class="na-card">
    <?= csrf_field('net_admin_contact') ?>
    <input type="hidden" name="ag" value="<?= $idAgence ?>">
    <div class="na-grid">
      <div class="na-field"><label>Téléphone</label><input type="text" name="telephone" maxlength="30" value="<?= h((string)($row['telephone'] ?? '')) ?>"></div>
      <div class="na-field"><label>E-mail</label><input type="text" name="email" maxlength="190" value="<?= h((string)($row['email'] ?? '')) ?>"></div>
      <div class="na-field full"><label>Adresse</label><input type="text" name="adresse_1" maxlength="190" value="<?= h((string)($row['adresse_1'] ?? '')) ?>"></div>
      <div class="na-field full"><label>Complément d'adresse</label><input type="text" name="adresse_2" maxlength="190" value="<?= h((string)($row['adresse_2'] ?? '')) ?>"></div>
      <div class="na-field"><label>Code postal</label><input type="text" name="code_postal" maxlength="10" value="<?= h((string)($row['code_postal'] ?? '')) ?>"></div>
      <div class="na-field"><label>Ville</label><input type="text" name="ville" maxlength="120" value="<?= h((string)($row['ville'] ?? '')) ?>"></div>
      <div class="na-field full">
        <label>Horaires d'ouverture (une ligne par jour)</label>
        <textarea name="horaires" placeholder="Lundi–Vendredi : 9h–12h / 14h–18h&#10;Samedi : 9h–12h"><?= h((string)($row['horaires'] ?? '')) ?></textarea>
        <div class="na-hint">Chaque ligne s'affiche telle quelle sur la page Contact de la vitrine.</div>
      </div>
    </div>
    <div class="na-actions"><button type="submit" class="na-btn na-btn-primary">Enregistrer</button></div>
  </form>

  <?php endif; ?>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
