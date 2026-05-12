<?php
/**
 * Migration v1.1 — ged_documents : champ visible_agence (scope inter-agences)
 *
 * Contexte : un document appartient à une agence (`agence_id`).
 * Le champ visible_agence ne s'applique qu'aux documents dont le
 * source_module IN ('01_AGENCE', '01_DIRECTION'). Pour les autres modules
 * (SYNDIC, GESTION_LOCATIVE, TRANSACTION, etc.), le scope agence est
 * strict (agence_id propriétaire uniquement, pas de partage inter-agences).
 *
 * RÈGLE MÉTIER PAR DÉFAUT :
 *   - 01_DIRECTION : tous les docs sont INVISIBLES aux autres agences
 *     (visible_agence DEFAULT 0). Sécurité maximum : la direction décide
 *     explicitement de partager (changer le champ à 1 ou ajouter des
 *     id_agence dans visible_agences_ids).
 *   - 01_AGENCE : pareil par défaut (0), à activer cas par cas.
 *   - Autres modules : champ ignoré par l'app (scope agence strict).
 *
 * Solution :
 *   - `visible_agence` TINYINT(1) DEFAULT 0
 *       0 = visible uniquement par l'agence propriétaire (agence_id)
 *       1 = visible par TOUTES les agences de la société (societe_id)
 *   - `visible_agences_ids` JSON NULL
 *       Si visible_agence = 0 mais ce champ contient un array d'id_agence,
 *       les agences listées en plus de l'agence propriétaire ont accès.
 *       Format : [12, 45, 67]
 *
 * Logique d'accès à appliquer dans les requêtes ged_documents :
 *   - super admin (id_role=1) : voit tout
 *   - source_module IN ('01_AGENCE', '01_DIRECTION') :
 *       (agence_id = $userAgence)
 *       OR (visible_agence = 1 AND societe_id = $userSociete)
 *       OR JSON_CONTAINS(visible_agences_ids, $userAgence)
 *   - autres modules : (agence_id = $userAgence) strict
 *
 * AJOUT uniquement (ADD COLUMN IF NOT EXISTS, idempotent).
 * Le défaut DEFAULT 0 préserve la confidentialité initiale (rétrocompatible).
 */

return [
    'id'          => '20260502_ged_v1_16_documents_visible_agence',
    'title'       => 'Ma GED Box V1.1 — ged_documents : visible_agence + visible_agences_ids (scope inter-agences)',
    'description' => "Ajoute 2 colonnes à ged_documents pour gérer la visibilité inter-agences : visible_agence TINYINT(1) DEFAULT 0 et visible_agences_ids JSON NULL. RÈGLE MÉTIER : ce champ ne s'applique qu'aux docs source_module IN ('01_AGENCE','01_DIRECTION'). Pour DIRECTION, default 0 = tous invisibles aux autres agences (sécurité max). Pour les autres modules (SYNDIC, GESTION, TRANSACTION...), scope agence strict (agence_id propriétaire uniquement). Default 0 = rétrocompatible.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `ged_documents`
  ADD COLUMN IF NOT EXISTS `visible_agence` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '0 = visible uniquement par agence_id ; 1 = visible par toutes agences de la societe',
  ADD COLUMN IF NOT EXISTS `visible_agences_ids` JSON NULL
    COMMENT 'Array d id_agence supplementaires autorisees (ex: [12,45,67])';

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_visible_agence` (`visible_agence`);
SQL,
];
