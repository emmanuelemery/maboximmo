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
 *
 * ── L'ORDRE DE LA CÉRÉMONIE (18/08/2026) ─────────────────────────────────────
 *   VAGUE 1 — preneur · colocataires · cautions · BAILLEUR, tous en même temps
 *   VAGUE 2 — le MANDATAIRE seul, ouverte quand la vague 1 est entièrement signée
 *
 * L'agence signe en dernier, en connaissance de cause : tant qu'une partie n'a
 * pas signé, nous n'avons engagé personne de notre côté. Si un colocataire se
 * rétracte, il n'y a pas de signature à retirer — juste une cérémonie qui s'est
 * arrêtée. Cf. migration 20260818b_bail_ceremonie_vagues.
 */
declare(strict_types=1);

// Durée de validité d'un lien de signature (minutes), à compter de l'envoi (sent_at) ou,
// à défaut, de la création (created_at). Un lien expiré n'autorise plus la signature ;
// l'agent peut renvoyer le lien (bsig_mark_sent réarme la fenêtre).
if (!defined('BSIG_TTL_MIN')) define('BSIG_TTL_MIN', 48 * 60); // 48 heures

if (!function_exists('bsig_token')) {
    function bsig_token(): string { return bin2hex(random_bytes(32)); }
}

if (!function_exists('bsig_vague_pour_role')) {
    /**
     * La vague d'un rôle. Le MANDATAIRE seul attend ; toutes les autres parties —
     * preneur, colocataires, cautions ET bailleur — reçoivent leur lien ensemble.
     *
     * ⚠️ Le bailleur est en vague 1. Il est partie à l'acte au même titre que le
     * preneur et n'a aucune raison d'attendre : ce n'est pas lui qui a besoin de
     * savoir que les autres se sont engagés, c'est l'agence qui signe POUR lui.
     */
    function bsig_vague_pour_role(string $roleCode): int {
        return str_starts_with($roleCode, 'mandataire') ? 2 : 1;
    }
}

if (!function_exists('bsig_vague_complete')) {
    /**
     * Toutes les signatures d'une vague sont-elles recueillies ? Une vague VIDE
     * répond « oui » : sans bailleur ni caution, la vague 1 peut se limiter au
     * preneur, et l'absence de signataire ne doit pas bloquer la suite.
     */
    function bsig_vague_complete(PDO $pdo, int $idBail, int $vague): bool {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) total, SUM(statut='signe') signes
                                   FROM bail_signatures
                                  WHERE id_bail = ? AND vague = ? AND statut <> 'refuse'");
            $st->execute([$idBail, $vague]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0, 'signes'=>0];
            return (int)$c['total'] === (int)$c['signes'];
        } catch (Throwable $e) { error_log('[bsig_vague_complete] '.$e->getMessage()); return false; }
    }
}

