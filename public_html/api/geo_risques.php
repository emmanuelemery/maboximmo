<?php
declare(strict_types=1);
/**
 * api/geo_risques.php — Risques naturels & technologiques depuis un point GPS.
 *
 * Source OFFICIELLE gratuite (sans clé) : API Géorisques (gouv.fr). Alimente
 * directement l'ERP (État des Risques, diagnostic obligatoire vente/location).
 *
 * GET : lat=FLOAT&lng=FLOAT
 * Réponse : { ok, commune, code_insee, url, risques:[{famille,label,statut}] }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);
if ($lat === 0.0 || $lng === 0.0) { exit(json_encode(['ok' => false, 'error' => 'lat/lng requis'])); }

$url = 'https://georisques.gouv.fr/api/v1/resultats_rapport_risque?latlon=' . $lng . ',' . $lat;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$r = curl_exec($ch);
curl_close($ch);

$d = is_string($r) ? json_decode($r, true) : null;
if (!is_array($d)) { exit(json_encode(['ok' => false, 'error' => 'Géorisques indisponible'])); }

$risques = [];
$collect = static function (array $bloc, string $famille) use (&$risques) {
    foreach ($bloc as $v) {
        if (!is_array($v) || empty($v['present'])) continue;
        $risques[] = [
            'famille' => $famille,
            'label'   => trim((string)($v['libelle'] ?? '')),
            'statut'  => trim((string)($v['libelleStatutAdresse'] ?? ($v['libelleStatutCommune'] ?? ''))),
        ];
    }
};
$collect($d['risquesNaturels'] ?? [], 'naturel');
$collect($d['risquesTechnologiques'] ?? [], 'techno');

echo json_encode([
    'ok'         => true,
    'commune'    => (string)($d['commune']['libelle'] ?? ''),
    'code_insee' => (string)($d['commune']['codeInsee'] ?? ''),
    'url'        => (string)($d['url'] ?? ''),
    'risques'    => $risques,
], JSON_UNESCAPED_UNICODE);
