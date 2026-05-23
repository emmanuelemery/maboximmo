<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/tenant_scope.php';
require_login();

$appLayout = true;
$pageTitle = 'Propriétaires';
$bodyClass = '';
$robots    = 'noindex, nofollow';

$pdo   = $GLOBALS['pdo'];
$ctx   = tenant_current_context();
$isAdmin = $ctx['is_super_admin'];

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ── Filtres GET ───────────────────────────────────────── */
$filterSearch = trim((string)($_GET['q']      ?? ''));
$filterType   = trim((string)($_GET['type']   ?? ''));
$filterStatut = trim((string)($_GET['statut'] ?? 'actif'));
$page         = max(1, (int)($_GET['page']    ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

/* ── Résolution scope société / agence via helper ──────── */
$agencesVisibles = tenant_agences_visibles($pdo);
$validAgenceIds  = array_map(static fn($a) => (int)$a['id'], $agencesVisibles);
$scope           = tenant_resolve_filter($validAgenceIds);
$scopeSoc        = (int)$scope['id_societe'];
$scopeAg         = (int)$scope['id_agence'];

/* ── Construction requête ──────────────────────────────── */
$where  = ["tr.role_code = 'proprietaire'"];
$params = [];

// Cloisonnement société (forcé pour users standards)
if ($scopeSoc > 0) {
    $where[]  = "(t.id_societe = ? OR t.id_societe IS NULL)";
    $params[] = $scopeSoc;
}
// Filtre agence optionnel (via tiers OU proprietaires legacy)
if ($scopeAg > 0) {
    $where[]  = "(t.id_agence = ? OR p.id_agence = ?)";
    $params[] = $scopeAg;
    $params[] = $scopeAg;
}

if ($filterSearch !== '') {
    $where[]  = "(t.nom LIKE ? OR t.prenom LIKE ? OR t.email LIKE ? OR t.telephone LIKE ? OR t.raison_sociale LIKE ?)";
    $q = '%' . $filterSearch . '%';
    array_push($params, $q, $q, $q, $q, $q);
}
if ($filterType !== '') {
    $where[]  = "t.type_tiers = ?";
    $params[] = $filterType === 'morale' ? 'personne_morale' : 'personne_physique';
}
if ($filterStatut === 'actif') {
    $where[] = "t.actif = 1";
} elseif ($filterStatut === 'archive') {
    $where[] = "t.actif = 0";
}
// 'tous' → pas de filtre

$whereStr = implode(' AND ', $where);

$baseJoin = "
    FROM tiers t
    INNER JOIN tiers_roles tr ON tr.id_tiers = t.id
    LEFT JOIN proprietaires p ON p.id_tiers = t.id
    LEFT JOIN agences ag ON ag.id = COALESCE(t.id_agence, p.id_agence)
";

try {
    $stmtCount = $pdo->prepare("SELECT COUNT(DISTINCT t.id) {$baseJoin} WHERE {$whereStr}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $sql = "
        SELECT t.id AS id_tiers,
               p.id AS id_proprio_legacy,
               COALESCE(NULLIF(t.nom_affichage, ''),
                        NULLIF(t.raison_sociale, ''),
                        TRIM(CONCAT_WS(' ', t.prenom, t.nom))) AS label,
               t.civilite, t.nom, t.prenom, t.raison_sociale, t.email, t.telephone,
               t.code_postal, t.ville, t.actif, t.type_tiers,
               ag.nom_agence AS agence_nom,
               (SELECT COUNT(*) FROM biens b   WHERE b.id_proprietaire = p.id) AS nb_biens,
               (SELECT COUNT(*) FROM mandats m WHERE m.id_proprietaire = p.id) AS nb_mandats,
               (SELECT GROUP_CONCAT(DISTINCT tr.role_code ORDER BY tr.role_code SEPARATOR ',')
                  FROM tiers_roles tr WHERE tr.id_tiers = t.id AND tr.actif = 1) AS roles_actifs,
               (SELECT b2.reference_bien FROM biens b2
                  WHERE b2.id_proprietaire = p.id
                  ORDER BY b2.id DESC LIMIT 1) AS first_bien_ref,
               (SELECT b3.id FROM biens b3
                  WHERE b3.id_proprietaire = p.id
                  ORDER BY b3.id DESC LIMIT 1) AS first_bien_id
        {$baseJoin}
        WHERE {$whereStr}
        GROUP BY t.id
        ORDER BY t.actif DESC, t.nom ASC, t.prenom ASC, t.raison_sociale ASC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $proprietaires = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('[agency_proprietaires] ' . $ex->getMessage());
    $proprietaires = [];
    $total = 0;
    $totalPages = 1;
}

include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  /* Styles spécifiques agency_proprietaires (table + modal) — le reste
     (topbar / page-head / bl-btn / bl-filters / bl-search / bl-select /
     bl-content / bl-pagination / bl-empty / bl-modal) vient de liste_layout.css
     donc strictement identique à bien_liste. */

  /* Table propriétaires */
  .ap-table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    background: var(--card); border-radius: var(--r-lg); overflow: hidden;
    box-shadow: var(--neu-out);
  }
  .ap-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
    color: var(--muted); padding: 12px 14px; text-align: left; background: var(--bg);
    border-bottom: 1px solid var(--stroke);
  }
  .ap-table td {
    padding: 12px 14px; font-size: 13px; border-bottom: 1px solid var(--stroke);
    vertical-align: middle; color: var(--ink);
  }
  .ap-table tr:last-child td { border-bottom: none; }
  .ap-table tr:hover td { background: rgba(54,87,125,0.03); }
  .ap-table tr.is-archived td { opacity: 0.55; background: rgba(138,134,128,0.04); }
  .ap-table a.ap-link { color: var(--accent); text-decoration: none; font-weight: 600; }
  .ap-table a.ap-link:hover { text-decoration: underline; }

  .ap-badge {
    display: inline-block; font-size: 10px; padding: 2px 8px;
    border-radius: 20px; font-weight: 600;
  }
  .ap-badge-physique { background: rgba(54,87,125,.12); color: var(--accent); }
  .ap-badge-morale   { background: rgba(122,144,96,.15); color: #4d6b3a; }
  .ap-badge-archived { background: rgba(138,134,128,.15); color: var(--muted); font-size: 10px; margin-left: 6px; }

  .ap-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; height: 20px; border-radius: 10px;
    font-size: 11px; font-weight: 700;
  }
  .ap-count-biens   { background: rgba(54,87,125,.12); color: var(--accent); }
  .ap-count-mandats { background: rgba(249,115,22,.12); color: #c05000; }
  .ap-count-zero    { background: rgba(138,134,128,.10); color: var(--muted); }

  .ap-actions { display: flex; gap: 4px; justify-content: flex-end; }
  .ap-action-btn {
    width: 30px; height: 30px; border-radius: 6px; border: none;
    background: var(--bg); cursor: pointer; color: var(--muted);
    display: flex; align-items: center; justify-content: center;
    text-decoration: none; font-size: 14px;
    transition: background .15s, color .15s;
  }
  .ap-action-btn:hover { background: var(--card); color: var(--ink); box-shadow: var(--neu-out); }
  .ap-action-btn.danger:hover { color: #dc2626; }

  /* Modal création propriétaire */
  .ap-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9999; align-items: center; justify-content: center; }
  .ap-modal-overlay.open { display: flex; }
  .ap-modal { background: var(--card); border-radius: 14px; padding: 24px; max-width: 600px; width: 92%; box-shadow: 0 12px 40px rgba(0,0,0,.15); }
  .ap-modal h3 { margin: 0 0 16px; font-size: 1.1rem; }
  .ap-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .ap-modal-field { display: flex; flex-direction: column; gap: 3px; }
  .ap-modal-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--muted); }
  .ap-modal-field input, .ap-modal-field select { padding: 8px 12px; border: 1px solid var(--stroke); border-radius: 8px; font-size: 13px; font-family: inherit; }
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
      <span class="active">Propriétaires</span>
    </nav>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <!-- PAGE HEAD -->
  <div class="page-head">
    <div class="page-head-info">
      <div class="page-head-label">Gestion des propriétaires</div>
      <h1 class="page-head-title">Propriétaires</h1>
      <div class="page-head-sub"><?= $total ?> propriétaire<?= $total > 1 ? 's' : '' ?><?= $filterStatut === 'archive' ? ' (archivés)' : '' ?></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="bl-btn bl-btn-primary" onclick="document.getElementById('modal-new-proprio').classList.add('open')">
        ➕ Nouveau propriétaire
      </button>
      <a href="<?= htmlspecialchars(app_url('/admin/admin_tiers_merge.php')) ?>"
         class="bl-btn"
         style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         title="Détecter et fusionner les doublons de tiers (propriétaires, locataires…)">
        🔀 Traiter doublons
      </a>
      <?php if ((int)($_SESSION['id_role'] ?? 0) === 1): ?>
      <a href="<?= htmlspecialchars(app_url('/admin/admin_proprietaires_suppression.php')) ?>"
         class="bl-btn"
         style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         title="Outil super-admin de suppression de propriétaires (avec cascade biens)">
        🗑 Nettoyage admin
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- FILTERS -->
  <form class="bl-filters" method="get" action="">
    <div class="bl-search">
      <span class="search-icon">🔍</span>
      <input type="text" name="q" placeholder="Nom, email, téléphone…" value="<?= e($filterSearch) ?>">
    </div>

    <select name="type" class="bl-select" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <option value="physique" <?= $filterType === 'physique' ? 'selected' : '' ?>>👤 Particulier</option>
      <option value="morale"   <?= $filterType === 'morale'   ? 'selected' : '' ?>>🏢 Société</option>
    </select>

    <select name="statut" class="bl-select" onchange="this.form.submit()">
      <option value="actif"   <?= $filterStatut === 'actif'   ? 'selected' : '' ?>>Actifs</option>
      <option value="archive" <?= $filterStatut === 'archive' ? 'selected' : '' ?>>📦 Archivés</option>
      <option value="tous"    <?= $filterStatut === 'tous'    ? 'selected' : '' ?>>Tous</option>
    </select>

    <!-- Filtres société (super admin) + agence -->
    <?= tenant_render_filter_bar($pdo, ['show_societe' => true, 'show_agence' => true]) ?>

    <?php if ($filterSearch || $filterType || $filterStatut !== 'actif' || $scopeAg > 0 || ($isAdmin && $scopeSoc > 0)): ?>
      <a href="agency_proprietaires.php" class="bl-btn bl-btn-ghost" style="font-size:.8rem;">✕ Réinitialiser</a>
    <?php endif; ?>

    <span class="bl-filter-count">Page <?= $page ?> / <?= $totalPages ?></span>
  </form>

  <!-- CONTENT -->
  <div class="bl-content">
    <?php if (empty($proprietaires)): ?>
      <div class="bl-empty">
        <div class="bl-empty-icon">👥</div>
        <h2>Aucun propriétaire trouvé</h2>
        <p><?= ($filterSearch || $filterType) ? 'Essaie d\'ajuster les filtres.' : 'Commence par créer un propriétaire.' ?></p>
        <button type="button" class="bl-btn bl-btn-primary" onclick="document.getElementById('modal-new-proprio').classList.add('open')">
          ➕ Nouveau propriétaire
        </button>
      </div>
    <?php else: ?>
      <table class="ap-table">
        <thead>
          <tr>
            <th>Nom <small style="color:#9a9690;font-weight:400;">· ID</small></th>
            <th>Type</th>
            <th>Rôles</th>
            <th>Contact</th>
            <th>Agence</th>
            <th style="text-align:center;">Biens</th>
            <th style="text-align:center;">Mandats</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($proprietaires as $p):
            $isArchived = (int)$p['actif'] === 0;
            $fullName   = (string)$p['label'];
            $isMorale   = $p['type_tiers'] === 'personne_morale';
            $ficheUrl   = 'agency_proprietaire_fiche.php?id=' . (int)($p['id_proprio_legacy'] ?? 0);
            $url360     = 'tiers_360.php?id=' . (int)$p['id_tiers'];
            $roles      = array_filter(array_map('trim', explode(',', (string)($p['roles_actifs'] ?? ''))));
          ?>
          <tr class="<?= $isArchived ? 'is-archived' : '' ?>" data-id-tiers="<?= (int)$p['id_tiers'] ?>">
            <td>
              <a href="<?= e($url360) ?>" class="ap-link" title="Ouvrir la vue 360° du tiers"><?= e($fullName) ?></a>
              <?php if ($p['civilite']): ?><span style="color:var(--muted);font-size:11px;"> · <?= e($p['civilite']) ?></span><?php endif; ?>
              <?php if ($isArchived): ?><span class="ap-badge ap-badge-archived">📦 archivé</span><?php endif; ?>
              <div style="margin-top:3px;display:flex;align-items:center;gap:6px;">
                <code class="ap-id-copy" data-copy="<?= (int)$p['id_tiers'] ?>"
                      style="cursor:pointer;font-size:10.5px;color:#5b21b6;background:#ede9fe;padding:1px 6px;border-radius:4px;font-family:'DM Mono',monospace;"
                      title="Cliquer pour copier — utilisable dans Suppression propriétaires">tiers #<?= (int)$p['id_tiers'] ?></code>
                <?php if ($p['id_proprio_legacy']): ?>
                  <code style="font-size:10.5px;color:#7a766f;font-family:'DM Mono',monospace;">proprio #<?= (int)$p['id_proprio_legacy'] ?></code>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <span class="ap-badge ap-badge-<?= $isMorale ? 'morale' : 'physique' ?>">
                <?= $isMorale ? '🏢 Société' : '👤 Particulier' ?>
              </span>
            </td>
            <td style="font-size:11px;">
              <?php if (empty($roles)): ?>
                <span style="color:var(--muted);">—</span>
              <?php else: foreach ($roles as $rc): ?>
                <span style="display:inline-block;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;margin:1px 2px 1px 0;font-family:'DM Mono',monospace;font-size:10px;"><?= e($rc) ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td style="font-size:12px;">
              <?php if ($p['email']): ?><div><?= e($p['email']) ?></div><?php endif; ?>
              <?php if ($p['telephone']): ?><div style="color:var(--muted);"><?= e($p['telephone']) ?></div><?php endif; ?>
              <?php if (!$p['email'] && !$p['telephone']): ?>—<?php endif; ?>
            </td>
            <td style="font-size:12px;"><?= $p['agence_nom'] ? e($p['agence_nom']) : '<span style="color:var(--muted);">—</span>' ?></td>
            <td style="text-align:center;">
              <span class="ap-count <?= (int)$p['nb_biens'] > 0 ? 'ap-count-biens' : 'ap-count-zero' ?>"><?= (int)$p['nb_biens'] ?></span>
              <?php if (!empty($p['first_bien_id']) && !empty($p['first_bien_ref'])): ?>
                <div style="margin-top:2px;">
                  <a href="bien_360.php?id=<?= (int)$p['first_bien_id'] ?>" style="font-size:10px;font-family:'DM Mono',monospace;color:#4878a6;text-decoration:none;" title="Ouvrir la fiche 360° du bien"><?= e($p['first_bien_ref']) ?><?= (int)$p['nb_biens'] > 1 ? ' +' . ((int)$p['nb_biens'] - 1) : '' ?></a>
                </div>
              <?php endif; ?>
            </td>
            <td style="text-align:center;">
              <span class="ap-count <?= (int)$p['nb_mandats'] > 0 ? 'ap-count-mandats' : 'ap-count-zero' ?>"><?= (int)$p['nb_mandats'] ?></span>
            </td>
            <td>
              <div class="ap-actions">
                <?php if ($p['id_proprio_legacy']): ?>
                  <a class="ap-action-btn" href="<?= e($ficheUrl) ?>" title="Voir la fiche">👁️</a>
                <?php endif; ?>
                <?php if ($isArchived): ?>
                  <button type="button" class="ap-action-btn" data-action="restore" title="Restaurer">♻️</button>
                <?php else: ?>
                  <button type="button" class="ap-action-btn" data-action="archive" title="Archiver">📦</button>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                  <button type="button" class="ap-action-btn danger" data-action="delete" title="Supprimer définitivement (admin)">🗑️</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
      <div class="bl-pagination">
        <?php
          $base = array_filter([
            'q'       => $filterSearch,
            'type'    => $filterType,
            'statut'  => $filterStatut !== 'actif' ? $filterStatut : '',
            'societe' => $scopeSoc > 0 && $isAdmin ? $scopeSoc : '',
            'agence'  => $scopeAg  > 0 ? $scopeAg  : '',
          ], fn($v) => $v !== '');
          for ($i = 1; $i <= $totalPages; $i++):
            $qs = http_build_query($base + ['page' => $i]);
        ?>
          <?php if ($i === $page): ?>
            <span class="bl-page-btn current"><?= $i ?></span>
          <?php else: ?>
            <a href="?<?= e($qs) ?>" class="bl-page-btn"><?= $i ?></a>
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
    <h3>➕ Nouveau propriétaire</h3>
    <form id="form-new-proprio">
      <div class="ap-modal-grid">
        <div class="ap-modal-field">
          <label>Type</label>
          <select name="type_personne" id="np-type">
            <option value="physique">👤 Particulier</option>
            <option value="morale">🏢 Société</option>
          </select>
        </div>
        <div class="ap-modal-field">
          <label>Civilité</label>
          <select name="civilite">
            <option value="">—</option><option value="M.">M.</option><option value="Mme">Mme</option>
          </select>
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
          <label>Code postal</label>
          <input type="text" name="code_postal">
        </div>
        <div class="ap-modal-field" style="grid-column: 1 / -1;">
          <label>Adresse</label>
          <input type="text" name="adresse_1">
        </div>
        <div class="ap-modal-field" style="grid-column: 1 / -1;">
          <label>Ville</label>
          <input type="text" name="ville">
        </div>
      </div>
      <div id="np-doublon" style="display:none;margin:12px 0;padding:10px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="bl-btn bl-btn-ghost" onclick="document.getElementById('modal-new-proprio').classList.remove('open')">Annuler</button>
        <button type="submit" class="bl-btn bl-btn-primary" id="np-submit">Créer</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Modal création propriétaire (inchangé du flow existant) ──
