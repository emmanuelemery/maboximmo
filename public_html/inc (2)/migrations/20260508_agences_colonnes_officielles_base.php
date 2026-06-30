<?php
/**
 * Migration : colonnes officielles BASE manquantes sur `agences`
 *
 * CONTEXTE
 * Le hook d'OCR société (rh_doc_societe_ocr_hook.php) écrit sur `agences` les
 * valeurs OCR extraites des docs officiels (KBIS, carte pro CCI, garant financier,
 * RC pro, MRI, barème honoraires) — pour que les supports/affiches/critic engine
 * lisent directement `agences.rc_pro` etc. sans jointure.
 *
 * La migration 20260505_agences_documents_officiels avait ajouté UNIQUEMENT
 * les colonnes de TRACKING (*_validite, *_date, *_montant), en supposant
 * que les colonnes BASE (rc_pro, garant_financier, carte_pro_numero, carte_pro_cci,
 * mri_assureur, mri_numero, mri_validite) existaient déjà depuis le schéma legacy.
 *
 * Constat 2026-05-08 sur dev : ces colonnes BASE n'existent PAS. Le hook fait
 * donc silencieusement échouer la réplication (try/catch dans le hook → pas
 * d'erreur visible mais agences.rc_pro reste NULL → affiches sans mention RCP).
 *
 * FIX
 * Ajout idempotent (IF NOT EXISTS) des 9 colonnes BASE manquantes. Aucune
 * donnée écrasée : si une colonne existe déjà avec un autre type, ALTER ...
 * ADD COLUMN IF NOT EXISTS la laisse intacte.
 *
 * Après cette migration : ré-uploader un doc RCP (par exemple) déclenche le hook
 * qui peuple correctement `agences.rc_pro = emetteur` pour TOUTES les agences
 * de la société (réplication société → agences).
 */

return [
    'id'          => '20260508_agences_colonnes_officielles_base',
    'title'       => 'Colonnes BASE officielles sur agences (rc_pro, garant_financier, carte_pro_*, mri_*)',
    'description' => "Ajoute les colonnes de base manquantes sur agences nécessaires à la réplication OCR depuis rh_documents. Sans ces colonnes, le hook société écrit silencieusement dans le vide (try/catch) et les affiches n'affichent ni RCP ni garant ni carte pro.",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `carte_pro_numero`   VARCHAR(50)  NULL COMMENT 'N° de carte professionnelle CCI (loi Hoguet) — répliqué depuis rh_documents.numero',
  ADD COLUMN IF NOT EXISTS `carte_pro_cci`      VARCHAR(150) NULL COMMENT 'CCI émettrice (ex : CCI Lyon Métropole) — répliqué depuis rh_documents.emetteur',
  ADD COLUMN IF NOT EXISTS `carte_pro_validite` DATE         NULL COMMENT 'Date de fin de validité — répliqué depuis rh_documents.date_validite',
  ADD COLUMN IF NOT EXISTS `garant_financier`   VARCHAR(150) NULL COMMENT 'Nom du garant financier (Galian, Socaf, MMA Caution...) — répliqué depuis rh_documents.emetteur (type=garant_financier)',
  ADD COLUMN IF NOT EXISTS `rc_pro`             VARCHAR(150) NULL COMMENT 'Nom de l''assureur RC pro (MMA, AXA, Allianz...) — répliqué depuis rh_documents.emetteur (type=rc_pro)',
  ADD COLUMN IF NOT EXISTS `mri_assureur`       VARCHAR(150) NULL COMMENT 'Assureur Multi-Risques Immeuble (MRI) — répliqué depuis rh_documents type=assurance_mri',
  ADD COLUMN IF NOT EXISTS `mri_numero`         VARCHAR(50)  NULL COMMENT 'N° contrat MRI',
  ADD COLUMN IF NOT EXISTS `mri_validite`       DATE         NULL COMMENT 'Validité MRI',
  ADD COLUMN IF NOT EXISTS `bareme_url`         VARCHAR(500) NULL COMMENT 'URL publique du barème (page web, distinct de bareme_url_doc qui pointe le PDF)';

-- Filet : si la migration 20260505 n'a pas tourné non plus, on rajoute aussi
-- les colonnes de validité (idempotent — IF NOT EXISTS).
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `garant_validite`    DATE          NULL,
  ADD COLUMN IF NOT EXISTS `rc_pro_validite`    DATE          NULL,
  ADD COLUMN IF NOT EXISTS `kbis_date`          DATE          NULL,
  ADD COLUMN IF NOT EXISTS `kbis_numero`        VARCHAR(50)   NULL,
  ADD COLUMN IF NOT EXISTS `garant_montant`     DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS `bareme_url_doc`     VARCHAR(500)  NULL;
SQL,
];
