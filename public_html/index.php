<?php
// Page d'accueil = portail public d'annonces immobilières (mbi_annonces).
// Le portail pro reste accessible via /default.php directement (pas de redirect chain).
header('Location: /mbi_annonces_index.php', true, 302);
exit;
