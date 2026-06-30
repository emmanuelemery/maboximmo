<?php
/**
 * Migration LOCALE : 4 biens EEM doublons (code_crg X_Y) — gardien CRG meme ref conserve.
 * Suite de 20260605c (qui n avait que les ref NULL). Ici inclut les ref -EEM.
 * Sauvegarde: C:/tmp/dedup_eem4_backup.json. LOCAL uniquement.
 */
return [
    'id' => '20260605d_dedup_eem_codecrg',
    'title' => 'Dedup biens EEM code_crg (LOCAL) - 4 biens',
    'description' => 'Supprime 4 squelettes EEM doublons (code_crg X_Y) dont le gardien CRG est conserve.',
    'created_at' => '2026-06-05',
    'sql' => <<<SQL
-- 4 biens EEM doublons (code_crg X_Y) dont le gardien CRG meme ref (X-Y) est conserve.
DELETE bv FROM biens_versions bv JOIN biens b ON b.id=bv.id_bien WHERE b.id IN (723,724,729,730)
   AND NOT EXISTS (SELECT 1 FROM bien_prix p WHERE p.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM bien_baux x WHERE x.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM crg_situations_locataires s WHERE s.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM annonces a WHERE a.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM locataires_statuts ls WHERE ls.id_bien=b.id);

DELETE FROM biens WHERE id IN (723,724,729,730)
   AND id NOT IN (SELECT id_bien FROM bien_prix WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM bien_baux WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM crg_situations_locataires WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM annonces WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM locataires_statuts WHERE id_bien IS NOT NULL);

SQL,
];
