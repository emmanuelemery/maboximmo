<?php
/**
 * Migration : rubrique GED « Financement » — types de documents du module Financement.
 *
 * Ajoute (INSERT IGNORE, idempotent) les 7 types propres au financement, sous
 * `ged_document_types.metier = 'financement'`. Les 3 autres de la liste
 * (Appel de fonds, Attestation, Autre) existent déjà en générique et sont réutilisés
 * tels quels par le module — on ne modifie pas leur métier.
 */
return [
    'id'          => '20260721b_ged_types_financement',
    'title'       => 'GED : rubrique Financement (types de documents)',
    'description' => "Offre de prêt, accord bancaire, tableau d'amortissement, garantie, assurance emprunteur, déblocage des fonds, correspondance.",
    'created_at'  => '2026-07-21',
    'sql' => <<<'SQL'
INSERT IGNORE INTO `ged_document_types` (`code`,`libelle`,`abbr`,`metier`,`actif`) VALUES
 ('OFFRE_PRET',           'Offre de prêt',            'OFP', 'financement', 1),
 ('ACCORD_BANCAIRE',      'Accord bancaire',          'ACB', 'financement', 1),
 ('TABLEAU_AMORTISSEMENT','Tableau d''amortissement', 'TAM', 'financement', 1),
 ('GARANTIE',             'Garantie',                 'GAR', 'financement', 1),
 ('ASSURANCE_EMPRUNTEUR', 'Assurance emprunteur',     'ASE', 'financement', 1),
 ('DEBLOCAGE_FONDS',      'Déblocage des fonds',      'DEB', 'financement', 1),
 ('CORRESPONDANCE',       'Correspondance',           'COR', 'financement', 1);
SQL
];
