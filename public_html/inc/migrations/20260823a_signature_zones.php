<?php
/**
 * Migration : les ZONES à remplir sur un PDF (signature, paraphe, date, texte, case).
 *
 * On prend un document DÉJÀ EN GED et on y pose des emplacements, comme dans Adobe :
 * « ici la signature du salarié », « là sa date », « là ses initiales ». Le document
 * n'est pas régénéré — il est repris tel quel et les valeurs sont apposées dessus à la
 * fin (FPDI : setSourceFile / importPage / useTemplate, déjà utilisés par
 * `inc/acte_pdf_fusion.php`).
 *
 * ── POURQUOI DES POURCENTAGES ET PAS DES POINTS ────────────────────────────────────
 * `x`, `y`, `w`, `h` sont exprimés en **% de la page**, jamais en points ni en pixels.
 * L'éditeur affiche le PDF via pdf.js à une échelle qui dépend de l'écran, du zoom et
 * du DPI ; le rendu final, lui, travaille en millimètres sur une page dont la taille
 * peut être A4, US Letter ou un scan de traviole. Stocker des pixels d'écran, c'est
 * garantir que la signature se retrouvera trois millimètres à côté — et sur un acte,
 * « à côté » veut dire hors du cadre prévu. Le pourcentage est la seule unité qui
 * survive à la fois au zoom, au DPI et au format de papier.
 *
 * L'origine est le COIN HAUT-GAUCHE de la page, comme en CSS et comme dans pdf.js.
 * ⚠️ FPDI/mPDF comptent aussi depuis le haut : pas d'inversion d'axe à faire, mais si
 * un jour on passe à un moteur qui compte depuis le bas (PDF natif), c'est ICI qu'il
 * faudra convertir — pas dans l'éditeur.
 *
 * ── CE QUI EST VOLONTAIREMENT ABSENT ───────────────────────────────────────────────
 * Aucune valeur saisie n'est stockée dans cette table : elle décrit OÙ écrire, pas QUOI.
 * Ce qui est réellement apposé appartient à l'acte signé et à sa preuve, qui vivent
 * dans la chaîne de signature — deux choses de nature différente, deux endroits.
 *
 * `objet_type`/`objet_id` rattachent le gabarit au dossier métier (BAIL, RH…) pour
 * qu'on le retrouve, sans jamais l'y enfermer : un même document peut n'être rattaché
 * à rien et rester signable.
 */

return [
    'id'          => '20260823a_signature_zones',
    'title'       => 'Zones de signature à poser sur un PDF de la GED',
    'description' => "Table signature_zones : emplacements (signature, paraphe, date, texte, case) posés sur un document GED existant, en % de la page pour survivre au zoom, au DPI et au format de papier. Ne stocke aucune valeur saisie — seulement où écrire.",
    'created_at'  => '2026-08-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `signature_zones` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ged_document_id` BIGINT UNSIGNED NOT NULL COMMENT 'Document GED sur lequel la zone est posee',
  `objet_type`      VARCHAR(30)  NULL COMMENT 'Dossier metier de rattachement (BAIL, RH...) — facultatif',
  `objet_id`        INT UNSIGNED NULL,
  `page`            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `x`               DECIMAL(6,3) NOT NULL COMMENT 'Pourcentage de la LARGEUR de page, origine coin haut-gauche',
  `y`               DECIMAL(6,3) NOT NULL COMMENT 'Pourcentage de la HAUTEUR de page, origine coin haut-gauche',
  `w`               DECIMAL(6,3) NOT NULL COMMENT 'Largeur en % de la largeur de page',
  `h`               DECIMAL(6,3) NOT NULL COMMENT 'Hauteur en % de la hauteur de page',
  `type`            VARCHAR(20)  NOT NULL DEFAULT 'signature' COMMENT 'signature|paraphe|date|texte|case',
  `role_code`       VARCHAR(40)  NULL COMMENT 'Qui doit la remplir (meme vocabulaire que les signatures)',
  `libelle`         VARCHAR(120) NULL COMMENT 'Ce qui est demande, affiche au signataire',
  `obligatoire`     TINYINT(1)   NOT NULL DEFAULT 1,
  `ordre`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`      INT UNSIGNED NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc`   (`ged_document_id`, `page`),
  KEY `idx_objet` (`objet_type`, `objet_id`),
  KEY `idx_role`  (`ged_document_id`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Emplacements a remplir sur un PDF de la GED — en % de page, jamais en points';
SQL
];
