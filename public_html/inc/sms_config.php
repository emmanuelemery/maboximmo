<?php
declare(strict_types=1);
/**
 * inc/sms_config.php — Chargement de la configuration SMS (OVHcloud).
 *
 * Même principe que le chargement OpenAI / Anthropic de bootstrap.php : le fichier
 * de secrets vit HORS du webroot et on le cherche dans une liste de candidats,
 * du plus sûr au plus permissif. Aucun secret ne transite jamais par public_html.
 *
 * Gabarit : public_html/config/ovh_sms_config.example.php
 *
 * ⚠️ La valeur retournée contient le mot de passe API. Ne jamais la sérialiser
 * dans un log, une réponse JSON ou une vue. Utiliser sms_config_publique() pour
 * tout ce qui est destiné à être affiché ou journalisé.
 */

if (!function_exists('sms_config')) {
    /**
     * @return array<string,mixed> Configuration complète, ou [] si absente.
     */
    function sms_config(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $candidats = [
            // 1. Hors webroot — emplacements canoniques
            '/home/u630423897/ovh_sms_config.php',
            __DIR__ . '/../../u630423897/ovh_sms_config.php',
            // 2. Racine projet (hors webroot également)
            __DIR__ . '/../../ovh_sms_config.php',
            // 3. Variante dev
            __DIR__ . '/../../u630423897/dev_ovh_sms_config.php',
        ];

        $conf = [];
        foreach ($candidats as $chemin) {
            if (is_file($chemin) && is_readable($chemin)) {
                $charge = @include $chemin;
                if (is_array($charge)) { $conf = $charge; break; }
            }
        }
        if (!$conf) return ($cache = []);

        // Valeurs par défaut : une config incomplète ne doit pas produire de
        // comportement surprenant (quota illimité, envoi malgré tout, etc.).
        $cache = [
            'service_name'      => trim((string)($conf['service_name'] ?? '')),
            'api_user'          => trim((string)($conf['api_user'] ?? '')),
            'api_password'      => (string)($conf['api_password'] ?? ''),
            'sender'            => trim((string)($conf['sender'] ?? '')),
            'actif'             => (bool)($conf['actif'] ?? false),
            'quota_horaire'     => max(0, (int)($conf['quota_horaire'] ?? 15)),
            'sans_mention_stop' => (bool)($conf['sans_mention_stop'] ?? true),
        ];
        return $cache;
    }
}

if (!function_exists('sms_config_prete')) {
    /** La configuration est-elle exploitable pour un envoi réel ? */
    function sms_config_prete(): bool
    {
        $c = sms_config();
        return $c !== []
            && $c['service_name'] !== ''
            && $c['api_user'] !== ''
            && $c['api_password'] !== '';
    }
}

if (!function_exists('sms_config_publique')) {
    /**
     * Version SANS SECRET, seule autorisée dans un log, un écran ou une réponse API.
     * Le mot de passe n'est jamais renvoyé, même masqué partiellement.
     */
    function sms_config_publique(): array
    {
        $c = sms_config();
        if ($c === []) return ['configuree' => false];
        return [
            'configuree'        => true,
            'prete'             => sms_config_prete(),
            'service_name'      => $c['service_name'],
            'api_user'          => $c['api_user'],
            'sender'            => $c['sender'] !== '' ? $c['sender'] : '(numéro court OVH)',
            'actif'             => $c['actif'],
            'quota_horaire'     => $c['quota_horaire'],
            'sans_mention_stop' => $c['sans_mention_stop'],
        ];
    }
}
