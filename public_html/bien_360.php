<?php
// bien_360.php — Vue 360° d'un bien immobilier
// Synthèse complète : bail actif + offres + docs + pièces obligatoires + tiers + immeuble + IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$bienId = (int)($_GET['id'] ?? 0);
if ($bienId <= 0) {
    header('Location: ' . app_url('/bien_liste.php'));
    exit;
}

// ─── Charge le bien + immeuble + propriétaire + tiers ────────────────
$sql = "SELECT b.*,
    COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1)     AS bien_adresse,
    COALESCE(NULLIF(b.code_postal, ''), i.code_postal) AS bien_cp,
    COALESCE(NULLIF(b.ville, ''), i.ville)             AS bien_ville,
    i.id AS immeuble_id, i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
    p.id AS proprio_id, p.id_tiers AS proprio_tiers_id,
    COALESCE(NULLIF(p.societe, ''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
    COALESCE(NULLIF(tp.nom_affichage, ''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom
FROM biens b
LEFT JOIN immeubles i      ON i.id = b.id_immeuble
LEFT JOIN proprietaires p  ON p.id = b.id_proprietaire
LEFT JOIN tiers tp         ON tp.id = p.id_tiers
WHERE b.id = ? LIMIT 1";
$st = $pdo->prepare($sql); $st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit('Bien introuvable.');
}

$proprietaireNom = $bien['proprio_tiers_nom'] ?: $bien['proprio_nom_legacy'] ?: '—';
$adresseComplete = trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? ''));

// ─── Bail actif + locataire ──────────────────────────────────────────
$bailActif = null; $locataireNom = null; $locataireTiersId = null;
try {
    $stB = $pdo->prepare("SELECT bb.*, t.nom_affichage AS loc_tiers_nom, t.raison_sociale AS loc_raison
        FROM bien_baux bb
        LEFT JOIN tiers t ON t.id = bb.id_tiers_locataire
        WHERE bb.id_bien = ? AND bb.statut = 'actif'
        ORDER BY bb.date_prise_effet DESC LIMIT 1");
    $stB->execute([$bienId]);
    $bailActif = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($bailActif) {
        $locataireNom = $bailActif['loc_tiers_nom'] ?: $bailActif['loc_raison']
            ?: ($bailActif['locataire_raison_sociale'] ?: trim((string)$bailActif['locataire_prenom'] . ' ' . $bailActif['locataire_nom']));
        $locataireTiersId = (int)($bailActif['id_tiers_locataire'] ?? 0);
    }
} catch (Throwable $e) {}

// ─── Archives baux ──
$archivesBaux = [];
try {
    $stA = $pdo->prepare("SELECT id, bail_nature, statut, date_prise_effet, date_fin,
        locataire_raison_sociale, locataire_nom, locataire_prenom, loyer_mensuel_hc
        FROM bien_baux WHERE id_bien = ? AND statut <> 'actif'
        ORDER BY date_prise_effet DESC LIMIT 10");
    $stA->execute([$bienId]);
    $archivesBaux = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Offres en cours ──
$offres = [];
try {
    $stO = $pdo->prepare("SELECT id, nom, prenom, prix_propose, financement_type, statut_offre, date_creation
        FROM leads_annonces WHERE id_bien = ? AND type_contact = 'offre'
        ORDER BY date_creation DESC LIMIT 20");
    $stO->execute([$bienId]);
    $offres = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$nbOffresActives = 0;
foreach ($offres as $o) if (!in_array($o['statut_offre'] ?? '', ['refusee','expiree'], true)) $nbOffresActives++;

// ─── Documents GED ──
$docs = [];
try {
    $stD = $pdo->prepare("SELECT id, name_display, document_type, created_at,
        JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.visibilite')) AS visibilite
        FROM ged_documents
        WHERE status='active' AND source_module='05_TRANSACTION'
          AND JSON_EXTRACT(metadata, '\$.classement.bien_id_bdd') = ?
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$bienId]);
    $docs = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

// ─── Checklist pièces obligatoires ──
$pieces = [
    ['code'=>'DIAG_DPE',      'label'=>'Diagnostic DPE',          'sublabel'=>'Performance énergétique'],
    ['code'=>'DIAG_ERP',      'label'=>'État des risques (ERP)',  'sublabel'=>'ERRIAL'],
    ['code'=>'DIAG_PLOMB',    'label'=>'Diagnostic plomb',        'sublabel'=>'CREP (logements <1949)'],
    ['code'=>'DIAG_AMIANTE',  'label'=>'Diagnostic amiante',      'sublabel'=>'Permis <1997'],
    ['code'=>'DIAG_GAZ',      'label'=>'Diagnostic gaz',          'sublabel'=>'Installation >15 ans'],
    ['code'=>'DIAG_ELEC',     'label'=>'Diagnostic électricité',  'sublabel'=>'Installation >15 ans'],
    ['code'=>'SURFACE_CARREZ','label'=>'Surface Carrez / Boutin', 'sublabel'=>'Mesurage loi Carrez'],
    ['code'=>'ETAT_LIEUX',    'label'=>'État des lieux d\'entrée','sublabel'=>'Si bail actif'],
    ['code'=>'PHOTO',         'label'=>'Photos du bien',          'sublabel'=>'Pour annonce / dossier'],
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
$nbPiecesOk = array_sum(array_map(fn($p)=>$p['ok']?1:0, $piecesItems));

// ─── Statut visuel intelligent ──
$statusColor = 'gray';
$statusIcon  = '⚪';
$statusMsg   = 'Bien créé.';
$statusAlertes = '';
if ($bien['date_retrait_commercialisation'] || $bien['prix_final_vente']) {
    $statusColor = 'gray'; $statusIcon = '🏁';
    $statusMsg = '<strong>Bien vendu / retiré</strong> — clôture en cours.';
} elseif ($nbOffresActives > 0) {
    $statusColor = 'orange'; $statusIcon = '💰';
    $statusMsg = "<strong>{$nbOffresActives} offre(s) active(s)</strong> — décision attendue.";
} elseif ($bailActif) {
    $statusColor = 'green'; $statusIcon = '✅';
    $statusMsg = "<strong>Bien loué</strong> à <strong>" . h($locataireNom) . "</strong> jusqu'au " . h($bailActif['date_fin']) . ".";
} elseif (($bien['statut_occupation'] ?? '') === 'vacant') {
    $statusColor = 'orange'; $statusIcon = '🔓';
    $statusMsg = "<strong>" . h(ucfirst($bien['type_commercialisation'] ?: 'Bien')) . " · Vacant</strong> — aucun bail actif.";
}
$piecesManquantes = 9 - $nbPiecesOk;
if ($piecesManquantes > 0) {
    $statusAlertes = $piecesManquantes . ' pièce(s) à charger';
}

// ─── Mentions dans (CRG, autres docs qui citent ce bien) ──
$mentions = [];
try {
    $stM = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status='active'
          AND source_module <> '05_TRANSACTION'
          AND (
              JSON_EXTRACT(metadata, '\$.classement.bien_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'bien', 'id', ?), '\$')
          )
        ORDER BY created_at DESC LIMIT 10");
    $stM->execute([$bienId, $bienId]);
    $mentions = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Représentants du propriétaire (via tiers_contacts) ──
$representants = [];
if (!empty($bien['proprio_tiers_id'])) {
    try {
        $stR = $pdo->prepare("SELECT tc.qualite, t.id, t.nom, t.prenom, t.email, t.telephone
            FROM tiers_contacts tc
            INNER JOIN tiers t ON t.id = tc.id_tiers_contact
            WHERE tc.id_tiers_entite = ? AND tc.actif = 1
            ORDER BY tc.priorite ASC LIMIT 10");
        $stR->execute([(int)$bien['proprio_tiers_id']]);
        $representants = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

$pageTitle    = 'Bien · ' . ($bien['reference_bien'] ?: '#' . $bienId);
$pageSubtitle = 'Vue 360° · ' . ($bien['ville'] ?? '');
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<?php
// ─── BREADCRUMB hiérarchique ──
$chaine = [];
if (!empty($bien['proprio_tiers_id'])) {
    $chaine[] = ['icon'=>'👤','label'=>$proprietaireNom,'url'=>app_url('/admin/admin_tiers_merge.php?q=' . urlencode('#' . $bien['proprio_tiers_id']))];
}
if (!empty($bien['immeuble_id'])) {
    $chaine[] = ['icon'=>'🏢','label'=>($bien['nom_immeuble'] ?: $bien['imm_adresse']),'url'=>app_url('/agency_immeuble_detail.php?id=' . $bien['immeuble_id'])];
}
$chaine[] = ['icon'=>'🏠','label'=>'Ce bien','url'=>null];
fiche360_breadcrumb($chaine, 'Patrimoine');

// ─── HEADER bien ──
$badgeBail = null;
if ($bien['date_retrait_commercialisation'] || $bien['prix_final_vente']) $badgeBail = ['label'=>'Vendu','class'=>'vendu'];
elseif ($bailActif)                                                       $badgeBail = ['label'=>'Loué','class'=>'loue'];
elseif (($bien['statut_occupation'] ?? '') === 'vacant')                  $badgeBail = ['label'=>'Vacant','class'=>'vacant'];

$metas = [];
if (!empty($bien['surface_habitable'])) $metas[] = ['icon'=>'📐','text'=>number_format((float)$bien['surface_habitable'], 0) . ' m²'];
if (!empty($bien['nb_pieces']))         $metas[] = ['icon'=>'🚪','text'=>$bien['nb_pieces'] . ' pièces'];
if (!empty($bien['dpe_classe']))        $metas[] = ['icon'=>'⚡','text'=>'DPE ' . $bien['dpe_classe']];
if (!empty($bien['numero_lot']))        $metas[] = ['icon'=>'🏢','text'=>'Lot ' . $bien['numero_lot']];

fiche360_header(
    '🏠',
    ($bien['designation'] ?: $bien['reference_bien'] ?: 'Bien #' . $bienId),
    $badgeBail,
    $adresseComplete ?: 'Adresse non renseignée',
    $metas,
    [
        ['label'=>'✏️ Éditer','url'=>app_url('/bien_detail.php?edit=' . $bienId),'class'=>'tr-btn'],
        ['label'=>'📥 Importer un document','url'=>app_url('/transaction_chargement.php'),'class'=>'tr-btn tr-btn-primary'],
    ]
);

// ─── Bandeau IA contextuelle ──
fiche360_ia_bar('bien', $bienId, "Demander à l'IA sur ce bien (rendement, échéances, conformité...)");

// ─── Bandeau statut ──
fiche360_status_banner($statusMsg, $statusColor, $statusIcon, $statusAlertes);
?>

<div class="f360-grid">

  <!-- ═══════════════════ COLONNE PRINCIPALE ═══════════════════ -->
  <div>

    <!-- Bail actif / Archives -->
    <div class="f360-card">
        <div class="f360-tabs">
            <button type="button" class="f360-tab active" onclick="f360tab(this, 'tab-bail')">📋 Bail actif <span class="count"><?= $bailActif ? 1 : 0 ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tab(this, 'tab-arch')">🗂 Archives <span class="count"><?= count($archivesBaux) ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tab(this, 'tab-offres')">💰 Offres <span class="count"><?= count($offres) ?></span></button>
        </div>

        <div id="tab-bail">
            <?php if ($bailActif): ?>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px;">
                    <div><div style="font-size:10px; color:#9a9690;">LOCATAIRE</div><strong><?= h($locataireNom) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">NATURE</div><strong><?= h($bailActif['bail_nature']) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">PRISE D'EFFET</div><strong><?= h($bailActif['date_prise_effet']) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">FIN</div><strong><?= h($bailActif['date_fin']) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">LOYER DE BASE</div><strong><?= number_format((float)$bailActif['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">CHARGES</div><strong><?= number_format((float)$bailActif['charges_mensuelles'], 0, ',', ' ') ?> €/mois</strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">DG</div><strong><?= number_format((float)$bailActif['depot_garantie'], 0, ',', ' ') ?> €</strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">INDICE</div><strong><?= h($bailActif['indice_type']) ?> <?= h($bailActif['indice_trimestre']) ?></strong></div>
                </div>
                <?php if ($bailActif['conditions_particulieres'] ?? ''): ?>
                    <div style="margin-top:12px; padding:10px 12px; background:#f9f7ff; border-left:3px solid #7c3aed; border-radius:6px; font-size:12px;">
                        <strong>📋 Conditions particulières :</strong> <?= h(mb_substr((string)$bailActif['conditions_particulieres'], 0, 400)) ?><?= mb_strlen($bailActif['conditions_particulieres']) > 400 ? '…' : '' ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="f360-empty"><div class="em-ico">🔓</div>Aucun bail actif sur ce bien.</div>
            <?php endif; ?>
        </div>

        <div id="tab-arch" style="display:none;">
            <?php if (empty($archivesBaux)): ?>
                <div class="f360-empty"><div class="em-ico">🗂</div>Pas d'historique de baux.</div>
            <?php else: foreach ($archivesBaux as $a):
                $aLoc = $a['locataire_raison_sociale'] ?: trim((string)$a['locataire_prenom'] . ' ' . $a['locataire_nom']);
            ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px;">
                    <strong>Bail #<?= (int)$a['id'] ?></strong> · <?= h($a['bail_nature']) ?> · <em><?= h($a['statut']) ?></em>
                    — <?= h($aLoc) ?> · <?= h($a['date_prise_effet']) ?> → <?= h($a['date_fin']) ?>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div id="tab-offres" style="display:none;">
            <?php if (empty($offres)): ?>
                <div class="f360-empty"><div class="em-ico">💰</div>Aucune offre reçue.</div>
            <?php else: foreach ($offres as $o):
                $sCol = match($o['statut_offre']){'acceptee'=>'#2d6a35','refusee'=>'#a8323b','expiree'=>'#7a766f',default=>'#8a4c12'};
            ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:10px; align-items:center;">
                    <strong style="color:<?= $sCol ?>; font-size:14px;"><?= number_format((float)$o['prix_propose'], 0, ',', ' ') ?> €</strong>
                    <span><?= h(trim((string)$o['prenom'] . ' ' . $o['nom'])) ?></span>
                    <span style="color:#9a9690; font-size:10px;"><?= h($o['financement_type'] ?? '') ?> · <?= h($o['statut_offre'] ?? '?') ?></span>
                    <span style="margin-left:auto; color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$o['date_creation']))) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Documents du bien -->
    <div class="f360-card">
        <h3>📂 Documents du bien <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/transaction_chargement.php')) ?>">→ Charger un document</a></div>
        <?php else: foreach ($docs as $d): ?>
            <div style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center;">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:120px;">[<?= h($d['document_type']) ?>]</span>
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
    // Checklist pièces obligatoires
    fiche360_checklist('Pièces du bien', $piecesItems);

    // Rattachement propriétaire
    if (!empty($bien['proprio_id'])) {
        $proprioLinks = [['icon'=>'👤','name'=>$proprietaireNom,'ref'=>'#' . $bien['proprio_id'] . (!empty($bien['proprio_tiers_id']) ? ' · tiers ' . $bien['proprio_tiers_id'] : ''),'url'=>app_url('/agency_proprietaires.php?q=' . urlencode($proprietaireNom))]];
        // Représentants
        foreach ($representants as $r) {
            $rNom = trim((string)$r['prenom'] . ' ' . $r['nom']);
            $proprioLinks[] = ['icon'=>'👥','name'=>$rNom . ' (' . $r['qualite'] . ')','ref'=>$r['email'] ?: '','url'=>'#'];
        }
        fiche360_attach('PROPRIÉTAIRE' . (count($representants) > 0 ? ' + REPRÉSENTANTS' : ''), $proprioLinks);
    }

    // Rattachement immeuble
    if (!empty($bien['immeuble_id'])) {
        fiche360_attach('IMMEUBLE', [[
            'icon' => '🏢',
            'name' => $bien['nom_immeuble'] ?: $bien['imm_adresse'] ?: 'Immeuble',
            'ref'  => $bien['imm_ville'] ?: '',
            'url'  => app_url('/agency_immeuble_detail.php?id=' . $bien['immeuble_id']),
        ]]);
    }

    // Rattachement locataire actuel
    if ($bailActif && $locataireTiersId) {
        fiche360_attach('LOCATAIRE ACTUEL', [[
            'icon' => '👥',
            'name' => $locataireNom,
            'ref'  => 'Bail #' . $bailActif['id'] . ' · ' . $bailActif['date_prise_effet'] . ' → ' . $bailActif['date_fin'],
            'url'  => '#',
        ]]);
    }

    // Panneau Actions
    fiche360_actions_panel('Actions bien', [
        ['icon'=>'📥','label'=>'Importer un document',     'url'=>app_url('/transaction_chargement.php')],
        ['icon'=>'💰','label'=>'Saisir une offre',         'url'=>app_url('/transaction_index.php?q=' . urlencode((string)$bien['reference_bien']))],
        ['icon'=>'✏️','label'=>'Éditer la fiche bien',     'url'=>app_url('/bien_detail.php?edit=' . $bienId)],
        ['icon'=>'📡','label'=>'Voir/créer l\'annonce',    'url'=>app_url('/bien_detail.php?edit=' . $bienId . '&section=annonce')],
        ['icon'=>'🎯','label'=>'Retour au tableau Transactions','url'=>app_url('/transaction_index.php')],
    ]);
    ?>

  </div>
</div>

<?= fiche360_js() ?>
<script>
function f360tab(btn, targetId) {
    btn.parentElement.querySelectorAll('.f360-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const container = btn.closest('.f360-card');
    ['tab-bail','tab-arch','tab-offres'].forEach(id => {
        const el = container.querySelector('#' + id);
        if (el) el.style.display = (id === targetId) ? 'block' : 'none';
    });
}
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
