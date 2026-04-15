<?php
/**
 * API Encadrement des loyers — Lyon & Villeurbanne
 *
 * Source officielle : Métropole de Lyon / data.gouv.fr
 * Arrêté préfectoral 2025-2026 (1er nov 2025 → 31 oct 2026)
 *
 * Paramètres GET :
 *   - code_postal  : code postal (69001-69009, 69100)
 *   - nb_pieces    : nombre de pièces (1, 2, 3, 4)  — 4 = "4 et plus"
 *   - epoque       : avant_1946 | 1946_1970 | 1971_1990 | 1991_2005 | apres_2005
 *   - meuble       : 0 ou 1
 *
 * Retour JSON :
 *   { ok, zone, loyer_reference, loyer_reference_majore, loyer_reference_minore, source }
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

// ═══════════════════════════════════════════════════════════════
// MAPPING CODE POSTAL → ZONE (source : CSV officiel Métropole)
// Vérifié sur data.gouv.fr le 15/04/2026 — codes INSEE → zones :
//   69381 (Lyon 1er) → Zone 1
//   69384 (Lyon 4e)  → Zone 2
//   69382 (Lyon 2e)  → Zone 3 | 69386 (Lyon 6e) → Zone 3
//   69385 (Lyon 5e)  → Zone 4
//   69383 (Lyon 3e)  → Zone 5 | 69387-69389 (7e,8e,9e) → Zone 5
//   69266 (Villeurbanne) → Zone 5
// ═══════════════════════════════════════════════════════════════
$CP_ZONE = [
    '69001' => 1,  // Lyon 1er
    '69002' => 3,  // Lyon 2e
    '69003' => 5,  // Lyon 3e
    '69004' => 2,  // Lyon 4e
    '69005' => 4,  // Lyon 5e
    '69006' => 3,  // Lyon 6e
    '69007' => 5,  // Lyon 7e
    '69008' => 5,  // Lyon 8e
    '69009' => 5,  // Lyon 9e
    '69100' => 5,  // Villeurbanne
];

// ═══════════════════════════════════════════════════════════════
// TARIFS OFFICIELS PAR ZONE (€/m² hab.)
// Source : data.gouv.fr — Encadrement loyers Métropole Lyon 2025-2026
// Format : [loyer_reference, loyer_reference_majore, loyer_reference_minore]
// Données extraites le 15/04/2026 du jeu de données officiel
// https://www.data.gouv.fr/datasets/encadrement-des-loyers-de-la-metropole-de-lyon-2025-2026
// ═══════════════════════════════════════════════════════════════
$TARIFS = [
    1 => [ // Lyon 1er
        1 => [
            'avant_1946'  => ['meuble' => [19.8, 23.8, 13.9], 'non_meuble' => [17.5, 21.0, 12.3]],
            '1946_1970'   => ['meuble' => [19.7, 23.6, 13.8], 'non_meuble' => [17.4, 20.9, 12.2]],
            '1971_1990'   => ['meuble' => [21.6, 25.9, 15.1], 'non_meuble' => [19.1, 22.9, 13.4]],
            '1991_2005'   => ['meuble' => [21.4, 25.7, 15.0], 'non_meuble' => [18.9, 22.7, 13.2]],
            'apres_2005'  => ['meuble' => [21.6, 25.9, 15.1], 'non_meuble' => [19.1, 22.9, 13.4]],
        ],
        2 => [
            'avant_1946'  => ['meuble' => [17.0, 20.4, 11.9], 'non_meuble' => [15.0, 18.0, 10.5]],
            '1946_1970'   => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
            '1971_1990'   => ['meuble' => [16.7, 20.0, 11.7], 'non_meuble' => [14.8, 17.8, 10.4]],
            '1991_2005'   => ['meuble' => [17.4, 20.9, 12.2], 'non_meuble' => [15.4, 18.5, 10.8]],
            'apres_2005'  => ['meuble' => [17.9, 21.5, 12.5], 'non_meuble' => [15.8, 19.0, 11.1]],
        ],
        3 => [
            'avant_1946'  => ['meuble' => [14.9, 17.9, 10.4], 'non_meuble' => [13.2, 15.8, 9.2]],
            '1946_1970'   => ['meuble' => [14.2, 17.0, 9.9],  'non_meuble' => [12.6, 15.1, 8.8]],
            '1971_1990'   => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
            '1991_2005'   => ['meuble' => [15.3, 18.4, 10.7], 'non_meuble' => [13.5, 16.2, 9.5]],
            'apres_2005'  => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
        ],
        4 => [
            'avant_1946'  => ['meuble' => [13.9, 16.7, 9.7],  'non_meuble' => [12.3, 14.8, 8.6]],
            '1946_1970'   => ['meuble' => [13.3, 16.0, 9.3],  'non_meuble' => [11.8, 14.2, 8.3]],
            '1971_1990'   => ['meuble' => [13.7, 16.4, 9.6],  'non_meuble' => [12.1, 14.5, 8.5]],
            '1991_2005'   => ['meuble' => [14.8, 17.8, 10.4], 'non_meuble' => [13.1, 15.7, 9.2]],
            'apres_2005'  => ['meuble' => [14.8, 17.8, 10.4], 'non_meuble' => [13.1, 15.7, 9.2]],
        ],
    ],
    2 => [ // Lyon 4e
        1 => [
            'avant_1946'  => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
            '1946_1970'   => ['meuble' => [19.3, 23.2, 13.5], 'non_meuble' => [17.1, 20.5, 12.0]],
            '1971_1990'   => ['meuble' => [20.2, 24.2, 14.1], 'non_meuble' => [17.9, 21.5, 12.5]],
            '1991_2005'   => ['meuble' => [21.9, 26.3, 15.3], 'non_meuble' => [19.4, 23.3, 13.6]],
            'apres_2005'  => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
        ],
        2 => [
            'avant_1946'  => ['meuble' => [15.9, 19.1, 11.1], 'non_meuble' => [14.1, 16.9, 9.9]],
            '1946_1970'   => ['meuble' => [15.3, 18.4, 10.7], 'non_meuble' => [13.5, 16.2, 9.5]],
            '1971_1990'   => ['meuble' => [15.0, 18.0, 10.5], 'non_meuble' => [13.3, 16.0, 9.3]],
            '1991_2005'   => ['meuble' => [16.4, 19.7, 11.5], 'non_meuble' => [14.5, 17.4, 10.2]],
            'apres_2005'  => ['meuble' => [17.1, 20.5, 12.0], 'non_meuble' => [15.1, 18.1, 10.6]],
        ],
        3 => [
            'avant_1946'  => ['meuble' => [14.1, 16.9, 9.9],  'non_meuble' => [12.5, 15.0, 8.8]],
            '1946_1970'   => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
            '1971_1990'   => ['meuble' => [13.6, 16.3, 9.5],  'non_meuble' => [12.0, 14.4, 8.4]],
            '1991_2005'   => ['meuble' => [14.6, 17.5, 10.2], 'non_meuble' => [12.9, 15.5, 9.0]],
            'apres_2005'  => ['meuble' => [15.0, 18.0, 10.5], 'non_meuble' => [13.3, 16.0, 9.3]],
        ],
        4 => [
            'avant_1946'  => ['meuble' => [13.8, 16.6, 9.7],  'non_meuble' => [12.2, 14.6, 8.5]],
            '1946_1970'   => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
            '1971_1990'   => ['meuble' => [12.4, 14.9, 8.7],  'non_meuble' => [11.0, 13.2, 7.7]],
            '1991_2005'   => ['meuble' => [13.4, 16.1, 9.4],  'non_meuble' => [11.9, 14.3, 8.3]],
            'apres_2005'  => ['meuble' => [13.8, 16.6, 9.7],  'non_meuble' => [12.2, 14.6, 8.5]],
        ],
    ],
    3 => [ // Lyon 2e, 6e
        1 => [
            'avant_1946'  => ['meuble' => [19.3, 23.2, 13.5], 'non_meuble' => [17.1, 20.5, 12.0]],
            '1946_1970'   => ['meuble' => [18.6, 22.3, 13.0], 'non_meuble' => [16.5, 19.8, 11.6]],
            '1971_1990'   => ['meuble' => [18.8, 22.6, 13.2], 'non_meuble' => [16.6, 19.9, 11.6]],
            '1991_2005'   => ['meuble' => [20.5, 24.6, 14.4], 'non_meuble' => [18.1, 21.7, 12.7]],
            'apres_2005'  => ['meuble' => [18.5, 22.2, 13.0], 'non_meuble' => [16.4, 19.7, 11.5]],
        ],
        2 => [
            'avant_1946'  => ['meuble' => [15.6, 18.7, 10.9], 'non_meuble' => [13.8, 16.6, 9.7]],
            '1946_1970'   => ['meuble' => [14.7, 17.6, 10.3], 'non_meuble' => [13.0, 15.6, 9.1]],
            '1971_1990'   => ['meuble' => [14.5, 17.4, 10.2], 'non_meuble' => [12.8, 15.4, 9.0]],
            '1991_2005'   => ['meuble' => [16.4, 19.7, 11.5], 'non_meuble' => [14.5, 17.4, 10.2]],
            'apres_2005'  => ['meuble' => [16.5, 19.8, 11.6], 'non_meuble' => [14.6, 17.5, 10.2]],
        ],
        3 => [
            'avant_1946'  => ['meuble' => [13.7, 16.4, 9.6],  'non_meuble' => [12.1, 14.5, 8.5]],
            '1946_1970'   => ['meuble' => [13.6, 16.3, 9.5],  'non_meuble' => [12.0, 14.4, 8.4]],
            '1971_1990'   => ['meuble' => [13.0, 15.6, 9.1],  'non_meuble' => [11.5, 13.8, 8.1]],
            '1991_2005'   => ['meuble' => [14.0, 16.8, 9.8],  'non_meuble' => [12.4, 14.9, 8.7]],
            'apres_2005'  => ['meuble' => [14.8, 17.8, 10.4], 'non_meuble' => [13.1, 15.7, 9.2]],
        ],
        4 => [
            'avant_1946'  => ['meuble' => [13.0, 15.6, 9.1],  'non_meuble' => [11.5, 13.8, 8.1]],
            '1946_1970'   => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
            '1971_1990'   => ['meuble' => [12.5, 15.0, 8.8],  'non_meuble' => [11.1, 13.3, 7.8]],
            '1991_2005'   => ['meuble' => [13.4, 16.1, 9.4],  'non_meuble' => [11.9, 14.3, 8.3]],
            'apres_2005'  => ['meuble' => [13.8, 16.6, 9.7],  'non_meuble' => [12.2, 14.6, 8.5]],
        ],
    ],
    4 => [ // Lyon 5e
        1 => [
            'avant_1946'  => ['meuble' => [18.1, 21.7, 12.7], 'non_meuble' => [16.0, 19.2, 11.2]],
            '1946_1970'   => ['meuble' => [17.6, 21.1, 12.3], 'non_meuble' => [15.6, 18.7, 10.9]],
            '1971_1990'   => ['meuble' => [17.5, 21.0, 12.3], 'non_meuble' => [15.5, 18.6, 10.9]],
            '1991_2005'   => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
            'apres_2005'  => ['meuble' => [17.6, 21.1, 12.3], 'non_meuble' => [15.6, 18.7, 10.9]],
        ],
        2 => [
            'avant_1946'  => ['meuble' => [15.1, 18.1, 10.6], 'non_meuble' => [13.4, 16.1, 9.4]],
            '1946_1970'   => ['meuble' => [14.9, 17.9, 10.4], 'non_meuble' => [13.2, 15.8, 9.2]],
            '1971_1990'   => ['meuble' => [14.6, 17.5, 10.2], 'non_meuble' => [12.9, 15.5, 9.0]],
            '1991_2005'   => ['meuble' => [15.7, 18.8, 11.0], 'non_meuble' => [13.9, 16.7, 9.7]],
            'apres_2005'  => ['meuble' => [16.4, 19.7, 11.5], 'non_meuble' => [14.5, 17.4, 10.2]],
        ],
        3 => [
            'avant_1946'  => ['meuble' => [13.1, 15.7, 9.2],  'non_meuble' => [11.6, 13.9, 8.1]],
            '1946_1970'   => ['meuble' => [12.3, 14.8, 8.6],  'non_meuble' => [10.9, 13.1, 7.6]],
            '1971_1990'   => ['meuble' => [12.2, 14.6, 8.5],  'non_meuble' => [10.8, 13.0, 7.6]],
            '1991_2005'   => ['meuble' => [13.4, 16.1, 9.4],  'non_meuble' => [11.9, 14.3, 8.3]],
            'apres_2005'  => ['meuble' => [14.2, 17.0, 9.9],  'non_meuble' => [12.6, 15.1, 8.8]],
        ],
        4 => [
            'avant_1946'  => ['meuble' => [12.9, 15.5, 9.0],  'non_meuble' => [11.4, 13.7, 8.0]],
            '1946_1970'   => ['meuble' => [11.3, 13.6, 7.9],  'non_meuble' => [10.0, 12.0, 7.0]],
            '1971_1990'   => ['meuble' => [12.1, 14.5, 8.5],  'non_meuble' => [10.7, 12.8, 7.5]],
            '1991_2005'   => ['meuble' => [13.0, 15.6, 9.1],  'non_meuble' => [11.5, 13.8, 8.1]],
            'apres_2005'  => ['meuble' => [12.9, 15.5, 9.0],  'non_meuble' => [11.4, 13.7, 8.0]],
        ],
    ],
    5 => [ // Lyon 3e, 7e, 8e, 9e + Villeurbanne
        1 => [
            'avant_1946'  => ['meuble' => [18.0, 21.6, 12.6], 'non_meuble' => [15.9, 19.1, 11.1]],
            '1946_1970'   => ['meuble' => [16.7, 20.0, 11.7], 'non_meuble' => [14.8, 17.8, 10.4]],
            '1971_1990'   => ['meuble' => [15.9, 19.1, 11.1], 'non_meuble' => [14.1, 16.9, 9.9]],
            '1991_2005'   => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
            'apres_2005'  => ['meuble' => [18.0, 21.6, 12.6], 'non_meuble' => [15.9, 19.1, 11.1]],
        ],
        2 => [
            'avant_1946'  => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
            '1946_1970'   => ['meuble' => [14.2, 17.0, 9.9],  'non_meuble' => [12.6, 15.1, 8.8]],
            '1971_1990'   => ['meuble' => [13.7, 16.4, 9.6],  'non_meuble' => [12.1, 14.5, 8.5]],
            '1991_2005'   => ['meuble' => [15.1, 18.1, 10.6], 'non_meuble' => [13.4, 16.1, 9.4]],
            'apres_2005'  => ['meuble' => [16.5, 19.8, 11.6], 'non_meuble' => [14.6, 17.5, 10.2]],
        ],
        3 => [
            'avant_1946'  => ['meuble' => [12.5, 15.0, 8.8],  'non_meuble' => [11.1, 13.3, 7.8]],
            '1946_1970'   => ['meuble' => [12.2, 14.6, 8.5],  'non_meuble' => [10.8, 13.0, 7.6]],
            '1971_1990'   => ['meuble' => [11.8, 14.2, 8.3],  'non_meuble' => [10.4, 12.5, 7.3]],
            '1991_2005'   => ['meuble' => [14.0, 16.8, 9.8],  'non_meuble' => [12.4, 14.9, 8.7]],
            'apres_2005'  => ['meuble' => [14.1, 16.9, 9.9],  'non_meuble' => [12.5, 15.0, 8.8]],
        ],
        4 => [
            'avant_1946'  => ['meuble' => [12.4, 14.9, 8.7],  'non_meuble' => [11.0, 13.2, 7.7]],
            '1946_1970'   => ['meuble' => [11.1, 13.3, 7.8],  'non_meuble' => [9.8, 11.8, 6.9]],
            '1971_1990'   => ['meuble' => [11.3, 13.6, 7.9],  'non_meuble' => [10.0, 12.0, 7.0]],
            '1991_2005'   => ['meuble' => [12.5, 15.0, 8.8],  'non_meuble' => [11.1, 13.3, 7.8]],
            'apres_2005'  => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
        ],
    ],
];

// ═══════════════════════════════════════════════════════════════
// TRAITEMENT DE LA REQUÊTE
// ═══════════════════════════════════════════════════════════════
$cp       = trim($_GET['code_postal'] ?? '');
$nbPieces = (int)($_GET['nb_pieces'] ?? 0);
$epoque   = trim($_GET['epoque'] ?? '');
$meuble   = (int)($_GET['meuble'] ?? 0);

// Déterminer la zone depuis le code postal
$zone = $CP_ZONE[$cp] ?? null;

if (!$zone) {
    echo json_encode([
        'ok' => false,
        'error' => 'Adresse hors zone encadrée (Lyon/Villeurbanne uniquement)',
        'code_postal' => $cp,
    ]);
    exit;
}

// Normaliser nb_pieces (4+ = 4)
if ($nbPieces <= 0) $nbPieces = 1;
if ($nbPieces > 4)  $nbPieces = 4;

// Valider l'époque
$epoquesValides = ['avant_1946', '1946_1970', '1971_1990', '1991_2005', 'apres_2005'];
if (!in_array($epoque, $epoquesValides, true)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Époque de construction requise',
        'epoques_valides' => $epoquesValides,
    ]);
    exit;
}

$typeKey = $meuble ? 'meuble' : 'non_meuble';

$tarif = $TARIFS[$zone][$nbPieces][$epoque][$typeKey] ?? null;
if (!$tarif) {
    echo json_encode(['ok' => false, 'error' => 'Tarif non trouvé pour cette combinaison']);
    exit;
}

// Mapping zone → arrondissements pour info
$ZONE_LABEL = [
    1 => 'Lyon 1er',
    2 => 'Lyon 4e (Croix-Rousse)',
    3 => 'Lyon 2e, 6e (Presqu\'île, Tête d\'Or)',
    4 => 'Lyon 5e (Vieux-Lyon)',
    5 => 'Lyon 3e, 7e, 8e, 9e, Villeurbanne',
];

echo json_encode([
    'ok'                      => true,
    'zone'                    => $zone,
    'zone_label'              => $ZONE_LABEL[$zone] ?? "Zone $zone",
    'loyer_reference'         => $tarif[0],
    'loyer_reference_majore'  => $tarif[1],
    'loyer_reference_minore'  => $tarif[2],
    'nb_pieces'               => $nbPieces,
    'epoque'                  => $epoque,
    'meuble'                  => (bool)$meuble,
    'source'                  => 'Métropole de Lyon / data.gouv.fr — Arrêté préfectoral 2025-2026',
]);
