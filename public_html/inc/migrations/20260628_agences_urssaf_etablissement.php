<?php
/**
 * Migration : coordonnées URSSAF / SIRET / APE par AGENCE (établissement).
 *
 * Le n° de compte employeur URSSAF est propre à chaque établissement (agence),
 * pas à la société. On le stocke donc au niveau `agences` pour pré-remplir les
 * contrats de travail selon l'agence de rattachement du salarié.
 *
 * Données issues des courriers URSSAF / CARSAT de Régie Emery (SIREN 398912766)
 * et Emery Immobilier (SIREN 303754204). Matché par SIREN société + ville agence.
 * Idempotente (ADD COLUMN IF NOT EXISTS + UPDATE ciblés).
 */
return [
    'id'          => '20260628_agences_urssaf_etablissement',
    'title'       => 'Agences : n° compte URSSAF + SIRET + APE par établissement',
    'description' => "Ajoute `urssaf_compte` et `code_ape` sur `agences` et renseigne SIRET/URSSAF/APE des établissements Régie Emery (Mions, Chaponost, Vienne, Lyon) et Emery Immobilier (Chamalières, Riom).",
    'created_at'  => '2026-06-28',
    'sql' => <<<'SQL'
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `urssaf_compte` VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS `code_ape` VARCHAR(10) NULL;

UPDATE `agences` a JOIN `societes` s ON s.id = a.id_societe
   SET a.siret='39891276600126', a.urssaf_compte='827 2194384984', a.code_ape='6831Z'
 WHERE s.siren='398912766' AND a.ville LIKE 'MIONS%';

UPDATE `agences` a JOIN `societes` s ON s.id = a.id_societe
   SET a.siret='39891276600050', a.urssaf_compte='827 2100564682', a.code_ape='6831Z'
 WHERE s.siren='398912766' AND a.ville LIKE 'CHAPONOST%';

UPDATE `agences` a JOIN `societes` s ON s.id = a.id_societe
   SET a.siret='39891276600118', a.urssaf_compte='827 2182195277', a.code_ape='6831Z'
 WHERE s.siren='398912766' AND a.ville LIKE 'VIENNE%';

UPDATE `agences` a JOIN `societes` s ON s.id = a.id_societe
   SET a.code_ape='6831Z'
 WHERE s.siren='398912766' AND a.ville LIKE 'LYON%';

UPDATE `agences` a JOIN `societes` s ON s.id = a.id_societe
   SET a.siret='30375420400053', a.code_ape='6831Z'
 WHERE s.siren='303754204' AND a.ville LIKE 'CHAMALIERES%';

UPDATE `agences` a JOIN `societes` s ON s.id = a.id_societe
   SET a.siret='30375420400020', a.code_ape='6831Z'
 WHERE s.siren='303754204' AND a.ville LIKE 'RIOM%';
SQL
];
