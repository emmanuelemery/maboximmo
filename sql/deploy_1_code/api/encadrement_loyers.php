<?php
declare(strict_types=1);
/**
 * encadrement_loyers.php
 * Calcule les loyers de référence minoré / normal / majoré
 * pour Lyon (69001-69009) et Villeurbanne (69100)
 * selon l'arrêté préfectoral en vigueur.
 *
 * Source officielle : data.grandlyon.com — mise à jour annuelle
 * Données intégrées : arrêté du 15 décembre 2023 (applicable 2024)
 *
 * GET/POST params :
 *   code_postal     : ex. "69006"
 *   surface         : float m²
 *   nb_pieces       : int 1-4+
 *   annee_constr    : int ex. 1978
 *   meuble          : 0|1
 *
 * Réponse JSON :
 *   zone, epoque, type_loc,
 *   taux_ref, taux_min, taux_max,          (€/m²/mois)
 *   loyer_ref, loyer_min, loyer_max,        (€/mois = taux × surface)
 *   complement_motifs                        (liste des motifs possibles)
 */

require_once __DIR__ . '/../inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$cp         = trim((string)($_REQUEST['code_postal'] ?? ''));
$surface    = max(1.0, (float)($_REQUEST['surface']      ?? 0));
$nbPieces   = max(1,   (int)  ($_REQUEST['nb_pieces']    ?? 1));
$anneeConstr= (int)($_REQUEST['annee_constr'] ?? 0);
$meuble     = (bool)(int)($_REQUEST['meuble'] ?? 0);

/* ── Vérifier éligibilité ──────────────────────────── */
$villesEligibles = [
    '69001','69002','69003','69004','69005',
    '69006','69007','69008','69009','69100',
];
if (!in_array($cp, $villesEligibles, true)) {
    echo json_encode(['ok' => false, 'error' => 'ville_non_concernee',
        'message' => 'L\'encadrement des loyers ne s\'applique pas à ce code postal.']);
    exit;
}

/* ── Détermination de la zone ──────────────────────── */
// Zonage simplifié par arrondissement (approximation — vérifier avec le SIG Grand Lyon)
$zoneMap = [
    '69001' => 1, '69002' => 1, '69004' => 1,
    '69005' => 1, '69006' => 1,
    '69003' => 2, '69007' => 2, '69008' => 2, '69100' => 2,
    '69009' => 3,
];
$zone = $zoneMap[$cp] ?? 2;

/* ── Détermination de l'époque de construction ─────── */
if ($anneeConstr > 0) {
    if      ($anneeConstr < 1946)  $epoque = 'avant_1946';
    elseif  ($anneeConstr <= 1970) $epoque = '1946_1970';
    elseif  ($anneeConstr <= 1990) $epoque = '1971_1990';
    else                           $epoque = 'apres_1990';
} else {
    $epoque = 'apres_1990'; // valeur par défaut si non renseigné
}

/* ── Normalisation nb pièces ───────────────────────── */
$pieces = min(4, max(1, $nbPieces)); // 4 = "4 et plus"

/* ── Type de location ──────────────────────────────── */
$typeLoc = $meuble ? 'meuble' : 'vide';

/* ══════════════════════════════════════════════════════
   BARÈME 2024 — Arrêté préfectoral du 15 décembre 2023
   Loyers de référence en €/m²/mois
   Source : data.grandlyon.com / préfecture du Rhône
   À mettre à jour annuellement depuis :
   https://www.data.gouv.fr/fr/datasets/
     encadrement-des-loyers-a-lyon-et-villeurbanne/
   ══════════════════════════════════════════════════════ */
