<?php
// immeuble_360.php — Vue 360° d'un immeuble
// Lots, propriétaires, syndic, documents, mentions, IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$immId = (int)($_GET['id'] ?? 0);
if ($immId <= 0) {
    header('Location: ' . app_url('/bien_liste.php'));
    exit;
}

// ─── Charge l'immeuble ──
try {
    $st = $pdo->prepare("SELECT * FROM immeubles WHERE id = ? LIMIT 1");
    $st->execute([$immId]);
    $imm = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[immeuble_360] chargement immeuble #' . $immId . ' : ' . $e->getMessage());
    http_response_code(500);
    exit('<pre style="font:13px/1.5 monospace;padding:24px;color:#b91c1c;">'
        . 'Erreur de chargement de la fiche immeuble (#' . (int)$immId . ").\n\n"
        . htmlspecialchars($e->getMessage())
        . "\n\nProbable colonne absente sur cette base. Transmets cette ligne pour correction.</pre>");
}
if (!$imm) {
    http_response_code(404);
    exit('Immeuble introuvable.');
}

$adresseComplete = trim((string)($imm['adresse_1'] ?? '') . ' ' . ($imm['code_postal'] ?? '') . ' ' . ($imm['ville'] ?? ''));
$nomAffichage    = $imm['nom_immeuble'] ?: ($imm['adresse_1'] ?: 'Immeuble #' . $immId);

