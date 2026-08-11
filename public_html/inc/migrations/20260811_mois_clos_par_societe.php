<?php
/**
 * Migration : la clôture du mois de paie devient PAR SOCIÉTÉ.
 *
 * AVANT : `mois_clos` était unique sur (mois, annee) et `closeMonth` faisait
 *   UPDATE salaires SET mois_cloture=… WHERE mois_reference LIKE ?
 * sans le moindre filtre. Clôturer la Régie Emery clôturait donc AUSSI Loca Immo
 * et Emery Immo, et le bouton affichait « Mois cloture » pour toutes les sociétés.
 *
 * APRÈS : une ligne par (mois, annee, id_societe).
 *
 * COMPATIBILITÉ DES DONNÉES EXISTANTES : les lignes déjà présentes reçoivent
 * `id_societe = 0`, qui signifie « clôture globale héritée ». Le contrôle lit
 * `id_societe IN (0, <société courante>)` : les mois déjà clôturés le restent
 * pour tout le monde, exactement comme avant. Aucune régression rétroactive.
 *
 * Idempotente : chaque étape est conditionnée à l'état réel du schéma.
 */
return [
    'id'          => '20260811_mois_clos_par_societe',
    'title'       => 'Paie — clôture du mois par société',
    'description' => "Ajoute id_societe à mois_clos et remplace l'unicité (mois, annee) par (mois, annee, id_societe). Les lignes existantes passent en id_societe=0 = clôture globale héritée.",
    'created_at'  => '2026-08-11',
    'sql' => <<<'SQL'
-- 1. La colonne de portée (0 = clôture globale héritée d'avant la migration).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mois_clos' AND COLUMN_NAME = 'id_societe');
SET @s := IF(@c = 0,
  'ALTER TABLE `mois_clos` ADD COLUMN `id_societe` INT NOT NULL DEFAULT 0 AFTER `annee`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. L'ancienne unicité (mois, annee) interdirait deux sociétés sur le même mois.
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mois_clos' AND INDEX_NAME = 'uniq_mois');
SET @s := IF(@i > 0, 'ALTER TABLE `mois_clos` DROP INDEX `uniq_mois`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. La nouvelle unicité, qui autorise une clôture par société.
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mois_clos' AND INDEX_NAME = 'uniq_mois_soc');
SET @s := IF(@i = 0,
  'ALTER TABLE `mois_clos` ADD UNIQUE KEY `uniq_mois_soc` (`mois`, `annee`, `id_societe`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SQL
];
