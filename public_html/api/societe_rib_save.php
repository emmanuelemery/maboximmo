<?php
/**
 * api/societe_rib_save.php — Crée/modifie un compte bancaire (societes_rib) typé + rattaché
 * à une agence. Usage : GESTION / SÉQUESTRE / SOCIÉTÉ.
 *
 * POST JSON : { id?, id_societe, id_agence?, type_compte, libelle, titulaire, iban, bic, banque, is_default? }
 * Auth : user + scope société (bypass super-admin role=1).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/comptes_bancaires.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$isAdmin=((int)($_SESSION['id_role']??0)===1);
$userSoc=(int)($_SESSION['id_societe']??0);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$idSoc=(int)($body['id_societe']??0);
if ($idSoc<=0) exit(json_encode(['ok'=>false,'error'=>'id_societe requis']));
if (!$isAdmin && $idSoc!==$userSoc){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre société'])); }

// L'agence (si fournie) doit appartenir à la société.
$idAge=!empty($body['id_agence'])?(int)$body['id_agence']:null;
if ($idAge!==null){
    $c=$pdo->prepare("SELECT 1 FROM agences WHERE id=? AND id_societe=? LIMIT 1");
    $c->execute([$idAge,$idSoc]);
    if(!$c->fetchColumn()){ http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Agence hors société'])); }
}

try {
    $id = cb_save($pdo, [
        'id'          => (int)($body['id']??0),
        'id_societe'  => $idSoc,
        'id_agence'   => $idAge,
        'type_compte' => (string)($body['type_compte']??'gestion'),
        'libelle'     => (string)($body['libelle']??''),
        'titulaire'   => (string)($body['titulaire']??''),
        'iban'        => (string)($body['iban']??''),
        'bic'         => (string)($body['bic']??''),
        'banque'      => (string)($body['banque']??''),
        'is_default'  => !empty($body['is_default']),
    ]);
    echo json_encode(['ok'=>true,'id'=>$id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); exit(json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE));
}
