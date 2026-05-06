<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * api/mbi_supports_generer_image_test.php
 * ═══════════════════════════════════════════════════════════════════════
 *
 * POC pivot affiche IA (2026-05-06) — génère 1 image d'ambiance pour
 * un bien donné via gpt-image-1 et la retourne / l'enregistre.
 *
 * Usage :
 *   GET /api/mbi_supports_generer_image_test.php?id_bien=886&angle=premium
 *
 * Paramètres :
 *   id_bien : int   (obligatoire)
 *   angle   : string (default 'famille')  — famille|investisseur|premium|premier_achat|generique
 *   format  : string (default 'png')      — png|json (json renvoie {url, prompt, cout, ...})
 *
 * Le PNG est sauvegardé dans uploads/supports/drafts/affiche_test_<id_bien>_<angle>_<ts>.png
 * et renvoyé directement (binaire) si format=png, ou URL+meta si format=json.
 *
 * ⚠️ POC uniquement : pas encore de composition finale (photo réelle +
 * mentions légales en bandeau PHP). On valide d'abord le rendu IA brut.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

require_once dirname(__DIR__) . '/inc/mbi_supports_image_ia.php';
require_once dirname(__DIR__) . '/inc/mbi_supports_redaction_ia.php';
require_once dirname(__DIR__) . '/inc/mbi_supports_image_compose.php';

$pdo       = $GLOBALS['pdo'];
$idBien    = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien']) ? (int)$_GET['id_bien'] : 0;
$angle     = (string)($_GET['angle']  ?? 'famille');
$format    = (string)($_GET['format'] ?? 'png');
$useHaiku  = !empty($_GET['use_haiku']) && $_GET['use_haiku'] !== '0';
$skipCompose = !empty($_GET['raw']) && $_GET['raw'] !== '0';  // ?raw=1 → renvoie l'image IA brute (sans photo+bandeau)

if ($idBien <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Paramètre id_bien manquant.\nExemple : ?id_bien=886&angle=premium");
}

$angles = ['famille','investisseur','premium','premier_achat','generique'];
if (!in_array($angle, $angles, true)) $angle = 'famille';

// ── Charge le bien + agence ───────────────────────────────────────
$bien = [];
try {
    $st = $pdo->prepare("
        SELECT b.*, a.nom_agence, a.ville AS ville_agence
        FROM biens b
        LEFT JOIN agences a ON a.id = b.id_agence
        WHERE b.id = :id LIMIT 1
    ");
    $st->execute([':id' => $idBien]);
    $bien = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Erreur DB : ' . $e->getMessage());
}
if (!$bien) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bien introuvable.');
}

$agence = [
    'nom_agence'   => $bien['nom_agence']   ?? '',
    'ville_agence' => $bien['ville_agence'] ?? '',
];

// ── Optionnel : génère d'abord la rédaction Haiku pour avoir l'accroche ──
$redaction = null;
if ($useHaiku && function_exists('mbi_supports_redaction_ia_generer')) {
    try {
        $rResp = mbi_supports_redaction_ia_generer($bien, [], $agence, $angle, null, 'haiku');
        if (!empty($rResp['ok']) && !empty($rResp['data'])) {
            $redaction = $rResp['data'];
        }
    } catch (Throwable $e) {
        error_log('[image_test] redaction Haiku failed: ' . $e->getMessage());
    }
}

// ── Génération image gpt-image-1 ──────────────────────────────────
$t0 = microtime(true);
$resp = mbi_supports_image_ia_generer($bien, $agence, $angle, $redaction);
$dureeSec = round(microtime(true) - $t0, 1);

if (!$resp['ok']) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erreur génération image :\n";
    echo "  modèle : " . ($resp['modele'] ?? '?') . "\n";
    echo "  durée  : {$dureeSec}s\n";
    echo "  erreur : " . ($resp['erreur'] ?? '?') . "\n\n";
    echo "Prompt utilisé :\n" . ($resp['prompt'] ?? '');
    exit;
}

// ── Sauvegarde PNG ────────────────────────────────────────────────
$dirAbs = dirname(__DIR__) . '/uploads/supports/drafts/';
if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
    http_response_code(500);
    exit('mkdir failed: ' . $dirAbs);
}

