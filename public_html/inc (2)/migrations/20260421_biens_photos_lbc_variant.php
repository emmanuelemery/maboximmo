<?php
/**
 * Migration : variante LBC pour biens_photos
 *
 * À l'upload, chaque photo d'un bien est désormais compressée (2500 px max,
 * JPEG q85) puis une variante dédiée Le Bon Coin est générée en parallèle :
 *   - 1200 px max de large
 *   - JPEG strict (LBC ne supporte pas WebP)
 *   - Forcée sous 1,9 Mo via baisse progressive de qualité
 *
 * Cette colonne stocke le chemin relatif de la variante LBC, pour que la
 * diffusion (Ubiflow / export LBC) la prenne directement plutôt que de
 * retraiter l'original à chaque export.
 */

return [
    'id'          => '20260421_biens_photos_lbc_variant',
    'title'       => 'biens_photos : ajout colonne url_lbc (variante 1200px JPEG <2Mo)',
    'description' => "Ajoute la colonne url_lbc sur biens_photos pour stocker le chemin de la variante conforme Le Bon Coin. Générée à l'upload par BienPhotosManager et rattrapable sur l'existant via admin/tools_photos_recompress.php.",
    'created_at'  => '2026-04-21',
    'sql' => <<<'SQL'
ALTER TABLE `biens_photos`
  ADD COLUMN IF NOT EXISTS `url_lbc` VARCHAR(255) NULL
    COMMENT 'Variante JPEG 1200px conforme Le Bon Coin (<2Mo) — générée à l upload';
SQL,
];
