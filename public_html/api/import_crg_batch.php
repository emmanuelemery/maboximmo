<?php
/**
 * api/import_crg_batch.php — Chargement de MASSE des CRG par DOSSIER.
 *
 * Principe (= méthode éprouvée) : un dossier de base contient un SOUS-DOSSIER
 * par propriétaire (le nom du dossier = le propriétaire, fiable même quand le
 * PDF ne porte pas le nom, ex. EMERY IMMO). Chaque PDF est parsé par le parser
 * déterministe Python (parse_crg.py) ; fallback GPT-4o si le Python échoue.
 * Trimestre déduit du nom de fichier. DÉTECTION DE DOUBLON sur
 * (propriétaire, année, trimestre). Écrit via le cœur partagé → bien_baux + GED.
 *
 * Actions (pilotées par la page admin, 1 PDF par appel pour éviter les timeouts) :
 *   - scan        : liste les PDF du dossier + statut (nouveau / doublon)
 *   - process_one : parse + import d'UN PDF, renvoie le statut
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ia_analyse.php';
require_once __DIR__ . '/../inc/crg_import_core.php';
// Diagnostic session (avant tout require) : /api/import_crg_batch.php?ping=1
if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'            => true,
        'session_id'    => session_id(),
        'user_id'       => $_SESSION['user_id'] ?? null,
        'is_admin'      => function_exists('is_admin_or_super_admin') ? is_admin_or_super_admin() : null,
        'cookie_recu'   => isset($_COOKIE['PHPSESSID']),
    ]);
    exit;
}
// Log léger pour diagnostic (méthode, cookie, session, action).
error_log('[import_crg_batch] '.($_SERVER['REQUEST_METHOD']??'?')
    .' cookie='.(isset($_COOKIE['PHPSESSID'])?'1':'0')
    .' user='.($_SESSION['user_id']??'null')
    .' action='.($_POST['action']??$_GET['action']??'-'));

// Auth EN JSON (jamais de redirection HTML qui casse le fetch).
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'status'=>'erreur',
        'error'=>'Session non transmise (reconnecte-toi sur localhost puis réessaie).']);
    exit;
}
if (function_exists('is_admin_or_super_admin') && !is_admin_or_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'status'=>'erreur','error'=>'Accès réservé aux admins.']);
    exit;
}
verify_csrf_any();

// Aucun HTML ne doit polluer la réponse : on capture tout fatal en JSON.
ini_set('display_errors', '0');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'status' => 'erreur',
            'error' => 'FATAL: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']]);
    }
});
set_time_limit(300);

$pdo       = $GLOBALS['pdo'];
$userId    = (int)current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
// Agence gestionnaire du lot CRG : override explicite (formulaire) sinon session.
// Indispensable pour un super admin dont la session n'est rattachée à aucune agence.
$agenceId  = (int)($_POST['id_agence'] ?? $_GET['id_agence'] ?? $_SESSION['id_agence'] ?? 0);

// Parser Python (surchargeable par variable d'env pour la portabilité prod).
$python = getenv('CRG_PYTHON') ?: 'C:/Users/emery/AppData/Local/Python/bin/python3.exe';
$parser = __DIR__ . '/../scripts/parse_crg.py';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Exécute parse_crg.py sur un PDF, renvoie le tableau brut ou null. */
function crg_run_python(string $python, string $parser, string $pdfPath): ?array {
    if (!is_file($python) || !is_file($parser)) return null;
    $tmpJson = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crgb_' . uniqid('', true) . '.json';
    $tmpPy   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crgb_' . uniqid('', true) . '.py';
    $code = "import sys, json\n"
          . "sys.path.insert(0, " . json_encode(dirname($parser)) . ")\n"
          . "from parse_crg import parse_crg\n"
          . "r = parse_crg(" . json_encode($pdfPath) . ")\n"
          . "open(" . json_encode($tmpJson) . ", 'w', encoding='utf-8').write(json.dumps(r, ensure_ascii=False))\n";
    file_put_contents($tmpPy, $code);
    @shell_exec(escapeshellarg($python) . ' ' . escapeshellarg($tmpPy) . ' 2>&1');
    @unlink($tmpPy);
    $j = @file_get_contents($tmpJson);
    @unlink($tmpJson);
    $d = json_decode($j ?: '', true);
    return (is_array($d) && empty($d['error']) && !empty($d['meta'])) ? $d : null;
}

