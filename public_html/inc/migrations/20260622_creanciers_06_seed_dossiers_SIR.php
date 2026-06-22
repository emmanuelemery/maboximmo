<?php
/**
 * Migration (SEED) : Dossiers créanciers GROUPE SIR — état avocat Pouderoux 06/05/2026.
 *
 * Crée 31 dossiers créanciers (saisies immobilières, contentieux bancaires, contentieux
 * locatifs) à partir du courrier de Maître Pouderoux, avec : débiteur + créancier liés,
 * commentaire avocat, dette (item), agenda (audiences/MARD/butoirs) et 4 contacts communs
 * (Thomas Saby gérant, Eric Pouderoux avocat, Philippe Guillet expert-comptable, Emmanuel
 * Emery régie). Idempotent (rejouable). Résolution des entités PAR NOM.
 *
 * ⚠️ Prérequis : migrations creanciers_01 à 05 appliquées (tables créées).
 * ⚠️ Tenant : @soc=3 / @age=7 / @usr=8 — ADAPTER si la Régie a d'autres ids en prod.
 *
 * ── ROLLBACK (-- DOWN) ──
 *   DELETE FROM creancier_dossier WHERE code LIKE 'SIR-%' OR code IN
 *     ('SIRES-CE','EVEREST-CE-1','EVEREST-CE-2','RHODAN-CA','HIMALAYA-CE','HIMALAYA-PALATINE-CAUDAN',
 *      'SABY-LCL','SMH-MARTINSFERREIRA');  -- cascade supprime liens/items/échéances
 *   -- (les tiers créés ne sont pas supprimés automatiquement)
 */

