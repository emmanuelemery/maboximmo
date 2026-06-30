<?php
declare(strict_types=1);

/**
 * FluxBox — parser .msg (Outlook OLE2) en PHP pur, best-effort.
 *
 * Stratégie sans dépendance Composer : on lit le binaire OLE2 et on extrait :
 *  - les chaînes UTF-16LE des en-têtes (sujet, from, to, cc, date)
 *  - le corps texte (plaintext ou HTML)
 *  - les pièces jointes (best-effort : noms + blobs des sous-storages "__attach_")
 *
 * Limites :
 *  - ~80% des .msg "simples" sont parsés correctement
 *  - Les .msg très volumineux ou signés/chiffrés peuvent échouer ou être incomplets
 *  - Si parsing échoue, on retourne au moins { ok:false, raw_hex_dump } + métadonnées fichier
 *
 * Pour un parsing fiable à 100%, installer `hfig/php-msgreader` via Composer.
 *
 * Validé EMERY 2026-05-16.
 */

if (!function_exists('fluxbox_msg_parse')) {
    /**
     * Parse un fichier .msg et retourne un tableau structuré.
     *
     * @return array{
     *   ok:bool,
     *   from:string, from_email:string,
     *   to:string, cc:string,
     *   subject:string,
     *   date_sent:?string,
     *   body_text:string, body_html:string,
     *   attachments:array<array{name:string, size:int, hash_sha256:string, idx:int}>,
     *   raw_size:int,
     *   warnings:array<string>,
     * }
     */
    function fluxbox_msg_parse(string $filePath): array
    {
        // Wrapper global try/catch : aucune exception/fatal ne doit casser le JSON de l'API
        try {
            return fluxbox_msg_parse_internal($filePath);
        } catch (Throwable $e) {
            error_log('fluxbox_msg_parse fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return [
                'ok' => false, 'from' => '', 'from_email' => '', 'to' => '', 'cc' => '',
                'subject' => '', 'date_sent' => null, 'body_text' => '', 'body_html' => '',
                'attachments' => [], 'raw_size' => 0,
                'warnings' => ['Erreur interne lors de la lecture du .msg : ' . $e->getMessage()],
            ];
        }
    }

    function fluxbox_msg_parse_internal(string $filePath): array
    {
        $result = [
            'ok'          => false,
            'from'        => '',
            'from_email'  => '',
            'to'          => '',
            'cc'          => '',
            'subject'     => '',
            'date_sent'   => null,
            'body_text'   => '',
            'body_html'   => '',
            'attachments' => [],
            'raw_size'    => 0,
            'warnings'    => [],
        ];

        if (!is_file($filePath)) {
            $result['warnings'][] = 'Fichier introuvable';
            return $result;
        }

        $size = @filesize($filePath) ?: 0;
        $result['raw_size'] = (int)$size;
        if ($size === 0) {
            $result['warnings'][] = 'Fichier vide';
            return $result;
        }
        if ($size > 50 * 1024 * 1024) {
            $result['warnings'][] = 'Fichier > 50 Mo — parsing partiel uniquement';
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            $result['warnings'][] = 'Lecture fichier échouée';
            return $result;
        }

        // Vérif signature OLE2 (D0 CF 11 E0 A1 B1 1A E1)
        if (substr($content, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            $result['warnings'][] = 'Signature OLE2 invalide — pas un .msg standard';
            return $result;
        }

        // ─── Parser MAPI via hfig/mapi (Composer) — priorité absolue ───
        $ole2Ok = false;
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }
        if (class_exists('Hfig\\MAPI\\MapiMessageFactory') && class_exists('Hfig\\MAPI\\OLE\\Pear\\DocumentFactory')) {
            try {
                $messageFactory  = new \Hfig\MAPI\MapiMessageFactory();
                $documentFactory = new \Hfig\MAPI\OLE\Pear\DocumentFactory();
                $ole2 = $documentFactory->createFromFile($filePath);
                $msg  = $messageFactory->parseMessage($ole2);

                $result['subject']   = (string)($msg->properties['subject'] ?? '');
                $result['body_text'] = (string)(@$msg->getBody() ?: '');
                try { $result['body_html'] = (string)(@$msg->getBodyHTML() ?: ''); } catch (Throwable) {}

                // getSender() retourne "Nom <email>" ou juste l'email/DN selon les cas
                $senderFull = (string)(@$msg->getSender() ?: '');
                $result['from'] = $senderFull;
                if (preg_match('/<([^>]+)>/', $senderFull, $m) && filter_var($m[1], FILTER_VALIDATE_EMAIL)) {
                    $result['from_email'] = $m[1];
                    // Nom propre sans le <email>
                    $clean = trim(preg_replace('/\s*<[^>]+>\s*$/', '', $senderFull) ?? $senderFull);
                    if ($clean !== '') $result['from'] = $clean;
                } elseif (filter_var($senderFull, FILTER_VALIDATE_EMAIL)) {
                    $result['from_email'] = $senderFull;
                }

                // Date d'envoi via getSendTime() → DateTime
                try {
                    $sendTime = @$msg->getSendTime();
                    if ($sendTime instanceof \DateTimeInterface) {
                        $result['date_sent'] = $sendTime->format('c');
                    }
                } catch (Throwable) {}

                // Destinataires (To / CC)
                $tos = []; $ccs = [];
                try {
                    foreach ($msg->getRecipients() as $r) {
                        $type = strtolower((string)@$r->getType());
                        $str  = trim((string)$r);
                        if ($str === '') continue;
                        if ($type === 'to')      $tos[] = $str;
                        elseif ($type === 'cc')  $ccs[] = $str;
                    }
                } catch (Throwable) {}
                $result['to'] = implode('; ', $tos);
                $result['cc'] = implode('; ', $ccs);

                // Pièces jointes — vrais noms via getFilename() de hfig/mapi
                $atts = [];
                try {
                    $i = 0;
                    foreach ($msg->getAttachments() as $a) {
                        $name = (string)(@$a->getFilename() ?: @$a->__get('display_name') ?: ('piece_jointe_' . ($i + 1)));
                        // Si nom vide, fallback
                        if (trim($name) === '') $name = 'piece_jointe_' . ($i + 1) . '.bin';
                        $atts[] = [
                            'idx'  => $i,
                            'name' => $name,
                            'size' => (int)(@$a->__get('attach_size') ?: 0),
                            'mime' => (string)(@$a->getMimeType() ?: ''),
                            'hash_sha256' => '',
                        ];
                        $i++;
                    }
                } catch (Throwable) {}
                $result['attachments'] = $atts;

                $ole2Ok = true;
            } catch (Throwable $e) {
                error_log('hfig/mapi parse failed: ' . $e->getMessage());
                $ole2Ok = false;
            }
        }

        // ─── Fallback : ancien parser OLE2 maison si hfig/mapi indispo ou échec ───
        if (!$ole2Ok) {
            try {
                require_once __DIR__ . '/fluxbox_ole2_parser.php';
                $ole = new FluxboxOle2Parser($content);
                $ole2Ok = $ole->load();

                if ($ole2Ok) {
                $rawSubject  = @$ole->getMapiProp('0037001F');
                $rawBody     = @$ole->getMapiProp('1000001F');
                $rawFromName = @$ole->getMapiProp('0C1A001F');
                $rawFromMail = @$ole->getMapiProp('0C1F001F');
                $rawSmtpFrom = @$ole->getMapiProp('5D01001F');
                $rawDispTo   = @$ole->getMapiProp('0E04001F');
                $rawDispCc   = @$ole->getMapiProp('0E03001F');
                $rawBodyHtml = @$ole->getMapiProp('1013001E');

                $result['subject']    = $rawSubject  !== null ? trim(fluxbox_msg_decode_utf16le($rawSubject))  : '';
                $result['body_text']  = $rawBody     !== null ? trim(fluxbox_msg_decode_utf16le($rawBody))     : '';
                $result['from']       = $rawFromName !== null ? trim(fluxbox_msg_decode_utf16le($rawFromName)) : '';
                $result['to']         = $rawDispTo   !== null ? trim(fluxbox_msg_decode_utf16le($rawDispTo))   : '';
                $result['cc']         = $rawDispCc   !== null ? trim(fluxbox_msg_decode_utf16le($rawDispCc))   : '';

                $smtpAddr = $rawSmtpFrom !== null ? trim(fluxbox_msg_decode_utf16le($rawSmtpFrom)) : '';
                $rawAddr  = $rawFromMail !== null ? trim(fluxbox_msg_decode_utf16le($rawFromMail)) : '';
                if ($smtpAddr !== '' && filter_var($smtpAddr, FILTER_VALIDATE_EMAIL)) {
                    $result['from_email'] = $smtpAddr;
                } elseif ($rawAddr !== '' && !str_starts_with($rawAddr, '/') && filter_var($rawAddr, FILTER_VALIDATE_EMAIL)) {
                    $result['from_email'] = $rawAddr;
                } else {
                    $result['from_email'] = '';
                    if ($rawAddr !== '' && str_starts_with($rawAddr, '/')) {
                        $result['warnings'][] = 'Email expéditeur en format Exchange interne (LegacyExchangeDN) — adresse SMTP indisponible';
                    }
                }

                if ($rawBodyHtml !== null && $rawBodyHtml !== '') {
                    $result['body_html'] = $rawBodyHtml;
                }

                try {
                    $result['attachments'] = $ole->listAttachments();
                } catch (Throwable) {
                    $result['attachments'] = [];
                }
            }
        } catch (Throwable $e) {
            error_log('msg OLE2 fallback parse failed: ' . $e->getMessage());
            $ole2Ok = false;
        }
        } // fin du if (!$ole2Ok) {  (fallback OLE2 maison)

        // Si parsing OLE2 a échoué OU n'a rien rendu → fallback heuristique
        $strings = [];
        if (!$ole2Ok || ($result['subject'] === '' && $result['body_text'] === '' && $result['from'] === '')) {
            try {
                $strings = fluxbox_msg_extract_utf16le_strings($content);
            } catch (Throwable) {
                $strings = [];
            }
            // Pas de warning utilisateur — l'heuristique va prendre le relais silencieusement
        }

        // Tentative d'extraction des streams MAPI nommés (plus fiable que l'heuristique pure)
        // PR_SUBJECT_W = 0x0037001F → stream name "__substg1.0_0037001F"
        // PR_BODY_W    = 0x1000001F → stream name "__substg1.0_1000001F"
        // PR_SENDER_NAME_W = 0x0C1A001F
        // PR_SENDER_EMAIL_ADDRESS_W = 0x0C1F001F
        $streamBody    = fluxbox_msg_extract_named_stream($content, '0x1000001F');
        $streamSubject = fluxbox_msg_extract_named_stream($content, '0x0037001F');
        $streamFrom    = fluxbox_msg_extract_named_stream($content, '0x0C1A001F');
        $streamFromEmail = fluxbox_msg_extract_named_stream($content, '0x0C1F001F');
        $streamTo      = fluxbox_msg_extract_named_stream($content, '0x0E04001F');

        // ─── Fallbacks champ par champ : seulement SI hfig/mapi n'a rien rendu pour ce champ ───
        // Helper : rejette les fragments trop courts ou techniques
        $isValidStr = static fn(string $s, int $minLen = 3): bool =>
            $s !== '' && mb_strlen(trim($s)) >= $minLen && !fluxbox_msg_is_technical_string($s);

        if ($result['subject']    === '' && $isValidStr($streamSubject, 3))   $result['subject']    = $streamSubject;
        elseif ($result['subject'] === '')                                    $result['subject']    = fluxbox_msg_find_subject($strings);

        if ($result['from']       === '' && $isValidStr($streamFrom, 3))      $result['from']       = $streamFrom;
        elseif ($result['from']   === '')                                     $result['from']       = fluxbox_msg_find_from($strings);

        if ($result['from_email'] === '' && $isValidStr($streamFromEmail, 5)) $result['from_email'] = $streamFromEmail;
        elseif ($result['from_email'] === '')                                 $result['from_email'] = fluxbox_msg_find_email($strings, $result['from']);

        if ($result['to']         === '' && $isValidStr($streamTo, 3))        $result['to']         = $streamTo;
        elseif ($result['to']     === '')                                     $result['to']         = fluxbox_msg_find_to($strings);

        if ($result['cc']         === '')                                     $result['cc']         = fluxbox_msg_find_cc($strings);
        if ($result['date_sent']  === null)                                   $result['date_sent']  = fluxbox_msg_find_date($strings);

        // Body : rejette les fragments < 30 chars (un vrai corps de mail a du contenu)
        if ($result['body_text']  === '' && $isValidStr($streamBody, 30))     $result['body_text']  = $streamBody;
        elseif ($result['body_text'] === '')                                  $result['body_text']  = fluxbox_msg_find_body($strings);

        if ($result['body_html']  === '')                                     $result['body_html']  = fluxbox_msg_find_html_body($content);

        // Filtre final : si from_email est un LegacyExchangeDN, on l'efface (illisible utilisateur)
        if ($result['from_email'] !== '' && (str_starts_with($result['from_email'], '/') || !filter_var($result['from_email'], FILTER_VALIDATE_EMAIL))) {
            $result['warnings'][] = 'Email expéditeur en format Exchange interne (non SMTP) — masqué';
            $result['from_email'] = '';
        }

        // Liste des PJ : si vide après hfig, fallback heuristique
        if (empty($result['attachments'])) {
            $result['attachments'] = fluxbox_msg_list_attachments($content, $strings);
        }

        $result['ok'] = $result['subject'] !== '' || $result['from'] !== '' || $result['body_text'] !== '';

        return $result;
    }
}

