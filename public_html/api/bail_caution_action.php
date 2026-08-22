<?php
/**
 * api/bail_caution_action.php — CRUD des cautions d'un bail (= tiers rôle 'caution' scopé bail).
 *
 * Une caution est un TIERS réel (contact/contentieux/signature), relié au bail via tiers_roles
 * (role_code='caution', objet_type='bail', id_objet=id_bail). Cf. inc/bail_cautions.php.
 *
 * POST :
 *   action=add    · id_bail + nom (+prenom,email,telephone,raison_sociale,caution_type,montant_max,duree_ans)
 *   action=detach · id_bail + id_tiers   → désactive le lien (le tiers est conservé)
 * Sécurité : login + CSRF (form 'bail_caution') + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/bail_cautions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('bail_caution');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

$bailId = isset($_POST['id_bail']) && ctype_digit((string)$_POST['id_bail']) ? (int)$_POST['id_bail'] : 0;
$action = (string)($_POST['action'] ?? '');
if ($bailId <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bail requis']); exit; }

// Le bail doit exister (garde-fou).
$st = $pdo->prepare("SELECT id FROM bien_baux WHERE id = ? LIMIT 1");
$st->execute([$bailId]);
if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Bail introuvable']); exit; }

if ($action === 'add') {
    $nom = trim((string)($_POST['nom'] ?? ''));
    $rs  = trim((string)($_POST['raison_sociale'] ?? ''));
    if ($nom === '' && $rs === '') { echo json_encode(['ok'=>false,'error'=>'Le nom (ou la raison sociale) est requis']); exit; }
    $ct = strtolower(trim((string)($_POST['caution_type'] ?? 'solidaire')));
    if (!in_array($ct, ['solidaire','simple'], true)) $ct = 'solidaire';

    $res = bail_caution_upsert($pdo, $bailId, [
        'nom'            => $nom,
        'prenom'         => trim((string)($_POST['prenom'] ?? '')) ?: null,
        'raison_sociale' => $rs ?: null,
        'email'          => trim((string)($_POST['email'] ?? '')) ?: null,
        'telephone'      => trim((string)($_POST['telephone'] ?? '')) ?: null,
        'caution_type'   => $ct,
        'montant_max'    => is_numeric($_POST['montant_max'] ?? null) ? (float)$_POST['montant_max'] : null,
        'duree_ans'      => is_numeric($_POST['duree_ans'] ?? null) ? (int)$_POST['duree_ans'] : null,
        'source'         => 'manuel',
    ]);
    if (($res['id_tiers'] ?? 0) <= 0) { echo json_encode(['ok'=>false,'error'=>'Création de la caution impossible']); exit; }
    echo json_encode([
        'ok'       => true,
        'id_tiers' => $res['id_tiers'],
        'id_role'  => $res['id_role'],
        'message'  => $res['created_tiers'] ? 'Caution (nouveau tiers) reliée au bail' : 'Caution (tiers existant) reliée au bail',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── MODIFIER LES CONDITIONS D'UN ENGAGEMENT DÉJÀ RATTACHÉ ────────────────────────
   ⚠️ Il fallait jusqu'ici DÉTACHER puis ré-ajouter la caution pour corriger un plafond
   ou une durée — donc perdre le lien, et avec lui tout ce qui s'y accroche. Or ces deux
   valeurs sont précisément ce que l'agent ajuste en rédigeant le projet de bail : le
   calcul automatique (36 mois / durée du bail) n'est qu'un DÉFAUT, sa saisie fait loi.

   On n'écrit QUE dans `tiers_roles.metadata` : ces conditions qualifient le LIEN entre
   cette caution et ce bail, pas la personne. La fiche tiers n'est pas touchée.

   Champ vide = on efface la valeur et on revient au calcul automatique. C'est voulu :
   sans ça, une saisie erronée serait irrattrapable autrement qu'en détachant. */
