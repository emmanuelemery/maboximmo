<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$roleId = current_role_id();
if (!in_array($roleId, [1, 2, 3], true)) {
    deny_access('Accès RH restreint.');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m){ $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[(int)$m]??''; }

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$year = (int)($_GET['annee'] ?? $now->format('Y'));
$day = (int)$now->format('j');

$idUser = current_user_id();
if ($idUser <= 0) { http_response_code(400); exit('Utilisateur introuvable'); }

$idUserList = rh_user_salary_ids($pdo, $idUser);
if (!$idUserList) { $idUserList = [$idUser]; }
$in = implode(',', array_fill(0, count($idUserList), '?'));

$hasDateModif = rh_column_exists($pdo, 'salaires', 'date_modification');

// Années disponibles
$years = [];
try {
    $stmtYears = $pdo->prepare("SELECT DISTINCT YEAR(mois_reference) AS y FROM salaires WHERE id_user IN ($in) AND mois_reference > '0000-00-00' ORDER BY y DESC");
    $stmtYears->execute($idUserList);
    foreach ($stmtYears->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['y'])) $years[] = (int)$row['y'];
    }
} catch (Exception $e) {}
if (!in_array($year, $years, true)) $years[] = $year;
$years = array_values(array_unique($years));
rsort($years);

