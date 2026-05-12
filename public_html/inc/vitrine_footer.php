<?php
declare(strict_types=1);

$agence = $agence ?? [];
$slug = (string)($agence['slug'] ?? '');
$tel = trim((string)($agence['telephone'] ?? ''));
$mail = trim((string)($agence['email'] ?? ''));
$addr = trim((string)($agence['adresse_1'] ?? ''));
$cp = trim((string)($agence['code_postal'] ?? ''));
$ville = trim((string)($agence['ville'] ?? ''));
?>

  </div>
</main>

<footer class="v-footer">
  <div class="v-container v-footer-inner">
    <div class="v-footer-col">
      <div class="v-footer-title"><?= h((string)($agence['nom_agence'] ?? 'Agence')) ?></div>
      <div class="v-footer-text">Tiers de confiance pour vendre, louer et gérer vos biens en toute sérénité.</div>
      <div class="v-footer-links">
        <a href="<?= h(vitrine_path($slug, '/annonces')) ?>">Annonces</a>
        <a href="<?= h(vitrine_path($slug, '/estimation-gratuite')) ?>">Estimation gratuite</a>
      </div>
    </div>

    <div class="v-footer-col">
      <div class="v-footer-title">Contact</div>
      <?php if ($tel !== ''): ?><div class="v-footer-text">Téléphone : <a href="tel:<?= h(preg_replace('/\\s+/', '', $tel) ?? $tel) ?>"><?= h($tel) ?></a></div><?php endif; ?>
      <?php if ($mail !== ''): ?><div class="v-footer-text">Email : <a href="mailto:<?= h($mail) ?>"><?= h($mail) ?></a></div><?php endif; ?>
      <?php if ($addr !== '' || $cp !== '' || $ville !== ''): ?>
        <div class="v-footer-text"><?= h(trim($addr . ' ' . $cp . ' ' . $ville)) ?></div>
      <?php endif; ?>
    </div>

    <div class="v-footer-col">
      <div class="v-footer-title">Confiance</div>
      <div class="v-footer-text">Sélection rigoureuse, transparence, suivi et reporting.</div>
      <div class="v-footer-text">© <?= (int)date('Y') ?> — Site vitrine MaBoxImmo</div>
    </div>
  </div>
</footer>

</body>
</html>

