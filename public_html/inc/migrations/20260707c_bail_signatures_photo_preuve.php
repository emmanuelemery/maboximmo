<?php
/**
 * Migration : photo-preuve du signataire (prise à la signature en présentiel).
 *
 * Après avoir signé, on peut capturer une photo du signataire (webcam PC ou caméra
 * du téléphone). Elle est stockée en data URI (base64) et affichée À CÔTÉ de la
 * signature dans le PDF, comme preuve d'identité.
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260707c_bail_signatures_photo_preuve',
    'title'       => 'Signatures bail : photo-preuve du signataire',
    'description' => "Ajoute photo_preuve (data URI base64) sur bail_signatures pour la photo prise au moment de la signature.",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `bail_signatures` ADD COLUMN IF NOT EXISTS `photo_preuve` MEDIUMTEXT NULL AFTER `signature_data`;
SQL
];
