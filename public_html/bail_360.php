<?php
// bail_360.php — Vue 360° d'un bail
// Bien + locataire + bailleur + loyer + échéances + documents + IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$bailId = (int)($_GET['id'] ?? 0);
if ($bailId <= 0) {
    header('Location: ' . app_url('/bien_baux_liste.php'));
    exit;
}

// ─── Charge le bail + bien + locataire ──
$sql = "SELECT bb.*,
    b.id AS bien_id, b.reference_bien, b.designation, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
    b.code_postal AS bien_cp, b.surface_habitable, b.numero_lot, b.id_immeuble,
    b.id_societe AS bien_soc, b.id_agence AS bien_age,
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
try {
    $st = $pdo->prepare($sql); $st->execute([$bailId]);
    $bail = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[bail_360] chargement bail #' . $bailId . ' : ' . $e->getMessage());
    http_response_code(500);
    exit('<pre style="font:13px/1.5 monospace;padding:24px;color:#b91c1c;">'
        . 'Erreur de chargement de la fiche bail (#' . (int)$bailId . ").\n\n"
        . htmlspecialchars($e->getMessage())
        . "\n\nProbable colonne absente sur cette base. Transmets cette ligne pour correction.</pre>");
}
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
// Index des document_type présents (normalisés en minuscules).
$docsByType = [];
foreach ($docs as $d) {
    $t = strtolower(trim((string)($d['document_type'] ?? '')));
    if ($t !== '') $docsByType[$t] = ($docsByType[$t] ?? 0) + 1;
}

