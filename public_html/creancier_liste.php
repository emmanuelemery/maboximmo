<?php
/**
 * creancier_liste.php — Liste des dossiers CRÉANCIERS accessibles (lecture seule).
 *
 * Point d'entrée du module : n'affiche QUE les dossiers autorisés (ACL
 * creancier_dossier_acces) + filtrage tenant. Super admin = tous. Clic → cockpit.
 * Ces dossiers n'apparaissent dans AUCUNE liste générale (sensibles).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$isSuper = function_exists('is_super_admin') && is_super_admin();

// Tenant de l'utilisateur.
$stU = $pdo->prepare("SELECT id_societe FROM users WHERE id = ? LIMIT 1");
$stU->execute([$userId]);
$idSociete = (int)($stU->fetchColumn() ?: 0);

// Dossiers accessibles : super admin = tous ; sinon tenant + ACL.
if ($isSuper) {
    $st = $pdo->query("SELECT * FROM creancier_dossier ORDER BY FIELD(niveau_risque,'rouge','orange','vert'), libelle");
    $dossiers = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare("
        SELECT d.* FROM creancier_dossier d
        JOIN creancier_dossier_acces a ON a.id_dossier = d.id AND a.id_user = :uid
        WHERE (d.id_societe IS NULL OR d.id_societe = :soc)
        ORDER BY FIELD(d.niveau_risque,'rouge','orange','vert'), d.libelle
    ");
    $st->execute([':uid' => $userId, ':soc' => $idSociete]);
    $dossiers = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Mini-synthèse par dossier (réutilise la couche cockpit).
$rows = [];
foreach ($dossiers as $d) {
    $u = creancier_urgence_data($pdo, (int)$d['id'], $userId);
    if (!$u['acces']) continue;
    $rows[] = ['d' => $d, 'u' => $u];
}

$riskColor = ['vert' => '#16a34a', 'orange' => '#ea580c', 'rouge' => '#dc2626'];
$statutLbl = ['actif' => 'Actif', 'surveillance' => 'Surveillance', 'clos' => 'Clos'];

$layout_title   = 'Créanciers';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = $isSuper || in_array($roleId, [1, 2, 3, 7], true);
if ($isMgr) {
    $_b = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';
    $layout_head_actions = '<a href="' . h($_b . 'creancier_scan.php') . '" class="ph-btn">📄 Scanner un document</a>'
        . '<a href="' . h($_b . 'creancier_dossier_form.php') . '" class="ph-btn primary">+ Nouveau dossier</a>';
}

$layout_extra_css = <<<'CSS'
<style>
.cre-wrap { padding: 18px; }
.cre-list { display:grid; gap:12px; }
.cre-item { display:flex; align-items:center; gap:16px; background:#fff; border:1px solid #e6e1d8; border-left:6px solid var(--risk,#ea580c); border-radius:12px; padding:14px 18px; text-decoration:none; color:inherit; transition:box-shadow .15s, transform .05s; }
.cre-item:hover { box-shadow:0 4px 14px rgba(36,59,92,.10); transform:translateY(-1px); }
.cre-item .ttl { font-family:'Sora',sans-serif; font-size:16px; color:#243B5C; margin:0; }
.cre-tag { font-size:11px; font-weight:600; padding:3px 9px; border-radius:999px; background:#f1ede5; color:#6b6358; margin-left:6px; }
.cre-kpis { margin-left:auto; display:flex; gap:22px; text-align:right; }
.cre-kpi .v { font-family:'Sora',sans-serif; font-weight:700; font-size:15px; }
.cre-kpi .l { font-size:11px; color:#8a8680; text-transform:uppercase; letter-spacing:.03em; }
.cre-retard { color:#dc2626; }
.cre-empty { background:#fff; border:1px dashed #d8d2c8; border-radius:12px; padding:28px; text-align:center; color:#8a8680; }
</style>
CSS;

ob_start();
?>
<div class="cre-wrap">
  <?php if (!$rows): ?>
    <div class="cre-empty">Aucun dossier créancier accessible.<br><small>Les dossiers sont confidentiels : ils n'apparaissent que pour les utilisateurs explicitement habilités.</small></div>
  <?php else: ?>
    <div class="cre-list">
      <?php foreach ($rows as $r): $d = $r['d']; $u = $r['u']; $rc = $riskColor[$d['niveau_risque']] ?? '#ea580c'; ?>
        <a class="cre-item" style="--risk:<?= $rc ?>" href="<?= h((function_exists('app_url') ? app_url('/creancier_dashboard.php') : 'creancier_dashboard.php')) ?>?id_dossier=<?= (int)$d['id'] ?>">
          <div>
            <p class="ttl"><?= h($d['libelle']) ?><span class="cre-tag"><?= h($d['code']) ?></span><span class="cre-tag"><?= h($statutLbl[$d['statut']] ?? $d['statut']) ?></span></p>
          </div>
          <div class="cre-kpis">
            <div class="cre-kpi"><div class="v" style="color:#dc2626"><?= $eur($u['reste_du']) ?></div><div class="l">Reste dû</div></div>
            <div class="cre-kpi"><div class="v"><?= $eur($u['total_net_bloque']) ?></div><div class="l">Net bloqué</div></div>
            <div class="cre-kpi"><div class="v <?= $u['butoirs_en_retard'] ? 'cre-retard' : '' ?>"><?= $u['butoirs_en_retard'] ? count($u['butoirs_en_retard']) . ' retard(s)' : $dfr($u['prochaine_butoir']) ?></div><div class="l"><?= $u['butoirs_en_retard'] ? 'Urgences' : 'Prochaine butoir' ?></div></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
