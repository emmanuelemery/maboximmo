<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('Identifiant manquant.'); }

$isManager = ($roleId === 1 || $roleId === 2);
$isCollab  = ($roleId >= 3);

// --- Charger entretien ---
try {
    $stmt = $pdo->prepare("
        SELECT e.*,
               uc.prenom AS collab_prenom, uc.nom AS collab_nom, uc.fonction AS collab_poste,
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

// Accès : manager voit ses entretiens, admin tout, collab voit les siens
if ($isManager && $roleId !== 1 && (int)$entretien['manager_id'] !== $userId) {
    http_response_code(403); exit('Accès refusé.');
}
if ($isCollab && (int)$entretien['collaborateur_id'] !== $userId) {
    http_response_code(403); exit('Accès refusé.');
}

// Filtres selon rôle
$visFilter = $isCollab
    ? " AND r.visible_collaborateur = 1 AND r.rubrique_id != 9"
    : "";

// --- Charger réponses ---
$reponses = [];
try {
    $stmtR = $pdo->prepare("
        SELECT r.*, c.label AS critere_label
        FROM rh_entretien_reponses r
        LEFT JOIN rh_entretien_criteres c ON c.id = r.critere_id
        WHERE r.entretien_id = ?
        $visFilter
        ORDER BY r.rubrique_id ASC, r.id ASC
    ");
    $stmtR->execute([$entretienId]);
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $rep) {
        $reponses[(int)$rep['rubrique_id']][] = $rep;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Charger actions ---
$actions = [];
try {
    $condA = $isCollab ? " AND a.visible_collaborateur = 1" : "";
    $stmtA = $pdo->prepare("SELECT a.*, CONCAT(u.prenom, ' ', u.nom) AS responsable FROM rh_entretien_actions a LEFT JOIN users u ON u.id = a.responsable_id WHERE a.entretien_id = ? $condA ORDER BY a.id ASC");
    $stmtA->execute([$entretienId]);
    $actions = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// --- Charger signatures ---
$signatures = [];
try {
    $stmtSig = $pdo->prepare("SELECT * FROM signatures WHERE module = 'rh_entretien' AND entity_id = ? ORDER BY signed_at ASC");
    $stmtSig->execute([$entretienId]);
    $signatures = $stmtSig->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// --- Scores radar (table 1 ligne par axe, scores 0–1) ---
$rawScores = [];
try {
    $stmtSc = $pdo->prepare("SELECT axe, score FROM rh_entretien_scores WHERE entretien_id = ?");
    $stmtSc->execute([$entretienId]);
    foreach ($stmtSc->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $rawScores[$s['axe']] = (float)$s['score'];
    }
} catch (PDOException $e) { /* ignoré */ }

// Normaliser et mapper sur les axes affichés
$scores = [
    'performance'  => $rawScores['performance'] ?? 0,
    'motivation'   => $rawScores['motivation'] ?? 0,
    // mapping pour rester compatible avec l'UI actuelle
    'comportement' => $rawScores['comportement'] ?? ($rawScores['relationnel'] ?? 0),
    'competences'  => $rawScores['competences'] ?? ($rawScores['maitrise_poste'] ?? 0),
    'adaptabilite' => $rawScores['adaptabilite'] ?? 0,
];

// Passage en échelle 0–5 pour l'affichage
foreach ($scores as $k => $v) {
    $scores[$k] = round($v * 5, 1);
}

// Noms rubriques
$rubriquesNoms = [
    1  => 'Ouverture',
    2  => 'Bilan collaborateur',
    3  => 'Valorisation',
    4  => 'Objectifs',
    5  => 'Compétences',
    6  => 'Formation & Développement',
    7  => 'Motivation & Engagement',
    8  => 'Conditions de travail',
    9  => 'Rémunération',
    10 => 'Perspectives',
    11 => 'Synthèse',
];

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

$statutBadge = [
    'planifie'             => ['Planifié',     '#8899aa'],
    'en_cours'             => ['En cours',     '#ffa040'],
    'termine'              => ['Terminé',      '#22c55e'],
    'signe'                => ['Signé',        '#3b82f6'],
    'archive'              => ['Archivé',      '#6366f1'],
];
$sbInfo = $statutBadge[$entretien['statut'] ?? ''] ?? ['Inconnu', '#94a3b8'];

$syntheseText = '';
if (!empty($reponses[11])) {
    foreach ($reponses[11] as $rep) {
        if (!empty($rep['texte_final'])) { $syntheseText = $rep['texte_final']; break; }
    }
}

$jsScores  = json_encode($scores);
$jsReponses = json_encode($reponses);

// ============================================================
// LAYOUT VARIABLES
// ============================================================
$layout_title       = 'Détail entretien — ' . $entretien['collab_prenom'] . ' ' . $entretien['collab_nom'];
$layout_module      = 'Ma Box RH';
$layout_sidebar     = 'rh_sidebar';
$layout_head_kpis   = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg-soft, #f5f6fa); margin: 0; }
.layout { display: flex; width: calc(100% - var(--rh-sidebar-w)); margin-left: var(--rh-sidebar-w); min-height: 100vh; }
.content-area { flex: 1; min-width: 0; padding: 1.5rem; overflow-y: auto; }
.page-header { background: #fff; border-radius: 10px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.page-header-main { flex: 1; }
.page-header h1 { font-size: 1.2rem; font-weight: 700; color: #1a1a2e; margin: 0 0 .25rem; }
.page-header-meta { font-size: .82rem; color: #64748b; display: flex; gap: 1rem; flex-wrap: wrap; margin-top: .3rem; }
.badge { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .7rem; border-radius: 20px; font-size: .78rem; font-weight: 600; }
.page-header-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
.btn { padding: .5rem 1rem; border-radius: 7px; font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; border: 1px solid transparent; transition: all .2s; }
.btn-primary { background: linear-gradient(135deg, #ffa040, #ff7c00); color: #fff; }
.btn-outline  { background: #fff; color: #334155; border-color: #e2e8f0; }
.btn-outline:hover { background: #f8fafc; }

.grid-top { display: grid; grid-template-columns: 1fr 320px; gap: 1.25rem; margin-bottom: 1.25rem; }
@media (max-width: 900px) { .grid-top { grid-template-columns: 1fr; } .layout { width: 100%; margin-left: 0; } }

.card { background: #fff; border-radius: 10px; padding: 1.25rem; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.card-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin: 0 0 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: .5rem; }
.radar-wrap { position: relative; height: 240px; display: flex; justify-content: center; }

.tabs-nav { display: flex; gap: .25rem; background: #fff; border-radius: 10px 10px 0 0; padding: .5rem .5rem 0; box-shadow: 0 1px 0 #e2e8f0; margin-bottom: 0; }
.tab-btn { background: none; border: none; padding: .55rem 1.1rem; border-radius: 7px 7px 0 0; font-size: .87rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; transition: all .15s; }
.tab-btn.active { color: #ffa040; border-bottom-color: #ffa040; background: #fff9f5; }
.tab-pane { display: none; background: #fff; border-radius: 0 0 10px 10px; padding: 1.5rem; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.tab-pane.active { display: block; }

.synthese-box { background: #fffbf5; border-left: 4px solid #ffa040; padding: 1rem 1.25rem; border-radius: 0 8px 8px 0; font-size: .92rem; line-height: 1.7; color: #334155; margin-bottom: 1rem; white-space: pre-wrap; }
.profil-badge { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem 1rem; border-radius: 20px; font-size: .85rem; font-weight: 700; margin-top: .75rem; }
.profil-excellent    { background: rgba(34,197,94,.15);  color: #166534; }
.profil-performant   { background: rgba(59,130,246,.15); color: #1e40af; }
.profil-a_accompagner{ background: rgba(251,191,36,.15); color: #92400e; }
.profil-a_risque     { background: rgba(239,68,68,.15);  color: #991b1b; }

.rubrique-section { margin-bottom: 1.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 1rem; }
.rubrique-name { font-size: .95rem; font-weight: 700; color: #1a1a2e; margin: 0 0 .75rem; display: flex; align-items: center; gap: .5rem; }
.rubrique-num { background: #ffa040; color: #fff; font-size: .7rem; font-weight: 700; padding: .15rem .45rem; border-radius: 4px; }
.critere-row { display: flex; align-items: flex-start; gap .75rem; padding: .4rem 0; border-bottom: 1px dashed #f1f5f9; }
.critere-row:last-child { border-bottom: none; }
.critere-label { flex: 1; font-size: .87rem; color: #334155; }
.stars { color: #ffa040; font-size: 1rem; letter-spacing: .05em; }
.stars-empty { color: #e2e8f0; }
.critere-texte { font-size: .82rem; color: #64748b; margin-top: .2rem; font-style: italic; }

.actions-table { width: 100%; border-collapse: collapse; font-size: .87rem; }
.actions-table th { background: #f8fafc; padding: .6rem .8rem; text-align: left; font-size: .78rem; color: #4a6038; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e2e8f0; }
.actions-table td { padding: .55rem .8rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.statut-action { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .6rem; border-radius: 12px; font-size: .75rem; font-weight: 600; }
.statut-todo     { background: rgba(148,163,184,.15); color: #475569; }
.statut-en_cours { background: rgba(255,160,64,.15);  color: #b45309; }
.statut-fait     { background: rgba(34,197,94,.15);   color: #166534; }

.sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
@media (max-width: 600px) { .sig-grid { grid-template-columns: 1fr; } }
.sig-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; text-align: center; }
.sig-card h4 { font-size: .9rem; color: #334155; margin: 0 0 .75rem; }
.sig-card img { max-width: 100%; border: 1px dashed #c4ccd8; border-radius: 6px; background: #fafbff; }
.sig-date { font-size: .78rem; color: #94a3b8; margin-top: .5rem; }
.no-sig { color: #94a3b8; font-style: italic; font-size: .87rem; }

.select-statut { font-size: .8rem; padding: .25rem .5rem; border-radius: 5px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; }
</style>
EXTRACSS;

ob_start();
?>
        <!-- En-tête -->
        <div class="page-header">
            <div class="page-header-main">
                <h1><?= h($entretien['collab_prenom'] . ' ' . $entretien['collab_nom']) ?> — <?= h($typeStr) ?></h1>
                <div class="page-header-meta">
                    <span>Manager : <?= h($entretien['manager_prenom'] . ' ' . $entretien['manager_nom']) ?></span>
                    <span>Date : <?= h($dateStr) ?></span>
                    <?php if (!empty($entretien['nom_agence'])): ?>
                    <span>Agence : <?= h($entretien['nom_agence']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="margin-top:.5rem;">
                    <span class="badge" style="background:<?= $sbInfo[1] ?>22; color:<?= $sbInfo[1] ?>;">
                        <?= h($sbInfo[0]) ?>
                    </span>
                    <?php if (!empty($entretien['verrouille']) && $entretien['verrouille']): ?>
                    <span class="badge" style="background:rgba(99,102,241,.1);color:#4338ca;margin-left:.4rem;">Verrouillé</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isManager): ?>
            <div class="page-header-actions">
                <?php if (in_array($entretien['statut'], ['signe', 'termine'], true)): ?>
                <a href="rh_entretien_generer_pdf.php?id=<?= $entretienId ?>" target="_blank" class="btn btn-primary">📄 Générer PDF</a>
                <?php endif; ?>
                <?php if ($entretien['statut'] === 'termine'): ?>
                <a href="rh_entretien_signature.php?id=<?= $entretienId ?>" class="btn btn-outline">✍️ Signatures</a>
                <?php endif; ?>
                <a href="rh_entretien_liste.php" class="btn btn-outline">← Retour liste</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Radar + scores -->
        <div class="grid-top">
            <div class="card">
                <div class="card-title">Radar des compétences</div>
                <div class="radar-wrap">
                    <canvas id="radarChart"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Scores détaillés</div>
                <?php
                $scoreLabels = [
                    'performance'   => 'Performance',
                    'motivation'    => 'Motivation',
                    'comportement'  => 'Comportement',
                    'competences'   => 'Compétences',
                    'adaptabilite'  => 'Adaptabilité',
                ];
                foreach ($scoreLabels as $key => $label):
                    $val = $scores[$key] ?? 0;
                    $pct = min(100, ($val / 5) * 100);
                ?>
                <div style="margin-bottom:.75rem;">
                    <div style="display:flex;justify-content:space-between;font-size:.83rem;color:#334155;margin-bottom:.25rem;">
                        <span><?= h($label) ?></span><span style="font-weight:700;color:#ffa040;"><?= h((string)$val) ?>/5</span>
                    </div>
                    <div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
                        <div style="height:100%;width:<?= (int)$pct ?>%;background:linear-gradient(90deg,#ffa040,#ff7c00);border-radius:3px;transition:width .4s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (!empty($entretien['profil_auto'])): ?>
                <div>
                    <?php
                    $profilMap = ['excellent' => 'Excellent', 'performant' => 'Performant', 'a_accompagner' => 'À accompagner', 'a_risque' => 'À risque'];
                    $profil = $entretien['profil_auto'];
                    ?>
                    <span class="profil-badge profil-<?= h($profil) ?>"><?= h($profilMap[$profil] ?? $profil) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchTab('synthese')">Synthèse</button>
            <button class="tab-btn" onclick="switchTab('rubriques')">Rubriques</button>
            <button class="tab-btn" onclick="switchTab('actions')">Plan d'actions</button>
            <button class="tab-btn" onclick="switchTab('signatures')">Signatures</button>
        </div>

        <!-- Onglet Synthèse -->
        <div id="tab-synthese" class="tab-pane active">
            <?php if ($syntheseText): ?>
            <div class="synthese-box"><?= h($syntheseText) ?></div>
            <?php else: ?>
            <p style="color:#94a3b8;font-style:italic;">Pas de synthèse disponible.</p>
            <?php endif; ?>
        </div>

        <!-- Onglet Rubriques -->
        <div id="tab-rubriques" class="tab-pane">
            <?php
            $ordreRubriques = $isCollab
                ? [1,2,3,4,5,6,7,8,10,11]
                : [1,2,3,4,5,6,7,8,9,10,11];
            foreach ($ordreRubriques as $rid):
                if (empty($reponses[$rid])) continue;
            ?>
            <div class="rubrique-section">
                <div class="rubrique-name">
                    <span class="rubrique-num"><?= $rid ?></span>
                    <?= h($rubriquesNoms[$rid] ?? 'Rubrique ' . $rid) ?>
                </div>
                <?php foreach ($reponses[$rid] as $rep): ?>
                <div class="critere-row">
                    <div style="flex:1;">
                        <?php if (!empty($rep['critere_label'])): ?>
                        <div class="critere-label"><?= h($rep['critere_label']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($rep['texte_final'])): ?>
                        <div class="critere-texte"><?= h($rep['texte_final']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($rep['remarque_collaborateur'])): ?>
                        <div style="font-size:.8rem;color:#64748b;margin-top:.2rem;background:#fffbf5;border-left:2px solid #ffa040;padding:.2rem .5rem;border-radius:0 4px 4px 0;">
                            Remarque : <?= h($rep['remarque_collaborateur']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($rep['note']) && (int)$rep['note'] > 0): ?>
                    <div style="flex-shrink:0;">
                        <span class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?= ($i <= (int)$rep['note']) ? '★' : '☆' ?>
                        <?php endfor; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Onglet Plan d'actions -->
        <div id="tab-actions" class="tab-pane">
            <?php if (empty($actions)): ?>
            <p style="color:#94a3b8;font-style:italic;">Aucune action définie.</p>
            <?php else: ?>
            <table class="actions-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Responsable</th>
                        <th>Échéance</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($actions as $act): ?>
                <tr>
                    <td><?= h($act['libelle'] ?? '') ?></td>
                    <td><?= h($act['responsable'] ?? '—') ?></td>
                    <td><?= !empty($act['echeance']) ? h((new DateTime($act['echeance']))->format('d/m/Y')) : '—' ?></td>
                    <td>
                        <?php if ($isManager): ?>
                        <select class="select-statut"
                                onchange="updateActionStatut(<?= (int)$act['id'] ?>, this.value)"
                                data-id="<?= (int)$act['id'] ?>">
                            <?php foreach (['todo' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'] as $sv => $sl): ?>
                            <option value="<?= $sv ?>" <?= ($act['statut'] ?? 'todo') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <?php
                        $sAct = $act['statut'] ?? 'todo';
                        $sActMap = ['todo' => ['À faire','statut-todo'], 'en_cours' => ['En cours','statut-en_cours'], 'fait' => ['Fait','statut-fait']];
                        $sActInfo = $sActMap[$sAct] ?? ['Inconnu','statut-todo'];
                        ?>
                        <span class="statut-action <?= $sActInfo[1] ?>"><?= $sActInfo[0] ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Onglet Signatures -->
        <div id="tab-signatures" class="tab-pane">
            <?php if (empty($signatures)): ?>
            <p style="color:#94a3b8;font-style:italic;">Aucune signature enregistrée.</p>
            <?php else: ?>
            <div class="sig-grid">
                <?php foreach ($signatures as $sig): ?>
                <div class="sig-card">
                    <h4>Signature <?= h(ucfirst($sig['signataire_type'])) ?></h4>
                    <?php if (!empty($sig['signature_data'])): ?>
                    <img src="<?= h($sig['signature_data']) ?>" alt="Signature" style="max-height:120px;">
                    <?php else: ?>
                    <p class="no-sig">Données de signature indisponibles.</p>
                    <?php endif; ?>
                    <?php if (!empty($sig['signed_at'])): ?>
                    <div class="sig-date">Signé le <?= h((new DateTime($sig['signed_at']))->format('d/m/Y à H:i')) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

<?php
$layout_content = ob_get_clean();

$_jsScores = $jsScores;
$_updateActionJs = '';
if ($isManager) {
    $_updateActionJs = <<<'JSBLOCK'

// AJAX mise à jour statut action
function updateActionStatut(actionId, statut) {
    fetch('api/rh_entretien_update_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action_id: actionId, statut: statut })
    })
    .then(r => r.json())
    .then(d => { if (!d.success) alert('Erreur mise à jour : ' + (d.error || '')); })
    .catch(() => alert('Erreur réseau.'));
}
JSBLOCK;
}

$layout_extra_js = <<<EXTRAJS
<script>
// Tabs
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}

// Radar Chart.js
const scores = {$_jsScores};
const radarLabels = ['Performance','Motivation','Comportement','Compétences','Adaptabilité'];
const radarData   = [scores.performance, scores.motivation, scores.comportement, scores.competences, scores.adaptabilite];

const ctx = document.getElementById('radarChart').getContext('2d');
new Chart(ctx, {
    type: 'radar',
    data: {
        labels: radarLabels,
        datasets: [{
            label: 'Scores',
            data: radarData,
            backgroundColor: 'rgba(255,160,64,0.15)',
            borderColor: '#ffa040',
            borderWidth: 2,
            pointBackgroundColor: '#ffa040',
            pointRadius: 4,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            r: {
                min: 0, max: 5,
                ticks: { stepSize: 1, font: { size: 10 } },
                pointLabels: { font: { size: 11 } },
                grid: { color: '#e2e8f0' },
            }
        },
        plugins: { legend: { display: false } }
    }
});
{$_updateActionJs}
</script>
EXTRAJS;

require_once __DIR__ . '/inc/layout_maboximmo.php';
