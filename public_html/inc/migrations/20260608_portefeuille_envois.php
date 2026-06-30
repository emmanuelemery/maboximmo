<?php
/**
 * Migration : historique des ENVOIS d'un portefeuille (liste d'offres acheteur).
 *
 * À chaque envoi par email d'un portefeuille, on enregistre une trace FIGÉE :
 * snapshot complet des biens + prix + honoraires au moment de l'envoi, email
 * destinataire, sujet, et le PDF archivé (lien GED). Permet plusieurs envois
 * successifs tracés, et de retrouver exactement ce qui a été envoyé même si les
 * prix changent ensuite.
 */

return [
    'id'          => '20260608_portefeuille_envois',
    'title'       => 'Table portefeuille_envois (historique des envois de listes d\'offres)',
    'description' => "Trace figée de chaque envoi email d'un portefeuille : snapshot JSON des biens/prix/honoraires, destinataire, sujet, PDF archivé en GED, auteur, date.",
    'created_at'  => '2026-06-08',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `portefeuille_envois` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_portefeuille`     INT UNSIGNED NOT NULL,
    `date_envoi`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `email_destinataire`  VARCHAR(190) NOT NULL,
    `destinataire_nom`    VARCHAR(190) NULL,
    `type_destinataire`   VARCHAR(20)  NULL COMMENT 'investisseur | commercialisateur',
    `sujet`               VARCHAR(255) NULL,
    `message`             TEXT         NULL,
    `snapshot_json`       LONGTEXT     NOT NULL COMMENT 'Biens + prix + honoraires figés à l envoi',
    `nb_biens`            INT          NOT NULL DEFAULT 0,
    `total_prix_vente`    DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_honoraires`    DECIMAL(15,2) NOT NULL DEFAULT 0,
    `pdf_ged_document_id` INT UNSIGNED NULL COMMENT 'ged_documents.id du PDF archivé',
    `pdf_path`            VARCHAR(500) NULL COMMENT 'chemin local de secours si hors GED',
    `id_user`             INT UNSIGNED NULL COMMENT 'agent expéditeur',
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pe_portefeuille` (`id_portefeuille`),
    KEY `idx_pe_date` (`date_envoi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL,
];
