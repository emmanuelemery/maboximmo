<?php
declare(strict_types=1);
/**
 * admin/audit_phase1_etape0.php — Audit Phase 1 / Étape 0 (LECTURE SEULE).
 *
 * Calcule sur les DONNÉES RÉELLES de l'environnement courant (à ouvrir sur PROD)
 * tous les comptages de l'audit « Bien unique + Missions ». N'écrit RIEN.
 *
 * Accès : admin / super admin uniquement.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Exécute un COUNT/scalaire en sécurité (retourne '—' si la table/colonne manque). */
function q1(PDO $pdo, string $sql): string {
    try { $v = $pdo->query($sql)->fetchColumn(); return $v === false ? '0' : (string)$v; }
    catch (Throwable $e) { return 'ERR: ' . $e->getMessage(); }
}

// ── Comptages BDD (réels) ───────────────────────────────────────────────
$counts = [
    '1 · Biens (total)'                          => q1($pdo, "SELECT COUNT(*) FROM biens"),
    '2 · Mandats Vente'                          => q1($pdo, "SELECT COUNT(*) FROM mandats WHERE type_mandat='vente'"),
    '3 · Dossiers de vente'                      => q1($pdo, "SELECT COUNT(*) FROM dossier_vente"),
    '4 · Mandats Vente SANS dossier_vente'       => q1($pdo, "SELECT COUNT(*) FROM mandats m WHERE m.type_mandat='vente' AND NOT EXISTS (SELECT 1 FROM dossier_vente dv WHERE dv.id_bien=m.id_bien)"),
    '5 · Dossiers_vente SANS mandat Vente'       => q1($pdo, "SELECT COUNT(*) FROM dossier_vente dv WHERE NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=dv.id_bien AND m.type_mandat='vente')"),
    '6 · Biens Gestion ET Vente (via mandats)'   => q1($pdo, "SELECT COUNT(DISTINCT g.id_bien) FROM mandats g JOIN mandats v ON v.id_bien=g.id_bien WHERE g.type_mandat='gestion' AND v.type_mandat='vente'"),
    "6b · Biens mandat Gestion + dossier_vente"  => q1($pdo, "SELECT COUNT(DISTINCT m.id_bien) FROM mandats m JOIN dossier_vente dv ON dv.id_bien=m.id_bien WHERE m.type_mandat='gestion'"),
    '7 · Biens avec plusieurs mandats'           => q1($pdo, "SELECT COUNT(*) FROM (SELECT id_bien FROM mandats GROUP BY id_bien HAVING COUNT(*)>1) x"),
];

// ── Doublons factuels loyers / prix (point 8) ───────────────────────────
$loyers = [
    'Biens avec loyer ESTIMÉ (loyer_hc/potentiel/estimation_agence_location)' =>
        q1($pdo, "SELECT COUNT(*) FROM biens WHERE COALESCE(loyer_hc,loyer_potentiel,estimation_agence_location,0)>0"),
    '…dont avec AUSSI un bail actif (loyer contractuel)' =>
        q1($pdo, "SELECT COUNT(DISTINCT b.id) FROM biens b JOIN bien_baux bb ON bb.id_bien=b.id AND bb.statut='actif' AND bb.loyer_mensuel_hc>0 WHERE COALESCE(b.loyer_hc,b.loyer_potentiel,b.estimation_agence_location,0)>0"),
    'Baux actifs avec loyer contractuel'        => q1($pdo, "SELECT COUNT(*) FROM bien_baux WHERE statut='actif' AND loyer_mensuel_hc>0"),
    'mandats.loyer_mandat renseigné'            => q1($pdo, "SELECT COUNT(*) FROM mandats WHERE loyer_mandat>0"),
    'Biens avec prix_final_vente (contractuel sur le BIEN)' => q1($pdo, "SELECT COUNT(*) FROM biens WHERE prix_final_vente>0"),
];

// ── Dossiers SANS mandat Vente : répartition par étape + statut (décision suppression) ──
$dossSansMandat = [];
try {
    $dossSansMandat = $pdo->query("
        SELECT dv.etape, dv.statut, COUNT(*) AS nb
        FROM dossier_vente dv
        WHERE NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=dv.id_bien AND m.type_mandat='vente')
        GROUP BY dv.etape, dv.statut
        ORDER BY FIELD(dv.etape,'estimation','mandat','commercialisation','offre','compromis','acte','solde','sans_suite','perdu'), dv.statut
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $dossSansMandat = [['etape'=>'ERR','statut'=>$e->getMessage(),'nb'=>'']]; }

