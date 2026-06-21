<?php
/**
 * Migration : logos sociétés pour les vitrines (fallback branding).
 * Renseigne societes.logo_url UNIQUEMENT s'il est vide (ne pas écraser).
 * Les fichiers doivent être présents : images/logos/regie-emery.jpg et emery-immo.jpg
 */
return [
    'id'          => '20260621b_societe_logos',
    'title'       => 'Logos sociétés (vitrines)',
    'description' => 'Renseigne societes.logo_url (sociétés 1 & 2) si vide.',
    'created_at'  => '2026-06-21',
    'sql' => <<<'SQL'
UPDATE `societes` SET `logo_url` = 'images/logos/regie-emery.jpg'
  WHERE `id` = 1 AND (`logo_url` IS NULL OR `logo_url` = '');
UPDATE `societes` SET `logo_url` = 'images/logos/emery-immo.jpg'
  WHERE `id` = 2 AND (`logo_url` IS NULL OR `logo_url` = '');
SQL,
];
