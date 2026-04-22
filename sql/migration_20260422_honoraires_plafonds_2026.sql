-- ════════════════════════════════════════════════════════════════
-- Migration 2026-04-22 bis : Mise à jour plafonds ALUR 2026
-- ════════════════════════════════════════════════════════════════
-- Source : arrêté revalorisant les plafonds honoraires location (indexation IRL).
--   Non tendue   :  8,00 €/m² → 8,07 €/m²
--   Tendue       : 10,00 €/m² → 10,09 €/m²
--   Très tendue  : 12,00 €/m² → 12,10 €/m²
--   EDL (toutes zones) : inchangé à 3,00 €/m²
-- Ne met à jour QUE les tarifs par défaut (id_societe IS NULL).
-- Les tarifs spécifiques société gardent leurs valeurs custom.

UPDATE `societe_tarifs_honoraires`
SET `honoraires_location_bail_m2` = 8.07
WHERE `id_societe` IS NULL AND `zone_tendue` = 'non_tendue';

UPDATE `societe_tarifs_honoraires`
SET `honoraires_location_bail_m2` = 10.09
WHERE `id_societe` IS NULL AND `zone_tendue` = 'tendue';

UPDATE `societe_tarifs_honoraires`
SET `honoraires_location_bail_m2` = 12.10
WHERE `id_societe` IS NULL AND `zone_tendue` = 'tres_tendue';
