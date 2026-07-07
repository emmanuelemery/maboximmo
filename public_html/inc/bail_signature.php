<?php
/**
 * inc/bail_signature.php — Signature électronique du bail commercial (clone du mandat).
 *
 * 1 token = 1 signataire (table bail_signatures). Preuve = IP + horodatage + UA.
 * Signataires d'un projet de bail : le PRENEUR (candidat) et, s'il existe, le GARANT.
 * À la signature de TOUS les signataires :
 *   - le bail passe `signe` (figé, modif par avenant uniquement) ;
 *   - BASCULE MÉTIER : ce bail devient le bail ACTIF du bien ; l'ancien bail actif
 *     du même bien passe `resilie` (le candidat « prend la place » de l'ancien locataire).
 *
 * Fonctions : bsig_token / bsig_build_url / bsig_get_by_token / bsig_list_for_bail /
 *   bsig_create_for_signataires / bsig_mark_sent / bsig_sign
 */
declare(strict_types=1);

if (!function_exists('bsig_token')) {
    function bsig_token(): string { return bin2hex(random_bytes(32)); }
}

if (!function_exists('bsig_build_url')) {
    function bsig_build_url(string $token): string {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
        $base   = function_exists('app_url') ? app_url('/p/bail_signature.php') : '/p/bail_signature.php';
        if (strpos($base, 'http') === 0) return $base . '?t=' . $token;
        return $scheme . '://' . $host . $base . '?t=' . $token;
    }
}

