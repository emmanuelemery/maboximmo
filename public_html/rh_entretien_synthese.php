<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403); exit('Accès réservé managers.');
}

$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('ID manquant.'); }

// Charger l'entretien
$entretien = null;
try {
    $stmt = $pdo->prepare("
        SELECT e.*, u.prenom AS collab_prenom, u.nom AS collab_nom,
               u.fonction AS collab_poste, u.email AS collab_email,
               m.prenom AS mgr_prenom, m.nom AS mgr_nom
        FROM rh_entretiens e
        JOIN users u ON u.id = e.collaborateur_id
        LEFT JOIN users m ON m.id = e.manager_id
        WHERE e.id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }
if ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId) { http_response_code(403); exit('Accès refusé.'); }

// Charger rubriques et critères depuis DB
$rubriques = [];
$critereMap = []; // id => critere
try {
    $stmtR = $pdo->prepare("SELECT * FROM rh_entretien_rubriques WHERE actif=1 ORDER BY ordre");
    $stmtR->execute();
    $stmtC = $pdo->prepare("SELECT * FROM rh_entretien_criteres WHERE actif=1 ORDER BY rubrique_id, ordre");
    $stmtC->execute();
    $allCrit = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allCrit as $c) { $critereMap[(int)$c['id']] = $c; }
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rubriques[(int)$r['ordre']] = array_merge($r, [
            'criteres' => array_filter($allCrit, fn($c) => (int)$c['rubrique_id'] === (int)$r['id']),
        ]);
    }
} catch (PDOException $e) {}

// Charger les réponses manager
$reponses = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM rh_entretien_reponses WHERE entretien_id = ?");
    $stmt->execute([$entretienId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reponses[$r['rubrique_id']][$r['critere_id']] = $r;
    }
} catch (PDOException $e) {}

