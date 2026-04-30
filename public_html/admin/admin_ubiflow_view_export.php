<?php
declare(strict_types=1);

/**
 * VIEWER ADMIN — fichiers exportés Ubiflow par agence
 *
 * Sert en lecture seule (text/plain) le XML déposé sur le FTP Ubiflow
 * pour une agence donnée. Permet à l'admin de vérifier exactement ce qui
 * a été envoyé (debug du flux LBC/SeLoger/Bien'ici en cas d'incohérence).
 *
 * Réservé strictement aux admins (role_id = 1). Le slug est validé contre
 * la liste des agences actives (config/ubiflow_agences.php).
 *
 * URL : /public_html/admin/admin_ubiflow_view_export.php?slug=chaponost
 *       /public_html/admin/admin_ubiflow_view_export.php?slug=chaponost&format=raw
 *       /public_html/admin/admin_ubiflow_view_export.php?slug=chaponost&download=zip
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/config/ubiflow_agences.php';
require_login();

$roleId = (int)current_role_id();
if ($roleId !== 1 && !is_super_admin()) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs.');
}

$slug   = trim((string)($_GET['slug']   ?? ''));
$format = trim((string)($_GET['format'] ?? ''));
$dl     = trim((string)($_GET['download'] ?? ''));

// Validation slug ⇒ doit appartenir à la liste des agences actives
$agences = ubiflow_agences_actives();
if (!isset($agences[$slug])) {
    http_response_code(404);
    exit('Slug agence inconnu.');
}
$ag       = $agences[$slug];
$loginFtp = (string)($ag['login_ftp'] ?? '');
$nomAg    = (string)($ag['nom'] ?? $slug);

if ($loginFtp === '') {
    http_response_code(500);
    exit('login_ftp manquant pour ' . htmlspecialchars($slug));
}

$exportDir = dirname(__DIR__) . '/api/flux/export/' . $slug;
$xmlPath   = $exportDir . '/' . $loginFtp . '.xml';
$zipPath   = $exportDir . '/' . $loginFtp . '.zip';

// Robustesse : si le fichier au nom `{login_ftp}.xml` n'existe pas (cas des
// anciens dépôts qui utilisaient `{slug_humain}.xml`), on prend le .xml le
// plus récent du dossier d'export — c'est forcément l'export courant.
if (!is_file($xmlPath) && is_dir($exportDir)) {
    $candidates = glob($exportDir . '/*.xml') ?: [];
    if ($candidates) {
        usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $xmlPath = $candidates[0];
    }
}
if (!is_file($zipPath) && is_dir($exportDir)) {
    $candidates = glob($exportDir . '/*.zip') ?: [];
    if ($candidates) {
        usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $zipPath = $candidates[0];
    }
}

// ─── Download du ZIP ────────────────────────────────────────────────
if ($dl === 'zip') {
    if (!is_file($zipPath)) {
        http_response_code(404);
        exit('ZIP non trouvé : ' . htmlspecialchars(basename($zipPath)));
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    exit;
}

// ─── Mode raw : XML brut en text/plain (utile pour copier-coller) ──
if ($format === 'raw') {
    if (!is_file($xmlPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Fichier XML non trouvé : " . basename($xmlPath) . "\n";
        echo "Chemin attendu : " . $xmlPath . "\n";
        echo "\nLancez d'abord un dépôt depuis la page diffusion (📤 Envoyer maintenant).";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    readfile($xmlPath);
    exit;
}

// ─── Mode HTML viewer (par défaut) ──────────────────────────────────
$xmlContent  = is_file($xmlPath) ? file_get_contents($xmlPath) : '';
$xmlExists   = is_file($xmlPath);
$zipExists   = is_file($zipPath);
$xmlMtime    = $xmlExists ? filemtime($xmlPath) : 0;
$zipMtime    = $zipExists ? filemtime($zipPath) : 0;
$xmlSize     = $xmlExists ? filesize($xmlPath) : 0;
$zipSize     = $zipExists ? filesize($zipPath) : 0;

// Pretty-print XML pour lisibilité
$xmlPretty = '';
$xmlNbAnnonces = 0;
if ($xmlContent !== '') {
    try {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        @$dom->loadXML($xmlContent);
        $xmlPretty = $dom->saveXML();
        $xmlNbAnnonces = $dom->getElementsByTagName('annonce')->length;
    } catch (Throwable) {
        $xmlPretty = $xmlContent; // fallback brut
    }
}

function ho($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_bytes(int $n): string {
    if ($n < 1024) return $n . ' o';
    if ($n < 1024 * 1024) return number_format($n / 1024, 1, ',', ' ') . ' Ko';
    return number_format($n / (1024 * 1024), 2, ',', ' ') . ' Mo';
}
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Export Ubiflow — <?= ho($nomAg) ?> — MaBoxImmo</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
  <style>
    body { margin: 0; font-family: 'Manrope', sans-serif; background: #f8fafc; color: #0f172a; }
    .vw-wrap { max-width: 1200px; margin: 0 auto; padding: 22px 20px; }
    .vw-head { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
    .vw-head h1 { margin: 0; font-size: 18px; }
    .vw-back { color: #0369a1; text-decoration: none; font-size: 13px; }
    .vw-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; background: #dbeafe; color: #1e40af; }
    .vw-meta { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 18px; margin-bottom: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 24px; font-size: 12px; }
    .vw-meta div span { color: #64748b; display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
    .vw-meta div strong { font-size: 13px; color: #0f172a; }
    .vw-actions { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
    .vw-btn { padding: 7px 14px; border-radius: 8px; background: #0ea5e9; color: #fff; border: none; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .vw-btn:hover { background: #0284c7; }
    .vw-btn.ghost { background: #fff; color: #0369a1; border: 1px solid #0ea5e9; }
    .vw-btn.warn  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .vw-empty { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 18px; border-radius: 10px; font-size: 13px; }
    .vw-xml { background: #0f172a; color: #e2e8f0; padding: 16px 18px; border-radius: 10px; font-family: 'JetBrains Mono', monospace; font-size: 11px; line-height: 1.6; white-space: pre-wrap; word-break: break-word; max-height: 75vh; overflow-y: auto; }
    .vw-xml .tag  { color: #38bdf8; }
    .vw-xml .attr { color: #fbbf24; }
    .vw-xml .text { color: #f0fdf4; }
    .vw-tip { font-size: 11px; color: #94a3b8; margin-top: 12px; }
  </style>
</head>
<body>
<div class="vw-wrap">
  <div class="vw-head">
    <a href="/public_html/agency_dashboard_diffusion.php" class="vw-back">← Diffusion</a>
    <h1>📄 Export Ubiflow — <?= ho($nomAg) ?></h1>
    <span class="vw-pill">slug: <?= ho($slug) ?></span>
    <span class="vw-pill" style="background:#fef3c7;color:#92400e;">login: <?= ho($loginFtp) ?></span>
  </div>

  <div class="vw-meta">
    <div><span>XML existe</span><strong><?= $xmlExists ? '✓ Oui' : '✗ Non' ?></strong></div>
    <div><span>Date dernier export</span><strong><?= $xmlMtime > 0 ? date('d/m/Y H:i:s', $xmlMtime) : '—' ?></strong></div>
    <div><span>Taille XML</span><strong><?= $xmlExists ? fmt_bytes($xmlSize) : '—' ?></strong></div>
    <div><span>Annonces dans le XML</span><strong><?= $xmlNbAnnonces ?></strong></div>
    <div><span>ZIP existe</span><strong><?= $zipExists ? '✓ ' . fmt_bytes($zipSize) : '✗ Non' ?></strong></div>
    <div><span>Chemin local</span><strong style="font-family:monospace;font-size:10px;"><?= ho($xmlPath) ?></strong></div>
  </div>

  <div class="vw-actions">
    <a href="?slug=<?= ho($slug) ?>&format=raw" target="_blank" class="vw-btn">📋 Ouvrir le XML brut (texte)</a>
    <?php if ($zipExists): ?>
      <a href="?slug=<?= ho($slug) ?>&download=zip" class="vw-btn ghost">⬇️ Télécharger le ZIP</a>
    <?php endif; ?>
    <a href="/public_html/agency_dashboard_diffusion.php" class="vw-btn ghost">← Retour</a>
  </div>

  <?php if (!$xmlExists): ?>
    <div class="vw-empty">
      Aucun fichier XML exporté pour cette agence.<br><br>
      Lance un dépôt depuis la page <strong>Diffusion</strong> (bouton 📤 Envoyer maintenant) pour générer le fichier.
    </div>
  <?php else: ?>
    <div class="vw-xml"><?= ho($xmlPretty) ?></div>
    <p class="vw-tip">
      Ce fichier est régénéré à chaque dépôt. La version actuelle reflète l'état des annonces au moment du dernier 📤 Envoyer.
      Pour reprendre à zéro après une correction (ex. types de bien), relance un dépôt depuis la page Diffusion.
    </p>
  <?php endif; ?>
</div>
</body>
</html>
