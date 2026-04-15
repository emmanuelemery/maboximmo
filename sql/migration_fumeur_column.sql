-- Migration: ajouter colonnes fumeur + zone tendue à la table biens
ALTER TABLE `biens` ADD COLUMN `fumeur_accepte` tinyint(1) NOT NULL DEFAULT 0 AFTER `animaux_acceptes`;
ALTER TABLE `biens` ADD COLUMN `zone_tendue` enum('non_tendue','tendue','tres_tendue') DEFAULT NULL AFTER `honoraires_locataire`;