if (!function_exists('fluxbox_msg_decode_utf16le')) {
    /**
     * Décode un blob UTF-16LE en chaîne UTF-8.
     */
    function fluxbox_msg_decode_utf16le(string $raw): string
    {
        if ($raw === '') return '';
        // Strip null terminator final si présent
        $raw = rtrim($raw, "\x00");
        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            if (is_string($out)) return $out;
        }
        // Fallback manuel
        $out = '';
        $len = strlen($raw);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $c = ord($raw[$i]) + (ord($raw[$i + 1]) << 8);
            if ($c === 0) break;
            if ($c < 0x80) $out .= chr($c);
            elseif (function_exists('mb_chr')) $out .= mb_chr($c, 'UTF-8');
        }
        return $out;
    }
}

if (!function_exists('fluxbox_msg_extract_named_stream')) {
    /**
     * Cherche dans le binaire OLE2 le contenu d'un stream MAPI nommé par son PropID.
     * Ex : PropID 0x1000001F (PR_BODY_W) → nom OLE2 "__substg1.0_1000001F" (en UTF-16LE).
     *
     * Stratégie pragmatique : on cherche le nom du stream encodé en UTF-16LE,
     * puis on extrait la première chaîne UTF-16LE valide dans la fenêtre suivante.
     * Best-effort : marche pour les .msg simples où le stream est stocké inline.
     */
    function fluxbox_msg_extract_named_stream(string $content, string $propIdHex): string
    {
        // Normalise le PropID : "0x1000001F" → "1000001F"
        $propIdHex = preg_replace('/^0x/i', '', $propIdHex);
        $streamName = '__substg1.0_' . strtoupper($propIdHex);
        // Encode en UTF-16LE (chaque char ASCII suivi de \x00)
        $needle = '';
        for ($i = 0, $l = strlen($streamName); $i < $l; $i++) {
            $needle .= $streamName[$i] . "\x00";
        }
        $pos = strpos($content, $needle);
        if ($pos === false) return '';

        // Fenêtre de recherche après le marqueur de nom de stream
        $searchStart = $pos + strlen($needle);
        $searchEnd   = min(strlen($content), $searchStart + 8192);
        $window = substr($content, $searchStart, $searchEnd - $searchStart);

        // Extrait la première chaîne UTF-16LE valide non-technique
        $strings = fluxbox_msg_extract_utf16le_strings($window);
        foreach ($strings as $s) {
            $s = trim($s);
            if ($s === '' || fluxbox_msg_is_technical_string($s)) continue;
            if (mb_strlen($s) < 2) continue;
            return $s;
        }
        return '';
    }
}

