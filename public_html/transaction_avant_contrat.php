<?php
// transaction_avant_contrat.php — Saisie de l'avant-contrat (compromis / promesse) :
// document HTML rempli à GAUCHE + champs éditables à DROITE. Ouvert en modal (iframe) depuis le dossier.
// ?id_dossier=ID  (le type est porté par dossier_avant_contrat ; bascule via le sélecteur).
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_once __DIR__ . '/inc/avant_contrat.php';
require_login();

$idDossier = (int)($_GET['id_dossier'] ?? 0);
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { http_response_code(404); exit('Dossier introuvable.'); }
$idBien = (int)$dossier['id_bien'];

$idAc = dac_ensure($pdo, $idDossier, (int)current_user_id() ?: null);
$avc  = dac_get($pdo, $idDossier) ?: [];
$avcLots = dac_lots($pdo, $idAc);
$lots = dv_lots($pdo, $idDossier);

// Acteurs (notaire vendeur vs notaire acquéreur distingués)
$vendeurs = []; $acquereurs = []; $notairesV = []; $notairesA = [];
foreach (dv_acteurs($pdo, $idDossier) as $a) {
    $nom = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '').' '.($a['nom'] ?? '')));
    if (in_array($a['role_code'], ['vendeur','prospect_vendeur'], true)) $vendeurs[] = $nom;
    elseif (in_array($a['role_code'], ['acquereur','prospect_acquereur'], true)) $acquereurs[] = $nom;
    elseif ($a['role_code'] === 'notaire_acquereur') $notairesA[] = $nom;
    elseif ($a['role_code'] === 'notaire') $notairesV[] = $nom;
}
// Lots sélectionnés + prix total acte
$selLots = []; $prixActe = 0.0;
foreach ($lots as $l) {
    if (!in_array((int)$l['id_bien'], $avcLots, true)) continue;
    $px = (float)(($l['prix_vente'] ?? null) ?? $l['_prix_vente_bien'] ?? 0);
    $prixActe += $px;
    $adr = trim(((string)($l['lot_adresse']??'')).' '.((string)($l['lot_cp']??'')).' '.((string)($l['lot_ville']??'')));
    $selLots[] = ['lib'=>($l['reference_bien'] ?: ('Bien #'.(int)$l['id_bien'])), 'type'=>$l['type_libelle'], 'adr'=>$adr, 'px'=>$px];
}

