<?php
declare(strict_types=1);

/**
 * admin/admin_flux_ubiflow.php
 *
 * Vue admin des flux XML Ubiflow générés par agence.
 *
 * Chaque diffusion Ubiflow (bouton "Envoyer maintenant" / "Force" sur le
 * dashboard agence) génère un XML dans api/flux/export/{slug_agence}/. Cette
 * page liste tous les fichiers générés, montre le nb d'annonces, la taille,
 * la date, et permet de :
 *   - Visualiser le XML dans le navigateur (nouvelle fenêtre)
 *   - Télécharger un XML individuel
 *   - Télécharger tous les XMLs en ZIP (pour envoi au support Ubiflow)
 *
 * Accès : super-admin (role_id = 1).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$exportDir = __DIR__ . '/../api/flux/export';

// ── Téléchargement ZIP global ───────────────────────────────────────
if (($_GET['zip'] ?? '') === '1') {
    $files = glob($exportDir . '/*/*.xml') ?: [];
    if (empty($files)) {
        exit('Aucun XML à zipper.');
    }
    $zipPath = sys_get_temp_dir() . '/flux_ubiflow_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        exit('Impossible de créer le ZIP.');
    }
    foreach ($files as $f) {
        $rel = str_replace($exportDir . DIRECTORY_SEPARATOR, '', $f);
        $rel = str_replace('\\', '/', $rel);
        $zip->addFile($f, $rel);
    }
    // Ajoute un README contextualisé
    $readme = "Flux XML Ubiflow — export pour vérification\n";
    $readme .= "Généré le " . date('d/m/Y H:i:s') . "\n";
    $readme .= "Serveur : " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\n";
    $readme .= "Fichiers inclus : " . count($files) . "\n\n";
    foreach ($files as $f) {
        $rel = str_replace($exportDir . DIRECTORY_SEPARATOR, '', $f);
        $readme .= "  • " . $rel . "  (" . filesize($f) . " octets)\n";
    }
    $zip->addFromString('LISEZ-MOI.txt', $readme);
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="flux_ubiflow_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// ── Téléchargement XML individuel ───────────────────────────────────
if (!empty($_GET['download'])) {
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['download']);
    $file = preg_replace('/[^a-z0-9_.-]/i', '', (string)($_GET['file'] ?? ''));
    $path = $exportDir . '/' . $slug . '/' . $file;
    $path = realpath($path);
    if ($path && str_starts_with($path, realpath($exportDir)) && is_file($path)) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Fichier introuvable.');
}

// ── Visu XML dans navigateur ────────────────────────────────────────
if (!empty($_GET['view'])) {
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['view']);
    $file = preg_replace('/[^a-z0-9_.-]/i', '', (string)($_GET['file'] ?? ''));
    $path = $exportDir . '/' . $slug . '/' . $file;
    $path = realpath($path);
    if ($path && str_starts_with($path, realpath($exportDir)) && is_file($path)) {
        header('Content-Type: application/xml; charset=utf-8');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Fichier introuvable.');
}

// ── Liste des flux (UI) ─────────────────────────────────────────────
$flux = [];
$totalAnnonces = 0;
$totalSize = 0;

if (is_dir($exportDir)) {
    foreach (glob($exportDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename($dir) === '_bundle') continue;
        $slug = basename($dir);
        foreach (glob($dir . '/*.xml') ?: [] as $f) {
            $name  = basename($f);
            $size  = (int)@filesize($f);
            $mtime = (int)@filemtime($f);
            $content = @file_get_contents($f) ?: '';
            $nbAnnonces = substr_count($content, '<annonce>');
            $nbPhotos   = substr_count($content, '<photo>');
            // Récupère le nom d'agence depuis la 1ère balise <agence> ou le nom du fichier
            $agenceNom = '';
            if (preg_match('#<raison_sociale>([^<]+)</raison_sociale>#', $content, $m)) {
                $agenceNom = trim($m[1]);
            } elseif (preg_match('#<nom_agence>([^<]+)</nom_agence>#', $content, $m)) {
                $agenceNom = trim($m[1]);
            }
            $flux[] = [
                'slug'        => $slug,
                'file'        => $name,
                'size'        => $size,
                'mtime'       => $mtime,
                'nb_annonces' => $nbAnnonces,
                'nb_photos'   => $nbPhotos,
                'agence_nom'  => $agenceNom,
            ];
            $totalAnnonces += $nbAnnonces;
            $totalSize     += $size;
        }
    }
}

