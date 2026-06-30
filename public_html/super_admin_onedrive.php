<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_admin_or_super_admin();
require_once __DIR__ . '/inc/microsoft_graph.php';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Formate une taille en octets de façon lisible. */
function onedrive_fmt_size(?int $bytes): string
{
    if ($bytes === null) { return '—'; }
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$v : number_format($v, 1, ',', ' ')) . ' ' . $units[$i];
}

/** Formate une date ISO Graph en français court. */
function onedrive_fmt_date(?string $iso): string
{
    if (!$iso) { return '—'; }
    try {
        return (new DateTime($iso))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return '—';
    }
}

$layout_title          = 'Explorateur OneDrive — Super Admin';
$layout_module         = 'Super Admin · Microsoft Graph';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

// ── Drives disponibles ─────────────────────────────────────────────
$drivePerso  = defined('GRAPH_ONEDRIVE_USER_PERSO') ? (string)GRAPH_ONEDRIVE_USER_PERSO : '';
$driveMetier = defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '';
$drives = [
    'metier' => ['label' => '🏢 Drive Métier', 'upn' => $driveMetier],
    'perso'  => ['label' => '👤 Mon Drive',    'upn' => $drivePerso],
];
$driveKey = (string)($_GET['drive'] ?? 'metier');
if (!isset($drives[$driveKey]) || $drives[$driveKey]['upn'] === '') {
    $driveKey = 'metier';
}
$driveUpn = $drives[$driveKey]['upn'];
graph_set_drive_user($driveUpn);

// ── État ───────────────────────────────────────────────────────────
$action     = (string)($_GET['action'] ?? 'browse');
$searchQ    = trim((string)($_GET['q'] ?? ''));
$curPath    = trim((string)($_GET['path'] ?? ''), '/');
$itemDetail = (string)($_GET['item'] ?? '');
$itemFolder = (string)($_GET['itemfolder'] ?? '');

$connError  = null;
$connOk     = false;
$tenant     = graph_tenant();
$testedAt   = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y H:i:s');

$items   = [];     // listing courant (dossiers + fichiers)
$results = null;   // résultats de recherche (null = pas de recherche)
$meta    = null;   // métadonnées item sélectionné
$listErr = null;

// Test de connexion : on tente d'obtenir un token.
try {
    if (!graph_is_configured()) {
        throw new RuntimeException('Configuration Microsoft Graph absente. Renseignez config/microsoft_graph.local.php');
    }
    graph_get_access_token();
    $connOk = true;
} catch (Throwable $e) {
    $connError = $e->getMessage();
}

// Listing / recherche / métadonnées (seulement si connexion OK).
if ($connOk) {
    try {
        if ($itemDetail !== '') {
            $meta = graph_get_item_metadata($itemDetail);
        } elseif ($itemFolder !== '') {
            // Ouverture d'un dossier par item_id (depuis les résultats de recherche).
            $items = function_exists('graph_list_all_children_by_item_id') ? graph_list_all_children_by_item_id($itemFolder) : graph_list_children_by_item_id($itemFolder);
        } elseif ($action === 'search' && $searchQ !== '') {
            $results = graph_search_drive($searchQ);
        } else {
            $items = ($curPath === '')
                ? graph_list_root()
                : (function_exists('graph_list_all_children_by_path') ? graph_list_all_children_by_path($curPath) : graph_list_children_by_path($curPath));
        }
    } catch (Throwable $e) {
        $listErr = $e->getMessage();
        // Journalisation côté serveur uniquement (jamais le secret/token).
        error_log('[OneDrive] ' . $e->getMessage());
    }
}

// Construit le chemin parent.
$parentPath = '';
if ($curPath !== '') {
    $segs = explode('/', $curPath);
    array_pop($segs);
    $parentPath = implode('/', $segs);
}

/** Construit une URL de navigation vers un dossier (chemin relatif). */
function onedrive_folder_url(string $path): string
{
    $drive = (string)($GLOBALS['driveKey'] ?? 'metier');
    return 'super_admin_onedrive.php?drive=' . rawurlencode($drive)
         . '&action=browse&path=' . rawurlencode($path);
}

