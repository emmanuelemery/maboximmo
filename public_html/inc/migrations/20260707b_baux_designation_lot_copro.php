<?php
/**
 * Migration : désignation du bien + lot de copropriété sur le bail.
 *
 * Le bail est un instantané au moment de la signature : on capture sa propre
 * désignation du bien et, s'il est en copropriété, le n° de lot + les tantièmes.
 * Ces champs sont pré-remplis depuis le bien (biens.designation / numero_lot /
 * lot_tantiemes / bien_en_copropriete) mais restent éditables/persistés sur le bail.
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260707b_baux_designation_lot_copro',
    'title'       => 'Baux : désignation du bien + lot de copropriété',
    'description' => "Ajoute bien_designation, en_copropriete, lot_copropriete, lot_tantiemes sur bien_baux (désignation + lot copro + tantièmes, repris du bien).",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `bien_designation` TEXT NULL AFTER `erp_local`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `en_copropriete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `bien_designation`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `lot_copropriete` VARCHAR(50) NULL AFTER `en_copropriete`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `lot_tantiemes` VARCHAR(30) NULL AFTER `lot_copropriete`;
SQL
];