$type    = (string)($avc['type'] ?? 'compromis');
$isProm  = ($type === 'promesse_unilaterale');
// Clé du texte intégral verbatim (partiel HTML extrait du PDF FNAIM)
$docKey  = ($isProm ? 'promesse' : 'compromis') . (!empty($avc['copro']) ? '_copro' : '_hors_copro');
$txtPath = __DIR__ . '/inc/actes_texts/' . $docKey . '.html';
$fmtP = fn($v)=> ($v!==null && $v!=='' && (float)$v>0) ? number_format((float)$v,0,',',' ').' €' : '…';
$fmtD = fn($v)=> $v ? date('d/m/Y', strtotime((string)$v)) : '…';
$refDossier = (string)($dossier['reference'] ?? '') ?: ('#'.$idDossier);
$sel = fn($k,$o)=> ((string)($avc[$k]??'')===(string)$o)?'selected':'';
$chk = fn($k)=> !empty($avc[$k])?'checked':'';
$val = fn($k)=> h((string)($avc[$k]??''));
$S = 'width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:7px;font-size:12px;';
?><!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><title>Avant-contrat — <?= h($refDossier) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
 *{box-sizing:border-box;} body{margin:0;font-family:'Times New Roman',Georgia,serif;background:#e9edf2;}
 .wrap{display:flex;gap:14px;align-items:flex-start;padding:14px;justify-content:center;}
 .doc{flex:0 1 820px;max-width:820px;background:#fff;padding:34px 40px;box-shadow:0 4px 18px rgba(0,0,0,.12);min-height:90vh;}
 .doc h1{text-align:center;font-size:18px;margin:0 0 2px;} .doc .sub{text-align:center;font-size:11px;color:#555;margin-bottom:14px;}
 .doc h2{font-size:12.5px;background:#243B5C;color:#fff;padding:3px 8px;margin:14px 0 6px;border-radius:3px;}
 .doc p,.doc td,.doc li{font-size:12px;line-height:1.5;text-align:justify;}
 .fill{background:#fff7d6;padding:0 3px;font-weight:bold;}
 .lot{border:1px solid #ddd;border-radius:5px;padding:6px 9px;margin:4px 0;font-size:12px;}
 table{width:100%;border-collapse:collapse;} td{padding:3px 6px;vertical-align:top;} .k{color:#555;width:42%;}
 .panel{width:330px;flex-shrink:0;position:sticky;top:14px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;font-family:system-ui,sans-serif;max-height:94vh;overflow:auto;}
 .panel h3{font-size:14px;margin:0 0 8px;color:#243B5C;}
 .pf{margin-bottom:9px;} .pf label{display:block;font-size:10.5px;font-weight:700;color:#64748b;margin-bottom:2px;text-transform:uppercase;}
 .pf input,.pf select,.pf textarea{width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:7px;font-size:12px;font-family:inherit;}
 .grid2{display:grid;grid-template-columns:1fr 1fr;gap:7px;} .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;}
 .sec{border-top:1px solid #eef2f6;margin-top:10px;padding-top:8px;font-size:10.5px;font-weight:800;color:#243B5C;text-transform:uppercase;}
 .save{width:100%;background:#15803d;color:#fff;border:none;border-radius:9px;padding:11px;font-size:14px;font-weight:800;cursor:pointer;margin-top:8px;}
 .chk{display:flex;align-items:center;gap:6px;font-size:12px;margin:3px 0;}
 .toolbar{padding:8px 14px;display:flex;gap:8px;background:#f1f5f9;}
 .toolbar button{font-family:system-ui;font-size:12px;font-weight:700;border:none;border-radius:7px;padding:7px 13px;cursor:pointer;}
 .b-print{background:#243B5C;color:#fff;}
 @media print{.panel,.toolbar{display:none;}.wrap{padding:0;}.doc{box-shadow:none;}}
</style></head>
<body>
<div class="toolbar">
  <button class="b-print" onclick="window.print()">🖨️ Imprimer la fiche</button>
  <a href="<?= h(app_url('/transaction_mail.php?id_dossier=' . $idDossier)) ?>" target="_blank"
     style="font-family:system-ui;font-size:12px;font-weight:700;border:none;border-radius:7px;padding:7px 13px;background:#0e7490;color:#fff;text-decoration:none;">✉️ Envoyer au notaire</a>
  <span style="font-size:12px;color:#64748b;align-self:center;">Réf. <?= h($refDossier) ?> — renseigne à droite puis Enregistrer ; transmets ensuite au notaire.</span>
</div>
<div class="wrap">
  <!-- DOCUMENT -->
  <div class="doc">
    <div style="font-size:11px;color:#243B5C;margin-bottom:8px;"><strong>REGIE EMERY</strong> — 19 Bd Yves Farge, 69007 LYON · FNAIM · GALIAN A 01913560</div>
    <h1>FICHE DE TRANSMISSION AU NOTAIRE</h1>
    <div class="sub">Acte à préparer : <strong><?= $isProm ? 'Promesse unilatérale de vente' : 'Compromis de vente' ?></strong> · <?= !empty($avc['copro']) ? 'copropriété' : 'hors copropriété' ?> · Réf. <?= h($refDossier) ?></div>

    <h2>Entre les parties</h2>
    <p><strong>Le Vendeur :</strong> <span class="fill"><?= $vendeurs ? h(implode(', ',$vendeurs)) : '… (ajouter au dossier)' ?></span></p>
    <p><strong>L'Acquéreur :</strong> <span class="fill"><?= $acquereurs ? h(implode(', ',$acquereurs)) : '… (ajouter l\'acquéreur au dossier)' ?></span></p>
    <p><strong>Notaire du vendeur :</strong> <span class="fill"><?= $notairesV ? h(implode(', ',$notairesV)) : '… (ajouter en acteur « Notaire vendeur »)' ?></span></p>
    <p><strong>Notaire de l'acquéreur :</strong> <span class="fill"><?= $notairesA ? h(implode(', ',$notairesA)) : '… (ajouter en acteur « Notaire acquéreur »)' ?></span></p>

    <h2>Désignation du/des bien(s)</h2>
    <?php if (!$selLots): ?><p><em>Aucun lot sélectionné (cochez les lots à droite).</em></p><?php endif; ?>
    <?php foreach ($selLots as $l): ?>
      <div class="lot"><strong><?= h($l['lib']) ?></strong><?= $l['type']?' — '.h($l['type']):'' ?><?= $l['adr']?'<br>'.h($l['adr']):'' ?><?php if($l['px']>0):?><br>Prix : <strong><?= h($fmtP($l['px'])) ?></strong><?php endif;?></div>
    <?php endforeach; ?>

    <h2>Prix et modalités</h2>
    <table>
      <tr><td class="k">Prix de vente total</td><td><strong><?= h($fmtP($prixActe)) ?></strong></td></tr>
      <?php if ($isProm): ?>
      <tr><td class="k">Indemnité d'immobilisation</td><td><?= h($fmtP($avc['indemnite_immobilisation']??null)) ?></td></tr>
      <tr><td class="k">Date limite de levée d'option</td><td><?= h($fmtD($avc['date_levee_option']??null)) ?></td></tr>
      <?php else: ?>
      <tr><td class="k">Dépôt de garantie</td><td><?= h($fmtP($avc['depot_garantie_montant']??null)) ?><?= !empty($avc['depot_garantie_pct'])?' ('.h($avc['depot_garantie_pct']).' %)':'' ?></td></tr>
      <?php endif; ?>
      <tr><td class="k">Séquestre</td><td><?= h($avc['sequestre_type']??'…') ?></td></tr>
      <tr><td class="k">Frais d'acte à la charge</td><td><?= h($avc['frais_acte_charge']??'acquéreur') ?></td></tr>
      <tr><td class="k">Signature (au plus tôt le)</td><td><?= h($fmtD($avc['date_signature']??null)) ?></td></tr>
      <tr><td class="k">Lieu de signature</td><td><?= h(($avc['lieu_signature'] ?? '') !== '' ? $avc['lieu_signature'] : 'chez le notaire du vendeur') ?></td></tr>
      <tr><td class="k">Réitération par acte authentique</td><td>au plus tard le <?= h($fmtD($avc['date_reiteration_max']??null)) ?></td></tr>
      <tr><td class="k">Occupation</td><td><?= h($avc['occupation']??'…') ?></td></tr>
      <?php if (!empty($avc['mobilier_inclus'])): ?><tr><td class="k">Mobilier inclus</td><td><?= h($fmtP($avc['mobilier_valeur']??null)) ?> <?= h($avc['mobilier_detail']??'') ?></td></tr><?php endif; ?>
    </table>

    <h2>Conditions suspensives</h2>
    <?php if (!empty($avc['cs_pret'])): ?>
      <p><strong>Condition suspensive d'obtention de prêt :</strong> montant <span class="fill"><?= h($fmtP($avc['pret_montant']??null)) ?></span>, durée <?= h($avc['pret_duree_mois']??'…') ?> mois, taux maximum <?= h($avc['pret_taux_max']??'…') ?> %, <?= h($avc['pret_nb_offres']??'…') ?> offre(s), apport <?= h($fmtP($avc['pret_apport']??null)) ?>. Offre(s) à obtenir au plus tard le <span class="fill"><?= h($fmtD($avc['pret_date_limite']??null)) ?></span> auprès de <?= h($avc['pret_organismes']??'…') ?>.</p>
    <?php else: ?>
      <p>La vente est conclue <strong>sans condition suspensive de prêt</strong> (acquéreur déclarant financer comptant).</p>
    <?php endif; ?>
    <ul>
      <?php if (!empty($avc['cs_preemption'])): ?><li>Absence d'exercice d'un droit de préemption<?= !empty($avc['cs_preemption_detail'])?' ('.h($avc['cs_preemption_detail']).')':'' ?>.</li><?php endif; ?>
      <?php if (!empty($avc['cs_servitudes'])): ?><li>Absence de servitudes non déclarées de nature à déprécier le bien.</li><?php endif; ?>
      <?php if (!empty($avc['cs_urbanisme'])): ?><li>Note / certificat d'urbanisme ne révélant aucune charge rendant le bien impropre à sa destination.</li><?php endif; ?>
      <?php if (!empty($avc['cs_hypotheques'])): ?><li>État hypothécaire ne révélant pas d'inscription supérieure au prix (purge/mainlevée).</li><?php endif; ?>
      <?php if (!empty($avc['cs_vente_bien_acquereur'])): ?><li>Vente préalable d'un bien appartenant à l'acquéreur.</li><?php endif; ?>
      <?php if (!empty($avc['cs_autres'])): foreach (array_filter(array_map('trim', explode("\n", (string)$avc['cs_autres']))) as $csl): ?>
        <li><?= h($csl) ?></li>
      <?php endforeach; endif; ?>
    </ul>

    <h2>Faculté de rétractation (art. L.271-1 CCH)</h2>
    <p>L'acquéreur non professionnel bénéficie d'un délai de rétractation de dix jours à compter du lendemain de la première présentation de la notification du présent avant-contrat. <em>Les dates de notification et de fin de rétractation seront gérées par le notaire.</em></p>

    <?php if (!empty($avc['conditions_particulieres'])): ?>
      <h2>Conditions particulières</h2><p style="white-space:pre-wrap;"><?= h($avc['conditions_particulieres']) ?></p>
    <?php endif; ?>

    <h2>Pièces à transmettre au notaire</h2>
    <p>Mandat de vente, titre de propriété, diagnostics techniques (DPE, amiante, plomb, ERP…), avis de valeur, pièces d'identité des parties, et tout document utile — à joindre via <strong>« ✉️ Envoyer au notaire »</strong> (les documents du dossier sont sélectionnables dans l'email).</p>

    <div style="margin-top:18px;background:#eef6f9;border:1px solid #bcdfe9;border-radius:8px;padding:10px 12px;font-size:11.5px;color:#155e75;">
      📨 <strong>Fiche de transmission</strong> : ce récapitulatif réunit les informations nécessaires au notaire pour préparer l'acte (<?= $isProm ? 'promesse' : 'compromis' ?>). Le notaire rédige et organise l'acte ; le document signé sera ensuite <strong>chargé</strong> dans le dossier (onglet Actes → « 📥 Charger des documents »).
    </div>
  </div>

  <!-- PANNEAU CHAMPS -->
  <aside class="panel">
    <h3>✏️ Saisie de l'acte</h3>
    <form id="avc-form" onsubmit="return false;">
      <div class="pf"><label>Type d'acte</label>
        <select name="type"><option value="compromis" <?= $sel('type','compromis') ?>>Compromis de vente</option><option value="promesse_unilaterale" <?= $sel('type','promesse_unilaterale') ?>>Promesse unilatérale</option></select></div>
      <div class="grid2">
        <div class="pf"><label>Copropriété</label><select name="copro"><option value="0" <?= $sel('copro','0') ?>>Non</option><option value="1" <?= $sel('copro','1') ?>>Oui</option></select></div>
        <div class="pf"><label>Statut</label><select name="statut"><?php foreach(['brouillon'=>'Brouillon','signe'=>'Signé','caduc'=>'Caduc','realise'=>'Réalisé','annule'=>'Annulé'] as $k=>$v):?><option value="<?=$k?>" <?= $sel('statut',$k) ?>><?=$v?></option><?php endforeach;?></select></div>
      </div>

      <div class="sec">Lots de l'acte</div>
      <?php foreach ($lots as $l): $b=(int)$l['id_bien']; ?>
        <label class="chk"><input type="checkbox" name="lots[]" value="<?= $b ?>" <?= in_array($b,$avcLots,true)?'checked':'' ?>> <?= h($l['reference_bien'] ?: ('Bien #'.$b)) ?></label>
      <?php endforeach; ?>

      <div class="sec">Dates</div>
      <div class="grid2">
        <div class="pf"><label>Signature au plus tôt le</label><input type="date" name="date_signature" value="<?= $val('date_signature') ?>"></div>
        <div class="pf"><label>Réitération max</label><input type="date" name="date_reiteration_max" value="<?= $val('date_reiteration_max') ?>"></div>
      </div>
      <div class="pf"><label>Lieu de signature</label><input type="text" name="lieu_signature" value="<?= h(($avc['lieu_signature'] ?? '') !== '' ? $avc['lieu_signature'] : 'Chez le notaire du vendeur') ?>"></div>

      <div class="sec">Dépôt / promesse <span style="font-weight:400;text-transform:none;color:#94a3b8;">(% calcule le montant)</span></div>
      <div class="grid2">
        <div class="pf"><label>Dépôt garantie %</label><input type="text" id="dg-pct" name="depot_garantie_pct" value="<?= $val('depot_garantie_pct') ?>"></div>
        <div class="pf"><label>Dépôt garantie €</label><input type="text" id="dg-eur" name="depot_garantie_montant" value="<?= $val('depot_garantie_montant') ?>"></div>
        <div class="pf"><label>Indemnité immob. %</label><input type="text" id="ind-pct" value=""></div>
        <div class="pf"><label>Indemnité immob. €</label><input type="text" id="ind-eur" name="indemnite_immobilisation" value="<?= $val('indemnite_immobilisation') ?>"></div>
        <div class="pf"><label>Séquestre</label><select name="sequestre_type"><option value="">—</option><option value="notaire" <?= $sel('sequestre_type','notaire') ?>>Notaire</option><option value="agence" <?= $sel('sequestre_type','agence') ?>>Agence</option><option value="aucun" <?= $sel('sequestre_type','aucun') ?>>Aucun</option></select></div>
        <div class="pf"><label>Levée d'option</label><input type="date" name="date_levee_option" value="<?= $val('date_levee_option') ?>"></div>
      </div>

      <div class="sec">Condition suspensive de prêt</div>
      <label class="chk"><input type="checkbox" name="cs_pret" value="1" <?= $chk('cs_pret') ?>> Inclure la condition de prêt <span style="color:#94a3b8;">(décocher = vente sans condition de financement)</span></label>
      <div class="grid2">
        <div class="pf"><label>Montant €</label><input type="text" name="pret_montant" value="<?= $val('pret_montant') ?>"></div>
        <div class="pf"><label>Durée (mois)</label><input type="text" name="pret_duree_mois" value="<?= $val('pret_duree_mois') ?>"></div>
        <div class="pf"><label>Taux max %</label><input type="text" name="pret_taux_max" value="<?= $val('pret_taux_max') ?>"></div>
        <div class="pf"><label>Nb offres</label><input type="text" name="pret_nb_offres" value="<?= $val('pret_nb_offres') ?>"></div>
        <div class="pf"><label>Date limite</label><input type="date" name="pret_date_limite" value="<?= $val('pret_date_limite') ?>"></div>
        <div class="pf"><label>Apport €</label><input type="text" name="pret_apport" value="<?= $val('pret_apport') ?>"></div>
      </div>
      <div class="pf"><label>Organismes</label><input type="text" name="pret_organismes" value="<?= $val('pret_organismes') ?>"></div>

      <div class="sec">Autres conditions suspensives</div>
      <label class="chk"><input type="checkbox" name="cs_preemption" value="1" <?= $chk('cs_preemption') ?>> Préemption</label>
      <div class="pf"><input type="text" name="cs_preemption_detail" placeholder="détail préemption" value="<?= $val('cs_preemption_detail') ?>"></div>
      <label class="chk"><input type="checkbox" name="cs_servitudes" value="1" <?= $chk('cs_servitudes') ?>> Servitudes</label>
      <label class="chk"><input type="checkbox" name="cs_urbanisme" value="1" <?= $chk('cs_urbanisme') ?>> Urbanisme</label>
      <label class="chk"><input type="checkbox" name="cs_hypotheques" value="1" <?= $chk('cs_hypotheques') ?>> Purge hypothèques</label>
      <label class="chk"><input type="checkbox" name="cs_vente_bien_acquereur" value="1" <?= $chk('cs_vente_bien_acquereur') ?>> Vente bien acquéreur</label>
      <div class="pf"><label>Conditions suspensives libres</label>
        <div id="cs-extra-list"></div>
        <button type="button" onclick="csAddRow('')" style="margin-top:4px;background:#eef5fc;border:1px dashed #0f6cbd;color:#0f6cbd;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer;">➕ Ajouter une condition suspensive</button>
      </div>

      <div class="sec">Occupation · mobilier · acte</div>
      <div class="grid2">
        <div class="pf"><label>Occupation</label><select name="occupation"><option value="">—</option><option value="libre" <?= $sel('occupation','libre') ?>>Libre</option><option value="occupe" <?= $sel('occupation','occupe') ?>>Occupé</option><option value="loue" <?= $sel('occupation','loue') ?>>Loué</option></select></div>
        <div class="pf"><label>Mobilier €</label><input type="text" name="mobilier_valeur" value="<?= $val('mobilier_valeur') ?>"></div>
        <div class="pf"><label class="chk"><input type="checkbox" name="mobilier_inclus" value="1" <?= $chk('mobilier_inclus') ?>> Mobilier inclus</label></div>
        <div class="pf"><label>Notaire rédacteur</label><select name="notaire_redacteur"><option value="">—</option><option value="vendeur" <?= $sel('notaire_redacteur','vendeur') ?>>Vendeur</option><option value="acquereur" <?= $sel('notaire_redacteur','acquereur') ?>>Acquéreur</option><option value="commun" <?= $sel('notaire_redacteur','commun') ?>>Commun</option></select></div>
        <div class="pf"><label>Frais d'acte</label><select name="frais_acte_charge"><option value="acquereur" <?= $sel('frais_acte_charge','acquereur') ?>>Acquéreur</option><option value="vendeur" <?= $sel('frais_acte_charge','vendeur') ?>>Vendeur</option><option value="partage" <?= $sel('frais_acte_charge','partage') ?>>Partagé</option></select></div>
      </div>
      <p style="font-size:10.5px;color:#94a3b8;margin:4px 0 0;">📌 Date d'entrée en jouissance et dates SRU (notification / fin de rétractation) seront renseignées par le notaire.</p>
      <div class="pf"><label>Conditions particulières</label><textarea name="conditions_particulieres" rows="3"><?= $val('conditions_particulieres') ?></textarea></div>

      <button type="button" class="save" onclick="avcSave()">💾 Enregistrer (met à jour le document)</button>
      <div id="avc-msg" style="font-size:11px;text-align:center;margin-top:6px;color:#94a3b8;"></div>
    </form>
  </aside>
</div>
<script>
// Calcul auto % ⇄ montant (base = prix total de l'acte)
(function(){
  var PRIX = <?= (float)$prixActe ?>;
  var n = function(el){ return parseFloat((el.value||'').replace(/[^0-9.]/g,''))||0; };
  function pair(pctId, eurId){
    var p=document.getElementById(pctId), e=document.getElementById(eurId);
    if(!p||!e) return;
    if(PRIX>0 && n(e)>0 && !n(p)) p.value=(n(e)/PRIX*100).toFixed(2); // init % depuis montant existant
    p.addEventListener('input', function(){ e.value = PRIX>0 ? Math.round(n(p)/100*PRIX) : ''; });
    e.addEventListener('input', function(){ p.value = PRIX>0 ? (n(e)/PRIX*100).toFixed(2) : ''; });
  }
  pair('dg-pct','dg-eur');
  pair('ind-pct','ind-eur');
})();

// Conditions suspensives libres (liste dynamique add/remove)
window.csAddRow = function(val){
  var box=document.getElementById('cs-extra-list');
  var row=document.createElement('div');
  row.style.cssText='display:flex;gap:6px;margin-bottom:5px;';
  row.innerHTML='<input type="text" class="cs-extra" style="flex:1;padding:6px 8px;border:1px solid #cbd5e1;border-radius:7px;font-size:12px;"> '
    +'<button type="button" title="Supprimer" style="border:none;background:#fee2e2;color:#dc2626;border-radius:7px;padding:0 10px;cursor:pointer;font-weight:700;">✕</button>';
  row.querySelector('button').addEventListener('click', function(){ row.remove(); });
  row.querySelector('input').value = val || '';
  box.appendChild(row);
};
(function(){ var init=<?= json_encode($avc && !empty($avc['cs_autres']) ? array_values(array_filter(array_map('trim', explode("\n", (string)$avc['cs_autres'])))) : []) ?>;
  init.forEach(function(v){ csAddRow(v); }); })();

window.avcSave = async function(){
  var f=document.getElementById('avc-form'), msg=document.getElementById('avc-msg');
  var fd=new FormData(f); fd.append('id_dossier', <?= (int)$idDossier ?>);
  ['cs_pret','cs_preemption','cs_servitudes','cs_urbanisme','cs_hypotheques','cs_vente_bien_acquereur','mobilier_inclus'].forEach(function(n){
    if(!f.querySelector('[name="'+n+'"]:checked')) fd.set(n,'0');
  });
  // Conditions suspensives libres → cs_autres (une par ligne)
  var extras=[].map.call(document.querySelectorAll('.cs-extra'), function(i){return i.value.trim();}).filter(Boolean);
  fd.set('cs_autres', extras.join('\n'));
  msg.style.color='#64748b'; msg.textContent='Enregistrement…';
  try{
    var r=await fetch(<?= json_encode(app_url('/api/transaction_dossier_avant_contrat_save.php')) ?>,{method:'POST',body:fd});
    var j=await r.json();
    if(j.ok){ location.reload(); } else { msg.style.color='#ef4444'; msg.textContent=j.error||'Erreur'; }
  }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
};
</script>
</body></html>
