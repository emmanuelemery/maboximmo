<?php
/**
 * Migration : DROP de societe_activites_docs (doublon de societes_couvertures)
 *
 * EXPLICATION DE LA BOULETTE
 * J'ai créé `societe_activites_docs` (migration 20260508_4) sans avoir vu
 * que la table `societes_couvertures` existait DÉJÀ depuis le 2026-04-11
 * (migration_societes_couvertures.sql) avec exactement la même fonction :
 * stocker les attestations RCP/GF par activité au niveau société.
 *
 * `societes_couvertures` est la BONNE table — elle :
 *   - est alimentée par l'analyse IA des docs upload via societe.php → Documents
 *   - a une UI complète sur societe.php?sa_id=N → onglet Financier
 *   - gère le versioning (est_active + version_num)
 *   - lie chaque attestation au document source (id_document_source)
 *
 * Le helper agence_load_with_societe_docs() a été corrigé pour piocher dans
 * societes_couvertures. La table societe_activites_docs n'est plus utilisée.
 *
 * On peut la dropper sans risque — pas de FK, pas de réfs ailleurs dans le code
 * après le fix du helper.
 */

return [
    'id'          => '20260508_5_drop_societe_activites_docs_doublon',
    'title'       => 'DROP societe_activites_docs (doublon de societes_couvertures qui existait depuis avril)',
    'description' => "Annule la migration 20260508_4 partiellement : drop la table societe_activites_docs créée par erreur. La table societes_couvertures (existante depuis 2026-04-11) est la bonne source. Les flags activite_* sur societes/agences créés par la même migration restent (utiles pour masquer les sidebars).",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
DROP TABLE IF EXISTS `societe_activites_docs`;
SQL,
];
