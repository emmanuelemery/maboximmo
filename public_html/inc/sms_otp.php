<?php
declare(strict_types=1);
/**
 * inc/sms_otp.php — Le code à usage unique envoyé par SMS.
 *
 * Bâti SUR le socle SMS (inc/sms_service.php) : cette couche ne parle jamais à
 * l'opérateur, elle décide quand émettre un code, comment le vérifier, et quand
 * refuser. Le transport, le quota et le journal restent l'affaire du service.
 *
 * ── Ce que le code prouve ────────────────────────────────────────────────────
 * Notre signature électronique repose sur un faisceau : un lien nominatif envoyé
 * à une adresse connue, un horodatage, une adresse IP, l'empreinte du document.
 * Le code SMS ajoute la pièce manquante — **le signataire détient le téléphone
 * que nous avons enregistré pour lui**. C'est ce qui rend défendable un constat de
 * signature EN PRÉSENTIEL : on signe devant le commercial, mais le code arrive sur
 * le téléphone du client, pas sur celui de l'agence.
 *
 * ── Ce qu'on ne fait jamais ──────────────────────────────────────────────────
 *   · stocker le code en clair — un journal lisible permettrait de signer à la
 *     place d'un client ;
 *   · laisser les tentatives ouvertes — six chiffres, c'est un million de
 *     combinaisons, essayées en quelques minutes sans plafond ;
 *   · accepter deux fois le même code — rejouer un code validé rejouerait la preuve.
 */
require_once __DIR__ . '/sms_service.php';

if (!defined('OTP_VALIDITE_MIN'))    define('OTP_VALIDITE_MIN', 10);   // minutes
if (!defined('OTP_MAX_TENTATIVES'))  define('OTP_MAX_TENTATIVES', 5);
if (!defined('OTP_RENVOI_DELAI_S'))  define('OTP_RENVOI_DELAI_S', 60); // anti-martelage

