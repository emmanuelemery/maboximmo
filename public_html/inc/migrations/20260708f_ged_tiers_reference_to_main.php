<?php
/**
 * Migration : répare les documents chargés depuis une fiche TIERS qui étaient liés en
 * relation 'reference' (→ section « Mentionné dans ») au lieu de 'main' (→ « Documents
 * du tiers »). Corrige uniquement les liens TIERS 'reference' n'ayant AUCUN autre lien
 * 'main' : le tiers est donc la cible principale réelle. Aucun doc légitimement « mentionné »
 * (rattaché à un bien/bail via son propriétaire) n'est touché.
 *
 * Cause corrigée côté code : inc/fluxbox_functions.php (branche $tiersId → 'main').
 */
return [
    'id'          => '20260708f_ged_tiers_reference_to_main',
    'title'       => 'GED : docs tiers mal classés reference → main',
    'description' => "Reclasse en 'main' les liens GED de type TIERS créés à tort en 'reference' (docs chargés depuis une fiche tiers), sans autre lien 'main'.",
    'created_at'  => '2026-07-08',
    'sql' => <<<'SQL'
UPDATE `ged_document_links` l
SET l.`relation_type` = 'main'
WHERE l.`entity_type` = 'TIERS' AND l.`relation_type` = 'reference'
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM `ged_document_links`) m
      WHERE m.`document_id` = l.`document_id` AND m.`relation_type` = 'main'
  );
SQL
];
