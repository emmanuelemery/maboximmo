<?php
// transaction_doc_preview.php — Aperçu HTML des documents types transaction (hors mandats,
// qui ont leur page dédiée). Champs éditables à droite (mise à jour LIVE du document) + impression.
// Groupe 1 : bon_visite, avenant_mandat. ?id_dossier=ID&modele=<key>
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_login();

$idDossier = (int)($_GET['id_dossier'] ?? 0);
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { http_response_code(404); exit('Dossier introuvable.'); }
$idBien = (int)$dossier['id_bien'];

$DOCS = [
    'bon_visite'     => 'BON DE VISITE',
    'avenant_mandat' => 'AVENANT AU MANDAT DE VENTE',
];
$modele = (string)($_GET['modele'] ?? '');
if (!isset($DOCS[$modele])) { http_response_code(404); exit('Modèle inconnu ou non disponible dans ce groupe.'); }

// Données dossier
$mandat = null;
if (!empty($dossier['id_mandat'])) {
    $stM = $pdo->prepare("SELECT * FROM mandats WHERE id = ? LIMIT 1"); $stM->execute([(int)$dossier['id_mandat']]);
    $mandat = $stM->fetch(PDO::FETCH_ASSOC) ?: null;
}
$lots = dv_lots($pdo, $idDossier);
$vendeurs = [];
foreach (dv_acteurs($pdo, $idDossier) as $a) {
    if (in_array($a['role_code'], ['vendeur','prospect_vendeur'], true))
        $vendeurs[] = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '').' '.($a['nom'] ?? '')));
}
// Adresse(s) des lots
$adrLots = [];
foreach ($lots as $l) {
    $lib = $l['reference_bien'] ?: ('Bien #' . (int)$l['id_bien']);
    $adr = trim(((string)($l['lot_adresse'] ?? '')).' '.((string)($l['lot_cp'] ?? '')).' '.((string)($l['lot_ville'] ?? '')));
    $adrLots[] = trim($lib . ' — ' . $adr, ' —');
}
$adrConcat = implode(' ; ', $adrLots);
// User en charge (accompagnateur par défaut)
$userNom = '';
if (!empty($dossier['id_user'])) {
    $stU = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) FROM users WHERE id=? LIMIT 1");
    $stU->execute([(int)$dossier['id_user']]); $userNom = trim((string)$stU->fetchColumn());
}
$refDossier = (string)($dossier['reference'] ?? '') ?: ('#' . $idDossier);
$numMandat  = (string)($mandat['numero_mandat'] ?? '');
$vendeurNom = $vendeurs ? implode(', ', $vendeurs) : '';
$today = date('d/m/Y');

// En-tête agence (mentions légales communes Régie Emery)
$AG_HEAD = '<strong>REGIE EMERY</strong><br>19 Boulevard Yves Farge — 69007 LYON · 04.81.07.07.00<br>contact@regie-emery.com · www.regie-emery.fr';
$AG_LEGAL = 'Adhérente de la caisse de Garantie <strong>GALIAN</strong> (89 rue de la Boétie, 75008 PARIS) sous le n° <strong>A 01913560</strong>, '
  . 'garantie pour 120 000 €. Assurance RC professionnelle <strong>MMA ENTREPRISE</strong> n° de police <strong>120 137 405</strong>. '
  . 'Carte « Non-détention de fonds » — l\'Agence ne pouvant recevoir ni détenir d\'autres fonds, effets ou valeurs que ceux représentatifs de sa rémunération. '
  . 'Immatriculée à l\'ORIAS sous le n° <strong>23000252</strong>. Adhérent FNAIM, titre d\'AGENT IMMOBILIER, activité régie par la loi n° 70-9 du 2 janvier 1970 (loi Hoguet) et son décret n° 72-678 du 20 juillet 1972.';

