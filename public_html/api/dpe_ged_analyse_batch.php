<?php
/**
 * api/dpe_ged_analyse_batch.php — Analyse GRATUITE (regex) des DPE déjà en GED mais
 * non renseignés (aucune lettre sur le bien ni dans dpe_diags). AUCUN appel IA / OCR.
 *
 * GET ?mode=list         → { ok, items:[ {bien_id, doc_id, ref} ] }
 * GET ?mode=one&bien_id&doc_id → extrait (regex) + enregistre → { ok, classe, n, reason? }
 *
 * Accessible aux utilisateurs connectés. Lecture du PDF GED via mr_ged_doc_path().
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/dpe_service.php';        // BienImportParser, DpeImportParser, dpe_enregistrer, dpe_score
require_once dirname(__DIR__) . '/inc/mandat_registre.php';   // mr_ged_doc_path()
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];
$mode = (string)($_GET['mode'] ?? 'list');

// Biens ayant un DPE en GED, MAIS sans lettre exploitable (ni bien, ni diag).
$baseSql = "
  FROM biens b
  WHERE (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
    AND (b.dpe_classe IS NULL OR b.dpe_classe = '')
    AND NOT EXISTS (SELECT 1 FROM dpe_diags d WHERE d.id_bien=b.id
                      AND d.dpe_classe REGEXP '^[A-Ga-g]$')
    AND EXISTS (SELECT 1 FROM ged_documents gd
                  JOIN ged_document_links gdl ON gdl.document_id=gd.id
                 WHERE gd.status='active' AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
                   AND UPPER(gd.document_type) LIKE '%DPE%')";

if ($mode === 'list') {
    $rows = $pdo->query("SELECT b.id AS bien_id, b.reference_bien AS ref,
              (SELECT gd.id FROM ged_documents gd
                 JOIN ged_document_links gdl ON gdl.document_id=gd.id
                WHERE gd.status='active' AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
                  AND UPPER(gd.document_type) LIKE '%DPE%'
                ORDER BY gd.id DESC LIMIT 1) AS doc_id
            {$baseSql}
            ORDER BY b.id")->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    foreach ($rows as $r) { if (!empty($r['doc_id'])) $items[] = ['bien_id'=>(int)$r['bien_id'],'doc_id'=>(int)$r['doc_id'],'ref'=>(string)$r['ref']]; }
    echo json_encode(['ok'=>true, 'items'=>$items, 'count'=>count($items)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'one') {
    $bienId = (int)($_GET['bien_id'] ?? 0);
    $docId  = (int)($_GET['doc_id'] ?? 0);
    if ($bienId <= 0 || $docId <= 0) exit(json_encode(['ok'=>false,'error'=>'paramètres manquants']));

    $path = function_exists('mr_ged_doc_path') ? mr_ged_doc_path($pdo, $docId) : '';
    if ($path === '' || !is_file($path)) exit(json_encode(['ok'=>false,'error'=>'PDF GED introuvable','reason'=>'fichier']));

    try {
        $texte = (string) BienImportParser::extractText($path);
        if (mb_strlen(trim($texte)) < 200) {
            // PDF scanné → nécessiterait l'OCR/IA (payant) : on NE fait rien ici.
            exit(json_encode(['ok'=>false,'reason'=>'scanne','error'=>'PDF scanné (extraction gratuite impossible)']));
        }
        $rx = DpeImportParser::parse($texte);
        $fields = (array)($rx['fields'] ?? []);
        if (!$fields) exit(json_encode(['ok'=>false,'reason'=>'vide','error'=>'Aucun champ détecté par regex']));

        $res = dpe_enregistrer($pdo, $bienId, $fields, [
            'method' => 'regex_batch_ged',
            'score'  => dpe_score($fields),
            'ged_document_id' => $docId,
        ]);
        if (empty($res['ok'])) exit(json_encode(['ok'=>false,'error'=>$res['error'] ?? 'enregistrement échoué']));

        echo json_encode([
            'ok'     => true,
            'classe' => $fields['dpe_classe'] ?? '',
            'vierge' => !empty($fields['dpe_vierge']) ? 1 : 0,
            'n'      => count($fields),
            'diag_id'=> (int)($res['diag_id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'mode inconnu']);
