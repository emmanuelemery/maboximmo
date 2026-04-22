<?php
/**
 * config/cron.template.php — Template pour config/cron.php
 *
 * ⚠️ À recopier en `config/cron.php` et ADAPTER le token secret.
 *    Le fichier config/cron.php est gitignored → secret local uniquement.
 *
 * Usage côté endpoint :
 *   api/cron_recap_annonces.php attend ?token=XXX et compare à CRON_RECAP_TOKEN.
 *
 * Usage côté cron Hostinger (hPanel → Avancé → Tâches Cron) :
 *   0 9 * * *  curl -s "https://maboximmo.fr/api/cron_recap_annonces.php?token=REMPLACE_PAR_TON_TOKEN" > /dev/null
 */
declare(strict_types=1);

// ⚠️ REMPLACE par un token aléatoire fort (32+ caractères).
// Génère en CLI : openssl rand -hex 32
// OU en PHP : bin2hex(random_bytes(32))
define('CRON_RECAP_TOKEN', 'REMPLACE_PAR_TON_TOKEN_SECRET_ALEATOIRE');

// Email super-admin qui reçoit le récap global (ne pas laisser vide)
define('CRON_RECAP_ADMIN_EMAIL', 'emmanuel.emery@regie-emery.com');

// Adresse expéditeur des mails
define('CRON_RECAP_FROM_EMAIL', 'ne-pas-repondre@maboximmo.fr');
define('CRON_RECAP_FROM_NAME',  'MaBoxImmo');
