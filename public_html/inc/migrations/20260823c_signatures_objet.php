<?php
/**
 * Migration : la chaîne de signature cesse d'être réservée au bail.
 *
 * `bail_signatures` porte tout ce qu'on a fiabilisé cette semaine — jeton nominatif,
 * code SMS, vagues, expiration qui se dit, relance qui réarme, suivi en huit étapes.
 * Rien de tout cela n'est propre au bail. Or la table exige un `id_bail`, si bien que
 * faire signer un devis ou une convention imposait d'ouvrir une SIXIÈME chaîne de
 * signature — le dépôt en compte déjà cinq (`bail_signatures`, `mandat_signatures`,
 * `convention_signatures`, `rh_entretien_signatures`, `signatures`), dont deux sont le
 * même fichier dupliqué et une n'a jamais servi. C'est exactement le geste qui a produit
 * ces cinq-là.
 *
 * On ajoute donc le COUPLE générique `objet_type` / `objet_id`, et on rend `id_bail`
 * facultatif.
 *
 * ⚠️ `id_bail` EST CONSERVÉ, et rempli comme avant pour un bail. Une soixantaine
 * d'endroits l'interrogent (`WHERE id_bail = ?`) : le supprimer casserait la cérémonie
 * du jour au lendemain pour ne gagner qu'une colonne. Il devient une redondance assumée
 * — la vérité est dans `objet_type`/`objet_id`, `id_bail` en est le raccourci pour le
 * cas historique.
 *
 * ⚠️ Le backfill met `objet_type='bail'` et `objet_id=id_bail` sur TOUTES les lignes
 * existantes : sans lui, les cérémonies en cours deviendraient invisibles aux écrans qui
 * interrogeront désormais le couple générique. Une migration qui rend le passé illisible
 * n'est pas une migration, c'est une perte.
 */

return [
    'id'          => '20260823c_signatures_objet',
    'title'       => 'Signatures : objet générique (bail, document GED, …)',
    'description' => "Ajoute objet_type/objet_id à bail_signatures et rend id_bail facultatif, pour que la cérémonie (jeton, code SMS, vagues, suivi) serve aussi un document de la GED sans ouvrir une sixième chaîne de signature. id_bail est conservé et backfillé.",
    'created_at'  => '2026-08-23',
    'sql' => <<<'SQL'
ALTER TABLE `bail_signatures`
  ADD COLUMN IF NOT EXISTS `objet_type` VARCHAR(20) NOT NULL DEFAULT 'bail'
      COMMENT 'bail | ged — ce que l on fait signer' AFTER `id_bail`,
  ADD COLUMN IF NOT EXISTS `objet_id` INT UNSIGNED NULL
      COMMENT 'id du bail, ou id du document GED' AFTER `objet_type`;

ALTER TABLE `bail_signatures`
  MODIFY COLUMN `id_bail` INT UNSIGNED NULL
      COMMENT 'Raccourci historique, NULL hors bail. La verite est dans objet_type/objet_id';

UPDATE `bail_signatures`
   SET `objet_id` = `id_bail`, `objet_type` = 'bail'
 WHERE `objet_id` IS NULL AND `id_bail` IS NOT NULL;

ALTER TABLE `bail_signatures`
  ADD INDEX IF NOT EXISTS `idx_objet` (`objet_type`, `objet_id`);
SQL
];
