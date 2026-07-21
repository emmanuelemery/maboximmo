<?php
/**
 * Migration : Financement dans le partage patrimoine (comptable).
 *
 * - patrimoine_partages.montrer_financements : flag d'affichage du voyant/KPI 💶
 *   sur la ligne propriétaire (comme montrer_creanciers pour l'avocat).
 * - fin_dossier : champs de PRÊT du bailleur (crédit d'acquisition ou trésorerie),
 *   saisis par le comptable : organisme, date, montant, mensualité, solde, échéance.
 *   Les biens concernés = fin_dossier_lien(entity_type='BIEN'). Le rattachement
 *   propriétaire (KPI) = fin_dossier_lien(entity_type='PROPRIETAIRE').
 *
 * Idempotente pour la table (IF NOT EXISTS via patrimoine_partages déjà créé) ;
 * les ADD COLUMN ne sont pas conditionnels → à n'appliquer qu'une fois.
 */
return [
    'id'          => '20260721d_financement_partage',
    'title'       => 'Financement : flag partage + champs prêt fin_dossier',
    'description' => "patrimoine_partages.montrer_financements + fin_dossier (organisme, date, montant, mensualité, solde, échéance, commentaire).",
    'created_at'  => '2026-07-21',
    'sql' => <<<'SQL'
ALTER TABLE patrimoine_partages
  ADD COLUMN montrer_financements TINYINT(1) NOT NULL DEFAULT 0 AFTER montrer_creanciers;

ALTER TABLE fin_dossier
  ADD COLUMN organisme VARCHAR(120) NULL AFTER libelle,
  ADD COLUMN date_financement DATE NULL AFTER organisme,
  ADD COLUMN montant_total DECIMAL(14,2) NULL AFTER date_financement,
  ADD COLUMN mensualite DECIMAL(12,2) NULL AFTER montant_total,
  ADD COLUMN solde_restant DECIMAL(14,2) NULL AFTER mensualite,
  ADD COLUMN date_echeance DATE NULL AFTER solde_restant,
  ADD COLUMN commentaire TEXT NULL AFTER synthese;
SQL
];
