<?php
// tiers_360.php — Vue 360° d'un tiers (propriétaire, locataire, agent, fournisseur…)
// Synthèse de tous les rôles + biens + baux + représentants + documents + mentions
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$tiersId = (int)($_GET['id'] ?? 0);
if ($tiersId <= 0) {
    header('Location: ' . app_url('/admin/admin_tiers_merge.php'));
    exit;
}

// ─── Charge le tiers ──
$st = $pdo->prepare("SELECT * FROM tiers WHERE id = ? LIMIT 1");
$st->execute([$tiersId]);
$tiers = $st->fetch(PDO::FETCH_ASSOC);
if (!$tiers) {
    http_response_code(404);
    exit('Tiers introuvable.');
}

// ─── Lookup id_proprio_legacy (pour le bouton « Voir la fiche complète ») ──
$idProprioLegacy = 0;
try {
    $stPL = $pdo->prepare("SELECT id FROM proprietaires WHERE id_tiers = ? ORDER BY id DESC LIMIT 1");
    $stPL->execute([$tiersId]);
    $idProprioLegacy = (int)($stPL->fetchColumn() ?: 0);
} catch (Throwable $e) {}

$nomAffichage = $tiers['nom_affichage']
    ?: $tiers['raison_sociale']
    ?: trim((string)$tiers['prenom'] . ' ' . $tiers['nom'])
    ?: ('Tiers #' . $tiersId);

$estPersonneMorale = !empty($tiers['raison_sociale']) || !empty($tiers['siren']);

