<?php
declare(strict_types=1);
set_time_limit(60); // cap global à 60 s pour tout l'upload+analyse
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_import_parser.php';
require_once dirname(__DIR__) . '/inc/bien_import_mapper.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

verify_csrf_any('ajouter_bien');

if (empty($_FILES['fichiers'])) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun fichier reçu']));
}

// Dossier de stockage
$uploadDir = dirname(__DIR__) . '/uploads/imports/' . $societeId . '/' . date('Y/m') . '/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    exit(json_encode(['ok' => false, 'error' => 'Impossible de créer le dossier d\'upload']));
}

// Normalise $_FILES pour multi-upload
$files = [];
if (is_array($_FILES['fichiers']['name'])) {
    for ($i = 0; $i < count($_FILES['fichiers']['name']); $i++) {
        $files[] = [
            'name'     => $_FILES['fichiers']['name'][$i],
            'tmp_name' => $_FILES['fichiers']['tmp_name'][$i],
            'size'     => $_FILES['fichiers']['size'][$i],
            'error'    => $_FILES['fichiers']['error'][$i],
        ];
    }
} else {
    $files[] = $_FILES['fichiers'];
}

$mode = count($files) > 1 ? 'multi' : 'single';

// ── Crée la session d'import ─────────────────────────────────
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO bien_imports (id_societe, id_user, mode_import, statut_global, nb_fichiers, created_at, updated_at)
                   VALUES (?, ?, ?, 'analysing', ?, NOW(), NOW())")
        ->execute([$societeId, $userId, $mode, count($files)]);
    $importId = (int)$pdo->lastInsertId();

    $resultats = [];

    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $resultats[] = ['ok' => false, 'nom' => $file['name'], 'error' => 'Erreur upload'];
            continue;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf'], true)) {
            $resultats[] = ['ok' => false, 'nom' => $file['name'], 'error' => 'Format non supporté (PDF uniquement)'];
            continue;
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            $resultats[] = ['ok' => false, 'nom' => $file['name'], 'error' => 'Fichier trop volumineux (max 20 Mo)'];
            continue;
        }

        $safeName  = date('His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath  = $uploadDir . $safeName;
        $relPath   = 'uploads/imports/' . $societeId . '/' . date('Y/m') . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $resultats[] = ['ok' => false, 'nom' => $file['name'], 'error' => 'Déplacement du fichier impossible'];
            continue;
        }

        // ── Enregistrement fichier ──
        $pdo->prepare("INSERT INTO bien_import_fichiers
            (id_import, nom_fichier, chemin_fichier, extension, taille, statut, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'analysing', NOW(), NOW())")
            ->execute([$importId, $file['name'], $relPath, $ext, $file['size']]);
        $fichierId = (int)$pdo->lastInsertId();

        // ── Extraction texte ──
        $texteSource  = BienImportParser::extractText($destPath);
        $texteNettoye = BienImportParser::cleanText($texteSource);

        // ── Extraction photos embarquées dans le PDF ──
        $photoDir   = dirname(__DIR__) . '/uploads/imports/' . $societeId . '/' . date('Y/m') . '/photos_' . $fichierId . '/';
        $photoRel   = 'uploads/imports/' . $societeId . '/' . date('Y/m') . '/photos_' . $fichierId . '/';
        $photosPaths = BienImportParser::extractPhotos($destPath, $photoDir);
        $photosRel   = array_map(fn($p) => $photoRel . basename($p), $photosPaths);

        // ── Analyse et mapping ──
        $analyse = BienImportMapper::analyse($texteNettoye);

        $champs             = $analyse['champs'];
        $score              = $analyse['score'];
        $alertes            = $analyse['alertes'];
        $manquants          = $analyse['manquants'];
        $deduits            = $analyse['deduits'];
        $descSug            = $analyse['description'];
        $proprietaire       = $analyse['proprietaire']       ?? [];
        $repriseDescriptif  = $analyse['reprise_descriptif'] ?? '';

        // ── Met à jour fichier avec données détectées ──
        $pdo->prepare("UPDATE bien_import_fichiers SET
            statut = 'analysed',
            score_global = ?,
            type_offre_detecte = ?,
            type_bien_detecte = ?,
            adresse_detectee = ?,
            ville_detectee = ?,
            code_postal_detecte = ?,
            reference_detectee = ?,
            updated_at = NOW()
            WHERE id = ?")
            ->execute([
                $score,
                $champs['type_offre']['valeur']   ?? null,
                $champs['type_bien']['valeur']     ?? null,
                $champs['adresse_1']['valeur']     ?? null,
                $champs['ville']['valeur']         ?? null,
                $champs['code_postal']['valeur']   ?? null,
                $champs['reference_bien']['valeur']?? null,
                $fichierId,
            ]);

        // ── Enregistre données détaillées ──
        $pdo->prepare("INSERT INTO bien_import_donnees
            (id_import_fichier, donnees_extraites, donnees_mappees,
             champs_obligatoires_manquants, alertes, suggestions,
             champs_deduits_description_json,
             texte_source, texte_nettoye, description_suggeree,
             created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), NOW())")
            ->execute([
                $fichierId,
                json_encode($champs, JSON_UNESCAPED_UNICODE),
                json_encode($champs, JSON_UNESCAPED_UNICODE),
                json_encode($manquants, JSON_UNESCAPED_UNICODE),
                json_encode($alertes, JSON_UNESCAPED_UNICODE),
                json_encode([], JSON_UNESCAPED_UNICODE),
                json_encode($deduits, JSON_UNESCAPED_UNICODE),
                $texteSource,
                $texteNettoye,
                $descSug,
            ]);

        // ── Alertes en table ──
        foreach ($alertes as $al) {
            $pdo->prepare("INSERT INTO bien_import_alertes (id_import_fichier, type_alerte, niveau, champ, message, created_at)
                VALUES (?, 'analyse', ?, ?, ?, NOW())")
                ->execute([$fichierId, $al['niveau'] ?? 'warning', $al['champ'] ?? '', $al['message'] ?? '']);
        }

        // ── Statut final ──
        $statut = empty($manquants) ? 'ready' : 'incomplete';
        $pdo->prepare("UPDATE bien_import_fichiers SET statut = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$statut, $fichierId]);

        $resultats[] = [
            'ok'          => true,
            'fichier_id'  => $fichierId,
            'import_id'   => $importId,
            'nom'         => $file['name'],
            'statut'      => $statut,
            'score'       => $score,
            'type_offre'  => $champs['type_offre']['valeur'] ?? null,
            'type_bien'   => $champs['type_bien']['valeur']  ?? null,
            'ville'       => $champs['ville']['valeur']      ?? null,
            'code_postal' => $champs['code_postal']['valeur']?? null,
            'surface'     => $champs['surface_habitable']['valeur'] ?? $champs['surface_terrain']['valeur'] ?? null,
            'prix'        => $champs['prix_vente']['valeur'] ?? $champs['loyer']['valeur'] ?? null,
            'manquants'   => $manquants,
            'alertes'     => count($alertes),
            'champs'             => $champs,
            'deduits'            => $deduits,
            'description'        => $descSug,
            'proprietaire'       => $proprietaire,
            'reprise_descriptif' => $repriseDescriptif,
            'photos'             => $photosRel,
        ];
    }

    $pdo->prepare("UPDATE bien_imports SET statut_global = 'analysed', updated_at = NOW() WHERE id = ?")
        ->execute([$importId]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'import_id' => $importId, 'mode' => $mode, 'resultats' => $resultats], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
