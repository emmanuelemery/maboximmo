<?php
/**
 * Migration : seed des 11 modèles de transaction (document_type=MODELE_TRANSACTION).
 *
 * Ces modèles avaient été importés en LOCAL mais sont absents en prod → la card
 * « Documents types » du dossier de vente affichait « Aucun modèle ».
 * Idempotent : n'insère que les modèles absents (WHERE NOT EXISTS sur name_file).
 *
 * ⚠️ Les fichiers PDF physiques doivent exister dans
 *    public_html/uploads/ged/modeles_transaction/  (à uploader sur prod).
 */
return [
    'id'          => '20260615_modeles_transaction_seed',
    'title'       => 'Seed 11 modèles transaction (MODELE_TRANSACTION)',
    'description' => "Insère en GED les 11 modèles (mandats, bon de visite, compromis, promesses, avenants, lettre SRU) si absents. Fichiers PDF à uploader dans uploads/ged/modeles_transaction/.",
    'created_at'  => '2026-06-15',
    'sql' => <<<'SQL'
INSERT INTO ged_documents
    (uuid, document_type, name_display, name_canonical, name_file, final_destination,
     source_module, security_level, storage_provider, status, metadata, created_at)
SELECT UUID(), 'MODELE_TRANSACTION', t.nd, t.nf, t.nf,
       CONCAT('/uploads/ged/modeles_transaction/', t.nf),
       '05_TRANSACTION', 'interne', 'local', 'active', t.meta, NOW()
FROM (
              SELECT 'Mandat exclusif de vente' AS nd, 'mandat_exclusif.pdf' AS nf, '{"modele_key":"mandat_exclusif","etape":"mandat","copro":null,"source":"modelo"}' AS meta
    UNION ALL SELECT 'Mandat de vente sans exclusivité', 'mandat_simple.pdf', '{"modele_key":"mandat_simple","etape":"mandat","copro":null,"source":"modelo"}'
    UNION ALL SELECT 'Mandat de vente « succès »', 'mandat_succes.pdf', '{"modele_key":"mandat_succes","etape":"mandat","copro":null,"source":"modelo"}'
    UNION ALL SELECT 'Avenant au mandat de vente', 'avenant_mandat.pdf', '{"modele_key":"avenant_mandat","etape":"mandat","copro":null,"source":"modelo"}'
    UNION ALL SELECT 'Bon de visite', 'bon_visite.pdf', '{"modele_key":"bon_visite","etape":"visite","copro":null,"source":"modelo"}'
    UNION ALL SELECT 'Compromis de vente (hors copropriété)', 'compromis_hors_copro.pdf', '{"modele_key":"compromis_hors_copro","etape":"compromis","copro":0,"source":"modelo"}'
    UNION ALL SELECT 'Compromis de vente (copropriété)', 'compromis_copro.pdf', '{"modele_key":"compromis_copro","etape":"compromis","copro":1,"source":"modelo"}'
    UNION ALL SELECT 'Promesse unilatérale de vente (hors copropriété)', 'promesse_hors_copro.pdf', '{"modele_key":"promesse_hors_copro","etape":"compromis","copro":0,"source":"modelo"}'
    UNION ALL SELECT 'Promesse unilatérale de vente (copropriété)', 'promesse_copro.pdf', '{"modele_key":"promesse_copro","etape":"compromis","copro":1,"source":"modelo"}'
    UNION ALL SELECT 'Avenant de prorogation du compromis', 'avenant_prorogation.pdf', '{"modele_key":"avenant_prorogation","etape":"compromis","copro":null,"source":"modelo"}'
    UNION ALL SELECT 'Lettre de notification SRU', 'lettre_sru.pdf', '{"modele_key":"lettre_sru","etape":"compromis","copro":null,"source":"modelo"}'
) t
WHERE NOT EXISTS (
    SELECT 1 FROM ged_documents g
     WHERE g.name_file = t.nf AND g.document_type = 'MODELE_TRANSACTION'
);
SQL
    ,
    'down' => <<<'SQL'
DELETE FROM ged_documents WHERE document_type = 'MODELE_TRANSACTION'
  AND name_file IN ('mandat_exclusif.pdf','mandat_simple.pdf','mandat_succes.pdf','avenant_mandat.pdf',
                    'bon_visite.pdf','compromis_hors_copro.pdf','compromis_copro.pdf',
                    'promesse_hors_copro.pdf','promesse_copro.pdf','avenant_prorogation.pdf','lettre_sru.pdf');
SQL
];
