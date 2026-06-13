<?php
/**
 * inc/dossier_vente.php — Cœur du pivot DOSSIER DE VENTE (Phase 1).
 *
 * Le dossier AGRÈGE l'existant par référence (zéro ressaisie) :
 *   - acteurs   → tiers_roles  (objet_type='dossier_vente', id_objet=dossier.id)
 *   - documents → ged_document_links (entity_type='DOSSIER', entity_id=dossier.id)
 *   - prix      → biens / bien_prix / estimation_agence_*  (lus, jamais recopiés)
 *
 * Fonctions exposées :
 *   dv_ensure_for_bien($pdo, $idBien, $opts)  → crée (idempotent) + sync l'étape, retourne id
 *   dv_get_by_bien($pdo, $idBien)             → ligne dossier_vente (ou null)
 *   dv_get($pdo, $idDossier)                  → ligne dossier_vente (ou null)
 *   dv_sync_etape($pdo, $idDossier)           → recalcule l'étape macro depuis les sources
 *   dv_seed_vendeur($pdo, $idDossier, $idBien)→ propose prospect_vendeur (modifiable)
 *   dv_acteurs($pdo, $idDossier)              → acteurs du dossier (tiers_roles + tiers)
 *   dv_documents($pdo, $idDossier)            → documents GED du dossier
 *
 * Étapes ordonnées (progression avant uniquement, hors états terminaux) :
 *   estimation < mandat < commercialisation < offre < compromis < acte < solde
 *   états terminaux figés : sans_suite, perdu (jamais écrasés par la synchro auto).
 */

declare(strict_types=1);

require_once __DIR__ . '/ged_document_links.php';

if (!function_exists('dv_etape_rank')) {
    /** Rang d'une étape dans la progression (terminaux = -1, non comparables). */
    function dv_etape_rank(string $etape): int {
        static $order = [
            'estimation' => 0, 'mandat' => 1, 'commercialisation' => 2,
            'offre' => 3, 'compromis' => 4, 'acte' => 5, 'solde' => 6,
        ];
        return $order[$etape] ?? -1;
    }
}

