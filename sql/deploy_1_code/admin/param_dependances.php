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
    verify_csrf('param_dep');
    $action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_actif' && $id > 0) {
        $pdo->prepare("UPDATE societe_dependances_exterieurs SET actif = NOT actif, updated_at = NOW() WHERE id = ? AND id_societe = ?")
            ->execute([$id, $societeId]);
        $success = 'Statut mis à jour.';
    }

    if ($action === 'save_edit' && $id > 0) {
        $label = trim((string)($_POST['label'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $ordre = (int)($_POST['ordre_affichage'] ?? 0);
        if ($label === '') { $errors[] = 'Libellé obligatoire.'; }
        else {
            $pdo->prepare("UPDATE societe_dependances_exterieurs SET label = ?, description = ?, ordre_affichage = ?, updated_at = NOW() WHERE id = ? AND id_societe = ?")
                ->execute([$label, $desc, $ordre, $id, $societeId]);
            $success = 'Mise à jour effectuée.';
        }
    }

    if ($action === 'add_custom') {
        $code    = trim((string)($_POST['code']    ?? ''));
        $label   = trim((string)($_POST['label']   ?? ''));
        $desc    = trim((string)($_POST['description'] ?? ''));
        $icone   = trim((string)($_POST['icone']   ?? ''));
        $famille = trim((string)($_POST['famille'] ?? 'dependance'));
        if ($code === '' || $label === '') { $errors[] = 'Code et libellé obligatoires.'; }
        else {
            try {
                $maxOrdre = (int)$pdo->query("SELECT COALESCE(MAX(ordre_affichage),0)+1 FROM societe_dependances_exterieurs WHERE id_societe = $societeId")->fetchColumn();
                $pdo->prepare("INSERT INTO societe_dependances_exterieurs (id_societe, code, label, description, icone, famille, ordre_affichage, actif, is_custom) VALUES (?,?,?,?,?,?,?,1,1)")
                    ->execute([$societeId, $code, $label, $desc, $icone, $famille, $maxOrdre]);
                $success = 'Entrée ajoutée.';
            } catch (PDOException $e) {
                $errors[] = 'Ce code existe déjà ou erreur : ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_custom' && $id > 0) {
        $pdo->prepare("DELETE FROM societe_dependances_exterieurs WHERE id = ? AND id_societe = ? AND is_custom = 1")
            ->execute([$id, $societeId]);
        $success = 'Entrée supprimée.';
    }

    // ── Liaisons type_bien ↔ dépendance ──
    if ($action === 'toggle_lien') {
        $idTB  = (int)($_POST['id_tb']  ?? 0);
        $idDep = (int)($_POST['id_dep'] ?? 0);
        if ($idTB > 0 && $idDep > 0) {
            // Vérifie appartenance société
            $okTB  = (int)$pdo->query("SELECT COUNT(*) FROM societe_types_bien WHERE id = $idTB AND id_societe = $societeId")->fetchColumn();
            $okDep = (int)$pdo->query("SELECT COUNT(*) FROM societe_dependances_exterieurs WHERE id = $idDep AND id_societe = $societeId")->fetchColumn();
            if ($okTB && $okDep) {
                $exists = (int)$pdo->prepare("SELECT COUNT(*) FROM societe_type_bien_dependance_ext WHERE id_societe_type_bien = ? AND id_societe_dependance_exterieur = ?")
                    ->execute([$idTB, $idDep]) ? (int)$pdo->query("SELECT COUNT(*) FROM societe_type_bien_dependance_ext WHERE id_societe_type_bien = $idTB AND id_societe_dependance_exterieur = $idDep")->fetchColumn() : 0;
                if ($exists) {
                    $pdo->prepare("DELETE FROM societe_type_bien_dependance_ext WHERE id_societe_type_bien = ? AND id_societe_dependance_exterieur = ?")
                        ->execute([$idTB, $idDep]);
                } else {
                    $pdo->prepare("INSERT IGNORE INTO societe_type_bien_dependance_ext (id_societe, id_societe_type_bien, id_societe_dependance_exterieur) VALUES (?,?,?)")
                        ->execute([$societeId, $idTB, $idDep]);
                }
            }
        }
        exit(json_encode(['ok' => true]));
    }
}

// ── Données ─────────────────────────────────────────────────────
$depStmt = $pdo->prepare("SELECT * FROM societe_dependances_exterieurs WHERE id_societe = ? ORDER BY famille ASC, ordre_affichage ASC, label ASC");
$depStmt->execute([$societeId]);
$deps = $depStmt->fetchAll(PDO::FETCH_ASSOC);

$depExt  = array_filter($deps, fn($d) => $d['famille'] === 'exterieur');
$depDep  = array_filter($deps, fn($d) => $d['famille'] === 'dependance');

$tbStmt = $pdo->prepare("SELECT * FROM societe_types_bien WHERE id_societe = ? AND actif = 1 ORDER BY ordre_affichage ASC, label ASC");
$tbStmt->execute([$societeId]);
$typesBien = $tbStmt->fetchAll(PDO::FETCH_ASSOC);

// Liaisons existantes
$liensStmt = $pdo->prepare("SELECT id_societe_type_bien, id_societe_dependance_exterieur FROM societe_type_bien_dependance_ext WHERE id_societe = ?");
$liensStmt->execute([$societeId]);
$liens = [];
foreach ($liensStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $liens[$l['id_societe_type_bien']][$l['id_societe_dependance_exterieur']] = true;
}
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paramétrage — Dépendances &amp; Extérieurs — MaBoxImmo</title>
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
        <h1>🏗️ Dépendances &amp; Extérieurs</h1>
      </div>
    </div>
    <div class="mbi-content">

      <?php if ($success): ?><div class="pa-alert pa-alert-ok"><?= h($success) ?></div><?php endif; ?>
      <?php foreach ($errors as $e): ?><div class="pa-alert pa-alert-err"><?= h($e) ?></div><?php endforeach; ?>

      <!-- Onglets -->
      <div class="pa-tabs">
        <button class="pa-tab active" onclick="switchTab('cartes',this)">🃏 Cartes</button>
        <button class="pa-tab" onclick="switchTab('liaisons',this)">🔗 Liaisons type de bien</button>
      </div>

      <!-- ── Onglet cartes ── -->
      <div class="pa-panel active" id="tab-cartes">
        <p class="pa-intro">Gérez les dépendances et extérieurs disponibles pour votre société.</p>

        <div class="mc-section-title">Extérieurs</div>
        <div class="mc-grid" data-mc-mode="admin">
          <?php foreach ($depExt as $d): ?>
          <?php include __DIR__ . '/_dep_card.php'; ?>
          <?php endforeach; ?>
        </div>

        <div class="mc-section-title">Dépendances</div>
        <div class="mc-grid" data-mc-mode="admin">
          <?php foreach ($depDep as $d): ?>
          <?php include __DIR__ . '/_dep_card.php'; ?>
          <?php endforeach; ?>
        </div>

        <!-- Ajouter custom -->
        <div class="pa-add-block">
          <div class="pa-add-title">+ Ajouter une dépendance / extérieur personnalisé</div>
          <form method="post" class="pa-add-form">
            <?= csrf_field('param_dep') ?>
            <input type="hidden" name="action" value="add_custom">
            <div class="pa-form-row">
              <div class="pa-field"><label>Code *</label><input type="text" name="code" placeholder="ex: loggia" maxlength="64" required></div>
              <div class="pa-field"><label>Libellé *</label><input type="text" name="label" maxlength="120" required></div>
              <div class="pa-field">
                <label>Famille</label>
                <select name="famille">
                  <option value="exterieur">Extérieur</option>
                  <option value="dependance">Dépendance</option>
                </select>
              </div>
              <div class="pa-field"><label>Icône FA</label><input type="text" name="icone" placeholder="fa-solid fa-door-open"></div>
              <div class="pa-field pa-field-wide"><label>Description</label><input type="text" name="description"></div>
            </div>
            <button type="submit" class="pa-btn-add">Ajouter</button>
          </form>
        </div>
      </div>

      <!-- ── Onglet liaisons ── -->
      <div class="pa-panel" id="tab-liaisons">
        <p class="pa-intro">Cliquez sur une dépendance pour activer ou désactiver son association avec un type de bien. Les dépendances activées apparaîtront dans les formulaires de saisie pour ce type de bien.</p>

        <table class="pa-table">
          <thead>
            <tr>
              <th>Type de bien</th>
              <th>Dépendances / Extérieurs associés</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($typesBien as $tb): ?>
            <tr>
              <td style="white-space:nowrap;font-weight:600;font-size:12px;"><?= h($tb['label']) ?></td>
              <td>
                <div class="pa-chips">
                  <?php foreach ($deps as $d): ?>
                  <?php if (!$d['actif']) continue; ?>
                  <?php $on = isset($liens[(int)$tb['id']][(int)$d['id']]); ?>
                  <span class="pa-chip <?= $on ? 'on' : '' ?>"
                        data-tb="<?= (int)$tb['id'] ?>"
                        data-dep="<?= (int)$d['id'] ?>"
                        onclick="toggleLien(this)"><?= h($d['label']) ?></span>
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
      <div class="pa-modal-title">Modifier</div>
      <form method="post" class="pa-add-form">
        <?= csrf_field('param_dep') ?>
        <input type="hidden" name="action" value="save_edit">
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
    function openEdit(id, label, desc, ordre) {
      document.getElementById('editId').value    = id;
      document.getElementById('editLabel').value = label;
      document.getElementById('editDesc').value  = desc;
      document.getElementById('editOrdre').value = ordre;
      document.getElementById('editModal').style.display = 'flex';
    }
    function closeEdit() { document.getElementById('editModal').style.display = 'none'; }

    function toggleLien(chip) {
      const idTB  = chip.dataset.tb;
      const idDep = chip.dataset.dep;
      const data  = new FormData();
      data.append('action','toggle_lien');
      data.append('id_tb', idTB);
      data.append('id_dep', idDep);
      data.append('csrf_token', '<?= csrf_token('param_dep') ?>');
      fetch(window.location.href, { method: 'POST', body: data })
        .then(r => r.json())
        .then(() => chip.classList.toggle('on'));
    }
  </script>
</body>
</html>
