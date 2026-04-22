<?php
declare(strict_types=1);
/**
 * investisseur/valorisation.php — Simulateur de valorisation du parc
 *
 * Principe : UI minimaliste, un seul écran.
 *   - 1 gros curseur "Rendement cible" (applique le même taux à tout)
 *   - 1 toggle "Affiner" qui révèle les paramètres par typologie + secteur
 *   - Tableau live bien par bien + KPI consolidés sticky
 *   - Bouton "Enregistrer le scénario" → versionning historique
 *   - Historique des scénarios en bas
 *
 * Aucune écriture sur investisseur_analyses — pur scénario.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';
require_once __DIR__ . '/../inc/investisseur_valo.php';

$pdo = $GLOBALS['pdo'];

// ─── POST : save / load / delete ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_any();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $params = [];
            foreach (array_keys(inv_valo_default_params()) as $k) {
                if (isset($_POST[$k]) && $_POST[$k] !== '') $params[$k] = (float)$_POST[$k];
            }
            $params['nom_scenario'] = trim((string)($_POST['nom_scenario'] ?? ''));
            $params['description']  = trim((string)($_POST['description'] ?? ''));
            if ($params['nom_scenario'] === '') throw new RuntimeException('Nom du scénario requis.');
            $idScen = inv_valo_save_scenario($pdo, $params, isset($_POST['id_scenario']) && (int)$_POST['id_scenario'] > 0 ? (int)$_POST['id_scenario'] : null);
            $_SESSION['_valo_flash'] = 'Scénario enregistré.';
            header('Location: ?loaded=' . $idScen);
            exit;
        }
        if ($action === 'delete' && !empty($_POST['id_scenario'])) {
            inv_valo_delete_scenario($pdo, (int)$_POST['id_scenario']);
            $_SESSION['_valo_flash'] = 'Scénario supprimé.';
            header('Location: ?');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['_valo_flash_error'] = $e->getMessage();
    }
}

// ─── Chargement d'un scénario existant ou paramètres courants ───────
$loadedScenario = null;
if (isset($_GET['loaded']) && (int)$_GET['loaded'] > 0) {
    $loadedScenario = inv_valo_load_scenario($pdo, (int)$_GET['loaded']);
}
$params = $loadedScenario ?: inv_valo_default_params();

// Applique aussi les params passés en GET (live preview avant sauvegarde)
foreach (array_keys(inv_valo_default_params()) as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $params[$k] = (float)$_GET[$k];
}

// ─── Calcul du portefeuille avec ces paramètres ─────────────────────
$port = inv_valo_compute_portefeuille($pdo, $params);

// ─── Historique scénarios ────────────────────────────────────────────
$history = inv_valo_list_scenarios($pdo);

$pageTitle     = 'Simulateur de valorisation';
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Simulateur';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn(float $v) => number_format($v, 0, ',', ' ');

$flash = $_SESSION['_valo_flash'] ?? '';
unset($_SESSION['_valo_flash']);
$flashErr = $_SESSION['_valo_flash_error'] ?? '';
unset($_SESSION['_valo_flash_error']);
?>
<div class="inv-wrap">

    <div class="inv-header">
        <h1>💰 Simulateur de valorisation</h1>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/')) ?>">☰ Liste</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="inv-paper" style="border-left:4px solid #4f7a3a; padding:12px 18px;"><?= $h($flash) ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="inv-paper" style="border-left:4px solid #b4443a; padding:12px 18px; color:#b4443a;"><?= $h($flashErr) ?></div><?php endif; ?>

    <form method="get" id="valo-form">

        <!-- KPI CONSOLIDÉS + GROS CURSEUR -->
        <div class="inv-paper" style="position:sticky; top:10px; z-index:50;">
            <div style="display:grid; grid-template-columns: 1.3fr 1fr; gap:24px; align-items:center;">

                <!-- Gros curseur simple -->
                <div>
                    <h2 style="border:none; margin:0 0 10px; padding:0; font-size:14px;">Rendement cible global</h2>
                    <div style="display:flex; align-items:center; gap:16px;">
                        <input type="range" id="valo-global" name="_global_slider" min="3" max="10" step="0.25"
                               value="<?= $h((float)($params['taux_commercial'] ?? 7)) ?>"
                               style="flex:1; accent-color:#24324a;">
                        <div style="font-family:'Sora',sans-serif; font-size:28px; font-weight:800; color:#24324a; min-width:80px; text-align:center;">
                            <span id="valo-global-val"><?= $h(number_format((float)($params['taux_commercial'] ?? 7), 2, ',', ' ')) ?></span> %
                        </div>
                    </div>
                    <p style="margin:8px 0 0; font-size:11.5px; color:#9a9690;">
                        Déplace le curseur pour appliquer le même taux à toutes les typologies.
                        Le prix théorique = loyer annuel ÷ taux. Secteur Lyon -1 / Métropole 0 / RA +0,5 / France +1,5.
                    </p>
                </div>

                <!-- KPI consolidés -->
                <div class="inv-kpis" style="flex-wrap:wrap; gap:10px; justify-content:flex-end;">
                    <div class="inv-kpi"><span class="v"><?= $port['totals']['nb'] ?></span><span class="l">Biens</span></div>
                    <div class="inv-kpi"><span class="v" id="kpi-catalogue"><?= $fmt((float)$port['totals']['prix_catalogue']) ?></span><span class="l">Catalogue €</span></div>
                    <div class="inv-kpi" style="background:#eef3ea;"><span class="v" id="kpi-theorique" style="color:#4f7a3a;"><?= $fmt((float)$port['totals']['prix_theorique']) ?></span><span class="l">Théorique €</span></div>
                    <div class="inv-kpi" style="background:<?= $port['totals']['ecart_pct'] >= 0 ? '#eef3ea' : '#fae9e1' ?>;">
                        <span class="v" id="kpi-ecart" style="color:<?= $port['totals']['ecart_pct'] >= 0 ? '#4f7a3a' : '#b4443a' ?>;">
                            <?= $port['totals']['ecart_pct'] >= 0 ? '+' : '' ?><?= number_format((float)$port['totals']['ecart_pct'], 1, ',', ' ') ?>%
                        </span><span class="l">Écart</span>
                    </div>
                </div>
            </div>

            <!-- Toggle "Affiner" -->
            <details style="margin-top:14px;">
                <summary style="cursor:pointer; font-family:'Sora',sans-serif; font-size:12px; font-weight:600; color:#4878a6; user-select:none;">
                    ⚙ Affiner par typologie et secteur
                </summary>
                <div style="margin-top:14px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                    <?php
                    $typoLabels = ['commercial' => 'Commercial', 'bureau' => 'Bureau', 'activite' => 'Activité', 'immeuble' => 'Immeuble', 'habitation' => 'Habitation', 'parking' => 'Parking', 'autre' => 'Autre'];
                    foreach ($typoLabels as $key => $lbl):
                    ?>
                    <div class="inv-field">
                        <label><?= $h($lbl) ?> — taux %</label>
                        <input type="number" name="taux_<?= $key ?>" step="0.25" min="1" max="15"
                               value="<?= $h((float)$params['taux_' . $key]) ?>"
                               class="valo-typo-input" data-typo="<?= $key ?>">
                    </div>
                    <?php endforeach; ?>
                    <?php foreach (['lyon' => 'Ajust. Lyon intra', 'metropole' => 'Ajust. Métropole', 'ra' => 'Ajust. Rhône-Alpes', 'france' => 'Ajust. France'] as $key => $lbl): ?>
                    <div class="inv-field">
                        <label><?= $h($lbl) ?> — +/-%</label>
                        <input type="number" name="ajust_<?= $key ?>" step="0.25" min="-5" max="5"
                               value="<?= $h((float)$params['ajust_' . $key]) ?>">
                    </div>
                    <?php endforeach; ?>
                    <div class="inv-field">
                        <label>Honoraires vente (%)</label>
                        <input type="number" name="taux_honoraires" step="0.1" min="0" max="10"
                               value="<?= $h((float)$params['taux_honoraires']) ?>">
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <button type="submit" class="inv-btn sm">Appliquer</button>
                </div>
            </details>
        </div>

    </form>

    <!-- Tableau bien par bien -->
    <div class="inv-paper inv-compare" style="margin-top:14px;">
        <h2>Valorisation bien par bien (<?= count($port['lines']) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left">Bien</th>
                    <th>Type</th>
                    <th>Secteur</th>
                    <th>Loyer/an</th>
                    <th>Catalogue</th>
                    <th>Taux</th>
                    <th>Théorique</th>
                    <th>Écart</th>
                    <th>Méthode</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($port['lines'] as $l):
                $ecartPct = (float)$l['ecart_pct'];
                $colorEcart = $ecartPct >= 15 ? '#4f7a3a' : ($ecartPct <= -15 ? '#b4443a' : '#5a5a55');
                $methodLabel = ['capitalisation' => '💰 Loyer', 'prix_m2' => '📐 m²', 'manuel' => '✍ Manuel'][$l['methode']] ?? $l['methode'];
            ?>
                <tr>
                    <td class="label" style="font-size:12px; max-width:260px;">
                        <a href="<?= $h($u('/investisseur/detail.php?id=' . (int)$l['id_analyse'])) ?>" style="text-decoration:none; color:#24324a;"><?= $h($l['titre_analyse']) ?></a>
                    </td>
                    <td style="font-size:11.5px;"><?= $h($l['typologie']) ?></td>
                    <td style="font-size:11.5px;"><?= $h($l['secteur']) ?></td>
                    <td><?= (float)$l['loyer_annuel'] > 0 ? $fmt((float)$l['loyer_annuel']) . ' €' : '—' ?></td>
                    <td><?= $fmt((float)$l['prix_catalogue']) ?> €</td>
                    <td><?= number_format((float)$l['taux_applique'], 2, ',', ' ') ?>%</td>
                    <td style="font-weight:800; color:#24324a;"><?= $fmt((float)$l['prix_theorique']) ?> €</td>
                    <td style="color:<?= $colorEcart ?>; font-weight:700;">
                        <?= $ecartPct >= 0 ? '+' : '' ?><?= number_format($ecartPct, 1, ',', ' ') ?>%
                    </td>
                    <td style="font-size:11px;"><?= $h($methodLabel) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--inv-bg); font-weight:700;">
                    <td class="label">TOTAL</td>
                    <td>—</td><td>—</td>
                    <td><?= $fmt((float)$port['totals']['loyer_annuel']) ?> €</td>
                    <td><?= $fmt((float)$port['totals']['prix_catalogue']) ?> €</td>
                    <td>—</td>
                    <td style="color:#24324a;"><?= $fmt((float)$port['totals']['prix_theorique']) ?> €</td>
                    <td style="color:<?= $port['totals']['ecart_pct'] >= 0 ? '#4f7a3a' : '#b4443a' ?>;"><?= $port['totals']['ecart_pct'] >= 0 ? '+' : '' ?><?= number_format((float)$port['totals']['ecart_pct'], 1, ',', ' ') ?>%</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Enregistrer / Versionner -->
    <div class="inv-paper">
        <h2>💾 Enregistrer ce scénario (versionning)</h2>
        <form method="post" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <?php foreach (array_keys(inv_valo_default_params()) as $k): ?>
                <input type="hidden" name="<?= $h($k) ?>" value="<?= $h((float)$params[$k]) ?>">
            <?php endforeach; ?>
            <?php if ($loadedScenario): ?><input type="hidden" name="id_scenario" value="<?= (int)$loadedScenario['id'] ?>"><?php endif; ?>

            <div class="inv-field" style="flex:1; min-width:240px;">
                <label>Nom du scénario *</label>
                <input type="text" name="nom_scenario" required
                       value="<?= $h($loadedScenario['nom_scenario'] ?? 'Valorisation ' . date('d/m/Y')) ?>"
                       placeholder="Ex : Valo offensive avril 2026">
            </div>
            <div class="inv-field" style="flex:2; min-width:300px;">
                <label>Description (optionnel)</label>
                <input type="text" name="description"
                       value="<?= $h($loadedScenario['description'] ?? '') ?>"
                       placeholder="Commentaire marché, hypothèse de cession, etc.">
            </div>
            <button type="submit" class="inv-btn primary">
                <?= $loadedScenario ? 'Mettre à jour' : 'Enregistrer' ?>
            </button>
        </form>
        <p style="margin:10px 0 0; font-size:11.5px; color:#9a9690;">
            L'enregistrement fige les valeurs catalogue et théoriques de chaque bien à l'instant T.
            Idéal pour tracer l'évolution de la valorisation dans le temps.
        </p>
    </div>

    <!-- Historique des scénarios -->
    <?php if (!empty($history)): ?>
    <div class="inv-paper">
        <h2>📚 Historique des scénarios (<?= count($history) ?>)</h2>
        <?php foreach ($history as $s):
            $diffPct = $s['total_catalogue'] > 0 ? round((((float)$s['total_theorique'] - (float)$s['total_catalogue']) / (float)$s['total_catalogue']) * 100, 2) : 0;
            $col = $diffPct >= 0 ? '#4f7a3a' : '#b4443a';
        ?>
        <div class="inv-card-list" style="--bar-color:<?= $col ?>; margin-bottom:8px;">
            <a href="?loaded=<?= (int)$s['id'] ?>" class="ic-title-block" style="text-decoration:none;">
                <div class="ic-title"><?= $h($s['nom_scenario']) ?></div>
                <div class="ic-sub">
                    <?= $h($s['description'] ?: '—') ?> ·
                    <?= (int)$s['nb_snapshots'] ?> biens figés ·
                    <?= date('d/m/Y H:i', strtotime((string)$s['created_at'])) ?>
                </div>
            </a>
            <div class="ic-metric">
                <span class="mv"><?= $fmt((float)$s['total_theorique']) ?></span>
                <span class="ml">Théorique €</span>
            </div>
            <div class="ic-metric">
                <span class="mv" style="color:<?= $col ?>;"><?= $diffPct >= 0 ? '+' : '' ?><?= number_format($diffPct, 1, ',', ' ') ?>%</span>
                <span class="ml">vs catalogue</span>
            </div>
            <div class="ic-actions">
                <a href="?loaded=<?= (int)$s['id'] ?>" class="inv-btn sm">Charger</a>
                <a href="<?= $h($u('/investisseur/partager.php?type=scenario&id_ref=' . (int)$s['id'] . '&email=t.saby@groupe-sir.fr')) ?>" class="inv-btn sm">📤 Partager</a>
                <form method="post" style="margin:0;" onsubmit="return confirm('Supprimer ce scénario ?');">
                    <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_scenario" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="inv-btn sm danger">Suppr.</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
// Curseur global → propage la valeur à toutes les typologies + submit
(function () {
    const slider = document.getElementById('valo-global');
    const val    = document.getElementById('valo-global-val');
    const form   = document.getElementById('valo-form');
    if (!slider) return;

    let debounceT = null;
    slider.addEventListener('input', (e) => {
        const v = parseFloat(e.target.value).toFixed(2).replace('.', ',');
        val.textContent = v;
        // Propage aux sous-inputs typologie
        document.querySelectorAll('.valo-typo-input').forEach(i => i.value = e.target.value);
        clearTimeout(debounceT);
        debounceT = setTimeout(() => form.submit(), 500);
    });

    // Si l'utilisateur change un input typologie, on ne rebondit pas sur le slider
    document.querySelectorAll('.valo-typo-input').forEach(i => {
        i.addEventListener('change', () => {
            // ok, on laisse le submit manuel depuis le bouton "Appliquer"
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
