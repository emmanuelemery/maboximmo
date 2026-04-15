<?php
// agency_syndic_proposition_form.php — Création / édition d'une proposition commerciale syndic
require_once __DIR__ . '/inc/init.php';
require_login();
if (current_role_id() > 2) { header('Location: agency_dashboard.php'); exit; }

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── AJAX : charger grille tarifaire ───────────────────────────────────────
if (isset($_GET['ajax_get_tarif'])) {
    header('Content-Type: application/json');
    $tid = (int)($_GET['tarif_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false]); exit; }
    $lignes = $pdo->prepare("SELECT * FROM agency_syndic_tarif_ligne WHERE id_tarif=? AND actif=1 ORDER BY ordre");
    $lignes->execute([$tid]);
    echo json_encode(['ok'=>true,'lignes'=>$lignes->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── AJAX : envoyer email ───────────────────────────────────────────────────
if (isset($_POST['ajax_send_mail'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/inc/mailer_syndic_proposition.php';
    $pid     = (int)($_POST['prop_id'] ?? 0);
    $emails  = array_filter(array_map('trim', explode(',', $_POST['emails_dest'] ?? '')));
    $cc      = trim($_POST['email_cc'] ?? '');
    $msg     = trim($_POST['message'] ?? '');
    $result  = sendSyndicPropositionMail($pdo, $pid, $emails, $cc, $msg, $user_id);
    echo json_encode($result);
    exit;
}

// ── Générer la référence ───────────────────────────────────────────────────
function nextPropReference(PDO $pdo, int $etab): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM agency_syndic_proposition WHERE YEAR(created_at)=?" . ($etab?" AND id_etablissement=$etab":""));
    $stmt->execute([$year]);
    $n = (int)$stmt->fetchColumn() + 1;
    return 'PROP-' . $year . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ── Charger les établissements ─────────────────────────────────────────────
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// ── Charger les grilles tarifaires ────────────────────────────────────────
$tarifs = $pdo->query("SELECT id, nom, niveau, id_etablissement FROM agency_syndic_tarif WHERE actif=1 ORDER BY niveau, nom")->fetchAll(PDO::FETCH_ASSOC);

// ── Charger les immeubles ─────────────────────────────────────────────────
$immeubles = $pdo->query("SELECT id, nom, adresse, ville, code_postal, nb_lots FROM immeubles ORDER BY nom LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

// ── Mode dupliquer ────────────────────────────────────────────────────────
$dupId = (int)($_GET['dupliquer'] ?? 0);

// ── Chargement d'une proposition existante ────────────────────────────────
$editId = (int)($_GET['id'] ?? 0);
$prop   = null;
$lignes = [];
$isEdit = false;

if ($editId || $dupId) {
    $loadId = $editId ?: $dupId;
    $stmt = $pdo->prepare("SELECT * FROM agency_syndic_proposition WHERE id=?");
    $stmt->execute([$loadId]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prop) {
        $isEdit = (bool)$editId;
        $lstmt = $pdo->prepare("SELECT * FROM agency_syndic_proposition_ligne WHERE id_proposition=? ORDER BY ordre");
        $lstmt->execute([$loadId]);
        $lignes = $lstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Sauvegarde ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_proposition'])) {
    $pid       = (int)($_POST['prop_id'] ?? 0);
    $ref       = trim($_POST['reference'] ?? '');
    $tarif_id  = (int)($_POST['id_tarif_base'] ?? 0) ?: null;
    $etabP     = (int)($_POST['id_etablissement'] ?? 0) ?: null;

    // Prospect
    $pros_nom  = trim($_POST['prospect_nom'] ?? '');
    $pros_fnc  = trim($_POST['prospect_fonction'] ?? '');
    $pros_email= trim($_POST['prospect_email'] ?? '');
    $pros_tel  = trim($_POST['prospect_tel'] ?? '');
    $pros_addr = trim($_POST['prospect_adresse'] ?? '');

    // Immeuble
    $imm_id    = (int)($_POST['id_immeuble'] ?? 0) ?: null;
    $imm_nom   = trim($_POST['immeuble_nom'] ?? '');
    $imm_addr  = trim($_POST['immeuble_adresse'] ?? '');
    $imm_ville = trim($_POST['immeuble_ville'] ?? '');
    $imm_cp    = trim($_POST['immeuble_code_postal'] ?? '');
    $imm_lots  = (int)($_POST['immeuble_nb_lots'] ?? 0) ?: null;
    $imm_lann  = (int)($_POST['immeuble_nb_lots_annexes'] ?? 0) ?: null;
    $imm_annee = (int)($_POST['immeuble_annee'] ?? 0) ?: null;
    $imm_type  = in_array($_POST['immeuble_type']??'',['collectif','mixte','commercial','autre']) ? $_POST['immeuble_type'] : 'collectif';

    // Tarification
    $hon_base  = (float)str_replace(',','.',$_POST['honoraires_base_ht'] ?? '0');
    $hon_lot   = isset($_POST['honoraires_par_lot']) ? 1 : 0;
    $tva       = (float)str_replace(',','.',$_POST['tva_pct'] ?? '20');

    // Proposition
    $statut    = in_array($_POST['statut']??'',['brouillon','envoyee','relancee','acceptee','refusee','expiree']) ? $_POST['statut'] : 'brouillon';
    $date_prop = $_POST['date_proposition'] ?: null;
    $date_val  = $_POST['date_validite'] ?: null;
    $msg_intro = trim($_POST['message_intro'] ?? '');
    $cond_part = trim($_POST['conditions_particulieres'] ?? '');
    $notes_int = trim($_POST['notes_internes'] ?? '');

    if (!$ref) $ref = nextPropReference($pdo, $etabP ?? $etab_id);
    if (!$imm_nom) { $errors[] = 'Le nom de l\'immeuble est obligatoire'; }
    if (!$pros_nom) { $errors[] = 'Le nom du prospect est obligatoire'; }

    if (empty($errors)) {
        $fields = [
            $ref, $tarif_id, $pros_nom, $pros_fnc, $pros_email, $pros_tel, $pros_addr,
            $imm_id, $imm_nom, $imm_addr, $imm_ville, $imm_cp, $imm_lots, $imm_lann,
            $imm_annee, $imm_type, $hon_base, $hon_lot, $tva, $statut, $date_prop, $date_val,
            $msg_intro, $cond_part, $notes_int, $etabP
        ];

        if ($pid) {
            $pdo->prepare("UPDATE agency_syndic_proposition SET
                reference=?,id_tarif_base=?,prospect_nom=?,prospect_fonction=?,prospect_email=?,prospect_tel=?,prospect_adresse=?,
                id_immeuble=?,immeuble_nom=?,immeuble_adresse=?,immeuble_ville=?,immeuble_code_postal=?,immeuble_nb_lots=?,immeuble_nb_lots_annexes=?,
                immeuble_annee=?,immeuble_type=?,honoraires_base_ht=?,honoraires_par_lot=?,tva_pct=?,statut=?,date_proposition=?,date_validite=?,
                message_intro=?,conditions_particulieres=?,notes_internes=?,id_etablissement=?
                WHERE id=?")->execute(array_merge($fields, [$pid]));
        } else {
            $pdo->prepare("INSERT INTO agency_syndic_proposition
                (reference,id_tarif_base,prospect_nom,prospect_fonction,prospect_email,prospect_tel,prospect_adresse,
                id_immeuble,immeuble_nom,immeuble_adresse,immeuble_ville,immeuble_code_postal,immeuble_nb_lots,immeuble_nb_lots_annexes,
                immeuble_annee,immeuble_type,honoraires_base_ht,honoraires_par_lot,tva_pct,statut,date_proposition,date_validite,
                message_intro,conditions_particulieres,notes_internes,id_etablissement,id_createur)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($fields, [$user_id]));
            $pid = (int)$pdo->lastInsertId();
        }

        // Sauvegarder les lignes
        $lignesJson = json_decode($_POST['lignes_json'] ?? '[]', true) ?: [];
        $pdo->prepare("DELETE FROM agency_syndic_proposition_ligne WHERE id_proposition=?")->execute([$pid]);
        $ord = 0;
        foreach ($lignesJson as $l) {
            $pdo->prepare("INSERT INTO agency_syndic_proposition_ligne (id_proposition,ordre,categorie,code,designation,description,unite,prix_ht,tva_pct,inclus_forfait) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $pid, $ord++,
                    in_array($l['categorie']??'',['forfait_base','prestation_particuliere','remise']) ? $l['categorie'] : 'prestation_particuliere',
                    strtoupper(substr(preg_replace('/[^A-Z0-9]/i','',$l['code']??''),0,30)) ?: 'LIG'.($ord),
                    $l['designation'] ?? '',
                    $l['description'] ?? null,
                    $l['unite'] ?? 'forfait',
                    (float)str_replace(',','.',$l['prix_ht']??'0'),
                    (float)str_replace(',','.',$l['tva_pct']??'20'),
                    isset($l['inclus_forfait']) ? 1 : 0,
                ]);
        }

        header('Location: agency_syndic_propositions.php?saved=1');
        exit;
    }
}

// ── Valeurs par défaut ────────────────────────────────────────────────────
$defRef  = $prop ? $prop['reference'] : nextPropReference($pdo, $etab_id);
$defDate = date('Y-m-d');
$defVal  = date('Y-m-d', strtotime('+30 days'));

// ── Layout config ──────────────────────────────────────────────────────────
$layout_title   = $isEdit ? 'Modifier la proposition' : ($dupId ? 'Dupliquer la proposition' : 'Nouvelle proposition');
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_actions = '
<button type="submit" form="propForm" onclick="prepareSave()" class="ph-btn primary">
  <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Enregistrer
</button>
<a href="agency_syndic_propositions.php" class="ph-btn">Retour</a>
'
. ($isEdit
    ? '<a href="agency_pdf_syndic_proposition.php?id='.$editId.'" target="_blank" class="ph-btn">PDF</a>'
    : '<a class="ph-btn dispo">dispo</a>')
. '<a class="ph-btn dispo">dispo</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
/* Tabs */
.tabs-row { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; }
.tab-btn {
    padding:9px 22px; border-radius:999px; border:none; cursor:pointer;
    background:var(--bg-primary,#e4e8f0); box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; color:#8a8680;
    font-family:inherit; font-size:13px; font-weight:600; transition:all .15s;
    display:flex; align-items:center; gap:6px;
}
.tab-btn.active { box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff; color:#4878a6; font-weight:700; }
.tab-btn.done { color:#3a7a6a; }
.tab-panel { display:none; flex-direction:column; gap:18px; }
.tab-panel.active { display:flex; }

/* Cards */
.card { background:var(--bg-primary,#e4e8f0); border-radius:20px; box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; padding:24px; }
.card-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#8a8680; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

/* Form */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-grid.three { grid-template-columns:1fr 1fr 1fr; }
.form-grid.full { grid-template-columns:1fr; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group.span2 { grid-column:span 2; }
.form-group.span3 { grid-column:span 3; }
label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#8a8680; }
input[type=text], input[type=email], input[type=tel], input[type=number], input[type=date],
select, textarea {
    padding:10px 14px; border:none; border-radius:10px;
    background:var(--bg-primary,#e4e8f0); box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;
    color:#1a1816; font-family:inherit; font-size:13px; outline:none;
    width:100%;
}
textarea { resize:vertical; min-height:80px; }
select option { background:var(--bg-primary,#e4e8f0); }

/* Lignes tarifaires */
.lignes-table { width:100%; border-collapse:collapse; font-size:13px; }
.lignes-table th {
    padding:8px 10px; text-align:left; font-size:11px; font-weight:700;
    text-transform:uppercase; letter-spacing:.4px; color:#4a6038;
    border-bottom:1px solid rgba(0,0,0,.08);
}
.lignes-table td { padding:6px 8px; border-bottom:1px solid rgba(0,0,0,.04); vertical-align:middle; }
.lignes-table td input, .lignes-table td select {
    padding:6px 10px; font-size:12px; min-width:0;
}
.lignes-table tr:hover td { background:rgba(72,120,166,.04); }
.add-ligne-btn {
    padding:7px 16px; border-radius:999px; border:none; cursor:pointer;
    background:var(--bg-primary,#e4e8f0); box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; color:#4878a6;
    font-family:inherit; font-size:12px; font-weight:600; margin-top:10px;
    display:inline-flex; align-items:center; gap:6px; transition:all .15s;
}
.add-ligne-btn:hover { box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff; }
.del-ligne-btn {
    width:26px; height:26px; border-radius:6px; border:none; cursor:pointer;
    background:transparent; color:#8a8680; font-size:13px; transition:all .15s;
    display:flex; align-items:center; justify-content:center;
}
.del-ligne-btn:hover { color:#8a5040; background:rgba(138,80,64,.1); }

/* Sub-tabs for ligne categories */
.sub-tabs { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
.sub-tab-btn {
    padding:5px 14px; border-radius:999px; border:none; cursor:pointer;
    background:var(--bg-primary,#e4e8f0); box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; color:#8a8680;
    font-family:inherit; font-size:12px; font-weight:600; transition:all .15s;
}
.sub-tab-btn.active { box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff; color:#4878a6; }
.sub-tab-panel { display:none; }
.sub-tab-panel.active { display:block; }

/* Recap panel */
.recap-box { background:var(--bg-primary,#e4e8f0); border-radius:14px; box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff; padding:20px; }
.recap-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(0,0,0,.06); font-size:13px; }
.recap-row.total { font-size:16px; font-weight:800; color:#4878a6; border-bottom:none; padding-top:12px; }
.recap-row.total .val { font-size:20px; }

/* Email panel */
.email-panel { background:var(--bg-primary,#e4e8f0); border-radius:20px; box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; padding:24px; margin-top:24px; }

/* Navigation buttons */
.btn-row { display:flex; gap:12px; align-items:center; margin-top:20px; flex-wrap:wrap; }
.btn-primary {
    padding:11px 24px; border-radius:999px; border:none; cursor:pointer;
    background:#4878a6; color:#fff; font-size:13px; font-weight:700;
    font-family:inherit; display:inline-flex; align-items:center; gap:6px; transition:opacity .15s;
}
.btn-primary:hover { opacity:.88; }
.btn-secondary {
    padding:11px 24px; border-radius:999px; border:none; cursor:pointer;
    background:var(--bg-primary,#e4e8f0); box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; color:#1a1816;
    font-size:13px; font-weight:600; font-family:inherit; transition:all .15s;
}
.btn-secondary:hover { box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff; color:#4878a6; }
.btn-green {
    padding:11px 24px; border-radius:999px; border:none; cursor:pointer;
    background:#3a7a6a; color:#fff; font-size:13px; font-weight:700;
    font-family:inherit; display:inline-flex; align-items:center; gap:6px; transition:opacity .15s;
}
.btn-green:hover { opacity:.88; }

/* Alert */
.alert { padding:12px 18px; border-radius:10px; font-size:13px; margin-bottom:16px; }
.alert.error { background:#fee2e2; color:#8a5040; }
.alert.success { background:#dcfce7; color:#3a7a6a; }

/* Immeuble autocomplete */
.imm-suggestions {
    position:absolute; z-index:100; background:var(--bg-primary,#e4e8f0); border-radius:12px;
    box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; overflow:hidden; max-height:200px; overflow-y:auto;
    width:100%;
}
.imm-sugg-item { padding:10px 14px; cursor:pointer; font-size:13px; transition:background .1s; }
.imm-sugg-item:hover { background:rgba(72,120,166,.12); }
.pos-rel { position:relative; }

/* Toast */
.toast {
    position:fixed; bottom:24px; right:24px; z-index:999;
    padding:12px 20px; border-radius:12px; font-size:13px; font-weight:600;
    background:#ffffff; color:#fff; box-shadow:0 4px 24px #f7f8fa;
    transform:translateY(80px); opacity:0; transition:all .3s;
}
.toast.show { transform:translateY(0); opacity:1; }
.toast.ok { background:#3a7a6a; }
.toast.err { background:#8a5040; }

@media (max-width:700px) {
    .form-grid, .form-grid.three { grid-template-columns:1fr; }
    .form-group.span2, .form-group.span3 { grid-column:1; }
}
</style>
EXTRACSS;

ob_start();
?>

    <?php if (!empty($errors)): ?>
    <div class="alert error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <form method="post" id="propForm">
        <input type="hidden" name="save_proposition" value="1">
        <input type="hidden" name="prop_id" value="<?= $isEdit ? $editId : 0 ?>">
        <input type="hidden" name="lignes_json" id="lignesJson" value="">

        <!-- Onglets -->
        <div class="tabs-row">
            <button type="button" class="tab-btn active" id="tab1" onclick="switchTab(1)">
                <span>①</span> Prospect & Immeuble
            </button>
            <button type="button" class="tab-btn" id="tab2" onclick="switchTab(2)">
                <span>②</span> Tarification
            </button>
            <button type="button" class="tab-btn" id="tab3" onclick="switchTab(3)">
                <span>③</span> Récap & Envoi
            </button>
        </div>

        <!-- ══ Panel 1 : Prospect + Immeuble ══ -->
        <div class="tab-panel active" id="panel1">

            <!-- Infos générales -->
            <div class="card">
                <div class="card-title">📋 Référence & Configuration</div>
                <div class="form-grid three">
                    <div class="form-group">
                        <label>Référence</label>
                        <input type="text" name="reference" value="<?= htmlspecialchars($prop ? $prop['reference'] : ($dupId ? nextPropReference($pdo,$etab_id) : $defRef)) ?>">
                    </div>
                    <div class="form-group">
                        <label>Date de proposition</label>
                        <input type="date" name="date_proposition" value="<?= htmlspecialchars($prop ? ($prop['date_proposition']??$defDate) : $defDate) ?>">
                    </div>
                    <div class="form-group">
                        <label>Valable jusqu'au</label>
                        <input type="date" name="date_validite" value="<?= htmlspecialchars($prop ? ($prop['date_validite']??$defVal) : $defVal) ?>">
                    </div>
                    <div class="form-group">
                        <label>Agence</label>
                        <select name="id_etablissement" id="etabSelect" onchange="filterTarifs()">
                            <option value="">— Toute la société —</option>
                            <?php foreach ($etablissements as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= ($prop?$prop['id_etablissement']:$etab_id)==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Statut</label>
                        <select name="statut">
                            <?php foreach (['brouillon'=>'Brouillon','envoyee'=>'Envoyée','relancee'=>'Relancée','acceptee'=>'Acceptée','refusee'=>'Refusée','expiree'=>'Expirée'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($prop?$prop['statut']:'brouillon')===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Grille tarifaire de base</label>
                        <select name="id_tarif_base" id="tarifSelect" onchange="loadTarifLignes(this.value)">
                            <option value="">— Saisie manuelle —</option>
                            <?php foreach ($tarifs as $t): ?>
                            <option value="<?= $t['id'] ?>" data-niveau="<?= $t['niveau'] ?>" data-etab="<?= $t['id_etablissement']??'' ?>"
                                <?= ($prop?$prop['id_tarif_base']:0)==$t['id']?'selected':'' ?>>
                                <?= htmlspecialchars('['.strtoupper($t['niveau']).'] '.$t['nom']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Prospect -->
            <div class="card">
                <div class="card-title">👤 Prospect / Destinataire</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nom du contact *</label>
                        <input type="text" name="prospect_nom" placeholder="M. Dupont / Syndic Bénévole / Conseil syndical" required
                               value="<?= htmlspecialchars($prop?$prop['prospect_nom']:'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Fonction / Qualité</label>
                        <input type="text" name="prospect_fonction" placeholder="Président du CS, représentant..."
                               value="<?= htmlspecialchars($prop?$prop['prospect_fonction']??'':'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="prospect_email" id="prospectEmail" placeholder="contact@exemple.fr"
                               value="<?= htmlspecialchars($prop?$prop['prospect_email']??'':'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Téléphone</label>
                        <input type="tel" name="prospect_tel" value="<?= htmlspecialchars($prop?$prop['prospect_tel']??'':'') ?>">
                    </div>
                    <div class="form-group span2">
                        <label>Adresse du contact</label>
                        <input type="text" name="prospect_adresse" value="<?= htmlspecialchars($prop?$prop['prospect_adresse']??'':'') ?>">
                    </div>
                </div>
            </div>

            <!-- Immeuble -->
            <div class="card">
                <div class="card-title">🏢 Immeuble concerné</div>
                <div class="form-group" style="margin-bottom:14px">
                    <label>Immeuble existant (optionnel)</label>
                    <div class="pos-rel">
                        <input type="text" id="immSearch" placeholder="Rechercher dans vos immeubles..." autocomplete="off" oninput="searchImm(this.value)">
                        <div class="imm-suggestions" id="immSugg" style="display:none"></div>
                    </div>
                    <input type="hidden" name="id_immeuble" id="immId" value="<?= $prop?($prop['id_immeuble']??''):'' ?>">
                </div>
                <div class="form-grid">
                    <div class="form-group span2">
                        <label>Nom / Adresse de la copropriété *</label>
                        <input type="text" name="immeuble_nom" id="immNom" required placeholder="Résidence Les Tilleuls"
                               value="<?= htmlspecialchars($prop?$prop['immeuble_nom']:'') ?>">
                    </div>
                    <div class="form-group span2">
                        <label>Adresse</label>
                        <input type="text" name="immeuble_adresse" id="immAddr" value="<?= htmlspecialchars($prop?$prop['immeuble_adresse']??'':'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Code postal</label>
                        <input type="text" name="immeuble_code_postal" id="immCp" value="<?= htmlspecialchars($prop?$prop['immeuble_code_postal']??'':'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Ville</label>
                        <input type="text" name="immeuble_ville" id="immVille" value="<?= htmlspecialchars($prop?$prop['immeuble_ville']??'':'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Nb lots principaux</label>
                        <input type="number" name="immeuble_nb_lots" id="immLots" min="1" value="<?= $prop?($prop['immeuble_nb_lots']??''):'' ?>">
                    </div>
                    <div class="form-group">
                        <label>Nb lots annexes</label>
                        <input type="number" name="immeuble_nb_lots_annexes" value="<?= $prop?($prop['immeuble_nb_lots_annexes']??''):'' ?>">
                    </div>
                    <div class="form-group">
                        <label>Année de construction</label>
                        <input type="number" name="immeuble_annee" min="1800" max="2030" value="<?= $prop?($prop['immeuble_annee']??''):'' ?>">
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="immeuble_type">
                            <?php foreach (['collectif'=>'Collectif','mixte'=>'Mixte','commercial'=>'Commercial','autre'=>'Autre'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($prop?$prop['immeuble_type']:'collectif')===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Message intro -->
            <div class="card">
                <div class="card-title">✉️ Message d'introduction (optionnel)</div>
                <div class="form-group">
                    <label>Accroche personnalisée (début du document)</label>
                    <textarea name="message_intro" rows="4" placeholder="Suite à notre rencontre du... nous avons le plaisir de..."><?= htmlspecialchars($prop?$prop['message_intro']??'':'') ?></textarea>
                </div>
            </div>

            <div class="btn-row">
                <button type="button" class="btn-primary" onclick="switchTab(2)">
                    Suivant : Tarification →
                </button>
            </div>
        </div>

        <!-- ══ Panel 2 : Tarification ══ -->
        <div class="tab-panel" id="panel2">

            <div class="card">
                <div class="card-title">💰 Honoraires & Lignes tarifaires</div>

                <!-- Récap honoraires globaux -->
                <div class="form-grid" style="margin-bottom:20px">
                    <div class="form-group">
                        <label>Forfait annuel HT (calculé automatiquement)</label>
                        <input type="number" name="honoraires_base_ht" id="honorairesHT" step="0.01" value="<?= $prop?$prop['honoraires_base_ht']:'0.00' ?>"
                               placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>TVA (%)</label>
                        <input type="number" name="tva_pct" id="tvaPct" step="0.01" value="<?= $prop?$prop['tva_pct']:'20.00' ?>">
                    </div>
                    <div class="form-group" style="align-self:end">
                        <label style="opacity:0">.</label>
                        <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--bg);border-radius:10px;box-shadow:var(--shadow-in);cursor:pointer">
                            <input type="checkbox" name="honoraires_par_lot" <?= $prop&&$prop['honoraires_par_lot']?'checked':'' ?> onchange="updateRecap()">
                            <span style="font-size:13px;text-transform:none;letter-spacing:0">Calcul par lot</span>
                        </label>
                    </div>
                </div>

                <!-- Sub-tabs lignes -->
                <div class="sub-tabs">
                    <button type="button" class="sub-tab-btn active" id="stab1" onclick="switchSubTab(1)">
                        Forfait de base
                    </button>
                    <button type="button" class="sub-tab-btn" id="stab2" onclick="switchSubTab(2)">
                        Prestations particulières (ALUR)
                    </button>
                    <button type="button" class="sub-tab-btn" id="stab3" onclick="switchSubTab(3)">
                        Remises
                    </button>
                </div>

                <!-- Forfait de base -->
                <div class="sub-tab-panel active" id="spanel1">
                    <table class="lignes-table" id="tableForfait">
                        <thead><tr>
                            <th style="width:35%">Désignation</th>
                            <th style="width:18%">Unité</th>
                            <th style="width:13%">Prix HT</th>
                            <th style="width:8%">TVA%</th>
                            <th style="width:10%">Inclus forfait</th>
                            <th style="width:8%"></th>
                        </tr></thead>
                        <tbody id="bodyForfait"></tbody>
                    </table>
                    <button type="button" class="add-ligne-btn" onclick="addLigne('forfait_base')">
                        ＋ Ajouter une ligne
                    </button>
                </div>

                <!-- Prestations particulières -->
                <div class="sub-tab-panel" id="spanel2">
                    <div style="font-size:11px;color:#f59e0b;margin-bottom:10px;padding:8px 12px;background:rgba(245,158,11,.08);border-radius:8px;">
                        ⚠️ Conformément à la loi ALUR (décret n°2015-342 du 26 mars 2015), les prestations particulières doivent être listées séparément du forfait de gestion courante.
                    </div>
                    <table class="lignes-table" id="tableParticu">
                        <thead><tr>
                            <th style="width:35%">Désignation</th>
                            <th style="width:18%">Unité</th>
                            <th style="width:13%">Prix HT</th>
                            <th style="width:8%">TVA%</th>
                            <th style="width:10%">Obligatoire ALUR</th>
                            <th style="width:8%"></th>
                        </tr></thead>
                        <tbody id="bodyParticu"></tbody>
                    </table>
                    <button type="button" class="add-ligne-btn" onclick="addLigne('prestation_particuliere')">
                        ＋ Ajouter une prestation
                    </button>
                    <button type="button" class="add-ligne-btn" onclick="loadALURPresets()" style="color:#f59e0b">
                        ⚡ Charger les prestations ALUR types
                    </button>
                </div>

                <!-- Remises -->
                <div class="sub-tab-panel" id="spanel3">
                    <table class="lignes-table" id="tableRemise">
                        <thead><tr>
                            <th style="width:45%">Motif de la remise</th>
                            <th style="width:20%">Unité</th>
                            <th style="width:15%">Montant HT (négatif)</th>
                            <th style="width:12%">TVA%</th>
                            <th style="width:8%"></th>
                        </tr></thead>
                        <tbody id="bodyRemise"></tbody>
                    </table>
                    <button type="button" class="add-ligne-btn" onclick="addLigne('remise')">
                        ＋ Ajouter une remise
                    </button>
                </div>

            </div>

            <!-- Conditions -->
            <div class="card">
                <div class="card-title">📄 Conditions particulières</div>
                <div class="form-group">
                    <textarea name="conditions_particulieres" rows="4" placeholder="Conditions spécifiques à mentionner dans la proposition..."><?= htmlspecialchars($prop?$prop['conditions_particulieres']??'':'') ?></textarea>
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label>Notes internes (non visibles dans le PDF)</label>
                    <textarea name="notes_internes" rows="3" placeholder="Notes de suivi commercial..."><?= htmlspecialchars($prop?$prop['notes_internes']??'':'') ?></textarea>
                </div>
            </div>

            <div class="btn-row">
                <button type="button" class="btn-secondary" onclick="switchTab(1)">← Retour</button>
                <button type="button" class="btn-primary" onclick="switchTab(3)">
                    Suivant : Récapitulatif →
                </button>
            </div>
        </div>

        <!-- ══ Panel 3 : Récap + Envoi ══ -->
        <div class="tab-panel" id="panel3">

            <div class="card">
                <div class="card-title">📊 Récapitulatif financier</div>
                <div class="recap-box" id="recapBox">
                    <div class="recap-row"><span>Forfait de base HT</span><span id="recapForfait">0,00 €</span></div>
                    <div class="recap-row"><span>Prestations particulières HT</span><span id="recapPart">0,00 €</span></div>
                    <div class="recap-row"><span>Remises HT</span><span id="recapRemise" style="color:#16a34a">0,00 €</span></div>
                    <div class="recap-row"><span>Total HT</span><span id="recapHT" style="font-weight:700">0,00 €</span></div>
                    <div class="recap-row"><span id="recapTvaLabel">TVA (20%)</span><span id="recapTVA">0,00 €</span></div>
                    <div class="recap-row total"><span>Total TTC / an</span><span class="val" id="recapTTC">0,00 €</span></div>
                    <div class="recap-row" id="recapLotRow" style="display:none">
                        <span>Soit par lot et par an</span><span id="recapParLot" style="font-weight:600;color:var(--accent)">—</span>
                    </div>
                </div>
            </div>

            <!-- Envoyer -->
            <div class="email-panel">
                <div class="card-title">📧 Envoyer la proposition par email</div>
                <?php if ($isEdit): ?>
                <div style="font-size:12px;color:var(--muted);margin-bottom:14px">
                    La proposition doit être sauvegardée avant l'envoi. Vous pouvez enregistrer et envoyer en une seule action.
                </div>
                <div class="form-grid">
                    <div class="form-group span2">
                        <label>Destinataires (emails séparés par virgule) *</label>
                        <input type="text" id="emailsDest" placeholder="president@exemple.fr, secretaire@exemple.fr"
                               value="<?= htmlspecialchars($prop?$prop['prospect_email']??'':'') ?>">
                    </div>
                    <div class="form-group">
                        <label>Email CC (optionnel)</label>
                        <input type="email" id="emailCc">
                    </div>
                    <div class="form-group">
                        <label>Statut après envoi</label>
                        <input type="text" value="Automatique (brouillon→envoyée→relancée)" disabled style="opacity:.6">
                    </div>
                    <div class="form-group span2">
                        <label>Message accompagnateur</label>
                        <textarea id="emailMsg" rows="4" placeholder="Madame, Monsieur, Suite à notre échange, veuillez trouver ci-joint notre proposition..."></textarea>
                    </div>
                </div>
                <div class="btn-row">
                    <button type="button" class="btn-green" onclick="sendMail()">
                        ✉️ Envoyer la proposition
                    </button>
                    <a href="agency_pdf_syndic_proposition.php?id=<?= $editId ?>" target="_blank" class="btn-secondary">
                        📄 Prévisualiser PDF
                    </a>
                </div>
                <?php else: ?>
                <div style="font-size:13px;color:var(--muted)">
                    Sauvegardez d'abord la proposition, puis vous pourrez l'envoyer par email.
                </div>
                <?php endif; ?>
            </div>

            <div class="btn-row">
                <button type="button" class="btn-secondary" onclick="switchTab(2)">← Retour</button>
                <button type="submit" class="btn-primary" onclick="prepareSave()">
                    💾 Enregistrer la proposition
                </button>
                <?php if ($isEdit): ?>
                <a href="agency_pdf_syndic_proposition.php?id=<?= $editId ?>" target="_blank" class="btn-secondary">
                    📄 PDF
                </a>
                <?php endif; ?>
            </div>
        </div>

    </form>

<div class="toast" id="toast"></div>

<?php
$layout_content = ob_get_clean();

$_init_lignes_json = json_encode($lignes);
$_immeubles_json   = json_encode($immeubles);
$_edit_id_js       = (int)$editId;

$layout_extra_js = <<<'EXTRAJS'
<script>
const INIT_LIGNES = __INIT_LIGNES__;
const IMMEUBLES   = __IMMEUBLES__;

// Lignes en mémoire
let lignes = { forfait_base:[], prestation_particuliere:[], remise:[] };

// ── Initialisation ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Charger lignes existantes
    INIT_LIGNES.forEach(l => {
        if (lignes[l.categorie] !== undefined) {
            lignes[l.categorie].push(l);
        }
    });
    renderAll();
    updateRecap();
});

// ── Onglets principaux ────────────────────────────────────────────────────
function switchTab(n) {
    [1,2,3].forEach(i => {
        document.getElementById('panel'+i).classList.toggle('active', i===n);
        document.getElementById('tab'+i).classList.toggle('active', i===n);
        document.getElementById('tab'+i).classList.toggle('done', i<n);
    });
    if (n===3) updateRecap();
}

// ── Sous-onglets lignes ───────────────────────────────────────────────────
function switchSubTab(n) {
    [1,2,3].forEach(i => {
        document.getElementById('spanel'+i).classList.toggle('active', i===n);
        document.getElementById('stab'+i).classList.toggle('active', i===n);
    });
}

// ── Rendu des tables ──────────────────────────────────────────────────────
function renderAll() {
    renderTable('bodyForfait',  lignes.forfait_base,           'forfait_base');
    renderTable('bodyParticu',  lignes.prestation_particuliere,'prestation_particuliere');
    renderTable('bodyRemise',   lignes.remise,                 'remise');
}

function renderTable(tbodyId, arr, cat) {
    const tbody = document.getElementById(tbodyId);
    tbody.innerHTML = '';
    arr.forEach((l, idx) => {
        tbody.insertAdjacentHTML('beforeend', buildRow(l, cat, idx));
    });
}

function buildRow(l, cat, idx) {
    const unites = ['annuel','par_lot','par_acte','par_heure','forfait','pourcentage'];
    const uniteOpts = unites.map(u =>
        `<option value="${u}" ${(l.unite||'forfait')===u?'selected':''}>${u}</option>`
    ).join('');

    const inclus = cat==='forfait_base'
        ? `<input type="checkbox" onchange="syncLigne('${cat}',${idx},'inclus_forfait',this.checked?1:0)" ${l.inclus_forfait?'checked':''}>`
        : (cat==='prestation_particuliere'
            ? `<input type="checkbox" onchange="syncLigne('${cat}',${idx},'obligatoire',this.checked?1:0)" title="ALUR obligatoire" ${l.obligatoire?'checked':''}>`
            : '—');

    return `<tr data-cat="${cat}" data-idx="${idx}">
        <td><input type="text" value="${esc(l.designation||'')}" oninput="syncLigne('${cat}',${idx},'designation',this.value)" placeholder="Désignation"></td>
        <td><select onchange="syncLigne('${cat}',${idx},'unite',this.value)">${uniteOpts}</select></td>
        <td><input type="number" step="0.01" value="${parseFloat(l.prix_ht||0).toFixed(2)}" oninput="syncLigne('${cat}',${idx},'prix_ht',this.value); updateRecap()"></td>
        <td><input type="number" step="0.01" value="${parseFloat(l.tva_pct||20).toFixed(2)}" oninput="syncLigne('${cat}',${idx},'tva_pct',this.value)"></td>
        <td style="text-align:center">${inclus}</td>
        <td><button type="button" class="del-ligne-btn" onclick="delLigne('${cat}',${idx})" title="Supprimer">✕</button></td>
    </tr>`;
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

// ── CRUD lignes ───────────────────────────────────────────────────────────
function addLigne(cat) {
    lignes[cat].push({ categorie:cat, code:'', designation:'', description:'', unite:'forfait', prix_ht:0, tva_pct:20, inclus_forfait:0, obligatoire:0 });
    renderAll();
    updateRecap();
}

function delLigne(cat, idx) {
    lignes[cat].splice(idx, 1);
    renderAll();
    updateRecap();
}

function syncLigne(cat, idx, key, val) {
    if (lignes[cat] && lignes[cat][idx] !== undefined) {
        lignes[cat][idx][key] = val;
        if (key === 'prix_ht') updateRecap();
    }
}

// ── Charger grille depuis tarif ───────────────────────────────────────────
function loadTarifLignes(tid) {
    if (!tid) return;
    if (!confirm('Remplacer les lignes actuelles par celles de la grille sélectionnée ?')) return;
    fetch('?ajax_get_tarif=1&tarif_id=' + tid)
        .then(r=>r.json()).then(d=>{
            if (!d.ok) { showToast('Erreur chargement grille', 'err'); return; }
            lignes = { forfait_base:[], prestation_particuliere:[], remise:[] };
            d.lignes.forEach(l => {
                if (lignes[l.categorie] !== undefined) lignes[l.categorie].push(l);
            });
            renderAll();
            updateRecap();
            showToast('Grille tarifaire chargée', 'ok');
        });
}

// ── Presets ALUR ──────────────────────────────────────────────────────────
function loadALURPresets() {
    const presets = [
        { code:'ETAT_DATE',    designation:'État daté',              unite:'par_acte', prix_ht:380, tva_pct:20, obligatoire:1 },
        { code:'MUTATION',     designation:'Frais de mutation',      unite:'par_acte', prix_ht:150, tva_pct:20, obligatoire:1 },
        { code:'AG_SUPP',      designation:'Assemblée générale supplémentaire', unite:'par_acte', prix_ht:280, tva_pct:20, obligatoire:1 },
        { code:'COPIE_DOC',    designation:'Copie de documents',     unite:'par_acte', prix_ht:30,  tva_pct:20, obligatoire:1 },
        { code:'DGD',          designation:'Décompte de charges pour départ', unite:'par_acte', prix_ht:60, tva_pct:20, obligatoire:1 },
        { code:'MISEENDEMEURE',designation:'Mise en demeure',        unite:'par_acte', prix_ht:45,  tva_pct:20, obligatoire:1 },
        { code:'HUISSIER',     designation:'Frais d\'huissier hors syndic', unite:'par_acte', prix_ht:0, tva_pct:20, obligatoire:1 },
        { code:'SINISTRE',     designation:'Gestion sinistre',       unite:'par_acte', prix_ht:250, tva_pct:20, obligatoire:1 },
        { code:'ARCHIVE',      designation:'Archivage/déménagement des archives', unite:'forfait', prix_ht:300, tva_pct:20, obligatoire:1 },
        { code:'DIAG_TECH',    designation:'Diagnostic technique',   unite:'par_acte', prix_ht:0,   tva_pct:20, obligatoire:0 },
        { code:'VISIO_AG',     designation:'AG en visioconférence',  unite:'par_acte', prix_ht:80,  tva_pct:20, obligatoire:0 },
    ];
    presets.forEach(p => {
        p.categorie = 'prestation_particuliere';
        p.inclus_forfait = 0;
        p.description = '';
        lignes.prestation_particuliere.push(p);
    });
    renderAll();
    updateRecap();
    showToast('Prestations ALUR chargées', 'ok');
}

// ── Récapitulatif ─────────────────────────────────────────────────────────
function updateRecap() {
    const sumCat = cat => lignes[cat].reduce((s,l) => s + parseFloat(l.prix_ht||0), 0);
    const forfaitHT = sumCat('forfait_base');
    const particHT  = sumCat('prestation_particuliere');
    const remiseHT  = sumCat('remise');
    const totalHT   = forfaitHT + particHT + remiseHT;
    const tva       = parseFloat(document.getElementById('tvaPct').value) || 20;
    const tvaAmt    = totalHT * tva / 100;
    const ttc       = totalHT + tvaAmt;

    const fmt = v => v.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';

    document.getElementById('recapForfait').textContent = fmt(forfaitHT);
    document.getElementById('recapPart').textContent    = fmt(particHT);
    document.getElementById('recapRemise').textContent  = fmt(remiseHT);
    document.getElementById('recapHT').textContent      = fmt(totalHT);
    document.getElementById('recapTvaLabel').textContent= 'TVA (' + tva.toFixed(0) + '%)';
    document.getElementById('recapTVA').textContent     = fmt(tvaAmt);
    document.getElementById('recapTTC').textContent     = fmt(ttc);
    document.getElementById('honorairesHT').value       = totalHT.toFixed(2);

    // Par lot
    const nbLots = parseInt(document.getElementById('immLots')?.value) || 0;
    const parLot = document.querySelector('[name=honoraires_par_lot]')?.checked;
    const lotRow = document.getElementById('recapLotRow');
    if (parLot && nbLots > 0) {
        lotRow.style.display = 'flex';
        document.getElementById('recapParLot').textContent = fmt(ttc / nbLots);
    } else {
        lotRow.style.display = 'none';
    }
}

// ── Immeuble autocomplete ─────────────────────────────────────────────────
function searchImm(q) {
    const sugg = document.getElementById('immSugg');
    if (!q || q.length < 2) { sugg.style.display='none'; return; }
    const matches = IMMEUBLES.filter(i =>
        (i.nom||'').toLowerCase().includes(q.toLowerCase()) ||
        (i.ville||'').toLowerCase().includes(q.toLowerCase())
    ).slice(0, 10);
    if (!matches.length) { sugg.style.display='none'; return; }
    sugg.innerHTML = matches.map(i =>
        `<div class="imm-sugg-item" onclick="selectImm(${JSON.stringify(i)})">${esc(i.nom)}${i.ville?' — '+esc(i.ville):''}</div>`
    ).join('');
    sugg.style.display = 'block';
}

function selectImm(i) {
    document.getElementById('immId').value    = i.id;
    document.getElementById('immNom').value   = i.nom || '';
    document.getElementById('immAddr').value  = i.adresse || '';
    document.getElementById('immCp').value    = i.code_postal || '';
    document.getElementById('immVille').value = i.ville || '';
    document.getElementById('immLots').value  = i.nb_lots || '';
    document.getElementById('immSearch').value= i.nom + (i.ville?' — '+i.ville:'');
    document.getElementById('immSugg').style.display = 'none';
    updateRecap();
}

document.addEventListener('click', e => {
    if (!e.target.closest('#immSearch') && !e.target.closest('#immSugg'))
        document.getElementById('immSugg').style.display = 'none';
});

// ── Préparer sauvegarde ───────────────────────────────────────────────────
function prepareSave() {
    const all = [
        ...lignes.forfait_base.map((l,i) => ({...l, categorie:'forfait_base', ordre:i})),
        ...lignes.prestation_particuliere.map((l,i) => ({...l, categorie:'prestation_particuliere', ordre:i})),
        ...lignes.remise.map((l,i) => ({...l, categorie:'remise', ordre:i}))
    ];
    document.getElementById('lignesJson').value = JSON.stringify(all);
}

// ── Envoi email ───────────────────────────────────────────────────────────
function sendMail() {
    const emails = document.getElementById('emailsDest')?.value;
    const cc     = document.getElementById('emailCc')?.value || '';
    const msg    = document.getElementById('emailMsg')?.value || '';
    if (!emails) { showToast('Veuillez saisir au moins un email', 'err'); return; }

    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Envoi en cours...';

    const fd = new FormData();
    fd.append('ajax_send_mail', '1');
    fd.append('prop_id', '__EDIT_ID__');
    fd.append('emails_dest', emails);
    fd.append('email_cc', cc);
    fd.append('message', msg);

    fetch('', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            showToast(d.msg, d.ok ? 'ok' : 'err');
            btn.disabled = false;
            btn.textContent = '✉️ Envoyer la proposition';
        })
        .catch(() => { showToast('Erreur réseau', 'err'); btn.disabled=false; btn.textContent='✉️ Envoyer'; });
}

// ── Toast ─────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + (type||'');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Filtrer tarifs par agence ─────────────────────────────────────────────
function filterTarifs() {
    // Could filter tarif select options by etablissement — for now leave all visible
}
</script>
EXTRAJS;

$layout_extra_js = strtr($layout_extra_js, [
    '__INIT_LIGNES__' => $_init_lignes_json,
    '__IMMEUBLES__'   => $_immeubles_json,
    '__EDIT_ID__'     => (string)$_edit_id_js,
]);

require_once __DIR__ . '/inc/layout_maboximmo.php';
