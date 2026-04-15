<?php
declare(strict_types=1);

/**
 * PAGE PUBLIQUE — Barème des honoraires
 * ─────────────────────────────────────────
 * Affichage public obligatoire des tarifs maximums du professionnel,
 * arrêté du 10/01/2017 modifié par l'arrêté du 26/01/2022.
 *
 * Accessible sans authentification, en 2 clics depuis la home.
 * Indexable par les moteurs de recherche (référence légale).
 *
 * URL d'accès : /tarifs.php  (ou /tarifs.php?societe=ID pour multi-société)
 */

require_once __DIR__ . '/config/db.php';

$pdo = db();

// Détermination de la société à afficher
$idSociete = isset($_GET['societe']) && ctype_digit((string)$_GET['societe'])
    ? (int)$_GET['societe']
    : 0;

if ($idSociete === 0) {
    // Société par défaut : la première trouvée (single-tenant ou racine)
    try {
        $idSociete = (int)$pdo->query("SELECT id_societe FROM societe_honoraires LIMIT 1")->fetchColumn();
    } catch (Throwable) { $idSociete = 0; }
}

$row = null;
$societe = null;
if ($idSociete > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? LIMIT 1");
        $stmt->execute([$idSociete]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Infos société (optionnel — table peut ne pas exister)
        try {
            $stmt2 = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
            $stmt2->execute([$idSociete]);
            $societe = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {}
    } catch (Throwable) {}
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 2, ',', ' ') . ' €' : '—';
$pct   = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 2, ',', ' ') . ' %' : '—';

$tranches = [];
if ($row && !empty($row['vente_tranches_json'])) {
    $tranches = json_decode($row['vente_tranches_json'], true) ?: [];
}

