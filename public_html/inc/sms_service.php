<?php
declare(strict_types=1);
/**
 * inc/sms_service.php — Service SMS central de MBI.
 *
 *   Module métier  →  sms_envoyer()  →  provider (OVH)  →  opérateur
 *
 * Aucun module ne doit appeler OVH directement : tout passe par ici. Le service
 * normalise le numéro, applique les garde-fous, historise dans sms_envois, puis
 * délègue l'envoi au fournisseur configuré.
 *
 * ⚠️ CE SERVICE NE FAIT PAS DE CONTRÔLE DE DROITS. C'est volontaire : il est appelé
 * aussi bien par un écran (où l'ACL est déjà faite) que par un cron (où il n'y a
 * pas d'utilisateur). Tout appelant exposé au web DOIT vérifier le rôle et le CSRF
 * avant d'appeler sms_envoyer().
 *
 * Table : inc/migrations/20260815b_sms_envois.php
 */

require_once __DIR__ . '/sms_config.php';
require_once __DIR__ . '/sms_provider_ovh.php';

/** Typologie extensible. Le type ne change rien à l'envoi : il sert au filtrage,
 *  aux statistiques et aux futures automatisations. */
if (!defined('SMS_TYPES')) {
    define('SMS_TYPES', ['LIBRE','RENDEZ_VOUS','RELANCE','INFORMATION','SIGNATURE',
                         'DOCUMENT','SINISTRE','IMPAYE','AG','INTERVENTION','OTP']);
}

if (!function_exists('sms_numero_suspect')) {
    /**
     * CE NUMÉRO A-T-IL L'AIR INVENTÉ ? — retourne la raison, ou '' si rien à signaler.
     *
     * ⚠️🔥 Pourquoi. `sms_normaliser_numero()` ne vérifie que la FORME : 06/07 + 8 chiffres.
     * « 0600000000 », « 0612345678 » et « 0611223344 » la passent tous les trois. Constaté
     * le 23/08/2026 dans le journal des communications : un SMS de signature parti sur
     * 0600000000 — accepté par l'opérateur, facturé, affiché « envoyé »… et reçu par
     * personne. Le signataire n'a rien, et l'écran dit que tout va bien.
     *
     * ⚠️ SIGNALEMENT, JAMAIS BLOCAGE (décision d'Emmanuel, 23/08). Un vrai numéro peut
     * théoriquement ressembler à ça, et la règle de la maison est « rien ne bloque, mais
     * rien ne se tait ». On nomme le doute, l'agent tranche.
     *
     * ⚠️ On ne corrige rien non plus : ces numéros viennent des FICHES TIERS, et une
     * réécriture automatique sur la table source serait pire que le mal.
     *
     * Fonction PURE : pas d'accès base, testable seule. Elle attend le numéro NATIONAL
     * (0XXXXXXXXX) tel que rendu par `sms_normaliser_numero()`.
     */
    function sms_numero_suspect(?string $national): string
    {
        $n = preg_replace('/\D+/', '', (string)$national) ?? '';
        if (strlen($n) !== 10) return '';
        $d = substr($n, 2);                       // les 8 chiffres qui suivent 06 / 07

        if (preg_match('/^(\d)\1{7}$/', $d)) return 'les 8 chiffres sont identiques';

        // Suite strictement croissante ou décroissante : 12345678 / 87654321.
        $croit = true; $decroit = true;
        for ($i = 1; $i < 8; $i++) {
            if ((int)$d[$i] !== (int)$d[$i - 1] + 1) $croit = false;
            if ((int)$d[$i] !== (int)$d[$i - 1] - 1) $decroit = false;
        }
        if ($croit || $decroit) return 'les chiffres se suivent';

        // Bloc de deux chiffres répété quatre fois : 12121212.
        if (substr($d, 0, 2) === substr($d, 2, 2)
            && substr($d, 0, 2) === substr($d, 4, 2)
            && substr($d, 0, 2) === substr($d, 6, 2)) return 'le même bloc est répété';

        /* Paires doublées ET qui se suivent : 11223344. Les deux conditions ensemble —
           « 11224455 » resterait plausible, « 11223344 » ne l'est pas. */
        if ($d[0] === $d[1] && $d[2] === $d[3] && $d[4] === $d[5] && $d[6] === $d[7]) {
            $p = [(int)$d[0], (int)$d[2], (int)$d[4], (int)$d[6]];
            $suite = true;
            for ($i = 1; $i < 4; $i++) { if ($p[$i] !== $p[$i - 1] + 1) $suite = false; }
            if ($suite) return 'les chiffres vont par paires qui se suivent';
        }
        return '';
    }
}