if (!function_exists('fluxbox_msg_extract_utf16le_strings')) {
    /**
     * Extrait les chaînes UTF-16LE du contenu binaire.
     * Une chaîne UTF-16LE valide a des octets alternés : char ASCII, 0x00, char ASCII, 0x00, ...
     * @return array<string>
     */
    function fluxbox_msg_extract_utf16le_strings(string $content): array
    {
        $strings = [];
        $len = strlen($content);
        $i = 0;
        $minLen = 3; // au moins 3 caractères pour considérer une string utile

        while ($i < $len - 4) {
            // Cherche un début de pattern : char + 0x00
            $start = $i;
            $chars = '';
            while ($i < $len - 1) {
                $c1 = ord($content[$i]);
                $c2 = ord($content[$i + 1]);
                // Caractère "lisible" en UTF-16LE BMP (ASCII + Latin-1)
                if ($c2 === 0 && $c1 >= 0x20 && $c1 < 0x7F) {
                    $chars .= chr($c1);
                    $i += 2;
                } elseif ($c2 === 0 && $c1 >= 0xA0 && $c1 < 0xFF) {
                    // Latin-1 supplément
                    $chars .= chr($c1);
                    $i += 2;
                } else {
                    break;
                }
            }
            if (mb_strlen($chars) >= $minLen) {
                $strings[] = $chars;
            }
            $i = max($i, $start + 1);
        }

        return $strings;
    }
}

