<?php
/**
 * Migration 2026-04-22 bis : Mise à jour plafonds ALUR 2026
 *
 * Source : arrêté revalorisant les plafonds honoraires location (indexation IRL).
 *   Non tendue   :  8,00 €/m² → 8,07 €/m²
 *   Tendue       : 10,00 €/m² → 10,09 €/m²
 *   Très tendue  : 12,00 €/m² → 12,10 €/m²
 *   EDL (toutes zones) : inchangé à 3,00 €/m²
 *
 * Ne met à jour QUE les tarifs par défaut (id_societe IS NULL).
 * Les tarifs spécifiques société gardent leurs valeurs custom.
 *
 * Idempotente : l'UPDATE porte toujours les bonnes valeurs 2026 même si
 * rejouée plusieurs fois.
 */

return [
    'id'          => '20260422_honoraires_plafonds_2026',
    'title'       => 'Honoraires ALUR : plafonds 2026 (indexation IRL)',
    'description' => "Met à jour les 3 tarifs par défaut dans societe_tarifs_honoraires (id_societe IS NULL) vers les plafonds 2026 : 8,07 / 10,09 / 12,10 €/m². N'affecte pas les tarifs custom par société.",
    'created_at'  => '2026-04-22',
    'sql' => <<<'SQL'
UPDATE `societe_tarifs_honoraires`
SET `honoraires_location_bail_m2` = 8.07
WHERE `id_societe` IS NULL AND `zone_tendue` = 'non_tendue';

UPDATE `societe_tarifs_honoraires`
SET `honoraires_location_bail_m2` = 10.09
WHERE `id_societe` IS NULL AND `zone_tendue` = 'tendue';

UPDATE `societe_tarifs_honoraires`
SET `honoraires_location_bail_m2` = 12.10
WHERE `id_societe` IS NULL AND `zone_tendue` = 'tres_tendue';
SQL,
];
