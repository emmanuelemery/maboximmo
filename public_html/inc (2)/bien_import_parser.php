<?php
declare(strict_types=1);

/**
 * BienImportParser — Extraction de texte depuis un PDF
 *
 * Stratégie :
 *  1. pdftotext (Poppler) — meilleur résultat
 *  2. Extraction brute des streams PDF (fallback pur PHP)
 */
class BienImportParser
{
    // Chemin pdftotext détecté sur ce serveur
    private const PDFTOTEXT_PATHS = [
        'C:\\poppler\\Library\\bin\\pdftotext.exe',
        'C:\\Program Files\\xpdf-tools\\bin64\\pdftotext.exe',
        'C:\\Program Files (x86)\\xpdf-tools\\bin32\\pdftotext.exe',
        '/usr/bin/pdftotext',
        '/usr/local/bin/pdftotext',
    ];

    /**
     * Extrait le texte d'un fichier PDF.
     * Retourne le texte nettoyé ou une chaîne vide si l'extraction échoue.
     */
    public static function extractText(string $filePath): string
    {
        if (!is_readable($filePath)) {
            return '';
        }

        // Tentative 1 : pdftotext
        $text = self::viaPdfToText($filePath);

        // Tentative 2 : extraction brute PHP
        if (trim($text) === '') {
            $text = self::viaRawExtract($filePath);
        }

        return self::cleanText($text);
    }