return [
    'id'          => '20260622_creanciers_06_seed_dossiers_SIR',
    'title'       => 'SEED — 31 dossiers créanciers GROUPE SIR (avocat 06/05/2026) + contacts',
    'description' => "Crée les dossiers créanciers du Groupe SIR depuis le courrier avocat : débiteur+créancier, commentaire, dette, agenda, 4 contacts communs. Idempotent. Prérequis : creanciers_01→05. Vérifier @soc/@age/@usr.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════
-- SEED — Dossiers créanciers GROUPE SIR (état avocat Pouderoux 06/05/2026)
-- Idempotent (rejouable). Résolution des entités PAR NOM (ids dev≠prod).
-- À exécuter via phpMyAdmin (UTF-8) OU `mysql --default-character-set=utf8mb4`.
-- Tenant : société 3 / agence 7 (Régie Emery), créateur user 8 (à adapter si besoin).
-- ════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;
SET @soc := 3; SET @age := 7; SET @usr := 8;

-- ── 1. DÉBITEURS manquants (créés si absents) ───────────────────────
INSERT INTO tiers (id_societe,id_agence,type_tiers,raison_sociale,nom_affichage,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_morale','Rhodanienne du bâtiment','Rhodanienne du bâtiment',1,@usr,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM tiers WHERE raison_sociale='Rhodanienne du bâtiment');
INSERT INTO tiers (id_societe,id_agence,type_tiers,raison_sociale,nom_affichage,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_morale','SMH','SMH',1,@usr,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM tiers WHERE raison_sociale='SMH');

-- ── 2. CRÉANCIERS (créés si absents) ────────────────────────────────
INSERT INTO tiers (id_societe,id_agence,type_tiers,raison_sociale,nom_affichage,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_morale',v,v,1,@usr,NOW(),NOW() FROM (
  SELECT 'Crédit Agricole' v UNION ALL SELECT 'Hewlett-Packard' UNION ALL
  SELECT 'Banque Populaire Auvergne Rhône-Alpes' UNION ALL SELECT 'Société lyonnaise de banque' UNION ALL
  SELECT 'Société Générale' UNION ALL SELECT 'Caisse d''épargne Rhône-Alpes' UNION ALL
  SELECT 'Banque Palatine' UNION ALL SELECT 'Trésor Public' UNION ALL SELECT 'HSBC' UNION ALL
  SELECT 'Star Lease' UNION ALL SELECT 'Impact Partners' UNION ALL SELECT 'Absus' UNION ALL
  SELECT 'Crédit Lyonnais' UNION ALL SELECT 'BPI' UNION ALL SELECT 'CB Finances' UNION ALL
  SELECT 'Villa Calade' UNION ALL SELECT 'Park Avenue' UNION ALL SELECT 'GI MAT' UNION ALL
  SELECT 'SCI Benel' UNION ALL SELECT 'Martins Ferreira'
) c
WHERE NOT EXISTS (SELECT 1 FROM tiers t WHERE t.raison_sociale = c.v);

-- ── 3. Rôle 'creancier' sur tous les créanciers ─────────────────────
INSERT IGNORE INTO tiers_roles (id_tiers,role_code,objet_type,actif)
SELECT id,'creancier',NULL,1 FROM tiers WHERE raison_sociale IN (
  'Crédit Agricole','Hewlett-Packard','Banque Populaire Auvergne Rhône-Alpes','Société lyonnaise de banque',
  'Société Générale','Caisse d''épargne Rhône-Alpes','Banque Palatine','Trésor Public','HSBC','Star Lease',
  'Impact Partners','Absus','Crédit Lyonnais','BPI','CB Finances','Villa Calade','Park Avenue','GI MAT',
  'SCI Benel','Martins Ferreira');

-- ── Résolution des ids (par nom) ────────────────────────────────────
SET @d_sir      := (SELECT id FROM tiers WHERE raison_sociale='SARL GROUPE SIR' LIMIT 1);
SET @d_everest  := (SELECT id FROM tiers WHERE raison_sociale='SCI EVEREST' LIMIT 1);
SET @d_himalaya := (SELECT id FROM tiers WHERE raison_sociale='SCI HIMMALAYA' LIMIT 1);
SET @d_sires    := (SELECT id FROM tiers WHERE raison_sociale='SCI SIRES' LIMIT 1);
SET @d_saby     := (SELECT id FROM tiers WHERE nom_affichage='SABY Yves' OR (nom='SABY' AND prenom='Yves') ORDER BY id LIMIT 1);
SET @d_rhodan   := (SELECT id FROM tiers WHERE raison_sociale='Rhodanienne du bâtiment' LIMIT 1);
SET @d_smh      := (SELECT id FROM tiers WHERE raison_sociale='SMH' LIMIT 1);

SET @c_ca       := (SELECT id FROM tiers WHERE raison_sociale='Crédit Agricole' LIMIT 1);
SET @c_hp       := (SELECT id FROM tiers WHERE raison_sociale='Hewlett-Packard' LIMIT 1);
SET @c_bp       := (SELECT id FROM tiers WHERE raison_sociale='Banque Populaire Auvergne Rhône-Alpes' LIMIT 1);
SET @c_lb       := (SELECT id FROM tiers WHERE raison_sociale='Société lyonnaise de banque' LIMIT 1);
SET @c_sg       := (SELECT id FROM tiers WHERE raison_sociale='Société Générale' LIMIT 1);
SET @c_ce       := (SELECT id FROM tiers WHERE raison_sociale='Caisse d''épargne Rhône-Alpes' LIMIT 1);
SET @c_palatine := (SELECT id FROM tiers WHERE raison_sociale='Banque Palatine' LIMIT 1);
SET @c_tp       := (SELECT id FROM tiers WHERE raison_sociale='Trésor Public' LIMIT 1);
SET @c_hsbc     := (SELECT id FROM tiers WHERE raison_sociale='HSBC' LIMIT 1);
SET @c_star     := (SELECT id FROM tiers WHERE raison_sociale='Star Lease' LIMIT 1);
SET @c_impact   := (SELECT id FROM tiers WHERE raison_sociale='Impact Partners' LIMIT 1);
SET @c_absus    := (SELECT id FROM tiers WHERE raison_sociale='Absus' LIMIT 1);
SET @c_lcl      := (SELECT id FROM tiers WHERE raison_sociale='Crédit Lyonnais' LIMIT 1);
SET @c_bpi      := (SELECT id FROM tiers WHERE raison_sociale='BPI' LIMIT 1);
SET @c_cb       := (SELECT id FROM tiers WHERE raison_sociale='CB Finances' LIMIT 1);
SET @c_villa    := (SELECT id FROM tiers WHERE raison_sociale='Villa Calade' LIMIT 1);
SET @c_park     := (SELECT id FROM tiers WHERE raison_sociale='Park Avenue' LIMIT 1);
SET @c_gimat    := (SELECT id FROM tiers WHERE raison_sociale='GI MAT' LIMIT 1);
SET @c_benel    := (SELECT id FROM tiers WHERE raison_sociale='SCI Benel' LIMIT 1);
SET @c_mf       := (SELECT id FROM tiers WHERE raison_sociale='Martins Ferreira' LIMIT 1);

-- ════════════════════════════════════════════════════════════════════
-- MACRO (répétée) par dossier :
--   1 INSERT creancier_dossier (si code absent) + SET @did
--   liens débiteur + créancier · item DETTE · échéances agenda
-- ════════════════════════════════════════════════════════════════════

-- 1) SIR c/ Crédit Agricole — saisie immo 2025 JO1268
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CA-2025JO1268','Groupe SIR c/ Crédit Agricole (2025 JO1268)','surveillance','rouge',
 'Saisie immobilière. Audience procédure 03/06/2026 avec possibilité de fixation à plaider.',
 '[avocat 06/05/2026] J''ai conclu mais devrai reconclure après réception des bilans + attestation Ph. Guillet pour solliciter un délai de paiement.',
 '2025 JO1268',@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CA-2025JO1268');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CA-2025JO1268');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_ca,'creancier_principal',@usr);
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience de procédure (fixation à plaider possible)','2026-06-03','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-03');

