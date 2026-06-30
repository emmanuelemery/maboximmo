<?php
/**
 * Migration : recalcul SYSTÉMATIQUE du label de zone d'encadrement (biens.enc_zone).
 *
 * Bug corrigé en parallèle dans enc_zone_label_recalc_save() : le label n'était
 * rempli que s'il était vide → restait figé (ex. « Bron ») après un changement
 * d'adresse (→ Lyon 7). On réaligne ici TOUS les biens sur la commune dérivée de
 * leur CP courant (priorité au CP de l'immeuble), via base_zones_tendues.
 *
 * Sûr : code_postal est unique par commune dans base_zones_tendues (vérifié),
 * on ne touche qu'aux biens dont le CP est connu de la base ET dont le label diffère.
 * N'efface jamais un label pour un CP hors base.
 */

return [
    'id'          => '20260608c_backfill_enc_zone_label',
    'title'       => 'Recalcul systématique du label enc_zone (commune) depuis le CP courant',
    'description' => "Réaligne biens.enc_zone sur la commune du CP courant (immeuble prioritaire) via base_zones_tendues. Corrige les labels figés après changement d'adresse (ex. Bron → Lyon 7).",
    'created_at'  => '2026-06-08',
    'sql' => <<<'SQL'
ALTER TABLE biens MODIFY COLUMN enc_zone VARCHAR(120) NULL;

UPDATE biens b
LEFT JOIN immeubles i ON i.id = b.id_immeuble
JOIN base_zones_tendues z
     ON z.code_postal = COALESCE(NULLIF(i.code_postal, ''), b.code_postal)
SET b.enc_zone = z.commune
WHERE (b.enc_zone IS NULL OR b.enc_zone <> z.commune);
SQL
];