$nomAgence = $societe['nom'] ?? $societe['raison_sociale'] ?? 'Notre agence';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Barème des honoraires — <?= $h($nomAgence) ?></title>
  <meta name="description" content="Barème des honoraires de <?= $h($nomAgence) ?> — affichage légal des tarifs maximums (arrêté 10/01/2017).">
  <meta name="robots" content="index, follow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Sora', system-ui, sans-serif; background: #f5f3ee; color: #1a1816; line-height: 1.5; }
    .hero {
      background: linear-gradient(135deg, #36577d, #1f6f7a);
      color: #fff;
      padding: 50px 20px 60px;
      text-align: center;
    }
    .hero h1 { font-size: 32px; font-weight: 800; margin-bottom: 8px; }
    .hero .sub { font-size: 14px; opacity: .85; max-width: 700px; margin: 0 auto; }
    .legal-note { display: inline-block; margin-top: 14px; padding: 6px 14px; background: rgba(255,255,255,.15); border-radius: 99px; font-size: 11px; font-weight: 600; }

    .container { max-width: 1000px; margin: -30px auto 60px; padding: 0 20px; position: relative; }

    .card {
      background: #fff;
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 4px 20px rgba(0,0,0,.06);
      margin-bottom: 24px;
      border-left: 4px solid;
    }
    .card.transaction { border-left-color: #f97316; }
    .card.gestion     { border-left-color: #1f6f7a; }
    .card.syndic      { border-left-color: #6a4ca8; }
    .card.legal       { border-left-color: #2d8659; }

    .card-head { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px dashed #e8e6e1; }
    .card-icon { font-size: 32px; }
    .card-title { font-size: 22px; font-weight: 700; }
    .card-sub { font-size: 12px; color: #888; margin-top: 2px; }

    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; }
    .row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1efe9; font-size: 14px; }
    .row .label { color: #555; }
    .row .value { font-weight: 700; color: #1a1816; }
    .row.empty .value { color: #ccc; font-weight: 400; }

    .subtitle { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #888; margin: 24px 0 12px; }
    .subtitle:first-child { margin-top: 0; }

    table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e8e6e1; font-size: 14px; }
    th { background: #fafaf7; font-weight: 600; color: #555; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
    .pct-cell { font-weight: 700; color: #f97316; font-size: 16px; }

    .free-text { background: #fafaf7; padding: 14px 18px; border-radius: 10px; font-size: 13px; color: #555; line-height: 1.6; margin-top: 14px; }
    .footer { text-align: center; padding: 30px 20px 50px; font-size: 12px; color: #888; }
    .empty-state { text-align: center; padding: 60px 20px; color: #888; }
    .empty-state h2 { font-size: 18px; margin-bottom: 10px; color: #555; }
  </style>
</head>
<body>

<div class="hero">
  <h1>Barème des honoraires</h1>
  <div class="sub"><?= $h($nomAgence) ?> — Affichage public des tarifs maximums TTC</div>
  <span class="legal-note">📜 Arrêté du 10 janvier 2017 — Information du consommateur</span>
</div>

<div class="container">

<?php if (!$row): ?>
  <div class="card">
    <div class="empty-state">
      <h2>Barème non encore renseigné</h2>
      <p>Ce barème sera prochainement disponible.</p>
    </div>
  </div>
<?php else: ?>

  <!-- ════ TRANSACTION ════ -->
  <div class="card transaction">
    <div class="card-head">
      <div class="card-icon">🤝</div>
      <div>
        <div class="card-title">Transaction immobilière</div>
        <div class="card-sub">Vente & location — honoraires de négociation</div>
      </div>
    </div>

    <div class="subtitle">Vente</div>

    <?php if ($row['vente_methode'] === 'tranches' && $tranches): ?>
      <table>
        <thead>
          <tr>
            <th>Prix de vente</th>
            <th style="text-align:right;">Taux maximum TTC</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tranches as $t): ?>
          <tr>
            <td>
              <?php
                $min = $t['min'] ?? 0;
                $max = $t['max'] ?? null;
                if ($max === null || $max === '') {
                    echo 'Au-delà de ' . number_format((float)$min, 0, ',', ' ') . ' €';
                } elseif ((float)$min === 0.0) {
                    echo 'Jusqu\'à ' . number_format((float)$max, 0, ',', ' ') . ' €';
                } else {
                    echo 'De ' . number_format((float)$min, 0, ',', ' ') . ' € à ' . number_format((float)$max, 0, ',', ' ') . ' €';
                }
              ?>
            </td>
            <td class="pct-cell" style="text-align:right;"><?= $pct($t['pct'] ?? null) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif ($row['vente_methode'] === 'pct_unique'): ?>
      <p style="font-size:16px;"><strong>Taux unique :</strong> <span class="pct-cell"><?= $pct($row['vente_taux_unique']) ?></span> appliqué au prix net vendeur</p>
    <?php elseif ($row['vente_methode'] === 'forfait'): ?>
      <p style="font-size:16px;"><strong>Forfait fixe :</strong> <span class="pct-cell"><?= $money($row['vente_forfait']) ?></span></p>
    <?php else: ?>
      <p style="font-size:14px;color:#888;font-style:italic;">Tarifs sur demande — nous consulter.</p>
    <?php endif; ?>

    <div class="grid" style="margin-top:14px;">
      <div class="row"><span class="label">Charge par défaut</span><span class="value"><?= $h(ucfirst((string)$row['vente_charge_par_defaut'])) ?></span></div>
      <div class="row<?= empty($row['vente_montant_minimum']) ? ' empty' : '' ?>"><span class="label">Plancher</span><span class="value"><?= $money($row['vente_montant_minimum']) ?></span></div>
    </div>

    <div class="subtitle">Location</div>
    <div class="grid">
      <div class="row"><span class="label">Zone</span><span class="value"><?= $h(str_replace('_',' ',(string)$row['location_zone'])) ?></span></div>
      <div class="row<?= empty($row['location_honoraires_locataire_m2']) ? ' empty' : '' ?>"><span class="label">Hon. locataire (€/m²)</span><span class="value"><?= $money($row['location_honoraires_locataire_m2']) ?></span></div>
      <div class="row<?= empty($row['location_honoraires_etat_des_lieux_m2']) ? ' empty' : '' ?>"><span class="label">État des lieux (€/m²)</span><span class="value"><?= $money($row['location_honoraires_etat_des_lieux_m2']) ?></span></div>
      <div class="row<?= empty($row['location_honoraires_bailleur_pct']) ? ' empty' : '' ?>"><span class="label">Hon. bailleur (% loyer annuel)</span><span class="value"><?= $pct($row['location_honoraires_bailleur_pct']) ?></span></div>
      <div class="row<?= empty($row['location_honoraires_bailleur_forfait']) ? ' empty' : '' ?>"><span class="label">Hon. bailleur — forfait</span><span class="value"><?= $money($row['location_honoraires_bailleur_forfait']) ?></span></div>
    </div>
  </div>

  <!-- ════ GESTION LOCATIVE ════ -->
  <div class="card gestion">
    <div class="card-head">
      <div class="card-icon">🔑</div>
      <div>
        <div class="card-title">Gestion locative</div>
        <div class="card-sub">Honoraires de gestion d'un bien donné en location</div>
      </div>
    </div>
    <div class="grid">
      <div class="row<?= empty($row['gestion_pct_loyer']) ? ' empty' : '' ?>"><span class="label">Honoraires de gestion (% loyer encaissé)</span><span class="value"><?= $pct($row['gestion_pct_loyer']) ?></span></div>
      <div class="row<?= empty($row['gestion_frais_entree_locataire']) ? ' empty' : '' ?>"><span class="label">Frais d'entrée locataire</span><span class="value"><?= $money($row['gestion_frais_entree_locataire']) ?></span></div>
      <div class="row<?= empty($row['gestion_frais_sortie_locataire']) ? ' empty' : '' ?>"><span class="label">Frais sortie / état des lieux</span><span class="value"><?= $money($row['gestion_frais_sortie_locataire']) ?></span></div>
      <div class="row<?= empty($row['gestion_renouvellement_bail']) ? ' empty' : '' ?>"><span class="label">Renouvellement bail</span><span class="value"><?= $money($row['gestion_renouvellement_bail']) ?></span></div>
      <div class="row<?= empty($row['gestion_avenant_bail']) ? ' empty' : '' ?>"><span class="label">Avenant au bail</span><span class="value"><?= $money($row['gestion_avenant_bail']) ?></span></div>
      <div class="row<?= empty($row['gestion_suivi_travaux_pct']) ? ' empty' : '' ?>"><span class="label">Suivi travaux (% montant)</span><span class="value"><?= $pct($row['gestion_suivi_travaux_pct']) ?></span></div>
      <div class="row<?= empty($row['gestion_quittance_supplementaire']) ? ' empty' : '' ?>"><span class="label">Quittance supplémentaire</span><span class="value"><?= $money($row['gestion_quittance_supplementaire']) ?></span></div>
      <div class="row<?= empty($row['gestion_assurance_loyers_impayes_pct']) ? ' empty' : '' ?>"><span class="label">GLI — Assurance loyers impayés</span><span class="value"><?= $pct($row['gestion_assurance_loyers_impayes_pct']) ?></span></div>
      <div class="row<?= empty($row['gestion_carence_locative_pct']) ? ' empty' : '' ?>"><span class="label">Carence locative</span><span class="value"><?= $pct($row['gestion_carence_locative_pct']) ?></span></div>
    </div>
    <?php if (!empty($row['gestion_prestations_html'])): ?>
    <div class="free-text"><?= nl2br($h($row['gestion_prestations_html'])) ?></div>
    <?php endif; ?>
  </div>

  <!-- ════ SYNDIC ════ -->
  <div class="card syndic">
    <div class="card-head">
      <div class="card-icon">🏢</div>
      <div>
        <div class="card-title">Syndic de copropriété</div>
        <div class="card-sub">Décret n° 2015-342 du 26/03/2015 — contrat type</div>
      </div>
    </div>
    <div class="grid">
      <div class="row<?= empty($row['syndic_forfait_annuel_lot']) ? ' empty' : '' ?>"><span class="label">Forfait annuel par lot</span><span class="value"><?= $money($row['syndic_forfait_annuel_lot']) ?></span></div>
      <div class="row<?= empty($row['syndic_forfait_min']) ? ' empty' : '' ?>"><span class="label">Plancher annuel total</span><span class="value"><?= $money($row['syndic_forfait_min']) ?></span></div>
      <div class="row<?= empty($row['syndic_remuneration_base']) ? ' empty' : '' ?>"><span class="label">Rémunération de base</span><span class="value"><?= $money($row['syndic_remuneration_base']) ?></span></div>
      <div class="row<?= empty($row['syndic_visite_immeuble']) ? ' empty' : '' ?>"><span class="label">Visite immeuble supplémentaire</span><span class="value"><?= $money($row['syndic_visite_immeuble']) ?></span></div>
      <div class="row<?= empty($row['syndic_assemblee_supplementaire']) ? ' empty' : '' ?>"><span class="label">AG supplémentaire</span><span class="value"><?= $money($row['syndic_assemblee_supplementaire']) ?></span></div>
      <div class="row<?= empty($row['syndic_etat_date_pre']) ? ' empty' : '' ?>"><span class="label">État daté pré-vente</span><span class="value"><?= $money($row['syndic_etat_date_pre']) ?></span></div>
      <div class="row<?= empty($row['syndic_mise_en_concurrence']) ? ' empty' : '' ?>"><span class="label">Mise en concurrence travaux</span><span class="value"><?= $money($row['syndic_mise_en_concurrence']) ?></span></div>
      <div class="row<?= empty($row['syndic_recouvrement_simple']) ? ' empty' : '' ?>"><span class="label">Recouvrement simple</span><span class="value"><?= $money($row['syndic_recouvrement_simple']) ?></span></div>
      <div class="row<?= empty($row['syndic_recouvrement_contentieux_pct']) ? ' empty' : '' ?>"><span class="label">Recouvrement contentieux</span><span class="value"><?= $pct($row['syndic_recouvrement_contentieux_pct']) ?></span></div>
      <div class="row<?= empty($row['syndic_archivage_pct']) ? ' empty' : '' ?>"><span class="label">Frais d'archivage</span><span class="value"><?= $pct($row['syndic_archivage_pct']) ?></span></div>
    </div>
    <?php if (!empty($row['syndic_prestations_html'])): ?>
    <div class="free-text"><?= nl2br($h($row['syndic_prestations_html'])) ?></div>
    <?php endif; ?>
  </div>

  <!-- ════ INFOS LÉGALES ════ -->
  <div class="card legal">
    <div class="card-head">
      <div class="card-icon">⚖️</div>
      <div>
        <div class="card-title">Informations légales</div>
        <div class="card-sub">Mentions obligatoires — Loi Hoguet</div>
      </div>
    </div>
    <div class="grid">
      <div class="row<?= empty($row['carte_pro_numero']) ? ' empty' : '' ?>"><span class="label">N° carte professionnelle</span><span class="value"><?= $h($row['carte_pro_numero'] ?? '—') ?></span></div>
      <div class="row<?= empty($row['carte_pro_cci']) ? ' empty' : '' ?>"><span class="label">CCI émettrice</span><span class="value"><?= $h($row['carte_pro_cci'] ?? '—') ?></span></div>
      <div class="row<?= empty($row['garant_financier']) ? ' empty' : '' ?>"><span class="label">Garant financier</span><span class="value"><?= $h($row['garant_financier'] ?? '—') ?></span></div>
      <div class="row<?= empty($row['garant_financier_montant']) ? ' empty' : '' ?>"><span class="label">Montant garantie</span><span class="value"><?= $money($row['garant_financier_montant']) ?></span></div>
      <div class="row<?= empty($row['assurance_rcp']) ? ' empty' : '' ?>"><span class="label">Assurance RCP</span><span class="value"><?= $h($row['assurance_rcp'] ?? '—') ?></span></div>
      <div class="row<?= empty($row['siret']) ? ' empty' : '' ?>"><span class="label">SIRET</span><span class="value"><?= $h($row['siret'] ?? '—') ?></span></div>
      <div class="row<?= empty($row['rcs']) ? ' empty' : '' ?>"><span class="label">RCS</span><span class="value"><?= $h($row['rcs'] ?? '—') ?></span></div>
      <div class="row<?= empty($row['tva_intra']) ? ' empty' : '' ?>"><span class="label">TVA intracommunautaire</span><span class="value"><?= $h($row['tva_intra'] ?? '—') ?></span></div>
    </div>
    <?php if (!empty($row['mediation_organisme'])): ?>
    <div style="margin-top:18px;padding:14px;background:#f0f9ff;border-radius:10px;font-size:13px;">
      <strong>Médiation de la consommation :</strong> <?= $h($row['mediation_organisme']) ?>
      <?php if (!empty($row['mediation_url'])): ?>
        — <a href="<?= $h($row['mediation_url']) ?>" target="_blank" rel="noopener" style="color:#1f6f7a;">Saisir le médiateur</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($row['contenu_html'])): ?>
    <div class="free-text" style="margin-top:18px;"><?= nl2br($h($row['contenu_html'])) ?></div>
    <?php endif; ?>
  </div>

<?php endif; ?>

</div>

<div class="footer">
  <p>Barème mis à jour le <?= $h(!empty($row['date_mise_a_jour']) ? date('d/m/Y', strtotime((string)$row['date_mise_a_jour'])) : '—') ?></p>
  <p style="margin-top:8px;">Conformément à l'arrêté du 10 janvier 2017 modifié — Information des consommateurs par les professionnels intervenant dans une transaction immobilière.</p>
</div>

</body>
</html>
