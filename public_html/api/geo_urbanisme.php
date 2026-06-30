<?php
declare(strict_types=1);
/**
 * api/geo_urbanisme.php — Urbanisme détaillé depuis un point GPS (API Carto GPU/IGN).
 *
 * Le COS n'existe plus (supprimé par la loi ALUR, 2014). Le CES, la hauteur, les
 * reculs et la divisibilité sont dans le RÈGLEMENT ÉCRIT de la zone (non structuré).
 * Ici on remonte ce qui EST exploitable et décisif pour la constructibilité :
 *   - zone (libellé + type) et destinations (autorisé / sous conditions / interdit)
 *   - prescriptions & servitudes SUR la parcelle (EBC, emplacement réservé,
 *     façade à conserver…) → impactent directement la divisibilité
 *   - drapeau SECTEUR SAUVEGARDÉ / SPR / PSMV / AVAP → avis ABF obligatoire
 *   - règlement de la zone (fichier) si fourni
 *
 * GET : lat=FLOAT&lng=FLOAT
 * Réponse : { ok, zone:{...}, abf:bool, abf_motif, destinations:{...},
 *             prescriptions:[...], reglement:{nom,url}|null }
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

$geom = rawurlencode(json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]));
$getJson = static function (string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $r = curl_exec($ch); curl_close($ch);
    if ($r === false) return null;
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : null;
};

// ── Zone d'urbanisme ──
$zone = null; $abf = false; $abfMotif = ''; $regl = null;
$destinations = ['autorise' => '', 'conditionnel' => '', 'interdit' => ''];
$zu = $getJson("https://apicarto.ign.fr/api/gpu/zone-urba?geom=$geom");
if (!empty($zu['features'][0]['properties'])) {
    $p = $zu['features'][0]['properties'];
    $zone = [
        'libelle'   => trim((string)($p['libelle'] ?? '')),
        'libelong'  => trim((string)($p['libelong'] ?? '')),
        'type'      => trim((string)($p['typezone'] ?? '')),
        'partition' => trim((string)($p['partition'] ?? '')),
        'datappro'  => trim((string)($p['datappro'] ?? '')),
    ];
    $destinations = [
        'autorise'     => trim((string)($p['destoui'] ?? '')),
        'conditionnel' => trim((string)($p['destcdt'] ?? '')),
        'interdit'     => trim((string)($p['destnon'] ?? '')),
    ];
    $nomfic = trim((string)($p['nomfic'] ?? ''));
    $urlfic = trim((string)($p['urlfic'] ?? ''));
    if ($nomfic !== '' || $urlfic !== '') $regl = ['nom' => $nomfic, 'url' => $urlfic];

    // SPR / secteur sauvegardé → ABF
    $part = mb_strtoupper($zone['partition']);
    $long = mb_strtoupper($zone['libelong'] . ' ' . $zone['libelle']);
    if (preg_match('/PSMV|AVAP|^SPR|_SPR|SAUVEGARD|PATRIMONIAL|AC4/', $part . ' ' . $long)) {
        $abf = true;
        $abfMotif = $zone['libelong'] !== '' ? $zone['libelong'] : 'Secteur patrimonial / sauvegardé';
    }
}

// ── Prescriptions / servitudes SUR la parcelle (surfaciques, linéaires, ponctuelles) ──
$prescriptions = [];
foreach (['prescription-surf', 'prescription-lin', 'prescription-pct'] as $api) {
    $pr = $getJson("https://apicarto.ign.fr/api/gpu/$api?geom=$geom");
    foreach (($pr['features'] ?? []) as $f) {
        $pp = $f['properties'] ?? [];
        $lib = trim((string)($pp['libelle'] ?? ($pp['txt'] ?? ($pp['typepsc'] ?? ''))));
        if ($lib !== '') $prescriptions[] = $lib;
    }
}
$prescriptions = array_values(array_unique($prescriptions));

echo json_encode([
    'ok' => true,
    'zone' => $zone,
    'abf' => $abf,
    'abf_motif' => $abfMotif,
    'destinations' => $destinations,
    'prescriptions' => $prescriptions,
    'reglement' => $regl,
], JSON_UNESCAPED_UNICODE);