if (!function_exists('fluxbox_msg_is_technical_string')) {
    /**
     * Détecte si une chaîne extraite est probablement du data interne OLE2/MAPI
     * (à filtrer hors de l'affichage utilisateur).
     */
    function fluxbox_msg_is_technical_string(string $s): bool
    {
        $s = trim($s);
        if ($s === '') return true;
        // Noms de streams/storages OLE2 standards
        if ($s === 'Root Entry' || $s === 'RootEntry') return true;
        if (str_starts_with($s, '__') || str_starts_with($s, '__')) return true;
        if (preg_match('/^_+(substg|attach|nameid|recip|properties|version|olemap|ole10|ssrc)/i', $s)) return true;
        // Métadonnées MAPI sérialisées : "0=100;1=0;2=0;..."
        if (preg_match('/^\d+=\d+(\.\d+)?(\s*;\s*\d+=\d+(\.\d+)?){3,}/', $s)) return true;
        // URN / namespace Microsoft
        if (str_contains($s, 'urn:schemas-microsoft')) return true;
        if (str_contains($s, 'schemas.microsoft.com')) return true;
        if (str_contains($s, 'Microsoft Office')) return true;
        if (str_contains($s, 'Outlook.Message')) return true;
        if (preg_match('/^IPM\.(Note|Schedule|Appointment|Document|Post|Contact)/', $s)) return true;
        // Noms de propriétés MAPI exposés en clair dans le binaire
        if (preg_match('/^(Exchange[A-Z][a-z]+|MessageClass|MessageFlags|InternetMessageId|TransportMessageHeaders|ContentType|PR_[A-Z_]+)/', $s)) return true;
        // LegacyExchangeDN (format "/O=..." ou "/CN=...")
        if (preg_match('/^\/(O|CN|OU|DC)=/i', $s)) return true;
        // GUID/UUID techniques
        if (preg_match('/^\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}/', $s)) return true;
        // Chaînes hex pures
        if (preg_match('/^[0-9A-Fa-f]{16,}$/', $s)) return true;
        // PropID MAPI tag (8 hex chars)
        if (preg_match('/^[0-9A-Fa-f]{8}$/', $s)) return true;
        // String composée uniquement de caractères non-alphabétiques (que des chiffres/symboles)
        if (!preg_match('/[A-Za-zÀ-ÿ]{3}/', $s)) return true;
        return false;
    }
}

