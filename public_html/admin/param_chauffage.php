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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('param_chauffage');
    $action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    // Toggle actif chauffage
    if ($action === 'toggle_chauffage' && $id > 0) {
        $pdo->prepare("UPDATE societe_types_chauffage SET actif = NOT actif, updated_at = NOW() WHERE id = ? AND id_societe = ?")
            ->execute([$id, $societeId]);
        $success = 'Statut mis à jour.';
    }

    // Toggle actif énergie
    if ($action === 'toggle_energie' && $id > 0) {
        $pdo->prepare("UPDATE societe_energies SET actif = NOT actif, updated_at = NOW() WHERE id = ? AND id_societe = ?")
            ->execute([$id, $societeId]);
        $success = 'Statut mis à jour.';
    }

    // Édition chauffage
    if ($action === 'save_chauffage' && $id > 0) {
        $label = trim((string)($_POST['label'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $ordre = (int)($_POST['ordre_affichage'] ?? 0);
        if ($label === '') { $errors[] = 'Libellé obligatoire.'; }
        else {
            $pdo->prepare("UPDATE societe_types_chauffage SET label = ?, description = ?, ordre_affichage = ?, updated_at = NOW() WHERE id = ? AND id_societe = ?")
                ->execute([$label, $desc, $ordre, $id, $societeId]);
            $success = 'Mis à jour.';
        }
    }

    // Édition énergie
    if ($action === 'save_energie' && $id > 0) {
        $label = trim((string)($_POST['label'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $ordre = (int)($_POST['ordre_affichage'] ?? 0);
        if ($label === '') { $errors[] = 'Libellé obligatoire.'; }
        else {
            $pdo->prepare("UPDATE societe_energies SET label = ?, description = ?, ordre_affichage = ?, updated_at = NOW() WHERE id = ? AND id_societe = ?")
                ->execute([$label, $desc, $ordre, $id, $societeId]);
            $success = 'Mis à jour.';
        }
    }

    // Ajouter chauffage custom
    if ($action === 'add_chauffage') {
        $code  = trim((string)($_POST['code']  ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $icone = trim((string)($_POST['icone'] ?? ''));
        if ($code === '' || $label === '') { $errors[] = 'Code et libellé obligatoires.'; }
        else {
            try {
                $maxOrdre = (int)$pdo->query("SELECT COALESCE(MAX(ordre_affichage),0)+1 FROM societe_types_chauffage WHERE id_societe = $societeId")->fetchColumn();
                $pdo->prepare("INSERT INTO societe_types_chauffage (id_societe, code, label, description, icone, ordre_affichage, actif, is_custom) VALUES (?,?,?,?,?,?,1,1)")
                    ->execute([$societeId, $code, $label, $desc, $icone, $maxOrdre]);
                $success = 'Chauffage personnalisé ajouté.';
            } catch (PDOException $e) {
                $errors[] = 'Ce code existe déjà : ' . $e->getMessage();
            }
        }
    }

    // Ajouter énergie custom
    if ($action === 'add_energie') {
        $code  = trim((string)($_POST['code']  ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $icone = trim((string)($_POST['icone'] ?? ''));
        if ($code === '' || $label === '') { $errors[] = 'Code et libellé obligatoires.'; }
        else {
            try {
                $maxOrdre = (int)$pdo->query("SELECT COALESCE(MAX(ordre_affichage),0)+1 FROM societe_energies WHERE id_societe = $societeId")->fetchColumn();
                $pdo->prepare("INSERT INTO societe_energies (id_societe, code, label, description, icone, ordre_affichage, actif, is_custom) VALUES (?,?,?,?,?,?,1,1)")
                    ->execute([$societeId, $code, $label, $desc, $icone, $maxOrdre]);
                $success = 'Énergie personnalisée ajoutée.';
            } catch (PDOException $e) {
                $errors[] = 'Ce code existe déjà : ' . $e->getMessage();
            }
        }
    }

    // Suppression custom
    if ($action === 'delete_chauffage' && $id > 0) {
        $pdo->prepare("DELETE FROM societe_types_chauffage WHERE id = ? AND id_societe = ? AND is_custom = 1")->execute([$id, $societeId]);
        $success = 'Supprimé.';
    }
    if ($action === 'delete_energie' && $id > 0) {
        $pdo->prepare("DELETE FROM societe_energies WHERE id = ? AND id_societe = ? AND is_custom = 1")->execute([$id, $societeId]);
        $success = 'Supprimé.';
    }

    // Toggle liaison chauffage ↔ énergie (AJAX)
    if ($action === 'toggle_lien_ce') {
        $idTC = (int)($_POST['id_tc'] ?? 0);
        $idEN = (int)($_POST['id_en'] ?? 0);
        if ($idTC > 0 && $idEN > 0) {
            $stmtTC = $pdo->prepare("SELECT COUNT(*) FROM societe_types_chauffage WHERE id = ? AND id_societe = ?");
            $stmtTC->execute([$idTC, $societeId]);
            $okTC = (int)$stmtTC->fetchColumn();
            $stmtEN = $pdo->prepare("SELECT COUNT(*) FROM societe_energies WHERE id = ? AND id_societe = ?");
            $stmtEN->execute([$idEN, $societeId]);
            $okEN = (int)$stmtEN->fetchColumn();
            if ($okTC && $okEN) {
                $stmtEx = $pdo->prepare("SELECT COUNT(*) FROM societe_chauffage_energie WHERE id_societe_type_chauffage = ? AND id_societe_energie = ?");
                $stmtEx->execute([$idTC, $idEN]);
                $exists = (int)$stmtEx->fetchColumn();
                if ($exists) {
                    $pdo->prepare("DELETE FROM societe_chauffage_energie WHERE id_societe_type_chauffage = ? AND id_societe_energie = ?")->execute([$idTC, $idEN]);
                } else {
                    $pdo->prepare("INSERT IGNORE INTO societe_chauffage_energie (id_societe, id_societe_type_chauffage, id_societe_energie) VALUES (?,?,?)")->execute([$societeId, $idTC, $idEN]);
                }
            }
        }
        exit(json_encode(['ok' => true]));
    }
}

// ── Données ─────────────────────────────────────────────────────
$chauffages = $pdo->prepare("SELECT * FROM societe_types_chauffage WHERE id_societe = ? ORDER BY ordre_affichage ASC, label ASC");
$chauffages->execute([$societeId]);
$chauffages = $chauffages->fetchAll(PDO::FETCH_ASSOC);

$energies = $pdo->prepare("SELECT * FROM societe_energies WHERE id_societe = ? ORDER BY ordre_affichage ASC, label ASC");
$energies->execute([$societeId]);
$energies = $energies->fetchAll(PDO::FETCH_ASSOC);

// Liaisons existantes
$liensStmt = $pdo->prepare("SELECT id_societe_type_chauffage, id_societe_energie FROM societe_chauffage_energie WHERE id_societe = ?");
$liensStmt->execute([$societeId]);
$liens = [];
foreach ($liensStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $liens[(int)$l['id_societe_type_chauffage']][(int)$l['id_societe_energie']] = true;
}
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paramétrage — Chauffage &amp; Énergies — MaBoxImmo</title>
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
        <h1>🔥 Chauffage &amp; Énergies</h1>
      </div>
    </div>
    <div class="mbi-content">

      <?php if ($success): ?><div class="pa-alert pa-alert-ok"><?= h($success) ?></div><?php endif; ?>
      <?php foreach ($errors as $e): ?><div class="pa-alert pa-alert-err"><?= h($e) ?></div><?php endforeach; ?>

      <div class="pa-tabs">
        <button class="pa-tab active" onclick="switchTab('chauffage',this)">🔥 Types de chauffage</button>
        <button class="pa-tab"        onclick="switchTab('energies',this)">⚡ Énergies</button>
        <button class="pa-tab"        onclick="switchTab('liaisons',this)">🔗 Associations</button>
      </div>

      <!-- ── Chauffage ── -->
      <div class="pa-panel active" id="tab-chauffage">
        <p class="pa-intro">Activez les types de chauffage pertinents pour votre activité.</p>
        <div class="mc-grid" data-mc-mode="admin">
          <?php foreach ($chauffages as $ch): ?>
          <div class="mc-card <?= $ch['actif'] ? '' : 'is-inactive' ?> <?= $ch['is_custom'] ? 'is-custom' : '' ?>"
               data-mc-value="<?= h($ch['code']) ?>"
               data-mc-label="<?= h($ch['label']) ?>"
               data-mc-desc="<?= h($ch['description'] ?? '') ?>"
               data-mc-id="<?= (int)$ch['id'] ?>">
            <div class="mc-icon"><i class="<?= h($ch['icone'] ?? 'fa-solid fa-fire') ?>"></i></div>
            <div class="mc-label"><?= h($ch['label']) ?></div>
            <div class="mc-actions">
              <form method="post" style="display:inline;">
                <?= csrf_field('param_chauffage') ?>
                <input type="hidden" name="action" value="toggle_chauffage">
                <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                <button type="submit" class="mc-action-btn"><?= $ch['actif'] ? 'Désactiver' : 'Activer' ?></button>
              </form>
              <button type="button" class="mc-action-btn"
                onclick="openEditModal('chauffage',<?= (int)$ch['id'] ?>,'<?= h(addslashes($ch['label'])) ?>','<?= h(addslashes($ch['description'] ?? '')) ?>',<?= (int)$ch['ordre_affichage'] ?>)">Modifier</button>
              <?php if ($ch['is_custom']): ?>
              <form method="post" style="display:inline;">
                <?= csrf_field('param_chauffage') ?>
                <input type="hidden" name="action" value="delete_chauffage">
                <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                <button type="submit" class="mc-action-btn is-danger" onclick="return confirm('Supprimer ?')">✕</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="pa-add-block">
          <div class="pa-add-title">+ Ajouter un type de chauffage personnalisé</div>
          <form method="post" class="pa-add-form">
            <?= csrf_field('param_chauffage') ?>
            <input type="hidden" name="action" value="add_chauffage">
            <div class="pa-form-row">
              <div class="pa-field"><label>Code *</label><input type="text" name="code" placeholder="ex: bio_masse" maxlength="64" required></div>
              <div class="pa-field"><label>Libellé *</label><input type="text" name="label" maxlength="120" required></div>
              <div class="pa-field"><label>Icône FA</label><input type="text" name="icone" placeholder="fa-solid fa-fire"></div>
              <div class="pa-field pa-field-wide"><label>Description</label><input type="text" name="description"></div>
            </div>
            <button type="submit" class="pa-btn-add">Ajouter</button>
          </form>
        </div>
      </div>

      <!-- ── Énergies ── -->
      <div class="pa-panel" id="tab-energies">
        <p class="pa-intro">Activez les énergies disponibles dans vos formulaires.</p>
        <div class="mc-grid" data-mc-mode="admin">
          <?php foreach ($energies as $en): ?>
          <div class="mc-card <?= $en['actif'] ? '' : 'is-inactive' ?> <?= $en['is_custom'] ? 'is-custom' : '' ?>"
               data-mc-value="<?= h($en['code']) ?>"
               data-mc-label="<?= h($en['label']) ?>"
               data-mc-desc="<?= h($en['description'] ?? '') ?>"
               data-mc-id="<?= (int)$en['id'] ?>">
            <div class="mc-icon"><i class="<?= h($en['icone'] ?? 'fa-solid fa-bolt') ?>"></i></div>
            <div class="mc-label"><?= h($en['label']) ?></div>
            <div class="mc-actions">
              <form method="post" style="display:inline;">
                <?= csrf_field('param_chauffage') ?>
                <input type="hidden" name="action" value="toggle_energie">
                <input type="hidden" name="id" value="<?= (int)$en['id'] ?>">
                <button type="submit" class="mc-action-btn"><?= $en['actif'] ? 'Désactiver' : 'Activer' ?></button>
              </form>
              <button type="button" class="mc-action-btn"
                onclick="openEditModal('energie',<?= (int)$en['id'] ?>,'<?= h(addslashes($en['label'])) ?>','<?= h(addslashes($en['description'] ?? '')) ?>',<?= (int)$en['ordre_affichage'] ?>)">Modifier</button>
              <?php if ($en['is_custom']): ?>
              <form method="post" style="display:inline;">
                <?= csrf_field('param_chauffage') ?>
                <input type="hidden" name="action" value="delete_energie">
                <input type="hidden" name="id" value="<?= (int)$en['id'] ?>">
                <button type="submit" class="mc-action-btn is-danger" onclick="return confirm('Supprimer ?')">✕</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="pa-add-block">
          <div class="pa-add-title">+ Ajouter une énergie personnalisée</div>
          <form method="post" class="pa-add-form">
            <?= csrf_field('param_chauffage') ?>
            <input type="hidden" name="action" value="add_energie">
            <div class="pa-form-row">
              <div class="pa-field"><label>Code *</label><input type="text" name="code" placeholder="ex: hydrogene" maxlength="64" required></div>
              <div class="pa-field"><label>Libellé *</label><input type="text" name="label" maxlength="120" required></div>
              <div class="pa-field"><label>Icône FA</label><input type="text" name="icone" placeholder="fa-solid fa-bolt"></div>
              <div class="pa-field pa-field-wide"><label>Description</label><input type="text" name="description"></div>
            </div>
            <button type="submit" class="pa-btn-add">Ajouter</button>
          </form>
        </div>
      </div>

      <!-- ── Associations ── -->
      <div class="pa-panel" id="tab-liaisons">
        <p class="pa-intro">Définissez quelles énergies sont compatibles avec chaque type de chauffage. Cliquez sur une énergie pour activer ou désactiver son association.</p>
        <table class="pa-table">
          <thead>
            <tr>
              <th>Type de chauffage</th>
              <th>Énergies compatibles</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($chauffages as $ch): ?>
            <?php if (!$ch['actif']) continue; ?>
            <tr>
              <td style="white-space:nowrap;font-weight:600;font-size:12px;">
                <i class="<?= h($ch['icone'] ?? 'fa-solid fa-fire') ?>" style="margin-right:6px;opacity:.7;"></i><?= h($ch['label']) ?>
              </td>
              <td>
                <div class="pa-chips">
                  <?php foreach ($energies as $en): ?>
                  <?php if (!$en['actif']) continue; ?>
                  <?php $on = isset($liens[(int)$ch['id']][(int)$en['id']]); ?>
                  <span class="pa-chip <?= $on ? 'on' : '' ?>"
                        data-tc="<?= (int)$ch['id'] ?>"
                        data-en="<?= (int)$en['id'] ?>"
                        onclick="toggleLienCE(this)"><?= h($en['label']) ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </main>

  <!-- Modal édition -->
  <div id="editModal" class="pa-modal" style="display:none;">
    <div class="pa-modal-box">
      <div class="pa-modal-title" id="editModalTitle">Modifier</div>
      <form method="post" class="pa-add-form" id="editModalForm">
        <?= csrf_field('param_chauffage') ?>
        <input type="hidden" name="action" id="editAction">
        <input type="hidden" name="id" id="editId">
        <div class="pa-form-row">
          <div class="pa-field"><label>Libellé *</label><input type="text" name="label" id="editLabel" required></div>
          <div class="pa-field pa-field-small"><label>Ordre</label><input type="number" name="ordre_affichage" id="editOrdre" min="0" max="999"></div>
          <div class="pa-field pa-field-wide"><label>Description</label><textarea name="description" id="editDesc" rows="2"></textarea></div>
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
    function switchTab(id, btn) {
      document.querySelectorAll('.pa-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.pa-tab').forEach(b => b.classList.remove('active'));
      document.getElementById('tab-' + id).classList.add('active');
      btn.classList.add('active');
    }

    function openEditModal(type, id, label, desc, ordre) {
      const titles = { chauffage: 'Modifier le chauffage', energie: 'Modifier l\'énergie' };
      const actions = { chauffage: 'save_chauffage', energie: 'save_energie' };
      document.getElementById('editModalTitle').textContent = titles[type] || 'Modifier';
      document.getElementById('editAction').value = actions[type] || '';
      document.getElementById('editId').value    = id;
      document.getElementById('editLabel').value = label;
      document.getElementById('editDesc').value  = desc;
      document.getElementById('editOrdre').value = ordre;
      document.getElementById('editModal').style.display = 'flex';
    }
    function closeEdit() { document.getElementById('editModal').style.display = 'none'; }

    function toggleLienCE(chip) {
      const data = new FormData();
      data.append('action', 'toggle_lien_ce');
      data.append('id_tc', chip.dataset.tc);
      data.append('id_en', chip.dataset.en);
      data.append('csrf_token', '<?= csrf_token('param_chauffage') ?>');
      fetch(window.location.href, { method: 'POST', body: data })
        .then(r => r.json())
        .then(() => chip.classList.toggle('on'));
    }
  </script>
</body>
</html>