if (!function_exists('dv_get')) {
    function dv_get(PDO $pdo, int $idDossier): ?array {
        if ($idDossier <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM dossier_vente WHERE id = ? LIMIT 1");
        $st->execute([$idDossier]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('dv_get_by_bien')) {
    function dv_get_by_bien(PDO $pdo, int $idBien): ?array {
        if ($idBien <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM dossier_vente WHERE id_bien = ? LIMIT 1");
        $st->execute([$idBien]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('dv_ensure_for_bien')) {
    /**
     * Crée le dossier de vente du bien s'il n'existe pas (idempotent via UK id_bien),
     * puis synchronise l'étape macro depuis les sous-systèmes existants.
     *
     * @param array $opts { source?: string, id_user?: int|null, date_estimation?: string|null }
     * @return int dossier_vente.id (0 si bien introuvable)
     */
    function dv_ensure_for_bien(PDO $pdo, int $idBien, array $opts = []): int {
        if ($idBien <= 0) return 0;

        $existing = dv_get_by_bien($pdo, $idBien);
        if ($existing) {
            dv_sync_etape($pdo, (int)$existing['id']);
            return (int)$existing['id'];
        }

        // Scope (société/agence) déduit du bien — jamais ressaisi.
        $stB = $pdo->prepare("SELECT id_societe, id_agence FROM biens WHERE id = ? LIMIT 1");
        $stB->execute([$idBien]);
        $bien = $stB->fetch(PDO::FETCH_ASSOC);
        if (!$bien) return 0;

        $source = (string)($opts['source'] ?? 'estimation');
        $idUser = isset($opts['id_user']) ? (int)$opts['id_user'] : null;
        $dateEstim = $opts['date_estimation'] ?? date('Y-m-d');

        try {
            $st = $pdo->prepare("
                INSERT INTO dossier_vente
                    (id_bien, id_societe, id_agence, etape, date_estimation, source, id_user, created_at, updated_at)
                VALUES
                    (:bien, :soc, :age, 'estimation', :destim, :src, :usr, NOW(), NOW())
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = NOW()
            ");
            $st->execute([
                ':bien'   => $idBien,
                ':soc'    => $bien['id_societe'] !== null ? (int)$bien['id_societe'] : 0,
                ':age'    => $bien['id_agence']  !== null ? (int)$bien['id_agence']  : null,
                ':destim' => $dateEstim ?: null,
                ':src'    => $source,
                ':usr'    => $idUser,
            ]);
            $idDossier = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[dv_ensure_for_bien] ' . $e->getMessage());
            $row = dv_get_by_bien($pdo, $idBien);
            return $row ? (int)$row['id'] : 0;
        }

        // Acteur vendeur (proposé, modifiable) + synchro étape réelle.
        dv_seed_vendeur($pdo, $idDossier, $idBien);
        dv_sync_etape($pdo, $idDossier);

        return $idDossier;
    }
}

if (!function_exists('dv_sync_etape')) {
    /**
     * Recalcule l'étape macro depuis les sources maîtres (mandats, annonces,
     * leads_annonces, statut bien). Progression AVANT uniquement : on ne
     * rétrograde jamais, et on ne touche pas aux états terminaux (sans_suite/perdu).
     * Renseigne aussi id_mandat + date_mandat / date_acte si déductibles.
     */
    function dv_sync_etape(PDO $pdo, int $idDossier): void {
        $d = dv_get($pdo, $idDossier);
        if (!$d) return;
        if (in_array($d['etape'], ['sans_suite', 'perdu'], true)) return; // terminal figé

        $idBien = (int)$d['id_bien'];

        // Mandat de VENTE le plus récent (gérance/location ignorés).
        $stM = $pdo->prepare("SELECT id, date_signature, date_debut FROM mandats
                               WHERE id_bien = ? AND type_mandat = 'vente'
                               ORDER BY COALESCE(date_signature, date_debut) DESC, id DESC LIMIT 1");
        $stM->execute([$idBien]);
        $mandat = $stM->fetch(PDO::FETCH_ASSOC) ?: null;

        // Annonce de vente diffusée ?
        $stA = $pdo->prepare("SELECT COUNT(*) FROM annonces
                               WHERE id_bien = ? AND COALESCE(type_transaction,'vente') = 'vente'
                                 AND (etat_publication = 'diffusee' OR visible_portails = 1 OR statut = 'en_ligne')");
        $stA->execute([$idBien]);
        $hasAnnonce = (int)$stA->fetchColumn() > 0;

        // Offres reçues ?
        $stO = $pdo->prepare("SELECT statut_offre FROM leads_annonces
                               WHERE id_bien = ? AND type_contact = 'offre'");
        $stO->execute([$idBien]);
        $offres = $stO->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Bien vendu (hook acte authentique existant) ?
        $stV = $pdo->prepare("SELECT statut_bien, date_retrait_commercialisation FROM biens WHERE id = ? LIMIT 1");
        $stV->execute([$idBien]);
        $bienV = $stV->fetch(PDO::FETCH_ASSOC) ?: [];
        $estVendu = (($bienV['statut_bien'] ?? '') === 'vendu');

        // Déduction de l'étape cible.
        $cible = 'estimation';
        if ($mandat)          $cible = 'mandat';
        if ($hasAnnonce)      $cible = 'commercialisation';
        if (!empty($offres))  $cible = 'offre';
        if ($estVendu)        $cible = 'acte';

        $updates = [];
        $params  = [':id' => $idDossier];

        // Progression avant uniquement.
        if (dv_etape_rank($cible) > dv_etape_rank((string)$d['etape'])) {
            $updates[] = 'etape = :etape';
            $params[':etape'] = $cible;
        }
        // Renseigne mandat (id + date) si découvert et absent.
        if ($mandat && empty($d['id_mandat'])) {
            $updates[] = 'id_mandat = :idm';
            $params[':idm'] = (int)$mandat['id'];
            $dm = $mandat['date_signature'] ?: $mandat['date_debut'];
            if ($dm && empty($d['date_mandat'])) {
                $updates[] = 'date_mandat = :dm';
                $params[':dm'] = $dm;
            }
        }
        // Date d'acte si vendu et absente.
        if ($estVendu && empty($d['date_acte'])) {
            $updates[] = 'date_acte = :da';
            $params[':da'] = $bienV['date_retrait_commercialisation'] ?: date('Y-m-d');
        }

        if ($updates) {
            $updates[] = 'updated_at = NOW()';
            $sql = "UPDATE dossier_vente SET " . implode(', ', $updates) . " WHERE id = :id";
            try { $pdo->prepare($sql)->execute($params); }
            catch (Throwable $e) { error_log('[dv_sync_etape] ' . $e->getMessage()); }
        }
    }
}

if (!function_exists('dv_seed_vendeur')) {
    /**
     * Propose le vendeur (rôle prospect_vendeur) depuis le propriétaire du bien.
     * PROPOSÉ par défaut, MODIFIABLE, jamais codé en dur : pour les indivisions /
     * SCI où le signataire diffère, l'acteur sera corrigé en phase ultérieure.
     * Idempotent (UK tiers_roles). Aucun effet si le bien n'a pas de tiers rattaché.
     */
    function dv_seed_vendeur(PDO $pdo, int $idDossier, int $idBien): void {
        if ($idDossier <= 0 || $idBien <= 0) return;

        // bien → propriétaire → tiers (id_tiers peut être NULL en legacy).
        $st = $pdo->prepare("SELECT p.id_tiers
                               FROM biens b
                               JOIN proprietaires p ON p.id = b.id_proprietaire
                              WHERE b.id = ? AND p.id_tiers IS NOT NULL LIMIT 1");
        $st->execute([$idBien]);
        $idTiers = (int)($st->fetchColumn() ?: 0);
        if ($idTiers <= 0) return;

        $meta = json_encode(['source' => 'auto_proprietaire', 'modifiable' => true], JSON_UNESCAPED_UNICODE);
        try {
            $pdo->prepare("
                INSERT IGNORE INTO tiers_roles
                    (id_tiers, role_code, objet_type, id_objet, priorite, metadata, actif, date_creation)
                VALUES (?, 'prospect_vendeur', 'dossier_vente', ?, 1, ?, 1, NOW())
            ")->execute([$idTiers, $idDossier, $meta]);
        } catch (Throwable $e) {
            error_log('[dv_seed_vendeur] ' . $e->getMessage());
        }
    }
}

if (!function_exists('dv_acteurs')) {
    /** Acteurs du dossier (vendeur/acquéreur/notaire…) via tiers_roles + tiers. */
    function dv_acteurs(PDO $pdo, int $idDossier): array {
        if ($idDossier <= 0) return [];
        $st = $pdo->prepare("
            SELECT tr.id AS role_id, tr.role_code, tr.priorite, tr.metadata, tr.actif,
                   t.id AS id_tiers, t.nom_affichage, t.nom, t.prenom, t.raison_sociale,
                   t.email, t.telephone, t.type_tiers
              FROM tiers_roles tr
              JOIN tiers t ON t.id = tr.id_tiers
             WHERE tr.objet_type = 'dossier_vente' AND tr.id_objet = ? AND tr.actif = 1
             ORDER BY tr.priorite ASC, tr.id ASC
        ");
        $st->execute([$idDossier]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('dv_documents')) {
    /** Documents GED rattachés au dossier (entity_type='DOSSIER'). */
    function dv_documents(PDO $pdo, int $idDossier): array {
        if ($idDossier <= 0) return [];
        return gdl_documents_for_entity($pdo, 'DOSSIER', $idDossier, ['limit' => 200]);
    }
}