// Tri par dossier puis nom
usort($flux, fn($a, $b) => strcmp($a['slug'] . $a['file'], $b['slug'] . $b['file']));

$pageTitle    = 'Flux XML Ubiflow';
$pageSubtitle = 'Super Admin · Diffusion portails';
require_once __DIR__ . '/../inc/agency_layout_top.php';

function fmtSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' o';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' Ko';
    return round($bytes / 1048576, 2) . ' Mo';
}
?>

<style>
  .fl-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 20px; }
  .fl-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .fl-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; line-height: 1.5; }
  .fl-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 16px 0 24px; }
  .fl-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; text-align: center; }
  .fl-stat-nb { font-size: 24px; font-weight: 700; color: #0f172a; }
  .fl-stat-lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
  .fl-actions { display: flex; gap: 10px; margin: 16px 0 24px; flex-wrap: wrap; }
  .fl-btn { padding: 11px 22px; border-radius: 10px; border: 1px solid transparent; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; }
  .fl-btn-zip { background: #16a34a; color: #fff; border-color: #16a34a; }
  .fl-btn-zip:hover { background: #15803d; }
  .fl-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
  .fl-table th { background: #f8fafc; padding: 10px 12px; text-align: left; font-weight: 700; color: #475569; border-bottom: 1px solid #e5e7eb; }
  .fl-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
  .fl-table tr:hover td { background: #f8fafc; }
  .fl-slug { font-family: monospace; font-size: 11px; padding: 2px 8px; background: #eff6ff; color: #1e40af; border-radius: 99px; }
  .fl-filename { font-family: monospace; font-size: 11px; color: #64748b; }
  .fl-nb { font-weight: 700; color: #0369a1; font-size: 14px; }
  .fl-links { display: flex; gap: 8px; }
  .fl-link-btn { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-decoration: none; }
  .fl-link-view { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
  .fl-link-view:hover { background: #f1f5f9; }
  .fl-link-dl { background: #0ea5e9; color: #fff; border: 1px solid #0ea5e9; }
  .fl-link-dl:hover { background: #0284c7; }
  .fl-empty { background: #f8fafc; border: 2px dashed #cbd5e1; padding: 40px; border-radius: 12px; text-align: center; color: #64748b; }
</style>

<div class="fl-wrap">
  <h1>📡 Flux Ubiflow — XMLs exportés par agence</h1>
  <p class="sub">
    Chaque diffusion Ubiflow (bouton « Envoyer maintenant » ou « Force » du dashboard agence) génère
    un XML dans <code>api/flux/export/&lt;agence&gt;/</code>. Cette page liste les derniers flux générés
    avec le nombre d'annonces de chaque fichier.
    <br>Utilise <strong>Télécharger tout en ZIP</strong> pour envoyer l'ensemble à ton contact Ubiflow pour vérification.
  </p>

  <div class="fl-stats">
    <div class="fl-stat">
      <div class="fl-stat-nb"><?= count($flux) ?></div>
      <div class="fl-stat-lbl">Fichiers XML</div>
    </div>
    <div class="fl-stat">
      <div class="fl-stat-nb"><?= $totalAnnonces ?></div>
      <div class="fl-stat-lbl">Annonces total</div>
    </div>
    <div class="fl-stat">
      <div class="fl-stat-nb"><?= fmtSize($totalSize) ?></div>
      <div class="fl-stat-lbl">Poids cumulé</div>
    </div>
    <div class="fl-stat">
      <div class="fl-stat-nb"><?= count(array_unique(array_column($flux, 'slug'))) ?></div>
      <div class="fl-stat-lbl">Agences</div>
    </div>
  </div>

  <?php if (empty($flux)): ?>
    <div class="fl-empty">
      <div style="font-size:40px; margin-bottom:12px;">📭</div>
      <p>Aucun flux XML n'a encore été généré dans <code>api/flux/export/</code>.</p>
      <p style="font-size:12px;">Va sur <a href="../agency_dashboard_diffusion.php">Ma Box Agency → Diffusion Ubiflow</a> et clique « Envoyer maintenant » ou « Force » sur une agence pour produire un XML.</p>
    </div>
  <?php else: ?>
    <div class="fl-actions">
      <a href="?zip=1" class="fl-btn fl-btn-zip">
        📦 Télécharger tout en ZIP (<?= count($flux) ?> fichier<?= count($flux) > 1 ? 's' : '' ?>)
      </a>
    </div>

    <table class="fl-table">
      <thead>
        <tr>
          <th>Agence</th>
          <th>Fichier</th>
          <th style="text-align:center;">Annonces</th>
          <th style="text-align:center;">Photos</th>
          <th>Poids</th>
          <th>Générée le</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($flux as $f):
          $isEmpty = (int)$f['nb_annonces'] === 0;
          $rowStyle = $isEmpty ? 'background:#fef2f2;' : '';
        ?>
          <tr style="<?= $rowStyle ?>">
            <td>
              <div style="font-weight:600; color:#0f172a;">
                <?php if ($isEmpty): ?>⚠️ <?php endif; ?>
                <?= htmlspecialchars($f['agence_nom'] ?: ucfirst((string)$f['slug'])) ?>
              </div>
              <span class="fl-slug"><?= htmlspecialchars((string)$f['slug']) ?></span>
            </td>
            <td><span class="fl-filename"><?= htmlspecialchars((string)$f['file']) ?></span></td>
            <td style="text-align:center;">
              <span class="fl-nb" style="<?= $isEmpty ? 'color:#dc2626;' : '' ?>"><?= (int)$f['nb_annonces'] ?></span>
              <?php if ($isEmpty): ?><div style="font-size:10px; color:#991b1b; margin-top:2px;">flux vide</div><?php endif; ?>
            </td>
            <td style="text-align:center; color:#64748b;"><?= (int)$f['nb_photos'] ?></td>
            <td style="color:#64748b; font-size:12px;"><?= fmtSize((int)$f['size']) ?></td>
            <td style="color:#64748b; font-size:12px;"><?= $f['mtime'] ? date('d/m/Y H:i', (int)$f['mtime']) : '—' ?></td>
            <td style="text-align:right;">
              <div class="fl-links" style="justify-content:flex-end;">
                <a href="?view=<?= htmlspecialchars(rawurlencode((string)$f['slug'])) ?>&file=<?= htmlspecialchars(rawurlencode((string)$f['file'])) ?>" target="_blank" class="fl-link-btn fl-link-view" title="Ouvrir le XML dans un nouvel onglet">👁️ Voir</a>
                <a href="?download=<?= htmlspecialchars(rawurlencode((string)$f['slug'])) ?>&file=<?= htmlspecialchars(rawurlencode((string)$f['file'])) ?>" class="fl-link-btn fl-link-dl" title="Télécharger le XML">⬇️ DL</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="margin-top:20px; padding:12px 16px; background:#f8fafc; border-radius:8px; font-size:12px; color:#64748b;">
    💡 <strong>Note</strong> : ces fichiers sont régénérés à chaque diffusion Ubiflow. Les horodatages reflètent le dernier envoi
    déclenché depuis <a href="../agency_dashboard_diffusion.php">Ma Box Agency → Diffusion Ubiflow</a>.
  </div>
</div>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
