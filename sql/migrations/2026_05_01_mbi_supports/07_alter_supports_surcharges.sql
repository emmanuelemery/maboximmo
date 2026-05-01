-- =============================================================================
-- Module : Ma Box Communication (mbi_supports)
-- Lot 8 — Colonnes de surcharge pour l'éditeur de support
-- Date  : 2026-05-02
--
-- Objectif :
--   Permettre à l'utilisateur de personnaliser titre / accroche / description /
--   photo héro d'un support SANS modifier la fiche bien officielle.
--   Les surcharges sont LOCALES au support : elles n'affectent pas `biens`.
--
-- Toutes les colonnes sont NULLABLES → si vide, le générateur retombe sur
-- les valeurs du bien (comportement actuel).
-- =============================================================================

ALTER TABLE `mbi_supports_commerciaux`
  ADD COLUMN `titre_personnalise`         VARCHAR(200)  NULL
    COMMENT 'Titre affiché sur le PDF — surcharge biens.designation'
    AFTER `titre_support`,
  ADD COLUMN `accroche`                   VARCHAR(500)  NULL
    COMMENT 'Phrase d''accroche commerciale — placée sous le titre',
  ADD COLUMN `description_personnalisee`  TEXT          NULL
    COMMENT 'Description retravaillée pour ce support — surcharge biens.description',
  ADD COLUMN `photo_hero_id_personnalise` INT UNSIGNED  NULL
    COMMENT 'biens_photos.id — surcharge la photo héro suggérée par l''IA',
  ADD COLUMN `mentions_overrides_json`    LONGTEXT      NULL
    COMMENT 'Surcharges des mentions (ex: désactivation ponctuelle d''une alerte)';