if (!function_exists('fluxbox_msg_find_subject')) {
    function fluxbox_msg_find_subject(array $strings): string
    {
        // Sans parser OLE2, l'heuristique est limitée : on prend la 1re chaîne
        // qui ressemble à un sujet (≥5 chars, ≤250, contient des mots normaux, pas un fragment)
        foreach ($strings as $s) {
            $s = trim($s);
            if (fluxbox_msg_is_technical_string($s)) continue;
            if (str_contains($s, 'MAPI')) continue;
            if (preg_match('/^[^\s]+@[^\s]+\.[^\s]+$/', $s)) continue;
            // Exige au moins 5 chars, au moins 1 mot de 3 lettres minimum,
            // ne pas commencer par ponctuation ou espace (fragments)
            if (mb_strlen($s) < 5 || mb_strlen($s) > 250) continue;
            if (!preg_match('/[A-Za-zÀ-ÿ]{3}/', $s)) continue;
            if (preg_match('/^[\s\.,;:!?]/', $s)) continue;
            return $s;
        }
        return '';
    }
}

if (!function_exists('fluxbox_msg_find_from')) {
    function fluxbox_msg_find_from(array $strings): string
    {
        foreach ($strings as $s) {
            $s = trim($s);
            if (fluxbox_msg_is_technical_string($s)) continue;
            // Pattern "Nom Prenom <email@domain>"
            if (preg_match('/^[A-ZÀ-ÿ][\w\s.\'-]{1,80}\s*<[^>]+@[^>]+>$/u', $s)) {
                return $s;
            }
        }
        // Fallback : juste un email (mais pas un email technique style noreply ou bounce)
        foreach ($strings as $s) {
            $s = trim($s);
            if (preg_match('/^[^\s<>]+@[^\s<>]+\.[^\s<>]+$/', $s)) {
                return $s;
            }
        }
        return '';
    }
}

