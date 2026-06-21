<?php
declare(strict_types=1);

/**
 * net_admin_pages.php — Ma Box Net : édition des TEXTES éditoriaux locaux
 * (table agence_net_page) par agence et par page (métiers, accueil, à-propos).
 * Ces textes différencient chaque vitrine (sous-domaine) pour le SEO local.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/net_context.php';
require_login();

if (!function_exists('h')) { function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$pdo = $GLOBALS['pdo'];
if (!net_admin_can()) { deny_access("Réservé aux managers."); }

$agences = net_admin_agences($pdo);

// Pages éditables (clé = page_key ; doit matcher les slugs métiers + accueil).
$PAGES = [
    'home'           => 'Accueil (intro locale)',
    'transaction'    => 'Achat & vente',
    'gestion'        => 'Gestion locative',
    'location'       => 'Location',
    'syndic'         => 'Syndic',
    'investissement' => 'Investissement',
    'a-propos'       => 'À propos',
];

$idAgence = (int)($_GET['ag'] ?? ($_POST['ag'] ?? 0));
if ($idAgence === 0 && $agences) { $idAgence = (int)$agences[0]['id']; }
$pageKey  = (string)($_GET['page'] ?? ($_POST['page'] ?? 'home'));
if (!isset($PAGES[$pageKey])) { $pageKey = 'home'; }

$flash = ['ok' => false, 'err' => ''];

if (is_post()) {
    verify_csrf('net_admin_pages');
    if (!net_admin_agence_autorisee($pdo, $idAgence)) {
        $flash['err'] = "Agence hors de votre périmètre.";
    } else {
        $titre   = trim((string)post('titre', ''));
        $contenu = trim((string)post('contenu_html', ''));
        $mt      = trim((string)post('meta_title', ''));
        $md      = trim((string)post('meta_description', ''));
        $actif   = post('actif', '') !== '' ? 1 : 0;
        try {
            $st = $pdo->prepare(
                "INSERT INTO agence_net_page
                   (id_agence, page_key, titre, contenu_html, meta_title, meta_description, actif, updated_by)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   titre=VALUES(titre), contenu_html=VALUES(contenu_html),
                   meta_title=VALUES(meta_title), meta_description=VALUES(meta_description),
                   actif=VALUES(actif), updated_by=VALUES(updated_by)"
            );
            $st->execute([$idAgence, $pageKey, $titre ?: null, $contenu ?: null,
                          $mt ?: null, $md ?: null, $actif, current_user_id()]);
            $flash['ok'] = true;
        } catch (Throwable $e) {
            $flash['err'] = APP_DEBUG ? $e->getMessage() : "Enregistrement impossible.";
        }
    }
}

// Données courantes
$row = null;
$schemaErr = '';
if ($idAgence > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM agence_net_page WHERE id_agence=? AND page_key=? LIMIT 1");
        $st->execute([$idAgence, $pageKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $schemaErr = "La table 'agence_net_page' est introuvable. Exécutez la migration SQL (SQL_A_EXECUTER.sql) avant d'utiliser cette page.";
        if (APP_DEBUG) { $schemaErr .= ' — ' . $e->getMessage(); }
    }
}
$agenceNom = '';
foreach ($agences as $a) { if ((int)$a['id'] === $idAgence) { $agenceNom = (string)$a['nom_agence']; } }

$layout_title          = 'Textes des vitrines';
$layout_module         = 'Ma Box Net';
$layout_sidebar        = 'sidebar_net';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.na-wrap{max-width:1000px;margin:0 auto;padding:18px 0 50px}
.na-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:18px}
.na-title{font-family:"Sora",sans-serif;font-size:24px;font-weight:800;color:#36577d}
.na-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.na-bar select{padding:9px 12px;border:1px solid #d6d3cc;border-radius:10px;font-size:14px;background:#fff}
.na-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:18px}
.na-tab{padding:7px 13px;border-radius:99px;font-size:13px;font-weight:600;text-decoration:none;color:#5a5650;background:#efece6;border:1px solid transparent}
.na-tab.active{background:#36577d;color:#fff}
.na-card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
.na-field{margin-bottom:16px}
.na-field label{display:block;font-size:12.5px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.na-field input[type=text],.na-field textarea{width:100%;padding:11px 13px;border:1px solid #d6d3cc;border-radius:10px;font-size:14px;font-family:inherit}
.na-field textarea{min-height:220px;line-height:1.6}
.na-hint{font-size:12px;color:#8a8680;margin-top:5px}
.na-actions{display:flex;gap:12px;align-items:center;margin-top:8px}
.na-btn{padding:11px 20px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer}
.na-btn-primary{background:#36577d;color:#fff}
.na-btn-ghost{background:#efece6;color:#36577d;text-decoration:none}
.na-alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
.na-alert-ok{background:#e7f6ec;color:#1f7a44}
.na-alert-err{background:#fdeaea;color:#b3261e}
.na-check{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:#444}
</style>';

ob_start();
?>
<div class="na-wrap">
  <div class="na-head">
    <div class="na-title">🌐 Textes des vitrines</div>
    <?php if ($agenceNom !== ''): ?>
      <a class="na-btn na-btn-ghost" target="_blank"
         href="<?= h(app_url('/mbi_annonces_' . ($pageKey === 'home' || $pageKey === 'a-propos' ? 'index' : $pageKey) . '.php?net_agence=' . $idAgence)) ?>">
        Prévisualiser ↗
      </a>
    <?php endif; ?>
  </div>

  <?php if (!$agences): ?>
    <div class="na-alert na-alert-err">Aucune agence dans votre périmètre.</div>
  <?php else: ?>

  <?php if ($schemaErr !== ''): ?><div class="na-alert na-alert-err">⚠️ <?= h($schemaErr) ?></div><?php endif; ?>
  <?php if ($flash['ok']): ?><div class="na-alert na-alert-ok">✅ Enregistré.</div>
  <?php elseif ($flash['err'] !== ''): ?><div class="na-alert na-alert-err"><?= h($flash['err']) ?></div><?php endif; ?>

  <form method="get" class="na-bar">
    <label for="ag" style="font-weight:700;color:#555">Agence :</label>
    <select id="ag" name="ag" onchange="this.form.submit()">
      <?php foreach ($agences as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $idAgence ? 'selected' : '' ?>><?= h((string)$a['nom_agence']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="page" value="<?= h($pageKey) ?>">
  </form>

  <div class="na-tabs">
    <?php foreach ($PAGES as $k => $lbl): ?>
      <a class="na-tab <?= $k === $pageKey ? 'active' : '' ?>"
         href="<?= h(app_url('/net_admin_pages.php?ag=' . $idAgence . '&page=' . $k)) ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="na-card">
    <?= csrf_field('net_admin_pages') ?>
    <input type="hidden" name="ag" value="<?= $idAgence ?>">
    <input type="hidden" name="page" value="<?= h($pageKey) ?>">

    <div class="na-field">
      <label>Titre de la section</label>
      <input type="text" name="titre" maxlength="255" value="<?= h((string)($row['titre'] ?? '')) ?>" placeholder="Ex : Acheter et vendre à Lyon 7 avec REGIE EMERY LYON">
    </div>

    <div class="na-field">
      <label>Contenu (HTML simple autorisé : &lt;p&gt; &lt;strong&gt; &lt;ul&gt; &lt;li&gt; &lt;h2&gt; &lt;h3&gt; &lt;a&gt;)</label>
      <textarea name="contenu_html" placeholder="Texte local différenciant pour le référencement…"><?= h((string)($row['contenu_html'] ?? '')) ?></textarea>
      <div class="na-hint">Conseil SEO : un contenu unique par ville/agence (quartiers, repères locaux) évite le « contenu dupliqué ».</div>
    </div>

    <div class="na-field">
      <label>Meta title (SEO)</label>
      <input type="text" name="meta_title" maxlength="255" value="<?= h((string)($row['meta_title'] ?? '')) ?>">
    </div>
    <div class="na-field">
      <label>Meta description (SEO)</label>
      <input type="text" name="meta_description" maxlength="320" value="<?= h((string)($row['meta_description'] ?? '')) ?>">
    </div>

    <div class="na-field">
      <label class="na-check"><input type="checkbox" name="actif" value="1" <?= ($row === null || (int)($row['actif'] ?? 1) === 1) ? 'checked' : '' ?>> Page active (visible sur la vitrine)</label>
    </div>

    <div class="na-actions">
      <button type="submit" class="na-btn na-btn-primary">Enregistrer</button>
    </div>
  </form>

  <?php endif; ?>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
