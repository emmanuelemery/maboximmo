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
        // Cas pdftotext : label puis montant à droite (ex. "Salaire de base ... 1923,08")
        if (preg_match('/(-?(?:\d{1,3}(?:[\s\xC2\xA0]\d{3})+|\d+)[,\.]\d{2})\s*$/u', $line, $m)) {
            return rh_parse_amount($m[1]);
        }
        return null;
    }
}

if (!function_exists('rh_extract_first_amount')) {
    function rh_extract_first_amount(string $line): ?float
    {
        // Cas smalot/pdfparser : montants en début de ligne, label à la fin
        // (ex. "1923,0812,6794151,67Salaire de base", "2124,04**** BRUT FISCAL ****").
        // Smalot lit les colonnes du PDF en ordre inverse vs pdftotext.
        if (preg_match('/^(-?(?:\d{1,3}(?:[\s\xC2\xA0]\d{3})+|\d+)[,\.]\d{2})/u', $line, $m)) {
            return rh_parse_amount($m[1]);
        }
        return null;
    }
}

if (!function_exists('rh_extract_amount_anywhere')) {
    function rh_extract_amount_anywhere(string $line): ?float
    {
        // Tente d'abord pdftotext (montant à la fin), puis smalot (montant au début).
        $amt = rh_extract_last_amount($line);
        if ($amt !== null) return $amt;
        return rh_extract_first_amount($line);
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
        // Boundaries (?<!\p{L}) et (?!\p{L}) au lieu de \b : \b échoue sur la
        // sortie smalot/pdfparser où les montants sont collés au label
        // ("67Salaire" -> 7 et S sont tous deux \w, pas de \b). On accepte
        // chiffres/ponctuation autour, mais pas de lettres.
        return [
            'Salaire de base'      => ['/(?<!\p{L})Salaire\s+de\s+base(?!\p{L})/iu'],
            'Prime ancienneté'     => ['/(?<!\p{L})Prime\s+anciennet[eé](?!\p{L})/iu'],
            'Avantage en nature'   => ['/(?<!\p{L})Avantage\s+en\s+nature(?!\p{L})/iu'],
            'Heures supp'          => ['/(?<!\p{L})Heures?\s+supp/iu'],
            'Commissions CA'       => ['/(?<!\p{L})Commissions?\s+CA(?!\p{L})/iu'],
            'Commissions NA'       => ['/(?<!\p{L})Commissions?\s+CA\s+NA(?!\p{L})/iu', '/(?<!\p{L})Commissions?\s+nouvelles?\s+affaires(?!\p{L})/iu'],
            'Prime administrative' => ['/(?<!\p{L})Prime\s+administrative(?!\p{L})/iu'],
            'Prime exceptionnelle' => ['/(?<!\p{L})Prime\s+exceptionnelle(?!\p{L})/iu'],
            'Treizieme mois'       => ['/(?<!\p{L})Treizi[eèé]me\s+mois(?!\p{L})/iu'],
            'Indemnité km'         => ['/(?<!\p{L})Indemn(?:it[eé]|ite)\s+kilom/iu', '/(?<!\p{L})Indemn(?:it[eé]|ite)\s+km(?!\p{L})/iu', '/(?<!\p{L})IK(?!\p{L})/iu'],
            'Remboursement achat'  => ['/(?<!\p{L})Remboursement\s+achat(?!\p{L})/iu'],
            'Frais professionnels' => ['/(?<!\p{L})Frais\s+professionnels(?!\p{L})/iu', '/(?<!\p{L})Remboursement\s+de\s+frais\s+professionnels(?!\p{L})/iu'],
            'Frais reception'      => ['/(?<!\p{L})Frais\s+r[eé]ception(?!\p{L})/iu'],
            'Stationnement'        => ['/(?<!\p{L})Stationnement(?!\p{L})/iu', '/(?<!\p{L})Remboursement\s+frais\s+de\s+stationnement(?!\p{L})/iu'],
            'Frais deplacement'    => ['/(?<!\p{L})Frais\s+d[eé]placement(?!\p{L})/iu', '/(?<!\p{L})Remboursement\s+frais\s+de\s+d[eé]placement(?!\p{L})/iu'],
        ];
    }
}

