<?php
/**
 * bailleur_contentieux.php — Contentieux / impayés du bailleur.
 * Liste des locataires en impayé, scopée sur les propriétaires de l'utilisateur
 * (user_proprietaires), à partir du dernier CRG connu par bien+locataire.
 * Accès : super admin OU service bailleur.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403);
    exit('Accès réservé au module Bailleur.');
}

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function fmt_euro_c(float $v): string { return number_format($v, 0, ',', ' ') . ' €'; }

// ── Propriétaires accessibles ──────────────────────────────
if ($isSuperAdmin) {
    $propIds = $pdo->query("SELECT DISTINCT id_proprietaire FROM crg_trimestres WHERE parse_statut='ok'")
                   ->fetchAll(\PDO::FETCH_COLUMN);
} else {
    $st = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
    $st->execute([$userId]);
    $propIds = $st->fetchAll(\PDO::FETCH_COLUMN);
}
$propIds = array_values(array_filter(array_map('intval', $propIds)));

$rows = [];
$totalImpaye = 0.0;
$nbDossiers  = 0;
if (!empty($propIds)) {
    $in = implode(',', $propIds);
    // Dernier CRG connu pour chaque couple (bien, locataire)
    $subCrg = "(
        SELECT ct2.annee,ct2.trimestre FROM crg_trimestres ct2
        JOIN crg_situations_locataires c2 ON c2.id_crg=ct2.id
        WHERE ct2.id_proprietaire=ct.id_proprietaire AND ct2.parse_statut='ok'
          AND c2.id_bien=crg.id_bien AND c2.locataire_nom=crg.locataire_nom
        ORDER BY ct2.annee DESC,ct2.trimestre DESC LIMIT 1
    )";
    $rows = $pdo->query("
        SELECT crg.locataire_nom, crg.total_impaye, crg.loyer_appele,
               CONCAT(ct.annee,' T',ct.trimestre) AS dernier_crg,
               ls.statut AS statut_loc,
               b.reference_bien, b.id AS id_bien,
               i.nom_immeuble, i.adresse_1, i.ville,
               COALESCE(NULLIF(TRIM(p.societe),''), CONCAT(p.prenom,' ',p.nom)) AS prop_nom
        FROM crg_situations_locataires crg
        JOIN crg_trimestres ct ON crg.id_crg=ct.id
        JOIN locataires_statuts ls
             ON ls.locataire_nom=crg.locataire_nom AND ls.id_bien=crg.id_bien
            AND ls.id_proprietaire=ct.id_proprietaire
            AND ls.statut!='irrecoverable' AND ls.archive=0
        LEFT JOIN biens b ON b.id=crg.id_bien
        LEFT JOIN immeubles i ON i.id=b.id_immeuble
        LEFT JOIN proprietaires p ON p.id=ct.id_proprietaire
        WHERE ct.id_proprietaire IN ({$in}) AND ct.parse_statut='ok'
          AND crg.total_impaye>0 AND COALESCE(i.vendu,0)=0
          AND (ct.annee,ct.trimestre)={$subCrg}
        ORDER BY crg.total_impaye DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $r) { $totalImpaye += (float)$r['total_impaye']; $nbDossiers++; }
}

// Libellés de statut locataire
$statutLabels = [
    'actif'        => ['Actif', '#0e7490'],
    'parti'        => ['Parti', '#64748b'],
    'contentieux'  => ['Contentieux', '#dc2626'],
    'procedure'    => ['Procédure', '#b45309'],
    'irrecoverable'=> ['Irrécouvrable', '#7c3aed'],
];

$layout_title   = 'Contentieux — Impayés';
$layout_module  = 'Ma Box Bailleur';
$layout_sidebar = 'sidebar_bailleur_module';

ob_start();
?>
<style>
.ct-kpis { display:flex; gap:16px; margin-bottom:18px; flex-wrap:wrap; }
.ct-kpi { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px; min-width:180px; }
.ct-kpi-k { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:700; }
.ct-kpi-v { font-size:22px; font-weight:800; color:#0f172a; margin-top:4px; }
.ct-kpi-v.red { color:#dc2626; }
.ct-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
.ct-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
.ct-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; color:#0f172a; }
.ct-table tr:last-child td { border-bottom:none; }
.ct-imp { font-weight:800; color:#dc2626; text-align:right; white-space:nowrap; }
.ct-badge { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:700; color:#fff; }
.ct-empty { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:40px; text-align:center; color:#64748b; }
</style>

<h1 style="font-size:20px;font-weight:800;margin-bottom:4px;">⚖️ Contentieux — Impayés</h1>
<p style="color:#64748b;font-size:13px;margin-bottom:18px;">Locataires en impayé sur le dernier CRG connu (biens actifs, hors vendus et irrécouvrables).</p>

<div class="ct-kpis">
  <div class="ct-kpi"><div class="ct-kpi-k">Total impayés</div><div class="ct-kpi-v red"><?= fmt_euro_c($totalImpaye) ?></div></div>
  <div class="ct-kpi"><div class="ct-kpi-k">Dossiers</div><div class="ct-kpi-v"><?= (int)$nbDossiers ?></div></div>
</div>

<?php if (empty($rows)): ?>
  <div class="ct-empty">✅ Aucun impayé recensé sur les propriétaires accessibles.</div>
<?php else: ?>
  <table class="ct-table">
    <thead>
      <tr>
        <th>Locataire</th><th>Bien</th><th>Immeuble</th><th>Propriétaire</th>
        <th>Statut</th><th>Dernier CRG</th><th style="text-align:right;">Impayé</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $st = (string)($r['statut_loc'] ?? '');
        [$stLbl, $stCol] = $statutLabels[$st] ?? [ucfirst($st ?: '—'), '#64748b'];
      ?>
        <tr>
          <td><strong><?= e($r['locataire_nom']) ?></strong></td>
          <td><?= e($r['reference_bien'] ?: '—') ?></td>
          <td><?= e($r['nom_immeuble'] ?: $r['adresse_1'] ?: '—') ?><?= $r['ville'] ? ' · ' . e($r['ville']) : '' ?></td>
          <td><?= e($r['prop_nom']) ?></td>
          <td><span class="ct-badge" style="background:<?= e($stCol) ?>;"><?= e($stLbl) ?></span></td>
          <td><?= e($r['dernier_crg']) ?></td>
          <td class="ct-imp"><?= fmt_euro_c((float)$r['total_impaye']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
