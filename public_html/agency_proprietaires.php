<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout  = true;
$pageTitle  = 'Propriétaires';
$bodyClass  = '';
$robots     = 'noindex, nofollow';

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence'] ?? 0);

// ── Filtres ──
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterType   = trim((string)($_GET['type'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ── Requête ──
$where  = ['1=1'];
$params = [];

if ($agenceId > 0) {
    $where[]  = 'p.id_agence = ?';
    $params[] = $agenceId;
}
if ($filterSearch !== '') {
    $where[]  = "(p.nom LIKE ? OR p.prenom LIKE ? OR p.email LIKE ? OR p.telephone LIKE ? OR p.societe LIKE ?)";
    $q = '%' . $filterSearch . '%';
    $params = array_merge($params, [$q, $q, $q, $q, $q]);
}
if ($filterType !== '') {
    $where[]  = "p.type_personne = ?";
    $params[] = $filterType;
}

$whereStr = implode(' AND ', $where);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM proprietaires p WHERE {$whereStr}");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "
    SELECT p.*,
           (SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire = p.id) AS nb_biens,
           (SELECT COUNT(*) FROM mandats m WHERE m.id_proprietaire = p.id) AS nb_mandats,
           (SELECT COUNT(*) FROM bailleur_documents bd WHERE bd.id_proprietaire = p.id) AS nb_docs
    FROM proprietaires p
    WHERE {$whereStr}
    ORDER BY p.nom ASC, p.prenom ASC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$proprietaires = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>

<style>
/* ── Topbar ── */
.bl-topbar { height: 56px; display: flex; align-items: center; gap: 8px; padding: 0 20px; border-bottom: 1px solid var(--stroke, #eee); background: var(--card-bg, #fff); position: sticky; top: 0; z-index: 100; }
.topbar-nav-btn { width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--bg-subtle, #f5f5f5); cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink-muted, #888); }
.topbar-nav-btn:hover { color: var(--ink, #333); }
.topbar-gap { width: 50px; flex-shrink: 0; }
.topbar-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; color: var(--ink-muted, #888); }
.topbar-breadcrumb .active { color: var(--accent, #f7941d); font-weight: 600; }
.topbar-spacer { flex: 1; }
.topbar-icon-btn { width: 32px; height: 32px; border-radius: 8px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink-muted, #888); }
.topbar-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--accent, #f7941d); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; }

.ap-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.ap-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.ap-head h1 { font-size: 1.4rem; font-weight: 700; color: var(--ink, #1a1a2e); margin: 0; }

.ap-filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.ap-filters input, .ap-filters select { padding: 7px 12px; border: 1px solid var(--stroke, #ddd); border-radius: 8px; font-size: 13px; }
.ap-filters input[type="text"] { min-width: 220px; }

.ap-table { width: 100%; border-collapse: separate; border-spacing: 0; background: var(--card-bg, #fff); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.ap-table th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ink-muted, #888); padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--stroke, #eee); background: var(--bg-subtle, #fafafa); }
.ap-table td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid var(--stroke, #f0f0f0); vertical-align: middle; }
.ap-table tr:last-child td { border-bottom: none; }
.ap-table tr:hover td { background: rgba(247,148,29,0.03); }
.ap-table a { color: var(--accent, #f7941d); text-decoration: none; font-weight: 600; }
.ap-table a:hover { text-decoration: underline; }

.ap-badge { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 20px; font-weight: 600; }
.ap-badge-physique { background: #dbeafe; color: #1e40af; }
.ap-badge-morale { background: #dcfce7; color: #166534; }

.ap-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 20px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.ap-count-biens { background: #eff6ff; color: #1e40af; }
.ap-count-mandats { background: #fef3c7; color: #92400e; }
.ap-count-docs { background: #f0fdf4; color: #166534; }

.ap-btn { padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.ap-btn-primary { background: var(--accent, #f7941d); color: #fff; }
.ap-btn-ghost { background: var(--bg-subtle, #f0f0f0); color: var(--ink, #333); }

.ap-pagination { display: flex; gap: 4px; justify-content: center; margin-top: 16px; }
.ap-pagination a, .ap-pagination span { padding: 6px 12px; border-radius: 6px; font-size: 12px; text-decoration: none; }
.ap-pagination a { background: var(--bg-subtle, #f0f0f0); color: var(--ink, #333); }
.ap-pagination a:hover { background: var(--accent, #f7941d); color: #fff; }
.ap-pagination span.current { background: var(--accent, #f7941d); color: #fff; font-weight: 700; }

.ap-empty { text-align: center; padding: 40px; color: var(--ink-muted, #888); font-size: 14px; }
</style>

<div class="mbi-main">

  <!-- TOPBAR -->
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <span>Agency</span> <span style="color:#ccc;">›</span> <span class="active">Propriétaires</span>
    </nav>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

<div class="ap-container">

  <div class="ap-head">
    <h1>👥 Propriétaires <span style="font-size:.8rem;font-weight:400;color:var(--ink-muted,#888);">(<?= $total ?>)</span></h1>
    <button type="button" class="ap-btn ap-btn-primary" onclick="document.getElementById('modal-new-proprio').classList.add('open')">+ Nouveau propriétaire</button>
  </div>

  <form class="ap-filters" method="get">
    <input type="text" name="q" value="<?= h($filterSearch) ?>" placeholder="Rechercher un propriétaire...">
    <select name="type" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <option value="physique" <?= $filterType === 'physique' ? 'selected' : '' ?>>Personne physique</option>
      <option value="morale" <?= $filterType === 'morale' ? 'selected' : '' ?>>Personne morale</option>
    </select>
    <button type="submit" class="ap-btn ap-btn-primary">Rechercher</button>
    <?php if ($filterSearch || $filterType): ?>
    <a href="agency_proprietaires.php" class="ap-btn ap-btn-ghost">Réinitialiser</a>
    <?php endif; ?>
  </form>

  <?php if (empty($proprietaires)): ?>
  <div class="ap-empty">Aucun propriétaire trouvé.</div>
  <?php else: ?>
  <table class="ap-table">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Type</th>
        <th>Contact</th>
        <th>Ville</th>
        <th>Biens</th>
        <th>Mandats</th>
        <th>Docs</th>
        <th>Créé le</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($proprietaires as $p):
        $fullName = trim(($p['prenom'] ? $p['prenom'] . ' ' : '') . $p['nom']);
        if ($p['societe']) $fullName .= ' (' . $p['societe'] . ')';
    ?>
      <tr>
        <td>
          <a href="agency_proprietaire_fiche.php?id=<?= (int)$p['id'] ?>"><?= h($fullName) ?></a>
          <?php if ($p['civilite']): ?><span style="color:#aaa;font-size:11px;"><?= h($p['civilite']) ?></span><?php endif; ?>
        </td>
        <td><span class="ap-badge ap-badge-<?= h($p['type_personne'] ?: 'physique') ?>"><?= $p['type_personne'] === 'morale' ? 'Morale' : 'Physique' ?></span></td>
        <td style="font-size:12px;">
          <?php if ($p['email']): ?><div><?= h($p['email']) ?></div><?php endif; ?>
          <?php if ($p['telephone']): ?><div style="color:#888;"><?= h($p['telephone']) ?></div><?php endif; ?>
        </td>
        <td style="font-size:12px;"><?= h(($p['code_postal'] ? $p['code_postal'] . ' ' : '') . ($p['ville'] ?? '')) ?></td>
        <td><span class="ap-count ap-count-biens"><?= (int)$p['nb_biens'] ?></span></td>
        <td><span class="ap-count ap-count-mandats"><?= (int)$p['nb_mandats'] ?></span></td>
        <td><span class="ap-count ap-count-docs"><?= (int)$p['nb_docs'] ?></span></td>
        <td style="font-size:11px;color:#888;"><?= $p['date_creation'] ? date('d/m/Y', strtotime($p['date_creation'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="ap-pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?page=<?= $i ?>&q=<?= urlencode($filterSearch) ?>&type=<?= urlencode($filterType) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>
</div>

<!-- MODAL NOUVEAU PROPRIÉTAIRE -->
<div class="ap-modal-overlay" id="modal-new-proprio" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ap-modal">
    <h3>Nouveau propriétaire</h3>
    <form id="form-new-proprio">
      <div class="ap-modal-grid">
        <div class="ap-modal-field">
          <label>Type</label>
          <select name="type_personne" id="np-type">
            <option value="physique">Physique</option>
            <option value="morale">Morale</option>
          </select>
        </div>
        <div class="ap-modal-field">
          <label>Civilité</label>
          <select name="civilite"><option value="">—</option><option value="M.">M.</option><option value="Mme">Mme</option></select>
        </div>
        <div class="ap-modal-field">
          <label>Nom *</label>
          <input type="text" name="nom" required>
        </div>
        <div class="ap-modal-field">
          <label>Prénom</label>
          <input type="text" name="prenom">
        </div>
        <div class="ap-modal-field">
          <label>Société / SCI</label>
          <input type="text" name="societe">
        </div>
        <div class="ap-modal-field">
          <label>Email</label>
          <input type="email" name="email">
        </div>
        <div class="ap-modal-field">
          <label>Téléphone</label>
          <input type="tel" name="telephone">
        </div>
        <div class="ap-modal-field">
          <label>Adresse</label>
          <input type="text" name="adresse_1">
        </div>
        <div class="ap-modal-field">
          <label>Code postal</label>
          <input type="text" name="code_postal">
        </div>
        <div class="ap-modal-field">
          <label>Ville</label>
          <input type="text" name="ville">
        </div>
      </div>
      <div id="np-doublon" style="display:none;margin:12px 0;padding:10px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="ap-btn ap-btn-ghost" onclick="document.getElementById('modal-new-proprio').classList.remove('open')">Annuler</button>
        <button type="submit" class="ap-btn ap-btn-primary" id="np-submit">Créer</button>
      </div>
    </form>
  </div>
</div>

<style>
.ap-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:9999; align-items:center; justify-content:center; }
.ap-modal-overlay.open { display:flex; }
.ap-modal { background:var(--card-bg,#fff); border-radius:14px; padding:24px; max-width:560px; width:90%; box-shadow:0 12px 40px rgba(0,0,0,.15); }
.ap-modal h3 { margin:0 0 16px; font-size:1.1rem; }
.ap-modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.ap-modal-field { display:flex; flex-direction:column; gap:3px; }
.ap-modal-field label { font-size:11px; font-weight:600; text-transform:uppercase; color:var(--ink-muted,#888); }
.ap-modal-field input, .ap-modal-field select { padding:7px 10px; border:1px solid var(--stroke,#ddd); border-radius:8px; font-size:13px; }
</style>

<script>
document.getElementById('form-new-proprio').addEventListener('submit', async function(e) {
  e.preventDefault();
  const form = this;
  const btn = document.getElementById('np-submit');
  const doublonEl = document.getElementById('np-doublon');
  btn.disabled = true;
  btn.textContent = 'Création…';
  doublonEl.style.display = 'none';

  const data = {};
  new FormData(form).forEach((v, k) => { data[k] = v; });
  data.csrf_token = '<?= csrf_token("ajouter_bien") ?>';

  try {
    const resp = await fetch('api/proprietaire_creer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const r = await resp.json();
    if (!r.ok) {
      doublonEl.textContent = r.error || 'Erreur';
      doublonEl.style.display = 'block';
    } else if (r.existant) {
      doublonEl.innerHTML = '⚠️ Un propriétaire similaire existe déjà : <strong>' + r.nom + '</strong> (ID #' + r.id + '). <a href="agency_proprietaire_fiche.php?id=' + r.id + '" style="color:#1e40af;">Voir la fiche</a>';
      doublonEl.style.display = 'block';
    } else {
      window.location.href = 'agency_proprietaire_fiche.php?id=' + r.id;
    }
  } catch (err) {
    doublonEl.textContent = 'Erreur réseau : ' + err.message;
    doublonEl.style.display = 'block';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Créer';
  }
});
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
