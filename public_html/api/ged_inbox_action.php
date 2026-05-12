<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Proxy endpoint pour l'inbox (validate/skip/reject).
 * Fichier : public_html/api/ged_inbox_action.php
 *
 * Pourquoi :
 * - Hostinger mod_security / WAF est plus permissif sur `/api/`.
 * - Permet d'éviter des réponses HTML (403/404) qui cassent le front (JSON attendu).
 *
 * Implémentation :
 * - On délègue 100% de la logique à `modules/ged/ged_inbox_action.php`.
 */

require_once dirname(__DIR__) . '/modules/ged/ged_inbox_action.php';

