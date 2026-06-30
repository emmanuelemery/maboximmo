<?php
declare(strict_types=1);
/**
 * api/geo_cadastre_plu.php — Parcelle cadastrale + zone PLU depuis un point GPS.
 *
 * Sources OFFICIELLES gratuites (sans clé) = API Carto IGN/Etalab :
 *   - Cadastre  : https://apicarto.ign.fr/api/cadastre/parcelle  (section, numéro, contenance m²)
 *   - GPU (PLU) : https://apicarto.ign.fr/api/gpu/zone-urba       (zone U/AU/A/N + libellé)
 *
 * Données 🟢 certaines (officielles) → reprise dès l'adresse (Manifeste Loi 4).
 * Tolérant : si une source ne répond pas / pas de couverture PLU, on renvoie le reste.
 *
 * GET : lat=FLOAT&lng=FLOAT
 * Réponse : { ok, parcelle:{section,numero,commune,contenance}|null, plu:{type,libelle}|null }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');

$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);
if ($lat === 0.0 || $lng === 0.0) { exit(json_encode(['ok' => false, 'error' => 'lat/lng requis'])); }

$geom = json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]);

/** Appel GET JSON tolérant. */
$getJson = static function (string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 9,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    if ($r === false) return null;
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : null;
};

// ── Parcelle cadastrale ──
$parcelle = null;
$cad = $getJson('https://apicarto.ign.fr/api/cadastre/parcelle?geom=' . rawurlencode($geom));
if ($cad && !empty($cad['features'][0]['properties'])) {
    $p = $cad['features'][0]['properties'];
    $section = trim((string)($p['section'] ?? ''));
    $numero  = trim((string)($p['numero'] ?? ''));
    $insee   = trim((string)($p['code_dep'] ?? '')) . trim((string)($p['code_com'] ?? ''));
    $abs     = trim((string)($p['com_abs'] ?? '000')) ?: '000';
    // Référence cadastrale 14 car : INSEE(5)+ABS(3)+SECTION(2)+NUMERO(4)
    $ref = '';
    if ($insee !== '' && $section !== '' && $numero !== '') {
        $ref = $insee . str_pad($abs, 3, '0', STR_PAD_LEFT)
             . str_pad($section, 2, '0', STR_PAD_LEFT)
             . str_pad($numero, 4, '0', STR_PAD_LEFT);
    }
    $parcelle = [
        'section'     => $section,
        'numero'      => $numero,
        'commune'     => trim((string)($p['nom_com'] ?? '')),
        'code_insee'  => $insee,
        'reference'   => $ref,
        'contenance'  => isset($p['contenance']) ? (int)$p['contenance'] : null, // m²
    ];
}

// ── Zone PLU (GPU) ──
$plu = null;
$gpu = $getJson('https://apicarto.ign.fr/api/gpu/zone-urba?geom=' . rawurlencode($geom));
if ($gpu && !empty($gpu['features'][0]['properties'])) {
    $z = $gpu['features'][0]['properties'];
    $plu = [
        'type'    => trim((string)($z['typezone'] ?? '')),   // U / AU / A / N
        'libelle' => trim((string)($z['libelle'] ?? ($z['libelong'] ?? ''))),
    ];
}

echo json_encode([
    'ok'       => true,
    'parcelle' => $parcelle,
    'plu'      => $plu,
], JSON_UNESCAPED_UNICODE);
