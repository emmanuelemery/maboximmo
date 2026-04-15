<?php
define('OPENAI_API_KEY', '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');
define('OPENAI_TEXT_MODEL', 'gpt-5');

// SMTP — défini ici, hors public_html, jamais dans le code source
define('SMTP_HOST',      'smtp.hostinger.com');
define('SMTP_PORT',      465);
define('SMTP_SECURE',    'ssl');
define('SMTP_USERNAME',  'contact@maboximmo.fr');
define('SMTP_PASSWORD',  'MaBoxImmo2026!');
define('MAIL_FROM',      'contact@maboximmo.fr');
define('MAIL_FROM_NAME', 'MaBoxImmo');

// IMAP — boîte documents@maboximmo.fr pour intake automatique
define('IMAP_PASSWORD_DOCS', 'MaBoxImmo2026!');