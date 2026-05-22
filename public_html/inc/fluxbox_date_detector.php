<?php
declare(strict_types=1);

/**
 * FluxBox — détection robuste de la date d'un document.
 *
 * Stratégie : on essaie plusieurs sources dans l'ordre de fiabilité descendante,
 * et on retourne la première date trouvée. JAMAIS la date du jour comme fallback
 * "présumé" — on retourne null et l'appelant signale "date à vérifier".
 *
 * Sources (par ordre de priorité) :
 *  1. Saisie utilisateur explicite (target_date)
 *  2. Extraction depuis nom de fichier (regex sur ddmmyyyy, yyyymmdd, releve_032025, etc.)
 *  3. Extraction depuis contenu :
 *     - .pdf → métadonnées CreationDate via smalot/pdfparser
 *     - .msg → date d'envoi via fluxbox_msg_parser
 *     - .pdf/image avec OCR → regex sur le texte OCR (fluxbox_documents.ocr_text)
 *  4. filemtime du fichier physique (peut être pertinent si import récent)
 *
 * Renvoie YYYY-MM-DD ou null si aucune source ne donne de date plausible.
 *
 * Validé EMERY 2026-05-16.
 */

if (!function_exists('fluxbox_detect_document_date')) {
    /**
     * @param array{
     *   path?:string,            // chemin physique du fichier
     *   filename?:string,        // nom original (avec extension)
     *   mime_type?:string,
     *   ocr_text?:string,        // texte OCR si déjà extrait
     *   user_date?:string,       // YYYY-MM-DD saisi par user
     * } $input
     * @return array{date:?string, source:string, confidence:int}
     */
    function fluxbox_detect_document_date(array $input): array
    {
        $filename = (string)($input['filename'] ?? '');
        $path     = (string)($input['path'] ?? '');
        $mime     = strtolower((string)($input['mime_type'] ?? ''));
        $ocrText  = (string)($input['ocr_text'] ?? '');
        $userDate = trim((string)($input['user_date'] ?? ''));

        // 1. Saisie utilisateur explicite (confiance max)
        if ($userDate !== '') {
            $iso = fluxbox_normalize_date_iso($userDate);
            if ($iso !== null) {
                return ['date' => $iso, 'source' => 'user_input', 'confidence' => 100];
            }
        }

        // 2. Extraction depuis le nom de fichier
        if ($filename !== '' && function_exists('ged_v3_extract_date_from_text')) {
            $extracted = ged_v3_extract_date_from_text($filename);
            if ($extracted) {
                return ['date' => $extracted, 'source' => 'filename_regex', 'confidence' => 85];
            }
        }
        // Fallback regex maison si ged_v3 indispo
        $fromName = fluxbox_extract_date_from_filename($filename);
        if ($fromName !== null) {
            return ['date' => $fromName, 'source' => 'filename_regex', 'confidence' => 85];
        }

        // 3a. PDF : métadonnées via smalot/pdfparser
        if ($path !== '' && is_file($path)) {
            $ext = strtolower(pathinfo($filename ?: $path, PATHINFO_EXTENSION));
            if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
                $pdfDate = fluxbox_extract_date_from_pdf($path);
                if ($pdfDate !== null) {
                    return ['date' => $pdfDate, 'source' => 'pdf_metadata', 'confidence' => 90];
                }
            }
            // 3b. .msg : date d'envoi via parser msg
            if ($ext === 'msg' || str_contains($mime, 'outlook')) {
                $msgDate = fluxbox_extract_date_from_msg($path);
                if ($msgDate !== null) {
                    return ['date' => $msgDate, 'source' => 'msg_sent_date', 'confidence' => 95];
                }
            }
        }

        // 3c. OCR text si dispo
        if ($ocrText !== '') {
            $ocrDate = fluxbox_extract_date_from_text_content($ocrText);
            if ($ocrDate !== null) {
                return ['date' => $ocrDate, 'source' => 'ocr_content', 'confidence' => 70];
            }
        }

        // 4. filemtime (confiance basse — souvent c'est la date d'upload)
        if ($path !== '' && is_file($path)) {
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $iso = date('Y-m-d', $mtime);
                // Ne retourner que si différent de aujourd'hui
                if ($iso !== date('Y-m-d')) {
                    return ['date' => $iso, 'source' => 'file_mtime', 'confidence' => 40];
                }
            }
        }

        // Aucune source fiable — l'appelant décide quoi faire (afficher "date à vérifier")
        return ['date' => null, 'source' => 'none', 'confidence' => 0];
    }
}

if (!function_exists('fluxbox_normalize_date_iso')) {
    /**
     * Normalise une date en YYYY-MM-DD. Accepte ISO, JJ/MM/AAAA, JJ-MM-AAAA, etc.
     */
    function fluxbox_normalize_date_iso(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') return null;
        // Déjà ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
        // JJ/MM/AAAA ou JJ-MM-AAAA
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        // JJ/MM/AA → suppose 20XX
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})$/', $date, $m)) {
            $year = (int)$m[3];
            $year += ($year < 70 ? 2000 : 1900);
            return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[1]);
        }
        // YYYY/MM/DD
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        // Fallback : strtotime
        $ts = strtotime($date);
        if ($ts !== false) return date('Y-m-d', $ts);
        return null;
    }
}

