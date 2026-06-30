<?php
/**
 * Migration : critique IA "prise de vue" sur biens_photos
 *
 * Ajoute les colonnes utilisées par bien_photo_analyser.php pour stocker la
 * critique technique d'une photo immobilière (cadrage / luminosité / rangement
 * / angle / lisibilité), en plus de l'analyse commerciale existante.
 *
 * Un seul appel IA (Claude Sonnet 4.6 Vision) produit désormais commercial +
 * critique en JSON, qui sont stockés ici.
 *
 * Champ analyse_statut :
 *   - 'pending' : photo uploadée, analyse pas encore lancée OU en cours
 *   - 'ok'      : analyse terminée avec succès
 *   - 'error'   : analyse a échoué (réseau, JSON invalide, quota, etc.)
 *
 *   Sert à : (1) bouton "Analyser toutes les photos" (cible status != 'ok'),
 *            (2) cron retry nightly (cible status = 'error' ou 'pending' ancien),
 *            (3) éviter les doublons d'analyse à l'affichage.
 *
 * Backfill : photos déjà analysées (description_ia non vide) marquées 'ok'.
 */

return [
    'id'          => '20260502_biens_photos_critique',
    'title'       => 'biens_photos : critique IA prise de vue (niveau, points forts/faibles, conseil, statut)',
    'description' => "Ajoute 7 colonnes (critique_niveau ENUM bon/moyen/mauvais, critique_points_forts JSON, critique_points_faibles JSON, critique_conseil, critique_ia_date, analyse_statut ENUM pending/ok/error, analyse_erreur) + 2 index. Backfill : photos avec description_ia non vide → analyse_statut='ok'.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
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
        COMMENT 'État de l analyse IA (commercial + critique) : pending / ok / error',
    ADD COLUMN IF NOT EXISTS `analyse_erreur` VARCHAR(500) DEFAULT NULL
        COMMENT 'Dernière erreur rencontrée si analyse_statut=error';

ALTER TABLE `biens_photos`
    ADD INDEX IF NOT EXISTS `idx_biens_photos_analyse_statut` (`analyse_statut`),
    ADD INDEX IF NOT EXISTS `idx_biens_photos_critique_niveau` (`critique_niveau`);

UPDATE `biens_photos`
SET `analyse_statut` = 'ok'
WHERE `description_ia` IS NOT NULL AND `description_ia` <> '' AND `analyse_statut` = 'pending';
SQL,
];
