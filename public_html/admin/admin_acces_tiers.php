<?php
declare(strict_types=1);

/**
 * admin/admin_acces_tiers.php — Matrice d'autorisations PAR PERSONNE × MODULE.
 *
 * Identités gérées : 'user' (collaborateurs) et 'tiers' (notaire, créancier,
 * comptable…). Une case cochée = grant de NIVEAU MODULE (page NULL, scope NULL)
 * = accès à TOUTES les pages du module sur tout son périmètre.
 * Les grants restreints (page précise / périmètre groupe SIR / portefeuille)
 * sont signalés "⊙ partiel" et JAMAIS écrasés par cette matrice.
 *
 * Filtrée (953 tiers) : l'enregistrement ne diffère QUE les lignes affichées
 * (hidden rows[]) — aucun droit hors écran n'est touché.
 *
 * Réservé admin / super admin. Doctrine : memory project_acl_grants_cage_acces.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];

// ─── Filtres ─────────────────────────────────────────────────────────
$q       = trim((string)($_GET['q'] ?? ''));
$kind    = (string)($_GET['kind'] ?? 'salaries'); // salaries | users | tiers | with_access
$fSoc    = (int)($_GET['societe'] ?? 0);
$fAge    = (int)($_GET['agence'] ?? 0);
$allowedKinds = ['salaries','users','tiers','with_access'];
if (!in_array($kind, $allowedKinds, true)) $kind = 'salaries';

// ─── Catalogue des modules (colonnes) ────────────────────────────────
$modules = $pdo->query("SELECT code, label, couleur FROM acl_modules WHERE actif = 1 ORDER BY ordre, code")
               ->fetchAll(PDO::FETCH_ASSOC);
$moduleCodes = array_column($modules, 'code');

// ─── Dropdowns société / agence ──────────────────────────────────────
$societes = $pdo->query("SELECT id, nom FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$agences  = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);

// ─── Enregistrement (diff sur les lignes affichées uniquement) ───────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf('admin_acces_tiers');

    $rows    = $_POST['rows']  ?? [];   // ["user:8","tiers:142", ...] = lignes visibles
    $checked = $_POST['grant'] ?? [];   // grant[type][id][module] = '1'
    if (!is_array($rows))    $rows = [];
    if (!is_array($checked)) $checked = [];
    $uidActor = (int)($_SESSION['user_id'] ?? 0);

    // État actuel des grants module pour les seules lignes affichées
    $cur = []; // ["type:id"][module] = true
    $pairs = [];
    foreach ($rows as $r) {
        [$t, $i] = array_pad(explode(':', (string)$r, 2), 2, '');
        if (!in_array($t, ['user','tiers','jeton'], true) || (int)$i <= 0) continue;
        $pairs["$t:".(int)$i] = ['type' => $t, 'id' => (int)$i];
    }
    if ($pairs) {
        $orParts = []; $bind = [];
        foreach ($pairs as $p) { $orParts[] = '(identite_type = ? AND identite_id = ?)'; $bind[] = $p['type']; $bind[] = $p['id']; }
        $st = $pdo->prepare("SELECT identite_type, identite_id, module_code FROM acl_grants
                             WHERE page IS NULL AND scope_type IS NULL AND (".implode(' OR ', $orParts).")");
        $st->execute($bind);
        foreach ($st as $g) { $cur[$g['identite_type'].':'.(int)$g['identite_id']][$g['module_code']] = true; }
    }

    $insSql = $pdo->prepare("INSERT INTO acl_grants
        (identite_type, identite_id, module_code, page, scope_type, scope_id, actif, created_by)
        VALUES (?, ?, ?, NULL, NULL, NULL, 1, ?)");
    $delSql = $pdo->prepare("DELETE FROM acl_grants
        WHERE identite_type = ? AND identite_id = ? AND module_code = ?
          AND page IS NULL AND scope_type IS NULL");

    $nbAdd = 0; $nbDel = 0;
    foreach ($pairs as $key => $p) {
        foreach ($moduleCodes as $m) {
            $want = !empty($checked[$p['type']][$p['id']][$m]);
            $has  = !empty($cur[$key][$m]);
            if ($want && !$has) { $insSql->execute([$p['type'], $p['id'], $m, $uidActor]); $nbAdd++; }
            elseif (!$want && $has) { $delSql->execute([$p['type'], $p['id'], $m]); $nbDel++; }
        }
    }
    $flash = ['type' => 'success', 'msg' => "✅ Autorisations enregistrées — $nbAdd ajout(s), $nbDel retrait(s)."];
}

// ─── Construction de la liste des personnes selon les filtres ────────
$people = []; // chaque ligne : kind, id, nom, meta, role
$likeQ = '%'.$q.'%';

if ($kind === 'tiers' || $kind === 'with_access') {
    // TIERS
    $w = ['t.actif = 1']; $b = [];
    if ($q !== '')  { $w[] = "(t.nom LIKE ? OR t.prenom LIKE ? OR t.raison_sociale LIKE ? OR t.nom_affichage LIKE ? OR t.email LIKE ?)";
                      array_push($b, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ); }
    if ($fSoc > 0)  { $w[] = "t.id_societe = ?"; $b[] = $fSoc; }
    if ($fAge > 0)  { $w[] = "t.id_agence = ?";  $b[] = $fAge; }
    if ($kind === 'with_access') {
        $w[] = "EXISTS (SELECT 1 FROM acl_grants g WHERE g.identite_type='tiers' AND g.identite_id=t.id)";
    }
    $st = $pdo->prepare("SELECT t.id, t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale,
            t.nom_affichage, t.email
        FROM tiers t WHERE ".implode(' AND ', $w)."
        ORDER BY COALESCE(NULLIF(t.nom_affichage,''), t.raison_sociale, t.nom) LIMIT 300");
    $st->execute($b);
    foreach ($st as $t) {
        $nom = trim((string)($t['nom_affichage'] ?: $t['raison_sociale'] ?: trim(($t['prenom']??'').' '.($t['nom']??'')))) ?: ('Tiers #'.$t['id']);
        $people[] = ['kind'=>'tiers','id'=>(int)$t['id'],'nom'=>$nom,
            'role'=> ($t['type_tiers']==='personne_morale'?'Société (tiers)':'Tiers'),
            'meta'=> (string)($t['email'] ?? '')];
    }
}

if ($kind === 'salaries' || $kind === 'users' || $kind === 'with_access') {
    // USERS
    $w = ['u.actif = 1']; $b = [];
    if ($kind === 'salaries') $w[] = 'u.est_salarie = 1';
    if ($q !== '')  { $w[] = "(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)"; array_push($b, $likeQ, $likeQ, $likeQ); }
    if ($fSoc > 0)  { $w[] = "u.id_societe = ?"; $b[] = $fSoc; }
    if ($fAge > 0)  { $w[] = "u.id_agence = ?";  $b[] = $fAge; }
    if ($kind === 'with_access') {
        $w[] = "EXISTS (SELECT 1 FROM acl_grants g WHERE g.identite_type='user' AND g.identite_id=u.id)";
    }
    $st = $pdo->prepare("SELECT u.id, u.nom, u.prenom, u.email, u.id_role, u.est_salarie
        FROM users u WHERE ".implode(' AND ', $w)."
        ORDER BY u.est_salarie DESC, u.nom, u.prenom LIMIT 300");
    $st->execute($b);
    $roleLabels = [1=>'Super admin',2=>'Manager',3=>'Collaborateur',4=>'Syndic',5=>'Propriétaire',
                   6=>'Locataire',7=>'Admin',8=>'Admin régie',9=>'Bailleur',10=>'Investisseur'];
    foreach ($st as $u) {
        $nom = trim(($u['prenom']??'').' '.($u['nom']??'')) ?: ('User #'.$u['id']);
        $role = $roleLabels[(int)$u['id_role']] ?? ('Rôle '.(int)$u['id_role']);
        if (!empty($u['est_salarie'])) $role .= ' · salarié';
        $people[] = ['kind'=>'user','id'=>(int)$u['id'],'nom'=>$nom,'role'=>$role,'meta'=>(string)($u['email'] ?? '')];
    }
}

// ─── État des grants pour les personnes affichées ────────────────────
$grantModule = []; $grantPartial = [];
if ($people) {
    $orParts = []; $bind = [];
    foreach ($people as $p) { $orParts[] = '(identite_type=? AND identite_id=?)'; $bind[] = $p['kind']; $bind[] = $p['id']; }
    $st = $pdo->prepare("SELECT identite_type, identite_id, module_code, page, scope_type
                         FROM acl_grants WHERE ".implode(' OR ', $orParts));
    $st->execute($bind);
    foreach ($st as $g) {
        $k = $g['identite_type'].':'.(int)$g['identite_id']; $m = $g['module_code'];
        if ($g['page'] === null && $g['scope_type'] === null) $grantModule[$k][$m] = true;
        else $grantPartial[$k][$m] = ($grantPartial[$k][$m] ?? 0) + 1;
    }
}

$csrf = csrf_token('admin_acces_tiers');
$appLayout = true;
$pageTitle = 'Accès par tiers / module';
$bodyClass = '';
require_once __DIR__ . '/../inc/header.php';

function acl_qs(array $over): string {
    $base = ['q'=>$_GET['q']??'','kind'=>$_GET['kind']??'salaries','societe'=>$_GET['societe']??0,'agence'=>$_GET['agence']??0];
    return htmlspecialchars('?'.http_build_query(array_merge($base, $over)));
}
$kindTabs = ['salaries'=>'👔 Salariés','users'=>'👥 Tous utilisateurs','tiers'=>'🤝 Tiers','with_access'=>'✅ Avec accès'];
?>

<style>
  .acl-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px; }
  .acl-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .acl-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 16px; }
  .acl-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
  .acl-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .acl-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
  .acl-tabs { display:flex; gap:6px; flex-wrap:wrap; }
  .acl-tab { padding:7px 13px; border-radius:99px; font-size:12px; font-weight:700; text-decoration:none;
       border:1px solid #e2e8f0; color:#475569; background:#fff; }
  .acl-tab.on { background:#243B5C; color:#fff; border-color:#243B5C; }
  .acl-filters input[type=text], .acl-filters select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; }
  .acl-filters input[type=text] { min-width:220px; }
  .acl-fbtn { padding:8px 14px; border-radius:8px; background:#0ea5e9; color:#fff; border:none; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; }
  .acl-fclear { font-size:12px; color:#64748b; text-decoration:none; }
  .acl-tablewrap { overflow-x:auto; border:1px solid #e5e7eb; border-radius:12px; background:#fff; }
  table.acl { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; }
  table.acl th, table.acl td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; }
  table.acl thead th { position: sticky; top: 0; background: #f8fafc; z-index: 2; font-size: 11px;
       text-transform: uppercase; letter-spacing: .03em; color: #334155; vertical-align: bottom; }
  table.acl thead th.mod { text-align:center; min-width: 84px; }
  table.acl .mod-chip { display:inline-block; padding:2px 7px; border-radius:99px; color:#fff; font-weight:700; font-size:10px; }
  table.acl td.person { white-space: nowrap; }
  table.acl .pname { font-weight: 700; color: #0f172a; }
  table.acl .pkind { display:inline-block; font-size:9px; font-weight:700; padding:1px 6px; border-radius:99px; margin-left:6px; vertical-align:middle; }
  table.acl .pkind.user { background:#e0f2fe; color:#075985; }
  table.acl .pkind.tiers { background:#f3e8ff; color:#6b21a8; }
  table.acl .pmeta { font-size: 11px; color: #64748b; }
  table.acl td.cell { text-align: center; }
  table.acl tbody tr:hover { background: #fcfdff; }
  .acl-chk { width: 18px; height: 18px; cursor: pointer; accent-color: #243B5C; }
  .acl-partial { font-size:10px; color:#b45309; display:block; margin-top:2px; }
  .acl-empty { padding:30px; text-align:center; color:#64748b; }
  .acl-bar { position: sticky; bottom: 0; background:#fff; border-top:1px solid #e5e7eb;
       padding: 12px 16px; display:flex; justify-content:space-between; align-items:center; }
  .acl-save { padding: 9px 18px; border-radius:8px; background:#243B5C; color:#fff; border:none; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }
  .acl-save:hover { background:#1b2c44; }
  .acl-cap { font-size:11px; color:#b45309; margin-top:8px; }
</style>

<div class="acl-wrap">
  <h1>🔐 Accès par tiers / module</h1>
  <p class="sub">Coche un module = accès à <strong>toutes ses pages</strong> pour cette personne.
     Les accès restreints (page/périmètre) sont signalés <span style="color:#b45309;">⊙ partiel</span> et non écrasés.</p>

  <?php if ($flash): ?>
    <div class="acl-flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <!-- Filtres -->
  <form method="get" class="acl-filters">
    <div class="acl-tabs">
      <?php foreach ($kindTabs as $k => $lbl): ?>
        <a class="acl-tab <?= $kind===$k?'on':'' ?>" href="<?= acl_qs(['kind'=>$k]) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="kind" value="<?= htmlspecialchars($kind) ?>">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nom, prénom, e-mail, société…">
    <select name="societe">
      <option value="0">— Société —</option>
      <?php foreach ($societes as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $fSoc===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="agence">
      <option value="0">— Agence —</option>
      <?php foreach ($agences as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $fAge===(int)$a['id']?'selected':'' ?>><?= htmlspecialchars($a['nom_agence']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="acl-fbtn">Filtrer</button>
    <a class="acl-fclear" href="?kind=<?= htmlspecialchars($kind) ?>">Réinitialiser</a>
  </form>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="save">

    <div class="acl-tablewrap">
      <?php if (!$people): ?>
        <div class="acl-empty">Aucune personne ne correspond à ce filtre.<br>
          <small><?= $kind==='tiers' ? 'Tape un nom dans la recherche pour trouver un tiers parmi les 953.' : 'Modifie les filtres ci-dessus.' ?></small></div>
      <?php else: ?>
        <table class="acl">
          <thead>
            <tr>
              <th>Personne (<?= count($people) ?>)</th>
              <?php foreach ($modules as $mod): ?>
                <th class="mod"><span class="mod-chip" style="background:<?= htmlspecialchars($mod['couleur'] ?: '#64748b') ?>;"><?= htmlspecialchars($mod['label']) ?></span></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($people as $p):
              $key = $p['kind'].':'.$p['id'];
            ?>
              <tr>
                <td class="person">
                  <input type="hidden" name="rows[]" value="<?= htmlspecialchars($key) ?>">
                  <span class="pname"><?= htmlspecialchars($p['nom']) ?></span>
                  <span class="pkind <?= $p['kind'] ?>"><?= $p['kind']==='tiers'?'TIERS':'USER' ?></span>
                  <br>
                  <span class="pmeta"><?= htmlspecialchars($p['role']) ?><?= $p['meta']!=='' ? ' · '.htmlspecialchars($p['meta']) : '' ?></span>
                </td>
                <?php foreach ($modules as $mod):
                  $m = $mod['code'];
                  $full = !empty($grantModule[$key][$m]);
                  $partial = (int)($grantPartial[$key][$m] ?? 0);
                ?>
                  <td class="cell">
                    <input type="checkbox" class="acl-chk"
                           name="grant[<?= $p['kind'] ?>][<?= $p['id'] ?>][<?= htmlspecialchars($m) ?>]" value="1"
                           <?= $full ? 'checked' : '' ?>>
                    <?php if ($partial > 0): ?><span class="acl-partial" title="Accès restreint">⊙ partiel</span><?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="acl-bar">
          <span style="font-size:12px;color:#64748b;"><?= count($people) ?> personne(s) affichée(s) · <?= count($modules) ?> module(s)</span>
          <button type="submit" class="acl-save">💾 Enregistrer les autorisations</button>
        </div>
      <?php endif; ?>
    </div>
    <?php if (count($people) >= 300): ?>
      <p class="acl-cap">⚠️ 300 lignes max affichées — affine la recherche pour voir le reste.</p>
    <?php endif; ?>
  </form>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
