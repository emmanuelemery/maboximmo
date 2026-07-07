<?php
/**
 * Migration : conditions particulières SUR LE LOYER (distinctes des conditions générales).
 *
 * `conditions_particulieres` (générales) existe déjà sur bien_baux → réutilisée.
 * On ajoute `conditions_particulieres_loyer` pour les stipulations propres au loyer
 * (paliers, franchise, révision spécifique, indexation dérogatoire…).
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260707a_baux_conditions_loyer',
    'title'       => 'Baux : conditions particulières sur le loyer',
    'description' => "Ajoute conditions_particulieres_loyer sur bien_baux (stipulations propres au loyer : paliers, franchise, indexation dérogatoire…).",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `conditions_particulieres_loyer` TEXT NULL AFTER `conditions_particulieres`;
SQL
];
