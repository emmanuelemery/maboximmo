<?php
/**
 * Migration : la FIN DE BAIL imprimée, et le rang de l'occupant dans son bloc.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE CES DEUX COLONNES RÉPARENT ──────────────────────────────────────────
 * ⚠️ UN BLOC DE LOT PEUT PORTER PLUSIEURS OCCUPANTS SUCCESSIFS. Le document imprime
 *    « Locataire: DE SOUSA Jordan, Bail du 01/06/2025 **au 04/06/2026** » puis
 *    « Locataire: GONIN Marine, Bail du 22/06/2026 » — dans le MÊME bloc, sur la
 *    MÊME page. Huit blocs du premier dépôt réel sont dans ce cas.
 *
 *    La phase 3 ne gardait que le PREMIER occupant : elle perdait huit successions
 *    que le document démontre. La phase 4, qui gardait le DERNIER, attribuait
 *    l'argent à l'autre occupant. Deux phases, deux locataires, sur la même page et
 *    le même lot — et rien ne le signalait. `rang` distingue désormais les
 *    occupants d'un même bloc.
 *
 * ⚠️ ET « AU … » EST UNE FIN DE BAIL IMPRIMÉE, PAS UNE DÉDUCTION. 56 lignes du
 *    dépôt la portent. `ABSENCE ≠ DÉPART DÉMONTRÉ` reste vrai — mais quand le
 *    document ÉCRIT la fin du bail, le départ n'est plus une absence : il est dit.
 *    `bail_au` porte cette preuve, et la qualification peut enfin s'en servir.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER.
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260902e_crg_integration_bail_fin',
    'title'       => 'Intégration CRG — fin de bail imprimée et occupants successifs',
    'description' => "Colonnes bail_au et rang sur crgi_occupation : plusieurs occupants dans "
                   . "un même bloc de lot, et la fin de bail que le document imprime.",
    'created_at'  => '2026-09-02',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_occupation`
  ADD COLUMN IF NOT EXISTS `bail_au` DATE NULL DEFAULT NULL
      COMMENT 'Fin de bail IMPRIMEE par le document. NULL = le document ne la dit pas.'
      AFTER `bail_du`,
  ADD COLUMN IF NOT EXISTS `rang` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Rang de l occupant dans son bloc de lot : 0 = le premier imprime.'
      AFTER `bail_au`;
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_occupation`
  DROP COLUMN IF EXISTS `bail_au`,
  DROP COLUMN IF EXISTS `rang`;
SQL,
];
