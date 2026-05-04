-- =====================================================================
-- Migration 2026-05-04 : ajout users.matricule_paie
-- Objet : matricule du logiciel de paie comptable, sert de clé fiable
--         pour le matching PDF bulletin -> user lors du workflow
--         comptable (rh_salaires.php, comparaison projet/bulletins).
-- Source des matricules : bulletins 04/2026 + 03/2026 simplifiés
--                         fournis par In Extenso.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN matricule_paie VARCHAR(20) NULL DEFAULT NULL
  COMMENT 'Matricule logiciel de paie comptable (cle de matching bulletins PDF)'
  AFTER id_legacy;

ALTER TABLE users
  ADD INDEX idx_matricule_paie (id_societe, matricule_paie);

-- ---------------------------------------------------------------------
-- Société #1 — Régie EMERY (3 agences : Lyon / Vienne / Mions / Chaponost)
-- ---------------------------------------------------------------------
UPDATE users SET matricule_paie = '20'      WHERE id = 6;   -- Benoit BRIAND (Lyon)
UPDATE users SET matricule_paie = '35'      WHERE id = 10;  -- Hervé GOUBE (Vienne)
UPDATE users SET matricule_paie = '37'      WHERE id = 15;  -- Céline GOUBE (Vienne)
UPDATE users SET matricule_paie = '36'      WHERE id = 9;   -- Audrey GILLES (Mions)
UPDATE users SET matricule_paie = '14'      WHERE id = 26;  -- Bernard SUZAT (Lyon)
UPDATE users SET matricule_paie = '29'      WHERE id = 11;  -- Annaelle FRANCISCO (Lyon)
UPDATE users SET matricule_paie = '2'       WHERE id = 13;  -- Alexandra RUBIO (Chaponost)
UPDATE users SET matricule_paie = '7000003' WHERE id = 40;  -- Nawel SENOUCI (Chaponost)
UPDATE users SET matricule_paie = '7000004' WHERE id = 21;  -- Géraldine DE GASPERIS (Chaponost)
UPDATE users SET matricule_paie = '7000007' WHERE id = 22;  -- Alice MARCEL (Chaponost)
UPDATE users SET matricule_paie = '7000008' WHERE id = 14;  -- Jennifer PAYET (Mions)
UPDATE users SET matricule_paie = '7000009' WHERE id = 37;  -- Anais LOPES (Lyon)
UPDATE users SET matricule_paie = '7000010' WHERE id = 35;  -- Alya HAFIANI SIMON (Vienne)

-- ---------------------------------------------------------------------
-- Société #2 — EMERY IMMOBILIER (Riom / Chamalières)
-- ---------------------------------------------------------------------
UPDATE users SET matricule_paie = '30001'   WHERE id = 27;  -- Margaux CALCHERA (Chamalières)
UPDATE users SET matricule_paie = '30002'   WHERE id = 30;  -- Franck FARINA (Chamalières)
UPDATE users SET matricule_paie = '30003'   WHERE id = 16;  -- Mélissa ANTUNES (Riom)
UPDATE users SET matricule_paie = '30005'   WHERE id = 29;  -- Ethan COMPTE (Chamalières)
UPDATE users SET matricule_paie = '30006'   WHERE id = 28;  -- Amélie LAURENT (Riom)

-- ---------------------------------------------------------------------
-- Société #3 — LOCA IMMO Holding
-- ---------------------------------------------------------------------
UPDATE users SET matricule_paie = '18'      WHERE id = 12;  -- Emmelyne EMERY
UPDATE users SET matricule_paie = '23'      WHERE id = 33;  -- Eva Lola EMERY DUVAREILLE
UPDATE users SET matricule_paie = '7000005' WHERE id = 8;   -- Emmanuel EMERY (Gérant Loca-Immo)

-- NB : user id=66 (Emmanuel EMERY société #1) volontairement laissé NULL
--      (doublon ; le matricule paie 7000005 est porté par id=8 sous Loca-Immo).
