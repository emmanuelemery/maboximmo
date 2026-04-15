<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';

require_login();

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Create Externe société if it doesn't exist
try {
    $stmt = $pdo->prepare("SELECT id FROM societes WHERE nom = ?");
    $stmt->execute(['Externe']);
    if ($stmt->rowCount() === 0) {
        $pdo->exec("INSERT INTO societes (nom, actif) VALUES ('Externe', 1)");
    }
} catch (Exception $e) {
    // Société might exist, continue
}

$filterSociete = !empty($_GET['societe']) ? (int)$_GET['societe'] : null;
$filterAgence = !empty($_GET['agence']) ? (int)$_GET['agence'] : null;
$filterModelesOnly = !empty($_GET['modeles_only']) ? true : false;

$societes = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);

// Récupère les commentaires admin les plus récents pour chaque user
$commentsByUser = [];
try {
    $stmtComments = $pdo->query("
        SELECT id_user, commentaire_admin, mois_reference
        FROM salaires
        WHERE mois_reference > '0000-00-00' AND commentaire_admin IS NOT NULL AND commentaire_admin != ''
        ORDER BY id_user ASC, mois_reference DESC
    ");
    $allComments = $stmtComments->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allComments as $row) {
        if (!isset($commentsByUser[(int)$row['id_user']])) {
            $commentsByUser[(int)$row['id_user']] = $row['commentaire_admin'];
        }
    }
} catch (Exception $e) {}

$sql = "SELECT DISTINCT
    u.id, u.prenom, u.nom, u.id_societe, u.id_agence, u.vehicule_nom, u.vehicule_puissance_fiscale,
    s.id as model_id, s.salaire_modele,
    s.salaire_brut_base, s.treizieme_mois, s.anciennete, s.avantage_nature,
    s.heures_supp, s.commission_ca, s.commission_ca_nouvelles_affaires,
    s.vehicule_nom as s_vehicule_nom, s.vehicule_puissance_fiscale as s_vehicule_puissance, s.prix_km,
    s.vehicule_utilise, s.frais_professionnels, s.prime_admin,
    s.prime_exceptionnelle, s.stationnement,
    soc.nom as societe_nom, ag.nom_agence
    FROM users u
    LEFT JOIN societes soc ON u.id_societe = soc.id
    LEFT JOIN agences ag ON u.id_agence = ag.id
    LEFT JOIN salaires s ON (u.id = s.id_user OR (u.id_legacy IS NOT NULL AND u.id_legacy = s.id_user)) AND s.mois_reference = '0000-00-00'
    WHERE u.actif = 1 AND soc.nom != 'Externe'";

$params = [];
if ($filterSociete !== null) {
    $sql .= " AND u.id_societe = ?";
    $params[] = $filterSociete;
}
if ($filterAgence !== null) {
    $sql .= " AND u.id_agence = ?";
    $params[] = $filterAgence;
}
if ($filterModelesOnly) {
    $sql .= " AND s.salaire_modele = 1";
}

$sql .= " ORDER BY soc.nom, ag.nom_agence, u.nom, u.prenom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcul des totaux
$totalUsers = count($users);
$totalBrut = 0.0;
$total13eme = 0.0;
$totalAnciennete = 0.0;
$totalAll = 0.0;

try {
    foreach ($users as $u) {
        $brut = !empty($u['salaire_brut_base']) ? (float)$u['salaire_brut_base'] : 0;
        $mois_anci = !empty($u['anciennete']) ? (int)$u['anciennete'] : 0;

        if ($brut > 0) $totalBrut += $brut;
        if (!empty($u['treizieme_mois'])) $total13eme += (float)$u['treizieme_mois'];

        // Ancienneté : 1% du brut par an (0.083% par mois)
        if ($mois_anci > 0 && $brut > 0) {
            $totalAnciennete += ($brut * $mois_anci * 0.01 / 12);
        }

        $totalAll += $brut;
        $totalAll += !empty($u['treizieme_mois']) ? (float)$u['treizieme_mois'] : 0;
        $totalAll += !empty($u['avantage_nature']) ? (float)$u['avantage_nature'] : 0;
        $totalAll += !empty($u['heures_supp']) ? (float)$u['heures_supp'] : 0;
        $totalAll += !empty($u['commission_ca']) ? (float)$u['commission_ca'] : 0;
        $totalAll += !empty($u['commission_ca_nouvelles_affaires']) ? (float)$u['commission_ca_nouvelles_affaires'] : 0;
        $totalAll += !empty($u['frais_professionnels']) ? (float)$u['frais_professionnels'] : 0;
        $totalAll += !empty($u['prime_admin']) ? (float)$u['prime_admin'] : 0;
        $totalAll += !empty($u['prime_exceptionnelle']) ? (float)$u['prime_exceptionnelle'] : 0;
        $totalAll += !empty($u['stationnement']) ? (float)$u['stationnement'] : 0;
        $totalAll += ($mois_anci > 0 && $brut > 0) ? ($brut * $mois_anci * 0.01 / 12) : 0;
    }
} catch (Exception $e) {
    error_log("Erreur calcul totaux: " . $e->getMessage());
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#A8D5BA',
    '#FFB347', '#87CEEB', '#DDA0DD', '#F0E68C', '#CD5C5C', '#20B2AA', '#9370DB', '#3CB371', '#DC143C', '#00CED1',
    '#FF8C00', '#9932CC', '#32CD32', '#FF1493', '#00FA9A', '#FFD700', '#8B4513', '#4169E1', '#FF69B4', '#1E90FF',
    '#FF4500', '#2F4F4F', '#00BFFF', '#ADFF2F', '#FF6347', '#40E0D0', '#EE82EE', '#F5DEB3', '#F0FFFF', '#FFFACD',
    '#FFE4E1', '#F0F8FF', '#FFF5EE', '#FFFAF0', '#FFFFF0', '#F5FFFA', '#F0FFF0', '#F0FFFF', '#FFF5EE', '#FFFACD'];

