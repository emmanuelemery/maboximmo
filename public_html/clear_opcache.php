<?php
// Réinitialise le OPcache PHP
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache réinitialisé!";
} else {
    echo "❌ OPcache pas disponible ou désactivé";
}
// Supprimer ce fichier après utilisation
unlink(__FILE__);
