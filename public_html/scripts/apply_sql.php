<?php
declare(strict_types=1);

/**
 * Usage:
 *   C:\xampp\php\php.exe scripts\apply_sql.php sql\file.sql
 */

require_once __DIR__ . '/../config/db.php';

$sqlFile = $argv[1] ?? '';
if ($sqlFile === '') {
    fwrite(STDERR, "Usage: php scripts/apply_sql.php <sql-file>\n");
    exit(2);
}

$root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$path = $sqlFile;
if (!is_file($path)) {
    $path = $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sqlFile), DIRECTORY_SEPARATOR);
}
if (!is_file($path)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}\n");
    exit(2);
}

$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Cannot read SQL file: {$path}\n");
    exit(2);
}

function split_sql_statements(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $inS = false; // '
    $inD = false; // "
    $inB = false; // `

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $nx = ($i + 1 < $len) ? $sql[$i + 1] : '';

        // Comments (only when not inside strings)
        if (!$inS && !$inD && !$inB) {
            if ($ch === '-' && $nx === '-') {
                while ($i < $len && $sql[$i] !== "\n") $i++;
                $buf .= "\n";
                continue;
            }
            if ($ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") $i++;
                $buf .= "\n";
                continue;
            }
            if ($ch === '/' && $nx === '*') {
                $i += 2;
                while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) $i++;
                $i++;
                continue;
            }
        }

        // Escapes in strings
        if ($ch === '\\' && ($inS || $inD)) {
            $buf .= $ch;
            if ($i + 1 < $len) {
                $buf .= $sql[$i + 1];
                $i++;
            }
            continue;
        }

        if (!$inD && !$inB && $ch === "'") { $inS = !$inS; $buf .= $ch; continue; }
        if (!$inS && !$inB && $ch === '"') { $inD = !$inD; $buf .= $ch; continue; }
        if (!$inS && !$inD && $ch === '`') { $inB = !$inB; $buf .= $ch; continue; }

        if (!$inS && !$inD && !$inB && $ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') $stmts[] = $stmt;
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }

    $tail = trim($buf);
    if ($tail !== '') $stmts[] = $tail;
    return $stmts;
}

$pdo = db();
$statements = split_sql_statements($sql);

$applied = 0;
foreach ($statements as $stmt) {
    $pdo->exec($stmt);
    $applied++;
}

echo "Applied statements: {$applied}\n";

