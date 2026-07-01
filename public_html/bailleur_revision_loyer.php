<?php
/*
 * bailleur_revision_loyer.php — Révision de loyer avec analyse IA du bail
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo']; $userId = (int)current_user_id(); $roleId = (int)current_role_id();
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }

$current_page = 'bailleur_revision_loyer';

/* ── Propriétaires ── */
$stmtProp = $pdo->prepare("SELECT up.id_proprietaire, p.societe, p.nom FROM user_proprietaires up JOIN proprietaires p ON p.id = up.id_proprietaire WHERE up.id_user = ? ORDER BY up.ordre");
$stmtProp->execute([$userId]);
$proprietaires = $stmtProp->fetchAll(PDO::FETCH_ASSOC);
$propIds = array_column($proprietaires, 'id_proprietaire');
if (empty($propIds) && in_array($roleId, [1, 7], true)) {
    $proprietaires = $pdo->query("SELECT id AS id_proprietaire, COALESCE(societe,CONCAT(nom,' ',prenom)) AS societe, nom FROM proprietaires WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $propIds = array_column($proprietaires, 'id_proprietaire');
}

$fProp = isset($_GET['prop']) ? (int)$_GET['prop'] : 0;

/* ── IRL disponibles ── */
$irls = $pdo->query("SELECT annee, trimestre, valeur, variation_pct FROM irl_indices ORDER BY annee DESC, trimestre DESC")->fetchAll(PDO::FETCH_ASSOC);

/* ── Analyses de baux existantes ── */
$analyses = [];
if (!empty($propIds)) {
    $ph = implode(',', array_fill(0, count($propIds), '?'));
    $w = $fProp > 0 ? "id_proprietaire = ?" : "id_proprietaire IN ($ph)";
    $p = $fProp > 0 ? [$fProp] : $propIds;
    $stmtA = $pdo->prepare("SELECT * FROM bailleur_baux_analyses WHERE $w ORDER BY date_analyse DESC");
    $stmtA->execute($p);
    $analyses = $stmtA->fetchAll(PDO::FETCH_ASSOC);
}

/* ── Layout ── */
$layout_title = 'Révision de loyer'; $layout_module = 'Ma Box Bailleur'; $layout_sidebar = 'sidebar_bailleur_module';
$_act = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;';
$_on = $_act.'background:#4a6038;color:#fff;border:1px solid #4a6038;';
$_off = $_act.'background:#fff;color:#555;border:1px solid #d4d7de;';
$layout_head_kpis = '<div style="display:flex;gap:10px;justify-content:center;flex:1;">
    <a href="bailleur_dashboard.php?prop='.$fProp.'" style="'.$_off.'">📊 Dashboard</a>
    <a href="bailleur_immeubles.php?prop='.$fProp.'" style="'.$_off.'">🏢 Immeubles</a>
    <a href="bailleur_revision_loyer.php?prop='.$fProp.'" style="'.$_on.'">📐 Révision loyer</a>
    <a href="bailleur_ged.php?prop='.$fProp.'" style="'.$_off.'">📁 GED</a>
    <a href="bailleur_crg_audit.php?prop='.$fProp.'" style="'.$_off.'">🔍 Audit</a>
    <a href="bailleur_sci_organigramme.php" style="'.$_off.'">🏛 SCI</a>
</div>';
$layout_head_actions = '';

$layout_extra_css = '<style>
.bf{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:12px 16px;background:#fff;border-radius:12px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bf select,.bf input{padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px}
.bf label{font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px}
.bs{background:#fff;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bs-t{font-size:14px;font-weight:700;margin-bottom:12px}
.bt{width:100%;border-collapse:collapse;font-size:12px}
.bt th{text-align:left;font-size:9px;text-transform:uppercase;color:#888;padding:6px 8px;border-bottom:2px solid #eee}
.bt td{padding:6px 8px;border-bottom:1px solid #f3f4f6}
.bt tr:hover{background:#f9fafb}
.upload-zone{background:#f8f7f5;border:2px dashed #d4d7de;border-radius:12px;padding:24px;text-align:center;margin-bottom:18px}
.rev-result{background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1px solid #86efac;border-radius:14px;padding:20px;margin-top:16px}
.rev-result h3{color:#16a34a;margin:0 0 12px}
.rev-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.rev-card{background:#fff;border-radius:10px;padding:12px;text-align:center;box-shadow:2px 2px 6px rgba(0,0,0,.04)}
.rev-card .val{font-size:18px;font-weight:700;font-family:"DM Mono",monospace}
.rev-card .lbl{font-size:10px;color:#888;margin-top:2px}
#bailResult{display:none}
.ged-msg{padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:14px}
.ged-msg.ok{background:#f0fdf4;color:#16a34a}.ged-msg.err{background:#fef2f2;color:#dc2626}
</style>';

ob_start();
?>

<!-- Upload bail -->
<div class="bs">
    <div class="bs-t">📐 Analyser un bail pour calculer la révision</div>
    <div class="upload-zone" id="uploadZone">
        <div style="font-size:28px;margin-bottom:8px;">📄</div>
        <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Importez votre bail (PDF)</div>
        <div style="font-size:12px;color:#888;margin-bottom:14px;">L'IA extraira automatiquement le loyer, les dates, l'indice de référence et calculera la révision</div>
        <form id="bailForm" style="display:inline-flex;gap:10px;align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <select name="id_proprietaire" style="padding:6px 10px;border:1px solid #d4d7de;border-radius:8px;font-size:12px;">
                <option value="0">— Propriétaire —</option>
                <?php foreach ($proprietaires as $p): ?>
                <option value="<?= $p['id_proprietaire'] ?>" <?= $fProp==$p['id_proprietaire']?'selected':'' ?>><?= h($p['societe']?:$p['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="file" name="bail_pdf" accept="application/pdf" required style="font-size:12px;">
            <button type="button" onclick="analyzeBail()" id="btnAnalyze" style="padding:8px 20px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">🔍 Analyser le bail</button>
        </form>
    </div>
    <div id="bailError" class="ged-msg err" style="display:none"></div>

    <!-- Résultat d'analyse -->
    <div id="bailResult">
        <div class="rev-result" id="revisionBlock" style="display:none">
            <h3>📐 Révision de loyer calculée</h3>
            <div class="rev-grid" id="revGrid"></div>
            <div style="margin-top:14px;font-size:12px;color:#666;" id="revFormula"></div>
        </div>
        <div class="bs" id="bailDetails" style="margin-top:16px">
            <div class="bs-t">Données extraites du bail</div>
            <div id="bailData"></div>
        </div>
    </div>
</div>

<!-- Tableau IRL -->
<div class="bs">
    <div class="bs-t">Indices de Référence des Loyers (IRL) — INSEE</div>
    <table class="bt">
        <thead><tr><th>Année</th><th>Trimestre</th><th>Valeur</th><th>Variation</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($irls, 0, 16) as $irl): ?>
        <tr><td><?= $irl['annee'] ?></td><td>T<?= $irl['trimestre'] ?></td>
        <td style="font-family:'DM Mono',monospace;font-weight:600"><?= number_format((float)$irl['valeur'], 2, ',', ' ') ?></td>
        <td style="color:<?= (float)$irl['variation_pct'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= $irl['variation_pct'] ? '+'.number_format((float)$irl['variation_pct'], 2, ',', ' ').' %' : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Baux analysés -->
<?php if (!empty($analyses)): ?>
<div class="bs">
    <div class="bs-t">Baux analysés (<?= count($analyses) ?>)</div>
    <table class="bt">
        <thead><tr><th>Locataire</th><th>Type</th><th>Loyer HC</th><th>Charges</th><th>Date bail</th><th>Révision</th><th>Indice réf.</th><th>Bail</th></tr></thead>
        <tbody>
        <?php foreach ($analyses as $a): ?>
        <tr>
            <td><strong><?= h($a['locataire_nom']) ?></strong><br><span style="font-size:10px;color:#888"><?= h($a['adresse_bien'] ?? '') ?></span></td>
            <td style="font-size:11px"><?= h($a['type_bail']) ?></td>
            <td style="font-family:'DM Mono',monospace"><?= $a['loyer_initial'] ? fmt((float)$a['loyer_initial']) : '—' ?></td>
            <td style="font-family:'DM Mono',monospace"><?= $a['charges_provisions'] ? fmt((float)$a['charges_provisions']) : '—' ?></td>
            <td style="font-size:11px"><?= $a['date_entree'] ? date('d/m/Y', strtotime($a['date_entree'])) : ($a['date_bail'] ? date('d/m/Y', strtotime($a['date_bail'])) : '—') ?></td>
            <td style="font-size:11px"><?= h($a['type_revision'] ?? 'IRL') ?></td>
            <td style="font-size:11px;font-family:'DM Mono',monospace"><?= $a['indice_reference'] ? $a['indice_reference'] . ' (T' . $a['trimestre_reference'] . ' ' . $a['annee_reference'] . ')' : '—' ?></td>
            <td><?php if ($a['fichier_bail']): ?><a href="<?= h($a['fichier_bail']) ?>" target="_blank" style="color:#4878a6">📄</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
$layout_extra_js = '<script>
async function analyzeBail() {
    var form = document.getElementById("bailForm");
    var btn = document.getElementById("btnAnalyze");
    var errDiv = document.getElementById("bailError");
    errDiv.style.display = "none";

    var fileInput = form.querySelector("input[type=file]");
    if (!fileInput.files[0]) { showErr("Sélectionnez un fichier PDF."); return; }

    btn.disabled = true; btn.textContent = "⏳ Analyse IA en cours (30-60s)…";
    var fd = new FormData(form);

    try {
        var r = await fetch("api/bail_analyze.php", {
            method: "POST",
            headers: {"X-CSRF-Token": form.querySelector("[name=csrf_token]").value},
            body: fd
        });
        var d = await r.json();
        if (!d.ok) { showErr(d.error || "Erreur analyse."); btn.disabled = false; btn.textContent = "🔍 Analyser le bail"; return; }

        document.getElementById("bailResult").style.display = "";
        showBailData(d.data);
        if (d.revision) showRevision(d.revision);

    } catch(e) { showErr("Erreur réseau: " + e.message); }
    btn.disabled = false; btn.textContent = "🔍 Analyser le bail";
}

function showErr(msg) {
    var el = document.getElementById("bailError");
    el.textContent = msg; el.style.display = "";
}

function showRevision(rev) {
    var block = document.getElementById("revisionBlock");
    block.style.display = "";
    var grid = document.getElementById("revGrid");
    grid.innerHTML = ""
        + card(fmtE(rev.loyer_actuel), "Loyer actuel", "#333")
        + card(fmtE(rev.loyer_revise), "Loyer révisé", "#16a34a")
        + card((rev.augmentation >= 0 ? "+" : "") + fmtE(rev.augmentation), "Augmentation", rev.augmentation > 0 ? "#dc2626" : "#16a34a")
        + card((rev.pct >= 0 ? "+" : "") + rev.pct.toFixed(2) + " %", "Variation", rev.pct > 0 ? "#dc2626" : "#16a34a")
        + card(rev.indice_ancien.toFixed(2), "Ancien indice (T" + rev.trimestre + " " + (rev.annee_nouveau - 1) + ")", "#888")
        + card(rev.indice_nouveau.toFixed(2), "Nouvel indice (T" + rev.trimestre + " " + rev.annee_nouveau + ")", "#4878a6");

    document.getElementById("revFormula").innerHTML = "<strong>Formule :</strong> " + fmtE(rev.loyer_actuel) + " × " + rev.indice_nouveau.toFixed(2) + " / " + rev.indice_ancien.toFixed(2) + " = <strong>" + fmtE(rev.loyer_revise) + "</strong>";
}

function showBailData(data) {
    var h = "";
    var loc = data.locataire || {};
    var bien = data.bien || {};
    var cond = data.conditions || {};
    var rev = data.revision || {};

    h += "<div style=\\"display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px;\\">";
    h += sec("Locataire", [["Nom", (loc.nom||"") + " " + (loc.prenom||"")], ["Type bail", data.type_bail||""]]);
    h += sec("Bien", [["Adresse", bien.adresse||""], ["Type", bien.type||""], ["Surface", bien.surface ? bien.surface + " m²" : "—"], ["Pièces", bien.nb_pieces||"—"]]);
    h += sec("Conditions", [["Loyer HC", cond.loyer_mensuel_hc ? fmtE(cond.loyer_mensuel_hc) : "—"], ["Charges", cond.charges_provisions ? fmtE(cond.charges_provisions) : "—"], ["Total CC", cond.loyer_total_cc ? fmtE(cond.loyer_total_cc) : "—"], ["Dépôt garantie", cond.depot_garantie ? fmtE(cond.depot_garantie) : "—"], ["Date entrée", cond.date_entree||"—"], ["Durée", cond.duree_bail||"—"]]);
    h += sec("Révision", [["Type", rev.type||"—"], ["Indice réf.", rev.indice_reference||"—"], ["Trimestre réf.", rev.trimestre_reference ? "T"+rev.trimestre_reference+" "+rev.annee_reference : "—"], ["Clause", rev.clause_revision_texte ? rev.clause_revision_texte.substring(0,100)+"…" : "—"]]);
    h += "</div>";
    document.getElementById("bailData").innerHTML = h;
}

function sec(title, rows) {
    var h = "<div style=\\"background:#f9fafb;border-radius:10px;padding:12px;\\"><div style=\\"font-weight:700;font-size:12px;margin-bottom:8px;\\">" + title + "</div>";
    rows.forEach(function(r) { h += "<div style=\\"display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #f0f0f0;\\"><span style=\\"color:#888;\\">" + r[0] + "</span><span style=\\"font-weight:600;\\">" + r[1] + "</span></div>"; });
    return h + "</div>";
}

function card(val, lbl, color) { return "<div class=\\"rev-card\\"><div class=\\"val\\" style=\\"color:" + color + "\\">" + val + "</div><div class=\\"lbl\\">" + lbl + "</div></div>"; }
function fmtE(n) { return parseFloat(n).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €"; }
</script>';

require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
