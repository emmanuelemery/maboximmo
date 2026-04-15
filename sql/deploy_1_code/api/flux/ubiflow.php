<?php
declare(strict_types=1);

/**
 * FLUX XML UBIFLOW — export des annonces immobilières (multi-agences)
 * ====================================================================
 *
 * Génère un ou plusieurs fichiers XML conformes au format d'intégration
 * Ubiflow (LeBonCoin, SeLoger, Bien'ici…). Architecture multi-agences :
 * chaque agence a son propre fichier `[login_ftp].xml` dans un
 * sous-répertoire `export/{slug}/`.
 *
 * UTILISATION — CLI
 * -----------------
 *   # Génère le flux d'une seule agence (stdout)
 *   php ubiflow.php --agence=chaponost
 *
 *   # Génère et écrit sur disque
 *   php ubiflow.php --agence=chaponost --save
 *
 *   # Génère, écrit, zippe et dépose sur FTP Ubiflow
 *   php ubiflow.php --agence=chaponost --save --deploy
 *
 *   # Génère les 5 flux d'un coup (toutes les agences actives)
 *   php ubiflow.php --all --save
 *
 *   # Génère et déploie les 5 flux (usage cron)
 *   php ubiflow.php --all --save --deploy
 *
 * UTILISATION — HTTP
 * ------------------
 *   https://site/api/flux/ubiflow.php?agence=chaponost&token=XXX
 *   https://site/api/flux/ubiflow.php?agence=chaponost&save=1&token=XXX
 *
 *   Token :
 *     - Par-agence : UBIFLOW_ACCESS_TOKEN_CHAPONOST (recommandé)
 *     - Global    : UBIFLOW_ACCESS_TOKEN           (legacy, fallback)
 *   Si aucune constante n'est définie, l'accès est libre (dev uniquement).
 *
 * CONFORMITÉ UBIFLOW
 * ------------------
 *   - Encodage UTF-8 minuscule dans l'en-tête XML
 *   - Racine <client>, une balise <annonce> par bien
 *   - CDATA sur tous les champs texte libres
 *   - Booléens O/N (jamais true/false/1/0)
 *   - Dates jj/mm/aaaa
 *   - Mode Annule/Remplace : TOUTES les annonces publiables sont dans le flux
 *   - Statuts exportés : publiee / active / en_ligne (JAMAIS brouillon)
 *
 * @see config/ubiflow_mapping.php   — mapping SQL → balises Ubiflow
 * @see config/ubiflow_agences.php   — registre des agences + logins FTP
 * @see api/flux/ubiflow_ftp.php     — packaging ZIP + dépôt FTP
 * @see sql/migration_ubiflow_conformite.sql
 */

// ---------------------------------------------------------------------
// 0. Bootstrap + options CLI / HTTP
// ---------------------------------------------------------------------
$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/ubiflow_mapping.php';
require_once __DIR__ . '/../../config/ubiflow_agences.php';

$saveToFile = false;
$deployFtp  = false;
$allMode    = false;
$slugAsked  = null;

$triggeredBy = $isCli ? 'cli' : 'manual';

if ($isCli) {
    $opts = getopt('', ['save', 'deploy', 'all', 'agence::', 'triggered-by::', 'force']);
    $saveToFile = array_key_exists('save', $opts);
    $deployFtp  = array_key_exists('deploy', $opts);
    $allMode    = array_key_exists('all', $opts);
    if (!empty($opts['agence'])) {
        $slugAsked = strtolower(trim((string)$opts['agence']));
    }
    if (!empty($opts['triggered-by']) && in_array($opts['triggered-by'], ['cron','manual','cli'], true)) {
        $triggeredBy = $opts['triggered-by'];
    }
    $forceFlag = array_key_exists('force', $opts);
} else {
    // HTTP — une seule agence à la fois (pas de --all pour éviter les timeouts)
    $saveToFile = !empty($_GET['save']);
    $deployFtp  = !empty($_GET['deploy']);
    if (!empty($_GET['agence'])) {
        $slugAsked = strtolower(trim((string)$_GET['agence']));
    }
    $forceFlag = !empty($_GET['force']);
}

// ---------------------------------------------------------------------
// 1. Sélection de la liste des agences à traiter
// ---------------------------------------------------------------------
$agencesToProcess = [];

