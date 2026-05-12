<?php
declare(strict_types=1);

/**
 * GED — Import ZIP relevés bancaires (LIB)
 * Fichier : modules/ged/ged_import_releves_banque_zip_lib.php
 *
 * Module spécialisé, volontairement indépendant de la GED "Inbox" existante.
 * Objectif : ZIP → PDF → reconnaissance immeuble → classement Drive + regroupement compta.
 */

require_once __DIR__ . '/ged_storage.php';
require_once __DIR__ . '/ged_storage_local.php';
require_once __DIR__ . '/ged_pdf_text.php';
require_once __DIR__ . '/ged_logger.php';

const GED_RELEVES_ZIP_MAX_ZIPS        = 80;
const GED_RELEVES_ZIP_MAX_ZIP_BYTES   = 80_000_000;  // 80 Mo (limité aussi par php.ini)
const GED_RELEVES_ZIP_MAX_PDFS_PER_BATCH = 800;
const GED_RELEVES_ZIP_MAX_PDF_BYTES   = 25_000_000;  // 25 Mo par PDF extrait

/**
 * Sanitise un segment de nom (fichier/dossier) pour Drive/Local.
 */
function ged_releves_safe_name(string $s, int $max = 160): string
{
    $s = trim($s);
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/[\\\\\/]/', '_', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s);
    if ($s === '' || $s === '.' || $s === '..') $s = 'DOC';
    return mb_substr($s, 0, $max);
}

/**
 * Normalisation forte ASCII/MAJ pour matching.
 */
function ged_releves_norm(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($tr) && $tr !== '') $s = $tr;
    }
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Extrait une période YYYY-MM depuis un texte (filename ou texte PDF).
 * Retourne [annee, mois] ou [null, null].
 *
 * @return array{0:?int,1:?int,2:?string} [annee, mois, reason]
 */
function ged_releves_detect_period(string $filename, ?string $pdfText, ?string $defaultYYYYMM): array
{
    $hay = $filename . "\n" . ($pdfText ?? '');
    $n = ged_releves_norm($hay);

    // 1) YYYY-MM ou YYYY/MM
    if (preg_match('/\b(20\d{2})\s*[-\/]\s*(0[1-9]|1[0-2])\b/', $n, $m)) {
        return [(int)$m[1], (int)$m[2], 'pattern:YYYY-MM'];
    }
    // 2) MM-YYYY ou MM/YYYY
    if (preg_match('/\b(0[1-9]|1[0-2])\s*[-\/]\s*(20\d{2})\b/', $n, $m)) {
        return [(int)$m[2], (int)$m[1], 'pattern:MM-YYYY'];
    }
    // 3) YYYYMM (ex 202604)
    if (preg_match('/\b(20\d{2})(0[1-9]|1[0-2])\b/', $n, $m)) {
        return [(int)$m[1], (int)$m[2], 'pattern:YYYYMM'];
    }

    // 4) Fallback : default imposé par user
    if ($defaultYYYYMM && preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $defaultYYYYMM, $m)) {
        return [(int)$m[1], (int)$m[2], 'fallback:default'];
    }

    return [null, null, 'not_found'];
}

/**
 * Détection simple de banque (best-effort).
 *
 * @return array{0:?string,1:?string} [banque, reason]
 */
function ged_releves_detect_bank(string $filename, ?string $pdfText): array
{
    $hay = $filename . "\n" . ($pdfText ?? '');
    $n = ged_releves_norm($hay);

    $banks = [
        'CREDIT MUTUEL' => ['CREDIT MUTUEL', 'CMUT', 'CIC'],
        'CIC'           => ['CIC'],
        'BNP'           => ['BNP', 'BNP PARIBAS'],
        'SOCIETE GENERALE' => ['SOCIETE GENERALE', 'SG'],
        'CREDIT AGRICOLE'  => ['CREDIT AGRICOLE', 'CA '],
        'BANQUE POPULAIRE' => ['BANQUE POPULAIRE', 'BP '],
        'CAISSE D EPARGNE' => ['CAISSE D EPARGNE'],
        'LA BANQUE POSTALE' => ['BANQUE POSTALE', 'LA BANQUE POSTALE'],
        'LCL' => ['LCL', 'CREDIT LYONNAIS'],
    ];

    foreach ($banks as $label => $needles) {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($n, ged_releves_norm($needle))) {
                return [$label, 'keyword'];
            }
        }
    }
    return [null, 'not_found'];
}

