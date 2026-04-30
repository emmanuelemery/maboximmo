// MaBoxImmo — portail public annonces
// Interactions minimales : galerie photos (clic miniature → main).
(function () {
  'use strict';

  function bindGallery() {
    var thumbs = document.querySelectorAll('[data-mbi-thumb]');
    if (!thumbs.length) return;
    var mainImg = document.querySelector('.mbi-gallery-main img');
    if (!mainImg) return;

    thumbs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var src = btn.getAttribute('data-mbi-thumb');
        if (!src) return;
        mainImg.src = src;
        thumbs.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
      });
    });
  }

  function bindSearchEnter() {
    // Submit du formulaire au "Entrée" depuis n'importe quel champ.
    var forms = document.querySelectorAll('.mbi-search');
    forms.forEach(function (form) {
      form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
          if (form.tagName === 'FORM') {
            e.preventDefault();
            form.submit();
          }
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bindGallery();
      bindSearchEnter();
    });
  } else {
    bindGallery();
    bindSearchEnter();
  }
})();