if (!function_exists('bsig_get_by_token')) {
    function bsig_get_by_token(PDO $pdo, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) return null;
        $st = $pdo->prepare("
            SELECT s.*, bb.numero_bail, bb.statut AS bail_statut, bb.id_bien,
                   bb.locataire_raison_sociale, bb.locataire_nom, bb.locataire_prenom,
                   bb.loyer_mensuel_hc, bb.charges_mensuelles, bb.date_prise_effet,
                   b.reference_bien, b.designation, b.adresse_1 AS bien_adresse,
                   b.code_postal AS bien_cp, b.ville AS bien_ville
              FROM bail_signatures s
              JOIN bien_baux bb ON bb.id = s.id_bail
              JOIN biens     b  ON b.id  = bb.id_bien
             WHERE s.token = ? LIMIT 1");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('bsig_list_for_bail')) {
    function bsig_list_for_bail(PDO $pdo, int $idBail): array {
        if ($idBail <= 0) return [];
        $st = $pdo->prepare("SELECT * FROM bail_signatures WHERE id_bail = ? ORDER BY id ASC");
        $st->execute([$idBail]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('bsig_create_for_signataires')) {
    /**
     * Crée un token pour le PRENEUR et (si présent) le GARANT du projet de bail.
     * Réutilise un token existant non refusé. Retourne les lignes créées/existantes.
     */
    function bsig_create_for_signataires(PDO $pdo, int $idBail, ?int $idUser = null): array {
        if ($idBail <= 0) return [];
        $st = $pdo->prepare("SELECT * FROM bien_baux WHERE id = ? LIMIT 1");
        $st->execute([$idBail]);
        $bail = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bail) return [];
        $idSoc = (int)($bail['id_societe'] ?? 0) ?: null;

        // Liste des signataires : preneur (obligatoire) + garant (si présent).
        $signataires = [];
        $preneurNom = $bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'] . ' ' . $bail['locataire_nom']);
        $signataires[] = [
            'role'  => 'preneur',
            'email' => trim((string)($bail['locataire_email'] ?? '')) ?: null,
            'nom'   => $preneurNom ?: 'Le preneur',
            'tiers' => (int)($bail['candidat_tiers_id'] ?? 0) ?: null,
        ];
        if (!empty($bail['garant_present'])) {
            $garNom = $bail['garant_raison_sociale'] ?: trim((string)$bail['garant_prenom'] . ' ' . $bail['garant_nom']);
            $signataires[] = [
                'role'  => 'caution',
                'email' => trim((string)($bail['garant_email'] ?? '')) ?: null,
                'nom'   => $garNom ?: 'Le garant',
                'tiers' => null,
            ];
        }

        $created = [];
        foreach ($signataires as $sg) {
            $stEx = $pdo->prepare("SELECT * FROM bail_signatures
                                    WHERE id_bail = ? AND role_code = ? AND statut <> 'refuse'
                                    ORDER BY id DESC LIMIT 1");
            $stEx->execute([$idBail, $sg['role']]);
            $ex = $stEx->fetch(PDO::FETCH_ASSOC);
            if ($ex) { $created[] = $ex + ['nom' => $sg['nom']]; continue; }

            $token = bsig_token();
            $pdo->prepare("
                INSERT INTO bail_signatures
                    (id_bail, id_tiers, role_code, id_societe, token, statut,
                     destinataire_email, nom_signataire, id_user_created, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
            ")->execute([$idBail, $sg['tiers'], $sg['role'], $idSoc, $token, $sg['email'], $sg['nom'], $idUser]);
            $id = (int)$pdo->lastInsertId();
            $stN = $pdo->prepare("SELECT * FROM bail_signatures WHERE id = ?");
            $stN->execute([$id]);
            $created[] = ($stN->fetch(PDO::FETCH_ASSOC) ?: []) + ['nom' => $sg['nom']];
        }
        return $created;
    }
}

if (!function_exists('bsig_mark_sent')) {
    function bsig_mark_sent(PDO $pdo, int $id): void {
        $pdo->prepare("UPDATE bail_signatures SET sent_at = NOW() WHERE id = ?")->execute([$id]);
    }
}

if (!function_exists('bsig_sign')) {
    /**
     * Enregistre la signature (nom + IP + UA + horodatage). Si TOUS les signataires ont
     * signé → bail `signe` + BASCULE : bail actif du bien = ce bail ; ancien → `resilie`.
     * @return array{ok:bool, already?:bool, all_signed?:bool, error?:string}
     */
    function bsig_sign(PDO $pdo, string $token, string $nom, string $ip, string $ua): array {
        $row = bsig_get_by_token($pdo, $token);
        if (!$row) return ['ok' => false, 'error' => 'lien invalide'];
        if ($row['statut'] === 'signe') return ['ok' => true, 'already' => true];
        $nom = trim($nom);
        if ($nom === '') return ['ok' => false, 'error' => 'nom requis'];

        $pdo->prepare("
            UPDATE bail_signatures
               SET statut = 'signe', nom_signataire = ?, lu_approuve = 1,
                   ip = ?, user_agent = ?, signature_data = ?, signed_at = NOW()
             WHERE id = ? AND statut <> 'signe'
        ")->execute([$nom, substr($ip, 0, 45), substr($ua, 0, 500), $nom, (int)$row['id']]);

        $idBail = (int)$row['id_bail'];
        $idBien = (int)$row['id_bien'];

        // Tous signés ?
        $stCnt = $pdo->prepare("SELECT COUNT(*) total, SUM(statut='signe') signes FROM bail_signatures WHERE id_bail = ?");
        $stCnt->execute([$idBail]);
        $c = $stCnt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'signes' => 0];
        $allSigned = ((int)$c['total'] > 0 && (int)$c['total'] === (int)$c['signes']);

        if ($allSigned) {
            try {
                $pdo->beginTransaction();
                // 1) Ancien bail actif du bien (autre que celui-ci) → resilie.
                $pdo->prepare("UPDATE bien_baux SET statut = 'resilie', date_fin = COALESCE(date_fin, CURDATE()), updated_at = NOW()
                                WHERE id_bien = ? AND id <> ? AND statut IN ('actif','signe')")
                    ->execute([$idBien, $idBail]);
                // 2) Ce bail devient signé (figé) + actif locataire.
                $pdo->prepare("UPDATE bien_baux
                                  SET statut = 'signe', date_signature = COALESCE(date_signature, CURDATE()),
                                      candidat_tiers_id = NULL, updated_at = NOW()
                                WHERE id = ?")->execute([$idBail]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[bsig_sign bascule] ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'all_signed' => $allSigned];
    }
}