if (!function_exists('sms_normaliser_numero')) {
    /**
     * Normalise un numéro français vers le format international attendu par OVH
     * (0033XXXXXXXXX), et refuse tout ce qui n'est pas un mobile français plausible.
     *
     * Accepte : 06XXXXXXXX, 07XXXXXXXX, +336…, +337…, 00336…, 0033 7…, avec
     * espaces, points, tirets ou barres obliques.
     *
     * Volontairement STRICT et distinct de em_normalize_phone() (inc/entity_matcher.php),
     * qui ne garde que les 9 derniers chiffres : c'est une clé de rapprochement, pas
     * un numéro composable. On n'envoie pas un SMS à une clé de rapprochement.
     *
     * @return array{ok:bool, numero:?string, national:?string, error:?string}
     */
    function sms_normaliser_numero(string $saisi): array
    {
        $ko = static fn(string $m): array => ['ok'=>false,'numero'=>null,'national'=>null,'error'=>$m];

        $s = trim($saisi);
        if ($s === '') return $ko('Numéro vide.');

        // On ne garde que les chiffres, en mémorisant un éventuel « + » initial.
        $plus = str_starts_with($s, '+');
        $d = preg_replace('/\D+/', '', $s) ?? '';
        if ($d === '') return $ko('Numéro sans aucun chiffre.');

        // Ramener toutes les écritures à la forme nationale 0XXXXXXXXX.
        if ($plus && str_starts_with($d, '33'))      $national = '0' . substr($d, 2);
        elseif (str_starts_with($d, '0033'))         $national = '0' . substr($d, 4);
        elseif (str_starts_with($d, '33') && strlen($d) === 11) $national = '0' . substr($d, 2);
        elseif (strlen($d) === 9 && $d[0] !== '0')   $national = '0' . $d;   // 6XXXXXXXX
        else                                          $national = $d;

        if (strlen($national) !== 10) {
            return $ko('Numéro invalide : ' . strlen($national) . ' chiffres au lieu de 10.');
        }
        // Un SMS ne se reçoit que sur un mobile : 06 ou 07 uniquement.
        if (!preg_match('/^0[67]\d{8}$/', $national)) {
            return $ko('Ce numéro n\'est pas un mobile français (06 ou 07 attendu).');
        }

        return ['ok'=>true, 'numero'=>'0033' . substr($national, 1), 'national'=>$national, 'error'=>null];
    }
}

if (!function_exists('sms_gsm7_compatible')) {
    /**
     * Le message tient-il dans l'alphabet GSM 03.38 ? Sinon il part en Unicode,
     * et la longueur utile chute de 160 à 70 caractères par segment — d'où le
     * calcul de segments qui suit.
     */
    function sms_gsm7_compatible(string $message): bool
    {
        $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
             . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà"
             . "^{}\\[~]|€";
        $len = mb_strlen($message, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            if (mb_strpos($gsm, mb_substr($message, $i, 1, 'UTF-8'), 0, 'UTF-8') === false) return false;
        }
        return true;
    }
}

if (!function_exists('sms_compter_segments')) {
    /** Nombre de SMS facturés par OVH pour ce message. */
    function sms_compter_segments(string $message): array
    {
        $gsm7 = sms_gsm7_compatible($message);
        $len  = mb_strlen($message, 'UTF-8');
        $simple = $gsm7 ? 160 : 70;      // message unique
        $multi  = $gsm7 ? 153 : 67;      // segments concaténés
        $seg = $len <= $simple ? 1 : (int)ceil($len / $multi);
        return ['segments' => max(1, $seg), 'coding' => $gsm7 ? 1 : 2, 'longueur' => $len];
    }
}

