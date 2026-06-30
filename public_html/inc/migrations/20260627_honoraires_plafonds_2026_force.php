<?php
/**
 * Migration 2026-06-27 : force les plafonds honoraires location 2026 partout.
 *
 * Plafonds légaux 2026 (indexés IRL) :
 *   - non tendue        : 8,07 €/m²
 *   - tendue            : 10,09 €/m²
 *   - très tendue       : 12,10 €/m²
 *   - état des lieux     : 3,03 €/m² (toutes zones)
 *
 * 1) Barèmes de RÉFÉRENCE (id_societe IS NULL) → fixés aux plafonds 2026.
 * 2) CAP légal : aucune société ne peut dépasser le plafond → on plafonne
 *    les éventuelles valeurs supérieures (les tarifs INFÉRIEURS au plafond,
 *    librement choisis par l'agence, sont conservés).
 *
 * Idempotent : rejouable sans effet une fois les valeurs en place.
 * (Le calcul réel passe par inc/honoraires_helper.php qui lit cette table ;
 *  bien_detail / annonces / vitrines en héritent automatiquement.)
 */
return [
    'id'          => '20260627_honoraires_plafonds_2026_force',
    'title'       => 'Force les plafonds honoraires location 2026 (8,07 / 10,09 / 12,10 · EDL 3,03)',
    'description' => "Fixe les barèmes de référence (id_societe NULL) aux plafonds 2026 et plafonne toute valeur dépassant le maximum légal. Conserve les tarifs agence inférieurs au plafond.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'

-- (1) Barèmes de référence (id_societe IS NULL) = plafonds 2026
UPDATE `societe_tarifs_honoraires` SET `honoraires_location_bail_m2` = 8.07,  `honoraires_edl_m2` = 3.03 WHERE `id_societe` IS NULL AND `zone_tendue` = 'non_tendue';
UPDATE `societe_tarifs_honoraires` SET `honoraires_location_bail_m2` = 10.09, `honoraires_edl_m2` = 3.03 WHERE `id_societe` IS NULL AND `zone_tendue` = 'tendue';
UPDATE `societe_tarifs_honoraires` SET `honoraires_location_bail_m2` = 12.10, `honoraires_edl_m2` = 3.03 WHERE `id_societe` IS NULL AND `zone_tendue` = 'tres_tendue';

-- (2) CAP légal : plafonner toute valeur supérieure au maximum 2026 (toutes sociétés)
UPDATE `societe_tarifs_honoraires` SET `honoraires_location_bail_m2` = 8.07  WHERE `zone_tendue` = 'non_tendue'  AND `honoraires_location_bail_m2` > 8.07;
UPDATE `societe_tarifs_honoraires` SET `honoraires_location_bail_m2` = 10.09 WHERE `zone_tendue` = 'tendue'      AND `honoraires_location_bail_m2` > 10.09;
UPDATE `societe_tarifs_honoraires` SET `honoraires_location_bail_m2` = 12.10 WHERE `zone_tendue` = 'tres_tendue' AND `honoraires_location_bail_m2` > 12.10;
UPDATE `societe_tarifs_honoraires` SET `honoraires_edl_m2` = 3.03 WHERE `honoraires_edl_m2` > 3.03;

SQL,
];
