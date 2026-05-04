<?php
declare(strict_types=1);

require_once __DIR__ . '/ik_carte_grise.php';

if (!function_exists('rh_parse_amount')) {
    function rh_parse_amount(?string $str): ?float
    {
        if ($str === null) return null;
        $s = trim($str);
        if ($s === '') return null;
        $s = str_replace(["\xC2\xA0", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.') return null;
        $v = (float)$s;
        return is_finite($v) ? $v : null;
    }
}

if (!function_exists('rh_extract_last_amount')) {
    function rh_extract_last_amount(string $line): ?float
    {
        if (preg_match('/(-?\d[\d\s]*[,\.]\d{2})\s*$/u', $line, $m)) {
            return rh_parse_amount($m[1]);
        }
        return null;
    }
}

if (!function_exists('rh_normalize_name')) {
    function rh_normalize_name(string $name): string
    {
        $n = trim($name);
        if ($n === '') return '';
        if (function_exists('iconv')) {
            $n = iconv('UTF-8', 'ASCII//TRANSLIT', $n) ?: $n;
        }
        $n = strtoupper($n);
        $n = preg_replace('/[^A-Z0-9 ]/', ' ', $n);
        $n = preg_replace('/\s+/', ' ', $n);
        return trim($n);
    }
}

if (!function_exists('rh_bulletin_label_map')) {
    function rh_bulletin_label_map(): array
    {
        return [
            'Salaire de base' => ['/\bSalaire\s+de\s+base\b/i'],
            'Prime ancienneté' => ['/\bPrime\s+anciennete\b/i', '/\bPrime\s+anciennet[eé]\b/i'],
            'Avantage en nature' => ['/\bAvantage\s+en\s+nature\b/i'],
            'Heures supp' => ['/\bHeures?\s+supp/i'],
            'Commissions CA' => ['/\bCommissions?\s+CA\b/i'],
            'Commissions NA' => ['/\bCommissions?\s+CA\s+NA\b/i', '/\bCommissions?\s+nouvelles?\s+affaires\b/i'],
            'Prime administrative' => ['/\bPrime\s+administrative\b/i'],
            'Prime exceptionnelle' => ['/\bPrime\s+exceptionnelle\b/i'],
            'Treizieme mois' => ['/\bTreizi[eè]me\s+mois\b/i'],
            'Indemnité km' => ['/\bIndemn(?:it[eé]|ite)\s+kilom/i', '/\bIndemn(?:it[eé]|ite)\s+km\b/i', '/\bIK\b/i'],
            'Remboursement achat' => ['/\bRemboursement\s+achat\b/i'],
            'Frais professionnels' => ['/\bFrais\s+professionnels\b/i'],
            'Frais reception' => ['/\bFrais\s+reception\b/i', '/\bFrais\s+r[eé]ception\b/i'],
            'Stationnement' => ['/\bStationnement\b/i'],
            'Frais deplacement' => ['/\bFrais\s+deplacement\b/i', '/\bFrais\s+d[eé]placement\b/i'],
        ];
    }
}

if (!function_exists('rh_looks_like_employee_name')) {
    function rh_looks_like_employee_name(string $line): bool
    {
        // Exclut les en-têtes / libellés du bulletin qui sont en majuscules.
        $blacklist = '/\b(SARL|REGIE|LOCA[-\s]?IMMO|EMERY\s+IMMOBILIER|IMMOBILIER|CHAMALIERES|RIOM|VIENNE|MIONS|LYON|CHAPONOST|VILLEURBANNE|GRIGNY|COMMUNAY|IRIGNY|TOURDAN|EUPHEMIE|GERZAT|MONTCEL|CHAURIAT|ROMAGNAT|OULLINS|MARTIN|RHONE|FONTAINE|EMILE|JEAN|ROMANET|FOCH|FARGES|VERDUN|LIBERTE|PASTEUR|YVES|PHILIPPE|LASSALLE|SEYTRE|AUDRY|REPUBLIQUE|MARECHAL|PAGNOL|GAZELLE|ANGELIQUE|BELLEVUE|ECOLIERS|CHATAIGNIERS|ROUTE|RUE|IMPASSE|PLACE|CHEMIN|ALLEE|BOULEVARD|AVENUE|LE\s+PONT|SANTE|RETRAITE|FAMILLE|CHOMAGE|ASSURANCE|ACCIDENTS|EXONERATIONS|TOTAL|MONTANT|NET|BRUT|CSG|CRDS|APEC|COTISATIONS|CONTRIBUTIONS|MODE|DATE|EMPLOYEUR|SALARIE|BULLETIN|PROVISOIRE|VERSION|PROFESSIONNELLES|FISCAL|HC\/HS|SOCIAL|TRANCHE|CONVENTION|COLLECTIVE)\b/iu';
        if (preg_match($blacklist, $line)) return false;
        // NOM en MAJUSCULES (avec tirets / multiples mots) suivi d'un prénom Capitalisé.
        // Couvre : "GOUBE Céline", "HAFIANI-SIMON Alya", "GOUBE Hervé Alain Adrien",
        // "EMERY DUVAREILLE Emmelyne", "EMERY Emmanuel".
        return (bool)preg_match('/^([\p{Lu}][\p{Lu}\-]+(?:\s+[\p{Lu}][\p{Lu}\-]+)*)\s+([\p{Lu}][\p{L}\-]+(?:\s+[\p{Lu}][\p{L}\-]+)*)$/u', $line);
    }
}

if (!function_exists('rh_split_bulletins_by_employee')) {
    /**
     * Découpe le texte d'un PDF de bulletins en sections par MATRICULE.
     * Le matricule (logiciel de paie comptable) est la clé de matching avec
     * users.matricule_paie en BDD — robuste face aux différences de layout
     * pdftotext -layout (Windows) vs smalot/pdfparser (Hostinger flow text).
     */
    function rh_split_bulletins_by_employee(string $text): array
    {
        $text = str_replace("\r", "\n", $text);
        $lines = preg_split('/\n+/', $text);
        $sections = [];
        $currentMat = '';

        foreach ($lines as $raw) {
            $line = trim((string)$raw);
            if ($line === '') continue;

            // Détection début de bulletin : "Matricule : <num>"
            if (preg_match('/\bMatricule\s*:\s*(\d+)\b/iu', $line, $m)) {
                $currentMat = $m[1];
                if (!isset($sections[$currentMat])) {
                    $sections[$currentMat] = [
                        'matricule' => $currentMat,
                        'name'      => '',
                        'lines'     => [],
                    ];
                }

                // Layout pdftotext -layout : "Matricule : 37    GOUBE Céline" sur la même ligne.
                if (preg_match('/\bMatricule\s*:\s*\d+\s+(.+?)$/iu', $line, $m2)) {
                    $tail = trim(preg_replace('/\s{2,}/', ' ', $m2[1] ?? ''));
                    if ($sections[$currentMat]['name'] === ''
                        && $tail !== ''
                        && rh_looks_like_employee_name($tail)) {
                        $sections[$currentMat]['name'] = $tail;
                    }
                }
            }

            if ($currentMat !== '' && isset($sections[$currentMat])) {
                $sections[$currentMat]['lines'][] = $line;

                // Layout flow text (smalot/pdfparser) : nom sur une ligne séparée
                // après "Matricule : <num>".
                if ($sections[$currentMat]['name'] === ''
                    && rh_looks_like_employee_name($line)) {
                    $sections[$currentMat]['name'] = $line;
                }
            }
        }
        return $sections;
    }
}

if (!function_exists('rh_parse_bulletins_text')) {
    function rh_parse_bulletins_text(string $text): array
    {
        $sections = rh_split_bulletins_by_employee($text);
        $map = rh_bulletin_label_map();
        $employees = [];

        foreach ($sections as $matricule => $sec) {
            $lines = $sec['lines'];
            $brut = null;
            $net = null;
            $netAvant = null;
            $items = [];

            foreach ($lines as $line) {
                if ($brut === null && preg_match('/BRUT\s+FISCAL/i', $line)) {
                    $amt = rh_extract_last_amount($line);
                    if ($amt !== null) $brut = $amt;
                }
                if (preg_match('/NET\s+A\s+PAYER\s+AU\s+SALARIE/i', $line) || preg_match('/NET\s+A\s+PAYER\s*:/i', $line)) {
                    $amt = rh_extract_last_amount($line);
                    if ($amt !== null) $net = $amt;
                }
                if ($netAvant === null && preg_match('/NET\s+A\s+PAYER\s+AVANT\s+IMPOT/i', $line)) {
                    $amt = rh_extract_last_amount($line);
                    if ($amt !== null) $netAvant = $amt;
                }
                foreach ($map as $label => $patterns) {
                    foreach ($patterns as $pat) {
                        if (preg_match($pat, $line)) {
                            $amt = rh_extract_last_amount($line);
                            if ($amt !== null) {
                                if (!isset($items[$label]) || abs($amt) > 0) {
                                    $items[$label] = $amt;
                                }
                            }
                            break;
                        }
                    }
                }
            }

            $employees[$matricule] = [
                'matricule'       => $matricule,
                'name'            => $sec['name'] !== '' ? $sec['name'] : ('Matricule ' . $matricule),
                'brut'            => $brut,
                'net'             => $net,
                'net_avant_impot' => $netAvant,
                'items'           => $items,
            ];
        }

        return ['employees' => $employees];
    }
}

if (!function_exists('rh_parse_bulletins_file')) {
    function rh_parse_bulletins_file(string $path, array &$meta = null): array
    {
        $meta = [];
        $text = rh_extract_text_from_file($path, 'application/pdf', $meta);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Extraction PDF impossible', 'meta' => $meta, 'data' => []];
        }
        $data = rh_parse_bulletins_text($text);
        return ['ok' => true, 'data' => $data, 'meta' => $meta];
    }
}

?>