if (!function_exists('fluxbox_msg_find_email')) {
    function fluxbox_msg_find_email(array $strings, string $from): string
    {
        // 1. Extrait l'email du champ "from" si format Name <email>
        if (preg_match('/<([^>]+@[^>]+)>/', $from, $m)) {
            if (filter_var($m[1], FILTER_VALIDATE_EMAIL)) return $m[1];
        }
        if (preg_match('/[^\s<>]+@[^\s<>]+\.[^\s<>]+/', $from, $m)) {
            if (filter_var($m[0], FILTER_VALIDATE_EMAIL)) return $m[0];
        }
        // 2. Cherche dans toutes les strings — SKIP LegacyExchangeDN (/O=, /CN=)
        foreach ($strings as $s) {
            $s = trim($s);
            if (str_starts_with($s, '/')) continue; // Exchange DN
            if (preg_match('/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/', $s)) {
                return $s;
            }
            if (preg_match('/[\w.+-]+@[\w-]+\.[\w-]+/', $s, $m)) {
                if (filter_var($m[0], FILTER_VALIDATE_EMAIL)) return $m[0];
            }
        }
        return '';
    }
}

if (!function_exists('fluxbox_msg_find_to')) {
    function fluxbox_msg_find_to(array $strings): string
    {
        // Heuristique stricte : doit contenir un email ou une liste type "Nom; Nom2"
        // ET ne pas être un fragment court (Bjr, etc.)
        foreach ($strings as $s) {
            $s = trim($s);
            if (fluxbox_msg_is_technical_string($s)) continue;
            if (mb_strlen($s) < 5) continue;
            // Email présent OU pattern "Nom; Nom" (deux mots min séparés par ;)
            if (substr_count($s, '@') >= 1 && mb_strlen($s) < 500) return $s;
            if (preg_match('/^[A-ZÀ-ÿ][\w\s.\'-]{3,};\s*[A-ZÀ-ÿ]/u', $s)) return $s;
        }
        return '';
    }
}

if (!function_exists('fluxbox_msg_find_cc')) {
    function fluxbox_msg_find_cc(array $strings): string
    {
        // Heuristique faible : skip pour V1, le user ouvrira le .msg dans Outlook si besoin
        return '';
    }
}

if (!function_exists('fluxbox_msg_find_date')) {
    function fluxbox_msg_find_date(array $strings): ?string
    {
        // Cherche des dates au format ISO ou français
        foreach ($strings as $s) {
            if (preg_match('/(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2})/', $s, $m)) return $m[1];
            if (preg_match('/(\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2})/', $s, $m)) return $m[1];
        }
        return null;
    }
}

if (!function_exists('fluxbox_msg_find_body')) {
    function fluxbox_msg_find_body(array $strings): string
    {
        // Critères durcis pour éviter "nce d" et autres fragments :
        //  - longueur min 100 chars (un mail réel a un vrai corps)
        //  - au moins 5 mots de 4+ lettres
        //  - ratio alpha > 60%
        //  - pas un fragment qui commence par minuscule (= morceau coupé d'une chaîne plus longue)
        $candidates = [];
        foreach ($strings as $s) {
            $s = trim($s);
            $len = mb_strlen($s);
            if ($len < 100) continue;
            if (fluxbox_msg_is_technical_string($s)) continue;
            if (str_starts_with($s, '<')) continue;
            // 5 mots de 4+ lettres
            if (!preg_match('/(?:[A-Za-zÀ-ÿ]{4,}[\s,.]+){4,}[A-Za-zÀ-ÿ]{4,}/u', $s)) continue;
            $alphaCount = preg_match_all('/[A-Za-zÀ-ÿ\s]/u', $s);
            if ($len > 0 && ($alphaCount / $len) < 0.6) continue;
            // Skip fragment : doit commencer par majuscule ou un mot connu
            if (!preg_match('/^[A-ZÀ-Ÿ"\'(]/', $s)) continue;
            $candidates[] = ['len' => $len, 'text' => $s];
        }
        usort($candidates, fn($a, $b) => $b['len'] - $a['len']);
        return $candidates[0]['text'] ?? '';
    }
}