/**
 * @return array<int,array{id:int,reference:?string,nom:?string,adresse:?string,cp:?string,ville:?string,logiciel:?string,label:string,norm:string,keywords:array<int,string>}>
 */
function ged_releves_load_immeubles(PDO $pdo, int $societeId): array
{
    $st = $pdo->prepare("
        SELECT id,
               reference_immeuble,
               nom_immeuble,
               adresse_1,
               code_postal,
               ville,
               logiciel_comptable
        FROM immeubles
        WHERE (? = 0 OR id_societe = ?)
        ORDER BY reference_immeuble ASC, nom_immeuble ASC
        LIMIT 5000
    ");
    $st->execute([$societeId, $societeId]);

    $rows = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $ref = isset($r['reference_immeuble']) ? (string)$r['reference_immeuble'] : '';
        $nom = isset($r['nom_immeuble']) ? (string)$r['nom_immeuble'] : '';
        $adr = isset($r['adresse_1']) ? (string)$r['adresse_1'] : '';
        $cp  = isset($r['code_postal']) ? (string)$r['code_postal'] : '';
        $ville = isset($r['ville']) ? (string)$r['ville'] : '';
        $logiciel = isset($r['logiciel_comptable']) ? (string)$r['logiciel_comptable'] : '';

        $label = trim(($ref !== '' ? $ref . ' · ' : '') . ($nom !== '' ? $nom : ($adr !== '' ? $adr : 'IMMEUBLE#' . (int)$r['id'])));
        $norm  = ged_releves_norm($label . ' ' . $adr . ' ' . $cp . ' ' . $ville);
        $keywords = array_values(array_filter(explode(' ', $norm), fn($w) => mb_strlen($w) >= 3));

        $rows[] = [
            'id'        => (int)$r['id'],
            'reference' => $ref !== '' ? $ref : null,
            'nom'       => $nom !== '' ? $nom : null,
            'adresse'   => $adr !== '' ? $adr : null,
            'cp'        => $cp !== '' ? $cp : null,
            'ville'     => $ville !== '' ? $ville : null,
            'logiciel'  => $logiciel !== '' ? $logiciel : null,
            'label'     => $label,
            'norm'      => $norm,
            'keywords'  => $keywords,
        ];
    }
    return $rows;
}

/**
 * @return array{0:?int,1:float,2:string} [id_immeuble, score_0_100, reason]
 */
function ged_releves_match_immeuble(string $filename, ?string $pdfText, array $immeubles): array
{
    $hint = ged_releves_norm($filename . ' ' . ($pdfText ?? ''));
    if ($hint === '') return [null, 0.0, 'empty_hint'];

    // 1) Match fort par reference_immeuble (substring)
    $hits = [];
    foreach ($immeubles as $imm) {
        $ref = $imm['reference'] ?? null;
        if (!$ref) continue;
        $r = ged_releves_norm($ref);
        if ($r !== '' && str_contains($hint, $r)) {
            $hits[] = (int)$imm['id'];
        }
    }
    $hits = array_values(array_unique($hits));
    if (count($hits) === 1) {
        return [$hits[0], 98.0, 'match:reference_immeuble'];
    }
    if (count($hits) > 1) {
        return [null, 40.0, 'ambiguous:reference_immeuble_multi'];
    }

    // 2) Score tokens (intersection)
    $tokens = array_values(array_filter(explode(' ', $hint), fn($w) => mb_strlen($w) >= 3));
    if (!$tokens) return [null, 0.0, 'no_tokens'];
    $tokenSet = array_fill_keys($tokens, true);

    $bestId = null;
    $bestScore = 0.0;
    $second = 0.0;
    foreach ($immeubles as $imm) {
        $keywords = $imm['keywords'] ?? [];
        if (!$keywords) continue;
        $common = 0;
        foreach ($keywords as $k) {
            if (isset($tokenSet[$k])) $common++;
        }
        if ($common <= 0) continue;
        $score = ($common / max(4, count($keywords))) * 100.0; // normalisation conservative

        // Bonus si CP trouvé
        $cp = $imm['cp'] ?? null;
        if ($cp && preg_match('/\b' . preg_quote((string)$cp, '/') . '\b/', $hint)) {
            $score += 8.0;
        }
        // Bonus si ville trouvée
        $ville = $imm['ville'] ?? null;
        if ($ville) {
            $v = ged_releves_norm((string)$ville);
            if ($v !== '' && str_contains($hint, $v)) $score += 6.0;
        }

        if ($score > $bestScore) {
            $second = $bestScore;
            $bestScore = $score;
            $bestId = (int)$imm['id'];
        } elseif ($score > $second) {
            $second = $score;
        }
    }

    if ($bestId === null) return [null, 0.0, 'no_match'];

    // Ambigu si le 2e est proche
    if ($second > 0 && ($bestScore - $second) < 8.0) {
        return [null, min(55.0, $bestScore), 'ambiguous:close_scores'];
    }

    return [$bestId, min(95.0, $bestScore), 'match:tokens'];
}

