<?php
declare(strict_types=1);
/**
 * tools/ged_fix_immeuble_docs.php — RÉPARATION : repromouvoir en 'main' les documents
 * d'un immeuble qui ont été rétrogradés en 'reference' par l'ancien bug de reclassement
 * (mbo_ged_links_for_entity mettait le TIERS proprio en 'main' et l'immeuble en 'reference').
 *
 * Ne touche QUE les docs « immeuble-primaires » (aucun lien BIEN 'main') → ne casse pas les
 * docs de bien (bail/diag) qui mentionnent légitimement l'immeuble.
 *
 * Usage navigateur (super-admin) :
 *   /tools/ged_fix_immeuble_docs.php?imm=848        → APERÇU (dry-run, ne modifie rien)
 *   /tools/ged_fix_immeuble_docs.php?imm=848&go=1   → APPLIQUE
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
header('Content-Type: text/plain; charset=utf-8');

if (!(function_exists('is_super_admin') && is_super_admin())) { http_response_code(403); echo "Réservé super-admin.\n"; exit; }

$pdo = $GLOBALS['pdo'];
$imm = isset($_GET['imm']) && ctype_digit((string)$_GET['imm']) ? (int)$_GET['imm'] : 0;
$go  = (($_GET['go'] ?? '') === '1');
if ($imm <= 0) { echo "Paramètre ?imm=<id_immeuble> requis.\n"; exit; }

echo "== Réparation docs immeuble #$imm — " . ($go ? "APPLICATION" : "APERÇU (dry-run)") . " ==\n\n";

// Docs liés à cet immeuble en 'reference', SANS lien BIEN 'main' (= immeuble-primaires démotés).
$sql = "SELECT gd.id, gd.name_display, gd.document_type
        FROM ged_documents gd
        JOIN ged_document_links l ON l.document_id = gd.id
             AND l.entity_type IN ('IMB','IMMEUBLE') AND l.entity_id = ? AND l.relation_type = 'reference'
        WHERE gd.status = 'active'
          AND NOT EXISTS (
              SELECT 1 FROM ged_document_links b
              WHERE b.document_id = gd.id AND b.entity_type = 'BIEN' AND b.relation_type = 'main'
          )
        GROUP BY gd.id
        ORDER BY gd.id";
$st = $pdo->prepare($sql);
$st->execute([$imm]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "Aucun document à réparer (rien de rétrogradé à tort).\n"; exit; }

$n = 0;
foreach ($rows as $r) {
    $docId = (int)$r['id'];
    echo sprintf("• doc #%d [%s] %s\n", $docId, $r['document_type'] ?: '-', $r['name_display'] ?: '');
    if ($go) {
        // Démote les autres 'main' → 'reference', puis promeut l'immeuble en 'main'.
        $pdo->prepare("UPDATE ged_document_links SET relation_type='reference' WHERE document_id=? AND relation_type='main'")->execute([$docId]);
        $pdo->prepare("UPDATE ged_document_links SET relation_type='main', is_validated=1
                       WHERE document_id=? AND entity_type IN ('IMB','IMMEUBLE') AND entity_id=?")->execute([$docId, $imm]);
        $n++;
    }
}

echo "\n" . ($go ? "✅ $n document(s) repromu(s) en 'main' sur l'immeuble #$imm.\n"
               : count($rows) . " document(s) seraient repromus. Ajoute &go=1 pour appliquer.\n");
