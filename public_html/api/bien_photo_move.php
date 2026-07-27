<?php
/**
 * api/bien_photo_move.php — Déplace / réordonne des photos d'un bien (entre groupes et dans un
 * groupe). La page envoie l'état cible des photos affectées.
 *
 * POST : id_bien, csrf_token, photos = JSON [ {id, groupe_no, groupe_label?, ordre} , ... ]
 * Réponse : { ok, moved }
 * Scope société (super admin bypass).
 */
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
verify_csrf_any('bien_photo_group');

$pdo    = $GLOBALS['pdo'];
$idBien = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$photos = json_decode((string)($_POST['photos'] ?? '[]'), true);
if ($idBien <= 0 || !is_array($photos) || !$photos) { exit(json_encode(['ok'=>false,'error'=>'id_bien et photos requis'])); }

try {
    $isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
    $societeId    = (int)($_SESSION['id_societe'] ?? 0);
    $st = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ? LIMIT 1");
    $st->execute([$idBien]); $bienSoc = $st->fetchColumn();
    if ($bienSoc === false) { exit(json_encode(['ok'=>false,'error'=>'bien introuvable'])); }
    if (!$isSuperAdmin && $societeId > 0 && (int)$bienSoc !== $societeId) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Bien hors de votre société'])); }

    // Ne met à jour QUE des photos appartenant à ce bien (sécurité).
    $upd = $pdo->prepare("UPDATE biens_photos SET groupe_no = ?, groupe_label = ?, ordre = ?, date_modification = NOW()
                          WHERE id = ? AND entity_type='BIEN' AND entity_id = ?");
    $moved = 0;
    $pdo->beginTransaction();
    foreach ($photos as $ph) {
        $pid = (int)($ph['id'] ?? 0); if ($pid <= 0) continue;
        $gno = (int)($ph['groupe_no'] ?? 0);
        $glb = array_key_exists('groupe_label', $ph) ? (trim((string)$ph['groupe_label']) ?: null) : null;
        $ord = (int)($ph['ordre'] ?? 0);
        // Si aucun label fourni, on reprend celui déjà porté par ce groupe (cohérence).
        if ($glb === null) {
            $qg = $pdo->prepare("SELECT groupe_label FROM biens_photos WHERE entity_type='BIEN' AND entity_id=? AND COALESCE(groupe_no,0)=? AND groupe_label IS NOT NULL AND groupe_label<>'' LIMIT 1");
            $qg->execute([$idBien, $gno]); $glb = $qg->fetchColumn() ?: null;
        }
        $upd->execute([$gno, $glb, $ord, $pid, $idBien]);
        $moved += $upd->rowCount();
    }
    $pdo->commit();
    echo json_encode(['ok'=>true, 'moved'=>$moved], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
