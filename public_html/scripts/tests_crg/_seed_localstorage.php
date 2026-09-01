<?php
/**
 * ARTEFACT DE TEST — amorce le localStorage comme s'il portait d'anciennes réponses.
 *
 * ⚠️ SERT À PROUVER LE FILET DE RÉCUPÉRATION. Quand l'enregistrement des réponses est passé du
 *    navigateur au serveur, la question était : les réponses déjà saisies remontent-elles
 *    toutes seules ? Promettre que oui sans l'avoir vérifié aurait pu coûter le travail d'une
 *    lecture entière. Cette page écrit un faux brouillon ; on recharge ensuite l'écran de
 *    lecture et on regarde si `data/crg_reponses_*.json` se remplit.
 *
 * ⚠️ MÊME ORIGINE, DONC MÊME localStorage : toute page servie par localhost partage le stockage
 *    de `crg_lecture_compte.php`. C'est ce qui permet d'amorcer sans toucher à l'écran testé.
 *
 * Conservé plutôt que supprimé : le jour où l'on refera ce genre de bascule, le contrôle est là.
 * Usage : /scripts/tests_crg/_seed_localstorage.php?compte=01040000
 */
$compte = preg_match('/^\d{8}$/', (string)($_GET['compte'] ?? '')) ? $_GET['compte'] : '01040000';
?><!doctype html><meta charset="utf-8"><title>amorce</title>
<pre id="o">amorçage…</pre>
<script>
const faux = {
  "0": "TEST AMORCE — reponse de la question 1 saisie avant la bascule.",
  "5": "TEST AMORCE — reponse de la question 6 saisie avant la bascule."
};
localStorage.setItem('crgl_reponses_<?= $compte ?>', JSON.stringify(faux));
document.getElementById('o').textContent =
  'brouillon amorcé : ' + Object.keys(faux).length + ' réponses dans localStorage';
</script>