if (!function_exists('otp_emettre')) {
    /**
     * Émet un code et l'envoie par SMS. Tout code antérieur encore vivant sur le
     * même objet est annulé : deux codes valables en même temps, c'est un code de
     * trop, et l'utilisateur ne sait plus lequel taper.
     *
     * @param array $opts telephone (obligatoire), id_societe, libelle (nom de
     *                    l'acte, repris dans le message), dry_run
     * @return array{ok:bool, id:?int, expire_at:?string, error:?string, attendre:?int}
     */
    function otp_emettre(PDO $pdo, string $objetType, int $objetId, array $opts = []): array
    {
        $tel = trim((string)($opts['telephone'] ?? ''));
        if ($objetType === '' || $objetId <= 0) return ['ok'=>false,'error'=>'Objet à protéger non identifié.'];
        if ($tel === '')                        return ['ok'=>false,'error'=>"Aucun numéro de téléphone pour ce signataire."];

        /* ⚠️ La clé est `numero` (format 0033…), pas `e164` : une clé fautive
           aurait donné une chaîne vide, donc un code envoyé nulle part — et
           l'utilisateur attendant un SMS qui n'arrive jamais. */
        $num = sms_normaliser_numero($tel);
        if (empty($num['ok'])) return ['ok'=>false,'error'=>$num['error'] ?? 'Numéro invalide.'];
        $tel = (string)$num['numero'];

        /* Anti-martelage : un bouton « renvoyer » cliqué dix fois coûte dix SMS et
           inonde le client. On refuse poliment en disant combien de temps attendre. */
        try {
            $st = $pdo->prepare("SELECT created_at FROM sms_otp
                                  WHERE objet_type=? AND objet_id=? AND annule_at IS NULL AND consomme_at IS NULL
                               ORDER BY id DESC LIMIT 1");
            $st->execute([$objetType, $objetId]);
            $dernier = (string)$st->fetchColumn();
            if ($dernier !== '') {
                $ecoule = time() - strtotime($dernier);
                if ($ecoule < OTP_RENVOI_DELAI_S) {
                    return ['ok'=>false, 'error'=>'Un code vient d\'être envoyé.',
                            'attendre'=>OTP_RENVOI_DELAI_S - $ecoule];
                }
            }
        } catch (Throwable $e) { error_log('[otp_emettre lecture] ' . $e->getMessage()); }

        // Un seul code vivant à la fois.
        try {
            $pdo->prepare("UPDATE sms_otp SET annule_at = NOW()
                            WHERE objet_type=? AND objet_id=? AND annule_at IS NULL AND consomme_at IS NULL")
                ->execute([$objetType, $objetId]);
        } catch (Throwable $e) { error_log('[otp_emettre annulation] ' . $e->getMessage()); }

        // random_int : générateur cryptographique. mt_rand serait prédictible.
        $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $libelle = trim((string)($opts['libelle'] ?? '')) ?: 'votre signature';
        $message = 'Votre code de signature : ' . $code . '. Valable '
                 . OTP_VALIDITE_MIN . ' minutes. Ne le communiquez a personne.';

        $sms = sms_envoyer($pdo, $tel, $message, [
            'type'       => 'OTP',
            'id_societe' => (int)($opts['id_societe'] ?? 0),
            'objet_type' => $objetType,
            'objet_id'   => $objetId,
            'dry_run'    => !empty($opts['dry_run']),
            /* Ce qui ne sert qu'au journal métier, transmis par l'appelant : sous quel
               dossier classer l'échange, qui a été joint, et à la demande de qui.
               ⚠️ Le code part de la page PUBLIQUE de signature : aucun utilisateur en
               session, donc l'écran affichait « — automate ». Ce n'était pas un robot,
               c'était le signataire qui venait de demander son code. */
            'journal_objet_type' => (string)($opts['journal_objet_type'] ?? ''),
            'journal_objet_id'   => (int)($opts['journal_objet_id'] ?? 0),
            'destinataire_nom'   => (string)($opts['destinataire_nom'] ?? ''),
            'journal_expediteur' => (string)($opts['journal_expediteur'] ?? ''),
        ]);
        if (empty($sms['ok'])) {
            return ['ok'=>false, 'error'=>$sms['error'] ?? "L'envoi du SMS a échoué."];
        }

        $expire = date('Y-m-d H:i:s', time() + OTP_VALIDITE_MIN * 60);
        try {
            $pdo->prepare("INSERT INTO sms_otp
                    (objet_type, objet_id, telephone, code_hash, max_tentatives, expire_at,
                     id_sms, id_societe, ip_emission)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$objetType, $objetId, $tel, password_hash($code, PASSWORD_DEFAULT),
                           OTP_MAX_TENTATIVES, $expire, $sms['id'] ?? null,
                           (int)($opts['id_societe'] ?? 0) ?: null,
                           substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {
            error_log('[otp_emettre insert] ' . $e->getMessage());
            return ['ok'=>false, 'error'=>"Le code n'a pas pu être enregistré."];
        }

        $out = ['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'expire_at'=>$expire,
                'telephone_masque'=>otp_masquer_numero($tel), 'error'=>null];
        /* En SIMULATION uniquement, on renvoie le code : aucun SMS n'est parti,
           l'appelant doit pouvoir vérifier la chaîne. Hors simulation le code
           n'existe nulle part ailleurs que dans le SMS reçu — c'est le principe.
           ⚠️ Ne JAMAIS relayer cette clé dans une réponse HTTP. */
        if (!empty($opts['dry_run'])) $out['code_simule'] = $code;
        return $out;
    }
}

if (!function_exists('otp_verifier')) {
    /**
     * Vérifie un code. Consomme le défi en cas de succès, incrémente les
     * tentatives sinon — et l'annule quand le plafond est atteint.
     *
     * Les messages d'erreur sont VOLONTAIREMENT peu bavards sur la cause : dire
     * « code expiré » plutôt que « code faux » renseigne un attaquant sur l'état
     * du défi. On distingue seulement ce que l'utilisateur légitime doit savoir
     * pour agir : redemander un code, ou en retaper un.
     */
    function otp_verifier(PDO $pdo, string $objetType, int $objetId, string $code): array
    {
        $code = preg_replace('/\D/', '', trim($code));
        if ($code === '') return ['ok'=>false, 'error'=>'Saisissez le code reçu par SMS.'];

        try {
            $st = $pdo->prepare("SELECT * FROM sms_otp
                                  WHERE objet_type=? AND objet_id=? AND annule_at IS NULL AND consomme_at IS NULL
                               ORDER BY id DESC LIMIT 1");
            $st->execute([$objetType, $objetId]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[otp_verifier] ' . $e->getMessage());
            return ['ok'=>false, 'error'=>'Vérification impossible pour le moment.'];
        }

        if (!$d) return ['ok'=>false, 'error'=>"Aucun code en cours. Demandez-en un nouveau.", 'renvoyer'=>true];

        if (strtotime((string)$d['expire_at']) < time()) {
            $pdo->prepare("UPDATE sms_otp SET annule_at=NOW() WHERE id=?")->execute([(int)$d['id']]);
            return ['ok'=>false, 'error'=>'Ce code a expiré. Demandez-en un nouveau.', 'renvoyer'=>true];
        }

        if (!password_verify($code, (string)$d['code_hash'])) {
            $n = (int)$d['tentatives'] + 1;
            $max = (int)$d['max_tentatives'];
            if ($n >= $max) {
                $pdo->prepare("UPDATE sms_otp SET tentatives=?, annule_at=NOW() WHERE id=?")->execute([$n, (int)$d['id']]);
                return ['ok'=>false, 'error'=>'Trop de tentatives. Demandez un nouveau code.', 'renvoyer'=>true];
            }
            $pdo->prepare("UPDATE sms_otp SET tentatives=? WHERE id=?")->execute([$n, (int)$d['id']]);
            return ['ok'=>false, 'error'=>'Code incorrect. Il vous reste ' . ($max - $n) . ' essai'
                                        . (($max - $n) > 1 ? 's' : '') . '.', 'restant'=>$max - $n];
        }

        $pdo->prepare("UPDATE sms_otp SET consomme_at=NOW(), ip_validation=? WHERE id=?")
            ->execute([substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), (int)$d['id']]);
        return ['ok'=>true, 'id'=>(int)$d['id'], 'telephone'=>(string)$d['telephone'], 'error'=>null];
    }
}

if (!function_exists('otp_masquer_numero')) {
    /**
     * « +33 6 •• •• •• 34 » — assez pour que le signataire reconnaisse SON
     * téléphone, pas assez pour qu'un tiers l'apprenne en regardant l'écran.
     */
    function otp_masquer_numero(string $numero): string
    {
        $n = preg_replace('/\D/', '', $numero);
        if ($n === null || strlen($n) < 4) return '••';

        /* ⚠️🔥 CETTE FONCTION REÇOIT LES DEUX FORMES.
           `sms_otp.telephone` est normalisé (« 0033661671516 »), mais
           `bail_signatures.destinataire_tel` porte ce que l'agent a SAISI — la
           forme nationale « 0661671516 ». Sans le cas national, la page de
           signature affichait « +06 6 •• •• •• 16 » : un numéro que personne ne
           reconnaît comme le sien, sur l'écran même où on lui demande de faire
           confiance au procédé. On ramène donc tout à l'international AVANT de
           masquer, au lieu de supposer une forme d'entrée. */
        if (str_starts_with($n, '00'))      $n = substr($n, 2);            // 0033… → 33…
        elseif (str_starts_with($n, '0'))   $n = '33' . substr($n, 1);     // 06…   → 336…

        // 33 6 61 67 15 16 → +33 6 •• •• •• 16
        if (strlen($n) >= 5) {
            return '+' . substr($n, 0, 2) . ' ' . substr($n, 2, 1) . ' •• •• •• ' . substr($n, -2);
        }
        return '•• ' . substr($n, -2);
    }
}

if (!function_exists('otp_en_cours')) {
    /** Un code vivant existe-t-il ? Pour rouvrir l'écran de saisie sans renvoyer. */
    function otp_en_cours(PDO $pdo, string $objetType, int $objetId): ?array
    {
        try {
            $st = $pdo->prepare("SELECT id, telephone, expire_at, tentatives, max_tentatives
                                   FROM sms_otp
                                  WHERE objet_type=? AND objet_id=? AND annule_at IS NULL
                                    AND consomme_at IS NULL AND expire_at > NOW()
                               ORDER BY id DESC LIMIT 1");
            $st->execute([$objetType, $objetId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $r['telephone_masque'] = otp_masquer_numero((string)$r['telephone']);
            unset($r['telephone']);
            return $r;
        } catch (Throwable $e) { return null; }
    }
}
