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

// Adresse expéditeur des mails — DOIT être une adresse authentifiée par le SMTP
// (sinon Hostinger rejette avec "Sender address rejected: not owned by user …").
// Par défaut = contact@maboximmo.fr (compte SMTP utilisé).
define('CRON_RECAP_FROM_EMAIL', 'contact@maboximmo.fr');
define('CRON_RECAP_FROM_NAME',  'MaBoxImmo');

// Reply-To : où arrivent les réponses des commerciaux quand ils répondent aux mails.
// Peut être une adresse d'un autre domaine (pas de contrainte SMTP).
define('CRON_RECAP_REPLY_TO',   'emmanuel.emery@regie-emery.com');
