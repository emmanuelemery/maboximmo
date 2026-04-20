<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$bodyClass = '';
$robots    = 'noindex, nofollow';

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId    = current_user_id();
$roleId    = current_role_id();
$isAdmin   = in_array($roleId, [1, 2, 7, 8], true);

$propId = (int)($_GET['id'] ?? 0);
if ($propId <= 0) { header('Location: agency_proprietaires.php'); exit; }

// ── Charger le propriétaire ──
$stmt = $pdo->prepare("SELECT * FROM proprietaires WHERE id = ?");
$stmt->execute([$propId]);
$prop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$prop) { header('Location: agency_proprietaires.php?err=introuvable'); exit; }

$fullName = trim(($prop['prenom'] ? $prop['prenom'] . ' ' : '') . $prop['nom']);
$pageTitle = $fullName;

// ── POST : mise à jour infos ──
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_info') {
    verify_csrf_any('proprio_fiche');
    $fields = ['civilite','nom','prenom','societe','email','telephone','telephone_2',
               'adresse_1','adresse_2','code_postal','ville','pays','type_personne',
               'commentaire','notes_internes','siren','forme_juridique','regie'];
    $sets = []; $params = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $sets[]  = "$f = ?";
            $params[] = trim((string)$_POST[$f]) ?: null;
        }
    }
    if ($sets) {
        $sets[]  = "date_modification = NOW()";
        $params[] = $propId;
        $pdo->prepare("UPDATE proprietaires SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        $msg = 'Informations mises à jour.';
        // Recharger
        $stmt->execute([$propId]);
        $prop = $stmt->fetch(PDO::FETCH_ASSOC);
        $fullName = trim(($prop['prenom'] ? $prop['prenom'] . ' ' : '') . $prop['nom']);
    }
}

// ── POST : upload document ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload_doc') {
    verify_csrf_any('proprio_fiche');
    $file = $_FILES['doc_file'] ?? null;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
        if (in_array($ext, $allowed, true)) {
            $destDir = __DIR__ . '/uploads/bailleur_docs/' . $propId;
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $destDir . '/' . $safeName;
            $relPath  = 'uploads/bailleur_docs/' . $propId . '/' . $safeName;
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $pdo->prepare("
                    INSERT INTO bailleur_documents
                        (id_proprietaire, type_document, titre, nom_fichier, chemin_fichier, taille, mime_type, uploaded_by, date_upload, commentaire)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ")->execute([
                    $propId,
                    (string)($_POST['type_document'] ?? 'autre'),
                    trim((string)($_POST['titre'] ?? '')) ?: pathinfo($file['name'], PATHINFO_FILENAME),
                    $file['name'],
                    $relPath,
                    $file['size'],
                    $file['type'],
                    $userId,
                    trim((string)($_POST['commentaire_doc'] ?? '')) ?: null,
                ]);
                $msg = 'Document ajouté.';
            }
        }
    }
}

// ── POST : supprimer document ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_doc') {
    verify_csrf_any('proprio_fiche');
    $docId = (int)($_POST['doc_id'] ?? 0);
    $st = $pdo->prepare("SELECT chemin_fichier FROM bailleur_documents WHERE id = ? AND id_proprietaire = ?");
    $st->execute([$docId, $propId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $fp = __DIR__ . '/' . ltrim($row['chemin_fichier'], '/');
        if (is_file($fp)) @unlink($fp);
        $pdo->prepare("DELETE FROM bailleur_documents WHERE id = ?")->execute([$docId]);
        $msg = 'Document supprimé.';
    }
}

// ── Onglet actif ──
$tab = $_GET['tab'] ?? 'infos';

