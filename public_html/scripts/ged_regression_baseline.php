<?php
/**
 * scripts/ged_regression_baseline.php — Harnais anti-régression du chantier GED.
 * LECTURE SEULE. Capture une empreinte des données CRITIQUES qui ne doivent JAMAIS
 * bouger pendant le chantier GED : flux Ubiflow (annonces.prix/loyer/prix_net_vendeur
 * des annonces diffusables), module Annonce, module RH/Salaire.
 *
 * Usage :
 *   php scripts/ged_regression_baseline.php            → écrit/affiche l'empreinte "avant"
 *   php scripts/ged_regression_baseline.php compare F  → compare l'état courant au fichier F
 *
 * Empreinte = counts + hash SHA-256 d'un jeu de lignes normalisé. Si le hash change
 * entre deux exécutions encadrant une modif → RÉGRESSION → stop/rollback.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

function snap(PDO $pdo): array {
    $counts = [];
    foreach ([
        'annonces','annonces_photos','ged_documents','ged_document_links',
        'biens_documents','dpe_diags','fluxbox_documents','rh_documents','salaires_documents','bien_prix',
    ] as $t) {
        try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
        catch (Throwable $e) { $counts[$t] = 'N/A'; }
    }
    // Données critiques UBIFLOW : prix/loyer des annonces diffusables (vente + location)
    $ub = $pdo->query("
        SELECT a.id, a.type_transaction, a.statut, a.etat_publication,
               COALESCE(a.prix,0) AS prix, COALESCE(a.prix_net_vendeur,0) AS pnv,
               COALESCE(a.loyer,0) AS loyer, COALESCE(a.honoraires,0) AS hono
        FROM annonces a
        WHERE (a.statut IS NULL OR a.statut NOT IN ('supprime','archivee','archive'))
        ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC);
    $ubLines = array_map(fn($r) => implode('|', $r), $ub);
    $hashUbiflow = hash('sha256', implode("\n", $ubLines));

    // RH / Salaire : empreinte des documents (id + type + lien)
    $rh = $pdo->query("SELECT id, document_type, source_module FROM ged_documents WHERE source_module='02_RH' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $hashRh = hash('sha256', json_encode($rh));

    return [
        'counts'        => $counts,
        'ubiflow_nb'    => count($ub),
        'hash_ubiflow'  => $hashUbiflow,   // INVARIANT : prix/loyer des annonces diffusables
        'hash_rh'       => $hashRh,
    ];
}

$mode = $argv[1] ?? 'snapshot';
$now  = snap($pdo);

if ($mode === 'compare' && !empty($argv[2]) && is_file($argv[2])) {
    $ref = json_decode((string)file_get_contents($argv[2]), true) ?: [];
    $ok = true;
    echo "=== COMPARAISON anti-régression ===\n";
    foreach (['hash_ubiflow','hash_rh'] as $k) {
        $same = ($ref[$k] ?? null) === $now[$k];
        echo ($same ? '  ✅ ' : '  ❌ ') . $k . ($same ? ' identique' : ' A CHANGÉ') . "\n";
        if (!$same) $ok = false;
    }
    echo "  ubiflow_nb : {$ref['ubiflow_nb']} → {$now['ubiflow_nb']}" . ($ref['ubiflow_nb']===$now['ubiflow_nb']?' ✅':' ⚠️') . "\n";
    echo $ok ? "\n🟢 AUCUNE RÉGRESSION sur les zones protégées.\n" : "\n🔴 RÉGRESSION DÉTECTÉE → STOP / ROLLBACK.\n";
    exit($ok ? 0 : 1);
}

echo json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