function getColor($userId) {
    global $colors;
    return $colors[$userId % count($colors)];
}

// ── Layout variables ─────────────────────────────────────────────────────
$layout_title   = 'Modèles Salaires';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
<div class="ph-kpi"><span class="ph-kpi-label">Utilisateurs</span><span class="ph-kpi-value">' . $totalUsers . '</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Brut Total</span><span class="ph-kpi-value">' . number_format($totalBrut, 2, ',', ' ') . ' &euro;</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">13e Mois</span><span class="ph-kpi-value">' . number_format($total13eme, 2, ',', ' ') . ' &euro;</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Ancienneté</span><span class="ph-kpi-value">' . number_format($totalAnciennete, 2, ',', ' ') . ' &euro;</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Total</span><span class="ph-kpi-value">' . number_format($totalAll, 2, ',', ' ') . ' &euro;</span></div>';

$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
    .filter-group{display:flex;flex-direction:column;gap:6px}
    .filter-group label{font-size:12px;font-weight:600;color:var(--muted)}
    .filter-group select{padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit;cursor:pointer}
    .user-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(550px,1fr));gap:20px;margin-bottom:20px}
    .user-card{background:#ffffff;border:1px solid var(--stroke);border-radius:14px;overflow:hidden;transition:all 0.2s}
    .user-card:hover{border-color:var(--accent);box-shadow:0 0 20px rgba(72,120,166,0.1)}
    .user-card-head{padding:16px;background:rgba(72,120,166,0.08);border-bottom:1px solid var(--stroke);display:flex;align-items:center;gap:12px;justify-content:space-between}
    .user-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:white;font-size:18px;flex-shrink:0}
    .user-info{flex:1;min-width:0}
    .user-card-name{font-weight:600;color:var(--accent);font-size:14px}
    .user-card-meta{font-size:11px;color:var(--muted);margin-top:2px}
    .user-card-body{padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .user-field{display:flex;flex-direction:column;gap:4px}
    .user-field label{font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase}
    .user-field input{padding:6px 8px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-family:inherit;font-size:11px}
    .user-field input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
    .user-field.full{grid-column:1/-1}
    .user-field input.saving{opacity:0.6}
    .user-card-footer{padding:12px 16px;background:rgba(0,0,0,0.2);border-top:1px solid var(--stroke)}
    .user-field textarea{padding:8px 10px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-family:inherit;font-size:11px;resize:vertical;min-height:40px;max-height:200px;overflow-y:auto}
    .user-field textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
    .user-field textarea.saving{opacity:0.6}
    .model-checkbox{display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none}
    .model-checkbox input[type="checkbox"]{width:16px;height:16px;cursor:pointer;accent-color:var(--accent)}
    .checkbox-label{font-size:11px;font-weight:600;color:var(--muted);white-space:nowrap}
    .model-checkbox input[type="checkbox"]:checked+.checkbox-label{color:var(--accent)}
    .filter-checkbox{display:flex;flex-direction:column;gap:6px;justify-content:flex-end}
    .filter-checkbox label{margin:0;color:var(--ink)}
    .filter-checkbox input[type="checkbox"]{width:16px;height:16px;cursor:pointer;accent-color:var(--accent)}
    @media(max-width:900px){.user-cards{grid-template-columns:1fr}}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const CSRF_HEADERS = CSRF_TOKEN ? {'X-CSRF-Token': CSRF_TOKEN} : {};
let saveTimeout = {};

// Save salary fields with debounce
document.querySelectorAll('.salary-field').forEach(field => {
    field.addEventListener('change', function() {
        const userId = parseInt(this.dataset.userId);
        const fieldName = this.dataset.field;
        const value = this.value;
        const key = `${userId}_${fieldName}`;

        this.classList.add('saving');
        clearTimeout(saveTimeout[key]);

        saveTimeout[key] = setTimeout(() => {
            fetch('./api/save_modele_salaire.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, field: fieldName, value })
            })
            .then(r => r.json())
            .then(data => {
                this.classList.remove('saving');
                console.log('Enregistré:', data);
            })
            .catch(e => {
                this.classList.remove('saving');
                console.error('Erreur:', e);
            });
        }, 500);
    });
});

