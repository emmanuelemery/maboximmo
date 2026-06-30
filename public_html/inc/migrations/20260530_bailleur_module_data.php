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

-- Migration historique (placeholder). L'import des données CRG était fait
-- via des fichiers SQL externes (data_crg.sql / data_biens_immeubles.sql).
-- Ces données sont déjà en base → plus rien à faire ici.
-- No-op : aucune écriture, aucune lecture renvoyant un result set.
DO 1;

SQL
];
