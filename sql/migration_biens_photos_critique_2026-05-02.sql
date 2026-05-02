-- =====================================================================
-- Migration : critique IA "prise de vue" sur biens_photos
-- Date      : 2026-05-02
-- Objet     : Ajouter les colonnes utilisées par bien_photo_analyser.php
--             pour stocker la critique technique d'une photo immobilière
--             (cadrage / luminosité / rangement / angle / lisibilité)
--             en plus de l'analyse commerciale existante.
--
--             Les colonnes existantes (categorie, description_ia,
--             description_ia_date) restent inchangées : un seul appel IA
--             produit désormais commercial + critique en JSON, qui sont
--             stockés ici.
--
-- Champ analyse_statut :
--   - 'pending' : photo upload, analyse pas encore lancée OU en cours
--   - 'ok'      : analyse terminée avec succès
--   - 'error'   : analyse a échoué (réseau, JSON invalide, quota, etc.)
--
--   Sert à :
--     1) bouton "Analyser toutes les photos" (cible status != 'ok')
--     2) cron retry nightly  (cible status = 'error' ou 'pending' ancien)
--     3) éviter les doublons d'analyse à l'affichage
--
-- Notes :
--  - Colonnes NULLable : les photos déjà présentes restent valides.
--  - Backfill : photos déjà analysées (description_ia non vide) → status='ok'
-- =====================================================================

START TRANSACTION;

ALTER TABLE `biens_photos`
    ADD COLUMN IF NOT EXISTS `critique_niveau` ENUM('bon','moyen','mauvais') DEFAULT NULL
        COMMENT 'Niveau qualité de la prise de vue (IA Claude Vision)',
    ADD COLUMN IF NOT EXISTS `critique_points_forts` TEXT DEFAULT NULL
        COMMENT 'JSON array : points forts de la prise de vue',
    ADD COLUMN IF NOT EXISTS `critique_points_faibles` TEXT DEFAULT NULL
        COMMENT 'JSON array : points faibles de la prise de vue',
    ADD COLUMN IF NOT EXISTS `critique_conseil` TEXT DEFAULT NULL
        COMMENT 'Conseil prioritaire pour la prochaine prise de vue',
    ADD COLUMN IF NOT EXISTS `critique_ia_date` DATETIME DEFAULT NULL
        COMMENT 'Horodatage de la critique IA',
    ADD COLUMN IF NOT EXISTS `analyse_statut` ENUM('pending','ok','error') DEFAULT 'pending'
        COMMENT 'État de l''analyse IA (commercial + critique) : pending / ok / error',
    ADD COLUMN IF NOT EXISTS `analyse_erreur` VARCHAR(500) DEFAULT NULL
        COMMENT 'Dernière erreur rencontrée si analyse_statut=error';

ALTER TABLE `biens_photos`
    ADD INDEX IF NOT EXISTS `idx_biens_photos_analyse_statut` (`analyse_statut`),
    ADD INDEX IF NOT EXISTS `idx_biens_photos_critique_niveau` (`critique_niveau`);

-- Backfill : photos déjà analysées (description_ia non vide) marquées 'ok'
-- (la critique reste vide tant qu'on ne ré-analyse pas — affichage masqué côté UI)
UPDATE `biens_photos`
SET `analyse_statut` = 'ok'
WHERE `description_ia` IS NOT NULL AND `description_ia` <> '' AND `analyse_statut` = 'pending';

COMMIT;

-- =====================================================================
-- FIN DE MIGRATION
-- =====================================================================