// ── Charger les biens ──
$stmtBiens = $pdo->prepare("
    SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.ville, b.code_postal,
           b.surface_habitable, b.statut_bien,
           tb.libelle AS type_bien_label,
           (SELECT a.type_transaction FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS type_transaction,
           (SELECT a.prix FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS prix
    FROM biens b
    LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
    WHERE b.id_proprietaire = ?
    ORDER BY b.reference_bien ASC
");
$stmtBiens->execute([$propId]);
$biens = $stmtBiens->fetchAll(PDO::FETCH_ASSOC);

// ── Charger les mandats ──
$stmtMandats = $pdo->prepare("
    SELECT m.*, b.reference_bien, b.designation AS bien_designation
    FROM mandats m
    LEFT JOIN biens b ON b.id = m.id_bien
    WHERE m.id_proprietaire = ?
    ORDER BY m.date_debut DESC
");
$stmtMandats->execute([$propId]);
$mandats = $stmtMandats->fetchAll(PDO::FETCH_ASSOC);

// ── Charger les documents (GED) ──
$stmtDocs = $pdo->prepare("
    SELECT bd.*, b.reference_bien
    FROM bailleur_documents bd
    LEFT JOIN biens b ON b.id = bd.id_bien
    WHERE bd.id_proprietaire = ?
    ORDER BY bd.date_upload DESC
");
$stmtDocs->execute([$propId]);
$docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

$docTypeLabels = [
    'mandat'     => 'Mandat signé',
    'crg'        => 'Compte rendu de gestion',
    'ag'         => 'Assemblée générale',
    'facture'    => 'Facture',
    'quittance'  => 'Quittance',
    'bail'       => 'Bail',
    'etat_lieux' => 'État des lieux',
    'assurance'  => 'Assurance',
    'diagnostics'=> 'Diagnostics',
    'courrier'   => 'Courrier',
    'autre'      => 'Autre',
];

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

.pf-container { max-width: 1100px; margin: 0 auto; padding: 20px; }

.pf-head { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.pf-head-back { color: var(--ink-muted, #888); text-decoration: none; font-size: 13px; }
.pf-head-back:hover { color: var(--ink); }
.pf-head h1 { font-size: 1.4rem; font-weight: 700; margin: 0; }
.pf-head-type { font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600; }
.pf-head-type-physique { background: #dbeafe; color: #1e40af; }
.pf-head-type-morale { background: #dcfce7; color: #166534; }

.pf-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--stroke, #eee); margin-bottom: 20px; }
.pf-tab { padding: 10px 18px; font-size: 13px; font-weight: 600; color: var(--ink-muted, #888); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all .2s; }
.pf-tab:hover { color: var(--ink); }
.pf-tab.active { color: var(--accent, #f7941d); border-bottom-color: var(--accent, #f7941d); }
.pf-tab-count { font-size: 10px; font-weight: 700; background: var(--bg-subtle, #f0f0f0); padding: 1px 6px; border-radius: 10px; margin-left: 4px; }

.pf-panel { display: none; }
.pf-panel.active { display: block; }

.pf-card { background: var(--card-bg, #fff); border-radius: 12px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.06); margin-bottom: 16px; }
.pf-card-title { font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--ink, #1a1a2e); }

.pf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.pf-field { display: flex; flex-direction: column; gap: 3px; }
.pf-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--ink-muted, #888); }
.pf-field input, .pf-field select, .pf-field textarea { padding: 8px 10px; border: 1px solid var(--stroke, #ddd); border-radius: 8px; font-size: 13px; }
.pf-field textarea { min-height: 80px; resize: vertical; }

.pf-table { width: 100%; border-collapse: separate; border-spacing: 0; background: var(--card-bg, #fff); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.pf-table th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--ink-muted, #888); padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--stroke, #eee); background: var(--bg-subtle, #fafafa); }
.pf-table td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid var(--stroke, #f0f0f0); }
.pf-table tr:last-child td { border-bottom: none; }
.pf-table tr:hover td { background: rgba(247,148,29,0.03); }
.pf-table a { color: var(--accent, #f7941d); text-decoration: none; font-weight: 600; }

.pf-btn { padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.pf-btn-primary { background: var(--accent, #f7941d); color: #fff; }
.pf-btn-ghost { background: var(--bg-subtle, #f0f0f0); color: var(--ink, #333); }
.pf-btn-danger { background: #fee2e2; color: #991b1b; }
.pf-btn:hover { opacity: .85; }

.pf-badge { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 20px; font-weight: 600; }
.pf-badge-actif { background: #dcfce7; color: #166534; }
.pf-badge-inactif { background: #fee2e2; color: #991b1b; }
.pf-badge-archive { background: #f3f4f6; color: #6b7280; }

.pf-upload { background: var(--card-bg, #fff); border: 2px dashed var(--stroke, #ddd); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.pf-upload-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
.pf-upload-grid .pf-field { margin: 0; }

.pf-toast { position: fixed; top: 16px; right: 16px; z-index: 99999; max-width: 400px; padding: 12px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; box-shadow: 0 4px 20px rgba(0,0,0,.12); background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }

@media (max-width: 768px) {
    .pf-upload-grid { grid-template-columns: 1fr; }
    .pf-tabs { overflow-x: auto; }
}
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
    <?php
      // Retour vers la page d'origine (ex: Card Proprio depuis bien_detail)
      // On valide le param pour bloquer les URLs externes (phishing) : on
      // n'accepte qu'un chemin local commencant par /.
      $returnUrl = isset($_GET['return']) ? (string)$_GET['return'] : '';
      if ($returnUrl !== '' && !preg_match('#^/[A-Za-z0-9_\-./?=&%]+$#', $returnUrl)) {
          $returnUrl = '';
      }
    ?>
    <?php if ($returnUrl !== ''): ?>
      <a href="<?= h(app_url($returnUrl)) ?>"
         class="topbar-nav-btn"
         style="width:auto;padding:0 12px;gap:6px;font-size:12px;font-weight:600;"
         title="Retour au bien d'origine">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
        <span>Retour au bien</span>
      </a>
    <?php endif; ?>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <span>Agency</span> <span style="color:#ccc;">›</span>
      <a href="agency_proprietaires.php" style="color:var(--ink-muted,#888);text-decoration:none;">Propriétaires</a>
      <span style="color:#ccc;">›</span>
      <span class="active"><?= h($fullName) ?></span>
    </nav>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

<div class="pf-container">

  <?php if ($msg): ?>
  <div class="pf-toast" id="pf-toast"><?= h($msg) ?>
    <button onclick="this.parentElement.remove()" style="margin-left:12px;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:.6;">✕</button>
  </div>
  <script>setTimeout(() => { const t = document.getElementById('pf-toast'); if (t) { t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(() => t.remove(), 300); }}, 4000);</script>
  <?php endif; ?>

  <!-- HEAD -->
  <div class="pf-head">
    <a href="agency_proprietaires.php" class="pf-head-back">← Propriétaires</a>
    <h1><?= h($fullName) ?></h1>
    <span class="pf-head-type pf-head-type-<?= h($prop['type_personne'] ?: 'physique') ?>">
      <?= ($prop['type_personne'] ?? 'physique') === 'morale' ? 'Personne morale' : 'Personne physique' ?>
    </span>
  </div>

  <!-- TABS -->
  <nav class="pf-tabs">
    <a href="?id=<?= $propId ?>&tab=infos" class="pf-tab <?= $tab === 'infos' ? 'active' : '' ?>">Informations</a>
    <a href="?id=<?= $propId ?>&tab=biens" class="pf-tab <?= $tab === 'biens' ? 'active' : '' ?>">Biens <span class="pf-tab-count"><?= count($biens) ?></span></a>
    <a href="?id=<?= $propId ?>&tab=mandats" class="pf-tab <?= $tab === 'mandats' ? 'active' : '' ?>">Mandats <span class="pf-tab-count"><?= count($mandats) ?></span></a>
    <a href="?id=<?= $propId ?>&tab=documents" class="pf-tab <?= $tab === 'documents' ? 'active' : '' ?>">Documents <span class="pf-tab-count"><?= count($docs) ?></span></a>
  </nav>

  <!-- ══════════ ONGLET INFOS ══════════ -->
  <div class="pf-panel <?= $tab === 'infos' ? 'active' : '' ?>">
    <form method="post">
      <?= csrf_field('proprio_fiche') ?>
      <input type="hidden" name="_action" value="update_info">

      <div class="pf-card">
        <div class="pf-card-title">Identité</div>
        <div class="pf-grid">
          <div class="pf-field">
            <label>Type</label>
            <select name="type_personne">
              <option value="physique" <?= ($prop['type_personne'] ?? '') === 'physique' ? 'selected' : '' ?>>Physique</option>
              <option value="morale" <?= ($prop['type_personne'] ?? '') === 'morale' ? 'selected' : '' ?>>Morale</option>
            </select>
          </div>
          <div class="pf-field">
            <label>Civilité</label>
            <select name="civilite">
              <option value="">—</option>
              <?php foreach (['M.','Mme','Mlle'] as $c): ?>
              <option value="<?= h($c) ?>" <?= ($prop['civilite'] ?? '') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="pf-field"><label>Nom</label><input type="text" name="nom" value="<?= h($prop['nom'] ?? '') ?>" required></div>
          <div class="pf-field"><label>Prénom</label><input type="text" name="prenom" value="<?= h($prop['prenom'] ?? '') ?>"></div>
          <div class="pf-field"><label>Société / SCI</label><input type="text" name="societe" value="<?= h($prop['societe'] ?? '') ?>"></div>
          <div class="pf-field"><label>Forme juridique</label><input type="text" name="forme_juridique" value="<?= h($prop['forme_juridique'] ?? '') ?>" placeholder="SCI, SARL..."></div>
          <div class="pf-field"><label>SIREN</label><input type="text" name="siren" value="<?= h($prop['siren'] ?? '') ?>"></div>
          <div class="pf-field"><label>Régie</label><input type="text" name="regie" value="<?= h($prop['regie'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="pf-card">
        <div class="pf-card-title">Coordonnées</div>
        <div class="pf-grid">
          <div class="pf-field"><label>Email</label><input type="email" name="email" value="<?= h($prop['email'] ?? '') ?>"></div>
          <div class="pf-field"><label>Téléphone</label><input type="tel" name="telephone" value="<?= h($prop['telephone'] ?? '') ?>"></div>
          <div class="pf-field"><label>Téléphone 2</label><input type="tel" name="telephone_2" value="<?= h($prop['telephone_2'] ?? '') ?>"></div>
          <div class="pf-field"><label>Adresse</label><input type="text" name="adresse_1" value="<?= h($prop['adresse_1'] ?? '') ?>"></div>
          <div class="pf-field"><label>Adresse 2</label><input type="text" name="adresse_2" value="<?= h($prop['adresse_2'] ?? '') ?>"></div>
          <div class="pf-field"><label>Code postal</label><input type="text" name="code_postal" value="<?= h($prop['code_postal'] ?? '') ?>"></div>
          <div class="pf-field"><label>Ville</label><input type="text" name="ville" value="<?= h($prop['ville'] ?? '') ?>"></div>
          <div class="pf-field"><label>Pays</label><input type="text" name="pays" value="<?= h($prop['pays'] ?? '') ?>" placeholder="France"></div>
        </div>
      </div>

      <div class="pf-card">
        <div class="pf-card-title">Notes</div>
        <div class="pf-grid" style="grid-template-columns:1fr 1fr;">
          <div class="pf-field"><label>Commentaire</label><textarea name="commentaire"><?= h($prop['commentaire'] ?? '') ?></textarea></div>
          <div class="pf-field"><label>Notes internes</label><textarea name="notes_internes"><?= h($prop['notes_internes'] ?? '') ?></textarea></div>
        </div>
      </div>

      <button type="submit" class="pf-btn pf-btn-primary">Enregistrer les modifications</button>
    </form>
  </div>

  <!-- ══════════ ONGLET BIENS ══════════ -->
  <div class="pf-panel <?= $tab === 'biens' ? 'active' : '' ?>">
    <?php if (empty($biens)): ?>
    <div class="pf-card"><div style="text-align:center;padding:20px;color:#888;">Aucun bien rattaché à ce propriétaire.</div></div>
    <?php else: ?>
    <table class="pf-table">
      <thead>
        <tr>
          <th>Référence</th>
          <th>Désignation</th>
          <th>Type</th>
          <th>Adresse</th>
          <th>Surface</th>
          <th>Transaction</th>
          <th>Prix</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($biens as $b):
          $statutClass = match($b['statut_bien'] ?? '') {
              'actif'   => 'actif',
              'archive' => 'archive',
              default   => 'inactif',
          };
      ?>
        <tr>
          <td><a href="bien_ajouter.php?edit=<?= (int)$b['id'] ?>"><?= h($b['reference_bien'] ?: '#' . $b['id']) ?></a></td>
          <td><?= h($b['designation'] ?? '—') ?></td>
          <td style="font-size:12px;"><?= h($b['type_bien_label'] ?? '—') ?></td>
          <td style="font-size:12px;"><?= h(($b['adresse_1'] ?? '') . ' ' . ($b['code_postal'] ?? '') . ' ' . ($b['ville'] ?? '')) ?></td>
          <td><?= $b['surface_habitable'] ? $b['surface_habitable'] . ' m²' : '—' ?></td>
          <td style="font-size:12px;"><?= h($b['type_transaction'] ?? '—') ?></td>
          <td style="font-size:12px;"><?= $b['prix'] ? number_format((float)$b['prix'], 0, ',', ' ') . ' €' : '—' ?></td>
          <td><span class="pf-badge pf-badge-<?= $statutClass ?>"><?= h($b['statut_bien'] ?? '—') ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ══════════ ONGLET MANDATS ══════════ -->
  <div class="pf-panel <?= $tab === 'mandats' ? 'active' : '' ?>">
    <?php if (empty($mandats)): ?>
    <div class="pf-card"><div style="text-align:center;padding:20px;color:#888;">Aucun mandat pour ce propriétaire.</div></div>
    <?php else: ?>
    <table class="pf-table">
      <thead>
        <tr>
          <th>N° Mandat</th>
          <th>Bien</th>
          <th>Type</th>
          <th>Nature</th>
          <th>Début</th>
          <th>Fin</th>
          <th>Honoraires</th>
          <th>Statut</th>
          <th>PDF</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($mandats as $m): ?>
        <tr>
          <td style="font-weight:600;"><?= h($m['numero_mandat'] ?? '—') ?></td>
          <td><a href="bien_ajouter.php?edit=<?= (int)$m['id_bien'] ?>"><?= h($m['reference_bien'] ?? '#' . $m['id_bien']) ?></a></td>
          <td style="font-size:12px;"><?= h($m['type_mandat'] ?? '—') ?></td>
          <td style="font-size:12px;"><?= h($m['nature_mandat'] ?? '—') ?> <?= $m['exclusif'] ? '<span style="color:#f7941d;font-weight:700;">Exclusif</span>' : '' ?></td>
          <td style="font-size:12px;"><?= $m['date_debut'] ? date('d/m/Y', strtotime($m['date_debut'])) : '—' ?></td>
          <td style="font-size:12px;"><?= $m['date_fin'] ? date('d/m/Y', strtotime($m['date_fin'])) : '—' ?></td>
          <td style="font-size:12px;"><?= $m['honoraires'] ? number_format((float)$m['honoraires'], 2, ',', ' ') . ' €' : '—' ?></td>
          <td><span class="pf-badge pf-badge-<?= ($m['statut'] ?? '') === 'actif' ? 'actif' : 'inactif' ?>"><?= h($m['statut'] ?? '—') ?></span></td>
          <td><?php if ($m['document_pdf']): ?><a href="<?= h($m['document_pdf']) ?>" target="_blank" class="pf-btn pf-btn-ghost" style="padding:3px 8px;">PDF</a><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ══════════ ONGLET DOCUMENTS (GED) ══════════ -->
  <div class="pf-panel <?= $tab === 'documents' ? 'active' : '' ?>">

    <!-- Upload -->
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field('proprio_fiche') ?>
      <input type="hidden" name="_action" value="upload_doc">
      <div class="pf-upload">
        <div class="pf-upload-grid">
          <div class="pf-field">
            <label>Fichier</label>
            <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" required>
          </div>
          <div class="pf-field">
            <label>Type</label>
            <select name="type_document">
              <?php foreach ($docTypeLabels as $k => $v): ?>
              <option value="<?= h($k) ?>"><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="pf-field">
            <label>Titre</label>
            <input type="text" name="titre" placeholder="Optionnel">
          </div>
          <button type="submit" class="pf-btn pf-btn-primary" style="height:38px;">Ajouter</button>
        </div>
      </div>
    </form>

    <?php if (empty($docs)): ?>
    <div class="pf-card"><div style="text-align:center;padding:20px;color:#888;">Aucun document. Utilisez le formulaire ci-dessus pour ajouter.</div></div>
    <?php else: ?>
    <table class="pf-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Titre / Fichier</th>
          <th>Bien</th>
          <th>Date</th>
          <th>Taille</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($docs as $d):
          $typeLabel = $docTypeLabels[$d['type_document']] ?? $d['type_document'];
          $sizeStr = ($d['taille'] ?? 0) > 1048576
              ? round($d['taille'] / 1048576, 1) . ' Mo'
              : round(($d['taille'] ?? 0) / 1024) . ' Ko';
      ?>
        <tr>
          <td style="font-size:12px;font-weight:600;"><?= h($typeLabel) ?></td>
          <td><?= h($d['titre'] ?: $d['nom_fichier']) ?></td>
          <td style="font-size:12px;"><?= $d['reference_bien'] ? h($d['reference_bien']) : '—' ?></td>
          <td style="font-size:12px;white-space:nowrap;"><?= $d['date_upload'] ? date('d/m/Y', strtotime($d['date_upload'])) : '—' ?></td>
          <td style="font-size:12px;"><?= $sizeStr ?></td>
          <td style="text-align:right;">
            <a href="<?= h($d['chemin_fichier']) ?>" target="_blank" class="pf-btn pf-btn-ghost">Voir</a>
            <a href="<?= h($d['chemin_fichier']) ?>" download class="pf-btn pf-btn-ghost">Télécharger</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce document ?')">
              <?= csrf_field('proprio_fiche') ?>
              <input type="hidden" name="_action" value="delete_doc">
              <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="pf-btn pf-btn-danger">Suppr.</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