// Toggle model activation
document.querySelectorAll('.toggle-modele').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const userId = parseInt(this.dataset.userId);
        const active = this.checked ? 1 : 0;

        fetch('./api/toggle_modele_salaire.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ userId, active })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                console.log('Modèle togglé:', userId, active);
            } else {
                console.error('Erreur:', data.message);
                this.checked = !this.checked;
            }
        })
        .catch(e => {
            console.error('Erreur:', e);
            this.checked = !this.checked;
        });
    });
});
</script>
EXTRAJS;

ob_start();
?>
        <div class="filters">
            <form method="GET" style="display:contents">
                <div class="filter-group">
                    <label>Société</label>
                    <select name="societe" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <?php foreach ($societes as $s): ?>
                            <option value="<?=(int)$s['id']?>" <?=(($filterSociete===(int)$s['id'])?'selected':'')?>><?=h($s['nom'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Agence</label>
                    <select name="agence" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <?php foreach ($agences as $a): ?>
                            <option value="<?=(int)$a['id']?>" <?=(($filterAgence===(int)$a['id'])?'selected':'')?>><?=h($a['nom_agence'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group filter-checkbox">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none">
                        <input type="checkbox" name="modeles_only" value="1" onchange="this.form.submit()" <?=$filterModelesOnly ? 'checked' : ''?>>
                        <span>Modèles actifs uniquement</span>
                    </label>
                </div>
            </form>
        </div>

        <div class="user-cards">
            <?php foreach ($users as $u):
                $color = getColor((int)$u['id']);
                $initials = strtoupper(substr($u['prenom']??'', 0, 1).substr($u['nom']??'', 0, 1));
            ?>
            <div class="user-card">
                <div class="user-card-head">
                    <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
                        <div class="user-avatar" style="background-color:<?=h($color)?>;"><?=$initials?></div>
                        <div class="user-info">
                            <div class="user-card-name"><?=h($u['prenom']??'')?> <?=h($u['nom']??'')?></div>
                            <div class="user-card-meta">#<?=(int)$u['id']?> &bull; <?=h($u['societe_nom']??'—')?></div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <label class="model-checkbox">
                            <input type="checkbox" class="toggle-modele" data-user-id="<?=(int)$u['id']?>" <?=($u['model_id'] && (int)$u['salaire_modele'] === 1) ? 'checked' : ''?>>
                            <span class="checkbox-label">Activé</span>
                        </label>
                    </div>
                </div>

                <div class="user-card-body">
                    <div class="user-field">
                        <label>Salaire brut base</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="salaire_brut_base" value="<?=h($u['salaire_brut_base']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>13ème mois</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="treizieme_mois" value="<?=h($u['treizieme_mois']??'')?>">
                    </div>

                    <div class="user-field">
                        <label>Ancienneté (mois)</label>
                        <input type="number" step="1" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="anciennete" value="<?=h($u['anciennete']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>Avantage nature</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="avantage_nature" value="<?=h($u['avantage_nature']??'')?>">
                    </div>

                    <div class="user-field">
                        <label>Heures supp</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="heures_supp" value="<?=h($u['heures_supp']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>Commission CA</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="commission_ca" value="<?=h($u['commission_ca']??'')?>">
                    </div>

                    <div class="user-field">
                        <label>Commission nouvelles affaires</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="commission_ca_nouvelles_affaires" value="<?=h($u['commission_ca_nouvelles_affaires']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>Nom du véhicule</label>
                        <input type="text" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="vehicule_nom" value="<?=h($u['s_vehicule_nom'] ?? $u['vehicule_nom'] ?? '')?>">
                    </div>
                    <div class="user-field">
                        <label>Puissance fiscale</label>
                        <input type="number" step="1" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="vehicule_puissance_fiscale" value="<?=h($u['s_vehicule_puissance'] ?? $u['vehicule_puissance_fiscale'] ?? '')?>">
                    </div>

                    <div class="user-field">
                        <label>Prix du km (&euro;)</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="prix_km" value="<?=h($u['prix_km']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>Véhicule utilisé</label>
                        <input type="text" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="vehicule_utilise" value="<?=h($u['vehicule_utilise']??'')?>">
                    </div>

                    <div class="user-field">
                        <label>Frais professionnels</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="frais_professionnels" value="<?=h($u['frais_professionnels']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>Prime admin</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="prime_admin" value="<?=h($u['prime_admin']??'')?>">
                    </div>

                    <div class="user-field">
                        <label>Prime exceptionnelle</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="prime_exceptionnelle" value="<?=h($u['prime_exceptionnelle']??'')?>">
                    </div>
                    <div class="user-field">
                        <label>Stationnement</label>
                        <input type="number" step="0.01" class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="stationnement" value="<?=h($u['stationnement']??'')?>">
                    </div>
                </div>

                <div class="user-card-footer">
                    <div class="user-field full">
                        <label>Commentaire</label>
                        <textarea class="salary-field" data-user-id="<?=(int)$u['id']?>" data-field="commentaire_admin" placeholder="Notes internes..."><?=h($commentsByUser[(int)$u['id']]??'')?></textarea>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
