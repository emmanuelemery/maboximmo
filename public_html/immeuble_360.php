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
$st = $pdo->prepare("SELECT * FROM immeubles WHERE id = ? LIMIT 1");
$st->execute([$immId]);
$imm = $st->fetch(PDO::FETCH_ASSOC);
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
        b.surface_habitable, b.type_commercialisation, b.statut_occupation, b.prix_vente,
        COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom,
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
    $statusMsg = "<strong>{$nbBauxActifs} bail" . ($nbBauxActifs > 1 ? 's' : '') . " actif" . ($nbBauxActifs > 1 ? 's' : '') . "</strong> sur {$nbBiens} lots · "
               . $nbBiensVacants . ' vacant' . ($nbBiensVacants > 1 ? 's' : '') . '.';
} elseif ($nbBiensVacants > 0) {
    $statusColor = 'orange'; $statusIcon = '🔓';
    $statusMsg = "<strong>{$nbBiensVacants} lot(s) vacant(s)</strong> sur {$nbBiens}.";
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
// ─── BREADCRUMB ──
$chaine = [
    ['icon'=>'🏢','label'=>$nomAffichage,'url'=>null],
];
fiche360_breadcrumb($chaine, 'Patrimoine');

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
        ['label'=>'📥 Importer un document','url'=>app_url('/transaction_chargement.php'),'class'=>'tr-btn tr-btn-primary'],
    ]
);

fiche360_ia_bar('immeuble', $immId, "Demander à l'IA sur cet immeuble (lots, occupation, charges, syndic…)");
fiche360_status_banner($statusMsg, $statusColor, $statusIcon, $statusAlertes);
?>

<div class="f360-grid">

  <!-- ═══════════════════ COLONNE PRINCIPALE ═══════════════════ -->
  <div>

    <!-- Lots de l'immeuble -->
    <div class="f360-card">
        <h3>🏘 Lots de l'immeuble <span class="count"><?= $nbBiens ?></span></h3>
        <?php if (empty($biens)): ?>
            <div class="f360-empty"><div class="em-ico">🏘</div>Aucun bien rattaché à cet immeuble.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="text-align:left; color:#7a766f; border-bottom:1px solid #f0ece6;">
                        <th style="padding:6px 4px;">Réf.</th>
                        <th style="padding:6px 4px;">Lot</th>
                        <th style="padding:6px 4px;">Usage</th>
                        <th style="padding:6px 4px; text-align:right;">Surface</th>
                        <th style="padding:6px 4px;">Propriétaire</th>
                        <th style="padding:6px 4px;">Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($biens as $b):
                    $statut = $b['nb_baux_actifs'] > 0 ? 'Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? 'Vacant' : '—');
                    $statutCol = $b['nb_baux_actifs'] > 0 ? '#2d6a35' : (($b['statut_occupation'] ?? '') === 'vacant' ? '#8a4c12' : '#7a766f');
                ?>
                    <tr style="border-bottom:1px solid #f5f3ef;">
                        <td style="padding:6px 4px;"><a href="<?= h(app_url('/bien_360.php?id=' . $b['id'])) ?>" style="color:#4878a6; text-decoration:none; font-family:'DM Mono',monospace;"><?= h($b['reference_bien'] ?: '#' . $b['id']) ?></a></td>
                        <td style="padding:6px 4px;"><?= h($b['numero_lot'] ?: '—') ?></td>
                        <td style="padding:6px 4px;"><?= h($b['usage_bien'] ?: '—') ?></td>
                        <td style="padding:6px 4px; text-align:right;"><?= $b['surface_habitable'] ? number_format((float)$b['surface_habitable'], 0) . ' m²' : '—' ?></td>
                        <td style="padding:6px 4px;"><?= h($b['proprio_nom'] ?: '—') ?></td>
                        <td style="padding:6px 4px; color:<?= $statutCol ?>; font-weight:600;"><?= h($statut) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Documents de l'immeuble -->
    <div class="f360-card">
        <h3>📂 Documents de l'immeuble <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/transaction_chargement.php')) ?>">→ Charger</a></div>
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

  </div>

  <!-- ═══════════════════ COLONNE LATÉRALE ═══════════════════ -->
  <div>

    <?php
    fiche360_checklist('Pièces immeuble', $piecesItems);

    // Propriétaires rattachés
    if (!empty($proprios)) {
        $proprioLinks = [];
        foreach ($proprios as $p) {
            $proprioLinks[] = [
                'icon' => '👤',
                'name' => $p['nom'] ?: 'Propriétaire #' . $p['id'],
                'ref'  => $p['nb_biens'] . ' bien(s) dans cet immeuble' . (!empty($p['id_tiers']) ? ' · tiers #' . $p['id_tiers'] : ''),
                'url'  => app_url('/agency_proprietaires.php?q=' . urlencode($p['nom'] ?: '')),
            ];
        }
        fiche360_attach(count($proprios) > 1 ? 'PROPRIÉTAIRES (' . count($proprios) . ')' : 'PROPRIÉTAIRE', $proprioLinks);
    }

    // Synthèse occupation
    fiche360_attach('OCCUPATION', [
        ['icon'=>'🏘','name'=>$nbBiens . ' lot(s) rattaché(s)','ref'=>'','url'=>'#'],
        ['icon'=>'✅','name'=>$nbBauxActifs . ' bail(x) actif(s)','ref'=>'','url'=>'#'],
        ['icon'=>'🔓','name'=>$nbBiensVacants . ' lot(s) vacant(s)','ref'=>'','url'=>'#'],
        ['icon'=>'💰','name'=>$nbBiensVendus . ' lot(s) vendu(s)','ref'=>'','url'=>'#'],
    ]);

    // Panneau Actions
    fiche360_actions_panel('Actions immeuble', [
        ['icon'=>'➕','label'=>'Ajouter un bien à cet immeuble','url'=>app_url('/bien_detail.php?id_immeuble=' . $immId)],
        ['icon'=>'📥','label'=>'Importer un document',         'url'=>app_url('/transaction_chargement.php')],
        ['icon'=>'✏️','label'=>'Éditer l\'immeuble',           'url'=>app_url('/agency_immeuble_form.php?id=' . $immId)],
        ['icon'=>'🗺','label'=>'Voir sur carte',
         'url'=>(!empty($imm['latitude']) && !empty($imm['longitude']))
                    ? 'https://www.google.com/maps?q=' . $imm['latitude'] . ',' . $imm['longitude']
                    : 'https://www.google.com/maps?q=' . urlencode($adresseComplete),
         'target'=>'_blank'],
    ]);
    ?>

  </div>
</div>

<?= fiche360_js() ?>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
