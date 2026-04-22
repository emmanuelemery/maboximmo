<?php
declare(strict_types=1);
/**
 * investisseur/nouvelle.php — Formulaire d'analyse (création + édition)
 *
 * Parcours :
 *   - ?id=X        → édite l'analyse existante X
 *   - ?from_bien=Y → crée une nouvelle analyse préremplie depuis le bien Y (CRG/arbitrage)
 *   - sinon        → formulaire vierge
 *
 * POST → sauvegarde, recalcul complet, redirection vers detail.php
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_prefill.php';

$pdo = $GLOBALS['pdo'];

$errors = [];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$fromBien = isset($_GET['from_bien']) ? (int)$_GET['from_bien'] : null;
$data = [];
$source_note = '';

// ── POST : sauvegarde ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_any();
        $clean = inv_sanitize_post($_POST);
        if (isset($_POST['id']) && (int)$_POST['id'] > 0) {
            $idRow = inv_save($pdo, $clean, (int)$_POST['id']);
        } else {
            $idRow = inv_save($pdo, $clean, null);
        }
        $target = (function_exists('app_url') ? app_url('/investisseur/detail.php?id=') : '/investisseur/detail.php?id=') . $idRow;
        header('Location: ' . $target);
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $data = array_merge($data, $_POST);
    }
}

// ── Chargement initial ──────────────────────────────────────────────
if (empty($data)) {
    if ($id !== null && $id > 0) {
        $row = inv_load($pdo, $id);
        if (!$row) {
            http_response_code(404);
            die('Analyse introuvable.');
        }
        $data = $row;
    } elseif ($fromBien !== null && $fromBien > 0) {
        try {
            $data = inv_prefill_from_bien($pdo, $fromBien);
            $source_note = 'Pré-rempli depuis le bien #' . $fromBien . ' (CRG / arbitrage / bail).';
        } catch (Throwable $e) {
            $errors[] = "Pré-remplissage impossible : " . $e->getMessage();
        }
    }
}

// Liste des biens pour sélecteur "Importer depuis un bien existant"
$biensDispos = [];
try { $biensDispos = inv_listable_biens($pdo, 500); } catch (Throwable $e) {}

$pageTitle     = $id ? 'Éditer l\'analyse' : 'Nouvelle analyse';
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › ' . ($id ? 'Édition' : 'Saisie');
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$val = fn($k, $def = '') => $h($data[$k] ?? $def);

function inv_rating($name, $current, $label) {
    echo '<div class="inv-field span-2">';
    echo '<label>' . htmlspecialchars($label) . '</label>';
    echo '<div class="inv-rating">';
    for ($i = 1; $i <= 5; $i++) {
        $id = $name . '_' . $i;
        $checked = ((int)$current === $i) ? 'checked' : '';
        echo '<input type="radio" id="' . $id . '" name="' . $name . '" value="' . $i . '" ' . $checked . '>';
        echo '<label class="star" for="' . $id . '">' . $i . '</label>';
    }
    echo '</div></div>';
}
?>
<div class="inv-wrap">

    <div class="inv-header">
        <h1><?= $h($pageTitle) ?></h1>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/')) ?>">☰ Liste</a>
            <a href="<?= $h($u('/investisseur/')) ?>">⌂ Dashboard</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="inv-paper" style="border-left:4px solid #b4443a">
            <strong style="color:#b4443a">Erreurs :</strong>
            <ul style="margin:8px 0 0"><?php foreach ($errors as $e) echo '<li>' . $h($e) . '</li>'; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($source_note !== ''): ?>
        <div class="inv-source-banner">
            <strong>⇡ Source BDD :</strong> <?= $h($source_note) ?>
        </div>
    <?php endif; ?>

    <?php if (!$id && $source_note === '' && !empty($biensDispos)): ?>
        <!-- Pré-remplir depuis un bien existant -->
        <div class="inv-paper" style="border-left:4px solid #4878a6">
            <h2>Importer depuis un bien existant (CRG + arbitrage)</h2>
            <form method="get" action="<?= $h($u('/investisseur/nouvelle.php')) ?>" style="display:flex; gap:12px; align-items:flex-end;">
                <div class="inv-field" style="flex:1">
                    <label>Sélectionner un bien</label>
                    <select name="from_bien" required>
                        <option value="">— Choisir un bien de la base —</option>
                        <?php foreach ($biensDispos as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">
                                <?= $h($b['designation'] ?: $b['reference_bien'] ?: 'Bien #' . $b['id']) ?>
                                <?php if ($b['ville']): ?> — <?= $h($b['ville']) ?><?php endif; ?>
                                <?php if ($b['type_libelle']): ?> · <?= $h($b['type_libelle']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Les valeurs seront pré-remplies depuis les CRG, l'arbitrage et le bail en cours.</span>
                </div>
                <button type="submit" class="inv-btn primary">Pré-remplir →</button>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
        <?php if (!empty($data['id_bien_source'])): ?>
            <input type="hidden" name="id_bien_source" value="<?= (int)$data['id_bien_source'] ?>">
        <?php endif; ?>

        <!-- ── 1. Identification ─────────────────────────────── -->
        <div class="inv-paper">
            <h2>1. Identification du bien</h2>
            <div class="inv-form-grid">
                <div class="inv-field span-2"><label>Titre de l'analyse *</label>
                    <input type="text" name="titre_analyse" required value="<?= $val('titre_analyse') ?>"
                           placeholder="Ex : T3 Riom centre-ville, loyer 700 €">
                </div>
                <div class="inv-field"><label>Référence bien</label>
                    <input type="text" name="reference_bien" value="<?= $val('reference_bien') ?>"></div>

                <div class="inv-field"><label>Type de bien</label>
                    <select name="type_bien">
                        <option value="">—</option>
                        <?php foreach (inv_types_bien() as $t): ?>
                            <option <?= ($data['type_bien'] ?? '') === $t ? 'selected' : '' ?>><?= $h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field"><label>Ville</label>
                    <input type="text" name="ville" value="<?= $val('ville') ?>"></div>
                <div class="inv-field"><label>Quartier</label>
                    <input type="text" name="quartier" value="<?= $val('quartier') ?>"></div>

                <div class="inv-field span-2"><label>Adresse</label>
                    <input type="text" name="adresse" value="<?= $val('adresse') ?>"></div>
                <div class="inv-field"><label>Année construction</label>
                    <input type="number" name="annee_construction" value="<?= $val('annee_construction') ?>"></div>

                <div class="inv-field"><label>Surface (m²)</label>
                    <input type="number" step="0.01" name="surface" value="<?= $val('surface') ?>"></div>
                <div class="inv-field"><label>Nb pièces</label>
                    <input type="number" name="nb_pieces" value="<?= $val('nb_pieces') ?>"></div>
                <div class="inv-field"><label>Étage</label>
                    <input type="text" name="etage" value="<?= $val('etage') ?>"></div>

                <div class="inv-field"><label>État général</label>
                    <select name="etat_general">
                        <option value="">—</option>
                        <?php foreach (inv_etats() as $e): ?>
                            <option <?= ($data['etat_general'] ?? '') === $e ? 'selected' : '' ?>><?= $h($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field"><label>DPE</label>
                    <select name="dpe">
                        <option value="">—</option>
                        <?php foreach (['A','B','C','D','E','F','G'] as $l): ?>
                            <option <?= ($data['dpe'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field"><label>GES</label>
                    <select name="ges">
                        <option value="">—</option>
                        <?php foreach (['A','B','C','D','E','F','G'] as $l): ?>
                            <option <?= ($data['ges'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="inv-field"><label>Extérieur</label>
                    <input type="text" name="exterieur" value="<?= $val('exterieur') ?>" placeholder="Balcon, terrasse…"></div>
                <div class="inv-field"><label>Nb parkings</label>
                    <input type="number" min="0" name="nb_parkings" value="<?= $val('nb_parkings') ?>"></div>

                <div class="inv-field" style="flex-direction:row; gap:16px; align-items:center; padding-top:22px;">
                    <label style="margin:0"><input type="checkbox" name="cave" value="1" <?= !empty($data['cave']) ? 'checked' : '' ?>> Cave</label>
                    <label style="margin:0"><input type="checkbox" name="garage" value="1" <?= !empty($data['garage']) ? 'checked' : '' ?>> Garage</label>
                    <label style="margin:0"><input type="checkbox" name="parking" value="1" <?= !empty($data['parking']) ? 'checked' : '' ?>> Parking</label>
                    <label style="margin:0"><input type="checkbox" name="photovoltaique" value="1" <?= !empty($data['photovoltaique']) ? 'checked' : '' ?>> Photovoltaïque</label>
                </div>
            </div>
        </div>

        <!-- ── 2. Acquisition ────────────────────────────────── -->
        <div class="inv-paper">
            <h2>2. Acquisition & financement</h2>
            <div class="inv-form-grid">
                <div class="inv-field"><label>Prix de vente catalogue (€)</label>
                    <input type="number" step="0.01" name="prix_vente_catalogue" id="fld_prix_vente" value="<?= $val('prix_vente_catalogue') ?>">
                    <span class="hint">Prix affiché par le vendeur / mandat</span></div>
                <div class="inv-field"><label>Prix d'achat négocié (€) *</label>
                    <input type="number" step="0.01" name="prix_achat" id="fld_prix_achat" value="<?= $val('prix_achat') ?>">
                    <span class="hint" id="fld_ecart_nego" style="color:#4f7a3a;">Prix retenu pour les calculs de rentabilité</span></div>
                <div class="inv-field"><label>Frais de notaire (€)</label>
                    <input type="number" step="0.01" name="frais_notaire" value="<?= $val('frais_notaire') ?>">
                    <span class="hint">Vide = 8 % par défaut</span></div>
                <div class="inv-field"><label>Frais d'agence (€)</label>
                    <input type="number" step="0.01" name="frais_agence" value="<?= $val('frais_agence') ?>"></div>
                <div class="inv-field"><label>Travaux (€)</label>
                    <input type="number" step="0.01" name="travaux" value="<?= $val('travaux') ?>"></div>
                <div class="inv-field"><label>Ameublement (€)</label>
                    <input type="number" step="0.01" name="ameublement" value="<?= $val('ameublement') ?>"></div>
                <div class="inv-field"><label>Apport (€)</label>
                    <input type="number" step="0.01" name="apport" value="<?= $val('apport') ?>"></div>

                <div class="inv-field"><label>Taux crédit (%)</label>
                    <input type="number" step="0.001" name="taux_credit" value="<?= $val('taux_credit') ?>" placeholder="3.5"></div>
                <div class="inv-field"><label>Durée crédit (années)</label>
                    <input type="number" name="duree_credit" value="<?= $val('duree_credit') ?>" placeholder="20"></div>
                <div class="inv-field"><label>Honoraires de vente (€)</label>
                    <input type="number" step="0.01" name="honoraires_vente" value="<?= $val('honoraires_vente') ?>">
                    <span class="hint">Estimation si cession — typiquement 4-5 % du prix</span></div>
            </div>
        </div>

        <!-- ── 3. Exploitation locative ──────────────────────── -->
        <div class="inv-paper">
            <h2>3. Exploitation locative</h2>
            <div class="inv-form-grid">
                <div class="inv-field"><label>Loyer HC mensuel (€)</label>
                    <input type="number" step="0.01" name="loyer_estime" value="<?= $val('loyer_estime') ?>"></div>
                <div class="inv-field"><label>Charges récupérables (€/mois)</label>
                    <input type="number" step="0.01" name="charges_recuperables" value="<?= $val('charges_recuperables') ?>"></div>
                <div class="inv-field"><label>Charges non récup. (€/mois)</label>
                    <input type="number" step="0.01" name="charges_non_recuperables" value="<?= $val('charges_non_recuperables') ?>"></div>

                <div class="inv-field"><label>Taxe foncière (€/an)</label>
                    <input type="number" step="0.01" name="taxe_fonciere" value="<?= $val('taxe_fonciere') ?>"></div>
                <div class="inv-field"><label>Assurance PNO (€/an)</label>
                    <input type="number" step="0.01" name="assurance_pno" value="<?= $val('assurance_pno') ?>"></div>
                <div class="inv-field"><label>Vacance locative (%/an)</label>
                    <input type="number" step="0.01" name="vacance_locative" value="<?= $val('vacance_locative') ?>" placeholder="5"></div>

                <div class="inv-field"><label>Gestion locative (% du loyer)</label>
                    <input type="number" step="0.01" name="gestion_locative" value="<?= $val('gestion_locative') ?>"></div>
                <div class="inv-field"><label>Entretien / imprévus (% loyer)</label>
                    <input type="number" step="0.01" name="entretien_imprevus" value="<?= $val('entretien_imprevus') ?>" placeholder="5"></div>

                <div class="inv-field"><label>Régime fiscal</label>
                    <select name="regime_fiscal">
                        <option value="">—</option>
                        <?php foreach (inv_regimes_fiscaux() as $r): ?>
                            <option <?= ($data['regime_fiscal'] ?? '') === $r ? 'selected' : '' ?>><?= $h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field"><label>Type de location</label>
                    <select name="type_location">
                        <option value="">—</option>
                        <?php foreach (inv_types_location() as $t): ?>
                            <option <?= ($data['type_location'] ?? '') === $t ? 'selected' : '' ?>><?= $h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="inv-field span-2"><label>Locataire actuel</label>
                    <input type="text" name="locataire_nom" value="<?= $val('locataire_nom') ?>" placeholder="Nom enseigne / locataire"></div>
                <div class="inv-field"><label>Fin de bail</label>
                    <input type="date" name="bail_fin" value="<?= $val('bail_fin') ?>"></div>
            </div>
        </div>

        <!-- ── 4. Stratégie & scoring ────────────────────────── -->
        <div class="inv-paper">
            <h2>4. Stratégie & appréciation terrain</h2>
            <div class="inv-form-grid col2">
                <div class="inv-field"><label>Stratégie</label>
                    <select name="strategie">
                        <option value="">—</option>
                        <?php foreach (inv_strategies() as $k => $lbl): ?>
                            <option value="<?= $h($k) ?>" <?= ($data['strategie'] ?? '') === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field"></div>

                <?php inv_rating('qualite_emplacement',    (int)($data['qualite_emplacement']    ?? 3), 'Qualité emplacement'); ?>
                <?php inv_rating('tension_locative',       (int)($data['tension_locative']       ?? 3), 'Tension locative'); ?>
                <?php inv_rating('potentiel_valorisation', (int)($data['potentiel_valorisation'] ?? 3), 'Potentiel de valorisation'); ?>
                <?php inv_rating('facilite_revente',       (int)($data['facilite_revente']       ?? 3), 'Facilité de revente'); ?>
                <?php inv_rating('niveau_risque',          (int)($data['niveau_risque']          ?? 3), 'Niveau de risque (5 = élevé)'); ?>

                <div class="inv-field span-full"><label>Commentaire humain / ressenti terrain</label>
                    <textarea name="commentaire_humain" rows="5" placeholder="Contexte, ressenti, éléments qualitatifs non mesurables…"><?= $val('commentaire_humain') ?></textarea>
                    <span class="hint">Ce texte pèse dans le scoring de lisibilité et enrichit les argumentaires.</span>
                </div>
            </div>
        </div>

        <!-- ── 5. Statut + actions ───────────────────────────── -->
        <div class="inv-paper">
            <div class="inv-form-grid col2">
                <div class="inv-field"><label>Statut</label>
                    <select name="statut">
                        <?php foreach (['brouillon','finalisee','archivee'] as $s): ?>
                            <option <?= ($data['statut'] ?? 'brouillon') === $s ? 'selected' : '' ?>><?= $h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:18px">
                <a href="<?= $h($u('/investisseur/')) ?>" class="inv-btn ghost">Annuler</a>
                <button type="submit" class="inv-btn primary">
                    <?= $id ? 'Enregistrer les modifications' : 'Créer l\'analyse' ?>
                </button>
            </div>
        </div>
    </form>

</div>
<script src="<?= $h(function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.js') : '/investisseur/assets/investisseur.js') ?>"></script>
<script>
// Écart négociation live
(function () {
    const pv = document.getElementById('fld_prix_vente');
    const pa = document.getElementById('fld_prix_achat');
    const hint = document.getElementById('fld_ecart_nego');
    if (!pv || !pa || !hint) return;

    function recompute() {
        const vente = parseFloat((pv.value || '0').replace(',', '.'));
        const achat = parseFloat((pa.value || '0').replace(',', '.'));
        if (vente <= 0 || achat <= 0) {
            hint.textContent = 'Prix retenu pour les calculs de rentabilité';
            hint.style.color = '#9a9690';
            return;
        }
        const ecart = achat - vente;
        const pct = (ecart / vente) * 100;
        if (Math.abs(pct) < 0.1) {
            hint.textContent = 'Prix identique au mandat (aucune négociation)';
            hint.style.color = '#9a9690';
        } else if (ecart < 0) {
            hint.textContent = 'Négociation : ' + Math.abs(ecart).toLocaleString('fr-FR', {maximumFractionDigits: 0}) + ' € en moins (' + pct.toFixed(1).replace('.', ',') + ' %)';
            hint.style.color = '#4f7a3a';
        } else {
            hint.textContent = 'Sur-offre : +' + ecart.toLocaleString('fr-FR', {maximumFractionDigits: 0}) + ' € (+' + pct.toFixed(1).replace('.', ',') + ' %)';
            hint.style.color = '#d97a3a';
        }
    }
    pv.addEventListener('input', recompute);
    pa.addEventListener('input', recompute);
    // Auto-copie : si achat vide et vente renseigné, on pré-remplit
    pv.addEventListener('blur', () => { if (!pa.value && pv.value) pa.value = pv.value; recompute(); });
    recompute();
})();
</script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
