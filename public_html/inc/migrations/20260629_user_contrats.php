<?php
/**
 * Migration : historique des CONTRATS de travail d'un collaborateur.
 *
 * Un salarié peut avoir PLUSIEURS contrats successifs (ex : CDD, puis un autre CDD,
 * puis un CDI). On garde donc chaque contrat rédigé comme une ligne d'historique,
 * pas un champ unique sur `users`.
 *
 * Idempotente (CREATE TABLE IF NOT EXISTS).
 */
return [
    'id'          => '20260629_user_contrats',
    'title'       => 'RH : historique des contrats de travail (user_contrats)',
    'description' => "Crée `user_contrats` (1 ligne par contrat rédigé : type, niveau, salaire, fonction, dates, motif, durée, mission). Supporte plusieurs contrats successifs par collaborateur.",
    'created_at'  => '2026-06-29',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_contrats` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `id_user`       INT NOT NULL,
  `type_contrat`  VARCHAR(10)  NULL,
  `niveau`        VARCHAR(10)  NULL,
  `salaire_brut`  VARCHAR(20)  NULL,
  `fonction`      VARCHAR(190) NULL,
  `date_debut`    VARCHAR(20)  NULL,
  `date_fin`      VARCHAR(20)  NULL,
  `motif`         VARCHAR(255) NULL,
  `duree`         VARCHAR(60)  NULL,
  `mission`       TEXT         NULL,
  `created_by`    INT          NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uc_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
];
