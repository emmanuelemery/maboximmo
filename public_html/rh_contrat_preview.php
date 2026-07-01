<?php
declare(strict_types=1);
/**
 * rh_contrat_preview.php — Rédaction d'un CONTRAT DE TRAVAIL (CDI/CDD, CCN Immobilier / FNAIM).
 *
 * Pattern « mandat » : document A4 à GAUCHE qui se remplit EN DIRECT depuis le formulaire à DROITE.
 * Pré-rempli depuis la fiche RH du salarié (employeur = société/agence, salarié = users).
 * Imprimable / PDF (impression navigateur). Ouvert en modal (iframe) depuis rh_profil.php.
 *
 * GET : ?id=<users.id>[&type=cdi|cdd]
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$userId = (int)($_GET['id'] ?? 0);
$type   = strtolower((string)($_GET['type'] ?? 'cdi')) === 'cdd' ? 'cdd' : 'cdi';
if ($userId <= 0) { http_response_code(400); exit('id requis'); }

$pdo = $GLOBALS['pdo'];
// On lit séparément users / societes / agences (colonnes homonymes : nom, adresse, ville…)
$stU = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stU->execute([$userId]);
$u = $stU->fetch(PDO::FETCH_ASSOC);
if (!$u) { http_response_code(404); exit('Salarié introuvable'); }

$soc = []; $age = [];
if (!empty($u['id_societe'])) { $q=$pdo->prepare("SELECT * FROM societes WHERE id=? LIMIT 1"); $q->execute([(int)$u['id_societe']]); $soc=$q->fetch(PDO::FETCH_ASSOC)?:[]; }
if (!empty($u['id_agence']))  { $q=$pdo->prepare("SELECT * FROM agences  WHERE id=? LIMIT 1"); $q->execute([(int)$u['id_agence']]);  $age=$q->fetch(PDO::FETCH_ASSOC)?:[]; }

$pick = static function(array $row, array $keys, string $def=''): string {
    foreach ($keys as $k) { if (isset($row[$k]) && trim((string)$row[$k]) !== '') return trim((string)$row[$k]); }
    return $def;
};
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';

// Valeurs pré-remplies
$salarieNom = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
$empNom     = $pick($soc, ['raison_sociale','nom'], 'REGIE EMERY');
$empAdr     = trim($pick($soc, ['adresse','adresse_1','adresse_ligne_1']) . ' ' . $pick($soc, ['code_postal']) . ' ' . $pick($soc, ['ville']));
if ($empAdr === '') $empAdr = '19 Boulevard Yves Farge 69007 LYON';
// Représentant légal = par défaut l'utilisateur CONNECTÉ (celui qui rédige), toujours pré-rempli.
$empRep = $pick($soc, ['representant','gerant','dirigeant'], '');
if ($empRep === '') {
    $meId = (int)($_SESSION['user_id'] ?? 0);
    if ($meId > 0) {
        try { $qm = $pdo->prepare("SELECT prenom, nom FROM users WHERE id=? LIMIT 1"); $qm->execute([$meId]);
              if ($me = $qm->fetch(PDO::FETCH_ASSOC)) $empRep = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? '')); } catch (Throwable) {}
    }
}
$empQualite = 'Représentant légal';
$lieu       = $pick($age, ['ville'], $pick($soc, ['ville'], 'Lyon'));
$adrTravail = trim($pick($age, ['adresse','adresse_1']) . ' ' . $pick($age, ['code_postal']) . ' ' . $pick($age, ['ville']));
if ($adrTravail === '') $adrTravail = $empAdr;
$fonction   = (string)($u['fonction'] ?? '');
$dateEntree = $fmtDate($u['date_entree'] ?? '');
$dateSortie = $fmtDate($u['date_sortie'] ?? '');
$tempsPlein = (stripos((string)($u['temps_travail'] ?? ''), 'partiel') === false);
$empContact = $pick($soc, ['email'], 'contact@regie-emery.com');
$posteDef   = trim((string)($u['poste_definition'] ?? '')); // mission du contrat = définition du poste RH
// État civil du salarié (issu CNI / carte vitale / CV → profil RH)
$salNaiss     = $fmtDate($u['date_naissance'] ?? '');
$salLieuNaiss = (string)($u['lieu_naissance'] ?? '');
$salNat       = (string)($u['nationalite'] ?? '');
$salSecu      = (string)($u['num_secu'] ?? '');
$salAdr       = trim(trim((string)($u['adresse'] ?? '') . ' ' . (string)($u['adresse2'] ?? '')) . ' ' . trim((string)($u['code_postal'] ?? '') . ' ' . (string)($u['ville'] ?? '')));
$salCivilite  = (string)($u['civilite'] ?? '');
// N° compte employeur URSSAF : celui de l'AGENCE (établissement) du salarié en priorité, sinon société.
$urssafCompte = $pick($age, ['urssaf_compte'], '') ?: $pick($soc, ['urssaf_compte'], '');

// Édition d'un contrat existant de l'historique (?contrat=ID) → on pré-remplit avec ses valeurs
$contratId = (int)($_GET['contrat'] ?? 0);
$preNiveau = ''; $preSalaire = ''; $preMotif = ''; $preDuree = ''; $preMission = ''; $savedData = null;
if ($contratId > 0) {
    try {
        $qc = $pdo->prepare("SELECT * FROM user_contrats WHERE id=? AND id_user=? LIMIT 1");
        $qc->execute([$contratId, $userId]);
        if ($row = $qc->fetch(PDO::FETCH_ASSOC)) {
            $rt = strtolower((string)($row['type_contrat'] ?? ''));
            if ($rt === 'cdd' || $rt === 'cdi') $type = $rt;
            if (!empty($row['fonction']))   $fonction   = (string)$row['fonction'];
            if (!empty($row['date_debut'])) $dateEntree = (string)$row['date_debut'];
            if (!empty($row['date_fin']))   $dateSortie = (string)$row['date_fin'];
            $preNiveau  = (string)($row['niveau'] ?? '');
            $preSalaire = (string)($row['salaire_brut'] ?? '');
            $preMotif   = (string)($row['motif'] ?? '');
            $preDuree   = (string)($row['duree'] ?? '');
            $preMission = (string)($row['mission'] ?? '');
            $tmpD = json_decode((string)($row['data_json'] ?? ''), true);
            if (is_array($tmpD)) $savedData = $tmpD; // tous les champs (prévoyance, santé, période d'essai, supérieur, délai…)
        } else { $contratId = 0; }
    } catch (Throwable) { $contratId = 0; }
}
if ($preMission === '') $preMission = $posteDef; // sinon mission = définition du poste RH
$titreType = $type === 'cdd' ? 'À DURÉE DÉTERMINÉE' : 'À DURÉE INDÉTERMINÉE';
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contrat de travail — <?= h($salarieNom) ?></title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#e9edf2;color:#111;}
  .layout{display:flex;height:100vh;overflow:hidden;}
  /* ── colonne GAUCHE : document ── */
  .doc-pane{flex:1;overflow:auto;padding:22px;}
  .doc{max-width:820px;margin:0 auto;background:#fff;padding:42px 52px;box-shadow:0 4px 24px rgba(0,0,0,.12);font-family:'Times New Roman',Georgia,serif;font-size:13px;line-height:1.5;}
  .doc .hd{font-size:11px;color:#243B5C;line-height:1.35;margin-bottom:8px;}
  .doc .hd b{font-size:13px;}
  .doc h1{text-align:center;font-size:17px;letter-spacing:.5px;margin:18px 0 2px;}
  .doc h1 small{display:block;font-size:13px;font-weight:700;margin-top:2px;}
  .doc h2{font-size:13px;color:#243B5C;border-bottom:1px solid #243B5C;padding-bottom:3px;margin:18px 0 8px;}
  .doc h3{font-size:12px;margin:12px 0 4px;color:#1f2d3d;}
  .doc p{margin:6px 0;text-align:justify;}
  .cv{background:#fff7e6;padding:0 3px;border-bottom:1px solid #e3b04b;font-style:normal;}
  @media print { .cv{background:transparent;border-bottom:none;} }
  .doc .sign{display:flex;justify-content:space-between;margin-top:34px;font-size:12px;}
  .doc .sign div{width:45%;}
  /* ── colonne DROITE : formulaire ── */
  .form-pane{width:420px;flex:none;background:#0f1a2e;color:#dce5f0;overflow:auto;padding:18px 20px;}
  .form-pane h3{margin:0 0 4px;font-size:14px;color:#fff;}
  .form-pane .sub{font-size:11px;color:#8ea3c0;margin-bottom:14px;}
  .fg{margin-bottom:11px;}
  .fg label{display:block;font-size:11px;font-weight:700;color:#a9bcd6;margin-bottom:3px;text-transform:uppercase;letter-spacing:.03em;}
  .fg input,.fg textarea,.fg select{width:100%;padding:8px 10px;border:1px solid #2b3c57;border-radius:7px;background:#16233a;color:#fff;font-size:13px;font-family:inherit;}
  .fg textarea{min-height:64px;resize:vertical;}
  .grp{border-top:1px solid #233248;margin-top:14px;padding-top:10px;}
  .grp .gt{font-size:11px;font-weight:800;color:#D4A047;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;}
  .bar{position:sticky;top:0;display:flex;gap:8px;background:#0f1a2e;padding-bottom:12px;z-index:5;}
  .bar button,.bar a{flex:1;cursor:pointer;border:none;border-radius:8px;padding:10px;font-family:inherit;font-weight:700;font-size:12.5px;text-align:center;text-decoration:none;}
  .bar .print{background:linear-gradient(135deg,#D4A047,#c08e2f);color:#1a2233;}
  .bar .type{background:#16233a;color:#dce5f0;border:1px solid #2b3c57;}
  .bar .type.on{background:#243B5C;color:#fff;border-color:#243B5C;}
  @media print {
    html,body{height:auto!important;overflow:visible!important;background:#fff;}
    .layout{display:block!important;height:auto!important;overflow:visible!important;}
    .form-pane,.bar{display:none!important;}
    .doc-pane{overflow:visible!important;height:auto!important;padding:0;}
    .doc{box-shadow:none;max-width:none;padding:0 14px;}
    .doc h2,.doc h3{break-after:avoid;page-break-after:avoid;}
    .doc p,.doc .sign{break-inside:avoid;page-break-inside:avoid;}
  }
</style>
</head>
<body>
<div class="layout">
  <!-- ════ DOCUMENT (gauche) ════ -->
  <div class="doc-pane">
    <div class="doc" id="doc">
      <div class="hd"><b><span class="cv" id="d-empNom"><?= h($empNom) ?></span></b><br>
        <span class="cv" id="d-empAdr"><?= h($empAdr) ?></span><br>
        <?= h($empContact) ?></div>

      <h1>CONTRAT DE TRAVAIL<small><span id="d-temps"><?= $tempsPlein ? 'À TEMPS COMPLET' : 'À TEMPS PARTIEL' ?></span> — <span id="d-type"><?= h($titreType) ?></span></small></h1>

      <p>Entre l'employeur et le salarié mentionnés ci-après, il est conclu le présent contrat de travail qui se compose de clauses particulières et de clauses générales. <b>Les relations contractuelles sont soumises à la convention collective nationale de l'immobilier (CCN I).</b></p>

      <h2>1. — CLAUSES PARTICULIÈRES</h2>
      <p>Le présent engagement est conclu entre :<br>
        <b><span class="cv" id="d-empNom2"><?= h($empNom) ?></span></b>, adhérent de la Fédération Nationale de l'Immobilier (FNAIM), dont l'activité est régie par la loi n° 70-9 du 2 janvier 1970 (loi Hoguet). Représentée par <span class="cv" id="d-empRep"><?= h($empRep ?: '…') ?></span>, agissant en sa qualité de <span class="cv" id="d-empQualite"><?= h($empQualite) ?></span>, ci-après dénommée « l'employeur »,</p>
      <p>et <b><span class="cv" id="d-salCiv"><?= h($salCivilite) ?></span> <span class="cv" id="d-salNom"><?= h($salarieNom) ?></span></b>, né(e) le <span class="cv" id="d-salNaiss"><?= h($salNaiss ?: '…') ?></span> à <span class="cv" id="d-salLieuNaiss"><?= h($salLieuNaiss ?: '…') ?></span>, de nationalité <span class="cv" id="d-salNat"><?= h($salNat ?: '…') ?></span>, n° de sécurité sociale <span class="cv" id="d-salSecu"><?= h($salSecu ?: '…') ?></span>, demeurant <span class="cv" id="d-salAdr"><?= h($salAdr ?: '…') ?></span>, ci-après dénommé « le salarié ».</p>
      <p>Adhérent de la Fédération Nationale de l'Immobilier (FNAIM), ayant le titre professionnel d'AGENT IMMOBILIER obtenu en France dont l'activité est régie par la loi n° 70-9 du 2 janvier 1970 (dite « loi Hoguet ») et son décret d'application n° 72-678 du 20 juillet 1972, et soumis au code d'éthique et de déontologie de la FNAIM intégrant les règles de déontologie fixées par le décret n° 2015-1090 du 28 août 2015.</p>
      <p>La déclaration préalable à l'embauche du salarié a été effectuée à l'Urssaf de <span class="cv" id="d-urssafVille"><?= h($lieu) ?></span> auprès de laquelle l'employeur est immatriculé sous le n° <span class="cv" id="d-urssafNum"><?= h($urssafCompte ?: '…') ?></span>. Le salarié peut exercer auprès de cet organisme son droit d'accès, de rectification, de limitation ou d'effacement et pour un motif légitime et sérieux.</p>
      <p>Les données personnelles du salarié collectées dans le cadre du présent contrat de travail font l'objet d'un traitement nécessaire à son exécution ainsi qu'aux différentes obligations déclaratives (notamment Chambre de commerce et d'industrie, différents organismes sociaux, de prévoyance, de santé et de formation professionnelle). Elles sont conservées pendant toute la durée de l'exécution du présent contrat, augmentée des délais légaux de prescription applicable. Le salarié est informé que ses nom, prénom, date de naissance, fonction, date d'embauche et coordonnées professionnelles pourront être transmis à la chambre FNAIM d'adhésion de l'employeur ainsi qu'à la FNAIM sise 129 rue du faubourg Saint-Honoré 75008 Paris, dans le cadre d'un traitement informatisé pour l'organisation des agences adhérentes. Conformément à la loi informatique et libertés, le salarié bénéficie d'un droit d'accès, de rectification, de portabilité, de limitation ou d'effacement de ses données, et pour un motif légitime et sérieux d'opposition. En cas de difficulté, il peut porter toute réclamation devant la Cnil (www.cnil.fr).</p>

<?php if ($type==='cdd'): ?>
      <h3>1.1. — MOTIF DU RECOURS</h3>
      <p>Le présent contrat à durée déterminée est conclu pour le motif suivant : <span class="cv" id="d-cddMotif">accroissement temporaire d'activité</span>.</p>
      <h3>1.2. — FONCTIONS</h3>
      <p>Le salarié est engagé pour une durée déterminée en qualité de <b><span class="cv" id="d-fonction"><?= h($fonction ?: '…') ?></span></b>, niveau <span class="cv" id="d-niveau">…</span>, sous réserve du résultat favorable de la période d'essai prévue aux conditions générales.</p>
      <p>Il exercera ses fonctions sous l'autorité de <span class="cv" id="d-superieur">…</span> à l'adresse suivante : <span class="cv" id="d-adrTravail"><?= h($adrTravail) ?></span>. Les tâches du salarié consistent de façon non limitative à : <span class="cv" id="d-missions">…</span></p>
      <h3>1.3. — PRISE D'EFFET ET DURÉE DU CONTRAT</h3>
      <p>Le présent contrat prend effet le <span class="cv" id="d-dateEntree"><?= h($dateEntree ?: '…') ?></span> à <span class="cv" id="d-lieu"><?= h($lieu) ?></span> et est conclu pour une période de <span class="cv" id="d-cddDuree">…</span>. Il prendra fin automatiquement à l'échéance du terme, soit le <span class="cv" id="d-dateFin"><?= h($dateSortie ?: '…') ?></span>.</p>
      <h3>1.4. — PÉRIODE D'ESSAI</h3>
      <p>Le présent contrat comporte une période d'essai d'une durée de <span class="cv" id="d-cddEssai">…</span>, calculée par référence à la durée minimale d'emploi convenue ci-dessus. Elle ne peut excéder un jour par semaine, dans la limite de deux semaines pour les contrats dont la période minimale d'emploi est égale ou inférieure à six mois, et d'un mois pour ceux dont elle est supérieure à six mois. Cette période d'essai s'entend d'une période de travail effectif ; toute suspension de l'exécution du contrat la prolonge d'une durée équivalente.</p>
<?php else: ?>
      <h3>1.1. — FONCTIONS</h3>
      <p>Le salarié est engagé à compter du <span class="cv" id="d-dateEntree"><?= h($dateEntree ?: '…') ?></span> à <span class="cv" id="d-lieu"><?= h($lieu) ?></span> en qualité de <b><span class="cv" id="d-fonction"><?= h($fonction ?: '…') ?></span></b>, niveau <span class="cv" id="d-niveau">…</span>, sous réserve du résultat favorable de la période d'essai prévue aux conditions générales.</p>
      <p>Il exercera ses fonctions sous l'autorité de <span class="cv" id="d-superieur">…</span> à l'adresse suivante : <span class="cv" id="d-adrTravail"><?= h($adrTravail) ?></span>.</p>
      <p>Les fonctions du salarié consistent de façon non limitative à : <span class="cv" id="d-missions">…</span></p>
<?php endif; ?>

      <h3>1.2. — RÉMUNÉRATION ET DURÉE DU TRAVAIL</h3>
      <p>En contrepartie de son activité, le salarié perçoit un salaire global brut mensuel contractuel de <b><span class="cv" id="d-salaire">…</span> €</b>. La durée de travail est fixée à <b><span class="cv" id="d-dureeHebdo">…</span> heures par semaine</b>. Le salarié bénéficie d'une prime d'ancienneté tous les trois ans au 1er janvier dans les conditions définies par l'article 36 de la convention collective.</p>

      <h3>1.3. — COUVERTURE PRÉVOYANCE ET FRAIS DE SANTÉ</h3>
      <p>La souscription d'une couverture prévoyance et santé est obligatoire dans la branche (avenant n° 91 du 11 avril 2022 de la CCN I). Contrats souscrits auprès de : prévoyance <span class="cv" id="d-prev">…</span> ; frais de santé <span class="cv" id="d-sante">…</span>. Le coût est réparti entre l'employeur et le salarié conformément aux dispositions conventionnelles.</p>
<?php if ($type==='cdd'): ?>
      <p>Conformément à l'article R. 242-1-6, 2° du code de la sécurité sociale, le salarié peut demander à être dispensé d'adhérer aux couvertures santé et/ou prévoyance si la durée de son CDD est inférieure à douze mois ; il peut également user de cette faculté si son CDD est d'une durée au moins égale à douze mois en justifiant par écrit d'une couverture individuelle équivalente souscrite par ailleurs. La demande écrite de dispense doit être faite dans les 15 jours qui suivent l'embauche.</p>
<?php endif; ?>

      <h3>1.4. — FRAIS PROFESSIONNELS</h3>
      <p>Le salarié a droit au remboursement, sur justification, des frais de déplacement engagés dans le cadre de sa mission, dans les conditions et limites définies par l'employeur.</p>

      <h2>2. — CLAUSES GÉNÉRALES</h2>
      <h3>2.1. — ENGAGEMENT</h3>
      <p>Le présent engagement ne deviendra définitif qu'à l'issue d'une période d'essai fixée, en fonction du niveau de classification, de la manière suivante : Niveau E1 : un mois renouvelable une fois, soit deux mois renouvellement inclus ; Niveaux E2 et E3 : deux mois renouvelables une fois à hauteur d'un mois, soit trois mois renouvellement inclus ; Niveaux AM1 à C4 : trois mois renouvelables une fois, soit six mois renouvellement inclus. Le renouvellement de la période d'essai devra faire l'objet d'un accord écrit des parties avant le terme de la première période d'essai.</p>
      <p>La période d'essai est une période de travail effectif ; les absences, quel qu'en soit le motif, en suspendent le déroulement et la prolongent d'autant. Elle peut être rompue par l'une ou l'autre des parties en respectant un préavis prévu aux articles L. 1221-25 et L. 1221-26 du code du travail. Lorsqu'il y est mis fin par l'employeur, le salarié est prévenu dans un délai qui ne peut être inférieur à : 24 heures en deçà de huit jours de présence ; 48 heures entre huit jours et un mois de présence ; deux semaines après un mois de présence ; un mois après trois mois de présence. Lorsqu'il y est mis fin par le salarié, celui-ci respecte un délai de prévenance de 48 heures (ramené à 24 heures si sa présence est inférieure à huit jours).</p>

      <h3>2.2. — CONDITIONS DE TRAVAIL</h3>
      <p><b>2.2.1. Obligations générales.</b> Le salarié doit se conformer à toutes les instructions générales ou particulières qui lui sont données par l'employeur. Il s'engage à respecter le code d'éthique et de déontologie qui lui a été remis. Dans l'accomplissement de ses fonctions, il peut être amené à se déplacer, au besoin pour une durée supérieure à la journée, et à effectuer des heures supplémentaires sur demande de l'employeur.</p>
      <p><b>2.2.2. Utilisation du matériel.</b> Le matériel nécessaire à la bonne exécution de ses obligations (outil informatique, téléphone…) est mis à disposition du salarié, qui s'engage à en limiter l'utilisation à des fins personnelles, à le maintenir en bon état et à informer l'employeur de tout dysfonctionnement. En cas de rupture du contrat, il s'engage à restituer l'ensemble du matériel en bon état.</p>
      <p><b>2.2.3. Confidentialité.</b> Le salarié s'engage à conserver la discrétion la plus absolue sur tout ce qui a trait à l'activité de son employeur dont il a connaissance dans l'exercice de ses fonctions, et ce, en tous domaines.</p>
      <p><b>2.2.4. Mobilité.</b> Au regard des fonctions occupées et dans le seul intérêt légitime de l'entreprise, l'employeur se réserve le droit de changer le lieu de travail du salarié. Cette mutation pourra avoir lieu au sein des succursales, agences, bureaux ou établissements secondaires existants ou à créer situés dans la zone géographique suivante : <span class="cv" id="d-mobilite"><?= h($lieu) ?> et sa région</span>. Le salarié bénéficiera d'un délai de prévenance minimum de <span class="cv" id="d-mobiliteDelai">15</span> jours. Le refus de changer de lieu de travail peut être assimilé à une faute dès lors que les conditions de la présente clause ont été respectées.</p>

<?php if ($type==='cdd'): ?>
      <p><b>2.2.5. Clause d'exclusivité.</b> Durant l'exercice de ses fonctions, le salarié aura connaissance d'informations stratégiques (procédés commerciaux de l'agence, données sur la clientèle avérée ou prospective, renseignements sur les biens immobiliers). À ce titre, il s'interdit, pendant toute l'exécution du présent contrat, de se livrer à toute opération commerciale, pour son compte ou pour celui d'un tiers, et plus généralement à toute activité contraire aux intérêts légitimes de l'entreprise, s'obligeant à consacrer tout son temps d'activité professionnelle à l'exercice de ses fonctions. Toutefois, en cas de création ou de reprise d'entreprise, cette clause ne lui sera pas opposable dans les conditions des articles L. 1222-5 et D. 1222-1 du code du travail.</p>
<?php endif; ?>
      <h3>2.3. — ENGAGEMENT DE NON-DISCRIMINATION</h3>
      <p>Dans le cadre de son activité, le salarié s'oblige à la plus grande vigilance et s'engage à lutter contre toute pratique discriminatoire à l'égard de la clientèle, fondée sur l'origine, le patronyme, l'apparence physique, le sexe, la situation de famille, l'état de santé, le handicap, les mœurs, l'orientation sexuelle, les caractéristiques génétiques, l'âge, les opinions politiques, l'état de grossesse, les activités syndicales ou l'appartenance, vraie ou supposée, à une ethnie, une nation, une race ou une religion déterminée (article 225-1 du code pénal). Il est rappelé que toute discrimination est punie de 45 000 € d'amende et trois ans d'emprisonnement (articles 225-1 et 225-2 du code pénal). Le salarié informe l'employeur de toute demande à caractère discriminatoire dont il aurait connaissance.</p>

      <h3>2.4. — RÉMUNÉRATION ET INDEMNITÉ DE MAINTIEN DE SALAIRE</h3>
      <p>Outre le salaire mensuel défini aux clauses particulières, le salarié bénéficie d'une prime d'ancienneté et d'une gratification annuelle dite treizième mois dans les conditions prévues par la convention collective. Sur tous les éléments de rémunération, il y a retenue des cotisations salariales destinées notamment au financement des régimes sociaux. En cas d'arrêt pour cause de maladie, d'accident ou de maternité, le salarié bénéficie d'un maintien de salaire dans les limites et conditions prévues par les articles 24 et 25 de la CCN I : l'assiette est égale à 90 % du salaire global brut mensuel contractuel en cas de maladie ou d'accident, et à 100 % en cas de maternité, dans la limite du plafond de la Sécurité sociale. Si les dispositions légales (articles L. 1226-1 et suivants du code du travail) s'avèrent plus favorables, le maintien interviendra dans les conditions définies par ces textes.</p>

      <h3>2.5. — CONGÉS RÉMUNÉRÉS</h3>
      <p>Le salarié bénéficie de ses droits à congés payés conformément aux dispositions législatives, réglementaires et conventionnelles en vigueur. La date à laquelle ces congés sont pris est déterminée par l'employeur en fonction des desiderata du salarié et des exigences qu'implique sa fonction. Des congés sans réduction de la rémunération sont accordés à l'occasion d'événements familiaux dans les conditions prévues par la loi et/ou la CCN I.<?php if ($type==='cdd'): ?> Dans l'hypothèse où le contrat viendrait à être rompu sans que le salarié ait pu liquider les droits acquis au titre des congés payés annuels, une indemnité compensatrice de congés payés sera versée à la date d'expiration du contrat.<?php endif; ?></p>

      <h3>2.6. — ABSENCES POUR MALADIE — ACCIDENT</h3>
      <p>En cas d'absence non prévisible, notamment maladie ou accident, le salarié doit prévenir ou faire prévenir immédiatement son employeur et fournir la justification de son absence au plus tard dans les trois jours, en indiquant la durée prévisible, afin que soit assurée la continuité du service. La justification résulte de l'envoi d'un certificat médical, la même formalité devant être renouvelée en cas de prolongation. Les appointements sont garantis dans les conditions de la CCN I et de l'article 2.4. ci-dessus, sous déduction des indemnités journalières de la Sécurité sociale et, le cas échéant, du régime de prévoyance.</p>

      <h3>2.7. — DURÉE — RUPTURE</h3>
      <p id="d-rupture"><?php if ($type==='cdd'): ?>L'employeur porte à la connaissance du salarié la liste des postes à pourvoir sous contrat à durée indéterminée lorsque ce dispositif d'information existe. Le présent engagement est conclu pour une durée déterminée ; conformément à l'article L. 1243-5 du code du travail, il prendra fin de plein droit, sans formalisme particulier ni préavis, du fait de la réalisation de l'objet pour lequel il a été conclu (article 1.1). Il pourra prendre fin de manière anticipée en cas de faute grave ou lourde, de force majeure, d'inaptitude définitive constatée par la médecine du travail, si le salarié justifie d'une embauche à durée indéterminée (article L. 1243-2), ou par accord écrit clair et non équivoque des parties ; la rupture anticipée hors ces cas peut donner lieu à des dommages-intérêts correspondant au préjudice subi. Lorsque, à l'issue du contrat, les relations de travail ne se poursuivent pas par un CDI, le salarié a droit à une indemnité de fin de contrat (« prime de précarité ») égale à 10 % de la rémunération totale brute versée pendant toute la durée du contrat, prime et accessoires compris, à l'exclusion de l'indemnité compensatrice de congés payés ; elle est versée à l'issue du contrat avec le dernier salaire, sauf dans les cas visés à l'article L. 1243-10 du code du travail.<?php else: ?>Le présent engagement est conclu pour une durée indéterminée. Hormis les cas de faute grave, de faute lourde, de force majeure ou de rupture conventionnelle, il ne prendra fin qu'en respectant les dispositions légales et conventionnelles applicables (motif, procédure…) et un préavis réciproque de : pour les employés, un mois après l'expiration de la période d'essai, porté à deux mois à partir de deux ans d'ancienneté ; pour les agents de maîtrise, un mois, porté à deux mois à partir d'un an d'ancienneté ; pour les cadres, trois mois. Pendant le préavis, le salarié pourra s'absenter deux heures par jour pour rechercher un emploi sans réduction de rémunération. Sauf faute grave ou lourde, le salarié licencié après une année d'ancienneté ininterrompue perçoit l'indemnité légale de licenciement (articles L. 1234-9 et suivants du code du travail) ou l'indemnité conventionnelle (article 33 de la CCN I), la plus favorable des deux s'appliquant.</p>

      <h3>2.8. — DÉPART À LA RETRAITE</h3>
      <p>Le présent contrat pourra être rompu par la retraite dans les conditions prévues par la CCN I et conformément aux dispositions législatives en vigueur. En cas de départ à la retraite à son initiative, le salarié respecte le préavis prévu par la CCN I en cas de démission et perçoit l'indemnité conventionnelle de départ à la retraite. En cas de mise à la retraite à l'initiative de l'employeur, le préavis est celui prévu ci-dessus en cas de licenciement et le salarié perçoit l'indemnité légale de licenciement (article L. 1237-7 du code du travail) ou l'indemnité conventionnelle de mise à la retraite si elle s'avère plus favorable.<?php endif; ?></p>

      <div class="sign">
        <div>Fait à <span class="cv" id="d-lieu2"><?= h($lieu) ?></span>, le <span class="cv" id="d-dateSign">__ / __ / ____</span>.<br><br><b>L'employeur</b><br><span id="d-empNom3"><?= h($empNom) ?></span><br>(signature précédée de « lu et approuvé »)</div>
        <div><br><br><b>Le salarié</b><br><span id="d-salNom2"><?= h($salarieNom) ?></span><br>(signature précédée de « lu et approuvé »)</div>
      </div>
    </div>
  </div>

  <!-- ════ FORMULAIRE (droite) ════ -->
  <div class="form-pane">
    <div class="bar">
      <button type="button" class="type <?= $type==='cdi'?'on':'' ?>" onclick="switchType('cdi')">CDI</button>
      <button type="button" class="type <?= $type==='cdd'?'on':'' ?>" onclick="switchType('cdd')">CDD</button>
      <button type="button" class="type" onclick="saveContrat(this)" title="Enregistrer ce contrat dans l'historique du salarié">💾 Historique</button>
      <button type="button" class="print" onclick="window.print()">🖨️ PDF</button>
    </div>
    <h3>Contrat de travail</h3>
    <div class="sub">Saisis à droite — le document se remplit en direct à gauche.</div>

    <div class="grp"><div class="gt">Employeur</div>
      <div class="fg"><label>Dénomination</label><input data-bind="d-empNom,d-empNom2,d-empNom3" value="<?= h($empNom) ?>"></div>
      <div class="fg"><label>Adresse</label><input data-bind="d-empAdr" value="<?= h($empAdr) ?>"></div>
      <div class="fg"><label>Représentée par</label><input data-bind="d-empRep" value="<?= h($empRep) ?>" placeholder="M./Mme …"></div>
      <div class="fg"><label>En qualité de</label><input data-bind="d-empQualite" value="<?= h($empQualite) ?>"></div>
      <div class="fg"><label>Urssaf — ville</label><input data-bind="d-urssafVille" value="<?= h($lieu) ?>"></div>
      <div class="fg"><label>Urssaf — n° compte employeur</label><input data-bind="d-urssafNum" value="<?= h($urssafCompte) ?>" placeholder="…"></div>
    </div>

    <div class="grp"><div class="gt">Salarié & fonctions</div>
      <div class="fg"><label>Salarié</label><input data-bind="d-salNom,d-salNom2" value="<?= h($salarieNom) ?>"></div>
      <div class="fg"><label>Civilité</label><input data-bind="d-salCiv" value="<?= h($salCivilite) ?>" placeholder="M. / Mme"></div>
      <div class="fg"><label>Né(e) le</label><input data-bind="d-salNaiss" value="<?= h($salNaiss) ?>" placeholder="jj/mm/aaaa"></div>
      <div class="fg"><label>Lieu de naissance</label><input data-bind="d-salLieuNaiss" value="<?= h($salLieuNaiss) ?>"></div>
      <div class="fg"><label>Nationalité</label><input data-bind="d-salNat" value="<?= h($salNat) ?>"></div>
      <div class="fg"><label>N° de sécurité sociale</label><input data-bind="d-salSecu" value="<?= h($salSecu) ?>"></div>
      <div class="fg"><label>Adresse du salarié</label><input data-bind="d-salAdr" value="<?= h($salAdr) ?>"></div>
      <div class="fg"><label>Date d'entrée</label><input data-bind="d-dateEntree" value="<?= h($dateEntree) ?>" placeholder="jj/mm/aaaa"></div>
      <div class="fg"><label>Lieu</label><input data-bind="d-lieu,d-lieu2" value="<?= h($lieu) ?>"></div>
      <div class="fg"><label>Fonction</label><input data-bind="d-fonction" value="<?= h($fonction) ?>"></div>
      <div class="fg"><label>Niveau (classification CCN I)</label>
        <select id="sel-niveau" data-bind="d-niveau">
          <?php $nivs = ['E1','E2','E3','AM1','AM2','C1','C2','C3','C4']; $selN = static fn($v) => $preNiveau===$v ? ' selected' : ''; ?>
          <option value="">— choisir —</option>
          <optgroup label="Employés">
            <option value="E1"<?= $selN('E1') ?>>E1</option><option value="E2"<?= $selN('E2') ?>>E2</option><option value="E3"<?= $selN('E3') ?>>E3</option>
          </optgroup>
          <optgroup label="Agents de maîtrise">
            <option value="AM1"<?= $selN('AM1') ?>>AM1</option><option value="AM2"<?= $selN('AM2') ?>>AM2</option>
          </optgroup>
          <optgroup label="Cadres">
            <option value="C1"<?= $selN('C1') ?>>C1</option><option value="C2"<?= $selN('C2') ?>>C2</option><option value="C3"<?= $selN('C3') ?>>C3</option><option value="C4"<?= $selN('C4') ?>>C4</option>
          </optgroup>
        </select>
        <div id="niveau-bareme" style="font-size:11px;color:#a9bcd6;margin-top:5px;line-height:1.5">
          📊 <a href="https://code.travail.gouv.fr/contribution/1527-quel-est-le-salaire-minimum" target="_blank" rel="noopener" style="color:#D4A047;font-weight:700;text-decoration:none">Salaire minimum CCN Immobilier (officiel — Code du travail numérique)</a><br>
          <span style="color:#7e93b4">IDCC 1527 — relève le minimum du niveau choisi, puis saisis le salaire ci-dessous.</span>
        </div>
      </div>
      <div class="fg"><label>Supérieur hiérarchique</label><input data-bind="d-superieur" placeholder="…"></div>
      <div class="fg"><label>Adresse du lieu de travail</label><input data-bind="d-adrTravail" value="<?= h($adrTravail) ?>"></div>
      <div class="fg"><label>Missions (= définition du poste RH)</label><textarea data-bind="d-missions" placeholder="ex : gestion locative, accueil clients, états des lieux…"><?= h($preMission) ?></textarea></div>
    </div>

    <div class="grp" id="grp-cdd" style="display:<?= $type==='cdd'?'block':'none' ?>"><div class="gt">CDD — terme & durée</div>
      <div class="fg"><label>Motif du recours</label><input data-bind="d-cddMotif" value="<?= h($preMotif ?: "accroissement temporaire d'activité") ?>"></div>
      <div class="fg"><label>Durée du contrat</label><input data-bind="d-cddDuree" value="<?= h($preDuree) ?>" placeholder="ex : 3 mois, 6 mois…"></div>
      <div class="fg"><label>Date de fin (terme)</label><input data-bind="d-dateFin" value="<?= h($dateSortie) ?>" placeholder="jj/mm/aaaa"></div>
      <div class="fg"><label>Période d'essai</label><input data-bind="d-cddEssai" placeholder="ex : 2 semaines, 1 mois…"></div>
    </div>

    <div class="grp"><div class="gt">Rémunération & couvertures</div>
      <div class="fg"><label>Salaire brut mensuel (€)</label><input data-bind="d-salaire" value="<?= h($preSalaire) ?>" placeholder="ex : 2 200"></div>
      <div class="fg"><label>Durée hebdomadaire (h/sem.)</label><input data-bind="d-dureeHebdo" value="" placeholder="ex : 35"></div>
      <div class="fg"><label>Organisme prévoyance</label><input data-bind="d-prev" placeholder="…"></div>
      <div class="fg"><label>Organisme frais de santé</label><input data-bind="d-sante" placeholder="…"></div>
    </div>

    <div class="grp"><div class="gt">Mobilité</div>
      <div class="fg"><label>Zone géographique</label><input data-bind="d-mobilite" value="<?= h($lieu) ?> et sa région"></div>
      <div class="fg"><label>Délai de prévenance (jours)</label><input data-bind="d-mobiliteDelai" value="15"></div>
    </div>
  </div>
</div>

<script>
(function(){
  function setBind(el){
    var ids = (el.getAttribute('data-bind')||'').split(',');
    var val = el.value;
    var isArea = el.tagName === 'TEXTAREA';
    ids.forEach(function(id){
      var t = document.getElementById(id.trim()); if(!t) return;
      if (isArea) t.innerHTML = val.replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}).replace(/\n/g,'<br>') || '…';
      else t.textContent = (val && val.length) ? val : '…';
    });
  }
  document.querySelectorAll('[data-bind]').forEach(function(el){
    el.addEventListener('input', function(){ setBind(el); });
    el.addEventListener('change', function(){ setBind(el); });
  });
  // Restauration d'un brouillon repris : ré-applique TOUS les champs enregistrés
  var SAVED = <?= $savedData ? json_encode($savedData, JSON_UNESCAPED_UNICODE) : 'null' ?>;
  if (SAVED) {
    document.querySelectorAll('[data-bind]').forEach(function(el){
      var k = el.getAttribute('data-bind');
      if (Object.prototype.hasOwnProperty.call(SAVED, k)) el.value = SAVED[k];
    });
  }
  // Binding initial : les champs (pré-remplis ou restaurés) remplissent le document
  document.querySelectorAll('[data-bind]').forEach(function(el){ setBind(el); });

  window.switchType = function(t){
    var url = new URL(window.location.href); url.searchParams.set('type', t); window.location.href = url.toString();
  };

  // Enregistrer le contrat dans l'historique du salarié (plusieurs contrats possibles)
  window.saveContrat = function(btn){
    if (btn.dataset.b) return; btn.dataset.b='1'; var old=btn.textContent; btn.textContent='⏳ …';
    function val(id){ var e=document.querySelector('[data-bind="'+id+'"]'); return e ? e.value : ''; }
    // TOUS les champs du formulaire (clé = attribut data-bind) → reprise fidèle ultérieure
    var allData = {};
    document.querySelectorAll('[data-bind]').forEach(function(el){ allData[el.getAttribute('data-bind')] = el.value; });
    var payload = {
      user_id: <?= (int)$userId ?>,
      id: <?= (int)$contratId ?>,
      type: <?= json_encode(strtoupper($type)) ?>,
      niveau: (document.getElementById('sel-niveau')||{}).value || '',
      salaire: val('d-salaire'), fonction: val('d-fonction'),
      date_debut: val('d-dateEntree'), date_fin: val('d-dateFin'),
      motif: val('d-cddMotif'), duree: val('d-cddDuree'), mission: val('d-missions'),
      data: allData
    };
    fetch('api/rh_contrat_save.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
      .then(function(r){ return r.json(); }).then(function(res){
        delete btn.dataset.b;
        if (res && res.ok) {
          btn.textContent = '✓ Enregistré';
          // Rafraîchit la liste « Historique des contrats » de la fiche RH (page parente)
          setTimeout(function(){ try { if (window.parent && window.parent !== window) window.parent.location.reload(); } catch(e){} }, 700);
        } else {
          btn.textContent = '⚠️ ' + ((res && (res.error||res.message)) || 'Échec');
          setTimeout(function(){ btn.textContent = old; }, 4000);
        }
      }).catch(function(){ delete btn.dataset.b; btn.textContent='⚠️ Réseau'; setTimeout(function(){ btn.textContent=old; }, 2500); });
  };
})();
</script>
</body></html>