document.getElementById('form-new-proprio').addEventListener('submit', async function(e) {
  e.preventDefault();
  const form = this;
  const btn = document.getElementById('np-submit');
  const doublonEl = document.getElementById('np-doublon');
  btn.disabled = true; btn.textContent = 'Création…';
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
      doublonEl.innerHTML = '⚠️ Un propriétaire similaire existe déjà : <strong>' + r.nom + '</strong>. <a href="agency_proprietaire_fiche.php?id=' + r.id + '">Voir la fiche</a>';
      doublonEl.style.display = 'block';
    } else {
      window.location.href = 'agency_proprietaire_fiche.php?id=' + r.id;
    }
  } catch (err) {
    doublonEl.textContent = 'Erreur réseau : ' + err.message;
    doublonEl.style.display = 'block';
  } finally {
    btn.disabled = false; btn.textContent = 'Créer';
  }
});

// Ouverture auto modal via ?new=1
if (new URLSearchParams(window.location.search).get('new') === '1') {
  document.getElementById('modal-new-proprio').classList.add('open');
}

// ── Actions archiver / restaurer / supprimer ──
document.querySelectorAll('.ap-action-btn[data-action]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const action = btn.dataset.action;
    const row = btn.closest('tr');
    const idTiers = parseInt(row?.dataset.idTiers, 10) || 0;
    if (!idTiers) return;

    if (action === 'delete') {
      if (!confirm('⚠️ SUPPRESSION DÉFINITIVE\n\nCette action retirera le propriétaire de la base de données. Impossible si des biens / mandats y sont liés.\n\nConfirmer ?')) return;
      btn.disabled = true; btn.textContent = '⏳';
      try {
        const r = await fetch('api/tiers_delete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ id_tiers: idTiers, confirm: 'DELETE' }),
        });
        const j = await r.json();
        if (!j.ok) { alert('❌ ' + (j.error || 'Erreur')); btn.disabled = false; btn.textContent = '🗑️'; return; }
        row.style.transition = 'opacity .3s'; row.style.opacity = '0';
        setTimeout(() => row.remove(), 300);
      } catch (e) { alert('❌ ' + e.message); btn.disabled = false; btn.textContent = '🗑️'; }
      return;
    }

    // archive / restore
    const label = action === 'archive' ? 'Archiver ce propriétaire ?' : 'Restaurer ce propriétaire ?';
    if (!confirm(label)) return;
    btn.disabled = true; btn.textContent = '⏳';
    try {
      const r = await fetch('api/tiers_archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ id_tiers: idTiers, action }),
      });
      const j = await r.json();
      if (!j.ok) { alert('❌ ' + (j.error || 'Erreur')); btn.disabled = false; return; }
      window.location.reload();
    } catch (e) { alert('❌ ' + e.message); btn.disabled = false; }
  });

  // Copier l'ID tiers au clic (utile pour la page Suppression propriétaires)
  document.querySelectorAll('.ap-id-copy').forEach(el => {
    el.addEventListener('click', async (ev) => {
      ev.preventDefault();
      const id = el.dataset.copy;
      try {
        await navigator.clipboard.writeText(id);
        const orig = el.textContent;
        el.textContent = '✓ copié #' + id;
        el.style.background = '#d9f0db';
        el.style.color = '#2d6a35';
        setTimeout(() => {
          el.textContent = orig;
          el.style.background = '#ede9fe';
          el.style.color = '#5b21b6';
        }, 1200);
      } catch (e) { /* clipboard non dispo : ignorer */ }
    });
  });
});
</script>

<?php /* Bloc « Nettoyage admin proprietaires » deplace 2026-05-23 vers admin/admin_proprietaires_suppression.php (accessible via super_admin_dashboard.php + bouton header). */ ?>

<?php include __DIR__ . '/inc/footer.php'; ?>
