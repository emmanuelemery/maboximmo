<?php
declare(strict_types=1);

/**
 * admin/admin_honoraires_recalc.php
 *
 * Rattrapage des honoraires locataire (location + bail, EDL) sur les annonces
 * existantes. Conçu pour être joué UNE FOIS après migration initiale, puis
 * périodiquement si nouveaux biens diffusés sans recalc auto (import legacy, etc.).
 *
 * Règles appliquées :
 *   - Scope : annonces location/saisonnier actives (statut publiee/active/en_ligne)
 *     ET visibles portails (visible_portails = 1)
 *   - Uniquement pour les biens d'habitation — exclut parking, garage, stationnement,
 *     box, terrain, commerce, local, bureau, entrepot, etc.
 *   - ÉCRASE honoraires_location_bail + honoraires_etat_des_lieux avec
 *     surface × plafond ALUR selon zone tendue du bien
 *   - Recalcule loyer_cc = loyer HC de référence + charges
 *   - Remplit depot_garantie si vide (= loyer HC de référence)
 *   - Pré-remplit biens.zone_tendue vide depuis base_zones_tendues
 *
 * Accès : super-admin (role_id = 1) uniquement.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = $GLOBALS['pdo'];

// Types de bien exclus du rattrapage (pas d'habitation → pas de plafond ALUR locatif)
const HONO_EXCLUDED_TYPES = [
    'parking','stationnement','garage','box','terrain','terrain_agricole',
    'commerce','local_commercial','bureau','bureaux','entrepot','atelier',
    'local_activite','fonds_commerce','droit_bail','cave','immeuble',
];

function hono_excluded_sql(): string
{
    $list = "'" . implode("','", array_map('addslashes', HONO_EXCLUDED_TYPES)) . "'";
    return "tb.code NOT IN ({$list})";
}

// Clause WHERE — scope configurable via $_POST['scope']
//   all       = toutes annonces non archivées (brouillon + diffusées)  [DÉFAUT]
//   diffusees = uniquement visibles portails (publiee/active/en_ligne)
//   brouillon = uniquement brouillon
function hono_where_clause(string $scope = 'all'): string
{
    $base = "a.type_transaction IN ('location','saisonnier','location_annuelle','location_saisonniere')
      AND " . hono_excluded_sql() . "
      AND COALESCE(b.surface_habitable, b.surface_totale, 0) > 0";
    if ($scope === 'diffusees') {
        return "a.visible_portails = 1
          AND a.statut IN ('publiee','active','en_ligne')
          AND {$base}";
    }
    if ($scope === 'brouillon') {
        return "(a.etat_publication = 'brouillon' OR a.statut = 'brouillon')
          AND {$base}";
    }
    // 'all' = tout sauf archivées
    return "(a.etat_publication IS NULL OR a.etat_publication NOT IN ('archivee','archived'))
      AND {$base}";
}

$action = (string)($_POST['action'] ?? '');
$scope  = (string)($_POST['scope']  ?? 'all');
if (!in_array($scope, ['all','diffusees','brouillon'], true)) $scope = 'all';
$flash = null;
$counts = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['preview', 'apply'], true)) {
    if (function_exists('verify_csrf')) verify_csrf('admin_honoraires_recalc');

    // ÉTAPE 1 (toujours) : remplit zone_tendue manquant sur biens
    $zoneFilled = 0;
    try {
        $res = $pdo->exec("
            UPDATE `biens` b
            LEFT JOIN `immeubles` i ON i.id = b.id_immeuble
            JOIN `base_zones_tendues` z
                ON z.code_postal = COALESCE(i.code_postal, b.code_postal)
            SET b.zone_tendue = z.zone_tendue
            WHERE (b.zone_tendue IS NULL OR b.zone_tendue = '')
        ");
        $zoneFilled = (int)$res;
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => '❌ Erreur UPDATE zone_tendue : ' . htmlspecialchars($e->getMessage())];
    }

    if (!$flash && $action === 'apply') {
        try {
            // 3a. Honoraires (location_bail + EDL)
            $n1 = $pdo->exec("
                UPDATE annonces a
                JOIN biens b       ON b.id = a.id_bien
                JOIN types_bien tb ON tb.id = b.id_type_bien
                JOIN societe_tarifs_honoraires t
                    ON t.id_societe IS NULL
                    AND t.zone_tendue = COALESCE(b.zone_tendue, 'non_tendue')
                    AND t.actif = 1
                SET
                    a.honoraires_location_bail  = ROUND(COALESCE(b.surface_habitable, b.surface_totale, 0) * t.honoraires_location_bail_m2, 2),
                    a.honoraires_etat_des_lieux = ROUND(COALESCE(b.surface_habitable, b.surface_totale, 0) * t.honoraires_edl_m2, 2),
                    a.date_modification = NOW()
                WHERE " . hono_where_clause($scope) . "
            ");
            // 3b. loyer_cc = loyer HC réf + charges
            $n2 = $pdo->exec("
                UPDATE annonces a
                JOIN biens b       ON b.id = a.id_bien
                JOIN types_bien tb ON tb.id = b.id_type_bien
                SET a.loyer_cc = ROUND(
                    IF(COALESCE(a.loyer_reference_majore, 0) > 0,
                        COALESCE(a.loyer_reference_majore, 0) + COALESCE(a.complement_loyer, 0),
                        COALESCE(a.loyer, 0)
                    ) + COALESCE(b.charges_locatives, 0)
                , 2),
                a.date_modification = NOW()
                WHERE " . hono_where_clause($scope) . "
            ");
            // 3c. depot_garantie si vide
            $n3 = $pdo->exec("
                UPDATE annonces a
                JOIN biens b       ON b.id = a.id_bien
                JOIN types_bien tb ON tb.id = b.id_type_bien
                SET a.depot_garantie = ROUND(
                    IF(COALESCE(a.loyer_reference_majore, 0) > 0,
                        COALESCE(a.loyer_reference_majore, 0) + COALESCE(a.complement_loyer, 0),
                        COALESCE(a.loyer, 0)
                    )
                , 2),
                a.date_modification = NOW()
                WHERE " . hono_where_clause($scope) . "
                AND (a.depot_garantie IS NULL OR a.depot_garantie = 0)
            ");
            $flash = [
                'type' => 'success',
                'msg' => "✅ Recalcul terminé — {$n1} annonces honoraires, {$n2} loyer_cc, {$n3} dépôts comblés. Zone auto-remplie sur {$zoneFilled} bien(s).",
            ];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => '❌ Erreur SQL UPDATE : ' . htmlspecialchars($e->getMessage())];
        }
    } elseif (!$flash && $action === 'preview') {
        $flash = [
            'type' => 'success',
            'msg' => "👀 Aperçu ci-dessous (aucune modif faite). Zone auto-remplie sur {$zoneFilled} bien(s).",
        ];
    }
}

// ─── Chargement aperçu + stats (toujours) ───────────────────────────
try {
    $counts = $pdo->query("
        SELECT
            SUM(1) AS total,
            SUM(CASE WHEN a.honoraires_location_bail IS NULL THEN 1 ELSE 0 END) AS loc_null,
            SUM(CASE WHEN a.honoraires_etat_des_lieux IS NULL THEN 1 ELSE 0 END) AS edl_null,
            SUM(CASE WHEN a.depot_garantie IS NULL OR a.depot_garantie = 0 THEN 1 ELSE 0 END) AS depot_null,
            SUM(CASE WHEN a.loyer_cc IS NULL OR a.loyer_cc = 0 THEN 1 ELSE 0 END) AS cc_null
        FROM annonces a
        JOIN biens b       ON b.id = a.id_bien
        JOIN types_bien tb ON tb.id = b.id_type_bien
        WHERE " . hono_where_clause($scope) . "
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $preview = $pdo->query("
        SELECT
            a.id AS annonce_id, b.id AS bien_id,
            tb.code AS type_bien, tb.libelle AS type_libelle,
            COALESCE(b.surface_habitable, b.surface_totale, 0) AS surface,
            COALESCE(b.zone_tendue, 'non_tendue') AS zone,
            t.honoraires_location_bail_m2 AS tarif_loc_m2,
            t.honoraires_edl_m2           AS tarif_edl_m2,
            ROUND(COALESCE(b.surface_habitable, b.surface_totale, 0) * t.honoraires_location_bail_m2, 2) AS nouveau_loc_bail,
            ROUND(COALESCE(b.surface_habitable, b.surface_totale, 0) * t.honoraires_edl_m2, 2)          AS nouveau_edl,
            a.honoraires_location_bail  AS ancien_loc_bail,
            a.honoraires_etat_des_lieux AS ancien_edl,
            a.loyer, a.loyer_reference_majore, a.complement_loyer, b.charges_locatives,
            a.loyer_cc AS ancien_cc,
            a.depot_garantie AS ancien_depot
        FROM annonces a
        JOIN biens b       ON b.id = a.id_bien
        JOIN types_bien tb ON tb.id = b.id_type_bien
        JOIN societe_tarifs_honoraires t
            ON t.id_societe IS NULL
            AND t.zone_tendue = COALESCE(b.zone_tendue, 'non_tendue')
            AND t.actif = 1
        WHERE " . hono_where_clause($scope) . "
        ORDER BY a.id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $counts = null;
    $preview = [];
    $flash = $flash ?? ['type' => 'error', 'msg' => '❌ ' . htmlspecialchars($e->getMessage())];
}

$csrf = function_exists('csrf_token') ? csrf_token('admin_honoraires_recalc') : '';

$pageTitle    = 'Rattrapage honoraires';
$pageSubtitle = 'Super Admin · Compliance ALUR';
require_once __DIR__ . '/../inc/agency_layout_top.php';
?>

<style>
  .hr-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 20px; }
  .hr-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .hr-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; line-height: 1.5; }
  .hr-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; }
  .hr-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .hr-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .hr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 16px 0 24px; }
  .hr-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; text-align: center; }
  .hr-stat-nb { font-size: 24px; font-weight: 700; color: #0f172a; }
  .hr-stat-lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
  .hr-actions { display: flex; gap: 10px; margin: 16px 0 24px; flex-wrap: wrap; }
  .hr-btn { padding: 12px 22px; border-radius: 10px; border: 1px solid transparent; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .hr-btn-preview { background: #fff; color: #0369a1; border-color: #0ea5e9; }
  .hr-btn-preview:hover { background: #f0f9ff; }
  .hr-btn-apply { background: #16a34a; color: #fff; border-color: #16a34a; }
  .hr-btn-apply:hover { background: #15803d; }
  .hr-warn { background: #fefce8; border: 1px solid #f59e0b; padding: 14px 18px; border-radius: 10px; color: #78350f; margin-bottom: 20px; font-size: 13px; }
  .hr-table { width: 100%; border-collapse: collapse; font-size: 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
  .hr-table th { background: #f8fafc; padding: 8px 10px; text-align: left; font-weight: 700; color: #475569; border-bottom: 1px solid #e5e7eb; }
  .hr-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
  .hr-table tr:hover td { background: #f8fafc; }
  .hr-zone { padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; }
  .hr-zone.non_tendue { background: #ecfdf5; color: #065f46; }
  .hr-zone.tendue     { background: #fef3c7; color: #78350f; }
  .hr-zone.tres_tendue{ background: #fee2e2; color: #991b1b; }
  .hr-diff-up   { color: #16a34a; font-weight: 700; }
  .hr-diff-down { color: #dc2626; font-weight: 700; }
  .hr-diff-same { color: #94a3b8; }
  .hr-excluded  { margin: 20px 0; padding: 12px 16px; background: #f8fafc; border-radius: 8px; font-size: 12px; color: #64748b; }
</style>

<div class="hr-wrap">
  <h1>⚖️ Rattrapage honoraires locataire (ALUR)</h1>
  <p class="sub">
    Recalcule et <strong>écrase</strong> les honoraires location+bail et EDL sur les annonces
    <strong>actives + diffusées portails</strong> en appliquant le plafond ALUR (surface × tarif zone tendue).
    Recalcule aussi <code>loyer_cc</code> et remplit <code>depot_garantie</code> si vide.
    <br>
    Habitation uniquement — les parking, garage, terrain, commerce, bureau, etc. sont conservés tels quels.
  </p>

  <?php if ($flash): ?>
    <div class="hr-flash <?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if ($counts && (int)$counts['total'] > 0): ?>
    <div class="hr-stats">
      <div class="hr-stat">
        <div class="hr-stat-nb"><?= (int)$counts['total'] ?></div>
        <div class="hr-stat-lbl">Annonces concernées</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-nb" style="color:<?= (int)$counts['loc_null'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= (int)$counts['loc_null'] ?></div>
        <div class="hr-stat-lbl">Sans location+bail</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-nb" style="color:<?= (int)$counts['edl_null'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= (int)$counts['edl_null'] ?></div>
        <div class="hr-stat-lbl">Sans EDL</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-nb" style="color:<?= (int)$counts['depot_null'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= (int)$counts['depot_null'] ?></div>
        <div class="hr-stat-lbl">Sans dépôt</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-nb" style="color:<?= (int)$counts['cc_null'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= (int)$counts['cc_null'] ?></div>
        <div class="hr-stat-lbl">Sans loyer CC</div>
      </div>
    </div>
  <?php endif; ?>

  <div class="hr-warn">
    ⚠️ <strong>Attention</strong> : "Appliquer" <strong>écrase</strong> les valeurs actuelles
    (même si elles ont été ajustées manuellement). Les agences pourront les rebaisser ensuite dans
    bien_detail.php (Card 1), mais pas les dépasser (plafond ALUR appliqué par le helper).
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <div style="margin-bottom:14px;">
      <label style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px;">Scope (toutes agences, tous biens habitation)</label>
      <select name="scope" class="hr-input" style="padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; min-width:360px;">
        <option value="all"       <?= $scope === 'all'       ? 'selected' : '' ?>>Toutes annonces non archivées (brouillon + diffusées)</option>
        <option value="brouillon" <?= $scope === 'brouillon' ? 'selected' : '' ?>>Brouillon uniquement</option>
        <option value="diffusees" <?= $scope === 'diffusees' ? 'selected' : '' ?>>Diffusées portails uniquement (visible_portails=1)</option>
      </select>
    </div>
    <div class="hr-actions">
      <button type="submit" name="action" value="preview" class="hr-btn hr-btn-preview">👀 Aperçu (ne modifie rien)</button>
      <button type="submit" name="action" value="apply" class="hr-btn hr-btn-apply"
              onclick="return confirm('⚠️ ÉCRASER les honoraires + loyer_cc + dépôt sur toutes les annonces du scope sélectionné ?\n\nCette action est IRRÉVERSIBLE.');">
        ✅ Appliquer (écraser)
      </button>
    </div>
  </form>

  <?php if (!empty($preview)): ?>
    <h3 style="font-size: 14px; margin: 20px 0 10px; color: #0f172a;">Aperçu — 100 premières lignes (sur <?= (int)($counts['total'] ?? 0) ?>)</h3>
    <div style="overflow-x:auto;">
      <table class="hr-table">
        <thead>
          <tr>
            <th>Annonce</th><th>Bien</th><th>Type</th><th>Surface</th><th>Zone</th>
            <th>Tarif loc €/m²</th>
            <th>Nouveau loc+bail</th><th>Ancien loc+bail</th>
            <th>Nouveau EDL</th><th>Ancien EDL</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview as $r):
            $oldLoc = $r['ancien_loc_bail'] === null ? null : (float)$r['ancien_loc_bail'];
            $newLoc = (float)$r['nouveau_loc_bail'];
            $diffClass = $oldLoc === null ? 'hr-diff-up' : ($newLoc > $oldLoc ? 'hr-diff-up' : ($newLoc < $oldLoc ? 'hr-diff-down' : 'hr-diff-same'));
          ?>
            <tr>
              <td><code>#<?= (int)$r['annonce_id'] ?></code></td>
              <td><code>#<?= (int)$r['bien_id'] ?></code></td>
              <td><?= htmlspecialchars((string)($r['type_libelle'] ?: $r['type_bien'])) ?></td>
              <td><?= number_format((float)$r['surface'], 2, ',', ' ') ?> m²</td>
              <td><span class="hr-zone <?= htmlspecialchars((string)$r['zone']) ?>"><?= htmlspecialchars((string)$r['zone']) ?></span></td>
              <td><?= number_format((float)$r['tarif_loc_m2'], 2, ',', ' ') ?> €</td>
              <td class="<?= $diffClass ?>"><strong><?= number_format($newLoc, 2, ',', ' ') ?> €</strong></td>
              <td style="color:#94a3b8;"><?= $oldLoc === null ? '—' : number_format($oldLoc, 2, ',', ' ') . ' €' ?></td>
              <td><strong><?= number_format((float)$r['nouveau_edl'], 2, ',', ' ') ?> €</strong></td>
              <td style="color:#94a3b8;"><?= $r['ancien_edl'] === null ? '—' : number_format((float)$r['ancien_edl'], 2, ',', ' ') . ' €' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div style="text-align:center; padding:40px; background:#f8fafc; border-radius:10px; color:#64748b;">
      Aucune annonce éligible au rattrapage.
    </div>
  <?php endif; ?>

  <div class="hr-excluded">
    🚫 <strong>Types de bien exclus</strong> (pas de plafond ALUR locatif — valeurs conservées telles quelles) :
    <code><?= implode('</code>, <code>', HONO_EXCLUDED_TYPES) ?></code>
  </div>
</div>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
