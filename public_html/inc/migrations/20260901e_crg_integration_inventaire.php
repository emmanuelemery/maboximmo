<?php
/**
 * Migration : PHASE 1 — L'INVENTAIRE, CONFRONTATION AVEC CE QUE MBI CONNAÎT.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE LA PHASE 1 ÉTABLIT ──────────────────────────────────────────────────
 * Pour chaque SITUATION issue de la phase 0 : MBI la connaît-il déjà, est-elle
 * nouvelle, ou faut-il la vérifier ? La confrontation se fait sur
 * `code_compte → proprietaire_comptes_crg → crg_trimestres`, à période égale.
 *
 * ── LES VERDICTS ───────────────────────────────────────────────────────────────
 *   `DEJA CONNUE`    une situation de `crg_trimestres` porte le même compte et la
 *                    même période. `mbi_trimestre_id` dit laquelle.
 *   `NOUVELLE`       le compte est connu, la période ne l'est pas.
 *   `COMPTE INCONNU` le code compte n'existe dans aucun système de MBI.
 *   `A VERIFIER`     plusieurs situations MBI candidates, ou une correspondance
 *                    partielle : on ne tranche pas à la place d'Emmanuel.
 *
 * ⚠️ `ABSENT DU NOUVEAU CORPUS ≠ À SUPPRIMER DE MBI`. Les situations que MBI
 *    connaît et que l'import ne contient pas sont listées à part, à titre
 *    d'information. Un CRG non redéposé ne prouve rien sur le mandat.
 *
 * ⚠️ LA PHASE 1 NE CRÉE, NE MODIFIE, NI N'ARCHIVE RIEN DANS MBI. Elle lit
 *    `crg_trimestres` et `proprietaire_comptes_crg` ; elle n'écrit que dans
 *    `crgi_*`. L'import reste annulable.
 *
 * ⚠️ CES COLONNES NE SONT PAS DANS L'EMPREINTE DE LA PHASE 0 : le sceau porte sur
 *    le découpage, et il survit à l'inventaire.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260901e_crg_integration_inventaire',
    'title'       => 'Intégration CRG — phase 1, inventaire face à MBI',
    'description' => "Ajoute inventaire_statut, inventaire_motif et mbi_trimestre_id à "
                   . "crgi_crg. Aucune écriture dans les tables métier.",
    'created_at'  => '2026-09-01',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_crg`
  ADD COLUMN `inventaire_statut` VARCHAR(24) NULL DEFAULT NULL
      COMMENT 'DEJA CONNUE | NOUVELLE | COMPTE INCONNU | A VERIFIER'
      AFTER `doublon_qualif_motif`,
  ADD COLUMN `inventaire_motif` VARCHAR(400) NULL DEFAULT NULL
      COMMENT 'Pourquoi ce verdict, et a quelle situation MBI elle est rapprochee.'
      AFTER `inventaire_statut`,
  ADD COLUMN `mbi_trimestre_id` INT(11) NULL DEFAULT NULL
      COMMENT 'crg_trimestres.id rapproche. Lecture seule : rien n est ecrit dans MBI.'
      AFTER `inventaire_motif`,
  ADD KEY `idx_inventaire` (`inventaire_statut`);
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_crg`
  DROP COLUMN `mbi_trimestre_id`,
  DROP COLUMN `inventaire_motif`,
  DROP COLUMN `inventaire_statut`;
SQL,
];