/**
 * Renvoie [sha256,size].
 * @return array{0:string,1:int}
 */
function ged_releves_sha_size(string $absPdfPath): array
{
    $sha = hash_file('sha256', $absPdfPath);
    if (!is_string($sha) || $sha === '') throw new RuntimeException('sha256 failed');
    $size = (int)filesize($absPdfPath);
    return [$sha, $size];
}

/**
 * Zip Slip guard : refuse toute entrée qui tente de sortir du dossier cible.
 */
function ged_releves_zip_entry_is_safe(string $entryName): bool
{
    $n = str_replace('\\', '/', $entryName);
    if ($n === '' || str_contains($n, "\0")) return false;
    if (str_starts_with($n, '/') || preg_match('/^[A-Za-z]:\//', $n)) return false;
    if (str_contains($n, '../') || str_contains($n, '..\\')) return false;
    return true;
}

/**
 * Construit un nom final de PDF, sans perdre le nom original.
 */
function ged_releves_build_pdf_name(int $annee, int $mois, array $immeubleRow, ?string $banque, string $originalFilename, string $sha256): string
{
    // Règle : conserver le nom de fichier (identification portée par le nom source).
    // On sanitise seulement pour éviter caractères/paths invalides côté storage.
    $base = basename(str_replace('\\', '/', $originalFilename));
    if (!preg_match('/\.pdf$/i', $base)) $base .= '.pdf';
    $safe = ged_releves_safe_name($base, 200);
    $safe = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $safe) ?? $safe;
    $safe = preg_replace('/_+/', '_', $safe) ?? $safe;
    return $safe;
}

/**
 * Dossier Drive cible pour "GED immeuble" (Comptabilité/Relevé Banque/AAAAMM).
 */
function ged_releves_drive_folder_for_immeuble(GedStorageDriver $driver, array $immeubleRow, int $annee, int $mois): string
{
    $immId = (int)($immeubleRow['id'] ?? 0);
    $ref   = trim((string)($immeubleRow['reference_immeuble'] ?? $immeubleRow['reference'] ?? ''));
    $nom   = trim((string)($immeubleRow['nom_immeuble'] ?? $immeubleRow['nom'] ?? ''));

    $immLabel = $ref !== '' ? ($ref . ($nom !== '' ? ' - ' . $nom : '')) : (sprintf('IMB-%06d', $immId) . ($nom !== '' ? ' - ' . $nom : ''));
    $immLabel = ged_releves_safe_name($immLabel, 120);

    $root = $driver->ensureFolder('IMMEUBLES');
    $immFolder = $driver->ensureFolder($immLabel, $root);
    $c = $driver->ensureFolder('Comptabilité', $immFolder);
    $r = $driver->ensureFolder('Relevé Banque', $c);
    $ym = $driver->ensureFolder(sprintf('%04d%02d', $annee, $mois), $r);
    return $ym;
}

/**
 * Dossier Drive cible pour regroupement compta global.
 * Structure : Comptabilité/Relevé Banque/{LOGICIEL}/AAAAMM
 */
function ged_releves_drive_folder_for_compta(GedStorageDriver $driver, string $logicielComptable, int $annee, int $mois): string
{
    $logicielComptable = strtoupper(trim($logicielComptable));
    if (!in_array($logicielComptable, ['SEPTEO', 'LOJJI', 'ICS', 'MABOXIMMO', 'AUTRE'], true)) {
        $logicielComptable = 'AUTRE';
    }
    $yyyymm = sprintf('%04d%02d', $annee, $mois);

    $root = $driver->ensureFolder('Comptabilité');
    $rb   = $driver->ensureFolder('Relevé Banque', $root);
    $sw   = $driver->ensureFolder($logicielComptable, $rb);
    $per  = $driver->ensureFolder($yyyymm, $sw);
    return $per;
}