$bareme = [
    // [zone][type][epoque][nb_pieces] = loyer_reference €/m²/mois
    1 => [
        'meuble' => [
            'avant_1946' => [1 => 18.6, 2 => 15.4, 3 => 12.9, 4 => 11.9],
            '1946_1970'  => [1 => 15.3, 2 => 12.7, 3 => 11.1, 4 =>  9.8],
            '1971_1990'  => [1 => 15.2, 2 => 12.6, 3 => 10.5, 4 => 10.1],
            'apres_1990' => [1 => 16.8, 2 => 13.9, 3 => 11.7, 4 => 10.2],
        ],
        'vide' => [
            'avant_1946' => [1 => 14.8, 2 => 12.2, 3 => 10.2, 4 =>  9.4],
            '1946_1970'  => [1 => 12.1, 2 => 10.0, 3 =>  8.8, 4 =>  7.7],
            '1971_1990'  => [1 => 12.0, 2 => 10.0, 3 =>  8.3, 4 =>  8.0],
            'apres_1990' => [1 => 13.3, 2 => 11.0, 3 =>  9.3, 4 =>  8.1],
        ],
    ],
    2 => [
        'meuble' => [
            'avant_1946' => [1 => 16.0, 2 => 13.2, 3 => 11.0, 4 => 10.2],
            '1946_1970'  => [1 => 13.1, 2 => 10.8, 3 =>  9.5, 4 =>  8.3],
            '1971_1990'  => [1 => 13.0, 2 => 10.8, 3 =>  8.9, 4 =>  8.6],
            'apres_1990' => [1 => 14.4, 2 => 11.9, 3 => 10.0, 4 =>  8.7],
        ],
        'vide' => [
            'avant_1946' => [1 => 12.7, 2 => 10.4, 3 =>  8.7, 4 =>  8.1],
            '1946_1970'  => [1 => 10.4, 2 =>  8.6, 3 =>  7.5, 4 =>  6.6],
            '1971_1990'  => [1 => 10.3, 2 =>  8.5, 3 =>  7.1, 4 =>  6.8],
            'apres_1990' => [1 => 11.4, 2 =>  9.4, 3 =>  7.9, 4 =>  6.9],
        ],
    ],
    3 => [
        'meuble' => [
            'avant_1946' => [1 => 14.4, 2 => 11.9, 3 =>  9.9, 4 =>  9.2],
            '1946_1970'  => [1 => 11.8, 2 =>  9.8, 3 =>  8.6, 4 =>  7.5],
            '1971_1990'  => [1 => 11.7, 2 =>  9.7, 3 =>  8.0, 4 =>  7.8],
            'apres_1990' => [1 => 13.0, 2 => 10.7, 3 =>  9.0, 4 =>  7.9],
        ],
        'vide' => [
            'avant_1946' => [1 => 11.4, 2 =>  9.4, 3 =>  7.9, 4 =>  7.3],
            '1946_1970'  => [1 =>  9.4, 2 =>  7.7, 3 =>  6.8, 4 =>  5.9],
            '1971_1990'  => [1 =>  9.3, 2 =>  7.7, 3 =>  6.4, 4 =>  6.2],
            'apres_1990' => [1 => 10.3, 2 =>  8.5, 3 =>  7.1, 4 =>  6.2],
        ],
    ],
];

$tauxRef = (float)($bareme[$zone][$typeLoc][$epoque][$pieces] ?? 10.0);
$tauxMin = round($tauxRef * 0.70, 2);   // minoré = -30 %
$tauxMax = round($tauxRef * 1.20, 2);   // majoré = +20 %

$loyerRef = round($tauxRef * $surface);
$loyerMin = round($tauxMin * $surface);
$loyerMax = round($tauxMax * $surface);