if ($allMode) {
    // Mode batch : toutes les agences actives (actif=true ET id_agence non null)
    $agencesToProcess = ubiflow_agences_actives();
    if (empty($agencesToProcess)) {
        ubiflow_cli_fail('Aucune agence active trouvée dans config/ubiflow_agences.php', $isCli);
    }
} elseif ($slugAsked !== null) {
    // Mode single : une agence ciblée
    $cfg = ubiflow_agence_get($slugAsked);
    if ($cfg === null) {
        ubiflow_cli_fail("Agence inconnue : '{$slugAsked}'. Slugs disponibles : "
            . implode(', ', array_keys(ubiflow_agences_all())), $isCli);
    }
    if (empty($cfg['id_agence'])) {
        ubiflow_cli_fail("Agence '{$slugAsked}' a un id_agence null — créer l'agence en DB avant.", $isCli);
    }
    $agencesToProcess = [$slugAsked => $cfg];
} else {
    // Legacy : pas de filtre → TOUTES les annonces, toutes agences (mode siège)
    // Conservé pour compatibilité avec l'ancien usage mono-flux.
    $agencesToProcess = ['_siege' => [
        'id_agence'   => null,
        'login_ftp'   => 'ubiflow',
        'nom'         => 'SIEGE (legacy mono-flux)',
        'code_postal' => null,
        'actif'       => true,
    ]];
}

// ---------------------------------------------------------------------
// 2. Contrôle d'accès HTTP par token (par-agence ou global)
// ---------------------------------------------------------------------
if (!$isCli) {
    $slug = array_key_first($agencesToProcess);
    $tokenExpected = null;
    $tokenConst = 'UBIFLOW_ACCESS_TOKEN_' . strtoupper($slug);
    if (defined($tokenConst)) {
        $tokenExpected = constant($tokenConst);
    } elseif (defined('UBIFLOW_ACCESS_TOKEN') && UBIFLOW_ACCESS_TOKEN !== '') {
        $tokenExpected = UBIFLOW_ACCESS_TOKEN;
    }
    if ($tokenExpected !== null && $tokenExpected !== '') {
        // Header Authorization: Bearer XXX (recommandé) OU ?token=XXX (legacy)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $tokenFromHeader = preg_match('/Bearer\s+(.+)$/i', $authHeader, $m) ? trim($m[1]) : '';
        $tokenFromQuery = (string)($_GET['token'] ?? '');
        $tokenProvided = $tokenFromHeader !== '' ? $tokenFromHeader : $tokenFromQuery;
        if (!hash_equals((string)$tokenExpected, $tokenProvided)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden — token manquant ou invalide";
            exit;
        }
    }
}

// ---------------------------------------------------------------------
// 3. Connexion BDD
// ---------------------------------------------------------------------
try {
    $pdo = db();
} catch (Throwable $e) {
    ubiflow_cli_fail('Erreur de connexion à la base : ' . $e->getMessage(), $isCli);
}

// ---------------------------------------------------------------------
// 4. Boucle de génération par agence
// ---------------------------------------------------------------------
$report = [];   // récapitulatif pour la sortie JSON / CLI

