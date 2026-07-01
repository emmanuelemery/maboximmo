<?php
declare(strict_types=1);
/**
 * api/erp_rapport_pdf.php — Proxy du rapport ERP officiel (Géorisques) en PDF.
 *
 * Charge le rapport « État des Risques » complet directement dans l'app (modal),
 * pas seulement un lien. Proxy même-origine → embarquable en iframe (CSP frame-src 'self').
 *
 * Source : https://georisques.gouv.fr/api/v1/rapport_pdf?latlon=LNG,LAT  (officiel, gratuit)
 *
 * GET : lat=FLOAT&lng=FLOAT
 * Réponse : application/pdf (inline). Lecture seule. Admin / super admin.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();   // données publiques Géorisques (proxy même-origine) — accessible tous niveaux

$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);
if ($lat === 0.0 || $lng === 0.0) { http_response_code(400); exit('lat/lng requis'); }

$url = 'https://georisques.gouv.fr/api/v1/rapport_pdf?latlon=' . $lng . ',' . $lat;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_HTTPHEADER     => ['Accept: application/pdf'],
]);
$pdf  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($pdf === false || $code !== 200 || stripos($type, 'pdf') === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Rapport ERP indisponible (Géorisques).');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="rapport_erp.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=600');
echo $pdf;
