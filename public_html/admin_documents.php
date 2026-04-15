<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Documents administratifs';
$bodyClass = '';
$robots    = 'noindex, nofollow';

$pdo       = $GLOBALS['pdo'] ?? null;
$roleId    = current_role_id();
$userId    = current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

if (!$isAdmin) {
    header('Location: ' . app_url('/agency_dashboard.php'));
    exit;
}

// ── Types de documents administratifs ──
$typesDocAdmin = [
    'kbis'                => 'Kbis / Extrait RCS',
    'assurance_rcp'       => 'Assurance RC Professionnelle',
    'assurance_locative'  => 'Assurance locative',
    'carte_professionnelle' => 'Carte professionnelle (T/G)',
    'garantie_financiere' => 'Garantie financire',
    'rib'                 => 'RIB / IBAN',
    'inscription_insee'   => 'Inscription INSEE / SIRET',
    'bail_commercial'     => 'Bail commercial',
    'reglement_interieur' => 'Rglement intrieur',
    'contrat'             => 'Contrat / Convention',
    'attestation'         => 'Attestation',
    'autre'               => 'Autre document',
];

// ── Listes pour affectation ──
$societes = $pdo->query("SELECT id, nom FROM societes WHERE nom != 'Externe' ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
$agences  = $pdo->query("SELECT id, nom_agence, id_societe FROM agences WHERE actif=1 ORDER BY nom_agence ASC")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, prenom, nom FROM users WHERE actif=1 ORDER BY nom, prenom ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── Filtres ──
$filterType   = trim((string)($_GET['type'] ?? ''));
$filterScope  = trim((string)($_GET['scope'] ?? ''));  // societe, agence, user, admin
$filterScopeId = (int)($_GET['scope_id'] ?? 0);
$viewMode     = ($_GET['view'] ?? $_COOKIE['adm_doc_view'] ?? 'list');
if (isset($_GET['view'])) {
    setcookie('adm_doc_view', $viewMode, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
}

// ── Upload POST ──
$uploadMsg = '';
$uploadErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    verify_csrf_any('admin_documents');

    if ($_POST['_action'] === 'upload') {
        $file = $_FILES['doc_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $uploadErr = 'Erreur upload fichier.';
        } else {
            $typeDoc   = (string)($_POST['type_document'] ?? 'autre');
            $titre     = trim((string)($_POST['titre'] ?? ''));
            $affectation = (string)($_POST['affectation'] ?? 'societe');
            $affectId    = (int)($_POST['affectation_id'] ?? 0);

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','odt'];
            if (!in_array($ext, $allowed, true)) {
                $uploadErr = 'Type de fichier non autoris.';
            } else {
                $destDir = __DIR__ . '/uploads/societes/' . $societeId;
                if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destPath = $destDir . '/' . $safeName;
                $relPath  = 'uploads/societes/' . $societeId . '/' . $safeName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $idSoc = null; $idAgc = null; $idUsr = null;
                    switch ($affectation) {
                        case 'societe': $idSoc = $affectId > 0 ? $affectId : $societeId; break;
                        case 'agence':  $idSoc = $societeId; $idAgc = $affectId; break;
                        case 'user':    $idSoc = $societeId; $idUsr = $affectId; break;
                        case 'admin':   $idSoc = $societeId; break;
                    }
                    if ($titre === '') $titre = pathinfo($file['name'], PATHINFO_FILENAME);

                    $stmt = $pdo->prepare("
                        INSERT INTO documents (id_societe, id_agence, id_user, type_document, categorie_document,
                                              nom_fichier, chemin_fichier, mime_type, taille_octets, titre, date_creation)
                        VALUES (?, ?, ?, ?, 'administratif', ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $idSoc, $idAgc, $idUsr, $typeDoc,
                        $file['name'], $relPath, $file['type'], $file['size'], $titre
                    ]);
                    $newDocId = (int)$pdo->lastInsertId();
                    $uploadMsg = 'Document charg avec succs (ID #' . $newDocId . ')';
                } else {
                    $uploadErr = 'Erreur lors du dplacement du fichier.';
                }
            }
        }
    } elseif ($_POST['_action'] === 'update_affectation') {
        $docId       = (int)($_POST['doc_id'] ?? 0);
        $affectation = (string)($_POST['affectation'] ?? 'societe');
        $affectId    = (int)($_POST['affectation_id'] ?? 0);
        $typeDoc     = (string)($_POST['type_document'] ?? '');
        $newTitre    = trim((string)($_POST['titre'] ?? ''));

        $idSoc = null; $idAgc = null; $idUsr = null;
        switch ($affectation) {
            case 'societe': $idSoc = $affectId > 0 ? $affectId : $societeId; break;
            case 'agence':  $idSoc = $societeId; $idAgc = $affectId; break;
            case 'user':    $idSoc = $societeId; $idUsr = $affectId; break;
            case 'admin':   $idSoc = $societeId; break;
        }
        $sql = "UPDATE documents SET id_societe=?, id_agence=?, id_user=?";
        $params = [$idSoc, $idAgc, $idUsr];
        if ($typeDoc !== '') {
            $sql .= ", type_document=?";
            $params[] = $typeDoc;
        }
        if ($newTitre !== '') {
            $sql .= ", titre=?";
            $params[] = $newTitre;
        }
        $sql .= " WHERE id=?";
        $params[] = $docId;
        $pdo->prepare($sql)->execute($params);
        $uploadMsg = 'Document mis à jour.';

    } elseif ($_POST['_action'] === 'delete') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $st = $pdo->prepare("SELECT chemin_fichier FROM documents WHERE id = ? AND id_societe = ?");
        $st->execute([$docId, $societeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $fp = __DIR__ . '/' . ltrim($row['chemin_fichier'], '/');
            if (is_file($fp)) @unlink($fp);
            $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$docId]);
            $uploadMsg = 'Document supprim.';
        }
    }
}