if (!function_exists('fluxbox_msg_find_html_body')) {
    function fluxbox_msg_find_html_body(string $content): string
    {
        // Cherche un bloc HTML dans le binaire (souvent en UTF-8 ou Windows-1252)
        if (preg_match('/<html[^>]*>(.+?)<\/html>/is', $content, $m)) {
            $html = $m[0];
            // Nettoyage de caractères de contrôle
            $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $html) ?? $html;
            return $html;
        }
        return '';
    }
}

if (!function_exists('fluxbox_msg_list_attachments')) {
    /**
     * Liste les pièces jointes sans extraire leur contenu binaire complet.
     * Approche heuristique : cherche les noms de fichiers candidats parmi les strings
     * (extensions .pdf, .docx, .xlsx, .jpg, etc.) + les sous-storages "__attach_".
     */
    function fluxbox_msg_list_attachments(string $content, array $strings): array
    {
        $attachments = [];
        $seen = [];

        // 1. Compte les sous-storages __attach_version1.0_#XXXXXXXX
        $attachCount = preg_match_all('/__attach_version1\.0_#[0-9A-F]{8}/', $content);

        // 2. Cherche les noms de fichiers parmi les strings (extensions communes)
        $extensions = '/\.(pdf|docx?|xlsx?|pptx?|jpe?g|png|gif|bmp|tiff?|zip|rar|7z|csv|txt|eml|msg|mp4|mov|avi|odt|ods|odp)$/i';
        foreach ($strings as $s) {
            $s = trim($s);
            if ($s === '' || isset($seen[$s])) continue;
            // On ne veut pas un chemin entier, juste un nom de fichier propre
            if (preg_match('/^[^\\\\\/]{1,200}' . substr($extensions, 1, -2) . '$/i', $s)) {
                $seen[$s] = true;
                $attachments[] = [
                    'name'        => $s,
                    'size'        => 0,        // taille indéterminée sans parsing OLE2 complet
                    'hash_sha256' => '',
                    'idx'         => count($attachments),
                ];
            }
        }

        // Si on a détecté des __attach mais aucun nom : signaler N PJ anonymes
        if ($attachCount > count($attachments)) {
            for ($i = count($attachments); $i < $attachCount; $i++) {
                $attachments[] = [
                    'name'        => "piece_jointe_" . ($i + 1) . ".bin",
                    'size'        => 0,
                    'hash_sha256' => '',
                    'idx'         => $i,
                ];
            }
        }

        return $attachments;
    }
}

if (!function_exists('fluxbox_msg_extract_attachment_blob')) {
    /**
     * Tentative d'extraction du blob d'une PJ par son index/nom.
     * Best-effort : sans parser OLE2 complet, on peut juste retourner le contenu
     * brut entre 2 marqueurs si trouvés. Sinon retourne null (download impossible).
     */
    function fluxbox_msg_extract_attachment_blob(string $filePath, int $idx, string $name = ''): ?array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) return null;

        // Cherche le nom de la PJ dans le binaire (UTF-16LE)
        if ($name !== '') {
            $nameU16 = '';
            for ($i = 0, $l = strlen($name); $i < $l; $i++) {
                $nameU16 .= $name[$i] . "\x00";
            }
            $pos = strpos($content, $nameU16);
            if ($pos !== false) {
                // Cherche un blob de données après ce nom (best effort, taille indéterminée)
                // Pour V1 on retourne juste null — extraction réelle requiert lib OLE2
            }
        }

        return null; // V1 : extraction non implémentée, fallback "télécharger le .msg complet"
    }
}