// ─── Checklist pièces bail ──
// 'types' = les document_type GED (minuscule) qui valident la pièce.
$pieces = [
    ['label'=>'Bail signé',              'sublabel'=>'Document principal',           'types'=>['bail_signe','bail']],
    ['label'=>"État des lieux d'entrée", 'sublabel'=>"Obligatoire à la prise d'effet",'types'=>['edl_entree','etat_lieux_entree']],
    ['label'=>"Attestation d'assurance", 'sublabel'=>'Locataire — annuel',           'types'=>['attestation_assurance','att_assurance','assurance']],
    ['label'=>'DPE',                     'sublabel'=>'Annexé au bail',               'types'=>['dpe']],
    ['label'=>'Acte de caution',         'sublabel'=>'Si garant',                    'types'=>['caution_garant','caution']],
    ['label'=>"État des lieux de sortie",'sublabel'=>'Si bail terminé',              'types'=>['edl_sortie','etat_lieux_sortie']],
];
$piecesItems = [];
foreach ($pieces as $p) {
    $ok = false;
    foreach ($p['types'] as $t) { if (isset($docsByType[$t])) { $ok = true; break; } }
    $piecesItems[] = [
        'label'    => $p['label'],
        'sublabel' => $p['sublabel'],
        'ok'       => $ok,
        'add_url'  => app_url('/bail_360.php?id=' . $bailId),
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
$extraCss     = fiche360_css() . '<style>
/* Bouton d\'action d\'en-tête : même style doux que bien_360 / immeuble_360 */
.f360-header-actions .tr-btn-primary { background:#f3f6fb; color:#2d4a72; border-color:#e3ebf5; }
.f360-header-actions .tr-btn-primary:hover { background:#eaf1fa; color:#243B5C; }
</style>';
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
        ['label'=>'📁 Documents du bien','url'=>app_url('/bien_documents_list.php?id=' . $bail['bien_id']),'class'=>'tr-btn tr-btn-primary'],
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
                    <strong>📜 Clause résolutoire :</strong> <?= h(mb_substr((string)$bail['clause_resolutoire'], 0, 300)) ?><?= mb_strlen((string)$bail['clause_resolutoire']) > 300 ? '…' : '' ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($bail['conditions_particulieres'])): ?>
                <div style="margin-top:8px; padding:10px 12px; background:#f9f7ff; border-left:3px solid #7c3aed; border-radius:6px;">
                    <strong>📋 Conditions particulières :</strong> <?= h(mb_substr((string)$bail['conditions_particulieres'], 0, 500)) ?><?= mb_strlen((string)$bail['conditions_particulieres']) > 500 ? '…' : '' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Charger un document (rangé automatiquement : bail + bien) -->
    <div class="f360-card">
        <h3>📥 Charger un document du bail</h3>
        <div style="font-size:12px;color:#6b7280;margin-bottom:10px;">
            Le contexte est déjà connu (propriétaire → immeuble → bien → bail). Le document est
            <b>rangé automatiquement</b> : rattaché au <b>bail</b> et visible dans le <b>bien</b>. Aucun chemin à choisir.
        </div>
        <form id="bailUpForm" enctype="multipart/form-data">
            <?= csrf_field('bail_doc_upload') ?>
            <input type="hidden" name="id_bail" value="<?= (int)$bailId ?>">
            <input type="file" name="document[]" id="bailUpFile" multiple
                   accept=".pdf,.jpg,.jpeg,.png,.tiff,.docx,.xlsx,.heic" style="display:none;">

            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
                <label style="font-size:12.5px;font-weight:700;color:#5b21b6;">Type de pièce :</label>
                <select id="bailUpType" name="type_piece"
                        style="padding:7px 10px;border:1px solid #b39ddb;border-radius:8px;background:#fff;font-size:13px;">
                    <option value="bail_signe">📄 Bail signé</option>
                    <option value="edl_entree">🔑 État des lieux d'entrée</option>
                    <option value="attestation_assurance">🛡 Attestation d'assurance</option>
                    <option value="dpe">⚡ DPE</option>
                    <option value="caution_garant">✍️ Acte de caution</option>
                    <option value="edl_sortie">📦 État des lieux de sortie</option>
                    <option value="autre">📎 Autre document</option>
                </select>
            </div>

            <div id="bailDropzone" tabindex="0"
                 style="border:2px dashed #b39ddb;border-radius:12px;background:#faf8ff;padding:26px 18px;text-align:center;cursor:pointer;transition:.15s;">
                <div style="font-size:30px;line-height:1;">📥</div>
                <div style="font-weight:800;color:#5b21b6;margin-top:6px;">Glissez un document ici</div>
                <div style="font-size:12px;color:#7a766f;margin-top:3px;">ou cliquez pour parcourir · PDF, images, DOCX… (50 Mo max/fichier)</div>
                <div id="bailUpPicked" style="font-size:12px;color:#2d8a4e;font-weight:700;margin-top:8px;"></div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:10px;">
                <button type="submit" id="bailUpBtn"
                        style="background:linear-gradient(135deg,#5e35b1,#7e57c2);color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;">
                    📎 Charger sur ce bail
                </button>
                <span id="bailUpMsg" style="font-size:12.5px;font-weight:600;"></span>
            </div>
        </form>
    </div>

    <!-- Documents du bail -->
    <div class="f360-card">
        <h3>📂 Documents du bail <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document. <a href="<?= h(app_url('/bien_documents_list.php?id=' . $bail['bien_id'])) ?>">→ Gérer les documents du bien</a></div>
        <?php else: foreach ($docs as $d): ?>
            <div onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>, window.BAIL_FIELDS)"
                 style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center; cursor:pointer;"
                 onmouseover="this.style.background='#faf8ff'" onmouseout="this.style.background='transparent'">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;">[<?= h($d['document_type']) ?>]</span>
                <span style="flex:1;"><?= h($d['name_display']) ?></span>
                <span style="color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                <span style="color:#5b21b6; font-size:11px; font-weight:700;">Ouvrir ›</span>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
    <?php
    // ── Champs extraits du bail (panneau gauche du modal) ──
    $eur = fn($v) => ($v === null || $v === '' || (float)$v == 0.0) ? null : number_format((float)$v, 0, ',', ' ') . ' €';
    $meta = json_decode((string)($bail['metadata'] ?? ''), true) ?: [];
    $ia   = $meta['analyse_ia_bail']['data'] ?? [];
    $rev  = $ia['revision'] ?? [];
    $loyerCC = ((float)($bail['loyer_mensuel_hc'] ?? 0) + (float)($bail['charges_mensuelles'] ?? 0));
    $bailFields = array_values(array_filter([
        ['section' => 'Finances'],
        ['label' => 'Loyer HC',           'value' => $eur($bail['loyer_mensuel_hc'] ?? null)],
        ['label' => 'Charges',            'value' => $eur($bail['charges_mensuelles'] ?? null)],
        ['label' => 'Loyer CC',           'value' => $eur($loyerCC ?: null)],
        ['label' => 'Dépôt de garantie',  'value' => $eur($bail['depot_garantie'] ?? null)],
        ['label' => 'Indice',             'value' => trim((string)($bail['indice_type'] ?? '') . ' ' . (string)($bail['indice_trimestre'] ?? '')) ?: null],
        ['label' => 'Valeur indice',      'value' => $bail['indice_valeur'] ?? null],
        ['label' => 'Périodicité',        'value' => $bail['periodicite_paiement'] ?? null],
        ['section' => 'Bail'],
        ['label' => 'Nature',             'value' => $bail['bail_nature'] ?? null],
        ['label' => 'Locataire',          'value' => $locataireNom ?: null],
        ['label' => 'Prise d\'effet',     'value' => $bail['date_prise_effet'] ?? null],
        ['label' => 'Fin',                'value' => $bail['date_fin'] ?? null],
        ['label' => 'Durée (extraite)',   'value' => $ia['conditions']['duree_bail'] ?? null],
        ['section' => 'Révision (extraite)'],
        ['label' => 'Type',               'value' => $rev['type'] ?? null],
        ['label' => 'Trimestre réf.',     'value' => $rev['trimestre_reference'] ?? null],
        ['label' => 'Année réf.',         'value' => $rev['annee_reference'] ?? null],
        ['label' => 'Indice réf.',        'value' => $rev['indice_reference'] ?? null],
        ['section' => 'Clauses'],
        ['label' => 'Clause résolutoire', 'value' => !empty($bail['clause_resolutoire']) ? 'Oui' : null],
        ['label' => 'Conditions particulières', 'value' => $bail['conditions_particulieres'] ?? null],
        ['label' => 'Diagnostics mentionnés', 'value' => !empty($ia['diagnostics_mentionnes']) ? implode(', ', (array)$ia['diagnostics_mentionnes']) : null],
    ], fn($f) => isset($f['section']) || ($f['value'] !== null && trim((string)$f['value']) !== '')));
    // Retire les sections devenues orphelines (sans champ derrière).
    $clean = []; $n = count($bailFields);
    foreach ($bailFields as $idx => $f) {
        if (isset($f['section'])) {
            $hasNext = ($idx + 1 < $n) && !isset($bailFields[$idx + 1]['section']);
            if ($hasNext) $clean[] = $f;
        } else { $clean[] = $f; }
    }
    ?>
    <script>window.BAIL_FIELDS = <?= json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

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
    // Panneau Actions — EN HAUT de la colonne (convention 360°)
    fiche360_actions_panel('Actions bail', [
        ['icon'=>'📤','label'=>'Charger des documents','url'=>'#','onclick'=>"window.fbxOpenUploadModal({origin:'bail_360', bail_id:" . (int)$bailId . ", bien_id:" . (int)$bail['bien_id'] . ", immeuble_id:" . (int)($bail['id_immeuble'] ?? 0) . ", soc_id:" . (int)($bail['bien_soc'] ?? 0) . ", age_id:" . (int)($bail['bien_age'] ?? 0) . ", entite_id_bdd:" . (int)$bailId . ", n1:'03_GESTION_LOCATIVE', entite_nom:'" . addslashes('Bail #' . $bailId . ' · ' . $bienLabel) . "'});return false;"],
        ['icon'=>'📨','label'=>'Demander un document (locataire)','url'=>app_url('/document_request_new.php?ctx=BAIL&id=' . $bailId . '&back=' . urlencode('bail_360.php?id=' . $bailId))],
        ['icon'=>'📥','label'=>'Importer docs du bail (OneDrive)','url'=>'javascript:odClasserOpen()'],
        ['icon'=>'📂','label'=>'Ouvrir le dossier OneDrive','url'=>'javascript:odOpenFolder()'],
        ['icon'=>'✏️','label'=>'Éditer le bien',           'url'=>app_url('/bien_detail.php?edit=' . $bail['bien_id'])],
        ['icon'=>'📁','label'=>'Documents du bien',        'url'=>app_url('/bien_documents_list.php?id=' . $bail['bien_id'])],
        ['icon'=>'📋','label'=>'Voir la fiche bien 360°',  'url'=>app_url('/bien_360.php?id=' . $bail['bien_id'])],
        ['icon'=>'🎯','label'=>'Retour au tableau Baux',   'url'=>app_url('/bien_baux_liste.php')],
    ]);

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

    ?>

  </div>
</div>

<?= fiche360_js() ?>
<script>
(function(){
  var form = document.getElementById('bailUpForm');
  if (!form) return;
  var input = document.getElementById('bailUpFile');
  var zone  = document.getElementById('bailDropzone');
  var picked= document.getElementById('bailUpPicked');
  var UP_URL = <?= json_encode(app_url('/api/bail_doc_upload.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

  function listNames(files){
    return Array.prototype.map.call(files, function(f){ return f.name; }).join(', ');
  }
  function doUpload(files){
    var btn = document.getElementById('bailUpBtn');
    var msg = document.getElementById('bailUpMsg');
    if (!files || !files.length){ msg.style.color='#c62828'; msg.textContent='❌ Aucun fichier sélectionné.'; return; }
    var fd = new FormData();
    fd.append('csrf_token', form.querySelector('[name=csrf_token]') ? form.querySelector('[name=csrf_token]').value : '');
    fd.append('id_bail', form.querySelector('[name=id_bail]').value);
    var tSel = document.getElementById('bailUpType');
    fd.append('type_piece', tSel ? tSel.value : 'bail_signe');
    for (var i=0;i<files.length;i++) fd.append('document[]', files[i]);
    var prev = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Chargement…'; msg.textContent='';
    fetch(UP_URL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        btn.disabled=false; btn.textContent=prev;
        if (j && j.ok){
          msg.style.color='#2d8a4e';
          var t = '✓ '+j.n+' document(s) rangé(s) sur le bail et le bien.';
          if (j.analyse && j.analyse.ok){
            var nf = (j.analyse.champs_remplis||[]).length;
            t += nf>0 ? ' 🤖 Bail analysé — '+nf+' champ(s) renseigné(s).' : ' 🤖 Bail analysé (champs déjà remplis).';
          } else if (j.analyse && j.analyse.error){
            t += ' ⚠️ Analyse IA : '+j.analyse.error+'.';
          }
          msg.textContent = t;
          setTimeout(function(){ location.reload(); }, 1400);
        } else {
          msg.style.color='#c62828';
          msg.textContent='❌ '+((j && (j.error || (j.errors||[]).join(' / '))) || 'Échec');
        }
      })
      .catch(function(e){ btn.disabled=false; btn.textContent=prev; msg.style.color='#c62828'; msg.textContent='❌ Erreur réseau : '+e; });
  }

  // Clic sur la zone → ouvre le sélecteur
  zone.addEventListener('click', function(){ input.click(); });
  zone.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });
  input.addEventListener('change', function(){ if (input.files.length) picked.textContent = '📄 '+listNames(input.files); });

  // Drag & drop
  ['dragenter','dragover'].forEach(function(ev){
    zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.background='#efe7f7'; zone.style.borderColor='#5e35b1'; });
  });
  ['dragleave','dragend'].forEach(function(ev){
    zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.background='#faf8ff'; zone.style.borderColor='#b39ddb'; });
  });
  zone.addEventListener('drop', function(e){
    e.preventDefault(); e.stopPropagation();
    zone.style.background='#faf8ff'; zone.style.borderColor='#b39ddb';
    var files = e.dataTransfer && e.dataTransfer.files;
    if (files && files.length){ picked.textContent = '📄 '+listNames(files); doUpload(files); }
  });

  // Empêche le navigateur d'ouvrir le fichier si lâché à côté
  ['dragover','drop'].forEach(function(ev){ document.addEventListener(ev, function(e){ if (e.target!==zone && !zone.contains(e.target)) e.preventDefault(); }); });

  // Bouton / submit → upload des fichiers du sélecteur
  form.addEventListener('submit', function(ev){ ev.preventDefault(); doUpload(input.files); });
})();
</script>

