<?php
/**
 * api/crg_find_page.php — Recherche d'un locataire dans un CRG PDF, retourne le numéro de page
 * Usage: GET ?file=uploads/bailleur_docs/12/...pdf&search=YUSUF
 * Retourne: { "ok": true, "page": 2 } ou { "ok": false }
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$file   = $_GET['file'] ?? '';
$search = trim($_GET['search'] ?? '');

if ($file === '' || $search === '') {
    echo json_encode(['ok' => false, 'error' => 'Paramètres manquants']);
    exit;
}

// Sécurité : le fichier doit être dans uploads/
$absPath = realpath(__DIR__ . '/../' . $file);
$uploadsDir = realpath(__DIR__ . '/../uploads');
if (!$absPath || !$uploadsDir || !str_starts_with($absPath, $uploadsDir)) {
    echo json_encode(['ok' => false, 'error' => 'Fichier non autorisé']);
    exit;
}
if (!is_file($absPath)) {
    echo json_encode(['ok' => false, 'error' => 'Fichier introuvable']);
    exit;
}

// Extraction texte page par page via pdftotext
$page = 0;
if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
    $cmd = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($absPath) . ' - 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    $out = @shell_exec($cmd);
    if ($out) {
        $pages = explode(chr(12), $out); // form feed = page break
        // Normaliser la recherche
        $searchNorm = mb_strtoupper(trim($search));
        // Extraire le nom de famille (premier mot significatif)
        $searchParts = preg_split('/[\s,]+/', $searchNorm);
        foreach ($pages as $i => $pageText) {
            $pageUpper = mb_strtoupper($pageText);
            // Chercher le nom complet d'abord
            if (mb_strpos($pageUpper, $searchNorm) !== false) {
                $page = $i + 1;
                break;
            }
            // Sinon chercher le nom de famille seul (premier mot > 3 chars)
            foreach ($searchParts as $part) {
                if (mb_strlen($part) >= 3 && mb_strpos($pageUpper, $part) !== false) {
                    $page = $i + 1;
                    break 2;
                }
            }
        }
    }
}

// Fallback smalot/pdfparser
if ($page === 0) {
    $vendorPaths = [__DIR__ . '/../vendor', __DIR__ . '/../../vendor', '/home/u630423897/vendor'];
    foreach ($vendorPaths as $vp) {
        if (is_file($vp . '/autoload.php')) {
            require_once $vp . '/autoload.php';
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdfDoc = $parser->parseFile($absPath);
                $pdfPages = $pdfDoc->getPages();
                $searchNorm = mb_strtoupper(trim($search));
                foreach ($pdfPages as $i => $pdfPage) {
                    $text = mb_strtoupper($pdfPage->getText());
                    if (mb_strpos($text, $searchNorm) !== false) {
                        $page = $i + 1;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[crg_find_page] pdfparser error: ' . $e->getMessage());
            }
            break;
        }
    }
}

echo json_encode(['ok' => $page > 0, 'page' => $page, 'search' => $search]);