if (!function_exists('fluxbox_extract_date_from_filename')) {
    /**
     * Extrait une date à partir du nom de fichier via patterns courants.
     * Ex : "releve_032026.pdf", "bulletin_decembre_2023.pdf", "20240315_facture.pdf"
     */
    function fluxbox_extract_date_from_filename(string $filename): ?string
    {
        if ($filename === '') return null;
        // 1. ISO complet : 2024-03-15 ou 20240315
        if (preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})/', $filename, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            if (checkdate($mo, $d, $y) && $y >= 2000 && $y <= (int)date('Y') + 1) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        // 2. JJ-MM-AAAA ou JJ_MM_AAAA dans le nom
        if (preg_match('/(\d{2})[-_](\d{2})[-_](\d{4})/', $filename, $m)) {
            $y = (int)$m[3]; $mo = (int)$m[2]; $d = (int)$m[1];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        // 3. MMYYYY (ex releve_032026) → 1er du mois
        if (preg_match('/(?<!\d)(\d{2})(\d{4})(?!\d)/', $filename, $m)) {
            $mo = (int)$m[1]; $y = (int)$m[2];
            if ($mo >= 1 && $mo <= 12 && $y >= 2000 && $y <= (int)date('Y') + 1) {
                return sprintf('%04d-%02d-01', $y, $mo);
            }
        }
        // 4. Nom de mois français + année
        $monthsFr = [
            'janvier'=>1, 'fevrier'=>2, 'février'=>2, 'mars'=>3, 'avril'=>4,
            'mai'=>5, 'juin'=>6, 'juillet'=>7, 'aout'=>8, 'août'=>8,
            'septembre'=>9, 'octobre'=>10, 'novembre'=>11, 'decembre'=>12, 'décembre'=>12,
        ];
        foreach ($monthsFr as $name => $num) {
            if (preg_match('/' . $name . '[\s_-]*(\d{4})/i', $filename, $m)) {
                $y = (int)$m[1];
                if ($y >= 2000 && $y <= (int)date('Y') + 1) {
                    return sprintf('%04d-%02d-01', $y, $num);
                }
            }
        }
        return null;
    }
}

if (!function_exists('fluxbox_extract_date_from_pdf')) {
    /**
     * Extrait CreationDate ou ModDate depuis les métadonnées PDF via smalot/pdfparser.
     */
    function fluxbox_extract_date_from_pdf(string $path): ?string
    {
        if (!class_exists('Smalot\\PdfParser\\Parser')) {
            $autoload = __DIR__ . '/../../vendor/autoload.php';
            if (!is_file($autoload)) return null;
            require_once $autoload;
            if (!class_exists('Smalot\\PdfParser\\Parser')) return null;
        }
        try {
            $parser = new Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $details = $pdf->getDetails();
            // smalot retourne CreationDate au format ISO ou D:YYYYMMDDhhmmss
            foreach (['CreationDate', 'ModDate'] as $key) {
                if (!empty($details[$key])) {
                    $raw = (string)$details[$key];
                    // Format PDF "D:YYYYMMDD..." ou "YYYY-MM-DD..."
                    if (preg_match('/(\d{4})[-]?(\d{2})[-]?(\d{2})/', $raw, $m)) {
                        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
                        if (checkdate($mo, $d, $y) && $y >= 1990 && $y <= (int)date('Y') + 1) {
                            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
                        }
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }
}

if (!function_exists('fluxbox_extract_date_from_msg')) {
    /**
     * Extrait la date d'envoi du .msg via le parser FluxBox.
     */
    function fluxbox_extract_date_from_msg(string $path): ?string
    {
        $parserFile = __DIR__ . '/fluxbox_msg_parser.php';
        if (is_file($parserFile)) require_once $parserFile;
        if (!function_exists('fluxbox_msg_parse')) return null;
        try {
            $parsed = fluxbox_msg_parse($path);
            if (!empty($parsed['date_sent'])) {
                return fluxbox_normalize_date_iso((string)$parsed['date_sent']);
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }
}

if (!function_exists('fluxbox_extract_date_from_text_content')) {
    /**
     * Cherche une date dans un bloc de texte (OCR, body mail, etc.).
     * Préfère les dates avec contexte (« Le 15/03/2024 », « Date d'émission : »).
     */
    function fluxbox_extract_date_from_text_content(string $text): ?string
    {
        if ($text === '') return null;
        // 1. Patterns avec contexte (Date, Émis le, Période)
        $contextPatterns = [
            '/(?:date|émis|emis|fait|établi|etabli)\s*(?:le|au|du)?\s*[:\-]?\s*(\d{1,2}[\/\-\s]\d{1,2}[\/\-\s]\d{2,4})/iu',
            '/(?:période|periode)\s*[:\-]?\s*\w+\s+(\d{4})/iu',
        ];
        foreach ($contextPatterns as $p) {
            if (preg_match($p, $text, $m)) {
                $iso = fluxbox_normalize_date_iso($m[1]);
                if ($iso) return $iso;
            }
        }
        // 2. Dates "nues" — première date plausible
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $text, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            if (checkdate($mo, $d, $y) && $y >= 2000 && $y <= (int)date('Y') + 1) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        return null;
    }
}
