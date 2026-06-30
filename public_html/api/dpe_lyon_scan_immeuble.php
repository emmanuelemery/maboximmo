<?php
/**
 * api/dpe_lyon_scan_immeuble.php — Scanne (à la demande) le dossier OneDrive d'UN
 * immeuble LYON via Graph et renvoie les fichiers DPE détectés + le bien suggéré.
 *
 * GET : folder_id=<graph item id>  &  id_immeuble=INT
 * Réponse : { ok, lots:[...], files:[ {name, rel, download_url, niveau, bien_id, bien_ref} ] }
 *
 * Lecture seule. Aucun téléchargement/écriture : on renvoie l'URL pré-authentifiée
 * Graph (@microsoft.graph.downloadUrl) pour l'aperçu dans le modal.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_admin_or_super_admin();
require_once dirname(__DIR__) . '/inc/microsoft_graph.php';
require_once dirname(__DIR__) . '/inc/onedrive_classer.php'; // oc_diag_type()
require_once dirname(__DIR__) . '/inc/dpe_lyon_lib.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];

$folderId   = (string)($_GET['folder_id'] ?? '');
$idImmeuble = (int)($_GET['id_immeuble'] ?? 0);
if ($folderId === '' || $idImmeuble <= 0) exit(json_encode(['ok' => false, 'error' => 'paramètres manquants']));
if (!graph_is_configured()) exit(json_encode(['ok' => false, 'error' => 'Microsoft Graph non configuré']));

// Lots du bien (pour le sélecteur du modal + suggestion)
[, $immBiens] = dly_load_scope($pdo);
$biens = $immBiens[$idImmeuble] ?? [];

graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');

/** Parcourt récursivement (profondeur limitée) un dossier Graph et collecte les fichiers PDF. */
function dly_collect_pdfs(string $itemId, string $rel, int $depth, array &$out): void {
    if ($depth > 2) return;
    try { $children = graph_list_all_children_by_item_id($itemId); }
    catch (Throwable $e) { return; }
    foreach ($children as $it) {
        $name = (string)($it['name'] ?? '');
        if (isset($it['folder'])) {
            dly_collect_pdfs((string)$it['id'], ($rel === '' ? $name : $rel . '/' . $name), $depth + 1, $out);
        } elseif (isset($it['file'])) {
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') continue;
            $out[] = [
                'name'    => $name,
                'rel'     => ($rel === '' ? $name : $rel . '/' . $name),
                'url'     => (string)($it['@microsoft.graph.downloadUrl'] ?? ''),
                'item_id' => (string)($it['id'] ?? ''),
            ];
        }
    }
}

$pdfs = [];
dly_collect_pdfs($folderId, '', 0, $pdfs);

$files = [];
foreach ($pdfs as $p) {
    if (oc_diag_type($p['name']) !== 'dpe') continue; // ne garder que les DPE
    $sug = dly_suggest_bien($p['rel'], $biens);
    $files[] = [
        'name'         => $p['name'],
        'rel'          => $p['rel'],
        'download_url' => $p['url'],
        'item_id'      => $p['item_id'],
        'niveau'       => $sug['niveau'],
        'bien_id'      => $sug['bien'] ? (int)$sug['bien']['id'] : 0,
        'bien_ref'     => $sug['bien'] ? (string)$sug['bien']['reference_bien'] : '',
    ];
}

// Lots formatés pour info
$lots = [];
foreach ($biens as $b) {
    $lots[] = [
        'id'  => (int)$b['id'],
        'ref' => (string)$b['reference_bien'],
        'lot' => (string)($b['lot_principal'] ?: $b['numero_lot'] ?: ''),
        'has_dpe' => (bool)(int)$b['has_dpe'],
    ];
}

echo json_encode(['ok' => true, 'lots' => $lots, 'files' => $files], JSON_UNESCAPED_UNICODE);
