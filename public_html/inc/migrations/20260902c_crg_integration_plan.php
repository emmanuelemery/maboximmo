<?php
/**
 * Migration : PHASE 5 — BILAN AVANT INTÉGRATION.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE LA PHASE 5 ÉTABLIT ──────────────────────────────────────────────────
 * Une seule réponse, famille par famille :
 *   « SI JE VALIDE L'INTÉGRATION, QU'EST-CE QUI SERA CRÉÉ, MIS À JOUR, ARCHIVÉ,
 *     LAISSÉ INCHANGÉ, ARBITRÉ OU REFUSÉ ? »
 *
 * ⚠️ LA PHASE 5 NE LIT PLUS AUCUN PDF. Elle n'a plus le droit de découvrir une
 *    règle de lecture : tout ce qu'elle affirme est DÉRIVÉ des phases 0 à 4,
 *    scellées. Si une question de lecture réapparaît ici, elle appartient à la
 *    phase qui l'a produite — on la lui renvoie, on ne l'absorbe pas.
 *
 * ── `crgi_plan` NE PORTE QUE DES DÉCISIONS ─────────────────────────────────────
 * Une ligne = une famille × une action × un dénombrement, avec le motif qui la
 * justifie et la référence des objets concernés. Aucune écriture métier n'en
 * découle : cette table DÉCRIT ce qui serait fait, elle ne le fait pas.
 *
 * ── LES INTERDITS QUI COMMANDENT CES COLONNES ──────────────────────────────────
 * ⚠️ `ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER.` Aucune action `SUPPRIMER` n'existe
 *    dans l'énumération. Le maximum est `ARCHIVER`, et il exige une démonstration.
 * ⚠️ `ANCIEN LOCATAIRE ≠ SUPPRIMER.` Une succession locative CLÔTURE l'occupation
 *    précédente ; elle n'efface ni l'occupant ni sa dette (`P6A-CREANCE-07`).
 * ⚠️ `ARBITRAGE OUVERT ≠ ÉCRITURE AUTORISÉE.` `bloque` dit exactement quelle
 *    écriture un arbitrage empêche — et, tout aussi important, lesquelles il
 *    n'empêche pas. Un arbitrage d'occupation ne gèle pas le lot ni son argent.
 * ⚠️ `RÉIMPRESSION ≠ NOUVEL ÉVÉNEMENT` et `STOCK ≠ FLUX` : les lignes non
 *    additionnables de la phase 4 sont `NON INTEGRABLE`, conservées et tracées.
 * ⚠️ AUCUNE ÉCRITURE MÉTIER.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260902c_crg_integration_plan',
    'title'       => 'Intégration CRG — phase 5, bilan avant intégration',
    'description' => "Table crgi_plan : par famille, ce qui serait créé, mis à jour, archivé, "
                   . "laissé inchangé, arbitré ou refusé. Aucune action SUPPRIMER n'existe. "
                   . "Aucune écriture métier.",
    'created_at'  => '2026-09-02',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_plan` (
  `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`  INT(10) UNSIGNED NOT NULL,
  `famille`    VARCHAR(30)      NOT NULL
               COMMENT 'PROPRIETAIRES | COMPTES MANDANTS | IMMEUBLES | LOTS | LOCATAIRES | OCCUPATIONS | APPELS | ENCAISSEMENTS | ENCOURS | CHARGES | FRAIS ET ASSURANCES | FLUX PROPRIETAIRE | SOLDES',
  `action`     VARCHAR(20)      NOT NULL
               COMMENT 'CREER | METTRE A JOUR | ARCHIVER | INCHANGE | A ARBITRER | NON INTEGRABLE — SUPPRIMER n existe pas',
  `nombre`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `maille`     VARCHAR(20)      NOT NULL DEFAULT '' COMMENT 'objet compte par cette action',
  `motif`      VARCHAR(500)     NOT NULL DEFAULT '' COMMENT 'ce qui justifie l action, en clair',
  `source`     VARCHAR(40)      NOT NULL DEFAULT '' COMMENT 'la phase scellee qui le demontre',
  `bloque`     VARCHAR(300)     NULL DEFAULT NULL
               COMMENT 'ce qu un arbitrage empeche reellement, et ce qu il n empeche pas',
  `rang`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`, `rang`),
  KEY `idx_famille` (`import_id`, `famille`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ce qui SERAIT ecrit si l integration etait validee. Rien n est ecrit.';
SQL,

    'down' => <<<'SQL'
DROP TABLE IF EXISTS `crgi_plan`;
SQL,
];