// Salaires de l'année
$selectCols = "id, mois_reference, termine_user" . ($hasDateModif ? ", date_modification" : "");
$stmt = $pdo->prepare("SELECT $selectCols FROM salaires WHERE id_user IN ($in) AND mois_reference >= ? AND mois_reference <= ?");
$params = array_merge($idUserList, [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byMonth = [];
foreach ($rows as $r) {
    $m = (int)substr((string)$r['mois_reference'], 5, 2);
    $byMonth[$m] = $r;
}

// Modèle de salaire
$model = null;
try {
    $stmtModel = $pdo->prepare("SELECT * FROM salaires WHERE id_user IN ($in) AND mois_reference='0000-00-00' AND salaire_modele=1 LIMIT 1");
    $stmtModel->execute($idUserList);
    $model = $stmtModel->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

$alertMonth = (int)$now->format('n');
$alertYear = (int)$now->format('Y');
$detailUrl = 'rh_salaires_user.php?mois=' . $alertMonth . '&annee=' . $alertYear;

/* ── Layout variables ── */
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Mes salaires — ' . h($_userName);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    :root{--bg-soft:#ffffff !important;--ink:#1a1816 !important;--muted:#8a8680 !important;--accent:#4878a6 !important;--stroke:#e4e6ec !important}
    .card{background:#ffffff;border:1px solid #e4e6ec;border-radius:14px;padding:16px 18px;box-shadow:4px 4px 12px #d4d7de,-4px -4px 12px #fff}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .filter{display:flex;gap:8px;align-items:center}
    .filter label{font-size:11px;text-transform:uppercase;color:#8a8680;font-weight:700;letter-spacing:.06em}
    select{padding:7px 10px;border-radius:8px;border:1px solid #d4d7de;background:#ffffff;color:#1a1816;font-size:12px}
    .alert{border:1px solid rgba(74,96,56,.25);background:#e8efe0;border-radius:12px;padding:14px 16px;margin-bottom:16px}
    .alert-title{font-weight:800;color:#4a6038;font-size:16px;margin-bottom:6px}
    .alert-text{font-size:14px;color:#6a6660}
    .alert-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;border:1px solid rgba(72,120,166,.25);background:rgba(72,120,166,.08);color:#4878a6;font-size:12px;font-weight:700;text-decoration:none;box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff}
    .btn:hover{background:rgba(72,120,166,.15)}
    .btn-ghost{border-color:#d4d7de;background:#ffffff;color:#1a1816}
    .list{margin-top:16px;display:grid;gap:10px}
    .item{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border:1px solid #e4e6ec;border-radius:10px;background:#ffffff;box-shadow:3px 3px 8px #d4d7de,-3px -3px 8px #fff;transition:transform .15s}
    .item:hover{transform:translateY(-1px);box-shadow:4px 4px 12px #d4d7de,-4px -4px 12px #fff}
    .item-left{display:flex;flex-direction:column;gap:4px}
    .item-title{font-weight:700;font-size:14px;color:#2f587d}
    .item-meta{font-size:11px;color:#8a8680}
    .status{font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;font-family:'DM Mono',monospace;letter-spacing:.04em;text-transform:uppercase}
    .status.ok{color:#4a6038;border:1px solid rgba(74,96,56,.25);background:#e8efe0}
    .status.todo{color:#c97b2e;border:1px solid rgba(201,123,46,.25);background:#fef8f0}
    .status.inprogress{color:#4878a6;border:1px solid rgba(72,120,166,.25);background:#e8f0f8}
    .model-card{margin-top:16px}
    details.model-card summary{cursor:pointer;font-weight:700;font-size:13px;color:#2f587d;list-style:none}
    details.model-card summary::-webkit-details-marker{display:none}
    .model-table{width:100%;border-collapse:collapse;margin-top:10px;font-size:12px}
    .model-table th,.model-table td{padding:8px 10px;border-bottom:1px solid #f0f1f3;text-align:left}
    .model-table th{color:#4a6038;font-size:10px;text-transform:uppercase;letter-spacing:.06em;width:40%;font-weight:700}
    .model-empty{font-size:12px;color:#8a8680;margin-top:10px}

    .grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
    .list-card{position:sticky;top:16px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}.list-card{position:static}}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
EXTRAJS;

/* ── Content ── */
ob_start();
?>
            <?php if ($day >= 23): ?>
            <div class="alert">
                <div class="alert-title">À partir du 23, pensez à remplir votre salaire</div>
                <div class="alert-text">Vos congés et vos IK sont liés au salaire du mois. Pensez à compléter le mois de <?= h(mois_fr($alertMonth)) ?>.</div>
                <div class="alert-actions">
                    <a class="btn" href="<?= h($detailUrl) ?>">🧾 Remplir mon salaire</a>
                    <a class="btn btn-ghost" href="rh_conges.php">🏖️ Mes congés</a>
                    <a class="btn btn-ghost" href="rh_indemnite_km.php">🚗 Mes IK</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid">
                <div class="card">
                    <div class="row" style="justify-content:space-between">
                        <div style="font-weight:700;font-size:13px">📌 Mon modèle de salaire</div>
                        <a class="btn" href="<?= h($detailUrl) ?>">+ Ouvrir le mois en cours</a>
                    </div>
                    <?php if ($model): ?>
                    <table class="model-table">
                        <?php
                            $modelFields = [
                                'salaire_brut_base' => 'Salaire brut',
                                'treizieme_mois' => '13e mois',
                                'anciennete' => 'Ancienneté (%)',
                                'avantage_nature' => 'Avantage nature',
                                'heures_supp' => 'Heures supp',
                                'commission_ca' => 'Commission CA',
                                'commission_ca_nouvelles_affaires' => 'Commission NA',
                                'prime_admin' => 'Prime admin',
                                'prime_exceptionnelle' => 'Prime exceptionnelle',
                                'stationnement' => 'Stationnement',
                                'frais_professionnels' => 'Frais professionnels',
                                'frais_reception' => 'Frais réception',
                                'frais_deplacement' => 'Frais déplacement',
                                'remboursement_achat' => 'Remboursement achat',
                                'total_ik' => 'Total IK',
                                'ik_nb_km' => 'Nombre km',
                                'ik_montant' => 'IK montant (€)'
                            ];
                            foreach ($modelFields as $k => $label) {
                                if ($model[$k] === null || $model[$k] === '') continue;
                                $val = is_numeric($model[$k]) ? number_format((float)$model[$k], 2, ',', '') : $model[$k];
                                echo '<tr><th>' . h($label) . '</th><td>' . h((string)$val) . '</td></tr>';
                            }
                        ?>
                    </table>
                    <?php else: ?>
                        <div class="model-empty">Aucun modèle de salaire défini pour vous.</div>
                    <?php endif; ?>
                </div>

                <div class="card list-card">
                    <div class="row" style="justify-content:space-between;gap:8px">
                        <div style="font-weight:700;font-size:13px">Liste des salaires</div>
                        <div class="filter">
                            <label>Année</label>
                            <select onchange="location.href='?annee=' + this.value">
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= (int)$y ?>" <?= $y===$year?'selected':'' ?>><?= (int)$y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="list">
                        <?php for ($m=1; $m<=12; $m++):
                            $row = $byMonth[$m] ?? null;
                            if ($row && (int)($row['termine_user'] ?? 0) === 1) {
                                $status = 'Complété'; $statusClass = 'ok';
                            } elseif ($row) {
                                $status = 'En cours'; $statusClass = 'inprogress';
                            } else {
                                $status = 'À compléter'; $statusClass = 'todo';
                            }
                            $dateStr = '';
                            if ($row && $hasDateModif && !empty($row['date_modification'])) {
                                $dateStr = 'Maj: ' . date('d/m/Y', strtotime($row['date_modification']));
                            }
                            $url = 'rh_salaires_user.php?mois=' . $m . '&annee=' . $year;
                        ?>
                        <div class="item">
                            <div class="item-left">
                                <div class="item-title"><?= h(mois_fr($m)) ?></div>
                                <div class="item-meta"><?= $dateStr ?: 'Salaire ' . (int)$year ?></div>
                            </div>
                            <div class="row">
                                <span class="status <?= $statusClass ?>"><?= h($status) ?></span>
                                <a class="btn" href="<?= h($url) ?>">Voir</a>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
