<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
if (!in_array((int)current_role_id(), [1], true) && !is_super_admin()) {
    http_response_code(403); exit('Accès refusé.');
}

$pdo       = $GLOBALS['pdo'];
$societeId = (int)current_societe_id();
if ($societeId <= 0) { exit('Société introuvable.'); }

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors  = [];
$success = '';

// ── Actions POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('param_vues');
    $action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_actif' && $id > 0) {
        $pdo->prepare("UPDATE societe_vues SET actif = NOT actif, updated_at = NOW() WHERE id = ? AND id_societe = ?")
            ->execute([$id, $societeId]);
        $success = 'Statut mis à jour.';
    }

    if ($action === 'save_edit' && $id > 0) {
        $label       = trim((string)($_POST['label']       ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $ordre       = (int)($_POST['ordre_affichage'] ?? 0);
        if ($label === '') { $errors[] = 'Le libellé est obligatoire.'; }
        else {
            $pdo->prepare("UPDATE societe_vues SET label = ?, description = ?, ordre_affichage = ?, updated_at = NOW() WHERE id = ? AND id_societe = ?")
                ->execute([$label, $description, $ordre, $id, $societeId]);
            $success = 'Vue mise à jour.';
        }
    }

    if ($action === 'add_custom') {
        $code        = trim((string)($_POST['code']        ?? ''));
        $label       = trim((string)($_POST['label']       ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $icone       = trim((string)($_POST['icone']       ?? ''));
        if ($code === '' || $label === '') { $errors[] = 'Code et libellé obligatoires.'; }
        else {
            try {
                $maxOrdre = (int)($pdo->prepare("SELECT COALESCE(MAX(ordre_affichage),0)+1 FROM societe_vues WHERE id_societe = ?")->execute([$societeId]) ? $pdo->query("SELECT COALESCE(MAX(ordre_affichage),0)+1 FROM societe_vues WHERE id_societe = $societeId")->fetchColumn() : 100);
                $pdo->prepare("INSERT INTO societe_vues (id_societe, code, label, description, icone, ordre_affichage, actif, is_custom) VALUES (?, ?, ?, ?, ?, ?, 1, 1)")
                    ->execute([$societeId, $code, $label, $description, $icone, $maxOrdre]);
                $success = 'Vue personnalisée ajoutée.';
            } catch (PDOException $e) {
                $errors[] = 'Ce code existe déjà ou erreur : ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_custom' && $id > 0) {
        $pdo->prepare("DELETE FROM societe_vues WHERE id = ? AND id_societe = ? AND is_custom = 1")
            ->execute([$id, $societeId]);
        $success = 'Vue supprimée.';
    }
}

// ── Données ─────────────────────────────────────────────────────
$vues = $pdo->prepare("SELECT * FROM societe_vues WHERE id_societe = ? ORDER BY ordre_affichage ASC, label ASC");
$vues->execute([$societeId]);
$vues = $vues->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paramétrage — Vues — MaBoxImmo</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/public_html/css/theme-rh.css">
  <link rel="stylesheet" href="/public_html/css/minicard.css">
  <?php include dirname(__DIR__) . '/inc/theme-init.php'; ?>
  <?php include __DIR__ . '/_param_style.php'; ?>
</head>
<body>
  <?php include dirname(__DIR__) . '/inc/sidebar.php'; ?>

  <main class="mbi-main">
    <div class="mbi-topbar">
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="/public_html/parametrage.php" class="pa-back">← Paramétrage</a>
        <h1>👁️ Vues</h1>
      </div>
    </div>
    <div class="mbi-content">

      <?php if ($success): ?><div class="pa-alert pa-alert-ok"><?= h($success) ?></div><?php endif; ?>
      <?php foreach ($errors as $e): ?><div class="pa-alert pa-alert-err"><?= h($e) ?></div><?php endforeach; ?>

      <p class="pa-intro">Activez ou désactivez les types de vue pour votre société. Vous pouvez aussi modifier les libellés ou ajouter des vues personnalisées.</p>

      <!-- Grille des vues -->
      <div class="mc-grid" data-mc-mode="admin">
        <?php foreach ($vues as $vue): ?>
        <div class="mc-card <?= $vue['actif'] ? '' : 'is-inactive' ?> <?= $vue['is_custom'] ? 'is-custom' : '' ?>"
             data-mc-value="<?= h($vue['code']) ?>"
             data-mc-label="<?= h($vue['label']) ?>"
             data-mc-desc="<?= h($vue['description'] ?? '') ?>"
             data-mc-id="<?= (int)$vue['id'] ?>">

          <div class="mc-icon"><i class="<?= h($vue['icone'] ?? 'fa-solid fa-eye') ?>"></i></div>
          <div class="mc-label"><?= h($vue['label']) ?></div>

          <div class="mc-actions">
            <form method="post" style="display:inline;">
              <?= csrf_field('param_vues') ?>
              <input type="hidden" name="action" value="toggle_actif">
              <input type="hidden" name="id" value="<?= (int)$vue['id'] ?>">
              <button type="submit" class="mc-action-btn"><?= $vue['actif'] ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <button type="button" class="mc-action-btn" onclick="openEdit(<?= (int)$vue['id'] ?>, '<?= h(addslashes($vue['label'])) ?>', '<?= h(addslashes($vue['description'] ?? '')) ?>', <?= (int)$vue['ordre_affichage'] ?>)">Modifier</button>
            <?php if ($vue['is_custom']): ?>
            <form method="post" style="display:inline;">
              <?= csrf_field('param_vues') ?>
              <input type="hidden" name="action" value="delete_custom">
              <input type="hidden" name="id" value="<?= (int)$vue['id'] ?>">
              <button type="submit" class="mc-action-btn is-danger" onclick="return confirm('Supprimer cette vue ?')">✕</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Ajouter une vue custom -->
      <div class="pa-add-block">
        <div class="pa-add-title">+ Ajouter une vue personnalisée</div>
        <form method="post" class="pa-add-form">
          <?= csrf_field('param_vues') ?>
          <input type="hidden" name="action" value="add_custom">
          <div class="pa-form-row">
            <div class="pa-field"><label>Code *</label><input type="text" name="code" placeholder="ex: mer_riviere" maxlength="64" required></div>
            <div class="pa-field"><label>Libellé *</label><input type="text" name="label" placeholder="ex: Vue mer / rivière" maxlength="120" required></div>
            <div class="pa-field"><label>Icône FontAwesome</label><input type="text" name="icone" placeholder="fa-solid fa-water"></div>
            <div class="pa-field pa-field-wide"><label>Description</label><input type="text" name="description" placeholder="Description courte affichée dans le tooltip"></div>
          </div>
          <button type="submit" class="pa-btn-add">Ajouter</button>
        </form>
      </div>

    </div>
  </main>

  <!-- Modal édition -->
  <div id="editModal" class="pa-modal" style="display:none;">
    <div class="pa-modal-box">
      <div class="pa-modal-title">Modifier la vue</div>
      <form method="post" class="pa-add-form">
        <?= csrf_field('param_vues') ?>
        <input type="hidden" name="action" value="save_edit">
        <input type="hidden" name="id" id="editId">
        <div class="pa-form-row">
          <div class="pa-field"><label>Libellé *</label><input type="text" name="label" id="editLabel" maxlength="120" required></div>
          <div class="pa-field pa-field-small"><label>Ordre</label><input type="number" name="ordre_affichage" id="editOrdre" min="0" max="999"></div>
          <div class="pa-field pa-field-wide"><label>Description</label><textarea name="description" id="editDesc" rows="3"></textarea></div>
        </div>
        <div style="display:flex;gap:10px;margin-top:8px;">
          <button type="submit" class="pa-btn-add">Enregistrer</button>
          <button type="button" class="pa-btn-cancel" onclick="closeEdit()">Annuler</button>
        </div>
      </form>
    </div>
  </div>

  <script src="/public_html/js/minicard.js"></script>
  <script>
    function openEdit(id, label, desc, ordre) {
      document.getElementById('editId').value    = id;
      document.getElementById('editLabel').value = label;
      document.getElementById('editDesc').value  = desc;
      document.getElementById('editOrdre').value = ordre;
      document.getElementById('editModal').style.display = 'flex';
    }
    function closeEdit() {
      document.getElementById('editModal').style.display = 'none';
    }
    document.getElementById('editModal').addEventListener('click', function(e) {
      if (e.target === this) closeEdit();
    });
  </script>
</body>
</html>
