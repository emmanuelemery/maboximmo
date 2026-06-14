<?php
/**
 * inc/mandat_signature.php — Signature électronique simple des mandats de vente.
 *
 * 1 token = 1 signataire (table mandat_signatures). Preuve = IP + horodatage + UA.
 * Le mandat + ses termes restent dans `mandats`. À la signature de TOUS les
 * signataires, le mandat passe signé et le dossier_vente se synchronise.
 *
 * Fonctions :
 *   msig_token()                              → token hex 64
 *   msig_build_url($token)                    → URL publique absolue de signature
 *   msig_get_by_token($pdo,$token)            → ligne signature (+ infos mandat/bien)
 *   msig_list_for_mandat($pdo,$idMandat)      → toutes les signatures d'un mandat
 *   msig_create_for_vendeurs($pdo,...)        → crée les tokens pour les vendeurs du dossier
 *   msig_mark_sent($pdo,$id)                  → horodate l'envoi
 *   msig_sign($pdo,$token,$nom,$ip,$ua)       → enregistre la signature + maj mandat/dossier
 */

declare(strict_types=1);

require_once __DIR__ . '/dossier_vente.php';

if (!function_exists('msig_token')) {
    function msig_token(): string { return bin2hex(random_bytes(32)); }
}

if (!function_exists('msig_build_url')) {
    function msig_build_url(string $token): string {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
        $base   = function_exists('app_url') ? app_url('/p/mandat_signature.php') : '/p/mandat_signature.php';
        if (strpos($base, 'http') === 0) return $base . '?t=' . $token;
        return $scheme . '://' . $host . $base . '?t=' . $token;
    }
}

