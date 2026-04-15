<?php
declare(strict_types=1);
require_once __DIR__ . '/ik_bareme.php';

function rh_text_is_blank(string $text): bool
{
    if ($text === '') return true;
    $clean = preg_replace('/[\s\x0C]+/u', '', $text);
    return $clean === '' || $clean === null;
}

function rh_clean_vehicle_value(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    $v = preg_replace('/^[=:\-\s]+/', '', $v);
    $v = preg_replace('/\s{2,}/', ' ', $v);
    return $v !== '' ? $v : null;
}

function rh_is_noise_value(?string $v): bool
{
    if ($v === null) return true;
    $v = trim($v);
    if ($v === '') return true;
    if (preg_match('/^I\s*=/i', $v)) return true;
    $alnum = preg_replace('/[^A-Za-z0-9]/', '', $v);
    $len = strlen($alnum);
    if ($len >= 10) {
        $vowels = preg_match_all('/[AEIOUYaeiouy]/', $v);
        if ($vowels < 2) return true;
    }
    if ($len >= 14 && preg_match('/^[A-Z0-9]+$/', $alnum)) {
        return true;
    }
    return false;
}

function rh_detect_brand_from_text(string $text): ?string
{
    $patterns = [
        '/\bTESLA\b/i' => 'TESLA',
        '/\bRENAULT\b/i' => 'RENAULT',
        '/\bPEUGEOT\b/i' => 'PEUGEOT',
        '/\bCITRO[ËE]N\b/i' => 'CITROEN',
        '/\bVOLKSWAGEN\b/i' => 'VOLKSWAGEN',
        '/\bVW\b/i' => 'VOLKSWAGEN',
        '/\bBMW\b/i' => 'BMW',
        '/\bMERCEDES(?:-BENZ)?\b/i' => 'MERCEDES',
        '/\bAUDI\b/i' => 'AUDI',
        '/\bTOYOTA\b/i' => 'TOYOTA',
        '/\bNISSAN\b/i' => 'NISSAN',
        '/\bFORD\b/i' => 'FORD',
        '/\bOPEL\b/i' => 'OPEL',
        '/\bFIAT\b/i' => 'FIAT',
        '/\bKIA\b/i' => 'KIA',
        '/\bHYUNDAI\b/i' => 'HYUNDAI',
        '/\bVOLVO\b/i' => 'VOLVO',
        '/\bSKODA\b/i' => 'SKODA',
        '/\bSEAT\b/i' => 'SEAT',
        '/\bDACIA\b/i' => 'DACIA',
        '/\bMINI\b/i' => 'MINI',
        '/\bJEEP\b/i' => 'JEEP',
        '/\bLAND\s*ROVER\b/i' => 'LAND ROVER',
        '/\bPORSCHE\b/i' => 'PORSCHE',
        '/\bMAZDA\b/i' => 'MAZDA',
        '/\bHONDA\b/i' => 'HONDA',
        '/\bSUZUKI\b/i' => 'SUZUKI',
        '/\bMITSUBISHI\b/i' => 'MITSUBISHI',
        '/\bLEXUS\b/i' => 'LEXUS',
        '/\bALFA\s*ROMEO\b/i' => 'ALFA ROMEO',
        '/\bCHEVROLET\b/i' => 'CHEVROLET',
        '/\bSMART\b/i' => 'SMART',
        '/\bIVECO\b/i' => 'IVECO',
        '/\bMAN\b/i' => 'MAN',
        '/\bSCANIA\b/i' => 'SCANIA',
    ];
    foreach ($patterns as $pat => $brand) {
        if (preg_match($pat, $text)) return $brand;
    }
    return null;
}

function rh_allow_scanned_ocr(): bool
{
    $env = getenv('RH_ALLOW_SCANNED_OCR') ?: ($_SERVER['RH_ALLOW_SCANNED_OCR'] ?? '') ?: ($_ENV['RH_ALLOW_SCANNED_OCR'] ?? '');
    if ($env === '' || $env === null) return false;
    $v = strtolower(trim((string)$env));
    if (in_array($v, ['0','false','off','no'], true)) return false;
    return true;
}