/** Résout le propriétaire par NOM de dossier, le crée si absent. */
function crg_proprio_by_folder(PDO $pdo, string $folderName, ?int $agenceId): int {
    $fake = ['proprietaire' => ['nom' => $folderName]];
    $r = crg_resolve_proprietaire($pdo, $fake, $agenceId);
    if ($r['id'] > 0) return $r['id'];
    return crg_create_proprietaire($pdo, $fake, $agenceId);
}

/** Liste les PDF d'un dossier de base : soit sous-dossiers=propriétaires, soit PDF à plat. */
function crg_scan_folder(string $basePath): array {
    $items = [];
    if (!is_dir($basePath)) return $items;
    $entries = scandir($basePath) ?: [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $full = $basePath . DIRECTORY_SEPARATOR . $e;
        if (is_dir($full)) {
            foreach (glob($full . '/*.pdf') ?: [] as $pdf) {
                $items[] = ['proprio' => $e, 'file' => basename($pdf), 'path' => $pdf];
            }
        } elseif (is_file($full) && strtolower(pathinfo($e, PATHINFO_EXTENSION)) === 'pdf') {
            // PDF à plat : propriétaire inconnu → laissé au parse (meta.proprietaire).
            $items[] = ['proprio' => '', 'file' => $e, 'path' => $full];
        }
    }
    return $items;
}

