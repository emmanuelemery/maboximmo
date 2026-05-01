<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Construction du nom de fichier canonique
 * Fichier : modules/ged/ged_naming.php
 *
 * Format officiel (prompt maître §4) :
 *   TYPE_YYYY-MM-DD_SOC_AG_OBJETID_TIERS_DESCRIPTION_VX.ext
 *
 * Exemples :
 *   FACTURE_2026-05-01_RE_AGLYON_IMB-000123_EDF_ELECTRICITE_V1.pdf
 *   DEVIS_2026-05-01_RE_AGVIENNE_BIEN-000456_DURAND_PLOMBERIE_V1.pdf
 *   MANDAT_2026-05-01_RE_AGLYON_MDT-000789_SDC_PASTEUR_V1.pdf
 *
 * Règles :
 *   - 1 seul objet pivot (IMB / BIEN / MDT / CTX / EMP / FOUR)
 *   - pas d'adresse en clair
 *   - slug propre (ASCII upper, _ comme séparateur)
 *   - max 140 caractères au total (extension comprise)
 */

const GED_OBJET_TYPES = ['IMB', 'BIEN', 'MDT', 'CTX', 'EMP', 'FOUR'];
const GED_NAME_MAX    = 140;

/**
 * Construit le nom canonique d'un document GED.
 *
 * @param array{
 *   type:string,
 *   date?:string,
 *   ref_societe:string,
 *   ref_agence:string,
 *   objet_type:string,
 *   objet_id:int|string,
 *   tiers?:?string,
 *   description?:?string,
 *   version?:int,
 *   ext:string
 * } $p
 * @throws InvalidArgumentException si paramètre obligatoire manquant.
 */
function gedBuildFilename(array $p): string
{
    foreach (['type', 'ref_societe', 'ref_agence', 'objet_type', 'objet_id', 'ext'] as $req) {
        if (!isset($p[$req]) || $p[$req] === '' || $p[$req] === null) {
            throw new InvalidArgumentException("gedBuildFilename : '{$req}' obligatoire.");
        }
    }

    $objetType = strtoupper(trim((string)$p['objet_type']));
    if (!in_array($objetType, GED_OBJET_TYPES, true)) {
        throw new InvalidArgumentException(
            "gedBuildFilename : objet_type invalide '{$objetType}'. Attendu : " . implode('|', GED_OBJET_TYPES)
        );
    }

    $type    = ged_slug_upper((string)$p['type']);
    $date    = !empty($p['date']) ? ged_normalize_date((string)$p['date']) : date('Y-m-d');
    $soc     = ged_slug_upper((string)$p['ref_societe']);
    $ag      = ged_slug_upper((string)$p['ref_agence']);
    $oid     = sprintf('%s-%06d', $objetType, (int)$p['objet_id']);
    $tiers   = !empty($p['tiers'])       ? ged_slug_upper((string)$p['tiers'])       : '';
    $desc    = !empty($p['description']) ? ged_slug_upper((string)$p['description']) : '';
    $version = max(1, (int)($p['version'] ?? 1));
    $ver     = 'V' . $version;
    $ext     = ltrim(strtolower((string)$p['ext']), '.');
    $ext     = preg_replace('/[^a-z0-9]/', '', $ext) ?? 'bin';
    if ($ext === '') $ext = 'bin';

    $parts = [$type, $date, $soc, $ag, $oid];
    if ($tiers !== '') $parts[] = $tiers;
    if ($desc  !== '') $parts[] = $desc;
    $parts[] = $ver;

    $stem = implode('_', $parts);
    $name = $stem . '.' . $ext;

    // Cap 140 chars en préservant l'extension + version
    if (strlen($name) > GED_NAME_MAX) {
        $reserve = strlen('_' . $ver . '.' . $ext);
        $maxStem = GED_NAME_MAX - $reserve;
        // On enlève le _Vx déjà concaténé pour le rajouter après troncature
        $stemSansVer = preg_replace('/_V\d+$/', '', $stem) ?? $stem;
        $stemSansVer = mb_substr($stemSansVer, 0, max(1, $maxStem));
        $name = rtrim($stemSansVer, '_') . '_' . $ver . '.' . $ext;
    }

    return $name;
}

/**
 * Décompose un nom canonique pour vérification / parsing inverse.
 * Retourne null si le nom n'est pas au format attendu.
 *
 * @return array{type:string, date:string, ref_societe:string, ref_agence:string,
 *               objet_type:string, objet_id:int, tiers:?string, description:?string,
 *               version:int, ext:string}|null
 */
function gedParseFilename(string $name): ?array
{
    if (!preg_match('/^(.+)\.([A-Za-z0-9]+)$/', $name, $m)) return null;
    $stem = $m[1]; $ext = strtolower($m[2]);

    $parts = explode('_', $stem);
    if (count($parts) < 6) return null;

    $type = $parts[0];
    $date = $parts[1];
    $soc  = $parts[2];
    $ag   = $parts[3];
    $oid  = $parts[4];

    if (!preg_match('/^(\d{4}-\d{2}-\d{2})$/', $date)) return null;
    if (!preg_match('/^(IMB|BIEN|MDT|CTX|EMP|FOUR)-(\d+)$/', $oid, $om)) return null;

    // Le dernier segment = Vx
    $last = $parts[count($parts) - 1];
    if (!preg_match('/^V(\d+)$/', $last, $vm)) return null;
    $version = (int)$vm[1];

    // Ce qu'il y a entre l'index 5 inclus et avant-dernier = tiers + description (variable)
    $middle = array_slice($parts, 5, count($parts) - 6);
    $tiers = $middle[0] ?? null;
    $desc  = isset($middle[1]) ? implode('_', array_slice($middle, 1)) : null;

    return [
        'type'        => $type,
        'date'        => $date,
        'ref_societe' => $soc,
        'ref_agence'  => $ag,
        'objet_type'  => $om[1],
        'objet_id'    => (int)$om[2],
        'tiers'       => $tiers !== '' ? $tiers : null,
        'description' => $desc !== '' ? $desc : null,
        'version'     => $version,
        'ext'         => $ext,
    ];
}

/**
 * Slug : transforme en ASCII majuscules, underscores comme séparateur.
 */
function ged_slug_upper(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';

    // Translit accents → ASCII (best-effort, fallback si iconv KO)
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($tr) && $tr !== '') $s = $tr;
    }
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', '_', $s) ?? $s;
    $s = preg_replace('/_+/', '_', $s) ?? $s;
    $s = trim($s, '_');
    return $s;
}

/**
 * Normalise une date diverse (DD/MM/YYYY, YYYY-MM-DD, etc.) → YYYY-MM-DD.
 * Si invalide, retourne la date du jour (jamais d'exception silencieuse côté caller).
 */
function ged_normalize_date(string $d): string
{
    $d = trim($d);
    if ($d === '') return date('Y-m-d');

    // Déjà ISO ?
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : date('Y-m-d');
    }
    // Tentative DD/MM/YYYY ou DD-MM-YYYY
    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $d, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
    }
    // Fallback strtotime
    $ts = strtotime($d);
    if ($ts !== false) return date('Y-m-d', $ts);
    return date('Y-m-d');
}
