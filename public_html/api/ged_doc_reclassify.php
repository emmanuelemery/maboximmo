<?php
/**
 * api/ged_doc_reclassify.php — Reclasse un document GED en 3 niveaux : ENTITÉ + LIBELLÉ + DATE.
 * Le moteur MaBoxOffice régénère le nom GED complet (société/agence/métier dérivés de l'entité).
 *
 * POST JSON : { ged_id, entity_type?, entity_id?, libelle?, date? (AAAA-MM-JJ), csrf }
 *   - entity_type/entity_id absents → on conserve l'entité principale actuelle.
 * Réponse : { ok, name, chain }
 *
 * Réservé admin / super-admin (même politique que ged_rename). Aucune suppression de fichier.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/maboxoffice_match.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
function gdr_out(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gdr_out(['ok'=>false,'error'=>'POST requis'], 405);
$in = json_decode((string)file_get_contents('php://input'), true); if (is_array($in)) $_POST = array_merge($_POST, $in);

// CSRF standard (form 'default') ou legacy.
$csrfR = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfStd = function_exists('csrf_token') ? csrf_token('default') : '';
$csrfLeg = (string)($_SESSION['csrf_token'] ?? '');
if (($csrfStd !== '' || $csrfLeg !== '') && !(($csrfStd !== '' && hash_equals($csrfStd, $csrfR)) || ($csrfLeg !== '' && hash_equals($csrfLeg, $csrfR)))) {
    gdr_out(['ok'=>false,'error'=>'CSRF invalide'], 403);
}
$isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)(current_role_id() ?? 0), [1,7], true);
if (!$isAdmin) gdr_out(['ok'=>false,'error'=>'Réservé administrateurs.'], 403);

$pdo = $GLOBALS['pdo'];
$gedId   = (int)($_POST['ged_id'] ?? 0);
$entType = strtoupper(trim((string)($_POST['entity_type'] ?? '')));
$entId   = (int)($_POST['entity_id'] ?? 0);
$libelle = trim((string)($_POST['libelle'] ?? ''));
$dateDoc = trim((string)($_POST['date'] ?? ''));
$typeDoc = trim((string)($_POST['type_doc'] ?? ''));   // code type GED (position 8) — facultatif
if ($gedId <= 0) gdr_out(['ok'=>false,'error'=>'ged_id requis'], 400);

// Doc + scope société.
$st = $pdo->prepare("SELECT id, tenant_id, societe_id, agence_id, name_file, document_type, metadata, created_at
                       FROM ged_documents WHERE id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1");
$st->execute([$gedId]); $doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) gdr_out(['ok'=>false,'error'=>'Document introuvable'], 404);
if (!is_super_admin() && !empty($doc['tenant_id']) && (int)$doc['tenant_id'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    gdr_out(['ok'=>false,'error'=>'Hors périmètre société'], 403);
}

// Entité cible : fournie, sinon lien principal actuel.
if ($entType === '' || $entId <= 0) {
    $ql = $pdo->prepare("SELECT entity_type, entity_id FROM ged_document_links WHERE document_id = ? AND relation_type = 'main' ORDER BY id DESC LIMIT 1");
    $ql->execute([$gedId]); if ($l = $ql->fetch(PDO::FETCH_ASSOC)) { $entType = strtoupper((string)$l['entity_type']); $entId = (int)$l['entity_id']; }
}
if ($entType === '' || $entId <= 0) gdr_out(['ok'=>false,'error'=>'Aucune entité (attribue le document à une entité).'], 400);

// Régénère le nom via le moteur MBO.
$d = [
    'fichier_nom'      => (string)($doc['name_file'] ?? 'doc.pdf'),
    'tenant_id'        => (int)($doc['tenant_id'] ?? 0),
    'created_at'       => (string)($doc['created_at'] ?? 'now'),   // horodatage (position 11) stable
    'mbo_entity_type'  => $entType,
    'mbo_entity_id'    => $entId,
    'mbo_type_propose' => strtolower($typeDoc !== '' ? $typeDoc : (string)($doc['document_type'] ?? '')),
    'mbo_libelle'      => $libelle,
    'mbo_ref_salaire'  => $dateDoc,   // position 9 = date métier (JJ-MM-AAAA)
];
try { $newName = mbo_build_ged_name($pdo, $d, true); }
catch (Throwable $e) { gdr_out(['ok'=>false,'error'=>'Nom non régénéré : '.$e->getMessage()], 500); }
if (trim((string)$newName) === '') gdr_out(['ok'=>false,'error'=>'Nom vide']);
$canon = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', pathinfo($newName, PATHINFO_FILENAME)) ?: 'DOC');

try {
    $pdo->beginTransaction();
    // 1. Nom (+ type de document si fourni).
    if ($typeDoc !== '') {
        $pdo->prepare("UPDATE ged_documents SET name_display = ?, name_canonical = ?, document_type = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newName, $canon, strtolower($typeDoc), $gedId]);
    } else {
        $pdo->prepare("UPDATE ged_documents SET name_display = ?, name_canonical = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newName, $canon, $gedId]);
    }
    // 2. Métadonnées (libellé + date conservés pour réédition).
    try {
        $meta = json_decode((string)($doc['metadata'] ?? ''), true); if (!is_array($meta)) $meta = [];
        $meta['extra'] = is_array($meta['extra'] ?? null) ? $meta['extra'] : [];
        $meta['extra']['libelle'] = $libelle; $meta['extra']['date_doc'] = $dateDoc;
        $pdo->prepare("UPDATE ged_documents SET metadata = ? WHERE id = ?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $gedId]);
    } catch (Throwable) {}
    // 3. Liens : remplace le lien principal si l'entité a changé.
    $tenant = (int)($doc['tenant_id'] ?? 0) ?: 1;
    $norm = static function (string $t): string { $t = strtoupper(trim($t)); return $t === 'IMMEUBLE' ? 'IMB' : (($t === 'LOCATION' || $t === 'LOC') ? 'BAIL' : ($t === 'TRS' ? 'TIERS' : $t)); };
    $entType = $norm($entType);
    $links = mbo_ged_links_for_entity($pdo, $entType, $entId);
    // ⚠️ L'ENTITÉ CHOISIE doit rester le lien 'main'. Sinon mbo_ged_links_for_entity peut la
    //    rétrograder en 'reference' (ex. IMB devient 'reference' au profit du TIERS proprio) et
    //    le doc DISPARAÎT de la liste de son entité (bascule dans « mentions »).
    $foundChosen = false;
    foreach ($links as &$lk) {
        if ($norm((string)$lk['entity_type']) === $entType && (int)$lk['entity_id'] === $entId) { $lk['relation_type'] = 'main'; $foundChosen = true; }
        elseif (($lk['relation_type'] ?? '') === 'main') { $lk['relation_type'] = 'reference'; }
    }
    unset($lk);
    if (!$foundChosen) array_unshift($links, ['entity_type' => $entType, 'entity_id' => $entId, 'relation_type' => 'main', 'is_validated' => 1]);
    if ($links) {
        $pdo->prepare("DELETE FROM ged_document_links WHERE document_id = ? AND relation_type = 'main'")->execute([$gedId]);
        $ins = $pdo->prepare("INSERT INTO ged_document_links (tenant_id, document_id, entity_type, entity_id, relation_type, is_validated, validated_at, created_at)
                              VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE is_validated = 1, validated_at = NOW()");
        foreach ($links as $lk) $ins->execute([$tenant, $gedId, (string)$lk['entity_type'], (int)$lk['entity_id'], (string)($lk['relation_type'] ?? 'reference')]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    gdr_out(['ok'=>false,'error'=>'Échec : '.$e->getMessage()], 500);
}

$chainParts = [];
foreach (mbo_entity_chain($pdo, $entType, $entId) as $lk) $chainParts[] = (string)($lk['label'] ?? '');
gdr_out(['ok'=>true, 'name'=>$newName, 'chain'=>implode(' › ', array_filter($chainParts))]);
