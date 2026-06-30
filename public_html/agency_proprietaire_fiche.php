<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_document_links.php';  // Sprint 7D : dual-write GED centrale
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
                $upType   = (string)($_POST['type_document'] ?? 'autre');
                $upTitre  = trim((string)($_POST['titre'] ?? '')) ?: pathinfo($file['name'], PATHINFO_FILENAME);
                $upComment= trim((string)($_POST['commentaire_doc'] ?? '')) ?: null;

                // Legacy INSERT bailleur_documents (preserve UI actuelle)
                $pdo->prepare("
                    INSERT INTO bailleur_documents
                        (id_proprietaire, type_document, titre, nom_fichier, chemin_fichier, taille, mime_type, uploaded_by, date_upload, commentaire)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ")->execute([
                    $propId, $upType, $upTitre, $file['name'], $relPath,
                    $file['size'], $file['type'], $userId, $upComment,
                ]);

                // ─── Sprint 7D (2026-05-25) : dual-write GED CENTRALE UNIQUE ───
                try {
                    // proprietaires n'a pas id_societe → passe par tiers
                    $stP = $pdo->prepare("SELECT p.id_tiers, t.id_societe,
                                                  COALESCE(t.id_agence, p.id_agence) AS id_agence,
                                                  s.raison_sociale AS soc_raison,
                                                  a.code_agence, a.nom_agence
                                            FROM proprietaires p
                                            LEFT JOIN tiers    t ON t.id = p.id_tiers
                                            LEFT JOIN societes s ON s.id = t.id_societe
                                            LEFT JOIN agences  a ON a.id = COALESCE(t.id_agence, p.id_agence)
                                            WHERE p.id = ? LIMIT 1");
                    $stP->execute([$propId]);
                    $propCtx = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
                    $tiersId = (int)($propCtx['id_tiers'] ?? 0);
                    $socIdGed = (int)($propCtx['id_societe'] ?? 0) ?: 1;

                    if ($tiersId > 0) {
                        gus_commit_document(
                            $pdo,
                            [
                                'path_on_disk'  => $destPath,
                                'name_original' => $file['name'],
                                'mime_type'     => $file['type'] ?? 'application/octet-stream',
                                'size_bytes'    => (int)$file['size'],
                                'public_url'    => $relPath,
                            ],
                            [
                                'document_type'  => strtoupper($upType),
                                'source_module'  => '03_GESTION_LOCATIVE',
                                'security_level' => 'interne',
                                'societe_id'     => $socIdGed,
                                'tenant_id'      => $socIdGed,
                                'created_by'     => $userId,
                                'storage_provider' => 'local',
                                'metadata_extra' => [
                                    'titre_user'  => $upTitre,
                                    'commentaire' => $upComment,
                                    'classement'  => ['tiers_proprio_id' => $tiersId],
                                    'legacy_source' => 'agency_proprietaire_fiche',
                                    'legacy_bailleur_proprio_id' => $propId,
                                ],
                                'naming_ctx' => [
                                    'societe_raison' => $propCtx['soc_raison'] ?? 'Régie EMERY',
                                    'agence_code'    => $propCtx['code_agence'] ?? 'RE69-2',
                                    'agence_nom'     => $propCtx['nom_agence']  ?? 'LYON',
                                    'user_id'        => $userId,
                                    'n1_slug'        => '03_gestion_locative',
                                    'n2_slug'        => 'proprietaires',
                                    'n3_slug'        => strtolower($upType),
                                    'type_doc'       => strtoupper($upType),
                                    'entity_type'    => 'TIERS',
                                    'entity_id'      => $tiersId,
                                    'source_filename'=> $file['name'],
                                ],
                            ],
                            [['entity_type' => 'TIERS', 'entity_id' => $tiersId, 'relation_type' => 'main']]
                        );
                    }
                } catch (Throwable $exGed) {
                    error_log('[agency_proprio_fiche] dual-write GED failed: ' . $exGed->getMessage());
                }
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

// ── Immeubles du propriétaire (cible possible pour TF / docs d'immeuble) ──
$stmtImm = $pdo->prepare("SELECT DISTINCT i.id, COALESCE(NULLIF(i.nom_immeuble,''), i.adresse_1, CONCAT('Immeuble #', i.id)) AS label
                          FROM immeubles i JOIN biens b ON b.id_immeuble = i.id
                          WHERE b.id_proprietaire = ? ORDER BY label");
$stmtImm->execute([$propId]);
$immeublesProp = $stmtImm->fetchAll(PDO::FETCH_ASSOC);

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

// ── Dossiers CRÉANCIERS liés à ce propriétaire (via son tiers) ──
$dossiersCreanciers = [];
$tiersIdProp = (int)($prop['id_tiers'] ?? 0);
if ($tiersIdProp > 0) {
    try {
        $stCre = $pdo->prepare("
            SELECT DISTINCT cd.id, cd.code, cd.libelle, cd.statut, cd.niveau_risque,
                   cd.numero_dossier_adverse, cdl.role_dossier
            FROM creancier_dossier_lien cdl
            JOIN creancier_dossier cd ON cd.id = cdl.id_dossier
            WHERE cdl.entity_type = 'TIERS' AND cdl.entity_id = ?
            ORDER BY FIELD(cd.niveau_risque,'rouge','orange','vert'), cd.updated_at DESC
        ");
        $stCre->execute([$tiersIdProp]);
        $dossiersCreanciers = $stCre->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // tables créanciers absentes (branche/migration non déployée) → on ignore
        $dossiersCreanciers = [];
    }
}

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

<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
/* Styles spécifiques fiche propriétaire (tabs, form grid, table, upload) —
   le reste (topbar, page-head, bl-btn, etc.) est hérité de liste_layout.css. */

.pf-container { padding: 20px 28px 60px; flex: 1; }

/* Type badge à côté du titre */
.pf-head-type {
  font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600;
  display: inline-block; letter-spacing: .04em;
}
.pf-head-type-physique { background: rgba(54,87,125,.12); color: var(--accent); }
.pf-head-type-morale   { background: rgba(122,144,96,.15); color: #4d6b3a; }

/* Tabs */
.pf-tabs {
  display: flex; gap: 4px; border-bottom: 2px solid var(--stroke);
  margin-bottom: 20px; flex-wrap: wrap;
}
.pf-tab {
  padding: 10px 18px; font-size: 13px; font-weight: 600;
  color: var(--muted); text-decoration: none;
  border-bottom: 2px solid transparent; margin-bottom: -2px;
  transition: color .2s, border-color .2s;
  font-family: inherit;
}
.pf-tab:hover { color: var(--ink); }
.pf-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.pf-tab-count {
  font-size: 10px; font-weight: 700;
  background: var(--bg); padding: 2px 8px; border-radius: 10px; margin-left: 4px;
  box-shadow: var(--neu-in);
}

.pf-panel { display: none; }
.pf-panel.active { display: block; }

/* Cards (fond neumorphique comme bien_liste) */
.pf-card {
  background: var(--card);
  border-radius: var(--r-lg);
  box-shadow: var(--neu-out);
  padding: 20px;
  margin-bottom: 16px;
}
.pf-card-title {
  font-family: 'DM Mono', monospace;
  font-size: 11px; font-weight: 500;
  letter-spacing: 1.2px; text-transform: uppercase;
  color: var(--accent-3); margin-bottom: 14px;
}

/* Form grid */
.pf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.pf-field { display: flex; flex-direction: column; gap: 4px; }
.pf-field label {
  font-size: 10px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .5px;
  color: var(--muted);
}
.pf-field input,
.pf-field select,
.pf-field textarea {
  padding: 9px 12px;
  background: var(--bg);
  border: none;
  border-radius: var(--r-sm);
  color: var(--ink);
  font-size: 13px;
  font-family: inherit;
  box-shadow: var(--neu-in);
  outline: none;
  transition: box-shadow .15s;
}
.pf-field input:focus,
.pf-field select:focus,
.pf-field textarea:focus { box-shadow: var(--neu-in), 0 0 0 2px rgba(54,87,125,.2); }
.pf-field textarea { min-height: 90px; resize: vertical; }

/* Table */
.pf-table {
  width: 100%;
  border-collapse: separate; border-spacing: 0;
  background: var(--card);
  border-radius: var(--r-lg);
  overflow: hidden;
  box-shadow: var(--neu-out);
}
.pf-table th {
  font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .5px;
  color: var(--muted);
  padding: 12px 14px; text-align: left;
  border-bottom: 1px solid var(--stroke);
  background: var(--bg);
}
.pf-table td {
  padding: 12px 14px; font-size: 13px;
  border-bottom: 1px solid var(--stroke);
  vertical-align: middle;
}
.pf-table tr:last-child td { border-bottom: none; }
.pf-table tr:hover td { background: rgba(54,87,125,.03); }
.pf-table a { color: var(--accent); text-decoration: none; font-weight: 600; }
.pf-table a:hover { text-decoration: underline; }

/* Badges statut (bien / mandat) */
.pf-badge {
  display: inline-block;
  font-size: 10px; padding: 3px 10px;
  border-radius: var(--r-pill); font-weight: 700;
  letter-spacing: .04em;
}
.pf-badge-actif    { background: #f0fdf4; color: #166534; }
.pf-badge-inactif  { background: #fef2f2; color: #991b1b; }
.pf-badge-archive  { background: rgba(138,134,128,.15); color: var(--muted); }

/* Upload zone documents */
.pf-upload {
  background: var(--card);
  border: 2px dashed rgba(54,87,125,.25);
  border-radius: var(--r-lg);
  padding: 16px;
  margin-bottom: 16px;
}
.pf-upload-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
.pf-upload-grid .pf-field { margin: 0; }

/* Bouton danger (suppr document) */
.bl-btn-danger {
  background: #fef2f2; color: #991b1b;
  box-shadow: var(--neu-out);
}

/* Toast */
.pf-toast {
  position: fixed; top: 70px; right: 24px; z-index: 9999;
  max-width: 400px; padding: 12px 18px;
  border-radius: var(--r-md);
  font-size: 13px; font-weight: 600;
  box-shadow: var(--neu-out);
  background: #f0fdf4; color: #14532d;
  border: 1px solid #bbf7d0;
}

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

<?php if ($msg): ?>
<div class="pf-toast" id="pf-toast"><?= h($msg) ?>
  <button onclick="this.parentElement.remove()" style="margin-left:12px;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:.6;">✕</button>
</div>
<script>setTimeout(() => { const t = document.getElementById('pf-toast'); if (t) { t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(() => t.remove(), 300); }}, 4000);</script>
<?php endif; ?>

<!-- PAGE HEAD (style bien_liste) -->
<div class="page-head">
  <div class="page-head-info">
    <div class="page-head-label">Gestion des propriétaires</div>
    <h1 class="page-head-title"><?= h($fullName) ?>
      <span class="pf-head-type pf-head-type-<?= h($prop['type_personne'] ?: 'physique') ?>" style="margin-left:12px;vertical-align:middle;font-size:13px;">
        <?= ($prop['type_personne'] ?? 'physique') === 'morale' ? '🏢 Personne morale' : '👤 Personne physique' ?>
      </span>
    </h1>
    <div class="page-head-sub">Fiche complète modifiable · biens, mandats, documents</div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <span title="ID à utiliser pour la suppression (outil super-admin)"
            style="display:inline-flex;align-items:center;gap:6px;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:6px;padding:3px 9px;font-size:12px;font-weight:700;font-family:monospace;">
        🆔 ID propriétaire : <?= (int)$propId ?>
        <button type="button" onclick="navigator.clipboard.writeText('<?= (int)$propId ?>');this.textContent='✓';setTimeout(()=>this.textContent='📋',1200);"
                style="border:none;background:transparent;cursor:pointer;font-size:12px;padding:0;" title="Copier l'ID">📋</button>
      </span>
      <?php if (!empty($prop['id_tiers'])): ?>
      <span title="ID tiers (référence interne — PAS celui de la suppression)"
            style="display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:6px;padding:3px 9px;font-size:12px;font-weight:600;font-family:monospace;">
        id_tiers : <?= (int)$prop['id_tiers'] ?>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <button type="button" class="bl-btn" id="odClasserBtn" onclick="odClasserOpen()"
            style="background:linear-gradient(135deg,#5e35b1,#7e57c2);color:#fff;border:none;">📥 Importer docs OneDrive</button>
    <button type="button" class="bl-btn" onclick="odOpenFolder()"
            style="background:#fff;color:#5e35b1;border:1px solid #b39ddb;">📂 Ouvrir le dossier OneDrive</button>
    <a href="agency_proprietaires.php" class="bl-btn bl-btn-ghost">← Liste des propriétaires</a>
  </div>
</div>

<!-- ── Modal classement OneDrive → GED (dry-run puis validation) ── -->
<div id="odModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Documents OneDrive — <?= h($fullName) ?></h3>
      <button type="button" onclick="document.getElementById('odModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <div id="odBody" style="flex:1;overflow:auto;padding:16px 18px;font-size:13px;"><div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div></div>
    <div style="padding:12px 18px;border-top:1px solid #eef0f2;display:flex;gap:10px;align-items:center;">
      <button type="button" id="odCommitBtn" onclick="odClasserCommit()" disabled
              style="background:#2d8a4e;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;opacity:.5;">✓ Valider et classer</button>
      <span id="odMsg" style="font-size:12.5px;font-weight:700;"></span>
    </div>
  </div>
</div>
<script>
(function(){
  var PID=<?= (int)$propId ?>, CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function post(action){var fd=new FormData();fd.append('csrf_token',CSRF);fd.append('id_proprietaire',PID);fd.append('action',action);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  window.odOpenFolder=function(){
    var w=window.open('','_blank'); if(w)w.document.write('Ouverture du dossier OneDrive…');
    post('folder_url').then(function(j){
      if(j&&j.ok&&j.url){ if(w){w.location.href=j.url;}else{window.location.href=j.url;} }
      else { if(w)w.close(); alert('❌ '+((j&&j.error)||'Dossier OneDrive introuvable')); }
    }).catch(function(e){ if(w)w.close(); alert('❌ Réseau : '+e); });
  };
  window.odClasserOpen=function(){
    document.getElementById('odModal').style.display='flex';
    document.getElementById('odCommitBtn').disabled=true; document.getElementById('odCommitBtn').style.opacity=.5;
    document.getElementById('odMsg').textContent='';
    document.getElementById('odBody').innerHTML='<div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div>';
    post('scan').then(function(j){
      if(!j||!j.ok){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ '+esc((j&&j.error)||'Erreur')+(j&&j.base?'<br><small>base: '+esc(j.base)+'</small>':'')+'</div>';return;}
      var rows=(j.items||[]).map(function(it){
        var col=it.status==='certain'?'#2d8a4e':(it.status==='pile'?'#8a6d1b':'#c62828');
        var cible=it.target==='PROPRIO'?'→ propriétaire':(it.target==='BAIL'?('→ bail #'+it.bail_id+' / bien #'+it.bien_id):(it.target==='BIEN'?('→ bien #'+it.bien_id):'→ pile'));
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;margin-top:2px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">'+esc(cible)+'</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason)+'</td></tr>';
      }).join('');
      var nbCertain=(j.items||[]).filter(function(x){return x.status==='certain';}).length;
      document.getElementById('odBody').innerHTML=
        '<div style="margin-bottom:8px;color:#6b7280;">Dossier OneDrive : <b>'+esc(j.folder)+'</b> · biens '+j.nb_biens+' · baux '+j.nb_baux+' · <b>'+nbCertain+'</b> doc(s) à classer / '+(j.items||[]).length+'.</div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(rows||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun document mandat/bail/EDL/DPE détecté.</td></tr>')+'</tbody></table>';
      var b=document.getElementById('odCommitBtn'); if(nbCertain>0){b.disabled=false;b.style.opacity=1;}
    }).catch(function(e){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>';});
  };
  window.odClasserCommit=function(){
    var b=document.getElementById('odCommitBtn'),m=document.getElementById('odMsg');
    b.disabled=true;b.style.opacity=.5;m.style.color='#6b7280';m.textContent='⏳ Classement en cours…';
    post('commit').then(function(j){
      if(!j||!j.ok){m.style.color='#c62828';m.textContent='❌ '+esc((j&&j.error)||'Erreur');return;}
      m.style.color='#2d8a4e';m.textContent='✓ '+j.classes+' document(s) classé(s) en GED'+(j.pile?(' · '+j.pile+' en pile'):'')+(j.erreurs&&j.erreurs.length?(' · '+j.erreurs.length+' erreur(s)'):'')+'. Recharge la page pour voir les docs.';
    }).catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau : '+esc(e);});
  };
})();
</script>

<?php if (!empty($dossiersCreanciers)): $nbCre = count($dossiersCreanciers); ?>
<div style="margin:0 28px 14px;">
  <a href="<?= h(app_url('/creancier_liste.php?tiers=' . $tiersIdProp)) ?>"
     title="Voir les dossiers créanciers / saisies de ce propriétaire"
     style="display:inline-flex;align-items:center;gap:8px;background:#dc2626;color:#fff;font-weight:700;font-size:13.5px;padding:10px 16px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(220,38,38,.3);">
    🚨 Dossier<?= $nbCre > 1 ? 's' : '' ?> créancier<?= $nbCre > 1 ? 's' : '' ?> en cours<?= $nbCre > 1 ? ' (' . $nbCre . ')' : '' ?>
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
  </a>
</div>
<?php endif; ?>

<div class="pf-container">

  <!-- TABS -->
  <nav class="pf-tabs">
    <a href="?id=<?= $propId ?>&tab=infos" class="pf-tab <?= $tab === 'infos' ? 'active' : '' ?>">Informations</a>
    <a href="?id=<?= $propId ?>&tab=biens" class="pf-tab <?= $tab === 'biens' ? 'active' : '' ?>">Biens <span class="pf-tab-count"><?= count($biens) ?></span></a>
    <a href="?id=<?= $propId ?>&tab=mandats" class="pf-tab <?= $tab === 'mandats' ? 'active' : '' ?>">Mandats <span class="pf-tab-count"><?= count($mandats) ?></span></a>
    <a href="?id=<?= $propId ?>&tab=documents" class="pf-tab <?= $tab === 'documents' ? 'active' : '' ?>">Documents <span class="pf-tab-count"><?= count($docs) ?></span></a>
    <a href="?id=<?= $propId ?>&tab=pile" class="pf-tab <?= $tab === 'pile' ? 'active' : '' ?>">📥 Pile à traiter (GED)</a>
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

      <button type="submit" class="bl-btn bl-btn-primary">Enregistrer les modifications</button>
    </form>
  </div>

  <!-- ══════════ ONGLET BIENS ══════════ -->
  <div class="pf-panel <?= $tab === 'biens' ? 'active' : '' ?>">
    <div style="margin:0 0 12px;text-align:right;">
      <a href="bien_creation.php?id_proprietaire=<?= $propId ?>" class="pf-badge pf-badge-actif"
         style="display:inline-block;padding:8px 14px;text-decoration:none;font-weight:600;">
        + Créer un bien / une annonce
      </a>
    </div>
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
          <td><?php if ($m['document_pdf']): ?><a href="<?= h($m['document_pdf']) ?>" target="_blank" class="bl-btn bl-btn-ghost" style="padding:3px 8px;">PDF</a><?php else: ?>—<?php endif; ?></td>
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
          <button type="submit" class="bl-btn bl-btn-primary" style="height:38px;">Ajouter</button>
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
            <a href="<?= h($d['chemin_fichier']) ?>" target="_blank" class="bl-btn bl-btn-ghost">Voir</a>
            <a href="<?= h($d['chemin_fichier']) ?>" download class="bl-btn bl-btn-ghost">Télécharger</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce document ?')">
              <?= csrf_field('proprio_fiche') ?>
              <input type="hidden" name="_action" value="delete_doc">
              <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="bl-btn bl-btn-danger">Suppr.</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── Onglet : Pile de docs à traiter pour la GED (docs OneDrive ambigus) ── -->
  <div class="pf-panel <?= $tab === 'pile' ? 'active' : '' ?>">
    <div class="pf-card" style="margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button type="button" class="bl-btn bl-btn-primary" id="pileScanBtn" onclick="pileScan()">🔎 Scanner le dossier OneDrive</button>
        <span id="pileMsg" style="font-size:13px;color:#6b7280;">Les documents <b>ambigus</b> (que le moteur n'a pas pu attribuer seul) s'affichent ici. Choisis le bien puis « Classer ».</span>
      </div>
    </div>
    <div id="pileBody"></div>
  </div>

</div>
</div>

<!-- Modal résolveur de pile : aperçu (gauche) + cibles en boutons (droite) -->
<div id="pileModal" style="display:none;position:fixed;inset:0;z-index:9500;background:rgba(15,18,24,.6);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1100px,96vw);height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.4);">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #eef0f2;">
      <h3 id="pileModalName" style="margin:0;font-size:15px;color:#4527a0;word-break:break-all;">Document</h3>
      <button type="button" onclick="pileCloseModal()" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <div style="flex:1;display:flex;min-height:0;">
      <div style="flex:1;border-right:1px solid #eef0f2;background:#f3f4f6;">
        <iframe id="pileModalPrev" src="" style="width:100%;height:100%;border:0;"></iframe>
      </div>
      <div style="width:340px;display:flex;flex-direction:column;padding:14px;overflow:auto;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Classer ce document dans :</div>
        <div id="pileModalBtns" style="flex:1;"></div>
        <div id="pileModalMsg" style="font-size:12px;font-weight:700;margin:8px 0;min-height:16px;"></div>
        <div style="display:flex;gap:8px;">
          <button type="button" class="bl-btn bl-btn-primary" style="flex:1;" onclick="pileValider()">✓ Valider</button>
          <button type="button" class="bl-btn bl-btn-danger" onclick="pileIgnorer()">🚫 Ignorer</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var PID2=<?= (int)$propId ?>;
  var CSRF2=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL2=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var BIENS=<?= json_encode(array_map(function($b){ return ['id'=>(int)$b['id'],'ref'=>($b['reference_bien'] ?: ('#'.$b['id'])),'lib'=>trim(($b['designation']??'').' '.($b['adresse_1']??'').' '.($b['ville']??''))]; }, $biens), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) ?: "[]" ?>;
  var IMMS=<?= json_encode(array_map(function($i){ return ['id'=>(int)$i['id'],'label'=>$i['label']]; }, $immeublesProp), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) ?: "[]" ?>;
  var PROPNOM=<?= json_encode($fullName, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) ?: "''" ?>;
  var PREVIEW=<?= json_encode(app_url('/api/graph_doc_preview.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};

  window.pileScan=function(){
    var btn=document.getElementById('pileScanBtn'), msg=document.getElementById('pileMsg'), body=document.getElementById('pileBody');
    btn.disabled=true; msg.textContent='⏳ Scan du dossier OneDrive…';
    var fd=new FormData(); fd.append('csrf_token',CSRF2); fd.append('id_proprietaire',PID2); fd.append('action','scan');
    fetch(URL2,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      btn.disabled=false;
      if(!j||!j.ok){ msg.textContent=''; body.innerHTML='<div class="pf-card" style="color:#c62828;padding:14px;">❌ '+esc((j&&j.error)||'Erreur')+'</div>'; return; }
      var pile=(j.items||[]).filter(function(it){return it.status==='pile';});
      var nbCertain=(j.items||[]).filter(function(it){return it.status==='certain';}).length;
      msg.innerHTML='Dossier <b>'+esc(j.folder||'')+'</b> · '+(j.items||[]).length+' doc(s) · <b>'+nbCertain+'</b> auto-classables · <b style="color:#b45309;">'+pile.length+'</b> en pile (à décider).';
      if(!pile.length){ body.innerHTML='<div class="pf-card" style="padding:16px;color:#166534;">✅ Aucun document ambigu. Les docs certains se classent via « Importer docs OneDrive ».</div>'; return; }
      var rows=pile.map(function(it,idx){
        return '<tr id="pile-'+idx+'">'
          +'<td style="font-size:12px;font-weight:700;">'+esc(it.type)+'<div style="color:#9a9690;font-size:10.5px;">'+esc(it.doc_type||'')+'</div></td>'
          +'<td>'+esc(it.name)+'</td>'
          +'<td style="font-size:11.5px;color:#7a766f;">'+esc(it.reason||'')+'</td>'
          +'<td style="text-align:right;"><button type="button" class="bl-btn bl-btn-primary" onclick="pileOpen('+idx+')">Traiter ▶</button>'
          +'<span class="pile-res" id="pile-res-'+idx+'" style="margin-left:8px;font-size:12px;font-weight:700;"></span></td></tr>';
      }).join('');
      body.innerHTML='<table class="pf-table"><thead><tr><th>Type</th><th>Fichier</th><th>Raison</th><th style="text-align:right;">Action</th></tr></thead><tbody>'+rows+'</tbody></table>';
      window.__pile=pile;
    }).catch(function(e){ btn.disabled=false; msg.textContent=''; body.innerHTML='<div class="pf-card" style="color:#c62828;padding:14px;">❌ Réseau : '+esc(e)+'</div>'; });
  };

  // Cible choisie dans le modal (PROPRIO / IMB:<id> / BIEN:<id>)
  var pileSel={idx:-1, target:null, id:0};
  function pileButtons(it){
    // Cible par défaut suggérée selon le type de doc
    var def = it.doc_type==='taxe_fonciere' ? (IMMS.length===1?('IMB:'+IMMS[0].id):null)
            : (it.doc_type==='mandat_gestion' ? 'PROPRIO'
            : (it.bien_id?('BIEN:'+it.bien_id):null));
    var html='';
    html+='<div style="font-size:11px;font-weight:800;color:#6b7280;margin:2px 0 4px;">PROPRIÉTAIRE</div>';
    html+='<button type="button" class="pile-tgt bl-btn bl-btn-ghost" data-t="PROPRIO" data-id="0">👤 '+esc(PROPNOM)+'</button>';
    if(IMMS.length){
      html+='<div style="font-size:11px;font-weight:800;color:#6b7280;margin:8px 0 4px;">IMMEUBLE (TF…)</div>';
      IMMS.forEach(function(im){ html+='<button type="button" class="pile-tgt bl-btn bl-btn-ghost" data-t="IMB" data-id="'+im.id+'">🏢 '+esc(im.label)+'</button>'; });
    }
    html+='<div style="font-size:11px;font-weight:800;color:#6b7280;margin:8px 0 4px;">BIEN</div>';
    BIENS.forEach(function(b){ html+='<button type="button" class="pile-tgt bl-btn bl-btn-ghost" data-t="BIEN" data-id="'+b.id+'">🔑 '+esc(b.ref)+(b.lib?(' · '+esc(b.lib)):'')+'</button>'; });
    return {html:html, def:def};
  }

  window.pileOpen=function(idx){
    var pile=window.__pile||[]; var it=pile[idx]; if(!it) return;
    pileSel={idx:idx, target:null, id:0};
    var m=document.getElementById('pileModal');
    document.getElementById('pileModalName').textContent=it.name;
    // #navpanes=0 = pas de panneau de vignettes de pages ; toolbar=0 ; ajusté largeur
    document.getElementById('pileModalPrev').src=PREVIEW+'?item_id='+encodeURIComponent(it.item_id)+'#toolbar=0&navpanes=0&scrollbar=1&view=FitH';
    var b=pileButtons(it);
    document.getElementById('pileModalBtns').innerHTML=b.html;
    document.getElementById('pileModalMsg').textContent='';
    // câblage des boutons cible (sélection visuelle)
    Array.prototype.slice.call(document.querySelectorAll('#pileModalBtns .pile-tgt')).forEach(function(btn){
      btn.style.display='block'; btn.style.width='100%'; btn.style.textAlign='left'; btn.style.marginBottom='4px';
      btn.addEventListener('click',function(){
        document.querySelectorAll('#pileModalBtns .pile-tgt').forEach(function(x){x.classList.remove('bl-btn-primary');x.classList.add('bl-btn-ghost');});
        this.classList.add('bl-btn-primary'); this.classList.remove('bl-btn-ghost');
        pileSel.target=this.getAttribute('data-t'); pileSel.id=parseInt(this.getAttribute('data-id'),10)||0;
      });
      // pré-sélection par défaut
      if(b.def && (btn.getAttribute('data-t')+(btn.getAttribute('data-id')!=='0'?':'+btn.getAttribute('data-id'):''))===b.def){ btn.click(); }
    });
    m.style.display='flex';
  };
  window.pileCloseModal=function(){ document.getElementById('pileModal').style.display='none'; };

  window.pileValider=function(){
    var idx=pileSel.idx; var pile=window.__pile||[]; var it=pile[idx]; if(!it) return;
    if(!pileSel.target){ document.getElementById('pileModalMsg').style.color='#c62828'; document.getElementById('pileModalMsg').textContent='Choisis une cible (bouton ci-dessus).'; return; }
    var item=Object.assign({}, it, {status:'certain'});
    if(pileSel.target==='PROPRIO'){ item.target='PROPRIO'; item.bien_id=0; item.imm_id=0; item.bail_id=0; }
    else if(pileSel.target==='IMB'){ item.target='IMB'; item.imm_id=pileSel.id; item.bien_id=0; item.bail_id=0; }
    else { item.target='BIEN'; item.bien_id=pileSel.id; item.imm_id=0; item.bail_id=0; }
    var msg=document.getElementById('pileModalMsg'); msg.style.color='#8a6d1b'; msg.textContent='⏳ classement…';
    var fd=new FormData(); fd.append('csrf_token',CSRF2); fd.append('id_proprietaire',PID2); fd.append('action','commit_items'); fd.append('items', JSON.stringify([item]));
    fetch(URL2,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      if(j&&j.ok&&(j.classes>0||j.dedup>0)){
        var res=document.getElementById('pile-res-'+idx); if(res){ res.style.color='#166534'; res.textContent=j.classes>0?'✅ classé':'• déjà présent'; }
        var tr=document.getElementById('pile-'+idx); if(tr){ tr.style.opacity=.5; }
        pileCloseModal();
      } else { msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.erreurs&&j.erreurs[0])||(j&&j.error)||'échec'); }
    }).catch(function(){ msg.style.color='#c62828'; msg.textContent='❌ réseau'; });
  };

  window.pileIgnorer=function(){
    var idx=pileSel.idx; var pile=window.__pile||[]; var it=pile[idx]; if(!it) return;
    var msg=document.getElementById('pileModalMsg'); msg.style.color='#8a6d1b'; msg.textContent='⏳…';
    var fd=new FormData(); fd.append('csrf_token',CSRF2); fd.append('id_proprietaire',PID2); fd.append('action','ignore');
    fd.append('item_id', it.item_id); fd.append('name', it.name||'');
    fetch(URL2,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      if(j&&j.ok){
        var res=document.getElementById('pile-res-'+idx); if(res){ res.style.color='#9a9690'; res.textContent='🚫 ignoré'; }
        var tr=document.getElementById('pile-'+idx); if(tr){ tr.style.opacity=.4; }
        pileCloseModal();
      } else { msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'échec'); }
    }).catch(function(){ msg.style.color='#c62828'; msg.textContent='❌ réseau'; });
  };

  // Auto-scan UNIQUEMENT si on arrive via un lien « pile » explicite (?tab=pile&scan=1)
  if (<?= ($tab === 'pile' && ($_GET['scan'] ?? '') === '1') ? 'true' : 'false' ?>) { pileScan(); }
})();

// ── Onglets CLIENT-SIDE : bascule instantanée sans recharger la page ──
(function(){
  var names=['infos','biens','mandats','documents','pile'];   // ordre des .pf-panel
  var tabs=document.querySelectorAll('.pf-tab');
  var panels=document.querySelectorAll('.pf-panel');
  if(!tabs.length || panels.length!==names.length) return;     // garde-fou : on ne casse rien
  tabs.forEach(function(a){
    a.addEventListener('click',function(e){
      var m=(this.getAttribute('href')||'').match(/tab=([^&]+)/); if(!m) return;
      e.preventDefault(); var tab=m[1];
      tabs.forEach(function(x){x.classList.remove('active');}); this.classList.add('active');
      panels.forEach(function(p,i){ p.classList.toggle('active', names[i]===tab); });
      try{ history.replaceState(null,'',this.getAttribute('href')); }catch(err){}
    });
  });
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
