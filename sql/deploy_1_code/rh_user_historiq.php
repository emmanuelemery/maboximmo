<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$roleId = current_role_id();
if ($roleId !== 1) {
    die('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Get selected user
$userId = (int)($_GET['user_id'] ?? 0);
if (!$userId) {
    die('ID utilisateur manquant');
}

// Get user info
$stmtUser = $pdo->prepare("SELECT id, prenom, nom, email FROM users WHERE id=?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Utilisateur non trouvé');
}

$nomComplet = trim(($user['prenom']??'').' '.($user['nom']??''));

// Get year filter
$annee = $_GET['annee'] ?? date('Y');

// Get user's salaries for the year
$idUserLegacy = rh_user_salary_id($pdo, $userId);
$stmtSalaires = $pdo->prepare("
    SELECT * FROM salaires
    WHERE (id_user=? OR id_user=?) AND YEAR(mois_reference)=?
    ORDER BY mois_reference DESC
");
$stmtSalaires->execute([$userId, $idUserLegacy, $annee]);
$salaires = $stmtSalaires->fetchAll(PDO::FETCH_ASSOC);

// Get last 12 months salary data for chart
$chartData = [];
$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
for ($i = 11; $i >= 0; $i--) {
    $date = clone $now;
    $date->modify("-$i months");
    $mois_ref = $date->format('Y-m-01');
    $label = $date->format('M');

    $stmtChart = $pdo->prepare("
        SELECT
            COALESCE(SUM(salaire_brut_base), 0) + COALESCE(SUM(treizieme_mois), 0) +
            COALESCE(SUM(avantage_nature), 0) + COALESCE(SUM(heures_supp), 0) +
            COALESCE(SUM(commission_ca), 0) + COALESCE(SUM(commission_ca_nouvelles_affaires), 0) +
            COALESCE(SUM(frais_professionnels), 0) + COALESCE(SUM(prime_admin), 0) +
            COALESCE(SUM(prime_exceptionnelle), 0) + COALESCE(SUM(stationnement), 0) +
            COALESCE(SUM(frais_deplacement), 0) + COALESCE(SUM(remboursement_achat), 0) +
            COALESCE(SUM(total_ik), 0) as total
        FROM salaires
        WHERE (id_user=? OR id_user=?) AND mois_reference=?
    ");
    $stmtChart->execute([$userId, $idUserLegacy, $mois_ref]);
    $result = $stmtChart->fetch(PDO::FETCH_ASSOC);
    $chartData[] = [
        'label' => $label,
        'value' => (float)($result['total'] ?? 0)
    ];
}

$chartLabels = json_encode(array_column($chartData, 'label'));
$chartValues = json_encode(array_column($chartData, 'value'));

// Get selected salary details
$selectedSalaire = null;
if (!empty($_GET['id_salaire'])) {
    $idSal = (int)$_GET['id_salaire'];
    foreach ($salaires as $s) {
        if ($s['id'] === $idSal) {
            $selectedSalaire = $s;
            break;
        }
    }
}

// Fields structure
$COLS = [
    'salaire_brut_base' => ['label'=>'Salaire brut','type'=>'money'],
    'treizieme_mois' => ['label'=>'13e mois','type'=>'money'],
    'anciennete' => ['label'=>'Ancienneté (%)','type'=>'int'],
    'avantage_nature' => ['label'=>'Avantage nature','type'=>'money'],
    'heures_supp' => ['label'=>'Heures supp','type'=>'money'],
    'commission_ca' => ['label'=>'Commission CA','type'=>'money'],
    'commission_ca_nouvelles_affaires' => ['label'=>'Commission NA','type'=>'money'],
    'ik_nb_km' => ['label'=>'Nombre km','type'=>'money'],
    'total_ik' => ['label'=>'Total IK','type'=>'money'],
    'remboursement_achat' => ['label'=>'Remboursement achat','type'=>'money'],
    'frais_professionnels' => ['label'=>'Frais professionnels','type'=>'money'],
    'frais_reception' => ['label'=>'Frais réception','type'=>'money'],
    'prime_admin' => ['label'=>'Prime admin','type'=>'money'],
    'prime_exceptionnelle' => ['label'=>'Prime excep','type'=>'money'],
    'stationnement' => ['label'=>'Stationnement','type'=>'money'],
    'frais_deplacement' => ['label'=>'Frais déplacement','type'=>'money'],
    'vehicule_utilise' => ['label'=>'Véhicule','type'=>'text'],
    'ik_montant' => ['label'=>'IK montant (€)','type'=>'money'],
];

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m){ $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[(int)$m]??''; }
function fmt_val($v, $type){ if($v === null || $v === '') return ''; if($type === 'money') return number_format((float)$v, 2, ',', ''); if($type === 'int') return (string)(int)$v; return (string)$v; }

// ── Layout variables ─────────────────────────────────────────────────────
$layout_title   = 'Historique Salaires - ' . h($nomComplet);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    .container{max-width:1200px;margin:0 auto}
    .back-link{color:var(--accent);text-decoration:none;font-size:12px;margin-bottom:16px;display:inline-block;cursor:pointer}
    .back-link:hover{text-decoration:underline}
    .card{background:#ffffff;border:1px solid var(--stroke);border-radius:12px;padding:20px;margin-bottom:20px}
    .header{background:#ffffff;border:1px solid rgba(124,245,214,0.25);border-radius:12px;padding:20px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:center}
    .header-left{display:flex;flex-direction:column}
    .header-right{display:flex;justify-content:center;align-items:center;min-height:120px}
    .header-title{font-size:28px;font-weight:800;color:#8a5040;margin-bottom:8px}
    .header-info{display:flex;gap:30px;font-size:12px;color:var(--muted);margin-top:12px}
    .header-info strong{color:var(--ink);font-family:monospace}
    .card-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:16px}
    .year-filter{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
    select{padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-family:inherit;cursor:pointer}
    select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
    .salary-list{display:flex;flex-direction:column;gap:8px}
    .salary-item{padding:12px;background:rgba(72,120,166,0.08);border:1px solid rgba(72,120,166,0.2);border-radius:8px;cursor:pointer;transition:all 0.2s;text-decoration:none;color:var(--ink)}
    .salary-item:hover{background:rgba(72,120,166,0.12);border-color:var(--accent)}
    .salary-item.active{background:rgba(72,120,166,0.12);border-color:var(--accent)}
    .salary-item-month{font-weight:600;color:#3a7a6a}
    .salary-item-status{font-size:11px;color:var(--muted);margin-top:4px}
    .salary-details{display:grid;gap:12px}
    .detail-field{display:flex;justify-content:space-between;align-items:center;padding:12px;background:#ffffff;border-radius:8px}
    .detail-label{font-size:12px;font-weight:600;color:var(--muted)}
    .detail-value{font-size:14px;color:var(--ink);font-weight:600}
    .two-column{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    #salaryChart{max-width:100%;height:120px}
    @media(max-width:1024px){.two-column{grid-template-columns:1fr}.header{grid-template-columns:1fr}}
</style>
EXTRACSS;

$layout_extra_js = <<<EXTRAJS
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    window.addEventListener('load',function(){
        const ctx = document.getElementById('salaryChart');
        if(ctx){
            new Chart(ctx,{
                type:'line',
                data:{
                    labels:{$chartLabels},
                    datasets:[{
                        label:'Salaire Total Versé (€)',
                        data:{$chartValues},
                        borderColor:'#4878a6',
                        backgroundColor:'rgba(72,120,166,0.08)',
                        borderWidth:2,
                        fill:true,
                        tension:0.4,
                        pointBackgroundColor:'#8a5040',
                        pointBorderColor:'#4878a6',
                        pointRadius:4,
                        pointHoverRadius:6
                    }]
                },
                options:{
                    responsive:true,
                    maintainAspectRatio:false,
                    plugins:{
                        legend:{display:false}
                    },
                    scales:{
                        y:{
                            beginAtZero:true,
                            ticks:{color:'#7a91a8',font:{size:11}},
                            grid:{color:'#ffffff'}
                        },
                        x:{
                            ticks:{color:'#7a91a8',font:{size:11}},
                            grid:{color:'#ffffff'}
                        }
                    }
                }
            });
        }
    });
</script>
EXTRAJS;

ob_start();
?>
            <div class="container">
                <span class="back-link" onclick="window.history.back()">&larr; Retour</span>
                <div class="header">
                    <div class="header-left">
                        <div class="header-title"><?=h($nomComplet)?></div>
                        <div class="header-info">
                            <div><span>ID User:</span><strong><?=$userId?></strong></div>
                            <div><span>Email:</span><strong><?=h($user['email'])?></strong></div>
                        </div>
                    </div>
                    <div class="header-right">
                        <canvas id="salaryChart"></canvas>
                    </div>
                </div>
                <div class="two-column">
                    <div>
                        <div class="card">
                            <div class="card-title">Historique des Salaires</div>
                            <div class="year-filter">
                                <label>Année:
                                    <select onchange="location.href='?user_id=<?=$userId?>&annee='+this.value">
                                        <?php for($y=date('Y'); $y>=2020; $y--): ?>
                                        <option value="<?=$y?>" <?=($y==$annee?'selected':'')?>><?=$y?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="salary-list">
                                <?php if(empty($salaires)): ?>
                                <p style="color:var(--muted);font-size:12px">Aucun salaire disponible</p>
                                <?php else: foreach($salaires as $sal):
                                    $dt=DateTime::createFromFormat('Y-m-d',$sal['mois_reference']??'');
                                    $mois=$dt?(int)$dt->format('n'):0;
                                    $statusIcon=$sal['termine_user']?'🔒':'🔓';
                                ?>
                                <a href="?user_id=<?=$userId?>&annee=<?=$annee?>&id_salaire=<?=$sal['id']?>" class="salary-item <?=(!empty($_GET['id_salaire']) && (int)$_GET['id_salaire']===$sal['id']?'active':'')?>">
                                    <span class="salary-item-month"><?=mois_fr($mois)?> <?=$dt->format('Y')?></span>
                                    <span class="salary-item-status"><?=$statusIcon?> <?=($sal['id']?'Créé':'En attente')?></span>
                                </a>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <?php if($selectedSalaire):
                            $dt=DateTime::createFromFormat('Y-m-d',$selectedSalaire['mois_reference']??'');
                            $mois=$dt?(int)$dt->format('n'):0;
                        ?>
                        <div class="card">
                            <div class="card-title">Détails du Salaire</div>
                            <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
                                <span>Période:</span><strong style="color:var(--ink);font-family:monospace"><?=mois_fr($mois)?> <?=$dt->format('Y')?></strong>
                            </div>
                            <div class="salary-details">
                                <?php $hasFields=false; foreach($COLS as $fieldName=>$meta):
                                    $val=$selectedSalaire[$fieldName]??null;
                                    if($val===null||$val===''||$val==0) continue;
                                    $hasFields=true;
                                    $displayVal=fmt_val($val,$meta['type']);
                                ?>
                                <div class="detail-field">
                                    <span class="detail-label"><?=h($meta['label'])?></span>
                                    <span class="detail-value"><?=h($displayVal)?></span>
                                </div>
                                <?php endforeach; if(!$hasFields): ?>
                                <p style="color:var(--muted);font-size:12px">Aucun champ renseigné</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="card">
                            <div class="card-title">Sélectionner un salaire</div>
                            <p style="color:var(--muted);font-size:12px">Cliquez sur un salaire à gauche pour voir les détails</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