// Default POPPLER_BIN for Windows (fallback if Apache PATH is missing)
if (stripos(PHP_OS, 'WIN') === 0) {
    $defaultPoppler = 'C:/poppler/Library/bin';
    if (!getenv('POPPLER_BIN') && is_dir($defaultPoppler)) {
        putenv('POPPLER_BIN=' . $defaultPoppler);
        $_ENV['POPPLER_BIN'] = $defaultPoppler;
        $_SERVER['POPPLER_BIN'] = $defaultPoppler;
    }
}

function rh_shell_exec_enabled(): bool
{
    $disabled = ini_get('disable_functions');
    if (!is_string($disabled) || trim($disabled) === '') {
        return true;
    }
    $list = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $list, true);
}

function rh_shell_quote(string $arg): string
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        return '"' . str_replace('"', '\\"', $arg) . '"';
    }
    return escapeshellarg($arg);
}

function rh_find_cmd(string $cmd): ?string
{
    $cmd = trim($cmd);
    if ($cmd === '') return null;

    static $cache = [];
    if (array_key_exists($cmd, $cache)) {
        return $cache[$cmd];
    }

    $found = null;
    $isWin = stripos(PHP_OS, 'WIN') === 0;

    // Env override: e.g. TESSERACT_BIN or POPPLER_BIN
    $envKey = strtoupper($cmd) . '_BIN';
    $env = getenv($envKey) ?: ($_SERVER[$envKey] ?? '') ?: ($_ENV[$envKey] ?? '');
    if (!$env && ($cmd === 'pdftotext' || $cmd === 'pdftoppm')) {
        $env = getenv('POPPLER_BIN') ?: ($_SERVER['POPPLER_BIN'] ?? '') ?: ($_ENV['POPPLER_BIN'] ?? '');
    }
    if (!$env && $cmd === 'tesseract') {
        $env = getenv('TESSERACT_BIN') ?: ($_SERVER['TESSERACT_BIN'] ?? '') ?: ($_ENV['TESSERACT_BIN'] ?? '');
    }
    if (is_string($env) && trim($env) !== '') {
        $env = trim($env);
        if (is_dir($env)) {
            $env = rtrim($env, "\\/") . DIRECTORY_SEPARATOR . $cmd . ($isWin ? '.exe' : '');
        }
        if (is_file($env)) {
            $found = $env;
        }
    }

    if ($found === null && rh_shell_exec_enabled()) {
        $check = $isWin ? 'where' : 'command -v';
        $out = @shell_exec($check . ' ' . rh_shell_quote($cmd));
        if (is_string($out) && !rh_text_is_blank($out)) {
            $line = trim(strtok($out, "\r\n"));
            if ($line !== '') {
                $found = $line;
            }
        }
    }

    if ($found === null) {
        if ($isWin) {
            $candidates = [];
            $add = function ($p) use (&$candidates) {
                if ($p && !in_array($p, $candidates, true)) {
                    $candidates[] = $p;
                }
            };

            if ($cmd === 'tesseract') {
                $add('C:/Program Files/Tesseract-OCR/tesseract.exe');
                $add('C:/Program Files (x86)/Tesseract-OCR/tesseract.exe');
            } elseif ($cmd === 'pdftotext' || $cmd === 'pdftoppm') {
                $bases = [
                    'C:/Program Files',
                    'C:/Program Files (x86)',
                    'C:/',
                    'D:/'
                ];
                foreach ($bases as $base) {
                    $add($base . '/poppler/Library/bin/' . $cmd . '.exe');
                    $add($base . '/poppler/bin/' . $cmd . '.exe');
                    foreach (glob($base . '/poppler*', GLOB_ONLYDIR) ?: [] as $dir) {
                        $add($dir . '/Library/bin/' . $cmd . '.exe');
                        $add($dir . '/bin/' . $cmd . '.exe');
                    }
                    foreach (glob($base . '/*poppler*', GLOB_ONLYDIR) ?: [] as $dir) {
                        $add($dir . '/Library/bin/' . $cmd . '.exe');
                        $add($dir . '/bin/' . $cmd . '.exe');
                    }
                }
                $add('C:/tools/poppler/Library/bin/' . $cmd . '.exe');
                $add('C:/tools/poppler/bin/' . $cmd . '.exe');
            }

            foreach ($candidates as $p) {
                if (is_file($p)) { $found = $p; break; }
            }
        } else {
            foreach (['/usr/bin', '/usr/local/bin'] as $dir) {
                $p = $dir . '/' . $cmd;
                if (is_file($p)) { $found = $p; break; }
            }
        }
    }

    $cache[$cmd] = $found;
    return $found;
}

