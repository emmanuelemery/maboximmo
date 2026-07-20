<?php
declare(strict_types=1);
/**
 * api/maboxoffice_purge_noise.php — Nettoie le bruit DÉJÀ capturé (signatures, logos,
 * images inline ré-attachées). Superadmin. Applique la même règle que le filtre de
 * capture (mbo_attachment_is_useful) aux docs non classés de l'inbox.
 *
 * ?dry=1 (défaut) : liste seulement. ?dry=0 : supprime réellement (doc + captures).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/maboxoffice_graph_mail.php'; // mbo_attachment_is_useful
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
if (!is_super_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit; }

$pdo = $GLOBALS['pdo'];
$tenant = (int)($_SESSION['id_societe'] ?? 0);
$dry = (string)($_REQUEST['dry'] ?? '1') !== '0';

$scope = 'WHERE mbo_classe_at IS NULL' . ($tenant > 0 ? ' AND tenant_id = ' . $tenant : '');
$rows = $pdo->query("SELECT id, fichier_nom, mime_type, taille_octets, fichier_chemin FROM fluxbox_documents $scope")->fetchAll(PDO::FETCH_ASSOC);

$noise = [];
foreach ($rows as $r) {
    $useful = mbo_attachment_is_useful([
        'name'         => (string)$r['fichier_nom'],
        'contentType'  => (string)$r['mime_type'],
        'size'         => (int)$r['taille_octets'],
        'isInline'     => false, // l'info inline n'est pas stockée → on se fie au nom + taille image
        'contentBytes' => 'x',
    ]);
    if (!$useful) $noise[] = $r;
}

$deleted = 0;
if (!$dry && $noise) {
    $ids = array_map(fn($r) => (int)$r['id'], $noise);
    $in = implode(',', array_fill(0, count($ids), '?'));
    // FILET : sauve une copie durable pour tout doc GED encore adossé, avant purge.
    require_once __DIR__ . '/../inc/ged_durable.php';
    foreach ($ids as $fid) { try { ged_ensure_durable_from_fluxbox($pdo, (int)$fid); } catch (Throwable) {} }
    // supprime le fichier physique
    foreach ($noise as $r) { $p = ged_flux_src_abspath((string)$r['fichier_chemin']); if ($p !== '' && is_file($p)) @unlink($p); }
    $pdo->prepare("DELETE FROM mbo_mail_captures WHERE fluxbox_document_id IN ($in)")->execute($ids);
    $deleted = $pdo->prepare("DELETE FROM fluxbox_documents WHERE id IN ($in)")->execute($ids) ? count($ids) : 0;
}

echo json_encode([
    'ok'=>true, 'dry_run'=>$dry, 'candidats'=>count($noise), 'supprimes'=>$deleted,
    'liste'=>array_map(fn($r)=>['id'=>(int)$r['id'],'nom'=>$r['fichier_nom'],'mime'=>$r['mime_type'],'taille'=>(int)$r['taille_octets']], $noise),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