foreach ($agencesToProcess as $slug => $cfg) {
    $idAgence  = $cfg['id_agence'] ?? null;
    $loginFtp  = $cfg['login_ftp'] ?? $slug;
    $nomAgence = $cfg['nom']       ?? strtoupper($slug);

    // 4.1 Requête SQL avec filtre par agence
    $sql = ubiflow_sql_select_annonces($idAgence);
    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $report[$slug] = [
            'ok'    => false,
            'error' => 'SQL : ' . $e->getMessage(),
        ];
        continue;
    }

    // 4.2 Construction du document XML
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput       = true;
    $dom->preserveWhiteSpace = false;

    $clientNode = $dom->createElement('client');
    $dom->appendChild($clientNode);

    /** Ajoute un enfant XML avec CDATA si nécessaire. Null/'' ignoré. */
    $appendValue = static function (DOMDocument $dom, DOMElement $parent, string $name, $value): void {
        if ($value === null || $value === '') return;
        $s = (string) $value;
        $child = $dom->createElement($name);
        if (preg_match('/[<>&\r\n"\']/', $s)) {
            $child->appendChild($dom->createCDATASection($s));
        } else {
            $child->appendChild($dom->createTextNode($s));
        }
        $parent->appendChild($child);
    };

    $appendGroup = static function (DOMDocument $dom, DOMElement $parent, string $groupName, array $data) use ($appendValue): ?DOMElement {
        if (empty($data)) return null;
        $group = $dom->createElement($groupName);
        foreach ($data as $key => $value) {
            $appendValue($dom, $group, (string) $key, $value);
        }
        $parent->appendChild($group);
        return $group;
    };

    $count   = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $idAnnonce = (int) ($row['a_id'] ?? 0);
        if ($idAnnonce <= 0) continue;

        $photos = ubiflow_get_photos($pdo, $idAnnonce);
        $data   = build_ubiflow_annonce($row, $photos);

        // CORRECTION #2 : build_ubiflow_annonce() peut retourner _skipped=true
        // pour ignorer un bien dont le type n'est pas mappé.
        if (!empty($data['_skipped'])) { $skipped++; continue; }

        $annonceNode = $dom->createElement('annonce');

        foreach ($data['annonce'] as $key => $value) {
            $appendValue($dom, $annonceNode, (string) $key, $value);
        }

        if (!empty($data['photos'])) {
            $photosNode = $dom->createElement('photos');
            foreach ($data['photos'] as $url) {
                $appendValue($dom, $photosNode, 'photo', $url);
            }
            $annonceNode->appendChild($photosNode);
        }

        $bienNode = $appendGroup($dom, $annonceNode, 'bien', $data['bien']);
        if ($bienNode !== null && !empty($data['diagnostiques'])) {
            $appendGroup($dom, $bienNode, 'diagnostiques', $data['diagnostiques']);
        }

        $appendGroup($dom, $annonceNode, 'prestation', $data['prestation']);

        $clientNode->appendChild($annonceNode);
        $count++;
    }

    $xml = $dom->saveXML();
    // Ubiflow exige encoding en minuscules
    $xml = preg_replace(
        '/^<\?xml version="1\.0" encoding="UTF-8"\?>/',
        '<?xml version="1.0" encoding="utf-8"?>',
        $xml,
        1
    );

    // 4.3 Sortie : fichier disque + (option) déploiement FTP
    $entry = [
        'ok'        => true,
        'agence'    => $slug,
        'nom'       => $nomAgence,
        'id_agence' => $idAgence,
        'login_ftp' => $loginFtp,
        'count'     => $count,
        'skipped'   => $skipped,
    ];

    if ($saveToFile) {
        $exportDir = __DIR__ . '/export/' . $slug;
        if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
            $entry['ok']    = false;
            $entry['error'] = 'mkdir failed: ' . $exportDir;
            $report[$slug]  = $entry;
            continue;
        }
        $outFile = $exportDir . '/' . $loginFtp . '.xml';
        $written = @file_put_contents($outFile, $xml);
        if ($written === false) {
            $entry['ok']    = false;
            $entry['error'] = 'write failed: ' . $outFile;
        } else {
            $entry['file']  = $outFile;
            $entry['bytes'] = $written;

            // Déploiement FTP optionnel
            if ($deployFtp && $entry['ok']) {
                require_once __DIR__ . '/ubiflow_ftp.php';
                try {
                    $deploy = ubiflow_deploy($slug, $outFile, [
                        'triggered_by'   => $triggeredBy,
                        'triggered_user' => $_SESSION['user_id'] ?? null,
                        'force'          => $forceFlag ?? false,
                    ]);
                    $entry['deploy'] = $deploy;
                    // skipped_duplicate = cas légitime, pas une erreur
                    if (empty($deploy['ok']) && ($deploy['status'] ?? '') !== 'skipped_duplicate') {
                        $entry['ok'] = false;
                        $entry['error'] = 'FTP : ' . ($deploy['error'] ?? 'échec');
                    }
                } catch (Throwable $e) {
                    $entry['ok']    = false;
                    $entry['error'] = 'FTP exception : ' . $e->getMessage();
                }
            }
        }
    }

    // En mode single HTTP sans --save, on renvoie directement le XML brut
    if (!$isCli && !$saveToFile && count($agencesToProcess) === 1) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $loginFtp . '.xml"');
        echo $xml;
        exit;
    }

    // En mode single CLI sans --save, on dump le XML sur stdout
    if ($isCli && !$saveToFile && count($agencesToProcess) === 1) {
        echo $xml;
        exit;
    }

    $report[$slug] = $entry;
}

// ---------------------------------------------------------------------
// 5. Rapport (CLI texte ou JSON HTTP)
// ---------------------------------------------------------------------
if ($isCli) {
    echo "\n=== RAPPORT UBIFLOW " . date('Y-m-d H:i:s') . " ===\n";
    foreach ($report as $slug => $r) {
        $status = ($r['ok'] ?? false) ? 'OK' : 'ECHEC';
        printf(
            "  [%s]  %-14s  annonces=%-4d  skipped=%-2d  %s\n",
            $status,
            $slug,
            (int)($r['count'] ?? 0),
            (int)($r['skipped'] ?? 0),
            !empty($r['file']) ? basename(dirname($r['file'])) . '/' . basename($r['file']) : ''
        );
        if (!empty($r['error']))  echo "     → error: " . $r['error'] . "\n";
        if (!empty($r['deploy'])) {
            $d = $r['deploy'];
            echo "     → deploy: zip=" . ($d['zip'] ?? '?') . " photos=" . ($d['photos_count'] ?? 0) . "\n";
        }
    }
    $nbOk = count(array_filter($report, fn($r) => $r['ok'] ?? false));
    echo "\nTotal : {$nbOk}/" . count($report) . " agence(s) exportée(s) avec succès\n";
    exit($nbOk === count($report) ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'        => !in_array(false, array_column($report, 'ok'), true),
    'timestamp' => date('c'),
    'results'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function ubiflow_cli_fail(string $msg, bool $isCli): void {
    if ($isCli) {
        fwrite(STDERR, "[ubiflow] ERREUR : {$msg}\n");
        exit(2);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erreur : {$msg}";
    exit;
}