$layout_extra_css = '<style>
.od-wrap { max-width: 1200px; margin: 0 auto; padding: 16px 0 40px; }
.od-card {
    background: linear-gradient(135deg,#fff 0%,#f7f8fa 100%);
    border-radius: 18px;
    box-shadow: 6px 6px 18px rgba(196,192,186,0.45), -6px -6px 18px #fff;
    padding: 18px 20px; margin-bottom: 18px;
}
.od-card-label {
    font-family:"DM Mono",monospace; font-size:10px; font-weight:600;
    color:#9a9690; letter-spacing:.16em; text-transform:uppercase;
    margin-bottom:12px; display:flex; align-items:center; gap:10px;
}
.od-card-label::after { content:""; flex:1; height:1px; background:linear-gradient(90deg,#d4d7de,transparent); }
.od-title { font-family:"Sora",sans-serif; font-size:24px; font-weight:800; color:#36577d; margin:4px 0 18px; }
.od-back { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#36577d; text-decoration:none; font-weight:600; margin-bottom:14px; }
.od-back:hover { text-decoration:underline; }
.od-drives { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
.od-drive-btn {
    padding:10px 20px; border-radius:14px; text-decoration:none; font-weight:700; font-size:14px;
    background:#fff; color:#6a6864; border:2px solid #e6e8ec;
    box-shadow:3px 3px 8px rgba(196,192,186,0.3), -3px -3px 8px #fff; transition:all .15s;
}
.od-drive-btn:hover { color:#36577d; border-color:#cdd3db; }
.od-drive-active { background:#36577d; color:#fff; border-color:#36577d; }
.od-drive-active:hover { color:#fff; }
.od-status { display:flex; flex-wrap:wrap; gap:18px; align-items:center; font-size:13px; }
.od-pill { display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:999px; font-weight:600; font-size:12px; }
.od-ok  { background:#ecfdf5; color:#065f46; border:1px solid rgba(6,95,70,.2); }
.od-err { background:#fef2f2; color:#991b1b; border:1px solid rgba(153,27,27,.2); }
.od-kv  { color:#6a6864; }
.od-kv b { color:#2c2a28; font-weight:700; }
.od-searchbar { display:flex; gap:10px; flex-wrap:wrap; }
.od-input { flex:1; min-width:220px; padding:10px 14px; border-radius:12px; border:1px solid #d4d7de; font-size:14px; background:#fff; }
.od-btn { padding:10px 18px; border-radius:12px; border:none; background:#36577d; color:#fff; font-weight:700; font-size:13px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.od-btn:hover { background:#2c4a6b; }
.od-btn-ghost { background:#fff; color:#36577d; border:1px solid #d4d7de; }
.od-crumb { font-family:"DM Mono",monospace; font-size:12px; color:#6a6864; margin-bottom:12px; word-break:break-all; }
.od-table { width:100%; border-collapse:collapse; font-size:13px; }
.od-table th { text-align:left; font-family:"DM Mono",monospace; font-size:10px; letter-spacing:.1em; text-transform:uppercase; color:#9a9690; padding:8px 10px; border-bottom:2px solid #eceef2; }
.od-table td { padding:9px 10px; border-bottom:1px solid #f0f1f4; vertical-align:middle; }
.od-table tr:hover td { background:#fafbfc; }
.od-name { font-weight:600; color:#2c2a28; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
.od-name:hover { color:#36577d; }
.od-type { font-size:11px; color:#8a8680; }
.od-id { font-family:"DM Mono",monospace; font-size:10px; color:#b5b1ab; word-break:break-all; }
.od-empty { padding:24px; text-align:center; color:#8a8680; font-size:13px; }
.od-msg-err { background:#fef2f2; color:#991b1b; border:1px solid rgba(153,27,27,.2); padding:10px 14px; border-radius:12px; font-size:13px; margin-bottom:12px; }
.od-meta dl { display:grid; grid-template-columns:180px 1fr; gap:8px 16px; font-size:13px; }
.od-meta dt { color:#8a8680; font-weight:600; }
.od-meta dd { margin:0; color:#2c2a28; word-break:break-all; }
</style>';

ob_start();
?>
<div class="od-wrap">
    <a class="od-back" href="super_admin_dashboard.php">← Retour tableau de bord</a>
    <div class="od-title">📁 Explorateur OneDrive Microsoft Graph</div>

    <!-- ── Sélecteur de drive ───────────────────────────────────── -->
    <div class="od-drives">
        <?php foreach ($drives as $k => $d):
            if ($d['upn'] === '') { continue; }
            $active = ($k === $driveKey);
        ?>
            <a class="od-drive-btn<?= $active ? ' od-drive-active' : '' ?>"
               href="super_admin_onedrive.php?drive=<?= h($k) ?>"
               title="<?= h($d['upn']) ?>">
                <?= h($d['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ── Connexion ────────────────────────────────────────────── -->
    <div class="od-card">
        <div class="od-card-label">Connexion</div>
        <div class="od-status">
            <?php if ($connOk): ?>
                <span class="od-pill od-ok">● Connexion OK</span>
            <?php else: ?>
                <span class="od-pill od-err">● Erreur de connexion</span>
            <?php endif; ?>
            <span class="od-kv">Tenant : <b><?= $tenant !== '' ? h($tenant) : '—' ?></b></span>
            <span class="od-kv">Drive : <b><?= h($drives[$driveKey]['label']) ?> — <?= h($driveUpn ?: '/me') ?></b></span>
            <span class="od-kv">Test : <b><?= h($testedAt) ?></b></span>
        </div>
        <?php if ($connError): ?>
            <div class="od-msg-err" style="margin-top:12px;"><?= h($connError) ?></div>
        <?php endif; ?>
    </div>

    <!-- ── Recherche ────────────────────────────────────────────── -->
    <div class="od-card">
        <div class="od-card-label">Recherche</div>
        <form class="od-searchbar" method="get" action="super_admin_onedrive.php">
            <input type="hidden" name="action" value="search">
            <input type="hidden" name="drive" value="<?= h($driveKey) ?>">
            <input class="od-input" type="text" name="q" placeholder="Recherche OneDrive (nom de fichier ou dossier)…"
                   value="<?= h($searchQ) ?>" <?= $connOk ? '' : 'disabled' ?>>
            <button class="od-btn" type="submit" <?= $connOk ? '' : 'disabled' ?>>🔍 Rechercher</button>
            <?php if ($action === 'search'): ?>
                <a class="od-btn od-btn-ghost" href="super_admin_onedrive.php">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($meta !== null): ?>
        <!-- ── Métadonnées item ─────────────────────────────────── -->
        <div class="od-card od-meta">
            <div class="od-card-label">Métadonnées du fichier</div>
            <a class="od-back" href="<?= h(onedrive_folder_url($curPath)) ?>">← Retour à l'explorateur</a>
            <?php
                $isFolder = isset($meta['folder']);
                $mime     = $meta['file']['mimeType'] ?? ($isFolder ? 'dossier' : '—');
                $webUrl   = $meta['webUrl'] ?? '';
                $parentRef = $meta['parentReference']['path'] ?? '';
            ?>
            <dl>
                <dt>Nom</dt><dd><?= h($meta['name'] ?? '—') ?></dd>
                <dt>Type</dt><dd><?= $isFolder ? 'Dossier' : 'Fichier' ?> (<?= h($mime) ?>)</dd>
                <dt>Taille</dt><dd><?= h(onedrive_fmt_size(isset($meta['size']) ? (int)$meta['size'] : null)) ?></dd>
                <dt>Modifié le</dt><dd><?= h(onedrive_fmt_date($meta['lastModifiedDateTime'] ?? null)) ?></dd>
                <dt>Créé le</dt><dd><?= h(onedrive_fmt_date($meta['createdDateTime'] ?? null)) ?></dd>
                <dt>Chemin Graph</dt><dd><?= h($parentRef) ?>/<?= h($meta['name'] ?? '') ?></dd>
                <dt>item_id</dt><dd class="od-id"><?= h($meta['id'] ?? '—') ?></dd>
                <?php if ($webUrl): ?><dt>Lien web</dt><dd><a href="<?= h($webUrl) ?>" target="_blank" rel="noopener"><?= h($webUrl) ?></a></dd><?php endif; ?>
            </dl>
        </div>
    <?php else: ?>
        <!-- ── Explorateur / Résultats ──────────────────────────── -->
        <div class="od-card">
            <div class="od-card-label"><?= $results !== null ? 'Résultats de recherche' : 'Explorateur' ?></div>

            <?php if ($results === null): ?>
                <div class="od-crumb">📂 Dossier courant : /<?= h($curPath) ?></div>
                <?php if ($curPath !== ''): ?>
                    <a class="od-btn od-btn-ghost" href="<?= h(onedrive_folder_url($parentPath)) ?>" style="margin-bottom:12px;">⬆ Dossier parent</a>
                <?php endif; ?>
            <?php else: ?>
                <div class="od-crumb">🔍 Recherche : « <?= h($searchQ) ?> » — <?= count($results) ?> résultat(s)</div>
            <?php endif; ?>

            <?php if ($listErr): ?>
                <div class="od-msg-err"><?= h($listErr) ?></div>
            <?php endif; ?>

            <?php
                $rows = $results !== null ? $results : $items;
                if (!$connOk):
            ?>
                <div class="od-empty">Connexion Graph indisponible — impossible d'afficher le contenu.</div>
            <?php elseif (empty($rows) && !$listErr): ?>
                <div class="od-empty">Aucun élément à afficher.</div>
            <?php else: ?>
                <table class="od-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Type</th>
                            <th>Taille</th>
                            <th>Modifié le</th>
                            <?php if ($results !== null): ?><th>Chemin</th><?php endif; ?>
                            <th>item_id</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $it):
                        $isFolder = isset($it['folder']);
                        $name     = (string)($it['name'] ?? '');
                        $id       = (string)($it['id'] ?? '');
                        $size     = isset($it['size']) ? (int)$it['size'] : null;
                        $modified = $it['lastModifiedDateTime'] ?? null;
                        $mime     = $it['file']['mimeType'] ?? '';
                        $isPdf    = stripos($name, '.pdf') !== false || $mime === 'application/pdf';
                        // Chemin Graph affiché en recherche.
                        $gpath    = $it['parentReference']['path'] ?? '';
                        $icon     = $isFolder ? '📁' : ($isPdf ? '📄' : '📃');

                        // Lien : dossier → ouvre (par chemin en explorateur, par id en recherche) ;
                        // fichier → métadonnées.
                        if ($isFolder) {
                            if ($results === null) {
                                $childPath = $curPath === '' ? $name : $curPath . '/' . $name;
                                $href = onedrive_folder_url($childPath);
                            } else {
                                // En recherche on n'a pas toujours le chemin relatif fiable → via item_id.
                                $href = 'super_admin_onedrive.php?drive=' . rawurlencode($driveKey)
                                      . '&action=browse&itemfolder=' . rawurlencode($id);
                            }
                        } else {
                            $href = 'super_admin_onedrive.php?drive=' . rawurlencode($driveKey)
                                  . '&item=' . rawurlencode($id)
                                  . '&path=' . rawurlencode($curPath);
                        }
                    ?>
                        <tr>
                            <td>
                                <a class="od-name" href="<?= h($href) ?>"><?= $icon ?> <?= h($name) ?></a>
                            </td>
                            <td class="od-type"><?= $isFolder ? 'Dossier' : ($isPdf ? 'PDF' : 'Fichier') ?></td>
                            <td><?= $isFolder ? '—' : h(onedrive_fmt_size($size)) ?></td>
                            <td><?= h(onedrive_fmt_date($modified)) ?></td>
                            <?php if ($results !== null): ?>
                                <td class="od-id"><?= h($gpath) ?></td>
                            <?php endif; ?>
                            <td class="od-id"><?= h($id) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