$pageTitle = $DOCS[$modele] . ' · ' . $refDossier;
$bind = static fn($id, $val) => '<span class="fill" id="' . $id . '">' . htmlspecialchars($val !== '' ? $val : '…', ENT_QUOTES, 'UTF-8') . '</span>';
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><title><?= h($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *{box-sizing:border-box;} body{font-family:'Times New Roman',Georgia,serif;color:#111;background:#e9edf2;margin:0;padding:22px;}
  .layout{max-width:1180px;margin:0 auto;display:flex;gap:18px;align-items:flex-start;}
  .doc{flex:1;background:#fff;padding:46px 54px;box-shadow:0 4px 24px rgba(0,0,0,.12);position:relative;}
  .ag-head{font-size:12px;line-height:1.4;color:#243B5C;margin-bottom:14px;}
  h1{text-align:center;font-size:19px;letter-spacing:.5px;margin:14px 0 4px;}
  .sub{text-align:center;font-size:12px;color:#555;margin-bottom:16px;}
  h2{font-size:13px;background:#243B5C;color:#fff;padding:4px 9px;margin:18px 0 7px;border-radius:3px;}
  p,li,td{font-size:12.5px;line-height:1.5;text-align:justify;}
  .fill{background:#fff7d6;padding:0 4px;font-weight:bold;border-bottom:1px solid #e3c84d;}
  .legal{font-size:11px;color:#444;}
  .sign{margin-top:34px;display:flex;justify-content:space-between;gap:40px;}
  .sign div{flex:1;border:1px solid #999;border-radius:6px;min-height:90px;padding:6px 8px;font-size:11px;color:#555;}
  .toolbar{max-width:1180px;margin:0 auto 14px;display:flex;gap:10px;}
  .toolbar a,.toolbar button{font-family:system-ui,sans-serif;font-size:13px;font-weight:700;border-radius:8px;padding:9px 16px;border:none;cursor:pointer;text-decoration:none;}
  .btn-print{background:#243B5C;color:#fff;} .btn-back{background:#e2e8f0;color:#334155;}
  .edit-panel{width:300px;flex-shrink:0;position:sticky;top:14px;font-family:system-ui,sans-serif;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:16px;}
  .edit-panel h3{font-size:14px;margin:0 0 4px;color:#243B5C;}
  .edit-panel .hint{font-size:11px;color:#94a3b8;margin:0 0 12px;}
  .ep-f{margin-bottom:11px;} .ep-f label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;}
  .ep-f input,.ep-f textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;font-family:inherit;}
  @media print{.toolbar,.edit-panel{display:none !important;} body{background:#fff;padding:0;} .layout{display:block;} .doc{box-shadow:none;padding:24px;}}
  @media(max-width:900px){.layout{flex-direction:column;} .edit-panel{width:100%;position:static;}}
</style></head>
<body>
<div class="toolbar">
  <a class="btn-back" href="<?= h(app_url('/transaction_dossier.php?id_dossier=' . $idDossier)) ?>">← Retour dossier</a>
  <button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
</div>
<div class="layout">
<div class="doc">
  <div class="ag-head"><?= $AG_HEAD ?></div>

<?php if ($modele === 'bon_visite'): ?>
  <h1>BON DE VISITE N° <?= $bind('f-num','') ?></h1>
  <div class="sub">Indication de bien(s) à vendre</div>
  <h2>L'Agence</h2>
  <p class="legal"><?= $AG_LEGAL ?></p>
  <h2>Déclarations du visiteur</h2>
  <p>Je soussigné(e) / Nous soussigné(e)s <?= $bind('f-visiteur', '') ?>, demeurant <?= $bind('f-visiteur-adr','') ?>, agissant tant en mon nom personnel que pour le compte de toute autre personne physique ou morale que nous jugerions bon de nous substituer.</p>
  <p>Reconnais m'être présenté ce jour à l'agence, avoir demandé et reçu de celle-ci les renseignements, adresses et conditions de vente des biens ci-dessous désignés. Ces biens, dont je n'avais aucune connaissance auparavant, m'ont été présentés pour la première fois et uniquement par l'Agence. Je sais que ces indications ont été fournies selon les dires des propriétaires et qu'elles sont confidentielles ; je m'engage à les garder secrètes et à ne pas me présenter chez les vendeurs sans être accompagné d'un représentant de l'Agence ou, à défaut, sans communiquer auxdits vendeurs le présent bon de visite. Je m'interdis de traiter ou négocier directement la vente de ces biens avec leur propriétaire vendeur.</p>
  <p>Je reconnais qu'en application de l'article L.125-25 du code de l'environnement, l'état des risques ainsi que, le cas échéant et en application de l'article L.271-4 du code de la construction et de l'habitation, l'audit énergétique relatif(s) au(x) bien(s) désigné(s) ci-dessous m'a (ont) été remis lors de la première visite.</p>
  <h2>Affaire(s) signalée(s)</h2>
  <table>
    <tr><td class="k" style="width:34%;color:#555;">Numéro du mandat</td><td><?= $bind('f-mandat', $numMandat) ?></td></tr>
    <tr><td class="k" style="color:#555;">Adresse du bien</td><td><?= $bind('f-adresse', $adrConcat) ?></td></tr>
    <tr><td class="k" style="color:#555;">Précisions</td><td><?= $bind('f-precisions','') ?></td></tr>
    <tr><td class="k" style="color:#555;">Accompagnateur</td><td><?= $bind('f-accompagnateur', $userNom) ?></td></tr>
    <tr><td class="k" style="color:#555;">Date de visite</td><td><?= $bind('f-date', $today) ?></td></tr>
  </table>
  <h2>Protection des données personnelles</h2>
  <p class="legal">Vos données personnelles collectées dans ce document font l'objet d'un traitement nécessaire pour faire droit à votre demande de visite et en exécution du mandat. Elles peuvent être communiquées au vendeur et utilisées dans le cadre de la lutte contre le blanchiment des capitaux et le financement du terrorisme. Droit d'accès, rectification, suppression, opposition et portabilité auprès de contact@regie-emery.com. Réclamation possible auprès de la CNIL (www.cnil.fr).</p>
  <div class="sign">
    <div><strong>Le visiteur</strong><br>Lu et approuvé<br><br>Fait à __________ le __________</div>
    <div><strong>Pour l'Agence</strong> — REGIE EMERY<br><br><br></div>
  </div>

<?php elseif ($modele === 'avenant_mandat'): ?>
  <h1>AVENANT N° <?= $bind('f-num','') ?> AU MANDAT DE VENTE N° <?= $bind('f-mandat', $numMandat) ?></h1>
  <h2>Entre les soussignés</h2>
  <p><strong>Le Mandant :</strong> <?= $bind('f-mandant', $vendeurNom) ?></p>
  <p><strong>Le Mandataire :</strong> REGIE EMERY</p>
  <p class="legal"><?= $AG_LEGAL ?></p>
  <h2>Il est convenu que</h2>
  <p><span class="fill" id="f-objet" style="display:block;min-height:48px;white-space:pre-wrap;">…</span></p>
  <p>TOUTES LES AUTRES CLAUSES ET CONDITIONS DU MANDAT RESTENT INCHANGÉES.</p>
  <p>Le présent avenant entre en vigueur le <?= $bind('f-effet', $today) ?>.</p>
  <div class="sign">
    <div><strong>Le Mandant</strong><br>Lu et approuvé<br><br>Fait à __________ le __________</div>
    <div><strong>Le Mandataire</strong> — REGIE EMERY<br><br><br></div>
  </div>
<?php endif; ?>
</div><!-- /doc -->

<aside class="edit-panel">
  <h3>✏️ Champs du document</h3>
  <p class="hint">Saisis ici — le document à gauche se met à jour en direct. Puis « Imprimer / PDF ».</p>
<?php if ($modele === 'bon_visite'): ?>
  <div class="ep-f"><label>N° du bon</label><input data-target="f-num" type="text"></div>
  <div class="ep-f"><label>Visiteur (nom)</label><input data-target="f-visiteur" type="text"></div>
  <div class="ep-f"><label>Visiteur — demeurant</label><input data-target="f-visiteur-adr" type="text"></div>
  <div class="ep-f"><label>N° de mandat</label><input data-target="f-mandat" type="text" value="<?= h($numMandat) ?>"></div>
  <div class="ep-f"><label>Adresse du bien</label><input data-target="f-adresse" type="text" value="<?= h($adrConcat) ?>"></div>
  <div class="ep-f"><label>Précisions</label><input data-target="f-precisions" type="text"></div>
  <div class="ep-f"><label>Accompagnateur</label><input data-target="f-accompagnateur" type="text" value="<?= h($userNom) ?>"></div>
  <div class="ep-f"><label>Date de visite</label><input data-target="f-date" type="text" value="<?= h($today) ?>"></div>
<?php elseif ($modele === 'avenant_mandat'): ?>
  <div class="ep-f"><label>N° de l'avenant</label><input data-target="f-num" type="text"></div>
  <div class="ep-f"><label>N° du mandat</label><input data-target="f-mandat" type="text" value="<?= h($numMandat) ?>"></div>
  <div class="ep-f"><label>Mandant</label><input data-target="f-mandant" type="text" value="<?= h($vendeurNom) ?>"></div>
  <div class="ep-f"><label>Objet de l'avenant (modification)</label><textarea data-target="f-objet" rows="4" placeholder="Ex : le prix de vente est ramené à 290 000 € ; la durée est prorogée de 3 mois…"></textarea></div>
  <div class="ep-f"><label>Entrée en vigueur le</label><input data-target="f-effet" type="text" value="<?= h($today) ?>"></div>
<?php endif; ?>
</aside>
</div>

<script>
(function(){
  document.querySelectorAll('.edit-panel [data-target]').forEach(function(inp){
    var span = document.getElementById(inp.dataset.target);
    if(!span) return;
    // initialise le span avec la valeur préremplie de l'input
    if(inp.value) span.textContent = inp.value;
    var sync = function(){ span.textContent = inp.value || '…'; };
    inp.addEventListener('input', sync);
  });
})();
</script>
</body></html>
