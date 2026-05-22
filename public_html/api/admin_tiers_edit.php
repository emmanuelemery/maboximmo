<?php
// api/admin_tiers_edit.php — Édition rapide d'un champ tiers (super admin)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/admin_tiers_scope.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$id    = (int)(post('id') ?? 0);
$field = trim((string)(post('field') ?? ''));
$value = post('value') ?? null;
if ($id <= 0)     { echo json_encode(['ok'=>false,'error'=>'id manquant']); exit; }

// Whitelist des champs éditables
$allowed = ['raison_sociale','nom','prenom','civilite','siren','siret','email','telephone','mobile',
            'adresse_ligne1','code_postal','ville','nom_affichage','sous_type','tva_intracom'];
if (!in_array($field, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'champ non éditable : ' . $field]); exit;
}

$value = is_string($value) ? trim($value) : $value;
if ($value === '') $value = null;

// Scope check : un user d'agence ne peut éditer que les tiers de son périmètre
$scope = tiers_check_scope($pdo, [$id]);
if (!$scope['ok']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>$scope['error']]); exit; }

try {
    $st = $pdo->prepare("UPDATE tiers SET `$field` = ?, date_modification = NOW() WHERE id = ?");
    $st->execute([$value, $id]);

    // Recalcule le nom_affichage si on touche aux composants
    if (in_array($field, ['nom','prenom','raison_sociale'], true)) {
        $stG = $pdo->prepare('SELECT type_tiers, nom, prenom, raison_sociale FROM tiers WHERE id = ?');
        $stG->execute([$id]);
        $t = $stG->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $aff = trim((string)$t['raison_sociale']) ?: trim((string)$t['prenom'] . ' ' . (string)$t['nom']);
            $aff = trim($aff);
            if ($aff !== '') {
                $pdo->prepare('UPDATE tiers SET nom_affichage = ? WHERE id = ?')->execute([$aff, $id]);
            }
        }
    }

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[admin_tiers_edit] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
