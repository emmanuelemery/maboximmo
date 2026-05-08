-- ─────────────────────────────────────────────────────────────────────────
-- Migration 09 — Ma Box Communication
-- Ajoute la colonne photos_secondaires_ids_json sur mbi_supports_commerciaux
-- pour stocker la sélection manuelle des thumbnails de l'affiche A3 H.
-- Format : JSON array d'ids biens_photos, ex: [12, 45, 78]
-- NULL = comportement auto (ordre BDD, comme avant)
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `mbi_supports_commerciaux`
  ADD COLUMN IF NOT EXISTS `photos_secondaires_ids_json` LONGTEXT NULL DEFAULT NULL
    COMMENT 'JSON array d ids biens_photos pour les thumbs (ordre = ordre d affichage). NULL = auto'
    AFTER `nb_photos`;
