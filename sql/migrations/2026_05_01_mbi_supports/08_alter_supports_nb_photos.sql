-- ─────────────────────────────────────────────────────────────────────────
-- Migration 08 — Ma Box Communication
-- Ajoute la colonne nb_photos sur mbi_supports_commerciaux
-- pour piloter le nombre de photos affichées sur l'affiche vitrine A3 H.
-- 1 = juste la photo héro plein cadre (mode cinéma pur)
-- 4 = héro + 3 thumbs (default — bonne densité visuelle)
-- 5 = héro + 4 thumbs (max)
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `mbi_supports_commerciaux`
  ADD COLUMN IF NOT EXISTS `nb_photos` TINYINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Nb total photos sur l affiche vitrine A3 H (1..5, NULL=auto). 1=cinema pur, 4=default, 5=max'
    AFTER `photo_hero_id_personnalise`;
