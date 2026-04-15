<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Mes honoraires';
$bodyClass = '';
$robots = 'noindex, nofollow';

$pdo = db();
$idSociete = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : 0;

$errors = [];
$success = '';

// ─── Chargement de la config existante ─────────────────────
$row = null;
if ($idSociete > 0) {
    $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? LIMIT 1");
    $stmt->execute([$idSociete]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─── Traitement POST ───────────────────────────────────────
if (is_post()) {
    verify_csrf('honoraires_config');

    $str = static fn(string $k) => trim((string)post($k, ''));
    $flt = static fn(string $k) => post($k, '') !== '' ? (float)post($k) : null;

    // ─── VENTE ──
    $venteMethode      = $str('vente_methode') ?: 'tranches';
    $venteTauxUnique   = $flt('vente_taux_unique');
    $venteForfait      = $flt('vente_forfait');
    $venteCharge       = $str('vente_charge_par_defaut') ?: 'acquereur';
    $venteMin          = $flt('vente_montant_minimum');

    // Tranches : tableaux parallèles
    $trMin = $_POST['tr_min'] ?? [];
    $trMax = $_POST['tr_max'] ?? [];
    $trPct = $_POST['tr_pct'] ?? [];
    $tranches = [];
    if (is_array($trMin)) {
        foreach ($trMin as $i => $min) {
            $min = $min !== '' ? (float)$min : null;
            $max = isset($trMax[$i]) && $trMax[$i] !== '' ? (float)$trMax[$i] : null;
            $pct = isset($trPct[$i]) && $trPct[$i] !== '' ? (float)$trPct[$i] : null;
            if ($min === null && $max === null && $pct === null) continue;
            $tranches[] = ['min' => $min, 'max' => $max, 'pct' => $pct];
        }
    }
    $venteTranchesJson = $tranches ? json_encode($tranches, JSON_UNESCAPED_UNICODE) : null;

    // ─── LOCATION ──
    $locZone           = $str('location_zone') ?: 'non_tendue';
    $locLocataireM2    = $flt('location_honoraires_locataire_m2');
    $locEdlM2          = $flt('location_honoraires_etat_des_lieux_m2');
    $locBailleurPct    = $flt('location_honoraires_bailleur_pct');
    $locBailleurForfait = $flt('location_honoraires_bailleur_forfait');

    // ─── GESTION LOCATIVE ──
    $gestionPctLoyer   = $flt('gestion_pct_loyer');
    $gestionEntree     = $flt('gestion_frais_entree_locataire');
    $gestionSortie     = $flt('gestion_frais_sortie_locataire');
    $gestionRenouv     = $flt('gestion_renouvellement_bail');
    $gestionAvenant    = $flt('gestion_avenant_bail');
    $gestionTravauxPct = $flt('gestion_suivi_travaux_pct');
    $gestionQuittance  = $flt('gestion_quittance_supplementaire');
    $gestionGli        = $flt('gestion_assurance_loyers_impayes_pct');
    $gestionCarence    = $flt('gestion_carence_locative_pct');
    $gestionHtml       = $str('gestion_prestations_html');

    // ─── SYNDIC ──
    $syndicForfaitLot  = $flt('syndic_forfait_annuel_lot');
    $syndicForfaitMin  = $flt('syndic_forfait_min');
    $syndicRemBase     = $flt('syndic_remuneration_base');
    $syndicVisite      = $flt('syndic_visite_immeuble');
    $syndicAg          = $flt('syndic_assemblee_supplementaire');
    $syndicEtatDate    = $flt('syndic_etat_date_pre');
    $syndicMec         = $flt('syndic_mise_en_concurrence');
    $syndicRecouvSimp  = $flt('syndic_recouvrement_simple');
    $syndicRecouvCont  = $flt('syndic_recouvrement_contentieux_pct');
    $syndicArchivPct   = $flt('syndic_archivage_pct');
    $syndicHtml        = $str('syndic_prestations_html');

    // ─── MANDAT RECHERCHE ──
    $mandatRPct        = $flt('mandat_recherche_pct');
    $mandatRForfait    = $flt('mandat_recherche_forfait');

    // ─── INFOS LÉGALES ──
    $cartePro          = $str('carte_pro_numero');
    $carteProCci       = $str('carte_pro_cci');
    $garant            = $str('garant_financier');
    $garantMontant     = $flt('garant_financier_montant');
    $rcp               = $str('assurance_rcp');
    $siret             = $str('siret');
    $rcs               = $str('rcs');
    $tva               = $str('tva_intra');
    $mediation         = $str('mediation_organisme');
    $mediationUrl      = $str('mediation_url');
    $contenuHtml       = $str('contenu_html');

    try {
        if ($row) {
            // UPDATE
            $sql = "
                UPDATE societe_honoraires SET
                    vente_methode=:vm, vente_taux_unique=:vtu, vente_forfait=:vf,
                    vente_tranches_json=:vtj, vente_charge_par_defaut=:vcd, vente_montant_minimum=:vmm,
                    location_zone=:lz, location_honoraires_locataire_m2=:llm,
                    location_honoraires_etat_des_lieux_m2=:lem, location_honoraires_bailleur_pct=:lbp,
                    location_honoraires_bailleur_forfait=:lbf,
                    gestion_pct_loyer=:gpl, gestion_frais_entree_locataire=:gel,
                    gestion_frais_sortie_locataire=:gsl, gestion_renouvellement_bail=:grb,
                    gestion_avenant_bail=:gab, gestion_suivi_travaux_pct=:gstp,
                    gestion_quittance_supplementaire=:gqs, gestion_assurance_loyers_impayes_pct=:ggli,
                    gestion_carence_locative_pct=:gcl, gestion_prestations_html=:gph,
                    syndic_forfait_annuel_lot=:sfl, syndic_forfait_min=:sfm,
                    syndic_remuneration_base=:srb, syndic_visite_immeuble=:svi,
                    syndic_assemblee_supplementaire=:sas, syndic_etat_date_pre=:sed,
                    syndic_mise_en_concurrence=:smec, syndic_recouvrement_simple=:srs,
                    syndic_recouvrement_contentieux_pct=:src, syndic_archivage_pct=:sap,
                    syndic_prestations_html=:sph,
                    mandat_recherche_pct=:mrp, mandat_recherche_forfait=:mrf,
                    carte_pro_numero=:cp, carte_pro_cci=:cc, garant_financier=:gf,
                    garant_financier_montant=:gfm, assurance_rcp=:rcp, siret=:siret,
                    rcs=:rcs, tva_intra=:tva, mediation_organisme=:med, mediation_url=:medu,
                    contenu_html=:html
                WHERE id_societe=:soc
            ";
        } else {
            $sql = "
                INSERT INTO societe_honoraires
                    (id_societe, vente_methode, vente_taux_unique, vente_forfait, vente_tranches_json,
                     vente_charge_par_defaut, vente_montant_minimum,
                     location_zone, location_honoraires_locataire_m2, location_honoraires_etat_des_lieux_m2,
                     location_honoraires_bailleur_pct, location_honoraires_bailleur_forfait,
                     gestion_pct_loyer, gestion_frais_entree_locataire, gestion_frais_sortie_locataire,
                     gestion_renouvellement_bail, gestion_avenant_bail, gestion_suivi_travaux_pct,
                     gestion_quittance_supplementaire, gestion_assurance_loyers_impayes_pct,
                     gestion_carence_locative_pct, gestion_prestations_html,
                     syndic_forfait_annuel_lot, syndic_forfait_min, syndic_remuneration_base,
                     syndic_visite_immeuble, syndic_assemblee_supplementaire, syndic_etat_date_pre,
                     syndic_mise_en_concurrence, syndic_recouvrement_simple,
                     syndic_recouvrement_contentieux_pct, syndic_archivage_pct, syndic_prestations_html,
                     mandat_recherche_pct, mandat_recherche_forfait,
                     carte_pro_numero, carte_pro_cci, garant_financier, garant_financier_montant,
                     assurance_rcp, siret, rcs, tva_intra, mediation_organisme, mediation_url,
                     contenu_html)
                VALUES
                    (:soc, :vm, :vtu, :vf, :vtj, :vcd, :vmm,
                     :lz, :llm, :lem, :lbp, :lbf,
                     :gpl, :gel, :gsl, :grb, :gab, :gstp, :gqs, :ggli, :gcl, :gph,
                     :sfl, :sfm, :srb, :svi, :sas, :sed, :smec, :srs, :src, :sap, :sph,
                     :mrp, :mrf,
                     :cp, :cc, :gf, :gfm, :rcp, :siret, :rcs, :tva, :med, :medu, :html)
            ";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':soc'   => $idSociete,
            ':vm'    => $venteMethode,
            ':vtu'   => $venteTauxUnique,
            ':vf'    => $venteForfait,
            ':vtj'   => $venteTranchesJson,
            ':vcd'   => $venteCharge,
            ':vmm'   => $venteMin,
            ':lz'    => $locZone,
            ':llm'   => $locLocataireM2,
            ':lem'   => $locEdlM2,
            ':lbp'   => $locBailleurPct,
            ':lbf'   => $locBailleurForfait,
            ':gpl'   => $gestionPctLoyer,
            ':gel'   => $gestionEntree,
            ':gsl'   => $gestionSortie,
            ':grb'   => $gestionRenouv,
            ':gab'   => $gestionAvenant,
            ':gstp'  => $gestionTravauxPct,
            ':gqs'   => $gestionQuittance,
            ':ggli'  => $gestionGli,
            ':gcl'   => $gestionCarence,
            ':gph'   => $gestionHtml ?: null,
            ':sfl'   => $syndicForfaitLot,
            ':sfm'   => $syndicForfaitMin,
            ':srb'   => $syndicRemBase,
            ':svi'   => $syndicVisite,
            ':sas'   => $syndicAg,
            ':sed'   => $syndicEtatDate,
            ':smec'  => $syndicMec,
            ':srs'   => $syndicRecouvSimp,
            ':src'   => $syndicRecouvCont,
            ':sap'   => $syndicArchivPct,
            ':sph'   => $syndicHtml ?: null,
            ':mrp'   => $mandatRPct,
            ':mrf'   => $mandatRForfait,
            ':cp'    => $cartePro ?: null,
            ':cc'    => $carteProCci ?: null,
            ':gf'    => $garant ?: null,
            ':gfm'   => $garantMontant,
            ':rcp'   => $rcp ?: null,
            ':siret' => $siret ?: null,
            ':rcs'   => $rcs ?: null,
            ':tva'   => $tva ?: null,
            ':med'   => $mediation ?: null,
            ':medu'  => $mediationUrl ?: null,
            ':html'  => $contenuHtml ?: null,
        ]);
        $success = 'Barème enregistré.';
        // Recharge
        $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? LIMIT 1");
        $stmt->execute([$idSociete]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $errors[] = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
    }
}

// Helper pour pré-remplir
$v = static function (string $k, string $default = '') use ($row): string {
    if (post($k, null) !== null) return (string)post($k, $default);
    return (string)($row[$k] ?? $default);
};

$tranches = [];
if ($row && !empty($row['vente_tranches_json'])) {
    $tranches = json_decode($row['vente_tranches_json'], true) ?: [];
}
if (post('tr_min', null) !== null && is_array($_POST['tr_min'])) {
    // re-saisie après erreur
    $tranches = [];
    foreach ($_POST['tr_min'] as $i => $min) {
        $tranches[] = [
            'min' => $min,
            'max' => $_POST['tr_max'][$i] ?? '',
            'pct' => $_POST['tr_pct'][$i] ?? '',
        ];
    }
}
if (empty($tranches)) {
    $tranches = [
        ['min' => 0, 'max' => 50000, 'pct' => 8.0],
        ['min' => 50000, 'max' => 100000, 'pct' => 6.0],
        ['min' => 100000, 'max' => null, 'pct' => 5.0],
    ];
}

require __DIR__ . '/inc/header.php';
?>

<style>
.hc-wrap { padding: 24px 28px 80px; max-width: 1100px; }
.hc-card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 22px; }
.hc-title { font-size: 16px; font-weight: 700; color: #1a1816; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.hc-sub   { font-size: 12px; color: #888; margin-bottom: 18px; }
.hc-grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.hc-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr 40px; gap: 8px; align-items: center; margin-bottom: 6px; }
.hc-field label { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
.hc-field input, .hc-field select, .hc-field textarea {
  width: 100%; padding: 9px 12px; border: 1px solid #d4d0ca; border-radius: 8px; font-size: 13px; font-family: inherit;
}
.hc-field textarea { resize: vertical; min-height: 80px; }
.hc-section-head { display: flex; align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 2px solid; margin-bottom: 16px; }
.hc-icon { font-size: 28px; }
.hc-card.transaction { border-left: 4px solid #f97316; }
.hc-card.gestion     { border-left: 4px solid #1f6f7a; }
.hc-card.syndic      { border-left: 4px solid #6a4ca8; }
.hc-card.legal       { border-left: 4px solid #2d8659; }
.hc-card.transaction .hc-section-head { border-color: #f97316; }
.hc-card.gestion .hc-section-head { border-color: #1f6f7a; }
.hc-card.syndic .hc-section-head { border-color: #6a4ca8; }
.hc-card.legal .hc-section-head { border-color: #2d8659; }
.hc-add-row { background: none; border: 1px dashed #d4d0ca; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 12px; color: #555; }
.hc-del-row { background: none; border: none; color: #c0392b; font-size: 18px; cursor: pointer; }
.hc-actions { display: flex; gap: 12px; justify-content: space-between; align-items: center; padding: 18px 0; position: sticky; bottom: 0; background: linear-gradient(180deg, transparent, #f5f3ee 30%); }
.hc-save-btn { padding: 12px 24px; background: linear-gradient(135deg, #f97316, #ea580c); color: #fff; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 4px 12px rgba(249,115,22,0.35); }
.hc-public-link { font-size: 12px; color: #1f6f7a; }
.hc-alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; }
.hc-alert.error { background: #fee2e2; color: #991b1b; border-left: 4px solid #c0392b; }
.hc-alert.success { background: #dcfce7; color: #14532d; border-left: 4px solid #2d8659; }
</style>

<main class="mbi-main">
  <header class="topbar">
    <div class="topbar-crumb">Administration · Mes honoraires</div>
  </header>

  <div class="hc-wrap">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;">
      <div>
        <div style="font-family:'DM Mono',monospace;font-size:11px;letter-spacing:1px;color:#7a9060;text-transform:uppercase;">Administration</div>
        <h1 style="font-size:24px;font-weight:700;margin:4px 0 6px;">Barème des honoraires</h1>
        <div style="font-size:13px;color:#888;">Configuration des tarifs publics — obligation arrêté du 10/01/2017 (modifié 26/01/2022) — affichage en tarifs maximums</div>
      </div>
      <a href="<?= h(app_url('/tarifs.php')) ?>" target="_blank" class="hc-public-link" style="padding:8px 14px;background:#fff;border:1px solid #1f6f7a;border-radius:8px;text-decoration:none;font-weight:600;">
        🔗 Voir la page publique
      </a>
    </div>

    <?php if ($errors): ?><div class="hc-alert error"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="hc-alert success">✅ <?= h($success) ?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field('honoraires_config') ?>

      <!-- ════════════════════════════════════════════ -->
      <!-- TRANSACTION (vente + location)               -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card transaction">
        <div class="hc-section-head">
          <span class="hc-icon">🤝</span>
          <div>
            <div class="hc-title">Transaction (Vente & Location)</div>
            <div class="hc-sub">Honoraires de négociation immobilière</div>
          </div>
        </div>

        <h3 style="font-size:14px;margin-bottom:10px;color:#f97316;">— Vente —</h3>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Méthode de calcul</label>
            <select name="vente_methode">
              <option value="tranches"   <?= $v('vente_methode','tranches') === 'tranches'   ? 'selected' : '' ?>>Tranches dégressives</option>
              <option value="pct_unique" <?= $v('vente_methode') === 'pct_unique' ? 'selected' : '' ?>>Pourcentage unique</option>
              <option value="forfait"    <?= $v('vente_methode') === 'forfait'    ? 'selected' : '' ?>>Forfait fixe</option>
              <option value="sur_demande" <?= $v('vente_methode') === 'sur_demande' ? 'selected' : '' ?>>Sur demande</option>
            </select>
          </div>
          <div class="hc-field">
            <label>Charge par défaut</label>
            <select name="vente_charge_par_defaut">
              <option value="acquereur" <?= $v('vente_charge_par_defaut','acquereur') === 'acquereur' ? 'selected' : '' ?>>Acquéreur</option>
              <option value="vendeur"   <?= $v('vente_charge_par_defaut') === 'vendeur'   ? 'selected' : '' ?>>Vendeur</option>
              <option value="partage"   <?= $v('vente_charge_par_defaut') === 'partage'   ? 'selected' : '' ?>>Partagé</option>
            </select>
          </div>
          <div class="hc-field">
            <label>Taux unique (%)</label>
            <input type="number" step="0.01" name="vente_taux_unique" value="<?= h($v('vente_taux_unique')) ?>" placeholder="Ex: 5.00">
          </div>
          <div class="hc-field">
            <label>Forfait fixe (€)</label>
            <input type="number" step="0.01" name="vente_forfait" value="<?= h($v('vente_forfait')) ?>" placeholder="Ex: 8000">
          </div>
          <div class="hc-field">
            <label>Plancher (€)</label>
            <input type="number" step="0.01" name="vente_montant_minimum" value="<?= h($v('vente_montant_minimum')) ?>" placeholder="Ex: 5000">
          </div>
        </div>

        <h4 style="font-size:12px;margin:18px 0 8px;color:#555;">Tranches dégressives (si méthode = Tranches)</h4>
        <div id="tranches-wrap">
          <div class="hc-grid-3" style="font-size:11px;font-weight:600;color:#888;">
            <div>Prix de</div>
            <div>Prix à</div>
            <div>Taux %</div>
            <div></div>
          </div>
          <?php foreach ($tranches as $i => $t): ?>
          <div class="hc-grid-3 tr-row">
            <input type="number" step="0.01" name="tr_min[]" value="<?= h((string)($t['min'] ?? '')) ?>" placeholder="0">
            <input type="number" step="0.01" name="tr_max[]" value="<?= h((string)($t['max'] ?? '')) ?>" placeholder="(illimité)">
            <input type="number" step="0.01" name="tr_pct[]" value="<?= h((string)($t['pct'] ?? '')) ?>" placeholder="ex: 6.00">
            <button type="button" class="hc-del-row" onclick="this.closest('.tr-row').remove()">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="hc-add-row" onclick="addTranche()">＋ Ajouter une tranche</button>

        <h3 style="font-size:14px;margin:24px 0 10px;color:#f97316;">— Location —</h3>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Zone tendue</label>
            <select name="location_zone">
              <option value="non_tendue"  <?= $v('location_zone','non_tendue') === 'non_tendue'  ? 'selected' : '' ?>>Non tendue</option>
              <option value="tendue"      <?= $v('location_zone') === 'tendue'      ? 'selected' : '' ?>>Tendue</option>
              <option value="tres_tendue" <?= $v('location_zone') === 'tres_tendue' ? 'selected' : '' ?>>Très tendue</option>
            </select>
          </div>
          <div class="hc-field">
            <label>Hon. locataire (€/m²) — visite + bail + dossier</label>
            <input type="number" step="0.01" name="location_honoraires_locataire_m2" value="<?= h($v('location_honoraires_locataire_m2')) ?>" placeholder="Plafond légal : 8/10/12">
          </div>
          <div class="hc-field">
            <label>État des lieux (€/m²)</label>
            <input type="number" step="0.01" name="location_honoraires_etat_des_lieux_m2" value="<?= h($v('location_honoraires_etat_des_lieux_m2','3.00')) ?>">
          </div>
          <div class="hc-field">
            <label>Hon. bailleur (% loyer annuel)</label>
            <input type="number" step="0.01" name="location_honoraires_bailleur_pct" value="<?= h($v('location_honoraires_bailleur_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Hon. bailleur — forfait (€)</label>
            <input type="number" step="0.01" name="location_honoraires_bailleur_forfait" value="<?= h($v('location_honoraires_bailleur_forfait')) ?>">
          </div>
        </div>
      </div>

      <!-- ════════════════════════════════════════════ -->
      <!-- GESTION LOCATIVE                              -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card gestion">
        <div class="hc-section-head">
          <span class="hc-icon">🔑</span>
          <div>
            <div class="hc-title">Gestion locative</div>
            <div class="hc-sub">Honoraires de gestion d'un bien donné en location</div>
          </div>
        </div>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Honoraires de gestion (% du loyer encaissé)</label>
            <input type="number" step="0.01" name="gestion_pct_loyer" value="<?= h($v('gestion_pct_loyer')) ?>" placeholder="Ex: 7.00">
          </div>
          <div class="hc-field">
            <label>Frais d'entrée locataire (€)</label>
            <input type="number" step="0.01" name="gestion_frais_entree_locataire" value="<?= h($v('gestion_frais_entree_locataire')) ?>">
          </div>
          <div class="hc-field">
            <label>Frais sortie / état des lieux (€)</label>
            <input type="number" step="0.01" name="gestion_frais_sortie_locataire" value="<?= h($v('gestion_frais_sortie_locataire')) ?>">
          </div>
          <div class="hc-field">
            <label>Renouvellement bail (€)</label>
            <input type="number" step="0.01" name="gestion_renouvellement_bail" value="<?= h($v('gestion_renouvellement_bail')) ?>">
          </div>
          <div class="hc-field">
            <label>Avenant au bail (€)</label>
            <input type="number" step="0.01" name="gestion_avenant_bail" value="<?= h($v('gestion_avenant_bail')) ?>">
          </div>
          <div class="hc-field">
            <label>Suivi travaux (% montant)</label>
            <input type="number" step="0.01" name="gestion_suivi_travaux_pct" value="<?= h($v('gestion_suivi_travaux_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Quittance supplémentaire (€)</label>
            <input type="number" step="0.01" name="gestion_quittance_supplementaire" value="<?= h($v('gestion_quittance_supplementaire')) ?>">
          </div>
          <div class="hc-field">
            <label>GLI — assurance loyers impayés (%)</label>
            <input type="number" step="0.01" name="gestion_assurance_loyers_impayes_pct" value="<?= h($v('gestion_assurance_loyers_impayes_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Carence locative (%)</label>
            <input type="number" step="0.01" name="gestion_carence_locative_pct" value="<?= h($v('gestion_carence_locative_pct')) ?>">
          </div>
        </div>
        <div class="hc-field" style="margin-top:14px;">
          <label>Détail des prestations incluses (HTML libre)</label>
          <textarea name="gestion_prestations_html" placeholder="Liste des prestations couvertes par les honoraires de gestion…"><?= h($v('gestion_prestations_html')) ?></textarea>
        </div>
      </div>

      <!-- ════════════════════════════════════════════ -->
      <!-- SYNDIC DE COPROPRIÉTÉ                         -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card syndic">
        <div class="hc-section-head">
          <span class="hc-icon">🏢</span>
          <div>
            <div class="hc-title">Syndic de copropriété</div>
            <div class="hc-sub">Honoraires du contrat type de syndic — décret du 26/03/2015</div>
          </div>
        </div>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Forfait annuel par lot (€/lot/an)</label>
            <input type="number" step="0.01" name="syndic_forfait_annuel_lot" value="<?= h($v('syndic_forfait_annuel_lot')) ?>" placeholder="Ex: 180">
          </div>
          <div class="hc-field">
            <label>Plancher annuel total (€)</label>
            <input type="number" step="0.01" name="syndic_forfait_min" value="<?= h($v('syndic_forfait_min')) ?>" placeholder="Ex: 1500">
          </div>
          <div class="hc-field">
            <label>Rémunération de base (€/an)</label>
            <input type="number" step="0.01" name="syndic_remuneration_base" value="<?= h($v('syndic_remuneration_base')) ?>">
          </div>
          <div class="hc-field">
            <label>Visite immeuble supplémentaire (€)</label>
            <input type="number" step="0.01" name="syndic_visite_immeuble" value="<?= h($v('syndic_visite_immeuble')) ?>">
          </div>
          <div class="hc-field">
            <label>AG supplémentaire (€)</label>
            <input type="number" step="0.01" name="syndic_assemblee_supplementaire" value="<?= h($v('syndic_assemblee_supplementaire')) ?>">
          </div>
          <div class="hc-field">
            <label>État daté pré-vente (€)</label>
            <input type="number" step="0.01" name="syndic_etat_date_pre" value="<?= h($v('syndic_etat_date_pre')) ?>" placeholder="Plafond légal: 380€">
          </div>
          <div class="hc-field">
            <label>Mise en concurrence travaux (€)</label>
            <input type="number" step="0.01" name="syndic_mise_en_concurrence" value="<?= h($v('syndic_mise_en_concurrence')) ?>">
          </div>
          <div class="hc-field">
            <label>Recouvrement simple (€)</label>
            <input type="number" step="0.01" name="syndic_recouvrement_simple" value="<?= h($v('syndic_recouvrement_simple')) ?>">
          </div>
          <div class="hc-field">
            <label>Recouvrement contentieux (% somme)</label>
            <input type="number" step="0.01" name="syndic_recouvrement_contentieux_pct" value="<?= h($v('syndic_recouvrement_contentieux_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Frais d'archivage (% honoraires)</label>
            <input type="number" step="0.01" name="syndic_archivage_pct" value="<?= h($v('syndic_archivage_pct')) ?>">
          </div>
        </div>
        <div class="hc-field" style="margin-top:14px;">
          <label>Tableau libre des prestations particulières (HTML)</label>
          <textarea name="syndic_prestations_html" placeholder="Détails complémentaires…"><?= h($v('syndic_prestations_html')) ?></textarea>
        </div>
      </div>

      <!-- ════════════════════════════════════════════ -->
      <!-- INFORMATIONS LÉGALES                          -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card legal">
        <div class="hc-section-head">
          <span class="hc-icon">⚖️</span>
          <div>
            <div class="hc-title">Informations légales du professionnel</div>
            <div class="hc-sub">Mentions obligatoires Loi Hoguet</div>
          </div>
        </div>
        <div class="hc-grid">
          <div class="hc-field">
            <label>N° carte professionnelle</label>
            <input type="text" name="carte_pro_numero" value="<?= h($v('carte_pro_numero')) ?>">
          </div>
          <div class="hc-field">
            <label>CCI émettrice</label>
            <input type="text" name="carte_pro_cci" value="<?= h($v('carte_pro_cci')) ?>">
          </div>
          <div class="hc-field">
            <label>Garant financier</label>
            <input type="text" name="garant_financier" value="<?= h($v('garant_financier')) ?>">
          </div>
          <div class="hc-field">
            <label>Montant garantie (€)</label>
            <input type="number" step="0.01" name="garant_financier_montant" value="<?= h($v('garant_financier_montant')) ?>">
          </div>
          <div class="hc-field">
            <label>Assurance RCP</label>
            <input type="text" name="assurance_rcp" value="<?= h($v('assurance_rcp')) ?>">
          </div>
          <div class="hc-field">
            <label>SIRET</label>
            <input type="text" name="siret" value="<?= h($v('siret')) ?>">
          </div>
          <div class="hc-field">
            <label>RCS</label>
            <input type="text" name="rcs" value="<?= h($v('rcs')) ?>">
          </div>
          <div class="hc-field">
            <label>TVA intracommunautaire</label>
            <input type="text" name="tva_intra" value="<?= h($v('tva_intra')) ?>">
          </div>
          <div class="hc-field">
            <label>Médiateur consommation</label>
            <input type="text" name="mediation_organisme" value="<?= h($v('mediation_organisme')) ?>">
          </div>
          <div class="hc-field">
            <label>URL du médiateur</label>
            <input type="url" name="mediation_url" value="<?= h($v('mediation_url')) ?>">
          </div>
        </div>
        <div class="hc-field" style="margin-top:14px;">
          <label>Mentions complémentaires (HTML libre)</label>
          <textarea name="contenu_html" placeholder="Texte additionnel à afficher en bas de la page publique…"><?= h($v('contenu_html')) ?></textarea>
        </div>
      </div>

      <div class="hc-actions">
        <div style="font-size:11px;color:#888;">Dernière mise à jour : <?= h($row['date_mise_a_jour'] ?? '—') ?></div>
        <button type="submit" class="hc-save-btn">💾 Enregistrer le barème</button>
      </div>
    </form>
  </div>
</main>

<script>
function addTranche() {
  const wrap = document.getElementById('tranches-wrap');
  const row = document.createElement('div');
  row.className = 'hc-grid-3 tr-row';
  row.innerHTML = `
    <input type="number" step="0.01" name="tr_min[]" placeholder="0">
    <input type="number" step="0.01" name="tr_max[]" placeholder="(illimité)">
    <input type="number" step="0.01" name="tr_pct[]" placeholder="ex: 6.00">
    <button type="button" class="hc-del-row" onclick="this.closest('.tr-row').remove()">✕</button>`;
  wrap.appendChild(row);
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
