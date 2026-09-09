-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0029 — `entrepot` entre au vocabulaire des natures de bien
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Emmanuel, 09/09/2026, en relisant les deux lots de SCI HIMMALAYA :
--  « il faut ajouter entrepot dans les tables ».
--
--  ⚠️ C'EST EXACTEMENT COMME ÇA QUE CE VOCABULAIRE EST FAIT POUR GRANDIR. La
--     migration 0020 posait la règle : « on n'invente pas `cave` ni `parking`
--     au cas où : un vocabulaire s'étend par INSERT le jour où un document le
--     prouve ». Le document l'a prouvé — 13 lots portent « Entrepot » ou
--     « Local commercial entrepôt » en toutes lettres.
--
--  ⚠️ ET LA NATURE SE LIT SUR LE LIBELLÉ QUAND IL EST PLUS PRÉCIS QUE LE TYPE.
--     Le CRG classe ces lots en LOCAL COMMERCIAL — ce qui n'est pas faux, mais
--     c'est la maille au-dessus. Le libellé, lui, dit « Entrepot ». On ne
--     choisit jamais ce qu'on peut lire : entre deux lectures vraies, on garde
--     la plus fine.
--
--  ⚠️ POURQUOI ÇA COMPTE POUR LES TAXES FONCIÈRES : un entrepôt n'est pas
--     évalué comme une boutique. `usage_code` reste `professionnel` — c'est la
--     famille — mais la nature devient distincte, et c'est elle que le
--     rapprochement lira.
--
--  ⚠️ NUMÉROTÉE 0029 ET NON 0028 : l'autre session a réservé la 0028 pour
--     l'alignement des vocabulaires d'`objet_type`. Une collision de numéro
--     ferait appliquer deux migrations différentes sous le même nom.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO biens_natures (code, libelle, usage_code, ordre) VALUES
  ('entrepot', 'Entrepôt', 'professionnel', 45);

-- Les 13 lots que le document nomme ainsi. `LIKE` sur le libellé LU, jamais
-- sur une supposition : un lot dont le libellé ne dit pas « entrepot » reste
-- ce qu'il est.
UPDATE biens
   SET nature_code = 'entrepot'
 WHERE nature_code = 'local_commercial'
   AND (designation LIKE '%ntrepot%' OR designation LIKE '%ntrepôt%');
