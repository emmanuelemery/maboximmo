<?php
/*
 * agency_immeuble_fiche.php — Fiche détaillée d'un immeuble (layout_maboximmo)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = current_role_id();
$pdo    = $GLOBALS['pdo'];

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: agency_immeubles.php'); exit; }

// ── Chargement immeuble ───────────────────────────────────────
$stmt = $pdo->prepare("SELECT i.*, e.nom AS nom_etablissement, CONCAT(u.prenom, ' ', u.nom) AS nom_gestionnaire
    FROM immeubles i
    LEFT JOIN etablissements e ON i.id_agence = e.id
    LEFT JOIN users u ON i.id_societe = u.id
    WHERE i.id = ?");
$stmt->execute([$id]);
$imm = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$imm) { header('Location: agency_immeubles.php'); exit; }

// ── Chargement infos fiche ────────────────────────────────────
$stmt2 = $pdo->prepare("SELECT * FROM immeubles_infos WHERE id_immeuble = ?");
$stmt2->execute([$id]);
$fiche = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Liste établissements ──────────────────────────────────────
$etablissements = [];
try {
    $etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Traitement POST (sauvegarde) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $roleId <= 2) {
    // ── Maj table immeubles (onglet identité) ──
    $immFields = ['reference_immeuble','nom_immeuble','adresse_1','code_postal','ville','nb_lots','type_immeuble','id_agence','latitude','longitude'];
    $immSets = []; $immData = [];
    foreach ($immFields as $f) {
        if (isset($_POST[$f])) {
            $immSets[] = "$f = :$f";
            $immData[$f] = trim((string)$_POST[$f]);
            if (in_array($f, ['nb_lots','id_agence'], true)) $immData[$f] = (int)$immData[$f] ?: null;
            if (in_array($f, ['latitude','longitude'], true)) $immData[$f] = $immData[$f] !== '' ? (float)$immData[$f] : null;
        }
    }
    if ($immSets) {
        $immData['id'] = $id;
        $pdo->prepare("UPDATE immeubles SET " . implode(',', $immSets) . " WHERE id = :id")->execute($immData);
        // Recharger l'immeuble
        $stmt->execute([$id]);
        $imm = $stmt->fetch(PDO::FETCH_ASSOC) ?: $imm;
    }

    $fields = [
        'budget','date_ag_derniere','date_ag_prochaine','date_arrete_comptes',
        'travaux_cours','travaux_prevoir','interventions_cours','procedures',
        'boite_cles','particularites','contrats','debiteurs','copros','mail',
        'sinistres','honoraires_ht','honoraires_lot','bloc_note_ics','commentaires',
        'hono_2026','hono_2027','hono_2028','hono_2029','hono_2030',
        'ag_2025','ag_2026','ag_2027','ag_2028','ag_2029','ag_2030',
    ];
    $data = [];
    foreach ($fields as $f) {
        $val = $_POST[$f] ?? null;
        $data[$f] = ($val === '') ? null : $val;
    }

    if ($fiche) {
        $set  = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($data)));
        $stmt = $pdo->prepare("UPDATE immeubles_infos SET $set WHERE id_immeuble = :id_imm");
        $data['id_imm'] = $id;
        $stmt->execute($data);
    } else {
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $phd  = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $stmt = $pdo->prepare("INSERT INTO immeubles_infos ($cols, id_immeuble) VALUES ($phd, :id_imm)");
        $data['id_imm'] = $id;
        $stmt->execute($data);
    }

    header('Location: agency_immeuble_fiche.php?' . http_build_query(array_merge($_GET, ['id' => $id, 'saved' => 1])));
    exit;
}

// ── Navigation prev/next ──
$navConds  = [];
$navParams = [];
if (($v = $_GET['reference'] ?? '') !== '') { $navConds[] = 'i.reference_immeuble LIKE ?'; $navParams[] = "%$v%"; }
if (($v = $_GET['nom']       ?? '') !== '') { $navConds[] = 'i.nom_immeuble LIKE ?';       $navParams[] = "%$v%"; }
if (($v = $_GET['ville']     ?? '') !== '') { $navConds[] = 'i.ville LIKE ?';     $navParams[] = "%$v%"; }
if (($v = $_GET['type']      ?? '') !== '') { $navConds[] = 'i.type_immeuble = ?';         $navParams[] = $v; }
if (($v = (int)($_GET['etablissement'] ?? 0)) > 0) { $navConds[] = 'i.id_agence = ?'; $navParams[] = $v; }

$navWhere = $navConds ? ' WHERE ' . implode(' AND ', $navConds) : '';
$stmtNav  = $pdo->prepare("SELECT id FROM immeubles i $navWhere ORDER BY i.reference_immeuble ASC");
$stmtNav->execute($navParams);
$allIds   = $stmtNav->fetchAll(PDO::FETCH_COLUMN);
$pos      = array_search((string)$id, array_map('strval', $allIds));
$prevId   = ($pos !== false && $pos > 0) ? $allIds[$pos - 1] : null;
$nextId   = ($pos !== false && $pos < count($allIds) - 1) ? $allIds[$pos + 1] : null;
$total    = count($allIds);

function buildNavUrl(int $newId): string {
    $p = $_GET; $p['id'] = $newId;
    return 'agency_immeuble_fiche.php?' . http_build_query($p);
}

$anneeCourante = (int)date('Y');
$saved   = (int)($_GET['saved'] ?? 0);
$canEdit = ($roleId <= 2);
$nomImm  = $imm['nom'] ?? $imm['nom_immeuble'] ?? '';
$refImm  = $imm['reference'] ?? $imm['reference_immeuble'] ?? '';

// ── Layout ──
$layout_title    = $nomImm ?: 'Fiche immeuble';
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$_honoHt = !empty($fiche['honoraires_ht']) ? number_format((float)$fiche['honoraires_ht'],0,',',' ').' €' : '—';
$_dateAg = !empty($fiche['date_ag_prochaine']) ? date('d/m/y', strtotime($fiche['date_ag_prochaine'])) : '—';
$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)($imm['nb_lots'] ?? 0).'</div><div class="ph-kpi-lbl">Lots</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.$_honoHt.'</div><div class="ph-kpi-lbl">Hono. HT</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.$_dateAg.'</div><div class="ph-kpi-lbl">Prochaine AG</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.($pos !== false ? ($pos + 1) : '—').' / '.(int)$total.'</div><div class="ph-kpi-lbl">Position</div></div>
';

$navPrev = $prevId
    ? '<a href="'.h(buildNavUrl((int)$prevId)).'" class="ph-btn">← Préc.</a>'
    : '<a class="ph-btn dispo">← Préc.</a>';
$navNext = $nextId
    ? '<a href="'.h(buildNavUrl((int)$nextId)).'" class="ph-btn">Suiv. →</a>'
    : '<a class="ph-btn dispo">Suiv. →</a>';

$layout_head_actions = $navPrev . $navNext . '
    <a href="agency_pdf_cr.php?id='.$id.'" target="_blank" class="ph-btn">PDF</a>
    '.($canEdit
        ? '<a href="agency_immeuble_form.php?id='.$id.'" class="ph-btn primary">Modifier</a>'
        : '<a class="ph-btn dispo">—</a>').'
';

$layout_extra_css = <<<'EXTRACSS'
<style>
/* Onglets fiche immeuble */
.tabs-bar {
    display: flex; gap: 0;
    background: var(--bg-secondary, #eef1f6);
    border-bottom: 1px solid rgba(196,192,186,0.4);
    padding: 0 12px;
}
.tab-btn {
    padding: 12px 18px; border: none; background: transparent;
    font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
    color: #8a8680; cursor: pointer; border-bottom: 2px solid transparent;
    transition: color .15s, border-color .15s;
}
.tab-btn:hover { color: #4a6038; }
.tab-btn.active { color: #2f587d; border-bottom-color: #2f587d; font-weight: 700; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.tab-actions {
    display: flex; justify-content: flex-end; gap: 8px;
    margin-top: 18px; padding-top: 14px;
    border-top: 1px solid rgba(196,192,186,0.3);
}

.v2-btn { padding: 0 16px; height: 34px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.04em; background: var(--bg-primary,#e4e8f0); box-shadow: 4px 4px 10px var(--shadow-dark,#d4d7de), -4px -4px 10px #ffffff; font-weight: 600; color: #3a3830; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: box-shadow 0.15s; }
.v2-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark,#d4d7de), inset -3px -3px 8px #ffffff; }
.v2-btn.primary { background: #4878a6; color: #fff; box-shadow: 3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 8px #ffffff; }
.v2-btn.success { background: #3a7a6a; color: #fff; }
.v2-btn.warning { background: #7a6830; color: #fff; }

/* Section card */
.fiche-section { background: var(--bg-primary,#e4e8f0); border-radius: 14px; box-shadow: 5px 5px 12px var(--shadow-dark,#d4d7de), -5px -5px 12px #ffffff; padding: 18px 22px; margin-bottom: 16px; }
.fiche-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 12px; }
.fiche-row:last-child { margin-bottom: 0; }
.fiche-row.cols2 { grid-template-columns: 1fr 1fr; }
.fiche-row.cols3 { grid-template-columns: 1fr 1fr 1fr; }
.fiche-row.cols1 { grid-template-columns: 1fr; }

.ff label { display: block; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: #a8a49e; margin-bottom: 5px; }
.ff input, .ff textarea, .ff select {
    width: 100%; background: var(--bg-primary,#e4e8f0);
    box-shadow: inset 3px 3px 6px var(--shadow-dark,#d4d7de), inset -3px -3px 8px #ffffff;
    border: none; border-radius: 10px; padding: 8px 12px;
    font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
    outline: none; resize: vertical; box-sizing: border-box;
}
.ff input:focus, .ff textarea:focus, .ff select:focus {
    box-shadow: inset 3px 3px 6px var(--shadow-dark,#d4d7de), inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(72,120,166,0.3);
}
.ff input[readonly] { color: #8a8680; cursor: default; box-shadow: inset 2px 2px 4px var(--shadow-dark,#d4d7de), inset -2px -2px 4px var(--bg-secondary,#eef1f6); }
.ff textarea { min-height: 60px; line-height: 1.5; }

/* Tableau honoraires */
.hono-wrap { overflow-x: auto; margin-top: 10px; }
.hono-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 660px; }
.hono-table th { padding: 8px 10px; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: #4a6038; border-bottom: 1px solid rgba(196,192,186,0.5); text-align: center; background: rgba(72,120,166,0.06); white-space: nowrap; }
.hono-table th:first-child { text-align: left; }
.hono-table td { padding: 8px 8px; border-bottom: 1px solid rgba(196,192,186,0.25); text-align: center; vertical-align: middle; }
.hono-table td:first-child { text-align: left; font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680; letter-spacing: 0.06em; }
.hono-table input[type=text] {
    width: 90px; text-align: right; background: var(--bg-primary,#e4e8f0);
    box-shadow: inset 2px 2px 5px var(--shadow-dark,#d4d7de), inset -2px -2px 5px #ffffff;
    border: none; border-radius: 8px; padding: 5px 8px;
    font-family: 'DM Mono', monospace; font-size: 11px; color: #1a1816; outline: none; box-sizing: border-box;
}
.hono-table input[type=text]:focus { box-shadow: inset 2px 2px 5px var(--shadow-dark,#d4d7de), inset -2px -2px 5px #ffffff, 0 0 0 2px rgba(72,120,166,0.3); }
.hono-table input.ro { color: #8a8680; cursor: default; box-shadow: inset 1px 1px 3px var(--shadow-dark,#d4d7de), inset -1px -1px 3px var(--bg-secondary,#eef1f6); }
.hono-table input[type=date] { width: 120px; font-size: 11px; }
.evo-chip { display: inline-block; padding: 3px 8px; border-radius: 8px; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600; background: #f0f0f0; color: #6a6660; white-space: nowrap; }
.evo-chip.pos { background: #e0f0e8; color: #3a7a6a; }
.evo-chip.neg { background: #fce8e6; color: #8a5040; }

/* Info immeuble header */
.imm-header { background: var(--bg-primary,#e4e8f0); border-radius: 14px; box-shadow: 5px 5px 12px var(--shadow-dark,#d4d7de), -5px -5px 12px #ffffff; padding: 16px 20px; margin-bottom: 16px; display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.imm-header-nom { font-size: 18px; font-weight: 700; color: #1a1816; }
.imm-header-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: #4878a6; font-weight: 600; margin-top: 2px; }
.imm-header-meta { display: flex; gap: 16px; margin-top: 10px; flex-wrap: wrap; }
.imm-meta-item { display: flex; flex-direction: column; gap: 1px; }
.imm-meta-label { font-family: 'DM Mono', monospace; font-size: 8px; color: #a8a49e; text-transform: uppercase; letter-spacing: 0.1em; }
.imm-meta-value { font-size: 13px; font-weight: 600; color: #1a1816; }
.imm-header-actions { display: flex; gap: 8px; align-items: flex-start; }

/* Retour AG widget */
.ag-widget { display: flex; align-items: center; gap: 8px; }
.ag-widget select { height: 32px; padding: 0 8px; background: var(--bg-primary,#e4e8f0); box-shadow: inset 2px 2px 5px var(--shadow-dark,#d4d7de), inset -2px -2px 5px #ffffff; border: none; border-radius: 8px; font-family: 'DM Mono', monospace; font-size: 11px; color: #1a1816; outline: none; }

/* Alert success */
.alert-saved { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 10px; background: #e0f0e8; color: #3a7a6a; border: 1px solid #b0d8c0; font-size: 13px; margin-bottom: 14px; }
</style>
EXTRACSS;

$_gmapsKey = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '';
$layout_extra_js = '<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".tab-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const tab = this.dataset.tab;
            document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
            document.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("active"));
            this.classList.add("active");
            document.querySelector(".tab-panel[data-tab=\"" + tab + "\"]").classList.add("active");
        });
    });
});

window.initImmAutocomplete = function() {
    const input = document.getElementById("address-search");
    if (!input || !window.google || !google.maps || !google.maps.places) return;
    const ac = new google.maps.places.Autocomplete(input, { types: ["address"], componentRestrictions: { country: "fr" } });
    ac.addListener("place_changed", function() {
        const p = ac.getPlace();
        if (!p || !p.address_components) return;
        let street = "", num = "", zip = "", city = "";
        p.address_components.forEach(c => {
            if (c.types.includes("street_number")) num = c.long_name;
            if (c.types.includes("route")) street = c.long_name;
            if (c.types.includes("postal_code")) zip = c.long_name;
            if (c.types.includes("locality")) city = c.long_name;
        });
        document.getElementById("addr_street").value = (num + " " + street).trim();
        document.getElementById("addr_zip").value = zip;
        document.getElementById("addr_city").value = city;
        if (p.geometry && p.geometry.location) {
            document.getElementById("addr_lat").value = p.geometry.location.lat();
            document.getElementById("addr_lng").value = p.geometry.location.lng();
        }
    });
};
</script>';
if ($_gmapsKey) {
    $layout_extra_js .= '<script async src="https://maps.googleapis.com/maps/api/js?key=' . urlencode($_gmapsKey) . '&libraries=places&callback=initImmAutocomplete"></script>';
}

// ── Contenu ──
ob_start();
?>

<?php if ($saved): ?>
<div class="alert-saved">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    Fiche enregistrée avec succès.
</div>
<?php endif; ?>

<!-- HEADER IMMEUBLE -->
<div class="imm-header">
    <div>
        <div class="imm-header-nom"><?= h($nomImm) ?></div>
        <div class="imm-header-ref"><?= h($refImm) ?><?= !empty($imm['immatriculation']) ? ' · Immat. ' . h($imm['immatriculation']) : '' ?></div>
        <div class="imm-header-meta">
            <div class="imm-meta-item">
                <span class="imm-meta-label">Adresse</span>
                <span class="imm-meta-value" style="font-size:12px"><?= h($imm['adresse'] ?? '') ?>, <?= h($imm['code_postal'] ?? '') ?> <?= h($imm['ville'] ?? '') ?></span>
            </div>
            <div class="imm-meta-item">
                <span class="imm-meta-label">Lots</span>
                <span class="imm-meta-value"><?= (int)$imm['nb_lots'] ?></span>
            </div>
            <div class="imm-meta-item">
                <span class="imm-meta-label">Établissement</span>
                <span class="imm-meta-value" style="font-size:12px"><?= h($imm['nom_etablissement'] ?? '—') ?></span>
            </div>
            <div class="imm-meta-item">
                <span class="imm-meta-label">Gestionnaire</span>
                <span class="imm-meta-value" style="font-size:12px"><?= h($imm['nom_gestionnaire'] ?? '—') ?></span>
            </div>
            <?php if ($fiche['mail'] ?? ''): ?>
            <div class="imm-meta-item">
                <span class="imm-meta-label">Mail multidiffusion</span>
                <span class="imm-meta-value" style="font-size:11px"><?= h($fiche['mail']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="imm-header-actions">
        <form action="agency_reunion_tenir.php" method="get" target="_blank" class="ag-widget">
            <input type="hidden" name="id_immeuble" value="<?= $id ?>">
            <select name="annee">
                <?php for ($a = $anneeCourante - 1; $a <= 2030; $a++): ?>
                    <option value="<?= $a ?>" <?= $a === $anneeCourante ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="v2-btn warning" style="height:32px;font-size:11px">
                📋 Retour AG
            </button>
        </form>
        <a href="agency_reunions.php?id_immeuble=<?= $id ?>" class="v2-btn" style="font-size:11px;height:30px">
            🗓 Réunions
        </a>
        <a href="agency_taches.php?id_immeuble=<?= $id ?>" class="v2-btn" style="font-size:11px;height:30px">
            ✅ Tâches
        </a>
    </div>
</div>

<form method="POST" id="fiche-form">

<!-- ═══ ONGLETS ═══ -->
<div class="fiche-section" style="padding:0;overflow:hidden">
    <div class="tabs-bar">
        <button type="button" class="tab-btn active" data-tab="identite">🏢 Identité</button>
        <button type="button" class="tab-btn" data-tab="financier">💰 Financier</button>
        <button type="button" class="tab-btn" data-tab="ag">📅 AG</button>
        <button type="button" class="tab-btn" data-tab="travaux">🔧 Travaux</button>
        <button type="button" class="tab-btn" data-tab="admin">📋 Administratif</button>
        <button type="button" class="tab-btn" data-tab="notes">📝 Notes</button>
    </div>

<!-- ═══ ONGLET IDENTITÉ ═══ -->
<div class="tab-panel active" data-tab="identite">
<div style="padding:18px 22px">
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Référence</label>
            <input type="text" name="reference_immeuble" value="<?= h($imm['reference_immeuble'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Nom de l'immeuble</label>
            <input type="text" name="nom_immeuble" value="<?= h($imm['nom_immeuble'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
    </div>
    <div class="fiche-row cols1">
        <div class="ff">
            <label>Adresse (recherche Google) 📍</label>
            <input type="text" id="address-search" placeholder="Tapez l'adresse..." <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
    </div>
    <div class="fiche-row cols3">
        <div class="ff">
            <label>Adresse</label>
            <input type="text" name="adresse_1" id="addr_street" value="<?= h($imm['adresse_1'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Code postal</label>
            <input type="text" name="code_postal" id="addr_zip" value="<?= h($imm['code_postal'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Ville</label>
            <input type="text" name="ville" id="addr_city" value="<?= h($imm['ville'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
    </div>
    <input type="hidden" name="latitude" id="addr_lat" value="<?= h($imm['latitude'] ?? '') ?>">
    <input type="hidden" name="longitude" id="addr_lng" value="<?= h($imm['longitude'] ?? '') ?>">
    <div class="fiche-row cols3">
        <div class="ff">
            <label>Type d'immeuble</label>
            <select name="type_immeuble" <?= !$canEdit ? 'disabled' : '' ?>>
                <?php foreach (['sdc'=>'SDC','Appartement'=>'Appartement','Maison individuelle'=>'Maison','Maison  - Jumelée'=>'Maison jumelée','Local commercial'=>'Local commercial','Garage'=>'Garage'] as $tk=>$tl): ?>
                <option value="<?= h($tk) ?>" <?= ($imm['type_immeuble'] ?? '') === $tk ? 'selected' : '' ?>><?= h($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ff">
            <label>Nombre de lots</label>
            <input type="number" name="nb_lots" value="<?= (int)($imm['nb_lots'] ?? 0) ?>" min="0" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Établissement / Agence</label>
            <select name="id_agence" <?= !$canEdit ? 'disabled' : '' ?>>
                <option value="0">— Aucun —</option>
                <?php foreach ($etablissements as $e): ?>
                <option value="<?= $e['id'] ?>" <?= (int)($imm['id_agence'] ?? 0) === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="tab-actions">
        <?php if ($canEdit): ?>
        <button type="submit" class="ph-btn primary">Enregistrer</button>
        <?php endif; ?>
    </div>
</div></div><!-- /tab identite -->

<!-- ═══ ONGLET FINANCIER ═══ -->
<div class="tab-panel" data-tab="financier">
<div style="padding:18px 22px">
    <div class="fiche-row cols3">
        <div class="ff">
            <label>Honoraires HT (annuel)</label>
            <input type="text" name="honoraires_ht" id="hono_base"
                   value="<?= h($fiche['honoraires_ht'] ?? '') ?>"
                   placeholder="0.00" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Honoraires / lot (calcul auto)</label>
            <input type="text" id="honoraires_lot_display" readonly
                   value="<?= h($fiche['honoraires_lot'] ?? '') ?>" class="ro">
            <input type="hidden" name="honoraires_lot" id="honoraires_lot">
        </div>
        <div class="ff">
            <label>Budget copropriété</label>
            <input type="text" name="budget" value="<?= h($fiche['budget'] ?? '') ?>"
                   <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
    </div>

    <div class="section-header" style="margin-top:14px">
        <div class="section-title">
            <div class="line-l"></div>
            <span class="sec-txt">Évolution honoraires 2025 → 2030</span>
            <div class="line-r"></div>
        </div>
    </div>
    <div class="hono-wrap">
        <table class="hono-table">
            <thead>
                <tr>
                    <th>Ligne</th>
                    <th>2025 (réf.)</th>
                    <th>2026</th>
                    <th>2027</th>
                    <th>2028</th>
                    <th>2029</th>
                    <th>2030</th>
                </tr>
            </thead>
            <tbody>
            <tr>
                <td>Honoraires HT</td>
                <td><input type="text" id="h25_disp" class="ro" readonly></td>
                <td><input type="text" name="hono_2026" id="h26" value="<?= h($fiche['hono_2026'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="text" name="hono_2027" id="h27" value="<?= h($fiche['hono_2027'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="text" name="hono_2028" id="h28" value="<?= h($fiche['hono_2028'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="text" name="hono_2029" id="h29" value="<?= h($fiche['hono_2029'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="text" name="hono_2030" id="h30" value="<?= h($fiche['hono_2030'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
            </tr>
            <tr>
                <td>Évolution</td>
                <td>—</td>
                <td><span class="evo-chip" id="evo_25_26">—</span></td>
                <td><span class="evo-chip" id="evo_26_27">—</span></td>
                <td><span class="evo-chip" id="evo_27_28">—</span></td>
                <td><span class="evo-chip" id="evo_28_29">—</span></td>
                <td><span class="evo-chip" id="evo_29_30">—</span></td>
            </tr>
            <tr>
                <td>Tarif / lot</td>
                <td><input type="text" id="lot25" class="ro" readonly></td>
                <td><input type="text" id="lot26" class="ro" readonly></td>
                <td><input type="text" id="lot27" class="ro" readonly></td>
                <td><input type="text" id="lot28" class="ro" readonly></td>
                <td><input type="text" id="lot29" class="ro" readonly></td>
                <td><input type="text" id="lot30" class="ro" readonly></td>
            </tr>
            <tr>
                <td>Date AG</td>
                <td><input type="date" name="ag_2025" value="<?= h($fiche['ag_2025'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="date" name="ag_2026" value="<?= h($fiche['ag_2026'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="date" name="ag_2027" value="<?= h($fiche['ag_2027'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="date" name="ag_2028" value="<?= h($fiche['ag_2028'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="date" name="ag_2029" value="<?= h($fiche['ag_2029'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                <td><input type="date" name="ag_2030" value="<?= h($fiche['ag_2030'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
            </tr>
            </tbody>
        </table>
    </div>
    <div class="tab-actions">
        <a href="agency_pdf_cr.php?id=<?= $id ?>" target="_blank" class="ph-btn">PDF Honoraires</a>
        <?php if ($canEdit): ?>
        <button type="submit" class="ph-btn primary">Enregistrer</button>
        <?php endif; ?>
    </div>
</div></div><!-- /tab financier -->

<!-- ═══ ONGLET AG ═══ -->
<div class="tab-panel" data-tab="ag">
<div style="padding:18px 22px">
    <div class="fiche-row cols3">
        <div class="ff">
            <label>Dernière AG</label>
            <input type="date" name="date_ag_derniere" value="<?= h($fiche['date_ag_derniere'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Prochaine AG</label>
            <input type="date" name="date_ag_prochaine" value="<?= h($fiche['date_ag_prochaine'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
        <div class="ff">
            <label>Arrêté de comptes</label>
            <input type="date" name="date_arrete_comptes" value="<?= h($fiche['date_arrete_comptes'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
    </div>
    <div class="tab-actions">
        <a href="agency_reunions.php?id_immeuble=<?= $id ?>" class="ph-btn">📅 Réunions</a>
        <a href="agency_reunion_tenir.php?id_immeuble=<?= $id ?>" class="ph-btn">📋 Tenir AG</a>
        <?php if ($canEdit): ?>
        <button type="submit" class="ph-btn primary">Enregistrer</button>
        <?php endif; ?>
    </div>
</div></div><!-- /tab ag -->

<!-- ═══ ONGLET TRAVAUX ═══ -->
<div class="tab-panel" data-tab="travaux">
<div style="padding:18px 22px">
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Travaux en cours</label>
            <textarea name="travaux_cours" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['travaux_cours'] ?? '') ?></textarea>
        </div>
        <div class="ff">
            <label>Travaux à prévoir</label>
            <textarea name="travaux_prevoir" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['travaux_prevoir'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Interventions en cours</label>
            <textarea name="interventions_cours" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['interventions_cours'] ?? '') ?></textarea>
        </div>
        <div class="ff">
            <label>Sinistres</label>
            <textarea name="sinistres" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['sinistres'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="tab-actions">
        <a href="agency_taches.php?id_immeuble=<?= $id ?>" class="ph-btn">✅ Tâches</a>
        <?php if ($canEdit): ?>
        <button type="submit" class="ph-btn primary">Enregistrer</button>
        <?php endif; ?>
    </div>
</div></div><!-- /tab travaux -->

<!-- ═══ ONGLET ADMINISTRATIF ═══ -->
<div class="tab-panel" data-tab="admin">
<div style="padding:18px 22px">
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Contrats à négocier / résilier</label>
            <textarea name="contrats" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['contrats'] ?? '') ?></textarea>
        </div>
        <div class="ff">
            <label>Procédures en cours</label>
            <textarea name="procedures" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['procedures'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Contacts privilégiés (copros)</label>
            <textarea name="copros" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['copros'] ?? '') ?></textarea>
        </div>
        <div class="ff">
            <label>Débiteurs</label>
            <textarea name="debiteurs" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['debiteurs'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Particularités</label>
            <textarea name="particularites" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['particularites'] ?? '') ?></textarea>
        </div>
        <div class="ff">
            <label>Mail multidiffusion</label>
            <input type="text" name="mail" value="<?= h($fiche['mail'] ?? '') ?>" placeholder="president@cs.fr, …" <?= !$canEdit ? 'readonly' : '' ?>>
        </div>
    </div>
    <div class="fiche-row cols1">
        <div class="ff">
            <label>Boîte à clés (quelles clés et où)</label>
            <textarea name="boite_cles" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['boite_cles'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="tab-actions">
        <?php if ($canEdit): ?>
        <button type="submit" class="ph-btn primary">Enregistrer</button>
        <?php endif; ?>
    </div>
</div></div><!-- /tab admin -->

<!-- ═══ ONGLET NOTES ═══ -->
<div class="tab-panel" data-tab="notes">
<div style="padding:18px 22px">
    <div class="fiche-row cols2">
        <div class="ff">
            <label>Bloc-note ICS</label>
            <textarea name="bloc_note_ics" class="auto-expand" rows="3" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['bloc_note_ics'] ?? '') ?></textarea>
        </div>
        <div class="ff">
            <label>Commentaires</label>
            <textarea name="commentaires" class="auto-expand" rows="4" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['commentaires'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="tab-actions">
        <?php if ($canEdit): ?>
        <button type="submit" class="ph-btn primary">Enregistrer</button>
        <?php endif; ?>
    </div>
</div></div><!-- /tab notes -->

</div><!-- /fiche-section onglets -->


</form>

<script>
// Auto-expand textareas
function autoResize(el) { el.style.height='auto'; el.style.height=(el.scrollHeight)+'px'; }
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('textarea.auto-expand').forEach(function(el){
        el.addEventListener('input', () => autoResize(el));
        autoResize(el);
    });
});

// Calculs honoraires / lots
document.addEventListener('DOMContentLoaded', function () {
    const nbLots = <?= (int)$imm['nb_lots'] ?>;
    const hBase  = document.getElementById('hono_base');
    const hDisp  = document.getElementById('honoraires_lot_display');
    const hHid   = document.getElementById('honoraires_lot');
    const h25d   = document.getElementById('h25_disp');
    const h26    = document.getElementById('h26');
    const h27    = document.getElementById('h27');
    const h28    = document.getElementById('h28');
    const h29    = document.getElementById('h29');
    const h30    = document.getElementById('h30');

    const b2526 = document.getElementById('evo_25_26');
    const b2627 = document.getElementById('evo_26_27');
    const b2728 = document.getElementById('evo_27_28');
    const b2829 = document.getElementById('evo_28_29');
    const b2930 = document.getElementById('evo_29_30');

    const lots = [
        document.getElementById('lot25'),
        document.getElementById('lot26'),
        document.getElementById('lot27'),
        document.getElementById('lot28'),
        document.getElementById('lot29'),
        document.getElementById('lot30'),
    ];

    function n(v) {
        if (!v) return NaN;
        const x = parseFloat(String(v.value ?? v).replace(/\s/g,'').replace(',','.'));
        return isNaN(x) ? NaN : x;
    }
    function euro(x) { return isNaN(x)?'—': x.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
    function pct(a,b) { if(isNaN(a)||isNaN(b)||b===0) return ''; const v=(a/b-1)*100; return (v>=0?'+':'')+v.toFixed(1)+' %'; }
    function setEvo(el, a, b) {
        if(!el) return;
        const va=n(a), vb=n(b);
        if(isNaN(va)||isNaN(vb)) { el.textContent='—'; el.className='evo-chip'; return; }
        const diff=vb-va;
        el.textContent=(diff>=0?'+':'')+euro(diff)+(va!==0?' ('+pct(vb,va)+')':'');
        el.className='evo-chip '+(diff>=0?'pos':'neg');
    }
    function setLot(el, val) {
        if(!el) return;
        const v=typeof val==='number'?val:n(val);
        el.value = (!isNaN(v)&&nbLots>0) ? (v/nbLots).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
    }

    function compute() {
        const v25 = n(hBase);
        if(h25d) h25d.value = hBase.value;
        if (!isNaN(v25) && nbLots > 0) {
            const perLot = (v25/nbLots).toFixed(2);
            hDisp.value = perLot;
            hHid.value  = perLot;
        } else { hDisp.value=''; hHid.value=''; }

        setEvo(b2526, hBase, h26);
        setEvo(b2627, h26, h27);
        setEvo(b2728, h27, h28);
        setEvo(b2829, h28, h29);
        setEvo(b2930, h29, h30);

        setLot(lots[0], n(hBase));
        setLot(lots[1], n(h26));
        setLot(lots[2], n(h27));
        setLot(lots[3], n(h28));
        setLot(lots[4], n(h29));
        setLot(lots[5], n(h30));
    }

    [hBase, h26, h27, h28, h29, h30].forEach(el => el && el.addEventListener('input', compute));
    compute();
});
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
