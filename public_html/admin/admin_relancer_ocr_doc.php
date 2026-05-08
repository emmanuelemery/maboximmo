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
// Choix du modèle : 'haiku' (test ~5 cts) ou 'sonnet' (prod ~25 cts, défaut)
$modele  = isset($_GET['modele']) && in_array($_GET['modele'], ['haiku', 'sonnet'], true)
    ? (string)$_GET['modele']
    : null; // null = défaut (Sonnet)

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
        $coutHaiku  = count($ids) * 5;
        $coutSonnet = count($ids) * 25;
        echo '<div class="box warn">🔔 Plan de relance : <strong>' . count($ids) . ' doc(s)</strong> en échec à relancer.<br>';
        echo '<table style="margin-top:8px;font-size:13px;border-collapse:collapse">';
        echo '<tr><td style="padding:4px 12px;border:1px solid #e5e7eb">🧪 Haiku 4.5 (test)</td><td style="padding:4px 12px;border:1px solid #e5e7eb"><strong>~' . $coutHaiku . ' cts</strong></td><td style="padding:4px 12px;border:1px solid #e5e7eb">qualité OK pour valider le pipeline</td></tr>';
        echo '<tr><td style="padding:4px 12px;border:1px solid #e5e7eb">🚀 Sonnet 4.6 (prod)</td><td style="padding:4px 12px;border:1px solid #e5e7eb"><strong>~' . $coutSonnet . ' cts</strong></td><td style="padding:4px 12px;border:1px solid #e5e7eb">précision max, recommandé pour l\'usage final</td></tr>';
        echo '</table></div>';
        echo '<p style="display:flex;gap:8px;flex-wrap:wrap"><a href="?failed=1&confirm=1&modele=haiku" class="btn" style="background:#16a34a" onclick="return confirm(\'Relancer ' . count($ids) . ' OCR avec Haiku (~' . $coutHaiku . ' cts) ?\')">🧪 Lancer avec Haiku (~' . $coutHaiku . ' cts)</a> ';
        echo '<a href="?failed=1&confirm=1&modele=sonnet" class="btn danger" onclick="return confirm(\'Relancer ' . count($ids) . ' OCR avec Sonnet (~' . $coutSonnet . ' cts) ?\')">🚀 Lancer avec Sonnet (~' . $coutSonnet . ' cts)</a> ';
        echo '<a href="" class="btn" style="background:#64748b">Annuler</a></p>';
        echo '</body></html>';
        exit;
    }

    foreach ($ids as $id) {
        echo "<div class='box'>Doc #{$id} : ";
        $r = rh_doc_societe_hook_apres_upload($pdo, $id, $modele);
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

// Détection : OCR déjà payé ? (ocr_json non null) → on peut re-appliquer le
// mapping structuré sans re-payer Sonnet. Économie ~25 cts par doc.
$ocrJsonExists = !empty($doc['ocr_json']);
$replayOnly    = isset($_GET['replay']) && $_GET['replay'] === '1';

if (!$confirm) {
    echo '<div class="box warn">';
    echo '<strong>Relancer le doc #' . $idDoc . '</strong><br>';
    echo 'Type : <code>' . htmlspecialchars((string)$doc['type_document']) . '</code><br>';
    echo 'Catégorie : <code>' . htmlspecialchars((string)$doc['categorie']) . '</code><br>';
    echo 'Fichier : <code>' . htmlspecialchars(basename((string)$doc['file_path'])) . '</code><br>';
    if ($ocrJsonExists) {
        echo 'OCR déjà payé : <strong style="color:#16a34a">✅ raw_json présent en BDD</strong> '
           . '(conf ' . (int)($doc['ocr_confidence'] ?? 0) . '%)<br>';
    } else {
        echo 'OCR déjà payé : <strong style="color:#dc2626">❌ aucun raw_json en BDD</strong><br>';
    }
    echo '</div>';

    if ($ocrJsonExists) {
        echo '<div class="box ok" style="margin-top:14px">';
        echo '<strong>💡 OCR déjà payé pour ce doc</strong> — tu peux re-appliquer le mapping structuré '
           . '(UPDATE rh_documents + societes + societes_couvertures) <strong>sans re-payer Sonnet</strong>. '
           . 'Coût : 0 cts.';
        echo '</div>';
        echo '<p><a href="?id=' . $idDoc . '&replay=1&confirm=1" class="btn" style="background:#16a34a">'
           . '♻️ RE-APPLIQUER MAPPING (gratuit)</a></p>';
    }

    echo '<p style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
    echo '<a href="?id=' . $idDoc . '&confirm=1&modele=haiku" class="btn" style="background:#16a34a">'
       . '🧪 Tester avec Haiku (~5 cts)</a> ';
    echo '<a href="?id=' . $idDoc . '&confirm=1&modele=sonnet" class="btn danger">'
       . '🚀 OCR Sonnet (~25 cts, max précision)</a> ';
    echo '<a href="" class="btn" style="background:#64748b">Annuler</a>';
    echo '</p>';
    echo '<p style="font-size:11px;color:#94a3b8;margin-top:8px">Haiku 4.5 = ~5x moins cher, qualité légèrement inférieure mais suffisante pour valider le pipeline. Sonnet 4.6 pour les saisies définitives.</p>';
    echo '</body></html>';
    exit;
}

// Note cross-env : si on est sur localhost et que le fichier est sur Hostinger,
// l'OCR va automatiquement le télécharger via HTTPS via la fonction
// agence_doc_ocr_resolve_local_or_fetch() (cf. agence_doc_officiel_ocr.php).
// Aucune action manuelle nécessaire — local marche partout.

// Mode REPLAY : OCR déjà payé, on re-applique juste le mapping structuré
if ($replayOnly && $ocrJsonExists) {
    echo "<div class='box'>♻️ Re-application du mapping structuré (sans OCR — économie 25 cts)...</div>";
    @ob_flush(); flush();

    // Re-décode le raw_json sauvegardé pour reconstruire les valeurs
    $rawJson = json_decode((string)$doc['ocr_json'], true);
    $text    = $rawJson['content'][0]['text'] ?? '';
    $data    = $text !== '' ? mbi_supports_ia_extract_json($text) : null;

    if (!$data) {
        echo '<div class="box fail">❌ Impossible de re-extraire les données depuis ocr_json. Le raw_json est peut-être corrompu.</div>';
        echo '<p><a href="?id=' . $idDoc . '&confirm=1" class="btn danger">🚀 Lancer un OCR neuf (~25 cts)</a></p>';
        echo '</body></html>'; exit;
    }

    // Détermine le type OCR à partir du type_document
    $ocrType = rh_doc_societe_type_to_ocr((string)$doc['type_document']);
    if ($ocrType === null) {
        echo '<div class="box fail">❌ Type document non mappable vers un OCR type : <code>'
           . htmlspecialchars((string)$doc['type_document']) . '</code></div>';
        echo '</body></html>'; exit;
    }

    // Normalise les données et UPDATE rh_documents (champs principaux)
    $normalised = agence_doc_ocr_normalize($data, $ocrType);
    try {
        $up = $pdo->prepare("
            UPDATE rh_documents SET
              numero            = :numero,
              emetteur          = :emetteur,
              montant_garantie  = :montant,
              date_emission     = :date_em,
              date_validite     = :date_val,
              ocr_at            = :ocr_at
            WHERE id = :id
        ");
        $up->execute([
            ':numero'   => $normalised['numero']   ?? null,
            ':emetteur' => $normalised['emetteur'] ?? null,
            ':montant'  => $normalised['montant_garantie'] ?? null,
            ':date_em'  => $normalised['date_emission']    ?? null,
            ':date_val' => $normalised['date_validite']    ?? null,
            ':ocr_at'   => date('Y-m-d H:i:s'),
            ':id'       => $idDoc,
        ]);
    } catch (Throwable $e) {
        echo '<div class="box fail">❌ Erreur UPDATE rh_documents : ' . htmlspecialchars($e->getMessage()) . '</div>';
        echo '</body></html>'; exit;
    }

    // Propage sur societes + societes_couvertures
    $idSoc = (int)($doc['id_societe'] ?? 0);
    if ($idSoc > 0 && $doc['categorie'] === 'societe') {
        rh_doc_societe_ecrire_societe($pdo, $idSoc, $idDoc, $ocrType);
    }

    echo '<div class="box ok">';
    echo '<strong>✅ Mapping re-appliqué sans coût IA</strong><br>';
    echo 'Émetteur : <code>' . htmlspecialchars((string)($normalised['emetteur'] ?? '—')) . '</code><br>';
    echo 'Numéro : <code>' . htmlspecialchars((string)($normalised['numero'] ?? '—')) . '</code><br>';
    echo 'Validité : <code>' . htmlspecialchars((string)($normalised['date_validite'] ?? '—')) . '</code>';
    echo '</div>';
    echo '<p><a href="" class="btn">↺ Retour à la liste</a></p>';
    echo '</body></html>';
    exit;
}

// Mode OCR neuf : appel Sonnet (~25 cts) ou Haiku (~5 cts test)
$modeleLabel = match ($modele) {
    'haiku'  => 'Haiku 4.5 (~5 cts)',
    'sonnet' => 'Sonnet 4.6 (~25 cts)',
    default  => 'Sonnet 4.6 (par défaut, ~25 cts)',
};
echo "<div class='box'>Lancement OCR sur doc #{$idDoc} avec modèle : <strong>" . htmlspecialchars($modeleLabel) . "</strong>...</div>";
@ob_flush(); flush();

$res = rh_doc_societe_hook_apres_upload($pdo, $idDoc, $modele);

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
