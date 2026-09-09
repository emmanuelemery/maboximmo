-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0022 — OÙ ÉCRIT-ON À UN TIERS : une VUE, pas une colonne recopiée
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Emmanuel, 09/09/2026 : « les locataires ont une adresse : le tiers rôle
--  locataire prend l'adresse de l'immeuble concerné ».
--
--  Le CHEMIN est juste, et il fonctionne :
--      tiers → occupation_occupants → occupations → biens → immeubles
--  1 082 locataires sur 1 262 (86 %) y atteignent une adresse complète.
--
--  ⚠️ MAIS ON NE LA RECOPIE PAS DANS `tiers.adr_*`, ET C'EST MESURÉ.
--
--    · 356 locataires n'ont AUCUNE occupation en cours. L'adresse du lot est
--      celle qu'ils ont QUITTÉE — et ce sont précisément ceux pour qui on
--      cherche une adresse, quand il reste une dette. Écrire l'ancienne dans
--      `tiers` la ferait passer pour l'actuelle.
--    · 273 louent un local commercial, un bureau, un garage ou un panneau.
--      On n'habite pas un garage : l'adresse postale d'un commerçant est son
--      siège, pas la vitrine.
--    · 58 occupent plusieurs lots, dont 23 dans des immeubles DIFFÉRENTS.
--      `tiers.adr_*` ne porte qu'une adresse : il faudrait en choisir une.
--    · 106 immeubles n'ont pas encore de rue. Le rapprochement avec les taxes
--      foncières va les compléter — et toute copie faite aujourd'hui serait
--      alors périmée sans que rien ne le signale.
--
--  `UNE DONNÉE QUI SE DÉDUIT NE SE STOCKE PAS : ELLE SE LIT.` Une copie est
--  juste le jour où on la fait, et fausse le jour où la source change.
--
--  D'où une VUE : elle ne stocke rien, elle ne peut pas diverger, et elle
--  répond à la question telle qu'on la pose vraiment — « où écrit-on à cette
--  personne ? ».
--
--  ⚠️ ET ELLE DIT D'OÙ VIENT L'ADRESSE. `source` vaut TIERS quand la personne
--     porte la sienne (les propriétaires, lue sur le CRG), LOT_OCCUPE quand
--     elle est déduite du logement occupé, et NULL quand on ne sait pas —
--     ce qui vaut mieux qu'une adresse quittée présentée comme actuelle.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE VIEW v_tiers_adresse_courrier AS
SELECT
    t.id                                                   AS id_tiers,
    t.nom_affichage,
    CASE
        WHEN t.adr_ligne_voie IS NOT NULL THEN 'TIERS'
        WHEN i.adresse_1      IS NOT NULL THEN 'LOT_OCCUPE'
    END                                                    AS source,
    COALESCE(t.adr_ligne_voie,  i.adresse_1)               AS ligne_voie,
    COALESCE(t.adr_code_postal, i.code_postal)             AS code_postal,
    COALESCE(t.adr_commune,     i.ville)                   AS commune,
    CASE WHEN t.adr_ligne_voie IS NOT NULL THEN t.adr_pays
         WHEN i.adresse_1      IS NOT NULL THEN 'FRANCE' END AS pays,
    o.id                                                   AS id_occupation,
    b.id                                                   AS id_bien,
    i.id                                                   AS id_immeuble
FROM tiers t
-- L'occupation EN COURS la plus récente, et une seule : un tiers qui occupe
-- deux lots ne doit pas apparaître deux fois dans une vue d'adressage.
LEFT JOIN occupation_occupants oo
       ON oo.id = (
            SELECT oo2.id
              FROM occupation_occupants oo2
              JOIN occupations o2 ON o2.id = oo2.id_occupation
             WHERE oo2.id_tiers = t.id
               AND o2.date_fin IS NULL          -- ⚠️ jamais une occupation close
             ORDER BY o2.date_debut DESC, o2.id DESC
             LIMIT 1)
LEFT JOIN occupations o ON o.id = oo.id_occupation
LEFT JOIN biens       b ON b.id = o.id_bien
LEFT JOIN immeubles   i ON i.id = b.id_immeuble;
