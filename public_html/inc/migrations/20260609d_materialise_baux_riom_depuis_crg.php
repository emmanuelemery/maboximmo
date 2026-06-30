<?php
/**
 * Migration : matérialise les bien_baux RIOM/CHAMALIERES depuis le CRG (vérité).
 *
 * Côté LYON, les bien_baux existent (132) ; côté RIOM ils n'ont jamais été créés
 * (309 biens, 0 bail) alors que le CRG connaît les locataires (crg_situations_locataires,
 * 364 situations). On crée donc un SQUELETTE de bail par (bien, locataire) du dernier
 * état CRG, à enrichir ensuite (dates/charges/tiers) via les baux OneDrive.
 *
 * Mapping CRG → bien_baux :
 *   id_bien, id_proprietaire, locataire_nom = depuis crg_situations_locataires
 *   loyer_mensuel_hc = loyer_appelé (trimestriel) / 3
 *   bail_nature      = commercial si type_bien local/commerce/bureau, sinon habitation
 *   statut           = actif si au moins une situation non-partie, sinon termine
 *
 * Garde-fou : UNIQUEMENT les biens RIOM/CHAMALIERES (agences 63-1/63-2) SANS aucun
 * bien_baux existant → idempotent (re-jouable sans doublon).
 */
return [
    'id'          => '20260609d_materialise_baux_riom_depuis_crg',
    'title'       => 'Matérialise les baux RIOM depuis le CRG (squelettes bien_baux)',
    'description' => "Crée un bien_baux par (bien, locataire) depuis crg_situations_locataires pour les biens RIOM/CHAMALIERES sans bail. Loyer = appelé/3, statut actif/termine. À enrichir via OneDrive.",
    'created_at'  => '2026-06-09',
    'sql' => <<<'SQL'
INSERT INTO `bien_baux`
    (id_bien, id_proprietaire, locataire_nom, loyer_mensuel_hc, bail_nature, statut, commentaire_admin, created_at, updated_at)
SELECT
    s.id_bien,
    b.id_proprietaire,
    s.locataire_nom,
    ROUND(MAX(s.loyer_appele) / 3, 2)                                                           AS loyer_mensuel_hc,
    CASE WHEN LOWER(MAX(s.type_bien)) REGEXP 'local|commerc|bureau|garage' THEN 'commercial'
         ELSE 'habitation' END                                                                  AS bail_nature,
    CASE WHEN SUM(CASE WHEN s.locataire_parti = 0 THEN 1 ELSE 0 END) > 0 THEN 'actif'
         ELSE 'termine' END                                                                     AS statut,
    'Squelette créé depuis CRG (RIOM) le 2026-06-09 — dates/charges à enrichir via bail OneDrive' AS commentaire_admin,
    NOW(), NOW()
FROM crg_trimestres t
JOIN crg_situations_locataires s ON s.id_crg = t.id
JOIN biens b        ON b.id = s.id_bien
JOIN proprietaires p ON p.id = b.id_proprietaire
JOIN agences a       ON a.id = p.id_agence
WHERE a.code_agence IN ('63-1','63-2')
  AND s.locataire_nom IS NOT NULL AND s.locataire_nom <> ''
  AND NOT EXISTS (SELECT 1 FROM bien_baux bb WHERE bb.id_bien = s.id_bien)
GROUP BY s.id_bien, b.id_proprietaire, s.locataire_nom;
SQL,
];
