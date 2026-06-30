<?php
/**
 * geocode_emery_immeubles.php — Géocodage Google des immeubles EMERY IMMO (société 2)
 * + détection des doublons d'immeubles par google_place_id (CRG ↔ annonce).
 *
 * Réutilise la même logique que admin/admin_immeubles_geocode_batch.php.
 * Remplit latitude, longitude, google_place_id, adresse_formatee.
 * NE supprime/fusionne RIEN : se contente de géocoder + RAPPORTER les doublons.
 *
 * Usage : php geocode_emery_immeubles.php          (géocode société 2 sans lat/lng)
 *         php geocode_emery_immeubles.php report   (juste le rapport de doublons)
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];
$REPORT_ONLY = (($argv[1] ?? '') === 'report');

$googleKey = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
if ($googleKey === '') { fwrite(STDERR, "GOOGLE_MAPS_API_KEY absente.\n"); exit(1); }

if (!$REPORT_ONLY) {
    $st = $pdo->query("SELECT id, adresse_1, code_postal, ville FROM immeubles
        WHERE id_societe=2 AND (latitude IS NULL OR longitude IS NULL) AND adresse_1<>''
        ORDER BY id");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ok=0;$fail=0;$i=0;$n=count($rows);
    echo "Géocodage de $n immeubles société 2…\n";
    foreach ($rows as $im) {
        $i++;
        $adresse = trim((string)$im['adresse_1'] . ' ' . $im['code_postal'] . ' ' . $im['ville']);
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($adresse) . '&key=' . urlencode($googleKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $body = $raw ? json_decode((string)$raw, true) : null;
        if ($code === 200 && ($body['status'] ?? '') === 'OK' && !empty($body['results'])) {
            $g = $body['results'][0];
            $lat = $g['geometry']['location']['lat'] ?? null;
            $lng = $g['geometry']['location']['lng'] ?? null;
            $pid = $g['place_id'] ?? null;
            $fmt = $g['formatted_address'] ?? null;
            if ($lat && $lng) {
                $pdo->prepare("UPDATE immeubles SET latitude=?, longitude=?, google_place_id=?, adresse_formatee=?, gps_source='google' WHERE id=?")
                    ->execute([$lat, $lng, $pid, $fmt, $im['id']]);
                $ok++;
                if ($i % 25 === 0) echo "  … $i/$n ($ok OK)\n";
                usleep(60000);
                continue;
            }
        }
        $fail++;
        echo "  ÉCHEC #{$im['id']} : $adresse (" . ($body['status'] ?? 'http '.$code) . ")\n";
        usleep(120000);
    }
    echo "Géocodés : $ok | échecs : $fail | coût approx : " . number_format($ok*0.005,2) . " €\n\n";
}

// ── Rapport doublons d'immeubles par google_place_id ──────────────
echo "=== DOUBLONS D'IMMEUBLES (même google_place_id) ===\n";
$dups = $pdo->query("SELECT google_place_id, COUNT(*) n, GROUP_CONCAT(id) ids
    FROM immeubles WHERE google_place_id IS NOT NULL AND google_place_id<>''
    GROUP BY google_place_id HAVING n>1")->fetchAll(PDO::FETCH_ASSOC);
$nd=0;
foreach ($dups as $d) {
    $ids = explode(',', $d['ids']);
    $det = $pdo->query("SELECT id, nom_immeuble, adresse_1, code_crg, id_proprietaire FROM immeubles WHERE id IN (".$d['ids'].")")->fetchAll(PDO::FETCH_ASSOC);
    // ne montrer que si mix CRG / non-CRG (vrai doublon à fusionner)
    $hasCrg = false; $hasNon = false;
    foreach ($det as $x) { if ($x['code_crg']) $hasCrg=true; else $hasNon=true; }
    if (!($hasCrg && $hasNon)) continue;
    $nd++;
    echo "\n  place_id …" . substr($d['google_place_id'],-12) . " :\n";
    foreach ($det as $x) {
        echo sprintf("     imm#%-6s %-22s %-28s crg=%-12s prop=%s\n",
            $x['id'], mb_substr((string)$x['nom_immeuble'],0,22), mb_substr((string)$x['adresse_1'],0,28), $x['code_crg']?:'-', $x['id_proprietaire']?:'-');
    }
}
echo "\nDoublons CRG↔annonce détectés : $nd (à fusionner en gardant l'immeuble porteur d'annonce)\n";