    // ── pdftotext ────────────────────────────────────────────────
    private static function viaPdfToText(string $path): string
    {
        $exe = self::findPdfToText();
        if (!$exe) return '';

        // proc_open doit être disponible
        if (!function_exists('proc_open')) return '';

        $tmp  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'biimport_' . md5($path) . '.txt';
        $cmd  = '"' . $exe . '" -q -layout -enc UTF-8 "' . $path . '" "' . $tmp . '"';

        $desc = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];
        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) { @unlink($tmp); return ''; }

        fclose($pipes[0]);
        // Non-bloquant pour éviter le gel sur les buffers
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 15.0; // timeout 15 s
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                $pid = (int)$status['pid'];
                // Sur Windows proc_terminate ne tue pas — on utilise taskkill
                if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
                    exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
                } else {
                    proc_terminate($proc, 9);
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                @unlink($tmp);
                return '';
            }
            usleep(50_000); // 50 ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if (is_readable($tmp)) {
            $text = file_get_contents($tmp) ?: '';
            @unlink($tmp);
            return $text;
        }
        @unlink($tmp);
        return '';
    }

    private static function findPdfToText(): ?string
    {
        foreach (self::PDFTOTEXT_PATHS as $p) {
            if (is_executable($p) || (PHP_OS_FAMILY === 'Windows' && file_exists($p))) {
                return $p;
            }
        }
        return null;
    }

    // ── Extraction brute des streams PDF ─────────────────────────
    private static function viaRawExtract(string $path): string
    {
        // Limite à 3 Mo pour éviter les traitements trop longs
        $raw = file_get_contents($path, false, null, 0, 3 * 1024 * 1024);
        if (!$raw) return '';

        $text = '';

        // Décompresse les streams zlib — on travaille segment par segment
        // pour éviter les regex dotall sur tout le fichier binaire
        $decoded = '';
        $offset  = 0;
        while (($start = strpos($raw, 'stream', $offset)) !== false) {
            $start += 6;
            // Saute \r\n ou \n
            if (isset($raw[$start]) && $raw[$start] === "\r") $start++;
            if (isset($raw[$start]) && $raw[$start] === "\n") $start++;
            $end = strpos($raw, 'endstream', $start);
            if ($end === false) break;
            $chunk = substr($raw, $start, $end - $start);
            $offset = $end + 9;
            if (function_exists('gzuncompress')) {
                $dec = @gzuncompress($chunk);
                if ($dec !== false && strlen($dec) < 512 * 1024) {
                    $decoded .= $dec . "\n";
                }
            }
        }

        $haystack = $decoded ?: $raw;

        // Extrait les blocs BT…ET — on cherche manuellement pour éviter
        // le backtracking catastrophique des regex dotall sur binaire
        $pos = 0;
        while (($btPos = strpos($haystack, 'BT', $pos)) !== false) {
            $etPos = strpos($haystack, 'ET', $btPos + 2);
            if ($etPos === false) break;
            $block = substr($haystack, $btPos + 2, $etPos - $btPos - 2);
            $pos   = $etPos + 2;

            // Limite la taille d'un bloc (sécurité)
            if (strlen($block) > 50_000) continue;

            // Tj : (texte) Tj
            preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)\s*T[j\']/s', $block, $tj);
            foreach ($tj[1] as $s) {
                $text .= self::decodePdfStr($s) . ' ';
            }

            // TJ : [(texte)] TJ
            preg_match_all('/\[([^\[\]]*)\]\s*TJ/', $block, $arrTj);
            foreach ($arrTj[1] as $arr) {
                preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)/', $arr, $parts);
                foreach ($parts[1] as $p) {
                    $text .= self::decodePdfStr($p);
                }
                $text .= ' ';
            }
        }

        // Fallback minimal si toujours vide
        if (trim($text) === '') {
            preg_match_all('/\(([\x20-\x7E]{4,})\)/', substr($haystack, 0, 200_000), $all);
            $text = implode(' ', $all[1]);
        }

        return $text;
    }

    private static function decodePdfStr(string $s): string
    {
        // Octal escapes
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn($m) => chr(octdec($m[1])), $s);
        // Backslash sequences
        $s = str_replace(['\\n','\\r','\\t','\\\\','\\(','\\)'], ["\n","\r","\t",'\\','(',')'], $s);
        // Latin-1 → UTF-8 si nécessaire
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $s;
    }

    // ── Extraction des photos embarquées dans le PDF ─────────────
    /**
     * Extrait les images JPEG embarquées directement depuis le binaire PDF.
     * Ne nécessite ni Imagick ni Poppler — fonctionne avec GD seul.
     *
     * @param string $pdfPath   Chemin vers le fichier PDF
     * @param string $destDir   Dossier où sauvegarder les JPEGs extraits
     * @param int    $minBytes  Taille minimale JPEG acceptable (évite les miniatures)
     * @param int    $maxPhotos Nombre maximum de photos à extraire
     * @return array  Liste de chemins relatifs (depuis public_html) des photos extraites
     */
    public static function extractPhotos(string $pdfPath, string $destDir, int $minBytes = 8000, int $maxPhotos = 10): array
    {
        if (!is_readable($pdfPath)) return [];
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) return [];

        $raw = file_get_contents($pdfPath);
        if (!$raw) return [];

        $photos  = [];
        $offset  = 0;
        $raw_len = strlen($raw);

        // Cherche toutes les occurrences du marqueur SOI JPEG (FF D8 FF)
        while ($offset < $raw_len && count($photos) < $maxPhotos) {
            $soi = strpos($raw, "\xFF\xD8\xFF", $offset);
            if ($soi === false) break;

            // Cherche le marqueur EOI JPEG (FF D9) — on cherche après un minimum raisonnable
            $search_from = $soi + 100;
            $eoi = false;
            // Cherche le dernier EOI plausible dans les 15 Mo suivants
            $search_end = min($soi + 15 * 1024 * 1024, $raw_len);
            $pos = $search_from;
            while ($pos < $search_end) {
                $found = strpos($raw, "\xFF\xD9", $pos);
                if ($found === false) break;
                $eoi = $found + 2; // on inclut FF D9
                $pos = $found + 2;
            }

            if ($eoi === false) { $offset = $soi + 3; continue; }

            $jpeg = substr($raw, $soi, $eoi - $soi);
            $size = strlen($jpeg);

            if ($size >= $minBytes) {
                // Vérifie que c'est un JPEG valide via GD
                if (function_exists('imagecreatefromstring')) {
                    $img = @imagecreatefromstring($jpeg);
                    if ($img !== false) {
                        $w = imagesx($img);
                        $h = imagesy($img);
                        imagedestroy($img);
                        // Filtre les images trop petites (icônes, logos)
                        if ($w >= 200 && $h >= 150) {
                            $fname  = 'photo_' . sprintf('%02d', count($photos) + 1) . '_' . bin2hex(random_bytes(4)) . '.jpg';
                            $fpath  = $destDir . $fname;
                            if (file_put_contents($fpath, $jpeg) !== false) {
                                $photos[] = $fpath;
                            }
                        }
                    }
                } else {
                    // Sans GD, on sauvegarde les JPEGs assez gros directement
                    if ($size >= 30000) {
                        $fname  = 'photo_' . sprintf('%02d', count($photos) + 1) . '_' . bin2hex(random_bytes(4)) . '.jpg';
                        if (file_put_contents($destDir . $fname, $jpeg) !== false) {
                            $photos[] = $destDir . $fname;
                        }
                    }
                }
            }

            $offset = $soi + 3;
        }

        return $photos;
    }

    // ── Nettoyage du texte extrait ───────────────────────────────
    public static function cleanText(string $text): string
    {
        // Supprime caractères de contrôle sauf sauts de ligne
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
        // Normalise les espaces multiples
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        // Max 3 sauts de ligne consécutifs
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
