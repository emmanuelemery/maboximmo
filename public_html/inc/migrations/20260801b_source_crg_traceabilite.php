<?php
/**
 * Migration : traçabilité du CRG source sur les biens et les baux.
 *
 * Ajoute `source_crg_id` (→ crg_trimestres.id) sur `biens` et `bien_baux`. L'import CRG le
 * (ré)écrit à chaque passage : on sait ainsi de QUEL CRG (année + trimestre, et donc quel PDF)
 * proviennent l'occupation et le loyer actuels d'un bien/bail. Il change à chaque trimestre
 * intégré (T2 2026 → T3 2026 → …). NULL = jamais alimenté par un CRG.
 *
 * Affichage : JOIN crg_trimestres ct ON ct.id = source_crg_id → « CRG T{trimestre} {annee} ».
 * Non destructif, idempotent (IF NOT EXISTS).
 */
return [
    'id'          => '20260801b_source_crg_traceabilite',
    'title'       => 'Traçabilité CRG source (biens.source_crg_id, bien_baux.source_crg_id)',
    'description' => "Ajoute source_crg_id (-> crg_trimestres) sur biens et bien_baux pour tracer le CRG (trimestre) d'origine des infos. Reecrit a chaque import.",
    'created_at'  => '2026-08-01',
    'sql' => <<<SQL
ALTER TABLE biens     ADD COLUMN IF NOT EXISTS source_crg_id INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'crg_trimestres.id ayant produit l occupation/infos actuelles' AFTER code_crg;
ALTER TABLE bien_baux ADD COLUMN IF NOT EXISTS source_crg_id INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'crg_trimestres.id ayant produit le loyer/locataire actuels';
ALTER TABLE biens     ADD INDEX IF NOT EXISTS idx_biens_source_crg (source_crg_id);
ALTER TABLE bien_baux ADD INDEX IF NOT EXISTS idx_baux_source_crg (source_crg_id);
SQL,
];
