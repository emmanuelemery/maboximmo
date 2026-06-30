<?php
/**
 * admin/admin_dedup_skeletons_diag.php — DIAGNOSTIC LECTURE SEULE (aucune suppression).
 *
 * Liste les biens "squelettes" issus de l'import CRG :
 *   - AUCUNE donnée rattachée (bien_prix, bien_baux, crg_situations_locataires,
 *     annonces, locataires_statuts) ;
 *   - ET ayant un "jumeau gardien" (même id_immeuble + même numero_lot) qui, lui,
 *     PORTE des données → c'est le vrai bien à conserver.
 *
 * Ne supprime, ne modifie, n'insère RIEN. Sert uniquement à montrer l'impact
 * avant une éventuelle migration de dédup prod.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin (role 1).'); }

// Sous-requêtes "a des données" réutilisées.
$dataExists = "(
    EXISTS (SELECT 1 FROM bien_prix x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM bien_baux x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM crg_situations_locataires x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM annonces x WHERE x.id_bien = %1\$s)
 OR EXISTS (SELECT 1 FROM locataires_statuts x WHERE x.id_bien = %1\$s)
)";
$gardienData = sprintf($dataExists, 'g.id');
$skelData    = sprintf($dataExists, 's.id');

$sql = "
    SELECT s.id            AS skel_id,
           s.reference_bien AS skel_ref,
           s.code_crg       AS skel_code,
           s.numero_lot,
           s.statut_bien,
           COALESCE(NULLIF(s.adresse_1,''), i.adresse_1) AS adresse,
           COALESCE(NULLIF(s.ville,''),     i.ville)     AS ville,
           g.id             AS gardien_id,
           g.reference_bien AS gardien_ref
      FROM biens s
      LEFT JOIN immeubles i ON i.id = s.id_immeuble
      JOIN biens g
        ON g.id <> s.id
       AND (
             g.reference_bien = REPLACE(s.code_crg, '_', '-')   -- gardien réf = code_crg normalisé
          OR (g.code_crg = s.code_crg)                          -- ou même code_crg exact
           )
     WHERE s.code_crg IS NOT NULL AND s.code_crg <> ''
       AND NOT $skelData          -- le squelette n'a AUCUNE donnée
       AND $gardienData           -- le jumeau gardien EN a
     ORDER BY s.code_crg, s.id";

$rows = [];
$err  = null;
try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Stat complémentaire : squelettes vides SANS jumeau (lots vides orphelins, à NE PAS supprimer ici).
$orphanCount = null;
try {
    $orphanCount = (int)$pdo->query("
        SELECT COUNT(*) FROM biens s
         WHERE NOT " . sprintf($dataExists, 's.id') . "
           AND (s.reference_bien IS NULL OR s.reference_bien LIKE '%-EEM')
           AND NOT EXISTS (
                SELECT 1 FROM biens g
                 WHERE g.id_immeuble = s.id_immeuble AND g.numero_lot = s.numero_lot AND g.id <> s.id
           )")->fetchColumn();
} catch (Throwable $e) {}

// ── Ventilation des 171 biens vides (ref nulle/-EEM, sans jumeau) par rattachement ──
// Un bien "vraiment supprimable" = AUCUN rattachement métier d'aucune sorte.
$breakdown = null;
try {
    $breakdown = $pdo->query("
        SELECT
          COUNT(*) AS total,
          SUM(EXISTS(SELECT 1 FROM dossier_vente dv WHERE dv.id_bien=s.id))        AS avec_dossier_vente,
          SUM(EXISTS(SELECT 1 FROM mandats m       WHERE m.id_bien=s.id AND m.statut='actif')) AS avec_mandat_actif,
          SUM(EXISTS(SELECT 1 FROM ged_documents g WHERE g.id_bien=s.id))          AS avec_ged,
          SUM(EXISTS(SELECT 1 FROM biens_photos ph WHERE ph.id_bien=s.id))         AS avec_photos,
          SUM(s.reference_bien LIKE '%-EEM')                                       AS ref_eem,
          SUM(s.reference_bien IS NULL)                                            AS ref_nulle
        FROM biens s
        WHERE NOT " . sprintf($dataExists, 's.id') . "
          AND (s.reference_bien IS NULL OR s.reference_bien LIKE '%-EEM')
          AND NOT EXISTS (
               SELECT 1 FROM biens g
                WHERE g.id_immeuble=s.id_immeuble AND g.numero_lot=s.numero_lot AND g.id<>s.id)
    ")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $breakdown = ['error' => $e->getMessage()]; }

// Compte des biens vides 100% orphelins (AUCUN rattachement, même secondaire) = vrai candidat suppression.
$trulyDeletable = null;
try {
    $trulyDeletable = (int)$pdo->query("
        SELECT COUNT(*) FROM biens s
         WHERE NOT " . sprintf($dataExists, 's.id') . "
           AND (s.reference_bien IS NULL OR s.reference_bien LIKE '%-EEM')
           AND NOT EXISTS (SELECT 1 FROM biens g WHERE g.id_immeuble=s.id_immeuble AND g.numero_lot=s.numero_lot AND g.id<>s.id)
           AND NOT EXISTS (SELECT 1 FROM dossier_vente dv WHERE dv.id_bien=s.id)
           AND NOT EXISTS (SELECT 1 FROM mandats m       WHERE m.id_bien=s.id AND m.statut='actif')
           AND NOT EXISTS (SELECT 1 FROM ged_documents g2 WHERE g2.id_bien=s.id)
           AND NOT EXISTS (SELECT 1 FROM biens_photos ph WHERE ph.id_bien=s.id)
    ")->fetchColumn();
} catch (Throwable $e) {}

// ── Affinage : ne considérer comme "mandat bloquant" que les mandats RÉELS
//    (numéro ne commençant pas par AUTO-). Les AUTO-G-CRG- sont du bruit auto-généré. ──
$base = "
    FROM biens s
   WHERE NOT " . sprintf($dataExists, 's.id') . "
     AND (s.reference_bien IS NULL OR s.reference_bien LIKE '%-EEM')
     AND NOT EXISTS (SELECT 1 FROM biens g WHERE g.id_immeuble=s.id_immeuble AND g.numero_lot=s.numero_lot AND g.id<>s.id)
     AND NOT EXISTS (SELECT 1 FROM dossier_vente dv WHERE dv.id_bien=s.id)
     AND NOT EXISTS (SELECT 1 FROM ged_documents g2 WHERE g2.id_bien=s.id)
     AND NOT EXISTS (SELECT 1 FROM biens_photos ph WHERE ph.id_bien=s.id)
     AND NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=s.id AND m.statut='actif'
                       AND m.numero_mandat NOT LIKE 'AUTO-%')  -- seul un VRAI mandat protège
";
$deletableIgnoringAuto = null; $sample = [];
try {
    $deletableIgnoringAuto = (int)$pdo->query("SELECT COUNT(*) $base")->fetchColumn();
    $sample = $pdo->query("
        SELECT s.id, s.reference_bien, s.code_crg, s.numero_lot, s.statut_bien,
               COALESCE(NULLIF(s.adresse_1,''), i.adresse_1) AS adresse,
               COALESCE(NULLIF(s.ville,''), i.ville) AS ville,
               (SELECT m.numero_mandat FROM mandats m WHERE m.id_bien=s.id AND m.statut='actif' LIMIT 1) AS mandat
          FROM biens s LEFT JOIN immeubles i ON i.id=s.id_immeuble
         WHERE s.id IN (SELECT s.id $base)
         ORDER BY s.id LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Diag dédup squelettes — Admin</title>
<style>
body{font-family:system-ui,Segoe UI,sans-serif;padding:26px;background:#f7f4ef;max-width:1200px;margin:0 auto;color:#1f2937;}
h1{font-size:21px;} .muted{color:#6b7280;font-size:13px;}
.kpi{display:inline-block;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;margin:8px 12px 8px 0;}
.kpi b{font-size:26px;display:block;color:#b45309;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;font-size:12.5px;margin-top:14px;}
th,td{padding:8px 10px;border-bottom:1px solid #f0ece6;text-align:left;}
th{background:#f3f4f6;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;}
.ref{font-family:monospace;font-weight:700;color:#4338ca;}
.warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin:14px 0;font-size:13px;}
.ok{background:#ecfdf5;border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;margin:14px 0;font-size:13px;}
code{background:#eef2ff;padding:1px 5px;border-radius:4px;}
</style></head><body>

<h1>🔎 Diagnostic dédup squelettes CRG <span class="muted">(lecture seule — rien n'est supprimé)</span></h1>

<?php if ($err): ?>
  <div class="warn"><strong>Erreur SQL :</strong> <?= htmlspecialchars($err) ?><br>
  (Probable colonne/table absente sur cette base — transmets ce message.)</div>
<?php else: ?>

<div>
  <div class="kpi">Squelettes vides <u>avec jumeau gardien</u><b><?= count($rows) ?></b><span class="muted">supprimables en sécurité</span></div>
  <?php if ($orphanCount !== null): ?>
  <div class="kpi">Biens vides <u>sans jumeau</u> (ref nulle/-EEM)<b><?= $orphanCount ?></b><span class="muted">lots vides orphelins — à examiner à part</span></div>
  <?php endif; ?>
</div>

<?php if ($breakdown && empty($breakdown['error'])): ?>
  <h2 style="font-size:17px;margin-top:24px;">📊 Ventilation des <?= (int)$breakdown['total'] ?> biens vides (réf. nulle / -EEM, sans jumeau)</h2>
  <table style="max-width:560px;">
    <tbody>
      <tr><td>… avec un <strong>dossier de vente</strong></td><td class="ref"><?= (int)$breakdown['avec_dossier_vente'] ?></td><td class="muted">à NE PAS supprimer</td></tr>
      <tr><td>… avec un <strong>mandat actif</strong></td><td class="ref"><?= (int)$breakdown['avec_mandat_actif'] ?></td><td class="muted">à NE PAS supprimer</td></tr>
      <tr><td>… avec des <strong>documents GED</strong></td><td class="ref"><?= (int)$breakdown['avec_ged'] ?></td><td class="muted">à NE PAS supprimer</td></tr>
      <tr><td>… avec des <strong>photos</strong></td><td class="ref"><?= (int)$breakdown['avec_photos'] ?></td><td class="muted">à NE PAS supprimer</td></tr>
      <tr><td>réf. <code>-EEM</code></td><td class="ref"><?= (int)$breakdown['ref_eem'] ?></td><td></td></tr>
      <tr><td>réf. nulle</td><td class="ref"><?= (int)$breakdown['ref_nulle'] ?></td><td></td></tr>
    </tbody>
  </table>
  <?php if ($trulyDeletable !== null): ?>
    <div class="<?= $trulyDeletable > 0 ? 'warn' : 'ok' ?>" style="margin-top:14px;">
      <strong><?= $trulyDeletable ?></strong> biens sont <strong>100 % orphelins</strong> : aucune donnée,
      <em>et</em> aucun dossier de vente / mandat actif / doc GED / photo. Ce sont les seuls réellement
      supprimables sans rien casser. Les <?= (int)$breakdown['total'] - $trulyDeletable ?> autres sont rattachés à quelque chose et seraient conservés.
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($deletableIgnoringAuto !== null): ?>
  <div class="warn" style="margin-top:14px;">
    En ignorant les mandats <code>AUTO-G-CRG-</code> (bruit auto-généré) et en protégeant tout bien
    ayant un <strong>vrai</strong> mandat / dossier de vente / doc GED / photo :
    <strong style="font-size:18px;"><?= $deletableIgnoringAuto ?></strong> biens deviennent supprimables.
    Échantillon ci-dessous — vérifie qu'ils te semblent bien être des coquilles vides.
  </div>
  <?php if ($sample): ?>
  <table style="margin-top:8px;">
    <thead><tr><th>#id</th><th>réf</th><th>code_crg</th><th>lot</th><th>statut</th><th>adresse</th><th>mandat</th></tr></thead>
    <tbody>
    <?php foreach ($sample as $r): ?>
      <tr>
        <td>#<?= (int)$r['id'] ?></td>
        <td class="ref"><?= htmlspecialchars((string)($r['reference_bien'] ?? '') ?: '—') ?></td>
        <td><code><?= htmlspecialchars((string)($r['code_crg'] ?? '')) ?></code></td>
        <td><?= htmlspecialchars((string)($r['numero_lot'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($r['statut_bien'] ?? '')) ?></td>
        <td><?= htmlspecialchars(trim((string)($r['adresse'] ?? '') . ' ' . (string)($r['ville'] ?? ''))) ?></td>
        <td class="muted"><?= htmlspecialchars((string)($r['mandat'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted">(40 premiers max affichés)</p>
  <?php endif; ?>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="ok">✅ Aucun squelette dupliqué « avec jumeau » — la dédup par fusion n'est pas le bon levier ici. Voir la ventilation ci-dessus.</div>
<?php else: ?>
  <div class="warn">Ces <strong><?= count($rows) ?></strong> biens sont des <strong>squelettes strictement vides</strong>
  (aucun prix / bail / CRG / annonce / statut locataire) qui <strong>doublonnent un vrai bien</strong> (gardien) au même
  immeuble + lot. Ce sont eux qui polluent tes listes. Aucune suppression n'a été faite — c'est juste l'aperçu.</div>

  <table>
    <thead><tr>
      <th>Bien squelette</th><th>code_crg</th><th>Lot</th><th>Statut</th>
      <th>Adresse</th><th>→ Gardien conservé</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="ref">#<?= (int)$r['skel_id'] ?> <?= htmlspecialchars((string)($r['skel_ref'] ?? '') ?: '(sans réf)') ?></td>
        <td><code><?= htmlspecialchars((string)($r['skel_code'] ?? '')) ?></code></td>
        <td><?= htmlspecialchars((string)($r['numero_lot'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($r['statut_bien'] ?? '')) ?></td>
        <td><?= htmlspecialchars(trim((string)($r['adresse'] ?? '') . ' ' . (string)($r['ville'] ?? ''))) ?></td>
        <td class="ref">#<?= (int)$r['gardien_id'] ?> <?= htmlspecialchars((string)($r['gardien_ref'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted" style="margin-top:16px;">
    Si cette liste te convient, je prépare la migration de suppression prod avec <strong>exactement</strong> ces
    garde-fous (suppression uniquement d'un squelette vide ayant un gardien). Tu valides avant exécution.
  </p>
<?php endif; ?>

<?php endif; ?>
</body></html>
