<?php
/**
 * Migration v2 — ged_level_codes : flag is_entity_placeholder
 *
 * Certains codes N3 (DOSSIER, SOCIETE, BANQUE, ASSURANCE, REUNION, COLLABORATEUR,
 * PARTENAIRE, OPERATEUR, SUPPORT, THEME, ORGANISME, VEHICULE, MODULE, COMMUNE,
 * APPORTEUR, IMMEUBLE, PROPRIETAIRE, BIEN, LOCATAIRE, FOURNISSEUR) sont en réalité
 * des "entités à nommer" (placeholders). Quand l'utilisateur clique un tel N3 dans
 * la modal d'import, l'UI doit l'inviter à saisir le nom de l'INSTANCE
 * (ex: "DURAND vs SCI Lac" pour un dossier juridique).
 *
 * Ce flag permet à l'UI de détecter ces codes et de mettre en avant le champ
 * `nom_entite` (qui existe déjà dans la modal et alimente {ENTITE} du
 * name_canonical).
 *
 * AJOUT uniquement, idempotent. Aucune table créée. Aucun code modifié.
 */

return [
    'id'          => '20260503_ged_v2_21_entity_placeholder',
    'title'       => 'Ma GED Box V2.5 — ged_level_codes : flag is_entity_placeholder pour N3 nominatifs',
    'description' => "Ajoute is_entity_placeholder TINYINT(1) sur ged_level_codes et marque les codes connus qui représentent une entité à instancier (DOSSIER, SOCIETE, BANQUE, ASSURANCE, REUNION, COLLABORATEUR, PARTENAIRE, OPERATEUR, SUPPORT, THEME, ORGANISME, VEHICULE, MODULE, COMMUNE, APPORTEUR, IMMEUBLE, PROPRIETAIRE, BIEN, LOCATAIRE, FOURNISSEUR). L'UI peut alors highlight le champ nom_entite et inviter l'utilisateur à saisir l'instance. Idempotent.",
    'created_at'  => '2026-05-03',
    'sql' => <<<'SQL'
-- Ajout du flag (additif, default 0 = comportement existant inchangé)
ALTER TABLE `ged_level_codes`
  ADD COLUMN IF NOT EXISTS `is_entity_placeholder` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = ce code represente une entite a instancier (DOSSIER, SOCIETE, IMMEUBLE...) ; UI doit demander le nom_entite';

ALTER TABLE `ged_level_codes`
  ADD INDEX IF NOT EXISTS `idx_ged_level_codes_entity_placeholder` (`is_entity_placeholder`);

-- Marque tous les codes connus qui sont des entités à nommer (peu importe le niveau)
UPDATE `ged_level_codes`
SET `is_entity_placeholder` = 1
WHERE `code` IN (
  -- Entités métier MaBoxImmo (qui pourraient être picker depuis BDD)
  'IMMEUBLE', 'PROPRIETAIRE', 'BIEN', 'LOCATAIRE', 'FOURNISSEUR', 'COLLABORATEUR',
  -- Entités libres (saisie directe)
  'DOSSIER', 'SOCIETE', 'BANQUE', 'ASSURANCE', 'PARTENAIRE',
  'REUNION', 'OPERATEUR', 'SUPPORT', 'THEME', 'ORGANISME', 'VEHICULE',
  'MODULE', 'COMMUNE', 'APPORTEUR', 'STRUCTURE', 'SYSTEME'
);
SQL,
];
