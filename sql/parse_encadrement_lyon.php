<?php
/**
 * Parse le CSV d'encadrement des loyers Lyon et génère :
 * 1. Un mapping INSEE → zone
 * 2. Les tarifs par zone (agrégés — tous les IRIS d'une zone ont les mêmes tarifs)
 */

$file = __DIR__ . '/encadrement_loyers_lyon_2025_2026.csv';
$handle = fopen($file, 'r');
$header = fgetcsv($handle);

// Index des colonnes
$idx = array_flip($header);

$zones = [];       // zone => valeurs JSON (toutes identiques par zone)
$inseeZone = [];   // insee => zone

while (($row = fgetcsv($handle)) !== false) {
    $insee   = $row[$idx['insee']] ?? '';
    $zonage  = (int)($row[$idx['zonage']] ?? 0);
    $commune = $row[$idx['commune']] ?? '';
    $valeurs = $row[$idx['valeurs']] ?? '';

    if (!$insee || !$zonage) continue;

    $inseeZone[$insee] = ['zone' => $zonage, 'commune' => $commune];

    if (!isset($zones[$zonage]) && $valeurs) {
        $decoded = json_decode($valeurs, true);
        if ($decoded) {
            $zones[$zonage] = $decoded;
        }
    }
}
fclose($handle);

// Afficher le mapping INSEE → zone
echo "=== MAPPING INSEE → ZONE ===\n";
ksort($inseeZone);
$seen = [];
foreach ($inseeZone as $code => $info) {
    $key = $code . '-' . $info['zone'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    echo sprintf("  %s (%s) → Zone %d\n", $code, $info['commune'], $info['zone']);
}

// Afficher les tarifs par zone
echo "\n=== TARIFS PAR ZONE ===\n";
ksort($zones);
foreach ($zones as $z => $data) {
    echo "\n--- Zone $z ---\n";
    foreach ($data as $nbPieces => $epoques) {
        echo "  $nbPieces pièce(s):\n";
        foreach ($epoques as $epoque => $types) {
            foreach ($types as $type => $tarifs) {
                echo sprintf("    %-12s %-10s → ref=%.1f maj=%.1f min=%.1f\n",
                    $epoque, $type,
                    $tarifs['loyer_reference'] ?? 0,
                    $tarifs['loyer_reference_majore'] ?? 0,
                    $tarifs['loyer_reference_minore'] ?? 0
                );
            }
        }
    }
}
