<?php
// agency_syndic_tarifs.php — Gestion des grilles tarifaires (3 niveaux)
require_once __DIR__ . '/inc/init.php';
require_login();
if (current_role_id() > 2) { header('Location: agency_dashboard.php'); exit; }

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── AJAX : sauvegarder une grille complète ─────────────────────────────────
if (isset($_POST['ajax_save_tarif'])) {
    header('Content-Type: application/json');
    $tid   = (int)($_POST['tarif_id'] ?? 0);
    $nom   = trim($_POST['nom'] ?? '');
    $niv   = in_array($_POST['niveau']??'', ['societe','agence','immeuble']) ? $_POST['niveau'] : 'societe';
    $etab  = (int)($_POST['id_etablissement'] ?? 0) ?: null;
    $imm   = (int)($_POST['id_immeuble']      ?? 0) ?: null;
    $def   = isset($_POST['est_defaut']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');

    if (!$nom) { echo json_encode(['ok'=>false,'msg'=>'Nom obligatoire']); exit; }

    if ($def) {
        $pdo->prepare("UPDATE agency_syndic_tarif SET est_defaut=0 WHERE niveau=? AND (id_etablissement<=>? OR ? IS NULL)")->execute([$niv,$etab,$etab]);
    }

    if ($tid) {
        $pdo->prepare("UPDATE agency_syndic_tarif SET nom=?,niveau=?,id_etablissement=?,id_immeuble=?,est_defaut=?,notes=? WHERE id=?")
            ->execute([$nom,$niv,$etab,$imm,$def,$notes,$tid]);
    } else {
        $pdo->prepare("INSERT INTO agency_syndic_tarif (nom,niveau,id_etablissement,id_immeuble,est_defaut,notes,id_createur) VALUES(?,?,?,?,?,?,?)")
            ->execute([$nom,$niv,$etab,$imm,$def,$notes,$user_id]);
        $tid = (int)$pdo->lastInsertId();
    }

    // Sauvegarder les lignes (JSON)
    $lignes = json_decode($_POST['lignes_json'] ?? '[]', true) ?: [];
    $pdo->prepare("DELETE FROM agency_syndic_tarif_ligne WHERE id_tarif=?")->execute([$tid]);
    $ord = 0;
    foreach ($lignes as $l) {
        $pdo->prepare("INSERT INTO agency_syndic_tarif_ligne (id_tarif,categorie,code,designation,description,unite,prix_ht,tva_pct,inclus_forfait,obligatoire,ordre,actif) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)")
            ->execute([
                $tid,
                in_array($l['categorie']??'', ['forfait_base','prestation_particuliere','remise']) ? $l['categorie'] : 'prestation_particuliere',
                strtoupper(substr(preg_replace('/[^A-Z0-9]/i','',$l['code']??''),0,30)) ?: 'LIG'.($ord+1),
                $l['designation'] ?? '',
                $l['description'] ?? null,
                in_array($l['unite']??'', ['annuel','par_lot','par_acte','par_heure','forfait','pourcentage']) ? $l['unite'] : 'forfait',
                (float)str_replace(',','.',$l['prix_ht']??'0'),
                (float)str_replace(',','.',$l['tva_pct']??'20'),
                isset($l['inclus_forfait']) ? 1 : 0,
                isset($l['obligatoire'])    ? 1 : 0,
                $ord++,
            ]);
    }
    echo json_encode(['ok'=>true,'tarif_id'=>$tid,'msg'=>'Grille sauvegardée']);
    exit;
}

// ── AJAX : supprimer une grille ───────────────────────────────────────────────
if (isset($_POST['ajax_delete_tarif'])) {
    header('Content-Type: application/json');
    $tid = (int)$_POST['tarif_id'];
    $pdo->prepare("DELETE FROM agency_syndic_tarif_ligne WHERE id_tarif=?")->execute([$tid]);
    $pdo->prepare("DELETE FROM agency_syndic_tarif WHERE id=?")->execute([$tid]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX : GET tarif (pour édition) ───────────────────────────────────────────
if (isset($_GET['_ajax_get_tarif'])) {
    header('Content-Type: application/json');
    $tid = (int)$_GET['id'];
    $t = $pdo->prepare("SELECT * FROM agency_syndic_tarif WHERE id=?");
    $t->execute([$tid]); $t = $t->fetch(PDO::FETCH_ASSOC);
    $l = $pdo->prepare("SELECT * FROM agency_syndic_tarif_ligne WHERE id_tarif=? ORDER BY ordre");
    $l->execute([$tid]); $l = $l->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['tarif'=>$t,'lignes'=>$l]);
    exit;
}

// ── AJAX : dupliquer une grille ───────────────────────────────────────────────
if (isset($_POST['ajax_dupliquer'])) {
    header('Content-Type: application/json');
    $src = (int)$_POST['tarif_id'];
    $t   = $pdo->prepare("SELECT * FROM agency_syndic_tarif WHERE id=?");
    $t->execute([$src]); $t = $t->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo json_encode(['ok'=>false,'msg'=>'Introuvable']); exit; }
    $pdo->prepare("INSERT INTO agency_syndic_tarif (nom,niveau,id_etablissement,id_immeuble,est_defaut,notes,id_createur) VALUES(?,?,?,?,0,?,?)")
        ->execute(['Copie de '.$t['nom'],$t['niveau'],$t['id_etablissement'],$t['id_immeuble'],$t['notes'],$user_id]);
    $nid = (int)$pdo->lastInsertId();
    $lignes = $pdo->prepare("SELECT * FROM agency_syndic_tarif_ligne WHERE id_tarif=? ORDER BY ordre");
    $lignes->execute([$src]); $lignes = $lignes->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lignes as $l) {
        $pdo->prepare("INSERT INTO agency_syndic_tarif_ligne (id_tarif,categorie,code,designation,description,unite,prix_ht,tva_pct,inclus_forfait,obligatoire,ordre,actif) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$nid,$l['categorie'],$l['code'],$l['designation'],$l['description'],$l['unite'],$l['prix_ht'],$l['tva_pct'],$l['inclus_forfait'],$l['obligatoire'],$l['ordre'],$l['actif']]);
    }
    echo json_encode(['ok'=>true,'new_id'=>$nid,'msg'=>'Grille dupliquée']);
    exit;
}

// ── Chargement des données ────────────────────────────────────────────────────
$tarifs = $pdo->query("
    SELECT t.*, e.nom AS etab_nom, NULL AS etab_sigle,
           i.nom_immeuble AS imm_nom,
           COUNT(l.id) AS nb_lignes
    FROM agency_syndic_tarif t
    LEFT JOIN etablissements e ON e.id = t.id_etablissement
    LEFT JOIN immeubles i ON i.id = t.id_immeuble
    LEFT JOIN agency_syndic_tarif_ligne l ON l.id_tarif = t.id AND l.actif=1
    GROUP BY t.id
    ORDER BY FIELD(t.niveau,'societe','agence','immeuble'), t.nom
")->fetchAll(PDO::FETCH_ASSOC);

$immeubles = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles WHERE statut_immeuble='actif' ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$etabs     = $pdo->query("SELECT id, nom AS raison_sociale, NULL AS sigle FROM etablissements ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC);

// Lignes ALUR prédéfinies (pour initialiser une nouvelle grille)
$alur_presets = [
    ['categorie'=>'forfait_base','code'=>'HON_BASE','designation'=>'Honoraires de gestion courante (forfait annuel)','description'=>'Gestion administrative, comptable, technique courante. Inclut la convocation et tenue de l\'AG ordinaire annuelle.','unite'=>'annuel','prix_ht'=>1200,'tva_pct'=>20,'inclus_forfait'=>1,'obligatoire'=>1],
    ['categorie'=>'forfait_base','code'=>'AG_ORD','designation'=>'Assemblée Générale Ordinaire (incluse au forfait)','description'=>'Préparation, convocation, tenue et compte-rendu de l\'AG ordinaire annuelle.','unite'=>'annuel','prix_ht'=>0,'tva_pct'=>20,'inclus_forfait'=>1,'obligatoire'=>1],
    ['categorie'=>'prestation_particuliere','code'=>'AG_EXTRA','designation'=>'Assemblée Générale Extraordinaire','description'=>'Convocation, tenue et rédaction du procès-verbal.','unite'=>'par_acte','prix_ht'=>450,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>1],
    ['categorie'=>'prestation_particuliere','code'=>'ETAT_DATE','designation'=>'État daté (mutation)','description'=>'Établissement de l\'état daté lors de la vente d\'un lot.','unite'=>'par_acte','prix_ht'=>380,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>1],
    ['categorie'=>'prestation_particuliere','code'=>'CERT_ART20','designation'=>'Certificat article 20 (loi 1965)','description'=>'Attestation de situation comptable du copropriétaire.','unite'=>'par_acte','prix_ht'=>90,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>1],
    ['categorie'=>'prestation_particuliere','code'=>'FICHE_SYNTH','designation'=>'Fiche synthétique (acheteur)','description'=>'Établissement de la fiche synthétique de la copropriété.','unite'=>'par_acte','prix_ht'=>50,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>1],
    ['categorie'=>'prestation_particuliere','code'=>'RELANCE','designation'=>'Frais de relance (lettre de mise en demeure)','description'=>'Envoi d\'une lettre recommandée de relance pour impayés.','unite'=>'par_acte','prix_ht'=>45,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>0],
    ['categorie'=>'prestation_particuliere','code'=>'RECOUVREMENT','designation'=>'Suivi de recouvrement judiciaire','description'=>'Honoraires de suivi pour dossier transmis à l\'huissier ou avocat.','unite'=>'par_acte','prix_ht'=>200,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>0],
    ['categorie'=>'prestation_particuliere','code'=>'SINISTRE','designation'=>'Gestion de sinistre important','description'=>'Suivi et coordination d\'un sinistre nécessitant travaux (>1500€).','unite'=>'par_acte','prix_ht'=>300,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>0],
    ['categorie'=>'prestation_particuliere','code'=>'TRAVAUX_EXCEP','designation'=>'Honoraires travaux exceptionnels','description'=>'Suivi de travaux votés en AG représentant plus de 15% du budget prévisionnel.','unite'=>'pourcentage','prix_ht'=>4,'tva_pct'=>20,'inclus_forfait'=>0,'obligatoire'=>0],
    ['categorie'=>'prestation_particuliere','code'=>'DEPL','designation'=>'Frais de déplacement','description'=>'Par kilomètre parcouru pour déplacement exceptionnel sur l\'immeuble.','unite'=>'par_acte','prix_ht'=>0.52,'tva_pct'=>0,'inclus_forfait'=>0,'obligatoire'=>0],
];

// Grouper tarifs par niveau
$by_niveau = ['societe'=>[],'agence'=>[],'immeuble'=>[]];
foreach ($tarifs as $t) $by_niveau[$t['niveau']][] = $t;

// ── Layout config ──────────────────────────────────────────────────────────
$layout_title   = 'Grilles tarifaires';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">'.count($tarifs).'</div><div class="ph-kpi-lbl">Total</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.count($by_niveau['societe']).'</div><div class="ph-kpi-lbl">Société</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.count($by_niveau['agence']).'</div><div class="ph-kpi-lbl">Agence</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.count($by_niveau['immeuble']).'</div><div class="ph-kpi-lbl">Immeuble</div></div>
';

$layout_head_actions = '
<a href="agency_syndic_propositions.php" class="ph-btn">Propositions</a>
<a href="agency_syndic_contrats.php" class="ph-btn">Contrats</a>
<a class="ph-btn dispo">dispo</a>
<a class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
/* Onglets niveaux */
.level-tabs{display:flex;gap:8px;margin-bottom:24px}
.ltab{padding:10px 22px;border-radius:999px;border:none;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px #ffffff;color:#6a6864;transition:box-shadow .15s;display:flex;align-items:center;gap:8px}
.ltab:hover{box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 4px #ffffff}
.ltab.active{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;color:#4878a6}
.ltab .lbadge{font-family:'DM Mono',monospace;font-size:10px;font-weight:700;background:#e0dbd4;border-radius:999px;padding:1px 7px;color:#6a6864}
.ltab.active .lbadge{background:#4878a6;color:#fff}
.level-panel{display:none}.level-panel.active{display:block}

/* Grid de cartes grilles */
.tarifs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-bottom:18px}
.tarif-card{background:var(--bg-primary,#e4e8f0);border-radius:16px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;overflow:hidden;cursor:pointer;transition:box-shadow .2s}
.tarif-card:hover{box-shadow:8px 8px 20px #c0bcb6,-8px -8px 18px #ffffff}
.tarif-card.selected{box-shadow:inset 4px 4px 10px var(--shadow-dark,#d4d7de),inset -4px -4px 9px #ffffff}
.tc-head{padding:16px 18px;border-bottom:1px solid #e4e6ec;display:flex;align-items:center;gap:10px}
.tc-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tc-icon.soc{background:linear-gradient(135deg,#6898bf,#4878a6)}
.tc-icon.age{background:linear-gradient(135deg,#6a9f88,#3a7a6a)}
.tc-icon.imm{background:linear-gradient(135deg,#c8a060,#7a6830)}
.tc-name{font-size:14px;font-weight:700;color:#1a1816;flex:1}
.tc-default{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#4878a6;color:#fff;border-radius:999px;padding:2px 8px}
.tc-body{padding:12px 18px;font-size:12px;color:#6a6864}
.tc-stat{display:flex;justify-content:space-between;margin-bottom:4px}
.tc-actions{display:flex;gap:6px;padding:10px 18px;border-top:1px solid #e4e6ec}

.add-tarif-card{background:var(--bg-primary,#e4e8f0);border-radius:16px;box-shadow:4px 4px 10px var(--shadow-dark,#d4d7de),-4px -4px 9px #ffffff;border:2px dashed var(--shadow-dark,#d4d7de);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:28px;cursor:pointer;transition:box-shadow .2s,border-color .2s;min-height:120px}
.add-tarif-card:hover{box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 12px #ffffff;border-color:#4878a6}
.add-tarif-card span{font-size:13px;font-weight:600;color:#9a9690}

.editor-panel{background:var(--bg-primary,#e4e8f0);border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;padding:24px 26px;margin-top:22px;display:none}
.editor-panel.open{display:block}
.ep-header{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.ep-title{font-size:17px;font-weight:700;color:#1a1816;flex:1}
.ep-form-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:14px;margin-bottom:18px}
input[type=text],input[type=number],select,textarea{border:none;border-radius:8px;padding:8px 12px;background:var(--bg-primary,#e4e8f0);box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;outline:none;width:100%}
textarea{min-height:60px;resize:vertical;border-radius:8px}
input:focus,select:focus,textarea:focus{box-shadow:inset 3px 3px 7px #b8b4ae,inset -3px -3px 6px #ffffff,0 0 0 2px rgba(72,120,166,.18)}
label.fg-label{font-size:11px;font-weight:600;color:#6a6864;display:block;margin-bottom:4px}

.lignes-section{margin-top:16px}
.lignes-tabs{display:flex;gap:6px;margin-bottom:14px}
.ltab2{padding:6px 16px;border-radius:999px;border:none;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:2px 2px 6px var(--shadow-dark,#d4d7de),-2px -2px 5px #ffffff;color:#6a6864}
.ltab2.active{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px #ffffff;color:#4878a6}
.lignes-table{width:100%;border-collapse:collapse;font-size:12px}
.lignes-table th{padding:8px 10px;background:#e0dbd4;font-size:10px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.07em;text-align:left;white-space:nowrap}
.lignes-table td{padding:6px 8px;border-bottom:1px solid #e4e6ec;vertical-align:middle}
.lignes-table td input,.lignes-table td select{height:30px;padding:0 8px;font-size:12px;border-radius:6px;box-shadow:inset 2px 2px 4px var(--shadow-dark,#d4d7de),inset -2px -2px 3px #ffffff}
.lignes-table td textarea{min-height:40px;padding:4px 8px;font-size:11px}
.row-drag{cursor:grab;color:#b0aaa4;padding:0 6px;font-size:16px}

.btn{padding:8px 18px;border-radius:999px;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;transition:box-shadow .15s}
.btn.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:2px 4px 12px rgba(72,120,166,.3)}
.btn.primary:hover{box-shadow:2px 6px 16px rgba(72,120,166,.4)}
.btn.secondary{background:var(--bg-primary,#e4e8f0);box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px #ffffff;color:#6a6864}
.btn.secondary:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px #ffffff}
.btn.danger{color:#8a5040}
.btn.success{background:linear-gradient(135deg,#5aa87a,#3a7a6a);color:#fff;box-shadow:2px 4px 12px rgba(58,122,106,.3)}
.btn.sm{padding:5px 12px;font-size:11px}
.btn.icon-btn{width:28px;height:28px;padding:0;border-radius:50%;background:var(--bg-primary,#e4e8f0);box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 4px #ffffff;display:inline-flex;align-items:center;justify-content:center;color:#6a6864}
.btn.icon-btn:hover{box-shadow:inset 2px 2px 4px var(--shadow-dark,#d4d7de),inset -2px -2px 3px #ffffff;color:#4878a6}

.alur-note{background:#e8f0f8;border-radius:10px;padding:12px 16px;font-size:11px;color:#4878a6;margin-bottom:14px;border-left:3px solid #4878a6}

.toast{position:fixed;bottom:24px;right:24px;background:#3a7a6a;color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.2);z-index:9999;transform:translateY(80px);opacity:0;transition:all .3s}
.toast.show{transform:translateY(0);opacity:1}
.toast.err{background:#8a5040}
</style>
EXTRACSS;

ob_start();
?>

<div class="alur-note">
  <strong>Loi ALUR (décret 26 mars 2015) :</strong> Le contrat de syndic doit distinguer les prestations incluses dans le forfait annuel et les prestations particulières facturées séparément. Cette grille structure votre offre conformément à cette obligation.
</div>

<!-- Onglets niveaux -->
<div class="level-tabs">
  <button class="ltab active" onclick="switchLevel('societe')">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
    Société
    <span class="lbadge"><?= count($by_niveau['societe']) ?></span>
  </button>
  <button class="ltab" onclick="switchLevel('agence')">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    Agence
    <span class="lbadge"><?= count($by_niveau['agence']) ?></span>
  </button>
  <button class="ltab" onclick="switchLevel('immeuble')">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="15"/><path d="M16 21V7M8 21V7M2 12h20"/></svg>
    Immeuble
    <span class="lbadge"><?= count($by_niveau['immeuble']) ?></span>
  </button>
</div>

<?php foreach (['societe','agence','immeuble'] as $niv):
  $iconCls = ['societe'=>'soc','agence'=>'age','immeuble'=>'imm'][$niv];
  $iconSvg = [
    'societe' => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
    'agence'  => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>',
    'immeuble'=> '<rect x="2" y="7" width="20" height="15"/><path d="M16 21V7M8 21V7M2 12h20"/>',
  ][$niv];
?>
<div class="level-panel <?= $niv==='societe'?'active':'' ?>" id="panel_<?= $niv ?>">
  <div class="tarifs-grid">
    <?php foreach ($by_niveau[$niv] as $t): ?>
    <div class="tarif-card" id="card_<?= $t['id'] ?>" onclick="selectTarif(<?= $t['id'] ?>, '<?= $niv ?>')">
      <div class="tc-head">
        <div class="tc-icon <?= $iconCls ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><?= $iconSvg ?></svg>
        </div>
        <div class="tc-name"><?= htmlspecialchars($t['nom']) ?></div>
        <?php if ($t['est_defaut']): ?><span class="tc-default">Défaut</span><?php endif; ?>
      </div>
      <div class="tc-body">
        <?php if ($t['etab_nom']): ?><div><?= htmlspecialchars($t['etab_sigle']??$t['etab_nom']) ?></div><?php endif; ?>
        <?php if ($t['imm_nom']): ?><div><?= htmlspecialchars($t['imm_nom']) ?></div><?php endif; ?>
        <div class="tc-stat" style="margin-top:8px">
          <span><?= (int)$t['nb_lignes'] ?> prestation<?= $t['nb_lignes']>1?'s':'' ?></span>
          <span><?= $t['actif'] ? '● Actif' : '○ Inactif' ?></span>
        </div>
      </div>
      <div class="tc-actions" onclick="event.stopPropagation()">
        <button class="btn secondary sm" onclick="openEditor(<?= $t['id'] ?>)">Modifier</button>
        <button class="btn secondary sm" onclick="dupliquer(<?= $t['id'] ?>)">Dupliquer</button>
        <button class="btn secondary sm danger" onclick="supprimer(<?= $t['id'] ?>, '<?= addslashes($t['nom']) ?>')">✕</button>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="add-tarif-card" onclick="openEditor(0, '<?= $niv ?>')">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#b0b8c8" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span>Nouvelle grille <?= $niv ?></span>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Éditeur -->
<div class="editor-panel" id="editor_panel">
  <div class="ep-header">
    <div class="ep-title" id="ep_title">Nouvelle grille</div>
    <button class="btn secondary sm" onclick="closeEditor()">Fermer</button>
    <button class="btn success" onclick="saveTarif()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;vertical-align:middle"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Enregistrer
    </button>
  </div>

  <input type="hidden" id="edit_tarif_id" value="0">
  <input type="hidden" id="edit_niveau" value="societe">

  <div class="ep-form-row">
    <div>
      <label class="fg-label">Nom de la grille *</label>
      <input type="text" id="ep_nom" placeholder="Ex. : Grille Standard 2025">
    </div>
    <div>
      <label class="fg-label">Niveau</label>
      <select id="ep_niveau" onchange="toggleNiveauFields()">
        <option value="societe">Société (global)</option>
        <option value="agence">Agence</option>
        <option value="immeuble">Immeuble</option>
      </select>
    </div>
    <div id="ep_etab_wrap">
      <label class="fg-label">Établissement</label>
      <select id="ep_etab">
        <option value="">— Tous —</option>
        <?php foreach ($etabs as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div id="ep_imm_wrap" style="display:none">
      <label class="fg-label">Immeuble</label>
      <select id="ep_imm">
        <option value="">—</option>
        <?php foreach ($immeubles as $im): ?><option value="<?= $im['id'] ?>"><?= htmlspecialchars($im['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
    <input type="checkbox" id="ep_defaut" style="width:16px;height:16px;accent-color:#4878a6;box-shadow:none">
    <label for="ep_defaut" style="font-size:12px;font-weight:600;color:#4a4844">Grille par défaut de ce niveau</label>
  </div>

  <!-- Lignes -->
  <div class="lignes-section">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div class="lignes-tabs">
        <button class="ltab2 active" id="ltab_base" onclick="switchLignesTab('base')">Forfait de base</button>
        <button class="ltab2" id="ltab_part" onclick="switchLignesTab('part')">Prestations particulières</button>
        <button class="ltab2" id="ltab_rem"  onclick="switchLignesTab('rem')">Remises</button>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn secondary sm" onclick="loadAlurPresets()">📋 Charger préréglages ALUR</button>
        <button class="btn primary sm"   onclick="addLigne()">+ Ligne</button>
      </div>
    </div>

    <!-- Tableau forfait base -->
    <div id="tab_base">
      <table class="lignes-table" id="tbl_base">
        <thead><tr>
          <th style="width:24px"></th>
          <th>Code</th><th>Désignation</th><th>Description</th>
          <th>Unité</th><th>Prix HT</th><th>TVA%</th>
          <th>✓ Inclus</th><th>✓ Oblig.</th><th></th>
        </tr></thead>
        <tbody id="body_base"></tbody>
      </table>
    </div>
    <!-- Tableau prestations particulières -->
    <div id="tab_part" style="display:none">
      <table class="lignes-table" id="tbl_part">
        <thead><tr>
          <th style="width:24px"></th>
          <th>Code</th><th>Désignation</th><th>Description</th>
          <th>Unité</th><th>Prix HT</th><th>TVA%</th><th>✓ Oblig.</th><th></th>
        </tr></thead>
        <tbody id="body_part"></tbody>
      </table>
    </div>
    <!-- Tableau remises -->
    <div id="tab_rem" style="display:none">
      <table class="lignes-table" id="tbl_rem">
        <thead><tr>
          <th style="width:24px"></th>
          <th>Code</th><th>Désignation</th><th>Valeur (% ou €)</th><th>TVA%</th><th></th>
        </tr></thead>
        <tbody id="body_rem"></tbody>
      </table>
    </div>
  </div>
</div>

<div id="toast_msg" class="toast"></div>

<?php
$layout_content = ob_get_clean();

$_alur_presets_json = json_encode($alur_presets);
$layout_extra_js = <<<'EXTRAJS'
<script>
const ALUR_PRESETS = __ALUR_PRESETS__;
let currentLignesTab = 'base';
let allLignes = []; // toutes les lignes en cours d'édition

// ── Onglets niveau ────────────────────────────────────────────────────────────
function switchLevel(niv) {
    document.querySelectorAll('.ltab').forEach((b,i)=>b.classList.toggle('active',['societe','agence','immeuble'][i]===niv));
    document.querySelectorAll('.level-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel_'+niv).classList.add('active');
    closeEditor();
}

// ── Sélection d'une carte ─────────────────────────────────────────────────────
function selectTarif(id, niv) { openEditor(id); }

// ── Ouvrir l'éditeur ──────────────────────────────────────────────────────────
async function openEditor(id, niv='societe') {
    document.getElementById('editor_panel').classList.add('open');
    document.getElementById('edit_tarif_id').value = id;
    allLignes = [];

    if (id === 0) {
        // Nouveau
        document.getElementById('ep_title').textContent = 'Nouvelle grille';
        document.getElementById('ep_nom').value    = '';
        document.getElementById('ep_niveau').value = niv;
        document.getElementById('ep_defaut').checked = false;
        document.getElementById('ep_etab').value  = '';
        document.getElementById('ep_imm').value   = '';
        toggleNiveauFields();
        renderLignes();
    } else {
        // Charger depuis serveur
        const r = await fetch(`?_ajax_get_tarif=1&id=${id}`);
        const d = await r.json();
        document.getElementById('ep_title').textContent = 'Modifier : ' + (d.tarif?.nom||'');
        document.getElementById('ep_nom').value    = d.tarif?.nom    || '';
        document.getElementById('ep_niveau').value = d.tarif?.niveau || 'societe';
        document.getElementById('ep_defaut').checked = !!d.tarif?.est_defaut;
        document.getElementById('ep_etab').value  = d.tarif?.id_etablissement || '';
        document.getElementById('ep_imm').value   = d.tarif?.id_immeuble      || '';
        toggleNiveauFields();
        allLignes = d.lignes || [];
        renderLignes();
    }
    editor_panel.scrollIntoView({behavior:'smooth'});
}

function closeEditor() {
    document.getElementById('editor_panel').classList.remove('open');
    allLignes = [];
}

function toggleNiveauFields() {
    const n = document.getElementById('ep_niveau').value;
    document.getElementById('ep_etab_wrap').style.display = n !== 'societe' ? 'block' : 'none';
    document.getElementById('ep_imm_wrap').style.display  = n === 'immeuble' ? 'block' : 'none';
}

// ── Onglets lignes ────────────────────────────────────────────────────────────
function switchLignesTab(t) {
    currentLignesTab = t;
    ['base','part','rem'].forEach(k => {
        document.getElementById('tab_'+k).style.display   = k===t ? 'block' : 'none';
        document.getElementById('ltab_'+k).classList.toggle('active', k===t);
    });
}

// ── Render lignes ─────────────────────────────────────────────────────────────
function renderLignes() {
    ['forfait_base','prestation_particuliere','remise'].forEach((cat,ci) => {
        const tid = ['base','part','rem'][ci];
        const tbody = document.getElementById('body_'+tid);
        tbody.innerHTML = '';
        allLignes.filter(l=>l.categorie===cat).forEach((l,i) => {
            tbody.appendChild(buildLigneRow(l, cat));
        });
    });
}

function buildLigneRow(l, cat) {
    const tr = document.createElement('tr');
    tr.dataset.cat = cat;
    const inclus = cat === 'forfait_base'
        ? `<td><input type="checkbox" ${l.inclus_forfait?'checked':''} class="cb_inclus" style="width:16px;height:16px;accent-color:#4878a6;box-shadow:none"></td>`
        : '';
    const oblig = cat !== 'remise'
        ? `<td><input type="checkbox" ${l.obligatoire?'checked':''} class="cb_oblig" style="width:16px;height:16px;accent-color:#c87030;box-shadow:none"></td>`
        : '';

    if (cat === 'remise') {
        tr.innerHTML = `
          <td class="row-drag">⋮⋮</td>
          <td><input type="text" value="${esc(l.code)}" class="f_code" placeholder="CODE" style="width:80px"></td>
          <td><input type="text" value="${esc(l.designation)}" class="f_desig" placeholder="Libellé remise"></td>
          <td><input type="number" value="${l.prix_ht||0}" class="f_prix" step="0.01" style="width:80px"></td>
          <td><input type="number" value="${l.tva_pct||0}" class="f_tva" step="0.01" style="width:60px"></td>
          <td><button type="button" class="btn icon-btn" onclick="removeLigne(this)"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></td>`;
    } else {
        tr.innerHTML = `
          <td class="row-drag">⋮⋮</td>
          <td><input type="text" value="${esc(l.code)}" class="f_code" placeholder="CODE" style="width:80px"></td>
          <td><input type="text" value="${esc(l.designation)}" class="f_desig" placeholder="Désignation"></td>
          <td><textarea class="f_desc" placeholder="Détail...">${esc(l.description||'')}</textarea></td>
          <td><select class="f_unite">${uniteOpts(l.unite)}</select></td>
          <td><input type="number" value="${l.prix_ht||0}" class="f_prix" step="0.01" style="width:80px"></td>
          <td><input type="number" value="${l.tva_pct||20}" class="f_tva" step="0.01" style="width:55px"></td>
          ${inclus}
          ${oblig}
          <td><button type="button" class="btn icon-btn" onclick="removeLigne(this)"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></td>`;
    }
    return tr;
}

function uniteOpts(sel) {
    return [['annuel','Annuel'],['par_lot','Par lot'],['par_acte','Par acte'],['par_heure','Par heure'],['forfait','Forfait'],['pourcentage','% travaux']]
        .map(([v,l])=>`<option value="${v}" ${sel===v?'selected':''}>${l}</option>`).join('');
}

function addLigne() {
    const cat = {'base':'forfait_base','part':'prestation_particuliere','rem':'remise'}[currentLignesTab];
    const tbody = document.getElementById('body_'+currentLignesTab);
    tbody.appendChild(buildLigneRow({code:'',designation:'',description:'',unite:'par_acte',prix_ht:0,tva_pct:20,inclus_forfait:0,obligatoire:0}, cat));
}

function removeLigne(btn) { btn.closest('tr').remove(); }

function loadAlurPresets() {
    if (!confirm('Charger les préréglages ALUR ? Les lignes existantes seront conservées.')) return;
    ALUR_PRESETS.forEach(p => {
        const cat = p.categorie;
        const tid = {'forfait_base':'base','prestation_particuliere':'part','remise':'rem'}[cat];
        document.getElementById('body_'+tid).appendChild(buildLigneRow(p, cat));
    });
    toast('Préréglages ALUR chargés');
}

// ── Collecte des lignes depuis le DOM ─────────────────────────────────────────
function collectLignes() {
    const lignes = [];
    [['base','forfait_base'],['part','prestation_particuliere'],['rem','remise']].forEach(([tid,cat]) => {
        document.querySelectorAll('#body_'+tid+' tr').forEach(tr => {
            const l = {
                categorie: cat,
                code:        tr.querySelector('.f_code')?.value   || '',
                designation: tr.querySelector('.f_desig')?.value  || '',
                description: tr.querySelector('.f_desc')?.value   || '',
                unite:       tr.querySelector('.f_unite')?.value  || 'forfait',
                prix_ht:     tr.querySelector('.f_prix')?.value   || '0',
                tva_pct:     tr.querySelector('.f_tva')?.value    || '20',
                inclus_forfait: tr.querySelector('.cb_inclus')?.checked ? 1 : 0,
                obligatoire:    tr.querySelector('.cb_oblig')?.checked  ? 1 : 0,
            };
            if (l.designation) lignes.push(l);
        });
    });
    return lignes;
}

// ── Sauvegarder ───────────────────────────────────────────────────────────────
async function saveTarif() {
    const fd = new FormData();
    fd.append('ajax_save_tarif', '1');
    fd.append('tarif_id',        document.getElementById('edit_tarif_id').value);
    fd.append('nom',             document.getElementById('ep_nom').value);
    fd.append('niveau',          document.getElementById('ep_niveau').value);
    fd.append('id_etablissement',document.getElementById('ep_etab').value);
    fd.append('id_immeuble',     document.getElementById('ep_imm').value);
    if (document.getElementById('ep_defaut').checked) fd.append('est_defaut','1');
    fd.append('lignes_json', JSON.stringify(collectLignes()));

    const r = await fetch(location.href, {method:'POST',body:fd});
    const d = await r.json();
    if (d.ok) { toast('✓ ' + d.msg); setTimeout(()=>location.reload(), 1000); }
    else toast('Erreur : ' + d.msg, true);
}

async function dupliquer(id) {
    const fd = new FormData(); fd.append('ajax_dupliquer','1'); fd.append('tarif_id',id);
    const r = await fetch(location.href,{method:'POST',body:fd});
    const d = await r.json();
    if (d.ok) { toast('✓ '+d.msg); setTimeout(()=>location.reload(),800); }
    else toast('Erreur : '+d.msg,true);
}

async function supprimer(id, nom) {
    if (!confirm(`Supprimer la grille "${nom}" et toutes ses lignes ?`)) return;
    const fd = new FormData(); fd.append('ajax_delete_tarif','1'); fd.append('tarif_id',id);
    const r = await fetch(location.href,{method:'POST',body:fd});
    const d = await r.json();
    if (d.ok) { toast('Grille supprimée'); setTimeout(()=>location.reload(),600); }
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg, err=false) {
    const el = document.getElementById('toast_msg');
    el.textContent = msg;
    el.className = 'toast' + (err?' err':'');
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 3000);
}
</script>
EXTRAJS;

$layout_extra_js = str_replace('__ALUR_PRESETS__', $_alur_presets_json, $layout_extra_js);

require_once __DIR__ . '/inc/layout_maboximmo.php';
