<?php
/**
 * Migration : RAPPROCHEMENT FINANCIER — sous-phase technique entre P4 et P5.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── POURQUOI CETTE SOUS-PHASE EXISTE ───────────────────────────────────────────
 * La phase 5 annonçait 1 330 mouvements « À ARBITRER » parce qu'ils portent sur des
 * situations que MBI possède déjà. Ce n'étaient pas 1 330 décisions humaines :
 * c'étaient 1 330 confrontations que le moteur n'avait pas encore faites. Les
 * faire porter à un humain, c'est lui déléguer le travail du logiciel.
 *
 * ⚠️ ELLE NE RELIT AUCUN PDF. Elle travaille sur `crgi_mouvement` scellé d'un côté
 *    et `crg_ecritures` de MBI de l'autre. Aucune phase certifiée n'est modifiée.
 *
 * ── LA RÈGLE DE PREUVE, EN COUCHES ─────────────────────────────────────────────
 * ⚠️ `MÊME MONTANT ≠ MÊME ÉCRITURE`, et `MÊME COMPTE + MÊME MONTANT ≠ MÊME
 *    ÉCRITURE`. Le montant n'intervient qu'en DERNIER, pour départager des
 *    candidats déjà retenus sur leur identité — jamais pour les désigner.
 *   1. la situation : le trimestre MBI rapproché par la phase 1, période vérifiée ;
 *   2. le libellé : celui du staging doit être le libellé MBI ou son PRÉFIXE —
 *      MBI recopie la ligne entière, montant compris, là où le staging l'extrait ;
 *   3. le lot : MBI l'écrit court (« 01 ») là où le document imprime « 276-01 ».
 *      On retient donc les candidats dont le lot MBI est le lot du staging ou son
 *      SUFFIXE, jamais une inclusion quelconque ;
 *   4. le sens et le montant : `debit` et `credit` seulement.
 *
 * ── CE QUE MBI NE PEUT PAS PORTER ──────────────────────────────────────────────
 * ⚠️ `crg_ecritures` N'A QUE `debit` ET `credit`. Les colonnes d'APPEL du document
 *    — `Loyers`, `Charges`, `Autres`, `Reste dû` — n'y ont AUCUN équivalent. Les
 *    montants qui en viennent sont donc nouveaux par construction du modèle, et
 *    non par échec de rapprochement : la distinction est portée par le motif.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER : la sous-phase n'écrit que dans le staging `crgi_*`.
 *    `crg_ecritures` n'est jamais modifiée, jamais complétée, jamais corrigée.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260902d_crg_integration_rapprochement',
    'title'       => 'Intégration CRG — rapprochement financier staging ↔ crg_ecritures',
    'description' => "Colonnes de rapprochement sur crgi_mouvement : verdict, écriture MBI "
                   . "candidate et motif. Aucun rapprochement sur le seul montant. "
                   . "Aucune écriture métier.",
    'created_at'  => '2026-09-02',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_mouvement`
  ADD COLUMN IF NOT EXISTS `rapprochement` VARCHAR(28) NULL DEFAULT NULL
      COMMENT 'DEJA PRESENT | NOUVEAU | CANDIDAT NON DEMONTRABLE | CONTRADICTION | HORS PERIMETRE'
      AFTER `provenance`,
  ADD COLUMN IF NOT EXISTS `mbi_ecriture_id` INT(10) UNSIGNED NULL DEFAULT NULL
      COMMENT 'L ecriture crg_ecritures retenue, quand elle est DEMONTREE.'
      AFTER `rapprochement`,
  ADD COLUMN IF NOT EXISTS `rappro_motif` VARCHAR(400) NULL DEFAULT NULL
      COMMENT 'Ce qui demontre le verdict, ou ce qui empeche de le demontrer.'
      AFTER `mbi_ecriture_id`;

ALTER TABLE `crgi_mouvement`
  ADD INDEX IF NOT EXISTS `idx_rappro` (`import_id`, `rapprochement`);
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_mouvement`
  DROP COLUMN IF EXISTS `rapprochement`,
  DROP COLUMN IF EXISTS `mbi_ecriture_id`,
  DROP COLUMN IF EXISTS `rappro_motif`;
SQL,
];
