<?php
/**
 * Migration : BACKFILL `dossier_vente` (Phase 1) — univers VENTE uniquement.
 *
 * Crée un dossier de vente pour les biens déjà engagés dans un cycle de vente,
 * en déduisant l'`etape` de l'existant. Réversible (clé `down`).
 *
 * ⚠️ PÉRIMÈTRE STRICT VENTE : sur 152 mandats en base, seuls ~7 sont de type 'vente'
 *   (le reste = gérance/location). On NE crée PAS de dossier_vente pour la gérance.
 *   Univers retenu = bien remplissant AU MOINS un critère vente :
 *     - biens.type_commercialisation = 'vente'
 *     - biens.statut_bien = 'vendu'
 *     - un mandat type_mandat='vente' sur le bien
 *     - une annonce de vente sur le bien
 *
 * Déduction de l'étape (du plus avancé au moins avancé) :
 *     vendu → 'acte' ; offre reçue → 'offre' ; annonce vente diffusée →
 *     'commercialisation' ; mandat vente → 'mandat' ; sinon → 'estimation'.
 *
 * Idempotent : INSERT IGNORE (UK id_bien). Les vendeurs (prospect_vendeur) sont
 * proposés depuis le propriétaire → tiers (modifiables ensuite). Marqueur source='backfill'.
 *
 * Le runner n'exécute que `sql`. `down` = rollback manuel documenté.
 */
return [
    'id'          => '20260614b_dossier_vente_backfill',
    'title'       => 'Backfill dossier_vente (univers vente)',
    'description' => "Crée un dossier_vente (source='backfill') pour chaque bien déjà en cycle de vente (type_commercialisation=vente OU statut_bien=vendu OU mandat vente OU annonce vente), étape déduite de l'existant, + vendeur proposé via tiers_roles. Périmètre strict vente (exclut la gérance).",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
-- 1) Dossiers de vente (étape déduite, mandat vente rattaché si présent)
INSERT IGNORE INTO dossier_vente
    (id_bien, id_societe, id_agence, etape, date_mandat, date_acte, id_mandat, source, created_at, updated_at)
SELECT
    b.id,
    COALESCE(b.id_societe, 0),
    b.id_agence,
    CASE
        WHEN b.statut_bien = 'vendu' THEN 'acte'
        WHEN EXISTS (SELECT 1 FROM leads_annonces la WHERE la.id_bien = b.id AND la.type_contact = 'offre') THEN 'offre'
        WHEN EXISTS (SELECT 1 FROM annonces a WHERE a.id_bien = b.id
                       AND COALESCE(a.type_transaction,'vente') = 'vente'
                       AND (a.etat_publication = 'diffusee' OR a.visible_portails = 1 OR a.statut = 'en_ligne')) THEN 'commercialisation'
        WHEN vm.mid IS NOT NULL THEN 'mandat'
        ELSE 'estimation'
    END AS etape,
    COALESCE(vm.date_sig, vm.date_deb) AS date_mandat,
    CASE WHEN b.statut_bien = 'vendu' THEN COALESCE(b.date_retrait_commercialisation, CURDATE()) ELSE NULL END AS date_acte,
    vm.mid AS id_mandat,
    'backfill', NOW(), NOW()
FROM biens b
LEFT JOIN (
    SELECT m1.id_bien,
           SUBSTRING_INDEX(GROUP_CONCAT(m1.id ORDER BY COALESCE(m1.date_signature, m1.date_debut) DESC, m1.id DESC), ',', 1) AS mid,
           MAX(m1.date_signature) AS date_sig,
           MAX(m1.date_debut)     AS date_deb
    FROM mandats m1
    WHERE m1.type_mandat = 'vente' AND m1.id_bien IS NOT NULL
    GROUP BY m1.id_bien
) vm ON vm.id_bien = b.id
WHERE
    b.type_commercialisation = 'vente'
    OR b.statut_bien = 'vendu'
    OR vm.mid IS NOT NULL
    OR EXISTS (SELECT 1 FROM annonces a WHERE a.id_bien = b.id AND COALESCE(a.type_transaction,'vente') = 'vente');

-- 2) Vendeur proposé (prospect_vendeur) depuis le propriétaire → tiers, pour les dossiers backfillés
INSERT IGNORE INTO tiers_roles
    (id_tiers, role_code, objet_type, id_objet, priorite, metadata, actif, date_creation)
SELECT p.id_tiers, 'prospect_vendeur', 'dossier_vente', dv.id, 1,
       JSON_OBJECT('source','auto_proprietaire','modifiable',true), 1, NOW()
FROM dossier_vente dv
JOIN biens b         ON b.id = dv.id_bien
JOIN proprietaires p ON p.id = b.id_proprietaire
WHERE dv.source = 'backfill' AND p.id_tiers IS NOT NULL;
SQL
    ,
    'down' => <<<'SQL'
DELETE tr FROM tiers_roles tr
  JOIN dossier_vente dv ON dv.id = tr.id_objet
 WHERE tr.objet_type = 'dossier_vente' AND dv.source = 'backfill';
DELETE FROM dossier_vente WHERE source = 'backfill';
SQL
];