-- 2) SIR c/ Crédit Agricole — saisie immo 2026 JO0298
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CA-2026JO0298','Groupe SIR c/ Crédit Agricole (2026 JO0298)','surveillance','rouge',
 'Saisie immobilière. Audience procédure 20/05/2026.',
 '[avocat 06/05/2026] Conclusions à déposer pour bloquer le délai sinon risque de fixation à plaider. En attente bilans + attestation Ph. Guillet.',
 '2026 JO0298',@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CA-2026JO0298');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CA-2026JO0298');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_ca,'creancier_principal',@usr);
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience de procédure','2026-05-20','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-20');

-- 3) SIR c/ Hewlett-Packard (CA Paris)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-HP','Groupe SIR c/ Hewlett-Packard (CA Paris)','surveillance','rouge',
 'Location imprimante (Centre Etiq). Jugement TAE Paris 19/11/2025 : 22 167,66 € loyers impayés + 258 622,70 € loyers à échoir. Appel en cours. Risque de radiation (adversaire a saisi le magistrat).',
 '[avocat 06/05/2026] Affaire appelée 01/06/2026 ; conclusions impératives avant fin mai avec éléments Ph. Guillet pour démontrer l''impossibilité de régler et éviter la radiation.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-HP');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-HP');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_hp,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,description,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Loyers impayés + à échoir (jugement 19/11/2025)','22 167,66 € + 258 622,70 €',280790.36,@c_hp,'ouvert',8,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Appel appelé (CA Paris) — radiation','2026-06-01','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-01');

-- 4) SIR c/ Banque Populaire ARA
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-BP','Groupe SIR c/ Banque Populaire Auvergne Rhône-Alpes','surveillance','orange',
 'TAE Lyon. 3 cautions (prêts Centre Est Peinture Distribution) : 700 000 € + 264 056,86 € + 353 470,67 €. Hypothèques sur 170 Rue Challemel Lacour Lyon et 4 Rue Camille Roy / 77-85 Av Berthelot Lyon.',
 '[avocat 07/04/2026] Accord négocié : 20 000 €/mois x24 puis 25 000 €/mois x23 + solde 48e échéance (banque exige intérêts taux contractuel + conversion hypothèques provisoires en définitives). MARD 19/05/2026 14h (présence Thomas Saby indispensable), audience 10/06/2026.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-BP');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-BP');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_bp,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,description,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','3 cautionnements (prêts CEPD)','700 000 + 264 056,86 + 353 470,67',1317527.53,@c_bp,'ouvert',6,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','MARD (conciliation) — présence Thomas Saby','2026-05-19','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-19');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience de procédure','2026-06-10','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-10');

-- 5) SIR c/ Société lyonnaise de banque (→ Absus)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-LB','Groupe SIR c/ Société lyonnaise de banque (→ Absus)','surveillance','orange',
 'TAE Lyon. Découvert en compte 241 056,95 € (caution Groupe SIR pour CEPD). Créance reprise par le fonds de titrisation Absus (intervention volontaire 26/02/2026).',
 '[avocat 06/05/2026] Audience de procédure 03/06/2026 pour dépôt conclusions. Tentative de contestation de la validité du cautionnement (chances minces, risque d''aveu judiciaire). Audience orientation 10/04/2026.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-LB');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-LB');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_lb,'creancier_principal',@usr),(@did,'TIERS',@c_absus,'creancier',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Découvert en compte (caution CEPD)',241056.95,@c_lb,'ouvert',6,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience de procédure (dépôt conclusions)','2026-06-03','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-03');

-- 6) SIR c/ Société Générale — dossier 1
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-SG-1','Groupe SIR c/ Société Générale (dossier 1)','surveillance','orange',
 'TAE Lyon. Condamnation solidaire Groupe SIR + Yves Saby : 217 398,58 € (intérêts 6,39 % dès 04/11/2025). Hypothèques judiciaires : 9 Bd Pinel Lyon 3 et 2 Rue de l''église Lyon 3.',
 '[avocat 06/05/2026] Audience de procédure 02/06/2026. Procédure récente (11/03/2026) ; conclusions à déposer courant mai (en attente bilan + attestation Ph. Guillet). Examiner l''excès de garantie.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-SG-1');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-SG-1');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@d_saby,'debiteur_solidaire',@usr),(@did,'TIERS',@c_sg,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Prêt (taux 2,39 % majoré 6,39 %)',217398.58,@c_sg,'ouvert',6,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience de procédure','2026-06-02','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-02');

