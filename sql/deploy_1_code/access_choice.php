<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier que l'utilisateur vient du login
if (empty($_SESSION['user_id']) || empty($_SESSION['available_accesses'])) {
    header('Location: login.php');
    exit;
}

$accesses = $_SESSION['available_accesses'] ?? [];
$username = $_SESSION['username'] ?? 'Utilisateur';

// Traitement du choix
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = (string)($_POST['access'] ?? '');
    if (isset($accesses[$choice]) && $accesses[$choice]) {
        $_SESSION['current_access'] = $choice;
        $dashboards = ['agency' => 'dashboard_agency.php', 'syndic' => 'dashboard_syndic.php', 'proprietaire' => 'dashboard_proprietaire.php'];
        header('Location: ' . ($dashboards[$choice] ?? 'default.php'));
        exit;
    }
}

$accessLabels = [
    'agency' => ['label' => '🏢 Ma Box Agency', 'desc' => 'Gestion agence immobilière'],
    'syndic' => ['label' => '🏛️ Ma Box Syndic', 'desc' => 'Gestion syndic de copropriété'],
    'proprietaire' => ['label' => '🔑 Ma Box Propriétaire', 'desc' => 'Gestion de vos biens en location'],
];
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sélectionner votre accès - MABOXIMMO</title><style>
:root{--bg: var(--bg-secondary);--ink:#e9f2ff;--muted:#8da0ba;--accent:#4878a6;--accent-3:#4a6038;--stroke:rgba(255,255,255,0.09)}*{margin:0;padding:0;box-sizing:border-box}body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(1100px 550px at 80% -10%,rgba(72,120,166,0.12),transparent 58%),#07111b;font-family:"Manrope",sans-serif;color:var(--ink);padding:24px;position:relative}body::after{content:"";position:fixed;inset:0;opacity:0.035;z-index:0}
.container{position:relative;z-index:1;width:100%;max-width:700px;background:#ffffff;backdrop-filter:blur(20px);border:1px solid var(--stroke);border-radius:20px;padding:48px 32px;box-shadow:0 20px 60px #f7f8fa;text-align:center}
h1{font-size:28px;font-weight:800;color:#fff;margin-bottom:8px}
.subtitle{font-size:14px;color:var(--muted);margin-bottom:32px}
.access-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.access-card{position:relative;border:1px solid var(--stroke);background:#f7f8fa;border-radius:16px;padding:24px;cursor:pointer;transition:all 0.3s;text-decoration:none;color:inherit;display:grid;grid-template-rows:auto auto auto 1fr auto;gap:12px}
.access-card:hover{background:rgba(72,120,166,0.06);border-color:rgba(72,120,166,0.2);transform:translateY(-4px)}
.access-card input{display:none}
.access-card input:checked+.access-card-inner{color:var(--accent)}
.access-icon{font-size:40px}
.access-title{font-size:16px;font-weight:700;color:#fff}
.access-desc{font-size:12px;color:var(--muted);line-height:1.4}
.access-card input[type="radio"]+label{display:none}
.access-card input[type="radio"]:checked+.access-card-label{display:block;margin-top:auto;color:var(--accent)}
.access-card-label{display:none;font-size:11px;color:var(--accent);font-weight:600}
form{display:grid;gap:16px}
.access-option{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:start;padding:16px;border:1px solid var(--stroke);border-radius:12px;background:rgba(255,255,255,0.02);cursor:pointer;transition:all 0.2s}
.access-option input[type="radio"]{appearance:none;width:20px;height:20px;border:2px solid var(--stroke);border-radius:50%;cursor:pointer;transition:all 0.2s}
.access-option input[type="radio"]:checked{border-color:var(--accent);background:var(--accent);box-shadow:0 0 0 3px rgba(72,120,166,0.12)}
.access-option:hover{background:#ffffff;border-color:rgba(72,120,166,0.2)}
.access-option-content{text-align:left}
.access-option-label{font-size:14px;font-weight:600;color:#fff;display:block;margin-bottom:4px}
.access-option-desc{font-size:12px;color:var(--muted)}
button{width:100%;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;color:#07121b;background:linear-gradient(135deg,rgba(102,217,255,0.95),rgba(28,77,255,0.85));box-shadow:0 12px 30px rgba(72,120,166,0.15);font-family:inherit;transition:transform 0.2s}
button:hover{transform:translateY(-2px)}
button:disabled{opacity:0.5;cursor:not-allowed}
.footer{margin-top:24px;font-size:11px;color:rgba(141,160,186,0.60)}
.logout{display:inline-block;font-size:11px;color:var(--accent);text-decoration:none;margin-top:16px}
</style></head><body><div class="container">
<h1>Sélectionnez votre accès</h1>
<p class="subtitle">Bienvenue <?=htmlspecialchars($username)?>, choisissez votre espace</p>
<form method="post">
<?php foreach($accesses as $key=>$active):
if(!$active)continue;
$info=$accessLabels[$key]??['label'=>ucfirst($key),'desc'=>''];
?>
<label class="access-option">
<input type="radio" name="access" value="<?=$key?>" required>
<div class="access-option-content">
<span class="access-option-label"><?=$info['label']?></span>
<span class="access-option-desc"><?=$info['desc']?></span>
</div>
</label>
<?php endforeach;?>
<button type="submit">Accéder →</button>
<div class="footer">
<a href="logout_agency.php" class="logout">Déconnecter</a>
</div>
</form>
</div></body></html>
