<?php
// agency_syndic_contrat_form.php — Contrat de syndic (4 onglets ALUR)
require_once __DIR__ . '/inc/init.php';
require_login();
if (current_role_id() > 2) { header('Location: agency_dashboard.php'); exit; }

$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── Données de référence ───────────────────────────────────────────────────
$immeubles      = $pdo->query("SELECT id, nom, adresse, ville, code_postal, nb_lots, immatriculation, honoraires_ht, id_etablissement FROM immeubles ORDER BY nom LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// ── Chargement contrat existant ────────────────────────────────────────────
$editId  = (int)($_GET['id'] ?? 0);
$contrat = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT cs.*, i.id AS i_id FROM contrat_syndic cs LEFT JOIN immeubles i ON i.id=cs.id_immeuble WHERE cs.id=?");
    $stmt->execute([$editId]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);
}

$c = $contrat ?? [];
$v = fn($k, $def='') => htmlspecialchars($c[$k] ?? $def);
$ck = fn($k) => !empty($c[$k]) ? 'checked' : '';

// ── Sauvegarde ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contrat'])) {
    $fields = [
        'id_immeuble'                     => (int)($_POST['id_immeuble']??0) ?: null,
        'id_etablissement'                => (int)($_POST['id_etablissement']??0) ?: null,
        'nom_copropriete'                 => trim($_POST['nom_copropriete']??''),
        'adresse_copropriete'             => trim($_POST['adresse_copropriete']??''),
        'immatriculation_copropriete'     => trim($_POST['immatriculation_copropriete']??''),
        'representant_copro'              => trim($_POST['representant_copro']??''),
        'date_ag_designation'             => $_POST['date_ag_designation'] ?: null,
        'assurance_rc_nom'                => trim($_POST['assurance_rc_nom']??''),
        'assurance_rc_date'               => $_POST['assurance_rc_date'] ?: null,
        'nb_lots_total'                   => (int)($_POST['nb_lots_total']??0) ?: null,
        'nb_lots_principaux'              => (int)($_POST['nb_lots_principaux']??0) ?: null,
        'date_debut'                      => $_POST['date_debut'] ?: null,
        'date_fin'                        => $_POST['date_fin'] ?: null,
        'horaires_ouvrables_lun_jeu'      => trim($_POST['horaires_ouvrables_lun_jeu']??''),
        'horaires_ouvrables_vendredi'     => trim($_POST['horaires_ouvrables_vendredi']??''),
        'accueil_physique_lun_ven'        => trim($_POST['accueil_physique_lun_ven']??''),
        'accueil_physique_sam'            => trim($_POST['accueil_physique_sam']??''),
        'accueil_tel_lun_ven'             => trim($_POST['accueil_tel_lun_ven']??''),
        'accueil_tel_sam'                 => trim($_POST['accueil_tel_sam']??''),
        'remuneration_annuelle_ht'        => (float)str_replace(',','.',$_POST['remuneration_annuelle_ht']??'0') ?: null,
        'nb_visites_annuelles'            => (int)($_POST['nb_visites_annuelles']??0) ?: null,
        'ag_duree_minutes'                => (int)($_POST['ag_duree_minutes']??0) ?: null,
        'reunions_cs_inclues'             => (int)($_POST['reunions_cs_inclues']??0) ?: null,
        'ag_extra_incluse'                => isset($_POST['ag_extra_incluse']) ? 1 : 0,
        'reunion_cs_incluse'              => isset($_POST['reunion_cs_incluse']) ? 1 : 0,
        'frais_affranchissement_inclus'   => isset($_POST['frais_affranchissement_inclus']) ? 1 : 0,
        'frequence_facturation'           => in_array($_POST['frequence_facturation']??'',['mensuelle','trimestrielle','annuelle']) ? $_POST['frequence_facturation'] : 'annuelle',
        'conditions_revision'             => trim($_POST['conditions_revision']??''),
        'duree_visite_minutes'            => (int)($_POST['duree_visite_minutes']??0) ?: null,
        'visite_avec_rapport'             => isset($_POST['visite_avec_rapport']) ? 1 : 0,
        'visite_avec_cs'                  => isset($_POST['visite_avec_cs']) ? 1 : 0,
        'plage_horaire_ag'                => trim($_POST['plage_horaire_ag']??''),
        'ag_tenue_par_syndic'             => isset($_POST['ag_tenue_par_syndic']) ? 1 : 0,
        'ag_tenue_par_prepose'            => isset($_POST['ag_tenue_par_prepose']) ? 1 : 0,
        'honoraires_ht_nplus1'            => (float)str_replace(',','.',$_POST['honoraires_ht_nplus1']??'0') ?: null,
        'tarif_ag_sup'                    => trim($_POST['tarif_ag_sup']??''),
        'tarif_reunion_cs_sup'            => trim($_POST['tarif_reunion_cs_sup']??''),
        'tarif_modif_reglement_copro'     => trim($_POST['tarif_modif_reglement_copro']??''),
        'tarif_publication_edd_modifie'   => trim($_POST['tarif_publication_edd_modifie']??''),
        'frais_lrar'                      => trim($_POST['frais_lrar']??''),
        'reprise_comptabilite_anterieure' => trim($_POST['reprise_comptabilite_anterieure']??''),
        'dossier_emprunt'                 => trim($_POST['dossier_emprunt']??''),
        'dossier_subvention'              => trim($_POST['dossier_subvention']??''),
        'immatriculation_initiale'        => trim($_POST['immatriculation_initiale']??''),
        'defraiement_autre'               => trim($_POST['defraiement_autre']??''),
        'prestations_particulieres'       => trim($_POST['prestations_particulieres']??''),
        'maj_horaire_hors_plage'          => (float)str_replace(',','.',$_POST['maj_horaire_hors_plage']??'0') ?: null,
        'tarif_visite_sup'                => (float)str_replace(',','.',$_POST['tarif_visite_sup']??'0') ?: null,
        'gestion_sinistres'               => isset($_POST['gestion_sinistres']) ? 1 : 0,
        'tarif_sinistre_deplacement'      => (float)str_replace(',','.',$_POST['tarif_sinistre_deplacement']??'0') ?: null,
        'tarif_assistance_expertise'      => (float)str_replace(',','.',$_POST['tarif_assistance_expertise']??'0') ?: null,
        'tarif_suivi_assureur'            => (float)str_replace(',','.',$_POST['tarif_suivi_assureur']??'0') ?: null,
        'frais_urgence_majoration'        => trim($_POST['frais_urgence_majoration']??''),
        'frais_recouvrement_mise_demeure' => (float)str_replace(',','.',$_POST['frais_recouvrement_mise_demeure']??'0') ?: null,
        'protocole_accord'                => (float)str_replace(',','.',$_POST['protocole_accord']??'0') ?: null,
        'frais_hypotheque'                => (float)str_replace(',','.',$_POST['frais_hypotheque']??'0') ?: null,
        'frais_mainlevee'                 => (float)str_replace(',','.',$_POST['frais_mainlevee']??'0') ?: null,
        'frais_injonction'                => (float)str_replace(',','.',$_POST['frais_injonction']??'0') ?: null,
        'frais_dossier_auxiliaire_justice'=> (float)str_replace(',','.',$_POST['frais_dossier_auxiliaire_justice']??'0') ?: null,
        'frais_dossier_avocat'            => (float)str_replace(',','.',$_POST['frais_dossier_avocat']??'0') ?: null,
        'frais_opposition_mutation'       => (float)str_replace(',','.',$_POST['frais_opposition_mutation']??'0') ?: null,
        'etat_date_ttc'                   => (float)str_replace(',','.',$_POST['etat_date_ttc']??'0') ?: null,
        'repro_carnet_entretien'          => (float)str_replace(',','.',$_POST['repro_carnet_entretien']??'0') ?: null,
        'repro_diagnostics'               => (float)str_replace(',','.',$_POST['repro_diagnostics']??'0') ?: null,
        'copie_pv_ag'                     => (float)str_replace(',','.',$_POST['copie_pv_ag']??'0') ?: null,
        'ag_sup_copro_text'               => trim($_POST['ag_sup_copro_text']??''),
        'ag_sup_date'                     => $_POST['ag_sup_date'] ?: null,
        'ag_sup_lieu'                     => trim($_POST['ag_sup_lieu']??''),
    ];

    if ($editId) {
        $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($fields)));
        $pdo->prepare("UPDATE contrat_syndic SET $set WHERE id=?")->execute([...array_values($fields), $editId]);
    } else {
        $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
        $phs  = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO contrat_syndic ($cols) VALUES($phs)")->execute(array_values($fields));
    }
    header('Location: agency_syndic_contrats.php?saved=1');
    exit;
}

