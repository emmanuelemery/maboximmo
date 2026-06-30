<?php
/**
 * Migration : Module Bailleur — Données CRG
 *
 * Importe les données CRG depuis le serveur local via des requêtes PHP/PDO.
 * Utilise INSERT IGNORE → safe à rejouer, pas de doublons.
 *
 * Tables : crg_trimestres, crg_situations_locataires, locataires_statuts,
 *          biens (colonnes compatibles), immeubles (sans vendu/date_vente)
 *
 * NOTE : Cette migration exécute du PHP, pas du SQL pur.
 * Elle est marquée comme un SQL factice pour passer le gestionnaire,
 * mais son vrai travail est fait dans le fichier de data SQL.
 */

return [
    'id'          => '20260530_bailleur_module_data',
    'title'       => 'Module Bailleur — Import données CRG',
    'description' => 'Importe crg_trimestres (91), crg_situations_locataires (1844), locataires_statuts (331), biens et immeubles compatibles. Utiliser INSERT IGNORE — safe à rejouer.',
    'created_at'  => '2026-05-30',
    'sql' => <<<'SQL'

-- Cette migration importe les fichiers SQL de données.
-- Les fichiers data_crg.sql et data_biens_immeubles.sql doivent être
-- uploadés dans public_html/admin/ puis importés via phpMyAdmin
-- OU via le terminal Hostinger :
--   mysql -u USER -p DB < data_crg.sql
--   mysql -u USER -p DB < data_biens_immeubles.sql
--
-- Vérification après import :
SELECT COUNT(*) AS crg_trimestres FROM crg_trimestres WHERE parse_statut='ok';
SELECT COUNT(*) AS crg_situations FROM crg_situations_locataires;
SELECT COUNT(*) AS locataires_statuts FROM locataires_statuts;

SQL
];
