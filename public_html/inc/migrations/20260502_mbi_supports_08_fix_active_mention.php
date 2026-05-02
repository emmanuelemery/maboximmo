<?php
/**
 * Migration : Ma Box Communication — Réparation version active mentions
 *
 * Bug initial dans la migration 06 (seed_mentions_v1) :
 *   - Etape 1 : UPDATE mbi_supports_mentions_versions SET actif=0
 *   - Etape 2 : INSERT IGNORE de la v1
 *   Si la v1 existait déjà (re-jouage de la migration), l'INSERT IGNORE
 *   etait sauté → BDD sans aucune version active → "Critique indisponible"
 *   sur tous les supports → impossible de générer un PDF.
 *
 * Cette migration de réparation force la v1 à actif=1 sans rien créer
 * (UPDATE seul, idempotent). À appliquer sur toutes les BDD qui ont déjà
 * passé la migration 06 et présentent le symptôme.
 *
 * (La migration 06 a aussi été corrigée pour ne plus reproduire le bug
 * sur les BDD vierges.)
 */

return [
    'id'          => '20260502_mbi_supports_08_fix_active_mention',
    'title'       => 'Ma Box Communication — réparation version active mentions (v1)',
    'description' => "Force la version 2026-05-01.v1 à actif=1 si elle existe. Répare le bug initial de la migration 06 qui laissait la BDD sans version active après un re-jouage.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
UPDATE `mbi_supports_mentions_versions`
SET `actif` = 1
WHERE `version` = '2026-05-01.v1';
SQL,
];
