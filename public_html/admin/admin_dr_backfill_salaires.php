<?php
declare(strict_types=1);
/**
 * admin/admin_dr_backfill_salaires.php — REPRISE one-shot.
 *
 * Importe dans le workflow Salaires (rh_salaire_workflow_log) les pièces AGENCE
 * déjà DÉPOSÉES via un lien « Demander un document » créé AVANT l'ajout du pont
 * (donc sans doc_type/étape). Idempotent : ne réimporte pas deux fois le même
 * fichier pour une même agence/mois/type.
 *
 * Usage :
 *   /admin/admin_dr_backfill_salaires.php?token=<token>&mois=2026-06&type=import_bulletins
 *   - token : celui du lien de dépôt (?t=... dans l'URL de la page de dépôt)
 *   - mois  : AAAA-MM (défaut : période de la pièce si présente, sinon requis)
 *   - type  : import_bulletins (défaut) | import_projet
 *   - &go=1 : exécute réellement (sinon : simulation / aperçu)
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/document_requests.php';
require_once __DIR__ . '/../inc/rh_salaire_workflow.php';
require_login();

$pdo = $GLOBALS['pdo'];
if ((int)($_SESSION['id_role'] ?? 0) !== 1) { http_response_code(403); exit('Réservé aux administrateurs.'); }

header('Content-Type: text/html; charset=utf-8');
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$token = trim((string)($_GET['token'] ?? ''));
$moisG = trim((string)($_GET['mois'] ?? ''));           // AAAA-MM
$type  = (string)($_GET['type'] ?? 'import_bulletins');
if (!in_array($type, ['import_bulletins','import_projet'], true)) $type = 'import_bulletins';
$go    = !empty($_GET['go']);

echo '<div style="font-family:system-ui;max-width:900px;margin:24px auto;padding:0 16px">';
echo '<h2>Reprise workflow Salaires ' . ($go ? '— EXÉCUTION' : '— simulation') . '</h2>';

if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
    echo '<p style="color:#b91c1c">Paramètre <code>token</code> manquant ou invalide.</p></div>'; exit;
}
$req = dr_get_by_token($pdo, $token);
if (!$req) { echo '<p style="color:#b91c1c">Demande introuvable pour ce token.</p></div>'; exit; }

echo '<p>Demande #' . (int)$req['id'] . ' — « ' . e($req['titre']) . ' ». Étape cible : <b>' . e($type) . '</b>.</p>';
echo '<table cellpadding="6" style="border-collapse:collapse;width:100%;font-size:13px">';
echo '<tr style="background:#f1f5f9;text-align:left"><th>Pièce</th><th>Agence</th><th>Mois</th><th>Fichier</th><th>Action</th></tr>';

$done = 0; $skip = 0;
foreach (dr_items($pdo, (int)$req['id']) as $it) {
    if (($it['status'] ?? '') !== 'recu') continue;
    if (strtoupper((string)($it['entity_type'] ?? '')) !== 'AGENCE') continue;
    $idAgence = (int)($it['entity_id'] ?? 0);
    if ($idAgence <= 0) continue;

    // Mois : période de la pièce (AAAA-MM) sinon paramètre global.
    $per = (string)($it['period'] ?? '');
    $moisSrc = preg_match('/^\d{4}-\d{2}$/', $per) ? $per : $moisG;
    if (!preg_match('/^\d{4}-\d{2}$/', $moisSrc)) { echo '<tr><td>' . e($it['label']) . '</td><td>#' . $idAgence . '</td><td>—</td><td>—</td><td style="color:#b91c1c">mois inconnu (ajoute &mois=AAAA-MM)</td></tr>'; $skip++; continue; }
    $moisRef = $moisSrc . '-01';

    // Société de l'agence + libellé
    $a = $pdo->prepare("SELECT id_societe, nom_agence FROM agences WHERE id=?"); $a->execute([$idAgence]); $ag = $a->fetch(PDO::FETCH_ASSOC) ?: [];
    $idSociete = (int)($ag['id_societe'] ?? 0);
    $nomAgence = (string)($ag['nom_agence'] ?? ('#' . $idAgence));
    if ($idSociete <= 0) { echo '<tr><td>' . e($it['label']) . '</td><td>' . e($nomAgence) . '</td><td>' . e($moisRef) . '</td><td>—</td><td style="color:#b91c1c">société inconnue</td></tr>'; $skip++; continue; }

    $path = dr_item_file_path($pdo, $it);
    $origName = (string)($it['original_name'] ?? '');
    if (!$path || !is_file($path)) { echo '<tr><td>' . e($it['label']) . '</td><td>' . e($nomAgence) . '</td><td>' . e($moisRef) . '</td><td>' . e($origName) . '</td><td style="color:#b91c1c">fichier introuvable</td></tr>'; $skip++; continue; }

    // Anti-doublon : même agence/mois/type + même nom de fichier déjà journalisé ?
    $chk = $pdo->prepare("SELECT COUNT(*) FROM rh_salaire_workflow_log WHERE id_agence=? AND mois_reference=? AND type_action=? AND fichier_nom_original=?");
    $chk->execute([$idAgence, $moisRef, $type, $origName]);
    if ((int)$chk->fetchColumn() > 0) { echo '<tr><td>' . e($it['label']) . '</td><td>' . e($nomAgence) . '</td><td>' . e($moisRef) . '</td><td>' . e($origName) . '</td><td style="color:#64748b">déjà importé — ignoré</td></tr>'; $skip++; continue; }

    if (!$go) {
        echo '<tr><td>' . e($it['label']) . '</td><td>' . e($nomAgence) . '</td><td>' . e($moisRef) . '</td><td>' . e($origName) . '</td><td style="color:#0e7490">à importer</td></tr>';
        $done++; continue;
    }

    $content = @file_get_contents($path);
    if ($content === false) { echo '<tr><td>' . e($it['label']) . '</td><td>' . e($nomAgence) . '</td><td>' . e($moisRef) . '</td><td>' . e($origName) . '</td><td style="color:#b91c1c">lecture impossible</td></tr>'; $skip++; continue; }
    $iter = rh_wf_next_iteration($pdo, $idAgence, $moisRef, $type);
    $rel  = rh_wf_save_file($idSociete, $idAgence, $moisRef, $type, $iter, $content, $origName ?: ('piece_' . (int)$it['id']));
    $logId = rh_wf_log_action($pdo, $idSociete, $idAgence, $moisRef, $type, $rel, $origName ?: 'document', strlen($content),
        (string)($req['recipient_email'] ?? ''), (int)($req['created_by'] ?? 0) ?: null, 'ok', null,
        'Reprise depuis lien « Demander un document » #' . (int)$req['id']);
    echo '<tr><td>' . e($it['label']) . '</td><td>' . e($nomAgence) . '</td><td>' . e($moisRef) . '</td><td>' . e($origName) . '</td><td style="color:#166534">✅ importé (log #' . (int)$logId . ')</td></tr>';
    $done++;
}
echo '</table>';
echo '<p style="margin-top:14px"><b>' . $done . '</b> ' . ($go ? 'importé(s)' : 'à importer') . ' · ' . $skip . ' ignoré(s).</p>';
if (!$go) {
    $u = strtok($_SERVER['REQUEST_URI'], '#');
    $u .= (strpos($u, '?') === false ? '?' : '&') . 'go=1';
    echo '<p><a href="' . e($u) . '" style="background:#0e7490;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700">▶️ Lancer l\'import réel</a></p>';
} else {
    echo '<p style="color:#166534;font-weight:700">Terminé. Vérifie l\'Historique des échanges dans le module Salaires (mois cible).</p>';
}
echo '</div>';