// ─── Rôles actifs du tiers ──
$roles = [];
try {
    $stR = $pdo->prepare("SELECT tr.role_code, tr.date_debut, tr.date_fin, tr.id_entite_metier, tr.entite_type
        FROM tiers_roles tr
        WHERE tr.id_tiers = ? AND tr.actif = 1
        ORDER BY tr.role_code ASC, tr.date_debut DESC
        LIMIT 100");
    $stR->execute([$tiersId]);
    $roles = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$rolesByCode = [];
foreach ($roles as $r) $rolesByCode[$r['role_code']] = ($rolesByCode[$r['role_code']] ?? 0) + 1;

// ─── Biens dont ce tiers est PROPRIÉTAIRE ──
$biensProprio = [];
try {
    $stBP = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation, b.ville, b.adresse_1,
        b.surface_habitable, b.type_commercialisation, b.statut_occupation,
        (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS nb_baux_actifs
        FROM biens b
        INNER JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE p.id_tiers = ?
        ORDER BY b.id DESC LIMIT 50");
    $stBP->execute([$tiersId]);
    $biensProprio = $stBP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Baux dont ce tiers est LOCATAIRE ──
$bauxLocataire = [];
try {
    $stBL = $pdo->prepare("SELECT bb.id, bb.bail_nature, bb.statut, bb.date_prise_effet, bb.date_fin,
        bb.loyer_mensuel_hc, b.reference_bien, b.designation, b.ville
        FROM bien_baux bb
        INNER JOIN biens b ON b.id = bb.id_bien
        WHERE bb.id_tiers_locataire = ?
        ORDER BY bb.date_prise_effet DESC LIMIT 30");
    $stBL->execute([$tiersId]);
    $bauxLocataire = $stBL->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Représentants du tiers (si personne morale / indivision) ──
$representants = [];
try {
    $stRep = $pdo->prepare("SELECT tc.qualite, tc.priorite, t.id, t.nom, t.prenom, t.email, t.telephone
        FROM tiers_contacts tc
        INNER JOIN tiers t ON t.id = tc.id_tiers_contact
        WHERE tc.id_tiers_entite = ? AND tc.actif = 1
        ORDER BY tc.priorite ASC, t.nom ASC LIMIT 20");
    $stRep->execute([$tiersId]);
    $representants = $stRep->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Documents GED du tiers ──
$docs = [];
try {
    $stD = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND (
              JSON_EXTRACT(metadata, '$.classement.tiers_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'tiers', 'id', ?), '$')
          )
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$tiersId, $tiersId]);
    $docs = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Mentions (CRG, courriers qui citent ce tiers) ──
$mentions = [];
try {
    $stM = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents
        WHERE status = 'active'
          AND source_module <> '05_TRANSACTION'
          AND JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'tiers', 'id', ?), '$')
        ORDER BY created_at DESC LIMIT 10");
    $stM->execute([$tiersId]);
    $mentions = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Statut visuel ──
$nbBiens = count($biensProprio);
$nbBaux  = count($bauxLocataire);
$nbBauxActifs = count(array_filter($bauxLocataire, fn($b) => $b['statut'] === 'actif'));

if ($nbBiens > 0 && $nbBaux > 0) {
    $statusColor = 'green'; $statusIcon = '👥';
    $statusMsg = "<strong>Propriétaire</strong> de {$nbBiens} bien(s) · <strong>locataire</strong> de {$nbBaux} bail(x).";
} elseif ($nbBiens > 0) {
    $statusColor = 'green'; $statusIcon = '🏠';
    $statusMsg = "<strong>Propriétaire</strong> de <strong>{$nbBiens}</strong> bien(s).";
} elseif ($nbBauxActifs > 0) {
    $statusColor = 'green'; $statusIcon = '🔑';
    $statusMsg = "<strong>Locataire actif</strong> sur <strong>{$nbBauxActifs}</strong> bail(x).";
} elseif (!empty($roles)) {
    $statusColor = 'orange'; $statusIcon = '👤';
    $statusMsg = "Tiers actif · " . count($roles) . " rôle(s) : <strong>" . implode(', ', array_keys($rolesByCode)) . '</strong>';
} else {
    $statusColor = 'gray'; $statusIcon = '⚪';
    $statusMsg = 'Tiers créé · aucun rôle actif.';
}

$pageTitle    = ($estPersonneMorale ? 'Société · ' : 'Personne · ') . $nomAffichage;
$pageSubtitle = 'Vue 360° tiers #' . $tiersId;
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<?php
fiche360_breadcrumb([
    ['icon'=>$estPersonneMorale ? '🏛' : '👤','label'=>$nomAffichage,'url'=>null],
], 'Tiers');

$badge = !empty($tiers['statut']) && $tiers['statut'] === 'inactif'
    ? ['label'=>'Inactif','class'=>'vacant']
    : null;

$metas = [];
if ($estPersonneMorale) {
    if (!empty($tiers['siren']))         $metas[] = ['icon'=>'🆔','text'=>'SIREN ' . $tiers['siren']];
    if (!empty($tiers['forme_juridique'])) $metas[] = ['icon'=>'📋','text'=>$tiers['forme_juridique']];
} else {
    if (!empty($tiers['date_naissance'])) $metas[] = ['icon'=>'🎂','text'=>'Né le ' . date('d/m/Y', strtotime((string)$tiers['date_naissance']))];
}
if (!empty($tiers['email']))     $metas[] = ['icon'=>'✉️','text'=>$tiers['email']];
if (!empty($tiers['telephone'])) $metas[] = ['icon'=>'📞','text'=>$tiers['telephone']];

$headerActions = [];
if ($idProprioLegacy > 0) {
    $headerActions[] = ['label'=>'📄 Voir la fiche complète','url'=>app_url('/agency_proprietaire_fiche.php?id=' . $idProprioLegacy),'class'=>'tr-btn tr-btn-primary'];
}
$headerActions[] = ['label'=>'✏️ Éditer le tiers','url'=>app_url('/admin/admin_tiers_merge.php?q=' . urlencode('#' . $tiersId)),'class'=>'tr-btn'];

fiche360_header(
    $estPersonneMorale ? '🏛' : '👤',
    $nomAffichage,
    $badge,
    trim((string)($tiers['adresse'] ?? '') . ' ' . ($tiers['code_postal'] ?? '') . ' ' . ($tiers['ville'] ?? '')) ?: 'Adresse non renseignée',
    $metas,
    $headerActions
);

fiche360_ia_bar('tiers', $tiersId, "Demander à l'IA sur ce tiers (biens, baux, échéances, fiscalité…)");
fiche360_status_banner($statusMsg, $statusColor, $statusIcon, '');
?>

<div class="f360-grid">

  <!-- ═══════════════════ COLONNE PRINCIPALE ═══════════════════ -->
  <div>

    <!-- Onglets : Biens / Baux -->
    <div class="f360-card">
        <div class="f360-tabs">
            <button type="button" class="f360-tab active" onclick="f360tabT(this, 'tab-biens')">🏠 Biens possédés <span class="count"><?= $nbBiens ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tabT(this, 'tab-baux')">🔑 Baux locataire <span class="count"><?= $nbBaux ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tabT(this, 'tab-roles')">🎭 Tous les rôles <span class="count"><?= count($roles) ?></span></button>
        </div>

        <div id="tab-biens">
            <?php if (empty($biensProprio)): ?>
                <div class="f360-empty"><div class="em-ico">🏠</div>Ce tiers ne possède aucun bien rattaché.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead><tr style="text-align:left; color:#7a766f; border-bottom:1px solid #f0ece6;">
                        <th style="padding:6px 4px;">Réf.</th>
                        <th style="padding:6px 4px;">Ville</th>
                        <th style="padding:6px 4px;">Type</th>
                        <th style="padding:6px 4px; text-align:right;">Surf.</th>
                        <th style="padding:6px 4px;">Statut</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($biensProprio as $b):
                        $statut = $b['nb_baux_actifs'] > 0 ? '🟢 Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? '🟠 Vacant' : '—');
                    ?>
                        <tr style="border-bottom:1px solid #f5f3ef;">
                            <td style="padding:6px 4px;"><a href="<?= h(app_url('/bien_360.php?id=' . $b['id'])) ?>" style="color:#4878a6; text-decoration:none; font-family:'DM Mono',monospace;"><?= h($b['reference_bien'] ?: '#' . $b['id']) ?></a></td>
                            <td style="padding:6px 4px;"><?= h($b['ville'] ?: '—') ?></td>
                            <td style="padding:6px 4px;"><?= h($b['type_commercialisation'] ?: '—') ?></td>
                            <td style="padding:6px 4px; text-align:right;"><?= $b['surface_habitable'] ? number_format((float)$b['surface_habitable'], 0) . ' m²' : '—' ?></td>
                            <td style="padding:6px 4px;"><?= $statut ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-baux" style="display:none;">
            <?php if (empty($bauxLocataire)): ?>
                <div class="f360-empty"><div class="em-ico">🔑</div>Aucun bail où ce tiers est locataire.</div>
            <?php else: foreach ($bauxLocataire as $b):
                $sCol = $b['statut'] === 'actif' ? '#2d6a35' : '#7a766f';
            ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px;">
                    <strong style="color:<?= $sCol ?>;">Bail #<?= (int)$b['id'] ?></strong> · <?= h($b['bail_nature']) ?> · <em><?= h($b['statut']) ?></em>
                    — bien <a href="<?= h(app_url('/bien_360.php?id=' . (int)($b['id'] ?? 0))) ?>" style="color:#4878a6;"><?= h($b['reference_bien']) ?></a>
                    (<?= h($b['ville']) ?>)
                    · <?= h($b['date_prise_effet']) ?> → <?= h($b['date_fin']) ?>
                    · <strong><?= number_format((float)$b['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div id="tab-roles" style="display:none;">
            <?php if (empty($roles)): ?>
                <div class="f360-empty"><div class="em-ico">🎭</div>Aucun rôle actif déclaré pour ce tiers.</div>
            <?php else: foreach ($rolesByCode as $code => $nb): ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:10px;">
                    <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;"><?= h($code) ?></span>
                    <span><?= (int)$nb ?> occurrence(s)</span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Documents -->
    <div class="f360-card">
        <h3>📂 Documents du tiers <span class="count"><?= count($docs) ?></span></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document rattaché à ce tiers.</div>
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
    // Représentants (si personne morale)
    if (!empty($representants)) {
        $repLinks = array_map(fn($r) => [
            'icon' => '👥',
            'name' => trim((string)$r['prenom'] . ' ' . $r['nom']) . ' (' . $r['qualite'] . ')',
            'ref'  => $r['email'] ?: $r['telephone'] ?: '',
            'url'  => app_url('/tiers_360.php?id=' . $r['id']),
        ], $representants);
        fiche360_attach('REPRÉSENTANTS (' . count($representants) . ')', $repLinks);
    }

    // Synthèse rôles
    fiche360_attach('RÔLES ACTIFS (' . count($rolesByCode) . ')', array_map(fn($code, $nb) => [
        'icon' => '🎭',
        'name' => $code,
        'ref'  => $nb . ' occurrence(s)',
        'url'  => '#',
    ], array_keys($rolesByCode), array_values($rolesByCode)) ?: [['icon'=>'⚪','name'=>'Aucun rôle','ref'=>'','url'=>'#']]);

    // Coordonnées synthétiques
    fiche360_attach('COORDONNÉES', array_filter([
        !empty($tiers['email'])     ? ['icon'=>'✉️','name'=>$tiers['email'],     'ref'=>'email','url'=>'mailto:' . $tiers['email']] : null,
        !empty($tiers['telephone']) ? ['icon'=>'📞','name'=>$tiers['telephone'], 'ref'=>'téléphone','url'=>'tel:' . $tiers['telephone']] : null,
        !empty($tiers['iban'])      ? ['icon'=>'🏦','name'=>substr($tiers['iban'], 0, 4) . '…' . substr($tiers['iban'], -4), 'ref'=>'IBAN','url'=>'#'] : null,
    ]) ?: [['icon'=>'⚪','name'=>'Aucune coordonnée','ref'=>'','url'=>'#']]);

    // Panneau Actions
    $actionsList = [];
    if ($idProprioLegacy > 0) {
        $actionsList[] = ['icon'=>'📄','label'=>'Voir la fiche propriétaire','url'=>app_url('/agency_proprietaire_fiche.php?id=' . $idProprioLegacy)];
    }
    $actionsList[] = ['icon'=>'✏️','label'=>'Éditer le tiers',        'url'=>app_url('/admin/admin_tiers_merge.php?q=' . urlencode('#' . $tiersId))];
    $actionsList[] = ['icon'=>'➕','label'=>'Ajouter un représentant','url'=>app_url('/admin/admin_tiers_merge.php?q=' . urlencode('#' . $tiersId))];
    $actionsList[] = ['icon'=>'📥','label'=>'Importer un document',  'url'=>app_url('/transaction_chargement.php')];
    $actionsList[] = ['icon'=>'🔀','label'=>'Fusionner avec un doublon','url'=>app_url('/admin/admin_tiers_merge.php')];
    fiche360_actions_panel('Actions tiers', $actionsList);
    ?>

  </div>
</div>

<?= fiche360_js() ?>
<script>
function f360tabT(btn, targetId) {
    btn.parentElement.querySelectorAll('.f360-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const container = btn.closest('.f360-card');
    ['tab-biens','tab-baux','tab-roles'].forEach(id => {
        const el = container.querySelector('#' + id);
        if (el) el.style.display = (id === targetId) ? 'block' : 'none';
    });
}
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
