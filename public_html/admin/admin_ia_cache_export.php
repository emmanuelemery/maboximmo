<?php
// admin/admin_ia_cache_export.php — Export SQL du cache IA pour migration local→dev→prod
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }

$filename = 'ia_extract_cache_' . date('Ymd_His') . '.sql';
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "-- Export cache IA — " . date('Y-m-d H:i:s') . "\n";
echo "-- Importez ce fichier sur dev/prod via phpMyAdmin pour réutiliser les analyses déjà payées.\n\n";

echo "CREATE TABLE IF NOT EXISTS `ia_extract_cache` (
  `hash_sha256` CHAR(64) NOT NULL,
  `model` VARCHAR(60) NOT NULL,
  `prompt_version` VARCHAR(20) NOT NULL DEFAULT '1',
  `response_json` LONGTEXT NOT NULL,
  `cout_centimes` INT NOT NULL DEFAULT 0,
  `hit_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `confidence` TINYINT UNSIGNED NULL,
  `source_origin` VARCHAR(40) NULL,
  PRIMARY KEY (`hash_sha256`,`model`,`prompt_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

try {
    $st = $pdo->query('SELECT * FROM ia_extract_cache ORDER BY first_at');
    $cnt = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $vals = [];
        foreach (['hash_sha256','model','prompt_version','response_json','cout_centimes','hit_count','first_at','last_at','confidence','source_origin'] as $f) {
            $v = $r[$f] ?? null;
            $vals[] = ($v === null) ? 'NULL' : "'" . str_replace(["\\","'"], ["\\\\","''"], (string)$v) . "'";
        }
        echo "INSERT IGNORE INTO `ia_extract_cache` (`hash_sha256`,`model`,`prompt_version`,`response_json`,`cout_centimes`,`hit_count`,`first_at`,`last_at`,`confidence`,`source_origin`) VALUES (" . implode(',', $vals) . ");\n";
        $cnt++;
    }
    echo "\n-- $cnt entrées exportées.\n";
} catch (Throwable $e) {
    echo "-- Erreur : " . $e->getMessage() . "\n";
}
