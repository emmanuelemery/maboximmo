<?php
declare(strict_types=1);

/**
 * Ma GED Box V1.1 — Page Import GED (super admin)
 *
 * Upload de fichiers/dossiers + tableau de préclassement + modal cascade N1→N6.
 * Génère le name_canonical automatiquement et crée des ged_documents à la
 * validation (sans toucher aux tables existantes).
 *
 * Réservé super admin (id_role = 1). Layout MBI standard (header.php).
 *
 * AJOUT uniquement.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_import_functions.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé super admin.</h1>');
}

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ─── Batch courant (depuis ?batch_id=) ────────────────────────
$batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$batch = $batchId > 0 ? ged_import_get_batch($batchId) : null;

// ─── Liste des batches récents ───────────────────────────────
$recentBatches = [];
try {
    $st = $pdo->query("SELECT id, batch_name, nb_items, nb_validated, status, created_at
                       FROM ged_import_batches
                       ORDER BY id DESC LIMIT 20");
    $recentBatches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {
    // Table pas encore créée
}

// ─── Liste N1 pour le filtre tableau ─────────────────────────
$n1Options = [];
try {
    $st = $pdo->query("SELECT code, label FROM ged_level_codes WHERE level_number = 1 AND is_active = 1 ORDER BY position ASC");
    $n1Options = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$appLayout = true;
$pageTitle = 'GED — Import super admin';
$bodyClass = '';
require_once __DIR__ . '/inc/header.php';
?>
<link rel="stylesheet" href="/css/ged_import.css?v=<?= @filemtime(__DIR__ . '/css/ged_import.css') ?: time() ?>">

<div class="gimp-wrap" data-batch-id="<?= (int)$batchId ?>">
  <h1>📥 GED — Import super admin</h1>
  <p class="sub">Importe des fichiers ou dossiers, préclasse par cascade N1→N6, génère le nom canonique, valide.</p>

  <!-- ─── Upload ──────────────────────────────────────────── -->
  <div class="gimp-upload">
    <h2>📤 Nouvel import</h2>
    <form id="gimp-upload-form">
      <div class="gimp-upload-row">
        <div>
          <label>Nom du batch</label>
          <input type="text" id="gimp-batch-name" placeholder="ex. Imports Téléchargements 2026-05">
        </div>
        <div>
          <label>Fichiers</label>
          <input type="file" id="gimp-upload-files" multiple>
        </div>
        <div>
          <label>OU dossier complet</label>
          <input type="file" id="gimp-upload-folder" webkitdirectory directory multiple>
        </div>
        <div class="gimp-upload-btns">
          <button type="submit" class="gimp-btn gimp-btn-primary">📤 Importer</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ─── Batches récents ──────────────────────────────── -->
  <?php if (!empty($recentBatches)): ?>
    <div class="gimp-upload" style="padding:10px 16px;">
      <strong style="font-size:12px;color:#475569;text-transform:uppercase;letter-spacing:.04em;">Imports récents :</strong>
      <span style="margin-left:8px;font-size:13px">
        <?php foreach ($recentBatches as $b):
          $isCur = ((int)$b['id'] === $batchId); ?>
          <a href="?batch_id=<?= (int)$b['id'] ?>" style="margin-right:10px;<?= $isCur ? 'font-weight:700;color:#0ea5e9' : 'color:#0369a1' ?>;text-decoration:none">
            <?= $h($b['batch_name']) ?>
            <small style="color:#94a3b8">(<?= (int)$b['nb_validated'] ?>/<?= (int)$b['nb_items'] ?>)</small>
          </a>
        <?php endforeach; ?>
      </span>
    </div>
  <?php endif; ?>

  <?php if ($batchId > 0): ?>

    <!-- ─── Filtres + bulk ──────────────────────────────────── -->
    <div class="gimp-toolbar">
      <strong id="gimp-batch-summary" style="font-size:13px;margin-right:14px">…</strong>

      <select id="gimp-filter-status">
        <option value="">— Tous statuts —</option>
        <option value="imported">Imported</option>
        <option value="proposed">Proposed</option>
        <option value="validated">Validated</option>
        <option value="to_review">To review</option>
        <option value="ignored">Ignored</option>
        <option value="error">Error</option>
      </select>

      <select id="gimp-filter-n1">
        <option value="">— Tous N1 —</option>
        <?php foreach ($n1Options as $o): ?>
          <option value="<?= $h($o['code']) ?>"><?= $h($o['label']) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="gimp-filter-score">
        <option value="">— Tous scores —</option>
        <option value="100">= 100</option>
        <option value="80">≥ 80</option>
        <option value="50">≥ 50</option>
      </select>

      <input type="text" id="gimp-filter-ext" placeholder="Ext (pdf, jpg…)" style="width:90px">
      <input type="search" id="gimp-filter-search" placeholder="🔍 Rechercher (nom, titre)…">

      <span style="flex:1"></span>
      <button id="gimp-validate-all-btn" class="gimp-btn gimp-btn-success">✓ Valider auto (score ≥ N)</button>
    </div>

    <!-- ─── Tableau ─────────────────────────────────────────── -->
    <div class="gimp-table-wrap">
      <table class="gimp-table">
        <thead>
          <tr>
            <th><input type="checkbox" id="gimp-check-all"></th>
            <th>Fichier original / titre</th>
            <th>N1</th>
            <th>N2</th>
            <th>N3</th>
            <th>N4</th>
            <th>N5</th>
            <th>N6</th>
            <th>Nom canonique</th>
            <th>Score</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="gimp-tbody">
          <tr><td colspan="12" style="text-align:center;color:#94a3b8;padding:30px;font-style:italic">Chargement…</td></tr>
        </tbody>
      </table>
    </div>

  <?php else: ?>
    <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:40px;text-align:center;color:#64748b">
      ⬆️ Lance un import ci-dessus, ou clique un import récent.
    </div>
  <?php endif; ?>

</div>

<!-- ─── Modal édition item ────────────────────────────────── -->
<div id="gimp-modal-bg" class="gimp-modal-bg">
  <div class="gimp-modal">
    <div class="gimp-modal-header">
      <h2 id="gimp-modal-title">Éditer item</h2>
      <button type="button" id="gimp-modal-close" class="gimp-modal-close">✕</button>
    </div>
    <div class="gimp-modal-body">

      <div class="gimp-modal-meta">
        <div>📁 <strong>Ancien chemin :</strong> <span id="gimp-meta-old-path"></span></div>
        <div>📄 <strong>Ancien nom :</strong> <span id="gimp-meta-old-name"></span></div>
        <div>🏷️ <strong>Extension :</strong> <span id="gimp-meta-ext"></span></div>
        <div>📦 <strong>MIME :</strong> <span id="gimp-meta-mime"></span></div>
        <div>📏 <strong>Taille :</strong> <span id="gimp-meta-size"></span></div>
        <div>🔐 <strong>SHA256 :</strong> <code id="gimp-meta-hash" style="font-size:10px"></code></div>
      </div>

      <!-- Cascade N1 → N5 -->
      <?php for ($lvl = 1; $lvl <= 5; $lvl++): ?>
        <div class="gimp-cascade">
          <div class="gimp-cascade-title">Niveau N<?= $lvl ?></div>
          <div class="gimp-cascade-buttons" id="gimp-cascade-l<?= $lvl ?>">
            <div class="gimp-cascade-empty">…</div>
          </div>
        </div>
      <?php endfor; ?>

      <!-- N6 libre -->
      <div class="gimp-cascade">
        <div class="gimp-cascade-title">Niveau N6 (libre)</div>
        <div class="gimp-cascade-n6">
          <input type="text" id="gimp-input-n6" placeholder="ex. PV_SIGNE, DEVIS_PORTAIL, FACTURE_ASCENSEUR…">
        </div>
      </div>

      <!-- Champs métier -->
      <div class="gimp-input-row">
        <div>
          <label>Titre humain</label>
          <input type="text" id="gimp-input-title" placeholder="ex. PV AG signé 2026">
        </div>
        <div>
          <label>Date document (YYYY-MM-DD)</label>
          <input type="date" id="gimp-input-date">
        </div>
      </div>

      <div class="gimp-input-row">
        <div>
          <label>Code société</label>
          <input type="text" id="gimp-input-societe" placeholder="ex. RE">
        </div>
        <div>
          <label>Code agence</label>
          <input type="text" id="gimp-input-agence" placeholder="ex. LY">
        </div>
      </div>

      <div class="gimp-input-row">
        <div>
          <label>Référence entité</label>
          <input type="text" id="gimp-input-ref-entite" placeholder="ex. 2004 (n° immeuble)">
        </div>
        <div>
          <label>Nom entité (15 car. max)</label>
          <input type="text" id="gimp-input-nom-entite" placeholder="ex. PARC GARIGL">
        </div>
      </div>

      <!-- Aperçu du nom canonique généré -->
      <div class="gimp-modal-canonical">
        <div class="gimp-modal-canonical-title">📛 Nom canonique généré</div>
        <code id="gimp-canonical-preview">—</code>
        <div class="gimp-modal-canonical-title" style="margin-top:8px">📂 Destination GED MBI</div>
        <code id="gimp-destination-preview">—</code>
      </div>
    </div>

    <div class="gimp-modal-footer">
      <button type="button" id="gimp-ignore-btn" class="gimp-btn gimp-btn-danger">✗ Ignorer</button>
      <button type="button" class="gimp-btn gimp-btn-warn" onclick="this.disabled=true;fetch('/api/ged_import_admin.php?action=to_review',{method:'POST',body:new URLSearchParams({action:'to_review',item_id:document.querySelector('.gimp-row.gimp-row-proposed,.gimp-row')?.dataset.id||0}),credentials:'same-origin'}).then(()=>window.location.reload())">⚠ À revoir</button>
      <span style="flex:1"></span>
      <button type="button" id="gimp-save-btn" class="gimp-btn gimp-btn-ghost">💾 Sauvegarder</button>
      <button type="button" id="gimp-save-next-btn" class="gimp-btn gimp-btn-primary">💾 Sauver &amp; fermer</button>
      <button type="button" id="gimp-validate-btn" class="gimp-btn gimp-btn-success">✓ Valider et créer doc</button>
    </div>
  </div>
</div>

<script src="/js/ged_import.js?v=<?= @filemtime(__DIR__ . '/js/ged_import.js') ?: time() ?>"></script>