// ─── Biens / lots de l'immeuble ──
$biens = [];
try {
    $stB = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation, b.numero_lot, b.usage_bien,
        b.surface_habitable, b.etage, b.type_commercialisation, b.statut_occupation, b.statut_bien, b.prix_final_vente AS prix_vente,
        COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom,
        (SELECT COUNT(*) FROM annonces an WHERE an.id_bien = b.id AND (an.statut='publiee' OR an.etat_publication='diffusee')) AS nb_annonces_actives,
        (SELECT bb.bail_nature FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif' LIMIT 1) AS bail_nature,
        (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS nb_baux_actifs
        FROM biens b
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE b.id_immeuble = ?
        ORDER BY b.numero_lot ASC, b.id ASC
        LIMIT 200");
    $stB->execute([$immId]);
    $biens = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Propriétaires distincts (via les biens de l'immeuble) ──
$proprios = [];
try {
    $stP = $pdo->prepare("SELECT DISTINCT p.id, p.id_tiers,
        COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS nom,
        (SELECT COUNT(*) FROM biens WHERE id_proprietaire = p.id AND id_immeuble = ?) AS nb_biens
        FROM proprietaires p
        INNER JOIN biens b ON b.id_proprietaire = p.id
        WHERE b.id_immeuble = ?
        ORDER BY nb_biens DESC, nom ASC
        LIMIT 30");
    $stP->execute([$immId, $immId]);
    $proprios = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Documents GED de l'immeuble ──
$docs = [];
try {
    $stD = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND (
              id_immeuble = ?
              OR JSON_EXTRACT(metadata, '$.classement.immeuble_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'immeuble', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$immId, $immId, $immId]);
    $docs = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

// ─── Checklist pièces immeuble ──
$piecesImm = [
    ['code'=>'REGLEMENT_COPRO',  'label'=>'Règlement de copropriété', 'sublabel'=>'Si copropriété'],
    ['code'=>'CARNET_ENTRETIEN', 'label'=>"Carnet d'entretien",       'sublabel'=>'Suivi équipements'],
    ['code'=>'AG_PV',            'label'=>'PV d\'AG',                 'sublabel'=>'Dernier exercice'],
    ['code'=>'DIAG_PARTIES_COM', 'label'=>'Diagnostics parties communes','sublabel'=>'Amiante, plomb…'],
    ['code'=>'CADASTRE',         'label'=>'Extrait cadastral',        'sublabel'=>'Référence parcelle'],
];
$piecesItems = [];
foreach ($piecesImm as $p) {
    $piecesItems[] = [
        'label'    => $p['label'],
        'sublabel' => $p['sublabel'],
        'ok'       => isset($docsByType[$p['code']]),
        'add_url'  => app_url('/transaction_chargement.php'),
    ];
}

// ─── Mentions (CRG, courriers qui citent cet immeuble) ──
$mentions = [];
try {
    $stM = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND source_module <> '05_TRANSACTION'
          AND (
              id_immeuble = ?
              OR JSON_EXTRACT(metadata, '$.classement.immeuble_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'immeuble', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 10");
    $stM->execute([$immId, $immId, $immId]);
    $mentions = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Infos syndic/gestion (immeubles_infos) ──
$infos = [];
try {
    $stI = $pdo->prepare("SELECT * FROM immeubles_infos WHERE id_immeuble = ? LIMIT 1");
    $stI->execute([$immId]);
    $infos = $stI->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Mandats (immeubles_gestion) : qui gère, à quel titre ──
$mandats = [];
try {
    $stG = $pdo->prepare("SELECT ig.*, s.nom AS societe_nom, e.nom AS agence_nom,
        CONCAT_WS(' ', ug.prenom, ug.nom) AS gestionnaire,
        CONCAT_WS(' ', ua.prenom, ua.nom) AS assistante,
        CONCAT_WS(' ', uc.prenom, uc.nom) AS comptable
        FROM immeubles_gestion ig
        LEFT JOIN societes s       ON s.id  = ig.id_societe
        LEFT JOIN etablissements e ON e.id  = ig.id_agence
        LEFT JOIN users ug ON ug.id = ig.id_gestionnaire
        LEFT JOIN users ua ON ua.id = ig.id_assistante
        LEFT JOIN users uc ON uc.id = ig.id_comptable
        WHERE ig.id_immeuble = ? ORDER BY ig.type");
    $stG->execute([$immId]);
    $mandats = $stG->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$mandatTypes = array_values(array_unique(array_filter(array_map(fn($m)=>$m['type'], $mandats))));
$estSyndic   = in_array('SYNDIC', $mandatTypes, true);

// ─── Catégorie DÉDUITE (même logique que la liste agency_immeubles.php) ──
// Fallback quand aucun mandat n'est enregistré dans immeubles_gestion :
// 1) override manuel categorie_mbi ; 2) SYNDIC auto = réf copro 1000-1200/2000-2200/3000-3200
//    + plusieurs lots + PAS créé par CRG ; 3) GESTION par défaut.
$catDeduite = '';
$cmImm = strtoupper(trim((string)($imm['categorie_mbi'] ?? '')));
if (in_array($cmImm, ['SYNDIC','GESTION','TRANSACTION'], true)) {
    $catDeduite = $cmImm;
} else {
    $refImm = (string)($imm['reference_immeuble'] ?? '');
    $isCrgImm = trim((string)($imm['code_crg'] ?? '')) !== '';
    if (!$isCrgImm && (int)($imm['nb_lots'] ?? 0) > 1 && ctype_digit($refImm)) {
        $n = (int)$refImm;
        if (($n>=1000&&$n<=1200)||($n>=2000&&$n<=2200)||($n>=3000&&$n<=3200)) $catDeduite = 'SYNDIC';
    }
    if ($catDeduite === '') $catDeduite = 'GESTION';
}
$estSyndic = $estSyndic || $catDeduite === 'SYNDIC';

// ─── Mandats de GESTION LOCATIVE au niveau des LOTS (table mandats par bien) ──
// Un immeuble peut cumuler : syndic (copro) ET 1..n mandats de gestion sur ses lots.
$mandatsGestionLots = [];
try {
    $bidsImm = array_map(fn($b) => (int)$b['id'], $biens);
    if ($bidsImm) {
        $inImm = implode(',', array_fill(0, count($bidsImm), '?'));
        $stGL = $pdo->prepare("SELECT m.id, m.id_bien, m.type_mandat, m.statut, m.numero_mandat,
                m.date_debut, m.date_fin, b.reference_bien, b.numero_lot, p.nom AS proprio_nom,
                (SELECT bb.id_tiers_locataire FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif'
                     ORDER BY bb.date_prise_effet DESC LIMIT 1) AS loc_tiers_id,
                (SELECT COALESCE(NULLIF(bb.locataire_nom,''), TRIM(CONCAT_WS(' ', tl.prenom, tl.nom)))
                     FROM bien_baux bb LEFT JOIN tiers tl ON tl.id = bb.id_tiers_locataire
                     WHERE bb.id_bien=b.id AND bb.statut='actif'
                     ORDER BY bb.date_prise_effet DESC LIMIT 1) AS loc_nom
            FROM mandats m
            JOIN biens b ON b.id = m.id_bien
            LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
            WHERE m.id_bien IN ($inImm)
              AND LOWER(m.type_mandat) IN ('gerance','gestion','location')
              AND (m.statut='actif' OR m.statut='en_cours' OR m.statut IS NULL)
            ORDER BY b.numero_lot, m.id DESC");
        $stGL->execute($bidsImm);
        $mandatsGestionLots = $stGL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {}

// ─── Statut visuel ──
$nbBiens          = count($biens);
$nbBauxActifs     = array_sum(array_map(fn($b) => (int)$b['nb_baux_actifs'], $biens));
$nbBiensVacants   = count(array_filter($biens, fn($b) => ($b['statut_occupation'] ?? '') === 'vacant'));
$nbBiensVendus    = count(array_filter($biens, fn($b) => !empty($b['prix_vente'])));
$pieceManquantes  = count(array_filter($piecesItems, fn($p) => !$p['ok']));

if ($nbBiens === 0) {
    $statusColor = 'gray'; $statusIcon = '⚪'; $statusMsg = 'Immeuble créé · <strong>aucun lot rattaché</strong>.';
} elseif ($nbBauxActifs > 0) {
    $statusColor = 'green'; $statusIcon = '🏢';
    $statusMsg = "<strong>{$nbBauxActifs} bail" . ($nbBauxActifs > 1 ? 's' : '') . " actif" . ($nbBauxActifs > 1 ? 's' : '') . "</strong> sur {$nbBiens} lots.";
} else {
    $statusColor = 'gray'; $statusIcon = '🏢';
    $statusMsg = "{$nbBiens} lot(s) rattaché(s).";
}
$statusAlertes = $pieceManquantes > 0 ? $pieceManquantes . ' pièce(s) à charger' : '';

$pageTitle    = 'Immeuble · ' . ($imm['reference_immeuble'] ?: '#' . $immId);
$pageSubtitle = 'Vue 360° · ' . ($imm['ville'] ?? '');
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<?php
// ─── HEADER ──
$badge = null;
if ($nbBauxActifs > 0) $badge = ['label'=>'Loué','class'=>'loue'];
elseif ($nbBiensVacants > 0) $badge = ['label'=>'Vacant partiel','class'=>'vacant'];

$metas = [];
if (!empty($imm['nb_lots']))     $metas[] = ['icon'=>'🏘','text'=>$imm['nb_lots'] . ' lots'];
if (!empty($imm['nb_niveaux']))  $metas[] = ['icon'=>'🏗','text'=>$imm['nb_niveaux'] . ' niveaux'];
if (!empty($imm['annee_construction'])) $metas[] = ['icon'=>'📅','text'=>'Construit en ' . $imm['annee_construction']];
if (!empty($imm['type_immeuble'])) $metas[] = ['icon'=>'🏢','text'=>$imm['type_immeuble']];

fiche360_header(
    '🏢',
    $nomAffichage,
    $badge,
    $adresseComplete ?: 'Adresse non renseignée',
    $metas,
    [
        ['label'=>'✏️ Éditer','url'=>app_url('/agency_immeuble_form.php?id=' . $immId),'class'=>'tr-btn'],
        ['label'=>'📁 Documents','url'=>app_url('/immeuble_documents_list.php?id=' . $immId),'class'=>'tr-btn tr-btn-primary'],
    ]
);

?>

<style>
.i360-grid3 { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
@media (max-width:900px){ .i360-grid3 { grid-template-columns:1fr; } }
.i360-grid3 > div { min-width:0; }

/* Card Actions — fond bleu pétrole (charte) */
.i360-grid3 .f360-actions { background:linear-gradient(155deg,#34586b,#243f4d); }
.i360-grid3 .f360-actions a:hover { background:rgba(255,255,255,.10); }

/* Boutons d'action du header 360° — style doux (cartes blanches) */
.f360-header-actions { gap:10px; flex-wrap:wrap; }
.f360-header-actions .tr-btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 15px; border-radius:13px;
    border:1px solid #f0ede7; background:#fff; color:#4a5568;
    font-size:13px; font-weight:600; line-height:1; white-space:nowrap;
    text-decoration:none; cursor:pointer;
    box-shadow:0 2px 6px rgba(36,59,92,.08), 0 1px 2px rgba(36,59,92,.04);
    transition:transform .14s ease, box-shadow .14s ease, background .14s ease, color .14s ease;
}
.f360-header-actions .tr-btn:hover {
    color:#243B5C; background:#fbfaf7;
    transform:translateY(-1px);
    box-shadow:0 5px 14px rgba(36,59,92,.12), 0 2px 4px rgba(36,59,92,.06);
}
.f360-header-actions .tr-btn-primary { background:#f3f6fb; color:#2d4a72; border-color:#e3ebf5; }
.f360-header-actions .tr-btn-primary:hover { background:#eaf1fa; color:#243B5C; }
</style>

<div class="i360-grid3">

  <!-- ═══════ ZONE GAUCHE : Barre IA + onglets ═══════ -->
  <div style="min-width:0;">

    <?php fiche360_ia_bar('immeuble', $immId, "Demander à l'IA sur cet immeuble (lots, occupation, charges, syndic…)"); ?>

    <?php
    $H  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    // mini-helper d'affichage champ/valeur
    $fld = function(string $label, $val, string $suffix='') use ($H) {
        $val = trim((string)$val);
        if ($val === '') return '';
        return '<div style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid #f5f3ef;font-size:13px">'
             . '<span style="color:#7a766f;min-width:190px">'.$H($label).'</span>'
             . '<span style="font-weight:600">'.$H($val).$H($suffix).'</span></div>';
    };
    $oui = fn($v) => !empty($v) ? 'Oui' : '';
    ?>
    <style>
    .imm-inner{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}
    @media(max-width:1100px){.imm-inner{grid-template-columns:1fr}}
    .imm-inner>div{min-width:0}
    .imm-sub{display:inline-block;padding:6px 12px;margin:0 6px 12px 0;border-radius:8px;background:#eef2f7;color:#3a6890;font-weight:600;font-size:12px}
    </style>

    <div class="imm-inner">

      <!-- ░░░ SOUS-COLONNE GAUCHE : Identité + Syndic ░░░ -->
      <div>
      <div class="f360-card">
        <h3>🪪 Identité</h3>
        <?= $fld('Référence', $imm['reference_immeuble']) ?>
        <?= $fld('Nom', $imm['nom_immeuble']) ?>
        <?= $fld('Adresse', $imm['adresse_1']) ?>
        <?= $fld('Code postal', $imm['code_postal']) ?>
        <?= $fld('Ville', $imm['ville']) ?>
        <?= $fld('Type', $imm['type_immeuble']) ?>
        <?= $fld('GPS', (!empty($imm['latitude']) ? $imm['latitude'].', '.$imm['longitude'] : '')) ?>
        <?php if (!empty($imm['latitude'])): ?>
          <div style="padding:8px 0"><a href="https://www.google.com/maps?q=<?= $H($imm['latitude'].','.$imm['longitude']) ?>" target="_blank" rel="noopener">🗺 Voir sur la carte</a></div>
        <?php endif; ?>
      </div>

      <!-- Syndic (sous l'identité, colonne gauche) -->
      <div class="f360-card">
        <h3>📋 Mandats & gestion</h3>

        <!-- ░ SYNDIC (niveau immeuble : immeubles_gestion) ░ -->
        <?php if (!empty($mandats)): foreach ($mandats as $m): ?>
          <div style="border:1px solid #f0ece6;border-radius:10px;padding:12px;margin-bottom:10px">
            <div style="font-weight:700;color:#243B5C;margin-bottom:6px">
              <?= $H($m['type'] ?: 'MANDAT') ?>
              <span style="font-size:11px;color:#7a766f;font-weight:400">· <?= $H($m['statut']) ?></span>
            </div>
            <?= $fld('Société', $m['societe_nom']) ?>
            <?= $fld('Agence', $m['agence_nom']) ?>
            <?= $fld('Gestionnaire', $m['gestionnaire']) ?>
            <?= $fld('Assistante', $m['assistante']) ?>
            <?= $fld('Comptable', $m['comptable']) ?>
            <?= $fld('Début', $m['date_debut']) ?>
            <?= $fld('Fin', $m['date_fin']) ?>
          </div>
        <?php endforeach; elseif ($catDeduite === 'SYNDIC'): ?>
          <div style="background:#eff6ff;border-left:3px solid #1d4ed8;padding:10px 12px;border-radius:8px;font-size:12.5px;color:#1e3a8a;margin-bottom:10px;">
            🏛️ <strong>Immeuble classé SYNDIC</strong> (déduit du classement copropriété) — mais <strong>aucun mandat syndic enregistré</strong> dans le registre (<code>immeubles_gestion</code>).
            <div style="margin-top:8px;">
              <a class="tr-btn" href="<?= $H(app_url('/agency_immeuble_form.php?id=' . $immId)) ?>">📝 Formaliser le mandat syndic</a>
            </div>
          </div>
        <?php endif; ?>

        <!-- ░ GESTION LOCATIVE (niveau lots : table mandats) ░ -->
        <?php if (!empty($mandatsGestionLots)): ?>
          <div style="margin-top:6px;border-top:1px solid #f0ece6;padding-top:10px;">
            <div style="font-weight:700;color:#15803d;margin-bottom:8px;font-size:13px;">
              🔑 Gestion locative — <?= count($mandatsGestionLots) ?> lot<?= count($mandatsGestionLots) > 1 ? 's' : '' ?> sous mandat
            </div>
            <?php foreach ($mandatsGestionLots as $g):
              $tg = strtolower((string)$g['type_mandat']);
              [$gbg,$gfg,$gic] = match($tg){ 'location'=>['#dbeafe','#1e40af','🔑'], default=>['#d9f0db','#14532d','🏠'] };
            ?>
              <div style="display:flex;align-items:center;gap:10px;padding:7px 10px;background:#fafaf6;border-radius:8px;margin-bottom:6px;font-size:12px;">
                <span style="background:<?= $gbg ?>;color:<?= $gfg ?>;padding:2px 9px;border-radius:99px;font-weight:700;font-size:10.5px;min-width:78px;text-align:center;"><?= $gic ?> <?= $H(ucfirst($tg)) ?></span>
                <span style="flex:1;min-width:0;">
                  Lot <strong><?= $H($g['numero_lot'] ?: '—') ?></strong>
                  · <a href="<?= $H(app_url('/bien_360.php?id=' . (int)$g['id_bien'])) ?>" style="color:#4878a6;text-decoration:none;font-family:'DM Mono',monospace;"><?= $H($g['reference_bien'] ?: '#'.(int)$g['id_bien']) ?></a>
                  <?php $glNom = trim((string)($g['loc_nom'] ?? '')); $glTid = (int)($g['loc_tiers_id'] ?? 0); ?>
                  <?php if ($glNom !== ''): ?>
                    · 👤 <?php if ($glTid > 0): ?><a href="<?= $H(app_url('/tiers_360.php?id=' . $glTid)) ?>" style="color:#2d5f6b;text-decoration:none;font-weight:700;border-bottom:1px dotted #8fb3bb;"><?= $H($glNom) ?></a><?php else: ?><strong style="color:#2d5f6b;"><?= $H($glNom) ?></strong><?php endif; ?>
                  <?php else: ?>
                    · <span style="color:#b9b4ac;">🔓 vacant</span>
                  <?php endif; ?>
                  <?php if ($g['proprio_nom']): ?> <span style="color:#9a9690;">· prop. <?= $H($g['proprio_nom']) ?></span><?php endif; ?>
                </span>
                <span style="color:#7a766f;font-size:10.5px;"><?= $H(ucfirst((string)($g['statut'] ?: 'actif'))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- ░ Vraiment aucun mandat (ni syndic réel/déduit, ni gestion lot) ░ -->
        <?php if (empty($mandats) && $catDeduite !== 'SYNDIC' && empty($mandatsGestionLots)): ?>
          <div class="f360-empty"><div class="em-ico">📋</div>Aucun mandat enregistré pour cet immeuble.</div>
        <?php endif; ?>
      </div>
      <div class="f360-card">
        <h3>🗳 Assemblée générale</h3>
        <?= $fld('Dernière AG', $infos['date_ag_derniere'] ?? '') ?>
        <?= $fld('Prochaine AG', $infos['date_ag_prochaine'] ?? '') ?>
        <?= $fld('AG 2025', $infos['ag_2025'] ?? '') ?>
        <?= $fld('AG 2026', $infos['ag_2026'] ?? '') ?>
        <?= $fld('AG 2027', $infos['ag_2027'] ?? '') ?>
        <div style="padding-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a class="tr-btn" href="<?= $H(app_url('/agency_reunions.php?id_immeuble=' . $immId)) ?>">📅 Réunions</a>
          <a class="tr-btn" href="<?= $H(app_url('/agency_immeuble_fiche.php?id=' . $immId . '#ag')) ?>">📑 Retour AG</a>
        </div>
      </div>
      <div class="f360-card">
        <h3>📄 Contrats & honoraires</h3>
        <?= $fld('Syndic actuel', $imm['syndic_actuel'] ?? '') ?>
        <?= $fld('Honoraires HT', $infos['honoraires_ht'] ?? '', ' €') ?>
        <?= $fld('Honoraires 2026', $infos['hono_2026'] ?? '', ' €') ?>
        <?= $fld('Honoraires 2027', $infos['hono_2027'] ?? '', ' €') ?>
        <?= $fld('Contrats', $infos['contrats'] ?? '') ?>
        <?= $fld('Codes d\'accès', $infos['codes'] ?? '') ?>
        <?= $fld('Boîte à clés', $infos['boite_cles'] ?? '') ?>
        <?= $fld('Débiteurs', $infos['debiteurs'] ?? '') ?>
      </div>
      </div><!-- ░░░ fin SOUS-COLONNE GAUCHE ░░░ -->

      <!-- ░░░ SOUS-COLONNE DROITE : Lots + Descriptif + Travaux + Documents ░░░ -->
      <div>
    <!-- Lots de l'immeuble -->
    <?php
    // Partition : actifs (visibles) vs sortis = archivés/vendus (repliés)
    $biensActifs = array_filter($biens, fn($b) => !in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));
    $biensSortis = array_filter($biens, fn($b) =>  in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));

    // Closure de rendu d'une ligne ; $mode = 'actif' | 'sorti'
    $renderRow = function(array $b, string $mode): string {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $statut    = $b['nb_baux_actifs'] > 0 ? 'Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? 'Vacant' : '—');
        $statutCol = $b['nb_baux_actifs'] > 0 ? '#2d6a35' : (($b['statut_occupation'] ?? '') === 'vacant' ? '#8a4c12' : '#7a766f');
        $aAnnonceActive = (int)($b['nb_annonces_actives'] ?? 0) > 0;
        $stBien = (string)($b['statut_bien'] ?? '');
        if ($mode === 'sorti') { $statut = $stBien === 'vendu' ? 'Vendu' : 'Archivé'; $statutCol = $stBien === 'vendu' ? '#2d6a35' : '#9a9690'; }
        $ref  = $h(app_url('/bien_360.php?id=' . $b['id']));
        $usageLabel = $b['usage_bien'] ?: ($b['bail_nature'] ?: '—');   // reprend la nature du bail (habitation) à défaut
        $etageLabel = ($b['etage'] !== null && $b['etage'] !== '') ? (((int)$b['etage'] === 0) ? 'RDC' : $h($b['etage'])) : '—';
        $cells = '<td style="padding:6px 4px;"><a href="'.$ref.'" style="color:#4878a6;text-decoration:none;font-family:\'DM Mono\',monospace;">'.$h($b['reference_bien'] ?: '#' . $b['id']).'</a></td>'
            . '<td style="padding:6px 4px;">'.$h($b['numero_lot'] ?: '—').'</td>'
            . '<td style="padding:6px 4px;">'.$h($usageLabel).'</td>'
            . '<td style="padding:6px 4px;text-align:right;">'.($b['surface_habitable'] ? number_format((float)$b['surface_habitable'],0).' m²' : '—').'</td>'
            . '<td style="padding:6px 4px;text-align:center;">'.$etageLabel.'</td>'
            . '<td style="padding:6px 4px;">'.$h($b['proprio_nom'] ?: '—').'</td>'
            . '<td style="padding:6px 4px;color:'.$statutCol.';font-weight:600;">'.$h($statut).'</td>';
        // colonne Action
        $act = '';
        if ($mode === 'actif') {
            if ($aAnnonceActive) {
                $act = '<span title="Annonce active : archivage bloqué" style="font-size:11px;color:#8a4c12;cursor:help;">🔒 Annonce active</span>';
            } else {
                $act = '<button type="button" class="bien-archive-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #e0d9cf;border-radius:6px;background:#fff;color:#8a4c12;cursor:pointer;">📦 Archiver</button>';
            }
        } else { // sorti
            if ($stBien === 'vendu') {
                $act = '<span style="font-size:11px;color:#2d6a35;font-weight:600;">✅ Vendu</span>';
            } elseif ($aAnnonceActive) {
                $act = '<span title="Annonce active" style="font-size:11px;color:#8a4c12;cursor:help;">🔒 Annonce active</span>';
            } else {
                $act = '<button type="button" class="bien-vendu-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #cfe0d2;border-radius:6px;background:#fff;color:#2d6a35;cursor:pointer;margin-right:4px;">✅ Vendu</button>'
                     . '<button type="button" class="bien-supprimer-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #e6c4c0;border-radius:6px;background:#fff;color:#a23b2e;cursor:pointer;">🗑 Supprimer</button>';
            }
        }
        $cells .= '<td style="padding:6px 4px;text-align:right;">'.$act.'</td>';
        return '<tr style="border-bottom:1px solid #f5f3ef;'.($mode==='sorti'?'opacity:.7;':'').'" data-bien-row="'.(int)$b['id'].'">'.$cells.'</tr>';
    };
    $thead = '<thead><tr style="text-align:left;color:#7a766f;border-bottom:1px solid #f0ece6;">'
        . '<th style="padding:6px 4px;">Réf.</th><th style="padding:6px 4px;">Lot</th><th style="padding:6px 4px;">Usage</th>'
        . '<th style="padding:6px 4px;text-align:right;">Surface</th><th style="padding:6px 4px;text-align:center;">Étage</th><th style="padding:6px 4px;">Propriétaire</th>'
        . '<th style="padding:6px 4px;">Statut</th><th style="padding:6px 4px;text-align:right;">Action</th></tr></thead>';
    ?>
    <div class="f360-card">
        <h3>🏘 Lots de l'immeuble <span class="count"><?= count($biensActifs) ?></span></h3>
        <?php if (empty($biensActifs)): ?>
            <div class="f360-empty"><div class="em-ico">🏘</div>Aucun bien actif rattaché à cet immeuble.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <?= $thead ?>
                <tbody><?php foreach ($biensActifs as $b) echo $renderRow($b, 'actif'); ?></tbody>
            </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($biensSortis)): ?>
        <details style="margin-top:14px;">
            <summary style="cursor:pointer; font-size:12px; color:#7a766f; font-weight:600; user-select:none;">
                📦 Biens archivés / vendus (<?= count($biensSortis) ?>) — déplier
            </summary>
            <div style="overflow-x:auto; margin-top:8px;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <?= $thead ?>
                <tbody><?php foreach ($biensSortis as $b) echo $renderRow($b, 'sorti'); ?></tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var CSRF = <?= json_encode(csrf_token('archiver_bien')) ?>;
        var URL_ARCHIVE = '<?= h(app_url('/api/bien_archiver.php')) ?>';
        var URL_ACTION  = '<?= h(app_url('/api/bien_archive_action.php')) ?>';

        function post(url, params, okCb, label){
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            Object.keys(params).forEach(function(k){ fd.append(k, params[k]); });
            return fetch(url, {method:'POST', body:fd, headers:{'X-CSRF-Token':CSRF}})
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (j.success){ okCb(j); }
                    else { alert(j.message || (label + ' impossible.')); }
                    return j;
                })
                .catch(function(){ alert('Erreur réseau.'); });
        }

        // Archiver (lots actifs)
        document.querySelectorAll('.bien-archive-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('Archiver ce bien ?')) return;
                btn.disabled = true; btn.textContent = '…';
                post(URL_ARCHIVE, {id_bien: btn.dataset.id}, function(){ location.reload(); }, 'Archivage')
                    .then(function(j){ if(!j || !j.success){ btn.disabled=false; btn.textContent='📦 Archiver'; } });
            });
        });

        // Marquer Vendu (le bien reste)
        document.querySelectorAll('.bien-vendu-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('Marquer ce bien comme VENDU ? (il reste dans le patrimoine)')) return;
                btn.disabled = true; btn.textContent = '…';
                post(URL_ACTION, {id_bien: btn.dataset.id, action:'vendu'}, function(){ location.reload(); }, 'Action')
                    .then(function(j){ if(!j || !j.success){ btn.disabled=false; btn.textContent='✅ Vendu'; } });
            });
        });

        // Supprimer (vers table d'archive biens_supprimes, disparaît)
        document.querySelectorAll('.bien-supprimer-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('SUPPRIMER définitivement ce bien ? (archivé dans biens_supprimes, réversible côté base)')) return;
                btn.disabled = true; btn.textContent = '…';
                post(URL_ACTION, {id_bien: btn.dataset.id, action:'supprimer'}, function(){
                    var row = document.querySelector('[data-bien-row="'+btn.dataset.id+'"]');
                    if (row) row.remove();
                }, 'Suppression')
                    .then(function(j){ if(!j || !j.success){ btn.disabled=false; btn.textContent='🗑 Supprimer'; } });
            });
        });
    })();
    </script>
    <!-- /lots -->

      <!-- Descriptif (déplacé en colonne droite) -->
      <div class="f360-card">
        <h3>📐 Descriptif</h3>
        <?= $fld('Année de construction', $imm['annee_construction']) ?>
        <?= $fld('Niveaux', $imm['nb_niveaux']) ?>
        <?= $fld('Bâtiments', $imm['nb_batiments']) ?>
        <?php $nbLotsEff = ((int)($imm['nb_lots'] ?? 0)) ?: ((int)($imm['copro_nb_lots'] ?? 0)); ?>
        <div class="f360-fld" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:6px 0;">
          <span style="min-width:150px;color:#64748b;font-size:12px;">Nombre de lots</span>
          <input type="number" id="imm-nb-lots" value="<?= $nbLotsEff ?: '' ?>" min="0"
                 placeholder="—" style="width:90px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
          <button type="button" id="imm-nb-lots-save"
                  style="border:none;background:#0e7490;color:#fff;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;cursor:pointer;">💾 Enregistrer</button>
          <span id="imm-nb-lots-status" style="font-size:11px;"></span>
        </div>
        <script>
        (function(){
          var btn=document.getElementById('imm-nb-lots-save'); if(!btn) return;
          btn.addEventListener('click', async function(){
            var inp=document.getElementById('imm-nb-lots');
            var st=document.getElementById('imm-nb-lots-status');
            st.textContent='⏳…'; st.style.color='#64748b';
            var fd=new FormData();
            fd.append('immeuble_id', '<?= (int)$immId ?>');
            fd.append('nb_lots', inp.value || '0');
            fd.append('csrf_token', <?= json_encode(csrf_token('immeuble_lots')) ?>);
            try{
              var r=await fetch(<?= json_encode(app_url('/api/immeuble_lots_save.php')) ?>, {method:'POST', body:fd, credentials:'same-origin'});
              var j=await r.json();
              st.textContent = j.ok ? '✅ Enregistré' : ('❌ '+(j.error||'Erreur'));
              st.style.color = j.ok ? '#059669' : '#dc2626';
            }catch(e){ st.textContent='❌ '+e.message; st.style.color='#dc2626'; }
          });
        })();
        </script>
        <?= $fld('Ascenseur', $oui($imm['presence_ascenseur'] ?? 0)) ?>
        <?= $fld('Gardien', $oui($imm['gardien'] ?? 0)) ?>
        <?= $fld('Chauffage collectif', $oui($imm['chauffage_collectif'] ?? 0)) ?>
        <?= $fld('Espace vert', $oui($imm['espace_vert'] ?? 0)) ?>
        <?= $fld('Lots copropriété', $imm['copro_nb_lots'] ?? '') ?>
        <?= $fld('Tantièmes total', $imm['copro_tantiemes_total'] ?? '') ?>
        <?= $fld('Budget prévisionnel copro', $imm['copro_budget_previsionnel_annuel'] ?? '', ' €') ?>
        <?php if (!trim((string)($imm['annee_construction'] ?? '')) && !($imm['nb_niveaux'] ?? 0)): ?>
          <div class="f360-empty" style="padding:10px"><div class="em-ico">📐</div>Descriptif à compléter.</div>
        <?php endif; ?>
      </div>
      <div class="f360-card">
        <h3>🔧 Travaux</h3>
        <?= $fld('Travaux en cours', $infos['travaux_cours'] ?? '') ?>
        <?= $fld('Travaux à prévoir', $infos['travaux_prevoir'] ?? '') ?>
        <?= $fld('Interventions en cours', $infos['interventions_cours'] ?? '') ?>
        <?= $fld('Sinistres', $infos['sinistres'] ?? '') ?>
        <?php if (empty($infos['travaux_cours']) && empty($infos['travaux_prevoir']) && empty($infos['interventions_cours']) && empty($infos['sinistres'])): ?>
          <div class="f360-empty" style="padding:10px"><div class="em-ico">🔧</div>Aucun travaux renseigné.</div>
        <?php endif; ?>
      </div>

    <!-- Documents de l'immeuble -->
    <div class="f360-card">
        <h3>📂 Documents de l'immeuble <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/immeuble_documents_list.php?id=' . $immId)) ?>">→ Gérer les documents</a></div>
        <?php else: foreach ($docs as $d): ?>
            <div style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center;">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;">[<?= h($d['document_type']) ?>]</span>
                <span style="flex:1;"><?= h($d['name_display']) ?></span>
                <span style="color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Mentionné dans -->
    <?php
    $mentionsForLayout = array_map(fn($m) => [
        'icon'  => '📄',
        'title' => $m['name_display'],
        'ref'   => $m['document_type'] . ' · ' . date('d/m/y', strtotime((string)$m['created_at'])),
        'url'   => null,
    ], $mentions);
    fiche360_mention_dans($mentionsForLayout);
    ?>

      </div><!-- ░░░ fin SOUS-COLONNE DROITE ░░░ -->
    </div><!-- fin .imm-inner -->

  </div>

  <!-- ═══════════════════ COLONNE DROITE — ACTIONS + CONTACTS ═══════════════════ -->
  <div style="min-width:0;">

    <?php
    // Métier (N1) induit par l'immeuble : réf syndic (commence par 1/2/3xxx) → SYNDIC,
    // tous les autres immeubles → GESTION. (Doctrine 2026-06-30 : jamais transaction par défaut.)
    $refImmDigits = preg_replace('/\D/', '', (string)($imm['reference_immeuble'] ?? '')) ?? '';
    $n1Imm = (strlen($refImmDigits) >= 4 && in_array($refImmDigits[0], ['1','2','3'], true))
        ? '04_SYNDIC'
        : '03_GESTION_LOCATIVE';
    // Panneau Actions — remonté EN HAUT de la colonne pour visibilité immédiate
    fiche360_actions_panel('Actions immeuble', [
        ['icon'=>'📤','label'=>'Charger des documents','url'=>'#','onclick'=>"window.fbxOpenUploadModal({origin:'immeuble_360', immeuble_id:" . (int)$immId . ", entite_id_bdd:" . (int)$immId . ", n1:'" . $n1Imm . "', entite_nom:'" . addslashes((string)($imm['reference_immeuble'] ?: $nomAffichage)) . "'});return false;"],
        ['icon'=>'➕','label'=>'Ajouter un bien à cet immeuble','url'=>app_url('/bien_detail.php?id_immeuble=' . $immId)],
        ['icon'=>'📁','label'=>'Documents de l\'immeuble',     'url'=>app_url('/immeuble_documents_list.php?id=' . $immId)],
        ['icon'=>'✏️','label'=>'Éditer l\'immeuble',           'url'=>app_url('/agency_immeuble_form.php?id=' . $immId)],
        ['icon'=>'🗺','label'=>'Voir sur carte',
         'url'=>(!empty($imm['latitude']) && !empty($imm['longitude']))
                    ? 'https://www.google.com/maps?q=' . $imm['latitude'] . ',' . $imm['longitude']
                    : 'https://www.google.com/maps?q=' . urlencode($adresseComplete),
         'target'=>'_blank'],
    ]);

    fiche360_checklist('Pièces immeuble', $piecesItems);

    // CONTACTS : propriétaires rattachés (→ fiche tiers 360° si rattaché, sinon recherche proprios)
    if (!empty($proprios)) {
        $contactLinks = [];
        foreach ($proprios as $p) {
            $contactLinks[] = [
                'icon' => '🏠',
                'name' => ($p['nom'] ?: 'Propriétaire #' . $p['id']) . ' — Propriétaire',
                'ref'  => $p['nb_biens'] . ' lot(s) dans cet immeuble',
                'url'  => !empty($p['id_tiers'])
                    ? app_url('/tiers_360.php?id=' . (int)$p['id_tiers'])
                    : app_url('/agency_proprietaires.php?q=' . urlencode($p['nom'] ?: '')),
            ];
        }
        fiche360_attach('CONTACTS (' . count($contactLinks) . ')', $contactLinks);
    }

    // Synthèse occupation
    fiche360_attach('OCCUPATION', [
        ['icon'=>'🏘','name'=>$nbBiens . ' lot(s) rattaché(s)','ref'=>'','url'=>'#'],
        ['icon'=>'✅','name'=>$nbBauxActifs . ' bail(x) actif(s)','ref'=>'','url'=>'#'],
        ['icon'=>'🔓','name'=>$nbBiensVacants . ' lot(s) vacant(s)','ref'=>'','url'=>'#'],
        ['icon'=>'💰','name'=>$nbBiensVendus . ' lot(s) vendu(s)','ref'=>'','url'=>'#'],
    ]);

    ?>

  </div>
</div>

<?= fiche360_js() ?>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
