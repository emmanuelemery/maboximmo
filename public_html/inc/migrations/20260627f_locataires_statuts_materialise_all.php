<?php
/**
 * Migration : matérialiser `locataires_statuts` pour TOUS les propriétaires.
 *
 * Le dashboard bailleur (bailleur_dashboard.php) calcule ses KPI via un JOIN
 * OBLIGATOIRE sur `locataires_statuts`. Or cette table n'avait été seedée que
 * pour le cercle SIR/SABY (12 propriétaires). Tous les autres bailleurs ont donc
 * leurs CRG/baux/loyers en base, mais 0 ligne de statut → le JOIN les vide → 0
 * partout sur leur tableau de bord.
 *
 * Cette migration génère une ligne de statut par triplet
 * (id_proprietaire, id_bien, locataire_nom) présent dans les CRG, à partir de la
 * DERNIÈRE situation trimestrielle (parse_statut='ok') :
 *   - loyer_appele > 0          → 'actif'          (locataire présent)
 *   - loyer_appele = 0 & impayé → 'debiteur_actif' (parti débiteur)
 *   - sinon                     → 'actif'
 * montant_creance = total_impaye de la dernière situation ; archive=0.
 *
 * Sécurité : `NOT EXISTS` → on n'ajoute QUE les triplets manquants. Les lignes
 * déjà présentes (SIR, statuts ajustés manuellement, 'irrecoverable', archive…)
 * ne sont JAMAIS écrasées. Rejouable sans effet (idempotent).
 */

return [
    'id'          => '20260627f_locataires_statuts_materialise_all',
    'title'       => 'Matérialiser locataires_statuts pour tous les bailleurs (depuis CRG)',
    'description' => "Génère une ligne locataires_statuts par triplet (proprio, bien, locataire) issu de la dernière situation CRG, pour débloquer les KPI du dashboard bailleur de TOUS les propriétaires (pas seulement SIR). NOT EXISTS = n'écrase aucune ligne existante. Idempotent.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
INSERT INTO `locataires_statuts`
    (`locataire_nom`, `id_bien`, `id_proprietaire`, `statut`, `montant_creance`, `archive`)
SELECT
    x.locataire_nom,
    x.id_bien,
    x.id_proprietaire,
    CASE
        WHEN x.loyer_appele > 0 THEN 'actif'
        WHEN x.total_impaye > 0 THEN 'debiteur_actif'
        ELSE 'actif'
    END AS statut,
    COALESCE(x.total_impaye, 0) AS montant_creance,
    0 AS archive
FROM (
    SELECT
        ct.id_proprietaire,
        c.id_bien,
        c.locataire_nom,
        c.loyer_appele,
        c.total_impaye,
        ROW_NUMBER() OVER (
            PARTITION BY ct.id_proprietaire, c.id_bien, c.locataire_nom
            ORDER BY ct.annee DESC, ct.trimestre DESC
        ) AS rn
    FROM `crg_situations_locataires` c
    JOIN `crg_trimestres` ct ON ct.id = c.id_crg
    WHERE ct.parse_statut = 'ok'
      AND c.locataire_nom IS NOT NULL
      AND c.locataire_nom <> ''
) x
WHERE x.rn = 1
  AND NOT EXISTS (
        SELECT 1 FROM `locataires_statuts` ls
        WHERE ls.id_proprietaire = x.id_proprietaire
          AND ls.id_bien <=> x.id_bien
          AND ls.locataire_nom = x.locataire_nom
  );
SQL
];
