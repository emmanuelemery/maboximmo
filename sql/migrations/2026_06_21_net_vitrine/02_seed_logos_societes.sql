-- =====================================================================
-- Migration 2026-06-21 : Ma Box Net — logos sociétés (fallback vitrines)
-- ---------------------------------------------------------------------
-- Les logos existaient déjà en fichiers (images/logos/) mais societes.logo_url
-- était vide. On renseigne les chemins URL-safe (copies sans espace).
-- net_context() utilise le logo société en fallback du logo agence.
-- =====================================================================

UPDATE societes SET logo_url = 'images/logos/regie-emery.jpg' WHERE id = 1;
UPDATE societes SET logo_url = 'images/logos/emery-immo.jpg'  WHERE id = 2;