// ── Point 9 : usages de biens.type_commercialisation (scan code, live) ──
$tcFiles = [];
$root = realpath(__DIR__ . '/..');
$rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $f->getPathname());
    if (preg_match('#/(\.git|_archive|backup|inc \(2\))/#', $path)) continue;
    if (preg_match('#_ex\d+\.php$#', $path)) continue;
    if (strpos($path, '/migrations/') !== false) continue;
    $content = @file_get_contents($f->getPathname());
    if ($content !== false && strpos($content, 'type_commercialisation') !== false) {
        $tcFiles[] = ltrim(str_replace($root, '', $path), '/');
    }
}
sort($tcFiles);

$pageTitle = 'Audit Phase 1 — Étape 0';
$appLayout = true;
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .au-wrap{max-width:980px;margin:0 auto;padding:20px}
  .au-wrap h1{font-size:22px;color:#0f172a;margin:0 0 4px}
  .au-sub{color:#64748b;font-size:13px;margin:0 0 18px}
  .au-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;margin-bottom:16px}
  .au-card h2{font-size:14px;color:#334155;margin:0 0 10px;text-transform:uppercase;letter-spacing:.04em}
  table.au{width:100%;border-collapse:collapse;font-size:13px}
  table.au td{padding:7px 8px;border-bottom:1px solid #f1f5f9}
  table.au td:last-child{text-align:right;font-weight:700;font-family:'DM Mono',monospace;color:#0f172a}
  .au-warn td:last-child{color:#dc2626}
  .au-files{font-family:'DM Mono',monospace;font-size:11.5px;color:#475569;columns:2;column-gap:24px}
  .au-back{display:inline-flex;gap:6px;color:#4f46e5;text-decoration:none;font-size:13px;font-weight:600;margin-bottom:14px}
</style>
<div class="au-wrap">
  <a class="au-back" href="<?= h(function_exists('app_url')?app_url('/agency_dashboard.php'):'/agency_dashboard.php') ?>">← Retour à Ma Box Agency</a>
  <h1>🧭 Audit Phase 1 — Étape 0</h1>
  <p class="au-sub">Lecture seule, sur les <strong>données réelles de cet environnement</strong>. Aucune écriture.</p>

  <div class="au-card">
    <h2>Comptages</h2>
    <table class="au">
      <?php foreach ($counts as $k=>$v): $w = (in_array(substr($k,0,1),['4','5']) && $v!=='0' && is_numeric($v)); ?>
      <tr class="<?= $w?'au-warn':'' ?>"><td><?= h($k) ?></td><td><?= h($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="au-card">
    <h2>Doublons factuels — loyers / prix (point 8)</h2>
    <table class="au">
      <?php foreach ($loyers as $k=>$v): ?>
      <tr><td><?= h($k) ?></td><td><?= h($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="au-card">
    <h2>Dossiers de vente SANS mandat Vente — par étape / statut</h2>
    <p style="font-size:12px;color:#64748b;margin:0 0 8px">
      AVANT toute suppression : <code>estimation</code> / <code>sans_suite</code> / <code>perdu</code> = candidats à clôturer ;
      <code>commercialisation</code> / <code>offre</code> / <code>compromis</code> / <code>acte</code> = ventes ACTIVES, à GARDER (et à formaliser avec un mandat).
    </p>
    <table class="au">
      <tr style="font-size:11px;color:#94a3b8"><td>Étape</td><td style="text-align:left">Statut</td><td>Nb</td></tr>
      <?php foreach ($dossSansMandat as $r):
          $actif = in_array($r['etape'], ['commercialisation','offre','compromis','acte','solde'], true); ?>
      <tr class="<?= $actif?'au-warn':'' ?>"><td><?= h($r['etape']) ?></td><td style="text-align:left;font-weight:400"><?= h($r['statut']) ?></td><td><?= h($r['nb']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="au-card">
    <h2>Point 9 · biens.type_commercialisation — <?= count($tcFiles) ?> fichier(s)</h2>
    <div class="au-files">
      <?php foreach ($tcFiles as $f): ?><div><?= h($f) ?></div><?php endforeach; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
