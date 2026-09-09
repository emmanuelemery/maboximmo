-- Amorçage du registre : ce qui EST dans la base sans avoir été enregistré.
-- Les migrations 0001 à 0020 sont appliquées — vérifié table par table le
-- 09/09/2026 (18 tables au stade 0019, plus les 5 tables de la 0020).
INSERT IGNORE INTO schema_migrations (id) VALUES
 ('0001_socle_identite_autorisation'),
 ('0002_user_identites_externes'),
 ('0003_tiers'),
 ('0004_tiers_roles'),
 ('0005_admin_ecritures'),
 ('0006_tiers_roles_referentiel'),
 ('0007_tiers_roles_modules'),
 ('0008_tiers_roles_une_seule_table'),
 ('0009_admin_ecritures_cle_texte'),
 ('0010_document_glossaire'),
 ('0011_document_glossaire_ag_et_photos'),
 ('0012_document_glossaire_trois_questions'),
 ('0013_socle_societe_agence'),
 ('0014_societes_reprise'),
 ('0015_agences_reprise'),
 ('0016_user_adresse_reelle'),
 ('0017_patrimoine'),
 ('0018_documents'),
 ('0019_occupation_plusieurs_occupants'),
 ('0020_mandats_natures_imports');

SELECT id, applique_le FROM schema_migrations ORDER BY id;
SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
 WHERE TABLE_SCHEMA='mbi' ORDER BY TABLE_NAME;
