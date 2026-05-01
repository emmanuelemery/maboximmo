<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Extraction texte natif PDF (sans IA).
 * Fichier : modules/ged/ged_pdf_text.php
 *
 * Étape 1 de la cascade OCR :
 *   PDF texte → extraction native → si non vide, on saute la vision IA (gratuit + instant)
 *   PDF scan / image → cette fonction retourne null → caller passe à la vision
 *
 * Stratégies (ordre de tentative) :
 *   1. Binaire `pdftotext` (Poppler) si présent — meilleure qualité
 *   2. Pure PHP (parsing minimaliste des streams BT/ET) — fallback dégradé
 *
 * Sortie : string|null (null = échec/PDF scanné/binaire absent → vision IA à prendre la suite).
 */

/**
 * Tente d'extraire le texte d'un PDF natif.
 * Retourne null si pas de texte exploitable (PDF image, pdftotext absent et pure-PHP KO).
 */
function gedPdfExtractText(string $pdfPath, int $minChars = 50): ?string
{
    if (!is_file($pdfPath)) {
        throw new InvalidArgumentException("PDF introuvable : {$pdfPath}");
    }

    // 1. Tentative pdftotext (Poppler)
    $text = ged_pdf_text_via_pdftotext($pdfPath);
    if ($text !== null && mb_strlen(trim($text)) >= $minChars) {
        return $text;
    }

    // 2. Fallback pure PHP (très basique)
    $text = ged_pdf_text_via_php($pdfPath);
    if ($text !== null && mb_strlen(trim($text)) >= $minChars) {
        return $text;
    }

    return null;
}

/**
 * Extraction via binaire pdftotext si présent dans le PATH système.
 * Sur Hostinger mutualisé, peu probable d'être dispo. Sur un VPS ou local Linux, OK.
 */
function ged_pdf_text_via_pdftotext(string $pdfPath): ?string
{
    if (!function_exists('shell_exec')) return null;

    // Détection présence binaire (ne génère pas d'erreur si absent)
    $which = stripos(PHP_OS_FAMILY, 'Windows') !== false
        ? @shell_exec('where pdftotext 2>NUL')
        : @shell_exec('command -v pdftotext 2>/dev/null');
    if (!is_string($which) || trim($which) === '') return null;

    $cmd = sprintf('pdftotext -layout -enc UTF-8 %s -', escapeshellarg($pdfPath));
    $out = @shell_exec($cmd);
    if (!is_string($out)) return null;

    // Normalise EOL
    $out = str_replace(["\r\n", "\r"], "\n", $out);
    return $out;
}

/**
 * Fallback pure-PHP : parse les blocs BT...ET et extrait les chaînes (Tj, TJ).
 * Très basique — ne gère pas les PDFs avec compression Flate complexe ou CMaps.
 * Suffit pour des PDFs texte natifs simples (factures, relevés, attestations).
 */
function ged_pdf_text_via_php(string $pdfPath): ?string
{
    $raw = @file_get_contents($pdfPath);
    if (!is_string($raw) || $raw === '') return null;

    // Décompresse les streams Flate (zlib) — best effort
    $raw = preg_replace_callback(
        '/stream\r?\n(.*?)\r?\nendstream/s',
        function ($m) {
            $inflated = @gzuncompress($m[1]);
            if (is_string($inflated) && $inflated !== '') return "stream\n" . $inflated . "\nendstream";
            return $m[0];
        },
        $raw
    ) ?? $raw;

    $texts = [];
    if (preg_match_all('/BT(.*?)ET/s', $raw, $blocks)) {
        foreach ($blocks[1] as $block) {
            // Tj : chaîne entre parenthèses
            if (preg_match_all('/\((.*?)\)\s*Tj/s', $block, $m1)) {
                foreach ($m1[1] as $s) $texts[] = ged_pdf_unescape($s);
            }
            // TJ : tableau de chaînes
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $m2)) {
                foreach ($m2[1] as $arr) {
                    if (preg_match_all('/\((.*?)\)/s', $arr, $sub)) {
                        foreach ($sub[1] as $s) $texts[] = ged_pdf_unescape($s);
                    }
                }
            }
        }
    }

    if (empty($texts)) return null;

    $out = trim(implode("\n", $texts));
    return $out !== '' ? $out : null;
}

/** Décode les escape PostScript courants : \(, \), \\, \n, \r, \t, \\octal. */
function ged_pdf_unescape(string $s): string
{
    return preg_replace_callback(
        '/\\\\([\(\)\\\\nrtbf]|\d{1,3})/',
        function ($m) {
            $c = $m[1];
            switch ($c) {
                case 'n': return "\n";
                case 'r': return "\r";
                case 't': return "\t";
                case 'b': return "\b";
                case 'f': return "\f";
                case '(': return '(';
                case ')': return ')';
                case '\\': return '\\';
            }
            if (ctype_digit($c)) {
                return chr((int)octdec($c));
            }
            return $m[0];
        },
        $s
    ) ?? $s;
}

/**
 * Convertit une 1re page PDF en image PNG pour vision IA.
 * Nécessite Imagick + Ghostscript. Retourne le chemin PNG ou null si pas dispo.
 */
function gedPdfFirstPageToPng(string $pdfPath, ?string $destPng = null, int $dpi = 200): ?string
{
    if (!extension_loaded('imagick')) return null;
    $destPng = $destPng ?: tempnam(sys_get_temp_dir(), 'gedpdf_') . '.png';

    try {
        $im = new Imagick();
        $im->setResolution($dpi, $dpi);
        $im->readImage($pdfPath . '[0]'); // page 1
        $im->setImageFormat('png');
        $im->writeImage($destPng);
        $im->clear(); $im->destroy();
        return is_file($destPng) ? $destPng : null;
    } catch (Throwable $e) {
        @unlink($destPng);
        return null;
    }
}
