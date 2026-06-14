<?php
/**
 * Migration : multi-lots sur le dossier/mandat de vente.
 *
 * BESOIN (Emmanuel 2026-06-14) : un mandat de vente peut couvrir PLUSIEURS lots
 *   d'un immeuble (immeuble de rapport, vente en bloc OU à la découpe), qu'ils
 *   soient en gestion chez nous (loyers connus) ou non (mandat extérieur → saisie).
 *
 * MODÈLE :
 *   - `dossier_vente` perd son UK(id_bien) : `id_bien` reste le LOT PRINCIPAL
 *     (celui qui a créé le dossier, dv_ensure_for_bien reste idempotent dessus).
 *   - Nouvelle table `dossier_vente_bien` = N lots rattachés au dossier, chacun
 *     portant prix_vente + loyer_reel + loyer_potentiel « pour la vente ».
 *   - `biens.loyer_potentiel` ajouté : à la SIGNATURE du mandat, les loyers du lot
 *     sont repris dans le bien (loyer_reel → biens.loyer_hc, potentiel → ce champ).
 *
 * Prix total du mandat = Σ prix_vente des lots (calculé, pas stocké).
 *
 * Backfill : 1 ligne pivot par dossier existant (le lot principal, rang 0), prix
 *   pré-rempli depuis le prix courant du bien si dispo. Le cas mono-bien = N=1,
 *   donc 100 % rétrocompatible pour l'existant.
 *
 * Idempotent (IF NOT EXISTS / IF EXISTS, MariaDB). down = rollback documenté.
 */
return [
    'id'          => '20260614f_dossier_vente_lots',
    'title'       => 'Multi-lots dossier/mandat de vente',
    'description' => "Retire UK(id_bien) de dossier_vente, crée dossier_vente_bien (N lots : prix_vente, loyer_reel, loyer_potentiel, rang), ajoute biens.loyer_potentiel. Un mandat de vente peut couvrir plusieurs lots. Backfill : 1 lot principal par dossier existant.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
-- 1. Le dossier n'est plus 1:1 avec un bien (id_bien = lot principal conservé).
ALTER TABLE `dossier_vente` DROP INDEX IF EXISTS `uk_dossier_bien`;
ALTER TABLE `dossier_vente` ADD KEY IF NOT EXISTS `idx_bien` (`id_bien`);

-- 2. Table des lots rattachés au dossier de vente.
CREATE TABLE IF NOT EXISTS `dossier_vente_bien` (
    `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_dossier`      BIGINT(20) UNSIGNED NOT NULL,
    `id_bien`         INT(10) UNSIGNED NOT NULL,
    `prix_vente`      DECIMAL(12,2) NULL,      -- prix de vente du lot (Σ = prix total mandat)
    `loyer_reel`      DECIMAL(12,2) NULL,      -- loyer mensuel HC réellement perçu
    `loyer_potentiel` DECIMAL(12,2) NULL,      -- loyer mensuel HC potentiel (relocation)
    `rang`            INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `id_user`         INT(10) UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dossier_lot` (`id_dossier`, `id_bien`),
    KEY `idx_dossier` (`id_dossier`),
    KEY `idx_bien` (`id_bien`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Champ loyer potentiel sur le bien (reçoit la reprise à la signature du mandat).
ALTER TABLE `biens` ADD COLUMN IF NOT EXISTS `loyer_potentiel` DECIMAL(12,2) NULL AFTER `loyer_hc`;

-- 4. Backfill : le lot principal de chaque dossier devient une ligne pivot (rang 0).
--    Prix pré-rempli depuis le prix de vente courant du bien si disponible.
INSERT INTO `dossier_vente_bien` (`id_dossier`, `id_bien`, `prix_vente`, `rang`, `id_user`)
SELECT dv.id, dv.id_bien,
       (SELECT bp.montant FROM bien_prix bp
          WHERE bp.id_bien = dv.id_bien AND bp.type_valeur = 'prix_vente'
            AND bp.scenario_code = 'courant' AND bp.is_courant = 1
          ORDER BY bp.id DESC LIMIT 1),
       0, dv.id_user
FROM `dossier_vente` dv
WHERE NOT EXISTS (
    SELECT 1 FROM `dossier_vente_bien` dvb
     WHERE dvb.id_dossier = dv.id AND dvb.id_bien = dv.id_bien
);
SQL
    ,
    // ── Rollback (manuel) ────────────────────────────────────────────────
    'down' => <<<'SQL'
DROP TABLE IF EXISTS `dossier_vente_bien`;
ALTER TABLE `biens` DROP COLUMN `loyer_potentiel`;
ALTER TABLE `dossier_vente` ADD UNIQUE KEY `uk_dossier_bien` (`id_bien`);
SQL
];
