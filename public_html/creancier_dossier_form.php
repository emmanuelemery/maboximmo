<?php
/**
 * creancier_dossier_form.php — Création d'un dossier CRÉANCIERS (manager).
 *
 * GET  : affiche le formulaire.
 * POST : crée le dossier + octroie l'ACL « pilote » au créateur + redirige vers le cockpit.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); exit('Accès réservé aux managers.'); }

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$err = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('creancier_dossier_form');
    $libelle = trim((string)($_POST['libelle'] ?? ''));
    $code    = strtoupper(trim((string)($_POST['code'] ?? '')));
    $risque  = in_array($_POST['niveau_risque'] ?? '', ['vert','orange','rouge'], true) ? $_POST['niveau_risque'] : 'orange';
    $statut  = in_array($_POST['statut'] ?? '', ['actif','surveillance','clos'], true) ? $_POST['statut'] : 'surveillance';
    $synth   = trim((string)($_POST['synthese'] ?? ''));
    $numAdv  = trim((string)($_POST['numero_dossier_adverse'] ?? ''));

    if ($libelle === '') {
        $err = 'Le libellé est obligatoire.';
    } else {
        // Code auto si absent + unicité.
        if ($code === '') {
            $base = preg_replace('/[^A-Z0-9]/', '', strtoupper(function_exists('iconv') ? (iconv('UTF-8','ASCII//TRANSLIT',$libelle) ?: $libelle) : $libelle));
            $code = substr($base ?: 'DOSSIER', 0, 8) ?: 'DOSSIER';
        }
        $stC = $pdo->prepare("SELECT 1 FROM creancier_dossier WHERE code = ? LIMIT 1");
        $b = $code; $i = 1;
        while (true) { $stC->execute([$code]); if (!$stC->fetchColumn()) break; $code = substr($b,0,6).$i; $i++; }

        // Tenant du créateur.
        $stU = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ?");
        $stU->execute([$userId]);
        $u = $stU->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO creancier_dossier (code, libelle, statut, niveau_risque, synthese, numero_dossier_adverse, id_societe, id_agence, created_by)
                                  VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->execute([$code, mb_substr($libelle,0,190), $statut, $risque, $synth ?: null, $numAdv ?: null,
                           $u['id_societe'] ?? null, $u['id_agence'] ?? null, $userId]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO creancier_dossier_acces (id_dossier, id_user, niveau, created_by) VALUES (?,?, 'pilote', ?)")
                ->execute([$id, $userId, $userId]);
            $pdo->commit();
            header('Location: ' . (function_exists('app_url') ? app_url('/creancier_dossier360.php') : 'creancier_dossier360.php') . '?id_dossier=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

$layout_title   = 'Nouveau dossier créancier';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';
$layout_extra_css = <<<'CSS'
<style>
.cf-wrap { padding:18px; max-width:680px; }
.cf-card { background:#fff; border:1px solid #e6e1d8; border-radius:12px; padding:20px 22px; }
.cf-row { margin-bottom:14px; }
.cf-row label { display:block; font-size:12px; color:#6b6358; font-weight:600; margin-bottom:5px; }
.cf-row input, .cf-row select, .cf-row textarea { width:100%; padding:9px 11px; border:1px solid #d8d2c8; border-radius:8px; font-size:14px; font-family:inherit; box-sizing:border-box; }
.cf-row textarea { min-height:70px; resize:vertical; }
.cf-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.cf-err { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:8px; padding:10px 12px; font-size:13px; margin-bottom:14px; }
.cf-actions { margin-top:8px; display:flex; gap:10px; }
</style>
CSS;

ob_start();
?>
<div class="cf-wrap">
  <div class="cf-card">
    <?php if ($err): ?><div class="cf-err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field('creancier_dossier_form') ?>
      <div class="cf-row"><label>Libellé du dossier (débiteur) *</label>
        <input type="text" name="libelle" required value="<?= h($_POST['libelle'] ?? '') ?>" placeholder="Ex. Groupe SIR"></div>
      <div class="cf-grid">
        <div class="cf-row"><label>Code (auto si vide)</label>
          <input type="text" name="code" value="<?= h($_POST['code'] ?? '') ?>" placeholder="SIR"></div>
        <div class="cf-row"><label>N° dossier adverse</label>
          <input type="text" name="numero_dossier_adverse" value="<?= h($_POST['numero_dossier_adverse'] ?? '') ?>"></div>
        <div class="cf-row"><label>Statut</label>
          <select name="statut">
            <option value="surveillance">Surveillance</option>
            <option value="actif">Actif</option>
            <option value="clos">Clos</option>
          </select></div>
        <div class="cf-row"><label>Niveau de risque</label>
          <select name="niveau_risque">
            <option value="orange">Orange</option>
            <option value="rouge">Rouge</option>
            <option value="vert">Vert</option>
          </select></div>
      </div>
      <div class="cf-row"><label>Synthèse</label>
        <textarea name="synthese" placeholder="Contexte du dossier en quelques lignes…"><?= h($_POST['synthese'] ?? '') ?></textarea></div>
      <div class="cf-actions">
        <button type="submit" class="ph-btn primary">Créer le dossier</button>
        <a href="<?= h(function_exists('app_url') ? app_url('/creancier_liste.php') : 'creancier_liste.php') ?>" class="ph-btn">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
