<?php
// tiers_360.php — Vue 360° d'un tiers (propriétaire, locataire, agent, fournisseur…)
// Synthèse de tous les rôles + biens + baux + représentants + documents + mentions
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/tiers_selector.php';
require_once __DIR__ . '/inc/acteur_modal.php';
require_once __DIR__ . '/inc/csrf.php';
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
    // Personne morale : RAISON SOCIALE SEULE (le contact est affiché dans la card Coordonnées)
    $nomAffichage = $tiers['raison_sociale'] ?: ($tiers['nom_affichage'] ?: ('Tiers #' . $tiersId));
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
        (SELECT COUNT(*) FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut = 'actif') AS nb_baux_actifs,
        (SELECT bb.id_tiers_locataire FROM bien_baux bb WHERE bb.id_bien = b.id AND bb.statut='actif'
             ORDER BY bb.date_prise_effet DESC LIMIT 1) AS loc_tiers_id,
        (SELECT COALESCE(NULLIF(bb.locataire_nom,''), TRIM(CONCAT_WS(' ', tl.prenom, tl.nom)))
             FROM bien_baux bb LEFT JOIN tiers tl ON tl.id = bb.id_tiers_locataire
             WHERE bb.id_bien = b.id AND bb.statut='actif'
             ORDER BY bb.date_prise_effet DESC LIMIT 1) AS loc_nom
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
    $stRep = $pdo->prepare("SELECT tc.id AS lien_id, tc.qualite, tc.priorite, t.id, t.nom, t.prenom, t.email, t.telephone
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

// ─── Dossiers CRÉANCIERS liés à ce tiers (débiteur / créancier / pro) ──
$dossiersCreanciers = [];
try {
    $stCre = $pdo->prepare("
        SELECT DISTINCT cd.id, cd.code, cd.libelle, cd.statut, cd.niveau_risque,
               cd.numero_dossier_adverse, cdl.role_dossier
        FROM creancier_dossier_lien cdl
        JOIN creancier_dossier cd ON cd.id = cdl.id_dossier
        WHERE cdl.entity_type = 'TIERS' AND cdl.entity_id = ?
        ORDER BY FIELD(cd.niveau_risque,'rouge','orange','vert'), cd.updated_at DESC");
    $stCre->execute([$tiersId]);
    $dossiersCreanciers = $stCre->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $dossiersCreanciers = []; }

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
<style>
/* Topbar conforme aux autres pages (agency_biens / bien_360) : fond dégradé + barre plate pleine largeur */
.agency-content{
  background:linear-gradient(135deg, rgba(132,169,140,0.18) 0%, rgba(255,255,255,0) 35%, rgba(72,120,166,0.14) 60%, rgba(255,255,255,0) 85%, rgba(201,123,46,0.16) 100%), #fafbfc;
  background-attachment:fixed;
  padding-top:0 !important;
}
.agency-topbar{
  background:#ffffff;
  border:none;
  border-bottom:1px solid #e8e4da;
  border-radius:0;
  box-shadow:0 2px 8px rgba(0,0,0,.05);
  padding:10px 28px;
  min-height:56px;
  margin:0 -28px 18px -28px;
}
.agency-topbar .tb-title{ font-size:1.5rem; font-weight:800; color:#1f2937; line-height:1.12; }
.agency-topbar .tb-center{ flex:1; display:flex; justify-content:flex-end; }
</style>

<?php
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

// (Barre du nom « fiche360_header » supprimée : le nom est dans la topbar et les actions
//  sont dans la card « Actions tiers » → la barre faisait doublon.)
?>

<?php if (!empty($dossiersCreanciers)): $nbCre = count($dossiersCreanciers); ?>
<a href="<?= h(app_url('/creancier_liste.php?tiers=' . (int)$tiersId)) ?>"
   style="display:flex;align-items:center;gap:12px;margin:0 0 14px;padding:12px 18px;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:12px;color:#991b1b;font-weight:700;font-size:14px;text-decoration:none;">
  <span style="font-size:18px;">🚨</span>
  <span><?= $nbCre ?> dossier<?= $nbCre > 1 ? 's' : '' ?> CRÉANCIER<?= $nbCre > 1 ? 'S' : '' ?> / SAISIE sur ce tiers — cliquer pour ouvrir la liste</span>
  <svg style="margin-left:auto;" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
</a>
<?php endif; ?>

<style>
.tiers360-grid3 { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
@media (max-width:900px){ .tiers360-grid3 { grid-template-columns:1fr; } }
.tiers360-grid3 > div { min-width:0; }
.tiers360-inner { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); gap:14px; align-items:start; }
@media (max-width:1100px){ .tiers360-inner { grid-template-columns:1fr; } }
.tiers360-inner > div { min-width:0; }
/* Masonry : les cards s'imbriquent (pas de trou sous une card courte) */
.tiers360-masonry { column-count:2; column-gap:14px; }
@media (max-width:1100px){ .tiers360-masonry { column-count:1; } }
.tiers360-masonry > * { break-inside:avoid; -webkit-column-break-inside:avoid; page-break-inside:avoid; }
.tiers360-masonry > div { min-width:0; margin-bottom:14px; display:inline-block; width:100%; }

/* Card Actions — fond bleu pétrole (charte) */
.tiers360-grid3 .f360-actions { background:linear-gradient(155deg,#34586b,#243f4d); }
.tiers360-grid3 .f360-actions a:hover { background:rgba(255,255,255,.10); }

/* ── Boutons d'action du header 360° — style doux (cartes blanches) ── */
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
.f360-header-actions .tr-btn:active { transform:translateY(0); box-shadow:0 2px 6px rgba(36,59,92,.08); }
/* Primary = même carte douce, teinte navy discrète */
.f360-header-actions .tr-btn-primary {
    background:#f3f6fb; color:#2d4a72; border-color:#e3ebf5;
}
.f360-header-actions .tr-btn-primary:hover {
    background:#eaf1fa; color:#243B5C;
}
</style>
<?php require_once __DIR__ . '/inc/financement.php'; /* card financier déplacée en colonne 1, sous le mandat de gestion */ ?>
<div class="tiers360-grid3">

  <!-- ═══════ ZONE GAUCHE (sur 2 colonnes) : Barre IA + Biens + Documents ═══════ -->
  <div style="min-width:0;">

    <?php fiche360_ia_bar('tiers', $tiersId, "Demander à l'IA sur ce tiers (biens, baux, échéances, fiscalité…)"); ?>

    <!-- Zone gauche en masonry : juridique + coordonnées + biens + documents s'imbriquent -->
    <div class="tiers360-masonry">
    <?php ob_start(); // capture juridique + coordonnées → affichées APRÈS les biens (biens = 1ʳᵉ ligne col. 1) ?>
    <?php if ($estPersonneMorale): ?>
    <?php
    // Snapshot juridique déjà persisté (récupéré ici OU lors de la création d'un bien hors gestion)
    $jurInit = null; $jurMaj = '';
    try {
        $stJ = $pdo->prepare("SELECT infos_juridiques_json, infos_juridiques_maj FROM tiers WHERE id = ? LIMIT 1");
        $stJ->execute([$tiersId]);
        if ($rowJ = $stJ->fetch(PDO::FETCH_ASSOC)) {
            $tmp = json_decode((string)($rowJ['infos_juridiques_json'] ?? ''), true);
            if (is_array($tmp) && !empty($tmp)) { $jurInit = $tmp; $jurMaj = (string)($rowJ['infos_juridiques_maj'] ?? ''); }
        }
    } catch (Throwable) {}
    ?>
    <!-- ═══════ CARD Infos juridiques (Pappers) ═══════ -->
    <div class="f360-card" id="jur-card" style="margin-bottom:14px">
      <h3 style="display:flex;align-items:center;gap:8px;">⚖️ Infos juridiques
        <span style="font-weight:500;color:#9a9690;font-size:11px;">(Pappers / annuaire des entreprises)</span>
        <button type="button" id="jur-run"
          style="margin-left:auto;cursor:pointer;font-family:inherit;font-weight:700;font-size:12.5px;
                 padding:7px 16px;border-radius:10px;border:1px solid #243B5C;
                 background:linear-gradient(135deg,#243B5C,#1a2c45);color:#fff;"><?= $jurInit ? '🔄 Actualiser' : '⬇️ Récupérer' ?></button>
      </h3>
      <!-- Recherche manuelle (corriger quand Pappers se trompe) -->
      <div id="jur-search" style="display:<?= $jurInit ? 'none' : 'flex' ?>;gap:6px;margin-bottom:8px;">
        <input id="jur-q" type="text" placeholder="Nom de société ou SIREN…"
               style="flex:1;min-width:0;padding:8px 10px;border:1px solid #d7cfc2;border-radius:8px;font-size:12.5px;font-family:inherit;">
        <button type="button" id="jur-go"
          style="cursor:pointer;font-family:inherit;font-weight:700;font-size:12px;padding:8px 14px;border-radius:8px;border:1px solid #243B5C;background:#243B5C;color:#fff;">🔎 Chercher</button>
      </div>
      <div id="jur-body" style="font-size:12.5px;color:#5a5650;">
        <?php if (!$jurInit): ?><div style="color:#9a9690;font-style:italic;padding:6px 0;">Cliquez « Récupérer » ou cherchez la société ci-dessus.</div><?php endif; ?>
      </div>
      <div id="jur-foot" style="margin-top:8px;display:<?= $jurInit ? 'block' : 'none' ?>;">
        <a href="javascript:void(0)" id="jur-other" style="font-size:11.5px;color:#4878a6;text-decoration:none;">🔎 Pas la bonne ? Choisir une autre entreprise</a>
      </div>
    </div>
    <script>
    (function(){
      var btn=document.getElementById('jur-run'), body=document.getElementById('jur-body');
      var box=document.getElementById('jur-search'), qEl=document.getElementById('jur-q');
      var goBtn=document.getElementById('jur-go'), foot=document.getElementById('jur-foot'), other=document.getElementById('jur-other');
      var SIREN=<?= json_encode(preg_replace('/\D+/','',(string)($tiers['siren'] ?? ''))) ?>;
      // Raison sociale SEULE pour Pappers (sans le « · contact » du nom d'affichage)
      var NAME=<?= json_encode(trim((string)($tiers['raison_sociale'] ?? '') ?: preg_replace('/\s*·.*$/u','',(string)$nomAffichage))) ?>;
      var EP=<?= json_encode(app_url('/api/pappers_search.php')) ?>;
      var SAVE=<?= json_encode(app_url('/api/tiers_infos_juridiques_save.php')) ?>;
      var TID=<?= (int)$tiersId ?>;
      var CSRF=<?= json_encode(function_exists('csrf_token') ? csrf_token('tiers_infos_juridiques') : '') ?>;
      var INIT=<?= json_encode($jurInit, JSON_UNESCAPED_UNICODE) ?>;
      var INIT_MAJ=<?= json_encode($jurMaj) ?>;
      function persist(d){ try{ fetch(SAVE,{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({tiers_id:TID,csrf:CSRF,data:d})}); }catch(e){} }
      function busy(on){ if(on){btn.dataset.b='1';btn.textContent='⏳ …';} else {delete btn.dataset.b;btn.textContent='🔄 Actualiser';} }
      function row(l,v){ if(v===null||v===undefined||v==='') return '';
        return '<div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #f0ece6;">'
             +'<span style="min-width:160px;color:#9a9690;">'+l+'</span>'
             +'<b style="color:#2c2a28;">'+String(v).replace(/</g,'&lt;')+'</b></div>'; }
      function money(n){ return (n==null||n==='')?'':Number(n).toLocaleString('fr-FR')+' €'; }
      function esc(s){ return String(s||'').replace(/</g,'&lt;'); }
      function render(d,source,maj){
        if(!d){ body.innerHTML='<div style="color:#b14a30;">Donnée indisponible.</div>'; return; }
        var s=d.siege||{}; var dir=(d.dirigeants||[]).map(function(x){return x.nom+(x.qualite?(' — '+x.qualite):'');}).join('<br>');
        var html=row('Dénomination',d.raison_sociale)+row('SIREN',d.siren)+row('Forme juridique',d.forme_juridique)
          +row('Date de création',d.date_creation)+row('Capital',money(d.capital))+row('Code NAF',d.naf)
          +row('Effectif',d.effectif)+row("Chiffre d'affaires",money(d.chiffre_affaires))+row('Résultat',money(d.resultat))
          +row('SIRET (siège)',s.siret)+row('Siège',[s.adresse,s.code_postal,s.ville].filter(Boolean).join(' '))
          +(dir?'<div style="display:flex;gap:10px;padding:6px 0;"><span style="min-width:160px;color:#9a9690;">Dirigeant(s)</span><b style="color:#2c2a28;">'+dir+'</b></div>':'');
        var note = maj ? ('Enregistré le '+maj.substring(8,10)+'/'+maj.substring(5,7)+'/'+maj.substring(0,4)) : ('Source : '+(source||'pappers'));
        body.innerHTML=html+'<div style="margin-top:8px;font-size:10.5px;color:#9a9690;">'+note+'</div>';
        box.style.display='none'; foot.style.display='block';
      }
      // Liste de candidats cliquables (corriger quand Pappers se trompe)
      function showCandidates(list, q){
        if(!list || !list.length){ body.innerHTML='<div style="color:#b14a30;padding:6px 0;">Aucune société pour « '+esc(q)+' ». Modifiez la recherche.</div>'; box.style.display='flex'; return; }
        var html='<div style="color:#9a9690;font-size:11px;margin:4px 0 8px;">Plusieurs résultats — choisissez la bonne :</div>';
        list.forEach(function(c){
          if(!c.siren) return;
          html+='<button type="button" data-siren="'+esc(c.siren)+'" style="display:block;width:100%;text-align:left;cursor:pointer;'
            +'margin-bottom:6px;padding:8px 10px;border:1px solid #e0d9cf;border-radius:8px;background:#fff;font-family:inherit;font-size:12px;">'
            +'<b style="color:#243B5C;">'+esc(c.raison_sociale)+'</b><span style="color:#9a9690;"> · '+esc(c.forme_juridique||'')+' · '
            +esc([c.code_postal,c.ville].filter(Boolean).join(' '))+' · SIREN '+esc(c.siren)+'</span></button>';
        });
        body.innerHTML=html; box.style.display='flex'; foot.style.display='none';
        body.querySelectorAll('[data-siren]').forEach(function(b){ b.addEventListener('click', function(){ pickSiren(b.getAttribute('data-siren')); }); });
      }
      function pickSiren(siren){
        busy(true); body.innerHTML='<div style="color:#9a9690;padding:6px 0;">⏳ Chargement…</div>';
        fetch(EP+'?siren='+encodeURIComponent(siren)).then(function(r){return r.json();}).then(function(res){
          busy(false);
          if(res&&res.ok&&res.data){ render(res.data,res.source); persist(res.data); }
          else { body.innerHTML='<div style="color:#b14a30;">⚠️ '+((res&&res.error)||'Détail indisponible')+'</div>'; }
        }).catch(function(){ busy(false); body.innerHTML='<div style="color:#b14a30;">⚠️ Erreur réseau.</div>'; });
      }
      function search(q){
        q=(q||'').trim(); if(q.length<2) return;
        busy(true); body.innerHTML='<div style="color:#9a9690;padding:6px 0;">⏳ Recherche…</div>';
        var digits=q.replace(/\D+/g,'');
        var url=EP+'?'+((digits.length===9||digits.length===14)?('siren='+encodeURIComponent(digits)):('q='+encodeURIComponent(q)));
        fetch(url).then(function(r){return r.json();}).then(function(res){
          busy(false);
          if(!res||!res.ok){ body.innerHTML='<div style="color:#b14a30;">⚠️ '+((res&&res.error)||'Erreur')+'</div>'; box.style.display='flex'; return; }
          var d=res.data||{};
          if(d.candidats){ showCandidates(d.candidats, q); }
          else { render(d,res.source); persist(d); }   // détail direct (SIREN)
        }).catch(function(){ busy(false); body.innerHTML='<div style="color:#b14a30;">⚠️ Erreur réseau.</div>'; });
      }
      // Bouton principal : SIREN connu → détail direct ; sinon → liste de candidats à choisir
      btn.addEventListener('click', function(){
        if(btn.dataset.b) return;
        if(SIREN){ pickSiren(SIREN); } else { box.style.display='flex'; search(NAME); }
      });
      goBtn.addEventListener('click', function(){ search(qEl.value); });
      qEl.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); search(qEl.value); } });
      other.addEventListener('click', function(){ box.style.display='flex'; qEl.value=NAME; qEl.focus(); });
      // Pré-affichage : snapshot déjà persisté (création bien hors gestion ou récupération précédente)
      if (INIT) { render(INIT, 'enregistré', INIT_MAJ); }
    })();
    </script>
    <?php endif; ?>

    <?php
    // ═══════ CARD Coordonnées du contact ═══════
    $coAdr1 = trim((string)($tiers['adresse_ligne1'] ?? ''));
    $coAdr2 = trim((string)($tiers['adresse_ligne2'] ?? ''));
    $coAdr  = trim($coAdr1 . ($coAdr2 !== '' ? ' ' . $coAdr2 : ''));
    $coCpV  = trim(trim((string)($tiers['code_postal'] ?? '') . ' ' . (string)($tiers['ville'] ?? '')));
    $coMail = trim((string)($tiers['email'] ?? ''));
    $coTel  = trim((string)($tiers['telephone'] ?? ''));
    $coRows = [];
    // Nom du contact (personne) — pour une société, c'est le gérant/représentant qu'on a en base
    $coContact = trim((string)($tiers['prenom'] ?? '') . ' ' . (string)($tiers['nom'] ?? ''));
    if ($coContact === '' && $estPersonneMorale) {
        // repli : segment après « · » du nom d'affichage (ancien format fusionné)
        if (preg_match('/·\s*(.+)$/u', (string)$nomAffichage, $m)) $coContact = trim($m[1]);
    }
    if ($coContact !== '') $coRows[] = ['👤','Contact', h($coContact)];
    if ($coAdr !== '' || $coCpV !== '') $coRows[] = ['📍','Adresse', trim($coAdr . ($coCpV !== '' ? ', ' . $coCpV : ''))];
    if ($coMail !== '') $coRows[] = ['✉️','Email', '<a href="mailto:'.h($coMail).'" style="color:#4878a6;text-decoration:none">'.h($coMail).'</a>'];
    if ($coTel !== '')  $coRows[] = ['📞','Téléphone', '<a href="tel:'.h(preg_replace('/\s+/','',$coTel)).'" style="color:#4878a6;text-decoration:none">'.h($coTel).'</a>'];
    if (!empty($tiers['siren'])) $coRows[] = ['🆔','SIREN', h((string)$tiers['siren'])];
    ?>
    <?php
      // Champ d'édition inline réutilisable (label + input pré-rempli).
      $coInput = fn($k,$lbl,$val,$ph='') =>
          '<label style="display:block;font-size:10px;font-weight:700;color:#9a9690;margin:7px 0 2px;">'.$lbl.'</label>'
        .'<input id="coord-'.$k.'" value="'.h($val).'" placeholder="'.h($ph).'" '
        .'style="width:100%;padding:7px 9px;border:1px solid #d9cdbb;border-radius:8px;font-size:12.5px;box-sizing:border-box;">';
    ?>
    <div class="f360-card" style="margin-bottom:14px">
      <h3 style="display:flex;align-items:center;gap:8px;">📇 Coordonnées du contact
        <button type="button" id="coord-edit-btn" onclick="coordEdit(true)"
                style="margin-left:auto;border:1px solid #d9cdbb;background:#fff;color:#8a6d3b;border-radius:8px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">✏️ Modifier</button>
      </h3>
      <!-- VUE lecture -->
      <div id="coord-view">
        <?php if ($coRows): foreach ($coRows as $cr): ?>
          <div style="display:flex;gap:8px;padding:7px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;align-items:baseline;">
            <span style="flex:none;color:#9a9690;white-space:nowrap;"><?= $cr[0] ?> <?= h($cr[1]) ?></span>
            <span style="color:#2c2a28;font-weight:600;margin-left:auto;text-align:right;word-break:break-word;"><?= $cr[2] ?></span>
          </div>
        <?php endforeach; else: ?>
          <div style="color:#9a9690;font-style:italic;padding:6px 0;font-size:12.5px;">Aucune coordonnée renseignée — clique « Modifier » pour les ajouter.</div>
        <?php endif; ?>
      </div>
      <!-- ÉDITION inline (email / tel / adresse) — pas de navigation -->
      <div id="coord-edit" hidden>
        <?= $coInput('email','✉️ Email',$coMail,'nom@exemple.fr') ?>
        <?= $coInput('telephone','📞 Téléphone',$coTel,'06 12 34 56 78') ?>
        <?= $coInput('adresse_ligne1','📍 Adresse',$coAdr1,'N° et voie') ?>
        <?= $coInput('adresse_ligne2','Complément',$coAdr2,'Bât., étage… (optionnel)') ?>
        <div style="display:flex;gap:8px;">
          <div style="width:110px;"><?= $coInput('code_postal','CP',(string)($tiers['code_postal'] ?? '')) ?></div>
          <div style="flex:1;"><?= $coInput('ville','Ville',(string)($tiers['ville'] ?? '')) ?></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;">
          <button type="button" onclick="coordSave()" style="flex:1;background:#3a7a6a;color:#fff;border:none;border-radius:8px;padding:9px;font-weight:700;cursor:pointer;">💾 Enregistrer</button>
          <button type="button" onclick="coordEdit(false)" style="border:1px solid #d9cdbb;background:#fff;border-radius:8px;padding:9px 14px;cursor:pointer;">Annuler</button>
        </div>
        <div id="coord-msg" style="font-size:11px;margin-top:7px;"></div>
      </div>
    </div>
    <?php $jurCoordHtml = ob_get_clean(); // fin capture juridique + coordonnées ?>

      <!-- ─────────────── BIENS (1ʳᵉ ligne, colonne 1) ─────────────── -->
      <div style="min-width:0;">

    <?php
    // ── Préparation rendu biens ──────────────────────────────────────────
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // Bail actif indexé par bien (enrichit chaque card : loyer + docs)
    $bailActifByBien = [];
    foreach ($bauxProprio as $bx) {
        $idB = (int)$bx['id_bien'];
        if (($bx['statut'] ?? '') === 'actif' && empty($bailActifByBien[$idB])) $bailActifByBien[$idB] = $bx;
    }

    $biensActifs = array_filter($biensProprio, fn($b) => !in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));
    $biensSortis = array_filter($biensProprio, fn($b) =>  in_array((string)($b['statut_bien'] ?? ''), ['archive','vendu'], true));

    // Helpers communs
    $bienAdresse = function(array $b) {
        $a = trim((string)($b['adresse_aff'] ?? ''));
        $cpVille = trim((string)($b['cp_aff'] ?? '') . ' ' . (string)($b['ville_aff'] ?? ''));
        if ($cpVille !== '' && stripos($a, $cpVille) === false) $a = trim($a . ' ' . $cpVille);
        return $a !== '' ? $a : '—';
    };
    $bienLocataireHtml = function(array $b) use ($h) {
        $locNom = trim((string)($b['loc_nom'] ?? ''));
        $locTiersId = (int)($b['loc_tiers_id'] ?? 0);
        if ($locNom === '') return '<span style="color:#b9b4ac;">—</span>';
        return $locTiersId > 0
            ? '<a href="'.$h(app_url('/tiers_360.php?id=' . $locTiersId)).'" style="color:#2d5f6b;text-decoration:none;font-weight:700;border-bottom:1px dotted #8fb3bb;" title="Ouvrir la fiche locataire 360°">'.$h($locNom).' ↗</a>'
            : '<span style="color:#2d5f6b;font-weight:700;">'.$h($locNom).'</span>';
    };
    $bienActionHtml = function(array $b, string $mode) {
        $aAnnonceActive = (int)($b['nb_annonces_actives'] ?? 0) > 0;
        $stBien = (string)($b['statut_bien'] ?? '');
        if ($mode === 'actif') {
            return $aAnnonceActive
                ? '<span title="Annonce active : archivage bloqué" style="font-size:11px;color:#8a4c12;cursor:help;">🔒 Annonce active</span>'
                : '<button type="button" class="bien-archive-btn" data-id="'.(int)$b['id'].'" style="font-size:11px;padding:3px 9px;border:1px solid #e0d9cf;border-radius:6px;background:#fff;color:#8a4c12;cursor:pointer;">📦 Archiver</button>';
        }
        return '<span style="font-size:11px;color:#9a9690;">'.($stBien === 'vendu' ? '✅ Vendu' : '📦 Archivé').'</span>';
    };

    // Rendu CARD (1 card / bien — utilisé si ≤ 5 biens actifs)
    $renderBienCard = function(array $b, string $mode) use ($h, $bienAdresse, $bienLocataireHtml, $bienActionHtml, $bailActifByBien, $bailDocs) {
        $aAnnonceActive = (int)($b['nb_annonces_actives'] ?? 0) > 0;
        $stBien = (string)($b['statut_bien'] ?? '');
        $statut = $b['nb_baux_actifs'] > 0 ? '🟢 Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? '🟠 Vacant' : '—');
        if ($mode === 'sorti') $statut = $stBien === 'vendu' ? '✅ Vendu' : '📦 Archivé';
        $annBadge = $aAnnonceActive ? ' <span title="Annonce en ligne" style="color:#7c3aed;font-weight:700;">📣 annonce</span>' : '';
        $typeAff = $b['type_libelle'] ?: ($b['type_commercialisation'] ?: '—');
        $surf = $b['surface_habitable'] ? number_format((float)$b['surface_habitable'],0).' m²' : '';
        $url = $h(app_url('/bien_360.php?id=' . $b['id']));

        // Loyer + docs du bail actif de ce bien
        $bail = $bailActifByBien[(int)$b['id']] ?? null;
        $loyerHtml = ($bail && (float)$bail['loyer_mensuel_hc'] > 0)
            ? ' · <strong>'.number_format((float)$bail['loyer_mensuel_hc'],0,',',' ').' €/mois</strong>' : '';
        $docsHtml = '';
        if ($bail) {
            $bDocs = $bailDocs[(int)$bail['id']] ?? [];
            foreach ($bDocs as $doc) {
                $docsHtml .= '<div style="padding:2px 0;font-size:11px;word-break:break-word;"><span style="color:#9a9690;">['.$h($doc['document_type']).']</span> 📄 '.$h($doc['name_display'])
                    .' <a href="'.$h(app_url('/api/ged_document_view.php?id=' . (int)$doc['id'] . '&mode=inline')).'" target="_blank" style="color:#5b21b6;font-weight:700;">Ouvrir ›</a></div>';
            }
        }

        $out  = '<div class="f360-bien-card" data-bien-row="'.(int)$b['id'].'" style="border:1px solid #ece7df;border-radius:9px;padding:11px 13px;margin-bottom:10px;background:#fcfbf9;'.($mode==='sorti'?'opacity:.6;':'').'">';
        $out .= '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
              . '<a href="'.$url.'" style="color:#4878a6;text-decoration:none;font-family:\'DM Mono\',monospace;font-weight:700;font-size:13px;">'.$h($b['reference_bien'] ?: '#'.$b['id']).' ↗</a>'
              . '<span style="font-size:12px;">'.$statut.$annBadge.'</span>'
              . '<span class="bien-action-slot" style="margin-left:auto;">'.$bienActionHtml($b, $mode).'</span></div>';
        $out .= '<div style="color:#5a564f;font-size:12px;margin-top:5px;">📍 '.$h($bienAdresse($b)).'</div>';
        $out .= '<div style="color:#7a766f;font-size:11.5px;margin-top:3px;">'.$h($typeAff).($surf ? ' · '.$h($surf) : '').'</div>';
        $out .= '<div style="font-size:12px;margin-top:6px;border-top:1px dashed #efeae1;padding-top:6px;">👤 Locataire : '.$bienLocataireHtml($b).$loyerHtml.'</div>';
        if ($docsHtml !== '') $out .= '<div style="margin-top:4px;padding-left:6px;">'.$docsHtml.'</div>';
        $out .= '</div>';
        return $out;
    };

    // Rendu LIGNE (liste compacte — utilisé si > 5 biens actifs)
    $renderBienRow = function(array $b, string $mode) use ($h, $bienAdresse, $bienLocataireHtml, $bienActionHtml) {
        $aAnnonceActive = (int)($b['nb_annonces_actives'] ?? 0) > 0;
        $stBien = (string)($b['statut_bien'] ?? '');
        $statut = $b['nb_baux_actifs'] > 0 ? '🟢 Loué' : (($b['statut_occupation'] ?? '') === 'vacant' ? '🟠 Vacant' : '—');
        if ($mode === 'sorti') $statut = $stBien === 'vendu' ? '✅ Vendu' : '📦 Archivé';
        $annBadge = $aAnnonceActive ? ' <span title="Annonce en ligne" style="color:#7c3aed;font-weight:600;">📣</span>' : '';
        $typeAff = $b['type_libelle'] ?: ($b['type_commercialisation'] ?: '—');
        $url = $h(app_url('/bien_360.php?id=' . $b['id']));
        return '<tr style="border-bottom:1px solid #f5f3ef;'.($mode==='sorti'?'opacity:.6;':'').'" data-bien-row="'.(int)$b['id'].'">'
            . '<td style="padding:6px 4px;"><a href="'.$url.'" style="color:#4878a6;text-decoration:none;font-family:\'DM Mono\',monospace;">'.$h($b['reference_bien'] ?: '#'.$b['id']).'</a></td>'
            . '<td style="padding:6px 4px;">'.$h($bienAdresse($b)).'</td>'
            . '<td style="padding:6px 4px;">'.$h($typeAff).'</td>'
            . '<td style="padding:6px 4px;text-align:right;">'.($b['surface_habitable'] ? number_format((float)$b['surface_habitable'],0).' m²' : '—').'</td>'
            . '<td style="padding:6px 4px;">'.$bienLocataireHtml($b).'</td>'
            . '<td style="padding:6px 4px;">'.$statut.$annBadge.'</td>'
            . '<td style="padding:6px 4px;text-align:right;"><span class="bien-action-slot">'.$bienActionHtml($b, $mode).'</span></td></tr>';
    };
    $theadBiens = '<thead><tr style="text-align:left;color:#7a766f;border-bottom:1px solid #f0ece6;">'
        . '<th style="padding:6px 4px;">Réf.</th><th style="padding:6px 4px;">Adresse</th><th style="padding:6px 4px;">Type</th>'
        . '<th style="padding:6px 4px;text-align:right;">Surf.</th><th style="padding:6px 4px;">Locataire</th><th style="padding:6px 4px;">Statut</th><th style="padding:6px 4px;text-align:right;">Action</th></tr></thead>';
    ?>

    <!-- Card : Biens possédés (1 card/bien si ≤5, sinon liste) -->
    <div class="f360-card" id="tab-biens">
        <h3>🏠 Biens possédés <span class="count"><?= $nbBiens ?></span></h3>
        <?php if (empty($biensProprio)): ?>
            <div class="f360-empty"><div class="em-ico">🏠</div>Ce tiers ne possède aucun bien rattaché.</div>
        <?php elseif (empty($biensActifs)): ?>
            <div class="f360-empty" style="padding:14px;">Aucun bien actif (voir archivés ci-dessous).</div>
        <?php elseif (count($biensActifs) > 5): ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <?= $theadBiens ?>
                <tbody><?php foreach ($biensActifs as $b) echo $renderBienRow($b, 'actif'); ?></tbody>
            </table>
            </div>
        <?php else: ?>
            <?php foreach ($biensActifs as $b) echo $renderBienCard($b, 'actif'); ?>
        <?php endif; ?>

        <?php if (!empty($biensSortis)): ?>
        <details style="margin-top:10px;">
            <summary style="cursor:pointer; font-size:12px; color:#7a766f; font-weight:600; user-select:none;">📦 Biens archivés / vendus (<?= count($biensSortis) ?>) — déplier</summary>
            <div style="margin-top:8px;">
            <?php if (count($biensSortis) > 5): ?>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <?= $theadBiens ?>
                    <tbody><?php foreach ($biensSortis as $b) echo $renderBienRow($b, 'sorti'); ?></tbody>
                </table>
                </div>
            <?php else: ?>
                <?php foreach ($biensSortis as $b) echo $renderBienCard($b, 'sorti'); ?>
            <?php endif; ?>
            </div>
        </details>
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
                                var el = document.querySelector('[data-bien-row="'+btn.dataset.id+'"]');
                                if (el){ var slot = el.querySelector('.bien-action-slot'); if (slot) slot.innerHTML = '<span style="font-size:11px;color:#9a9690;">📦 Archivé</span>'; el.style.opacity = '.55'; }
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

    <!-- Card : Baux où ce tiers est LOCATAIRE (rare pour un propriétaire) -->
    <?php if (!empty($bauxLocataire)): ?>
    <div class="f360-card">
        <h3>🧑‍💼 Baux locataire <span class="count"><?= $nbBaux ?></span></h3>
        <?php foreach ($bauxLocataire as $b): $sCol = $b['statut'] === 'actif' ? '#2d6a35' : '#7a766f'; ?>
            <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px;">
                <strong style="color:<?= $sCol ?>;">Bail #<?= (int)$b['id'] ?></strong> · <?= h($b['bail_nature']) ?> · <em><?= h($b['statut']) ?></em>
                — bien <a href="<?= h(app_url('/bien_360.php?id=' . (int)($b['id'] ?? 0))) ?>" style="color:#4878a6;"><?= h($b['reference_bien']) ?></a>
                (<?= h($b['ville']) ?>)
                · <?= h($b['date_prise_effet']) ?> → <?= h($b['date_fin']) ?>
                · <strong><?= number_format((float)$b['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; /* modale standard mvptModalView */ ?>
    <?php require_once __DIR__ . '/inc/ged_delete_modal.php'; /* gedDeleteDoc(id,nom,el) */ ?>

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

    <!-- Dossiers financiers (déplacé ici : colonne 1, sous le mandat de gestion) -->
    <?php echo fin_related_block($pdo, 'TIERS', $tiersId); ?>

  </div>

  <?php echo $jurCoordHtml; // juridique + coordonnées, placées après les biens ?>

  <!-- ═══════════════════ COLONNE 2 — DOCUMENTS ═══════════════════ -->
  <div style="min-width:0;">

    <!-- Documents du pro -->
    <div class="f360-card">
        <h3>📂 Documents du tiers <span class="count"><?= count($docs) ?></span>
            <button type="button" onclick="gedToggleArchives(this,'TIERS',<?= (int)$tiersId ?>)" style="float:right;border:1px solid #e0d6c4;background:#fbf7ef;color:#a26a1c;border-radius:7px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">📦 Voir les archives</button></h3>
        <?php if (empty($docs)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document rattaché à ce tiers.</div>
        <?php else: foreach ($docs as $d): ?>
            <div onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>)"
                 style="padding:6px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:8px; align-items:center; cursor:pointer;"
                 onmouseover="this.style.background='#faf8ff'" onmouseout="this.style.background='transparent'">
                <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700; font-size:10px; flex:none;">[<?= h($d['document_type']) ?>]</span>
                <span style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($d['name_display']) ?>">📄 <?= h($d['name_display']) ?></span>
                <span style="color:#9a9690; font-size:10px; flex:none;"><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                <button type="button" onclick="event.stopPropagation();gedDeleteDoc(<?= (int)$d['id'] ?>,<?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>,this)" title="Supprimer ce document" style="border:none;background:transparent;color:#c0392b;cursor:pointer;font-size:13px;flex:none;padding:0 2px;">🗑️</button>
                <span style="color:#5b21b6; font-size:11px; font-weight:700;">›</span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Dossiers sources (archives OneDrive liées, non importées) — inclusion défensive -->
    <?php
    $gsfCardFile = __DIR__ . '/inc/ged_source_folders_card.php';
    if (is_file($gsfCardFile)) { require_once $gsfCardFile;
        if (function_exists('ged_source_folders_card')) { try {
            ged_source_folders_card($pdo, 'TIERS', $tiersId, ['id_societe'=>(int)($tiers['id_societe'] ?? 0), 'id_agence'=>(int)($tiers['id_agence'] ?? 0)]);
        } catch (Throwable $e) {} } }
    ?>

    <!-- Mentionné dans (rendu manuel pour permettre la suppression d'un doc) -->
    <?php if (!empty($mentions)): ?>
    <div class="f360-card">
        <h3>🔗 Mentionné dans <span class="count"><?= count($mentions) ?></span></h3>
        <?php foreach ($mentions as $m): ?>
            <div style="padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12px;display:flex;gap:8px;align-items:center;">
                <span style="font-family:'DM Mono',monospace;color:#0e7490;font-weight:700;font-size:10px;flex:none;">[<?= h($m['document_type']) ?>]</span>
                <a href="<?= h(app_url('/api/ged_document_view.php?id=' . (int)$m['id'] . '&mode=inline')) ?>" target="_blank" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;text-decoration:none;" title="<?= h($m['name_display']) ?>">📄 <?= h($m['name_display']) ?></a>
                <span style="color:#9a9690;font-size:10px;flex:none;"><?= h(date('d/m/y', strtotime((string)$m['created_at']))) ?></span>
                <button type="button" onclick="gedDeleteDoc(<?= (int)$m['id'] ?>,<?= htmlspecialchars(json_encode((string)$m['name_display']), ENT_QUOTES) ?>,this)" title="Supprimer ce document" style="border:none;background:transparent;color:#c0392b;cursor:pointer;font-size:13px;flex:none;padding:0 2px;">🗑️</button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

      </div>
      <!-- fin COLONNE 2 -->

    </div><!-- fin .tiers360-masonry -->
  </div><!-- fin ZONE GAUCHE -->

  <!-- ═══════════════════ COLONNE DROITE — ACTIONS + CONTACTS ═══════════════════ -->
  <div style="min-width:0;">

    <?php
    // Panneau Actions
    $actionsList = [];
    $tiersIsMgr = (function_exists('current_role_id') && in_array((int)current_role_id(), [1,2,3,7], true)) || (function_exists('is_super_admin') && is_super_admin());
    // Tiers (propriétaire/locataire) → métier GESTION par défaut (jamais transaction).
    $actionsList[] = ['icon'=>'📤','label'=>'Charger des documents','url'=>'#','onclick'=>"window.fbxOpenUploadModal({origin:'tiers_360', proprio_tiers_id:" . (int)$tiersId . ", proprio_nom:'" . addslashes((string)$nomAffichage) . "', entite_id_bdd:" . (int)$tiersId . ", soc_id:" . (int)($tiers['id_societe'] ?? 0) . ", age_id:" . (int)($tiers['id_agence'] ?? 0) . ", n1:'03_GESTION_LOCATIVE', entite_nom:'" . addslashes((string)$nomAffichage) . "'});return false;"];
    $actionsList[] = ['icon'=>'📧','label'=>'Envoyer un document par mail','url'=>mail_compose_url('TIERS', (int)$tiersId, 'tiers_360.php?id=' . (int)$tiersId)];
    $actionsList[] = ['icon'=>'📨','label'=>'Demander un document','url'=>app_url('/document_request_new.php?ctx=TIERS&id=' . (int)$tiersId . '&back=' . urlencode('tiers_360.php?id=' . (int)$tiersId))];
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
    $actionsList[] = ['icon'=>'📁','label'=>'Documents du tiers',    'url'=>app_url('/tiers_documents_list.php?id=' . $tiersId)];
    $actionsList[] = ['icon'=>'🔀','label'=>'Fusionner avec un doublon','url'=>app_url('/admin/admin_tiers_merge.php')];
    fiche360_actions_panel('Actions tiers', $actionsList);

    // Contacts — composant réutilisable « Ajouter un acteur ».
    // On surface SYSTÉMATIQUEMENT les parties clés : propriétaire + locataire(s),
    // puis les représentants/contacts ajoutés à la main.
    $coLinks = [];

    // 1) Propriétaire (le tiers lui-même quand il possède des biens)
    if (!empty($biensProprio)) {
        $coLinks[] = [
            'icon' => '🏠',
            'name' => $nomAffichage . ' — Propriétaire',
            'ref'  => $tiers['email'] ?: $tiers['telephone'] ?: '',
            'url'  => $idProprioLegacy > 0 ? app_url('/agency_proprietaire_fiche.php?id=' . $idProprioLegacy) : app_url('/tiers_360.php?id=' . $tiersId),
        ];
    }

    // 2) Locataire(s) actifs des biens (dédupliqués)
    $locSeen = [];
    foreach ($biensProprio as $b) {
        $ln = trim((string)($b['loc_nom'] ?? ''));
        if ($ln === '') continue;
        $lid = (int)($b['loc_tiers_id'] ?? 0);
        $key = $lid > 0 ? 't' . $lid : 'n' . mb_strtolower($ln);
        if (isset($locSeen[$key])) continue;
        $locSeen[$key] = true;
        $coLinks[] = [
            'icon' => '🔑',
            'name' => $ln . ' — Locataire',
            'ref'  => 'bien ' . ($b['reference_bien'] ?: ('#' . (int)$b['id'])),
            'url'  => $lid > 0 ? app_url('/tiers_360.php?id=' . $lid) : '#',
        ];
    }

    // 3) Représentants / contacts (tiers_contacts)
    foreach ($representants as $r) {
        $rNom = trim((string)$r['prenom'] . ' ' . $r['nom']);
        $coLinks[] = [
            'icon' => '👥',
            'name' => $rNom . ' (' . $r['qualite'] . ')',
            'ref'  => $r['email'] ?: $r['telephone'] ?: '',
            'url'  => app_url('/tiers_360.php?id=' . $r['id']),
            'action' => '<button type="button" title="Retirer ce contact" onclick="tiersContactRemove('
                        . (int)$r['lien_id'] . ',\'' . addslashes($rNom) . '\')" '
                        . 'style="border:none;background:none;color:#c0392b;cursor:pointer;font-size:15px;padding:2px 6px;">✕</button>',
        ];
    }

    $csrfTiersContact = csrf_token('tiers_contact');
    $addContactBtn = $tiersIsMgr ? acteur_modal_button('tiers_contact', 'Ajouter un contact') : '';
    fiche360_attach('CONTACTS (' . count($coLinks) . ')', $coLinks, $addContactBtn);

    // Synthèse rôles — uniquement si présents
    if (!empty($rolesByCode)) {
        fiche360_attach('RÔLES ACTIFS (' . count($rolesByCode) . ')', array_map(fn($code, $nb) => [
            'icon' => '🎭',
            'name' => $code,
            'ref'  => $nb . ' occurrence(s)',
            'url'  => '#',
        ], array_keys($rolesByCode), array_values($rolesByCode)));
    }

    // (Card COORDONNÉES de droite supprimée : remplacée par la card principale « Coordonnées du contact ».)

    // Modal « Ajouter un contact » (acteur_modal réutilisable)
    if ($tiersIsMgr) {
        if (function_exists('tiers_selector_assets')) tiers_selector_assets();
        acteur_modal_render([
            'id'         => 'tiers_contact',
            'title'      => '➕ Ajouter un contact',
            'role_label' => 'Qualité du contact',
            'roles'      => [
                'gerant'         => 'Gérant',
                'representant'   => 'Représentant légal',
                'associe'        => 'Associé',
                'indivisaire'    => 'Indivisaire',
                'conjoint'       => 'Conjoint / époux(se)',
                'enfant'         => 'Enfant',
                'parent'         => 'Parent',
                'proche'         => 'Proche / famille',
                'comptable'      => 'Comptable',
                'contact'        => 'Contact',
            ],
            'api_add'    => app_url('/api/tiers_contact_add.php'),
            'entity'     => ['id_tiers_entite' => $tiersId],
            'role_field' => 'qualite',
            'tiers_field'=> 'id_tiers_contact',
            'csrf'       => $csrfTiersContact,
        ]);
    }
    ?>

  </div>
</div>

<?= function_exists('fiche360_js') ? fiche360_js() : '' ?>

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
// ── Édition inline des COORDONNÉES (email / tél / adresse) — sans navigation ──
window.coordEdit = function(on){
    const v = document.getElementById('coord-view'), e = document.getElementById('coord-edit'),
          b = document.getElementById('coord-edit-btn');
    if (v) v.hidden = on; if (e) e.hidden = !on; if (b) b.style.display = on ? 'none' : '';
};
window.coordSave = async function(){
    const id = <?= (int)$tiersId ?>;
    const g = k => { const el = document.getElementById('coord-' + k); return el ? el.value.trim() : ''; };
    const msg = document.getElementById('coord-msg'); if (msg) msg.textContent = '⏳ Enregistrement…';
    try {
        const res = await fetch('<?= h(app_url("/api/tiers_quick_update.php")) ?>', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ id: id, email: g('email'), telephone: g('telephone'),
                adresse_ligne1: g('adresse_ligne1'), adresse_ligne2: g('adresse_ligne2'),
                code_postal: g('code_postal'), ville: g('ville') })
        });
        const d = await res.json();
        if (d.ok) { if (msg) msg.textContent = '✅ Enregistré — rechargement…'; setTimeout(() => location.reload(), 600); }
        else if (msg) msg.textContent = '❌ ' + (d.error || 'Erreur');
    } catch (e) { if (msg) msg.textContent = '❌ ' + e.message; }
};
// ── Retirer un contact lié (soft-delete) — le clic sur la ligne ouvre le contact ──
window.tiersContactRemove = async function(lienId, nom){
    if (!lienId) return;
    if (!confirm('Retirer « ' + (nom || 'ce contact') + ' » des contacts ?\n(Le tiers n\'est pas supprimé, seul le lien est retiré.)')) return;
    try {
        const fd = new FormData();
        fd.append('lien_id', lienId);
        fd.append('csrf_token', '<?= h(csrf_token("tiers_contact")) ?>');
        const res = await fetch('<?= h(app_url("/api/tiers_contact_remove.php")) ?>', {
            method: 'POST', body: fd, credentials: 'same-origin'
        });
        const d = await res.json();
        if (d.ok) location.reload();
        else alert('❌ ' + (d.error || 'Erreur'));
    } catch (e) { alert('❌ Réseau : ' + e.message); }
};
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
