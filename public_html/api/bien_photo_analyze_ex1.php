<?php
declare(strict_types=1);
set_time_limit(120);

/**
 * POST /api/bien_photo_analyze.php
 *
 * Lance (ou relance) l'analyse GPT-4o Vision sur une ou plusieurs photos
 * de la bibliothèque d'un bien et met à jour `biens_photos.categorie`
 * + `description_ia` + `description_ia_date`.
 *
 * Utile pour :
 *   - les photos uploadées avant l'ajout des colonnes IA (migration passée)
 *   - relancer une analyse jugée insatisfaisante
 *   - analyser des photos ajoutées plus tard à la bibliothèque sans passer
 *     par l'intake (ex : upload direct dans bien_ajouter.php)
 *
 * Paramètres (POST) :
 *   - csrf_token (obligatoire, token "ajouter_bien")
 *   - id_photo  (optionnel, int) : analyse UNE photo
 *   - id_bien   (optionnel, int) : analyse TOUTES les photos du bien qui
 *                                  n'ont pas encore de description_ia
 *   - force     (optionnel, 0|1) : avec id_bien, relance aussi les photos
 *                                  déjà analysées (défaut = 0)
 *   - max       (optionnel, int) : limite batch (défaut 20)
 *
 * Réponse JSON :
 *   { ok: true, analysed: N, skipped: M, errors: [...], results: [ {id, categorie, description, ok, error?}, … ] }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_photo_analyser.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = db();
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $idPhoto   = isset($_POST['id_photo']) && ctype_digit((string)$_POST['id_photo']) ? (int)$_POST['id_photo'] : 0;
    $idBien    = isset($_POST['id_bien'])  && ctype_digit((string)$_POST['id_bien'])  ? (int)$_POST['id_bien']  : 0;
    $force     = !empty($_POST['force']);
    $maxBatch  = isset($_POST['max']) && ctype_digit((string)$_POST['max']) ? max(1, min(50, (int)$_POST['max'])) : 20;

    if ($idPhoto <= 0 && $idBien <= 0) {
        throw new RuntimeException('id_photo ou id_bien requis');
    }

    // ── Résolution des photos à analyser ────────────────────
    // Scope société via jointure biens.id_societe.
    $rows = [];
    if ($idPhoto > 0) {
        $st = $pdo->prepare("
            SELECT bp.id, bp.id_bien, bp.url_photo, b.id_societe
            FROM biens_photos bp
            JOIN biens b ON b.id = bp.id_bien
            WHERE bp.id = ?
            LIMIT 1
        ");
        $st->execute([$idPhoto]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new RuntimeException('Photo introuvable');
        if ($societeId > 0 && (int)$r['id_societe'] !== $societeId) {
            throw new RuntimeException('Accès refusé');
        }
        $rows = [$r];
    } else {
        // Mode batch : toutes les photos d'un bien
        $whereIa = $force ? '' : ' AND (bp.description_ia IS NULL OR bp.description_ia = \'\') ';
        // Tolère l'absence des colonnes IA (migration non passée) — dans ce cas,
        // on analyse toutes les photos du bien.
        try {
            $st = $pdo->prepare("
                SELECT bp.id, bp.id_bien, bp.url_photo, b.id_societe
                FROM biens_photos bp
                JOIN biens b ON b.id = bp.id_bien
                WHERE bp.id_bien = ?
                {$whereIa}
                ORDER BY bp.ordre ASC, bp.id ASC
                LIMIT {$maxBatch}
            ");
            $st->execute([$idBien]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Fallback : colonnes IA absentes
            $st = $pdo->prepare("
                SELECT bp.id, bp.id_bien, bp.url_photo, b.id_societe
                FROM biens_photos bp
                JOIN biens b ON b.id = bp.id_bien
                WHERE bp.id_bien = ?
                ORDER BY bp.ordre ASC, bp.id ASC
                LIMIT {$maxBatch}
            ");
            $st->execute([$idBien]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        // Vérif scope sur le premier
        if ($rows && $societeId > 0 && (int)$rows[0]['id_societe'] !== $societeId) {
            throw new RuntimeException('Accès refusé');
        }
    }

    if (empty($rows)) {
        echo json_encode([
            'ok' => true,
            'analysed' => 0,
            'skipped'  => 0,
            'results'  => [],
            'message'  => 'Aucune photo à analyser',
        ]);
        exit;
    }

    // ── Détection disponibilité colonnes IA (migration_biens_photos_ia.sql) ──
    $iaColsOk = false;
    try {
        $pdo->query("SELECT description_ia FROM biens_photos LIMIT 1");
        $iaColsOk = true;
    } catch (Throwable) {
        $iaColsOk = false;
    }

    // ── Analyse photo par photo ────────────────────────────
    $results  = [];
    $analysed = 0;
    $skipped  = 0;
    $errors   = [];

    foreach ($rows as $row) {
        $photoId = (int)$row['id'];
        $urlRel  = (string)$row['url_photo'];
        $photoAbs = dirname(__DIR__) . '/' . ltrim($urlRel, '/');

        if (!is_file($photoAbs)) {
            $skipped++;
            $results[] = ['id' => $photoId, 'ok' => false, 'error' => 'Fichier introuvable sur disque'];
            continue;
        }

        $res = analyserPhotoBien($photoAbs);
        if (!$res['ok']) {
            $skipped++;
            $errors[] = $res['error'] ?? 'inconnue';
            $results[] = ['id' => $photoId, 'ok' => false, 'error' => $res['error'] ?? 'inconnue'];
            continue;
        }

        $cat  = $res['categorie']   ?? null;
        $desc = $res['description'] ?? null;

        // Reconnexion MySQL si la connexion a expiré pendant l'appel OpenAI
        $pdo = db_reconnect_fresh();

        if ($iaColsOk) {
            try {
                $pdo->prepare("
                    UPDATE biens_photos
                    SET categorie = ?, description_ia = ?, description_ia_date = NOW()
                    WHERE id = ?
                ")->execute([$cat, $desc, $photoId]);
            } catch (Throwable $e) {
                // Si le schema diffère (colonnes nommées autrement), remonte l'erreur une seule fois
                $errors[] = 'UPDATE: ' . $e->getMessage();
                $results[] = ['id' => $photoId, 'ok' => false, 'error' => 'BDD : ' . $e->getMessage()];
                continue;
            }
        }

        $analysed++;
        $results[] = [
            'id'          => $photoId,
            'ok'          => true,
            'categorie'   => $cat,
            'description' => $desc,
            'saved'       => $iaColsOk,
        ];
    }

    echo json_encode([
        'ok'         => true,
        'analysed'   => $analysed,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'ia_cols_ok' => $iaColsOk,
        'results'    => $results,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
