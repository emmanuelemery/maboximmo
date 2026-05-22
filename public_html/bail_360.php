<?php
// bail_360.php — Vue 360° d'un bail
// Bien + locataire + bailleur + loyer + échéances + documents + IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$bailId = (int)($_GET['id'] ?? 0);
if ($bailId <= 0) {
    header('Location: ' . app_url('/transaction_baux_overview.php'));
    exit;
}

// ─── Charge le bail + bien + locataire ──
$sql = "SELECT bb.*,
    b.id AS bien_id, b.reference_bien, b.designation, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
    b.code_postal AS bien_cp, b.surface_habitable, b.numero_lot, b.id_immeuble,
    i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
    p.id AS proprio_id, p.id_tiers AS proprio_tiers_id,
    COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
    COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom,
    tl.id AS loc_tiers_id, tl.nom_affichage AS loc_tiers_nom, tl.raison_sociale AS loc_raison_sociale,
    tl.email AS loc_email, tl.telephone AS loc_telephone
    FROM bien_baux bb
    INNER JOIN biens b      ON b.id = bb.id_bien
    LEFT JOIN immeubles i   ON i.id = b.id_immeuble
    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
    LEFT JOIN tiers tp      ON tp.id = p.id_tiers
    LEFT JOIN tiers tl      ON tl.id = bb.id_tiers_locataire
    WHERE bb.id = ? LIMIT 1";
$st = $pdo->prepare($sql); $st->execute([$bailId]);
$bail = $st->fetch(PDO::FETCH_ASSOC);
if (!$bail) {
    http_response_code(404);
    exit('Bail introuvable.');
}

$proprietaireNom = $bail['proprio_tiers_nom'] ?: $bail['proprio_nom_legacy'] ?: '—';
$locataireNom    = $bail['loc_tiers_nom'] ?: $bail['loc_raison_sociale']
                  ?: ($bail['locataire_raison_sociale'] ?: trim((string)$bail['locataire_prenom'] . ' ' . $bail['locataire_nom']))
                  ?: '—';
$bienLabel       = $bail['reference_bien'] ?: $bail['designation'] ?: 'Bien #' . $bail['bien_id'];
$bienAdresse     = trim((string)($bail['bien_adresse'] ?? '') . ' ' . ($bail['bien_cp'] ?? '') . ' ' . ($bail['bien_ville'] ?? ''));