-- 7) SIR c/ Société Générale — dossier 2
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-SG-2','Groupe SIR c/ Société Générale (dossier 2)','surveillance','orange',
 'TAE Lyon. Condamnation solidaire Groupe SIR + Yves Saby : 76 422 € (intérêts 5,85 % dès 04/11/2025). Mêmes hypothèques (9 Bd Pinel / 2 Rue de l''église Lyon 3).',
 '[avocat 06/05/2026] Audience de procédure 02/06/2026. Conclusions à déposer courant mai.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-SG-2');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-SG-2');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@d_saby,'debiteur_solidaire',@usr),(@did,'TIERS',@c_sg,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Prêt (taux 1,85 % majoré 5,85 %)',76422.00,@c_sg,'ouvert',6,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience de procédure','2026-06-02','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-02' AND libelle LIKE 'Audience%');

-- 8) SIR c/ Trésor Public (ex-HSBC, saisie immo reprise)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-TP-HSBC','Groupe SIR c/ Trésor Public (ex-HSBC, saisie immo)','actif','rouge',
 'HSBC s''est désistée de la saisie immobilière, reprise par le Trésor Public. Créance 114 557,25 € (taxes foncières 2022-2024). Risque de vente aux enchères élevé.',
 '[avocat 06/05/2026] Conclusions dès le 07/05 ; audience de plaidoiries 12/05/2026. Pas tous les éléments pour un délai : besoin de 2 mandats de vente + évaluation J. Boulez pour solliciter une vente amiable.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-TP-HSBC');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-TP-HSBC');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_tp,'creancier_principal',@usr),(@did,'TIERS',@c_hsbc,'creancier',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Taxes foncières 2022-2024',114557.25,@c_tp,'ouvert',9,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Plaidoiries (JEX Lyon) — risque enchères','2026-05-12','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-12');

-- 9) SIR c/ Caisse d'épargne — 26/00046
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CE-26-00046','Groupe SIR c/ Caisse d''épargne (26/00046)','surveillance','rouge',
 'Saisie immobilière Caisse d''épargne. Conclusions imposées au 29/06/2026, audience d''orientation JEX 01/09/2026.',
 '[avocat 06/05/2026] Première audience 28/04/2026.',
 '26/00046',@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CE-26-00046');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CE-26-00046');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_ce,'creancier_principal',@usr);
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'butoir','Dépôt conclusions imposé','2026-06-29','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-29');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience d''orientation JEX','2026-09-01','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-09-01');

-- 10) SIR c/ Caisse d'épargne — 26/00055
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CE-26-00055','Groupe SIR c/ Caisse d''épargne (26/00055)','surveillance','rouge',
 'Saisie immobilière Caisse d''épargne. Première audience 26/05/2026.',
 '[avocat 06/05/2026] Tenter un renvoi mais préférable d''être prêt et d''établir des conclusions avant l''audience.',
 '26/00055',@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CE-26-00055');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CE-26-00055');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_ce,'creancier_principal',@usr);
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Première audience','2026-05-26','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-26');

-- 11) Sires c/ Caisse d'épargne
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIRES-CE','SCI Sires c/ Caisse d''épargne','surveillance','rouge',
 'Saisie immobilière. Renvoi obtenu au 02/06/2026. Dette Sires ~231 000 € (cf. récap saisies). Biens : 33 Rue de Brest Lyon (2 appts) ; 51 Av du point du jour Lyon 5.',
 '[avocat 06/05/2026] Besoin impératif fin mai : bilans Sires + Groupe SIR, attestation Ph. Guillet, 2 mandats de vente, évaluation J. Boulez.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIRES-CE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIRES-CE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sires,'debiteur',@usr),(@did,'TIERS',@c_ce,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Dette Sires (saisies CE)',231000.00,@c_ce,'ouvert',8,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience (renvoi)','2026-06-02','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-02');

-- 12) SCI Everest c/ Caisse d'épargne (dossier 1, renvoi 02/06)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'EVEREST-CE-1','SCI Everest c/ Caisse d''épargne (dossier 1)','surveillance','rouge',
 'Saisie immobilière. Renvoi obtenu au 02/06/2026. Dette Everest ~162 900 € (2 prêts). Biens : 33-39 Cours de la République Villeurbanne ; Rue Maryse Bastié Lyon 8.',
 '[avocat 06/05/2026] Même impératif : délai de paiement ou autorisation de vente amiable.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='EVEREST-CE-1');
