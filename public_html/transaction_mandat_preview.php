<?php
// transaction_mandat_preview.php — Mandat de vente FNAIM (Régie Emery) REMPLI, imprimable.
// Reproduit fidèlement le modèle FNAIM (mandat_simple.pdf) + injection auto des variables
// depuis le dossier (zéro ressaisie). ?id_dossier=ID
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_once __DIR__ . '/inc/mandat_clauses.php';
require_login();

$idDossier = (int)($_GET['id_dossier'] ?? 0);
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { http_response_code(404); exit('Dossier introuvable.'); }
$idBien = (int)$dossier['id_bien'];

$mandat = null;
if (!empty($dossier['id_mandat'])) {
    $stM = $pdo->prepare("SELECT * FROM mandats WHERE id = ? LIMIT 1");
    $stM->execute([(int)$dossier['id_mandat']]);
    $mandat = $stM->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Modèle choisi (mandat_simple par défaut). Le titre/variante en découle.
$modele = (string)($_GET['modele'] ?? 'mandat_simple');
$modeleLabels = ['mandat_simple'=>'SANS EXCLUSIVITÉ', 'mandat_exclusif'=>'EXCLUSIF', 'mandat_succes'=>'« SUCCÈS »'];
if (!isset($modeleLabels[$modele])) $modele = 'mandat_simple';
$exclusif = ($modele === 'mandat_exclusif') || ($modele === 'mandat_succes') || !empty($mandat['exclusif']);
$modeleTitre = $modeleLabels[$modele];
$isExclu  = ($modele === 'mandat_exclusif' || $modele === 'mandat_succes'); // clauses d'exclusivité + reporting renforcé
$isSucces = ($modele === 'mandat_succes');                                  // autorisations supplémentaires (SSP, préemption, séquestre)

$lots   = dv_lots($pdo, $idDossier);
$totaux = dv_totaux($pdo, $idDossier);

// Mandant(s) : vendeurs + adresse + email depuis tiers
$vendeurs = [];
$vendeurEmails = [];
foreach (dv_acteurs($pdo, $idDossier) as $a) {
    if (!in_array($a['role_code'], ['vendeur','prospect_vendeur'], true)) continue;
    $nom = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')));
    $adr = ''; $em = '';
    try {
        $stA = $pdo->prepare("SELECT CONCAT_WS(', ', NULLIF(adresse_1,''), NULLIF(CONCAT_WS(' ',code_postal,ville),'')) AS adr, email FROM tiers WHERE id = ? LIMIT 1");
        $stA->execute([(int)$a['id_tiers']]); $r = $stA->fetch(PDO::FETCH_ASSOC) ?: [];
        $adr = (string)($r['adr'] ?? ''); $em = trim((string)($r['email'] ?? ''));
    } catch (Throwable $e) {}
    $vendeurs[] = ['nom' => $nom, 'adr' => $adr];
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $vendeurEmails[] = $em;
}
$vendeurEmails = array_values(array_unique($vendeurEmails));

// Durée (mois) si dates connues
$duree = ''; $dureeMoisCur = 3;
if (!empty($mandat['date_debut']) && !empty($mandat['date_fin'])) {
    $d1 = new DateTime($mandat['date_debut']); $d2 = new DateTime($mandat['date_fin']);
    $mm = ($d2->format('Y') - $d1->format('Y')) * 12 + ($d2->format('n') - $d1->format('n'));
    if ($mm > 0) { $duree = $mm . ' mois'; $dureeMoisCur = $mm; }
}

$fmtPrix = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '…';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '…';
$refDossier = (string)($dossier['reference'] ?? '') ?: ('#' . $idDossier);
$signe = !empty($mandat['date_signature']);
$chargeLbl = ['vendeur'=>'VENDEUR (mandant)','acquereur'=>'ACQUEREUR','partage'=>'partage vendeur/acquéreur'][$mandat['honoraires_charge'] ?? ''] ?? '…';

// ── Modes : rendu "corps seul" (pour génération PDF TCPDF) · filigrane forcé · prix net vendeur ──
$renderBody  = (($_GET['render'] ?? '') === 'body');           // n'émet que le <style> + le .doc
// Filigrane PROJET = UNIQUEMENT à la demande (envoi pour validation). Jamais sur le document
// provisoire ni à l'impression. On l'active seulement si ?projet=1.
$showProjet  = (($_GET['projet'] ?? '') === '1');
// Net vendeur = AUTO-DÉDUIT : si honoraires à la charge du vendeur → prix total − honoraires,
// sinon (acquéreur) le vendeur perçoit le prix total. (Plus de saisie manuelle.)
$prixTotalNum = (float)($totaux['prix_total'] ?? 0);
$honosNum     = (float)($mandat['honoraires'] ?? 0);
$netVendeur   = $prixTotalNum > 0
    ? ((($mandat['honoraires_charge'] ?? '') === 'vendeur') ? max(0, $prixTotalNum - $honosNum) : $prixTotalNum)
    : null;
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><title>Mandat de vente — <?= h($refDossier) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *{box-sizing:border-box;} body{font-family:'Times New Roman',Georgia,serif;color:#111;background:#e9edf2;margin:0;padding:22px;}
  .doc{max-width:800px;margin:0 auto;background:#fff;padding:46px 54px;box-shadow:0 4px 24px rgba(0,0,0,.12);position:relative;}
  .ag-head{font-size:12px;line-height:1.4;color:#243B5C;margin-bottom:18px;}
  .ag-head strong{font-size:14px;}
  h1{text-align:center;font-size:19px;letter-spacing:.5px;margin:14px 0 2px;}
  .law{text-align:center;font-size:11px;color:#555;font-style:italic;margin-bottom:16px;}
  h2{font-size:13px;background:#243B5C;color:#fff;padding:4px 9px;margin:18px 0 7px;border-radius:3px;}
  p,li,td{font-size:12.5px;line-height:1.5;text-align:justify;}
  .fill{background:#fff7d6;padding:0 4px;font-weight:bold;border-bottom:1px solid #e3c84d;}
  table{width:100%;border-collapse:collapse;margin:4px 0;} td{padding:3px 6px;vertical-align:top;} .k{color:#555;width:34%;}
  .lot{border:1px solid #ddd;border-radius:5px;padding:7px 10px;margin:5px 0;font-size:12.5px;}
  ul{margin:5px 0 5px 4px;padding-left:18px;} li{margin-bottom:4px;}
  .caps{text-transform:uppercase;font-weight:bold;font-size:11.5px;}
  .sign{margin-top:34px;display:flex;justify-content:space-between;gap:40px;}
  .sign div{flex:1;border:1px solid #999;border-radius:6px;min-height:90px;padding:6px 8px;font-size:11px;color:#555;}
  .toolbar{max-width:800px;margin:0 auto 14px;display:flex;gap:10px;}
  .toolbar a,.toolbar button{font-family:system-ui,sans-serif;font-size:13px;font-weight:700;border-radius:8px;padding:9px 16px;border:none;cursor:pointer;text-decoration:none;}
  .btn-print{background:#243B5C;color:#fff;} .btn-back{background:#e2e8f0;color:#334155;}
  .btn-close{background:#fee2e2;color:#b91c1c;}
  .draft{position:fixed;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-28deg);font-size:130px;color:rgba(200,0,0,.06);font-weight:900;pointer-events:none;z-index:5;}
  .layout{max-width:1180px;margin:0 auto;display:flex;gap:18px;align-items:flex-start;}
  .layout .doc{margin:0;flex:1;}
  .edit-panel{width:300px;flex-shrink:0;position:sticky;top:14px;font-family:system-ui,sans-serif;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:16px;}
  .edit-panel h3{font-size:14px;margin:0 0 4px;color:#243B5C;}
  .edit-panel .hint{font-size:11px;color:#94a3b8;margin:0 0 12px;}
  .ep-f{margin-bottom:11px;} .ep-f label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;}
  .ep-f input,.ep-f select{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;}
  .ep-pills{display:flex;gap:5px;flex-wrap:wrap;}
  .ep-pill{flex:1;text-align:center;padding:7px 4px;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#475569;}
  .ep-pill.on{background:#eef5fc;border-color:#0f6cbd;color:#0f6cbd;}
  .ep-save{width:100%;background:#15803d;color:#fff;border:none;border-radius:9px;padding:11px;font-size:14px;font-weight:800;cursor:pointer;margin-top:4px;}
  .ep-ro{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:7px 10px;font-size:13px;font-weight:700;color:#334155;}
  @media print{.toolbar,.edit-panel{display:none !important;} body{background:#fff;padding:0;} .layout{display:block;} .doc{box-shadow:none;padding:24px;}}
  @media(max-width:900px){.layout{flex-direction:column;} .edit-panel{width:100%;position:static;}}
</style></head>
<body>
<?php if (!$renderBody): ?>
<div class="toolbar">
  <a class="btn-back" href="<?= h(app_url('/transaction_dossier.php?id_dossier=' . $idDossier)) ?>">← Retour au dossier de vente</a>
  <button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
  <button type="button" class="btn-close" onclick="closePreview()">✖ Fermer la fenêtre</button>
</div>
<script>
function closePreview(){
  // Fenêtre ouverte par script (window.open) → on la ferme.
  window.close();
  // Repli : si elle ne s'est pas fermée (onglet ouvert directement), on revient au dossier.
  setTimeout(function(){
    if (!window.closed) {
      window.location.href = <?= json_encode(app_url('/transaction_dossier.php?id_dossier=' . $idDossier)) ?>;
    }
  }, 200);
}
</script>
<?php endif; /* !$renderBody */ ?>
<div class="layout">
<div class="doc">
  <?php if ($showProjet && !$renderBody): ?><div class="draft">PROJET</div><?php endif; ?>

  <div class="ag-head">
    <strong>REGIE EMERY</strong><br>
    19 Boulevard Yves Farge — 69007 LYON · 04.81.07.07.00<br>
    contact@regie-emery.com · www.regie-emery.fr
  </div>

  <h1>MANDAT DE VENTE <?= h($modeleTitre) ?> N° <span class="fill"><?= h($mandat['numero_mandat'] ?? '…') ?></span></h1>
  <?php if ($modele !== 'mandat_simple'): ?>
    <div class="law" style="color:#a11;">⚠ Variante « <?= h($modeleTitre) ?> » : clauses spécifiques en cours de transcription — base affichée = mandat sans exclusivité.</div>
  <?php endif; ?>
  <div class="law">(article 6 loi n° 70-9 du 2 janvier 1970 et articles 72 et suivants du décret n° 72-678 du 20 juillet 1972)</div>
  <p>Rémunération à la charge du <span class="fill"><?= h($chargeLbl) ?></span></p>

  <h2>Entre les soussignés</h2>
  <p><strong>Le Mandant</strong> — D'UNE PART :</p>
  <?php if ($vendeurs): foreach ($vendeurs as $v): ?>
    <p style="margin:2px 0;"><span class="fill"><?= h($v['nom']) ?></span><?= $v['adr'] ? ', demeurant ' . h($v['adr']) : '' ?></p>
  <?php endforeach; else: ?>
    <p><em>À compléter — ajoutez le vendeur dans le dossier.</em></p>
  <?php endif; ?>

  <p style="margin-top:10px;"><strong>Le Mandataire</strong> — D'AUTRE PART :</p>
  <p><strong>REGIE EMERY</strong>, titulaire de la carte professionnelle « Transaction sur immeubles et fonds de commerce ».
  Adhérente de la caisse de Garantie <strong>GALIAN</strong> (89 rue de la Boétie, 75008 PARIS) sous le n° <strong>A 01913560</strong>,
  garantie pour un montant de <strong>120 000 €</strong>. Titulaire d'une assurance en responsabilité civile professionnelle
  souscrite auprès de <strong>MMA ENTREPRISE</strong> sous le n° de police <strong>120 137 405</strong>.
  Cette carte porte la mention « Non-détention de fonds », l'Agence NE POUVANT NI RECEVOIR NI DÉTENIR D'AUTRES FONDS,
  EFFETS OU VALEURS QUE CEUX REPRÉSENTATIFS DE SA RÉMUNÉRATION. Immatriculée à l'ORIAS sous le n° <strong>23000252</strong>.
  Adhérent de la Fédération Nationale de l'Immobilier (FNAIM), titre professionnel d'AGENT IMMOBILIER, activité régie par la
  loi n° 70-9 du 2 janvier 1970 (« loi Hoguet ») et son décret n° 72-678 du 20 juillet 1972, soumis au code d'éthique et de
  déontologie de la FNAIM (décret n° 2015-1090 du 28 août 2015).</p>

  <p style="text-align:center;font-weight:bold;margin:14px 0;">IL A ÉTÉ CONVENU ET ARRÊTÉ CE QUI SUIT</p>

  <h2>Désignation et usage des biens</h2>
  <?php
  foreach ($lots as $l):
    $lib = $l['reference_bien'] ?: ($l['designation'] ?: ('Bien #' . (int)$l['id_bien']));
    $adr = trim(((string)($l['lot_adresse'] ?? '')) . ' ' . ((string)($l['lot_cp'] ?? '')) . ' ' . ((string)($l['lot_ville'] ?? '')));
    // Caractéristiques complètes du bien
    $stBb = $pdo->prepare("SELECT * FROM biens WHERE id = ? LIMIT 1");
    $stBb->execute([(int)$l['id_bien']]);
    $b = $stBb->fetch(PDO::FETCH_ASSOC) ?: [];
    $nz = static fn($v) => $v !== null && $v !== '' && (float)$v != 0;
    // Caractéristiques principales
    $carac = [];
    if ($nz($b['surface_habitable'] ?? null)) $carac[] = 'Surface habitable ' . rtrim(rtrim(number_format((float)$b['surface_habitable'],2,',',' '),'0'),',') . ' m²';
    if ($nz($b['surface_carrez'] ?? null))    $carac[] = 'Loi Carrez ' . rtrim(rtrim(number_format((float)$b['surface_carrez'],2,',',' '),'0'),',') . ' m²';
    if ($nz($b['nb_pieces'] ?? null))         $carac[] = (int)$b['nb_pieces'] . ' pièce(s)';
    if ($nz($b['nb_chambres'] ?? null))       $carac[] = (int)$b['nb_chambres'] . ' chambre(s)';
    if ($nz($b['nb_salles_bain'] ?? null))    $carac[] = (int)$b['nb_salles_bain'] . ' salle(s) de bain';
    if ($nz($b['nb_salles_eau'] ?? null))     $carac[] = (int)$b['nb_salles_eau'] . " salle(s) d'eau";
    if ($nz($b['nb_wc'] ?? null))             $carac[] = (int)$b['nb_wc'] . ' WC';
    if (isset($b['etage']) && $b['etage'] !== null && $b['etage'] !== '') $carac[] = 'Étage ' . (int)$b['etage'] . (!empty($b['dernier_etage']) ? ' (dernier)' : '');
    if (!empty($b['ascenseur']))              $carac[] = 'Ascenseur';
    if ($nz($b['annee_construction'] ?? null))$carac[] = 'Construit en ' . (int)$b['annee_construction'];
    if (!empty($b['etat_bien']))              $carac[] = 'État : ' . $b['etat_bien'];
    if (!empty($b['exposition']))             $carac[] = 'Exposition ' . $b['exposition'];
    // Chauffage
    $chauf = trim(((string)($b['chauffage_type'] ?? '')) . ' ' . ((string)($b['chauffage_energie'] ?? '')));
    // Annexes (booléens + surfaces)
    $annexes = [];
    foreach (['balcon'=>'Balcon','terrasse'=>'Terrasse','jardin'=>'Jardin','cave'=>'Cave','garage'=>'Garage'] as $col=>$lbl) {
        if (!empty($b[$col])) { $sc='surface_'.$col; $annexes[] = $lbl . ($nz($b[$sc] ?? null) ? ' (' . rtrim(rtrim(number_format((float)$b[$sc],2,',',' '),'0'),',') . ' m²)' : ''); }
    }
    if ($nz($b['parking_nb'] ?? null)) $annexes[] = (int)$b['parking_nb'] . ' parking(s)';
    if ($nz($b['surface_terrain'] ?? null)) $annexes[] = 'Terrain ' . rtrim(rtrim(number_format((float)$b['surface_terrain'],2,',',' '),'0'),',') . ' m²';
    // Copropriété
    $copro = [];
    if ($nz($b['copro_nb_lots'] ?? null)) $copro[] = (int)$b['copro_nb_lots'] . ' lots';
    if ($nz($b['copro_quote_part_charges'] ?? null)) $copro[] = 'quote-part charges ' . number_format((float)$b['copro_quote_part_charges'],0,',',' ') . ' €';
    // DPE
    $dpe = [];
    if (!empty($b['dpe_classe'])) $dpe[] = 'DPE ' . strtoupper($b['dpe_classe']) . ($nz($b['dpe_valeur'] ?? null) ? ' (' . (int)$b['dpe_valeur'] . ' kWh/m²/an)' : '');
    if (!empty($b['ges_classe'])) $dpe[] = 'GES ' . strtoupper($b['ges_classe']);
    if (!empty($b['dpe_vierge'])) $dpe[] = 'DPE vierge';
    $descr = trim((string)($b['description'] ?? '')) ?: trim((string)($b['reprise_descriptif'] ?? ''));
  ?>
    <div class="lot">
      <div style="font-size:13.5px;"><span class="fill"><?= h($lib) ?></span><?= $l['type_libelle'] ? ' — ' . h($l['type_libelle']) : '' ?><?= !empty($b['usage_bien']) ? ' · usage ' . h($b['usage_bien']) : '' ?></div>
      <?php if ($adr): ?><div><?= h($adr) ?></div><?php endif; ?>
      <?php if ($carac): ?><div style="margin-top:4px;"><strong>Caractéristiques :</strong> <?= h(implode(' · ', $carac)) ?></div><?php endif; ?>
      <?php if ($chauf !== ''): ?><div><strong>Chauffage :</strong> <?= h($chauf) ?></div><?php endif; ?>
      <?php if ($annexes): ?><div><strong>Annexes :</strong> <?= h(implode(' · ', $annexes)) ?></div><?php endif; ?>
      <?php if ($copro): ?><div><strong>Copropriété :</strong> <?= h(implode(' · ', $copro)) ?></div><?php endif; ?>
      <?php if ($dpe): ?><div><strong>Performance énergétique :</strong> <?= h(implode(' · ', $dpe)) ?></div><?php endif; ?>
      <?php if ($descr !== ''): ?><div style="margin-top:4px;"><strong>Descriptif :</strong> <?= nl2br(h($descr)) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <h2>Prix de vente</h2>
  <p>Les biens objet du présent mandat devront être proposés au prix de <span class="fill"><?= h($fmtPrix($totaux['prix_total'])) ?></span><?php if ($netVendeur !== null): ?>, soit un prix net vendeur de <span class="fill"><?= h($fmtPrix($netVendeur)) ?></span><?php endif; ?>.
  Ce prix a été fixé par le MANDANT après avoir pris connaissance de l'évaluation faite par le MANDATAIRE, au regard de l'état
  actuel du marché immobilier local. Ce prix est payable au plus tard le jour de la signature de l'acte de vente définitif.</p>

  <h2>Honoraires du mandataire</h2>
  <p>Honoraires : <span class="fill"><?= h($fmtPrix($mandat['honoraires'] ?? null)) ?></span>, à la charge du <span class="fill"><?= h($chargeLbl) ?></span>.
  Le taux de TVA en vigueur est susceptible de modification conformément à la règlementation fiscale. La rémunération du
  MANDATAIRE sera exigible le jour où l'opération sera effectivement conclue et réitérée par acte authentique.</p>

  <h2>Durée du mandat</h2>
  <p>Le présent mandat prendra effet le jour de sa signature par l'ensemble des parties (<span class="fill"><?= h($fmtDate($mandat['date_debut'] ?? null)) ?></span>),
  pour une durée de <span class="fill"><?= h($duree ?: '…') ?></span>, au terme de laquelle il prendra automatiquement fin
  (échéance : <span class="fill"><?= h($fmtDate($mandat['date_fin'] ?? null)) ?></span>). Passé un délai irrévocable à compter de sa signature,
  il pourra être dénoncé à tout moment par chacune des parties avec un préavis de quinze jours, par lettre recommandée AR
  (art. 78, 2e alinéa, du décret n° 72-678 du 20 juillet 1972).
  <?php if ($isExclu): ?> Il est ici précisé que la clause d'exclusivité ne pourra être dénoncée que dans les mêmes conditions que le mandat lui-même.<?php endif; ?></p>

  <?= mandat_clauses_obligations($modele) ?>

  <h2>Protection des données personnelles du Mandant</h2>
  <p>Vos données personnelles collectées dans le cadre du présent mandat font l'objet d'un traitement nécessaire à son exécution. Elles sont susceptibles d'être utilisées dans le cadre de l'application de règlementations comme celle relative à la lutte contre le blanchiment des capitaux et le financement du terrorisme. Vos données personnelles sont conservées pendant toute la durée de l'exécution du présent mandat, augmentée des délais légaux de prescription applicable.</p>
  <p>Pour la réalisation de la finalité des présentes, vos données sont, le cas échéant, susceptibles d'être transmises, notamment :</p>
  <ul>
    <li>au(x) notaire(s) ;</li>
    <li>au(x) diagnostiqueur(s) chargé(s) des diagnostics obligatoires ;</li>
    <li>au(x) site(s) d'annonces en ligne en cas de géolocalisation ;</li>
    <li>au syndic de la copropriété pour la délivrance des documents et informations en application de l'article L. 721-2 du CCH ;</li>
    <li>aux prestataires de la signature électronique et de la lettre recommandée électronique ;</li>
    <li>au(x) agent(s) immobilier(s) en cas de délégation de mandat ;</li>
    <li>à notre assureur RCP, au commissaire de justice et à l'avocat en cas de procédures.</li>
  </ul>
  <p>Il est précisé que dans le cadre de l'exécution de leurs prestations, les tiers limitativement énumérés ci-avant n'ont qu'un accès limité aux données et ont l'obligation de les utiliser en conformité avec les dispositions de la législation applicable en matière de protection des données personnelles. Conformément à la loi informatique et libertés, vous bénéficiez d'un droit d'accès, de rectification, de suppression, d'opposition et de portabilité de vos données en vous adressant à <strong>emmanuel.emery@regie-emery.com</strong> ou par courrier à l'adresse de l'Agence indiquée en tête des présentes. Toute réclamation pourra être introduite auprès de la Commission Nationale de l'Informatique et des Libertés (www.cnil.fr). Dans le cas où des coordonnées téléphoniques ont été recueillies, vous êtes informé(e)(s) de la faculté de vous inscrire sur la liste d'opposition au démarchage téléphonique prévue en faveur des consommateurs (article L. 223-1 du code de la consommation).</p>

  <h2>Engagement de non-discrimination</h2>
  <p>Il est ici rappelé que constitue une discrimination toute distinction opérée entre les personnes en raison de leurs origine, sexe, situation de famille, grossesse, apparence physique, particulière vulnérabilité résultant de leur situation économique (apparente ou connue de son auteur), patronyme, lieu de résidence, état de santé, perte d'autonomie, handicap, caractéristiques génétiques, mœurs, orientation sexuelle, identité de genre, âge, opinions politiques, activités syndicales, qualité de lanceur d'alerte (ou de facilitateur, ou de personne en lien avec un lanceur d'alerte au sens des articles 6 et 6-1 de la loi n° 2016-1691 du 9 décembre 2016), capacité à s'exprimer dans une langue autre que le français, appartenance ou non-appartenance, vraie ou supposée, à une ethnie, une nation, une prétendue race ou une religion déterminée.</p>
  <p>Le mandataire informe le mandant que toute discrimination commise à l'égard d'une personne est punie de trois ans d'emprisonnement et de 45 000 € d'amende (article 225-2 du code pénal). En conséquence, les parties prennent l'engagement exprès de n'opposer, à un candidat à l'acquisition des biens ci-dessus désignés, aucun refus fondé sur un motif discriminatoire au sens de l'article 225-1 du code pénal. Par ailleurs, le mandant s'interdit expressément de donner au mandataire des directives et consignes, verbales ou écrites, tendant à refuser la vente pour des motifs discriminatoires au sens de l'article 225-1 du code pénal.</p>

  <h2>Médiation de la consommation — Règlement amiable des litiges</h2>
  <p>En cas de litige entre le mandant consommateur et le mandataire, le mandant peut recourir gratuitement au médiateur de la consommation dont relève le mandataire, en vue de la résolution amiable du différend, conformément aux articles L.612-1 et suivants du code de la consommation.</p>

  <?php $signLe = !empty($mandat['date_signature']) ? $fmtDate($mandat['date_signature']) : '__________'; ?>
  <div class="sign">
    <div><strong>Le Mandant (vendeur)</strong><br>Lu et approuvé, bon pour mandat<br><br>Fait à <span class="fill">LYON</span> le <span class="fill"><?= h($signLe) ?></span></div>
    <div><strong>Le Mandataire</strong> — REGIE EMERY<br><br><br>Fait à <span class="fill">LYON</span> le <span class="fill"><?= h($signLe) ?></span></div>
  </div>
</div><!-- /doc -->
<?php if ($renderBody): ?>
</div><!-- /layout (rendu corps seul pour PDF) -->
</body></html>
<?php return; endif; ?>

<aside class="edit-panel">
  <h3>✏️ Champs du mandat</h3>
  <p class="hint">Modifiez ici — le document à gauche se met à jour après enregistrement. Le corps du texte n'est pas éditable directement.</p>
  <div class="ep-f"><label>N° de mandat</label><input type="text" id="ep-numero" value="<?= h($mandat['numero_mandat'] ?? '') ?>"></div>
  <div class="ep-f"><label>Type de mandat</label>
    <div class="ep-pills" id="ep-excl">
      <div class="ep-pill <?= $exclusif ? '' : 'on' ?>" data-excl="0">Simple</div>
      <div class="ep-pill <?= $exclusif ? 'on' : '' ?>" data-excl="1">Exclusif</div>
    </div>
  </div>
  <div class="ep-f"><label>Honoraires</label>
    <div style="display:flex;gap:6px;">
      <input type="text" id="ep-honos" inputmode="numeric" placeholder="Montant €" style="flex:2;" value="<?= $mandat['honoraires'] !== null ? (int)$mandat['honoraires'] : '' ?>">
      <input type="text" id="ep-honos-pct" inputmode="decimal" placeholder="%" style="flex:1;">
    </div>
    <small style="color:#94a3b8;font-size:10px;">Base : <?= h($fmtPrix($totaux['prix_total'])) ?> — remplir l'un calcule l'autre.</small>
  </div>
  <div class="ep-f"><label>Honoraires à la charge de</label>
    <select id="ep-charge">
      <option value="vendeur"   <?= ($mandat['honoraires_charge'] ?? '')==='vendeur'?'selected':'' ?>>Vendeur</option>
      <option value="acquereur" <?= ($mandat['honoraires_charge'] ?? '')==='acquereur'?'selected':'' ?>>Acquéreur</option>
      <option value="partage"   <?= ($mandat['honoraires_charge'] ?? '')==='partage'?'selected':'' ?>>Partagé</option>
    </select>
  </div>
  <div class="ep-f"><label>Durée</label>
    <select id="ep-duree">
      <option value="3"  <?= $dureeMoisCur===3?'selected':'' ?>>3 mois</option>
      <option value="6"  <?= $dureeMoisCur===6?'selected':'' ?>>6 mois</option>
      <option value="12" <?= $dureeMoisCur===12?'selected':'' ?>>1 an</option>
    </select>
  </div>
  <div class="ep-f"><label>Prise d'effet</label><input type="date" id="ep-datedebut" value="<?= h($mandat['date_debut'] ?? date('Y-m-d')) ?>"></div>
  <div class="ep-f"><label>Date de signature (mandant + mandataire)</label><input type="date" id="ep-datesign" value="<?= h($mandat['date_signature'] ?? '') ?>"></div>
  <div class="ep-f"><label>Prix de vente total (depuis les lots)</label><div class="ep-ro"><?= h($fmtPrix($totaux['prix_total'])) ?></div></div>
  <div class="ep-f"><label>Prix net vendeur <span style="color:#9a9690;font-weight:400;">(auto)</span></label><div class="ep-ro"><?= $netVendeur !== null ? h($fmtPrix($netVendeur)) : '—' ?></div>
    <small style="color:#94a3b8;font-size:10px;">Déduit : charge vendeur → prix − honoraires ; charge acquéreur → = prix total.</small>
  </div>
  <button type="button" class="ep-save" onclick="epSave()">💾 Enregistrer les champs</button>
  <div id="ep-msg" style="font-size:11px;color:#94a3b8;margin-top:8px;text-align:center;"></div>

  <div class="ep-f" style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px;"><label style="color:#243B5C;font-weight:800;">Document & envoi</label></div>
  <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#7c3aed;margin:0 0 8px;cursor:pointer;">
    <input type="checkbox" id="m-projet"> Filigrane « PROJET » (envoi pour validation — décocher pour signature)
  </label>
  <button type="button" class="ep-save" style="background:#243B5C;margin-top:0;" onclick="mDownload()">🖨️ Télécharger / imprimer (sans filigrane)</button>
  <button type="button" class="ep-save" style="background:#0e7490;" onclick="mSaveGed(this)">📄 Enregistrer en GED</button>
  <button type="button" class="ep-save" style="background:#7c3aed;" onclick="mMail(this)">✉️ Composer le mail (joindre depuis la GED)</button>
  <div id="m-msg" style="font-size:11px;color:#94a3b8;margin-top:8px;text-align:center;"></div>
</aside>

</div><!-- /layout -->

<script>
(function(){
  // Honoraires : % ⇄ montant (base = prix total des lots)
  var PRIX = <?= (float)$totaux['prix_total'] ?>;
  var hM = document.getElementById('ep-honos'), hP = document.getElementById('ep-honos-pct');
  function num(el){ return parseFloat((el.value||'').replace(/[^0-9.]/g,'')) || 0; }
  if (PRIX > 0 && num(hM) > 0) hP.value = (num(hM)/PRIX*100).toFixed(2);
  hM.addEventListener('input', function(){ hP.value = PRIX>0 ? (num(hM)/PRIX*100).toFixed(2) : ''; });
  hP.addEventListener('input', function(){ hM.value = PRIX>0 ? Math.round(num(hP)/100*PRIX) : ''; });

  var excl = <?= $exclusif ? 1 : 0 ?>;
  document.querySelectorAll('#ep-excl .ep-pill').forEach(function(p){
    p.addEventListener('click', function(){
      document.querySelectorAll('#ep-excl .ep-pill').forEach(x=>x.classList.remove('on'));
      p.classList.add('on'); excl = p.dataset.excl;
    });
  });
  window.epSave = async function(){
    var msg = document.getElementById('ep-msg'); msg.style.color='#64748b'; msg.textContent='Enregistrement…';
    var fd = new FormData();
    fd.append('id_dossier', <?= (int)$idDossier ?>);
    fd.append('numero_mandat', document.getElementById('ep-numero').value);
    fd.append('honoraires', (document.getElementById('ep-honos').value||'').replace(/[^0-9.]/g,''));
    fd.append('honoraires_charge', document.getElementById('ep-charge').value);
    fd.append('exclusif', excl);
    fd.append('duree_mois', document.getElementById('ep-duree').value);
    fd.append('date_debut', document.getElementById('ep-datedebut').value);
    fd.append('date_signature', document.getElementById('ep-datesign').value);
    try{
      var r = await fetch(<?= json_encode(app_url('/api/transaction_dossier_mandat_update.php')) ?>, {method:'POST', body:fd});
      var j = await r.json();
      if(j.ok){ msg.style.color='#15803d'; msg.textContent='✓ Enregistré'; setTimeout(()=>location.reload(), 500); }
      else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Erreur'; }
    }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
  };
})();
</script>
<script>
(function(){
  var ID = <?= (int)$idDossier ?>;
  var MODELE = <?= json_encode($modele) ?>;
  var PDF = <?= json_encode(app_url('/api/transaction_mandat_pdf.php')) ?>;
  var TMAIL = <?= json_encode(app_url('/transaction_mail.php')) ?>;
  function projetOn(){ var c=document.getElementById('m-projet'); return c && c.checked; }
  // Net vendeur auto-déduit côté serveur. Filigrane PROJET seulement si la case est cochée.
  function qs(extra){ var p='id_dossier='+ID+'&modele='+encodeURIComponent(MODELE); if(projetOn()) p+='&projet=1'; return p+(extra||''); }
  function mInfo(txt, color){ var m=document.getElementById('m-msg'); m.style.color=color||'#0e7490'; m.innerHTML=txt; }

  // Impression / signature : TOUJOURS sans filigrane
  window.mDownload = function(){ window.open(PDF+'?id_dossier='+ID+'&modele='+encodeURIComponent(MODELE), '_blank'); mInfo('✓ PDF (sans filigrane) ouvert dans un nouvel onglet.','#243B5C'); };

  // Enregistrer en GED (filigrane selon la case). Renvoie une Promise avec le doc_id.
  function saveGed(){ return fetch(PDF+'?'+qs('&save=1'), {method:'POST'}).then(function(r){return r.json();}); }
  window.mSaveGed = function(btn){
    btn.disabled=true; mInfo('⏳ Génération + enregistrement en GED…','#64748b');
    saveGed().then(function(j){ btn.disabled=false;
      if(j&&j.ok){ mInfo('✓ Mandat'+(projetOn()?' (PROJET)':'')+' enregistré en GED — <a href="'+j.url+'" target="_blank">ouvrir</a>','#0e7490'); }
      else { mInfo('❌ '+((j&&j.error)||'échec'),'#ef4444'); }
    }).catch(function(){ btn.disabled=false; mInfo('❌ réseau','#ef4444'); });
  };

  // Composer le mail : on enregistre d'abord le PDF en GED (filigrane selon la case),
  // puis on ouvre transaction_mail.php (UI mail unique : modèles, sélection des PJ GED, envoi).
  window.mMail = function(btn){
    btn.disabled=true; mInfo('⏳ Préparation du document pour l’email…','#64748b');
    saveGed().then(function(j){ btn.disabled=false;
      if(j&&j.ok){ mInfo('✓ Document prêt — ouverture du mail…','#7c3aed');
        window.location.href = TMAIL + '?id_dossier=' + ID;
      } else { mInfo('❌ '+((j&&j.error)||'échec génération'),'#ef4444'); }
    }).catch(function(){ btn.disabled=false; mInfo('❌ réseau','#ef4444'); });
  };
})();
</script>
</body></html>
