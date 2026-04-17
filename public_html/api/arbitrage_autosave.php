<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/arbitrage_access.php';
require_once dirname(__DIR__) . '/inc/arbitrage_calc.php';
require_once dirname(__DIR__) . '/inc/arbitrage_crg.php';
require_once dirname(__DIR__) . '/inc/arbitrage_text.php';
require_once dirname(__DIR__) . '/inc/arbitrage_ai.php';

require_arbitrage_access();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('arbitrage');

$pdo = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$bienId = (int)($input['id_bien'] ?? 0);
if ($bienId <= 0) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'id_bien requis']));
}

$regenAi = !empty($input['regen_ai']);

// ── Charger le bien + contrôle d'accès ────────────────────────────────
$stmtBien = $pdo->prepare("
    SELECT
        b.*,
        tb.code AS type_code, tb.libelle AS type_libelle,
        p.societe AS proprietaire_societe, p.nom AS proprietaire_nom, p.prenom AS proprietaire_prenom,
        (SELECT ba.locataire_nom FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS locataire_nom,
        (SELECT ba.date_debut    FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS bail_debut,
        (SELECT ba.date_fin      FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS bail_fin
    FROM biens b
    LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
    WHERE b.id = ?
    LIMIT 1
");
$stmtBien->execute([$bienId]);
$bien = $stmtBien->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
}

// Contrôle d'accès (societe / SIR / admin)
$roleId = (int)current_role_id();
$isAdmin = in_array($roleId, [1, 7], true) || !empty($_SESSION['super_admin']);

if (!$isAdmin) {
    $bienSocieteId = (int)($bien['id_societe'] ?? 0);
    if ($societeId > 0 && $bienSocieteId === $societeId) {
        // ok
    } else {
        $code = strtoupper((string)($_SESSION['code_acces'] ?? ''));
        if ($code === 'SIR') {
            $propId = (int)($bien['id_proprietaire'] ?? 0);
            $allowed = in_array($propId, get_sir_proprietaire_ids($userId), true);
            if (!$allowed) {
                http_response_code(403);
                exit(json_encode(['ok' => false, 'error' => 'Accès refusé (scope propriétaire).']));
            }
        } else {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Accès refusé (scope société).']));
        }
    }
}

// ── Charger settings (fallback default) ───────────────────────────────
$settings = [
    'objectif_tresorerie' => 15000000.0,
    'horizon_mois' => 18,
    'rendement_reinvest_cible_pct' => 6.0,
];
try {
    $stmtS = $pdo->prepare("SELECT objectif_tresorerie, horizon_mois, rendement_reinvest_cible_pct FROM arbitrage_settings WHERE id_societe = ? LIMIT 1");
    $stmtS->execute([$societeId]);
    $rowS = $stmtS->fetch(PDO::FETCH_ASSOC);
    if ($rowS) {
        $settings['objectif_tresorerie'] = (float)($rowS['objectif_tresorerie'] ?? $settings['objectif_tresorerie']);
        $settings['horizon_mois'] = (int)($rowS['horizon_mois'] ?? $settings['horizon_mois']);
        $settings['rendement_reinvest_cible_pct'] = (float)($rowS['rendement_reinvest_cible_pct'] ?? $settings['rendement_reinvest_cible_pct']);
    }
} catch (Throwable $e) {
    // Table non migrée : garder défaut
}

// ── Upsert arbitrage_biens ────────────────────────────────────────────
$allowed = [
    // décision
    'decision' => 'str',
    'posture' => 'str',
    'statut_locatif' => 'str',
    // vacance/loyers
    'vacance_debut' => 'date',
    'vacance_mois' => 'int',
    'loyer_actuel_mensuel' => 'float',
    'loyer_potentiel_mensuel' => 'float',
    'qualite_locative' => 'int',
    'difficulte_relocation' => 'int',
    // coûts
    'taxe_fonciere' => 'float',
    'charges_non_recup' => 'float',
    'assurance' => 'float',
    'entretien' => 'float',
    'frais_gestion' => 'float',
    'autres_couts_annuels' => 'float',
    // travaux
    'travaux_niveau' => 'str',
    'travaux_tags' => 'json',
    'travaux_1an' => 'float',
    'travaux_3ans' => 'float',
    'travaux_5ans' => 'float',
    // prix
    'prix_estime' => 'float',
    'prix_demande' => 'float',
    'prix_propose' => 'float',
    'prix_vente_realiste' => 'float',
    'frais_agence' => 'float',
    'frais_notaire' => 'float',
    'cout_acte_en_main' => 'float',
    'decote_pct' => 'float',
    'delai_vente_mois' => 'int',
    'liquidite_niveau' => 'int',
    'risque_niveau' => 'int',
    // emery
    'commentaire_emery' => 'str',
    'conclusion_emery' => 'str',
];

// Charger arbitrage existant
$arb = [];
$arbId = 0;
try {
    $stmtA = $pdo->prepare("SELECT * FROM arbitrage_biens WHERE id_societe = ? AND id_bien = ? LIMIT 1");
    $stmtA->execute([$societeId, $bienId]);
    $arb = $stmtA->fetch(PDO::FETCH_ASSOC) ?: [];
    $arbId = (int)($arb['id'] ?? 0);
} catch (Throwable $e) {
    // Table non migrée : on renverra une erreur explicite plus bas
}

if ($arbId <= 0) {
    try {
        $pdo->prepare("INSERT INTO arbitrage_biens (id_societe, id_bien, posture, travaux_niveau, argumentaire_source, created_at, updated_at, updated_by) VALUES (?,?, 'neutre','aucun','template', NOW(), NOW(), ?)")
            ->execute([$societeId, $bienId, $userId]);
        $arbId = (int)$pdo->lastInsertId();
        $arb = ['id' => $arbId, 'id_societe' => $societeId, 'id_bien' => $bienId, 'posture' => 'neutre', 'travaux_niveau' => 'aucun'];
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => 'Table arbitrage_biens absente : appliquer la migration SQL.']));
    }
}