SET @did := (SELECT id FROM creancier_dossier WHERE code='EVEREST-CE-1');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_everest,'debiteur',@usr),(@did,'TIERS',@c_ce,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Dette Everest (2 prêts, saisies CE)',162900.00,@c_ce,'ouvert',8,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Audience (renvoi)','2026-06-02','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-02');

-- 13) SCI Everest c/ Caisse d'épargne (dossier 2, 09/06)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'EVEREST-CE-2','SCI Everest c/ Caisse d''épargne (dossier 2)','surveillance','rouge',
 'Saisie immobilière (2e dossier Everest). Première audience 09/06/2026.',
 '[avocat 06/05/2026] Même impératif de préparation avant l''audience (délai ou vente amiable).',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='EVEREST-CE-2');
SET @did := (SELECT id FROM creancier_dossier WHERE code='EVEREST-CE-2');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_everest,'debiteur',@usr),(@did,'TIERS',@c_ce,'creancier_principal',@usr);
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Première audience','2026-06-09','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-09');

-- 14) Rhodanienne du bâtiment c/ Crédit Agricole
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'RHODAN-CA','Rhodanienne du bâtiment c/ Crédit Agricole','surveillance','orange',
 'MARD (audience de conciliation) fixée au 12/05/2026 à 9h30.',
 '[avocat 06/05/2026] Présence de Thomas Saby indispensable au MARD.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='RHODAN-CA');
SET @did := (SELECT id FROM creancier_dossier WHERE code='RHODAN-CA');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_rhodan,'debiteur',@usr),(@did,'TIERS',@c_ca,'creancier_principal',@usr);
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','MARD (conciliation) 9h30 — présence Thomas Saby','2026-05-12','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-12');

-- 15) SCI Himalaya c/ Caisse d'épargne (TJ Grenoble)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'HIMALAYA-CE','SCI Himalaya c/ Caisse d''épargne (TJ Grenoble)','surveillance','rouge',
 'Saisie immobilière, JEX TJ Grenoble. Première audience 12/05/2026. Dette ~224 000 € (prêt acquisition La Tronche).',
 '[avocat 06/05/2026] Correspondante à Grenoble a reçu instructions pour obtenir un renvoi.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='HIMALAYA-CE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='HIMALAYA-CE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_himalaya,'debiteur',@usr),(@did,'TIERS',@c_ce,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Prêt acquisition La Tronche',224000.00,@c_ce,'ouvert',8,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Première audience (TJ Grenoble)','2026-05-12','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-12');

-- 16) SCI Himalaya c/ Banque Palatine (Caudan, accord)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'HIMALAYA-PALATINE-CAUDAN','SCI Himalaya c/ Banque Palatine (Caudan)','actif','orange',
 'Saisie immobilière d''un bien à Caudan (Bretagne). Accord trouvé : 8 750 €/mois sur compte CARPA de l''avocat poursuivant.',
 '[avocat 07/04/2026] 3 virements effectués ; restent 8 virements de 8 750 € + un 9e majoré des intérêts/frais. Exécuter sans défaillir pour solder le dossier.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='HIMALAYA-PALATINE-CAUDAN');
SET @did := (SELECT id FROM creancier_dossier WHERE code='HIMALAYA-PALATINE-CAUDAN');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_himalaya,'debiteur',@usr),(@did,'TIERS',@c_palatine,'creancier_principal',@usr);

-- 17) SIR c/ Crédit Agricole — procédure 1 (TAE, cautions)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CA-PROC1','Groupe SIR c/ Crédit Agricole (TAE — procédure 1)','surveillance','orange',
 'TAE Lyon (assignation 21/07/2025). 150 000 € contre Groupe SIR (caution prêt notarié) ; 1 040 000 € contre Yves Saby (caution crédit global trésorerie) ; 707 807,78 € in solidum Groupe SIR + Yves Saby (caution « tous engagements »).',
 '[avocat 07/04/2026] Hypothèques CA : Brignais, Irigny, Annecy Valvert (Groupe SIR) + maisons St-Germain-au-Mont-d''Or et domicile Écully (Saby). Mainlevée partielle envisagée. MARD 12/05/2026 9h30.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CA-PROC1');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CA-PROC1');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@d_saby,'debiteur_solidaire',@usr),(@did,'TIERS',@c_ca,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,description,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Cautions (150 000 SIR + 1 040 000 Saby + 707 807,78 in solidum)','TAE Lyon',1897807.78,@c_ca,'ouvert',7,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','MARD Crédit Agricole 9h30','2026-05-12','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-05-12');

