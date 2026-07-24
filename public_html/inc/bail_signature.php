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

// Durée de validité d'un lien de signature (minutes), à compter de l'envoi (sent_at) ou,
// à défaut, de la création (created_at). Un lien expiré n'autorise plus la signature ;
// l'agent peut renvoyer le lien (bsig_mark_sent réarme la fenêtre).
if (!defined('BSIG_TTL_MIN')) define('BSIG_TTL_MIN', 30);

if (!function_exists('bsig_token')) {
    function bsig_token(): string { return bin2hex(random_bytes(32)); }
}

if (!function_exists('bsig_is_expired')) {
    /** Le lien est-il hors délai ? (jamais expiré s'il est déjà signé) */
    function bsig_is_expired(array $sig): bool {
        if (($sig['statut'] ?? '') === 'signe') return false;
        $base = $sig['sent_at'] ?? null;
        if (!$base) $base = $sig['created_at'] ?? null;
        if (!$base) return false; // pas d'horodatage fiable → on ne bloque pas
        $ts = strtotime((string)$base);
        if ($ts === false) return false;
        return (time() - $ts) > (BSIG_TTL_MIN * 60);
    }
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
        // Destinataire = la PERSONNE qui signe (représentant légal du preneur) en priorité,
        // et non l'email générique du tiers. Fallback sur l'email preneur si non renseigné.
        $preneurEmail = trim((string)($bail['locataire_representant_email'] ?? ''))
                     ?: trim((string)($bail['locataire_email'] ?? ''));
        $signataires[] = [
            'role'  => 'preneur',
            'email' => $preneurEmail ?: null,
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
            if ($ex) {
                // Token déjà créé (bail déjà envoyé) mais pas encore signé : on rafraîchit
                // l'email/nom si l'agent a corrigé le signataire entre-temps.
                if (($ex['statut'] ?? '') !== 'signe'
                    && ($ex['destinataire_email'] !== $sg['email'] || $ex['nom_signataire'] !== $sg['nom'])) {
                    $pdo->prepare("UPDATE bail_signatures SET destinataire_email = ?, nom_signataire = ? WHERE id = ?")
                        ->execute([$sg['email'], $sg['nom'], (int)$ex['id']]);
                    $ex['destinataire_email'] = $sg['email'];
                    $ex['nom_signataire'] = $sg['nom'];
                }
                $created[] = $ex + ['nom' => $sg['nom']];
                continue;
            }

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
    function bsig_sign(PDO $pdo, string $token, string $nom, string $ip, string $ua, ?string $signatureData = null, ?string $photoData = null): array {
        $row = bsig_get_by_token($pdo, $token);
        if (!$row) return ['ok' => false, 'error' => 'lien invalide'];
        if ($row['statut'] === 'signe') return ['ok' => true, 'already' => true];
        $nom = trim($nom);
        if ($nom === '') return ['ok' => false, 'error' => 'nom requis'];
        // signature_data : image du tracé (data URI PNG, présentiel) sinon le nom saisi (email).
        $sigData = ($signatureData !== null && strncmp($signatureData, 'data:image', 10) === 0)
            ? substr($signatureData, 0, 400000) : $nom;

        $pdo->prepare("
            UPDATE bail_signatures
               SET statut = 'signe', nom_signataire = ?, lu_approuve = 1,
                   ip = ?, user_agent = ?, signature_data = ?, signed_at = NOW()
             WHERE id = ? AND statut <> 'signe'
        ")->execute([$nom, substr($ip, 0, 45), substr($ua, 0, 500), $sigData, (int)$row['id']]);

        // Photo-preuve (best-effort) : ne casse JAMAIS la signature si la colonne n'existe pas encore.
        if ($photoData !== null && strncmp($photoData, 'data:image', 10) === 0) {
            try {
                $pdo->prepare("UPDATE bail_signatures SET photo_preuve = ? WHERE id = ?")
                    ->execute([substr($photoData, 0, 1500000), (int)$row['id']]);
            } catch (Throwable $e) { error_log('[bsig_sign photo] ' . $e->getMessage()); }
        }

        $idBail = (int)$row['id_bail'];

        // NB : signer n'a AUCUN effet de bord. La cérémonie continue jusqu'à ce que TOUTES les
        // parties aient signé ; c'est l'AGENT qui clôture ensuite explicitement (bail_cloturer)
        // → bascule candidat→locataire + PDF signé en GED + envoi. `all_signed` est purement
        // informatif (pour activer le bouton « Clôturer » côté UI).
        $stCnt = $pdo->prepare("SELECT COUNT(*) total, SUM(statut='signe') signes FROM bail_signatures WHERE id_bail = ?");
        $stCnt->execute([$idBail]);
        $c = $stCnt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'signes' => 0];
        $allSigned = ((int)$c['total'] > 0 && (int)$c['total'] === (int)$c['signes']);

        // AUTO-FINALISATION : dès que TOUTES les parties ont signé, on génère le PDF définitif,
        // on le classe en GED et on l'envoie à tous les signataires (+ agent). Best-effort : une
        // erreur ne casse jamais la signature. NB : ceci NE fait PAS la bascule métier
        // (résiliation de l'ancien bail / promotion locataire) — celle-ci reste sous le contrôle
        // explicite de l'agent via bail_cloturer.
        $finalized = null;
        if ($allSigned) {
            try { $finalized = bail_finalize_signed($pdo, $idBail); }
            catch (Throwable $e) { error_log('[bsig_sign auto-finalize] ' . $e->getMessage()); }
        }

        return ['ok' => true, 'all_signed' => $allSigned, 'signes' => (int)$c['signes'], 'total' => (int)$c['total'], 'finalized' => $finalized];
    }
}

if (!function_exists('bail_finalize_signed')) {
    /**
     * Une fois le bail signé par TOUTES les parties :
     *   1) génère le PDF DÉFINITIF (sans filigrane, tracés incrustés) ;
     *   2) le CLASSE en GED (type `bail_signe`, lié au BAIL en `main` → coche « Bail signé »
     *      dans « Pièces bail » = voyant vert) + lien BIEN en `reference` ;
     *   3) l'ENVOIE par mail à tous les signataires (preneur + caution) et à l'agent créateur.
     *
     * Idempotent : si un document `bail_signe` est déjà lié au bail, on ne re-classe pas.
     * Best-effort : toute erreur est journalisée, jamais propagée (ne casse pas la signature).
     *
     * @return array{ok:bool, doc_id?:int, mailed?:array, error?:string}
     */
    function bail_finalize_signed(PDO $pdo, int $bailId): array
    {
        if ($bailId <= 0) return ['ok' => false, 'error' => 'bail_id invalide'];

        // Contexte minimal (société / agence / bien) — jamais en dur.
        $st = $pdo->prepare("
            SELECT bb.numero_bail, bb.id_bien, bb.id_societe AS bail_soc, bb.id_agence AS bail_age,
                   bb.locataire_email, bb.locataire_raison_sociale, bb.locataire_nom, bb.locataire_prenom,
                   bb.id_user_created,
                   b.reference_bien, b.id_societe AS bien_soc, b.id_agence AS bien_age,
                   s.raison_sociale AS soc_raison, a.code_agence, a.nom_agence
              FROM bien_baux bb
              JOIN biens b     ON b.id = bb.id_bien
              LEFT JOIN societes s ON s.id = COALESCE(b.id_societe, bb.id_societe)
              LEFT JOIN agences  a ON a.id = COALESCE(b.id_agence, bb.id_agence)
             WHERE bb.id = ? LIMIT 1");
        $st->execute([$bailId]);
        $bail = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bail) return ['ok' => false, 'error' => 'bail introuvable'];

        $socId = (int)($bail['bien_soc'] ?? 0) ?: (int)($bail['bail_soc'] ?? 0) ?: null;
        $ageId = (int)($bail['bien_age'] ?? 0) ?: (int)($bail['bail_age'] ?? 0) ?: null;
        $idBien = (int)$bail['id_bien'];
        $refBail = (string)($bail['numero_bail'] ?: ('bail_' . $bailId));
        $userId = (int)($bail['id_user_created'] ?? 0) ?: null;

        require_once __DIR__ . '/ged_document_links.php';
        require_once __DIR__ . '/bail_commercial_pdf.php';

        // Idempotence : déjà classé (type bail_signe lié au bail) ? → on ne recommence pas le classement.
        $docId = 0;
        try {
            $q = $pdo->prepare("SELECT d.id FROM ged_documents d
                                 JOIN ged_document_links l ON l.document_id = d.id AND l.entity_type='BAIL' AND l.entity_id = ?
                                WHERE d.document_type='bail_signe' AND d.status='active' ORDER BY d.id DESC LIMIT 1");
            $q->execute([$bailId]); $docId = (int)$q->fetchColumn();
        } catch (Throwable) {}

        if ($docId <= 0) {
            // 1) PDF définitif (forceProjet = false → sans filigrane, tracés incrustés).
            $tmpPdf = bail_commercial_build_pdf($pdo, $bailId, false);
            // Persistant (la GED référence le fichier sur disque).
            $permDir = __DIR__ . '/../uploads/baux/';
            if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
            $permName = 'bail_' . $bailId . '_signe_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
            $permPath = $permDir . $permName;
            if (!@rename($tmpPdf, $permPath)) { @copy($tmpPdf, $permPath); @unlink($tmpPdf); }

            $dateDoc = date('Y-m-d');
            $res = gus_commit_document($pdo,
                ['path_on_disk'=>$permPath, 'name_original'=>'Bail_signe_'.$refBail.'.pdf',
                 'mime_type'=>'application/pdf', 'size_bytes'=>filesize($permPath) ?: 0,
                 'public_url'=>'/uploads/baux/'.$permName],
                [
                    'document_type'=>'bail_signe', 'source_module'=>'03_GESTION_LOCATIVE', 'security_level'=>'interne',
                    'societe_id'=>$socId, 'agence_id'=>$ageId, 'tenant_id'=>$socId, 'created_by'=>$userId, 'storage_provider'=>'local',
                    'name_display'=>'Bail commercial signé — '.$refBail,
                    'metadata_extra'=>['statut'=>'signe','id_bail'=>$bailId,'doc_date'=>$dateDoc,'legacy_source'=>'bail_finalize_signed',
                        'classement'=>['bail_id_bdd'=>$bailId,'bien_id_bdd'=>$idBien,'date_doc'=>$dateDoc]],
                    'naming_ctx'=>['societe_raison'=>$bail['soc_raison'] ?? '', 'agence_code'=>$bail['code_agence'] ?? '', 'agence_nom'=>$bail['nom_agence'] ?? '',
                        'user_id'=>$userId, 'n1_slug'=>'03_gestion_locative', 'type_doc'=>'bail_signe',
                        'entity_type'=>'BAIL', 'entity_id'=>$bailId, 'date_doc'=>$dateDoc, 'source_filename'=>'Bail_signe.pdf'],
                ],
                [
                    ['entity_type'=>'BAIL','entity_id'=>$bailId,'relation_type'=>'main'],
                    ['entity_type'=>'BIEN','entity_id'=>$idBien,'relation_type'=>'reference'],
                ]
            );
            if (empty($res['ok'])) return ['ok'=>false, 'error'=>'GED: '.json_encode($res['errors'] ?? ['unknown'])];
            $docId = (int)($res['doc_id'] ?? 0);
        }

        // 3) Envoi du bail signé aux signataires (+ agent créateur).
        $mailed = [];
        try {
            require_once __DIR__ . '/mailer.php';
            if (function_exists('send_mail')) {
                // Le PDF définitif en pièce jointe (régénéré proprement pour le mail).
                $attach = [];
                try {
                    $p = bail_commercial_build_pdf($pdo, $bailId, false);
                    $clean = sys_get_temp_dir() . '/Bail_signe_' . preg_replace('/[^A-Za-z0-9_-]/','', $refBail) . '.pdf';
                    $attach = (@copy($p, $clean)) ? [$clean] : [$p];
                } catch (Throwable) {}

                // Destinataires = emails des signataires + email du preneur + agent.
                $dest = [];
                $qE = $pdo->prepare("SELECT destinataire_email, nom_signataire, role_code FROM bail_signatures WHERE id_bail=? AND statut='signe'");
                $qE->execute([$bailId]);
                foreach ($qE->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $e = trim((string)($r['destinataire_email'] ?? ''));
                    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $dest[strtolower($e)] = $e;
                }
                $le = trim((string)($bail['locataire_email'] ?? ''));
                if ($le !== '' && filter_var($le, FILTER_VALIDATE_EMAIL)) $dest[strtolower($le)] = $le;

                $preneur = $bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'].' '.$bail['locataire_nom']) ?: 'Madame, Monsieur';
                $refB = $bail['reference_bien'] ?: ('#'.$bailId);
                $subject = 'Votre bail commercial signé — ' . $refB;
                $body =
                    '<p>Bonjour '.htmlspecialchars((string)$preneur).',</p>'.
                    '<p>Le <strong>bail commercial</strong> concernant le local <strong>'.htmlspecialchars((string)$refB).'</strong> a été '.
                    '<strong>signé par l\'ensemble des parties</strong>. Vous en trouverez un exemplaire définitif en pièce jointe.</p>'.
                    '<p>Ce document est archivé dans votre espace documentaire. Nous restons à votre disposition.</p>';

                foreach ($dest as $to) {
                    $ok = false;
                    try { $ok = send_mail($to, $subject, $body, $attach, true); } catch (Throwable $e) { error_log('[bail_finalize mail] '.$e->getMessage()); }
                    $mailed[] = ['to'=>$to, 'sent'=>$ok];
                }
            }
        } catch (Throwable $e) { error_log('[bail_finalize_signed mail] '.$e->getMessage()); }

        return ['ok'=>true, 'doc_id'=>$docId, 'mailed'=>$mailed];
    }
}

if (!function_exists('bail_cloturer')) {
    /**
     * CLÔTURE de la cérémonie de signature, déclenchée EXPLICITEMENT par l'agent (bouton +
     * modal de validation). N'agit que si TOUTES les parties enregistrées ont signé.
     *   1) bascule métier : ce bail → `signe` (actif locataire) ; ancien bail du bien → `resilie` ;
     *      le candidat prend la place du locataire ;
     *   2) finalisation : PDF signé → GED (voyant vert) → envoi aux signataires.
     *
     * @return array{ok:bool, error?:string, finalize?:array, signes?:int, total?:int}
     */
    function bail_cloturer(PDO $pdo, int $bailId): array
    {
        if ($bailId <= 0) return ['ok' => false, 'error' => 'bail_id invalide'];

        $st = $pdo->prepare("SELECT COUNT(*) total, SUM(statut='signe') signes FROM bail_signatures WHERE id_bail = ?");
        $st->execute([$bailId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'signes' => 0];
        $total = (int)$c['total']; $signes = (int)$c['signes'];
        if ($total === 0)      return ['ok' => false, 'error' => 'Aucun signataire enregistré sur ce bail.'];
        if ($total !== $signes) return ['ok' => false, 'error' => "Toutes les parties n'ont pas encore signé ($signes/$total). Clôture impossible.", 'signes' => $signes, 'total' => $total];

        // id_bien pour la bascule
        $qb = $pdo->prepare("SELECT id_bien FROM bien_baux WHERE id = ? LIMIT 1");
        $qb->execute([$bailId]);
        $idBien = (int)$qb->fetchColumn();

        try {
            $pdo->beginTransaction();
            // 1) Ancien bail actif du bien (autre que celui-ci) → resilie.
            $pdo->prepare("UPDATE bien_baux SET statut = 'resilie', date_fin = COALESCE(date_fin, CURDATE()), updated_at = NOW()
                            WHERE id_bien = ? AND id <> ? AND statut IN ('actif','signe')")
                ->execute([$idBien, $bailId]);
            // 2) Ce bail devient ACTIF (signé + en vigueur). On CONSERVE candidat_tiers_id :
            //    il devient le locataire (promotion du rôle ci-dessous).
            //    NB : on met 'actif' (et non 'signe') car les listes « baux actifs » filtrent
            //    sur statut='actif' — un bail 'signe' n'y apparaissait pas.
            $pdo->prepare("UPDATE bien_baux
                              SET statut = 'actif', date_signature = COALESCE(date_signature, CURDATE()),
                                  updated_at = NOW()
                            WHERE id = ?")->execute([$bailId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[bail_cloturer bascule] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Bascule échouée : ' . $e->getMessage()];
        }

        // 2bis) Promotion du candidat en LOCATAIRE (rôle tiers). Best-effort.
        try { require_once __DIR__ . '/candidat_tiers.php'; candidat_promote_to_locataire($pdo, $bailId); }
        catch (Throwable $e) { error_log('[bail_cloturer promote] ' . $e->getMessage()); }

        // 3) Finalisation (best-effort) : PDF signé → GED (voyant vert) → mail aux signataires.
        $fin = ['ok' => false];
        try { $fin = bail_finalize_signed($pdo, $bailId); }
        catch (Throwable $e) { error_log('[bail_cloturer finalize] ' . $e->getMessage()); $fin = ['ok' => false, 'error' => $e->getMessage()]; }

        return ['ok' => true, 'finalize' => $fin, 'signes' => $signes, 'total' => $total];
    }
}
