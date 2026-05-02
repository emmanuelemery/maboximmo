<?php
declare(strict_types=1);

/**
 * BIENS — RETRY DES ANALYSES PHOTOS EN ÉCHEC OU EN ATTENTE
 * =========================================================
 *
 * Reprend les photos avec analyse_statut = 'pending' ou 'error'
 * et lance l'analyse IA Vision (commercial + critique de prise de vue).
 *
 * UTILISATION
 * -----------
 *   # Cron Linux (toutes les heures, max 30 photos / run)
 *   0 * * * * php /home/u630423897/domains/maboximmo.fr/public_html/scripts/bien_photo_analyse_retry.php --max=30
 *
 *   # Test à la main
 *   php bien_photo_analyse_retry.php --max=5 --verbose
 *
 *   # Cible un seul bien (debug)
 *   php bien_photo_analyse_retry.php --bien=123
 *
 * OPTIONS CLI
 * -----------
 *   --max=N        Nombre max de photos par exécution (défaut 20, max 100)
 *   --bien=ID      Limite à un bien (debug)
 *   --include-ok   Re-analyse aussi les photos déjà 'ok' (FORCE — coûte de l'IA)
 *   --verbose      Affiche le détail par photo
 *   --dry-run      N'appelle pas l'IA, montre juste les photos qui seraient traitées
 *
 * SÉCURITÉ
 * --------
 * CLI uniquement. Refuse l'accès HTTP : ce script consomme des appels IA payants
 * et ne doit pas être exposé sur le web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Accès interdit : script CLI uniquement.\n");
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bien_photo_analyser.php';

$opts       = getopt('', ['max::', 'bien::', 'include-ok', 'verbose', 'dry-run']);
$maxBatch   = isset($opts['max']) && ctype_digit((string)$opts['max']) ? max(1, min(100, (int)$opts['max'])) : 20;
$idBien     = isset($opts['bien']) && ctype_digit((string)$opts['bien']) ? (int)$opts['bien'] : 0;
$includeOk  = array_key_exists('include-ok', $opts);
$verbose    = array_key_exists('verbose', $opts);
$dryRun     = array_key_exists('dry-run', $opts);

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Erreur : connexion BDD indisponible (bootstrap KO).\n");
    exit(1);
}

// Vérifie que la migration critique a été appliquée
try {
    $pdo->query("SELECT analyse_statut FROM biens_photos LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "Erreur : colonne analyse_statut absente. Applique migration_biens_photos_critique.sql d'abord.\n");
    exit(2);
}

// ── Sélection des photos à retraiter ────────────────────────────
$where  = $includeOk ? "1=1" : "(bp.analyse_statut IS NULL OR bp.analyse_statut <> 'ok')";
$params = [];
if ($idBien > 0) {
    $where .= " AND bp.id_bien = ?";
    $params[] = $idBien;
}

$st = $pdo->prepare("
    SELECT bp.id, bp.id_bien, bp.url_photo, bp.analyse_statut, bp.analyse_erreur
    FROM biens_photos bp
    WHERE {$where}
    ORDER BY bp.analyse_statut = 'error' DESC, bp.id ASC
    LIMIT {$maxBatch}
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "✓ Aucune photo à retraiter.\n";
    exit(0);
}

echo "→ " . count($rows) . " photo(s) à analyser (max={$maxBatch}, include-ok=" . ($includeOk?'oui':'non') . ").\n";
if ($dryRun) {
    foreach ($rows as $r) {
        echo "  [dry-run] photo #{$r['id']} (bien #{$r['id_bien']}) statut=" . ($r['analyse_statut'] ?: 'pending') . "\n";
    }
    exit(0);
}

$ok = 0; $ko = 0;
foreach ($rows as $r) {
    $photoId = (int)$r['id'];
    $rel     = (string)$r['url_photo'];
    $abs     = dirname(__DIR__) . '/' . ltrim($rel, '/');

    if (!is_file($abs)) {
        $ko++;
        $pdo->prepare("UPDATE biens_photos SET analyse_statut='error', analyse_erreur=? WHERE id=?")
            ->execute(['Fichier introuvable sur disque : ' . $rel, $photoId]);
        if ($verbose) echo "  ✗ #{$photoId} : fichier absent\n";
        continue;
    }

    $res = analyserPhotoBien($abs);
    if (!$res['ok']) {
        $ko++;
        $err = substr((string)($res['error'] ?? 'inconnu'), 0, 500);
        $pdo->prepare("UPDATE biens_photos SET analyse_statut='error', analyse_erreur=? WHERE id=?")
            ->execute([$err, $photoId]);
        if ($verbose) echo "  ✗ #{$photoId} : {$err}\n";
        continue;
    }

    $cat  = $res['commercial']['categorie']   ?? null;
    $desc = $res['commercial']['description'] ?? null;
    $cri  = $res['critique'] ?? null;

    try {
        $pdo->prepare("
            UPDATE biens_photos
            SET categorie = ?, description_ia = ?, description_ia_date = NOW(),
                critique_niveau = ?, critique_points_forts = ?, critique_points_faibles = ?,
                critique_conseil = ?, critique_ia_date = NOW(),
                analyse_statut = 'ok', analyse_erreur = NULL
            WHERE id = ?
        ")->execute([
            $cat,
            $desc,
            !empty($cri['niveau']) ? $cri['niveau'] : null,
            !empty($cri['points_forts'])   ? json_encode($cri['points_forts'],   JSON_UNESCAPED_UNICODE) : null,
            !empty($cri['points_faibles']) ? json_encode($cri['points_faibles'], JSON_UNESCAPED_UNICODE) : null,
            !empty($cri['conseil']) ? $cri['conseil'] : null,
            $photoId,
        ]);
        $ok++;
        if ($verbose) {
            echo "  ✓ #{$photoId} : [{$cat}] niveau=" . ($cri['niveau'] ?? '?') . "\n";
        }
    } catch (Throwable $e) {
        $ko++;
        if ($verbose) echo "  ✗ #{$photoId} BDD : " . $e->getMessage() . "\n";
    }
}

echo "\nTerminé : {$ok} OK, {$ko} en erreur.\n";
exit($ko > 0 ? 1 : 0);
