<?php
declare(strict_types=1);
/**
 * inc/sms_provider_ovh.php — Fournisseur OVHcloud SMS (HTTP2SMS).
 *
 * SEUL fichier de MBI qui connaît OVH. Les modules métier appellent sms_envoyer()
 * dans inc/sms_service.php et ignorent tout du fournisseur : changer d'opérateur
 * demain se limite à écrire un sms_provider_xxx.php et à le brancher dans le service.
 *
 * ── Pourquoi HTTP2SMS et pas l'API REST api.ovh.com ────────────────────────────
 * OVH expose deux voies. L'API REST (POST /sms/{serviceName}/jobs) s'authentifie
 * avec un triplet application key / application secret / consumer key signé. Notre
 * compte est configuré avec un UTILISATEUR SMS (login + mot de passe), et c'est
 * HTTP2SMS qui consomme ce couple. Les deux ne sont pas interchangeables : la clé
 * d'API ne fonctionnerait pas ici, et l'utilisateur SMS ne signerait pas là-bas.
 * Référence : docs.ovhcloud.com → /fr/guides/web-cloud/messaging/sms/send-sms-http2sms
 *
 * ⚠️ SÉCURITÉ — HTTP2SMS est un GET : le mot de passe voyage dans la query string.
 * L'URL complète ne doit JAMAIS être journalisée. ovh_sms_url_masquee() existe pour
 * ça et c'est la seule forme autorisée dans un log ou un message d'erreur.
 */

if (!function_exists('ovh_sms_url_masquee')) {
    /** Rend une URL HTTP2SMS journalisable en retirant le mot de passe. */
    function ovh_sms_url_masquee(string $url): string
    {
        return (string)preg_replace('/(password=)[^&]*/i', '$1***MASQUE***', $url);
    }
}

if (!function_exists('ovh_sms_envoyer')) {
    /**
     * Envoie un SMS via HTTP2SMS.
     *
     * @param string $telephone Numéro déjà normalisé au format international OVH (0033XXXXXXXXX).
     * @param array  $conf      Configuration issue de sms_config().
     * @param array  $opts      ['sans_mention_stop'=>bool, 'coding'=>1|2, 'tag'=>string]
     * @return array{ok:bool, provider_message_id:?string, credit_restant:?string,
     *               error_code:?string, error_message:?string, http:int}
     */
    function ovh_sms_envoyer(string $telephone, string $message, array $conf, array $opts = []): array
    {
        $echec = static fn(string $code, string $msg, int $http = 0): array => [
            'ok' => false, 'provider_message_id' => null, 'credit_restant' => null,
            'error_code' => $code, 'error_message' => $msg, 'http' => $http,
        ];

        $params = [
            'account'     => (string)$conf['service_name'],
            'login'       => (string)$conf['api_user'],
            'password'    => (string)$conf['api_password'],
            'to'          => $telephone,
            'message'     => $message,
            'contentType' => 'application/json',
            'smsCoding'   => (string)(int)($opts['coding'] ?? 1),
        ];

        /* ⚠️🔥 SANS `from`, OVH REFUSE L'ENVOI (« status=201 Missing from »).
           Le commentaire précédent affirmait qu'OVH basculait sur un numéro court :
           c'est faux, mesuré le 18/08/2026. On continue d'omettre le paramètre
           quand la configuration est vide — l'envoi échouera, mais avec le message
           d'OVH, qui NOMME la cause. Envoyer un `from` vide donnerait une erreur
           moins parlante, et la panne se diagnostiquerait moins vite. */
        if (($conf['sender'] ?? '') !== '') $params['from'] = (string)$conf['sender'];

        // Mention STOP : obligatoire en prospection commerciale, facultative pour
        // le transactionnel. Le choix est porté par la configuration, pas par le code.
        if (!empty($opts['sans_mention_stop'])) $params['noStop'] = '1';

        // Tag OVH : 20 caractères maxi côté opérateur, on tronque pour ne pas
        // faire échouer un envoi à cause d'un libellé trop long.
        if (!empty($opts['tag'])) $params['tag'] = mb_substr((string)$opts['tag'], 0, 20);

        $url = 'https://www.ovh.com/cgi-bin/sms/http2sms.cgi?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $brut  = curl_exec($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $errTx = curl_error($ch);
        curl_close($ch);

        if ($errNo !== 0 || $brut === false) {
            // On journalise l'URL MASQUÉE : le mot de passe ne doit jamais toucher un log.
            error_log('[ovh_sms] curl ' . $errNo . ' — ' . $errTx . ' — ' . ovh_sms_url_masquee($url));
            return $echec('reseau', 'Contact impossible avec OVH : ' . $errTx, $http);
        }

        $json = json_decode((string)$brut, true);
        if (!is_array($json) || !isset($json['status'])) {
            error_log('[ovh_sms] réponse illisible — ' . substr((string)$brut, 0, 300));
            return $echec('reponse_illisible', 'Réponse OVH inattendue.', $http);
        }

        // OVH : 100 et 101 = succès, tout le reste est une erreur.
        $status = (int)$json['status'];
        if ($status !== 100 && $status !== 101) {
            $msg = trim((string)($json['message'] ?? ('code OVH ' . $status)));
            error_log('[ovh_sms] refus OVH status=' . $status . ' — ' . $msg);
            return $echec('ovh_' . $status, $msg, $http);
        }

        // Attention à la casse : OVH renvoie « SmsIds », pas « smsIds ».
        $ids = $json['SmsIds'] ?? $json['smsIds'] ?? [];
        $id  = is_array($ids) ? (string)($ids[0] ?? '') : (string)$ids;

        return [
            'ok'                  => true,
            'provider_message_id' => $id !== '' ? $id : null,
            'credit_restant'      => isset($json['creditLeft']) ? (string)$json['creditLeft'] : null,
            'error_code'          => null,
            'error_message'       => null,
            'http'                => $http,
        ];
    }
}
