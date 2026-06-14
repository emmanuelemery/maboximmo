<?php
/**
 * Migration : `dossier_vente.statut` — cycle de vie temporaire → confirmé.
 *
 * Un dossier reste TEMPORAIRE (brouillon) tant que le mandat de vente n'est pas
 * signé. Il passe CONFIRMÉ à la signature du mandat. Permet de distinguer les
 * dossiers réels des dossiers amorcés/abandonnés (nettoyables plus tard) sans
 * perdre les infos saisies en cours de route.
 *
 * Backfill : confirmé si le mandat lié est signé (date_signature) ou bien vendu.
 * Idempotent (ADD COLUMN IF NOT EXISTS). down = rollback documenté.
 */
return [
    'id'          => '20260614e_dossier_vente_statut',
    'title'       => 'dossier_vente.statut (temporaire/confirmé)',
    'description' => "Ajoute dossier_vente.statut ENUM(temporaire,confirme) DEFAULT temporaire. Confirmé à la signature du mandat. Backfill depuis mandats.date_signature / biens vendus.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
ALTER TABLE `dossier_vente`
  ADD COLUMN IF NOT EXISTS `statut` ENUM('temporaire','confirme') NOT NULL DEFAULT 'temporaire' AFTER `source`,
  ADD KEY IF NOT EXISTS `idx_statut` (`statut`);

-- Backfill : confirmé si mandat signé ou bien déjà vendu.
UPDATE `dossier_vente` dv
  LEFT JOIN `mandats` m ON m.id = dv.id_mandat
  LEFT JOIN `biens`   b ON b.id = dv.id_bien
  SET dv.statut = 'confirme'
  WHERE (m.date_signature IS NOT NULL) OR (b.statut_bien = 'vendu') OR dv.etape IN ('acte','solde');
SQL
    ,
    'down' => <<<'SQL'
ALTER TABLE `dossier_vente` DROP COLUMN `statut`;
SQL
];