if (!function_exists('bsig_vague_a_ouvrir')) {
    /**
     * Les signataires d'une vague ENCORE FERMÉE qu'il faut désormais prévenir.
     * Renvoie [] si la vague est déjà ouverte : c'est ce qui rend l'ouverture
     * idempotente, deux signatures simultanées de la vague 1 ne devant pas
     * déclencher deux fois le mail du mandataire.
     */
    function bsig_vague_a_ouvrir(PDO $pdo, int $idBail, int $vague): array {
        try {
            $st = $pdo->prepare("SELECT * FROM bail_signatures
                                  WHERE id_bail = ? AND vague = ? AND statut = 'pending'
                                    AND vague_ouverte_at IS NULL
                               ORDER BY id ASC");
            $st->execute([$idBail, $vague]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[bsig_vague_a_ouvrir] '.$e->getMessage()); return []; }
    }
}

if (!function_exists('bsig_is_expired')) {
    /** Le lien est-il hors délai ? (jamais expiré s'il est déjà signé) */
    function bsig_is_expired(array $sig): bool {
        if (($sig['statut'] ?? '') === 'signe') return false;
        /* ⚠️🔥 UN LIEN JAMAIS ENVOYÉ N'EST PAS EXPIRÉ — IL N'EST PAS ENCORE ARMÉ.
           Les lignes de la vague 2 naissent avec la cérémonie, `sent_at` à NULL :
           le repli sur `created_at` les déclarait donc mortes 48 h plus tard, alors
           que leur vague n'avait même pas été ouverte. Le mandataire convoqué le
           5e jour serait tombé sur « lien expiré » pour un lien qui venait de lui
           être envoyé. Le compte à rebours part de l'ENVOI, jamais de la création. */
        if (empty($sig['sent_at']) && empty($sig['vague_ouverte_at']) && (int)($sig['vague'] ?? 1) >= 2) return false;
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
        // Exclut les signatures ANNULÉES (statut 'refuse') : elles ne doivent plus compter ni
        // s'afficher (sinon une annulation d'envoi laisse des « demandes en attente » fantômes).
        $st = $pdo->prepare("SELECT * FROM bail_signatures WHERE id_bail = ? AND statut <> 'refuse' ORDER BY id ASC");
        $st->execute([$idBail]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('bsig_create_for_signataires')) {
    /**
     * Crée un token pour le PRENEUR et (si présent) le GARANT du projet de bail.
     * Réutilise un token existant non refusé. Retourne les lignes créées/existantes.
     */
    function bsig_create_for_signataires(PDO $pdo, int $idBail, ?int $idUser = null, array $roleEmails = [], array $roleTels = []): array {
        if ($idBail <= 0) return [];
        $st = $pdo->prepare("SELECT * FROM bien_baux WHERE id = ? LIMIT 1");
        $st->execute([$idBail]);
        $bail = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bail) return [];
        $idSoc = (int)($bail['id_societe'] ?? 0) ?: null;
        $ov = static fn(string $role, string $def) => trim((string)($roleEmails[$role] ?? '')) ?: ($def ?: null);
        /* Le MOBILE suit exactement la même règle que l'email : ce que l'agent a
           saisi dans l'écran d'envoi l'emporte sur la fiche du tiers, parce que
           c'est lui qui vient de vérifier le numéro avec le client. On retient le
           `mobile` avant le `telephone` — un code SMS envoyé sur un fixe n'arrive
           nulle part, et l'échec serait silencieux. */
        $ovT = static fn(string $role, string $def) => trim((string)($roleTels[$role] ?? '')) ?: ($def ?: null);

        /* Un numéro n'est retenu QUE s'il ressemble à un portable français
           (06/07, ou 33 6/7 avec indicatif). Un fixe ne recevra jamais le code, et
           l'échec serait SILENCIEUX : l'agent croirait le SMS parti, le signataire
           attendrait un code qui n'arrive pas. Mieux vaut un numéro vide, que
           l'écran d'envoi réclame explicitement avant de laisser partir la
           cérémonie. */
        $telMobile = static function (?string $t): string {
            $t = trim((string)$t);
            if ($t === '') return '';
            $n = preg_replace('/\D/', '', $t);
            return preg_match('/^(0[67]\d{8}$|(00)?33[67]\d{8}$)/', (string)$n) ? $t : '';
        };
        /* `mobile` d'abord, `telephone` en repli — beaucoup de fiches anciennes
           n'ont qu'un `telephone` qui contient en réalité un portable. */
        $telTiers = static function (array $r) use ($telMobile): string {
            return $telMobile($r['mobile'] ?? '') ?: $telMobile($r['telephone'] ?? '');
        };


        // Emails par défaut du BAILLEUR (fiche du propriétaire) et de l'AGENCE (société), best-effort.
        $bailleurNom = ''; $bailleurEmail = ''; $agenceNom = ''; $agenceEmail = ''; $bailleurTiers = null;
        $bailleurTel = ''; $agenceTel = '';
        try {
            /* `tp.id` : le tiers du propriétaire. Il n'était pas remonté, et la ligne de
               signature du bailleur naissait donc avec `id_tiers = NULL` — impossible
               d'ouvrir sa fiche depuis la cérémonie pour corriger un mail ou un mobile,
               alors que le tiers existait bel et bien. */
            $q = $pdo->prepare("SELECT tp.id AS btiers,
                                       COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS bnom,
                                       tp.email AS bemail, tp.mobile AS bmobile, tp.telephone AS btel
                                  FROM biens b LEFT JOIN proprietaires p ON p.id = b.id_proprietaire LEFT JOIN tiers tp ON tp.id = p.id_tiers
                                 WHERE b.id = ? LIMIT 1");
            $q->execute([(int)($bail['id_bien'] ?? 0)]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $bailleurNom = (string)($r['bnom'] ?? ''); $bailleurEmail = (string)($r['bemail'] ?? '');
                $bailleurTel = $telMobile($r['bmobile'] ?? '') ?: $telMobile($r['btel'] ?? '');
                $bailleurTiers = (int)($r['btiers'] ?? 0) ?: null;
            }
        } catch (Throwable) {}
        if ($idSoc) { try {
            $q = $pdo->prepare("SELECT raison_sociale, email FROM societes WHERE id = ? LIMIT 1");
            $q->execute([$idSoc]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $agenceNom = (string)($r['raison_sociale'] ?? ''); $agenceEmail = (string)($r['email'] ?? ''); }
        } catch (Throwable) {} }
        // Mandataire = l'UTILISATEUR CONNECTÉ (celui qui envoie) en priorité, pas l'email
        // générique de la société. Repli sur l'email société si l'user n'en a pas.
        if ($idUser) { try {
            $q = $pdo->prepare("SELECT email, telephone, telephone_pro FROM users WHERE id = ? LIMIT 1");
            $q->execute([(int)$idUser]);
            $u = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            $ue = trim((string)($u['email'] ?? ''));
            if ($ue !== '' && filter_var($ue, FILTER_VALIDATE_EMAIL)) $agenceEmail = $ue;
            /* Le mandataire signe en vague 2 : son code part sur SON portable, pas
               sur la ligne fixe de l'agence, sans quoi la preuve de détention ne
               vaudrait rien (n'importe qui décroche un fixe d'agence). */
            $agenceTel = $telMobile($u['telephone'] ?? '') ?: $telMobile($u['telephone_pro'] ?? '');
        } catch (Throwable) {} }

        // Cérémonie : preneur → agence → bailleur (+ garant si présent). Tous reçoivent leur lien ;
        // le bail signé n'est distribué qu'une fois TOUTES les signatures recueillies (auto-finalisation).
        $signataires = [];

        /* ── TOUTES LES PARTIES SIGNENT ──────────────────────────────────────
           La cérémonie lisait QUATRE COLONNES du bail : un preneur, un garant.
           Un couple de colocataires, ou les deux parents cautions — cas le plus
           courant — n'avaient qu'un seul signataire, et l'acte ne valait que
           contre lui.
           On énumère donc les parties depuis `tiers_roles` (source unique, via
           bail_locataires_list / bail_cautions_list), avec REPLI sur les colonnes
           du bail : les baux d'avant cette bascule n'ont pas de tiers rattachés,
           ils doivent continuer à se signer. */
        require_once __DIR__ . '/bail_locataires.php';
        require_once __DIR__ . '/bail_cautions.php';
        $nomTiers = static function (array $r): string {
            return (string)($r['nom_affichage'] ?: ($r['raison_sociale']
                 ?: trim(((string)($r['prenom'] ?? '')) . ' ' . ((string)($r['nom'] ?? '')))));
        };
        $qualite = ['preneur' => 'preneur', 'colocataire' => 'colocataire', 'locataire' => 'preneur'];

        $preneurs = bail_locataires_list($pdo, $idBail);
        foreach ($preneurs as $i => $r) {
            $em = trim((string)($r['email'] ?? ''));
            $signataires[] = [
                // Le premier est le titulaire ; les suivants gardent leur qualité,
                // qui apparaît sur le lien de signature comme dans l'acte.
                /* ⚠️🔥 LE SUFFIXE MANQUAIT SUR LE RÔLE — un seul signataire pour
                   deux co-preneurs. La ligne de signature est retrouvée par
                   (id_bail, role_code) : deux preneurs portant tous deux
                   `role_code = 'preneur'` se rabattaient sur LA MÊME ligne, et le
                   second écrasait simplement le premier. Résultat mesuré sur un
                   bail commercial à deux preneurs le 18/08 : UNE seule signature
                   créée, au nom du dernier — l'autre co-preneur n'était jamais
                   appelé à signer, et l'acte ne valait pas contre lui.
                   Le suffixe était pourtant déjà appliqué aux cautions ; il avait
                   été oublié ici, où le cas est le plus fréquent. */
                'role'  => ($qualite[(string)($r['role_code'] ?? 'locataire')] ?? 'preneur') . ($i > 0 ? '_' . $i : ''),
                'email' => $ov('preneur' . ($i > 0 ? '_' . $i : ''), $em),
                'tel'   => $ovT('preneur' . ($i > 0 ? '_' . $i : ''), $telTiers($r)),
                'nom'   => $nomTiers($r) ?: 'Le preneur',
                'tiers' => (int)($r['id_tiers'] ?? 0) ?: null,
            ];
        }
        if (!$preneurs) {   // repli : bail sans tiers rattaché
            $preneurNom = $bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'] . ' ' . $bail['locataire_nom']);
            // Email du représentant légal en priorité, sinon email preneur, sinon override modal.
            $preneurEmail = trim((string)($bail['locataire_representant_email'] ?? '')) ?: trim((string)($bail['locataire_email'] ?? ''));
            $signataires[] = [
                'role'  => 'preneur',
                'email' => $ov('preneur', $preneurEmail),
                'tel'   => $ovT('preneur', $telMobile($bail['locataire_representant_telephone'] ?? '') ?: $telMobile($bail['locataire_telephone'] ?? '')),
                'nom'   => $preneurNom ?: 'Le preneur',
                'tiers' => (int)($bail['candidat_tiers_id'] ?? 0) ?: null,
            ];
        }

        $cautions = bail_cautions_list($pdo, $idBail);
        foreach ($cautions as $i => $r) {
            $signataires[] = [
                'role'  => 'caution' . ($i > 0 ? '_' . $i : ''),
                'email' => $ov('caution' . ($i > 0 ? '_' . $i : ''), trim((string)($r['email'] ?? ''))),
                'tel'   => $ovT('caution' . ($i > 0 ? '_' . $i : ''), $telTiers($r)),
                'nom'   => $nomTiers($r) ?: 'La caution',
                'tiers' => (int)($r['id_tiers'] ?? 0) ?: null,
            ];
        }
        if (!$cautions && !empty($bail['garant_present'])) {
            $garNom = $bail['garant_raison_sociale'] ?: trim((string)$bail['garant_prenom'] . ' ' . $bail['garant_nom']);
            $signataires[] = [
                'role'  => 'caution',
                'email' => $ov('caution', trim((string)($bail['garant_email'] ?? ''))),
                'tel'   => $ovT('caution', $telMobile($bail['garant_telephone'] ?? '')),
                'nom'   => $garNom ?: 'Le garant',
                'tiers' => null,
            ];
        }
        /* Agence (mandataire) — présente dès qu'on sait la joindre, par MAIL OU PAR SMS.
           ⚠️ La condition était « un email, sinon rien ». Elle datait d'avant le SMS :
           depuis le 18/08 les deux canaux portent le même jeton, et exiger l'email
           écartait de la cérémonie quelqu'un qu'un SMS suffisait à convoquer.
           L'atelier « 👥 Signataires » permet de compléter ce qui manque. */
        $mandEmail = $ov('mandataire', $agenceEmail);
        $mandTel   = $ovT('mandataire', $agenceTel);
        if ($mandEmail || $mandTel) $signataires[] = [
            'role'  => 'mandataire',
            'email' => $mandEmail,
            'tel'   => $ovT('mandataire', $agenceTel),
            'nom'   => $agenceNom ?: 'L\'agence (mandataire)',
            'tiers' => null,
        ];
        /* Bailleur (propriétaire) — dès qu'on sait le joindre, PAR MAIL OU PAR SMS,
           et seulement si le mandataire ne signe pas à sa place.
           Le mandat de gestion donne à l'agence le pouvoir de conclure le bail au
           nom du propriétaire. Quand elle l'exerce, appeler le bailleur à signer
           n'a pas de sens : c'est l'agence qui engage, et elle seule. La case se
           coche dans l'atelier « 👥 Signataires ».
           ⚠️ Ici aussi la condition « email, sinon rien » écartait silencieusement
           un propriétaire joignable au seul SMS — et son absence de la cérémonie ne
           se voyait nulle part : le bail partait simplement sans lui. */
        $bailEmail = $ov('bailleur', $bailleurEmail);
        $bailTel   = $ovT('bailleur', $bailleurTel ?: $telMobile($bail['bailleur_representant_telephone'] ?? ''));
        if (!empty($bail['mandataire_signe_pour_bailleur'])) { $bailEmail = ''; $bailTel = ''; }
        if ($bailEmail || $bailTel) $signataires[] = [
            'role'  => 'bailleur',
            'email' => $bailEmail,
            'tel'   => $bailTel,
            'nom'   => ($bail['bailleur_representant_nom'] ?? '') ?: ($bailleurNom ?: 'Le bailleur'),
            'tiers' => $bailleurTiers,
        ];

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
                    && ($ex['destinataire_email'] !== $sg['email'] || $ex['nom_signataire'] !== $sg['nom']
                        || ($ex['destinataire_tel'] ?? null) !== ($sg['tel'] ?? null))) {
                    /* ⚠️ On ne remplace JAMAIS un numéro déjà renseigné par du vide :
                       l'agent qui renvoie un lien sans repasser par l'écran d'envoi
                       effacerait le mobile auquel le code doit partir. */
                    $telMaj = ($sg['tel'] ?? null) ?: ($ex['destinataire_tel'] ?? null);
                    $pdo->prepare("UPDATE bail_signatures SET destinataire_email = ?, destinataire_tel = ?, nom_signataire = ? WHERE id = ?")
                        ->execute([$sg['email'], $telMaj, $sg['nom'], (int)$ex['id']]);
                    $ex['destinataire_email'] = $sg['email'];
                    $ex['destinataire_tel']   = $telMaj;
                    $ex['nom_signataire'] = $sg['nom'];
                }
                $created[] = $ex + ['nom' => $sg['nom']];
                continue;
            }

            $token = bsig_token();
            /* La vague est GRAVÉE à la création, pas recalculée à chaque lecture :
               l'ordre d'une cérémonie est un fait de l'acte, et une cérémonie en
               cours ne doit pas se réordonner parce qu'on a changé la règle. */
            $pdo->prepare("
                INSERT INTO bail_signatures
                    (id_bail, id_tiers, role_code, id_societe, token, statut,
                     destinataire_email, destinataire_tel, nom_signataire, vague, id_user_created, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, NOW())
            ")->execute([$idBail, $sg['tiers'], $sg['role'], $idSoc, $token, $sg['email'],
                         $sg['tel'] ?? null, $sg['nom'], bsig_vague_pour_role((string)$sg['role']), $idUser]);
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
    function bsig_sign(PDO $pdo, string $token, string $nom, string $ip, string $ua, ?string $signatureData = null, ?string $photoData = null, ?string $mode = null): array {
        $row = bsig_get_by_token($pdo, $token);
        if (!$row) return ['ok' => false, 'error' => 'lien invalide'];
        if ($row['statut'] === 'signe') return ['ok' => true, 'already' => true];
        $nom = trim($nom);
        if ($nom === '') return ['ok' => false, 'error' => 'nom requis'];
        // signature_data : image du tracé (data URI PNG, présentiel) sinon le nom saisi (email).
        $estTrace = ($signatureData !== null && strncmp($signatureData, 'data:image', 10) === 0);
        $sigData  = $estTrace ? substr($signatureData, 0, 400000) : $nom;
        /* ⚠️ Le mode est une INFORMATION, jamais une hiérarchie. Le tracé au doigt
           n'a aucune supériorité légale sur un nom tapé : ni l'un ni l'autre n'est
           une signature manuscrite au sens du droit, ce sont deux représentations.
           Ce qui vaut signature électronique, c'est le procédé fiable
           d'identification (art. 1367 al. 2 C. civ.) — lien nominatif, code SMS,
           horodatage, IP, empreinte. Le certificat note le mode retenu ; il ne
           doit surtout pas laisser croire que l'un vaut mieux que l'autre. */
        $modeRetenu = in_array((string)$mode, ['trace', 'clavier'], true)
            ? (string)$mode : ($estTrace ? 'trace' : 'clavier');

        $pdo->prepare("
            UPDATE bail_signatures
               SET statut = 'signe', nom_signataire = ?, lu_approuve = 1,
                   ip = ?, user_agent = ?, signature_data = ?, signature_mode = ?, signed_at = NOW()
             WHERE id = ? AND statut <> 'signe'
        ")->execute([$nom, substr($ip, 0, 45), substr($ua, 0, 500), $sigData, $modeRetenu, (int)$row['id']]);

        // Photo-preuve (best-effort) : ne casse JAMAIS la signature si la colonne n'existe pas encore.
        if ($photoData !== null && strncmp($photoData, 'data:image', 10) === 0) {
            try {
                $pdo->prepare("UPDATE bail_signatures SET photo_preuve = ? WHERE id = ?")
                    ->execute([substr($photoData, 0, 1500000), (int)$row['id']]);
            } catch (Throwable $e) { error_log('[bsig_sign photo] ' . $e->getMessage()); }
        }

        $idBail = (int)$row['id_bail'];

        /* ── AVANCEMENT DE LA CÉRÉMONIE ──────────────────────────────────────
           Si cette signature achève la vague 1, le mandataire est convoqué
           MAINTENANT — mail + SMS — sans que personne n'ait à surveiller l'écran.
           Best-effort : une panne de SMS ne doit jamais faire échouer la signature
           qui vient d'être recueillie ; l'agent garde la relance manuelle. */
        $vague2 = [];
        try {
            require_once __DIR__ . '/bail_ceremonie.php';
            $vague2 = bcer_avancer($pdo, $idBail);
        } catch (Throwable $e) { error_log('[bsig_sign vague2] ' . $e->getMessage()); }

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

        return ['ok' => true, 'all_signed' => $allSigned, 'signes' => (int)$c['signes'],
                'total' => (int)$c['total'], 'finalized' => $finalized,
                'mode' => $modeRetenu, 'vague2_ouverte' => $vague2];
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

        /* ⚠️🔥 « Bail commercial » ÉTAIT EN DUR ici — dans le nom GED, dans l objet
           du mail et dans son corps. Mesuré le 15/08/2026 : 469 baux d habitation
           contre 70 commerciaux. Quatre destinataires sur cinq recevaient donc un
           document intitulé « bail commercial signé » pour un logement. Le libellé
           vient désormais de la nature du bail, comme partout ailleurs. */
        $nomBail = 'Bail';
        try {
            require_once __DIR__ . '/bail_types_registry.php';
            $qN = $pdo->prepare("SELECT bail_nature, bail_regime, duree_mois FROM bien_baux WHERE id = ? LIMIT 1");
            $qN->execute([$bailId]);
            if ($rN = $qN->fetch(PDO::FETCH_ASSOC)) $nomBail = ucfirst((string)bt_libelle($rN));
        } catch (Throwable $e) { error_log('[bail_finalize libelle] ' . $e->getMessage()); }
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

        $combineHash = ''; $combineInfo = null;
        if ($docId <= 0) {
            // 1) PDF définitif (forceProjet = false → sans filigrane, tracés incrustés).
            $tmpPdf = bail_build_pdf_dispatch($pdo, $bailId, false);

            /* ── LE DOCUMENT SIGNÉ EST LE COMBINÉ ────────────────────────────
               Bail + annexes figées à l envoi + page de justificatifs, en UNE
               pièce. Des annexes qui ne voyageraient qu en pièces jointes du mail
               ne seraient couvertes par rien : le locataire pourrait soutenir n
               avoir jamais reçu le règlement de copropriété, et l empreinte ne
               prouverait que le bail.
               ⚠️ Best-effort intégral : si la fusion échoue, on classe le bail
               seul. Un bail signé non classé serait bien pire qu un bail classé
               sans sa page de preuve. */
            try {
                require_once __DIR__ . '/bail_justificatifs_pdf.php';
                $combineInfo = bail_combine_construire($pdo, $bailId, $tmpPdf);
                if (!empty($combineInfo['path']) && is_file($combineInfo['path'])) {
                    if ($combineInfo['path'] !== $tmpPdf) @unlink($tmpPdf);
                    $tmpPdf      = $combineInfo['path'];
                    $combineHash = (string)($combineInfo['hash'] ?? '');
                }
            } catch (Throwable $e) { error_log('[bail_finalize combine] ' . $e->getMessage()); }

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
                    'name_display'=>$nomBail.' signé — '.$refBail,
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

            /* L empreinte est portée par CHAQUE ligne de signature : c est ce qui
               permet, des années plus tard, de repartir d une signature et de
               retrouver exactement le document auquel elle se rapportait. */
            if ($docId > 0) {
                try {
                    $pdo->prepare("UPDATE bail_signatures SET doc_combine_id = ?, doc_combine_hash = ?
                                    WHERE id_bail = ? AND statut = 'signe'")
                        ->execute([$docId, $combineHash ?: null, $bailId]);
                } catch (Throwable $e) { error_log('[bail_finalize empreinte] ' . $e->getMessage()); }
            }
        }

        // 3) Envoi du bail signé aux signataires (+ agent créateur).
        $mailed = [];
        try {
            require_once __DIR__ . '/mailer.php';
            if (function_exists('send_mail')) {
                // Le PDF définitif en pièce jointe (régénéré proprement pour le mail).
                /* ⚠️ On joint LE COMBINÉ DÉJÀ CLASSÉ, pas une régénération. Une
                   régénération repartirait de la fiche : au premier caractère
                   modifié depuis, l empreinte du fichier envoyé ne correspondrait
                   plus à celle qu atteste la page de justificatifs — et le
                   destinataire recevrait un document que son propre certificat
                   dément. On relit donc le fichier posé en GED. */
                $attach = [];
                try {
                    /* Le chemin se demande au point de passage central : `ged_documents`
                       ne porte pas de colonne de chemin, et une lecture directe
                       contournerait la cage d'accès. */
                    require_once __DIR__ . '/ged_access.php';
                    $srcFinal = (string)(ged_internal_path($docId, 'mail') ?? '');
                    if ($srcFinal === '' || !is_file($srcFinal)) {
                        // Filet : la GED n a pas rendu de chemin exploitable.
                        $srcFinal = bail_build_pdf_dispatch($pdo, $bailId, false);
                    }
                    $clean = sys_get_temp_dir() . '/Bail_signe_' . preg_replace('/[^A-Za-z0-9_-]/','', $refBail) . '.pdf';
                    $attach = (@copy($srcFinal, $clean)) ? [$clean] : [$srcFinal];
                } catch (Throwable $e) { error_log('[bail_finalize piece jointe] ' . $e->getMessage()); }

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
                $subject = 'Votre ' . mb_strtolower($nomBail) . ' signé — ' . $refB;
                /* Le mail DIT ce que contient la pièce jointe. Sans cette phrase, le
                   destinataire qui découvre un PDF de quatre-vingts pages ne sait pas
                   que les annexes et la preuve y sont — et il redemande le règlement
                   de copropriété qu il a déjà entre les mains. */
                $nbAx  = is_array($combineInfo['annexes'] ?? null) ? count($combineInfo['annexes']) : 0;
                $compo = 'Cette pièce jointe réunit le ' . mb_strtolower($nomBail)
                       . ($nbAx > 0 ? ', ses ' . $nbAx . ' annexe' . ($nbAx > 1 ? 's' : '') : '')
                       . ' et la <strong>page de justificatifs</strong> qui atteste des signatures '
                       . '(horodatage, code SMS, empreinte du document).';
                $body =
                    '<p>Bonjour '.htmlspecialchars((string)$preneur).',</p>'.
                    '<p>Le <strong>'.htmlspecialchars(mb_strtolower($nomBail)).'</strong> concernant <strong>'.htmlspecialchars((string)$refB).'</strong> a été '.
                    '<strong>signé par l\'ensemble des parties</strong>. Vous en trouverez un exemplaire définitif en pièce jointe.</p>'.
                    '<p style="font-size:13px;color:#475569;">'.$compo.'</p>'.
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

if (!function_exists('bail_commit_projet_ged')) {
    /**
     * Génère le PROJET de bail (PDF filigrané) et le CLASSE en GED en VERSION incrémentée
     * (« Projet bail V2 », « V3 »…), lié au BAIL (main) + BIEN (reference). Appelé au moment de
     * l'ouverture de la cérémonie (« Envoyer pour signature »). Best-effort : renvoie le doc_id.
     */
    function bail_commit_projet_ged(PDO $pdo, int $bailId): int
    {
        if ($bailId <= 0) return 0;
        $st = $pdo->prepare("
            SELECT bb.numero_bail, bb.id_bien, bb.id_societe AS bail_soc, bb.id_agence AS bail_age,
                   b.reference_bien, b.id_societe AS bien_soc, b.id_agence AS bien_age,
                   s.raison_sociale AS soc_raison, a.code_agence, a.nom_agence
              FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien
              LEFT JOIN societes s ON s.id = COALESCE(b.id_societe, bb.id_societe)
              LEFT JOIN agences  a ON a.id = COALESCE(b.id_agence, bb.id_agence)
             WHERE bb.id = ? LIMIT 1");
        $st->execute([$bailId]);
        $bail = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bail) return 0;

        $socId  = (int)($bail['bien_soc'] ?? 0) ?: (int)($bail['bail_soc'] ?? 0) ?: null;
        $ageId  = (int)($bail['bien_age'] ?? 0) ?: (int)($bail['bail_age'] ?? 0) ?: null;
        $idBien = (int)$bail['id_bien'];
        $ref    = (string)($bail['numero_bail'] ?: ('bail_' . $bailId));
        $userId = function_exists('current_user_id') ? ((int)current_user_id() ?: null) : null;

        require_once __DIR__ . '/ged_document_links.php';
        require_once __DIR__ . '/bail_commercial_pdf.php';

        // Empreinte du CONTENU du bail (ce qui influe sur le PDF ; on exclut le volatile).
        $hash = '';
        try {
            $row = bail_commercial_bail_row($pdo, $bailId);
            if (is_array($row)) { foreach (['updated_at','created_at','sent_at','date_signature','date_envoi','statut'] as $k) unset($row[$k]); $hash = md5((string)json_encode($row)); }
        } catch (Throwable) {}

        // Dernière version active + son hash. Contenu INCHANGÉ → on RÉUTILISE (aucune nouvelle version,
        // pas de pollution GED). Contenu modifié → nouvelle version et les anciennes passent superseded.
        $lastId = 0; $lastHash = ''; $lastVer = 0; $activeIds = [];
        try {
            $q = $pdo->prepare("SELECT d.id, JSON_UNQUOTE(JSON_EXTRACT(d.metadata,'$.extra.content_hash')) AS h, JSON_EXTRACT(d.metadata,'$.extra.version') AS v
                                  FROM ged_documents d
                                  JOIN ged_document_links l ON l.document_id=d.id AND l.entity_type='BAIL' AND l.entity_id=?
                                 WHERE d.document_type='projet_bail' AND d.status='active' ORDER BY d.id DESC");
            $q->execute([$bailId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $i => $r) { $activeIds[] = (int)$r['id']; if ($i === 0) { $lastId=(int)$r['id']; $lastHash=(string)($r['h'] ?? ''); $lastVer=(int)($r['v'] ?? 0); } }
        } catch (Throwable) {}
        if ($lastId > 0 && $hash !== '' && $lastHash === $hash) return $lastId; // inchangé → réutilise

        $ver = $lastVer > 0 ? $lastVer + 1 : 1;
        if ($activeIds) { try { $pdo->exec("UPDATE ged_documents SET status='superseded' WHERE id IN (" . implode(',', array_map('intval', $activeIds)) . ")"); } catch (Throwable) {} }

        $tmp = bail_build_pdf_dispatch($pdo, $bailId, true); // projet filigrané
        $permDir = __DIR__ . '/../uploads/baux/'; if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
        $permName = 'bail_' . $bailId . '_projet_v' . $ver . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $permPath = $permDir . $permName;
        if (!@rename($tmp, $permPath)) { @copy($tmp, $permPath); @unlink($tmp); }

        $res = gus_commit_document($pdo,
            ['path_on_disk'=>$permPath, 'name_original'=>'Projet_bail_V' . $ver . '_' . $ref . '.pdf',
             'mime_type'=>'application/pdf', 'size_bytes'=>filesize($permPath) ?: 0, 'public_url'=>'/uploads/baux/' . $permName],
            [
                'document_type'=>'projet_bail', 'source_module'=>'03_GESTION_LOCATIVE', 'security_level'=>'interne',
                'societe_id'=>$socId, 'agence_id'=>$ageId, 'tenant_id'=>$socId, 'created_by'=>$userId, 'storage_provider'=>'local',
                'name_display'=>'Projet bail V' . $ver . ' — ' . $ref,
                'metadata_extra'=>['statut'=>'projet','version'=>$ver,'id_bail'=>$bailId,'doc_date'=>date('Y-m-d'),'content_hash'=>$hash],
                'naming_ctx'=>['societe_raison'=>$bail['soc_raison'] ?? '', 'agence_code'=>$bail['code_agence'] ?? '', 'agence_nom'=>$bail['nom_agence'] ?? '',
                    'user_id'=>$userId, 'n1_slug'=>'03_gestion_locative', 'type_doc'=>'projet_bail',
                    'entity_type'=>'BAIL', 'entity_id'=>$bailId, 'date_doc'=>date('Y-m-d'), 'source_filename'=>'Projet_bail.pdf'],
            ],
            [
                ['entity_type'=>'BAIL','entity_id'=>$bailId,'relation_type'=>'main'],
                ['entity_type'=>'BIEN','entity_id'=>$idBien,'relation_type'=>'reference'],
            ]
        );
        return (int)($res['doc_id'] ?? 0);
    }
}
