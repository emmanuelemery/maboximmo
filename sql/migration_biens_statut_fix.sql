-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Nettoyage biens au statut vide (fantômes)
-- Créé le 2026-04-19 · Exécuté sur dev le 2026-04-19
-- ═══════════════════════════════════════════════════════════════════════
-- Bug historique : api/bien_autosave.php écrasait biens.statut_bien avec
-- une chaîne vide quand le form courant (ex: Express) n'avait pas d'input
-- `statut_bien`. Résultat : biens fantômes invisibles au modal de purge
-- cascade (qui ne reconnaît que les statuts valides).
--
-- Cette migration est 100% idempotente (aucun effet si déjà exécutée).
-- ═══════════════════════════════════════════════════════════════════════

UPDATE `biens`
   SET `statut_bien` = 'brouillon'
 WHERE `statut_bien` = ''
    OR `statut_bien` IS NULL;