$fname  = 'affiche_test_' . $idBien . '_' . $angle . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4) . '.png';
$absPath= $dirAbs . $fname;
$relUrl = '/uploads/supports/drafts/' . $fname;

$bytes = base64_decode($resp['image_b64']);
if ($bytes === false || @file_put_contents($absPath, $bytes) === false) {
    http_response_code(500);
    exit('Erreur sauvegarde PNG');
}
$tailleKo = round(filesize($absPath) / 1024);

// ── Composition option B : photo réelle + bandeau mentions ──
$composedAbs = $absPath;
$composedRel = $relUrl;
$composeOk   = false;
$composeErr  = null;

if (!$skipCompose) {
    // Charge la photo principale du bien
    $photoPath = null;
    try {
        $stP = $pdo->prepare("SELECT url_photo FROM biens_photos WHERE id_bien = :b AND (exploitable = 1 OR exploitable IS NULL) ORDER BY ordre ASC, id ASC LIMIT 1");
        $stP->execute([':b' => $idBien]);
        $url = (string)($stP->fetchColumn() ?: '');
        if ($url !== '') {
            $rootPublic = dirname(__DIR__);
            $abs = $url[0] === '/' ? ($rootPublic . $url) : ($rootPublic . '/' . $url);
            if (is_file($abs) && is_readable($abs)) $photoPath = $abs;
        }
    } catch (Throwable) {}

    // Charge le contexte agence + nego pour le bandeau
    $agence = [
        'nom_agence'         => $bien['nom_agence']   ?? '',
        'ville'              => $bien['ville_agence'] ?? '',
        'telephone'          => null,
        'carte_pro_numero'   => null,
        'garant_financier'   => null,
        'rc_pro'             => null,
    ];
    if (!empty($bien['id_agence'])) {
        try {
            $stA = $pdo->prepare("SELECT nom_agence, ville, telephone, carte_pro_numero, garant_financier, rc_pro FROM agences WHERE id = :id LIMIT 1");
            $stA->execute([':id' => (int)$bien['id_agence']]);
            $a = $stA->fetch(PDO::FETCH_ASSOC) ?: [];
            $agence = array_merge($agence, $a);
        } catch (Throwable) {}
    }

    $nego = [];
    if (!empty($bien['id_user_actuel'])) {
        try {
            $stN = $pdo->prepare("SELECT nom, prenom, email, telephone_pro FROM users WHERE id = :id LIMIT 1");
            $stN->execute([':id' => (int)$bien['id_user_actuel']]);
            $nego = $stN->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {}
    }

    $compResp = mbi_supports_image_composer($absPath, $photoPath, [
        'bien'        => $bien,
        'agence'      => $agence,
        'negociateur' => $nego,
    ]);
    if ($compResp['ok']) {
        $composedAbs = $compResp['fichier_path'];
        $composedRel = '/uploads/supports/drafts/' . basename($composedAbs);
        $composeOk   = true;
    } else {
        $composeErr = $compResp['erreur'] ?? '?';
    }
}

// ── Sortie ─────────────────────────────────────────────────────────
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'            => true,
        'url_ia'        => $relUrl,
        'url_finale'    => $composedRel,
        'compose_ok'    => $composeOk,
        'compose_erreur'=> $composeErr,
        'taille_ko'     => $tailleKo,
        'modele'        => $resp['modele'],
        'cout_centimes' => $resp['cout_centimes'],
        'duree_sec'     => $dureeSec,
        'angle'         => $angle,
        'redaction_ia'  => $redaction,
        'prompt'        => $resp['prompt'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// format=png : retour direct du binaire (image composée par défaut, IA brute si ?raw=1)
$finalBytes = ($skipCompose || !$composeOk) ? $bytes : @file_get_contents($composedAbs);
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="' . basename($composedAbs) . '"');
header('X-Image-Url-IA: ' . $relUrl);
header('X-Image-Url-Finale: ' . $composedRel);
header('X-Compose-Ok: ' . ($composeOk ? '1' : '0'));
if ($composeErr) header('X-Compose-Erreur: ' . $composeErr);
header('X-Cout-Centimes: ' . (int)$resp['cout_centimes']);
header('X-Duree-Sec: ' . $dureeSec);
header('X-Modele: ' . $resp['modele']);
echo $finalBytes;