function rh_cmd_exists(string $cmd): bool
{
    return rh_find_cmd($cmd) !== null;
}

function rh_run_tesseract(string $path, string $lang = '', string $extra = ''): string
{
    $bin = rh_find_cmd('tesseract');
    if (!$bin) return '';
    $isWin = stripos(PHP_OS, 'WIN') === 0;
    $nullRedir = $isWin ? '2>NUL' : '2>/dev/null';
    $cmd = rh_shell_quote($bin) . ' ' . rh_shell_quote($path) . ' stdout';
    if ($lang !== '') {
        $cmd .= ' -l ' . rh_shell_quote($lang);
    }
    if ($extra !== '') {
        $cmd .= ' ' . $extra;
    }
    $cmd .= ' ' . $nullRedir;
    return (string)@shell_exec($cmd);
}

function rh_try_tesseract(string $path, array $langs, array $psms): string
{
    foreach ($psms as $psm) {
        $extra = '--oem 1 --psm ' . $psm;
        foreach ($langs as $lang) {
            $out = rh_run_tesseract($path, $lang, $extra);
            if (!rh_text_is_blank($out)) return $out;
        }
    }
    foreach ($langs as $lang) {
        $out = rh_run_tesseract($path, $lang);
        if (!rh_text_is_blank($out)) return $out;
    }
    return '';
}

function rh_extract_text_from_file(string $path, string $mime, array &$meta = null): string
{
    if (!is_file($path)) return '';
    $text = '';

    $isWin = stripos(PHP_OS, 'WIN') === 0;
    $nullRedir = $isWin ? '2>NUL' : '2>/dev/null';
    $langs = ['fra+eng', 'fra', 'eng', ''];
    $psms = ['6', '11', '3'];

    $binPdftotext = rh_find_cmd('pdftotext');
    $binPdftoppm = rh_find_cmd('pdftoppm');
    $binTess = rh_find_cmd('tesseract');

    $meta = [
        'shell_exec' => rh_shell_exec_enabled(),
        'has_pdftotext' => $binPdftotext !== null,
        'has_tesseract' => $binTess !== null,
        'has_pdftoppm' => $binPdftoppm !== null,
        'engine' => null,
        'scanned_blocked' => false,
        'text_len' => 0,
        'pages' => 0,
    ];

    if (!$meta['shell_exec']) {
        return '';
    }

    if ($mime === 'application/pdf') {
        if ($binPdftotext) {
            $cmd = rh_shell_quote($binPdftotext) . ' -layout ' . rh_shell_quote($path) . ' - ' . $nullRedir;
            $text = (string)@shell_exec($cmd);
            if (!rh_text_is_blank($text)) {
                $meta['engine'] = 'pdftotext';
            } else {
                $text = '';
            }
        }

        if (rh_text_is_blank($text) && !rh_allow_scanned_ocr()) {
            $meta['scanned_blocked'] = true;
            return '';
        }

        if (rh_text_is_blank($text) && $binPdftoppm && $binTess) {
            $tmp = sys_get_temp_dir();
            $prefix = $tmp . DIRECTORY_SEPARATOR . 'cg_' . uniqid();
            $cmd = rh_shell_quote($binPdftoppm) . ' -gray -r 400 -f 1 -l 2 -png ' . rh_shell_quote($path) . ' ' . rh_shell_quote($prefix) . ' ' . $nullRedir;
            @shell_exec($cmd);
            $pages = [];
            for ($i = 1; $i <= 2; $i++) {
                $img = $prefix . '-' . $i . '.png';
                if (!is_file($img)) continue;
                $chunk = rh_try_tesseract($img, $langs, $psms);
                if (!rh_text_is_blank($chunk)) {
                    $pages[] = $chunk;
                    $meta['engine'] = 'pdftoppm+tesseract';
                    $meta['pages'] = $i;
                }
                @unlink($img);
            }
            if ($pages) {
                $text = implode("\n", $pages);
            }
        }

        if (rh_text_is_blank($text) && $binTess) {
            $text = rh_try_tesseract($path, $langs, $psms);
            if (!rh_text_is_blank($text)) {
                $meta['engine'] = 'tesseract';
            }
        }
    } elseif (preg_match('/^image\//', $mime)) {
        if (!rh_allow_scanned_ocr()) {
            $meta['scanned_blocked'] = true;
            return '';
        }
        if ($binTess) {
            $text = rh_try_tesseract($path, $langs, $psms);
            if (!rh_text_is_blank($text)) {
                $meta['engine'] = 'tesseract';
            }
        }
    }

    $text = trim($text, " \t\n\r\0\x0B\x0C");
    $meta['text_len'] = strlen($text);
    return $text;
}