// Charger les scores
$scores = [];
try {
    $stmt = $pdo->prepare("SELECT axe, score FROM rh_entretien_scores WHERE entretien_id = ?");
    $stmt->execute([$entretienId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $scores[$s['axe']] = (float)$s['score'];
    }
} catch (PDOException $e) {}

// Charger les alertes
$alertes = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM rh_entretien_alertes WHERE entretien_id = ? ORDER BY created_at DESC");
    $stmt->execute([$entretienId]);
    $alertes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Charger le plan d'action
$actions = [];
try {
    $stmt = $pdo->prepare("SELECT a.*, u.prenom, u.nom FROM rh_entretien_actions a LEFT JOIN users u ON u.id=a.responsable_id WHERE a.entretien_id = ? ORDER BY a.echeance");
    $stmt->execute([$entretienId]);
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Calcul score global
$scoreGlobal = 0;
$totalPoids  = 0;
foreach ($rubriques as $rub) {
    foreach ($rub['criteres'] as $crit) {
        $rep = $reponses[$rub['ordre']][$crit['id']] ?? null;
        if ($rep && isset($rep['note']) && $rep['note'] > 0) {
            $poids = (float)($crit['poids_score'] ?? 1.0);
            $scoreGlobal += (float)$rep['note'] * $poids;
            $totalPoids  += 5 * $poids;
        }
    }
}
$scoreGlobalPct = $totalPoids > 0 ? round(($scoreGlobal / $totalPoids) * 100) : 0;

// Profil automatique
function detecterProfil(float $pct, array $scores): string {
    $motivation = $scores['motivation'] ?? 0;
    $performance = $scores['performance'] ?? 0;
    $potentiel = $scores['potentiel'] ?? 0;
    $engagement = $scores['engagement'] ?? $scores['comportement'] ?? 0;
    if ($pct >= 80 && $motivation >= 0.75 && $performance >= 0.75) return 'pilier';
    if ($pct >= 65 && $potentiel >= 0.70) return 'potentiel';
    if ($pct >= 55 && $pct < 80) return 'progression';
    if ($pct >= 35 && $pct < 55) return 'accompagner';
    return 'risque';
}
$profil = $entretien['profil_auto'] ?? detecterProfil($scoreGlobalPct, $scores);
$profilLabels = ['pilier'=>'Pilier fiable','potentiel'=>'Profil à potentiel','progression'=>'Profil en progression','accompagner'=>'Profil à accompagner','risque'=>'Profil à risque'];
$profilColors = ['pilier'=>'#166534','potentiel'=>'#1d4ed8','progression'=>'#0369a1','accompagner'=>'#92400e','risque'=>'#991b1b'];
$profilBg     = ['pilier'=>'#dcfce7','potentiel'=>'#dbeafe','progression'=>'#e0f2fe','accompagner'=>'#fef3c7','risque'=>'#fee2e2'];

// Axes radar (depuis les données)
$axeLabels = ['performance'=>'Performance','motivation'=>'Motivation','relationnel'=>'Relationnel','adaptabilite'=>'Adaptabilité','autonomie'=>'Autonomie','potentiel'=>'Potentiel','organisation'=>'Organisation','maitrise_poste'=>'Maîtrise poste','digital'=>'Digital','engagement'=>'Engagement','fiabilite'=>'Fiabilité','competences'=>'Compétences'];
$radarAxes = array_filter(array_keys($axeLabels), fn($a) => isset($scores[$a]));
if (empty($radarAxes)) $radarAxes = array_keys($scores);

$jsScores = json_encode($scores);
$jsRadarAxes = json_encode(array_values($radarAxes));
$jsRadarLabels = json_encode(array_values(array_map(fn($a) => $axeLabels[$a] ?? $a, $radarAxes)));

// ============================================================
// LAYOUT VARIABLES
// ============================================================
$layout_title       = 'Synthèse — ' . $entretien['collab_prenom'] . ' ' . $entretien['collab_nom'];
$layout_module      = 'Ma Box RH';
$layout_sidebar     = 'rh_sidebar';
$layout_head_kpis   = '';
$layout_head_actions = '<a href="rh_entretien_tenir.php?id=' . $entretienId . '" class="ph-btn ph-btn-outline" style="font-size:13px">Continuer l\'entretien</a>'
    . ' <a href="rh_entretien_generer_pdf.php?id=' . $entretienId . '" class="ph-btn ph-btn-outline" style="font-size:13px" target="_blank">PDF</a>'
    . ' <a href="rh_entretien_signature.php?id=' . $entretienId . '" class="ph-btn" style="font-size:13px">Signatures</a>'
    . ' <a href="rh_entretien_liste.php" class="ph-btn ph-btn-outline" style="font-size:13px">&#8592; Liste</a>';

$layout_extra_css = <<<'EXTRACSS'
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--bg-soft,#f5f6fa); color:var(--ink,#1a1a2e); margin:0; }
        .top-bar { background:#fff; border-bottom:1px solid #e2e8f0; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
        .top-bar h1 { font-size:1rem; font-weight:700; margin:0; }
        .top-bar-actions { margin-left:auto; display:flex; gap:.5rem; }
        .btn { padding:.45rem 1rem; border-radius:7px; border:none; cursor:pointer; font-size:.82rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:.3rem; }
        .btn-primary { background:var(--btn-save-from); color:#fff; }
        .btn-outline { background:#fff; border:1px solid #e2e8f0; color:#374151; }
        .container { max-width:1200px; margin:1.5rem auto; padding:0 1.5rem; display:grid; grid-template-columns: 1fr 380px; gap:1.5rem; }
        @media(max-width:900px){ .container { grid-template-columns:1fr; } }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
        .card h2 { font-size:.85rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin:0 0 1rem; }
        .score-global { text-align:center; padding:1.5rem; }
        .score-number { font-size:3.5rem; font-weight:900; color:var(--accent,var(--btn-save-from)); line-height:1; }
        .score-label  { font-size:.85rem; color:#64748b; margin-top:.35rem; }
        .score-bar { height:10px; background:#f1f5f9; border-radius:99px; margin:.75rem 0; overflow:hidden; }
        .score-bar-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--btn-save-from),var(--btn-save-to)); transition:width 1s; }
        .profil-badge { display:inline-block; padding:.5rem 1.25rem; border-radius:99px; font-size:.9rem; font-weight:800; margin-top:.75rem; }
        .axes-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem .75rem; }
        .axe-row { display:flex; flex-direction:column; gap:.2rem; }
        .axe-label { font-size:.72rem; font-weight:600; color:#64748b; display:flex; justify-content:space-between; }
        .axe-bar { height:6px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
        .axe-bar-fill { height:100%; border-radius:99px; }
        .alertes-list .alerte { display:flex; align-items:flex-start; gap:.5rem; padding:.55rem .75rem; border-radius:7px; margin-bottom:.4rem; font-size:.8rem; }
        .alerte.danger  { background:#fef2f2; color:#991b1b; }
        .alerte.warning { background:#fef9c3; color:#854d0e; }
        .alerte.info    { background:#eff6ff; color:#1d4ed8; }
        .reponses-section { margin-bottom:1.25rem; }
        .rub-title { font-size:.82rem; font-weight:700; color:#374151; margin:.75rem 0 .5rem; padding:.3rem .65rem; background:#f8fafc; border-radius:6px; border-left:3px solid var(--btn-save-from); }
        .crit-row { padding:.5rem .65rem; border-bottom:1px solid #f1f5f9; font-size:.8rem; }
        .crit-row:last-child { border:none; }
        .crit-note { display:inline-flex; gap:1px; }
        .star { color:var(--btn-save-from); font-size:.7rem; }
        .star.empty { color:#e2e8f0; }
        .crit-texte { color:#475569; margin-top:.2rem; font-size:.78rem; line-height:1.4; }
        .actions-table { width:100%; border-collapse:collapse; font-size:.8rem; }
        .actions-table th { background:#f8fafc; padding:.4rem .65rem; text-align:left; font-size:.7rem; font-weight:700; text-transform:uppercase; color:#4a6038; }
        .actions-table td { padding:.45rem .65rem; border-bottom:1px solid #f1f5f9; }
        .statut-badge { font-size:.65rem; font-weight:700; padding:.15rem .5rem; border-radius:99px; }
        .st-a_faire    { background:#fff7ed; color:#c2410c; }
        .st-en_cours   { background:#dbeafe; color:#1d4ed8; }
        .st-realise    { background:#dcfce7; color:#166534; }
        .st-reporte    { background:#fef3c7; color:#92400e; }
        .compte-rendu  { font-size:.85rem; line-height:1.7; color:#374151; }
        .cr-section    { margin-bottom:1.25rem; }
        .cr-section h3 { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--btn-save-from); margin:0 0 .4rem; }
        @media print {
            .top-bar-actions, .btn { display:none; }
            body { background:#fff; }
        }
    </style>
EXTRACSS;

ob_start();
?>
<div class="container">
    <!-- Colonne principale -->
    <div>
        <!-- Score global -->
        <div class="card score-global">
            <h2>Score global</h2>
            <div class="score-number"><?= $scoreGlobalPct ?>%</div>
            <div class="score-label">Score pondéré — <?= $totalPoids > 0 ? round($scoreGlobal,1).'/'.$totalPoids : 'Évaluation incomplète' ?></div>
            <div class="score-bar"><div class="score-bar-fill" id="scoreBar" style="width:<?= $scoreGlobalPct ?>%"></div></div>
            <div>
                <span class="profil-badge" style="background:<?= $profilBg[$profil] ?? '#f1f5f9' ?>;color:<?= $profilColors[$profil] ?? '#475569' ?>">
                    <?= h($profilLabels[$profil] ?? $profil) ?>
                </span>
            </div>
        </div>

        <!-- Compte rendu généré -->
        <div class="card">
            <h2>Compte rendu automatique</h2>
            <div class="compte-rendu">
                <?php
                $collabNom = h($entretien['collab_prenom'].' '.$entretien['collab_nom']);
                $date = $entretien['date_realisation'] ? date('d/m/Y', strtotime($entretien['date_realisation'])) : date('d/m/Y');
                $mgrNom = h(($entretien['mgr_prenom'] ?? '').' '.($entretien['mgr_nom'] ?? ''));
                ?>
                <div class="cr-section">
                    <h3>Ouverture</h3>
                    <p>L'entretien individuel de <?= $collabNom ?> s'est tenu le <?= $date ?> avec <?= $mgrNom ?>. Ce document constitue le compte rendu officiel de cet entretien.</p>
                </div>
                <?php
                // Générer synthèse par rubrique visible
                foreach ($rubriques as $rNum => $rub):
                    if (!empty($rub['manager_only'])) continue;
                    $rubReponses = $reponses[$rNum] ?? [];
                    $hasContent = false;
                    foreach ($rub['criteres'] as $crit) {
                        if (!empty($rubReponses[$crit['id']]['texte_final'])) { $hasContent = true; break; }
                    }
                    if (!$hasContent) continue;
                ?>
                <div class="cr-section">
                    <h3><?= h($rub['nom']) ?></h3>
                    <?php foreach ($rub['criteres'] as $crit):
                        $rep = $rubReponses[$crit['id']] ?? null;
                        if (!$rep || empty($rep['texte_final'])) continue;
                        $texte = $rep['texte_final'];
                        // Si JSON (bloc spécial), extraire
                        $decoded = json_decode($texte, true);
                        if (is_array($decoded)) $texte = implode(' — ', array_filter($decoded));
                    ?>
                    <p><strong><?= h($crit['label']) ?> :</strong>
                    <?php if ($rep['note'] ?? 0): ?>
                        <?= str_repeat('★', (int)$rep['note']) ?><?= str_repeat('☆', 5-(int)$rep['note']) ?> —
                    <?php endif; ?>
                    <?= h($texte) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php if (!empty($actions)): ?>
                <div class="cr-section">
                    <h3>Plan d'action</h3>
                    <p>Les actions suivantes ont été définies lors de cet entretien :</p>
                    <ul>
                    <?php foreach ($actions as $action): ?>
                        <li><?= h($action['libelle'] ?? '') ?><?= $action['echeance'] ? ' (échéance : '.date('d/m/Y', strtotime($action['echeance'])).')' : '' ?></li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="cr-section">
                    <h3>Conclusion</h3>
                    <p>Ce compte rendu a été établi à l'issue de l'entretien. Il sera transmis pour validation et signature aux deux parties.</p>
                    <p>Score global : <strong><?= $scoreGlobalPct ?>%</strong> — Profil : <strong><?= h($profilLabels[$profil] ?? $profil) ?></strong></p>
                </div>
            </div>
        </div>

        <!-- Réponses détaillées -->
        <div class="card">
            <h2>Détail des évaluations</h2>
            <?php foreach ($rubriques as $rNum => $rub): ?>
            <?php if (!empty($rub['manager_only'])) continue; ?>
            <div class="reponses-section">
                <div class="rub-title"><?= $rNum ?>. <?= h($rub['nom']) ?></div>
                <?php foreach ($rub['criteres'] as $crit):
                    $rep = $reponses[$rNum][$crit['id']] ?? null;
                    if (!($rep['note'] ?? 0) && empty($rep['texte_final'])) continue;
                ?>
                <div class="crit-row">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                        <strong style="font-size:.8rem;"><?= h($crit['label']) ?></strong>
                        <?php if ($rep['note'] ?? 0): ?>
                        <div class="crit-note">
                            <?php for ($s=1;$s<=5;$s++): ?>
                            <span class="star <?= (int)$rep['note'] < $s ? 'empty' : '' ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($rep['texte_final'])):
                        $texte = $rep['texte_final'];
                        $d = json_decode($texte, true);
                        if (is_array($d)) $texte = implode(' / ', array_filter(array_values($d)));
                    ?>
                    <div class="crit-texte"><?= h(mb_strimwidth($texte, 0, 200, '…')) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Colonne droite -->
    <div>
        <!-- Radar -->
        <div class="card">
            <h2>Radar de compétences</h2>
            <canvas id="radarChart" height="280"></canvas>
        </div>

        <!-- Axes détaillés -->
        <div class="card">
            <h2>Scores par axe</h2>
            <div class="axes-grid">
                <?php
                $axeBarColors = ['performance'=>'var(--btn-save-from)','motivation'=>'#8b5cf6','relationnel'=>'#06b6d4','adaptabilite'=>'#10b981','autonomie'=>'#f59e0b','potentiel'=>'#3b82f6','organisation'=>'#6366f1','maitrise_poste'=>'#84cc16','engagement'=>'#ec4899','fiabilite'=>'#14b8a6'];
                foreach ($scores as $axe => $score):
                    if (in_array($axe, ['stabilite','implication'], true)) continue;
                    $label = $axeLabels[$axe] ?? $axe;
                    $pct   = round($score * 100);
                    $color = $axeBarColors[$axe] ?? '#94a3b8';
                ?>
                <div class="axe-row">
                    <div class="axe-label"><span><?= h($label) ?></span><span><?= $pct ?>%</span></div>
                    <div class="axe-bar"><div class="axe-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Alertes -->
        <?php if (!empty($alertes)): ?>
        <div class="card">
            <h2>Alertes RH <span style="font-size:.75rem;color:#ef4444;">(<?= count($alertes) ?>)</span></h2>
            <div class="alertes-list">
                <?php foreach ($alertes as $al): ?>
                <div class="alerte <?= h($al['niveau'] ?? 'warning') ?>">
                    <span><?= $al['niveau'] === 'danger' ? '🚨' : '⚠️' ?></span>
                    <span><?= h($al['message'] ?? $al['type_alerte'] ?? '') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Plan d'action -->
        <div class="card">
            <h2>Plan d'action</h2>
            <?php if (empty($actions)): ?>
                <div style="font-size:.82rem;color:#94a3b8;text-align:center;padding:1rem 0;">Aucune action définie</div>
            <?php else: ?>
            <table class="actions-table">
                <thead><tr><th>Action</th><th>Échéance</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($actions as $act): ?>
                <tr>
                    <td><?= h(mb_strimwidth($act['libelle'] ?? '', 0, 50, '…')) ?></td>
                    <td><?= $act['echeance'] ? date('d/m/Y', strtotime($act['echeance'])) : '—' ?></td>
                    <td><span class="statut-badge st-<?= h($act['statut'] ?? 'a_faire') ?>"><?= h(ucfirst(str_replace('_',' ',$act['statut'] ?? 'à faire'))) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <div style="margin-top:.75rem;">
                <a href="rh_entretien_detail.php?id=<?= $entretienId ?>" class="btn btn-outline" style="width:100%;justify-content:center;">+ Gérer le plan d'action</a>
            </div>
        </div>

        <!-- Signatures -->
        <div class="card">
            <h2>Signatures</h2>
            <?php
            $sigs = [];
            try {
                $stmt = $pdo->prepare("SELECT * FROM signatures WHERE module='rh_entretien' AND entity_id=?");
                $stmt->execute([$entretienId]);
                $sigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {}
            $sigManager = null; $sigCollab = null;
            foreach ($sigs as $s) {
                if ($s['signataire_type'] === 'manager') $sigManager = $s;
                if ($s['signataire_type'] === 'collaborateur') $sigCollab = $s;
            }
            ?>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem .65rem;background:#f8fafc;border-radius:7px;">
                    <span style="font-size:.8rem;font-weight:600;">Manager</span>
                    <?php if ($sigManager): ?>
                        <span style="font-size:.75rem;color:#166534;">✅ Signé le <?= date('d/m/Y', strtotime($sigManager['signed_at'] ?? $sigManager['created_at'])) ?></span>
                    <?php else: ?>
                        <span style="font-size:.75rem;color:var(--btn-save-from);">En attente</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem .65rem;background:#f8fafc;border-radius:7px;">
                    <span style="font-size:.8rem;font-weight:600;">Collaborateur</span>
                    <?php if ($sigCollab): ?>
                        <span style="font-size:.75rem;color:#166534;">✅ Signé le <?= date('d/m/Y', strtotime($sigCollab['signed_at'] ?? $sigCollab['created_at'])) ?></span>
                    <?php else: ?>
                        <span style="font-size:.75rem;color:var(--btn-save-from);">En attente</span>
                    <?php endif; ?>
                </div>
                <?php if (!$sigManager || !$sigCollab): ?>
                <a href="rh_entretien_signature.php?id=<?= $entretienId ?>" class="btn btn-primary" style="text-align:center;justify-content:center;">✍️ Signer l'entretien</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$layout_content = ob_get_clean();

$_jsScores = $jsScores;
$_jsRadarAxes = $jsRadarAxes;
$_jsRadarLabels = $jsRadarLabels;

$layout_extra_js = <<<EXTRAJS
<script>
const scores     = {$_jsScores};
const radarAxes  = {$_jsRadarAxes};
const radarLabels = {$_jsRadarLabels};

const radarCtx = document.getElementById('radarChart').getContext('2d');
new Chart(radarCtx, {
    type: 'radar',
    data: {
        labels: radarLabels,
        datasets: [{
            label: 'Score',
            data: radarAxes.map(a => (scores[a] || 0) * 5),
            backgroundColor: 'rgba(249,115,22,.15)',
            borderColor: 'rgba(249,115,22,.8)',
            pointBackgroundColor: 'rgba(249,115,22,1)',
            borderWidth: 2,
        }],
    },
    options: {
        responsive: true,
        scales: { r: { min:0, max:5, ticks:{ stepSize:1, font:{size:9} }, pointLabels:{ font:{size:9} } } },
        plugins: { legend:{ display:false } },
    }
});
</script>
EXTRAJS;

require_once __DIR__ . '/inc/layout_maboximmo.php';