-- 18) SIR c/ Crédit Agricole — procédure 2 (prêts + découvert)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CA-PROC2','Groupe SIR c/ Crédit Agricole (TAE — procédure 2)','surveillance','orange',
 'Trois prêts + découvert : 93 421,07 € + 276 410,15 € + 239 684,37 € (Groupe SIR) ; 19 895,49 € solidaire Groupe SIR + Yves Saby ; 293 966,29 € Yves Saby (prêt notarié, titre exécutoire). Intérêt légal majoré 3 %.',
 '[avocat 07/04/2026] Montant approx. total des créances CA ~2 825 000 € hors intérêts. Vérifier excès de garantie hypothécaire (plafond 130 %).',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CA-PROC2');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CA-PROC2');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@d_saby,'debiteur_solidaire',@usr),(@did,'TIERS',@c_ca,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,description,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','3 prêts + découvert + caution Saby','93 421,07 + 276 410,15 + 239 684,37 + 19 895,49 + 293 966,29',923377.37,@c_ca,'ouvert',7,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 19) SIR c/ GI MAT
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-GIMAT','Groupe SIR c/ GI MAT','surveillance','orange',
 'CA Lyon : appel radié puis caduc (>2 ans). Condamnations TC Lyon : intérêts légaux sur vente fonds 35 000 € (2015-2021) + 50 000 € préjudice moral + 1 859,52 € frais huissier.',
 '[avocat 07/04/2026] Risque théorique de mise en recouvrement / exécution forcée. Ne pas se manifester tout en gardant le risque à l''esprit (cf. dossier HP).',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-GIMAT');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-GIMAT');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_gimat,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Condamnations TC Lyon (préjudice + frais)',86859.52,@c_gimat,'ouvert',3,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 20) SIR c/ SCI Benel
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-BENEL','Groupe SIR c/ SCI Benel','surveillance','orange',
 'Bail commercial Chassieu (activité Centre Etiq). Référé TJ Lyon 28/10/2024. Appel : 74 038,59 € (loyers/charges/indemnité d''occupation jusqu''au 06/05/2025, montant réel supérieur, clés rendues 07/2025).',
 '[avocat 07/04/2026] Appel sans chance, purement dilatoire. Clôture 04/02/2026 ; date de plaidoiries à venir.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-BENEL');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-BENEL');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_benel,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Loyers/charges/indemnité occupation Chassieu',74038.59,@c_benel,'ouvert',5,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 21) SIR c/ Star Lease
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-STARLEASE','Groupe SIR c/ Star Lease','surveillance','orange',
 'Location imprimante (Centre Etiq). Protocole homologué (TAE Lyon, 07/2025) limitant la créance à 135 000 € sans intérêts (vs >200 000 € sinon).',
 '[avocat 07/04/2026] Mise en demeure reçue (délai de huitaine) : une seule mensualité versée depuis 07/2025. Risque de caducité du protocole → totalité exigible + restitution matériel. Suspension demandée au confrère.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-STARLEASE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-STARLEASE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_star,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Protocole Star Lease (caducité = totalité exigible)',135000.00,@c_star,'ouvert',6,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 22) SIR c/ Impact Partners (saisie-attribution Régie Emery) — PRIORITÉ
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-IMPACT','Groupe SIR c/ Impact Partners (2 fonds)','actif','rouge',
 'Deux FCP (financement CEPD, option de vente des titres à Groupe SIR + intérêt 10 %/an). Saisie-attribution entre les mains de Régie Emery : condamnations 44 746,20 € + 248 073,56 €. Nouvelle saisie-attribution récente. À l''origine de l''interdiction bancaire de Groupe SIR.',
 '[avocat 07/04/2026] PRIORITÉ : fonds très agressifs (gestionnaire remonté contre Yves Saby). Régie Emery a interjeté appel (condamnation sera confirmée + frais).',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-IMPACT');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-IMPACT');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_impact,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,description,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Saisie-attribution (2 fonds)','44 746,20 + 248 073,56',292819.76,@c_impact,'ouvert',10,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 23) SIR c/ Absus (Estrablin)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-ABSUS','Groupe SIR c/ Absus (Estrablin)','surveillance','orange',
 'Fonds de titrisation Absus (a racheté une créance Banque Palatine). Créance revendiquée 150 300 €. Saisie immobilière du bien Abbaye Sud à Estrablin.',
 '[avocat 07/04/2026] Offre amiable 11 550 €/mois sur 12 mois (principal) refusée ; le confrère invite à améliorer (intérêts à intégrer). Soumettre une 2e offre. Loyers consignés sur CARPA tant que la saisie n''est pas levée.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-ABSUS');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-ABSUS');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_absus,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Créance Absus (ex-Banque Palatine)',150300.00,@c_absus,'ouvert',6,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 24) Yves Saby c/ Crédit Lyonnais
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SABY-LCL','Yves Saby c/ Crédit Lyonnais','surveillance','orange',
 'TAE Lyon (assignation 19/09/2025). Caution CEPD : solde débiteur 14 443 € + 7 effets d''escompte 40 248 €.',
 '[avocat 07/04/2026] Délai de paiement sollicité ; banque s''y oppose. Examiner solde rapide (montant faible) ou délai selon décision de Thomas Saby.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SABY-LCL');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SABY-LCL');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_saby,'debiteur',@usr),(@did,'TIERS',@c_lcl,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Solde débiteur + effets d''escompte',54691.00,@c_lcl,'ouvert',4,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 25) SMH c/ Martins Ferreira
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SMH-MARTINSFERREIRA','SMH c/ Martins Ferreira','surveillance','orange',
 'Compromis de vente 12/10/2022 (172 Rue Challemel Lacour Lyon, 165 000 €) non signé par Yves Saby. Adversaires réclament clause pénale 47 422 €.',
 '[avocat 07/04/2026] Plaidoiries 23/06/2026. Risque de condamnation élevé ; demande de réduction de la clause pénale formulée.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SMH-MARTINSFERREIRA');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SMH-MARTINSFERREIRA');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_smh,'debiteur',@usr),(@did,'TIERS',@c_mf,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Clause pénale (compromis non signé)',47422.00,@c_mf,'ouvert',5,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');
