<?php
/**
 * Migration : VUE de compatibilité `types_bien` → `types_bien_legacy`
 *
 * CONTEXTE
 *   La migration 20260430_bien_types a renommé `types_bien` en `types_bien_legacy`.
 *   Plusieurs pages PHP avaient des `JOIN types_bien` non recensés lors du recâblage
 *   initial → 500 sur ces pages en prod (ex. annonce_liste.php, admin_honoraires_recalc,
 *   agency_proprietaire_fiche, ~12 fichiers concernés).
 *
 * SOLUTION
 *   Créer une VUE `types_bien` qui sélectionne tout depuis `types_bien_legacy`. Les
 *   pages legacy continuent à fonctionner sans modification (leurs `b.id_type_bien`
 *   pointent vers les mêmes ids que la vue ⇒ matching correct).
 *
 * IMPORTANT
 *   La vue ne contient QUE les 13 codes legacy (maison, appartement, terrain, ...).
 *   Les 14 nouveaux codes ajoutés dans `bien_types` (studio, duplex, villa, mas, etc.)
 *   ne sont pas reflétés ici — ils s'affichent quand le code lit directement depuis
 *   `bien_types` (cas du flux Ubiflow + form loader recâblés).
 *
 *   Cette vue est une mesure transitoire pour ne pas casser les pages non encore
 *   recâblées. À supprimer une fois TOUS les fichiers PHP migrés vers bien_types.
 *
 * IDEMPOTENCE
 *   CREATE OR REPLACE VIEW = rejouable sans casse.
 */

return [
    'id'          => '20260430_types_bien_view',
    'title'       => 'Vue de compatibilité types_bien → types_bien_legacy (transitoire)',
    'description' => "Crée une VUE `types_bien` qui pointe sur `types_bien_legacy` pour préserver les pages PHP non encore recâblées (annonce_liste, admin_honoraires_recalc, etc.). À supprimer une fois le recâblage complet.",
    'created_at'  => '2026-04-30',
    'sql' => <<<'SQL'
CREATE OR REPLACE VIEW `types_bien` AS
  SELECT
    `id`,
    `code`,
    `libelle`,
    `categorie`,
    `description`,
    `ordre_affichage`,
    `actif`,
    `date_creation`
  FROM `types_bien_legacy`;
SQL,
];
