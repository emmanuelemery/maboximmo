<?php
/**
 * api/mandat_save.php — Enregistre les champs édités d'un mandat (registre).
 * POST : id (mandats_registre.id) + champs. Recalcule le statut depuis les dates.
 * Sécurité : admin / super admin + CSRF (form 'mandat_extraire').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/mandat_registre.php';
require_admin_or_super_admin();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('mandat_extraire');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$id  = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id requis']); exit; }

$nz   = fn($k) => trim((string)($_POST[$k] ?? '')) !== '' ? trim((string)$_POST[$k]) : null;  // null si vide
$num  = fn($k) => is_numeric($_POST[$k] ?? '') ? (float)$_POST[$k] : null;
$int  = fn($k) => ctype_digit((string)($_POST[$k] ?? '')) ? (int)$_POST[$k] : null;
$bool = fn($k) => !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0;
$date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;

$cols = [
    'numero_mandat'=>$nz('numero_mandat'), 'numero_mandat_alt'=>$nz('numero_mandat_alt'), 'mandataire'=>$nz('mandataire'),
    'mandant_noms'=>$nz('mandant_noms'), 'bien_designation'=>$nz('bien_designation'),
    'date_effet'=>$date('date_effet'), 'duree_initiale_ans'=>$int('duree_initiale_ans'),
    'duree_ferme'=>$bool('duree_ferme'), 'tacite_reconduction'=>$bool('tacite_reconduction'),
    'periode_reconduction_ans'=>$int('periode_reconduction_ans'), 'date_fin_theorique'=>$date('date_fin_theorique'),
    'preavis_resiliation_mois'=>$int('preavis_resiliation_mois'), 'date_resiliation'=>$date('date_resiliation'),
    'hono_gestion_taux_ht'=>$num('hono_gestion_taux_ht'), 'hono_gestion_taux_ttc'=>$num('hono_gestion_taux_ttc'),
    'hono_gestion_assiette'=>$nz('hono_gestion_assiette'), 'hono_location'=>$nz('hono_location'),
    'hono_location_remise_pct'=>$num('hono_location_remise_pct'), 'hono_contentieux'=>$nz('hono_contentieux'),
    'hono_declaration_fiscale_eur'=>$num('hono_declaration_fiscale_eur'), 'crg_periodicite'=>$nz('crg_periodicite'),
    'travaux_seuil_autorisation'=>$nz('travaux_seuil_autorisation'),
];

// Statut : recalcul auto depuis les dates, sauf si l'utilisateur force une valeur explicite.
$statutForce = in_array($_POST['statut'] ?? '', ['actif','termine','inconnu'], true) ? $_POST['statut'] : null;
$cols['statut'] = $statutForce ?: mr_calcul_statut($cols);

try {
    // Ne garder que les colonnes réellement présentes (résilient si une migration n'est pas encore jouée).
    $existing = $pdo->query("SHOW COLUMNS FROM mandats_registre")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_intersect_key($cols, array_flip($existing));
    if (!$cols) { echo json_encode(['ok'=>false,'error'=>'aucune colonne à mettre à jour']); exit; }

    $set = implode(', ', array_map(fn($c) => "`$c`=:$c", array_keys($cols)));
    $st = $pdo->prepare("UPDATE mandats_registre SET $set, updated_at=NOW() WHERE id=:id");
    foreach ($cols as $k=>$v) $st->bindValue(":$k", $v);
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
    echo json_encode(['ok'=>true, 'id'=>$id, 'statut'=>$cols['statut'] ?? null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'SQL : '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
