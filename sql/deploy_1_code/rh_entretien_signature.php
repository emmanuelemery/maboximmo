<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Accès réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403); exit('Accès réservé aux managers et administrateurs.');
}

$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('Identifiant manquant.'); }

// --- Charger entretien ---
try {
    $stmt = $pdo->prepare("
        SELECT e.*,
               uc.prenom AS collab_prenom, uc.nom AS collab_nom,
               um.prenom AS manager_prenom, um.nom AS manager_nom,
               a.nom_agence
        FROM rh_entretiens e
        JOIN users uc ON uc.id = e.collaborateur_id
        JOIN users um ON um.id = e.manager_id
        LEFT JOIN agences a ON a.id = e.agence_id
        WHERE e.id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); exit('Erreur base de données.');
}

if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }

// Vérifier ownership
if ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId) {
    http_response_code(403); exit('Accès refusé.');
}

// Vérifier statut = 'termine'
if ($entretien['statut'] !== 'termine') {
    http_response_code(403); exit('Cet entretien n\'est pas encore terminé. La signature n\'est possible que sur un entretien au statut "terminé".');
}

// --- Charger synthèse (rubrique 11, visible_pdf=1) ---
$synthese = '';
try {
    $stmtS = $pdo->prepare("
        SELECT texte_final FROM rh_entretien_reponses
        WHERE entretien_id = ? AND rubrique_id = 11 AND visible_pdf = 1
        ORDER BY id DESC LIMIT 1
    ");
    $stmtS->execute([$entretienId]);
    $rowS = $stmtS->fetch(PDO::FETCH_ASSOC);
    if ($rowS) $synthese = $rowS['texte_final'];
} catch (PDOException $e) { /* ignoré */ }

// --- Charger plan d'actions (visible_collaborateur=1) ---
$actions = [];
try {
    $stmtA = $pdo->prepare("
        SELECT * FROM rh_entretien_actions
        WHERE entretien_id = ? AND visible_collaborateur = 1
        ORDER BY ordre ASC, id ASC
    ");
    $stmtA->execute([$entretienId]);
    $actions = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

$erreur  = '';
$success = false;

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sigManager = $_POST['sig_manager'] ?? '';
    $sigCollab  = $_POST['sig_collab']  ?? '';

    if (empty($sigManager) || empty($sigCollab)) {
        $erreur = 'Les deux signatures sont requises.';
    } else {
        try {
            $pdo->beginTransaction();

            // INSERT signature manager
            $stmtIns = $pdo->prepare("
                INSERT INTO signatures (module, entity_id, signataire_type, signataire_id, signature_data, ip_address, statut, signed_at, created_at)
                VALUES ('rh_entretien', ?, 'manager', ?, ?, ?, 'signee', NOW(), NOW())
                ON DUPLICATE KEY UPDATE signature_data=VALUES(signature_data), signed_at=NOW(), ip_address=VALUES(ip_address)
            ");
            $stmtIns->execute([
                $entretienId,
                $userId,
                $sigManager,
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            // INSERT signature collaborateur
            $stmtIns2 = $pdo->prepare("
                INSERT INTO signatures (module, entity_id, signataire_type, signataire_id, signature_data, ip_address, statut, signed_at, created_at)
                VALUES ('rh_entretien', ?, 'collaborateur', ?, ?, ?, 'signee', NOW(), NOW())
                ON DUPLICATE KEY UPDATE signature_data=VALUES(signature_data), signed_at=NOW(), ip_address=VALUES(ip_address)
            ");
            $stmtIns2->execute([
                $entretienId,
                (int)$entretien['collaborateur_id'],
                $sigCollab,
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            // UPDATE entretien
            $pdo->prepare("UPDATE rh_entretiens SET statut = 'signe', verrouille = 1, updated_at = NOW() WHERE id = ?")
                ->execute([$entretienId]);

            $pdo->commit();

            header('Location: rh_entretien_generer_pdf.php?id=' . $entretienId);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreur = 'Erreur lors de l\'enregistrement des signatures.';
            error_log('Signature entretien: ' . $e->getMessage());
        }
    }
}

$typeLabel = [
    'annuel'        => 'Entretien Annuel',
    'professionnel' => 'Entretien Professionnel',
    'mi_annuel'     => 'Entretien Mi-Annuel',
    'recadrage'     => 'Entretien de Recadrage',
    'fin_periode'   => 'Fin de Période d\'Essai',
];
$typeStr = $typeLabel[$entretien['type_entretien'] ?? ''] ?? h($entretien['type_entretien'] ?? '');
$dateStr = !empty($entretien['date_entretien'])
    ? (new DateTime($entretien['date_entretien']))->format('d/m/Y')
    : (!empty($entretien['date_planifiee']) ? (new DateTime($entretien['date_planifiee']))->format('d/m/Y') : '—');

// ── Layout variables ─────────────────────────────────────────────────────
$layout_title   = 'Signature — Compte-rendu d\'entretien';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '<a class="ph-btn" href="rh_entretien_liste.php" style="text-decoration:none">Annuler</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
*, *::before, *::after { box-sizing: border-box; }
.sig-container {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    max-width: 780px;
    width: 100%;
    padding: 2.5rem;
    margin: 0 auto;
}
.sig-header {
    text-align: center;
    border-bottom: 2px solid #e8eaf0;
    padding-bottom: 1.5rem;
    margin-bottom: 1.5rem;
}
.sig-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 .25rem;
}
.sig-header p { color: #64748b; margin: 0; font-size: .9rem; }
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .75rem 1.5rem;
    background: #f8fafc;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    font-size: .9rem;
}
.info-item label { color: #64748b; font-size: .8rem; display: block; }
.info-item strong { color: #1a1a2e; }
.section-title {
    font-size: .85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #4a6038;
    margin: 1.5rem 0 .75rem;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: .4rem;
}
.synthese-text {
    background: #f8fafc;
    border-left: 3px solid #8a5040;
    padding: .75rem 1rem;
    border-radius: 0 6px 6px 0;
    font-size: .9rem;
    line-height: 1.6;
    color: #334155;
    margin-bottom: 1rem;
}
.actions-list { list-style: none; padding: 0; margin: 0; }
.actions-list li {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .5rem 0; border-bottom: 1px solid #f1f5f9;
    font-size: .88rem;
}
.actions-list li:last-child { border-bottom: none; }
.action-check { color: #8a5040; font-size: 1rem; flex-shrink: 0; margin-top: .1rem; }
.action-meta { color: #94a3b8; font-size: .78rem; margin-top: .2rem; }
.sig-zones {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-top: 1.5rem;
}
.signature-zone {
    border: 2px dashed #c4ccd8;
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    transition: border-color .2s;
}
.signature-zone:hover { border-color: #8a5040; }
.signature-zone h3 {
    font-size: .9rem;
    font-weight: 700;
    color: #334155;
    margin: 0 0 .75rem;
}
.signature-zone canvas {
    display: block;
    width: 100%;
    max-width: 340px;
    height: 150px;
    background: #fafbff;
    border-radius: 6px;
    cursor: crosshair;
    margin: 0 auto;
    touch-action: none;
}
.btn-clear {
    margin-top: .6rem;
    background: none;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: .3rem .9rem;
    font-size: .8rem;
    color: #64748b;
    cursor: pointer;
    transition: all .2s;
}
.btn-clear:hover { background: #f1f5f9; border-color: #94a3b8; }
.sig-actions {
    margin-top: 1.5rem;
    display: flex;
    justify-content: center;
    gap: 1rem;
}
.btn-sign {
    background: linear-gradient(135deg, #8a5040, #7a6830);
    color: #fff;
    border: none;
    padding: .75rem 2rem;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .2s;
}
.btn-sign:hover { opacity: .9; }
.btn-cancel {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    padding: .75rem 1.5rem;
    border-radius: 8px;
    font-size: 1rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}
.alert-error {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #b91c1c;
    border-radius: 8px;
    padding: .75rem 1rem;
    margin-bottom: 1rem;
    font-size: .9rem;
}
@media (max-width: 600px) {
    .sig-zones { grid-template-columns: 1fr; }
    .info-grid  { grid-template-columns: 1fr; }
}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function initCanvas(canvasId) {
    const canvas = document.getElementById('canvas-' + canvasId);
    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1a1a2e';
    let drawing = false;

    canvas.addEventListener('mousedown', (e) => {
        drawing = true;
        ctx.beginPath();
        ctx.moveTo(e.offsetX, e.offsetY);
    });
    canvas.addEventListener('mouseup',   () => { drawing = false; });
    canvas.addEventListener('mouseleave',() => { drawing = false; });
    canvas.addEventListener('mousemove', (e) => {
        if (!drawing) return;
        ctx.lineTo(e.offsetX, e.offsetY);
        ctx.stroke();
    });

    canvas.addEventListener('touchstart', (e) => {
        e.preventDefault();
        drawing = true;
        const rect = canvas.getBoundingClientRect();
        const touch = e.touches[0];
        const scaleX = canvas.width  / rect.width;
        const scaleY = canvas.height / rect.height;
        ctx.beginPath();
        ctx.moveTo((touch.clientX - rect.left) * scaleX, (touch.clientY - rect.top) * scaleY);
    }, { passive: false });
    canvas.addEventListener('touchend',   (e) => { e.preventDefault(); drawing = false; }, { passive: false });
    canvas.addEventListener('touchmove',  (e) => {
        e.preventDefault();
        if (!drawing) return;
        const rect  = canvas.getBoundingClientRect();
        const touch = e.touches[0];
        const scaleX = canvas.width  / rect.width;
        const scaleY = canvas.height / rect.height;
        ctx.lineTo((touch.clientX - rect.left) * scaleX, (touch.clientY - rect.top) * scaleY);
        ctx.stroke();
    }, { passive: false });
}

function clearCanvas(canvasId) {
    const canvas = document.getElementById('canvas-' + canvasId);
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function isCanvasBlank(canvas) {
    const ctx    = canvas.getContext('2d');
    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    return !pixels.some(p => p !== 0);
}

function prepareSubmit() {
    const cManager = document.getElementById('canvas-manager');
    const cCollab  = document.getElementById('canvas-collab');
    if (isCanvasBlank(cManager)) { alert('Veuillez apposer la signature du manager.'); return false; }
    if (isCanvasBlank(cCollab))  { alert('Veuillez apposer la signature du collaborateur.'); return false; }
    document.getElementById('sig_manager_input').value = cManager.toDataURL('image/png');
    document.getElementById('sig_collab_input').value  = cCollab.toDataURL('image/png');
    return true;
}

initCanvas('manager');
initCanvas('collab');
</script>
EXTRAJS;

ob_start();
?>
<div class="sig-container">
    <div class="sig-header">
        <h1>Signature du compte-rendu d'entretien</h1>
        <p>Les deux parties doivent signer pour valider le document.</p>
    </div>

    <?php if ($erreur): ?>
    <div class="alert-error"><?= h($erreur) ?></div>
    <?php endif; ?>

    <div class="info-grid">
        <div class="info-item">
            <label>Collaborateur</label>
            <strong><?= h($entretien['collab_prenom'] . ' ' . $entretien['collab_nom']) ?></strong>
        </div>
        <div class="info-item">
            <label>Manager</label>
            <strong><?= h($entretien['manager_prenom'] . ' ' . $entretien['manager_nom']) ?></strong>
        </div>
        <div class="info-item">
            <label>Type d'entretien</label>
            <strong><?= h($typeStr) ?></strong>
        </div>
        <div class="info-item">
            <label>Date</label>
            <strong><?= h($dateStr) ?></strong>
        </div>
        <?php if (!empty($entretien['nom_agence'])): ?>
        <div class="info-item">
            <label>Agence</label>
            <strong><?= h($entretien['nom_agence']) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($synthese)): ?>
    <div class="section-title">Synthèse</div>
    <div class="synthese-text"><?= nl2br(h($synthese)) ?></div>
    <?php endif; ?>

    <?php if (!empty($actions)): ?>
    <div class="section-title">Plan d'actions</div>
    <ul class="actions-list">
        <?php foreach ($actions as $act): ?>
        <li>
            <span class="action-check">&#9723;</span>
            <div>
                <div><?= h($act['action'] ?? $act['titre'] ?? '') ?></div>
                <div class="action-meta">
                    <?php if (!empty($act['responsable'])): ?>Responsable : <?= h($act['responsable']) ?><?php endif; ?>
                    <?php if (!empty($act['echeance'])): ?> &mdash; Échéance : <?= h((new DateTime($act['echeance']))->format('d/m/Y')) ?><?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="POST" id="form-signature">
        <?= csrf_field() ?>
        <input type="hidden" name="sig_manager" id="sig_manager_input">
        <input type="hidden" name="sig_collab"  id="sig_collab_input">

        <div class="sig-zones">
            <div class="signature-zone">
                <h3>Signature du Manager</h3>
                <canvas id="canvas-manager" width="340" height="150"></canvas>
                <button type="button" class="btn-clear" onclick="clearCanvas('manager')">Effacer</button>
            </div>
            <div class="signature-zone">
                <h3>Signature du Collaborateur</h3>
                <canvas id="canvas-collab" width="340" height="150"></canvas>
                <button type="button" class="btn-clear" onclick="clearCanvas('collab')">Effacer</button>
            </div>
        </div>

        <div class="sig-actions">
            <a href="rh_entretien_liste.php" class="btn-cancel">Annuler</a>
            <button type="submit" class="btn-sign" onclick="return prepareSubmit()">Valider et Générer le PDF</button>
        </div>
    </form>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
