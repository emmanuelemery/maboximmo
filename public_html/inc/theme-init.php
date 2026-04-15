<!-- Thème : lecture localStorage avant le premier rendu pour éviter le flash -->
<script>
(function(){
  var t = localStorage.getItem('mbi-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