INSERT INTO creancier_echeance (id_dossier,type,libelle,date_echeance,source,created_by) SELECT @did,'audience','Plaidoiries','2026-06-23','manuel',@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_echeance WHERE id_dossier=@did AND date_echeance='2026-06-23');

-- 26) SIR c/ HSBC (3 PGE)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-HSBC-PGE','Groupe SIR c/ HSBC (3 PGE)','surveillance','orange',
 'Trois PGE : 435 277 € + 97 890 € + 37 835 € (taux faibles 0,54 %/1 % majorés 3 pts). Pas d''hypothèque connue.',
 '[avocat 07/04/2026] Plaidé le 26/02/2026, en délibéré ; jugement attendu sous ~1 à 1,5 mois.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-HSBC-PGE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-HSBC-PGE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_hsbc,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,description,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','3 PGE','435 277 + 97 890 + 37 835',571002.00,@c_hsbc,'ouvert',5,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 27) SIR c/ CB Finances (Campus Léo Lagrange)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-CBFINANCES','Groupe SIR c/ CB Finances (Campus Léo Lagrange)','surveillance','vert',
 'Contentieux ancien locataire commercial (expertise judiciaire). Protocole 11/2025 : solde loyers ~18 000 € en 6 mois.',
 '[avocat 07/04/2026] Protocole = soulagement (risque de lourde condamnation pour mauvais état du bâtiment écarté). Reste quelques mensualités à percevoir. E. Emery connaît bien le dossier.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-CBFINANCES');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-CBFINANCES');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_cb,'creancier_principal',@usr);
INSERT INTO creancier_dossier_item (id_dossier,type,titre,montant,id_tiers_lie,statut,priorite,created_by) SELECT @did,'DETTE','Solde loyers (protocole 11/2025)',18000.00,@c_cb,'ouvert',2,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier_item WHERE id_dossier=@did AND type='DETTE');

-- 28) SIR c/ Villa Calade (syndic)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-VILLACALADE','Groupe SIR c/ Syndicat copro Villa Calade','surveillance','vert',
 'Arriéré de charges de copropriété entièrement soldé. L''adversaire maintient sa demande au titre des frais de procédure.',
 '[avocat 06/05/2026] Affaire en délibéré (examinée le 03/03/2026) ; délibéré attendu vers le 05/05/2026.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-VILLACALADE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-VILLACALADE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_villa,'creancier_principal',@usr);

-- 29) SIR c/ Park Avenue (syndic)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-PARKAVENUE','Groupe SIR c/ Syndicat copro Park Avenue','surveillance','vert',
 'Arriéré de charges de copropriété entièrement soldé. L''adversaire maintient ses demandes au titre des frais de procédure.',
 '[avocat 07/04/2026] Affaire toujours en cours sur les seuls frais de procédure.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-PARKAVENUE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-PARKAVENUE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_park,'creancier_principal',@usr);

