<?php
/**
 * Migration : biens.id_user_actuel + index (rétro-compat / idempotent)
 *
 * Objectif :
 *   - Garantir l'existence de `biens.id_user_actuel` (commercial attribué au bien)
 *     utilisé par bien_form_create_draft, bien_detail (anti-doublon brouillon)
 *     et le nouveau filtre commercial de bien_liste.php.
 *   - Ajouter l'index associé pour les filtres de listing.
 *
 * Note : la colonne avait déjà été ajoutée par la migration 20260418_express_flow,
 * mais celle-ci groupe énormément de changements (slugs, refs, annonces_photos…)
 * et peut ne pas avoir été jouée intégralement sur prod. Cette migration isolée
 * permet de garantir l'état de la BDD pour le filtre commercial sans risque
 * de side-effect.
 *
 * Statements additifs uniquement (IF NOT EXISTS) → rejouable autant de fois
 * que nécessaire.
 */

return [
    'id'          => '20260527_biens_id_user_actuel',
    'title'       => 'biens — colonne id_user_actuel + index',
    'description' => "Garantit l'existence de biens.id_user_actuel (commercial attribué) et l'index associé. Nécessaire pour le filtre commercial de bien_liste.php.",
    'created_at'  => '2026-05-27',
    'sql'         => <<<'SQL'

ALTER TABLE `biens`
    ADD COLUMN IF NOT EXISTS `id_user_actuel` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK users.id — commercial attribué au bien (set par bien_form_create_draft)'
        AFTER `id_agence`;

ALTER TABLE `biens`
    ADD INDEX IF NOT EXISTS `idx_biens_user_actuel` (`id_user_actuel`);

SQL
];