/* ── Motifs de complément de loyer ────────────────── */
// Jurisprudence 2021-2024 sur Lyon/Villeurbanne
// Source : tribunaux judiciaires + OLAP + Commission départementale conciliation
$complementMotifs = [
    [
        'id'          => 'vue_exceptionnelle',
        'label'       => 'Vue exceptionnelle',
        'description' => 'Panorama sur monument classé, fleuve, colline (non visible depuis la rue)',
        'accepte'     => 'oui',
        'fourchette'  => '5 – 15 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.08),
    ],
    [
        'id'          => 'luminosite_exceptionnelle',
        'label'       => 'Luminosité exceptionnelle',
        'description' => 'Double exposition (façades opposées) ou triple exposition, très grandes baies vitrées',
        'accepte'     => 'oui',
        'fourchette'  => '5 – 8 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.06),
    ],
    [
        'id'          => 'calme_exceptionnel',
        'label'       => 'Calme exceptionnel',
        'description' => 'Fond de cour privé sans vis-à-vis, immeuble en retrait de toute voie',
        'accepte'     => 'oui',
        'fourchette'  => '3 – 7 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.05),
    ],
    [
        'id'          => 'terrasse_jardin',
        'label'       => 'Terrasse ou jardin privatif',
        'description' => 'Surface extérieure privative ≥ 50 % de la surface habitable (terrasse, jardin, loggia)',
        'accepte'     => 'oui',
        'fourchette'  => '5 – 10 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.07),
    ],
    [
        'id'          => 'piscine_privative',
        'label'       => 'Piscine privative ou partagée (résidence)',
        'description' => 'Piscine en accès exclusif ou résidence de standing avec piscine',
        'accepte'     => 'oui',
        'fourchette'  => '10 – 20 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.13),
    ],
    [
        'id'          => 'parking_couvert',
        'label'       => 'Place de parking couverte ou box privatif',
        'description' => 'Inclus dans le bail, non loué séparément, couvert ou en sous-sol',
        'accepte'     => 'oui',
        'fourchette'  => '30 – 80 €/mois',
        'montant_base'=> 50,
    ],
    [
        'id'          => 'prestations_haut_gamme',
        'label'       => 'Prestations intérieures haut de gamme',
        'description' => 'Parquet massif ancien, marbre, cuisine équipée grand standing (> 5 000 €), domotique',
        'accepte'     => 'oui',
        'fourchette'  => '5 – 12 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.07),
    ],
    [
        'id'          => 'concierge_services',
        'label'       => 'Services résidentiels exceptionnels',
        'description' => 'Conciergerie 24h/24, salle de sport privatisée, sécurité gardée en permanence',
        'accepte'     => 'conditionnel',
        'fourchette'  => '3 – 8 % du loyer de référence',
        'montant_base'=> round($loyerRef * 0.05),
    ],
    // ── Motifs refusés / risqués ──
    [
        'id'          => 'localisation',
        'label'       => 'Localisation / adresse prestigieuse',
        'description' => 'Déjà pris en compte dans la zone. Refusé systématiquement en commission.',
        'accepte'     => 'non',
        'fourchette'  => null,
        'montant_base'=> 0,
    ],
    [
        'id'          => 'ascenseur',
        'label'       => 'Ascenseur',
        'description' => 'Équipement standard non justifiant un complément selon la jurisprudence.',
        'accepte'     => 'non',
        'fourchette'  => null,
        'montant_base'=> 0,
    ],
    [
        'id'          => 'digicode_interphone',
        'label'       => 'Digicode / interphone / visiophone',
        'description' => 'Équipements de base. Refusés en commission de conciliation.',
        'accepte'     => 'non',
        'fourchette'  => null,
        'montant_base'=> 0,
    ],
    [
        'id'          => 'transports',
        'label'       => 'Proximité transports / commerces',
        'description' => 'Déjà intégré dans la valorisation de zone. Non retenu.',
        'accepte'     => 'non',
        'fourchette'  => null,
        'montant_base'=> 0,
    ],
];

/* ── Labels lisibles ───────────────────────────────── */
$epoqueLabels = [
    'avant_1946' => 'Avant 1946',
    '1946_1970'  => '1946 – 1970',
    '1971_1990'  => '1971 – 1990',
    'apres_1990' => 'Après 1990',
];
$zoneLabels = [
    1 => 'Zone 1 — Centre / Presqu'île / Rive gauche nord',
    2 => 'Zone 2 — Zones intermédiaires / Villeurbanne',
    3 => 'Zone 3 — Périphérie (Lyon 9ème)',
];

echo json_encode([
    'ok'           => true,
    'zone'         => $zone,
    'zone_label'   => $zoneLabels[$zone],
    'epoque'       => $epoque,
    'epoque_label' => $epoqueLabels[$epoque],
    'type_loc'     => $typeLoc,
    'nb_pieces_ref'=> $pieces,
    'surface'      => $surface,
    'taux_ref'     => $tauxRef,
    'taux_min'     => $tauxMin,
    'taux_max'     => $tauxMax,
    'loyer_ref'    => $loyerRef,
    'loyer_min'    => $loyerMin,
    'loyer_max'    => $loyerMax,
    'complement_motifs' => $complementMotifs,
    'source'       => 'Arrêté préfectoral du 15 décembre 2023 — Grand Lyon / Préfecture du Rhône',
    'annee_bareme' => 2024,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