// ── SCAN : inventaire + statut doublon ────────────────────────────────
if ($action === 'scan') {
    $basePath = trim((string)($_POST['base_path'] ?? ''));
    if ($basePath === '' || !is_dir($basePath)) {
        echo json_encode(['ok' => false, 'error' => 'Dossier introuvable : ' . $basePath]);
        exit;
    }
    $items = crg_scan_folder($basePath);
    $out = [];
    foreach ($items as $it) {
        $hint = crg_trimestre_from_filename($it['file']);
        // Propriétaire : nom de fichier d'abord, dossier en secours.
        $proprioLabel = crg_proprio_from_filename($it['file']);
        if ($proprioLabel === '') $proprioLabel = $it['proprio'];
        $proprioId = 0; $dup = false;
        if ($proprioLabel !== '') {
            $res = crg_resolve_proprietaire($pdo, ['proprietaire' => ['nom' => $proprioLabel]], $agenceId);
            $proprioId = $res['id'];
            if ($proprioId > 0 && $hint['annee'] > 0 && $hint['trimestre'] > 0) {
                $dup = crg_is_duplicate($pdo, $proprioId, $hint['annee'], $hint['trimestre']);
            }
        }
        $out[] = [
            'proprio'      => $proprioLabel,
            'proprio_id'   => $proprioId,
            'proprio_new'  => ($it['proprio'] !== '' && $proprioId === 0),
            'file'         => $it['file'],
            'path'         => $it['path'],
            'annee'        => $hint['annee'],
            'trimestre'    => $hint['trimestre'],
            'doublon'      => $dup,
        ];
    }
    echo json_encode(['ok' => true, 'total' => count($out), 'items' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── PROCESS_ONE : import d'un seul PDF ────────────────────────────────
if ($action === 'process_one') {
    $path        = (string)($_POST['path'] ?? '');
    $proprioName = trim((string)($_POST['proprio'] ?? ''));
    $force       = !empty($_POST['force']);   // ré-importer un doublon
    $isUploaded  = false;
    $origName    = trim((string)($_POST['filename'] ?? ''));

    // MODE PROD : fichier envoyé par le navigateur (upload de dossier).
    if (!empty($_FILES['fichier']['tmp_name']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        if (mime_content_type($_FILES['fichier']['tmp_name']) !== 'application/pdf') {
            echo json_encode(['ok' => false, 'status' => 'erreur', 'error' => 'Fichier non PDF']);
            exit;
        }
        $tmpDir = __DIR__ . '/../uploads/_tmp/';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $path = $tmpDir . 'crgb_' . uniqid('', true) . '.pdf';
        move_uploaded_file($_FILES['fichier']['tmp_name'], $path);
        $isUploaded = true;
        if ($origName === '') $origName = (string)($_FILES['fichier']['name'] ?? '');
        $__tmpUpload = $path;
        register_shutdown_function(static function () use ($__tmpUpload) { @unlink($__tmpUpload); });
    }

    // MODE LOCAL : chemin d'un fichier déjà sur le serveur.
    if (!$isUploaded && ($path === '' || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf')) {
        echo json_encode(['ok' => false, 'status' => 'erreur', 'error' => 'PDF introuvable']);
        exit;
    }
    if ($origName === '') $origName = basename($path);

    try {
        // 1) Parse : Python d'abord (local), fallback GPT-4o (prod).
        $hint = crg_trimestre_from_filename($origName);
        $py = crg_run_python($python, $parser, $path);
        if ($py) {
            $parsed = crg_adapt_python_output($py, $hint);
            $moteur = 'python';
        } else {
            $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
            $ia = crg_parse_pdf($path, (string)$apiKey);
            if (empty($ia['ok'])) {
                echo json_encode(['ok' => false, 'status' => 'erreur', 'file' => $origName, 'error' => 'Parse échoué : ' . ($ia['error'] ?? 'python+IA KO')]);
                exit;
            }
            $parsed = $ia['data'];
            // Compléter période depuis le nom de fichier si l'IA ne l'a pas.
            if (empty($parsed['periode']['annee']) && $hint['annee'])       $parsed['periode']['annee'] = $hint['annee'];
            if (empty($parsed['periode']['trimestre']) && $hint['trimestre']) $parsed['periode']['trimestre'] = $hint['trimestre'];
            $moteur = 'ia';
        }

        // ── PÉRIODE FORCÉE (lot homogène, ex. tous les CRG arrêtés au 30/06/2026 = T2) ──
        // Certains PDF portent une date d'ÉDITION (24/07…) que le parser prend pour la date
        // d'arrêté → trimestre calculé en T3, ce qui masquerait le T2 dans les tableaux de bord.
        // Quand l'opérateur force la période, elle prime sur la date lue.
        $forceAnnee = (int)($_POST['force_annee'] ?? 0);
        $forceTrim  = (int)($_POST['force_trimestre'] ?? 0);
        if ($forceAnnee >= 2000 && $forceTrim >= 1 && $forceTrim <= 4) {
            $endMonth = $forceTrim * 3;                    // 3, 6, 9, 12
            $lastDay  = in_array($endMonth, [6, 9], true) ? 30 : 31;
            $parsed['periode']['annee']       = $forceAnnee;
            $parsed['periode']['trimestre']   = $forceTrim;
            $parsed['periode']['date_arrete'] = sprintf('%04d-%02d-%02d', $forceAnnee, $endMonth, $lastDay);
        }

        // Reconnexion MySQL : le parse IA (curl jusqu'à 120s) peut faire expirer la
        // connexion → « MySQL server has gone away » à l'écriture. On rétablit avant.
        if (function_exists('db_keepalive')) { $pdo = db_keepalive(); $GLOBALS['pdo'] = $pdo; }
        elseif (function_exists('db')) { try { $pdo->query('SELECT 1'); } catch (Throwable) { $pdo = db(true); $GLOBALS['pdo'] = $pdo; } }

        // AGENCE GESTIONNAIRE : détectée dans l'entête du CRG (code postal de l'agence).
        // Prime sur le sélecteur/session → import « tout azimut » de dossiers mixtes.
        $agenceSource = 'formulaire/session';
        if (function_exists('extractPdfText') && function_exists('crg_detect_agence')) {
            $agenceDetectee = crg_detect_agence($pdo, extractPdfText($path));
            if ($agenceDetectee) { $agenceId = $agenceDetectee; $agenceSource = 'CRG (entête)'; }
        }

        // 2) Propriétaire : NOM DE FICHIER d'abord (ex. Monsieur_QU_..._2026_T1),
        //    puis dossier (ex. EMERY IMMO sans nom dans le fichier), puis contenu PDF.
        $proprioEffectif = crg_proprio_from_filename($origName);
        if ($proprioEffectif === '') $proprioEffectif = $proprioName;
        if ($proprioEffectif === '') $proprioEffectif = trim((string)($parsed['proprietaire']['nom'] ?? ''));
        if ($proprioEffectif === '') {
            echo json_encode(['ok' => false, 'status' => 'erreur', 'file' => $origName, 'error' => 'Propriétaire indéterminé (ni fichier, ni dossier, ni PDF)']);
            exit;
        }
        // GARDE ANTI-RÉGIE : ne JAMAIS créer le gestionnaire comme propriétaire
        // (cause du bug « 50 biens sous REGIE EMERY »).
        if (crg_is_gestionnaire_name($proprioEffectif)) {
            echo json_encode(['ok' => false, 'status' => 'erreur', 'file' => $origName,
                'error' => 'Propriétaire = gestionnaire/régie détecté (« ' . $proprioEffectif . ' »). Le nom de fichier doit porter le VRAI propriétaire (ex. « M._et_Mme_CLARY_Bernard_2026_T1_… »).']);
            exit;
        }
        // GARDE ANTI-POUBELLE : refuse un nom issu d'un mauvais parse PDF (libellé de
        // trimestre, date, en-tête, TTAxxx…) → cause du faux propriétaire « - 1er Trimestre 2026 - ».
        if (crg_is_invalid_proprio_name($proprioEffectif)) {
            echo json_encode(['ok' => false, 'status' => 'erreur', 'file' => $origName,
                'error' => 'Nom de propriétaire invalide (« ' . $proprioEffectif . ' ») — le PDF/fichier ne donne pas de propriétaire exploitable. Renomme le fichier « {Propriétaire}_2026_T1_… » ou classe-le à part.']);
            exit;
        }
        // Résolution/création par CLÉ code_compte (robuste, idempotent), sinon nom.
        // Nom = fichier (fiable), code_compte + adresse = parse du PDF.
        $codeCompte  = trim((string)($parsed['proprietaire']['code_compte'] ?? ''));
        $adresseProp = trim((string)($parsed['proprietaire']['adresse'] ?? '')) ?: null;

        // ── CAS PARTICULIER : GROUPE SIR OYONNAX = propriétaire DISTINCT du GROUPE SIR ──
        // Le CRG d'OYONNAX porte le compte 01040000 (= GROUPE SIR principal) : sans règle,
        // la résolution par compte le ferait FUSIONNER dans GROUPE SIR et l'écraserait.
        // On lui force un nom + un compte distincts (réf immeuble 01040247) → fiche séparée.
        if (stripos($origName, 'OYONNAX') !== false && stripos($proprioEffectif, 'SIR') !== false) {
            $proprioEffectif = 'GROUPE SIR OYONNAX';
            $codeCompte      = '01040247';
            $parsed['proprietaire']['nom']         = 'GROUPE SIR OYONNAX';
            $parsed['proprietaire']['code_compte'] = '01040247';
        }

        $proprietaireId = function_exists('crg_resolve_or_create_proprio')
            ? crg_resolve_or_create_proprio($pdo, $proprioEffectif, $codeCompte, $adresseProp, $agenceId)
            : crg_proprio_by_folder($pdo, $proprioEffectif, $agenceId);

        $annee     = (int)($parsed['periode']['annee'] ?? $hint['annee']);
        $trimestre = (int)($parsed['periode']['trimestre'] ?? $hint['trimestre']);

        // 3) DÉTECTION DE DOUBLON.
        if (!$force && crg_is_duplicate($pdo, $proprietaireId, $annee, $trimestre)) {
            echo json_encode(['ok' => true, 'status' => 'doublon', 'file' => $origName,
                'proprio' => $proprioName, 'annee' => $annee, 'trimestre' => $trimestre,
                'message' => "Déjà importé (T$trimestre $annee) — ignoré"]);
            exit;
        }

        // 4) Copie du PDF en emplacement définitif (pour GED).
        $pdfAbs = null; $pdfUrl = null;
        if ($annee > 0 && $trimestre > 0) {
            $destDir = __DIR__ . '/../uploads/crg/' . $proprietaireId;
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            $pdfAbs = $destDir . '/' . $annee . '_T' . $trimestre . '.pdf';
            @copy($path, $pdfAbs);
            $pdfUrl = '/uploads/crg/' . $proprietaireId . '/' . $annee . '_T' . $trimestre . '.pdf';
        }

        // 5) Application via le cœur partagé (bien_baux + GED).
        $res = crg_apply_parsed($pdo, $parsed, $proprietaireId, [
            'societeId'    => $societeId ?: null,
            'agenceId'     => $agenceId ?: null,
            'userId'       => $userId ?: null,
            'pdfAbsPath'   => $pdfAbs,
            'pdfPublicUrl' => $pdfUrl,
        ]);

        echo json_encode([
            'ok'      => !empty($res['ok']),
            'status'  => !empty($res['ok']) ? 'ok' : 'erreur',
            'file'    => basename($path),
            'proprio' => $proprioName,
            'moteur'  => $moteur,
            'agence'  => $agenceId ?: null,
            'agence_source' => $agenceSource,
            'stats'   => $res['stats'] ?? [],
            'error'   => $res['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        error_log('[import_crg_batch] ' . $e->getMessage() . ' @ ' . $e->getLine());
        echo json_encode(['ok' => false, 'status' => 'erreur', 'file' => $origName, 'error' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
