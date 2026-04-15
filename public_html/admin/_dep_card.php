<div class="mc-card <?= $d['actif'] ? '' : 'is-inactive' ?> <?= $d['is_custom'] ? 'is-custom' : '' ?>"
     data-mc-value="<?= h($d['code']) ?>"
     data-mc-label="<?= h($d['label']) ?>"
     data-mc-desc="<?= h($d['description'] ?? '') ?>"
     data-mc-id="<?= (int)$d['id'] ?>">
  <div class="mc-icon"><i class="<?= h($d['icone'] ?? 'fa-solid fa-box') ?>"></i></div>
  <div class="mc-label"><?= h($d['label']) ?></div>
  <div class="mc-actions">
    <form method="post" style="display:inline;">
      <?= csrf_field('param_dep') ?>
      <input type="hidden" name="action" value="toggle_actif">
      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
      <button type="submit" class="mc-action-btn"><?= $d['actif'] ? 'Désactiver' : 'Activer' ?></button>
    </form>
    <button type="button" class="mc-action-btn"
      onclick="openEdit(<?= (int)$d['id'] ?>,'<?= h(addslashes($d['label'])) ?>','<?= h(addslashes($d['description'] ?? '')) ?>',<?= (int)$d['ordre_affichage'] ?>)">Modifier</button>
    <?php if ($d['is_custom']): ?>
    <form method="post" style="display:inline;">
      <?= csrf_field('param_dep') ?>
      <input type="hidden" name="action" value="delete_custom">
      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
      <button type="submit" class="mc-action-btn is-danger" onclick="return confirm('Supprimer ?')">✕</button>
    </form>
    <?php endif; ?>
  </div>
</div>
