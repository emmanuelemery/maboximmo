</main>

<footer class="mbi-footer" role="contentinfo">
  <div class="mbi-container mbi-footer-inner">
    <div class="mbi-footer-col">
      <div class="mbi-footer-title">MaBoxImmo</div>
      <p class="mbi-footer-text">Le portail immobilier des agences MaBoxImmo. Annonces vérifiées, contact direct avec les professionnels.</p>
    </div>

    <div class="mbi-footer-col">
      <div class="mbi-footer-title">Trouver un bien</div>
      <ul class="mbi-footer-links">
        <li><a href="<?= h(app_url('/mbi_annonces_recherche.php?transaction=vente')) ?>">Acheter</a></li>
        <li><a href="<?= h(app_url('/mbi_annonces_recherche.php?transaction=location')) ?>">Louer</a></li>
        <li><a href="<?= h(app_url('/mbi_annonces_index.php')) ?>">Toutes les annonces</a></li>
      </ul>
    </div>

    <div class="mbi-footer-col">
      <div class="mbi-footer-title">Professionnels</div>
      <ul class="mbi-footer-links">
        <li><a href="<?= h(app_url('/agence_portail.php')) ?>">Espace Pro</a></li>
        <li><a href="<?= h(app_url('/agence_inscription.php')) ?>">Devenir agence MBI</a></li>
        <li><a href="<?= h(app_url('/default.php')) ?>">Se connecter</a></li>
      </ul>
    </div>

    <div class="mbi-footer-col">
      <div class="mbi-footer-title">Confiance</div>
      <p class="mbi-footer-text">Annonces diffusées par des professionnels de l'immobilier titulaires d'une carte professionnelle.</p>
      <p class="mbi-footer-copy">© <?= (int)date('Y') ?> MaBoxImmo</p>
    </div>
  </div>
</footer>

<script src="<?= h(asset_url('/js/mbi_annonces.js')) ?>" defer></script>
</body>
</html>
