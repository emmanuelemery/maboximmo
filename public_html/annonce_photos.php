<?php
declare(strict_types=1);

/**
 * Page de sélection des photos d'une annonce.
 *
 * Affiche toutes les photos du bien en 2 zones :
 *   - Haut  : sélectionnées (clic = retire vers le bas)
 *   - Bas   : disponibles  (clic = monte vers le haut)
 *
 * À l'ouverture, les photos déjà liées à l'annonce dans annonces_photos
 * apparaissent en haut (sélection persistante). Si l'annonce n'est pas
 * active (statut != 'actif'), la page passe en lecture seule (pas de
 * toggle, pas de bouton Valider).
 *
 * Validation : POST vers api/annonce_photos_save.php → DELETE+INSERT
 * idempotent. Puis redirection vers ?return=... ou bien_detail.
 *
 * Accès : utilisateur connecté + scope société.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;

$annonceId = isset($_GET['id_annonce']) && ctype_digit((string)$_GET['id_annonce']) ? (int)$_GET['id_annonce'] : 0;
if ($annonceId <= 0) {
    http_response_code(400);
    exit('<h1>400 — id_annonce manquant ou invalide.</h1>');
}

// Charge annonce + bien (vérif scope)
$st = $pdo->prepare("
    SELECT a.id, a.id_bien, a.statut, a.titre, a.type_transaction,
           b.id_societe, b.id_agence, b.adresse_1, b.code_postal, b.ville,
           b.reference_bien
    FROM annonces a
    JOIN biens b ON b.id = a.id_bien
    WHERE a.id = ?
    LIMIT 1
");
$st->execute([$annonceId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('<h1>404 — Annonce introuvable.</h1>');
}
if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
    http_response_code(403);
    exit('<h1>403 — Hors scope société.</h1>');
}

$bienId       = (int)$row['id_bien'];
$annonceTitre = (string)($row['titre'] ?? '');
$bienAdresse  = trim(((string)($row['adresse_1'] ?? '')) . ' · ' . trim(((string)($row['code_postal'] ?? '')) . ' ' . ((string)($row['ville'] ?? ''))), ' ·');
$bienRef      = (string)($row['reference_bien'] ?? '');
// Lecture seule UNIQUEMENT si l'annonce est archivée (terminée). Brouillon =
// annonce en cours de préparation, on doit pouvoir éditer la sélection photos.
$statutLower  = strtolower(trim((string)$row['statut']));
$annonceActif = !in_array($statutLower, ['archive', 'archivee', 'archivée', 'ferme', 'ferme', 'fermee', 'fermée', 'cloturee', 'clôturée'], true);

// Photos du bien
$st = $pdo->prepare("SELECT id, url_photo, nom_original, largeur, hauteur FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
$st->execute([$bienId]);
$photosBien = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Sélection actuelle dans annonces_photos
$st = $pdo->prepare("SELECT id_biens_photo FROM annonces_photos WHERE id_annonce = ? ORDER BY ordre ASC, id ASC");
$st->execute([$annonceId]);
$selectedIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
$selectedSet = array_flip($selectedIds);

// Tri : sélectionnées d'abord (dans l'ordre annonce), disponibles ensuite (ordre bien)
$photosSelectionnees = [];
foreach ($selectedIds as $pid) {
    foreach ($photosBien as $p) {
        if ((int)$p['id'] === $pid) { $photosSelectionnees[] = $p; break; }
    }
}
$photosDisponibles = [];
foreach ($photosBien as $p) {
    if (!isset($selectedSet[(int)$p['id']])) $photosDisponibles[] = $p;
}

$returnUrlRaw = (string)($_GET['return'] ?? "/bien_detail.php?edit={$bienId}&section=annonce");
// Si l'URL commence par "/", on préfixe avec app_url pour gérer le sous-dossier XAMPP local
$returnUrl = (str_starts_with($returnUrlRaw, '/') ? app_url($returnUrlRaw) : $returnUrlRaw);
$csrfTokenVal = csrf_token('ajouter_bien');

$appLayout = true;
$pageTitle = 'Sélection des photos de l\'annonce';
$bodyClass = '';
require_once __DIR__ . '/inc/header.php';
?>

<style>
  .ap-wrap { max-width: 1300px; margin: 0 auto; padding: 24px 20px; }
  .ap-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 22px; flex-wrap: wrap; }
  .ap-head h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .ap-head .sub { color: #64748b; font-size: 13px; margin: 0; }
  .ap-head .meta { font-family: monospace; font-size: 12px; color: #64748b; margin-top: 4px; }
  .ap-actions { display: flex; gap: 8px; }
  .ap-btn { padding: 9px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .ap-btn.primary { background: #0ea5e9; color: #fff; }
  .ap-btn.primary:hover { background: #0284c7; }
  .ap-btn.primary:disabled { background: #94a3b8; cursor: not-allowed; }
  .ap-btn.secondary { background: #fff; color: #0369a1; border: 1px solid #cbd5e1; }
  .ap-btn.secondary:hover { background: #f1f5f9; }

  .ap-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 20px; margin-bottom: 18px; }
  .ap-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
  .ap-section-head h2 { margin: 0; font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px; }
  .ap-section-head .count { background: #f1f5f9; color: #475569; font-size: 12px; padding: 2px 10px; border-radius: 99px; font-weight: 700; }
  .ap-section.selected .ap-section-head .count { background: #dcfce7; color: #166534; }

  .ap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 18px; }
  .ap-tile { position: relative; border: 2px solid #e5e7eb; border-radius: 10px; overflow: hidden; cursor: pointer; aspect-ratio: 4/3; background: #f8fafc; transition: transform 0.15s, border-color 0.15s, box-shadow 0.15s, opacity 0.15s; }
  .ap-tile:hover { transform: scale(1.03); border-color: #0ea5e9; }
  .ap-tile img { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
  .ap-tile .ap-order {
    position: absolute; top: 8px; left: 8px;
    background: rgba(15,23,42,0.92); color: #fff;
    font-size: 18px; font-weight: 800;
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    border: 2px solid #fff;
  }
  .ap-tile .ap-main { position: absolute; bottom: 6px; left: 6px; background: #f59e0b; color: #fff; font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: .04em; text-transform: uppercase; }
  .ap-tile .ap-action { position: absolute; top: 6px; right: 6px; background: rgba(255,255,255,0.95); border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: #0f172a; }
  .ap-tile.is-selected { border-color: #16a34a; }

  /* Drag & drop : sélection uniquement (les tiles disponibles ne sont pas réordonnables) */
  .ap-section.selected .ap-tile.is-selected { cursor: grab; }
  .ap-section.selected .ap-tile.is-selected:active { cursor: grabbing; }
  .ap-tile.dragging { opacity: 0.4; transform: scale(0.95); }
  .ap-tile.drag-over { box-shadow: 0 0 0 4px #0ea5e9 inset; border-color: #0ea5e9; }
  .ap-tile .ap-drag-handle {
    position: absolute; top: 8px; right: 36px;
    background: rgba(255,255,255,0.9); color: #475569;
    font-size: 14px; line-height: 1;
    width: 24px; height: 24px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none;
    opacity: 0; transition: opacity 0.15s;
  }
  .ap-section.selected .ap-tile.is-selected:hover .ap-drag-handle { opacity: 0.9; }

  .ap-empty { color: #94a3b8; text-align: center; padding: 30px 12px; font-size: 13px; }

  .ap-footer { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e5e7eb; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; box-shadow: 0 -2px 8px rgba(15,23,42,0.04); margin: 0 -20px -24px; border-radius: 0 0 12px 12px; }
  .ap-footer .info { font-size: 13px; color: #475569; }
  .ap-footer .info strong { color: #0f172a; }
  .ap-status { font-size: 12px; }
  .ap-status.ok { color: #16a34a; }
  .ap-status.err { color: #dc2626; }

  .ap-locked-banner { background: #fef3c7; border-left: 4px solid #f59e0b; color: #92400e; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
</style>

<div class="ap-wrap">
  <div class="ap-head">
    <div>
      <h1>📸 Sélection des photos de l'annonce</h1>
      <p class="sub"><?= htmlspecialchars($annonceTitre ?: '(Annonce sans titre)') ?></p>
      <p class="meta">
        <?php if ($bienRef !== ''): ?><strong><?= htmlspecialchars($bienRef) ?></strong> · <?php endif; ?>
        <?= htmlspecialchars($bienAdresse) ?>
        · Bien #<?= $bienId ?> · Annonce #<?= $annonceId ?>
      </p>
    </div>
    <div class="ap-actions">
      <a href="<?= htmlspecialchars($returnUrl) ?>" class="ap-btn secondary">← Retour</a>
    </div>
  </div>

  <?php if (!$annonceActif): ?>
    <div class="ap-locked-banner">
      🔒 Cette annonce n'est pas active (statut : <?= htmlspecialchars((string)$row['statut']) ?>) — la sélection est en
      <strong>lecture seule</strong>. Vous pouvez consulter l'historique des photos sélectionnées, mais pas le modifier.
    </div>
  <?php endif; ?>

  <!-- Sélectionnées -->
  <div class="ap-section selected">
    <div class="ap-section-head">
      <h2>📌 Photos sélectionnées <small style="color:#64748b; font-weight:400;">(la 1ère sera la principale diffusée)</small></h2>
      <span class="count" id="ap-count-sel"><?= count($photosSelectionnees) ?></span>
    </div>
    <div class="ap-grid" id="ap-grid-sel">
      <?php if (empty($photosSelectionnees)): ?>
        <div class="ap-empty" style="grid-column: 1 / -1;">
          Aucune photo sélectionnée pour le moment. Clique sur les vignettes en bas pour les ajouter.
        </div>
      <?php else: ?>
        <?php foreach ($photosSelectionnees as $i => $p):
          $url = $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '';
        ?>
          <div class="ap-tile is-selected" data-photo-id="<?= (int)$p['id'] ?>" draggable="<?= $annonceActif ? 'true' : 'false' ?>">
            <span class="ap-order"><?= $i + 1 ?></span>
            <?php if ($i === 0): ?><span class="ap-main">PRINCIPALE</span><?php endif; ?>
            <span class="ap-drag-handle" title="Glisser pour réordonner">⠿</span>
            <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars((string)($p['nom_original'] ?? '')) ?>" loading="lazy">
            <span class="ap-action" title="Cliquer pour retirer de la sélection">×</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Disponibles -->
  <div class="ap-section">
    <div class="ap-section-head">
      <h2>📂 Photos disponibles du bien</h2>
      <span class="count" id="ap-count-dispo"><?= count($photosDisponibles) ?></span>
    </div>
    <div class="ap-grid" id="ap-grid-dispo">
      <?php if (empty($photosDisponibles)): ?>
        <div class="ap-empty" style="grid-column: 1 / -1;">
          <?php if (empty($photosBien)): ?>
            Aucune photo n'est encore chargée pour ce bien.<br>
            <small>Charge-les via <a href="<?= h(app_url('/bien_detail.php?edit=' . $bienId . '&section=documents')) ?>">📎 Documents → Chargement</a>.</small>
          <?php else: ?>
            Toutes les photos du bien sont sélectionnées.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php foreach ($photosDisponibles as $p):
          $url = $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '';
        ?>
          <div class="ap-tile" data-photo-id="<?= (int)$p['id'] ?>">
            <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars((string)($p['nom_original'] ?? '')) ?>" loading="lazy">
            <span class="ap-action" title="Cliquer pour ajouter à la sélection">+</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($annonceActif): ?>
    <div class="ap-footer">
      <div class="info">
        <strong id="ap-info-count"><?= count($photosSelectionnees) ?></strong> photo(s) sélectionnée(s) sur <?= count($photosBien) ?> disponible(s)
        <span id="ap-status" class="ap-status" style="margin-left:14px;"></span>
      </div>
      <div>
        <a href="<?= htmlspecialchars($returnUrl) ?>" class="ap-btn secondary">Annuler</a>
        <button type="button" id="ap-btn-save" class="ap-btn primary">✅ Valider la sélection</button>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const annonceId = <?= json_encode($annonceId) ?>;
  const csrf      = <?= json_encode($csrfTokenVal, JSON_UNESCAPED_SLASHES) ?>;
  const returnUrl = <?= json_encode($returnUrl, JSON_UNESCAPED_SLASHES) ?>;
  const saveUrl   = <?= json_encode(app_url('/api/annonce_photos_save.php'), JSON_UNESCAPED_SLASHES) ?>;
  const isLocked  = <?= json_encode(!$annonceActif) ?>;

  const gridSel   = document.getElementById('ap-grid-sel');
  const gridDispo = document.getElementById('ap-grid-dispo');
  const countSel  = document.getElementById('ap-count-sel');
  const countDispo= document.getElementById('ap-count-dispo');
  const infoCount = document.getElementById('ap-info-count');
  const status    = document.getElementById('ap-status');
  const btnSave   = document.getElementById('ap-btn-save');

  function refreshOrders() {
    Array.from(gridSel.querySelectorAll('.ap-tile')).forEach((tile, i) => {
      let lblOrder = tile.querySelector('.ap-order');
      if (!lblOrder) {
        lblOrder = document.createElement('span');
        lblOrder.className = 'ap-order';
        tile.insertBefore(lblOrder, tile.firstChild);
      }
      lblOrder.textContent = String(i + 1);
      // Tag PRINCIPALE sur la 1ère
      let mainTag = tile.querySelector('.ap-main');
      if (i === 0 && !mainTag) {
        mainTag = document.createElement('span');
        mainTag.className = 'ap-main';
        mainTag.textContent = 'PRINCIPALE';
        tile.appendChild(mainTag);
      } else if (i !== 0 && mainTag) {
        mainTag.remove();
      }
    });
  }

  function refreshCounts() {
    const n1 = gridSel.querySelectorAll('.ap-tile').length;
    const n2 = gridDispo.querySelectorAll('.ap-tile').length;
    if (countSel)  countSel.textContent  = String(n1);
    if (countDispo) countDispo.textContent = String(n2);
    if (infoCount) infoCount.textContent = String(n1);
    // Empty placeholders
    const emptyMsgSel = gridSel.querySelector('.ap-empty');
    if (n1 > 0 && emptyMsgSel) emptyMsgSel.remove();
    if (n1 === 0 && !emptyMsgSel) {
      const d = document.createElement('div');
      d.className = 'ap-empty';
      d.style.gridColumn = '1 / -1';
      d.textContent = 'Aucune photo sélectionnée. Clique sur les vignettes en bas.';
      gridSel.appendChild(d);
    }
  }

  if (!isLocked) {
    // Distinction click / drag : si on a draggé, on bloque le clic suivant
    let suppressNextClick = false;

    // Event delegation sur le container parent (robuste : marche même si on déplace les tiles)
    function handleTileClick(tile) {
      const isSel = tile.classList.contains('is-selected');
      if (isSel) {
        // Désélection : descend dans dispo
        tile.classList.remove('is-selected');
        tile.removeAttribute('draggable');
        const o = tile.querySelector('.ap-order'); if (o) o.remove();
        const m = tile.querySelector('.ap-main'); if (m) m.remove();
        const h = tile.querySelector('.ap-drag-handle'); if (h) h.remove();
        const a = tile.querySelector('.ap-action'); if (a) { a.textContent = '+'; a.title = 'Cliquer pour ajouter à la sélection'; }
        const emptyDispo = gridDispo.querySelector('.ap-empty');
        if (emptyDispo) emptyDispo.remove();
        gridDispo.appendChild(tile);
      } else {
        // Sélection : monte dans sélectionnées
        tile.classList.add('is-selected');
        tile.setAttribute('draggable', 'true');
        if (!tile.querySelector('.ap-drag-handle')) {
          const dh = document.createElement('span');
          dh.className = 'ap-drag-handle';
          dh.title = 'Glisser pour réordonner';
          dh.textContent = '⠿';
          tile.appendChild(dh);
        }
        const a = tile.querySelector('.ap-action'); if (a) { a.textContent = '×'; a.title = 'Cliquer pour retirer de la sélection'; }
        const emptySel = gridSel.querySelector('.ap-empty');
        if (emptySel) emptySel.remove();
        gridSel.appendChild(tile);
      }
      refreshOrders();
      refreshCounts();
    }
    // Délégation : un seul listener sur document, qui détecte le tile cliqué
    document.addEventListener('click', (e) => {
      if (suppressNextClick) { suppressNextClick = false; return; }
      const tile = e.target.closest('.ap-tile');
      if (!tile) return;
      // Vérifie qu'on est dans une des 2 grids (pas un autre tile ailleurs)
      if (!gridSel.contains(tile) && !gridDispo.contains(tile)) return;
      handleTileClick(tile);
    });

    // ── Drag & drop pour réordonner dans la sélection ───────────────
    let dragSrc = null;

    gridSel.addEventListener('dragstart', (e) => {
      const tile = e.target.closest('.ap-tile');
      if (!tile || !gridSel.contains(tile)) return;
      dragSrc = tile;
      tile.classList.add('dragging');
      // Firefox a besoin d'un setData pour démarrer le drag
      try { e.dataTransfer.setData('text/plain', tile.dataset.photoId || ''); } catch(_) {}
      e.dataTransfer.effectAllowed = 'move';
    });

    gridSel.addEventListener('dragend', (e) => {
      const tile = e.target.closest('.ap-tile');
      if (tile) tile.classList.remove('dragging');
      gridSel.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
      dragSrc = null;
      // Empêche le click qui suit le dragend
      suppressNextClick = true;
      setTimeout(() => { suppressNextClick = false; }, 50);
    });

    gridSel.addEventListener('dragover', (e) => {
      if (!dragSrc) return;
      e.preventDefault(); // autorise le drop
      e.dataTransfer.dropEffect = 'move';
      const tile = e.target.closest('.ap-tile');
      if (!tile || tile === dragSrc) return;
      gridSel.querySelectorAll('.drag-over').forEach(el => { if (el !== tile) el.classList.remove('drag-over'); });
      tile.classList.add('drag-over');
    });

    gridSel.addEventListener('dragleave', (e) => {
      const tile = e.target.closest('.ap-tile');
      if (tile && !tile.contains(e.relatedTarget)) tile.classList.remove('drag-over');
    });

    gridSel.addEventListener('drop', (e) => {
      e.preventDefault();
      if (!dragSrc) return;
      const target = e.target.closest('.ap-tile');
      if (!target || target === dragSrc) return;
      // Détermine s'il faut insérer avant ou après la cible selon la position du curseur
      const rect = target.getBoundingClientRect();
      const horizontal = (rect.left + rect.right) / 2;
      const vertical   = (rect.top + rect.bottom) / 2;
      // En grille multi-colonnes : si on est dans la moitié droite/bas → insère après ; sinon avant
      const insertAfter = (e.clientX > horizontal) || (e.clientY > vertical);
      if (insertAfter) {
        target.parentNode.insertBefore(dragSrc, target.nextSibling);
      } else {
        target.parentNode.insertBefore(dragSrc, target);
      }
      target.classList.remove('drag-over');
      refreshOrders();
    });

    // Save
    btnSave.addEventListener('click', async () => {
      btnSave.disabled = true;
      status.className = 'ap-status'; status.textContent = '⏳ Enregistrement…';
      const ids = Array.from(gridSel.querySelectorAll('.ap-tile')).map(t => parseInt(t.dataset.photoId, 10));
      try {
        const fd = new FormData();
        fd.append('_annonce_id', String(annonceId));
        fd.append('csrf_token', csrf);
        ids.forEach(id => fd.append('id_photos[]', String(id)));
        const r = await fetch(saveUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j.ok) {
          status.className = 'ap-status ok';
          status.textContent = '✅ ' + j.count + ' photo(s) sauvée(s) — retour…';
          setTimeout(() => { window.location.href = returnUrl; }, 600);
        } else {
          status.className = 'ap-status err';
          status.textContent = '❌ ' + (j.error || j.message || 'Erreur');
          btnSave.disabled = false;
        }
      } catch (e) {
        status.className = 'ap-status err';
        status.textContent = '❌ ' + e.message;
        btnSave.disabled = false;
      }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