<!-- ── Modal classement OneDrive → GED (scope BAIL : bail + EDL entrée) ── -->
<div id="odModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Documents du bail — <?= h($locataireNom) ?></h3>
      <button type="button" onclick="document.getElementById('odModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <div id="odBody" style="flex:1;overflow:auto;padding:16px 18px;font-size:13px;"><div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div></div>
    <div style="padding:12px 18px;border-top:1px solid #eef0f2;display:flex;gap:10px;align-items:center;">
      <button type="button" id="odCommitBtn" onclick="odClasserCommit()" disabled
              style="background:#2d8a4e;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;opacity:.5;">✓ Valider et classer</button>
      <span id="odMsg" style="font-size:12.5px;font-weight:700;"></span>
    </div>
  </div>
</div>
<script>
(function(){
  var BAILID=<?= (int)$bailId ?>, CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function post(action){var fd=new FormData();fd.append('csrf_token',CSRF);fd.append('id_bail',BAILID);fd.append('action',action);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  window.odOpenFolder=function(){
    var w=window.open('','_blank'); if(w)w.document.write('Ouverture du dossier OneDrive…');
    post('folder_url').then(function(j){
      if(j&&j.ok&&j.url){ if(w){w.location.href=j.url;}else{window.location.href=j.url;} }
      else { if(w)w.close(); alert('❌ '+((j&&j.error)||'Dossier OneDrive introuvable')); }
    }).catch(function(e){ if(w)w.close(); alert('❌ Réseau : '+e); });
  };
  window.odClasserOpen=function(){
    document.getElementById('odModal').style.display='flex';
    document.getElementById('odCommitBtn').disabled=true; document.getElementById('odCommitBtn').style.opacity=.5;
    document.getElementById('odMsg').textContent='';
    document.getElementById('odBody').innerHTML='<div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div>';
    post('scan').then(function(j){
      if(!j||!j.ok){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ '+esc((j&&j.error)||'Erreur')+(j&&j.base?'<br><small>base: '+esc(j.base)+'</small>':'')+'</div>';return;}
      var rows=(j.items||[]).map(function(it){
        var col=it.status==='certain'?'#2d8a4e':(it.status==='pile'?'#8a6d1b':'#c62828');
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;margin-top:2px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">→ ce bail</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason)+'</td></tr>';
      }).join('');
      var nbCertain=(j.items||[]).filter(function(x){return x.status==='certain';}).length;
      document.getElementById('odBody').innerHTML=
        '<div style="margin-bottom:8px;color:#6b7280;">Dossier <b>'+esc(j.folder)+'</b> · <b>'+nbCertain+'</b> doc(s) du bail (bail signé + EDL entrée).</div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(rows||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun bail/EDL entrée trouvé pour ce locataire.</td></tr>')+'</tbody></table>';
      var b=document.getElementById('odCommitBtn'); if(nbCertain>0){b.disabled=false;b.style.opacity=1;}
    }).catch(function(e){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>';});
  };
  window.odClasserCommit=function(){
    var b=document.getElementById('odCommitBtn'),m=document.getElementById('odMsg');
    b.disabled=true;b.style.opacity=.5;m.style.color='#6b7280';m.textContent='⏳ Classement en cours…';
    post('commit').then(function(j){
      if(!j||!j.ok){m.style.color='#c62828';m.textContent='❌ '+esc((j&&j.error)||'Erreur');return;}
      m.style.color='#2d8a4e';m.textContent='✓ '+j.classes+' document(s) classé(s) en GED'+(j.erreurs&&j.erreurs.length?(' · '+j.erreurs.length+' erreur(s)'):'')+'. Recharge la page.';
    }).catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau : '+esc(e);});
  };
})();
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
