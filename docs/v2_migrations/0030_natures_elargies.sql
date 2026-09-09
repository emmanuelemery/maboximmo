-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0030 — LE VOCABULAIRE DES NATURES S'ÉLARGIT À CE QU'ON DEVRA SAISIR
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Emmanuel, 09/09/2026 : « il faut élargir les types de bien ! ».
--
--  ─────────────────────────────────────────────────────────────────────────
--  UN CHANGEMENT DE RÈGLE, ET IL FAUT LE DIRE
--
--  La migration 0020 posait : « on n'invente pas `cave` ni `parking` au cas
--  où : un vocabulaire s'étend par INSERT le jour où un document le prouve, et
--  rester vide n'a jamais coûté à personne ».
--
--  Cette règle était juste POUR UN IMPORT, et fausse pour une base vivante.
--  Un vocabulaire calibré sur ce que 948 comptes rendus ont imprimé ne permet
--  à personne de SAISIR un bien : le gestionnaire qui crée une cave, un
--  parking ou un atelier n'a rien à choisir, et prendra « Autre ». On aura
--  alors perdu l'information à l'entrée, ce qui est pire que d'avoir une
--  ligne de référentiel inutilisée.
--
--  `UNE LISTE FERMÉE DOIT COUVRIR CE QU'ON AURA À DÉSIGNER, PAS SEULEMENT CE
--   QU'ON A DÉJÀ LU.` Le coût d'une nature en trop est une ligne ; le coût
--  d'une nature manquante est une saisie fausse, et elle ne se rattrape pas.
--
--  ─────────────────────────────────────────────────────────────────────────
--  CE QUE LE CORPUS PROUVE DÉJÀ — et qui était mal classé
--
--      Parking · Parking couvert · Parking extérieur   13 lots, rangés en GARAGE
--      Cave                                             1 lot,  rangé en GARAGE
--      Réserve                                          1 lot,  rangé en LOCAL COMMERCIAL
--
--  Une place de stationnement n'est pas un box fermé, et une cave encore
--  moins : la taxe foncière ne les évalue pas de la même façon. Ces trois
--  natures ne sont donc pas ajoutées « au cas où » — elles réparent un
--  classement.
--
--  ⚠️ CE QU'ON NE TOUCHE PAS, ET POURQUOI :
--     · les 5 « Local commercial BUREAUX » — on ne sait pas si ce sont des
--       bureaux ou un local qui en comporte ; deviner rangerait une
--       supposition là où la TF lira un fait ;
--     · les 6 « Box » et « Garage BOX » — un box EST un garage fermé ;
--     · les 15 « Villa » — une villa est une maison ;
--     · les 2 lots dont la désignation est « VENDU » — c'est un statut, pas
--       une désignation, et leur nature reste celle que le document a dite.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO biens_natures (code, libelle, usage_code, ordre) VALUES
  -- ── HABITATION ────────────────────────────────────────────────────────
  ('immeuble',           'Immeuble entier',             'habitation',    25),
  ('chambre',            'Chambre',                     'habitation',    35),
  -- ── PROFESSIONNEL ─────────────────────────────────────────────────────
  ('atelier',            'Atelier',                     'professionnel', 46),
  ('local_professionnel','Local professionnel',         'professionnel', 47),
  -- ── ANNEXES ───────────────────────────────────────────────────────────
  -- Une PLACE n est pas un BOX : le garage est clos, le parking ne l est pas.
  ('parking',            'Place de stationnement',      'annexe',        62),
  ('cave',               'Cave',                        'annexe',        64),
  ('grenier',            'Grenier ou comble',           'annexe',        66),
  ('jardin',             'Jardin',                      'annexe',        68),
  -- Le fourre-tout des annexes NOMMÉES : réserve, remise, local technique.
  ('dependance',         'Dépendance',                  'annexe',        70),
  -- ── AUTRES ────────────────────────────────────────────────────────────
  ('terrain_agricole',   'Terrain agricole',            'autre',         75),
  -- Un relais télécom sur un toit est un objet loué, avec son bail.
  ('antenne',            'Antenne ou relais',           'autre',         95);

-- ═══════════════════════════════════════════════════════════════════════════
--  ON REPREND LES TROIS CLASSEMENTS QUE LE DOCUMENT CONTREDIT
-- ═══════════════════════════════════════════════════════════════════════════
--
--  ⚠️ EN TÊTE DE LIBELLÉ, PAS N'IMPORTE OÙ. « Garage BOX » contient BOX et
--     reste un garage ; « Parking Parking couvert » COMMENCE par Parking.
--     Un `LIKE '%parking%'` aurait aussi attrapé « Appartement avec parking »,
--     et rangé un logement en annexe.
UPDATE biens SET nature_code = 'parking'
 WHERE nature_code = 'garage' AND designation REGEXP '^[Pp]arking';

UPDATE biens SET nature_code = 'cave'
 WHERE nature_code = 'garage' AND designation REGEXP '^[Cc]ave';

UPDATE biens SET nature_code = 'dependance'
 WHERE nature_code = 'local_commercial' AND designation REGEXP '^[Rr]éserve|^[Rr]eserve';
