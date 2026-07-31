<?php
/**
 * Migration : BACKFILL de biens.id_bien_type (Phase A de la consolidation des types).
 *
 * CONTEXTE
 *   La migration 20260430_bien_types a créé le référentiel unique `bien_types` et la
 *   colonne `biens.id_bien_type`, mais le backfill n'a couvert qu'une partie des biens.
 *   ~1073 biens ont encore id_bien_type NULL et ne s'appuient que sur la legacy
 *   `id_type_bien` → types_bien_legacy. Objectif : id_bien_type renseigné à 100 %.
 *
 * SÉCURITÉ
 *   - Résolution PAR CODE (jamais par id) : les ids sont INVERSÉS entre tables
 *     (types_bien_legacy: maison=1/appartement=2 ; bien_types: appartement=1/maison=6).
 *     Une migration par id inverserait ~920 biens → INTERDIT.
 *   - ADDITIF & IDEMPOTENT : ne touche que les lignes id_bien_type IS NULL ; la colonne
 *     legacy id_type_bien reste intacte (rollback possible : restaurer le backup, ou
 *     UPDATE biens SET id_bien_type=NULL pour annuler).
 *   - Les orphelins (id_type_bien absent de la legacy, ex. bien réf AG-EE-MAI-5P) ne sont
 *     PAS devinés ici : ils restent NULL et sont à corriger manuellement (rapport à part).
 */
return [
    'id'          => '20260731a_backfill_id_bien_type',
    'title'       => 'biens : backfill id_bien_type (par code) depuis la legacy',
    'description' => "Renseigne biens.id_bien_type (référentiel bien_types) pour tous les biens qui n'ont que la legacy id_type_bien. Résolution par CODE (anti-inversion). Additif, idempotent, non destructif.",
    'created_at'  => '2026-07-31',
    'sql' => <<<'SQL'
UPDATE biens b
JOIN types_bien_legacy tbl ON tbl.id = b.id_type_bien
JOIN bien_types bt          ON bt.code = tbl.code
SET b.id_bien_type = bt.id
WHERE b.id_bien_type IS NULL;
SQL,
];
