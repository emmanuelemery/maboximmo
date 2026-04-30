<?php
/**
 * ADMIN — Purge OPcache ciblee sur les fichiers du portail mbi_annonces.
 *
 * URL : /admin/admin_opcache_mbi_annonces.php
 *
 * Pourquoi : opcache_reset() global peut ne pas vider TOUS les workers PHP-FPM
 * sur Hostinger mutualise. opcache_invalidate() avec force=true par fichier
 * est plus fiable.
 */

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if (current_role_id() !== 1) {
    http_response_code(403);
    exit('Acces admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');

$targets = [
    __DIR__ . '/../inc/mbi_annonces_helpers.php',
    __DIR__ . '/../inc/mbi_annonces_header.php',
    __DIR__ . '/../inc/mbi_annonces_footer.php',
    __DIR__ . '/../mbi_annonces_index.php',
    __DIR__ . '/../mbi_annonces_recherche.php',
    __DIR__ . '/../mbi_annonces_detail.php',
    __DIR__ . '/../mbi_annonces_contact.php',
    __DIR__ . '/../annonce_liste.php',
];

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OPcache MBI annonces</title>';
echo '<style>body{font-family:monospace;padding:20px;line-height:1.5}'
   . 'h1{color:#0f172a}.ok{color:#16a34a;font-weight:bold}.ko{color:#dc2626;font-weight:bold}'
   . 'table{border-collapse:collapse;margin:10px 0}td,th{border:1px solid #cbd5e1;padding:6px 12px;text-align:left}'
   . '</style></head><body>';

echo '<h1>Purge OPcache - Portail mbi_annonces</h1>';

// Reset global d'abord
if (function_exists('opcache_reset')) {
    $ok = @opcache_reset();
    echo $ok ? '<p class="ok">[Reset global] OK</p>'
             : '<p class="ko">[Reset global] echec (peut-etre desactive)</p>';
}

echo '<h2>Invalidate par fichier (force=true)</h2>';
echo '<table><tr><th>Fichier</th><th>Existe</th><th>Mtime</th><th>Taille</th><th>Invalidate</th></tr>';

foreach ($targets as $t) {
    $exists = is_file($t);
    $mtime = $exists ? date('Y-m-d H:i:s', filemtime($t)) : '-';
    $size = $exists ? number_format(filesize($t)) . ' o' : '-';
    $inv = '-';
    if ($exists && function_exists('opcache_invalidate')) {
        $r = @opcache_invalidate($t, true);
        $inv = $r ? '<span class="ok">OK</span>' : '<span class="ko">FAIL</span>';
    }
    $rel = basename(dirname($t)) . '/' . basename($t);
    echo '<tr><td>' . htmlspecialchars($rel) . '</td>'
       . '<td>' . ($exists ? '<span class="ok">OUI</span>' : '<span class="ko">NON</span>') . '</td>'
       . '<td>' . $mtime . '</td>'
       . '<td>' . $size . '</td>'
       . '<td>' . $inv . '</td></tr>';
}
echo '</table>';

echo '<h2>Verification : code actuel de mbi_annonces_helpers.php</h2>';
$file = __DIR__ . '/../inc/mbi_annonces_helpers.php';
if (is_file($file)) {
    $src = file_get_contents($file);
    $hasOldBug = strpos($src, 'ap.url_photo') !== false;
    $hasFix    = strpos($src, 'biens_photos bp WHERE bp.id_bien') !== false;
    echo '<table>';
    echo '<tr><th>Ancienne reference ap.url_photo (bug)</th><td>'
       . ($hasOldBug ? '<span class="ko">PRESENTE - le fichier sur disque est encore ancien !</span>'
                    : '<span class="ok">ABSENTE</span>') . '</td></tr>';
    echo '<tr><th>Nouveau pattern biens_photos bp (fix)</th><td>'
       . ($hasFix ? '<span class="ok">PRESENT</span>'
                  : '<span class="ko">ABSENT - fichier non a jour sur disque !</span>') . '</td></tr>';
    echo '</table>';
}

echo '<p style="margin-top:30px"><a href="' . htmlspecialchars(app_url('/mbi_annonces_index.php')) . '">-> Tester mbi_annonces_index.php</a></p>';
echo '</body></html>';