if (!function_exists('rh_looks_like_employee_name')) {
    function rh_looks_like_employee_name(string $line): bool
    {
        // Exclut les en-têtes / libellés du bulletin qui sont en majuscules.
        $blacklist = '/\b(SARL|REGIE|LOCA[-\s]?IMMO|EMERY\s+IMMOBILIER|IMMOBILIER|CHAMALIERES|RIOM|VIENNE|MIONS|LYON|CHAPONOST|VILLEURBANNE|GRIGNY|COMMUNAY|IRIGNY|TOURDAN|EUPHEMIE|GERZAT|MONTCEL|CHAURIAT|ROMAGNAT|OULLINS|MARTIN|RHONE|FONTAINE|EMILE|JEAN|ROMANET|FOCH|FARGES|VERDUN|LIBERTE|PASTEUR|YVES|PHILIPPE|LASSALLE|SEYTRE|AUDRY|REPUBLIQUE|MARECHAL|PAGNOL|GAZELLE|ANGELIQUE|BELLEVUE|ECOLIERS|CHATAIGNIERS|ROUTE|RUE|IMPASSE|PLACE|CHEMIN|ALLEE|BOULEVARD|AVENUE|LE\s+PONT|SANTE|RETRAITE|FAMILLE|CHOMAGE|ASSURANCE|ACCIDENTS|EXONERATIONS|TOTAL|MONTANT|NET|BRUT|CSG|CRDS|APEC|COTISATIONS|CONTRIBUTIONS|MODE|DATE|EMPLOYEUR|SALARIE|BULLETIN|PROVISOIRE|VERSION|PROFESSIONNELLES|FISCAL|HC\/HS|SOCIAL|TRANCHE|CONVENTION|COLLECTIVE)\b/iu';
        if (preg_match($blacklist, $line)) return false;
        // NOM en MAJUSCULES (avec tirets / multiples mots) suivi d'un prénom
        // Capitalisé qui DOIT contenir au moins une minuscule. Le requirement
        // d'une minuscule dans le prénom évite les faux positifs sur des
        // libellés tout-majuscules type "LOCATIF AGENT DE LOCATION".
        return (bool)preg_match('/^([\p{Lu}][\p{Lu}\-]+(?:\s+[\p{Lu}][\p{Lu}\-]+)*)\s+([\p{Lu}][\p{L}\-]*\p{Ll}[\p{L}\-]*(?:\s+[\p{Lu}][\p{L}\-]*\p{Ll}[\p{L}\-]*)*)$/u', $line);
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

            // Détection début de bulletin :
            //   - "Matricule : 37"  (sortie pdftotext)
            //   - "37:Matricule"    (sortie smalot/pdfparser - colonnes inversées)
            $matMatched = false;
            if (preg_match('/\bMatricule\s*:\s*(\d+)\b/iu', $line, $m)) {
                $currentMat = $m[1];
                $matMatched = true;
            } elseif (preg_match('/^(\d+)\s*:\s*Matricule\b/iu', $line, $m)) {
                $currentMat = $m[1];
                $matMatched = true;
            }

            if ($matMatched) {
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
                    $amt = rh_extract_amount_anywhere($line);
                    if ($amt !== null) $brut = $amt;
                }
                if (preg_match('/NET\s+A\s+PAYER\s+AU\s+SALARIE/i', $line) || preg_match('/NET\s+A\s+PAYER\s*:/i', $line)) {
                    $amt = rh_extract_amount_anywhere($line);
                    if ($amt !== null) $net = $amt;
                }
                if ($netAvant === null && preg_match('/NET\s+A\s+PAYER\s+AVANT\s+IMPOT/i', $line)) {
                    $amt = rh_extract_amount_anywhere($line);
                    if ($amt !== null) $netAvant = $amt;
                }
                foreach ($map as $label => $patterns) {
                    foreach ($patterns as $pat) {
                        if (preg_match($pat, $line)) {
                            $amt = rh_extract_amount_anywhere($line);
                            if ($amt !== null) {
                                // Capture aussi la ligne PDF brute pour traçabilité.
                                // pdftotext: "Salaire de base ... 1923,08" -> strip trailing nums
                                // smalot:    "1923,08...Salaire de base"   -> strip leading nums
                                $rawLine = preg_replace('/\s+/', ' ', $line);
                                $rawLine = preg_replace('/\s*-?\d[\d\s,\.%]*\s*$/u', '', $rawLine); // trailing numerics
                                $rawLine = preg_replace('/^\s*-?[\d\s,\.%]+/u', '', $rawLine);    // leading numerics
                                $rawLine = trim($rawLine);
                                if (!isset($items[$label]) || abs($amt) > 0) {
                                    $items[$label] = [
                                        'amount'   => $amt,
                                        'pdf_label' => $rawLine !== '' ? $rawLine : $label,
                                    ];
                                }
                            }
                            break;
                        }
                    }
                }
            }

            // Lignes "Absence Congés payés (DD-MM-AAAA - DD-MM-AAAA)" pour check congés
            $absences = [];
            foreach ($lines as $line) {
                if (preg_match('/Absence\s+Cong[eé]s\s+pay[eé]s\s*\(([^)]+)\)/iu', $line, $am)) {
                    $jours = null;
                    if (preg_match('/(\d+(?:[\.,]\d+)?)\s*,\s*\d+/', $line, $jm)) {
                        $jours = (float)str_replace(',', '.', $jm[1]);
                    }
                    $absences[] = [
                        'periode' => trim($am[1]),
                        'jours'   => $jours,
                        'raw'     => preg_replace('/\s+/', ' ', trim($line)),
                    ];
                }
            }

            $employees[$matricule] = [
                'matricule'       => $matricule,
                'name'            => $sec['name'] !== '' ? $sec['name'] : ('Matricule ' . $matricule),
                'brut'            => $brut,
                'net'             => $net,
                'net_avant_impot' => $netAvant,
                'items'           => $items,
                'absences_cp'     => $absences,
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