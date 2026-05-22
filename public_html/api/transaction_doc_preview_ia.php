<?php
// api/transaction_doc_preview_ia.php — Preview IA d'un doc Transaction (sans stockage final)
// POST FormData : fichier (upload), modele (optionnel)
// → analyse IA Vision Claude → JSON enrichi
declare(strict_types=1);

// ── Hardening : on garantit du JSON même en cas de fatal error ──
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@set_time_limit(180);
ob_start();
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo json_encode(['ok'=>false,'error'=>'Fatal PHP: ' . ($err['message'] ?? '') . ' (' . basename((string)($err['file'] ?? '')) . ':' . (int)($err['line'] ?? 0) . ')'], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_doc_extract_ia.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_once __DIR__ . '/../inc/transaction_doc_match.php';
require_login();

// Vider tout output parasite émis par le bootstrap avant d'écrire le JSON
while (ob_get_level() > 1) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$modele = trim((string)(post('modele') ?? ''));
if ($modele === '') $modele = null;

$stagingId = (int)(post('staging_id') ?? 0);
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$pathToAnalyse = null;
$cleanupAfter  = false;

try {
    if ($stagingId > 0) {
        // Mode staging : analyse le fichier déjà uploadé
        $row = tr_staging_check_owner($pdo, $stagingId, $userId);
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'staging introuvable']); exit; }
        $pathToAnalyse = __DIR__ . '/../' . $row['stored_path'];
        if (!is_file($pathToAnalyse)) {
            echo json_encode(['ok'=>false,'error'=>'fichier staging absent du disque']); exit;
        }
    } elseif (isset($_FILES['fichier']) && ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        // Mode legacy : upload direct (rétrocompat)
        $tmpDir = sys_get_temp_dir();
        $orig   = (string)$_FILES['fichier']['name'];
        $ext    = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $pathToAnalyse = $tmpDir . '/mbi_tr_preview_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $pathToAnalyse)) {
            echo json_encode(['ok'=>false,'error'=>'move_uploaded_file failed']); exit;
        }
        $cleanupAfter = true;
    } else {
        echo json_encode(['ok'=>false,'error'=>'staging_id ou fichier requis']); exit;
    }

    $res = transaction_doc_extract_ia($pathToAnalyse, $modele);
    if ($cleanupAfter) @unlink($pathToAnalyse);

    // L'appel IA peut durer 60-90s → la connexion MySQL a très probablement
    // été coupée par wait_timeout. On force un reconnect frais avant tout SQL.
    $pdo = db_reconnect_fresh();

    if (!$res['ok']) {
        echo json_encode(['ok'=>false, 'error'=>$res['erreur'] ?: 'analyse_echec', 'modele'=>$res['modele']]); exit;
    }

    // Match bien automatique par adresse extraite — via fonction partagée
    $matchBien = null;
    $creationNeeded = false;
    $data = $res['data'] ?? [];
    $adresse = trim((string)($data['adresse_bien'] ?? ''));
    $ville   = trim((string)($data['ville'] ?? ''));
    $cp      = trim((string)($data['code_postal'] ?? ''));

    if ($adresse !== '' || $ville !== '' || $cp !== '') {
        $roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
        $isManager = ($roleId === 1 || $roleId === 2);
        $idSoc  = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
        $matchResult = transaction_doc_match_bien($pdo, $data, $isManager, $idSoc);
        $matchBien = $matchResult['match_biens'];
        $creationNeeded = $matchResult['creation_needed'];
    }
    // Le code legacy ci-dessous est conservé pour rétrocompatibilité (non utilisé)
    if (false && ($adresse !== '' || $ville !== '' || $cp !== '')) {
        $roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
        $isManager = ($roleId === 1 || $roleId === 2);
        $idSoc  = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

        // Stop-words à ignorer pour le matching (mots trop courants en adresse FR)
        $stop = ['rue','avenue','boulevard','blv','blvd','bld','bd','place','chemin','allee','allée','impasse','route','voie','quai','cours','passage','square','parvis','lieu','dit','dite','dits','de','du','des','la','le','les','et','aux','en','sur','sous'];

        // Extrait n° de rue depuis "15 boulevard Yves Farge"
        $numRue = '';
        if (preg_match('/^\s*(\d+)\b/', $adresse, $m)) $numRue = $m[1];

        // Tokens "signifiants" de l'adresse (nom de rue, etc.)
        $addrTokens = [];
        foreach (preg_split('~[\s,;\-_/]+~', mb_strtolower($adresse)) as $t) {
            $t = trim((string)$t);
            if (mb_strlen($t) < 3 || is_numeric($t)) continue;
            if (in_array($t, $stop, true)) continue;
            $addrTokens[] = $t;
        }
        $addrTokens = array_values(array_unique($addrTokens));

        // Pool de candidats : on récupère TOUS les biens scopés sur la ville/CP, puis on score.
        $whereParts = ['(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))'];
        $bind = [];
        if ($cp !== '') { $whereParts[] = 'b.code_postal = ?'; $bind[] = $cp; }
        elseif ($ville !== '') { $whereParts[] = 'LOWER(b.ville) LIKE ?'; $bind[] = '%' . mb_strtolower($ville) . '%'; }
        if (!$isManager && $idSoc !== null) {
            $whereParts[] = '(b.id_societe = ? OR b.id_societe IS NULL)';
            $bind[]       = $idSoc;
        }
        $sqlMatch = 'SELECT b.id, b.reference_bien, b.adresse_1, b.ville, b.code_postal, b.designation,
                            COALESCE(p.societe, CONCAT_WS(" ", p.prenom, p.nom)) AS proprio_nom
                     FROM biens b
                     LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                     WHERE ' . implode(' AND ', $whereParts) . '
                     LIMIT 200';
        $stmt = $pdo->prepare($sqlMatch);
        $stmt->execute($bind);
        $pool = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Scoring côté PHP — l'adresse_1 prime largement sur la ville
        $scored = [];
        foreach ($pool as $b) {
            $rawScore = 0;
            $addrHits = 0;
            $addrLow = mb_strtolower((string)($b['adresse_1'] ?? ''));
            $vilLow  = mb_strtolower((string)($b['ville'] ?? ''));
            $cpLow   = (string)($b['code_postal'] ?? '');
            $desLow  = mb_strtolower((string)($b['designation'] ?? ''));
            $refLow  = mb_strtolower((string)($b['reference_bien'] ?? ''));

            // Match adresse_1 = poids fort (50 par token signifiant trouvé)
            foreach ($addrTokens as $t) {
                if ($addrLow !== '' && str_contains($addrLow, $t)) { $rawScore += 50; $addrHits++; }
                elseif (str_contains($desLow, $t))                  $rawScore += 8;
                elseif (str_contains($refLow, $t))                  $rawScore += 12;
            }
            // Numéro de rue
            if ($numRue !== '' && $addrLow !== '' && preg_match('/(^|\D)' . preg_quote($numRue, '/') . '(\D|$)/', $addrLow)) {
                $rawScore += 30;
            }
            // CP / ville : juste pour départager si plusieurs candidats matchent l'adresse
            if ($cp !== '' && $cpLow === $cp) $rawScore += 12;
            if ($ville !== '' && $vilLow !== '' && str_contains($vilLow, mb_strtolower($ville))) $rawScore += 6;

            if ($rawScore <= 0) continue;
            $b['score'] = $rawScore;
            $b['addr_hits'] = $addrHits;
            $scored[] = $b;
        }

        // Tri : addr_hits desc d'abord (priorité absolue), puis score
        usort($scored, function ($x, $y) {
            if ($x['addr_hits'] !== $y['addr_hits']) return $y['addr_hits'] <=> $x['addr_hits'];
            return $y['score'] <=> $x['score'];
        });

        // Normalisation de l'affichage : on convertit le score brut en % (0-100)
        $maxScore = !empty($scored) ? max(50, $scored[0]['score']) : 100;
        foreach ($scored as &$s) {
            $s['score'] = min(100, (int)round(($s['score'] / $maxScore) * 100));
        }
        unset($s);

        $top = array_slice($scored, 0, 5);

        // Détection "à créer" : aucun match d'adresse réel → on signalera côté UI
        $bestAddrHits = !empty($top) ? (int)($top[0]['addr_hits'] ?? 0) : 0;
        $matchBien = !empty($top) ? $top : null;
        $creationNeeded = ($bestAddrHits === 0);
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode([
        'ok'              => true,
        'data'            => $data,
        'modele'          => $res['modele'],
        'cout_centimes'   => $res['cout_centimes'],
        'confidence'      => $res['confidence'],
        'match_biens'     => $matchBien,
        'creation_needed' => ($creationNeeded ?? false),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[transaction_doc_preview_ia] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
