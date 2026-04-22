<?php
declare(strict_types=1);
/**
 * investisseur/_seed_parc.php — Amorçage en masse
 *
 * Itère sur tous les biens de la société de l'utilisateur connecté
 * (ou d'une société donnée en CLI) et crée une analyse pré-remplie
 * pour chacun d'eux — à partir des données biens + arbitrage + CRG + bail.
 *
 * Modes :
 *   - CLI  : php _seed_parc.php [id_societe] [--dry-run] [--force] [--limit=N]
 *            dry-run = affiche ce qu'il ferait sans écrire
 *            force   = recrée une analyse même si une existe déjà pour ce bien
 *            limit   = ne traite que les N premiers biens
 *
 *   - Web  : ?run=1&id_societe=1 (session admin requise)
 *            ?dry_run=1 pour prévisualiser
 *
 * Protection : jamais ré-amorce 2 fois pour le même bien sans --force
 * (deduplication sur investisseur_analyses.id_bien_source).
 */

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $argv = $_SERVER['argv'] ?? [];
    $opt = ['id_societe' => null, 'dry_run' => false, 'force' => false, 'limit' => null];
    foreach ($argv as $i => $a) {
        if ($i === 0) continue;
        if ($a === '--dry-run') $opt['dry_run'] = true;
        elseif ($a === '--force') $opt['force'] = true;
        elseif (strpos($a, '--limit=') === 0) $opt['limit'] = (int)substr($a, 8);
        elseif (is_numeric($a)) $opt['id_societe'] = (int)$a;
    }
} else {
    require_once __DIR__ . '/../inc/bootstrap.php';
    require_once __DIR__ . '/../inc/auth.php';
    require_login();
    $opt = [
        'id_societe' => (int)($_GET['id_societe'] ?? ($_SESSION['id_societe'] ?? 0)),
        'dry_run'    => !empty($_GET['dry_run']),
        'force'      => !empty($_GET['force']),
        'limit'      => !empty($_GET['limit']) ? (int)$_GET['limit'] : null,
    ];
    // Admin only côté web
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if (!in_array($roleId, [1, 7], true) && empty($_SESSION['super_admin'])) {
        http_response_code(403);
        exit('Accès réservé admin.');
    }
}

if ($isCli) {
    require_once __DIR__ . '/../config/db.php';
    session_start();
}
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';
require_once __DIR__ . '/../inc/investisseur_interpretations.php';
require_once __DIR__ . '/../inc/investisseur_prefill.php';

$pdo = isset($GLOBALS['pdo']) ? $GLOBALS['pdo'] : db();
$GLOBALS['pdo'] = $pdo;

$idSociete = (int)($opt['id_societe'] ?? 0);
if (!$idSociete) {
    if ($isCli) {
        fwrite(STDERR, "Usage: php _seed_parc.php <id_societe> [--dry-run] [--force] [--limit=N]\n");
        exit(1);
    } else {
        exit('id_societe requis.');
    }
}

// Pour le save, on force les sessions sur la société cible
$_SESSION['id_societe'] = $idSociete;
if (empty($_SESSION['id_user']) && empty($_SESSION['id'])) $_SESSION['id'] = 1;

// 1) Liste des biens de la société (qui ont un minimum d'infos)
$sql = "SELECT b.id, b.designation, b.ville
          FROM biens b
         WHERE b.id_societe = :s
           AND (b.surface_habitable > 0 OR b.prix_vente_estime > 0 OR b.loyer_hc > 0)
         ORDER BY b.ville, b.id";
$st = $pdo->prepare($sql);
$st->bindValue(':s', $idSociete, PDO::PARAM_INT);
$st->execute();
$biens = $st->fetchAll(PDO::FETCH_ASSOC);

if ($opt['limit']) $biens = array_slice($biens, 0, $opt['limit']);

// 2) Analyses déjà présentes
$exist = [];
$st = $pdo->prepare("SELECT id, id_bien_source FROM investisseur_analyses WHERE id_societe = :s AND id_bien_source IS NOT NULL");
$st->bindValue(':s', $idSociete, PDO::PARAM_INT);
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $exist[(int)$r['id_bien_source']] = (int)$r['id'];

$nbCreated = 0; $nbSkipped = 0; $nbError = 0; $nbUpdated = 0;
$report = [];

foreach ($biens as $b) {
    $idBien = (int)$b['id'];
    $label  = '#' . $idBien . ' ' . ($b['designation'] ?: '(sans désignation)') . ' — ' . ($b['ville'] ?: '?');

    if (isset($exist[$idBien]) && !$opt['force']) {
        $nbSkipped++;
        $report[] = ['status' => 'skip', 'label' => $label, 'analyse_id' => $exist[$idBien]];
        continue;
    }

    try {
        $data = inv_prefill_from_bien($pdo, $idBien);
        if (empty($data['loyer_estime']) && empty($data['prix_achat'])) {
            $nbSkipped++;
            $report[] = ['status' => 'skip-empty', 'label' => $label];
            continue;
        }

        if ($opt['dry_run']) {
            $report[] = ['status' => 'would-create', 'label' => $label,
                         'prix' => (float)($data['prix_achat'] ?? 0),
                         'loyer' => (float)($data['loyer_estime'] ?? 0)];
            $nbCreated++;
            continue;
        }

        $existingId = $exist[$idBien] ?? null;
        $id = inv_save($pdo, $data, $existingId);
        if ($existingId) {
            $nbUpdated++;
            $report[] = ['status' => 'updated', 'label' => $label, 'analyse_id' => $id];
        } else {
            $nbCreated++;
            $report[] = ['status' => 'created', 'label' => $label, 'analyse_id' => $id];
        }
    } catch (Throwable $e) {
        $nbError++;
        $report[] = ['status' => 'error', 'label' => $label, 'error' => $e->getMessage()];
    }
}

$summary = [
    'societe'    => $idSociete,
    'biens_candidats' => count($biens),
    'created'    => $nbCreated,
    'updated'    => $nbUpdated,
    'skipped'    => $nbSkipped,
    'errors'     => $nbError,
    'dry_run'    => $opt['dry_run'],
];

if ($isCli) {
    echo "═══ Seed parc investisseur société #{$idSociete} ═══\n";
    echo "Biens candidats : " . count($biens) . "\n";
    foreach ($report as $r) {
        $ic = match ($r['status']) {
            'created'      => '✓ CREATE',
            'updated'      => '⟳ UPDATE',
            'would-create' => '~ DRY   ',
            'skip'         => '- SKIP  ',
            'skip-empty'   => '- EMPTY ',
            'error'        => '✗ ERROR ',
            default        => '? ' . $r['status'],
        };
        $line = $ic . ' ' . $r['label'];
        if (!empty($r['analyse_id'])) $line .= ' → analyse #' . $r['analyse_id'];
        if (!empty($r['error']))      $line .= ' : ' . $r['error'];
        if (isset($r['prix']))        $line .= ' (prix ' . number_format($r['prix'], 0, ',', ' ') . ' € / loyer ' . number_format($r['loyer'], 0, ',', ' ') . ' €)';
        echo $line . "\n";
    }
    echo "\n";
    echo "Créées : {$nbCreated} | MAJ : {$nbUpdated} | Skip : {$nbSkipped} | Erreurs : {$nbError}\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['summary' => $summary, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
