<?php
/**
 * Migration : la mention de cautionnement ne tient pas dans 255 caractères.
 *
 * `bail_signatures.mention_manuscrite` a été créée en VARCHAR(255) (migration
 * 20260724d), à l'époque où l'on y stockait « Bon pour caution solidaire, lu et
 * approuvé » — trente-huit caractères.
 *
 * La mention de l'article 2297, elle, porte le montant garanti EN TOUTES LETTRES
 * et en chiffres, la durée, et la renonciation au bénéfice de discussion. Mesurée
 * par la session qui l'a rédigée : **461 caractères en habitation, 489 en
 * commercial**.
 *
 * En VARCHAR(255), MySQL la TRONQUE — silencieusement hors mode strict. On
 * enregistrerait donc une mention amputée en plein milieu du montant, sur le seul
 * élément dont l'article 2297 sanctionne l'absence par la NULLITÉ de
 * l'engagement. La caution aurait signé, la garantie ne vaudrait rien, et rien
 * dans l'écran ne l'aurait signalé.
 *
 * TEXT (65 535 octets) laisse la marge nécessaire, y compris pour une caution
 * multiple ou un montant en toutes lettres particulièrement long.
 *
 * Audit préalable (15/08/2026) : **0 cautionnement signé en base**. Aucun acte
 * existant n'est donc tronqué ni à rattraper — la correction arrive avant le
 * premier usage réel.
 */

return [
    'id'          => '20260815f_mention_2297_longueur',
    'title'       => 'Mention de cautionnement : VARCHAR(255) → TEXT',
    'description' => "La mention de l'article 2297 fait 461 caractères en habitation et 489 en commercial : en VARCHAR(255) elle serait tronquée en plein milieu du montant garanti, c'est-à-dire sur l'élément dont l'absence entraîne la nullité du cautionnement. Passage en TEXT. Audit : 0 cautionnement signé en base au moment du changement.",
    'created_at'  => '2026-08-15',
    'sql' => <<<'SQL'
ALTER TABLE `bail_signatures`
  MODIFY COLUMN `mention_manuscrite` TEXT NULL
    COMMENT 'Mention art. 2297 apposee par la caution — 461 a 489 caracteres, JAMAIS VARCHAR(255)';
SQL
];