// ── Layout config ──────────────────────────────────────────────────────────
$layout_title   = $editId ? 'Modifier contrat de syndic' : 'Nouveau contrat de syndic';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_actions = '
<button type="submit" form="formContrat" class="ph-btn primary">
  <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Enregistrer
</button>
<a href="agency_syndic_contrats.php" class="ph-btn">Retour</a>
'
. ($editId
    ? '<a href="agency_pdf_contrat_syndic.php?id='.$editId.'" target="_blank" class="ph-btn">PDF</a>'
    : '<a class="ph-btn dispo">dispo</a>')
. '<a class="ph-btn dispo">dispo</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
/* Onglets */
.tabs-row{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.tab-btn{padding:9px 20px;border-radius:999px;border:none;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;color:#8a8680;font-family:inherit;font-size:13px;font-weight:600;transition:all .15s;display:flex;align-items:center;gap:8px}
.tab-btn.active{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;color:#4878a6;font-weight:700}
.tab-btn.done{color:#3a7a6a}
.tab-btn .num{width:20px;height:20px;border-radius:50%;background:currentColor;color:var(--bg-primary,#e4e8f0);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tab-btn.done .num{background:#3a7a6a}
.tab-panel{display:none;flex-direction:column;gap:18px}
.tab-panel.active{display:flex}

/* Cartes */
.card{background:var(--bg-primary,#e4e8f0);border-radius:20px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;padding:24px}
.card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8a8680;margin-bottom:16px;display:flex;align-items:center;gap:8px}

/* Formulaire */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid.three{grid-template-columns:1fr 1fr 1fr}
.form-grid.four{grid-template-columns:1fr 1fr 1fr 1fr}
.fg-span2{grid-column:span 2}
.fg-span3{grid-column:span 3}
.fg-span4{grid-column:span 4}
.form-group{display:flex;flex-direction:column;gap:5px}
label.field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#8a8680}
input[type=text],input[type=number],input[type=date],input[type=email],select,textarea{
    padding:9px 13px;border:none;border-radius:10px;background:var(--bg-primary,#e4e8f0);box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;
    color:#1a1816;font-family:inherit;font-size:13px;outline:none;width:100%}
textarea{resize:vertical;min-height:72px}
select option{background:var(--bg-primary,#e4e8f0)}

/* Checkbox row */
.chk-row{display:flex;flex-wrap:wrap;gap:12px;padding:4px 0}
.chk-item{display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;padding:6px 12px;border-radius:8px;background:var(--bg-primary,#e4e8f0);box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;transition:all .15s}
.chk-item:hover{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff}
.chk-item input[type=checkbox]{width:16px;height:16px;cursor:pointer}

.section-div{height:1px;background:rgba(0,0,0,.06);margin:4px 0}
.section-label{font-size:12px;font-weight:700;color:#4878a6;text-transform:uppercase;letter-spacing:.5px;padding:6px 0 2px}

.prix-field{position:relative}
.prix-field input{padding-right:32px}
.prix-field::after{content:'€';position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#8a8680;font-size:13px;pointer-events:none}

.btn-row{display:flex;gap:12px;align-items:center;margin-top:6px;flex-wrap:wrap}
.btn-primary{padding:11px 24px;border-radius:999px;border:none;cursor:pointer;background:#4878a6;color:#fff;font-size:13px;font-weight:700;font-family:inherit;display:inline-flex;align-items:center;gap:6px;transition:opacity .15s}
.btn-primary:hover{opacity:.88}
.btn-secondary{padding:11px 24px;border-radius:999px;border:none;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;color:#1a1816;font-size:13px;font-weight:600;font-family:inherit;transition:all .15s}
.btn-secondary:hover{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;color:#4878a6}

.ac-wrap{position:relative}
.ac-list{position:absolute;z-index:100;background:var(--bg-primary,#e4e8f0);border-radius:12px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;overflow:hidden;max-height:200px;overflow-y:auto;width:100%;top:calc(100% + 4px)}
.ac-item{padding:9px 14px;cursor:pointer;font-size:13px}
.ac-item:hover{background:rgba(72,120,166,.12)}

.ttc-display{font-size:12px;color:#8a8680;padding:4px 0}

@media(max-width:700px){.form-grid,.form-grid.three,.form-grid.four{grid-template-columns:1fr}.fg-span2,.fg-span3,.fg-span4{grid-column:1}}
</style>
EXTRACSS;

ob_start();
?>

<form method="post" id="formContrat">
<input type="hidden" name="save_contrat" value="1">

<!-- ── Onglets ── -->
<div class="tabs-row">
    <button type="button" class="tab-btn active" id="tab1" onclick="goTab(1)">
        <span class="num">1</span> Informations générales
    </button>
    <button type="button" class="tab-btn" id="tab2" onclick="goTab(2)">
        <span class="num">2</span> Rémunération & Conditions
    </button>
    <button type="button" class="tab-btn" id="tab3" onclick="goTab(3)">
        <span class="num">3</span> Prestations particulières
    </button>
    <button type="button" class="tab-btn" id="tab4" onclick="goTab(4)">
        <span class="num">4</span> Prestations copropriétaires
    </button>
</div>

<!-- ════════════════════════════════════════════════════════
     ONGLET 1 — Informations générales
════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="panel1">

    <!-- Sélection immeuble (autocomplete) -->
    <div class="card">
        <div class="card-title">🏢 Immeuble / Copropriété</div>
        <div class="form-grid">
            <div class="form-group fg-span2">
                <label class="field-label">Rechercher un immeuble existant</label>
                <div class="ac-wrap">
                    <input type="text" id="immSearch" autocomplete="off" placeholder="Nom ou adresse de l'immeuble…" oninput="searchImm(this.value)">
                    <div class="ac-list" id="immAcList" style="display:none"></div>
                </div>
                <input type="hidden" name="id_immeuble" id="immId" value="<?= $v('id_immeuble') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Agence *</label>
                <select name="id_etablissement" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($etablissements as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= ($c['id_etablissement']??$etab_id)==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="field-label">Représentant copropriété</label>
                <input type="text" name="representant_copro" value="<?= $v('representant_copro') ?>" placeholder="Président CS, représentant...">
            </div>
        </div>
    </div>

    <!-- Infos copropriété -->
    <div class="card">
        <div class="card-title">📋 Identification de la copropriété</div>
        <div class="form-grid">
            <div class="form-group fg-span2">
                <label class="field-label">Nom de la copropriété *</label>
                <input type="text" name="nom_copropriete" id="fNom" required value="<?= $v('nom_copropriete') ?>" placeholder="Résidence Les Tilleuls">
            </div>
            <div class="form-group fg-span2">
                <label class="field-label">Adresse</label>
                <input type="text" name="adresse_copropriete" id="fAddr" value="<?= $v('adresse_copropriete') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">N° Immatriculation Registre (RCS)</label>
                <input type="text" name="immatriculation_copropriete" id="fImmat" placeholder="AAA-000-000" value="<?= $v('immatriculation_copropriete') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Date AG de désignation</label>
                <input type="date" name="date_ag_designation" value="<?= $v('date_ag_designation') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Nb total de lots</label>
                <input type="number" name="nb_lots_total" min="1" value="<?= $v('nb_lots_total') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Nb lots principaux</label>
                <input type="number" name="nb_lots_principaux" id="fLots" min="1" value="<?= $v('nb_lots_principaux') ?>">
            </div>
        </div>
    </div>

    <!-- Assurance + Période -->
    <div class="card">
        <div class="card-title">🛡️ Assurance RC & Durée du contrat</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="field-label">Assureur RC professionnel</label>
                <input type="text" name="assurance_rc_nom" value="<?= $v('assurance_rc_nom') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Date souscription RC</label>
                <input type="date" name="assurance_rc_date" value="<?= $v('assurance_rc_date') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Date de début *</label>
                <input type="date" name="date_debut" required value="<?= $v('date_debut') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Date de fin</label>
                <input type="date" name="date_fin" value="<?= $v('date_fin') ?>">
            </div>
        </div>
    </div>

    <!-- Horaires accueil -->
    <div class="card">
        <div class="card-title">🕐 Horaires d'accueil du syndic</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="field-label">Heures ouvrables Lun–Jeu</label>
                <input type="text" name="horaires_ouvrables_lun_jeu" placeholder="8h30–12h / 14h–18h" value="<?= $v('horaires_ouvrables_lun_jeu') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Heures ouvrables Vendredi</label>
                <input type="text" name="horaires_ouvrables_vendredi" placeholder="8h30–12h / 14h–17h" value="<?= $v('horaires_ouvrables_vendredi') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Accueil physique Lun–Ven</label>
                <input type="text" name="accueil_physique_lun_ven" value="<?= $v('accueil_physique_lun_ven') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Accueil physique Samedi</label>
                <input type="text" name="accueil_physique_sam" value="<?= $v('accueil_physique_sam') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Accueil téléphonique Lun–Ven</label>
                <input type="text" name="accueil_tel_lun_ven" value="<?= $v('accueil_tel_lun_ven') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Accueil téléphonique Samedi</label>
                <input type="text" name="accueil_tel_sam" value="<?= $v('accueil_tel_sam') ?>">
            </div>
        </div>
    </div>

    <div class="btn-row">
        <button type="button" class="btn-primary" onclick="goTab(2)">Suivant : Rémunération →</button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     ONGLET 2 — Rémunération & Conditions
════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel2">

    <div class="card">
        <div class="card-title">💰 Rémunération annuelle</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="field-label">Rémunération annuelle HT *</label>
                <div class="prix-field">
                    <input type="number" step="0.01" name="remuneration_annuelle_ht" id="remuHT"
                           value="<?= $v('remuneration_annuelle_ht') ?>" oninput="calcTTC()">
                </div>
                <div class="ttc-display" id="remuTTC"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Honoraires N+1 HT</label>
                <div class="prix-field">
                    <input type="number" step="0.01" name="honoraires_ht_nplus1" id="remuHT1"
                           value="<?= $v('honoraires_ht_nplus1') ?>" oninput="calcTTC1()">
                </div>
                <div class="ttc-display" id="remuTTC1"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Fréquence de facturation</label>
                <select name="frequence_facturation">
                    <option value="mensuelle" <?= ($c['frequence_facturation']??'')==='mensuelle'?'selected':'' ?>>Mensuelle</option>
                    <option value="trimestrielle" <?= ($c['frequence_facturation']??'')==='trimestrielle'?'selected':'' ?>>Trimestrielle</option>
                    <option value="annuelle" <?= ($c['frequence_facturation']??'annuelle')==='annuelle'?'selected':'' ?>>Annuelle</option>
                </select>
            </div>
            <div class="form-group">
                <label class="field-label">Conditions de révision</label>
                <textarea name="conditions_revision"><?= $v('conditions_revision') ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">📅 Assemblées Générales & Visites</div>
        <div class="form-grid three">
            <div class="form-group">
                <label class="field-label">Nb visites annuelles incluses</label>
                <input type="number" name="nb_visites_annuelles" min="0" value="<?= $v('nb_visites_annuelles') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Durée d'une visite (min)</label>
                <input type="number" name="duree_visite_minutes" min="0" value="<?= $v('duree_visite_minutes') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Réunions CS incluses</label>
                <input type="number" name="reunions_cs_inclues" min="0" value="<?= $v('reunions_cs_inclues') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Durée AG (minutes)</label>
                <input type="number" name="ag_duree_minutes" min="0" value="<?= $v('ag_duree_minutes') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Plage horaire des AG</label>
                <input type="text" name="plage_horaire_ag" placeholder="18h–22h" value="<?= $v('plage_horaire_ag') ?>">
            </div>
        </div>

        <div style="margin-top:14px">
        <div class="section-label">Options incluses dans le forfait</div>
        <div class="chk-row">
            <label class="chk-item"><input type="checkbox" name="ag_extra_incluse" <?= $ck('ag_extra_incluse') ?>> AG supplémentaires incluses</label>
            <label class="chk-item"><input type="checkbox" name="reunion_cs_incluse" <?= $ck('reunion_cs_incluse') ?>> Réunion CS incluse</label>
            <label class="chk-item"><input type="checkbox" name="frais_affranchissement_inclus" <?= $ck('frais_affranchissement_inclus') ?>> Frais d'affranchissement inclus</label>
            <label class="chk-item"><input type="checkbox" name="visite_avec_rapport" <?= $ck('visite_avec_rapport') ?>> Visite avec rapport</label>
            <label class="chk-item"><input type="checkbox" name="visite_avec_cs" <?= $ck('visite_avec_cs') ?>> Visite en présence du CS</label>
        </div>
        </div>

        <div style="margin-top:14px">
        <div class="section-label">AG tenue par</div>
        <div class="chk-row">
            <label class="chk-item"><input type="checkbox" name="ag_tenue_par_syndic" <?= $ck('ag_tenue_par_syndic') ?>> Le syndic lui-même</label>
            <label class="chk-item"><input type="checkbox" name="ag_tenue_par_prepose" <?= $ck('ag_tenue_par_prepose') ?>> Un préposé</label>
        </div>
        </div>
    </div>

    <!-- AG suppl -->
    <div class="card">
        <div class="card-title">📌 AG supplémentaire prévue</div>
        <div class="form-grid three">
            <div class="form-group">
                <label class="field-label">Date AG supp.</label>
                <input type="date" name="ag_sup_date" value="<?= $v('ag_sup_date') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Lieu AG supp.</label>
                <input type="text" name="ag_sup_lieu" value="<?= $v('ag_sup_lieu') ?>">
            </div>
        </div>
    </div>

    <div class="btn-row">
        <button type="button" class="btn-secondary" onclick="goTab(1)">← Retour</button>
        <button type="button" class="btn-primary" onclick="goTab(3)">Suivant : Prestations particulières →</button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     ONGLET 3 — Prestations particulières (ALUR)
════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel3">

    <div style="font-size:12px;color:#f59e0b;padding:10px 16px;background:rgba(245,158,11,.08);border-radius:10px;margin-bottom:2px;">
        ⚠️ Conformément au décret ALUR n°2015-342 du 26 mars 2015, les prestations particulières doivent être listées et tarifées séparément du forfait de gestion courante.
    </div>

    <div class="card">
        <div class="card-title">📄 Prestations générales</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="field-label">Majoration horaire hors plage (€/h)</label>
                <div class="prix-field"><input type="number" step="0.01" name="maj_horaire_hors_plage" value="<?= $v('maj_horaire_hors_plage') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Visite hors forfait (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="tarif_visite_sup" value="<?= $v('tarif_visite_sup') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">AG supplémentaire (texte/tarif)</label>
                <input type="text" name="tarif_ag_sup" placeholder="Ex: 250 € HT / séance" value="<?= $v('tarif_ag_sup') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Réunion CS supplémentaire</label>
                <input type="text" name="tarif_reunion_cs_sup" value="<?= $v('tarif_reunion_cs_sup') ?>">
            </div>
            <div class="form-group fg-span2">
                <label class="field-label">Prestations particulières (texte libre)</label>
                <textarea name="prestations_particulieres" rows="4"><?= $v('prestations_particulieres') ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">⚡ Sinistres & Urgences</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="field-label">Majoration urgence</label>
                <input type="text" name="frais_urgence_majoration" placeholder="Ex: +50% tarif horaire" value="<?= $v('frais_urgence_majoration') ?>">
            </div>
            <div class="form-group" style="align-self:end">
                <label class="chk-item" style="width:fit-content">
                    <input type="checkbox" name="gestion_sinistres" <?= $ck('gestion_sinistres') ?>> Gestion sinistres incluse dans le forfait
                </label>
            </div>
            <div class="form-group">
                <label class="field-label">Déplacement sinistre (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="tarif_sinistre_deplacement" value="<?= $v('tarif_sinistre_deplacement') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Assistance expertise (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="tarif_assistance_expertise" value="<?= $v('tarif_assistance_expertise') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Suivi assureur (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="tarif_suivi_assureur" value="<?= $v('tarif_suivi_assureur') ?>"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">📑 Actes administratifs</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="field-label">Modification règlement de copropriété</label>
                <input type="text" name="tarif_modif_reglement_copro" value="<?= $v('tarif_modif_reglement_copro') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Publication EDD modifié</label>
                <input type="text" name="tarif_publication_edd_modifie" value="<?= $v('tarif_publication_edd_modifie') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Frais LRAR</label>
                <input type="text" name="frais_lrar" value="<?= $v('frais_lrar') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Reprise comptabilité antérieure</label>
                <input type="text" name="reprise_comptabilite_anterieure" value="<?= $v('reprise_comptabilite_anterieure') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Dossier emprunt</label>
                <input type="text" name="dossier_emprunt" value="<?= $v('dossier_emprunt') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Dossier subvention</label>
                <input type="text" name="dossier_subvention" value="<?= $v('dossier_subvention') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Immatriculation initiale RCS</label>
                <input type="text" name="immatriculation_initiale" value="<?= $v('immatriculation_initiale') ?>">
            </div>
            <div class="form-group">
                <label class="field-label">Défraiement autre</label>
                <input type="text" name="defraiement_autre" value="<?= $v('defraiement_autre') ?>">
            </div>
        </div>
    </div>

    <div class="btn-row">
        <button type="button" class="btn-secondary" onclick="goTab(2)">← Retour</button>
        <button type="button" class="btn-primary" onclick="goTab(4)">Suivant : Prestations copropriétaires →</button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     ONGLET 4 — Prestations copropriétaires
════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel4">

    <div class="card">
        <div class="card-title">⚖️ Frais de recouvrement & Contentieux</div>
        <div class="form-grid four">
            <div class="form-group">
                <label class="field-label">Mise en demeure (€ HT)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_recouvrement_mise_demeure" value="<?= $v('frais_recouvrement_mise_demeure') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Protocole d'accord (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="protocole_accord" value="<?= $v('protocole_accord') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Hypothèque (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_hypotheque" value="<?= $v('frais_hypotheque') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Mainlevée (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_mainlevee" value="<?= $v('frais_mainlevee') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Injonction (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_injonction" value="<?= $v('frais_injonction') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Dossier auxiliaire de justice (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_dossier_auxiliaire_justice" value="<?= $v('frais_dossier_auxiliaire_justice') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Dossier avocat (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_dossier_avocat" value="<?= $v('frais_dossier_avocat') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Opposition mutation (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="frais_opposition_mutation" value="<?= $v('frais_opposition_mutation') ?>"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">📋 Documents & Copies</div>
        <div class="form-grid four">
            <div class="form-group">
                <label class="field-label">État daté TTC (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="etat_date_ttc" value="<?= $v('etat_date_ttc') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Repro carnet entretien (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="repro_carnet_entretien" value="<?= $v('repro_carnet_entretien') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Repro diagnostics (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="repro_diagnostics" value="<?= $v('repro_diagnostics') ?>"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Copie PV AG (€)</label>
                <div class="prix-field"><input type="number" step="0.01" name="copie_pv_ag" value="<?= $v('copie_pv_ag') ?>"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">🗓️ AG supplémentaire — copropriétaires</div>
        <div class="form-grid three">
            <div class="form-group">
                <label class="field-label">Tarif AG supp. copropriété (texte)</label>
                <input type="text" name="ag_sup_copro_text" value="<?= $v('ag_sup_copro_text') ?>">
            </div>
        </div>
    </div>

    <!-- Récap final -->
    <div class="card" style="background:linear-gradient(135deg,rgba(72,120,166,.12),rgba(96,165,250,.08))">
        <div class="card-title">✅ Enregistrement</div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:16px">
            Vérifiez les 4 parties avant d'enregistrer. Vous pourrez modifier le contrat à tout moment.
        </div>
        <div class="btn-row">
            <button type="button" class="btn-secondary" onclick="goTab(3)">← Retour</button>
            <button type="submit" class="btn-primary">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Enregistrer le contrat
            </button>
            <?php if ($editId): ?>
            <a href="agency_pdf_contrat_syndic.php?id=<?= $editId ?>" target="_blank" class="btn-secondary">📄 Voir le PDF</a>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /panel4 -->

</form>

<?php
$layout_content = ob_get_clean();

$_immeubles_json = json_encode($immeubles);
$layout_extra_js = <<<'EXTRAJS'
<script>
const IMMEUBLES = __IMMEUBLES__;

// ── Navigation onglets ────────────────────────────────────────────────────
function goTab(n) {
    [1,2,3,4].forEach(i => {
        document.getElementById('panel'+i).classList.toggle('active', i===n);
        document.getElementById('tab'+i).classList.toggle('active', i===n);
        document.getElementById('tab'+i).classList.toggle('done', i<n);
    });
    window.scrollTo({top:0, behavior:'smooth'});
}

// ── Calcul TTC auto ───────────────────────────────────────────────────────
function calcTTC() {
    const ht = parseFloat(document.getElementById('remuHT').value)||0;
    document.getElementById('remuTTC').textContent = ht ? '→ TTC : ' + (ht*1.2).toLocaleString('fr-FR',{minimumFractionDigits:2})+ ' €' : '';
}
function calcTTC1() {
    const ht = parseFloat(document.getElementById('remuHT1').value)||0;
    document.getElementById('remuTTC1').textContent = ht ? '→ TTC : ' + (ht*1.2).toLocaleString('fr-FR',{minimumFractionDigits:2})+ ' €' : '';
}
// Init
calcTTC(); calcTTC1();

// ── Autocomplete immeuble ─────────────────────────────────────────────────
function searchImm(q) {
    const list = document.getElementById('immAcList');
    if (!q || q.length < 2) { list.style.display='none'; return; }
    const res = IMMEUBLES.filter(i =>
        (i.nom||'').toLowerCase().includes(q.toLowerCase()) ||
        (i.ville||'').toLowerCase().includes(q.toLowerCase())
    ).slice(0,10);
    if (!res.length) { list.style.display='none'; return; }
    list.innerHTML = res.map(i =>
        `<div class="ac-item" onclick='fillImm(${JSON.stringify(i)})'>${esc(i.nom)}${i.ville?' — '+esc(i.ville):''}</div>`
    ).join('');
    list.style.display='block';
}
function fillImm(i) {
    document.getElementById('immId').value   = i.id;
    document.getElementById('fNom').value    = i.nom||'';
    document.getElementById('fAddr').value   = (i.adresse||'') + (i.code_postal?' '+i.code_postal:'') + (i.ville?' '+i.ville:'');
    document.getElementById('fImmat').value  = i.immatriculation||'';
    document.getElementById('fLots').value   = i.nb_lots||'';
    document.getElementById('immSearch').value = i.nom + (i.ville?' — '+i.ville:'');
    document.getElementById('immAcList').style.display='none';
    // remu ht auto
    if (i.honoraires_ht) {
        document.getElementById('remuHT').value = parseFloat(i.honoraires_ht).toFixed(2);
        calcTTC();
    }
}
document.addEventListener('click', e => {
    if (!e.target.closest('#immSearch') && !e.target.closest('#immAcList'))
        document.getElementById('immAcList').style.display='none';
});
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
</script>
EXTRAJS;

$layout_extra_js = str_replace('__IMMEUBLES__', $_immeubles_json, $layout_extra_js);

require_once __DIR__ . '/inc/layout_maboximmo.php';