// ─── Documents GED du bail ──
$docs = [];
try {
    $stD = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND (
              id_bail = ?
              OR JSON_EXTRACT(metadata, '$.classement.bail_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'bail', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$bailId, $bailId, $bailId]);
    $docs = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

// ─── Checklist pièces bail ──
$pieces = [
    ['code'=>'BAIL_SIGNE',     'label'=>'Bail signé',            'sublabel'=>'Document principal'],
    ['code'=>'EDL_ENTREE',     'label'=>"État des lieux d'entrée",'sublabel'=>'Obligatoire à la prise d\'effet'],
    ['code'=>'ATT_ASSURANCE',  'label'=>"Attestation d'assurance",'sublabel'=>'Locataire — annuel'],
    ['code'=>'DPE',            'label'=>'DPE',                   'sublabel'=>'Annexé au bail'],
    ['code'=>'CAUTION',        'label'=>'Acte de caution',       'sublabel'=>'Si garant'],
    ['code'=>'EDL_SORTIE',     'label'=>'État des lieux de sortie','sublabel'=>'Si bail terminé'],
];
$piecesItems = [];
foreach ($pieces as $p) {
    $piecesItems[] = [
        'label'    => $p['label'],
        'sublabel' => $p['sublabel'],
        'ok'       => isset($docsByType[$p['code']]),
        'add_url'  => app_url('/transaction_chargement.php'),
    ];
}

// ─── Mentions (CRG, quittances, courriers qui citent ce bail) ──
$mentions = [];
try {
    $stM = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND source_module <> '05_TRANSACTION'
          AND (
              id_bail = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'bail', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 10");
    $stM->execute([$bailId, $bailId]);
    $mentions = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Statut visuel intelligent ──
$today = date('Y-m-d');
$dateFin = (string)($bail['date_fin'] ?? '');
$joursAvantFin = $dateFin ? (int)((strtotime($dateFin) - strtotime($today)) / 86400) : null;

if ($bail['statut'] === 'actif') {
    if ($joursAvantFin !== null && $joursAvantFin < 0) {
        $statusColor = 'red'; $statusIcon = '⚠️';
        $statusMsg = '<strong>Bail expiré</strong> depuis le ' . h($dateFin) . ' · à régulariser.';
    } elseif ($joursAvantFin !== null && $joursAvantFin < 90) {
        $statusColor = 'orange'; $statusIcon = '⏰';
        $statusMsg = "<strong>Bail actif</strong> · expire dans <strong>{$joursAvantFin} jours</strong> (préavis à anticiper).";
    } else {
        $statusColor = 'green'; $statusIcon = '✅';
        $statusMsg = '<strong>Bail actif</strong> · jusqu\'au ' . h($dateFin ?: '—') . '.';
    }
} elseif ($bail['statut'] === 'termine') {
    $statusColor = 'gray'; $statusIcon = '🏁';
    $statusMsg = '<strong>Bail terminé</strong> · sortie le ' . h($bail['date_sortie'] ?? '—') . '.';
} else {
    $statusColor = 'gray'; $statusIcon = '⚪';
    $statusMsg = 'Bail · statut <strong>' . h($bail['statut'] ?? '—') . '</strong>.';
}
$nbPiecesOk      = array_sum(array_map(fn($p) => $p['ok'] ? 1 : 0, $piecesItems));
$piecesManquantes = count($piecesItems) - $nbPiecesOk;
$statusAlertes   = $piecesManquantes > 0 ? $piecesManquantes . ' pièce(s) à charger' : '';

$pageTitle    = 'Bail · ' . ($bail['bail_nature'] ?? 'bail') . ' #' . $bailId;
$pageSubtitle = 'Vue 360° · ' . $bienLabel;
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<?php
// ─── BREADCRUMB ──
$chaine = [];
if (!empty($bail['proprio_tiers_id'])) {
    $chaine[] = ['icon'=>'👤','label'=>$proprietaireNom,'url'=>app_url('/tiers_360.php?id=' . $bail['proprio_tiers_id'])];
}
if (!empty($bail['id_immeuble'])) {
    $chaine[] = ['icon'=>'🏢','label'=>($bail['nom_immeuble'] ?: $bail['imm_adresse']),'url'=>app_url('/immeuble_360.php?id=' . $bail['id_immeuble'])];
}
$chaine[] = ['icon'=>'🏠','label'=>$bienLabel,'url'=>app_url('/bien_360.php?id=' . $bail['bien_id'])];
$chaine[] = ['icon'=>'📋','label'=>'Bail #' . $bailId,'url'=>null];
fiche360_breadcrumb($chaine, 'Hiérarchie');

// ─── HEADER ──
$badge = match($bail['statut'] ?? '') {
    'actif'   => ['label'=>'Actif','class'=>'actif'],
    'termine' => ['label'=>'Terminé','class'=>'vendu'],
    default   => null,
};

$metas = [];
if (!empty($bail['bail_nature']))      $metas[] = ['icon'=>'📋','text'=>$bail['bail_nature']];
if (!empty($bail['date_prise_effet'])) $metas[] = ['icon'=>'📅','text'=>'Effet ' . date('d/m/Y', strtotime((string)$bail['date_prise_effet']))];
if (!empty($bail['date_fin']))         $metas[] = ['icon'=>'🏁','text'=>'Fin ' . date('d/m/Y', strtotime((string)$bail['date_fin']))];
if (!empty($bail['loyer_mensuel_hc'])) $metas[] = ['icon'=>'💰','text'=>number_format((float)$bail['loyer_mensuel_hc'], 0, ',', ' ') . ' €/mois'];

fiche360_header(
    '📋',
    'Bail ' . ($bail['bail_nature'] ?? '') . ' · ' . $locataireNom,
    $badge,
    $bienAdresse ?: 'Adresse non renseignée',
    $metas,
    [
        ['label'=>'✏️ Éditer le bien','url'=>app_url('/bien_detail.php?edit=' . $bail['bien_id']),'class'=>'tr-btn'],
        ['label'=>'📥 Importer un document','url'=>app_url('/transaction_chargement.php'),'class'=>'tr-btn tr-btn-primary'],
    ]
);

fiche360_ia_bar('bail', $bailId, "Demander à l'IA sur ce bail (loyer, échéances, conformité, indexation…)");
fiche360_status_banner($statusMsg, $statusColor, $statusIcon, $statusAlertes);
?>

<div class="f360-grid">

  <!-- ═══════════════════ COLONNE PRINCIPALE ═══════════════════ -->
  <div>

    <!-- Synthèse financière + dates -->
    <div class="f360-card">
        <h3>💰 Synthèse financière</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px;">
            <div><div style="font-size:10px; color:#9a9690;">LOYER HC</div><strong><?= number_format((float)$bail['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">CHARGES</div><strong><?= number_format((float)$bail['charges_mensuelles'], 0, ',', ' ') ?> €/mois</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">LOYER CC</div><strong><?= number_format((float)$bail['loyer_mensuel_hc'] + (float)$bail['charges_mensuelles'], 0, ',', ' ') ?> €/mois</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">DÉPÔT DE GARANTIE</div><strong><?= number_format((float)$bail['depot_garantie'], 0, ',', ' ') ?> €</strong></div>
            <div><div style="font-size:10px; color:#9a9690;">INDICE</div><strong><?= h($bail['indice_type'] ?? '—') ?> <?= h($bail['indice_trimestre'] ?? '') ?></strong></div>
            <div><div style="font-size:10px; color:#9a9690;">PÉRIODICITÉ</div><strong><?= h($bail['periodicite_paiement'] ?? 'mensuelle') ?></strong></div>
        </div>
    </div>

    <!-- Clauses & assurance -->
    <div class="f360-card">
        <h3>🛡 Clauses & assurance</h3>
        <div style="font-size:12px; line-height:1.7;">
            <?php if (!is_null($bail['renonciation_recours_reciproque'] ?? null)): ?>
                <div>• <strong>Renonciation à recours réciproque :</strong> <?= $bail['renonciation_recours_reciproque'] ? '✅ Oui' : '❌ Non' ?></div>
            <?php endif; ?>
            <?php if (!empty($bail['assurance_surprimes_a_charge'])): ?>
                <div>• <strong>Surprimes d'assurance à charge :</strong> <?= h($bail['assurance_surprimes_a_charge']) ?></div>
            <?php endif; ?>
            <?php if (!is_null($bail['assurance_justification_annuelle'] ?? null)): ?>
                <div>• <strong>Justification annuelle d'assurance :</strong> <?= $bail['assurance_justification_annuelle'] ? '✅ Obligatoire' : '⚪ Non précisée' ?></div>
            <?php endif; ?>
            <?php if (!empty($bail['clause_resolutoire'])): ?>
                <div style="margin-top:8px; padding:8px 10px; background:#fff7ed; border-left:3px solid #f59e0b; border-radius:6px;">
                    <strong>📜 Clause résolutoire :</strong> <?= h(mb_substr((string)$bail['clause_resolutoire'], 0, 300)) ?><?= mb_strlen($bail['clause_resolutoire']) > 300 ? '…' : '' ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($bail['conditions_particulieres'])): ?>
                <div style="margin-top:8px; padding:10px 12px; background:#f9f7ff; border-left:3px solid #7c3aed; border-radius:6px;">
                    <strong>📋 Conditions particulières :</strong> <?= h(mb_substr((string)$bail['conditions_particulieres'], 0, 500)) ?><?= mb_strlen($bail['conditions_particulieres']) > 500 ? '…' : '' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Documents du bail -->
    <div class="f360-card">
        <h3>📂 Documents du bail <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/transaction_chargement.php')) ?>">→ Charger un document</a></div>
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
    fiche360_checklist('Pièces bail', $piecesItems);

    // Bien concerné
    fiche360_attach('BIEN CONCERNÉ', [[
        'icon' => '🏠',
        'name' => $bienLabel,
        'ref'  => ($bail['bien_ville'] ?? '') . ($bail['surface_habitable'] ? ' · ' . number_format((float)$bail['surface_habitable'], 0) . ' m²' : ''),
        'url'  => app_url('/bien_360.php?id=' . $bail['bien_id']),
    ]]);

    // Bailleur (propriétaire)
    if (!empty($bail['proprio_id'])) {
        fiche360_attach('BAILLEUR', [[
            'icon' => '👤',
            'name' => $proprietaireNom,
            'ref'  => !empty($bail['proprio_tiers_id']) ? 'tiers #' . $bail['proprio_tiers_id'] : 'propriétaire #' . $bail['proprio_id'],
            'url'  => !empty($bail['proprio_tiers_id']) ? app_url('/tiers_360.php?id=' . $bail['proprio_tiers_id']) : app_url('/agency_proprietaires.php?q=' . urlencode($proprietaireNom)),
        ]]);
        if (!empty($bail['bailleur_representant_nom'])) {
            fiche360_attach('REPRÉSENTANT BAILLEUR', [[
                'icon' => '👥',
                'name' => $bail['bailleur_representant_nom'] . ($bail['bailleur_representant_qualite'] ? ' (' . $bail['bailleur_representant_qualite'] . ')' : ''),
                'ref'  => $bail['bailleur_representant_email'] ?: $bail['bailleur_representant_telephone'] ?: '',
                'url'  => $bail['bailleur_representant_email'] ? 'mailto:' . $bail['bailleur_representant_email'] : '#',
            ]]);
        }
    }

    // Locataire
    fiche360_attach('LOCATAIRE', [[
        'icon' => '🔑',
        'name' => $locataireNom,
        'ref'  => !empty($bail['loc_tiers_id']) ? 'tiers #' . $bail['loc_tiers_id'] : '',
        'url'  => !empty($bail['loc_tiers_id']) ? app_url('/tiers_360.php?id=' . $bail['loc_tiers_id']) : '#',
    ]]);
    if (!empty($bail['locataire_representant_nom'])) {
        fiche360_attach('REPRÉSENTANT LOCATAIRE', [[
            'icon' => '👥',
            'name' => $bail['locataire_representant_nom'] . ($bail['locataire_representant_qualite'] ? ' (' . $bail['locataire_representant_qualite'] . ')' : ''),
            'ref'  => $bail['locataire_representant_email'] ?: $bail['locataire_representant_telephone'] ?: '',
            'url'  => $bail['locataire_representant_email'] ? 'mailto:' . $bail['locataire_representant_email'] : '#',
        ]]);
    }

    // Panneau Actions
    fiche360_actions_panel('Actions bail', [
        ['icon'=>'✏️','label'=>'Éditer le bien',           'url'=>app_url('/bien_detail.php?edit=' . $bail['bien_id'])],
        ['icon'=>'📥','label'=>'Importer un document',     'url'=>app_url('/transaction_chargement.php')],
        ['icon'=>'📋','label'=>'Voir la fiche bien 360°',  'url'=>app_url('/bien_360.php?id=' . $bail['bien_id'])],
        ['icon'=>'🎯','label'=>'Retour au tableau Baux',   'url'=>app_url('/transaction_baux_overview.php')],
    ]);
    ?>

  </div>
</div>

<?= fiche360_js() ?>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
