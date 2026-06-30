<?php
/**
 * Migration : clôturer les baux dont le locataire est PARTI (d'après le CRG).
 *
 * Les baux ont été matérialisés depuis les CRG (AUTO-G-CRG) TOUS en statut
 * 'actif', sans jamais être clôturés quand le locataire quittait le logement.
 * Résultat : `bien_baux_liste.php` (qui affiche `bien_baux.statut`) montre comme
 * « actif » des baux dont le locataire est en réalité parti — incohérent avec la
 * vue patrimoine / le dashboard bailleur (qui dérivent « parti » du CRG).
 *
 * Signal de « parti » = MÊME logique que le dashboard (bailleur_dashboard.php) :
 *   loyer appelé = 0 sur le DERNIER trimestre CRG dispo pour ce (bien, locataire).
 * (On n'utilise pas `locataire_parti` : ce flag n'est pas fiablement rempli.)
 *
 * Clé de jointure bail ↔ CRG = (id_bien, locataire_nom) — exactement la clé qui a
 * servi à créer les baux. Les baux sans correspondance CRG (saisis manuellement,
 * locataire via id_tiers sans locataire_nom) ne sont JAMAIS touchés.
 *
 * Action : statut 'actif' → 'resilie' pour ces baux. Idempotent (ne reprend pas
 * un bail déjà 'resilie'). Testé local : 67 baux concernés.
 */

return [
    'id'          => '20260627g_baux_resilier_locataires_partis',
    'title'       => 'Clôturer (resilie) les baux dont le locataire est parti selon le CRG',
    'description' => "Passe bien_baux.statut de 'actif' à 'resilie' pour les baux dont le locataire a un loyer appelé = 0 au dernier trimestre CRG (= parti), pour aligner bien_baux_liste sur la vue patrimoine / dashboard bailleur. Jointure (id_bien, locataire_nom). Idempotent.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
UPDATE `bien_baux` bb
JOIN (
    SELECT s.id_bien, s.locataire_nom,
           CAST(SUBSTRING_INDEX(
               GROUP_CONCAT(s.loyer_appele ORDER BY t.annee DESC, t.trimestre DESC),
               ',', 1
           ) AS DECIMAL(10,2)) AS dernier_loyer_appele
    FROM `crg_situations_locataires` s
    JOIN `crg_trimestres` t ON t.id = s.id_crg AND t.parse_statut = 'ok'
    WHERE s.locataire_nom IS NOT NULL AND s.locataire_nom <> ''
    GROUP BY s.id_bien, s.locataire_nom
) last ON last.id_bien = bb.id_bien AND last.locataire_nom = bb.locataire_nom
SET bb.statut = 'resilie',
    bb.updated_at = NOW()
WHERE bb.statut = 'actif'
  AND bb.locataire_nom IS NOT NULL
  AND bb.locataire_nom <> ''
  AND last.dernier_loyer_appele = 0;
SQL
];
