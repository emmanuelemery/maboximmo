<?php
declare(strict_types=1);

/**
 * ADMIN — Relance l'OCR Sonnet sur un rh_document existant
 *
 * Cas d'usage :
 *   - L'OCR a échoué au moment de l'upload (timeout API, erreur transitoire,
 *     PDF avec problème de structure → confidence=0, ocr_at NULL)
 *   - Le hook n'a pas tourné (doc uploadé avant son installation)
 *   - On a modifié le prompt OCR et on veut re-extraire avec la nouvelle version
 *
 * URL :
 *   /admin/admin_relancer_ocr_doc.php                  → liste les docs avec OCR à relancer
 *   /admin/admin_relancer_ocr_doc.php?id=147           → relance UN doc précis
 *   /admin/admin_relancer_ocr_doc.php?id=147&confirm=1 → exécute (sinon affiche le plan)
 *   /admin/admin_relancer_ocr_doc.php?failed=1&confirm=1 → relance TOUS les docs en échec
 *
 * Coût : ~25 centimes par doc relancé (Claude Sonnet 4.6 + PDF natif).
 * Le fichier source n'est pas re-uploadé — Sonnet ré-analyse le PDF déjà sur disque.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_doc_societe_ocr_hook.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin (role=1)</h1>');
}

$pdo = $GLOBALS['pdo'];

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Relance OCR</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:1100px;margin:24px auto;padding:0 20px;line-height:1.5}'
   . 'h1{color:#0f172a}.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:12px}'
   . '.ok{border-left:4px solid #16a34a;background:#f0fdf4}'
   . '.fail{border-left:4px solid #dc2626;background:#fef2f2}'
   . '.warn{border-left:4px solid #f59e0b;background:#fffbeb}'
   . '.btn{display:inline-block;padding:8px 14px;border-radius:8px;background:#0ea5e9;color:#fff;text-decoration:none;font-weight:700;font-size:13px;margin:4px}'
   . '.btn.danger{background:#dc2626}'
   . 'table{border-collapse:collapse;width:100%;margin:10px 0}td,th{border:1px solid #cbd5e1;padding:6px 10px;font-size:13px;text-align:left}'
   . 'th{background:#f1f5f9}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11px}'
   . '</style></head><body>';

echo '<h1>🔄 Relance OCR Sonnet sur rh_documents</h1>';

$idDoc   = (int)($_GET['id']      ?? 0);
$failed  = (bool)($_GET['failed'] ?? 0);
$confirm = (bool)($_GET['confirm'] ?? 0);

// Vue par défaut : liste des docs en échec
if (!$idDoc && !$failed) {
    $st = $pdo->query("
        SELECT d.id, d.id_societe, d.id_agence, d.type_document, d.categorie,
               d.emetteur, d.ocr_at, d.ocr_confidence, d.upload_date,
               s.nom AS societe_nom
        FROM rh_documents d
        LEFT JOIN societes s ON s.id = d.id_societe
        WHERE d.actif = 1
          AND d.categorie IN ('societe', 'agence')
          AND (d.ocr_at IS NULL OR d.ocr_confidence = 0)
        ORDER BY d.upload_date DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo '<div class="box ok"><strong>✅ Aucun doc en échec OCR.</strong> Tous les docs officiels ont été OCR-isés avec succès.</div>';
    } else {
        echo '<div class="box warn">' . count($rows) . ' doc(s) avec OCR en échec ou non lancé. Tu peux les relancer un par un, ou tous d\'un coup.</div>';
        echo '<p><a href="?failed=1" class="btn">Voir le plan de relance globale</a></p>';
        echo '<table><tr><th>ID</th><th>Société</th><th>Type</th><th>Catégorie</th><th>Uploadé le</th><th>OCR conf</th><th>Action</th></tr>';
        foreach ($rows as $r) {
            $conf = (int)$r['ocr_confidence'];
            $confDisp = $r['ocr_at'] ? "{$conf}%" : 'jamais lancé';
            echo '<tr>'
               . '<td>' . (int)$r['id'] . '</td>'
               . '<td>' . htmlspecialchars((string)$r['societe_nom']) . '</td>'
               . '<td><code>' . htmlspecialchars((string)$r['type_document']) . '</code></td>'
               . '<td>' . htmlspecialchars((string)$r['categorie']) . '</td>'
               . '<td>' . htmlspecialchars((string)$r['upload_date']) . '</td>'
               . '<td>' . htmlspecialchars($confDisp) . '</td>'
               . '<td><a href="?id=' . (int)$r['id'] . '" class="btn">🔄 Relancer</a></td>'
               . '</tr>';
        }
        echo '</table>';
    }
    echo '</body></html>';
    exit;
}

// Mode "relance globale"
if ($failed) {
    $st = $pdo->query("
        SELECT id FROM rh_documents
        WHERE actif = 1
          AND categorie IN ('societe', 'agence')
          AND (ocr_at IS NULL OR ocr_confidence = 0)
        ORDER BY id ASC
    ");
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (!$confirm) {
        echo '<div class="box warn">🔔 Plan de relance : <strong>' . count($ids) . ' doc(s)</strong> en échec à relancer.<br>';
        echo 'Coût estimé : <strong>' . count($ids) . ' × ~25 centimes = ~' . (count($ids) * 25) . ' centimes</strong> (Sonnet 4.6).</div>';
        echo '<p><a href="?failed=1&confirm=1" class="btn danger" onclick="return confirm(\'Relancer les ' . count($ids) . ' OCR ?\')">🚀 LANCER MAINTENANT</a> ';
        echo '<a href="" class="btn" style="background:#64748b">Annuler</a></p>';
        echo '</body></html>';
        exit;
    }

    foreach ($ids as $id) {
        echo "<div class='box'>Doc #{$id} : ";
        $r = rh_doc_societe_hook_apres_upload($pdo, $id);
        if (!empty($r['ocr_ok'])) {
            echo "<span style='color:#16a34a;font-weight:700'>✅ OCR OK</span> · type={$r['type_mappe']}";
        } else {
            echo "<span style='color:#dc2626;font-weight:700'>❌ Échec</span> · " . htmlspecialchars((string)($r['ocr_erreur'] ?? 'inconnu'));
        }
        echo '</div>';
        @ob_flush(); flush();
    }
    echo '<p><a href="" class="btn">↺ Recharger la liste</a> ';
    echo '<a href="admin_migrations_apply_all.php" class="btn">Lancer le backfill societes</a></p>';
    echo '</body></html>';
    exit;
}

// Mode "relance UN doc"
$st = $pdo->prepare("SELECT * FROM rh_documents WHERE id = :id AND actif = 1 LIMIT 1");
$st->execute([':id' => $idDoc]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    echo '<div class="box fail">❌ Doc introuvable (id=' . $idDoc . ')</div></body></html>'; exit;
}

if (!$confirm) {
    echo '<div class="box warn">';
    echo '<strong>Relancer l\'OCR sur le doc #' . $idDoc . '</strong><br>';
    echo 'Type : <code>' . htmlspecialchars((string)$doc['type_document']) . '</code><br>';
    echo 'Catégorie : <code>' . htmlspecialchars((string)$doc['categorie']) . '</code><br>';
    echo 'Fichier : <code>' . htmlspecialchars(basename((string)$doc['file_path'])) . '</code><br>';
    echo 'Coût estimé : ~25 centimes (Claude Sonnet 4.6)';
    echo '</div>';
    echo '<p><a href="?id=' . $idDoc . '&confirm=1" class="btn danger">🚀 LANCER</a> ';
    echo '<a href="" class="btn" style="background:#64748b">Annuler</a></p>';
    echo '</body></html>';
    exit;
}

// Note cross-env : si on est sur localhost et que le fichier est sur Hostinger,
// l'OCR va automatiquement le télécharger via HTTPS via la fonction
// agence_doc_ocr_resolve_local_or_fetch() (cf. agence_doc_officiel_ocr.php).
// Aucune action manuelle nécessaire — local marche partout.

// Exécution
echo "<div class='box'>Lancement OCR sur doc #{$idDoc}...</div>";
@ob_flush(); flush();

$res = rh_doc_societe_hook_apres_upload($pdo, $idDoc);

if (!empty($res['ocr_ok'])) {
    echo '<div class="box ok">';
    echo '<strong>✅ OCR réussi</strong><br>';
    echo 'Type mappé : <code>' . htmlspecialchars((string)$res['type_mappe']) . '</code><br>';
    echo 'Société mise à jour : ' . (int)($res['societe_mise_a_jour'] ?? $res['agences_repliquees'] ?? 0);
    echo '</div>';
} else {
    echo '<div class="box fail">';
    echo '<strong>❌ Échec OCR</strong><br>';
    echo 'Erreur : <code>' . htmlspecialchars((string)($res['ocr_erreur'] ?? 'inconnu')) . '</code>';
    echo '</div>';
}

echo '<p><a href="" class="btn">↺ Retour à la liste</a> ';
echo '<a href="admin_migrations_apply_all.php" class="btn">Relancer backfill societes</a></p>';
echo '</body></html>';
