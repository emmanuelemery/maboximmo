<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}
verify_csrf_any('ajouter_bien');

$bienId   = isset($_POST['bien_id']) && ctype_digit((string)$_POST['bien_id']) ? (int)$_POST['bien_id'] : 0;
$kind     = (string)($_POST['kind'] ?? '');           // 'proprietaire' | 'immeuble'
$action   = (string)($_POST['action'] ?? 'select');   // 'select' | 'create'
$targetId = isset($_POST['target_id']) && ctype_digit((string)$_POST['target_id']) ? (int)$_POST['target_id'] : 0;
$dataJson = (string)($_POST['data'] ?? '');
$data     = $dataJson ? json_decode($dataJson, true) : [];

if ($bienId <= 0) exit(json_encode(['ok' => false, 'error' => 'bien_id manquant']));
if (!in_array($kind, ['proprietaire','immeuble'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'kind invalide']));
}

try {
    // ── UNLINK : déliaison universelle (clic sur une carte déjà liée) ──
    if ($action === 'unlink') {
        $col = $kind === 'proprietaire' ? 'id_proprietaire' : 'id_immeuble';
        $pdo->prepare("UPDATE biens SET `$col` = NULL WHERE id = ?")->execute([$bienId]);
        echo json_encode(['ok' => true, 'unlinked' => true, 'kind' => $kind]);
        exit;
    }

    if ($kind === 'proprietaire') {
        $proprioId = 0;
        if ($action === 'select' && $targetId > 0) {
            // Vérifie que le proprio existe
            $stmt = $pdo->prepare("SELECT id FROM proprietaires WHERE id = ? AND actif = 1 LIMIT 1");
            $stmt->execute([$targetId]);
            $proprioId = (int)$stmt->fetchColumn();
            if ($proprioId === 0) exit(json_encode(['ok' => false, 'error' => 'Propriétaire introuvable']));
        } elseif ($action === 'create') {
            // Création depuis les données extraites
            $stmt = $pdo->prepare("
                INSERT INTO proprietaires
                    (id_agence, type_personne, civilite, nom, prenom, societe,
                     email, telephone, adresse_1, code_postal, ville, actif)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,1)
            ");
            $stmt->execute([
                $agenceId ?: null,
                $data['type_personne'] ?? 'physique',
                $data['civilite'] ?? null,
                $data['nom'] ?? ($data['societe'] ?? 'Inconnu'),
                $data['prenom'] ?? null,
                $data['societe'] ?? null,
                $data['email'] ?? null,
                $data['telephone'] ?? null,
                $data['adresse_1'] ?? null,
                $data['code_postal'] ?? null,
                $data['ville'] ?? null,
            ]);
            $proprioId = (int)$pdo->lastInsertId();
        } else {
            exit(json_encode(['ok' => false, 'error' => 'action invalide']));
        }

        $pdo->prepare("UPDATE biens SET id_proprietaire = ? WHERE id = ?")
            ->execute([$proprioId, $bienId]);

        // On retourne aussi le label pour l'UI
        $stmt = $pdo->prepare("SELECT id, civilite, nom, prenom, societe, ville FROM proprietaires WHERE id = ?");
        $stmt->execute([$proprioId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        $label = !empty($info['societe'])
            ? $info['societe'] . (!empty($info['nom']) ? ' (' . trim(($info['prenom'] ?? '') . ' ' . $info['nom']) . ')' : '')
            : trim(($info['civilite'] ?? '') . ' ' . ($info['prenom'] ?? '') . ' ' . ($info['nom'] ?? ''));

        echo json_encode(['ok' => true, 'kind' => 'proprietaire', 'id' => $proprioId, 'label' => $label]);
        exit;
    }

    if ($kind === 'immeuble') {
        $immeubleId = 0;
        $source = (string)($_POST['source'] ?? 'immeubles'); // 'immeubles' | 'reg'

        if ($action === 'select' && $targetId > 0 && $source === 'immeubles') {
            // Sélection d'un immeuble existant dans la table immeubles
            $stmt = $pdo->prepare("SELECT id FROM immeubles WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $immeubleId = (int)$stmt->fetchColumn();
            if ($immeubleId === 0) exit(json_encode(['ok' => false, 'error' => 'Immeuble introuvable']));
        } elseif ($action === 'select' && $targetId > 0 && $source === 'reg') {
            // Sélection d'un reg_immeuble : on crée une ligne immeubles à partir du registre
            $stmtReg = $pdo->prepare("SELECT id, nom, adresse, code_postal, ville, nb_lots, type, immatriculation FROM reg_immeubles WHERE id = ? LIMIT 1");
            $stmtReg->execute([$targetId]);
            $reg = $stmtReg->fetch(PDO::FETCH_ASSOC);
            if (!$reg) exit(json_encode(['ok' => false, 'error' => 'Registre introuvable']));

            // Vérifie qu'on n'a pas déjà une ligne immeubles à cette même adresse
            $stmtFind = $pdo->prepare("
                SELECT id FROM immeubles
                WHERE LOWER(TRIM(adresse_1)) = LOWER(TRIM(?))
                  AND code_postal = ?
                  AND LOWER(TRIM(ville)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $stmtFind->execute([
                (string)($reg['adresse'] ?? ''),
                (string)($reg['code_postal'] ?? ''),
                (string)($reg['ville'] ?? ''),
            ]);
            $immeubleId = (int)$stmtFind->fetchColumn();

            if ($immeubleId === 0) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO immeubles
                        (id_societe, id_agence, nom_immeuble, adresse_1,
                         code_postal, ville, pays, nb_lots, type_immeuble)
                    VALUES (?, ?, ?, ?, ?, ?, 'France', ?, ?)
                ");
                $stmtIns->execute([
                    $societeId ?: null,
                    $agenceId  ?: null,
                    $reg['nom'] ?? null,
                    $reg['adresse'] ?? null,
                    $reg['code_postal'] ?? null,
                    $reg['ville'] ?? null,
                    $reg['nb_lots'] ?? null,
                    $reg['type'] ?? null,
                ]);
                $immeubleId = (int)$pdo->lastInsertId();
            }
        } elseif ($action === 'create') {
            $stmt = $pdo->prepare("
                INSERT INTO immeubles
                    (id_societe, id_agence, adresse_1, adresse_2,
                     code_postal, ville, pays)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $societeId ?: null,
                $agenceId  ?: null,
                $data['adresse_1'] ?? null,
                $data['adresse_2'] ?? null,
                $data['code_postal'] ?? null,
                $data['ville'] ?? null,
                $data['pays'] ?? 'France',
            ]);
            $immeubleId = (int)$pdo->lastInsertId();
        } else {
            exit(json_encode(['ok' => false, 'error' => 'action invalide']));
        }

        $pdo->prepare("UPDATE biens SET id_immeuble = ? WHERE id = ?")
            ->execute([$immeubleId, $bienId]);

        $stmt = $pdo->prepare("SELECT id, adresse_1, code_postal, ville FROM immeubles WHERE id = ?");
        $stmt->execute([$immeubleId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        $label = trim(($info['adresse_1'] ?? '') . ' • ' . ($info['code_postal'] ?? '') . ' ' . ($info['ville'] ?? ''));

        echo json_encode(['ok' => true, 'kind' => 'immeuble', 'id' => $immeubleId, 'label' => $label]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
