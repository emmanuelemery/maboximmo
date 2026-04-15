<?php
/**
 * api/pdf_viewer.php — Viewer PDF avec navigation à la page
 * Usage: pdf_viewer.php?file=uploads/bailleur_docs/14/xxx.pdf&page=26&name=MANISE+PIERRICK
 */
require_once __DIR__ . '/../inc/bootstrap.php';

$file = urldecode($_GET['file'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$name = $_GET['name'] ?? '';

if (!$file) { http_response_code(400); exit('Fichier requis'); }

// Sécurité : vérifier que le fichier est dans uploads/
$absPath = realpath(__DIR__ . '/../' . $file);
$uploadsDir = realpath(__DIR__ . '/../uploads');
if (!$absPath || !$uploadsDir || !str_starts_with($absPath, $uploadsDir) || !is_file($absPath)) {
    http_response_code(404);
    exit('Fichier introuvable : ' . htmlspecialchars($file));
}

$pdfUrl = app_url('/' . $file);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>CRG<?= $name ? ' — ' . htmlspecialchars($name) : '' ?> — Page <?= $page ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { background:#2d2a26; font-family:'Sora',system-ui,sans-serif; height:100vh; display:flex; flex-direction:column; }
.toolbar {
    background:linear-gradient(180deg,#1a1a1a,#111);
    color:#fff; padding:10px 20px;
    display:flex; align-items:center; gap:20px;
    font-size:13px; flex-shrink:0;
    border-bottom:1px solid #333;
}
.toolbar .title { font-weight:700; color:#e5e5e5; }
.toolbar .page-badge {
    background:#16a34a; color:#fff; padding:3px 12px;
    border-radius:20px; font-weight:700;
    font-family:'DM Mono',monospace; font-size:12px;
}
.toolbar .btn {
    padding:5px 14px; border-radius:8px; text-decoration:none;
    font-size:12px; font-weight:600; border:1px solid #444;
    color:#ccc; background:#222; cursor:pointer;
}
.toolbar .btn:hover { background:#333; color:#fff; }
.toolbar .spacer { flex:1; }
.nav-pages { display:flex; align-items:center; gap:6px; }
.nav-pages button {
    width:30px; height:30px; border-radius:6px; border:1px solid #444;
    background:#222; color:#fff; font-size:16px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
}
.nav-pages button:hover { background:#444; }
.nav-pages input {
    width:50px; text-align:center; padding:4px;
    border-radius:6px; border:1px solid #444;
    background:#222; color:#fff; font-size:12px;
    font-family:'DM Mono',monospace;
}
#pdfCanvas { flex:1; width:100%; border:none; }
</style>
</head>
<body>
<div class="toolbar">
    <span class="title">📄 CRG<?= $name ? ' — ' . htmlspecialchars($name) : '' ?></span>
    <span class="page-badge">Page <?= $page ?></span>
    <div class="nav-pages">
        <button onclick="goPage(currentPage-1)" title="Page précédente">◀</button>
        <input type="number" id="pageInput" value="<?= $page ?>" min="1" onchange="goPage(parseInt(this.value))">
        <span style="color:#888;font-size:11px">/ <span id="totalPages">?</span></span>
        <button onclick="goPage(currentPage+1)" title="Page suivante">▶</button>
    </div>
    <div class="spacer"></div>
    <a href="<?= htmlspecialchars($pdfUrl) ?>" download class="btn">📥 Télécharger</a>
    <a href="javascript:window.close()" class="btn">✕ Fermer</a>
</div>
<canvas id="pdfCanvas"></canvas>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

var pdfDoc = null;
var currentPage = <?= $page ?>;
var canvas = document.getElementById('pdfCanvas');
var ctx = canvas.getContext('2d');

pdfjsLib.getDocument('<?= htmlspecialchars($pdfUrl) ?>').promise.then(function(pdf) {
    pdfDoc = pdf;
    document.getElementById('totalPages').textContent = pdf.numPages;
    document.getElementById('pageInput').max = pdf.numPages;
    renderPage(currentPage);
});

function renderPage(num) {
    if (!pdfDoc || num < 1 || num > pdfDoc.numPages) return;
    currentPage = num;
    document.getElementById('pageInput').value = num;

    pdfDoc.getPage(num).then(function(page) {
        // Adapter à la largeur de la fenêtre
        var containerWidth = window.innerWidth;
        var viewport = page.getViewport({ scale: 1 });
        var scale = (containerWidth - 20) / viewport.width;
        // Limiter le scale max pour ne pas pixeliser
        scale = Math.min(scale, 2.5);
        var scaledViewport = page.getViewport({ scale: scale });

        canvas.height = scaledViewport.height;
        canvas.width = scaledViewport.width;

        page.render({
            canvasContext: ctx,
            viewport: scaledViewport
        });
    });
}

function goPage(n) {
    if (n >= 1 && pdfDoc && n <= pdfDoc.numPages) {
        renderPage(n);
    }
}

// Navigation clavier
document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { goPage(currentPage - 1); e.preventDefault(); }
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { goPage(currentPage + 1); e.preventDefault(); }
});

// Resize
window.addEventListener('resize', function() { renderPage(currentPage); });
</script>
</body>
</html>
