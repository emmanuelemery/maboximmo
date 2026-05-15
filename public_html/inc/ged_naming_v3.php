<?php
declare(strict_types=1);

/**
 * GED Naming v3 — format canonique option B (underscores partout)
 *
 * Format figé par EMERY 2026-05-13 :
 *   {SOC}_{AGE}_{N1}_{N2}_{N3}_{N4}_{N5}_{N6}_{YYYYMMDD}.{ext}
 *
 * Exemples :
 *   REG_LYO7_SYN_TRVX_FACT_AVAL_ASCENSEUR_20260512.pdf
 *   REG_LYO7_RH_PAIE_BULL_VALIDE_DUPONT_JEAN_20260430.pdf
 *
 * Règles :
 * - Séparateur unique = "_"
 * - Date en fin, format YYYYMMDD (8 chiffres, sans tiret)
 * - N4/N5/N6 optionnels (segments vides → collapse)
 * - Codes UPPERCASE, slug normalisé (sans accents, pas de caractères interdits)
 * - Pas d'IDGED dans le nom (ID reste en BDD)
 *
 * Coexiste avec inc/ged_naming_v2.php (V2 reste actif pour pages legacy).
 */

if (!function_exists('ged_v3_slug')) {
    /**
     * Slug strict pour codes/labels : ASCII upper, alphanumeric + underscore uniquement.
     */
    function ged_v3_slug(string $s): string
    {
        if ($s === '') return '';
        $s = trim($s);
        // Normalisation accents
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false) $s = $tr;
        }
        $s = strtoupper($s);
        // Caractères autorisés : A-Z 0-9 _ → tout le reste devient _
        $s = preg_replace('/[^A-Z0-9_]+/', '_', $s) ?? '';
        // Compaction des _ multiples
        $s = preg_replace('/_+/', '_', $s) ?? '';
        return trim($s, '_');
    }
}

if (!function_exists('ged_v3_format_date')) {
    /**
     * Formatte une date en YYYYMMDD. Accepte string ISO, DateTime, ou null (= aujourd'hui).
     */
    function ged_v3_format_date(string|DateTimeInterface|null $date = null): string
    {
        if ($date === null || $date === '') {
            return date('Ymd');
        }
        if ($date instanceof DateTimeInterface) {
            return $date->format('Ymd');
        }
        $ts = strtotime((string)$date);
        if ($ts === false) {
            return date('Ymd');
        }
        return date('Ymd', $ts);
    }
}

