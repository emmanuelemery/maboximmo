<?php
/**
 * creancier_creancier360.php — 360 par CRÉANCIER.
 *
 * Voir si un créancier (SIP, banque, fournisseur… = tiers existant) a plusieurs
 * dossiers chez nous : agrège tous les dossiers où ce tiers intervient comme créancier
 * (creancier_dossier_lien rôle 'creancier*'), filtrés par l'ACL.
 *
 * GET : id=<tiers_id>.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/entity_card.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_login();

if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$base   = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';

$tid = (int)($_GET['id'] ?? 0);
if ($tid <= 0) { http_response_code(400); exit('Paramètre id requis.'); }

$st = $pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''),NULLIF(raison_sociale,''),TRIM(CONCAT_WS(' ',prenom,nom))) AS lib, email, telephone, mobile FROM tiers WHERE id = ?");
$st->execute([$tid]);
$cre = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$creLib = (string)($cre['lib'] ?? ('Créancier #' . $tid));

// Dossiers où ce tiers est créancier (rôle commençant par 'creancier').
$st = $pdo->prepare("SELECT DISTINCT d.* FROM creancier_dossier_lien l
    JOIN creancier_dossier d ON d.id = l.id_dossier
    WHERE l.entity_type='TIERS' AND l.entity_id = ?
      AND (l.role_dossier LIKE 'creancier%' OR l.role_dossier IS NULL)
    ORDER BY FIELD(d.niveau_risque,'rouge','orange','vert'), d.libelle");
$st->execute([$tid]);
$all = $st->fetchAll(PDO::FETCH_ASSOC);

$rows = []; $kReste = 0.0; $kNet = 0.0;
foreach ($all as $d) {
    $u = creancier_urgence_data($pdo, (int)$d['id'], $userId);
    if (!$u['acces']) continue;
    // total net de CE créancier dans le dossier
    $part = 0.0;
    foreach ($u['saisies_par_creancier'] as $c) if ((int)$c['id_creancier'] === $tid) $part += (float)$c['total_net'];
    $kNet += ($part ?: $u['total_net_bloque']); $kReste += $u['reste_du'];
    $rows[] = ['d' => $d, 'u' => $u, 'part' => $part];
}

$riskColor = ['vert' => '#16a34a', 'orange' => '#ea580c', 'rouge' => '#dc2626'];
$statutLbl = ['actif' => 'Actif', 'surveillance' => 'Surveillance', 'clos' => 'Clos'];

$appLayout = true; $pageTitle = 'Créancier · ' . $creLib; $bodyClass = ''; $robots = 'noindex, nofollow';
include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  .mbi-main{ background:#fafbfc; }
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:#fff; border-radius:14px; padding:10px 16px; min-width:120px; box-shadow:4px 4px 10px #d4d7de,-4px -4px 10px #fff; text-align:center; }
  .pk-kpi-val { font-size:22px; font-weight:800; color:#243B5C; } .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; color:#8a8680; margin-top:3px; }
  .cc-contact { font-size:12px; color:#6b6358; }
</style>
<div class="mbi-main">
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span style="color:#8a8680">Créancier ·</span>&nbsp;<span class="active" style="font-size:1.5rem;font-weight:800;"><?= e($creLib) ?></span></nav>
    <div class="topbar-spacer"></div>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>
  <div class="page-head" style="gap:16px;align-items:center;flex-wrap:wrap;">
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= count($rows) ?></div><div class="pk-kpi-lbl">Dossiers</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val"><?= $eur($kNet) ?></div><div class="pk-kpi-lbl">Net (ce créancier)</div></div>
    </div>
    <div class="cc-contact"><?= e(trim((string)($cre['email'] ?? ''))) ?> <?= e(trim((string)($cre['telephone'] ?? $cre['mobile'] ?? ''))) ?></div>
  </div>
  <div class="bl-content">
    <?php if (!$rows): ?>
      <div class="bl-empty"><div class="bl-empty-icon">⚖️</div><h2>Aucun dossier accessible pour ce créancier</h2></div>
    <?php else: ?>
      <?php entity_card_assets(); ?>
      <div class="ec-grid">
        <?php foreach ($rows as $r): $d = $r['d']; $u = $r['u']; $rc = $riskColor[$d['niveau_risque']] ?? '#ea580c';
          $chips = [];
          if ($r['part'] > 0) $chips[] = '⚖️ Net ici <b style="margin-left:3px;">' . $eur($r['part']) . '</b>';
          $chips[] = '💰 Dossier : ' . $eur($u['reste_du']);
          if ($u['butoirs_en_retard']) $chips[] = '<span style="color:#dc2626;font-weight:700;">⏰ ' . count($u['butoirs_en_retard']) . ' retard(s)</span>';
          entity_card([
            'url' => $base . 'creancier_dossier360.php?id_dossier=' . (int)$d['id'],
            'accent' => $rc, 'ref' => (string)$d['code'], 'title' => (string)$d['libelle'],
            'badge' => '<span class="ap-badge" style="background:' . $rc . '22;color:' . $rc . ';">' . e($statutLbl[$d['statut']] ?? $d['statut']) . '</span>',
            'chips' => $chips,
          ]);
        endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
