<?php
declare(strict_types=1);
/**
 * inc/candidat_tiers.php — Cycle de vie du CANDIDAT LOCATAIRE en tant que TIERS.
 *
 *  - À la création d'un projet de bail : on crée (ou retrouve, anti-doublon) un tiers à partir
 *    des infos candidat saisies, et on lui pose le rôle `candidat_locataire` (statut PROVISOIRE
 *    / RGPD), rattaché au bail. Le candidat devient immédiatement CHERCHABLE.
 *  - À la clôture du bail signé : le rôle passe `candidat_locataire` → `locataire`.
 *  - Si le bail est dévalidé : retour à `candidat_locataire` (provisoire).
 *
 * Rôle candidat = `candidat_locataire` ; objet_type = 'BAIL', id_objet = id du bail.
 */

if (!function_exists('candidat_role_set')) {
    /** Pose/réactive un rôle sur le tiers pour un bail (upsert manuel — id_mandat NULL casse INSERT IGNORE). */
    function candidat_role_set(PDO $pdo, int $idTiers, string $role, int $idBail, bool $provisoire): void {
        if ($idTiers <= 0 || $idBail <= 0) return;
        $meta = json_encode(['provisoire' => $provisoire], JSON_UNESCAPED_UNICODE);
        $st = $pdo->prepare("SELECT id FROM tiers_roles
                              WHERE id_tiers=? AND role_code=? AND objet_type='BAIL' AND id_objet=? AND id_mandat IS NULL LIMIT 1");
        $st->execute([$idTiers, $role, $idBail]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            $pdo->prepare("UPDATE tiers_roles SET actif=1, metadata=?, date_modification=NOW() WHERE id=?")
                ->execute([$meta, $id]);
        } else {
            $pdo->prepare("INSERT INTO tiers_roles
                (id_tiers, role_code, objet_type, id_objet, priorite, metadata, actif, date_creation, date_modification)
                VALUES (?, ?, 'BAIL', ?, 0, ?, 1, NOW(), NOW())")
                ->execute([$idTiers, $role, $idBail, $meta]);
        }
    }
}

if (!function_exists('candidat_role_deactivate')) {
    function candidat_role_deactivate(PDO $pdo, int $idTiers, string $role, int $idBail): void {
        $pdo->prepare("UPDATE tiers_roles SET actif=0, date_modification=NOW()
                        WHERE id_tiers=? AND role_code=? AND objet_type='BAIL' AND id_objet=?")
            ->execute([$idTiers, $role, $idBail]);
    }
}

if (!function_exists('candidat_tiers_ensure')) {
    /**
     * Crée (ou retrouve) le tiers candidat à partir des infos saisies + pose le rôle provisoire.
     * @param array $cand  clés : type('physique'|'societe'), nom, prenom, raison_sociale, siren,
     *                     email, telephone, adresse, date_naissance, lieu_naissance, nationalite
     * @return int id_tiers (0 si infos insuffisantes)
     */
    function candidat_tiers_ensure(PDO $pdo, array $cand, int $idSociete, int $idAgence, ?int $idUser, int $idBail): int {
        require_once __DIR__ . '/entity_matcher.php';
        $isSoc = (($cand['type'] ?? '') === 'societe');
        $nom    = trim((string)($cand['nom'] ?? ''));
        $prenom = trim((string)($cand['prenom'] ?? ''));
        $raison = trim((string)($cand['raison_sociale'] ?? ''));
        if ($isSoc ? $raison === '' : ($nom === '' && $prenom === '')) return 0; // rien à créer

        // Anti-doublon : réutilise un tiers existant qui matche.
        $idTiers = 0;
        try {
            $m = em_match_tiers($pdo, [
                'nom' => $nom, 'prenom' => $prenom, 'raison_sociale' => $raison,
                'email' => (string)($cand['email'] ?? ''), 'telephone' => (string)($cand['telephone'] ?? ''),
                'siren' => (string)($cand['siren'] ?? ''),
            ], null, null, $idSociete ?: null);
            if (!empty($m['found']) && !empty($m['best']['id'])) $idTiers = (int)$m['best']['id'];
        } catch (Throwable $e) { error_log('[candidat_tiers em_match] ' . $e->getMessage()); }

        if ($idTiers <= 0) {
            $nomAff = $isSoc ? $raison : trim($prenom . ' ' . $nom);
            $dOK = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : null;
            $st = $pdo->prepare("INSERT INTO tiers
                (id_societe, id_agence, type_tiers, civilite, nom, prenom, raison_sociale, siren,
                 nom_affichage, email, telephone, adresse_ligne1, date_naissance, lieu_naissance, nationalite,
                 source_creation, origine, id_user_createur, actif, date_creation, date_modification)
                VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bail_candidat', 'projet_bail', ?, 1, NOW(), NOW())");
            $st->execute([
                $idSociete ?: null, $idAgence ?: null,
                $isSoc ? 'personne_morale' : 'personne_physique',
                $isSoc ? null : ($nom ?: null), $isSoc ? null : ($prenom ?: null),
                $isSoc ? ($raison ?: null) : null,
                trim((string)($cand['siren'] ?? '')) ?: null,
                $nomAff ?: 'Candidat locataire',
                trim((string)($cand['email'] ?? '')) ?: null,
                trim((string)($cand['telephone'] ?? '')) ?: null,
                trim((string)($cand['adresse'] ?? '')) ?: null,
                $dOK($cand['date_naissance'] ?? ''),
                trim((string)($cand['lieu_naissance'] ?? '')) ?: null,
                trim((string)($cand['nationalite'] ?? '')) ?: null,
                $idUser,
            ]);
            $idTiers = (int)$pdo->lastInsertId();
        }

        // Rôle provisoire lié au bail.
        candidat_role_set($pdo, $idTiers, 'candidat_locataire', $idBail, true);
        return $idTiers;
    }
}

if (!function_exists('candidat_promote_to_locataire')) {
    /** À la clôture/signature : candidat_locataire → locataire (non provisoire) + lien locataire du bail. */
    function candidat_promote_to_locataire(PDO $pdo, int $idBail): void {
        if ($idBail <= 0) return;
        $st = $pdo->prepare("SELECT candidat_tiers_id FROM bien_baux WHERE id=? LIMIT 1");
        $st->execute([$idBail]); $idTiers = (int)$st->fetchColumn();
        if ($idTiers <= 0) return;
        candidat_role_deactivate($pdo, $idTiers, 'candidat_locataire', $idBail);
        candidat_role_set($pdo, $idTiers, 'locataire', $idBail, false);
        // Rattache le tiers comme locataire du bail (colonne dédiée si présente).
        try { $pdo->prepare("UPDATE bien_baux SET id_tiers_locataire=? WHERE id=?")->execute([$idTiers, $idBail]); }
        catch (Throwable) {}
    }
}

if (!function_exists('candidat_revert_to_provisoire')) {
    /** Si le bail est dévalidé : locataire → candidat_locataire (provisoire). */
    function candidat_revert_to_provisoire(PDO $pdo, int $idBail): void {
        if ($idBail <= 0) return;
        $st = $pdo->prepare("SELECT candidat_tiers_id FROM bien_baux WHERE id=? LIMIT 1");
        $st->execute([$idBail]); $idTiers = (int)$st->fetchColumn();
        if ($idTiers <= 0) return;
        candidat_role_deactivate($pdo, $idTiers, 'locataire', $idBail);
        candidat_role_set($pdo, $idTiers, 'candidat_locataire', $idBail, true);
    }
}
