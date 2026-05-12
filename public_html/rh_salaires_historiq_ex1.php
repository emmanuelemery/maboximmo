<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Add missing columns if needed
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (!in_array('mois_cloture', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN mois_cloture DATETIME NULL");
    }
    if (!in_array('commentaire_general', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN commentaire_general TEXT NULL");
    }
} catch (Exception $e) {}

$userId = current_user_id();
$roleId = current_role_id();

// Get user info
$stmtUser = $pdo->prepare("SELECT id, prenom, nom, email FROM users WHERE id=?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Utilisateur non trouvé');
}

$nomComplet = trim(($user['prenom']??'').' '.($user['nom']??''));

// Handle field save
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_field'])) {
    verify_csrf();
    $field = $_POST['save_field']??'';
    $value = $_POST['value']??null;
    $idSalaire = (int)($_POST['id_salaire']??0);

    // Only allow modification if conditions are met
    $stmtCheck = $pdo->prepare("SELECT * FROM salaires WHERE id=? AND (id_user=? OR id_user IN (SELECT id_legacy FROM users WHERE id=?))");
    $stmtCheck->execute([$idSalaire, $userId, $userId]);
    $salary = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$salary) {
        http_response_code(400);
        exit('Salaire non trouvé');
    }

    // Check if user can modify
    $dt = DateTime::createFromFormat('Y-m-d', $salary['mois_reference']??'');
    $lastDay = $dt ? (int)$dt->format('t') : 29;
    $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
    $isAfterDeadline = $now->format('Y-m') > $dt->format('Y-m') || ($now->format('Y-m') === $dt->format('Y-m') && (int)$now->format('d') > $lastDay);

    $canModify = ($roleId === 1) || // Admin can always modify
                 (!$salary['termine_user'] && !$isAfterDeadline); // User can modify if not locked and not after deadline

    if (!$canModify) {
        http_response_code(400);
        exit('Modification non autorisée');
    }

    // Validate field
    $allowedFields = ['salaire_brut_base','treizieme_mois','anciennete','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','vehicule_utilise','ik_montant'];

    if (!in_array($field, $allowedFields)) {
        http_response_code(400);
        exit('Champ invalide');
    }

    // Type conversion
    if (in_array($field, ['salaire_brut_base','treizieme_mois','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','ik_montant'])) {
        $value = ($value===''||$value===null) ? null : (float)str_replace(',', '.', $value);
    } elseif ($field==='anciennete') {
        $value = ($value===''||$value===null) ? null : (int)$value;
    } else {
        $value = ($value===''||$value===null) ? null : trim($value);
    }

    // Save
    $u = $pdo->prepare("UPDATE salaires SET `$field`=? WHERE id=?");
    $u->execute([$value, $idSalaire]);
    http_response_code(200);
    exit('OK');
}

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
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Mes Salaires — ' . h($_userName);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    .container{max-width:1200px;margin:0 auto}
    .back-link{color:var(--accent);text-decoration:none;font-size:12px;margin-bottom:16px;display:inline-block}
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
    .button{padding:8px 14px;background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;transition:all 0.2s}
    .button:hover{background:rgba(72,120,166,0.2);border-color:var(--accent)}
    .button-gold{background:rgba(255,215,0,0.2);border:1px solid rgba(255,215,0,0.4);color:#7a6830}
    .button-gold:hover{background:rgba(255,215,0,0.3);border-color:#7a6830}
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
    .detail-value input{width:120px;padding:6px 8px;background:#ffffff;border:1px solid var(--stroke);border-radius:4px;color:var(--ink);font-family:inherit}
    .detail-value input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
    .two-column{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    #salaryChart{max-width:100%;height:120px}
    @media(max-width:1024px){.two-column{grid-template-columns:1fr}.header{grid-template-columns:1fr}}
</style>
EXTRACSS;

$layout_extra_js = <<<EXTRAJS
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    function appendCsrf(formData) {
        if (CSRF_TOKEN) {
            formData.append('csrf_token', CSRF_TOKEN);
        }
        return formData;
    }
    let saveTimeout={};
    function autoSaveField(fieldName,value,idSalaire){
        clearTimeout(saveTimeout[fieldName]);
        saveTimeout[fieldName]=setTimeout(()=>{
            const formData=new FormData();
            formData.append('save_field',fieldName);
            formData.append('value',value);
            formData.append('id_salaire',idSalaire);
            appendCsrf(formData);
            fetch(window.location.pathname+window.location.search,{
                method:'POST',
                body:formData
            }).then(r=>{
                if(!r.ok)alert('Erreur lors de la sauvegarde')
            }).catch(e=>alert('Erreur: '+e.message))
        },500)
    }

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
                <a href="agency_dashboard.php" class="back-link">&larr; Retour</a>
                <div class="header">
                    <div class="header-left">
                        <div class="header-title"><?=h($nomComplet)?></div>
                        <div class="header-info">
                            <div><span>ID User:</span><strong><?=$userId?></strong></div>
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
                                    <select onchange="location.href='?annee='+this.value">
                                        <?php for($y=date('Y'); $y>=2020; $y--): ?>
                                        <option value="<?=$y?>" <?=($y==$annee?'selected':'')?>><?=$y?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <a href="rh_salaires_user.php" class="button button-gold">+ Nouveau</a>
                            </div>
                            <div class="salary-list">
                                <?php if(empty($salaires)): ?>
                                <p style="color:var(--muted);font-size:12px">Aucun salaire disponible</p>
                                <?php else: foreach($salaires as $sal):
                                    $dt=DateTime::createFromFormat('Y-m-d',$sal['mois_reference']??'');
                                    $mois=$dt?(int)$dt->format('n'):0;
                                    $statusIcon=$sal['termine_user']?'🔒':'🔓';
                                ?>
                                <a href="?annee=<?=$annee?>&id_salaire=<?=$sal['id']?>" class="salary-item <?=(!empty($_GET['id_salaire']) && (int)$_GET['id_salaire']===$sal['id']?'active':'')?>">
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
                            $lastDay=$dt?(int)$dt->format('t'):29;
                            $now=new DateTime('now',new DateTimeZone('Europe/Paris'));
                            $isAfterDeadline=$now->format('Y-m')>$dt->format('Y-m')||($now->format('Y-m')===$dt->format('Y-m')&&(int)$now->format('d')>$lastDay);
                            $canModify=($roleId===1)||(!$selectedSalaire['termine_user']&&!$isAfterDeadline);
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
                                    <span class="detail-value">
                                        <?=$canModify?'<input type="text" value="'.h($displayVal).'" onchange="autoSaveField(\''.$fieldName.'\', this.value, '.$selectedSalaire['id'].')">':h($displayVal)?>
                                    </span>
                                </div>
                                <?php endforeach; if(!$hasFields): ?>
                                <p style="color:var(--muted);font-size:12px">Aucun champ renseigné</p>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--stroke)">
                                <?php if($isAfterDeadline && !$selectedSalaire['termine_user']): ?>
                                <p style="font-size:11px;color:#7a6830;margin-bottom:8px">La date limite pour modifier est dépassée. Seul l'administrateur peut modifier.</p>
                                <?php endif; if($roleId!==1 && $canModify): ?>
                                <button onclick="alert('Valider définitivement - À implémenter')" style="padding:8px 14px;background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:6px;cursor:pointer;font-weight:600">Valider définitivement</button>
                                <?php elseif($selectedSalaire['termine_user']): ?>
                                <p style="font-size:12px;color:#3a7a6a">Salaire validé définitivement</p>
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