// ── Requte documents ──
$where  = ["d.categorie_document = 'administratif'", "d.id_societe = ?"];
$params = [$societeId];

if ($filterType !== '') {
    $where[]  = "d.type_document = ?";
    $params[] = $filterType;
}
if ($filterScope === 'societe') {
    $where[] = "d.id_agence IS NULL AND d.id_user IS NULL";
} elseif ($filterScope === 'agence' && $filterScopeId > 0) {
    $where[]  = "d.id_agence = ?";
    $params[] = $filterScopeId;
} elseif ($filterScope === 'user' && $filterScopeId > 0) {
    $where[]  = "d.id_user = ?";
    $params[] = $filterScopeId;
}

$sql = "SELECT d.*,
               s.nom AS nom_societe,
               ag.nom_agence,
               CONCAT(u.prenom, ' ', u.nom) AS nom_user
        FROM documents d
        LEFT JOIN societes s ON s.id = d.id_societe
        LEFT JOIN agences ag ON ag.id = d.id_agence
        LEFT JOIN users u ON u.id = d.id_user
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.date_creation DESC";
$stmtDocs = $pdo->prepare($sql);
$stmtDocs->execute($params);
$docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>

<style>
/* ── Admin Documents ── */
.adoc-container { max-width: 1200px; margin: 0 auto; padding: 20px; }