if (!function_exists('comm_log_sms')) {
    /**
     * Passerelle SMS vers le journal métier — pendant exact de `comm_log_mail()`.
     * Isolée pour la même raison : l'absence du module de journal ne doit pas
     * empêcher un SMS de partir.
     *
     * ⚠️ On journalise `$messageJournal`, c'est-à-dire le message DÉJÀ MASQUÉ par
     * `sms_envoyer()` quand le type est OTP — jamais le message parti. Un code
     * lisible dans un journal consultable annulerait le hachage de `sms_otp`.
     */
    function comm_log_sms(string $numero, string $messageJournal, string $type,
                          string $statut, ?string $erreur, ?string $objetType,
                          ?int $objetId, int $idSociete, ?int $idTiers,
                          string $refTechnique, string $sender, array $journal = []): void
    {
        /* ── LE JOURNAL NE CLASSE PAS COMME LA TECHNIQUE ─────────────────────────
           ⚠️🔥 `sms_envois` doit pointer l'objet TECHNIQUE — la ligne de signature —
           parce que c'est par là que `bcer_suivi()` retrouve le SMS d'un signataire.
           Mais le JOURNAL métier, lui, doit classer sous le dossier que l'utilisateur
           a en tête : le BAIL. Sans cette distinction, les SMS d'une cérémonie
           tombaient sous « BAIL_SIGNATURE #18 » et les mails sous « BAIL #660 » :
           filtrer sur le bail ne montrait que la moitié de la conversation, ce que
           le journal unifié est précisément censé éviter. Constaté le 23/08/2026.
           `journal[objet_*]` l'emporte donc ici, et seulement ici. */
        if (!empty($journal['objet_type'])) {
            $objetType = (string)$journal['objet_type'];
            $objetId   = (int)($journal['objet_id'] ?? 0) ?: null;
        }
        try {
            $f = __DIR__ . '/communications.php';
            if (!is_file($f)) return;
            require_once $f;
            if (!function_exists('comm_log')) return;
            comm_log([
                'canal'         => 'sms',
                'destinataire'  => $numero,
                'sujet'         => 'SMS ' . $type,
                'corps'         => $messageJournal,
                'statut'        => $statut,
                'error_message' => (string)$erreur,
                'objet_type'    => $objetType,
                'objet_id'      => $objetId,
                'id_societe'    => $idSociete,
                'id_tiers'      => $idTiers,
                'ref_technique' => 'sms_envois#' . $refTechnique,
                /* ⚠️ « — automate » n'est pas la vérité. L'écran affiche ce libellé dès
                   qu'il n'y a pas d'utilisateur en session — or le code SMS part de la
                   PAGE PUBLIQUE de signature : personne n'est connecté, mais ce n'est pas
                   un robot pour autant, c'est le signataire qui vient de le demander.
                   L'appelant peut donc nommer l'origine ; à défaut, le sender OVH. */
                'expediteur'    => trim((string)($journal['expediteur'] ?? '')) ?: $sender,
                // Un numéro seul n'apprend rien : on journalise QUI on a joint.
                'destinataire_nom' => (string)($journal['destinataire_nom'] ?? ''),
            ]);
        } catch (Throwable $e) { error_log('[comm_log_sms] ' . $e->getMessage()); }
    }
}

