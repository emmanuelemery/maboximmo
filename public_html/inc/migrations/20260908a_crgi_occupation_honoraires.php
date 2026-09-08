<?php
/**
 * Migration : LES HONORAIRES DE GESTION — LA PREUVE QUE LE MANDAT VIT.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ UN BIEN VIDE ET UN MANDAT PERDU CESSENT TOUS DEUX D'APPELER UN LOYER. Rien,
 *    dans le tableau d'occupation, ne les distingue — et la file d'arbitrage
 *    demandait donc de trancher entre « vendu », « gestion terminée » et
 *    « vacant » sur un silence.
 *
 * ⚠️ SAUF QUE LE DOCUMENT LE DIT AILLEURS. Tant que la régie facture ses
 *    HONORAIRES DE GESTION sur ce compte rendu, le mandat n'est pas perdu : on
 *    gère un bien vide. Emmanuel, 08/09/2026, sur un compte rendu de juillet où
 *    plus aucun loyer n'est appelé : « on voit qu'il n'y a plus de loyer appelé
 *    donc le bien est vide, et que nous continuons à lui prendre des honoraires
 *    de gestion — donc gestion NON perdue, bien vide ». La preuve tient en une
 *    ligne : « 31/07/2026 Honoraires Gestion TTC (taux:5,50 % HT,base:283,00 €)
 *    … 18,67 ».
 *
 * ⚠️ CES LIGNES VIVENT HORS DES BLOCS DE LOT, dans la section « - Honoraires de
 *    Gestion - » du compte rendu. Les chercher au niveau du lot ne les aurait
 *    jamais trouvées : le compteur porte donc sur le COMPTE RENDU entier.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER — une colonne de staging, rien d'autre.
 */
return [
    'id'          => '20260908a_crgi_occupation_honoraires',
    'title'       => 'Intégration CRG — les honoraires de gestion du compte rendu',
    'description' => "Ajoute crgi_occupation.honoraires : le nombre de lignes d'honoraires de "
                   . "gestion facturées par le compte rendu qui porte cette occupation. "
                   . "C'est ce qui distingue un BIEN VIDE encore géré d'un MANDAT PERDU — "
                   . "deux situations qui cessent l'une comme l'autre d'appeler un loyer, et "
                   . "que le tableau d'occupation ne départage pas. Aucune écriture métier.",
    'created_at'  => '2026-09-08',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_occupation`
  ADD COLUMN `honoraires` SMALLINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Lignes d honoraires de gestion du CRG : la regie facture, donc le mandat vit.'
  AFTER `dernier_appel_au`;
SQL,
];
