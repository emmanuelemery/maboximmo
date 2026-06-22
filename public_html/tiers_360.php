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

// État « parti de la gestion » du propriétaire (pour le bouton toggle).
$proprioPartiGestion = false;
if ($idProprioLegacy > 0) {
    try {
        $stPg = $pdo->prepare("SELECT parti_gestion FROM proprietaires WHERE id = ?");
        $stPg->execute([$idProprioLegacy]);
        $proprioPartiGestion = (bool)$stPg->fetchColumn();
    } catch (Throwable $e) {}
}

$estPersonneMorale = !empty($tiers['raison_sociale']) || !empty($tiers['siren']);

// Nom d'affichage : si personne morale ET nom/prenom dispos → concat "RAISON · M. NOM Prénom"
// pour afficher aussi le représentant légal/contact principal.
$personnePhysiqueLabel = trim(
    (string)($tiers['civilite'] ?? '')
    . ' ' . (string)($tiers['nom'] ?? '')
    . ' ' . (string)($tiers['prenom'] ?? '')
);
$personnePhysiqueLabel = trim(preg_replace('/\s+/', ' ', $personnePhysiqueLabel) ?? '');

if ($estPersonneMorale) {
    // Personne morale : raison_sociale en titre, + nom/prenom du contact si renseigné
    $baseNom = $tiers['raison_sociale'] ?: ('Tiers #' . $tiersId);
    $nomAffichage = $tiers['nom_affichage'] ?: $baseNom;
    if ($personnePhysiqueLabel !== '' && stripos($nomAffichage, $personnePhysiqueLabel) === false) {
        $nomAffichage .= ' · ' . $personnePhysiqueLabel;
    }
} else {
    // Personne physique : civilité + nom + prenom
    $nomAffichage = $tiers['nom_affichage']
        ?: ($personnePhysiqueLabel !== '' ? $personnePhysiqueLabel : ('Tiers #' . $tiersId));
}

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
    // FIX 2026-05-25 : cascade adresse/ville/CP depuis l'immeuble parent si bien vide,
    // + JOIN bien_types pour afficher le vrai libellé du type (Maison/Appartement/etc.)
    $stBP = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation,
        COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1) AS adresse_aff,
        COALESCE(NULLIF(b.code_postal, ''), i.code_postal) AS cp_aff,
        COALESCE(NULLIF(b.ville, ''), i.ville) AS ville_aff,
        b.surface_habitable, b.type_commercialisation, b.statut_occupation, b.statut_bien,
        bt.libelle AS type_libelle,
        bt.code AS type_code,
        (SELECT COUNT(*) FROM annonces an WHERE an.id_bien = b.id AND (an.statut='publiee' OR an.etat_publication='diffusee')) AS nb_annonces_actives,
        (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS nb_baux_actifs
        FROM biens b
        INNER JOIN proprietaires p ON p.id = b.id_proprietaire
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
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

// ─── Baux des biens POSSÉDÉS par ce tiers (côté propriétaire) ──
$bauxProprio = [];
try {
    $stBPx = $pdo->prepare("SELECT bb.id, bb.bail_nature, bb.statut, bb.date_prise_effet, bb.date_fin,
        bb.loyer_mensuel_hc, bb.locataire_nom, b.id AS id_bien, b.reference_bien, b.ville
        FROM bien_baux bb
        INNER JOIN biens b ON b.id = bb.id_bien
        INNER JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE p.id_tiers = ?
        ORDER BY (bb.statut='actif') DESC, bb.date_prise_effet DESC LIMIT 50");
    $stBPx->execute([$tiersId]);
    $bauxProprio = $stBPx->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Mandat(s) de gestion extrait(s) — registre (pour la card fiche pro) ──
$mandats = [];
if ($idProprioLegacy > 0) {
    try { require_once __DIR__ . '/inc/mandat_registre.php'; $mandats = mr_for_proprio($pdo, $idProprioLegacy); }
    catch (Throwable $e) {}
}

// ─── Documents rattachés à chaque bail (bail signé + EDL) — pour l'onglet « Bail actif » ──
$bailDocs = [];
try {
    $bids = array_map(fn($b) => (int)$b['id'], $bauxProprio);
    if ($bids) {
        $in = implode(',', array_fill(0, count($bids), '?'));
        $stBD = $pdo->prepare("SELECT DISTINCT gd.id, gd.name_display, gd.document_type, gdl.entity_id AS bail_id
            FROM ged_documents gd
            JOIN ged_document_links gdl ON gdl.document_id = gd.id
            WHERE gd.status='active' AND gdl.entity_type='BAIL' AND gdl.entity_id IN ($in)
            ORDER BY gd.document_type");
        $stBD->execute($bids);
        foreach ($stBD as $row) { $bailDocs[(int)$row['bail_id']][] = $row; }
    }
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
        FROM ged_documents gd
        WHERE status = 'active'
          AND (
              JSON_EXTRACT(metadata, '$.classement.tiers_id_bdd') = ?
              OR JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'tiers', 'id', ?), '$')
              OR EXISTS (SELECT 1 FROM ged_document_links gdl
                         WHERE gdl.document_id = gd.id AND gdl.entity_type = 'TIERS'
                           AND gdl.entity_id = ? AND gdl.relation_type = 'main')
          )
        ORDER BY created_at DESC LIMIT 30");
    $stD->execute([$tiersId, $tiersId, $tiersId]);
    $docs = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Mentions (CRG, courriers qui citent ce tiers) ──
$mentions = [];
try {
    $stM = $pdo->prepare("SELECT id, name_display, document_type, created_at
        FROM ged_documents gd
        WHERE status = 'active'
          AND source_module <> '05_TRANSACTION'
          AND (
              JSON_CONTAINS(linked_entities, JSON_OBJECT('type', 'tiers', 'id', ?), '$')
              OR EXISTS (SELECT 1 FROM ged_document_links gdl
                         WHERE gdl.document_id = gd.id AND gdl.entity_type = 'TIERS'
                           AND gdl.entity_id = ? AND gdl.relation_type <> 'main')
          )
        ORDER BY created_at DESC LIMIT 10");
    $stM->execute([$tiersId, $tiersId]);
    $mentions = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Statut visuel ──
$nbBiens = count($biensProprio);
$nbBaux  = count($bauxLocataire);
$nbBauxActifs = count(array_filter($bauxLocataire, fn($b) => $b['statut'] === 'actif'));
$nbBauxProprio       = count($bauxProprio);
$nbBauxProprioActifs = count(array_filter($bauxProprio, fn($b) => $b['statut'] === 'actif'));

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
// FIX 2026-05-25 : modal édition rapide au lieu d'envoyer sur l'outil admin de fusion
$headerActions[] = ['label'=>'✏️ Éditer le tiers','url'=>'javascript:tiersEditOpen('.(int)$tiersId.')','class'=>'tr-btn'];
$headerActions[] = ['label'=>'📁 Documents','url'=>app_url('/tiers_documents_list.php?id=' . $tiersId),'class'=>'tr-btn'];

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
            <button type="button" class="f360-tab"        onclick="f360tabT(this, 'tab-bauxpro')">🔑 Bail actif <span class="count"><?= $nbBauxProprioActifs ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tabT(this, 'tab-baux')">🧑‍💼 Baux locataire <span class="count"><?= $nbBaux ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tabT(this, 'tab-roles')">🎭 Tous les rôles <span class="count"><?= count($roles) ?></span></button>
        </div>

        <div id="tab-biens">
            <?php if (empty($biensProprio)): ?>
                <div class="f360-empty"><div class="em-ico">🏠</div>Ce tiers ne possède aucun bien rattaché.</div>
            <?php else: ?>
                <?php
                $biensActifs = array_filter($biensProprio, fn($b) => !in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));
                $biensSortis = array_filter($biensProprio, fn($b) =>  in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));
                $renderBienRow = function(array $b, string $mode): string {
                    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                    $aAnnonceActive = (int)($b['nb_annonces_actives'] ?? 0) > 0;
                    $stBien = (string)($b['statut_bien'] ?? '');
                    $statut = $b['nb_baux_actifs'] > 0 ? '🟢 Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? '🟠 Vacant' : '—');
                    if ($mode === 'sorti') { $statut = $stBien === 'vendu' ? '✅ Vendu' : '📦 Archivé'; }
                    // Indicateur annonce — TOUJOURS affiché si une annonce active existe
                    $annBadge = $aAnnonceActive ? ' <span title="Annonce en ligne" style="color:#7c3aed; font-weight:600;">📣 annonce</span>' : '';
                    $typeAff = $b['type_libelle'] ?: ($b['type_commercialisation'] ?: '—');
                    $adresseComplete = trim((string)($b['adresse_aff'] ?? ''));
                    $cpVille = trim((string)($b['cp_aff'] ?? '') . ' ' . (string)($b['ville_aff'] ?? ''));
                    if ($cpVille !== '' && stripos($adresseComplete, $cpVille) === false) $adresseComplete = trim($adresseComplete . ' ' . $cpVille);
                    $url = $h(app_url('/bien_360.php?id=' . $b['id']));
                    if ($mode === 'actif') {
                        $act = $aAnnonceActive
                            ? '<span title="Annonce active : archivage bloqué" style="font-size:11px;color:#8a4c12;cursor:help;">🔒 Annonce active</span>'
                            : '<button type="button" class="bien-archive-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #e0d9cf;border-radius:6px;background:#fff;color:#8a4c12;cursor:pointer;">📦 Archiver</button>';
                    } else {
                        $act = '<span style="font-size:11px;color:#9a9690;">'.($stBien === 'vendu' ? '✅ Vendu' : '📦 Archivé').'</span>';
                    }
                    return '<tr style="border-bottom:1px solid #f5f3ef;'.($mode==='sorti'?'opacity:.6;':'').'" data-bien-row="'.(int)$b['id'].'">'
                        . '<td style="padding:6px 4px;"><a href="'.$url.'" style="color:#4878a6;text-decoration:none;font-family:\'DM Mono\',monospace;">'.$h($b['reference_bien'] ?: '#'.$b['id']).'</a></td>'
                        . '<td style="padding:6px 4px;">'.$h($adresseComplete !== '' ? $adresseComplete : '—').'</td>'
                        . '<td style="padding:6px 4px;">'.$h($typeAff).'</td>'
                        . '<td style="padding:6px 4px;text-align:right;">'.($b['surface_habitable'] ? number_format((float)$b['surface_habitable'],0).' m²' : '—').'</td>'
                        . '<td style="padding:6px 4px;">'.$statut.$annBadge.'</td>'
                        . '<td style="padding:6px 4px;text-align:right;">'.$act.'</td></tr>';
                };
                $theadBiens = '<thead><tr style="text-align:left;color:#7a766f;border-bottom:1px solid #f0ece6;">'
                    . '<th style="padding:6px 4px;">Réf.</th><th style="padding:6px 4px;">Adresse</th><th style="padding:6px 4px;">Type</th>'
                    . '<th style="padding:6px 4px;text-align:right;">Surf.</th><th style="padding:6px 4px;">Statut</th><th style="padding:6px 4px;text-align:right;">Action</th></tr></thead>';
                ?>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <?= $theadBiens ?>
                    <tbody><?php foreach ($biensActifs as $b) echo $renderBienRow($b, 'actif'); ?></tbody>
                </table>
                </div>
                <?php if (!empty($biensSortis)): ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer; font-size:12px; color:#7a766f; font-weight:600; user-select:none;">📦 Biens archivés / vendus (<?= count($biensSortis) ?>) — déplier</summary>
                    <div style="overflow-x:auto; margin-top:8px;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <?= $theadBiens ?>
                        <tbody><?php foreach ($biensSortis as $b) echo $renderBienRow($b, 'sorti'); ?></tbody>
                    </table>
                    </div>
                </details>
                <?php endif; ?>
            <?php endif; ?>
            <script>
            (function(){
                var CSRF = <?= json_encode(csrf_token('archiver_bien')) ?>;
                document.querySelectorAll('#tab-biens .bien-archive-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if (!confirm('Archiver ce bien ?')) return;
                        btn.disabled = true; btn.textContent = '…';
                        var fd = new FormData(); fd.append('id_bien', btn.dataset.id); fd.append('csrf_token', CSRF);
                        fetch('<?= h(app_url('/api/bien_archiver.php')) ?>', {method:'POST', body:fd, headers:{'X-CSRF-Token':CSRF}})
                            .then(function(r){ return r.json(); })
                            .then(function(j){
                                if (j.success){
                                    var row = document.querySelector('[data-bien-row="'+btn.dataset.id+'"]');
                                    if (row){ row.querySelector('td:last-child').innerHTML = '<span style="font-size:11px;color:#9a9690;">📦 Archivé</span>'; row.style.opacity = '.55'; }
                                } else {
                                    alert(j.message || "Archivage impossible.");
                                    btn.disabled = false; btn.textContent = '📦 Archiver';
                                }
                            })
                            .catch(function(){ alert('Erreur réseau.'); btn.disabled = false; btn.textContent = '📦 Archiver'; });
                    });
                });
            })();
            </script>
        </div>

        <div id="tab-bauxpro" style="display:none;">
            <?php if (empty($bauxProprio)): ?>
                <div class="f360-empty"><div class="em-ico">🔑</div>Aucun bail sur les biens de ce propriétaire.</div>
            <?php else: foreach ($bauxProprio as $b):
                $sCol = $b['statut'] === 'actif' ? '#2d6a35' : '#7a766f';
            ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px;">
                    <a href="<?= h(app_url('/bail_360.php?id=' . (int)$b['id'])) ?>" style="color:#5b21b6;text-decoration:none;border-bottom:1px dotted #b39ddb;font-weight:700;" title="Ouvrir la fiche bail 360°">
                        <?= h($b['locataire_nom'] ?: ('Bail #' . (int)$b['id'])) ?> ↗</a>
                    <span style="color:<?= $sCol ?>;">· <em><?= h($b['statut']) ?></em></span>
                    · bien <a href="<?= h(app_url('/bien_360.php?id=' . (int)$b['id_bien'])) ?>" style="color:#4878a6;"><?= h($b['reference_bien'] ?: ('#' . (int)$b['id_bien'])) ?></a>
                    (<?= h($b['ville']) ?>)
                    <?php if ($b['date_prise_effet']): ?> · <?= h($b['date_prise_effet']) ?><?php endif; ?>
                    <?php if ((float)$b['loyer_mensuel_hc'] > 0): ?> · <strong><?= number_format((float)$b['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong><?php endif; ?>
                    <?php $bDocs = $bailDocs[(int)$b['id']] ?? []; ?>
                    <?php if ($bDocs): ?>
                        <div style="margin:6px 0 2px; padding-left:8px;">
                        <?php foreach ($bDocs as $doc): ?>
                            <div style="padding:3px 0;">
                                <span style="color:#9a9690; font-size:10px;">[<?= h($doc['document_type']) ?>]</span>
                                📄 <?= h($doc['name_display']) ?>
                                <a href="<?= h(app_url('/api/ged_document_view.php?id=' . (int)$doc['id'] . '&mode=inline')) ?>" target="_blank" style="color:#5b21b6; font-weight:700; margin-left:6px;">Ouvrir ›</a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="margin:4px 0 2px; padding-left:8px; color:#b9b4ac; font-size:11px;">Aucun document de bail importé.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
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
            <div onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>)"
                 style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center; cursor:pointer;"
                 onmouseover="this.style.background='#faf8ff'" onmouseout="this.style.background='transparent'">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; min-width:140px;">[<?= h($d['document_type']) ?>]</span>
                <span style="flex:1;">📄 <?= h($d['name_display']) ?></span>
                <span style="color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                <span style="color:#5b21b6; font-size:11px; font-weight:700;">Ouvrir ›</span>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; /* modale standard mvptModalView */ ?>

    <!-- Mandat de gestion (registre + données IA) -->
    <?php if ($idProprioLegacy > 0): ?>
    <div class="f360-card">
      <h3>📜 Mandat de gestion <?php if ($mandats): ?><span class="count"><?= count($mandats) ?></span><?php endif; ?></h3>
      <?php if (!$mandats): ?>
        <div class="f360-empty" style="padding:14px;">
          <div style="color:#7a766f;font-size:12px;margin-bottom:8px;">Mandat non encore analysé.</div>
          <button type="button" onclick="mandatExtraire(<?= (int)$idProprioLegacy ?>, this)"
                  style="background:#7a6830;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer;font-size:12px;">
            🤖 Analyser le mandat (IA)
          </button>
          <span id="mandatMsg" style="font-size:12px;margin-left:8px;color:#7a766f;"></span>
        </div>
      <?php else: foreach ($mandats as $m):
        $sCol = $m['statut']==='actif' ? '#2d6a35' : ($m['statut']==='termine' ? '#a23' : '#777');
        $fd = fn($d)=>$d?date('d/m/Y',strtotime((string)$d)):'—';
      ?>
        <div style="border-bottom:1px solid #f0ece6;padding:8px 0;font-size:12.5px;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <strong>Mandat n° <?= h($m['numero_mandat'] ?: '?') ?></strong>
            <span style="background:<?= $sCol ?>22;color:<?= $sCol ?>;padding:2px 9px;border-radius:99px;font-weight:800;font-size:11px;"><?= h($m['statut']) ?></span>
            <a href="javascript:void(0)" onclick='mandatEditOpen(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)' style="color:#5b21b6;font-size:11px;font-weight:700;">✏️ Éditer</a>
            <?php if ($m['ged_document_id']): ?><a href="<?= h(app_url('/api/ged_document_view.php?id='.(int)$m['ged_document_id'].'&mode=inline')) ?>" target="_blank" style="color:#5b21b6;font-size:11px;">PDF ↗</a><?php endif; ?>
          </div>
          <div style="color:#7a766f;margin-top:4px;display:flex;gap:16px;flex-wrap:wrap;">
            <span>📅 Effet : <strong><?= $fd($m['date_effet']) ?></strong></span>
            <span>🏁 Fin théorique : <strong><?= $fd($m['date_fin_theorique']) ?></strong></span>
            <span><?= $m['duree_ferme'] ? ('durée ferme '.h($m['duree_initiale_ans']).' ans') : ($m['tacite_reconduction'] ? 'tacite reconduction' : '—') ?></span>
          </div>
          <div style="color:#7a766f;margin-top:4px;display:flex;gap:16px;flex-wrap:wrap;">
            <span>💶 Gestion : <strong><?= $m['hono_gestion_taux_ttc'] ? h(rtrim(rtrim((string)$m['hono_gestion_taux_ttc'],'0'),'.')).' % TTC' : '—' ?></strong> <?= $m['hono_gestion_assiette'] ? '('.h($m['hono_gestion_assiette']).')' : '' ?></span>
            <?php if ($m['hono_location']): ?><span>Location : <?= h($m['hono_location']) ?></span><?php endif; ?>
            <?php if ($m['hono_declaration_fiscale_eur']): ?><span>Décl. fiscale : <?= h(rtrim(rtrim((string)$m['hono_declaration_fiscale_eur'],'0'),'.')) ?> €</span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <!-- Mentionné dans -->
    <?php
    $mentionsForLayout = array_map(fn($m) => [
        'icon'  => '📄',
        'title' => $m['name_display'],
        'ref'   => $m['document_type'] . ' · ' . date('d/m/y', strtotime((string)$m['created_at'])),
        'url'   => app_url('/api/ged_document_view.php?id=' . (int)$m['id'] . '&mode=inline'),
    ], $mentions);
    if (function_exists('fiche360_mention_dans')) fiche360_mention_dans($mentionsForLayout);
    ?>

  </div>

  <!-- ═══════════════════ COLONNE LATÉRALE ═══════════════════ -->
  <div>

    <?php
    // Panneau Actions — EN HAUT de la colonne (convention 360°)
    $actionsList = [];
    $tiersIsMgr = (function_exists('current_role_id') && in_array((int)current_role_id(), [1,2,3,7], true)) || (function_exists('is_super_admin') && is_super_admin());
    if ($idProprioLegacy > 0) {
        $actionsList[] = ['icon'=>'📄','label'=>'Voir la fiche propriétaire','url'=>app_url('/agency_proprietaire_fiche.php?id=' . $idProprioLegacy)];
        $actionsList[] = ['icon'=>'📥','label'=>'Importer docs OneDrive (pro + biens + locataires)','url'=>'javascript:odClasserOpen()'];
        $actionsList[] = ['icon'=>'📂','label'=>'Ouvrir le dossier OneDrive','url'=>'javascript:odOpenFolder()'];
        if ($tiersIsMgr) {
            $actionsList[] = $proprioPartiGestion
                ? ['icon'=>'↩️','label'=>'Réintégrer dans la gestion','url'=>'#','onclick'=>'partiGestionToggle(0);return false;']
                : ['icon'=>'🚪','label'=>'Parti de la gestion (propriétaire perdu)','url'=>'#','onclick'=>'partiGestionToggle(1);return false;'];
        }
    }
    // FIX 2026-05-25 : éditer = modal au lieu d'admin merge tool
    $actionsList[] = ['icon'=>'✏️','label'=>'Éditer le tiers',        'url'=>'javascript:tiersEditOpen('.(int)$tiersId.')'];
    $actionsList[] = ['icon'=>'➕','label'=>'Ajouter un représentant','url'=>app_url('/admin/admin_tiers_merge.php?q=' . urlencode('#' . $tiersId))];
    $actionsList[] = ['icon'=>'📁','label'=>'Documents du tiers',    'url'=>app_url('/tiers_documents_list.php?id=' . $tiersId)];
    $actionsList[] = ['icon'=>'🔀','label'=>'Fusionner avec un doublon','url'=>app_url('/admin/admin_tiers_merge.php')];
    fiche360_actions_panel('Actions tiers', $actionsList);

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
    ?>

  </div>
</div>

<?= function_exists('fiche360_js') ? fiche360_js() : '' ?>
<script>
function f360tabT(btn, targetId) {
    btn.parentElement.querySelectorAll('.f360-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const container = btn.closest('.f360-card');
    ['tab-biens','tab-bauxpro','tab-baux','tab-roles'].forEach(id => {
        const el = container.querySelector('#' + id);
        if (el) el.style.display = (id === targetId) ? 'block' : 'none';
    });
}
</script>

<!-- ── Sprint R-EDIT-TIERS 2026-05-25 : modal d'édition rapide du tiers ── -->
<dialog id="tiersEditModal" class="tiers-edit-modal">
    <form method="dialog" class="tiers-edit-form">
        <header class="tiers-edit-head">
            <h3>✏️ Éditer le tiers #<?= (int)$tiersId ?></h3>
            <button type="button" onclick="tiersEditClose()" class="tiers-edit-close">✕</button>
        </header>
        <div class="tiers-edit-body">
            <input type="hidden" id="tiersEditId" value="<?= (int)$tiersId ?>">
            <label>
                <span>🏢 Raison sociale (personne morale)</span>
                <input type="text" id="tiersEditRaisonSociale" value="<?= h((string)($tiers['raison_sociale'] ?? '')) ?>" placeholder="Ex: SARL SABY">
            </label>
            <label>
                <span>👤 Nom de famille (personne physique)</span>
                <input type="text" id="tiersEditNom" value="<?= h((string)($tiers['nom'] ?? '')) ?>" placeholder="Ex: SABY">
            </label>
            <label>
                <span>Prénom</span>
                <input type="text" id="tiersEditPrenom" value="<?= h((string)($tiers['prenom'] ?? '')) ?>" placeholder="Ex: Pierre">
            </label>
            <label>
                <span>🏷️ Nom d'affichage (forcé, optionnel)</span>
                <input type="text" id="tiersEditNomAffichage" value="<?= h((string)($tiers['nom_affichage'] ?? '')) ?>" placeholder="Ex: SARL SABY (préféré aux autres)">
            </label>
            <label>
                <span>📧 Email</span>
                <input type="email" id="tiersEditEmail" value="<?= h((string)($tiers['email'] ?? '')) ?>">
            </label>
            <label>
                <span>📞 Téléphone</span>
                <input type="tel" id="tiersEditTelephone" value="<?= h((string)($tiers['telephone'] ?? '')) ?>">
            </label>
            <div id="tiersEditResult" class="tiers-edit-result"></div>
        </div>
        <footer class="tiers-edit-foot">
            <button type="button" onclick="tiersEditClose()" class="tiers-edit-cancel">Annuler</button>
            <button type="button" onclick="tiersEditSubmit()" class="tiers-edit-save">💾 Enregistrer</button>
        </footer>
    </form>
</dialog>

<style>
dialog.tiers-edit-modal[open] {
    margin: auto; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    width: 95%; max-width: 540px; max-height: 90vh;
    padding: 0; border: 0; border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.3); z-index: 99999;
}
dialog.tiers-edit-modal::backdrop { background: rgba(15,23,42,.6); backdrop-filter: blur(4px); }
.tiers-edit-form { display: flex; flex-direction: column; max-height: 90vh; margin: 0; }
.tiers-edit-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: linear-gradient(135deg, #243B5C, #1a2940); color: #fff; border-radius: 16px 16px 0 0; }
.tiers-edit-head h3 { margin: 0; font-size: 16px; }
.tiers-edit-close { background: rgba(255,255,255,.15); color: #fff; border: 0; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; font-size: 16px; }
.tiers-edit-body { padding: 20px; overflow-y: auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
.tiers-edit-body label { display: block; margin-bottom: 14px; }
.tiers-edit-body label > span { display: block; font-weight: 700; color: #243B5C; margin-bottom: 6px; font-size: 12px; }
.tiers-edit-body input { width: 100%; padding: 10px 12px; border: 1px solid #d4d7de; border-radius: 6px; font-size: 14px; }
.tiers-edit-foot { display: flex; gap: 10px; padding: 14px 20px; border-top: 1px solid #f1eee9; }
.tiers-edit-cancel, .tiers-edit-save { padding: 10px 18px; border: 0; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; }
.tiers-edit-cancel { background: #f1eee9; color: #2c2a28; }
.tiers-edit-save { background: #16a34a; color: #fff; flex: 1; }
.tiers-edit-save:hover { background: #15803d; }
.tiers-edit-result { margin-top: 12px; padding: 10px; border-radius: 6px; font-size: 12px; display: none; }
.tiers-edit-result.ok { background: #f0fdf4; color: #065f46; display: block; }
.tiers-edit-result.ko { background: #fef2f2; color: #991b1b; display: block; }
</style>

<script>
window.tiersEditOpen = function(id) {
    const modal = document.getElementById('tiersEditModal');
    if (!modal) return;
    if (typeof modal.showModal === 'function') modal.showModal();
    else modal.setAttribute('open', '');
};
window.tiersEditClose = function() {
    const modal = document.getElementById('tiersEditModal');
    if (!modal) return;
    if (typeof modal.close === 'function') modal.close();
    else modal.removeAttribute('open');
};
window.tiersEditSubmit = async function() {
    const id = parseInt(document.getElementById('tiersEditId').value || '0', 10);
    if (id <= 0) return;
    const body = {
        id: id,
        raison_sociale: document.getElementById('tiersEditRaisonSociale').value,
        nom: document.getElementById('tiersEditNom').value,
        prenom: document.getElementById('tiersEditPrenom').value,
        nom_affichage: document.getElementById('tiersEditNomAffichage').value,
        email: document.getElementById('tiersEditEmail').value,
        telephone: document.getElementById('tiersEditTelephone').value,
    };
    const out = document.getElementById('tiersEditResult');
    out.className = 'tiers-edit-result';
    out.textContent = '⏳ Enregistrement…';
    out.style.display = 'block';
    try {
        const res = await fetch('<?= h(app_url("/api/tiers_quick_update.php")) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (data.ok) {
            out.className = 'tiers-edit-result ok';
            out.textContent = '✅ ' + data.message + ' — rechargement…';
            setTimeout(() => window.location.reload(), 1000);
        } else {
            out.className = 'tiers-edit-result ko';
            out.textContent = '❌ ' + (data.error || 'Erreur inconnue');
        }
    } catch (e) {
        out.className = 'tiers-edit-result ko';
        out.textContent = '❌ Réseau : ' + e.message;
    }
};
</script>

<?php if ($idProprioLegacy > 0): ?>
<!-- ── Modal classement OneDrive → GED (scope propriétaire) ── -->
<div id="odModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Documents OneDrive — <?= h($nomAffichage) ?></h3>
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
  var PID=<?= (int)$idProprioLegacy ?>, CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function post(action){var fd=new FormData();fd.append('csrf_token',CSRF);fd.append('id_proprietaire',PID);fd.append('action',action);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  window.odOpenFolder=function(){
    var w=window.open('','_blank');  // ouvre tout de suite (évite le blocage popup)
    if(w)w.document.write('Ouverture du dossier OneDrive…');
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
        var cible=it.target==='PROPRIO'?'→ propriétaire':(it.target==='BAIL'?('→ bail #'+it.bail_id+' / bien #'+it.bien_id):(it.target==='BIEN'?('→ bien #'+it.bien_id):'→ pile'));
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;margin-top:2px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">'+esc(cible)+'</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason)+'</td></tr>';
      }).join('');
      var nbCertain=(j.items||[]).filter(function(x){return x.status==='certain';}).length;
      document.getElementById('odBody').innerHTML=
        '<div style="margin-bottom:8px;color:#6b7280;">Dossier OneDrive : <b>'+esc(j.folder)+'</b> · biens '+j.nb_biens+' · baux '+j.nb_baux+' · <b>'+nbCertain+'</b> doc(s) à classer / '+(j.items||[]).length+'.</div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(rows||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun document mandat/bail/EDL/DPE détecté.</td></tr>')+'</tbody></table>';
      var b=document.getElementById('odCommitBtn'); if(nbCertain>0){b.disabled=false;b.style.opacity=1;}
    }).catch(function(e){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>';});
  };
  window.odClasserCommit=function(){
    var b=document.getElementById('odCommitBtn'),m=document.getElementById('odMsg');
    b.disabled=true;b.style.opacity=.5;m.style.color='#6b7280';m.textContent='⏳ Classement en cours…';
    post('commit').then(function(j){
      if(!j||!j.ok){m.style.color='#c62828';m.textContent='❌ '+esc((j&&j.error)||'Erreur');return;}
      m.style.color='#2d8a4e';m.textContent='✓ '+j.classes+' document(s) classé(s) en GED'+(j.pile?(' · '+j.pile+' en pile'):'')+(j.erreurs&&j.erreurs.length?(' · '+j.erreurs.length+' erreur(s)'):'')+'. Recharge la page pour voir les docs.';
    }).catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau : '+esc(e);});
  };
})();
</script>
<?php endif; ?>

<script>
window.mandatExtraire=function(pid, btn){
  var CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('mandat_extraire'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/mandat_extraire.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var msg=document.getElementById('mandatMsg'); if(btn)btn.disabled=true;
  if(msg){msg.textContent='⏳ Analyse du mandat par l\'IA…';}
  var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('action','proprio'); fd.append('id_proprietaire',pid);
  fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(function(j){
    if(j&&j.ok){ if(msg)msg.textContent='✅ Mandat analysé ('+(j.statut||'')+'). Rechargement…'; setTimeout(function(){location.reload();},800); }
    else { if(msg)msg.textContent='❌ '+((j&&j.error)||'échec'); if(btn)btn.disabled=false; }
  }).catch(function(e){ if(msg)msg.textContent='❌ réseau'; if(btn)btn.disabled=false; });
};
</script>
<?php if ($idProprioLegacy > 0 && $mandats) include __DIR__ . '/inc/mandat_edit_modal.php'; ?>
<?php if ($idProprioLegacy > 0): ?>
<script>
window.partiGestionToggle = async function(etat){
  const msg = etat ? 'Marquer ce propriétaire comme PARTI de la gestion ?\nIl sera masqué de la liste (déplacé dans « Perdus »).' : 'Réintégrer ce propriétaire dans la gestion ?';
  if (!confirm(msg)) return;
  try {
    const fd = new FormData();
    fd.append('id_proprietaire', <?= (int)$idProprioLegacy ?>);
    fd.append('etat', etat);
    fd.append('csrf_token', <?= json_encode(csrf_token('proprio_parti_gestion')) ?>);
    const r = await fetch(<?= json_encode(app_url('/api/proprietaire_parti_gestion.php')) ?>, {method:'POST', body:fd, credentials:'same-origin'});
    const j = await r.json();
    if (j.ok) { alert(etat ? '🚪 Propriétaire marqué parti de la gestion.' : '↩️ Propriétaire réintégré.'); location.reload(); }
    else { alert('❌ ' + (j.error || 'Erreur')); }
  } catch(e) { alert('❌ Réseau : ' + e); }
};
</script>
<?php endif; ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