function rh_find_field_in_text(string $text, array $patterns): ?string
{
    foreach ($patterns as $pat) {
        if (preg_match($pat, $text, $m)) {
            $v = trim($m[1] ?? '');
            if ($v !== '') return $v;
        }
    }
    return null;
}

function rh_find_field_from_lines(array $lines, array $patterns): ?string
{
    foreach ($lines as $i => $line) {
        $lineTrim = trim($line);
        if ($lineTrim === '') continue;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $lineTrim, $m)) {
                $rest = trim(preg_replace($pat, '', $lineTrim));
                if ($rest !== '') return $rest;
                for ($j = $i + 1; $j < count($lines); $j++) {
                    $next = trim($lines[$j]);
                    if ($next !== '') return $next;
                }
            }
        }
    }
    return null;
}

function rh_extract_plate(string $text): ?string
{
    $upper = strtoupper($text);
    if (preg_match('/\b([A-Z]{2})[-\s]?([0-9]{3})[-\s]?([A-Z]{2})\b/', $upper, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/\b([A-Z]{2})([0-9]{3})([A-Z]{2})\b/', $upper, $m3)) {
        return $m3[1] . '-' . $m3[2] . '-' . $m3[3];
    }
    if (preg_match('/\b([0-9]{1,4})\s?([A-Z]{2,3})\s?([0-9]{2})\b/', $upper, $m2)) {
        return $m2[1] . ' ' . $m2[2] . ' ' . $m2[3];
    }
    return null;
}

function rh_detect_electric(string $text): bool
{
    return (bool)preg_match('/\b(electrique|electric|energie\s*:\s*el|p\s*\.?\s*3\s*el|ev)\b/i', $text);
}

function rh_parse_carte_grise_text(string $text): array
{
    $text = str_replace("\r", "\n", $text);
    $text = preg_replace("/[\t ]+/", " ", $text);

    $lines = preg_split('/\n+/', $text);
    $lines = array_map('trim', $lines);

    $marque = rh_find_field_from_lines($lines, [
        '/\bD\s*\.?\s*1\b/i',
        '/\bD1\b/i',
        '/\bMARQUE\b/i',
    ]);
    if (!$marque) {
        $marque = rh_find_field_in_text($text, [
            '/\bD\s*\.?\s*1\s*[:\-]?\s*([^\n\r]{2,40})/i',
            '/\bMARQUE\s*[:\-]?\s*([^\n\r]{2,40})/i',
        ]);
    }
    $marque = rh_clean_vehicle_value($marque);
    if (rh_is_noise_value($marque)) $marque = null;
    if (!$marque) {
        $marque = rh_detect_brand_from_text($text);
    }

    $type = rh_find_field_from_lines($lines, [
        '/\bD\s*\.?\s*2\b/i',
        '/\bD2\b/i',
        '/\bD\s*\.?\s*2\s*\.\s*1\b/i',
        '/\bD2\.1\b/i',
        '/\bTYPE\b/i',
        '/\bVARIANTE\b/i',
        '/\bVERSION\b/i',
    ]);
    if (!$type) {
        $type = rh_find_field_in_text($text, [
            '/\bD\s*\.?\s*2(?:\s*\.\s*1)?\s*[:\-]?\s*([^\n\r]{2,60})/i',
            '/\bTYPE\s*[:\-]?\s*([^\n\r]{2,60})/i',
            '/\bVARIANTE\s*[:\-]?\s*([^\n\r]{2,60})/i',
            '/\bVERSION\s*[:\-]?\s*([^\n\r]{2,60})/i',
        ]);
    }
    $type = rh_clean_vehicle_value($type);
    if (rh_is_noise_value($type)) $type = null;

    $denom = rh_find_field_from_lines($lines, [
        '/\bD\s*\.?\s*3\b/i',
        '/\bD3\b/i',
        '/DENOMINATION\s+COMMERCIALE/i',
    ]);
    if (!$denom) {
        $denom = rh_find_field_in_text($text, [
            '/\bD\s*\.?\s*3\s*[:\-]?\s*([^\n\r]{2,60})/i',
            '/DENOMINATION\s+COMMERCIALE\s*[:\-]?\s*([^\n\r]{2,60})/i',
            '/\bMODELE\s*[:\-]?\s*([^\n\r]{2,60})/i',
            '/\bMODEL\s*[:\-]?\s*([^\n\r]{2,60})/i',
        ]);
    }
    $denom = rh_clean_vehicle_value($denom);
    if (rh_is_noise_value($denom)) $denom = null;

    $immat = rh_extract_plate($text);
    if (!$immat) {
        $immat = rh_find_field_in_text($text, [
            '/\bA\s*[:\-]?\s*([A-Z0-9 \-]{4,12})/i',
            '/IMMATRICULATION[^A-Z0-9]{0,6}([A-Z0-9 \-]{4,12})/i',
        ]);
        if ($immat) {
            $immat = rh_extract_plate($immat) ?? trim($immat);
        }
    }

    $cv = null;
    $cvRaw = rh_find_field_in_text($text, [
        '/\bP\s*\.?\s*6\s*[:\-]?\s*([0-9]{1,2})/i',
        '/\bP6\b[^0-9]{0,10}([0-9]{1,2})/i',
        '/PUISSANCE\s+(?:FISCALE|ADMINISTRATIVE)[^0-9]{0,10}([0-9]{1,2})/i',
    ]);
    if ($cvRaw !== null && $cvRaw !== '') {
        $cv = (int)$cvRaw;
    }

    $energie = rh_find_field_from_lines($lines, [
        '/\bP\s*\.?\s*3\b/i',
        '/\bP3\b/i',
        '/\bENERGIE\b/i',
    ]);
    if (!$energie) {
        $energie = rh_find_field_in_text($text, [
            '/\bP\s*\.?\s*3\s*[:\-]?\s*([^\n\r]{1,20})/i',
            '/\bENERGIE\s*[:\-]?\s*([^\n\r]{1,20})/i',
        ]);
    }

    $vehiculeNom = null;
    $parts = [];
    if ($marque) $parts[] = $marque;
    if ($denom) {
        $parts[] = $denom;
    } elseif ($type) {
        $parts[] = $type;
    }
    if ($parts) $vehiculeNom = trim(implode(' ', $parts));

    return [
        'marque' => $marque,
        'type' => $type,
        'denomination' => $denom,
        'immat' => $immat,
        'cv' => $cv,
        'energie' => $energie,
        'vehicule_nom' => $vehiculeNom,
        'is_electric' => rh_detect_electric($text),
    ];
}

function rh_ensure_vehicle_columns(PDO $pdo): void
{
    if (!rh_column_exists($pdo, 'users', 'vehicule_type')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN vehicule_type VARCHAR(120) NULL");
    }
}

function rh_update_vehicle_from_info(PDO $pdo, int $userId, array $info): array
{
    rh_ensure_vehicle_columns($pdo);

    $stmt = $pdo->prepare("SELECT vehicule_nom, vehicule_type, vehicule_puissance_fiscale, vehicule_immat, indemnite_km FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $sets = [];
    $params = [];

    if (!empty($info['vehicule_nom']) && $info['vehicule_nom'] !== ($current['vehicule_nom'] ?? null)) {
        $sets[] = 'vehicule_nom = ?';
        $params[] = $info['vehicule_nom'];
    } elseif (!empty($current['vehicule_nom']) && rh_is_noise_value($current['vehicule_nom'])) {
        $sets[] = 'vehicule_nom = ?';
        $params[] = null;
    }
    if (!empty($info['type']) && $info['type'] !== ($current['vehicule_type'] ?? null)) {
        $sets[] = 'vehicule_type = ?';
        $params[] = $info['type'];
    } elseif (!empty($current['vehicule_type']) && rh_is_noise_value($current['vehicule_type'])) {
        $sets[] = 'vehicule_type = ?';
        $params[] = null;
    }
    if (!empty($info['immat']) && $info['immat'] !== ($current['vehicule_immat'] ?? null)) {
        $sets[] = 'vehicule_immat = ?';
        $params[] = $info['immat'];
    }
    if (!empty($info['cv']) && (string)$info['cv'] !== (string)($current['vehicule_puissance_fiscale'] ?? '')) {
        $sets[] = 'vehicule_puissance_fiscale = ?';
        $params[] = $info['cv'];
    }

    if (isset($info['indemnite_km']) && $info['indemnite_km'] !== null) {
        $curKm = (float)($current['indemnite_km'] ?? 0);
        if ($curKm <= 0) {
            $sets[] = 'indemnite_km = ?';
            $params[] = $info['indemnite_km'];
        }
    }

    if ($sets) {
        $params[] = $userId;
        $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . ", date_modification=NOW() WHERE id=?")->execute($params);
    }

    return [
        'updated' => $sets,
    ];
}

function rh_analyse_carte_grise(PDO $pdo, int $userId, string $filePath, string $mime): array
{
    $meta = [];
    $text = rh_extract_text_from_file($filePath, $mime, $meta);

    if (!$meta['shell_exec']) {
        return ['ok' => false, 'error' => 'shell_exec désactivé (php.ini)', 'meta' => $meta];
    }
    if (!$meta['has_pdftotext'] && !$meta['has_tesseract']) {
        return ['ok' => false, 'error' => 'Outils OCR introuvables', 'meta' => $meta];
    }
    if ($text === '') {
        if (!empty($meta['scanned_blocked'])) {
            return ['ok' => false, 'error' => 'PDF scanné non pris en charge', 'meta' => $meta];
        }
        return ['ok' => false, 'error' => 'OCR indisponible', 'meta' => $meta];
    }

    $meta['preview'] = substr($text, 0, 400);

    $info = rh_parse_carte_grise_text($text);

    $rate = null;
    if (!empty($info['cv'])) {
        $year = ik_bareme_default_year();
        $rate = ik_rate_per_km($pdo, (int)$info['cv'], $year, !empty($info['is_electric']));
        if ($rate !== null) {
            $info['indemnite_km'] = round($rate, 4);
        }
    }

    $update = rh_update_vehicle_from_info($pdo, $userId, $info);

    return [
        'ok' => true,
        'info' => $info,
        'update' => $update,
        'meta' => $meta,
    ];
}