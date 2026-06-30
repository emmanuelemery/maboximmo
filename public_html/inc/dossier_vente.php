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

        // Référence propre du dossier : <code_agence>-DV-AAMM-id (unique par construction).
        try {
            $pdo->prepare("UPDATE dossier_vente dv
                             LEFT JOIN agences a ON a.id = dv.id_agence
                              SET dv.reference = CONCAT(COALESCE(NULLIF(a.code_agence,''),'MBI'), '-DV-',
                                                        DATE_FORMAT(dv.created_at,'%y%m'), '-', LPAD(dv.id,4,'0'))
                            WHERE dv.id = ? AND (dv.reference IS NULL OR dv.reference = '')")
                ->execute([$idDossier]);
        } catch (Throwable $e) { error_log('[dv reference] ' . $e->getMessage()); }

        // Lot principal (rang 0) + acteur vendeur + collaborateur créateur + synchro étape.
        dv_ensure_lot($pdo, $idDossier, $idBien, ['rang' => 0, 'id_user' => $idUser]);
        dv_seed_vendeur($pdo, $idDossier, $idBien);
        dv_seed_collaborateur($pdo, $idDossier, (int)$idUser);
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

if (!function_exists('dv_seed_collaborateur')) {
    /**
     * Ajoute l'utilisateur qui crée le dossier comme acteur 'collaborateur'.
     * users n'a pas de lien direct vers tiers : on retrouve le tiers par email,
     * puis par nom+prénom, sinon on crée une fiche tiers minimale (réutilisable).
     * Idempotent (INSERT IGNORE sur l'UNIQUE tiers_roles).
     */
    function dv_seed_collaborateur(PDO $pdo, int $idDossier, int $idUser): void {
        if ($idDossier <= 0 || $idUser <= 0) return;
        try {
            $st = $pdo->prepare("SELECT nom, prenom, email, id_societe, id_agence FROM users WHERE id = ? LIMIT 1");
            $st->execute([$idUser]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) return;

            $email  = trim((string)($u['email'] ?? ''));
            $nom    = trim((string)($u['nom'] ?? ''));
            $prenom = trim((string)($u['prenom'] ?? ''));

            // 1) Retrouver un tiers existant : par email, sinon par nom + prénom.
            $idTiers = 0;
            if ($email !== '') {
                $q = $pdo->prepare("SELECT id FROM tiers WHERE email = ? AND email <> '' ORDER BY id ASC LIMIT 1");
                $q->execute([$email]);
                $idTiers = (int)($q->fetchColumn() ?: 0);
            }
            if ($idTiers <= 0 && ($nom !== '' || $prenom !== '')) {
                $q = $pdo->prepare("SELECT id FROM tiers
                                     WHERE type_tiers = 'personne_physique' AND nom = ? AND prenom = ?
                                     ORDER BY id ASC LIMIT 1");
                $q->execute([$nom, $prenom]);
                $idTiers = (int)($q->fetchColumn() ?: 0);
            }

            // 2) Créer une fiche tiers minimale pour le collaborateur si introuvable.
            if ($idTiers <= 0) {
                $nomAff = trim($prenom . ' ' . $nom);
                if ($nomAff === '') $nomAff = $email !== '' ? $email : ('Utilisateur #' . $idUser);
                $ins = $pdo->prepare("INSERT INTO tiers
                    (id_societe, id_agence, type_tiers, nom, prenom, nom_affichage, email,
                     source_creation, id_user_createur, actif, date_creation)
                    VALUES (?, ?, 'personne_physique', ?, ?, ?, ?, 'auto_collaborateur_dossier', ?, 1, NOW())");
                $ins->execute([
                    $u['id_societe'] !== null ? (int)$u['id_societe'] : null,
                    $u['id_agence']  !== null ? (int)$u['id_agence']  : null,
                    $nom ?: null, $prenom ?: null, $nomAff, $email ?: null, $idUser,
                ]);
                $idTiers = (int)$pdo->lastInsertId();
            }
            if ($idTiers <= 0) return;

            // 3) Attacher comme acteur 'collaborateur' du dossier (idempotent).
            $meta = json_encode(['source' => 'auto_createur', 'id_user' => $idUser, 'modifiable' => true], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT IGNORE INTO tiers_roles
                    (id_tiers, role_code, objet_type, id_objet, priorite, metadata, actif, date_creation)
                VALUES (?, 'collaborateur', 'dossier_vente', ?, 5, ?, 1, NOW())")
                ->execute([$idTiers, $idDossier, $meta]);
        } catch (Throwable $e) {
            error_log('[dv_seed_collaborateur] ' . $e->getMessage());
        }
    }
}

if (!function_exists('dv_cancel')) {
    /**
     * Annule un dossier de vente.
     *   - Dossier "vide" (étape estimation, AUCUN mandat) = créé pour rien
     *     → SUPPRESSION physique (dossier + lots + acteurs auto rattachés).
     *   - Dossier ayant progressé (mandat, offre, vente…)
     *     → passage soft en 'sans_suite' (jamais de destruction de données réelles).
     * Les documents GED (entity_type='DOSSIER') ne sont JAMAIS supprimés.
     *
     * @return array { ok: bool, mode: 'deleted'|'sans_suite'|'noop', error?: string }
     */
    function dv_cancel(PDO $pdo, int $idDossier): array {
        $d = dv_get($pdo, $idDossier);
        if (!$d) return ['ok' => false, 'mode' => 'noop', 'error' => 'Dossier introuvable.'];
        if ($d['etape'] === 'sans_suite') return ['ok' => true, 'mode' => 'sans_suite'];

        // "Vide" = encore à l'estimation et sans mandat rattaché.
        $vide = ($d['etape'] === 'estimation') && empty($d['id_mandat']);

        if ($vide) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM tiers_roles WHERE objet_type = 'dossier_vente' AND id_objet = ?")
                    ->execute([$idDossier]);
                $pdo->prepare("DELETE FROM dossier_vente_bien WHERE id_dossier = ?")->execute([$idDossier]);
                $pdo->prepare("DELETE FROM dossier_vente WHERE id = ?")->execute([$idDossier]);
                $pdo->commit();
                return ['ok' => true, 'mode' => 'deleted'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[dv_cancel delete] ' . $e->getMessage());
                return ['ok' => false, 'mode' => 'noop', 'error' => 'Échec de la suppression.'];
            }
        }

        // Dossier engagé : on fige en 'sans_suite' (terminal, non écrasé par la synchro auto).
        try {
            $pdo->prepare("UPDATE dossier_vente SET etape = 'sans_suite', updated_at = NOW() WHERE id = ?")
                ->execute([$idDossier]);
            return ['ok' => true, 'mode' => 'sans_suite'];
        } catch (Throwable $e) {
            error_log('[dv_cancel sans_suite] ' . $e->getMessage());
            return ['ok' => false, 'mode' => 'noop', 'error' => 'Échec de l\'annulation.'];
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

if (!function_exists('dv_roles_autorises')) {
    /** Rôles métier rattachables à un dossier de vente (catalogue tiers_roles_codes). */
    function dv_roles_autorises(): array {
        return [
            'acquereur'            => 'Acquéreur',
            'prospect_acquereur'   => 'Acquéreur (pressenti)',
            'vendeur'              => 'Vendeur',
            'notaire'              => 'Notaire vendeur',
            'notaire_acquereur'    => 'Notaire acquéreur',
            'avocat'               => 'Avocat',
            'conseil'              => 'Conseil',
            'partenaire_apporteur' => 'Apporteur / partenaire',
            'collaborateur'        => 'Collaborateur',
        ];
    }
}

if (!function_exists('dv_acteur_row')) {
    /** Une ligne acteur (tiers_roles + tiers) par id de rôle. */
    function dv_acteur_row(PDO $pdo, int $roleId): ?array {
        if ($roleId <= 0) return null;
        $st = $pdo->prepare("
            SELECT tr.id AS role_id, tr.role_code, tr.priorite, tr.metadata, tr.actif,
                   t.id AS id_tiers, t.nom_affichage, t.nom, t.prenom, t.raison_sociale,
                   t.email, t.telephone, t.type_tiers
              FROM tiers_roles tr
              JOIN tiers t ON t.id = tr.id_tiers
             WHERE tr.id = ? LIMIT 1");
        $st->execute([$roleId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('dv_attach_acteur')) {
    /**
     * Rattache un tiers EXISTANT au dossier avec un rôle (aucune ressaisie).
     * Idempotent (UK tiers_roles). Retourne la ligne acteur, ou null si invalide.
     */
    function dv_attach_acteur(PDO $pdo, int $idDossier, int $idTiers, string $roleCode): ?array {
        if ($idDossier <= 0 || $idTiers <= 0) return null;
        if (!array_key_exists($roleCode, dv_roles_autorises())) return null;

        // Le tiers doit exister.
        $stT = $pdo->prepare("SELECT id FROM tiers WHERE id = ? LIMIT 1");
        $stT->execute([$idTiers]);
        if (!$stT->fetchColumn()) return null;

        try {
            $pdo->prepare("
                INSERT INTO tiers_roles
                    (id_tiers, role_code, objet_type, id_objet, priorite, metadata, actif, date_creation)
                VALUES (?, ?, 'dossier_vente', ?, 5, JSON_OBJECT('source','dossier_card'), 1, NOW())
                ON DUPLICATE KEY UPDATE actif = 1, date_modification = NOW()
            ")->execute([$idTiers, $roleCode, $idDossier]);
        } catch (Throwable $e) {
            error_log('[dv_attach_acteur] ' . $e->getMessage());
            return null;
        }

        // Récupère l'id du rôle (insert ou existant réactivé).
        $stR = $pdo->prepare("SELECT id FROM tiers_roles
                               WHERE id_tiers=? AND role_code=? AND objet_type='dossier_vente' AND id_objet=? LIMIT 1");
        $stR->execute([$idTiers, $roleCode, $idDossier]);
        return dv_acteur_row($pdo, (int)$stR->fetchColumn());
    }
}

if (!function_exists('dv_detach_acteur')) {
    /** Retire un acteur du dossier (soft : actif=0). Le vendeur auto reste retirable. */
    function dv_detach_acteur(PDO $pdo, int $idDossier, int $roleId): bool {
        if ($idDossier <= 0 || $roleId <= 0) return false;
        $st = $pdo->prepare("UPDATE tiers_roles SET actif = 0, date_modification = NOW()
                              WHERE id = ? AND objet_type = 'dossier_vente' AND id_objet = ?");
        return $st->execute([$roleId, $idDossier]);
    }
}

/* ════════════════════════════════════════════════════════════════════════
   LOTS DU MANDAT DE VENTE (multi-biens)
   Un dossier/mandat de vente peut couvrir plusieurs lots (immeuble de rapport,
   vente en bloc ou à la découpe). Chaque lot porte son prix_vente + loyer_reel
   + loyer_potentiel. Loyers pré-remplis depuis le bien si en gestion (Saby),
   saisis librement sinon (mandat extérieur). Repris dans le bien à la signature.
   Voir migration 20260614f_dossier_vente_lots.
   ════════════════════════════════════════════════════════════════════════ */

if (!function_exists('dv_ensure_lot')) {
    /**
     * Rattache (idempotent) un bien comme lot du dossier. Ne touche pas aux
     * prix/loyers d'un lot déjà présent. Retourne l'id du lot (0 si invalide).
     *
     * @param array $opts { rang?: int, id_user?: int|null, prix_vente?: float|null,
     *                       loyer_reel?: float|null, loyer_potentiel?: float|null }
     */
    function dv_ensure_lot(PDO $pdo, int $idDossier, int $idBien, array $opts = []): int {
        if ($idDossier <= 0 || $idBien <= 0) return 0;

        // Le bien doit exister.
        $stB = $pdo->prepare("SELECT id FROM biens WHERE id = ? LIMIT 1");
        $stB->execute([$idBien]);
        if (!$stB->fetchColumn()) return 0;

        $rang   = isset($opts['rang']) ? (int)$opts['rang'] : 0;
        $idUser = isset($opts['id_user']) ? (int)$opts['id_user'] : null;
        $num = static fn($v) => ($v === null || $v === '') ? null : (float)$v;

        try {
            $pdo->prepare("
                INSERT INTO dossier_vente_bien
                    (id_dossier, id_bien, prix_vente, loyer_reel, loyer_potentiel, rang, id_user, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = NOW()
            ")->execute([
                $idDossier, $idBien,
                $num($opts['prix_vente'] ?? null),
                $num($opts['loyer_reel'] ?? null),
                $num($opts['loyer_potentiel'] ?? null),
                $rang, $idUser,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[dv_ensure_lot] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('dv_lots')) {
    /**
     * Lots du dossier + infos bien. Pour les loyers laissés vides sur le lot, on
     * expose la valeur connue du bien (_loyer_reel_bien / _loyer_potentiel_bien)
     * comme suggestion d'affichage — sans jamais l'écrire dans le lot.
     */
    function dv_lots(PDO $pdo, int $idDossier): array {
        if ($idDossier <= 0) return [];
        $st = $pdo->prepare("
            SELECT dvb.id AS lot_id, dvb.id_bien, dvb.estimation, dvb.prix_vente, dvb.loyer_reel,
                   dvb.loyer_potentiel, dvb.rang,
                   b.reference_bien, b.designation, b.id_immeuble,
                   bt.libelle AS type_libelle, b.sous_type_bien,
                   COALESCE(NULLIF(b.adresse_1,''), im.adresse_1) AS lot_adresse,
                   COALESCE(NULLIF(b.code_postal,''), im.code_postal) AS lot_cp,
                   COALESCE(NULLIF(b.ville,''), im.ville) AS lot_ville,
                   -- Locataire : bail actif (bien_baux) en priorité, sinon CRG/gestion
                   -- (locataires_statuts) pour les lots loués vus côté gestion (pas de bien_baux).
                   COALESCE(
                     (SELECT COALESCE(NULLIF(bx.locataire_raison_sociale,''),
                                      NULLIF(TRIM(CONCAT(COALESCE(bx.locataire_prenom,''),' ',COALESCE(bx.locataire_nom,''))),''))
                        FROM bien_baux bx
                       WHERE bx.id_bien = b.id AND bx.statut = 'actif'
                       ORDER BY bx.date_prise_effet DESC, bx.id DESC LIMIT 1),
                     (SELECT ls.locataire_nom
                        FROM locataires_statuts ls
                       WHERE ls.id_bien = b.id AND ls.archive = 0
                         AND ls.statut != 'irrecoverable'
                       ORDER BY ls.id DESC LIMIT 1)
                   ) AS locataire_nom,
                   -- Loyer réel suggéré (ANNUEL) : bail actif sinon biens.loyer_hc, ×12 (sources mensuelles)
                   COALESCE(
                     (SELECT bx2.loyer_mensuel_hc * 12 FROM bien_baux bx2
                       WHERE bx2.id_bien = b.id AND bx2.statut = 'actif'
                       ORDER BY bx2.date_prise_effet DESC, bx2.id DESC LIMIT 1),
                     b.loyer_hc * 12
                   ) AS _loyer_reel_bien,
                   b.loyer_potentiel * 12 AS _loyer_potentiel_bien,
                   -- Prix suggéré (NET VENDEUR) : repris de l'annonce, sinon estimation bien.
                   COALESCE(
                     (SELECT COALESCE(a.prix_net_vendeur, a.prix, a.prix_honoraires_inclus)
                        FROM annonces a
                       WHERE a.id_bien = b.id AND COALESCE(a.type_transaction,'vente') = 'vente'
                         AND COALESCE(a.prix_net_vendeur, a.prix, a.prix_honoraires_inclus) > 0
                       ORDER BY a.id DESC LIMIT 1),
                     b.prix_vente_estime
                   ) AS _prix_vente_bien,
                   im.nom_immeuble, im.adresse_1 AS imm_adresse
              FROM dossier_vente_bien dvb
              JOIN biens b      ON b.id = dvb.id_bien
              LEFT JOIN immeubles im ON im.id = b.id_immeuble
              LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
             WHERE dvb.id_dossier = ?
             ORDER BY dvb.rang ASC, dvb.id ASC
        ");
        $st->execute([$idDossier]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('dv_save_lot')) {
    /** Met à jour prix/loyers d'un lot. Valeurs null/'' → effacent le champ. */
    function dv_save_lot(PDO $pdo, int $idDossier, int $lotId, array $vals): bool {
        if ($idDossier <= 0 || $lotId <= 0) return false;
        $num = static fn($v) => ($v === null || $v === '') ? null : (float)$v;
        $st = $pdo->prepare("
            UPDATE dossier_vente_bien
               SET estimation = :e, prix_vente = :p, loyer_reel = :lr, loyer_potentiel = :lp, updated_at = NOW()
             WHERE id = :lot AND id_dossier = :doss
        ");
        try {
            return $st->execute([
                ':e'   => $num($vals['estimation'] ?? null),
                ':p'   => $num($vals['prix_vente'] ?? null),
                ':lr'  => $num($vals['loyer_reel'] ?? null),
                ':lp'  => $num($vals['loyer_potentiel'] ?? null),
                ':lot' => $lotId, ':doss' => $idDossier,
            ]);
        } catch (Throwable $e) {
            error_log('[dv_save_lot] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('dv_remove_lot')) {
    /** Détache un lot du dossier. Refuse de retirer le dernier lot restant. */
    function dv_remove_lot(PDO $pdo, int $idDossier, int $lotId): bool {
        if ($idDossier <= 0 || $lotId <= 0) return false;
        $stC = $pdo->prepare("SELECT COUNT(*) FROM dossier_vente_bien WHERE id_dossier = ?");
        $stC->execute([$idDossier]);
        if ((int)$stC->fetchColumn() <= 1) return false; // garde au moins 1 lot
        $st = $pdo->prepare("DELETE FROM dossier_vente_bien WHERE id = ? AND id_dossier = ?");
        return $st->execute([$lotId, $idDossier]);
    }
}

if (!function_exists('dv_totaux')) {
    /**
     * Totaux du mandat : prix total (Σ prix lots), loyers réels/potentiels cumulés
     * (lot si renseigné, sinon valeur du bien), rendement brut sur le réel.
     */
    function dv_totaux(PDO $pdo, int $idDossier): array {
        $lots = dv_lots($pdo, $idDossier);
        $estim = 0.0; $prix = 0.0; $lr = 0.0; $lp = 0.0; $n = 0;
        // Loyer retenu pour le rendement, choisi PAR LOT (estimé en priorité, sinon
        // réel) puis sommé sur tous les lots.
        $loyerRdt = 0.0; $usedEstim = false; $usedReel = false;
        foreach ($lots as $l) {
            $n++;
            $estim += (float)($l['estimation'] ?? 0);
            $prix  += (float)(($l['prix_vente']      ?? null) ?? $l['_prix_vente_bien']      ?? 0);
            $lrLot  = (float)(($l['loyer_reel']      ?? null) ?? $l['_loyer_reel_bien']      ?? 0);
            $lpLot  = (float)(($l['loyer_potentiel'] ?? null) ?? $l['_loyer_potentiel_bien'] ?? 0);
            $lr += $lrLot; $lp += $lpLot;
            if ($lpLot > 0)      { $loyerRdt += $lpLot; $usedEstim = true; }
            elseif ($lrLot > 0)  { $loyerRdt += $lrLot; $usedReel  = true; }
        }
        // Loyers saisis en ANNUEL → rendement = loyer annuel / prix (pas de ×12).
        $rendement = ($prix > 0 && $loyerRdt > 0) ? round(($loyerRdt / $prix) * 100, 2) : null;
        $base = null;
        if ($usedEstim && $usedReel) $base = 'estimé/réel';
        elseif ($usedEstim)          $base = 'estimé';
        elseif ($usedReel)           $base = 'réel';
        return [
            'nb_lots'         => $n,
            'estimation'      => $estim,
            'prix_total'      => $prix,
            'loyer_reel'      => $lr,
            'loyer_potentiel' => $lp,
            'rendement_brut'  => $rendement,
            'rendement_base'  => $base,
        ];
    }
}

if (!function_exists('dv_estimations')) {
    /**
     * Avis de valeur / estimations (GED, document_type=ESTIMATION) de tous les lots
     * du dossier, indexés par id_bien. Lecture seule, source unique = GED du bien.
     * @return array<int, array> map id_bien => liste de docs (id, name_display, date)
     */
    function dv_estimations(PDO $pdo, int $idDossier): array {
        $out = [];
        foreach (dv_lots($pdo, $idDossier) as $l) {
            $idBien = (int)$l['id_bien'];
            if (isset($out[$idBien])) continue;
            $docs = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['document_type' => 'ESTIMATION']);
            $out[$idBien] = $docs ?: [];
        }
        return $out;
    }
}

if (!function_exists('dv_sync_prix_annonce')) {
    /**
     * Propage les prix des lots vers le bien + l'annonce (source unique).
     * Règle (prix saisi = NET VENDEUR) : FAI = net + honoraires du lot.
     *   - honoraires répartis au prorata du net de chaque lot (mono-bien = total).
     *   - annonce.prix = FAI · prix_net_vendeur = net · prix_honoraires_inclus = FAI.
     * Réutilise bien_prix_valider (historise + miroirs biens/annonce).
     */
    function dv_sync_prix_annonce(PDO $pdo, int $idDossier): void {
        require_once __DIR__ . '/bien_prix.php';
        $d = dv_get($pdo, $idDossier);
        if (!$d) return;

        $honos = 0.0;
        if (!empty($d['id_mandat'])) {
            $stM = $pdo->prepare("SELECT honoraires FROM mandats WHERE id = ? LIMIT 1");
            $stM->execute([(int)$d['id_mandat']]);
            $honos = (float)($stM->fetchColumn() ?: 0);
        }
        $lots = dv_lots($pdo, $idDossier);
        $totalNet = 0.0;
        foreach ($lots as $l) { $totalNet += (float)($l['prix_vente'] ?? 0); }

        foreach ($lots as $l) {
            $net = (float)($l['prix_vente'] ?? 0);
            if ($net <= 0) continue; // seul un prix de lot explicite se propage
            $honosLot = ($totalNet > 0 && $honos > 0) ? round($honos * $net / $totalNet, 2) : 0.0;
            $fai = $net + $honosLot;
            $idB = (int)$l['id_bien'];
            try {
                bien_prix_valider($pdo, $idB, 'prix_net_vendeur', $net, 'dossier_vente');
                bien_prix_valider($pdo, $idB, 'prix_vente', $fai, 'dossier_vente'); // → annonce.prix
                $pdo->prepare("UPDATE annonces SET prix_honoraires_inclus = ?
                                WHERE id_bien = ? AND (statut IS NULL OR statut NOT IN ('supprime','archive','archivee'))")
                    ->execute([$fai, $idB]);
            } catch (Throwable $e) { error_log('[dv_sync_prix_annonce] ' . $e->getMessage()); }
        }
    }
}

if (!function_exists('dv_apply_lots_to_biens')) {
    /**
     * À la SIGNATURE du mandat : reprend les loyers des lots dans les biens.
     *   loyer_reel      → biens.loyer_hc
     *   loyer_potentiel → biens.loyer_potentiel
     * N'écrit que les valeurs renseignées sur le lot (n'efface jamais le bien).
     * Retourne le nombre de biens mis à jour.
     */
    function dv_apply_lots_to_biens(PDO $pdo, int $idDossier): int {
        if ($idDossier <= 0) return 0;
        $lots = dv_lots($pdo, $idDossier);
        $done = 0;
        foreach ($lots as $l) {
            $sets = []; $params = [];
            // Lot en ANNUEL → biens en MENSUEL : on divise par 12 à la reprise.
            if ($l['loyer_reel'] !== null && $l['loyer_reel'] !== '') {
                $sets[] = 'loyer_hc = ?'; $params[] = round((float)$l['loyer_reel'] / 12, 2);
            }
            if ($l['loyer_potentiel'] !== null && $l['loyer_potentiel'] !== '') {
                $sets[] = 'loyer_potentiel = ?'; $params[] = round((float)$l['loyer_potentiel'] / 12, 2);
            }
            if (!$sets) continue;
            $params[] = (int)$l['id_bien'];
            try {
                $pdo->prepare("UPDATE biens SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $done++;
            } catch (Throwable $e) {
                error_log('[dv_apply_lots_to_biens] ' . $e->getMessage());
            }
        }
        return $done;
    }
}
