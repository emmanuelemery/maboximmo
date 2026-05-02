<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Classer (low-cost, sans IA)
 * Fichier : modules/ged/ged_classer.php
 *
 * Objectif : proposer un classement et un nom "propre" à coût minimal :
 *  - utilise le nom de fichier + extraction texte PDF (si disponible)
 *  - règles simples (regex / mots-clés)
 *  - aucune IA
 */

require_once __DIR__ . '/ged_pdf_text.php';

// Compat PHP < 8 : str_contains n'existe pas
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('ged_mb_strtoupper')) {
    function ged_mb_strtoupper(string $s): string
    {
        return function_exists('mb_strtoupper') ? (string)mb_strtoupper($s) : strtoupper($s);
    }
}

if (!function_exists('ged_mb_substr')) {
    function ged_mb_substr(string $s, int $start, int $length): string
    {
        if (function_exists('mb_substr')) return (string)mb_substr($s, $start, $length);
        return substr($s, $start, $length);
    }
}

/**
 * @return array{
 *   ok:bool,
 *   analysis_level:string, // 'classer_v1'
 *   engine:string,         // 'filename_only'|'pdf_text_rules'
 *   title:?string,
 *   type_document:?string,
 *   module:?string,
 *   niveau_2:?string,
 *   niveau_3:?string,
 *   date_document:?string,
 *   montant_ttc:?float,
 *   tiers_principal:?string,
 *   banque_detectee:?string,
 *   suggested_filename:?string,
 *   confidence:float,
 *   fields:array<string,mixed>
 * }
 */
function gedClasserLowCost(string $localPath, string $originalName): array
{
    $analysisLevel = 'classer_v1';
    $engine = 'filename_only';
    $text = null;

    $mime = '';
    if (is_file($localPath)) {
        try {
            if (function_exists('mime_content_type')) {
                $mime = (string)(mime_content_type($localPath) ?: '');
            } elseif (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)($fi->file($localPath) ?: '');
            }
        } catch (Throwable) {
            $mime = '';
        }
    }
    if ($mime === '') {
        $extGuess = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extGuess === 'pdf') $mime = 'application/pdf';
    }
    if ($mime === 'application/pdf') {
        try {
            $t = gedPdfExtractText($localPath, 50);
            if (is_string($t) && trim($t) !== '') {
                $text = $t;
                $engine = 'pdf_text_rules';
            }
        } catch (Throwable) {
            // ignore
        }
    }

    $hay = $originalName . "\n" . ($text ?? '');
    $H = ged_mb_strtoupper($hay);

    $type = null;
    $module = null;
    $niv2 = null;
    $niv3 = null;
    $title = null;
    $tiers = null;
    $bank = null;
    $date = null;
    $montant = null;

    // ── 1) Type document (règles fortes) ───────────────────────────────────
    if (preg_match('/TAXE\\s+FONCIER|AVIS\\s+D[\' ]IMPOSITION|DGFIP|TRESOR\\s+PUBLIC/', $H)) {
        $type = 'TAXE_FONCIERE';
        $module = 'COMPTA';
        $niv2 = 'Fiscalité';
        $niv3 = 'Taxe foncière';
        $title = 'Taxe foncière';
        $tiers = 'DGFiP';
    } elseif (preg_match('/RELEVE\\s+BANC|RELEV[ÉE]\\s+DE\\s+COMPTE|SOLDE\\b|IBAN\\b/', $H)) {
        $type = 'RELEVE_BANCAIRE';
        $module = 'COMPTA';
        $niv2 = 'Banque';
        $niv3 = 'Relevé Banque';
        $title = 'Relevé bancaire';
        [$bank] = gedClasserDetectBank($H);
        $tiers = $bank;
    } elseif (preg_match('/\\bDPE\\b|DIAGNOSTIC\\b|DIAGNOSTICS\\b|AMIANTE|PLOMB|GAZ|ELECTRICITE/', $H)) {
        $type = 'DIAGNOSTIC';
        $module = 'ADMIN';
        $niv2 = 'Diagnostics';
        $niv3 = 'DPE';
        $title = 'Diagnostic / DPE';
    } elseif (preg_match('/\\bFACTURE\\b/', $H)) {
        $type = 'FACTURE';
        $module = 'COMPTA';
        $niv2 = 'Factures';
        $niv3 = 'Entrantes';
        $title = 'Facture';
    } elseif (preg_match('/\\bDEVIS\\b/', $H)) {
        $type = 'DEVIS';
        $module = 'FOURNISSEURS';
        $niv2 = 'Devis';
        $niv3 = null;
        $title = 'Devis';
    }

    // ── 2) Date document (best-effort) ─────────────────────────────────────
    $date = gedClasserDetectDate($hay);

    // ── 3) Montant TTC (facture/devis uniquement) ──────────────────────────
    if ($type === 'FACTURE' || $type === 'DEVIS') {
        $montant = gedClasserDetectMontantTtc($hay);
    }

    // ── 4) Confidence ─────────────────────────────────────────────────────
    $conf = 35.0;
    if ($type !== null) $conf += 25.0;
    if ($module !== null) $conf += 10.0;
    if ($text !== null) $conf += 10.0;
    if ($date !== null) $conf += 10.0;
    if ($type === 'RELEVE_BANCAIRE' && $bank) $conf += 10.0;
    if ($conf > 95.0) $conf = 95.0;

    // ── 5) Nom de fichier proposé (sans objet métier) ─────────────────────
    $suggested = gedClasserSuggestFilename($type, $module, $niv2, $niv3, $date, $montant, $originalName);

    return [
        'ok'              => true,
        'analysis_level'   => $analysisLevel,
        'engine'          => $engine,
        'raw_text'        => $text !== null ? ged_mb_substr($text, 0, 6000) : null,
        'title'           => $title,
        'type_document'   => $type,
        'module'          => $module,
        'niveau_2'        => $niv2,
        'niveau_3'        => $niv3,
        'date_document'   => $date,
        'montant_ttc'     => $montant,
        'tiers_principal' => $tiers,
        'banque_detectee' => $bank,
        'suggested_filename' => $suggested,
        'confidence'      => $conf,
        'fields'          => [
            'type_document' => $type,
            'module'        => $module,
            'niveau_2'      => $niv2,
            'niveau_3'      => $niv3,
            'date_document' => $date,
            'montant_ttc'   => $montant,
            'tiers_principal' => $tiers,
            'banque'        => $bank,
        ],
    ];
}

