<?php
declare(strict_types=1);

/**
 * admin/admin_bareme.php
 *
 * Attribution de l'URL de barème public (honoraires) sur les annonces.
 *
 * Contexte ALUR :
 *   L'agence DOIT publier ses tarifs d'honoraires sur son site web, et
 *   fournir dans chaque annonce (balise Ubiflow <url_tarifs_publics>)
 *   un lien direct vers cette page. Cf. arrêté ALUR du 10 janvier 2017.
 *
 * Cette page permet de (re)appliquer en masse l'URL du barème sur :
 *   - toutes les annonces brouillon + diffusée (tout SAUF archivées)
 *   - filtrage optionnel par société / par agence
 *
 * Accès : super-admin (role_id = 1).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = $GLOBALS['pdo'];

// Options sociétés / agences (pour filtre ciblé)
$societes = $pdo->query("SELECT id, raison_sociale FROM societes ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$agences  = $pdo->query("SELECT id, nom, id_societe FROM agences ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$action = (string)($_POST['action'] ?? '');
$url     = trim((string)($_POST['url_tarifs_publics'] ?? ''));
$scope   = (string)($_POST['scope'] ?? 'all');           // all | brouillon | actif
$filtSoc = (int)($_POST['filter_societe'] ?? 0);
$filtAg  = (int)($_POST['filter_agence']  ?? 0);
$flash   = null;

// Validation URL (basique)
function admin_bareme_valid_url(string $u): bool {
    if ($u === '') return false;
    return (bool)filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u);
}

// Compose la WHERE clause selon scope + filtres
function admin_bareme_where(string $scope, int $filtSoc, int $filtAg): array {
    $w = [];
    // Scope : on exclut toujours archivées
    if ($scope === 'brouillon') {
        $w[] = "(a.etat_publication = 'brouillon' OR a.statut = 'brouillon')";
    } elseif ($scope === 'actif') {
        $w[] = "a.etat_publication IN ('diffusee','publiee') OR a.statut IN ('publiee','active','en_ligne')";
    } else {
        // 'all' = brouillon + actif = tout sauf archivée
        $w[] = "(a.etat_publication IS NULL OR a.etat_publication NOT IN ('archivee','archived'))";
    }
    $params = [];
    if ($filtSoc > 0) { $w[] = 'a.id_societe = :fsoc'; $params[':fsoc'] = $filtSoc; }
    if ($filtAg  > 0) { $w[] = 'a.id_agence = :fag';   $params[':fag']  = $filtAg;  }
    return [implode(' AND ', $w), $params];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['preview','apply'], true)) {
    if (function_exists('verify_csrf')) verify_csrf('admin_bareme');

    if (!admin_bareme_valid_url($url)) {
        $flash = ['type' => 'error', 'msg' => '❌ URL invalide. Doit commencer par http(s):// et être valide.'];
    } else {
        [$where, $params] = admin_bareme_where($scope, $filtSoc, $filtAg);

        if ($action === 'apply') {
            try {
                $params[':url'] = $url;
                $stmt = $pdo->prepare("UPDATE annonces a SET a.url_tarifs_publics = :url, a.date_modification = NOW() WHERE {$where}");
                $stmt->execute($params);
                $n = $stmt->rowCount();
                $flash = ['type' => 'success', 'msg' => "✅ URL appliquée sur {$n} annonce(s)."];
            } catch (Throwable $e) {
                $flash = ['type' => 'error', 'msg' => '❌ Erreur SQL : ' . htmlspecialchars($e->getMessage())];
            }
        } else {
            $flash = ['type' => 'success', 'msg' => "👀 Aperçu ci-dessous — aucune modification faite. Saisis puis clique « Appliquer » pour écraser."];
        }
    }
}

// Stats scope courant
$countsByScope = null;
try {
    $countsByScope = $pdo->query("
        SELECT
            SUM(CASE WHEN a.etat_publication IS NULL OR a.etat_publication NOT IN ('archivee','archived') THEN 1 ELSE 0 END) AS tot_actives,
            SUM(CASE WHEN (a.etat_publication = 'brouillon' OR a.statut = 'brouillon') THEN 1 ELSE 0 END) AS tot_brouillon,
            SUM(CASE WHEN a.etat_publication IN ('diffusee','publiee') OR a.statut IN ('publiee','active','en_ligne') THEN 1 ELSE 0 END) AS tot_actif,
            SUM(CASE WHEN a.url_tarifs_publics IS NULL OR a.url_tarifs_publics = '' THEN 1 ELSE 0 END) AS sans_url,
            COUNT(*) AS grand_total
        FROM annonces a
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $countsByScope = null; }

// Aperçu 50 lignes concernées par le scope courant (après POST preview OU chargement initial)
[$whereOn, $paramsOn] = admin_bareme_where($scope, $filtSoc, $filtAg);
$preview = [];
try {
    $q = $pdo->prepare("
        SELECT a.id, a.type_transaction, a.etat_publication, a.statut,
               a.url_tarifs_publics,
               s.raison_sociale AS societe, ag.nom AS agence
        FROM annonces a
        LEFT JOIN societes s  ON s.id  = a.id_societe
        LEFT JOIN agences  ag ON ag.id = a.id_agence
        WHERE {$whereOn}
        ORDER BY a.id DESC
        LIMIT 50
    ");
    $q->execute($paramsOn);
    $preview = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$csrf = function_exists('csrf_token') ? csrf_token('admin_bareme') : '';

$appLayout = true;
$pageTitle = 'Barème honoraires — URL publique';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
  .br-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 20px; }
  .br-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .br-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; line-height: 1.5; }
  .br-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; }
  .br-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .br-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .br-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
  .br-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 16px 0 24px; }
  .br-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; text-align: center; }
  .br-stat-nb { font-size: 24px; font-weight: 700; color: #0f172a; }
  .br-stat-lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
  .br-field { margin-bottom: 14px; }
  .br-field label { display:block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px; }
  .br-input  { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit; box-sizing: border-box; }
  .br-row    { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
  .br-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
  .br-btn { padding: 12px 22px; border-radius: 10px; border: 1px solid transparent; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .br-btn-preview { background: #fff; color: #0369a1; border-color: #0ea5e9; }
  .br-btn-preview:hover { background: #f0f9ff; }
  .br-btn-apply   { background: #16a34a; color: #fff; border-color: #16a34a; }
  .br-btn-apply:hover { background: #15803d; }
  .br-table { width: 100%; border-collapse: collapse; font-size: 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
  .br-table th { background: #f8fafc; padding: 8px 10px; text-align: left; font-weight: 700; color: #475569; border-bottom: 1px solid #e5e7eb; }
  .br-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
  .br-badge { padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
  .br-badge.brouillon { background: #fef3c7; color: #78350f; }
  .br-badge.diffusee  { background: #dcfce7; color: #166534; }
  .br-badge.archivee  { background: #f3f4f6; color: #64748b; }
  .br-url-ok   { color: #16a34a; font-weight: 600; font-family: monospace; font-size: 11px; }
  .br-url-miss { color: #dc2626; font-style: italic; }
</style>

<div class="br-wrap">
  <h1>📜 Barème honoraires — URL publique (ALUR)</h1>
  <p class="sub">
    Obligation ALUR (arrêté 10/01/2017) : chaque annonce doit contenir un lien
    vers la page tarifaire publique de l'agence (balise Ubiflow <code>url_tarifs_publics</code>).
    Utilise cette page pour attribuer l'URL en masse sur toutes les annonces
    <strong>brouillon</strong> et/ou <strong>actives diffusées</strong>.
  </p>

  <?php if ($flash): ?>
    <div class="br-flash <?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if ($countsByScope): ?>
    <div class="br-stats">
      <div class="br-stat">
        <div class="br-stat-nb"><?= (int)$countsByScope['grand_total'] ?></div>
        <div class="br-stat-lbl">Total annonces</div>
      </div>
      <div class="br-stat">
        <div class="br-stat-nb" style="color:#0369a1;"><?= (int)$countsByScope['tot_brouillon'] ?></div>
        <div class="br-stat-lbl">En brouillon</div>
      </div>
      <div class="br-stat">
        <div class="br-stat-nb" style="color:#16a34a;"><?= (int)$countsByScope['tot_actif'] ?></div>
        <div class="br-stat-lbl">Diffusées / actives</div>
      </div>
      <div class="br-stat">
        <div class="br-stat-nb" style="color:<?= (int)$countsByScope['sans_url'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= (int)$countsByScope['sans_url'] ?></div>
        <div class="br-stat-lbl">Sans URL barème</div>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="br-card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="br-field">
      <label>URL publique du barème (obligatoire ALUR)</label>
      <input type="url" name="url_tarifs_publics" class="br-input"
             placeholder="https://maboximmo.fr/bareme-honoraires-agence-xxx"
             value="<?= htmlspecialchars($url) ?>"
             required>
    </div>

    <div class="br-row">
      <div class="br-field">
        <label>Scope</label>
        <select name="scope" class="br-input">
          <option value="all"       <?= $scope === 'all'       ? 'selected' : '' ?>>Toutes (brouillon + actif) sauf archivées</option>
          <option value="brouillon" <?= $scope === 'brouillon' ? 'selected' : '' ?>>Brouillon uniquement</option>
          <option value="actif"     <?= $scope === 'actif'     ? 'selected' : '' ?>>Actives / diffusées uniquement</option>
        </select>
      </div>
      <div class="br-field">
        <label>Filtre société (optionnel)</label>
        <select name="filter_societe" class="br-input">
          <option value="0">— Toutes les sociétés —</option>
          <?php foreach ($societes as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $filtSoc === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['raison_sociale']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="br-field">
        <label>Filtre agence (optionnel)</label>
        <select name="filter_agence" class="br-input">
          <option value="0">— Toutes les agences —</option>
          <?php foreach ($agences as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>" <?= $filtAg === (int)$ag['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$ag['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="br-actions">
      <button type="submit" name="action" value="preview" class="br-btn br-btn-preview">👀 Aperçu</button>
      <button type="submit" name="action" value="apply" class="br-btn br-btn-apply"
              onclick="return confirm('⚠️ Appliquer cette URL sur TOUTES les annonces correspondant au scope ?\n\nLes URLs existantes seront ÉCRASÉES.');">
        ✅ Appliquer (écraser URL existante)
      </button>
    </div>
  </form>

  <?php if (!empty($preview)): ?>
    <h3 style="font-size: 14px; margin: 20px 0 10px; color: #0f172a;">Aperçu 50 dernières annonces du scope sélectionné</h3>
    <div style="overflow-x:auto;">
      <table class="br-table">
        <thead>
          <tr>
            <th>#</th><th>Type</th><th>État pub.</th><th>Statut</th>
            <th>Société</th><th>Agence</th><th>URL actuelle</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview as $r):
            $ep = (string)($r['etat_publication'] ?? '');
            $badge = match ($ep) {
                'diffusee','publiee' => 'diffusee',
                'archivee','archived' => 'archivee',
                default => 'brouillon',
            };
            $hasUrl = !empty($r['url_tarifs_publics']);
          ?>
            <tr>
              <td><code>#<?= (int)$r['id'] ?></code></td>
              <td><?= htmlspecialchars((string)($r['type_transaction'] ?? '—')) ?></td>
              <td><span class="br-badge <?= $badge ?>"><?= htmlspecialchars($ep ?: '—') ?></span></td>
              <td><?= htmlspecialchars((string)($r['statut'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string)($r['societe'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string)($r['agence']  ?? '—')) ?></td>
              <td>
                <?php if ($hasUrl): ?>
                  <span class="br-url-ok">✓ <?= htmlspecialchars((string)$r['url_tarifs_publics']) ?></span>
                <?php else: ?>
                  <span class="br-url-miss">(aucune)</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div style="text-align:center; padding:40px; background:#f8fafc; border-radius:10px; color:#64748b;">
      Aucune annonce ne correspond au scope / filtre actuel.
    </div>
  <?php endif; ?>
</div>

<?php
if (file_exists(__DIR__ . '/../inc/footer.php')) {
    require_once __DIR__ . '/../inc/footer.php';
}
