<?php
declare(strict_types=1);

/**
 * inc/feature_flags.php — Feature flags MaBoxImmo
 * ─────────────────────────────────────────────────────────────────────
 *
 * Constantes globales contrôlant l'activation des nouveaux modules.
 * Inclus par bootstrap.php donc disponible partout.
 *
 * Pour activer un flag en local SANS toucher ce fichier (et donc sans risque
 * en prod), créer `inc/feature_flags.local.php` avec :
 *
 *     <?php define('FEATURE_ENTITY_MATCHER', true);
 *
 * (fichier dans .gitignore — chaque dev/env définit ses overrides)
 */

// ─── Charge les overrides locaux EN PREMIER (define() définitif) ────
$_localOverrides = __DIR__ . '/feature_flags.local.php';
if (file_exists($_localOverrides)) {
    require_once $_localOverrides;
}
unset($_localOverrides);

// ─── Defaults — appliqués SEULEMENT si non déjà définis par les overrides ──

/**
 * FEATURE_ENTITY_MATCHER
 *
 * Quand ON : les fichiers legacy (bailleur_check_duplicate.php,
 * bien_check_duplicate.php, transaction_doc_match.php, admin_tiers_merge.php)
 * délèguent leur matching au moteur central inc/entity_matcher.php.
 *
 * Quand OFF (par défaut) : les fichiers legacy gardent leur logique d'origine.
 * Aucun risque de régression. Le moteur central reste accessible via les APIs
 * dédiées (api/tiers_check_duplicate.php, api/immeuble_check_duplicate.php)
 * pour les nouveaux développements.
 *
 * Migration prévue Sprint 2 : on bascule ON fichier par fichier, avec test
 * A/B en parallèle pour vérifier l'iso-résultat.
 */
if (!defined('FEATURE_ENTITY_MATCHER')) {
    define('FEATURE_ENTITY_MATCHER', false);
}

/**
 * FEATURE_ENTITY_MATCHER_LOG
 *
 * Si ON : chaque appel délégué au moteur (bailleur_check_duplicate,
 * bien_check_duplicate, …) écrit une ligne dans le log PHP avec count + score
 * + match_type pour comparer empiriquement legacy vs moteur en parallèle.
 *
 * À activer SEULEMENT en local pendant la phase de migration.
 */
if (!defined('FEATURE_ENTITY_MATCHER_LOG')) {
    define('FEATURE_ENTITY_MATCHER_LOG', false);
}