function gedClasserDetectDate(string $hay): ?string
{
    // ISO first
    if (preg_match('/\\b(20\\d{2})-(0\\d|1[0-2])-(0\\d|[12]\\d|3[01])\\b/', $hay, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    // DD/MM/YYYY
    if (preg_match('/\\b(0\\d|[12]\\d|3[01])[\\/\\-](0\\d|1[0-2])[\\/\\-](20\\d{2})\\b/', $hay, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

function gedClasserDetectMontantTtc(string $hay): ?float
{
    $H = mb_strtoupper($hay);
    // capture "TOTAL TTC 1 234,56" or "1.234,56"
    if (preg_match('/TOTAL\\s+TTC[^0-9]{0,20}([0-9][0-9\\s\\.]*[,\\.][0-9]{2})/', $H, $m)) {
        $raw = str_replace([' ', "\u{00A0}"], '', (string)$m[1]);
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
        $v = (float)$raw;
        return $v > 0 ? $v : null;
    }
    return null;
}

/**
 * @return array{0:?string,1:string} [banque, reason]
 */
function gedClasserDetectBank(string $H): array
{
    $banks = [
        'CREDIT MUTUEL' => ['CREDIT MUTUEL', 'CIC'],
        'CIC' => ['CIC'],
        'BNP' => ['BNP', 'BNP PARIBAS'],
        'SOCIETE GENERALE' => ['SOCIETE GENERALE', 'S G '],
        'CREDIT AGRICOLE' => ['CREDIT AGRICOLE'],
        'BANQUE POPULAIRE' => ['BANQUE POPULAIRE'],
        'CAISSE D EPARGNE' => ['CAISSE D EPARGNE'],
        'LA BANQUE POSTALE' => ['BANQUE POSTALE'],
        'LCL' => ['LCL', 'CREDIT LYONNAIS'],
    ];
    foreach ($banks as $label => $needles) {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($H, $n)) return [$label, 'keyword'];
        }
    }
    return [null, 'not_found'];
}

function gedClasserSuggestFilename(?string $type, ?string $module, ?string $niv2, ?string $niv3, ?string $date, ?float $montant, string $orig): ?string
{
    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    $ext = $ext !== '' ? $ext : 'pdf';
    $date = $date ?? date('Y-m-d');

    $parts = [];
    if ($type) $parts[] = $type;
    if ($module) $parts[] = $module;
    if ($niv2) $parts[] = $niv2;
    if ($niv3) $parts[] = $niv3;
    $parts[] = $date;
    if ($montant !== null) $parts[] = (string)round($montant, 2);

    $stem = implode('_', $parts);
    $stem = preg_replace('/[^A-Za-z0-9]+/', '_', $stem) ?? $stem;
    $stem = trim($stem, '_');
    if ($stem === '') return null;
    return ged_mb_substr($stem, 0, 120) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
}