if (!function_exists('sms_envoyer')) {
    /**
     * Point d'entrée unique pour envoyer un SMS depuis MBI.
     *
     * @param array $opts  type, id_societe, id_user, id_tiers, objet_type, objet_id,
     *                     cle_idempotence, tag, dry_run (bool : tout valider et
     *                     historiser SANS appeler l'opérateur).
     * @return array{ok:bool, id:?int, statut:string, telephone:?string,
     *               provider_message_id:?string, segments:int, error:?string}
     */
    function sms_envoyer(PDO $pdo, string $telephone, string $message, array $opts = []): array
    {
        $type       = strtoupper(trim((string)($opts['type'] ?? 'LIBRE')));
        if (!in_array($type, SMS_TYPES, true)) $type = 'LIBRE';
        $idSociete  = (int)($opts['id_societe'] ?? 0);
        $idUser     = isset($opts['id_user'])  ? (int)$opts['id_user']  : null;
        $idTiers    = isset($opts['id_tiers']) ? (int)$opts['id_tiers'] : null;
        $objetType  = trim((string)($opts['objet_type'] ?? '')) ?: null;
        $objetId    = isset($opts['objet_id']) ? (int)$opts['objet_id'] : null;
        /* Ce qui ne concerne QUE le journal métier : le dossier sous lequel classer
           l'échange (souvent le BAIL, alors que la trace technique pointe la ligne de
           signature), le nom du destinataire, et l'origine de l'envoi. */
        $journalMeta = [
            'objet_type'       => trim((string)($opts['journal_objet_type'] ?? '')) ?: null,
            'objet_id'         => isset($opts['journal_objet_id']) ? (int)$opts['journal_objet_id'] : null,
            'destinataire_nom' => trim((string)($opts['destinataire_nom'] ?? '')),
            'expediteur'       => trim((string)($opts['journal_expediteur'] ?? '')),
        ];
        $dryRun     = !empty($opts['dry_run']);

        $message = trim($message);
        /* ⚠️🔥 UN REFUS AUSSI SE JOURNALISE. Les refus d'AMONT (société manquante,
           configuration absente, numéro invalide) sortent AVANT l'INSERT dans
           `sms_envois` : aucune trace technique n'existe pour eux. C'est très
           exactement ce qui a rendu la panne du 20/08 indéchiffrable — la table
           était vide, et « vide » ne distinguait pas « rien tenté » de « refusé ».
           Le journal MÉTIER, lui, garde la tentative ET sa raison. */
        $ko = static function (string $err, string $statut = 'refuse') use ($telephone, $type, $objetType, $objetId, $idSociete): array {
            try {
                $f = __DIR__ . '/communications.php';
                if (is_file($f)) {
                    require_once $f;
                    if (function_exists('comm_log')) {
                        comm_log([
                            'canal' => 'sms', 'destinataire' => $telephone,
                            'sujet' => 'SMS ' . $type, 'statut' => 'echec',
                            'error_message' => $err,
                            'objet_type' => $objetType, 'objet_id' => $objetId,
                            'id_societe' => $idSociete,
                        ]);
                    }
                }
            } catch (Throwable $e) { error_log('[sms ko journal] ' . $e->getMessage()); }
            return [
                'ok'=>false,'id'=>null,'statut'=>$statut,'telephone'=>null,
                'provider_message_id'=>null,'segments'=>0,'error'=>$err,
            ];
        };

        if ($message === '')      return $ko('Message vide.');
        if ($idSociete <= 0)      return $ko('Société requise : un SMS est toujours émis au nom d\'une société.');
        // 8 segments ≈ 1200 caractères : au-delà, c'est une erreur d'appel, pas un SMS.
        $m = sms_compter_segments($message);
        if ($m['segments'] > 8)   return $ko('Message trop long (' . $m['segments'] . ' segments, 8 maximum).');

        $num = sms_normaliser_numero($telephone);
        if (!$num['ok'])          return $ko($num['error'] ?? 'Numéro invalide.');

        $conf = sms_config();
        if (!sms_config_prete())  return $ko('Configuration SMS absente ou incomplète sur cet environnement.');
        if (empty($conf['actif'])) return $ko('Envoi de SMS désactivé dans la configuration.');

        // ── Garde-fou anti-boucle ────────────────────────────────────────────
        // Un bug de boucle peut vider le crédit en quelques secondes. On plafonne
        // par société et par heure glissante, AVANT tout appel à l'opérateur.
        $quota = (int)$conf['quota_horaire'];
        if ($quota > 0) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM sms_envois
                                 WHERE id_societe = ? AND statut = 'envoye'
                                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $q->execute([$idSociete]);
            if ((int)$q->fetchColumn() >= $quota) {
                return $ko('Quota de sécurité atteint (' . $quota . ' SMS sur la dernière heure). Envoi bloqué.');
            }
        }

        // ── Anti double-clic ─────────────────────────────────────────────────
        // Même société + même numéro + même message dans la minute = un seul envoi.
        // L'unicité est portée par un index en base, donc elle résiste aussi à deux
        // requêtes simultanées, ce qu'un simple SELECT préalable ne ferait pas.
        $cle = trim((string)($opts['cle_idempotence'] ?? ''));
        if ($cle === '') {
            $cle = sha1($idSociete . '|' . $num['numero'] . '|' . $message . '|' . floor(time() / 60));
        }

        /* ⚠️🔥 UN CODE OTP NE S'ÉCRIT PAS DANS UN JOURNAL.
           Le message PARTI contient le code — c'est son objet. Le message
           HISTORISÉ, non : ce journal est consultable, il alimentera demain le
           journal de communication unifié, et un code lisible permet de signer à
           la place du client. Cela annulerait le hachage de `sms_otp`, qui ne sert
           qu'à ça.
           On garde la trace de l'envoi, du numéro, de l'heure et du coût — tout
           sauf le secret. */
        $messageJournal = $message;
        if ($type === 'OTP') {
            $messageJournal = preg_replace('/\b\d{4,8}\b/', '••••••', $message);
        }

        $ins = $pdo->prepare("INSERT INTO sms_envois
            (id_societe, id_user, id_tiers, telephone, telephone_saisi, message, type,
             provider, provider_service, sender, statut, nb_segments, coding,
             objet_type, objet_id, cle_idempotence, ip, created_at)
            VALUES (?,?,?,?,?,?,?, 'ovh', ?, ?, 'en_attente', ?, ?, ?, ?, ?, ?, NOW())");
        try {
            $ins->execute([
                $idSociete, $idUser, $idTiers, $num['numero'], mb_substr($telephone, 0, 30),
                $messageJournal, $type, (string)$conf['service_name'], (string)$conf['sender'],
                $m['segments'], $m['coding'], $objetType, $objetId, $cle,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $ko('SMS identique déjà envoyé il y a moins d\'une minute — envoi ignoré.', 'doublon');
            }
            throw $e;
        }
        $idEnvoi = (int)$pdo->lastInsertId();

        // ── Simulation : tout est validé et tracé, rien n'est envoyé ni facturé ──
        if ($dryRun) {
            $pdo->prepare("UPDATE sms_envois SET statut='simule' WHERE id=?")->execute([$idEnvoi]);
            return ['ok'=>true,'id'=>$idEnvoi,'statut'=>'simule','telephone'=>$num['numero'],
                    'provider_message_id'=>null,'segments'=>$m['segments'],'error'=>null];
        }

        $rep = ovh_sms_envoyer($num['numero'], $message, $conf, [
            'coding'            => $m['coding'],
            'sans_mention_stop' => (bool)$conf['sans_mention_stop'],
            'tag'               => (string)($opts['tag'] ?? $type),
        ]);

        if (!empty($rep['ok'])) {
            $pdo->prepare("UPDATE sms_envois
                              SET statut='envoye', provider_message_id=?, credit_restant=?, sent_at=NOW()
                            WHERE id=?")
                ->execute([$rep['provider_message_id'], $rep['credit_restant'], $idEnvoi]);
            comm_log_sms($num['numero'], $messageJournal, $type, 'envoye', null, $objetType, $objetId, $idSociete, $idTiers, (string)$idEnvoi, (string)$conf['sender'], $journalMeta);
            return ['ok'=>true,'id'=>$idEnvoi,'statut'=>'envoye','telephone'=>$num['numero'],
                    'provider_message_id'=>$rep['provider_message_id'],'segments'=>$m['segments'],'error'=>null];
        }

        $pdo->prepare("UPDATE sms_envois SET statut='echec', error_code=?, error_message=? WHERE id=?")
            ->execute([$rep['error_code'], mb_substr((string)$rep['error_message'], 0, 255), $idEnvoi]);
        comm_log_sms($num['numero'], $messageJournal, $type, 'echec',
                     (string)($rep['error_message'] ?? ''), $objetType, $objetId,
                     $idSociete, $idTiers, (string)$idEnvoi, (string)$conf['sender'], $journalMeta);
        return ['ok'=>false,'id'=>$idEnvoi,'statut'=>'echec','telephone'=>$num['numero'],
                'provider_message_id'=>null,'segments'=>$m['segments'],
                'error'=>(string)($rep['error_message'] ?? 'Échec de l\'envoi.')];
    }
}