.adoc-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.adoc-head h1 { font-size: 1.4rem; font-weight: 700; color: var(--ink, #1a1a2e); margin: 0; }
.adoc-head-actions { display: flex; gap: 8px; align-items: center; }

/* Upload card */
.adoc-upload { background: var(--card-bg, #fff); border: 2px dashed var(--stroke, #e0e0e0); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
.adoc-upload.drag-over { border-color: var(--accent, #f7941d); background: rgba(247,148,29,0.04); }
.adoc-upload-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: end; }
.adoc-upload-grid .field { display: flex; flex-direction: column; gap: 4px; }
.adoc-upload-grid label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--ink-muted, #888); letter-spacing: 0.5px; }
.adoc-upload-grid select,
.adoc-upload-grid input { padding: 8px 10px; border: 1px solid var(--stroke, #ddd); border-radius: 8px; font-size: 13px; background: var(--card-bg, #fff); }
.adoc-upload-grid input[type="file"] { padding: 6px; }

/* Filtres */
.adoc-filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.adoc-filters select { padding: 6px 10px; border: 1px solid var(--stroke, #ddd); border-radius: 8px; font-size: 12px; }

/* Toggle view */
.adoc-view-toggle { display: flex; gap: 4px; }
.adoc-view-toggle a { padding: 6px 10px; border-radius: 6px; font-size: 12px; text-decoration: none; color: var(--ink-muted, #888); background: var(--bg-subtle, #f5f5f5); }
.adoc-view-toggle a.active { background: var(--accent, #f7941d); color: #fff; }

/* ── LIST VIEW ── */
.adoc-table { width: 100%; border-collapse: separate; border-spacing: 0; background: var(--card-bg, #fff); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.adoc-table th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ink-muted, #888); padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--stroke, #eee); background: var(--bg-subtle, #fafafa); }
.adoc-table td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid var(--stroke, #f0f0f0); vertical-align: middle; }
.adoc-table tr:last-child td { border-bottom: none; }
.adoc-table tr:hover td { background: rgba(247,148,29,0.03); }

/* ── CARD VIEW ── */
.adoc-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.adoc-card { background: var(--card-bg, #fff); border-radius: 12px; padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.06); border: 1px solid var(--stroke, #eee); position: relative; transition: box-shadow .2s; }
.adoc-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); }
.adoc-card-type { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent, #f7941d); margin-bottom: 6px; }
.adoc-card-title { font-size: 14px; font-weight: 600; color: var(--ink, #1a1a2e); margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.adoc-card-meta { font-size: 11px; color: var(--ink-muted, #888); margin-bottom: 8px; }
.adoc-card-scope { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 20px; background: var(--bg-subtle, #f0f0f0); color: var(--ink-muted, #666); font-weight: 600; }
.adoc-card-actions { display: flex; gap: 6px; margin-top: 10px; }

/* Badges / boutons */
.adoc-badge { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 20px; font-weight: 600; }
.adoc-badge-societe { background: #dbeafe; color: #1e40af; }
.adoc-badge-agence  { background: #dcfce7; color: #166534; }
.adoc-badge-user    { background: #fef3c7; color: #92400e; }
.adoc-badge-admin   { background: #fce7f3; color: #9d174d; }

.adoc-btn { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: opacity .2s; }
.adoc-btn:hover { opacity: .85; }
.adoc-btn-primary { background: var(--accent, #f7941d); color: #fff; }
.adoc-btn-ghost { background: var(--bg-subtle, #f0f0f0); color: var(--ink, #333); }
.adoc-btn-danger { background: #fee2e2; color: #991b1b; }
.adoc-btn-ai { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }

/* Tooltip résumé IA */
.adoc-tooltip { position: relative; }
.adoc-tooltip .adoc-tip { display: none; position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%); background: #1a1a2e; color: #f0f0f0; padding: 10px 14px; border-radius: 10px; font-size: 12px; line-height: 1.5; width: 320px; max-height: 200px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.2); z-index: 100; white-space: pre-line; }
.adoc-tooltip .adoc-tip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #1a1a2e; }
.adoc-tooltip:hover .adoc-tip { display: block; }

/* Inbox email */
.adoc-inbox-bar { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
.adoc-inbox-bar .adoc-btn { flex-shrink: 0; }

/* Toast */
.adoc-toast { position: fixed; top: 16px; right: 16px; z-index: 99999; max-width: 400px; padding: 12px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; box-shadow: 0 4px 20px rgba(0,0,0,.12); animation: adocSlide .3s ease; }
@keyframes adocSlide { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

/* Modal */
.adoc-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 9999; align-items: center; justify-content: center; }
.adoc-modal-overlay.open { display: flex; }
.adoc-modal { background: var(--card-bg, #fff); border-radius: 14px; padding: 24px; max-width: 500px; width: 90%; box-shadow: 0 12px 40px rgba(0,0,0,.15); }
.adoc-modal h3 { margin: 0 0 16px; font-size: 1.1rem; }
.adoc-modal .field { margin-bottom: 12px; }
.adoc-modal .field label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; color: var(--ink-muted, #888); }
.adoc-modal .field select { width: 100%; padding: 8px 10px; border: 1px solid var(--stroke, #ddd); border-radius: 8px; font-size: 13px; }
.adoc-modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

/* Preview */
.adoc-preview-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 9999; align-items: center; justify-content: center; }
.adoc-preview-overlay.open { display: flex; }
.adoc-preview-box { background: #fff; border-radius: 14px; width: 90%; max-width: 900px; height: 85vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 12px 40px rgba(0,0,0,.2); }
.adoc-preview-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--stroke, #eee); }
.adoc-preview-head h3 { margin: 0; font-size: 1rem; }
.adoc-preview-body { flex: 1; }
.adoc-preview-body iframe { width: 100%; height: 100%; border: none; }
.adoc-preview-body img { max-width: 100%; max-height: 100%; object-fit: contain; margin: auto; display: block; }

@media (max-width: 768px) {
    .adoc-upload-grid { grid-template-columns: 1fr; }
    .adoc-cards { grid-template-columns: 1fr; }
}
</style>

<div class="mbi-main">
<div class="adoc-container">

  <!-- PAGE HEAD -->
  <div class="adoc-head">
    <h1>Documents administratifs</h1>
    <div class="adoc-head-actions">
      <div class="adoc-view-toggle">
        <a href="?view=list<?= $filterType ? '&type=' . h($filterType) : '' ?><?= $filterScope ? '&scope=' . h($filterScope) : '' ?>" class="<?= $viewMode === 'list' ? 'active' : '' ?>">Liste</a>
        <a href="?view=cards<?= $filterType ? '&type=' . h($filterType) : '' ?><?= $filterScope ? '&scope=' . h($filterScope) : '' ?>" class="<?= $viewMode === 'cards' ? 'active' : '' ?>">Cards</a>
      </div>
    </div>
  </div>

  <!-- INBOX EMAIL -->
  <div class="adoc-inbox-bar">
    <span>Envoyez vos documents  <strong>documents@maboximmo.fr</strong> pour les importer automatiquement.</span>
    <button type="button" class="adoc-btn adoc-btn-primary" id="btn-check-inbox" onclick="checkInbox()">Relever le courrier</button>
  </div>

  <?php if ($uploadMsg || $uploadErr): ?>
  <div class="adoc-toast" id="adoc-toast" style="<?= $uploadErr ? 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;' : 'background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;' ?>">
    <?= $uploadErr ? '&#10060; ' . h($uploadErr) : '&#9989; ' . h($uploadMsg) ?>
    <button onclick="this.parentElement.remove()" style="margin-left:12px;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:.6;">&#10005;</button>
  </div>
  <script>setTimeout(() => { const t = document.getElementById('adoc-toast'); if (t) { t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(() => t.remove(), 300); }}, 4000);</script>
  <?php endif; ?>

  <!-- UPLOAD -->
  <form method="post" enctype="multipart/form-data" id="upload-form">
    <?= csrf_field('admin_documents') ?>
    <input type="hidden" name="_action" value="upload">
    <div class="adoc-upload" id="drop-zone">
      <div class="adoc-upload-grid">
        <div class="field">
          <label>Fichier</label>
          <input type="file" name="doc_file" id="doc-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.odt" required>
        </div>
        <div class="field">
          <label>Type de document</label>
          <select name="type_document">
            <?php foreach ($typesDocAdmin as $code => $label): ?>
            <option value="<?= h($code) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Titre (optionnel)</label>
          <input type="text" name="titre" placeholder="Nom du document">
        </div>
        <button type="submit" class="adoc-btn adoc-btn-primary" style="height:38px;">Charger</button>
      </div>
      <div class="adoc-upload-grid" style="margin-top:10px;">
        <div class="field">
          <label>Affecter </label>
          <select name="affectation" id="affectation-scope" onchange="updateAffectationList()">
            <option value="societe">Socit</option>
            <option value="agence">Agence</option>
            <option value="user">Utilisateur</option>
            <option value="admin">Admin (global)</option>
          </select>
        </div>
        <div class="field" id="affectation-target-wrap">
          <label>Cible</label>
          <select name="affectation_id" id="affectation-target">
            <?php foreach ($societes as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $societeId ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"></div>
        <div></div>
      </div>
    </div>
  </form>

  <!-- FILTRES -->
  <form class="adoc-filters" method="get">
    <input type="hidden" name="view" value="<?= h($viewMode) ?>">
    <select name="type" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <?php foreach ($typesDocAdmin as $code => $label): ?>
      <option value="<?= h($code) ?>" <?= $filterType === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="scope" onchange="this.form.submit()">
      <option value="">Toutes affectations</option>
      <option value="societe" <?= $filterScope === 'societe' ? 'selected' : '' ?>>Socit</option>
      <option value="agence"  <?= $filterScope === 'agence'  ? 'selected' : '' ?>>Agence</option>
      <option value="user"    <?= $filterScope === 'user'    ? 'selected' : '' ?>>Utilisateur</option>
    </select>
    <?php if ($filterType || $filterScope): ?>
    <a href="admin_documents.php" class="adoc-btn adoc-btn-ghost">Rinitialiser</a>
    <?php endif; ?>
    <span style="margin-left:auto;font-size:12px;color:var(--ink-muted,#888);"><?= count($docs) ?> document<?= count($docs) > 1 ? 's' : '' ?></span>
  </form>

  <!-- ══════════════ LIST VIEW ══════════════ -->
  <?php if ($viewMode === 'list'): ?>
  <table class="adoc-table">
    <thead>
      <tr>
        <th>Type</th>
        <th>Titre / Fichier</th>
        <th>Affect </th>
        <th>Date</th>
        <th>Taille</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($docs)): ?>
      <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--ink-muted,#888);">Aucun document administratif</td></tr>
    <?php endif; ?>
    <?php foreach ($docs as $d):
        $scope = $d['id_user'] ? 'user' : ($d['id_agence'] ? 'agence' : 'societe');
        $scopeLabel = match($scope) {
            'user'   => $d['nom_user'] ?? 'Utilisateur #' . $d['id_user'],
            'agence' => $d['nom_agence'] ?? 'Agence #' . $d['id_agence'],
            default  => $d['nom_societe'] ?? 'Socit',
        };
        $typeLabel = $typesDocAdmin[$d['type_document']] ?? $d['type_document'];
        $hasAnalysis = !empty($d['analysis_json']);
        $summary = '';
        if ($hasAnalysis) {
            $parsed = json_decode($d['analysis_json'], true);
            $summary = $parsed['resume'] ?? $parsed['summary'] ?? '';
            if (!$summary && is_array($parsed)) {
                // Construit un rsum  partir des champs extraits
                $parts = [];
                foreach ($parsed as $k => $v) {
                    if (is_string($v) && $v !== '' && !str_starts_with($k, '_')) {
                        $parts[] = ucfirst(str_replace('_', ' ', $k)) . ' : ' . $v;
                    }
                }
                $summary = implode("\n", array_slice($parts, 0, 8));
            }
        }
        $sizeStr = $d['taille_octets'] > 1048576
            ? round($d['taille_octets'] / 1048576, 1) . ' Mo'
            : round($d['taille_octets'] / 1024) . ' Ko';
    ?>
      <tr>
        <td><span class="adoc-badge adoc-badge-<?= $scope ?>"><?= h($typeLabel) ?></span></td>
        <td class="adoc-tooltip">
          <strong><?= h($d['titre'] ?: $d['nom_fichier']) ?></strong>
          <?php if ($summary): ?>
          <div class="adoc-tip"><?= h($summary) ?></div>
          <?php endif; ?>
        </td>
        <td><span class="adoc-badge adoc-badge-<?= $scope ?>"><?= h($scopeLabel) ?></span></td>
        <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($d['date_creation'])) ?></td>
        <td><?= $sizeStr ?></td>
        <td style="text-align:right;">
          <button type="button" class="adoc-btn adoc-btn-ghost" onclick="previewDoc(<?= (int)$d['id'] ?>, '<?= h($d['chemin_fichier']) ?>', '<?= h($d['mime_type']) ?>')" title="Visualiser">Voir</button>
          <button type="button" class="adoc-btn adoc-btn-ai" onclick="analyzeDoc(<?= (int)$d['id'] ?>)" title="Analyser avec IA"><?= $hasAnalysis ? 'Re-analyser' : 'Analyser' ?></button>
          <button type="button" class="adoc-btn adoc-btn-ghost" onclick="openAffectModal(<?= (int)$d['id'] ?>, '<?= h($d['type_document']) ?>', '<?= h($d['titre'] ?: $d['nom_fichier']) ?>')" title="Modifier affectation">Affecter</button>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer dfinitivement ce document ?')">
            <?= csrf_field('admin_documents') ?>
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
            <button type="submit" class="adoc-btn adoc-btn-danger" title="Supprimer">Suppr.</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php else: ?>
  <!-- ══════════════ CARD VIEW ══════════════ -->
  <div class="adoc-cards">
    <?php if (empty($docs)): ?>
      <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--ink-muted,#888);">Aucun document administratif</div>
    <?php endif; ?>
    <?php foreach ($docs as $d):
        $scope = $d['id_user'] ? 'user' : ($d['id_agence'] ? 'agence' : 'societe');
        $scopeLabel = match($scope) {
            'user'   => $d['nom_user'] ?? 'Utilisateur #' . $d['id_user'],
            'agence' => $d['nom_agence'] ?? 'Agence #' . $d['id_agence'],
            default  => $d['nom_societe'] ?? 'Socit',
        };
        $typeLabel = $typesDocAdmin[$d['type_document']] ?? $d['type_document'];
        $hasAnalysis = !empty($d['analysis_json']);
        $summary = '';
        if ($hasAnalysis) {
            $parsed = json_decode($d['analysis_json'], true);
            $summary = $parsed['resume'] ?? $parsed['summary'] ?? '';
            if (!$summary && is_array($parsed)) {
                $parts = [];
                foreach ($parsed as $k => $v) {
                    if (is_string($v) && $v !== '' && !str_starts_with($k, '_')) {
                        $parts[] = ucfirst(str_replace('_', ' ', $k)) . ' : ' . $v;
                    }
                }
                $summary = implode("\n", array_slice($parts, 0, 8));
            }
        }
        $sizeStr = $d['taille_octets'] > 1048576
            ? round($d['taille_octets'] / 1048576, 1) . ' Mo'
            : round($d['taille_octets'] / 1024) . ' Ko';
        $dateStr = date('d/m/Y', strtotime($d['date_creation']));
        $icon = match(true) {
            str_contains($d['mime_type'] ?? '', 'pdf')   => '&#128196;',
            str_starts_with($d['mime_type'] ?? '', 'image') => '&#128247;',
            default => '&#128195;',
        };
    ?>
    <div class="adoc-card adoc-tooltip">
      <div class="adoc-card-type"><?= $icon ?> <?= h($typeLabel) ?></div>
      <div class="adoc-card-title"><?= h($d['titre'] ?: $d['nom_fichier']) ?></div>
      <div class="adoc-card-meta"><?= $dateStr ?> &middot; <?= $sizeStr ?></div>
      <span class="adoc-card-scope adoc-badge-<?= $scope ?>"><?= h($scopeLabel) ?></span>
      <?php if ($summary): ?>
      <div class="adoc-tip"><?= h($summary) ?></div>
      <?php endif; ?>
      <div class="adoc-card-actions">
        <button type="button" class="adoc-btn adoc-btn-ghost" onclick="previewDoc(<?= (int)$d['id'] ?>, '<?= h($d['chemin_fichier']) ?>', '<?= h($d['mime_type']) ?>')">Voir</button>
        <button type="button" class="adoc-btn adoc-btn-ai" onclick="analyzeDoc(<?= (int)$d['id'] ?>)"><?= $hasAnalysis ? 'Re-analyser' : 'Analyser' ?></button>
        <button type="button" class="adoc-btn adoc-btn-ghost" onclick="openAffectModal(<?= (int)$d['id'] ?>, '<?= h($d['type_document']) ?>', '<?= h($d['titre'] ?: $d['nom_fichier']) ?>')">Affecter</button>
        <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer dfinitivement ce document ?')">
          <?= csrf_field('admin_documents') ?>
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
          <button type="submit" class="adoc-btn adoc-btn-danger">Suppr.</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /adoc-container -->
</div><!-- /mbi-main -->

<!-- MODAL AFFECTATION -->
<div class="adoc-modal-overlay" id="affectModal">
  <div class="adoc-modal">
    <h3>Modifier le document</h3>
    <form method="post" id="affect-form">
      <?= csrf_field('admin_documents') ?>
      <input type="hidden" name="_action" value="update_affectation">
      <input type="hidden" name="doc_id" id="affect-doc-id">
      <div class="field">
        <label>Nom du document</label>
        <input type="text" name="titre" id="affect-titre" style="width:100%;padding:8px 10px;border:1px solid var(--stroke,#ddd);border-radius:8px;font-size:13px;">
      </div>
      <div class="field">
        <label>Type de document</label>
        <select name="type_document" id="affect-type">
          <?php foreach ($typesDocAdmin as $code => $label): ?>
          <option value="<?= h($code) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Affecter </label>
        <select name="affectation" id="affect-scope" onchange="updateAffectTargetModal()">
          <option value="societe">Socit</option>
          <option value="agence">Agence</option>
          <option value="user">Utilisateur</option>
          <option value="admin">Admin (global)</option>
        </select>
      </div>
      <div class="field" id="affect-target-wrap-modal">
        <label>Cible</label>
        <select name="affectation_id" id="affect-target-modal"></select>
      </div>
      <div class="adoc-modal-actions">
        <button type="button" class="adoc-btn adoc-btn-ghost" onclick="closeAffectModal()">Annuler</button>
        <button type="submit" class="adoc-btn adoc-btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- PREVIEW -->
<div class="adoc-preview-overlay" id="previewOverlay" onclick="if(event.target===this)closePreview()">
  <div class="adoc-preview-box">
    <div class="adoc-preview-head">
      <h3 id="preview-title">Aperu</h3>
      <button type="button" class="adoc-btn adoc-btn-ghost" onclick="closePreview()">Fermer</button>
    </div>
    <div class="adoc-preview-body" id="preview-body"></div>
  </div>
</div>

<script>
// ── Data JS ──
const _societes = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'nom' => $s['nom']], $societes)) ?>;
const _agences  = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['id'], 'nom' => $a['nom_agence'], 'id_societe' => (int)$a['id_societe']], $agences)) ?>;
const _users    = <?= json_encode(array_map(fn($u) => ['id' => (int)$u['id'], 'nom' => trim($u['prenom'] . ' ' . $u['nom'])], $users)) ?>;
const _csrf     = '<?= csrf_token("admin_documents") ?>';

// ── Affectation dynamique (upload form) ──
function updateAffectationList() {
    const scope = document.getElementById('affectation-scope').value;
    const target = document.getElementById('affectation-target');
    const wrap = document.getElementById('affectation-target-wrap');
    target.innerHTML = '';
    if (scope === 'admin') { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    const list = scope === 'societe' ? _societes : scope === 'agence' ? _agences : _users;
    list.forEach(item => {
        const o = document.createElement('option');
        o.value = item.id;
        o.textContent = item.nom;
        target.appendChild(o);
    });
}

// ── Modal affectation ──
function openAffectModal(docId, typeDoc, titre) {
    document.getElementById('affect-doc-id').value = docId;
    document.getElementById('affect-titre').value = titre || '';
    document.getElementById('affect-type').value = typeDoc;
    document.getElementById('affectModal').classList.add('open');
    updateAffectTargetModal();
}
function closeAffectModal() { document.getElementById('affectModal').classList.remove('open'); }
document.getElementById('affectModal').addEventListener('click', e => { if (e.target === document.getElementById('affectModal')) closeAffectModal(); });

function updateAffectTargetModal() {
    const scope = document.getElementById('affect-scope').value;
    const target = document.getElementById('affect-target-modal');
    const wrap = document.getElementById('affect-target-wrap-modal');
    target.innerHTML = '';
    if (scope === 'admin') { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    const list = scope === 'societe' ? _societes : scope === 'agence' ? _agences : _users;
    list.forEach(item => {
        const o = document.createElement('option');
        o.value = item.id;
        o.textContent = item.nom;
        target.appendChild(o);
    });
}

// ── Preview ──
function previewDoc(id, path, mime) {
    const body = document.getElementById('preview-body');
    const base = '<?= rtrim(app_url("/"), "/") ?>/';
    const url = base + path;
    if (mime.includes('pdf')) {
        body.innerHTML = '<iframe src="' + url + '"></iframe>';
    } else if (mime.startsWith('image')) {
        body.innerHTML = '<img src="' + url + '" alt="Preview">';
    } else {
        body.innerHTML = '<div style="padding:40px;text-align:center;color:#888;">Aperçu non disponible pour ce type de fichier.<br><br><a href="' + url + '" download class="adoc-btn adoc-btn-primary">Télécharger</a></div>';
    }
    document.getElementById('previewOverlay').classList.add('open');
}
function closePreview() {
    document.getElementById('previewOverlay').classList.remove('open');
    document.getElementById('preview-body').innerHTML = '';
}

// ── Analyse IA ──
function showToast(text, isError) {
    const old = document.getElementById('adoc-toast');
    if (old) old.remove();
    const t = document.createElement('div');
    t.id = 'adoc-toast';
    t.className = 'adoc-toast';
    t.style.cssText = isError
        ? 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;'
        : 'background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;';
    t.innerHTML = (isError ? '&#10060; ' : '&#9989; ') + text
        + ' <button onclick="this.parentElement.remove()" style="margin-left:12px;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:.6;">&#10005;</button>';
    document.body.appendChild(t);
    setTimeout(() => { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 5000);
}

async function analyzeDoc(docId) {
    showToast('Analyse IA en cours...', false);
    try {
        const fd = new FormData();
        fd.append('doc_id', docId);
        fd.append('csrf_token', _csrf);
        const resp = await fetch('<?= h(app_url("/api/admin_doc_analyze.php")) ?>', {
            method: 'POST', body: fd, credentials: 'same-origin'
        });
        const data = await resp.json();
        if (data.ok) {
            showToast('Analyse termine (' + (data.fields_filled || 0) + ' champs extraits). Rechargement...', false);
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Erreur analyse : ' + (data.error || 'inconnue'), true);
        }
    } catch (e) {
        showToast('Erreur rseau', true);
    }
}

// ── Inbox email (relve courrier) ──
async function checkInbox() {
    const btn = document.getElementById('btn-check-inbox');
    btn.disabled = true;
    btn.textContent = 'Relve en cours...';
    try {
        const resp = await fetch('<?= h(app_url("/api/document_email_intake.php")) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': _csrf },
            body: JSON.stringify({ action: 'check' }),
            credentials: 'same-origin'
        });
        const data = await resp.json();
        if (data.ok) {
            const n = data.imported || 0;
            showToast(n > 0 ? n + ' document(s) import(s) depuis la bote mail.' : 'Aucun nouveau document dans la bote mail.', false);
            if (n > 0) setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Erreur : ' + (data.error || 'inconnue'), true);
        }
    } catch (e) {
        showToast('Erreur rseau ou serveur', true);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Relever le courrier';
    }
}

// ── Drag & drop ──
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('doc-file');
if (dropZone && fileInput) {
    ['dragenter','dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag-over'); }));
    ['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('drag-over'); }));
    dropZone.addEventListener('drop', ev => {
        if (ev.dataTransfer.files.length) {
            fileInput.files = ev.dataTransfer.files;
        }
    });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
