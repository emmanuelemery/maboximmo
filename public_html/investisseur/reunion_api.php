<?php
declare(strict_types=1);
/**
 * investisseur/reunion_api.php
 *
 * Actions :
 *   simulate     : reçoit loyer_annuel + taux_renta + prix (optionnels) et retourne KPI + arbitrage
 *   save         : persiste prix_vente_catalogue + priorite_vente + taux_renta_retenu + commentaire_reunion
 *   upload_audio : ajoute un enregistrement vocal (webm/ogg/mp3) lié à l'analyse
 *   delete_audio : supprime un enregistrement
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';
require_once __DIR__ . '/../inc/investisseur_interpretations.php';
require_once __DIR__ . '/../inc/investisseur_projection.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];

try {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    $id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('id manquant');

    $row = inv_load($pdo, $id);
    if (!$row) throw new RuntimeException('Analyse introuvable');

    if ($action === 'simulate') {
        // Appliquer les hypothèses de simulation
        $loyerAnnuel = isset($_POST['loyer_annuel']) && $_POST['loyer_annuel'] !== ''
            ? (float)str_replace(',', '.', (string)$_POST['loyer_annuel']) : (float)$row['loyer_estime'] * 12;
        $prix = isset($_POST['prix']) && $_POST['prix'] !== ''
            ? (float)str_replace(',', '.', (string)$_POST['prix']) : (float)$row['prix_vente_catalogue'];

        $row['loyer_estime'] = round($loyerAnnuel / 12, 2);
        $row['prix_vente_catalogue'] = $prix;
        $row['prix_achat'] = $prix;

        $calc = inv_compute_all($row);
        $arb  = inv_proj_arbitrage($row, [5, 10]);
        $tauxRenta = $prix > 0 ? ($loyerAnnuel / $prix) * 100 : 0;
        echo json_encode([
            'ok' => true,
            'taux_renta' => round($tauxRenta, 2),
            'kpi' => [
                'rendement_brut'   => (float)$calc['rendement_brut'],
                'rendement_net'    => (float)$calc['rendement_net'],
                'prix_m2'          => (float)$calc['prix_m2'],
                'multiple_loyer'   => (float)$calc['multiple_loyer'],
                'cashflow_mensuel' => (float)$calc['cashflow_mensuel'],
                'score_global'     => (int)$calc['score_global'],
            ],
            'arbitrage' => [
                'vendre_now'  => (float)($arb['scenarios']['vendre_now']['cash_net'] ?? 0),
                'garder_5'    => (float)($arb['scenarios']['garder_5']['cash_net']   ?? 0),
                'garder_10'   => (float)($arb['scenarios']['garder_10']['cash_net']  ?? 0),
                'meilleur'    => $arb['meilleur'],
                'conseil'     => $arb['conseils'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save') {
        verify_csrf_any();
        if (isset($_POST['prix_vente_catalogue']) && $_POST['prix_vente_catalogue'] !== '') {
            $row['prix_vente_catalogue'] = (float)str_replace(',', '.', (string)$_POST['prix_vente_catalogue']);
            $row['prix_achat'] = $row['prix_vente_catalogue'];
        }
        if (isset($_POST['loyer_annuel']) && $_POST['loyer_annuel'] !== '') {
            $row['loyer_estime'] = round(((float)str_replace(',', '.', (string)$_POST['loyer_annuel'])) / 12, 2);
        }
        if (isset($_POST['priorite_vente'])) {
            $row['priorite_vente'] = max(0, min(10, (int)$_POST['priorite_vente']));
        }
        if (isset($_POST['taux_renta_retenu'])) {
            $row['taux_renta_retenu'] = (float)str_replace(',', '.', (string)$_POST['taux_renta_retenu']);
        }
        if (isset($_POST['commentaire_reunion'])) {
            $row['commentaire_reunion'] = trim((string)$_POST['commentaire_reunion']);
        }
        inv_save($pdo, $row, $id);
        $row = inv_load($pdo, $id);
        echo json_encode(['ok' => true, 'row' => [
            'prix_vente_catalogue' => (float)$row['prix_vente_catalogue'],
            'priorite_vente'       => (int)($row['priorite_vente'] ?? 0),
            'taux_renta_retenu'    => (float)($row['taux_renta_retenu'] ?? 0),
            'rendement_net'        => (float)$row['rendement_net'],
            'score_global'         => (int)$row['score_global'],
        ]]);
        exit;
    }

    if ($action === 'upload_audio') {
        verify_csrf_any();
        if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Fichier audio manquant');
        }
        $size = (int)$_FILES['audio']['size'];
        if ($size > 20 * 1024 * 1024) throw new RuntimeException('Audio trop lourd (>20 Mo)');
        $ext = 'webm';
        $mime = (string)$_FILES['audio']['type'];
        if (strpos($mime, 'ogg') !== false) $ext = 'ogg';
        elseif (strpos($mime, 'mpeg') !== false || strpos($mime, 'mp3') !== false) $ext = 'mp3';
        elseif (strpos($mime, 'wav') !== false) $ext = 'wav';

        $dir = __DIR__ . '/../uploads/investisseur_audio/' . date('Y/m');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = date('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $fname;
        if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) throw new RuntimeException('Échec enregistrement fichier');
        $rel = 'uploads/investisseur_audio/' . date('Y/m') . '/' . $fname;

        $duree = isset($_POST['duree_sec']) && $_POST['duree_sec'] !== '' ? (int)$_POST['duree_sec'] : null;
        $uid = (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
        $st = $pdo->prepare("INSERT INTO investisseur_audios (id_analyse, id_user, url_fichier, duree_sec) VALUES (:a, :u, :f, :d)");
        $st->bindValue(':a', $id, PDO::PARAM_INT);
        $st->bindValue(':u', $uid, PDO::PARAM_INT);
        $st->bindValue(':f', $rel);
        $st->bindValue(':d', $duree, $duree === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        $audioId = (int)$pdo->lastInsertId();

        echo json_encode([
            'ok' => true,
            'audio' => [
                'id' => $audioId,
                'url' => (function_exists('app_url') ? app_url('/' . $rel) : '/' . $rel),
                'duree_sec' => $duree,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
        exit;
    }

    if ($action === 'delete_audio') {
        verify_csrf_any();
        $idAudio = (int)($_POST['id_audio'] ?? 0);
        if ($idAudio <= 0) throw new RuntimeException('id_audio manquant');
        $st = $pdo->prepare("SELECT url_fichier FROM investisseur_audios WHERE id = :i AND id_analyse = :a LIMIT 1");
        $st->bindValue(':i', $idAudio, PDO::PARAM_INT);
        $st->bindValue(':a', $id, PDO::PARAM_INT);
        $st->execute();
        if ($url = $st->fetchColumn()) {
            $abs = __DIR__ . '/../' . ltrim((string)$url, '/');
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM investisseur_audios WHERE id = :i")->execute([':i' => $idAudio]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new RuntimeException('Action inconnue');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
