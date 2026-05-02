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
    // Super admin (id_role=1) : bypass du scope société (peut analyser toute photo).
    $isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
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
        if (!$isSuperAdmin && $societeId > 0 && (int)$r['id_societe'] !== $societeId) {
            throw new RuntimeException('Accès refusé');
        }
        $rows = [$r];
    } else {
        // Mode batch : toutes les photos d'un bien.
        // Sans force : on cible les photos qui ne sont PAS marquées 'ok'
        //              (jamais analysées, en pending, ou en erreur)
        $whereIa = '';
        if (!$force) {
            $whereIa = " AND (bp.analyse_statut IS NULL OR bp.analyse_statut <> 'ok') ";
        }
        // Tolère l'absence des colonnes IA (migration non passée) — fallback brut.
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
            // Fallback : colonnes critique/statut absentes — on retombe sur description_ia
            $whereIaLegacy = $force ? '' : ' AND (bp.description_ia IS NULL OR bp.description_ia = \'\') ';
            try {
                $st = $pdo->prepare("
                    SELECT bp.id, bp.id_bien, bp.url_photo, b.id_societe
                    FROM biens_photos bp
                    JOIN biens b ON b.id = bp.id_bien
                    WHERE bp.id_bien = ?
                    {$whereIaLegacy}
                    ORDER BY bp.ordre ASC, bp.id ASC
                    LIMIT {$maxBatch}
                ");
                $st->execute([$idBien]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
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
        }
        // Vérif scope sur le premier
        if (!$isSuperAdmin && $rows && $societeId > 0 && (int)$rows[0]['id_societe'] !== $societeId) {
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

    // ── Détection disponibilité colonnes critique (migration_biens_photos_critique.sql) ──
    $critiqueColsOk = false;
    try {
        $pdo->query("SELECT critique_niveau, analyse_statut FROM biens_photos LIMIT 1");
        $critiqueColsOk = true;
    } catch (Throwable) {
        $critiqueColsOk = false;
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
            $errMsg = $res['error'] ?? 'inconnue';
            // Reconnexion MySQL au cas où la connexion a expiré pendant l'appel IA
            $pdo = db_reconnect_fresh();
            // Marque le statut error (utile pour le cron retry)
            if ($critiqueColsOk) {
                try {
                    $pdo->prepare("UPDATE biens_photos SET analyse_statut='error', analyse_erreur=? WHERE id=?")
                        ->execute([substr($errMsg, 0, 500), $photoId]);
                } catch (Throwable) {}
            }
            $skipped++;
            $errors[] = $errMsg;
            $results[] = ['id' => $photoId, 'ok' => false, 'error' => $errMsg];
            continue;
        }

        $cat  = $res['commercial']['categorie']   ?? ($res['categorie']   ?? null);
        $desc = $res['commercial']['description'] ?? ($res['description'] ?? null);
        $cri  = $res['critique'] ?? null;

        // Reconnexion MySQL si la connexion a expiré pendant l'appel IA
        $pdo = db_reconnect_fresh();

        if ($critiqueColsOk) {
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
            } catch (Throwable $e) {
                $errors[] = 'UPDATE: ' . $e->getMessage();
                $results[] = ['id' => $photoId, 'ok' => false, 'error' => 'BDD : ' . $e->getMessage()];
                continue;
            }
        } elseif ($iaColsOk) {
            // Fallback : seules les colonnes commercial sont dispo
            try {
                $pdo->prepare("
                    UPDATE biens_photos
                    SET categorie = ?, description_ia = ?, description_ia_date = NOW()
                    WHERE id = ?
                ")->execute([$cat, $desc, $photoId]);
            } catch (Throwable $e) {
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
            'critique'    => $cri,
            'saved'       => $iaColsOk || $critiqueColsOk,
        ];
    }

    echo json_encode([
        'ok'              => true,
        'analysed'        => $analysed,
        'skipped'         => $skipped,
        'errors'          => $errors,
        'ia_cols_ok'      => $iaColsOk,
        'critique_cols_ok'=> $critiqueColsOk,
        'results'         => $results,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
