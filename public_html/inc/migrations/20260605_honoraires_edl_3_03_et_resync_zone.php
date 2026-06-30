<?php
/**
 * Migration 2026-06-05 : EDL 3,03 €/m² + resync cache biens.zone_tendue
 *
 * Deux corrections liées au chantier zones tendues / honoraires ALUR :
 *
 * 1) ÉTAT DES LIEUX indexé IRL 2026 : 3,00 → 3,03 €/m² (au même titre que
 *    location+bail 8/10/12 → 8,07/10,09/12,10). Corrige la table de tarifs
 *    (source réelle du calcul) PUIS la valeur figée des annonces déjà créées.
 *
 * 2) CACHE biens.zone_tendue : le label « ZONE » de la Card Conditions
 *    financières lit cette colonne, rafraîchie uniquement à la sauvegarde d'une
 *    annonce. Les scripts de restauration zones tendues ont corrigé les
 *    honoraires mais pas ce cache → biens déjà créés affichés « NON TENDUE »
 *    alors que leur CP est en zone tendue (Bron 69500, Chaponost 69630…).
 *    On le recalcule depuis le CP (COALESCE immeuble puis bien), exactement
 *    comme inc/honoraires_helper.php::zone_tendue_from_cp().
 *
 * IDEMPOTENT / rejouable : chaque UPDATE ne touche que les lignes encore à
 * l'ancienne valeur (tarif 3,00, EDL stocké = surface*3,00, ou cache zone
 * divergent du CP). Re-jouer ne refait rien une fois corrigé.
 *
 * Prérequis : la migration 20260604_zones_tendues_national_decret doit être
 * appliquée (base_zones_tendues à jour) pour que la resync (statement 3) ait
 * le bon référentiel.
 */
return [
    'id'          => '20260605_honoraires_edl_3_03_et_resync_zone',
    'title'       => 'Honoraires EDL 3,03 €/m² + resync cache biens.zone_tendue',
    'description' => "EDL 3,00→3,03 (table tarifs + annonces figées) et recalcul du cache biens.zone_tendue depuis le CP (corrige les biens existants affichés NON TENDUE à tort).",
    'created_at'  => '2026-06-05',
    'sql' => <<<'SQL'

-- (1) Tarif EDL : 3,00 -> 3,03 €/m² (source réelle du calcul, toutes zones)
UPDATE `societe_tarifs_honoraires`
SET `honoraires_edl_m2` = 3.03
WHERE `honoraires_edl_m2` = 3.00;

-- (2) Annonces déjà créées : EDL stocké recalculé surface*3,00 -> surface*3,03
UPDATE `annonces` a
JOIN `biens` b ON b.id = a.id_bien
SET a.honoraires_etat_des_lieux = ROUND(COALESCE(b.surface_habitable, b.surface_totale, 0) * 3.03, 2),
    a.date_modification = NOW()
WHERE a.honoraires_etat_des_lieux IS NOT NULL
  AND COALESCE(b.surface_habitable, b.surface_totale, 0) > 0
  AND ABS(a.honoraires_etat_des_lieux - ROUND(COALESCE(b.surface_habitable, b.surface_totale, 0) * 3.00, 2)) < 0.50;

-- (3) Resync cache biens.zone_tendue depuis le CP (COALESCE immeuble puis bien)
UPDATE `biens` b
LEFT JOIN `immeubles` i ON i.id = b.id_immeuble
LEFT JOIN `base_zones_tendues` z ON z.code_postal = COALESCE(NULLIF(i.code_postal, ''), b.code_postal)
SET b.zone_tendue = COALESCE(z.zone_tendue, 'non_tendue')
WHERE COALESCE(b.zone_tendue, '') <> COALESCE(z.zone_tendue, 'non_tendue');

SQL,
];
