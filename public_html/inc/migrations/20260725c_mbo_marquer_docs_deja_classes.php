<?php
/**
 * Migration corrective : marquer TRAITÉ (côté MaBoxOffice) les documents déjà classés en GED.
 *
 * Symptôme : un doc chargé via le modal FluxBox (auto-commit GED) restait « à classer » dans
 * l'inbox MaBoxOffice → doublon visuel. Le code est désormais corrigé pour marquer le doc à la
 * volée ; cette migration régularise les documents déjà classés AVANT le fix (inbox MBO qui
 * pointent déjà vers un ged_documents actif).
 *
 * Règle d'or : idempotent (ne touche que les lignes encore mbo_classe_at IS NULL qui ont un GED).
 */
return [
    'id'          => '20260725c_mbo_marquer_docs_deja_classes',
    'title'       => 'MaBoxOffice : régulariser les docs déjà classés en GED',
    'description' => "Passe mbo_statut='traite' + mbo_classe_at pour les fluxbox_documents ayant déjà un ged_documents actif.",
    'created_at'  => '2026-07-25',
    'sql' => <<<'SQL'
UPDATE `fluxbox_documents` f
  JOIN `ged_documents` g ON g.`fluxbox_source_id` = f.`id` AND g.`status` = 'active'
   SET f.`mbo_statut` = 'traite',
       f.`mbo_classe_at` = NOW(),
       f.`mbo_ged_document_id` = g.`id`
 WHERE f.`mbo_classe_at` IS NULL;
SQL
];
