<?php
/**
 * Migration : workflow comptable salaires multi-itérations (par agence)
 *
 * Contexte
 * --------
 * Le workflow comptable mensuel actuel (rh_salaires.php) ne tient pas
 * compte des aller-retours fréquents entre l'agence et le comptable :
 *   1. Agence envoie le 1er PDF des salaires
 *   2. Comptable renvoie un projet
 *   3. Agence corrige + renvoie un 2e PDF
 *   4. Comptable renvoie un 2e projet
 *   5. ... etc, jusqu'aux bulletins finaux
 *
 * Cette table journalise CHAQUE action (envoi, import projet, import
 * bulletins) avec :
 *   - le numéro d'itération (1, 2, 3...) pour ordonner les aller-retours
 *   - le fichier conservé sur disque (téléchargeable depuis la timeline)
 *   - l'horodatage et l'utilisateur qui a déclenché
 *
 * Granularité : id_agence (pas id_societe) — chaque agence a son propre
 * workflow car chaque comptable peut être différent et les users à payer
 * dépendent de l'agence.
 *
 * Visibilité (côté code applicatif) :
 *   - admin (role=1)             : voit toutes les agences
 *   - gestion_salaires=1         : voit son agence uniquement
 *   - autres                     : workflow caché
 */

return [
    'id'          => '20260430_rh_salaire_workflow',
    'title'       => 'Workflow comptable salaires : journal des aller-retours par agence',
    'description' => "Crée la table rh_salaire_workflow_log pour journaliser chaque action du workflow comptable (envoi PDF, import projet, import bulletins) par agence et par mois, avec itérations multiples, fichiers conservés et horodatage. Permet de garder l'historique des échanges (envoi 1, projet 1, envoi 2, projet 2, ..., bulletins finaux).",
    'created_at'  => '2026-04-30',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rh_salaire_workflow_log` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_societe`            INT UNSIGNED NOT NULL,
  `id_agence`             INT UNSIGNED NOT NULL COMMENT 'Workflow par agence (chaque agence = comptable + users distincts)',
  `mois_reference`        DATE NOT NULL COMMENT 'Premier jour du mois concerné (YYYY-MM-01)',
  `type_action`           ENUM('envoi_comptable','import_projet','import_bulletins') NOT NULL,
  `iteration`             INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Numéro d ordre dans la chaîne d aller-retours pour ce (agence, mois, type)',
  `fichier_path`          VARCHAR(500) NULL COMMENT 'Chemin relatif depuis public_html/uploads/rh_salaires/...',
  `fichier_nom_original`  VARCHAR(255) NULL,
  `fichier_taille`        INT UNSIGNED NULL,
  `destinataire`          VARCHAR(190) NULL COMMENT 'Email comptable pour envoi_comptable, sinon NULL',
  `triggered_by`          INT UNSIGNED NULL COMMENT 'users.id qui a déclenché l action',
  `status`                ENUM('ok','error','pending') NOT NULL DEFAULT 'ok',
  `error_msg`             VARCHAR(500) NULL,
  `commentaire`           VARCHAR(500) NULL COMMENT 'Note libre ajoutable par l utilisateur',
  `date_action`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agence_mois`     (`id_agence`, `mois_reference`),
  KEY `idx_societe_mois`    (`id_societe`, `mois_reference`),
  KEY `idx_type_action`     (`type_action`),
  KEY `idx_triggered_by`    (`triggered_by`),
  KEY `idx_date_action`     (`date_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Journal multi-itérations des échanges salaires entre agence et comptable';
SQL,
];