if ($action === 'update') {
    $idTiers = isset($_POST['id_tiers']) && ctype_digit((string)$_POST['id_tiers']) ? (int)$_POST['id_tiers'] : 0;
    if ($idTiers <= 0) { echo json_encode(['ok'=>false,'error'=>'id_tiers requis']); exit; }

    try {
        $st = $pdo->prepare("SELECT id, metadata FROM tiers_roles
                              WHERE id_tiers = ? AND role_code = 'caution' AND objet_type = 'bail'
                                AND id_objet = ? AND actif = 1 LIMIT 1");
        $st->execute([$idTiers, $bailId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'Cette caution n\'est pas rattachée à ce bail']); exit; }

        $meta = json_decode((string)($row['metadata'] ?? ''), true) ?: [];

        // Plafond : vide ⇒ retour au calcul ; sinon un entier positif, en euros.
        if (array_key_exists('montant_max', $_POST)) {
            $v = trim((string)$_POST['montant_max']);
            $v = str_replace([' ', "\u{202F}", ','], ['', '', '.'], $v);
            if ($v === '')            { $meta['montant_max'] = null; }
            elseif (!is_numeric($v))  { echo json_encode(['ok'=>false,'error'=>'Plafond : montant non numérique']); exit; }
            elseif ((float)$v < 0)    { echo json_encode(['ok'=>false,'error'=>'Plafond : montant négatif']); exit; }
            else                      { $meta['montant_max'] = (float)round((float)$v); }
        }
        // Durée : vide ⇒ retour à la durée du bail. Bornée à 99 — au-delà c'est une faute de frappe.
        if (array_key_exists('duree_ans', $_POST)) {
            $v = trim((string)$_POST['duree_ans']);
            if ($v === '')                                  { $meta['duree_ans'] = null; }
            elseif (!ctype_digit($v) || (int)$v < 1 || (int)$v > 99) {
                echo json_encode(['ok'=>false,'error'=>'Durée : indiquer un nombre d\'années entre 1 et 99']); exit;
            } else                                          { $meta['duree_ans'] = (int)$v; }
        }
        if (array_key_exists('caution_type', $_POST)) {
            $v = (string)$_POST['caution_type'];
            $meta['caution_type'] = in_array($v, ['solidaire','simple'], true) ? $v : null;
        }

        $up = $pdo->prepare("UPDATE tiers_roles SET metadata = ? WHERE id = ?");
        $up->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
    } catch (Throwable $e) {
        error_log('[bail_caution_action update] ' . $e->getMessage());
        echo json_encode(['ok'=>false,'error'=>'Enregistrement impossible : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* On renvoie CE QUE ÇA DONNE — plafond retenu, durée retenue, et l'alerte éventuelle.
       L'agent doit voir l'effet de sa saisie sur l'engagement réel, pas seulement « OK ». */
    require_once dirname(__DIR__) . '/inc/bail_cautions.php';
    $cx  = bail_caution_mention_ctx($pdo, $bailId, $idTiers);
    echo json_encode([
        'ok'      => true,
        'message' => 'Conditions de l\'engagement enregistrées.',
        'apercu'  => $cx['ok']
            ? ['plafond' => $cx['plafond'], 'source' => $cx['source_plafond'],
               'duree_ans' => $cx['duree_ans'], 'duree_source' => $cx['duree_source'], 'alerte' => $cx['alerte']]
            : ['erreur' => $cx['raison']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'detach') {
    $idTiers = isset($_POST['id_tiers']) && ctype_digit((string)$_POST['id_tiers']) ? (int)$_POST['id_tiers'] : 0;
    if ($idTiers <= 0) { echo json_encode(['ok'=>false,'error'=>'id_tiers requis']); exit; }
    $ok = bail_caution_detach($pdo, $bailId, $idTiers);
    echo json_encode(['ok'=>$ok, 'message'=>$ok ? 'Caution retirée du bail' : 'Aucun lien à retirer'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'action inconnue']);