-- 30) SIR c/ BPI (soldé)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-BPI','Groupe SIR c/ BPI','clos','vert',
 'Deux procédures devant le TAE Lyon. Soldées (sauf élément ignoré).',
 '[avocat 07/04/2026] Les deux dossiers sont soldés.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-BPI');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-BPI');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_bpi,'creancier_principal',@usr);

-- 31) SIR c/ Société Générale — SGCP / CALIXTE (soldés)
INSERT INTO creancier_dossier (code,libelle,statut,niveau_risque,synthese,commentaire,numero_dossier_adverse,id_societe,id_agence,created_by)
SELECT 'SIR-SGCP-CALIXTE','Groupe SIR c/ Société Générale (SGCP / CALIXTE)','surveillance','vert',
 'Deux dossiers (filiales Société Générale) entièrement soldés via Me Vincent Rulliat (huissier Lyon 6).',
 '[avocat 06/05/2026] Yves Saby soupçonne un trop-versé : 2 points de taux d''écart entre SGCP et Calixte (~70 000 € en défaveur). Vérification demandée ; remboursement peu probable.',
 NULL,@soc,@age,@usr WHERE NOT EXISTS (SELECT 1 FROM creancier_dossier WHERE code='SIR-SGCP-CALIXTE');
SET @did := (SELECT id FROM creancier_dossier WHERE code='SIR-SGCP-CALIXTE');
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (@did,'TIERS',@d_sir,'debiteur',@usr),(@did,'TIERS',@c_sg,'creancier_principal',@usr);

-- ════════════════════════════════════════════════════════════════════
-- CONTACTS communs — liés à TOUS les dossiers
--   Thomas Saby (gérant) · Eric Pouderoux (avocat) · Philippe Guillet (expert-comptable)
--   · Emmanuel Emery (régie / gestionnaire)
-- ════════════════════════════════════════════════════════════════════
INSERT INTO tiers (id_societe,id_agence,type_tiers,nom,prenom,nom_affichage,email,telephone,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_physique','Saby','Thomas','Thomas Saby','t.saby@groupe-sir.fr',NULL,1,@usr,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM tiers WHERE email='t.saby@groupe-sir.fr');
INSERT INTO tiers (id_societe,id_agence,type_tiers,nom,prenom,nom_affichage,email,telephone,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_physique','Pouderoux','Eric','Maître Eric Pouderoux (avocat)','cabinet@pouderoux-avocats.fr','04 78 24 95 01',1,@usr,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM tiers WHERE email='cabinet@pouderoux-avocats.fr');
INSERT INTO tiers (id_societe,id_agence,type_tiers,nom,prenom,nom_affichage,email,telephone,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_physique','Guillet','Philippe','Philippe Guillet (expert-comptable)','pguillet@chiffres-conseils.com',NULL,1,@usr,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM tiers WHERE email='pguillet@chiffres-conseils.com');
INSERT INTO tiers (id_societe,id_agence,type_tiers,nom,prenom,nom_affichage,email,telephone,actif,id_user_createur,date_creation,date_modification)
SELECT @soc,@age,'personne_physique','Emery','Emmanuel','Emmanuel Emery (Régie Emery)','emmanuel.emery@regie-emery.com',NULL,1,@usr,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM tiers WHERE email='emmanuel.emery@regie-emery.com');

SET @k_thomas := (SELECT id FROM tiers WHERE email='t.saby@groupe-sir.fr' LIMIT 1);
SET @k_avocat := (SELECT id FROM tiers WHERE email='cabinet@pouderoux-avocats.fr' LIMIT 1);
SET @k_ec     := (SELECT id FROM tiers WHERE email='pguillet@chiffres-conseils.com' LIMIT 1);
SET @k_emery  := (SELECT id FROM tiers WHERE email='emmanuel.emery@regie-emery.com' LIMIT 1);

-- Rôles catalogue (avocat préexistant, expert_comptable créé en migration 01)
INSERT IGNORE INTO tiers_roles (id_tiers,role_code,objet_type,actif) VALUES
  (@k_avocat,'avocat',NULL,1),(@k_ec,'expert_comptable',NULL,1);

-- Liaison des 4 contacts à TOUS les dossiers (idempotent via clé unique)
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by)
  SELECT d.id,'TIERS',@k_thomas,'gerant',@usr        FROM creancier_dossier d;
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by)
  SELECT d.id,'TIERS',@k_avocat,'avocat',@usr         FROM creancier_dossier d;
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by)
  SELECT d.id,'TIERS',@k_ec,'expert_comptable',@usr   FROM creancier_dossier d;
INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by)
  SELECT d.id,'TIERS',@k_emery,'gestionnaire',@usr    FROM creancier_dossier d;

SQL
];
