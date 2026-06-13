<?php
// api/transaction_dossier_get.php — Lecture d'un dossier de vente (agrégat par référence).
// GET : id (dossier) OU id_bien  →  { ok, dossier, acteurs, nb_documents }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$idDossier = (int)($_GET['id'] ?? 0);
$idBien    = (int)($_GET['id_bien'] ?? 0);

$dossier = null;
if ($idDossier > 0)      $dossier = dv_get($pdo, $idDossier);
elseif ($idBien > 0)     $dossier = dv_get_by_bien($pdo, $idBien);

if (!$dossier) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }
$idDossier = (int)$dossier['id'];

// Scope société (super admin / manager bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    $acteurs = array_map(static function(array $a): array {
        $nom = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')));
        return [
            'id_tiers'  => (int)$a['id_tiers'],
            'role_code' => $a['role_code'],
            'nom'       => $nom !== '' ? $nom : ('Tiers #' . $a['id_tiers']),
            'email'     => $a['email'] ?: null,
            'telephone' => $a['telephone'] ?: null,
        ];
    }, dv_acteurs($pdo, $idDossier));

    echo json_encode([
        'ok'           => true,
        'dossier'      => [
            'id'         => $idDossier,
            'id_bien'    => (int)$dossier['id_bien'],
            'id_mandat'  => $dossier['id_mandat'] !== null ? (int)$dossier['id_mandat'] : null,
            'etape'      => $dossier['etape'],
            'source'     => $dossier['source'],
            'date_estimation' => $dossier['date_estimation'],
            'date_mandat'     => $dossier['date_mandat'],
            'date_compromis'  => $dossier['date_compromis'],
            'date_acte'       => $dossier['date_acte'],
        ],
        'acteurs'      => $acteurs,
        'nb_documents' => gdl_documents_count_for_entity($pdo, 'DOSSIER', $idDossier),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_get] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
