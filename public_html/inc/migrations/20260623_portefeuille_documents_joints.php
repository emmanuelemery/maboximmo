<?php
/**
 * Migration : documents joints par bien dans un portefeuille de vente.
 *
 * Sur la page « Constituer un portefeuille », chaque bien affiche désormais la
 * liste de SES documents GED déjà chargés (DPE, bail, diagnostics…) avec une
 * coche. Les documents cochés doivent partir avec le portefeuille (téléchargeables
 * sur la page privée à jeton `p.php`). On stocke la sélection (liste d'IDs GED)
 * par ligne de portefeuille. NULL/vide = comportement historique (tous les docs
 * de types autorisés).
 */

return [
    'id'          => '20260623_portefeuille_documents_joints',
    'title'       => 'Colonne portefeuille_biens.documents_joints (IDs GED à joindre)',
    'description' => "Persiste les documents GED cochés par bien dans un portefeuille (JSON d'IDs ged_documents). Filtre les docs téléchargeables côté page privée p.php. NULL = tous les docs de types autorisés (rétrocompat).",
    'created_at'  => '2026-06-23',
    'sql' => <<<'SQL'
ALTER TABLE `portefeuille_biens`
    ADD COLUMN `documents_joints` TEXT NULL DEFAULT NULL
    COMMENT 'JSON: liste d''IDs ged_documents à joindre (NULL = tous les types autorisés)'
    AFTER `prix_acte_en_main`;
SQL
];