if (!function_exists('ged_v3_extract_date_from_text')) {
    /**
     * Essaie d'extraire une date depuis un nom de fichier ou un texte (OCR snippet).
     * Reconnaît les formats courants : YYYY-MM-DD, DD-MM-YYYY, YYYYMMDD, YYYY-MM,
     * MM-YYYY, "mars 2026", "032026", "Mar 2026", etc.
     *
     * @return string|null format YYYY-MM-DD ou null si rien trouvé
     */
    function ged_v3_extract_date_from_text(string $text): ?string
    {
        if ($text === '') return null;

        // Normalisation
        $t = $text;
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            if ($tr !== false) $t = $tr;
        }
        $t = strtolower($t);

        // 1) YYYY-MM-DD ou YYYY/MM/DD ou YYYY_MM_DD
        if (preg_match('/(20\d{2})[-_\/.](\d{1,2})[-_\/.](\d{1,2})/', $t, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // 2) DD-MM-YYYY (Europe)
        if (preg_match('/(\d{1,2})[-_\/.](\d{1,2})[-_\/.](20\d{2})/', $t, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // 3) YYYYMMDD compact (8 chiffres)
        if (preg_match('/(?<!\d)(20\d{2})(\d{2})(\d{2})(?!\d)/', $t, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // 4) Mois texte FR + année (ex "mars 2026", "fev 2026", "decembre 2025")
        $monthsFr = [
            'janvier'=>1, 'janv'=>1, 'jan'=>1,
            'fevrier'=>2, 'fev'=>2, 'feb'=>2,
            'mars'=>3, 'mar'=>3,
            'avril'=>4, 'avr'=>4, 'apr'=>4,
            'mai'=>5, 'may'=>5,
            'juin'=>6, 'jun'=>6,
            'juillet'=>7, 'juil'=>7, 'jul'=>7,
            'aout'=>8, 'aug'=>8,
            'septembre'=>9, 'sept'=>9, 'sep'=>9,
            'octobre'=>10, 'oct'=>10,
            'novembre'=>11, 'nov'=>11,
            'decembre'=>12, 'dec'=>12,
        ];
        $monthAlt = implode('|', array_keys($monthsFr));
        if (preg_match('/(' . $monthAlt . ')[^\d]{0,4}(20\d{2})/', $t, $m)) {
            $mo = $monthsFr[$m[1]] ?? 0;
            $y = (int)$m[2];
            if ($mo > 0) return sprintf('%04d-%02d-01', $y, $mo);
        }
        // Inversé : "2026 mars"
        if (preg_match('/(20\d{2})[^\d]{0,4}(' . $monthAlt . ')/', $t, $m)) {
            $y = (int)$m[1];
            $mo = $monthsFr[$m[2]] ?? 0;
            if ($mo > 0) return sprintf('%04d-%02d-01', $y, $mo);
        }

        // 5) MM-YYYY ou MM/YYYY (ex "03-2026", "03/2026")
        if (preg_match('/(?<!\d)(\d{1,2})[-_\/.](20\d{2})(?!\d)/', $t, $m)) {
            $mo = (int)$m[1]; $y = (int)$m[2];
            if ($mo >= 1 && $mo <= 12) return sprintf('%04d-%02d-01', $y, $mo);
        }
        // YYYY-MM (ex "2026-03", "2026_03")
        if (preg_match('/(20\d{2})[-_\/.](\d{1,2})(?!\d)/', $t, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2];
            if ($mo >= 1 && $mo <= 12) return sprintf('%04d-%02d-01', $y, $mo);
        }

        // 6) MMAAAA / MMAAAA compact (ex "032026" en 6 chiffres) — risqué, en dernier
        if (preg_match('/(?<!\d)(\d{2})(20\d{2})(?!\d)/', $t, $m)) {
            $mo = (int)$m[1]; $y = (int)$m[2];
            if ($mo >= 1 && $mo <= 12) return sprintf('%04d-%02d-01', $y, $mo);
        }

        return null;
    }
}

if (!function_exists('ged_v3_build_canonical_name')) {
    /**
     * Construit le nom canonique selon format option B (underscores partout).
     *
     * @param array{
     *   soc?: string,           // ex "REG" (code société)
     *   age?: string,           // ex "LYO7" (code agence)
     *   n1?: string,            // ex "SYN" ou "04_SYNDIC" (court ou long, auto-slug)
     *   n2?: string,
     *   n3?: string,
     *   n4?: string,
     *   n5?: string,
     *   n6?: string,            // libellé libre user, slugifié
     *   date?: string|DateTimeInterface,  // défaut = aujourd'hui
     *   ext?: string,           // ex "pdf" (sans point)
     * } $parts
     */
    function ged_v3_build_canonical_name(array $parts): string
    {
        $segments = [];
        foreach (['soc', 'age', 'n1', 'n2', 'n3', 'n4', 'n5', 'n6'] as $key) {
            $val = (string)($parts[$key] ?? '');
            $val = ged_v3_slug($val);
            // N4, N5, N6 optionnels → segments vides supprimés
            // N1, N2, N3, SOC, AGE laissés vides → garde la position (placeholder _)
            // SAUF si on est en fin de chaîne avant la date
            $segments[] = $val;
        }

        // Trim des segments vides en fin (avant la date)
        // pour ne pas avoir "REG_LYO7_SYN_TRVX_FACT_____20260512.pdf"
        while (count($segments) > 0 && end($segments) === '') {
            array_pop($segments);
        }

        // Date en dernier
        $date = ged_v3_format_date($parts['date'] ?? null);
        $segments[] = $date;

        // Compose
        $base = implode('_', $segments);
        // Sécurité : compaction des _ multiples
        $base = preg_replace('/_+/', '_', $base) ?? $base;
        $base = trim($base, '_');

        // Extension
        $ext = strtolower(trim((string)($parts['ext'] ?? ''), " \t.\n\r"));
        if ($ext === '') {
            return $base;
        }

        return $base . '.' . $ext;
    }
}

if (!function_exists('ged_v3_parse_canonical_name')) {
    /**
     * Parse inverse : reconstitue les segments depuis un nom canonique.
     * Best effort — utilise la position relative.
     *
     * @return array{soc:string, age:string, n1:string, n2:string, n3:string, n4:string, n5:string, n6:string, date:string, ext:string}
     */
    function ged_v3_parse_canonical_name(string $filename): array
    {
        $result = ['soc'=>'', 'age'=>'', 'n1'=>'', 'n2'=>'', 'n3'=>'', 'n4'=>'', 'n5'=>'', 'n6'=>'', 'date'=>'', 'ext'=>''];

        $name = $filename;
        $dot  = strrpos($name, '.');
        if ($dot !== false) {
            $result['ext'] = strtolower(substr($name, $dot + 1));
            $name = substr($name, 0, $dot);
        }

        $parts = explode('_', $name);
        // Dernier segment = date (YYYYMMDD attendu)
        $last = end($parts);
        if (is_string($last) && preg_match('/^\d{8}$/', $last)) {
            $result['date'] = (string)array_pop($parts);
        }

        // Affecte dans l'ordre soc, age, n1, n2, n3, n4, n5, n6
        $keys = ['soc', 'age', 'n1', 'n2', 'n3', 'n4', 'n5', 'n6'];
        foreach ($keys as $i => $key) {
            if (!isset($parts[$i])) break;
            $result[$key] = (string)$parts[$i];
        }

        return $result;
    }
}

if (!function_exists('ged_v3_preview')) {
    /**
     * Génère une preview du nom canonique pour l'UI (sans extension).
     * Utile pour afficher en live pendant que l'utilisateur saisit.
     */
    function ged_v3_preview(array $parts): string
    {
        $copy = $parts;
        unset($copy['ext']);
        return ged_v3_build_canonical_name($copy);
    }
}
