<?php
/**
 * admin/admin_annonces_table.php
 *
 * Tableau éditable inline de la table `annonces` — évite phpMyAdmin.
 * Cellules cliquables → input/select → blur save via api/admin_annonce_edit.php.
 * FK (id_agence, id_user, id_societe) affichées avec le libellé résolu.
 *
 * Accès : admin (role_id IN 1, 2, 3).
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$isAdmin      = in_array($roleId, [1, 2, 3], true);

if (!$isAdmin) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

function ate($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Filtres minimaux
$fAgence = (int)($_GET['id_agence'] ?? 0);
$fEtat   = trim((string)($_GET['etat'] ?? ''));

$where = ['1=1'];
$params = [];
if (!$isSuperAdmin && $societeId > 0) {
    $where[] = 'a.id_societe = :fsoc';
    $params[':fsoc'] = $societeId;
}
if ($fAgence > 0) { $where[] = 'a.id_agence = :fag'; $params[':fag'] = $fAgence; }
if ($fEtat === 'brouillon')    $where[] = "(a.etat_publication = 'brouillon' OR a.statut = 'brouillon')";
if ($fEtat === 'diffusee')     $where[] = "(a.etat_publication IN ('diffusee','publiee') OR a.statut IN ('publiee','active','en_ligne'))";
if ($fEtat === 'archivee')     $where[] = "a.etat_publication IN ('archivee','archived')";
$whereClause = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT
        a.id, a.id_bien, a.id_agence, a.id_societe, a.id_user,
        a.reference_annonce, a.titre, a.type_transaction,
        a.statut, a.etat_publication,
        a.prix, a.loyer, a.loyer_cc, a.charges, a.depot_garantie,
        a.honoraires_location_bail, a.honoraires_etat_des_lieux,
        a.taxe_fonciere, a.taxe_habitation, a.taxe_ordures_menageres,
        a.visible_portails, a.visible_site, a.visible_maboximmo, a.visible_site_perso,
        a.mandat_numero, a.mandat_type, a.date_mandat,
        a.url_tarifs_publics,
        a.date_creation, a.date_modification,
        b.reference_bien,
        ag.nom_agence,
        s.nom AS societe_nom,
        CONCAT(u.prenom, ' ', u.nom) AS user_nom
    FROM annonces a
    LEFT JOIN biens b     ON b.id = a.id_bien
    LEFT JOIN agences ag  ON ag.id = a.id_agence
    LEFT JOIN societes s  ON s.id = a.id_societe
    LEFT JOIN users u     ON u.id = a.id_user
    {$whereClause}
    ORDER BY a.id DESC
    LIMIT 300
";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Options FK pour les dropdowns
$agences  = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
$societes = $pdo->query("SELECT id, COALESCE(NULLIF(raison_sociale,''), nom) AS lbl FROM societes ORDER BY lbl")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) AS lbl FROM users WHERE actif = 1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);

$csrf = function_exists('csrf_token') ? csrf_token('admin_annonce_edit') : '';

$pageTitle    = 'BDD Annonces — édition directe';
$pageSubtitle = 'Super Admin · remplace phpMyAdmin';
require_once __DIR__ . '/../inc/agency_layout_top.php';
?>

<style>
  .at-wrap { max-width: 100%; padding: 0 8px; }
  .at-filters { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin-bottom:14px;
                display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .at-filters select { padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:12px; }
  .at-filters .count { margin-left:auto; font-size:12px; color:#64748b; }
  .at-table { width:100%; border-collapse:collapse; font-size:11px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
  .at-table th { background:#f8fafc; padding:6px 8px; text-align:left; font-weight:700; color:#475569; border-bottom:1px solid #e5e7eb; white-space:nowrap; font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
  .at-table td { padding:5px 8px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
  .at-table tr:hover td { background:#f8fafc; }
  .at-cell { cursor:pointer; padding:3px 6px; border-radius:4px; min-height:18px; display:inline-block; min-width:40px; }
  .at-cell:hover { background:#dbeafe; }
  .at-cell.saving { background:#fef3c7 !important; }
  .at-cell.saved { background:#dcfce7 !important; transition:background 1s; }
  .at-cell.err { background:#fee2e2 !important; }
  .at-cell input, .at-cell select {
    font-size:11px; padding:2px 4px; border:1px solid #0ea5e9; border-radius:4px;
    background:#fff; min-width:80px; font-family:inherit;
  }
  .at-id { font-family:monospace; font-size:10px; color:#64748b; }
  .at-fk-link { color:#0369a1; text-decoration:none; border-bottom:1px dotted #0ea5e9; }
  .at-fk-link:hover { background:#dbeafe; }
  .at-b { padding:1px 6px; border-radius:99px; font-size:9px; font-weight:700; text-transform:uppercase; }
  .at-b-on  { background:#dcfce7; color:#166534; }
  .at-b-off { background:#fee2e2; color:#991b1b; }
  .at-ref { font-family:monospace; font-size:10px; color:#334155; }
  .at-wide td { white-space:nowrap; }
  .at-scroll { overflow-x:auto; max-width:100%; }
</style>

<div class="at-wrap">

  <form method="get" class="at-filters">
    <label style="font-size:12px; font-weight:600; color:#475569;">Agence</label>
    <select name="id_agence" onchange="this.form.submit()">
      <option value="0">Toutes</option>
      <?php foreach ($agences as $ag): ?>
        <option value="<?= (int)$ag['id'] ?>" <?= $fAgence === (int)$ag['id'] ? 'selected' : '' ?>><?= ate($ag['nom_agence']) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="font-size:12px; font-weight:600; color:#475569;">État</label>
    <select name="etat" onchange="this.form.submit()">
      <option value="">Tous</option>
      <option value="brouillon" <?= $fEtat === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
      <option value="diffusee"  <?= $fEtat === 'diffusee'  ? 'selected' : '' ?>>Diffusée</option>
      <option value="archivee"  <?= $fEtat === 'archivee'  ? 'selected' : '' ?>>Archivée</option>
    </select>
    <a href="admin_annonces_table.php" style="font-size:12px; color:#64748b;">✕ Reset</a>
    <span class="count"><?= count($rows) ?> annonce(s) · édition directe BDD</span>
  </form>

  <div style="background:#fef3c7; padding:10px 14px; border-radius:8px; margin-bottom:10px; font-size:12px; color:#78350f;">
    ⚠️ <strong>Édition directe BDD</strong> — clique une cellule pour la modifier. La sauvegarde se fait au blur (hors focus).
    Toute modif est irréversible — pas de système d'annulation.
  </div>

  <div class="at-scroll">
  <table class="at-table at-wide">
    <thead>
      <tr>
        <th>#id</th>
        <th>Bien</th>
        <th>Agence</th>
        <th>Société</th>
        <th>Commercial</th>
        <th>Transaction</th>
        <th>Statut</th>
        <th>État pub.</th>
        <th>Portails</th>
        <th>Site</th>
        <th>MBI</th>
        <th>S.perso</th>
        <th>Prix</th>
        <th>Loyer</th>
        <th>Loyer CC</th>
        <th>Hono loc+bail</th>
        <th>Hono EDL</th>
        <th>Dépôt</th>
        <th>Mandat №</th>
        <th>Type M.</th>
        <th>Titre</th>
        <th>Modifiée</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $editBien = app_url('/bien_detail.php?edit=' . (int)$r['id_bien'] . '&section=annonce');
          $annId = (int)$r['id'];
        ?>
        <tr>
          <td><a href="<?= ate($editBien) ?>" class="at-fk-link">#<?= $annId ?></a></td>
          <td>
            <a href="<?= ate($editBien) ?>" class="at-fk-link">
              #<?= (int)$r['id_bien'] ?>
              <?php if (!empty($r['reference_bien'])): ?><br><span class="at-ref"><?= ate($r['reference_bien']) ?></span><?php endif; ?>
            </a>
          </td>
          <!-- id_agence (FK) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="id_agence" data-type="fk-agence" data-value="<?= (int)$r['id_agence'] ?>"><?= ate($r['nom_agence'] ?: '—') ?></span></td>
          <!-- id_societe (FK) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="id_societe" data-type="fk-societe" data-value="<?= (int)$r['id_societe'] ?>"><?= ate($r['societe_nom'] ?: '—') ?></span></td>
          <!-- id_user (FK) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="id_user" data-type="fk-user" data-value="<?= (int)$r['id_user'] ?>"><?= ate($r['user_nom'] ?: '—') ?></span></td>
          <!-- type_transaction (enum) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="type_transaction" data-type="enum-trans" data-value="<?= ate($r['type_transaction']) ?>"><?= ate($r['type_transaction'] ?: '—') ?></span></td>
          <!-- statut (enum) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="statut" data-type="enum-statut" data-value="<?= ate($r['statut']) ?>"><?= ate($r['statut'] ?: '—') ?></span></td>
          <!-- etat_publication (enum) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="etat_publication" data-type="enum-etat" data-value="<?= ate($r['etat_publication']) ?>"><?= ate($r['etat_publication'] ?: '—') ?></span></td>
          <!-- bools -->
          <?php foreach (['visible_portails','visible_site','visible_maboximmo','visible_site_perso'] as $bf):
            $bv = (int)($r[$bf] ?? 0);
          ?>
            <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="<?= $bf ?>" data-type="bool" data-value="<?= $bv ?>">
              <span class="at-b <?= $bv ? 'at-b-on':'at-b-off' ?>"><?= $bv ? '1':'0' ?></span>
            </span></td>
          <?php endforeach; ?>
          <!-- decimals -->
          <?php foreach (['prix','loyer','loyer_cc','honoraires_location_bail','honoraires_etat_des_lieux','depot_garantie'] as $df):
            $dv = $r[$df];
          ?>
            <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="<?= $df ?>" data-type="decimal" data-value="<?= ate((string)$dv) ?>"><?= $dv !== null ? ate((string)$dv) : '—' ?></span></td>
          <?php endforeach; ?>
          <!-- mandat_numero (text) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="mandat_numero" data-type="text" data-value="<?= ate((string)$r['mandat_numero']) ?>"><?= ate($r['mandat_numero'] ?: '—') ?></span></td>
          <!-- mandat_type (enum) -->
          <td><span class="at-cell" data-ann="<?= $annId ?>" data-field="mandat_type" data-type="enum-mandat" data-value="<?= ate((string)$r['mandat_type']) ?>"><?= ate($r['mandat_type'] ?: '—') ?></span></td>
          <!-- titre (text) -->
          <td style="max-width:260px; white-space:normal;"><span class="at-cell" data-ann="<?= $annId ?>" data-field="titre" data-type="text" data-value="<?= ate((string)$r['titre']) ?>" title="Clique pour éditer"><?= ate(mb_substr((string)($r['titre'] ?: '—'), 0, 60)) ?></span></td>
          <td style="color:#94a3b8; font-size:10px;"><?= ate($r['date_modification']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
const AT_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
const AT_AGENCES = <?= json_encode(array_map(fn($a) => ['id'=>(int)$a['id'],'lbl'=>$a['nom_agence']], $agences), JSON_UNESCAPED_UNICODE) ?>;
const AT_SOCIETES = <?= json_encode(array_map(fn($s) => ['id'=>(int)$s['id'],'lbl'=>$s['lbl']], $societes), JSON_UNESCAPED_UNICODE) ?>;
const AT_USERS = <?= json_encode(array_map(fn($u) => ['id'=>(int)$u['id'],'lbl'=>$u['lbl']], $users), JSON_UNESCAPED_UNICODE) ?>;

function atBuildEditor(cell) {
  const type  = cell.dataset.type;
  const value = cell.dataset.value || '';
  let el;

  if (type === 'bool') {
    el = document.createElement('select');
    el.innerHTML = '<option value="0">0 (non)</option><option value="1">1 (oui)</option>';
    el.value = value;
  } else if (type === 'fk-agence' || type === 'fk-societe' || type === 'fk-user') {
    el = document.createElement('select');
    const list = type === 'fk-agence' ? AT_AGENCES : (type === 'fk-societe' ? AT_SOCIETES : AT_USERS);
    el.innerHTML = '<option value="0">—</option>' + list.map(o =>
      `<option value="${o.id}" ${String(o.id) === String(value) ? 'selected' : ''}>${o.id} — ${o.lbl || ''}</option>`
    ).join('');
  } else if (type === 'enum-trans') {
    el = document.createElement('select');
    el.innerHTML = ['','vente','location','saisonnier','viager','location_annuelle','cession_bail','fonds_commerce','neuf','vefa']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'enum-statut') {
    el = document.createElement('select');
    el.innerHTML = ['','brouillon','publiee','active','en_ligne','archivee']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'enum-etat') {
    el = document.createElement('select');
    el.innerHTML = ['','brouillon','diffusee','publiee','archivee','archived']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'enum-mandat') {
    el = document.createElement('select');
    el.innerHTML = ['','exclusif','simple']
      .map(v => `<option value="${v}" ${v===value?'selected':''}>${v || '—'}</option>`).join('');
  } else if (type === 'decimal') {
    el = document.createElement('input');
    el.type = 'number'; el.step = '0.01'; el.value = value;
  } else {
    el = document.createElement('input');
    el.type = 'text'; el.value = value;
  }
  return el;
}

async function atSave(cell, newVal) {
  cell.classList.add('saving');
  cell.classList.remove('saved','err');
  try {
    const fd = new FormData();
    fd.append('id_annonce', cell.dataset.ann);
    fd.append('field', cell.dataset.field);
    fd.append('value', newVal);
    fd.append('csrf_token', AT_CSRF);
    const r = await fetch('../api/admin_annonce_edit.php', { method:'POST', body:fd, credentials:'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Erreur');

    // Met à jour l'affichage + data-value
    cell.dataset.value = j.value !== null && j.value !== undefined ? j.value : '';
    const type = cell.dataset.type;
    if (type === 'bool') {
      cell.innerHTML = '<span class="at-b ' + ((+j.value) ? 'at-b-on' : 'at-b-off') + '">' + (j.value ? '1' : '0') + '</span>';
    } else if (type.startsWith('fk-')) {
      cell.textContent = j.display || (j.value ? '#' + j.value : '—');
    } else {
      cell.textContent = j.value === null || j.value === '' ? '—' : String(j.value);
    }
    cell.classList.remove('saving');
    cell.classList.add('saved');
    setTimeout(() => cell.classList.remove('saved'), 1500);
  } catch (e) {
    cell.classList.remove('saving');
    cell.classList.add('err');
    alert('❌ ' + e.message);
  }
}

// Binding de tous les at-cell
document.querySelectorAll('.at-cell').forEach(cell => {
  cell.addEventListener('click', function(e) {
    if (cell.querySelector('input, select')) return; // déjà en édition
    const currentText = cell.textContent;
    const editor = atBuildEditor(cell);
    cell.innerHTML = '';
    cell.appendChild(editor);
    editor.focus();
    if (editor.tagName === 'INPUT') editor.select();

    const finish = (save) => {
      if (save) {
        const newVal = editor.value;
        if (String(newVal) === String(cell.dataset.value)) {
          cell.innerHTML = currentText;
          return;
        }
        cell.dataset.value = newVal;
        atSave(cell, newVal);
      } else {
        cell.innerHTML = currentText;
      }
    };
    editor.addEventListener('blur', () => finish(true));
    editor.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); editor.blur(); }
      if (ev.key === 'Escape') { finish(false); }
    });
  });
});
</script>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
