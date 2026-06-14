<?php
/*
 * agency_immeuble_form.php — Formulaire ajout/modif immeuble (layout_maboximmo)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = current_role_id();
if ($roleId > 2) {
    header('Location: agency_immeubles.php');
    exit;
}

$pdo = $GLOBALS['pdo'];
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Mode édition ou création ──────────────────────────────────
$id       = (int)($_GET['id'] ?? 0);
$editMode = false;
$imm      = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM immeubles WHERE id = ?");
    $stmt->execute([$id]);
    $imm = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $editMode = !empty($imm);
}

// ── Référentiels ──────────────────────────────────────────────
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$gestionnaires  = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) AS nom_complet FROM users WHERE actif=1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);

$types = [
    'sdc'                  => 'SDC (Syndicat de copropriété)',
    'Appartement'          => 'Appartement',
    'Maison individuelle'  => 'Maison individuelle',
    'Maison  - Jumelée'    => 'Maison jumelée',
    'Local commercial'     => 'Local commercial',
    'Garage'               => 'Garage',
];

$errors  = [];
$success = '';

// ── Traitement POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'reference'        => trim($_POST['reference']       ?? ''),
        'nom'              => trim($_POST['nom']             ?? ''),
        'adresse'          => trim($_POST['adresse']         ?? ''),
        'code_postal'      => trim($_POST['code_postal']     ?? ''),
        'ville'            => trim($_POST['ville']           ?? ''),
        'nb_lots'          => (int)($_POST['nb_lots']        ?? 0),
        'type'             => $_POST['type']                 ?? '',
        'id_etablissement' => (int)($_POST['id_etablissement'] ?? 0),
        'immatriculation'  => strtoupper(trim($_POST['immatriculation'] ?? '')),
        'gestionnaire'     => (int)($_POST['gestionnaire']  ?? 0) ?: null,
        'latitude'         => trim($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null,
        'longitude'        => trim($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null,
        'google_place_id'  => trim($_POST['google_place_id'] ?? '') ?: null,
        'adresse_formatee' => trim($_POST['adresse_formatee'] ?? '') ?: null,
    ];

    if ($data['reference'] === '') $errors[] = 'La référence est obligatoire.';
    if ($data['nom'] === '')        $errors[] = 'Le nom est obligatoire.';
    if ($data['adresse'] === '')    $errors[] = 'L\'adresse est obligatoire.';
    if ($data['code_postal'] === '') $errors[] = 'Le code postal est obligatoire.';
    if ($data['ville'] === '')      $errors[] = 'La ville est obligatoire.';
    if ($data['id_etablissement'] === 0) $errors[] = 'L\'établissement est obligatoire.';
    if ($data['nb_lots'] <= 0)      $errors[] = 'Le nombre de lots doit être supérieur à 0.';

    if (empty($errors)) {
        if ($editMode) {
            $stmt = $pdo->prepare("UPDATE immeubles SET
                reference_immeuble=:reference, nom_immeuble=:nom, adresse_1=:adresse, code_postal=:code_postal,
                ville=:ville, nb_lots=:nb_lots, type_immeuble=:type, id_agence=:id_etablissement,
                latitude=:latitude, longitude=:longitude, google_place_id=:google_place_id, adresse_formatee=:adresse_formatee
                WHERE id=:id");
            unset($data['immatriculation'], $data['gestionnaire']);
            $data['id'] = $id;
            $stmt->execute($data);
            $success = 'Immeuble modifié avec succès.';
            $stmt2 = $pdo->prepare("SELECT * FROM immeubles WHERE id = ?");
            $stmt2->execute([$id]);
            $imm = $stmt2->fetch(PDO::FETCH_ASSOC) ?: $imm;

            try {
                if (file_exists(__DIR__ . '/inc/ged_glossary.php')) {
                    require_once __DIR__ . '/inc/ged_glossary.php';
                    $label = (string)($imm['nom_immeuble'] ?? $data['nom'] ?? '');
                    if ($label !== '') {
                        ged_glossary_sync_entity('immeuble', $id, $label, 'immeubles', [
                            'reference' => (string)($imm['reference_immeuble'] ?? ''),
                        ], $pdo);
                    }
                }
            } catch (Throwable) {}
        } else {
            $stmt = $pdo->prepare("INSERT INTO immeubles
                (reference_immeuble, nom_immeuble, adresse_1, code_postal, ville, nb_lots, type_immeuble, id_agence,
                 latitude, longitude, google_place_id, adresse_formatee)
                VALUES (:reference, :nom, :adresse, :code_postal, :ville, :nb_lots, :type, :id_etablissement,
                        :latitude, :longitude, :google_place_id, :adresse_formatee)");
            unset($data['immatriculation'], $data['gestionnaire']);
            $stmt->execute($data);
            $newId = (int)$pdo->lastInsertId();

            try {
                if (file_exists(__DIR__ . '/inc/ged_glossary.php')) {
                    require_once __DIR__ . '/inc/ged_glossary.php';
                    $label = (string)($data['nom'] ?? '');
                    if ($label !== '') {
                        ged_glossary_sync_entity('immeuble', $newId, $label, 'immeubles', [
                            'reference' => (string)($data['reference'] ?? ''),
                        ], $pdo);
                    }
                }
            } catch (Throwable) {}

            header("Location: agency_immeuble_fiche.php?id=$newId");
            exit;
        }
    }
}

// Valeurs du formulaire
$val = function(string $k) use ($imm): string {
    return (string)($_POST[$k] ?? $imm[$k] ?? '');
};

// ── Layout ──
$layout_title    = $editMode ? 'Modifier un immeuble' : 'Nouvel immeuble';
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.($editMode ? 'EDIT' : 'NEW').'</div><div class="ph-kpi-lbl">Mode</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($etablissements).'</div><div class="ph-kpi-lbl">Établ.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($gestionnaires).'</div><div class="ph-kpi-lbl">Gestionnaires</div></div>
';

$layout_head_actions = '
    <a href="agency_immeubles.php" class="ph-btn">Liste</a>
    '.($editMode
        ? '<a href="agency_immeuble_fiche.php?id='.$id.'" class="ph-btn primary">Fiche</a>'
        : '<a class="ph-btn dispo">Fiche</a>').'
    <a class="ph-btn dispo">—</a>
    <a class="ph-btn dispo">—</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.form-card { background: var(--bg-primary,#e4e8f0); border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; padding: 20px 24px; margin-bottom: 16px; max-width: 800px; }
.form-row { display: grid; gap: 14px; margin-bottom: 14px; }
.form-row.cols2 { grid-template-columns: 1fr 1fr; }
.form-row.cols3 { grid-template-columns: 1fr 1fr 1fr; }
.form-row.cols1 { grid-template-columns: 1fr; }
.form-row:last-child { margin-bottom: 0; }

.ff label { display: block; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: #a8a49e; margin-bottom: 5px; }
.ff label .req { color: #4878a6; }
.ff input, .ff select, .ff textarea {
    width: 100%; background: var(--bg-primary,#e4e8f0);
    box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff;
    border: none; border-radius: 10px; padding: 9px 12px;
    font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
    outline: none; box-sizing: border-box;
}
.ff input:focus, .ff select:focus {
    box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(72,120,166,0.3);
}
.ff input.error { box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(138,80,64,0.4); }

.v2-btn { padding: 0 20px; height: 38px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600; background: var(--bg-primary,#e4e8f0); box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; color: #3a3830; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: box-shadow 0.15s; }
.v2-btn:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
.v2-btn.primary { background: #4878a6; color: #fff; }

.alert { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; max-width: 800px; }
.alert.success { background: #e0f0e8; color: #3a7a6a; border: 1px solid #b0d8c0; }
.alert.error   { background: #fce8e6; color: #8a5040; border: 1px solid #f0c0bc; }
.alert ul { margin: 4px 0 0 16px; }

#immatriculation { font-family: 'DM Mono', monospace; letter-spacing: 0.1em; }
.immat-hint { font-size: 10px; color: #a8a49e; margin-top: 4px; font-family: 'DM Mono', monospace; }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<style>
.places-dropdown {
    position: absolute; z-index: 2000;
    background: #fff; border: 1px solid rgba(196,192,186,0.5); border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12); max-height: 300px; overflow-y: auto;
}
.places-item { padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid rgba(196,192,186,0.2); }
.places-item:last-child { border-bottom: none; }
.places-item:hover, .places-item.active { background: rgba(72,120,166,0.08); }
</style>
<script src="js/places.js"></script>
EXTRAJS;

if ($GOOGLE_MAPS_API_KEY !== '') {
    $layout_extra_js .= '<script async src="https://maps.googleapis.com/maps/api/js?key=' . urlencode($GOOGLE_MAPS_API_KEY) . '&libraries=places&callback=initPlacesAutocomplete"></script>';
}

$layout_extra_js .= <<<'EXTRAJS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('immatriculation');
    if (!el) return;
    el.addEventListener('input', function () {
        let v = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        let out = '';
        for (let i = 0; i < v.length && i < 9; i++) {
            if (i === 3 || i === 6) out += '-';
            out += v[i];
        }
        const pos = this.selectionStart;
        this.value = out;
        try { this.setSelectionRange(pos, pos); } catch(e) {}
    });
});
</script>
EXTRAJS;

// ── Contenu ──
ob_start();
?>

<?php if ($success): ?>
<div class="alert success" style="max-width:800px">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    <?= h($success) ?>
    <?php if ($editMode): ?>
    &nbsp;—&nbsp;<a href="agency_immeuble_fiche.php?id=<?= $id ?>" style="color:#3a7a6a;font-weight:600">Voir la fiche →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert error" style="max-width:800px">
    <div>
        <strong>Veuillez corriger les erreurs suivantes :</strong>
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<form method="POST">

    <!-- Section Identité -->
    <div class="section-header">
        <div class="section-title">
            <div class="line-l"></div>
            <span class="sec-txt">Identité de l'immeuble</span>
            <div class="line-r"></div>
        </div>
    </div>
    <div class="form-card">
        <div class="form-row cols3">
            <div class="ff">
                <label>Référence <span class="req">*</span></label>
                <input type="text" name="reference" value="<?= h($val('reference')) ?>"
                       placeholder="ex. 1070" required class="<?= in_array('La référence est obligatoire.', $errors) ? 'error' : '' ?>">
            </div>
            <div class="ff" style="grid-column: span 2">
                <label>Nom de l'immeuble <span class="req">*</span></label>
                <input type="text" name="nom" value="<?= h($val('nom')) ?>"
                       placeholder="ex. Résidence Le Chamois" required>
            </div>
        </div>
        <div class="form-row cols2">
            <div class="ff">
                <label>Type <span class="req">*</span></label>
                <select name="type" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($types as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= $val('type') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ff">
                <label>Nombre de lots <span class="req">*</span></label>
                <input type="number" name="nb_lots" value="<?= h($val('nb_lots')) ?>"
                       min="0" placeholder="0" required>
            </div>
        </div>
        <div class="form-row cols1">
            <div class="ff">
                <label>Immatriculation (SDC)</label>
                <input type="text" name="immatriculation" id="immatriculation"
                       value="<?= h($val('immatriculation')) ?>" placeholder="AAA-000-000">
                <div class="immat-hint">Format : AAA-000-000 (ex. RCS-123-456)</div>
            </div>
        </div>
    </div>

    <!-- Section Adresse -->
    <div class="section-header">
        <div class="section-title">
            <div class="line-l"></div>
            <span class="sec-txt">Adresse &mdash; Google Places</span>
            <div class="line-r"></div>
        </div>
    </div>
    <div class="form-card">
        <div class="form-row cols1">
            <div class="ff">
                <label>🔍 Rechercher l'adresse</label>
                <input type="text"
                       id="imm_places_search"
                       placeholder="Commencez à taper l'adresse (autocomplete Google)…"
                       data-places-input
                       data-places-endpoint="api/places_autocomplete.php"
                       data-places-details-endpoint="api/places_details.php"
                       data-places-geocode-endpoint="api/geocode_address.php"
                       data-places-street1="imm_adresse"
                       data-places-postal="imm_cp"
                       data-places-city="imm_ville"
                       data-places-lat="imm_lat"
                       data-places-lng="imm_lng"
                       data-places-place-id="imm_place_id"
                       data-places-formatted="imm_formatee"
                       data-places-country-code="fr">
            </div>
        </div>
        <div class="form-row cols1">
            <div class="ff">
                <label>Adresse <span class="req">*</span></label>
                <input type="text" name="adresse" id="imm_adresse" value="<?= h($val('adresse')) ?>"
                       placeholder="Numéro et rue" required>
            </div>
        </div>
        <div class="form-row cols2">
            <div class="ff">
                <label>Code postal <span class="req">*</span></label>
                <input type="text" name="code_postal" id="imm_cp" value="<?= h($val('code_postal')) ?>"
                       placeholder="69000" maxlength="10" required>
            </div>
            <div class="ff">
                <label>Ville / Commune <span class="req">*</span></label>
                <input type="text" name="ville" id="imm_ville" value="<?= h($val('ville')) ?>"
                       placeholder="Lyon" required>
            </div>
        </div>
        <div class="form-row cols3">
            <div class="ff">
                <label>Latitude (GPS)</label>
                <input type="text" name="latitude" id="imm_lat" value="<?= h($val('latitude')) ?>" readonly>
            </div>
            <div class="ff">
                <label>Longitude (GPS)</label>
                <input type="text" name="longitude" id="imm_lng" value="<?= h($val('longitude')) ?>" readonly>
            </div>
            <div class="ff">
                <label>Google Place ID</label>
                <input type="text" name="google_place_id" id="imm_place_id" value="<?= h($val('google_place_id')) ?>" readonly>
            </div>
        </div>
        <input type="hidden" name="adresse_formatee" id="imm_formatee" value="<?= h($val('adresse_formatee')) ?>">
    </div>

    <!-- Section Organisation -->
    <div class="section-header">
        <div class="section-title">
            <div class="line-l"></div>
            <span class="sec-txt">Organisation & Affectation</span>
            <div class="line-r"></div>
        </div>
    </div>
    <div class="form-card">
        <div class="form-row cols2">
            <div class="ff">
                <label>Établissement <span class="req">*</span></label>
                <select name="id_etablissement" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($etablissements as $e): ?>
                        <option value="<?= $e['id'] ?>"
                            <?= (int)$val('id_etablissement') === (int)$e['id'] ? 'selected' : '' ?>>
                            <?= h($e['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ff">
                <label>Gestionnaire</label>
                <select name="gestionnaire">
                    <option value="">— Aucun —</option>
                    <?php foreach ($gestionnaires as $g): ?>
                        <option value="<?= $g['id'] ?>"
                            <?= (int)$val('gestionnaire') === (int)$g['id'] ? 'selected' : '' ?>>
                            <?= h($g['nom_complet']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:10px;align-items:center;max-width:800px;margin-top:8px">
        <a href="agency_immeubles.php" class="v2-btn">✕ Annuler</a>
        <?php if ($editMode): ?>
            <a href="agency_immeuble_fiche.php?id=<?= $id ?>" class="v2-btn">📄 Voir la fiche</a>
        <?php endif; ?>
        <?php if (!empty($_GET['lier'])): ?>
        <!-- Mode « lier » (ouvert en modal depuis une création de bien) : valide
             l'immeuble et le rattache au bien. Visible uniquement avec ?lier=1. -->
        <button type="submit" class="v2-btn primary" style="background:linear-gradient(135deg,#0f9d58,#0b8043);border-color:#0b8043;">
            ✅ Valider et lier au bien
        </button>
        <?php else: ?>
        <button type="submit" class="v2-btn primary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $editMode ? 'Enregistrer les modifications' : 'Créer l\'immeuble' ?>
        </button>
        <?php endif; ?>
    </div>

</form>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
