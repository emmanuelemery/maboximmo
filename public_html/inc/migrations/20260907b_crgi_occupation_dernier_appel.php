<?php
/**
 * Migration : JUSQU'OÙ LE BLOC APPELLE-T-IL ? — la date qui tranche l'occupation.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ COMPTER LES APPELS NE SUFFIT PAS, IL FAUT SAVOIR JUSQU'OÙ ILS VONT. La colonne
 *    `appels` (migration `20260907a`) disait COMBIEN ; elle ne disait pas si le
 *    dernier s'arrêtait AVANT la date d'arrêté. Or c'est exactement là que se lit
 *    un départ en cours de trimestre : CHILLA appelle « Du 01.02.26 Au 09.02.26 »
 *    dans un rapport arrêté au 31/03/2026 — le mois n'est pas complet, le bail est
 *    fini. Trois preuves concordantes sur la même page : la période tronquée, un
 *    « Rembt D G reversé -842,00 » et « honoraires état des lieux SORTIE CHILLA ».
 *
 * ⚠️ ET UN APPEL DE L'AN PASSÉ N'EST PAS UN APPEL DE LA PÉRIODE. SEMACO portait
 *    trois lignes « Du 01.01.25 Au 31.12.25 » dans un rapport du 1er trimestre
 *    2026 : des régularisations annuelles, comptées comme trois appels, qui
 *    faisaient croire à un locataire présent. `appels` ne compte désormais que ce
 *    qui RECOUPE la période du compte rendu.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER — une colonne de staging, rien d'autre.
 */
return [
    'id'          => '20260907b_crgi_occupation_dernier_appel',
    'title'       => 'Intégration CRG — jusqu’où le bloc d’occupation appelle',
    'description' => "Ajoute crgi_occupation.dernier_appel_au : la fin du dernier appel qui "
                   . "recoupe la période du compte rendu. Comparée à la date d'arrêté, elle "
                   . "démontre un départ en cours de trimestre sans rien déduire d'une "
                   . "absence. Aucune écriture métier.",
    'created_at'  => '2026-09-07',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_occupation`
  ADD COLUMN `dernier_appel_au` DATE DEFAULT NULL
  COMMENT 'Fin du dernier appel RECOUPANT la periode. NULL = le bloc n appelle rien.'
  AFTER `appels`;
SQL,
];