if (!function_exists('msig_get_by_token')) {
    function msig_get_by_token(PDO $pdo, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) return null;
        $st = $pdo->prepare("
            SELECT s.*, m.numero_mandat, m.type_mandat, m.exclusif, m.honoraires, m.honoraires_charge,
                   m.date_debut, m.date_fin, m.id_bien,
                   b.reference_bien, b.designation, b.adresse_1 AS bien_adresse,
                   b.code_postal AS bien_cp, b.ville AS bien_ville,
                   b.prix_demande_initial, b.prix_vente_estime
              FROM mandat_signatures s
              JOIN mandats m ON m.id = s.id_mandat
              JOIN biens   b ON b.id = m.id_bien
             WHERE s.token = ? LIMIT 1");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('msig_list_for_mandat')) {
    function msig_list_for_mandat(PDO $pdo, int $idMandat): array {
        if ($idMandat <= 0) return [];
        $st = $pdo->prepare("
            SELECT s.*, t.nom_affichage, t.raison_sociale, t.nom, t.prenom, t.email AS tiers_email
              FROM mandat_signatures s
              LEFT JOIN tiers t ON t.id = s.id_tiers
             WHERE s.id_mandat = ?
             ORDER BY s.id ASC");
        $st->execute([$idMandat]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('msig_create_for_vendeurs')) {
    /**
     * Crée une demande de signature (token) pour chaque vendeur du dossier qui n'en
     * a pas déjà une en cours. Retourne les lignes créées/existantes (avec token + email).
     */
    function msig_create_for_vendeurs(PDO $pdo, int $idMandat, int $idDossier, ?int $idUser = null): array {
        if ($idMandat <= 0 || $idDossier <= 0) return [];

        $dossier = dv_get($pdo, $idDossier);
        $idSoc   = $dossier ? (int)$dossier['id_societe'] : null;

        $created = [];
        foreach (dv_acteurs($pdo, $idDossier) as $a) {
            if (!in_array($a['role_code'], ['vendeur', 'prospect_vendeur'], true)) continue;
            $idTiers = (int)$a['id_tiers'];
            if ($idTiers <= 0) continue;

            // Déjà une signature en cours (pending) ou signée ? on la réutilise.
            $stEx = $pdo->prepare("SELECT * FROM mandat_signatures
                                    WHERE id_mandat = ? AND id_tiers = ? AND statut <> 'refuse'
                                    ORDER BY id DESC LIMIT 1");
            $stEx->execute([$idMandat, $idTiers]);
            $ex = $stEx->fetch(PDO::FETCH_ASSOC);
            if ($ex) { $created[] = $ex; continue; }

            $token = msig_token();
            $email = $a['email'] ?: null;
            $pdo->prepare("
                INSERT INTO mandat_signatures
                    (id_mandat, id_dossier, id_tiers, role_code, id_societe, token, statut,
                     destinataire_email, id_user_created, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ")->execute([$idMandat, $idDossier, $idTiers, $a['role_code'], $idSoc, $token, $email, $idUser]);

            $id = (int)$pdo->lastInsertId();
            $stN = $pdo->prepare("SELECT * FROM mandat_signatures WHERE id = ?");
            $stN->execute([$id]);
            $created[] = $stN->fetch(PDO::FETCH_ASSOC);
        }
        return $created;
    }
}

if (!function_exists('msig_mark_sent')) {
    function msig_mark_sent(PDO $pdo, int $id): void {
        $pdo->prepare("UPDATE mandat_signatures SET sent_at = NOW() WHERE id = ?")->execute([$id]);
    }
}

if (!function_exists('msig_sign')) {
    /**
     * Enregistre la signature (nom + IP + horodatage + UA). Si tous les signataires
     * du mandat ont signé → mandats.date_signature + statut='actif' + dossier→mandat.
     * @return array { ok, already?:bool, all_signed?:bool, error?:string }
     */
    function msig_sign(PDO $pdo, string $token, string $nom, string $ip, string $ua): array {
        $row = msig_get_by_token($pdo, $token);
        if (!$row) return ['ok' => false, 'error' => 'lien invalide'];
        if ($row['statut'] === 'signe') return ['ok' => true, 'already' => true];

        $nom = trim($nom);
        if ($nom === '') return ['ok' => false, 'error' => 'nom requis'];

        $pdo->prepare("
            UPDATE mandat_signatures
               SET statut = 'signe', nom_signataire = ?, lu_approuve = 1,
                   ip = ?, user_agent = ?, signature_data = ?, signed_at = NOW()
             WHERE id = ? AND statut <> 'signe'
        ")->execute([$nom, substr($ip, 0, 45), substr($ua, 0, 500), $nom, (int)$row['id']]);

        $idMandat  = (int)$row['id_mandat'];
        $idDossier = $row['id_dossier'] !== null ? (int)$row['id_dossier'] : 0;

        // Tous les signataires ont-ils signé ?
        $stCnt = $pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(statut = 'signe') AS signes
            FROM mandat_signatures WHERE id_mandat = ?");
        $stCnt->execute([$idMandat]);
        $c = $stCnt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'signes' => 0];
        $allSigned = ((int)$c['total'] > 0 && (int)$c['total'] === (int)$c['signes']);

        if ($allSigned) {
            // Mandat signé : date + statut, puis resynchro de l'étape du dossier.
            $pdo->prepare("UPDATE mandats
                              SET date_signature = COALESCE(date_signature, CURDATE()),
                                  statut = 'actif'
                            WHERE id = ?")->execute([$idMandat]);
            if ($idDossier > 0) {
                // Mandat signé : le dossier n'est plus temporaire → CONFIRMÉ.
                // Renseigne date_mandat si absente, puis sync étape.
                $pdo->prepare("UPDATE dossier_vente
                                  SET date_mandat = COALESCE(date_mandat, CURDATE()),
                                      statut = 'confirme'
                                WHERE id = ?")->execute([$idDossier]);
                dv_sync_etape($pdo, $idDossier);
            }
        }

        return ['ok' => true, 'all_signed' => $allSigned];
    }
}
