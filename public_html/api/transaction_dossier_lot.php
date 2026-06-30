<?php
// api/transaction_dossier_lot.php — Gestion des lots d'un dossier/mandat de vente.
// POST action=add    : id_dossier, id_bien                         → ajoute un lot
// POST action=save   : id_dossier, lot_id, prix_vente, loyer_reel, loyer_potentiel
// POST action=remove : id_dossier, lot_id                          → détache un lot
// Retour : { ok, lots, totaux }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$action    = trim((string)(post('action') ?? ''));
$idDossier = (int)(post('id_dossier') ?? 0);

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }

// Scope société (super admin / manager bypass — cf. feedback_scope_super_admin_bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    switch ($action) {
        case 'search':
            // Recherche de biens à rattacher comme lots (scope société, priorité même immeuble).
            $q = trim((string)(post('q') ?? ($_GET['q'] ?? '')));
            $stI = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ? LIMIT 1");
            $stI->execute([(int)$dossier['id_bien']]);
            $immId = (int)($stI->fetchColumn() ?: 0);

            $where  = ['(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))'];
            $params = [];
            // Exclut les biens déjà lots de CE dossier.
            $where[] = 'b.id NOT IN (SELECT id_bien FROM dossier_vente_bien WHERE id_dossier = :doss)';
            $params[':doss'] = $idDossier;
            if (!$isManager && $idSoc !== null) {
                $where[] = '(b.id_societe = :s OR b.id_societe IS NULL)';
                $params[':s'] = $idSoc;
            }
            if (mb_strlen($q) >= 2) {
                $where[] = '(b.reference_bien LIKE :q OR b.designation LIKE :q OR b.ville LIKE :q OR b.adresse_1 LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            } elseif ($immId <= 0) {
                echo json_encode(['ok'=>true,'items'=>[]]); exit; // pas d'immeuble + pas de requête
            }
            $sql = 'SELECT b.id, b.reference_bien, b.designation, b.etage, b.numero_lot,
                           b.loyer_hc, b.id_immeuble,
                           COALESCE(NULLIF(b.ville,""), i.ville) AS ville,
                           (b.id_immeuble = :imm) AS meme_immeuble
                    FROM biens b
                    LEFT JOIN immeubles i ON i.id = b.id_immeuble
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY meme_immeuble DESC, b.date_modification DESC LIMIT 25';
            $params[':imm'] = $immId;
            $stS = $pdo->prepare($sql);
            foreach ($params as $k=>$v) { $stS->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
            $stS->execute();
            echo json_encode(['ok'=>true, 'items'=>$stS->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
            exit;

        case 'add':
            $idBien = (int)(post('id_bien') ?? 0);
            if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'bien manquant']); exit; }
            // Le bien doit appartenir à la même société (sauf manager).
            $stB = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ? LIMIT 1");
            $stB->execute([$idBien]);
            $bSoc = $stB->fetchColumn();
            if ($bSoc === false) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }
            if (!$isManager && $idSoc !== null && (int)$bSoc !== $idSoc) {
                http_response_code(403); echo json_encode(['ok'=>false,'error'=>'bien hors scope']); exit;
            }
            // Rang = max + 1.
            $stR = $pdo->prepare("SELECT COALESCE(MAX(rang),0)+1 FROM dossier_vente_bien WHERE id_dossier = ?");
            $stR->execute([$idDossier]);
            $rang = (int)$stR->fetchColumn();
            $lotId = dv_ensure_lot($pdo, $idDossier, $idBien, ['rang'=>$rang, 'id_user'=>(int)current_user_id() ?: null]);
            if ($lotId <= 0) { echo json_encode(['ok'=>false,'error'=>'ajout impossible']); exit; }
            break;

        case 'save':
            $lotId = (int)(post('lot_id') ?? 0);
            if ($lotId <= 0) { echo json_encode(['ok'=>false,'error'=>'lot manquant']); exit; }
            $ok = dv_save_lot($pdo, $idDossier, $lotId, [
                'estimation'      => post('estimation'),
                'prix_vente'      => post('prix_vente'),
                'loyer_reel'      => post('loyer_reel'),
                'loyer_potentiel' => post('loyer_potentiel'),
            ]);
            if (!$ok) { echo json_encode(['ok'=>false,'error'=>'enregistrement impossible']); exit; }
            break;

        case 'remove':
            $lotId = (int)(post('lot_id') ?? 0);
            if ($lotId <= 0) { echo json_encode(['ok'=>false,'error'=>'lot manquant']); exit; }
            if (!dv_remove_lot($pdo, $idDossier, $lotId)) {
                echo json_encode(['ok'=>false,'error'=>'retrait impossible (dernier lot ?)']); exit;
            }
            break;

        default:
            echo json_encode(['ok'=>false,'error'=>'action inconnue']); exit;
    }

    // Propage les prix (net→FAI+honos) vers le bien + l'annonce.
    dv_sync_prix_annonce($pdo, $idDossier);

    echo json_encode([
        'ok'     => true,
        'lots'   => dv_lots($pdo, $idDossier),
        'totaux' => dv_totaux($pdo, $idDossier),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_lot] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
