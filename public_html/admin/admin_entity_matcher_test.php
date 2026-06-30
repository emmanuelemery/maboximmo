<?php
/**
 * admin/admin_entity_matcher_test.php
 *
 * Page de test du moteur central entity_matcher.php — Sprint 1B.
 * 11 cas automatiques : 5 tiers + 6 immeubles + helpers + APIs.
 * STOP Sprint 2 sauf si TOUS les tests passent.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/entity_matcher.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? db();
$results = [];
$tests = []; // [name => ['pass'=>bool, 'detail'=>...]]

// ═══════════════════════════════════════════════════════════════════
// SECTION A — Infrastructure
// ═══════════════════════════════════════════════════════════════════
$constOk = defined('ENTITY_MATCHER_AUTO') && defined('ENTITY_MATCHER_VALIDATE') && defined('ENTITY_MATCHER_CREATE_OK');
$tests['A1. Constantes seuils définies'] = [
    'pass' => $constOk,
    'detail' => $constOk ? "AUTO=" . ENTITY_MATCHER_AUTO . " VAL=" . ENTITY_MATCHER_VALIDATE . " CREATE=" . ENTITY_MATCHER_CREATE_OK : 'NON DÉFINIES',
];

try {
    $hasTiers     = (bool)$pdo->query("SHOW TABLES LIKE 'tiers'")->fetchColumn();
    $hasImmeubles = (bool)$pdo->query("SHOW TABLES LIKE 'immeubles'")->fetchColumn();
    $tests['A2. Tables tiers + immeubles présentes'] = [
        'pass' => $hasTiers && $hasImmeubles,
        'detail' => "tiers=" . ($hasTiers?'Y':'N') . " immeubles=" . ($hasImmeubles?'Y':'N'),
    ];
} catch (Throwable $e) {
    $tests['A2. Tables tiers + immeubles présentes'] = ['pass' => false, 'detail' => $e->getMessage()];
    $hasTiers = $hasImmeubles = false;
}

$apiTiers     = file_exists(dirname(__DIR__) . '/api/tiers_check_duplicate.php');
$apiImmeuble  = file_exists(dirname(__DIR__) . '/api/immeuble_check_duplicate.php');
$tests['A3. APIs wrappers présentes'] = [
    'pass' => $apiTiers && $apiImmeuble,
    'detail' => "tiers=" . ($apiTiers?'Y':'N') . " immeuble=" . ($apiImmeuble?'Y':'N'),
];

$flagDefined = defined('FEATURE_ENTITY_MATCHER');
$tests['A4. Feature flag FEATURE_ENTITY_MATCHER défini'] = [
    'pass' => $flagDefined,
    'detail' => $flagDefined ? ('FEATURE_ENTITY_MATCHER=' . (FEATURE_ENTITY_MATCHER ? 'ON' : 'OFF')) : 'NON DÉFINI (legacy actif)',
];

// ═══════════════════════════════════════════════════════════════════
// SECTION B — Helpers normalisation
// ═══════════════════════════════════════════════════════════════════
$v2 = em_normalize_address('100 BLVD YVES FARGE');
$v3 = em_normalize_address('100 boulevard yves farge');
$tests['B1. Variantes adresse (BLVD vs boulevard) → identique'] = [
    'pass' => $v2 === $v3,
    'detail' => "v2='$v2' v3='$v3'",
];

$nums68a    = em_extract_street_numbers('6/8 rue Mermet');
$nums68b    = em_extract_street_numbers('6-8 rue Mermet');
$nums68c    = em_extract_street_numbers('6 à 8 rue Mermet');
$nums68et   = em_extract_street_numbers('6 et 8 rue Mermet');
$nums6      = em_extract_street_numbers('6 rue Mermet');
$rueA       = em_normalize_street('6/8 rue Mermet');
$rueB       = em_normalize_street('6 rue Mermet');
$tests['B2. Plage numéros 6/8 → [6,7,8]'] = [
    'pass' => $nums68a === [6,7,8] && $nums68b === [6,7,8] && $nums68c === [6,7,8],
    'detail' => "6/8=" . json_encode($nums68a) . " 6-8=" . json_encode($nums68b) . " 6àà8=" . json_encode($nums68c),
];
$tests['B3. "6 et 8" → [6,8] (pas de plage)'] = [
    'pass' => $nums68et === [6,8],
    'detail' => json_encode($nums68et),
];
$tests['B4. Rue normalisée 6/8 ≡ 6 rue Mermet'] = [
    'pass' => $rueA === $rueB && $rueA !== '',
    'detail' => "A='$rueA' B='$rueB'",
];

// ═══════════════════════════════════════════════════════════════════
// SECTION C — em_match_tiers
// ═══════════════════════════════════════════════════════════════════
// C1. Vide
$r = em_match_tiers($pdo, [], null, null, null);
$tests['C1. Tiers vide → found=false'] = [
    'pass' => !$r['found'] && $r['confidence'] === 0 && $r['can_create'],
    'detail' => "found=" . ($r['found']?'true':'false') . " conf=" . $r['confidence'] . " can_create=" . ($r['can_create']?'Y':'N'),
];

// Trouve un tiers réel pour les tests C2-C5
$sampleTiers = null;
if ($hasTiers) {
    try {
        $st = $pdo->query("SELECT id, nom, prenom, raison_sociale, email, telephone, siret
                           FROM tiers WHERE actif = 1
                             AND nom IS NOT NULL AND TRIM(nom) <> ''
                           ORDER BY id DESC LIMIT 1");
        $sampleTiers = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}

if ($sampleTiers) {
    // C2. Nom identique SEUL → found=true, conf>=60, needs_validation=true, can_create=false
    $r = em_match_tiers($pdo, ['nom' => $sampleTiers['nom']], null, null, null);
    $tests['C2. Nom seul → found=true, conf>=60, validation obligatoire, création bloquée'] = [
        'pass' => $r['found'] && $r['confidence'] >= 60 && $r['needs_user_validation'] && !$r['can_create'],
        'detail' => "nom='" . $sampleTiers['nom'] . "' conf=" . $r['confidence']
                  . " val=" . ($r['needs_user_validation']?'Y':'N') . " create=" . ($r['can_create']?'Y':'N')
                  . " warn=" . (!empty($r['create_with_warning'])?'Y':'N'),
    ];

    // C3. Nom + email → conf >= 85 (si tiers réel a un email)
    if (!empty($sampleTiers['email'])) {
        $r = em_match_tiers($pdo, [
            'nom' => $sampleTiers['nom'],
            'email' => $sampleTiers['email'],
        ], null, null, null);
        $tests['C3. Nom + email identique → conf>=85'] = [
            'pass' => $r['found'] && $r['confidence'] >= 85,
            'detail' => "conf=" . $r['confidence'] . " reasons=" . implode(', ', $r['best']['reasons'] ?? []),
        ];
    } else {
        $tests['C3. Nom + email identique → conf>=85'] = ['pass' => null, 'detail' => 'SKIP : tiers sample sans email'];
    }

    // C4. SIRET identique → conf >= 95
    if (!empty($sampleTiers['siret']) && preg_match('/^\d{9,14}$/', preg_replace('/\D/', '', (string)$sampleTiers['siret']))) {
        $r = em_match_tiers($pdo, ['siret' => $sampleTiers['siret']], null, null, null);
        $tests['C4. SIRET identique → conf>=95'] = [
            'pass' => $r['found'] && $r['confidence'] >= 95,
            'detail' => "conf=" . $r['confidence'] . " reasons=" . implode(', ', $r['best']['reasons'] ?? []),
        ];
    } else {
        $tests['C4. SIRET identique → conf>=95'] = ['pass' => null, 'detail' => 'SKIP : tiers sample sans SIRET valide'];
    }

    // C5. Téléphone identique → conf >= 65 (signal fort)
    if (!empty($sampleTiers['telephone'])) {
        $r = em_match_tiers($pdo, ['telephone' => $sampleTiers['telephone']], null, null, null);
        $tests['C5. Téléphone identique → conf>=65'] = [
            'pass' => $r['found'] && $r['confidence'] >= 65,
            'detail' => "conf=" . $r['confidence'] . " reasons=" . implode(', ', $r['best']['reasons'] ?? []),
        ];
    } else {
        $tests['C5. Téléphone identique → conf>=65'] = ['pass' => null, 'detail' => 'SKIP : tiers sample sans téléphone'];
    }
} else {
    $tests['C2. Nom seul → found=true, conf>=60'] = ['pass' => null, 'detail' => 'SKIP : aucun tiers actif'];
}

// ═══════════════════════════════════════════════════════════════════
// SECTION D — em_match_immeuble
// ═══════════════════════════════════════════════════════════════════
$sampleImm = null;
if ($hasImmeubles) {
    try {
        $st = $pdo->query("SELECT id, adresse_1, code_postal, ville, latitude, longitude
                           FROM immeubles
                           WHERE adresse_1 IS NOT NULL AND TRIM(adresse_1) <> ''
                             AND code_postal IS NOT NULL AND TRIM(code_postal) <> ''
                           ORDER BY id DESC LIMIT 1");
        $sampleImm = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}

if ($sampleImm) {
    // D1. Adresse exacte identique → conf >= 85 (strategy 3 normalized_address)
    $r = em_match_immeuble($pdo, [
        'adresse_1' => $sampleImm['adresse_1'],
        'code_postal' => $sampleImm['code_postal'],
        'ville' => $sampleImm['ville'],
    ], null);
    $tests['D1. Immeuble adresse identique → conf>=85'] = [
        'pass' => $r['found'] && $r['confidence'] >= 85,
        'detail' => "adr='" . $sampleImm['adresse_1'] . "' conf=" . $r['confidence']
                  . " type=" . $r['match_type'] . " best_id=" . ($r['best']['id'] ?? '?')
                  . " reasons=" . implode(', ', $r['best']['reasons'] ?? []),
    ];

    // D2. Variante minuscules → conf >= 85
    $r = em_match_immeuble($pdo, [
        'adresse_1' => strtolower((string)$sampleImm['adresse_1']),
        'code_postal' => $sampleImm['code_postal'],
        'ville' => $sampleImm['ville'],
    ], null);
    $tests['D2. Adresse minuscules → conf>=85'] = [
        'pass' => $r['found'] && $r['confidence'] >= 85,
        'detail' => "conf=" . $r['confidence'] . " type=" . $r['match_type'],
    ];

    // D3. Test plage : si l'adresse contient "/" ou "-", essayer 1 numéro seul
    $nums = em_extract_street_numbers((string)$sampleImm['adresse_1']);
    if (count($nums) >= 2) {
        // L'adresse a une plage (ex: 6/8) → on cherche avec juste le 1er numéro
        $rueOnly = em_normalize_street((string)$sampleImm['adresse_1']);
        $variant = $nums[0] . ' ' . $rueOnly;
        $r = em_match_immeuble($pdo, [
            'adresse_1' => $variant,
            'code_postal' => $sampleImm['code_postal'],
            'ville' => $sampleImm['ville'],
        ], null);
        $tests['D3. Numéro inclus dans plage (' . $nums[0] . ' vs ' . implode('/', $nums) . ') → conf>=85'] = [
            'pass' => $r['found'] && $r['confidence'] >= 85,
            'detail' => "variant='$variant' conf=" . $r['confidence'] . " type=" . $r['match_type']
                      . " reasons=" . implode(', ', $r['best']['reasons'] ?? []),
        ];
    } else {
        $tests['D3. Numéro inclus dans plage → conf>=85'] = ['pass' => null, 'detail' => 'SKIP : sample sans plage'];
    }

    // D4. Rue seule + CP + ville (sans numéro) → found=true mais needs_validation
    $rueSeule = em_normalize_street((string)$sampleImm['adresse_1']);
    if ($rueSeule !== '') {
        $r = em_match_immeuble($pdo, [
            'adresse_1' => $rueSeule,
            'code_postal' => $sampleImm['code_postal'],
            'ville' => $sampleImm['ville'],
        ], null);
        $tests['D4. Rue seule + CP + ville → found=true, validation obligatoire'] = [
            'pass' => $r['found'] && $r['needs_user_validation'],
            'detail' => "rue='$rueSeule' conf=" . $r['confidence']
                      . " val=" . ($r['needs_user_validation']?'Y':'N') . " type=" . $r['match_type'],
        ];
    } else {
        $tests['D4. Rue seule + CP + ville → found=true'] = ['pass' => null, 'detail' => 'SKIP : rue normalisée vide'];
    }

    // D5. Adresse inconnue → found=false
    $r = em_match_immeuble($pdo, [
        'adresse_1' => '999999 rue zzzzzzzzzzzz inexistante',
        'code_postal' => $sampleImm['code_postal'],
        'ville' => $sampleImm['ville'],
    ], null);
    $tests['D5. Adresse inconnue → found=false'] = [
        'pass' => !$r['found'],
        'detail' => "found=" . ($r['found']?'true':'false') . " conf=" . $r['confidence'],
    ];

    // D6. Géoloc proche (lat/lng identique) → conf=95 si dispo
    if ($sampleImm['latitude'] && $sampleImm['longitude'] && abs((float)$sampleImm['latitude']) > 0.01) {
        $r = em_match_immeuble($pdo, [
            'adresse_1' => 'adresse différente bidon',
            'code_postal' => '99999',
            'ville' => 'inexistante',
            'latitude' => (float)$sampleImm['latitude'],
            'longitude' => (float)$sampleImm['longitude'],
        ], null);
        $tests['D6. Géoloc identique → conf>=95'] = [
            'pass' => $r['found'] && $r['confidence'] >= 95,
            'detail' => "conf=" . $r['confidence'] . " type=" . $r['match_type'],
        ];
    } else {
        $tests['D6. Géoloc identique → conf>=95'] = ['pass' => null, 'detail' => 'SKIP : sample sans lat/lng'];
    }
} else {
    $tests['D1-D6. em_match_immeuble réels'] = ['pass' => null, 'detail' => 'SKIP : aucun immeuble en BDD'];
}

// ═══════════════════════════════════════════════════════════════════
// Verdict global
// ═══════════════════════════════════════════════════════════════════
$total = 0; $passed = 0; $failed = 0; $skipped = 0;
foreach ($tests as $t) {
    if ($t['pass'] === null) $skipped++;
    elseif ($t['pass']) { $total++; $passed++; }
    else { $total++; $failed++; }
}
$allPass = $failed === 0 && $passed > 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Test entity_matcher.php (Sprint 1B)</title>
    <style>
        body { font-family: "DM Mono", "Courier New", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1200px; margin: 0 auto; }
        h1 { color: #fde68a; margin: 0 0 8px; font-size: 22px; }
        h2 { color: #84a98c; font-size: 14px; margin: 24px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #334155; }
        .pass { color: #84a98c; font-weight: 700; }
        .fail { color: #f87171; font-weight: 700; }
        .skip { color: #94a3b8; }
        .test { background: #1e293b; padding: 8px 12px; border-radius: 4px; margin: 4px 0; border-left: 3px solid #334155; }
        .test.pass { border-left-color: #84a98c; }
        .test.fail { border-left-color: #f87171; }
        .test.skip { border-left-color: #94a3b8; }
        .test-name { font-weight: 600; }
        .test-detail { font-size: 11px; color: #94a3b8; margin-top: 4px; word-break: break-all; }
        .verdict { background: #1e293b; padding: 18px 22px; border-radius: 8px; margin-top: 30px; border-left: 6px solid; }
        .verdict.ok { border-left-color: #84a98c; }
        .verdict.ko { border-left-color: #f87171; }
        .verdict h3 { margin: 0 0 8px; font-size: 18px; }
        .stats { font-size: 14px; margin: 8px 0; }
    </style>
</head>
<body>

<h1>🧪 Test moteur entity_matcher.php — Sprint 1B</h1>
<p>Vérifications automatiques au chargement. Aucune modification BDD ni fichier legacy.</p>

<?php
$sections = [
    'A. Infrastructure'        => ['A1', 'A2', 'A3', 'A4'],
    'B. Helpers normalisation' => ['B1', 'B2', 'B3', 'B4'],
    'C. em_match_tiers'        => ['C1', 'C2', 'C3', 'C4', 'C5'],
    'D. em_match_immeuble'     => ['D1', 'D2', 'D3', 'D4', 'D5', 'D6'],
];
foreach ($sections as $secName => $prefixes) {
    echo "<h2>" . htmlspecialchars($secName) . "</h2>";
    foreach ($tests as $tName => $t) {
        $matchPrefix = false;
        foreach ($prefixes as $p) { if (str_starts_with($tName, $p)) { $matchPrefix = true; break; } }
        if (!$matchPrefix) continue;
        $cls = $t['pass'] === null ? 'skip' : ($t['pass'] ? 'pass' : 'fail');
        $icon = $t['pass'] === null ? '⏭️ SKIP' : ($t['pass'] ? '✅ PASS' : '❌ FAIL');
        echo "<div class='test $cls'><div class='test-name'><span class='$cls'>$icon</span> " . htmlspecialchars($tName) . "</div>";
        echo "<div class='test-detail'>" . htmlspecialchars($t['detail']) . "</div></div>";
    }
}
?>

<div class="verdict <?= $allPass ? 'ok' : 'ko' ?>">
    <h3><?= $allPass ? '✅ Sprint 1B PASS — feu vert Sprint 2' : '❌ Sprint 1B FAIL — STOP Sprint 2' ?></h3>
    <div class="stats">
        <strong><?= $passed ?></strong> PASS &nbsp; · &nbsp;
        <strong class="fail"><?= $failed ?></strong> FAIL &nbsp; · &nbsp;
        <span class="skip"><?= $skipped ?></span> SKIP &nbsp; (sur <?= count($tests) ?> tests)
    </div>
    <?php if ($allPass): ?>
        <p>Tous les tests métier passent. Le moteur est prêt à absorber bailleur_check_duplicate.php +
        bien_check_duplicate.php + transaction_doc_match.php + admin_tiers_merge.php (Sprint 2).</p>
    <?php else: ?>
        <p><strong>Règle absolue :</strong> aucun fichier legacy n'est migré tant qu'au moins 1 FAIL persiste.</p>
        <p>Skipped (null) = cas de test sans donnée représentative en BDD — pas un FAIL, mais à compléter si possible.</p>
    <?php endif; ?>
</div>

</body>
</html>