// Normalisation + build update
$updates = [];
$params = [];

foreach ($allowed as $key => $type) {
    if (!array_key_exists($key, $input)) continue;
    $value = $input[$key];

    if ($type === 'float') {
        $value = ($value === '' || $value === null) ? null : (float)$value;
    } elseif ($type === 'int') {
        $value = ($value === '' || $value === null) ? null : (int)$value;
    } elseif ($type === 'date') {
        $value = trim((string)$value);
        $value = $value === '' ? null : $value;
    } elseif ($type === 'json') {
        if ($value === '' || $value === null) $value = null;
        if (is_string($value)) {
            $tmp = json_decode($value, true);
            $value = is_array($tmp) ? $tmp : null;
        }
        $value = $value !== null ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    } else { // str
        $value = trim((string)$value);
        $value = $value === '' ? null : $value;
    }

    $updates[] = "`$key` = ?";
    $params[] = $value;
    $arb[$key] = $value;
}

// Recalcul + textes (toujours)
$crgSnap = arb_crg_latest_snapshot($pdo, $bienId);
$metrics = arb_compute_metrics($bien, $arb, $crgSnap['latest'] ?? [], $settings);

$syntheseTemplate = arb_build_synthese_template($bien, $arb, $crgSnap, $metrics, $settings);
$argumentaireTemplate = arb_build_argumentaire_template($bien, $arb, $crgSnap, $metrics, $settings);
$commentaire = (string)($arb['commentaire_emery'] ?? '');
$conclusion  = (string)($arb['conclusion_emery'] ?? '');
$argumentaireFinal = arb_merge_argumentaire_final($syntheseTemplate, $crgSnap, $commentaire, $conclusion, $argumentaireTemplate);

$argumentaireSource = 'template';
$aiPayload = null;

if ($regenAi) {
    $facts = [
        'bien' => [
            'id' => (int)($bien['id'] ?? 0),
            'designation' => (string)($bien['designation'] ?? ''),
            'ville' => (string)($bien['ville'] ?? ''),
            'adresse' => (string)($bien['adresse_1'] ?? ''),
            'type' => (string)($bien['type_libelle'] ?? $bien['type_bien'] ?? ''),
            'surface' => (float)($bien['surface_habitable'] ?? 0.0),
        ],
        'arbitrage' => [
            'decision' => (string)($arb['decision'] ?? ''),
            'posture' => (string)($arb['posture'] ?? 'neutre'),
            'statut_locatif' => (string)($arb['statut_locatif'] ?? ''),
        ],
        'metrics' => $metrics,
        'crg_signaux' => $crgSnap['signals'] ?? [],
        'settings' => $settings,
    ];

    $ai = arb_generate_argumentaire_ai([
        'posture' => (string)($arb['posture'] ?? 'neutre'),
        'facts' => $facts,
        'commentaire_emery' => $commentaire,
        'conclusion_emery' => $conclusion,
    ]);

    if (!empty($ai['ok']) && !empty($ai['data']) && is_array($ai['data'])) {
        $aiPayload = $ai;
        $synth = trim((string)($ai['data']['synthese_auto'] ?? ''));
        $arg   = trim((string)($ai['data']['argumentaire_final'] ?? ''));
        if ($synth !== '' && $arg !== '') {
            $syntheseTemplate = $synth;
            $argumentaireFinal = $arg;
            $argumentaireSource = 'ia';
        }
    } else {
        $aiPayload = $ai;
    }
}

// On enregistre aussi le cache texte côté DB (utile export/impression)
$updates[] = "`synthese_auto` = ?";
$params[] = $syntheseTemplate;
$updates[] = "`argumentaire_final` = ?";
$params[] = $argumentaireFinal;
$updates[] = "`argumentaire_updated_at` = NOW()";
$updates[] = "`argumentaire_source` = ?";
$params[] = $argumentaireSource;

$updates[] = "`updated_by` = ?";
$params[] = $userId;

if (!empty($updates)) {
    $sql = "UPDATE arbitrage_biens SET " . implode(', ', $updates) . " WHERE id = ? LIMIT 1";
    $params[] = $arbId;
    $pdo->prepare($sql)->execute($params);

    // AuditLog (diff minimal)
    if (class_exists('AuditLog')) {
        AuditLog::log($pdo, 'UPDATE', 'arbitrage_biens', $arbId, [], [
            'id_bien' => $bienId,
            'updated_by' => $userId,
            'argumentaire_source' => $argumentaireSource,
        ]);
    }
}

$savedAt = date('H:i');

echo json_encode([
    'ok' => true,
    'saved_at' => $savedAt,
    'metrics' => $metrics,
    'crg' => [
        'latest' => $crgSnap['latest'] ?? [],
        'signals' => $crgSnap['signals'] ?? [],
    ],
    'texts' => [
        'synthese_auto' => $syntheseTemplate,
        'argumentaire_final' => $argumentaireFinal,
        'source' => $argumentaireSource,
    ],
    'ai' => $aiPayload,
], JSON_UNESCAPED_UNICODE);

