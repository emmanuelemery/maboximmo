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
      <div style="margin-top:10px;display:flex;align-items:center;gap:14px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:#475569">
          <input type="checkbox" id="gimp-mode-quick" style="cursor:pointer">
          <span>⚡ <strong>Mode rapide</strong> — TITLE/N6 optionnels, validation possible avec N1+N2+entité</span>
        </label>
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

      <select id="gimp-filter-mode" title="Filtre Mode (rapide / normalisé / à revoir)">
        <option value="">— Tous modes —</option>
        <option value="quick">⚡ Quick (rapide)</option>
        <option value="normalized">📝 Normalisé</option>
        <option value="to_review">⚠️ À revoir</option>
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

      <!-- N6 libre + autocomplete -->
      <div class="gimp-cascade">
        <div class="gimp-cascade-title">Niveau N6 (libre, suggestions IA)</div>
        <div class="gimp-cascade-n6">
          <div class="gimp-n6-wrap">
            <input type="text" id="gimp-input-n6" autocomplete="off"
                   placeholder="ex. PV_SIGNE, DEVIS_PORTAIL, FACTURE_ASCENSEUR…">
            <div id="gimp-n6-suggestions" class="gimp-n6-suggestions" style="display:none"></div>
          </div>
        </div>
      </div>

      <!-- ─── Phase 2 V2 : Scores granulaires + raisons + doublons + validation ─── -->

      <!-- Score global -->
      <div class="gimp-score-global-block" id="gimp-score-global-wrap" style="display:none">
        <div class="gimp-score-global-circle" id="gimp-score-global-circle">—</div>
        <div class="gimp-score-global-text">
          <strong id="gimp-score-global-label">Score global</strong>
          <small id="gimp-score-global-help">Plus le score est élevé, plus la classification est fiable.</small>
        </div>
      </div>

      <!-- 5 scores granulaires -->
      <div class="gimp-scores-grid" id="gimp-scores-grid" style="display:none">
        <?php foreach (['type'=>'Type', 'entity'=>'Entité', 'date'=>'Date', 'structure'=>'Structure', 'destination'=>'Destination'] as $k => $lbl): ?>
          <div class="gimp-score-cell" data-score-key="<?= $h($k) ?>">
            <div class="gimp-score-cell-label"><?= $h($lbl) ?></div>
            <div class="gimp-score-cell-bar">
              <div class="gimp-score-cell-bar-fill" data-bar-fill style="width:0%"></div>
            </div>
            <div class="gimp-score-cell-value" data-score-val>0</div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Raisons à revoir (cumulables, JSON) -->
      <div class="gimp-review-block" id="gimp-review-block" style="display:none">
        <div class="gimp-review-block-title">⚠️ Pourquoi ce document est à revoir</div>
        <div class="gimp-review-reasons" id="gimp-review-reasons"></div>
      </div>

      <!-- Doublons potentiels (find_duplicates_smart) -->
      <div class="gimp-dupes-block" id="gimp-dupes-block" style="display:none">
        <div class="gimp-dupes-title" id="gimp-dupes-title">Vérification doublons…</div>
        <div id="gimp-dupes-content"></div>
      </div>

      <!-- Validation par champ (avec bouton global "Tout valider") -->
      <div class="gimp-validate-block">
        <div class="gimp-validate-title">
          ✅ Validation par champ
          <button type="button" id="gimp-validate-toggle-all" class="gimp-validate-toggle-all">✓ Tout valider</button>
        </div>
        <div class="gimp-validate-grid">
          <?php foreach ([
              'n1'=>'N1 (module)', 'n2'=>'N2 (rubrique)',
              'entity'=>'Entité métier', 'date'=>'Date document',
              'title'=>'Titre humain', 'destination'=>'Destination GED'
          ] as $k => $lbl): ?>
            <label class="gimp-validate-checkbox" data-validate-field="<?= $h($k) ?>">
              <input type="checkbox" data-validate-input="<?= $h($k) ?>">
              <span><?= $h($lbl) ?></span>
            </label>
          <?php endforeach; ?>
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
      <button type="button" id="gimp-validate-quick-btn" class="gimp-btn"
              style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;display:none"
              title="Valide même si TITLE/N6 incomplets — nécessite N1+N2+entité validés">⚡ Valider rapidement</button>
      <button type="button" id="gimp-validate-btn" class="gimp-btn gimp-btn-success">✓ Valider et créer doc</button>
    </div>
  </div>
</div>

<script src="/js/ged_import.js?v=<?= @filemtime(__DIR__ . '/js/ged_import.js') ?: time() ?>"></script>
