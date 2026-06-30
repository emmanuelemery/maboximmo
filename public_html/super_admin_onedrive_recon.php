<?php
/**
 * super_admin_onedrive_recon.php — RECON (lecture seule) de l'arborescence OneDrive
 * des dossiers propriétaires (service Gestion), pour concevoir le classement auto
 * des mandats / baux signés / EDL E / DPE vers les biens & locataires créés par CRG.
 *
 * Paramètres :
 *   ?base=<chemin du dossier PROPRIETAIRES>   (défaut : RIOM GESTION / 00 - PROPRIETAIRES)
 *   ?n=<nb de dossiers proprio à explorer>    (défaut 3)
 *   ?proprio=<filtre nom de dossier>          (optionnel : un proprio précis)
 *   ?depth=<profondeur max>                    (défaut 4)
 *
 * Réservé super admin. N'écrit RIEN (aucun classement) — juste un dump pour analyse.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/microsoft_graph.php';
require_login();
if ((int)($_SESSION['id_role'] ?? 0) !== 1) { http_response_code(403); exit('Super admin uniquement.'); }
header('Content-Type: text/html; charset=utf-8');

if (!function_exists('graph_is_configured') || !graph_is_configured()) {
    exit('<p style="font-family:sans-serif">⚠️ Microsoft Graph non configuré sur cet environnement (config/microsoft_graph.php).</p>');
}

// Drive métier (même accès que l'explorateur).
graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');

$base    = trim((string)($_GET['base'] ?? '01_SERVICE_GESTION/05_RIOM GESTION/0001 - NOUVEAUX DOSSIERS ONEDRIVE/00 - PROPRIETAIRES'), '/');
$n       = max(1, min(20, (int)($_GET['n'] ?? 3)));
$filter  = trim((string)($_GET['proprio'] ?? ''));
$maxDepth= max(1, min(6, (int)($_GET['depth'] ?? 4)));

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Détection de type par mot-clé (sur le nom de fichier OU le chemin).
function recon_type(string $name, string $path): string {
    $s = mb_strtolower($name . ' ' . $path, 'UTF-8');
    if (preg_match('/\bmandat/u', $s))                                   return 'MANDAT';
    if (preg_match('/\bbail|baux/u', $s))                                return 'BAIL';
    if (preg_match('/e\.?d\.?l|etat des lieux|état des lieux|edl/u', $s))return 'EDL';
    if (preg_match('/\bdpe|ddt|diagnostic|diag\b/u', $s))                return 'DPE';
    if (preg_match('/quittance|avis.?ech|loyer/u', $s))                  return 'AVIS/QUITT';
    return '';
}

$counts = ['MANDAT'=>0,'BAIL'=>0,'EDL'=>0,'DPE'=>0,'AVIS/QUITT'=>0,'autre'=>0];
$lines  = [];
$itemBudget = 800;

function recon_walk(string $path, int $depth, int $maxDepth, array &$lines, array &$counts, int &$budget): void {
    if ($depth > $maxDepth || $budget <= 0) return;
    try { $children = function_exists('graph_list_all_children_by_path') ? graph_list_all_children_by_path($path) : graph_list_children_by_path($path); } catch (Throwable $e) { $lines[] = str_repeat('  ', $depth) . '⚠️ (erreur: ' . $e->getMessage() . ')'; return; }
    foreach ($children as $it) {
        if ($budget-- <= 0) { $lines[] = str_repeat('  ', $depth) . '… (limite atteinte)'; return; }
        $name = (string)($it['name'] ?? '?');
        $isFolder = isset($it['folder']);
        if ($isFolder) {
            $lines[] = str_repeat('  ', $depth) . '📁 ' . $name;
            recon_walk($path . '/' . $name, $depth + 1, $maxDepth, $lines, $counts, $budget);
        } else {
            $t = recon_type($name, $path);
            $tag = $t !== '' ? "  «{$t}»" : '';
            $lines[] = str_repeat('  ', $depth) . '📄 ' . $name . $tag;
            $counts[$t !== '' ? $t : 'autre']++;
        }
    }
}

// 1) Lister les dossiers propriétaires sous la base.
$proprioFolders = [];
try {
    foreach ((function_exists('graph_list_all_children_by_path') ? graph_list_all_children_by_path($base) : graph_list_children_by_path($base)) as $it) {
        if (!isset($it['folder'])) continue;
        $nm = (string)($it['name'] ?? '');
        if ($filter !== '' && mb_stripos($nm, $filter) === false) continue;
        $proprioFolders[] = $nm;
    }
} catch (Throwable $ex) {
    exit('<p style="font-family:sans-serif">❌ Impossible de lister la base : ' . $e($ex->getMessage()) . '<br>base = <code>' . $e($base) . '</code></p>');
}
sort($proprioFolders);
$selected = array_slice($proprioFolders, 0, $n);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Recon OneDrive propriétaires</title>
<style>body{font-family:-apple-system,Segoe UI,sans-serif;margin:24px;color:#222}
h1{font-size:20px}.box{background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px;white-space:pre;overflow:auto;font-family:'DM Mono',Consolas,monospace;font-size:12.5px;line-height:1.5}
.meta{color:#64748b;font-size:13px;margin:8px 0}.sum{background:#f1f5f9;border-radius:8px;padding:10px 14px;display:inline-block;margin:6px 0;font-size:13px}
code{background:#eef2ff;padding:1px 5px;border-radius:4px}</style></head><body>
<h1>🔎 Recon OneDrive — dossiers propriétaires (service Gestion)</h1>
<div class="meta">Base : <code><?= $e($base) ?></code> · <?= count($proprioFolders) ?> dossier(s) propriétaire trouvé(s) · exploration de <?= count($selected) ?> (profondeur <?= $maxDepth ?>).</div>
<div class="meta">Astuce : <code>?n=3</code> nb proprios · <code>?proprio=AIELLO</code> cible un proprio · <code>?depth=4</code> · <code>?base=...</code> autre dossier.</div>
<?php
foreach ($selected as $pf) {
    $lines = [];
    recon_walk($base . '/' . $pf, 0, $maxDepth, $lines, $counts, $itemBudget);
    echo '<h2 style="font-size:15px;margin-top:18px;">👤 ' . $e($pf) . '</h2>';
    echo '<div class="box">' . $e(implode("\n", $lines) ?: '(vide)') . '</div>';
}
?>
<h2 style="font-size:15px;margin-top:18px;">Synthèse types détectés (sur l'échantillon)</h2>
<div class="sum">
  📜 MANDAT : <b><?= $counts['MANDAT'] ?></b> &nbsp;·&nbsp;
  📄 BAIL : <b><?= $counts['BAIL'] ?></b> &nbsp;·&nbsp;
  🔑 EDL : <b><?= $counts['EDL'] ?></b> &nbsp;·&nbsp;
  ⚡ DPE : <b><?= $counts['DPE'] ?></b> &nbsp;·&nbsp;
  💶 AVIS/QUITT : <b><?= $counts['AVIS/QUITT'] ?></b> &nbsp;·&nbsp;
  ❔ autre : <b><?= $counts['autre'] ?></b>
</div>
<p class="meta">Copie-colle les arborescences ci-dessus dans la conversation → je conçois les règles de classement auto (mandat/bail/EDL/DPE → bien & locataire créés par CRG).</p>
</body></html>
