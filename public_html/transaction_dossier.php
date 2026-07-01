<?php
/**
 * transaction_dossier.php — DOSSIER DE VENTE UNIQUE (Phase 1, lecture seule).
 *
 * Fiche pivot qui AGRÈGE l'existant sans aucune ressaisie :
 *   bien + immeuble (biens/immeubles) · acteurs (tiers_roles) · documents (ged_document_links,
 *   entity_type='DOSSIER' + ceux du bien) · mandat (mandats) · offres (leads_annonces) ·
 *   prix (bien_prix / biens). L'étape macro vient de dossier_vente.etape.
 *
 * Accès : ?id_bien=ID (crée/retrouve le dossier, idempotent) ou ?id=ID_DOSSIER.
 * UX MaBoxImmo : navigation par cartes/boutons, aucun <select>. Phase 1 = affichage.
 * La timeline est conçue pour accueillir EN PHASE ULTÉRIEURE les actions d'étape
 * (compromis / acte / honoraires) — ici les jalons futurs sont affichés inactifs.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_once __DIR__ . '/inc/ged_file_path.php';
require_once __DIR__ . '/inc/avant_contrat.php';
require_once __DIR__ . '/inc/tiers_selector.php';   // composant recherche/création tiers réutilisable
require_once __DIR__ . '/inc/acteur_modal.php';     // composant partagé « Ajouter un acteur »
require_login();

$pdo = $GLOBALS['pdo'];

// ── Résolution du dossier (par bien = crée/retrouve, ou par id dossier) ──
$idBien    = (int)($_GET['id_bien'] ?? 0);
$idDossier = (int)($_GET['id'] ?? 0);
$confirm   = isset($_GET['confirm']) && $_GET['confirm'] === '1';

if ($idBien > 0) {
    // Garde-fou anti "dossiers pour rien" : si aucun dossier n'existe encore pour
    // ce bien ET que l'ouverture n'est pas confirmée, on AFFICHE une confirmation
    // SANS RIEN CRÉER. La création n'a lieu qu'après clic explicite sur « Oui ».
    if (!$confirm && !dv_get_by_bien($pdo, $idBien)) {
        require __DIR__ . '/inc/dossier_vente_confirm.php'; // rend l'écran + exit
        exit;
    }
    $idDossier = dv_ensure_for_bien($pdo, $idBien, ['source' => 'estimation', 'id_user' => current_user_id()]);
}
$dossier = $idDossier > 0 ? dv_get($pdo, $idDossier) : null;
if (!$dossier) {
    http_response_code(404);
    exit('Dossier de vente introuvable.');
}
$idBien = (int)$dossier['id_bien'];

// ── Bien + immeuble + propriétaire (lu par référence) ──
$stB = $pdo->prepare("
    SELECT b.*,
        COALESCE(NULLIF(b.adresse_1,''), i.adresse_1)     AS bien_adresse,
        COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS bien_cp,
        COALESCE(NULLIF(b.ville,''), i.ville)             AS bien_ville,
        i.id AS immeuble_id, i.nom_immeuble, i.adresse_1 AS imm_adresse,
        p.id AS proprio_id, p.id_tiers AS proprio_tiers_id
    FROM biens b
    LEFT JOIN immeubles i     ON i.id = b.id_immeuble
    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
    WHERE b.id = ? LIMIT 1");
$stB->execute([$idBien]);
$bien = $stB->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit('Bien introuvable.'); }

// ── Checklist « Documents à récupérer » (modèle mise en vente) + statut GED ──
$recupItems = [];
if (is_file(__DIR__ . '/inc/document_requests.php')) {
    require_once __DIR__ . '/inc/document_requests.php';
    $entMap = ['BIEN' => $idBien];
    if (!empty($bien['immeuble_id']))      $entMap['IMMEUBLE'] = (int)$bien['immeuble_id'];
    if (!empty($bien['proprio_tiers_id']))  $entMap['TIERS']    = (int)$bien['proprio_tiers_id'];
    // Mapping doc_type (modèle) → type_document accepté par l'endpoint d'upload.
    $typeDocMap = [
        'pv_assemblee'=>'COPROPRIETE','reglement_copropriete'=>'COPROPRIETE','carnet_entretien'=>'COPROPRIETE',
        'charges_copropriete'=>'COPROPRIETE','pre_etat_date'=>'COPROPRIETE','cni'=>'IDENTITE',
        'acte_propriete'=>'TITRE_PROPRIETE','taxe_fonciere'=>'AUTRE','bail'=>'BAIL','conge_dedite'=>'BAIL',
        'edl'=>'BAIL','dpe'=>'DIAG_DPE','diagnostics_techniques'=>'DIAG_DPE',
    ];
    $tpl = null;
    if (function_exists('dr_default_templates')) {
        foreach (dr_default_templates() as $t) { if (($t['code'] ?? '') === 'mise_en_vente_proprietaire') { $tpl = $t; break; } }
    }
    if ($tpl) {
        foreach ($tpl['items'] as $it) {
            $et  = strtoupper((string)($it['entity_type'] ?? ''));
            $eid = (int)($entMap[$et] ?? 0);
            $inGed = false; $docId = 0;
            if ($eid > 0 && function_exists('gdl_documents_for_entity') && function_exists('dr_doctype_synonyms')) {
                try { $docs = gdl_documents_for_entity($pdo, $et, $eid, ['document_type' => dr_doctype_synonyms((string)$it['doc_type'])]);
                      if ($docs) { $inGed = true; $docId = (int)($docs[0]['id'] ?? 0); } } catch (Throwable) {}
            }
            $recupItems[] = [
                'label' => (string)$it['label'], 'doc_type' => (string)$it['doc_type'],
                'entity_type' => $et, 'type_document' => $typeDocMap[$it['doc_type']] ?? 'AUTRE',
                'required' => (int)($it['required'] ?? 0), 'in_ged' => $inGed, 'doc_id' => $docId,
            ];
        }
    }
}
$csrfDocUpload = function_exists('csrf_token') ? csrf_token('dossier_checklist') : '';

// ── Mandat de vente lié (référence) ──
$mandat = null;
if (!empty($dossier['id_mandat'])) {
    $stM = $pdo->prepare("SELECT * FROM mandats WHERE id = ? LIMIT 1");
    $stM->execute([(int)$dossier['id_mandat']]);
    $mandat = $stM->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Offres (référence) ──
$stO = $pdo->prepare("SELECT id, civilite, nom, prenom, email, telephone, prix_propose,
                             financement_type, statut_offre, date_creation
                      FROM leads_annonces
                      WHERE id_bien = ? AND type_contact = 'offre'
                      ORDER BY COALESCE(prix_propose,0) DESC, date_creation DESC");
$stO->execute([$idBien]);
$offres = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── Lots du mandat de vente (multi-biens) + totaux / rent roll ──
$lots   = dv_lots($pdo, $idDossier);
$totaux = dv_totaux($pdo, $idDossier);
// Avis de valeur (GED, par bien) — pour badge par lot + liste atelier Estimation
$estimsByBien = dv_estimations($pdo, $idDossier);
// Avant-contrat (compromis/promesse) du dossier + lots sélectionnés
$avc      = dac_get($pdo, $idDossier);
$avcLots  = $avc ? dac_lots($pdo, (int)$avc['id']) : array_map(fn($l)=>(int)$l['id_bien'], $lots);
$avcV     = fn($k, $d='') => $avc[$k] ?? $d; // accès court aux champs
// Documents mandat / acte du dossier (GED sur le bien principal)
$docsMandat = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['document_type' => 'MANDAT_VENTE']);
$docsActe   = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['document_type' => 'ACTE_AUTHENTIQUE']);
$docsOffre  = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['document_type' => 'OFFRE_ACHAT']);
// Documents types (modèles transaction) pour la card « Documents types »
$modeles = [];
try {
    $stMod = $pdo->query("SELECT id, name_display, metadata FROM ged_documents
                           WHERE document_type='MODELE_TRANSACTION' AND status='active' ORDER BY id");
    foreach ($stMod->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $meta = json_decode((string)($m['metadata'] ?? ''), true) ?: [];
        $m['_key']   = (string)($meta['modele_key'] ?? '');
        $m['_etape'] = (string)($meta['etape'] ?? '');
        $modeles[] = $m;
    }
} catch (Throwable $e) {}
// Historique des mails envoyés depuis le dossier (mail_history)
$comms = [];
try {
    $stC = $pdo->prepare("SELECT id, subject, recipients_count, sent_at
                            FROM mail_history WHERE recipient_type = ? ORDER BY sent_at DESC LIMIT 20");
    $stC->execute(['dossier_vente:' . $idDossier]);
    $comms = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ── Prix courant (bien_prix), repli biens (référence, jamais recopié) ──
$prixCourant = null;
try {
    $stP = $pdo->prepare("SELECT montant FROM bien_prix
                          WHERE id_bien = ? AND type_valeur = 'prix_vente' AND is_courant = 1
                          ORDER BY date_validation DESC, id DESC LIMIT 1");
    $stP->execute([$idBien]);
    $prixCourant = $stP->fetchColumn();
} catch (Throwable) { /* table/col absente en legacy : on retombe sur biens */ }
$prixCourant = $prixCourant !== false && $prixCourant !== null
    ? (float)$prixCourant
    : ($bien['prix_demande_initial'] ?? $bien['prix_vente_estime'] ?? null);

// ── Acteurs + documents (pivot) ──
$acteurs   = dv_acteurs($pdo, $idDossier);
$docsDoss  = dv_documents($pdo, $idDossier);
$docsBien  = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['limit' => 100]);
// Docs rattachés au(x) bail(s) et à l'immeuble du bien (un bail/diag déposé via FluxBox
// peut être lié à ces entités plutôt qu'au BIEN) → on les remonte aussi dans le dossier.
$docsLies = [];
try {
    $stBx = $pdo->prepare("SELECT id FROM bien_baux WHERE id_bien = ?");
    $stBx->execute([$idBien]);
    foreach ($stBx->fetchAll(PDO::FETCH_COLUMN) as $bailId) {
        foreach (gdl_documents_for_entity($pdo, 'BAIL', (int)$bailId, ['limit' => 50]) as $d) $docsLies[] = $d;
    }
    $immId = (int)($bien['id_immeuble'] ?? $bien['immeuble_id'] ?? 0);
    if ($immId > 0) {
        foreach (['IMB','IMMEUBLE'] as $et) {
            foreach (gdl_documents_for_entity($pdo, $et, $immId, ['limit' => 80]) as $d) $docsLies[] = $d;
        }
    }
} catch (Throwable $e) { error_log('[transaction_dossier docsLies] ' . $e->getMessage()); }

// Bail du bien → cible du bouton « Charger un document » (réutilise le module fiable de bail_360).
$bailUploadId = 0;
try {
    $stBu = $pdo->prepare("SELECT id FROM bien_baux WHERE id_bien = ? ORDER BY (date_fin IS NULL) DESC, id DESC LIMIT 1");
    $stBu->execute([$idBien]);
    $bailUploadId = (int)($stBu->fetchColumn() ?: 0);
} catch (Throwable $e) {}

// « Charger un document » depuis un dossier de vente = MÊME module FluxBox, métier TRANSACTION.
// (Doctrine 2026-06-30 : transaction UNIQUEMENT quand le chargement provient de transaction_dossier.)
$dossRefJs = addslashes((string)($bien['reference_bien'] ?: ('Bien #' . $idBien)));
$dossAdrJs = addslashes(trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? '')));
$fbxOnClickDossier = "window.fbxOpenUploadModal({"
    . "bien_id:" . $idBien . ", soc_id:" . (int)($bien['id_societe'] ?? 0) . ", age_id:" . (int)($bien['id_agence'] ?? 0)
    . ", proprio_id:" . (int)($bien['proprio_id'] ?? 0) . ", proprio_tiers_id:" . (int)($bien['proprio_tiers_id'] ?? 0)
    . ", immeuble_id:" . (int)($bien['immeuble_id'] ?? 0)
    . ", n1:'05_TRANSACTION', n2:'BIENS', n3:'BIEN'"
    . ", entite_nom:'" . $dossRefJs . "', entite_id_bdd:" . $idBien . ", entite_adresse:'" . $dossAdrJs . "'"
    . ", origin:'transaction_dossier'});return false;";

// ── Signatures du mandat (si mandat lié) ──
require_once __DIR__ . '/inc/mandat_signature.php';
$signatures = $mandat ? msig_list_for_mandat($pdo, (int)$mandat['id']) : [];
// Mandat signé = toutes les signatures recueillies (ou dossier confirmé / mandat daté).
$mandatSigne = (($dossier['statut'] ?? '') === 'confirme')
    || ($mandat && !empty($mandat['date_signature']))
    || (count($signatures) > 0 && count(array_filter($signatures, fn($s) => $s['statut'] === 'signe')) === count($signatures));

// ── État visuel du mandat (couleur du bloc prix/honoraires de la card Mandat) ──
//   • vert   : mandat déposé en GED (cycle bouclé, « mis en GED et analysé »)
//   • orange : envoyé au vendeur pour signature mais pas encore retourné/déposé
//   • neutre : brouillon / pas encore envoyé
$mandatEnGed  = !empty($docsMandat);
$mandatEnvoye = count($signatures) > 0 || !empty($mandat['date_signature']);
$mandatEtat   = $mandatEnGed ? 'green' : ($mandatEnvoye ? 'orange' : 'neutral');

// ── Helpers d'affichage ──
$fmtPrix = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$acteurNom = static function(array $a): string {
    $n = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')));
    return $n !== '' ? $n : 'Tiers #' . $a['id_tiers'];
};
$roleLabels = [
    'prospect_vendeur' => 'Vendeur (pressenti)', 'vendeur' => 'Vendeur',
    'acquereur' => 'Acquéreur', 'prospect_acquereur' => 'Acquéreur (pressenti)',
    'notaire' => 'Notaire vendeur', 'notaire_acquereur' => 'Notaire acquéreur',
    'avocat' => 'Avocat', 'partenaire_apporteur' => 'Apporteur / partenaire',
    'collaborateur' => 'Collaborateur',
];
// Icône courte par rôle (compact mais informatif, dans la card Contacts).
$roleIcons = [
    'prospect_vendeur' => '🔑', 'vendeur' => '🔑',
    'acquereur' => '🛒', 'prospect_acquereur' => '🛒',
    'notaire' => '⚖️', 'notaire_acquereur' => '⚖️',
    'avocat' => '👔', 'partenaire_apporteur' => '🤝', 'collaborateur' => '👥',
];

// ── Nom du propriétaire / vendeur (le dossier porte sur un propriétaire, pas
//    sur un seul bien) — sert de titre. Priorité : acteur vendeur du dossier. ──
$proprioNom = '';
foreach ($acteurs as $a) {
    if (in_array($a['role_code'], ['vendeur', 'prospect_vendeur'], true)) { $proprioNom = $acteurNom($a); break; }
}
if ($proprioNom === '' && !empty($bien['proprio_tiers_id'])) {
    $stPN = $pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''), NULLIF(raison_sociale,''),
                                  NULLIF(TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))),''))
                             FROM tiers WHERE id = ? LIMIT 1");
    $stPN->execute([(int)$bien['proprio_tiers_id']]);
    $proprioNom = (string)($stPN->fetchColumn() ?: '');
}

// ── User en charge principale : acteur 'collaborateur', sinon créateur du dossier ──
$userEnCharge = '';
foreach ($acteurs as $a) {
    if ($a['role_code'] === 'collaborateur') {
        $userEnCharge = $a['nom_affichage'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')); break;
    }
}
if ($userEnCharge === '' && !empty($dossier['id_user'])) {
    $stU = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) FROM users WHERE id = ? LIMIT 1");
    $stU->execute([(int)$dossier['id_user']]);
    $userEnCharge = trim((string)($stU->fetchColumn() ?: ''));
}
// Référence propre du dossier (fallback réf bien si pas encore générée).
$refBien    = $bien['reference_bien'] ?: ('#' . $idBien);
$refDossier = (string)($dossier['reference'] ?? '') ?: $refBien;

// ── Timeline : 7 jalons macro ──
$etapes = [
    'estimation'        => ['Estimation', '📊', $dossier['date_estimation']],
    'mandat'            => ['Mandat',      '📝', $dossier['date_mandat']],
    'commercialisation' => ['Commercialisation', '📣', null],
    'offre'             => ['Offre',       '💰', null],
    'compromis'         => ['Compromis',   '🤝', $dossier['date_compromis']],
    'acte'              => ['Acte',        '🏛️', $dossier['date_acte']],
    'solde'             => ['Solde',       '✅', null],
];
$rankCourant   = dv_etape_rank((string)$dossier['etape']);
$etapeTerminal = in_array($dossier['etape'], ['sans_suite', 'perdu'], true);

$pageTitle = 'Dossier de vente · ' . $refDossier . ($userEnCharge !== '' ? ' · 👤 ' . $userEnCharge : '');
$pageIcon  = '🗂️';
$extraCss  = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
/* Fond de page dégradé diagonal (même que FluxBox) : fait ressortir les cards blanches */
.agency-content{
  min-height:100vh;
  background:
    linear-gradient(135deg,
      rgba(154, 170, 132, 0.18) 0%,    /* vert amande (haut-gauche) */
      rgba(255, 255, 255, 0)   35%,
      rgba(72, 120, 166, 0.14) 60%,    /* bleu pétrole (centre) */
      rgba(255, 255, 255, 0)   85%,
      rgba(201, 123, 46, 0.16) 100%    /* orange (bas-droite) */
    ),
    #fafbfc !important;
  background-attachment:fixed !important;
}
.dv-wrap{max-width:1180px;margin:0 auto;padding:8px 16px 48px;}
.dv-card{box-shadow:0 1px 3px rgba(15,23,42,.06),0 6px 18px rgba(15,23,42,.05);}
/* Timeline compacte : ligne de progression + pastilles connectées (pas des boutons) */
.dv-timeline{display:flex;align-items:flex-start;margin:8px 0 18px;}
.dv-step{flex:1 1 0;min-width:0;text-align:center;position:relative;}
.dv-step::before{content:"";position:absolute;top:13px;left:-50%;width:100%;height:3px;background:#e2e8f0;z-index:0;}
.dv-step:first-child::before{display:none;}
.dv-step.done::before,.dv-step.current::before{background:#9ad3ab;}
.dv-step .dot{position:relative;z-index:1;width:28px;height:28px;line-height:24px;border-radius:50%;margin:0 auto;
  background:#fff;border:2px solid #e2e8f0;color:#94a3b8;font-size:13px;}
.dv-step.done .dot{background:#d7f0e0;border-color:#9ad3ab;color:#15803d;}
.dv-step.current .dot{border-color:#0f6cbd;box-shadow:0 0 0 3px #0f6cbd22;background:#eef5fc;color:#0f6cbd;}
.dv-step.future{opacity:.5;}
.dv-step .lb{font-size:10px;font-weight:700;margin-top:5px;color:#475569;line-height:1.15;}
.dv-step .dt{font-size:9px;color:#94a3b8;}
.dv-terminal{display:inline-block;background:#fde2e1;color:#a11;border:1px solid #f3b4b1;border-radius:8px;padding:4px 12px;font-weight:800;margin-bottom:14px;}
.dv-statut{display:inline-block;border-radius:8px;padding:5px 14px;font-weight:800;font-size:12.5px;}
.dv-statut.temp{background:#fef3c7;color:#92600a;border:1px solid #fcd980;}
.dv-statut.conf{background:#d7f0e0;color:#0b6b35;border:1px solid #9ad3ab;}
.dv-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:880px){.dv-grid{grid-template-columns:1fr;}}
/* ── Cockpit : 2 colonnes (gauche onglets, droite fixe Actions+Contacts) ── */
.dvk-cols{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;margin-top:6px;}
@media(max-width:980px){.dvk-cols{grid-template-columns:1fr;}}
.dvk-aside{display:flex;flex-direction:column;gap:14px;position:sticky;top:12px;}
@media(max-width:980px){.dvk-aside{position:static;}}
.dvk-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;border-bottom:2px solid #eef2f6;padding-bottom:0;}
.dvk-tab{border:none;background:none;padding:9px 14px;font-size:13px;font-weight:800;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;}
.dvk-tab.active{color:#0f6cbd;border-bottom-color:#0f6cbd;}
.dvk-tab:hover{color:#0f172a;}
.dvk-panel{display:none;}
.dvk-panel.active{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;}
.dvk-panel.solo{grid-template-columns:1fr;}
.dvk-colstack{display:flex;flex-direction:column;gap:16px;min-width:0;}
/* Filets de contour colorés par card (palette métier) */
.dv-card{border-width:1.5px;}
#dv-estim-card{border-color:#7c3aed;}            /* estimation — violet */
#dv-lots-card{border-color:#84a98c;}             /* lots — vert bien */
.dvc-mandat{border-color:#243B5C;}               /* mandat — navy */
.dvc-comm{border-color:#d4a047;}                 /* commercialisation — or */
.dvc-acte{border-color:#9d174d;}                 /* acte — bordeaux */
.dvc-communications{border-color:#0891b2;}       /* communications — teal */
.dvc-contacts{border-color:#0e7490;}             /* contacts — pétrole */
/* Blocs lot du mandat (empilés) */
.dv-lot{border:1px solid #e8edf3;border-radius:11px;padding:10px 12px;margin-bottom:10px;background:#fcfdfe;}
.dv-lot-head{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.dv-lot-ref{font-weight:800;color:#0f172a;font-size:13px;text-decoration:none;}
.dv-lot-type{font-size:10px;font-weight:700;color:#0f6cbd;background:#eef5fc;border-radius:6px;padding:1px 7px;}
.dv-lot-rm{margin-left:auto;border:none;background:none;color:#ef4444;cursor:pointer;font-size:14px;}
.dv-lot-adr{font-size:11px;color:#64748b;margin-top:2px;}
.dv-lot-loc{font-size:11px;color:#0e7490;margin-top:2px;font-weight:600;}
.dv-lot-fields{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px;}
.dv-lot-fields3{display:grid;grid-template-columns:1fr 1fr 0.95fr;gap:6px;margin-top:7px;align-items:end;}
.dv-lot-rdt{padding:6px 6px;border:1px solid #bfe3cc;border-radius:7px;text-align:right;font-size:13px;font-weight:800;color:#15803d;background:#f6fcf8;white-space:nowrap;overflow:hidden;}
.dv-lot-f label{display:block;font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:2px;}
/* Montants : gras, gros, couleur par champ pour lisibilité */
.dv-lot-f input{width:100%;padding:6px 7px;border:1px solid #cbd5e1;border-radius:7px;text-align:right;font-size:13px;font-weight:800;letter-spacing:-.2px;}
.dv-lot-f input[data-f="estimation"]{color:#475569;}
.dv-lot-f input[data-f="prix_vente"]{color:#15803d;background:#f6fcf8;border-color:#bfe3cc;}
.dv-lot-f input[data-f="loyer_reel"]{color:#0e7490;}
.dv-lot-f input[data-f="loyer_potentiel"]{color:#7c9885;}
.dv-lot-f input::placeholder{font-weight:800;color:#0e7490;opacity:1;}
@media(max-width:680px){.dvk-panel.active{grid-template-columns:1fr;}}
.dvk-actions{border:1px solid #0c5f78;border-radius:14px;background:#0e7490;padding:14px 16px;}
.dvk-actions h4{margin:0 0 10px;font-size:13px;font-weight:900;color:#fff;}
.dvk-act-btn{display:block;width:100%;text-align:left;margin-bottom:7px;border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:9px 12px;font-size:12.5px;font-weight:700;color:#334155;cursor:pointer;}
.dvk-act-btn:hover{border-color:#0f6cbd;background:#eef5fc;}
.dvk-soon{font-size:12px;color:#94a3b8;font-style:italic;padding:8px 0;}
.dv-fin-link{flex:1;text-align:center;text-decoration:none;border:1px solid #cbd5e1;background:#f8fafc;border-radius:9px;padding:9px 10px;font-size:12px;font-weight:700;color:#0f6cbd;white-space:nowrap;}
.dv-fin-link:hover{border-color:#0f6cbd;background:#eef5fc;}
/* Consultation mobile efficace */
@media(max-width:640px){
  .dv-wrap{padding:6px 10px 40px;}
  .dv-wrap h1{font-size:18px !important;}
  .dv-step .lb{font-size:9px;}
  .dv-step .dot{width:24px;height:24px;line-height:20px;font-size:11px;}
  .dv-step::before{top:11px;}
  .dv-card{padding:14px 15px;border-radius:12px;}
  .dvm{padding:18px 16px;border-radius:14px;}
  .dvm-actions{flex-direction:column-reverse;}
  .dvm-actions .dvm-btn{width:100%;}
}
.dv-card{border:1px solid #e2e8f0;border-radius:14px;background:#fff;padding:16px 18px;}
.dv-card h3{margin:0 0 12px;font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;}
.dv-row{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed #eef2f6;font-size:13px;}
.dv-row:last-child{border-bottom:0;}
.dv-row .k{color:#64748b;}
.dv-row .v{font-weight:700;color:#1f2937;text-align:right;}
.dv-actor{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed #eef2f6;}
.dv-actor:last-child{border-bottom:0;}
.dv-actor .role-ic{font-size:17px;flex:none;cursor:default;}
.dv-actor .role{font-size:11px;font-weight:800;color:#0f6cbd;background:#eef5fc;border-radius:6px;padding:2px 7px;white-space:nowrap;}
.dv-actor .nm{font-weight:700;font-size:13px;}
.dv-actor .ct{font-size:11px;color:#64748b;}
.dv-empty{color:#94a3b8;font-size:13px;font-style:italic;padding:6px 0;}
.dv-doc{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed #eef2f6;font-size:12.5px;}
.dv-doc:last-child{border-bottom:0;}
.dv-doc a{color:#0f6cbd;text-decoration:none;font-weight:700;}
.dv-badge{font-size:10px;background:#f1f5f9;border-radius:5px;padding:1px 6px;color:#475569;font-weight:700;}
.dv-prix{font-size:26px;font-weight:900;color:#0b8043;}
.dv-note{font-size:11px;color:#94a3b8;margin-top:8px;}
.dv-estim-btn{margin-top:8px;border:none;background:none;color:#0f6cbd;font-size:12px;font-weight:700;cursor:pointer;text-decoration:underline;}
.dv-estim-btn:hover{color:#0c5aa0;}
.dv-add-btn{margin-left:auto;width:26px;height:26px;border:none;border-radius:8px;background:linear-gradient(135deg,#0f6cbd,#0c5aa0);color:#fff;font-size:18px;font-weight:900;line-height:1;cursor:pointer;}
.dv-add-btn:hover{filter:brightness(1.08);}
.dv-actor-del{border:none;background:#f1f5f9;color:#94a3b8;border-radius:7px;width:22px;height:22px;font-size:14px;font-weight:900;cursor:pointer;flex:none;}
.dv-actor-del:hover{background:#fde2e1;color:#a11;}
/* Modal acteur */
.dvm-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9000;align-items:flex-start;justify-content:center;padding:48px 16px;}
.dvm-backdrop.open{display:flex;}
.dvm{background:#fff;border-radius:16px;max-width:520px;width:100%;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.dvm h3{margin:0 0 4px;font-size:16px;font-weight:900;}
.dvm .sub{font-size:12px;color:#64748b;margin-bottom:16px;}
.dvm-roles{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.dvm-role{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:800;color:#334155;cursor:pointer;}
.dvm-role.active{border-color:#0f6cbd;background:#eef5fc;color:#0c5aa0;box-shadow:0 0 0 2px #0f6cbd22;}
.dvm-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
.dvm-btn{border:none;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;}
.dvm-btn.cancel{background:#eceef1;color:#374151;}
.dvm-btn.ok{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
.dvm-btn:disabled{opacity:.5;cursor:not-allowed;}
.dvm-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:#8a8680;text-transform:uppercase;margin:0 0 6px;}
/* Le modal de création de tiers (composant partagé, z-index 5000) doit s'empiler
   AU-DESSUS du modal acteur (9000) — sinon il s'ouvre derrière et reste inaccessible. */
.ts-modal-overlay{z-index:9500 !important;}
.tiers-selector .ts-dropdown{z-index:9600;}
/* Modal adresse Google par-dessus le modal création tiers (cascade complète). */
.addr-modal{z-index:9700 !important;}
.addr-modal .places-dropdown{z-index:9800 !important;}
</style>

<div class="dv-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <div style="color:#475569;font-size:14px;font-weight:700;">
        <?php if ($proprioNom !== ''): ?>
          👤 <?= h($proprioNom) ?>
          <span style="color:#94a3b8;font-weight:400;font-size:12px;">· <?= h($bien['designation'] ?: $refBien) ?></span>
        <?php else: ?>
          <?= h($bien['designation'] ?: $refBien) ?>
        <?php endif; ?>
        <span style="color:#64748b;font-weight:400;"><?= h(trim(($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? ''))) ?></span>
      </div>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="tr-btn tr-btn-primary" href="<?= h(app_url('/bien_360.php?id=' . $idBien)) ?>">🏠 Vue 360° du bien</a>
      <a class="tr-btn" href="<?= h(app_url('/bien_documents_list.php?id=' . $idBien)) ?>">📁 Documents</a>
      <a class="tr-btn" href="<?= h(app_url('/document_request_new.php?ctx=BIEN&id=' . $idBien . '&tpl=mise_en_vente_proprietaire&back=' . urlencode('transaction_dossier.php?id_bien=' . $idBien))) ?>">📨 Demander les documents au propriétaire</a>
      <?php if (!$etapeTerminal): ?>
        <button type="button" class="tr-btn" style="color:#b91c1c;" onclick="dvCancelOpen()">🗑️ Annuler le dossier</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($etapeTerminal): ?>
    <div style="margin-top:14px;"><span class="dv-terminal">⛔ <?= $dossier['etape'] === 'perdu' ? 'Dossier perdu' : 'Sans suite' ?></span></div>
  <?php endif; ?>


  <!-- ═══ TIMELINE (jalons futurs prêts à accueillir les actions d'étape) ═══ -->
  <div class="dv-timeline">
    <?php foreach ($etapes as $code => [$lib, $ic, $dt]):
        $rank = dv_etape_rank($code);
        $cls  = $etapeTerminal ? 'future'
              : ($rank < $rankCourant ? 'done' : ($rank === $rankCourant ? 'current' : 'future'));
        // Jalons en écriture (compromis/acte/solde) = à venir en phase ultérieure
        $soon = in_array($code, ['compromis','acte','solde'], true) && $rank > $rankCourant;
    ?>
      <div class="dv-step <?= $cls ?>" title="<?= h($lib) . ($soon ? ' (à venir)' : '') ?>">
        <div class="dot"><?= ($cls === 'done') ? '✓' : $ic ?></div>
        <div class="lb"><?= h($lib) ?></div>
        <div class="dt"><?= $dt ? h($fmtDate($dt)) : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Onglets au-dessus des 3 colonnes (alignement des tops) -->
  <div class="dvk-tabs" role="tablist">
    <button type="button" class="dvk-tab active" onclick="dvkTab(this,'dashboard')">📊 Dashboard</button>
    <button type="button" class="dvk-tab" onclick="dvkTab(this,'documents')">📄 Documents</button>
    <button type="button" class="dvk-tab" onclick="dvkTab(this,'actes')">🏛️ Actes</button>
    <button type="button" class="dvk-tab" onclick="dvkTab(this,'estimation')">📈 Estimation</button>
    <button type="button" class="dvk-tab" onclick="dvkTab(this,'communication')">✉️ Communication</button>
  </div>

  <div class="dvk-cols">
    <!-- ═══════════ COLONNE GAUCHE : PANELS ═══════════ -->
    <div class="dvk-main">
      <!-- ===== DASHBOARD ===== -->
      <div class="dvk-panel active" id="dvk-dashboard">
        <!-- ════════ COLONNE 1 : Estimation · Mandat · Commercialisation · Acte ════════ -->
        <div class="dvk-colstack">
        <!-- Card ESTIMATION (atelier avis de valeur) — placée ici si mandat non signé,
             sinon basculée dans l'onglet Estimation (cf. plus bas). Bufferisée pour ne
             pas dupliquer le markup. -->
        <?php
          $bLat = $bien['latitude'] ?? null; $bLng = $bien['longitude'] ?? null;
          $dvfUrl = ($bLat && $bLng)
            ? 'https://explore.data.gouv.fr/fr/immobilier?onglet=carte&lat=' . rawurlencode((string)$bLat) . '&lng=' . rawurlencode((string)$bLng) . '&zoom=18'
            : 'https://app.dvf.etalab.gouv.fr/';
          ob_start();
        ?>
        <div class="dv-card" id="dv-estim-card">
          <h3>📈 Estimation <span style="font-weight:400;color:#94a3b8;font-size:12px;">avis de valeur</span></h3>
          <p style="font-size:12px;color:#64748b;margin:2px 0 10px;">Le prix par lot se saisit dans la card <strong>Lots du mandat</strong>. Ici : déposer / consulter les avis de valeur (classés en GED sur le bon bien).</p>

          <!-- Liste des avis de valeur déposés (par lot) -->
          <div id="dv-estim-list">
          <?php
            $aucunAvis = true;
            foreach ($lots as $l):
              $bId  = (int)$l['id_bien'];
              $docs = $estimsByBien[$bId] ?? [];
              if (!$docs) continue;
              $aucunAvis = false;
              $lotLib = $l['reference_bien'] ?: ($l['designation'] ?: ('Bien #' . $bId));
              foreach ($docs as $d): ?>
                <div class="dv-doc">
                  <a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$d['id'])) ?>" target="_blank">
                    📄 <?= h($d['name_display'] ?: $d['name_file'] ?: ('Avis #' . $d['id'])) ?>
                  </a>
                  <span class="dv-badge"><?= h($lotLib) ?></span>
                </div>
          <?php endforeach; endforeach; ?>
          <?php if ($aucunAvis): ?><div class="dv-empty" id="dv-estim-empty">Aucun avis de valeur déposé.</div><?php endif; ?>
          </div>

          <!-- Dépôt d'un avis de valeur (PDF/Word) -->
          <div style="margin-top:10px;border-top:1px solid #eef2f6;padding-top:10px;">
            <p class="dvm-label">Déposer un avis de valeur (PDF / Word)</p>
            <?php if (count($lots) > 1): ?>
              <select id="dv-estim-lot" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;margin-bottom:6px;">
                <?php foreach ($lots as $l): $bId=(int)$l['id_bien']; ?>
                  <option value="<?= $bId ?>"><?= h($l['reference_bien'] ?: ('Bien #' . $bId)) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="hidden" id="dv-estim-lot" value="<?= (int)($lots[0]['id_bien'] ?? $idBien) ?>">
            <?php endif; ?>
            <input type="file" id="dv-estim-file" accept=".pdf,.doc,.docx" style="width:100%;font-size:12px;">
            <button type="button" class="dvm-btn ok" style="width:100%;margin-top:8px;padding:9px;" onclick="dvEstimUpload()">📎 Déposer l'avis de valeur</button>
            <div id="dv-estim-up-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
          </div>

          <p class="dvm-label" style="margin-top:12px;">Aides à l'estimation</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="dv-fin-link" href="https://www.cadastre.com/" target="_blank" rel="noopener">🗺️ Cadastre</a>
            <a class="dv-fin-link" href="<?= h($dvfUrl) ?>" target="_blank" rel="noopener">📊 DVF · valeurs foncières</a>
          </div>
          <div class="dvk-soon" style="margin-top:10px;">Envoi de l'avis de valeur au vendeur — à venir.</div>
        </div>
        <?php
          $estimCard = ob_get_clean();
          // Tant que le mandat n'est pas signé : la card Estimation vit dans le Dashboard.
          if (!$mandatSigne) echo $estimCard;
        ?>

        <!-- Card MANDAT -->
        <div class="dv-card dvc-mandat">
          <h3>📝 Mandat de vente</h3>
          <?php if ($mandat):
            $mandatBoxCss = $mandatEtat === 'green'
                ? 'background:#d7f0e0;border:1px solid #9ad3ab;color:#0b6b35;'
                : ($mandatEtat === 'orange'
                    ? 'background:#fff3c7;border:1px solid #fcd980;color:#92600a;'
                    : 'background:#f8fafc;border:1px solid #e2e8f0;color:#475569;');
            $mandatEtatLbl = $mandatEtat === 'green'
                ? '🟢 Mandat déposé en GED & analysé'
                : ($mandatEtat === 'orange'
                    ? '🟧 Envoyé au vendeur — en attente de retour'
                    : '📝 Brouillon — non encore envoyé');
          ?>
          <div style="border-radius:10px;padding:10px 12px;margin-bottom:12px;<?= $mandatBoxCss ?>">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;">
              <span>Prix mandat</span><strong><?= h($fmtPrix($totaux['prix_total'])) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;margin-top:3px;">
              <span>Honoraires</span><strong><?= h($fmtPrix($mandat['honoraires'] ?? null)) ?><?= !empty($mandat['honoraires_charge']) ? ' · ' . h($mandat['honoraires_charge']) : '' ?></strong>
            </div>
            <div style="font-size:11px;font-weight:700;margin-top:7px;"><?= $mandatEtatLbl ?></div>
          </div>
          <?php endif; ?>
          <div class="dv-row"><span class="k">Mandat</span><span class="v">
            <?php if ($mandat): ?><?= h($mandat['numero_mandat'] ?: ('#' . $mandat['id'])) ?><?= !empty($mandat['exclusif']) ? ' · exclusif' : '' ?><?php else: ?>—<?php endif; ?>
          </span></div>
          <?php if ($mandat): ?>
            <div class="dv-row"><span class="k">Signature</span><span class="v">
              <?php
                $nbSig = count($signatures);
                $nbSigne = count(array_filter($signatures, fn($s) => $s['statut'] === 'signe'));
                if ($nbSig === 0)            echo '<span class="dv-badge">non envoyé</span>';
                elseif ($nbSigne === $nbSig) echo '<span class="dv-badge" style="background:#d7f0e0;color:#0b6b35;">signé ✓</span>';
                else                         echo '<span class="dv-badge" style="background:#fef3c7;color:#92600a;">' . $nbSigne . '/' . $nbSig . ' signé</span>';
              ?>
            </span></div>
            <?php foreach ($signatures as $s): ?>
              <div class="dv-row" style="font-size:12px;">
                <span class="k"><?= h($s['nom_affichage'] ?: $s['raison_sociale'] ?: trim(($s['prenom'] ?? '') . ' ' . ($s['nom'] ?? '')) ?: 'Vendeur') ?></span>
                <span class="v"><?php if ($s['statut'] === 'signe'): ?>✅ <?= h($fmtDate($s['signed_at'])) ?> (IP <?= h($s['ip'] ?: '—') ?>)<?php else: ?>⏳ en attente<?php endif; ?></span>
              </div>
            <?php endforeach; ?>
            <div style="margin-top:10px;text-align:center;">
              <p class="dvm-label" style="text-align:left;">Modèle de mandat</p>
              <select id="dv-mandat-modele" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;margin-bottom:8px;">
                <option value="mandat_simple">Mandat de vente sans exclusivité</option>
                <option value="mandat_exclusif">Mandat exclusif de vente</option>
                <option value="mandat_succes">Mandat de vente « succès »</option>
              </select>
              <a id="dv-mandat-preview" class="dvm-btn cancel" style="padding:9px 16px;text-decoration:none;display:inline-block;margin-bottom:6px;" target="_blank"
                 href="<?= h(app_url('/transaction_mandat_preview.php?id_dossier=' . $idDossier . '&modele=mandat_simple')) ?>">👁️ Voir le mandat complété</a><br>
              <button type="button" class="dvm-btn ok" style="padding:9px 16px;" onclick="dvSendMandat()">✉️ Envoyer au vendeur pour signature</button>
              <div id="dv-mandat-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
            </div>
            <script>
              (function(){ var s=document.getElementById('dv-mandat-modele'), a=document.getElementById('dv-mandat-preview');
                if(s&&a){ var base=<?= json_encode(app_url('/transaction_mandat_preview.php?id_dossier=' . $idDossier . '&modele=')) ?>;
                  s.addEventListener('change',function(){ a.href=base+s.value; }); } })();
            </script>
          <?php else: ?>
            <div style="margin-top:10px;text-align:center;">
              <button type="button" class="dvm-btn ok" style="padding:9px 16px;" onclick="dvOpenMandatModal()">📝 Créer le mandat de vente</button>
            </div>
          <?php endif; ?>

          <!-- Dépôt du mandat signé (PDF/Word) -->
          <div style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">
            <?php foreach ($docsMandat as $d): ?>
              <div class="dv-doc"><a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$d['id'])) ?>" target="_blank">📄 <?= h($d['name_display'] ?: $d['name_file'] ?: ('Mandat #' . $d['id'])) ?></a></div>
            <?php endforeach; ?>
            <p class="dvm-label">Déposer le mandat (PDF / Word)</p>
            <input type="file" id="dv-mandat-file" accept=".pdf,.doc,.docx" style="width:100%;font-size:12px;">
            <button type="button" class="dvm-btn ok" style="width:100%;margin-top:8px;padding:9px;" onclick="dvMandatUpload()">📎 Déposer le mandat</button>
            <div id="dv-mandat-up-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
          </div>
        </div>

        </div><!-- /colstack col1 -->

        <!-- ════════ COLONNE 2 : Lots du mandat (empilés) ════════ -->
        <div class="dvk-colstack">
        <!-- Card LOTS DU MANDAT (multi-biens : prix + loyers par lot) -->
        <div class="dv-card" id="dv-lots-card">
          <h3>🏢 Lots du mandat <span style="font-weight:400;color:#94a3b8;font-size:12px;">(<?= (int)$totaux['nb_lots'] ?>)</span></h3>
          <div id="dv-lots-body">
            <?php foreach ($lots as $l):
              $lib = $l['reference_bien'] ?: ($l['designation'] ?: ('Bien #' . (int)$l['id_bien']));
              $typeLbl = $l['type_libelle'] ?: $l['sous_type_bien'];
              $adr = trim(((string)($l['lot_adresse'] ?? '')) . ' ' . ((string)($l['lot_cp'] ?? '')) . ' ' . ((string)($l['lot_ville'] ?? '')));
              $loc = trim((string)($l['locataire_nom'] ?? ''));
              $lotEstims = $estimsByBien[(int)$l['id_bien']] ?? [];
            ?>
              <div class="dv-lot" data-lot-id="<?= (int)$l['lot_id'] ?>">
                <div class="dv-lot-head">
                  <a class="dv-lot-ref" title="Ouvrir la fiche du bien"
                     href="<?= h(app_url('/bien_detail.php?edit=' . (int)$l['id_bien'] . '&return_dossier=' . $idDossier)) ?>"><?= h($lib) ?> ✏️</a>
                  <?php if ($typeLbl): ?><span class="dv-lot-type"><?= h($typeLbl) ?></span><?php endif; ?>
                  <?php if ($lotEstims): ?>
                    <a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$lotEstims[0]['id'])) ?>" target="_blank"
                       title="Avis de valeur déposé" style="font-size:10px;color:#15803d;text-decoration:none;">📎 ✓</a>
                  <?php else: ?>
                    <span title="Aucun avis de valeur" style="font-size:10px;color:#cbd5e1;">📎 —</span>
                  <?php endif; ?>
                  <button type="button" class="dv-lot-rm" title="Retirer ce lot" onclick="dvLotRemove(<?= (int)$l['lot_id'] ?>)">✕</button>
                </div>
                <?php if ($adr !== ''): ?><div class="dv-lot-adr"><?= h($adr) ?></div><?php endif; ?>
                <?php if ($loc !== ''): ?><div class="dv-lot-loc">👤 <?= h($loc) ?></div><?php endif; ?>
                <?php
                  // Rentabilité du lot : loyer estimé si réel vide, sinon réel, / prix.
                  $lpLot   = ($l['loyer_potentiel'] ?? null) ?? $l['_loyer_potentiel_bien'] ?? 0;
                  $lrLot   = ($l['loyer_reel']      ?? null) ?? $l['_loyer_reel_bien']      ?? 0;
                  $prixLot = ($l['prix_vente']      ?? null) ?? $l['_prix_vente_bien']      ?? 0;
                  $loyerRet = (float)$lpLot > 0 ? (float)$lpLot : (float)$lrLot;
                  $rdtLot = ((float)$prixLot > 0 && $loyerRet > 0) ? round($loyerRet / (float)$prixLot * 100, 2) : null;
                ?>
                <div class="dv-lot-fields">
                  <div class="dv-lot-f"><label>Estimation €</label>
                    <input type="text" inputmode="numeric" class="dv-lot-in" data-f="estimation"
                           value="<?= $l['estimation'] !== null ? (int)$l['estimation'] : '' ?>"></div>
                  <div class="dv-lot-f"><label>Prix du mandat €</label>
                    <input type="text" inputmode="numeric" class="dv-lot-in" data-f="prix_vente"
                           value="<?= $l['prix_vente'] !== null ? (int)$l['prix_vente'] : '' ?>"
                           placeholder="<?= $l['_prix_vente_bien'] !== null ? (int)$l['_prix_vente_bien'] : '' ?>"></div>
                </div>
                <div class="dv-lot-fields3">
                  <div class="dv-lot-f"><label>Loyer réel €/an</label>
                    <input type="text" inputmode="numeric" class="dv-lot-in" data-f="loyer_reel"
                           value="<?= $l['loyer_reel'] !== null ? (int)$l['loyer_reel'] : '' ?>"
                           placeholder="<?= $l['_loyer_reel_bien'] !== null ? (int)$l['_loyer_reel_bien'] : '' ?>"></div>
                  <div class="dv-lot-f"><label>Loyer estimé €/an</label>
                    <input type="text" inputmode="numeric" class="dv-lot-in" data-f="loyer_potentiel"
                           value="<?= $l['loyer_potentiel'] !== null ? (int)$l['loyer_potentiel'] : '' ?>"
                           placeholder="<?= $l['_loyer_potentiel_bien'] !== null ? (int)$l['_loyer_potentiel_bien'] : '' ?>"></div>
                  <div class="dv-lot-f"><label>Rentabilité</label>
                    <div class="dv-lot-rdt"><?= $rdtLot !== null ? h(number_format((float)$rdtLot,2,',',' ')) . ' %' : '—' ?></div></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div id="dv-lot-msg" style="font-size:11px;color:#94a3b8;margin-top:4px;min-height:14px;"></div>
          <!-- Ajout d'un lot -->
          <button type="button" onclick="dvLotModalOpen()" style="margin-top:8px;width:100%;padding:10px;border:1px dashed #0e7490;border-radius:9px;font-size:13px;font-weight:700;color:#0e7490;background:#f3fbfd;cursor:pointer;">➕ Ajouter un lot</button>
          <!-- Totaux / rent roll -->
          <div style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">
            <div class="dv-row"><span class="k">Prix mandat total (Σ lots)</span><span class="v" id="dv-tot-prix"><?= h($fmtPrix($totaux['prix_total'])) ?></span></div>
          </div>
        </div>

        </div><!-- /colstack col2 -->
      </div>

      <!-- ===== MODAL AJOUTER UN LOT ===== -->
      <div id="dv-lot-modal" style="display:none;position:fixed;inset:0;z-index:9600;align-items:center;justify-content:center;padding:20px;">
        <div style="position:absolute;inset:0;background:rgba(15,23,42,.55);" onclick="dvLotModalClose()"></div>
        <div style="position:relative;background:#fff;border-radius:16px;width:min(880px,100%);max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.3);">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #eef2f7;">
            <div><strong style="font-size:16px;color:#143A41;">➕ Ajouter un lot au dossier</strong>
              <div id="dv-lot-modal-sub" style="font-size:12px;color:#7a8694;margin-top:2px;"></div></div>
            <button type="button" onclick="dvLotModalClose()" style="background:#f1f5f9;border:none;border-radius:8px;padding:6px 11px;font-size:15px;cursor:pointer;">✕</button>
          </div>
          <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <a href="<?= h(app_url('/bien_nouveau.php')) ?>" target="_blank" style="background:linear-gradient(135deg,#D4A047,#c97b2e);color:#fff;text-decoration:none;border-radius:10px;padding:10px 16px;font-weight:700;font-size:13px;">🆕 Nouveau lot (hors gestion)</a>
            <input type="text" id="dv-lot-msearch" placeholder="🔎 Rechercher un bien (réf, ville, immeuble…)" style="flex:1;min-width:220px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:13px;">
          </div>
          <div id="dv-lot-grid" style="padding:14px 20px;overflow:auto;display:grid;grid-template-columns:repeat(2,1fr);gap:10px;">
            <div style="color:#94a3b8;font-size:13px;padding:20px;grid-column:1/-1;text-align:center;">Chargement…</div>
          </div>
        </div>
      </div>

      <!-- ===== DOCUMENTS ===== -->
      <div class="dvk-panel solo" id="dvk-documents">
        <!-- 2 colonnes : COL 1 (Données publiques + À faire) · COL 2 (Documents du dossier + À récupérer replié) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;" class="dv-docs-2col">

        <div><!-- COL 1 : données publiques + doc types -->
        <?php if (!empty($bien['immeuble_id'])):
            require_once __DIR__ . '/inc/immeuble_public_card.php';
            $canEnrich = (function_exists('current_role_id') && in_array((int)current_role_id(), [1,2,7,8], true))
                      || (function_exists('is_super_admin') && is_super_admin());
            $gvLabel = trim((string)(($bien['nom_immeuble'] ?? '') ?: ($bien['imm_adresse'] ?? '')));
            if (($bien['bien_ville'] ?? '') !== '') $gvLabel = trim($gvLabel . ' · ' . $bien['bien_ville']);
            immeuble_public_card($pdo, (int)$bien['immeuble_id'], $canEnrich,
                ['lat' => $bien['latitude'] ?? '', 'lng' => $bien['longitude'] ?? '', 'label' => $gvLabel]);
        endif; ?>

        <!-- Card : Documents À FAIRE (modèles : mandats, compromis…) — repliée par défaut -->
        <details class="dv-card dvc-mandat">
          <summary style="cursor:pointer;list-style:none;outline:none;"><h3 style="display:inline-block;margin:0;">📝 Documents à faire <span style="font-weight:400;color:#94a3b8;font-size:12px;">(mandats, compromis…)</span> <span style="font-size:11px;color:#0e7490;font-weight:700;">— déplier ▾</span></h3></summary>
          <?php if (!$modeles): ?>
            <div class="dv-empty">Aucun modèle disponible.</div>
          <?php else:
            $mandatKeys = ['mandat_simple','mandat_exclusif','mandat_succes'];
            $docHtmlKeys = ['bon_visite','avenant_mandat']; // moteur HTML générique (groupe 1)
            foreach ($modeles as $m):
              $isMandat  = in_array($m['_key'], $mandatKeys, true);
              $isDocHtml = in_array($m['_key'], $docHtmlKeys, true);
              $editable  = $isMandat || $isDocHtml;
              if ($isMandat)        $href = app_url('/transaction_mandat_preview.php?id_dossier=' . $idDossier . '&modele=' . $m['_key']);
              elseif ($isDocHtml)   $href = app_url('/transaction_doc_preview.php?id_dossier=' . $idDossier . '&modele=' . $m['_key']);
              else                  $href = app_url('/api/ged_doc_serve.php?id=' . (int)$m['id']);
          ?>
            <div class="dv-doc">
              <a href="<?= h($href) ?>" target="_blank"><?= $editable ? '✍️' : '📄' ?> <?= h($m['name_display'] ?: ('Modèle #' . $m['id'])) ?></a>
              <span>
                <?php if ($m['_etape']): ?><span class="dv-badge"><?= h($m['_etape']) ?></span><?php endif; ?>
                <?php if ($editable): ?><span class="dv-badge" style="background:#d7f0e0;color:#0b6b35;">éditable</span><?php endif; ?>
              </span>
            </div>
          <?php endforeach; endif; ?>
          <div class="dv-note">Mandats : aperçu pré-rempli depuis le dossier. Autres modèles : visualisation du gabarit (remplissage à venir).</div>
        </details>
        </div><!-- /COL 1 -->

        <div><!-- COL 2 : documents du dossier + à récupérer replié -->
        <!-- Card : Documents du dossier -->
        <div class="dv-card">
          <h3>📄 Documents du dossier</h3>
          <?php
            $seen = [];
            $allDocs = [];
            foreach ($docsDoss as $d) { $seen[$d['id']] = 1; $d['_scope'] = 'dossier'; $allDocs[] = $d; }
            foreach ($docsBien as $d) { if (isset($seen[$d['id']])) continue; $seen[$d['id']] = 1; $d['_scope'] = 'bien'; $allDocs[] = $d; }
            foreach ($docsLies as $d) { if (isset($seen[$d['id']])) continue; $seen[$d['id']] = 1; $d['_scope'] = 'lié'; $allDocs[] = $d; }
          ?>
          <?php if (!$allDocs): ?>
            <div class="dv-empty">Aucun document rattaché.</div>
          <?php else: foreach (array_slice($allDocs, 0, 60) as $d):
            $dispo = ged_file_path($pdo, (int)$d['id']) !== null; ?>
            <div class="dv-doc" style="<?= $dispo ? '' : 'opacity:.55;' ?>">
              <?php if ($dispo): ?>
                <a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$d['id'])) ?>" target="_blank"><?= h($d['name_display'] ?: $d['name_file'] ?: ('Doc #' . $d['id'])) ?></a>
              <?php else: ?>
                <span title="Fichier physique absent sur cet environnement"><?= h($d['name_display'] ?: $d['name_file'] ?: ('Doc #' . $d['id'])) ?> <small style="color:#ef4444;">⚠ indisponible</small></span>
              <?php endif; ?>
              <span>
                <?php if (!empty($d['document_type'])): ?><span class="dv-badge"><?= h($d['document_type']) ?></span><?php endif; ?>
                <span class="dv-badge"><?= $d['_scope'] === 'dossier' ? 'dossier' : 'bien' ?></span>
              </span>
            </div>
          <?php endforeach; endif; ?>
          <div class="dv-note">GED unique — un même document peut être rattaché au bien et au dossier sans duplication physique.</div>
        </div>

        <!-- Card : Documents À RÉCUPÉRER (checklist mise en vente, upload auto-classé) — repliée -->
        <details class="dv-card">
          <summary style="cursor:pointer;list-style:none;outline:none;"><h3 style="display:inline-block;margin:0;">📥 Documents à récupérer <span style="font-weight:400;color:#94a3b8;font-size:12px;">(dossier de vente)</span> <span style="font-size:11px;color:#0e7490;font-weight:700;">— déplier ▾</span></h3></summary>
          <style>
            .dv-recup{border:1px solid #eef2f6;border-radius:10px;padding:9px 11px;margin-bottom:8px;}
            .dv-recup.is-ok{background:#f6fcf8;border-color:#c8ecd6;}
            .dv-recup-h{display:flex;justify-content:space-between;align-items:center;gap:8px;}
            .dv-recup-lbl{font-size:12.5px;font-weight:600;color:#1e293b;}
            .dv-recup-drop{margin-top:7px;display:flex;align-items:center;justify-content:center;gap:6px;text-align:center;
              padding:9px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafbfc;cursor:pointer;font-size:11.5px;color:#64748b;font-weight:600;transition:.15s;}
            .dv-recup-drop:hover,.dv-recup-drop.drag{border-color:#0e7490;background:#eefafd;color:#0e7490;}
          </style>
          <?php if (!$recupItems): ?>
            <div class="dv-empty">Checklist indisponible (propriétaire/immeuble du bien non résolus).</div>
          <?php else: foreach ($recupItems as $ri): $rok = $ri['in_ged']; ?>
            <div class="dv-recup <?= $rok?'is-ok':'' ?>" data-doctype="<?= h($ri['doc_type']) ?>" data-entity="<?= h($ri['entity_type']) ?>" data-label="<?= h($ri['label']) ?>">
              <div class="dv-recup-h">
                <span class="dv-recup-lbl"><?= $rok?'✅':'⬜' ?> <?= h($ri['label']) ?></span>
                <?php if ($ri['required']): ?><span class="dv-badge" style="background:#fef3d8;color:#b7791f;">requis</span><?php endif; ?>
              </div>
              <?php if ($rok): ?>
                <a href="javascript:void(0)" onclick="mvptModalView(<?= (int)$ri['doc_id'] ?>, <?= htmlspecialchars(json_encode((string)$ri['label']), ENT_QUOTES) ?>)" style="font-size:11px;color:#176a3a;font-weight:700;">📄 Visualiser le document →</a>
              <?php else: ?>
                <label class="dv-recup-drop"><span>📎 Glissez le fichier ici ou cliquez</span><input type="file" style="display:none"></label>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
          <div class="dv-note">Glissez un fichier sur une pièce → classé automatiquement en GED (bien · immeuble · propriétaire). ✅ = déjà présent.</div>
        </details>
        </div><!-- /COL 2 -->

        </div><!-- /grid 2col -->
      </div>
      <script>
      (function(){
        var CSRF=<?= json_encode((string)$csrfDocUpload) ?>, DOSS=<?= (int)$idDossier ?>,
            UP=<?= json_encode(app_url('/api/transaction_dossier_checklist_upload.php')) ?>;
        document.querySelectorAll('.dv-recup-drop').forEach(function(zone){
          var input=zone.querySelector('input[type=file]'), card=zone.closest('.dv-recup');
          function upload(file){
            if(!file) return;
            zone.querySelector('span').textContent='⏳ Envoi de '+file.name+'…';
            var fd=new FormData();
            fd.append('csrf_token',CSRF); fd.append('id_dossier',DOSS);
            fd.append('doc_type',card.getAttribute('data-doctype'));
            fd.append('entity_type',card.getAttribute('data-entity'));
            fd.append('label',card.getAttribute('data-label'));
            fd.append('file',file);
            fetch(UP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
              if(j.ok){ zone.querySelector('span').textContent='✅ Classé — actualisation…'; setTimeout(function(){location.reload();},700); }
              else { zone.querySelector('span').textContent='⚠️ '+((j.errors&&j.errors[0])||j.error||'échec'); }
            }).catch(function(){ zone.querySelector('span').textContent='⚠️ erreur réseau'; });
          }
          ['dragenter','dragover'].forEach(function(e){zone.addEventListener(e,function(ev){ev.preventDefault();zone.classList.add('drag');});});
          ['dragleave','dragend','drop'].forEach(function(e){zone.addEventListener(e,function(ev){ev.preventDefault();zone.classList.remove('drag');});});
          zone.addEventListener('drop',function(ev){ var f=ev.dataTransfer&&ev.dataTransfer.files; if(f&&f.length) upload(f[0]); });
          input.addEventListener('change',function(){ if(input.files&&input.files.length) upload(input.files[0]); });
        });
      })();
      </script>
      <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; /* modale standard mvptModalView (pièces déjà chargées) */ ?>
      <?php require_once __DIR__ . '/inc/geo_views_modal.php'; /* définit window.openGeoViews → bouton « 🛰️ 3 vues » de la card infos publiques */ ?>

      <!-- ===== ACTES (progression de la vente) ===== -->
      <div class="dvk-panel" id="dvk-actes">

        <!-- Card OFFRES REÇUES -->
        <div class="dv-card dvc-comm">
          <h3>💰 Offres reçues <span class="dv-badge"><?= count($offres) ?></span></h3>
          <?php if (!$offres): ?>
            <div class="dv-empty">Aucune offre reçue.</div>
          <?php else: foreach ($offres as $o): ?>
            <div class="dv-row">
              <span class="k"><?= h(trim(($o['prenom'] ?? '') . ' ' . ($o['nom'] ?? '')) ?: 'Acquéreur') ?>
                <?php if ($o['statut_offre']): ?><span class="dv-badge"><?= h($o['statut_offre']) ?></span><?php endif; ?>
              </span>
              <span class="v"><?= h($fmtPrix($o['prix_propose'])) ?></span>
            </div>
          <?php endforeach; endif; ?>
          <div style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">
            <?php foreach ($docsOffre as $d): ?>
              <div class="dv-doc"><a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$d['id'])) ?>" target="_blank">📄 <?= h($d['name_display'] ?: $d['name_file'] ?: ('Offre #' . $d['id'])) ?></a></div>
            <?php endforeach; ?>
            <button type="button" class="dvm-btn ok" style="width:100%;padding:9px;" onclick="dvToggleOffre(true)">💰 Saisir une offre</button>
            <div id="dv-offre-form" style="display:none;margin-top:8px;">
              <input type="text" id="dv-offre-prix" inputmode="numeric" placeholder="Prix proposé €" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:7px;margin-bottom:6px;">
              <input type="text" id="dv-offre-nom" placeholder="Acquéreur (nom)" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:7px;margin-bottom:6px;">
              <input type="text" id="dv-offre-email" placeholder="Email (optionnel)" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:7px;margin-bottom:6px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Document de l'offre (PDF / Word, optionnel)</label>
              <input type="file" id="dv-offre-file" accept=".pdf,.doc,.docx" style="width:100%;font-size:12px;margin:4px 0 6px;">
              <div style="display:flex;gap:6px;">
                <button type="button" class="dvm-btn ok" style="flex:1;padding:8px;" onclick="dvSaveOffre()">Valider</button>
                <button type="button" class="dvm-btn cancel" style="padding:8px 12px;" onclick="dvToggleOffre(false)">×</button>
              </div>
              <div id="dv-offre-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
            </div>
          </div>
        </div>

        <!-- Card COMPROMIS / PROMESSE -->
        <div class="dv-card dvc-acte">
          <h3>🤝 Compromis / Promesse</h3>
          <?php $avcResume = $avc ? ((($avc['type'] ?? '')==='promesse_unilaterale'?'Promesse':'Compromis').' · '.h($avc['statut'] ?? 'brouillon')) : null; ?>
          <?php if ($avcResume): ?><div class="dv-note" style="margin-bottom:8px;">Fiche notaire : <strong><?= $avcResume ?></strong></div><?php endif; ?>
          <button type="button" class="dvm-btn ok" style="width:100%;padding:10px 14px;margin-bottom:8px;" onclick="dvActeModalOpen()">📨 Préparation / envoi notaire</button>
          <button type="button" class="dvm-btn cancel" style="width:100%;padding:10px 14px;" onclick="odClasserOpen()">📥 Charger un avant-contrat</button>
        </div>

        <!-- Card FINANCEMENT acquéreur -->
        <?php
          $finDemande = $avcV('demande_financement_date');
          $finAccord  = $avcV('accord_financement_date');
          $finCss = $finAccord ? 'background:#d7f0e0;border:1px solid #9ad3ab;color:#0b6b35;'
                  : ($finDemande ? 'background:#fff3c7;border:1px solid #fcd980;color:#92600a;'
                  : 'background:#f8fafc;border:1px solid #e2e8f0;color:#475569;');
          $finLbl = $finAccord ? '🟢 Accord de financement obtenu'
                  : ($finDemande ? '🟧 Demande déposée — accord en attente'
                  : '🏦 Financement non démarré');
        ?>
        <div class="dv-card" style="border-color:#0891b2;">
          <h3>🏦 Financement acquéreur</h3>
          <div style="border-radius:10px;padding:9px 12px;margin-bottom:10px;font-size:12px;font-weight:700;<?= $finCss ?>"><?= $finLbl ?></div>
          <?php if (!empty($avc['cs_pret'])): ?>
            <div class="dv-row"><span class="k">Condition susp. prêt</span><span class="v"><?= h($fmtPrix($avcV('pret_montant'))) ?><?= $avcV('pret_date_limite') ? ' · limite ' . h($fmtDate($avcV('pret_date_limite'))) : '' ?></span></div>
          <?php endif; ?>
          <div class="dv-lot-f" style="margin-top:8px;"><label class="dvm-label">Demande de financement déposée le</label>
            <input type="date" id="dv-fin-demande" value="<?= h((string)$finDemande) ?>" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:7px;"></div>
          <div class="dv-lot-f" style="margin-top:8px;"><label class="dvm-label">Accord de financement obtenu le</label>
            <input type="date" id="dv-fin-accord" value="<?= h((string)$finAccord) ?>" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:7px;"></div>
          <button type="button" class="dvm-btn ok" style="width:100%;padding:9px;margin-top:10px;" onclick="dvFinSave()">💾 Enregistrer le financement</button>
          <div id="dv-fin-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
        </div>

        <!-- Card VENTE — acte authentique -->
        <div class="dv-card dvc-acte">
          <h3>🏛️ Vente — acte authentique</h3>
          <?php if (!$docsActe): ?>
            <div class="dv-empty">Aucun acte déposé.</div>
          <?php else: foreach ($docsActe as $d): ?>
            <div class="dv-doc" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
              <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">📄 <?= h($d['name_display'] ?: $d['name_file'] ?: ('Acte #' . $d['id'])) ?>
                <?php if (!empty($d['document_type'])): ?><span class="dv-badge"><?= h($d['document_type']) ?></span><?php endif; ?></span>
              <a class="dvm-btn cancel" style="padding:5px 12px;text-decoration:none;flex-shrink:0;" target="_blank"
                 href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$d['id'])) ?>">👁️ Voir</a>
            </div>
          <?php endforeach; endif; ?>
          <div style="margin-top:10px;border-top:1px solid #eef2f6;padding-top:10px;">
            <input type="file" id="dv-acte-file" accept=".pdf,.doc,.docx" style="width:100%;font-size:12px;">
            <button type="button" class="dvm-btn ok" style="width:100%;margin-top:8px;padding:9px;" onclick="dvActeUpload()">📎 Déposer l'acte</button>
            <div id="dv-acte-up-msg" style="font-size:11px;color:#94a3b8;margin-top:6px;"></div>
          </div>
        </div>
      </div>
      <!-- Modal saisie de l'acte (document + champs à droite, via iframe) -->
      <div id="dv-acte-modal" style="display:none;position:fixed;inset:0;z-index:9200;background:rgba(15,23,42,.55);padding:18px;">
        <div style="background:#fff;border-radius:14px;width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4);">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #e2e8f0;font-family:system-ui;">
            <strong style="color:#243B5C;">📨 Préparation envoi notaire — <?= h($refDossier) ?></strong>
            <button type="button" class="dvm-btn cancel" style="padding:6px 14px;" onclick="document.getElementById('dv-acte-modal').style.display='none'">✕ Fermer</button>
          </div>
          <iframe id="dv-acte-frame" src="about:blank" title="Avant-contrat" style="border:0;flex:1;width:100%;"></iframe>
        </div>
      </div>
      <script>
        window.dvActeModalOpen = function(){
          var f = document.getElementById('dv-acte-frame');
          if (f.src.indexOf('transaction_avant_contrat') === -1) f.src = <?= json_encode(app_url('/transaction_avant_contrat.php?id_dossier=' . $idDossier)) ?>;
          document.getElementById('dv-acte-modal').style.display = 'block';
        };
        // Enregistrement des jalons de financement (réutilise l'API avant-contrat,
        // champs whitelistés demande_financement_date / accord_financement_date).
        window.dvFinSave = async function(){
          var msg = document.getElementById('dv-fin-msg'); msg.style.color='#64748b'; msg.textContent='Enregistrement…';
          var fd = new FormData();
          fd.append('id_dossier', <?= (int)$idDossier ?>);
          fd.append('demande_financement_date', document.getElementById('dv-fin-demande').value || '');
          fd.append('accord_financement_date',  document.getElementById('dv-fin-accord').value  || '');
          try{
            var r = await fetch(<?= json_encode(app_url('/api/transaction_dossier_avant_contrat_save.php')) ?>, {method:'POST', body:fd});
            var j = await r.json();
            if(j.ok){ msg.style.color='#15803d'; msg.textContent='✓ Enregistré'; setTimeout(()=>location.reload(), 500); }
            else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Erreur'; }
          }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
        };
      </script>
      <?php if (false): // ancien formulaire inline désactivé (déplacé dans le modal) ?>
      <div style="display:none">
        <?php
          $sel = fn($k,$opt)=> '';
          $chk = fn($k)=> '';
          $val = fn($k)=> '';
          $acStyle = '';
        ?>
        <div>
          <form id="dv-avc-form-old" onsubmit="return false;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
              <div><label class="dvm-label">Type d'acte</label>
                <select name="type" style="<?= $acStyle ?>">
                  <option value="compromis" <?= $sel('type','compromis') ?>>Compromis (synallagmatique)</option>
                  <option value="promesse_unilaterale" <?= $sel('type','promesse_unilaterale') ?>>Promesse unilatérale</option>
                </select></div>
              <div><label class="dvm-label">Copropriété</label>
                <select name="copro" style="<?= $acStyle ?>"><option value="0" <?= $sel('copro','0') ?>>Hors copropriété</option><option value="1" <?= $sel('copro','1') ?>>Copropriété</option></select></div>
              <div><label class="dvm-label">Statut</label>
                <select name="statut" style="<?= $acStyle ?>">
                  <?php foreach (['brouillon'=>'Brouillon','signe'=>'Signé','caduc'=>'Caduc','realise'=>'Réalisé','annule'=>'Annulé'] as $k=>$lib): ?>
                    <option value="<?= $k ?>" <?= $sel('statut',$k) ?>><?= $lib ?></option>
                  <?php endforeach; ?>
                </select></div>
            </div>

            <p class="dvm-label" style="margin-top:12px;">Lots intégrés dans l'acte</p>
            <div style="display:flex;flex-direction:column;gap:4px;">
              <?php foreach ($lots as $l): $bId=(int)$l['id_bien'];
                $lib=$l['reference_bien'] ?: ('Bien #'.$bId);
                $adr=trim(((string)($l['lot_adresse']??'')).' '.((string)($l['lot_ville']??''))); ?>
                <label class="tm-opt" style="font-size:13px;">
                  <input type="checkbox" name="lots[]" value="<?= $bId ?>" <?= in_array($bId,$avcLots,true)?'checked':'' ?>>
                  <span><strong><?= h($lib) ?></strong> <small style="color:#94a3b8;"><?= h($adr) ?></small></span>
                </label>
              <?php endforeach; ?>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:12px;">
              <div><label class="dvm-label">Date de signature</label><input type="date" name="date_signature" value="<?= $val('date_signature') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Réitération (acte) max</label><input type="date" name="date_reiteration_max" value="<?= $val('date_reiteration_max') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Lieu de signature</label><input type="text" name="lieu_signature" value="<?= $val('lieu_signature') ?>" style="<?= $acStyle ?>"></div>
            </div>

            <p class="dvm-label" style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">💶 Dépôt de garantie / séquestre</p>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
              <div><label class="dvm-label">Dépôt garantie €</label><input type="text" name="depot_garantie_montant" value="<?= $val('depot_garantie_montant') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Dépôt %</label><input type="text" name="depot_garantie_pct" value="<?= $val('depot_garantie_pct') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Séquestre</label><select name="sequestre_type" style="<?= $acStyle ?>"><option value="">—</option><option value="notaire" <?= $sel('sequestre_type','notaire') ?>>Notaire</option><option value="agence" <?= $sel('sequestre_type','agence') ?>>Agence</option><option value="aucun" <?= $sel('sequestre_type','aucun') ?>>Aucun</option></select></div>
              <div><label class="dvm-label">Indemnité immobilisation € (promesse)</label><input type="text" name="indemnite_immobilisation" value="<?= $val('indemnite_immobilisation') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Date levée d'option (promesse)</label><input type="date" name="date_levee_option" value="<?= $val('date_levee_option') ?>" style="<?= $acStyle ?>"></div>
            </div>

            <p class="dvm-label" style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">🏦 Condition suspensive de prêt</p>
            <label class="tm-opt" style="font-size:13px;"><input type="checkbox" name="cs_pret" value="1" <?= $chk('cs_pret') ?>><span>Vente avec condition suspensive de prêt</span></label>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-top:6px;">
              <div><label class="dvm-label">Montant emprunté €</label><input type="text" name="pret_montant" value="<?= $val('pret_montant') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Durée (mois)</label><input type="text" name="pret_duree_mois" value="<?= $val('pret_duree_mois') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Taux max %</label><input type="text" name="pret_taux_max" value="<?= $val('pret_taux_max') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Nb offres</label><input type="text" name="pret_nb_offres" value="<?= $val('pret_nb_offres') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Date limite obtention</label><input type="date" name="pret_date_limite" value="<?= $val('pret_date_limite') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Apport €</label><input type="text" name="pret_apport" value="<?= $val('pret_apport') ?>" style="<?= $acStyle ?>"></div>
              <div style="grid-column:span 2;"><label class="dvm-label">Organismes</label><input type="text" name="pret_organismes" value="<?= $val('pret_organismes') ?>" style="<?= $acStyle ?>"></div>
            </div>

            <p class="dvm-label" style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">📋 Autres conditions suspensives</p>
            <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:13px;">
              <label class="tm-opt"><input type="checkbox" name="cs_preemption" value="1" <?= $chk('cs_preemption') ?>><span>Préemption</span></label>
              <label class="tm-opt"><input type="checkbox" name="cs_servitudes" value="1" <?= $chk('cs_servitudes') ?>><span>Servitudes</span></label>
              <label class="tm-opt"><input type="checkbox" name="cs_urbanisme" value="1" <?= $chk('cs_urbanisme') ?>><span>Urbanisme</span></label>
              <label class="tm-opt"><input type="checkbox" name="cs_hypotheques" value="1" <?= $chk('cs_hypotheques') ?>><span>Purge hypothèques</span></label>
              <label class="tm-opt"><input type="checkbox" name="cs_vente_bien_acquereur" value="1" <?= $chk('cs_vente_bien_acquereur') ?>><span>Vente bien acquéreur</span></label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
              <div><label class="dvm-label">Détail préemption</label><input type="text" name="cs_preemption_detail" value="<?= $val('cs_preemption_detail') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Autres CS (libre)</label><input type="text" name="cs_autres" value="<?= $val('cs_autres') ?>" style="<?= $acStyle ?>"></div>
            </div>

            <p class="dvm-label" style="margin-top:12px;border-top:1px solid #eef2f6;padding-top:10px;">🔑 Jouissance / mobilier · Acte · SRU</p>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
              <div><label class="dvm-label">Entrée en jouissance</label><input type="date" name="date_entree_jouissance" value="<?= $val('date_entree_jouissance') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">Occupation</label><select name="occupation" style="<?= $acStyle ?>"><option value="">—</option><option value="libre" <?= $sel('occupation','libre') ?>>Libre</option><option value="occupe" <?= $sel('occupation','occupe') ?>>Occupé</option><option value="loue" <?= $sel('occupation','loue') ?>>Loué</option></select></div>
              <div><label class="dvm-label">Mobilier €</label><input type="text" name="mobilier_valeur" value="<?= $val('mobilier_valeur') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="tm-opt"><input type="checkbox" name="mobilier_inclus" value="1" <?= $chk('mobilier_inclus') ?>><span>Mobilier inclus</span></label></div>
              <div><label class="dvm-label">Notaire rédacteur</label><select name="notaire_redacteur" style="<?= $acStyle ?>"><option value="">—</option><option value="vendeur" <?= $sel('notaire_redacteur','vendeur') ?>>Notaire vendeur</option><option value="acquereur" <?= $sel('notaire_redacteur','acquereur') ?>>Notaire acquéreur</option><option value="commun" <?= $sel('notaire_redacteur','commun') ?>>Commun</option></select></div>
              <div><label class="dvm-label">Frais d'acte à charge</label><select name="frais_acte_charge" style="<?= $acStyle ?>"><option value="acquereur" <?= $sel('frais_acte_charge','acquereur') ?>>Acquéreur</option><option value="vendeur" <?= $sel('frais_acte_charge','vendeur') ?>>Vendeur</option><option value="partage" <?= $sel('frais_acte_charge','partage') ?>>Partagé</option></select></div>
              <div><label class="dvm-label">SRU — date notification</label><input type="date" name="sru_date_notification" value="<?= $val('sru_date_notification') ?>" style="<?= $acStyle ?>"></div>
              <div><label class="dvm-label">SRU — fin rétractation</label><input type="date" name="sru_date_fin_retractation" value="<?= $val('sru_date_fin_retractation') ?>" style="<?= $acStyle ?>" placeholder="auto +10 j"></div>
            </div>
            <div style="margin-top:10px;"><label class="dvm-label">Conditions particulières</label><textarea name="conditions_particulieres" rows="3" style="<?= $acStyle ?>"><?= $val('conditions_particulieres') ?></textarea></div>

            <div style="margin-top:14px;display:flex;align-items:center;gap:12px;">
              <button type="button" class="dvm-btn ok" style="padding:10px 20px;" onclick="dvAvcSave()">💾 Enregistrer l'avant-contrat</button>
              <span id="dv-avc-msg" style="font-size:12px;"></span>
            </div>
          </form>
        </div>
      </div>
      <script>
        window.dvAvcSave = async function(){
          var f=document.getElementById('dv-avc-form'), msg=document.getElementById('dv-avc-msg');
          var fd=new FormData(f); fd.append('id_dossier', <?= (int)$idDossier ?>);
          // checkboxes non cochées : FormData ne les envoie pas → on force 0 pour les flags
          ['cs_pret','cs_preemption','cs_servitudes','cs_urbanisme','cs_hypotheques','cs_vente_bien_acquereur','mobilier_inclus','copro'].forEach(function(n){
            if(!f.querySelector('[name="'+n+'"]:checked') && f.querySelector('[name="'+n+'"][type=checkbox]')) fd.set(n,'0');
          });
          msg.style.color='#64748b'; msg.textContent='Enregistrement…';
          try{
            var r=await fetch(<?= json_encode(app_url('/api/transaction_dossier_avant_contrat_save.php')) ?>,{method:'POST',body:fd});
            var j=await r.json();
            if(j.ok){ msg.style.color='#15803d'; msg.textContent='✓ Enregistré'; }
            else { msg.style.color='#ef4444'; msg.textContent=j.error||'Erreur'; }
          }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
        };
      </script>
      <?php endif; // fin ancien formulaire désactivé ?>

      <!-- ===== ESTIMATION ===== -->
      <div class="dvk-panel solo" id="dvk-estimation">
        <?php if ($mandatSigne && !empty($estimCard)): ?>
          <?= $estimCard /* mandat signé : la card Estimation a basculé du Dashboard vers cet onglet */ ?>
        <?php else: ?>
          <div class="dv-card">
            <h3>📈 Estimation détaillée</h3>
            <div class="dvk-soon">Historique des prix, scénarios, comparables — à venir. (Atelier d'estimation dans le Dashboard tant que le mandat n'est pas signé.)</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- ===== COMMUNICATION ===== -->
      <div class="dvk-panel" id="dvk-communication">

        <!-- Card ANNONCE & SUPPORTS -->
        <div class="dv-card dvc-comm">
          <h3>📣 Annonce &amp; supports</h3>
          <p style="font-size:12px;color:#64748b;margin:2px 0 10px;">Tout est rattaché au bien du dossier — aucune ressaisie.</p>
          <a class="dvm-btn ok" style="display:block;text-align:center;text-decoration:none;padding:9px;margin-bottom:8px;" href="<?= h(app_url('/annonce_creation.php?id_bien=' . $idBien)) ?>">📝 Annonce directe</a>
          <a class="dvm-btn cancel" style="display:block;text-align:center;text-decoration:none;padding:9px;margin-bottom:8px;" href="<?= h(app_url('/mbi_supports_dashboard.php?id_bien=' . $idBien)) ?>">🖼️ Affiches &amp; supports</a>
          <a class="dvm-btn cancel" style="display:block;text-align:center;text-decoration:none;padding:9px;" href="<?= h(app_url('/annonce_liste.php?id_bien=' . $idBien)) ?>">📡 Diffusion portails</a>
        </div>

        <!-- Card COMMUNICATION (mails) -->
        <div class="dv-card dvc-communications">
          <h3>✉️ Communication <span style="font-weight:400;color:#94a3b8;font-size:12px;">(mails du dossier)</span></h3>
          <?php if (!$comms): ?>
            <div class="dv-empty">Aucun mail envoyé.</div>
          <?php else: foreach ($comms as $c): ?>
            <a href="<?= h(app_url('/transaction_mail.php?id_dossier=' . $idDossier . '&from_history=' . (int)$c['id'])) ?>"
               title="Rouvrir ce mail (destinataires + pièces jointes repris)"
               style="display:block;padding:6px 0;border-bottom:1px solid #f1f5f9;text-decoration:none;">
              <span style="font-size:13px;font-weight:700;color:#0e7490;">↻ <?= h($c['subject']) ?></span>
              <span style="display:block;font-size:11px;color:#94a3b8;"><?= h($fmtDate($c['sent_at'])) ?> · <?= (int)$c['recipients_count'] ?> destinataire(s)</span>
            </a>
          <?php endforeach; endif; ?>
          <div style="margin-top:12px;">
            <a class="dvm-btn ok" style="display:inline-block;padding:9px 16px;text-decoration:none;" href="<?= h(app_url('/transaction_mail.php?id_dossier=' . $idDossier)) ?>">✉️ Nouveau mail</a>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════ COLONNE DROITE : ACTIONS + CONTACTS ═══════════ -->
    <div class="dvk-aside">
      <div class="dvk-actions">
        <h4>⚡ Actions</h4>
        <?php if (!$mandat): ?>
          <button type="button" class="dvk-act-btn" onclick="dvOpenMandatModal()">📝 Créer le mandat de vente</button>
        <?php else: ?>
          <button type="button" class="dvk-act-btn" onclick="dvSendMandat()">✉️ Envoyer le mandat à signer</button>
        <?php endif; ?>
        <a class="dvk-act-btn" style="text-decoration:none;" href="<?= h(app_url('/transaction_mail.php?id_dossier=' . $idDossier)) ?>">✉️ Envoyer un mail</a>
        <button type="button" class="dvk-act-btn" style="background:#eef9f1;border-color:#9bd3ab;color:#1d6a3a;"
                onclick="<?= h($fbxOnClickDossier) ?>">📥 Charger un document</button>
        <button type="button" class="dvk-act-btn" onclick="odClasserOpen()">📥 Importer docs OneDrive</button>
        <a class="dvk-act-btn" style="text-decoration:none;" href="<?= h(app_url('/bien_360.php?id=' . $idBien)) ?>">🏠 Vue 360° du bien</a>
        <a class="dvk-act-btn" style="text-decoration:none;" href="<?= h(app_url('/bien_detail.php?edit=' . $idBien . '&section=descriptif&return_dossier=' . $idDossier)) ?>">📐 Descriptif du bien</a>
        <a class="dvk-act-btn" style="text-decoration:none;" href="<?= h(app_url('/bien_documents_list.php?id=' . $idBien)) ?>">📁 Documents du bien</a>
      </div>

      <!-- CONTACTS (acteurs du dossier) -->
      <div class="dv-card dvc-contacts">
        <h3>👥 Contacts
          <button type="button" class="dv-add-btn" onclick="acteurModalOpen_trx_acteur()" title="Ajouter un acteur (acquéreur, vendeur, notaire, conseil…)">+</button>
        </h3>
        <div id="dv-acteurs-list">
          <?php foreach ($acteurs as $a):
              $meta = json_decode((string)($a['metadata'] ?? ''), true) ?: [];
          ?>
            <div class="dv-actor" data-role-id="<?= (int)$a['role_id'] ?>" title="<?= h($roleLabels[$a['role_code']] ?? $a['role_code']) ?>">
              <span class="role-ic" title="<?= h($roleLabels[$a['role_code']] ?? $a['role_code']) ?>"><?= $roleIcons[$a['role_code']] ?? '👤' ?></span>
              <div style="flex:1;min-width:0;">
                <div class="nm"><?= h($acteurNom($a)) ?>
                  <?php if (!empty($meta['modifiable'])): ?><span class="dv-badge" title="Proposé automatiquement, modifiable">proposé</span><?php endif; ?>
                </div>
                <?php if ($a['email'] || $a['telephone']): ?>
                  <div class="ct"><?= h(trim(($a['email'] ?? '') . ($a['telephone'] ? ' · ' . $a['telephone'] : ''))) ?></div>
                <?php endif; ?>
              </div>
              <?php if (!empty($a['id_tiers'])): ?>
                <a class="dv-badge" href="<?= h(app_url('/tiers_360.php?id=' . (int)$a['id_tiers'])) ?>">fiche →</a>
              <?php endif; ?>
              <button type="button" class="dv-actor-del" title="Retirer du dossier" onclick="dvRemoveActeur(<?= (int)$a['role_id'] ?>, this)">×</button>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="dv-empty" id="dv-acteurs-empty" style="<?= $acteurs ? 'display:none;' : '' ?>">Aucun acteur rattaché.</div>
      </div>
    </div>
  </div>

  <div class="dv-note" style="margin-top:18px;">
    Dossier #<?= (int)$dossier['id'] ?> · étape <strong><?= h($dossier['etape']) ?></strong> ·
    source <?= h($dossier['source']) ?> · créé le <?= h($fmtDate($dossier['created_at'])) ?>.
    Toutes les données ci-dessus sont lues depuis les modules existants (aucune ressaisie).
  </div>
</div>

<!-- ═══ MODAL : ajouter un acteur au dossier (composant partagé) ═══ -->
<?php acteur_modal_render([
    'id'         => 'trx_acteur',
    'title'      => '➕ Ajouter un acteur',
    'role_label' => 'Rôle dans la vente',
    'roles'      => dv_roles_autorises(),
    'api_add'    => app_url('/api/transaction_dossier_acteur_add.php'),
    'entity'     => ['id_dossier' => $idDossier],
    'role_field' => 'role_code',
    'tiers_field'=> 'id_tiers',
    'csrf'       => function_exists('csrf_token') ? csrf_token('transaction_acteur') : '',
]); ?>
<!-- ═══ MODAL : créer le mandat de vente (termes) ═══ -->
<div class="dvm-backdrop" id="dvm-mandat">
  <div class="dvm">
    <h3>📝 Créer le mandat de vente</h3>
    <div class="sub">Les termes du mandat. Le bien et le vendeur sont déjà repris du dossier (zéro ressaisie).</div>

    <p class="dvm-label">Honoraires</p>
    <div style="display:flex;gap:8px;align-items:center;">
      <input type="text" id="dvm-honoraires" inputmode="numeric" placeholder="Montant €"
             style="width:120px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;text-align:right;">
      <input type="text" id="dvm-honoraires-pct" inputmode="decimal" placeholder="%"
             style="width:70px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;text-align:right;">
      <span style="font-size:12px;color:#64748b;">à charge :</span>
      <div class="dvm-roles" id="dvm-charge" style="margin:0;">
        <button type="button" class="dvm-role" data-charge="vendeur">Vendeur</button>
        <button type="button" class="dvm-role" data-charge="acquereur">Acquéreur</button>
        <button type="button" class="dvm-role" data-charge="partage">Partagé</button>
      </div>
    </div>

    <p class="dvm-label" style="margin-top:14px;">Exclusivité</p>
    <div class="dvm-roles" id="dvm-exclusif">
      <button type="button" class="dvm-role" data-excl="0">Mandat simple</button>
      <button type="button" class="dvm-role" data-excl="1">Mandat exclusif</button>
    </div>

    <div style="display:flex;gap:18px;margin-top:14px;flex-wrap:wrap;">
      <div><p class="dvm-label">Durée</p>
        <select id="dvm-duree" style="padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;">
          <option value="3">3 mois</option>
          <option value="6">6 mois</option>
          <option value="12">1 an</option>
        </select></div>
      <div><p class="dvm-label">Prise d'effet</p>
        <input type="date" id="dvm-datedebut" value="<?= date('Y-m-d') ?>" style="padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;"></div>
    </div>

    <div class="dvm-actions">
      <button type="button" class="dvm-btn cancel" onclick="dvCloseMandatModal()">Annuler</button>
      <button type="button" class="dvm-btn ok" onclick="dvSubmitMandat()">Créer le mandat</button>
    </div>
    <div id="dvm-mandat-form-msg" style="font-size:11px;color:#94a3b8;margin-top:8px;text-align:right;"></div>
  </div>
</div>

<?php
tiers_selector_assets();
// STANDARD MBI : modal immeuble (toute adresse = immeuble) — rend le bouton adresse
// du formulaire de création de tiers actif (opt-in via window.ImmeubleRechercheMBI).
require_once __DIR__ . '/inc/immeuble_recherche_mbi.php';
immeuble_mbi_render();
immeuble_mbi_assets();
?>

<?php
// Modal d'adresse Google (obligatoire pour la saisie d'adresse d'un nouveau tiers).
require_once __DIR__ . '/inc/adresse_modal.php';
?>

<!-- ── Modal classement OneDrive → GED (scope BIEN du dossier) ─────────── -->
<div id="odModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Documents OneDrive — <?= h($refBien) ?></h3>
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
  var BID=<?= (int)$idBien ?>, CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function post(action,extra){var fd=new FormData();fd.append('csrf_token',CSRF);fd.append('id_bien',BID);fd.append('action',action);
    if(extra){for(var k in extra){fd.append(k,extra[k]);}}
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  // Dossier OneDrive introuvable → saisie manuelle mémorisée, par RACINE (une agence peut
  // en avoir plusieurs, ex. LYON = propriétaires + diagnostics). Plus jamais « introuvable ».
  function odRenderManual(j){
    var roots=(j&&j.missing&&j.missing.length)?j.missing:[{schema_id:0,label:'principal',base:(j&&j.base)||''}];
    var inputs=roots.map(function(r,i){
      var lbl=esc(r.label||'principal'), base=esc(r.base||'');
      return '<div style="margin-bottom:10px;">'
        +'<div style="font-weight:700;font-size:12.5px;margin-bottom:3px;">📁 Racine « '+lbl+' »'
        +(r.mode?' <span style="color:#7c3aed;font-weight:600;">('+esc(r.mode)+')</span>':'')+'</div>'
        +(base?'<div style="color:#6b7280;font-size:11.5px;margin-bottom:4px;">base : <code>'+base+'</code></div>':'')
        +'<input class="odManualPath" data-schema="'+(r.schema_id||0)+'" type="text" '
        +'placeholder="01_SERVICE_GESTION/…/NOM (dossier proprio ou immeuble)" '
        +'style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;"></div>';
    }).join('');
    document.getElementById('odBody').innerHTML=
      '<div style="color:#c62828;padding:6px 0 10px;">❌ '+esc((j&&j.error)||'Dossier OneDrive introuvable')+'</div>'
      +'<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px;">'
      +'<div style="color:#6b7280;font-size:12px;margin-bottom:10px;">Colle le chemin du dossier OneDrive (ex. <code>C:\\Users\\…\\OneDrive - REGIE EMERY (1)\\01_SERVICE_GESTION\\…</code> ou chemin relatif). Mémorisé pour ce propriétaire. Laisse vide une racine que tu ne veux pas renseigner.</div>'
      +inputs
      +'<button type="button" onclick="odSaveManual()" style="background:#243B5C;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;">💾 Enregistrer et réessayer</button>'
      +'<span id="odManualMsg" style="margin-left:10px;font-size:12.5px;font-weight:700;"></span></div>';
  }
  window.odSaveManual=function(){
    var m=document.getElementById('odManualMsg');
    var fields=[].slice.call(document.querySelectorAll('.odManualPath'))
      .map(function(i){return {schema:i.getAttribute('data-schema')||'0',val:(i.value||'').trim()};})
      .filter(function(f){return f.val!=='';});
    if(!fields.length){m.style.color='#c62828';m.textContent='Au moins un chemin requis';return;}
    m.style.color='#6b7280';m.textContent='⏳ Enregistrement…';
    var chain=Promise.resolve();
    fields.forEach(function(f){ chain=chain.then(function(){return post('set_folder',{folder_path:f.val,schema_id:f.schema});}); });
    chain.then(function(){m.style.color='#2d8a4e';m.textContent='✓ Mémorisé. Nouvelle analyse…';setTimeout(window.odClasserOpen,500);})
      .catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau';});
  };
  window.odClasserOpen=function(){
    document.getElementById('odModal').style.display='flex';
    document.getElementById('odCommitBtn').disabled=true; document.getElementById('odCommitBtn').style.opacity=.5;
    document.getElementById('odMsg').textContent='';
    document.getElementById('odBody').innerHTML='<div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div>';
    post('scan').then(function(j){
      if(!j||!j.ok){ if(j&&j.needs_manual){odRenderManual(j);return;} document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ '+esc((j&&j.error)||'Erreur')+(j&&j.base?'<br><small>base: '+esc(j.base)+'</small>':'')+'</div>';return;}
      var rows=(j.items||[]).map(function(it){
        var col=it.status==='certain'?'#2d8a4e':(it.status==='pile'?'#8a6d1b':'#c62828');
        var cible=it.target==='BAIL'?('→ bail #'+it.bail_id):(it.target==='BIEN'?'→ ce bien':'→ pile');
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;margin-top:2px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">'+esc(cible)+'</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason)+'</td></tr>';
      }).join('');
      var nbCertain=(j.items||[]).filter(function(x){return x.status==='certain';}).length;
      document.getElementById('odBody').innerHTML=
        '<div style="margin-bottom:8px;color:#6b7280;">Dossier <b>'+esc(j.folder)+'</b> · baux du bien '+(j.nb_baux||0)+' · <b>'+nbCertain+'</b> doc(s) à classer (nouveaux + loupés).</div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(rows||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun document rattachable.</td></tr>')+'</tbody></table>';
      var b=document.getElementById('odCommitBtn'); if(nbCertain>0){b.disabled=false;b.style.opacity=1;}
    }).catch(function(e){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>';});
  };
  window.odClasserCommit=function(){
    var b=document.getElementById('odCommitBtn'),m=document.getElementById('odMsg');
    b.disabled=true;b.style.opacity=.5;m.style.color='#6b7280';m.textContent='⏳ Classement en cours…';
    post('commit').then(function(j){
      if(!j||!j.ok){m.style.color='#c62828';m.textContent='❌ '+esc((j&&j.error)||'Erreur');return;}
      m.style.color='#2d8a4e';m.textContent='✓ '+j.classes+' document(s) classé(s) en GED'+(j.pile?(' · '+j.pile+' en pile'):'')+'. Recharge la page.';
      setTimeout(function(){location.reload();}, 1200);
    }).catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau : '+esc(e);});
  };
})();
</script>
<script src="<?= h(asset_url('/js/places.js')) ?>?v=<?= @filemtime(__DIR__ . '/js/places.js') ?: '1' ?>"></script>
<script src="<?= h(asset_url('/js/adresse_modal.js')) ?>?v=<?= @filemtime(__DIR__ . '/js/adresse_modal.js') ?: '1' ?>"></script>
<?php if (($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '') !== ''): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= h($GLOBALS['GOOGLE_MAPS_API_KEY']) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>

<script>
(function(){
  const DOSSIER_ID = <?= (int)$idDossier ?>;
  const API_ADD    = <?= json_encode(app_url('/api/transaction_dossier_acteur_add.php')) ?>;
  const API_DEL    = <?= json_encode(app_url('/api/transaction_dossier_acteur_remove.php')) ?>;
  const API_ESTIM  = <?= json_encode(app_url('/api/transaction_dossier_estimation_save.php')) ?>;
  const API_MANDAT = <?= json_encode(app_url('/api/transaction_dossier_mandat_create.php')) ?>;
  const API_MSEND  = <?= json_encode(app_url('/api/transaction_dossier_mandat_send.php')) ?>;
  const TIERS_FICHE= <?= json_encode(app_url('/tiers_360.php?id=')) ?>;
  const API_LOT    = <?= json_encode(app_url('/api/transaction_dossier_lot.php')) ?>;
  const BIEN_360   = <?= json_encode(app_url('/bien_360.php?id=')) ?>;
  const API_ESTIM_UP = <?= json_encode(app_url('/api/bien_estimation_upload.php')) ?>;
  const CSRF_ESTIM   = <?= json_encode(function_exists('csrf_token') ? csrf_token('dossier_estimation') : '') ?>;

  // ════════ Dépôt de document (PDF/Word) → GED du bien (estimation / mandat / acte) ════════
  const DV_DOSSIER_BIEN = <?= (int)$idBien ?>;
  async function dvDocUpload(fileId, msgId, docType, idBien){
    const fileEl = document.getElementById(fileId);
    const msg = document.getElementById(msgId);
    if (!fileEl || !fileEl.files.length){ msg.textContent = 'Sélectionne un fichier.'; msg.style.color='#ef4444'; return; }
    const fd = new FormData();
    fd.append('id_bien', idBien);
    fd.append('doc_type', docType);
    fd.append('id_dossier', DOSSIER_ID);
    fd.append('CSRF', CSRF_ESTIM);
    fd.append('fichier', fileEl.files[0]);
    msg.style.color='#94a3b8'; msg.textContent = 'Dépôt en cours…';
    try{
      const r = await fetch(API_ESTIM_UP, {method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ msg.style.color='#15803d'; msg.textContent = '✓ Document déposé'; setTimeout(()=>location.reload(), 700); }
      else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Erreur'; }
    }catch(e){ msg.style.color='#ef4444'; msg.textContent = 'Erreur réseau'; }
  }
  // Estimation : lot sélectionné (ou unique) ; mandat/acte : bien principal du dossier.
  window.dvEstimUpload  = () => { const l=document.getElementById('dv-estim-lot'); dvDocUpload('dv-estim-file','dv-estim-up-msg','ESTIMATION', l?l.value:DV_DOSSIER_BIEN); };
  window.dvMandatUpload = () => dvDocUpload('dv-mandat-file','dv-mandat-up-msg','MANDAT_VENTE', DV_DOSSIER_BIEN);
  window.dvActeUpload   = () => dvDocUpload('dv-acte-file','dv-acte-up-msg','ACTE_AUTHENTIQUE', DV_DOSSIER_BIEN);

  // ── Offre : réutilise l'API structurée existante transaction_offre_save.php ──
  const API_OFFRE = <?= json_encode(app_url('/api/transaction_offre_save.php')) ?>;
  window.dvToggleOffre = (show) => { document.getElementById('dv-offre-form').style.display = show ? 'block' : 'none'; };
  window.dvSaveOffre = async function(){
    const msg = document.getElementById('dv-offre-msg');
    const prix = (document.getElementById('dv-offre-prix').value||'').replace(/[^0-9.]/g,'');
    if(!prix){ msg.style.color='#ef4444'; msg.textContent='Prix requis.'; return; }
    const fd = new FormData();
    fd.append('id_bien', DV_DOSSIER_BIEN);
    fd.append('prix_propose', prix);
    fd.append('nom', document.getElementById('dv-offre-nom').value||'');
    fd.append('email', document.getElementById('dv-offre-email').value||'');
    fd.append('statut_offre', 'recue');
    msg.style.color='#94a3b8'; msg.textContent='Enregistrement…';
    try{
      const r = await fetch(API_OFFRE, {method:'POST', body:fd});
      const j = await r.json();
      if(!j.ok){ msg.style.color='#ef4444'; msg.textContent = j.error || 'Erreur'; return; }
      // Document de l'offre fourni → dépôt GED (OFFRE_ACHAT) sur le bien + dossier.
      const fileEl = document.getElementById('dv-offre-file');
      if(fileEl && fileEl.files.length){
        msg.textContent = 'Offre OK, dépôt du document…';
        const fd2 = new FormData();
        fd2.append('id_bien', DV_DOSSIER_BIEN);
        fd2.append('doc_type', 'OFFRE_ACHAT');
        fd2.append('id_dossier', DOSSIER_ID);
        fd2.append('CSRF', CSRF_ESTIM);
        fd2.append('fichier', fileEl.files[0]);
        try{ await fetch(API_ESTIM_UP, {method:'POST', body:fd2}); }catch(e){}
      }
      msg.style.color='#15803d'; msg.textContent='✓ Offre enregistrée'; setTimeout(()=>location.reload(),700);
    }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
  };
  let selectedRole = '';
  let mCharge = '', mExcl = '0';

  // ════════ LOTS DU MANDAT ════════
  const fmtE = n => (n || n === 0) ? new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' €' : '—';

  async function lotPost(params){
    params.append('id_dossier', DOSSIER_ID);
    const r = await fetch(API_LOT, {method:'POST', body:params});
    return r.json();
  }
  function lotMsg(t, err){
    const el = document.getElementById('dv-lot-msg');
    if(el){ el.textContent = t || ''; el.style.color = err ? '#ef4444' : '#94a3b8'; }
  }
  function lotRefreshTotaux(tot){
    if(!tot) return;
    var p = document.getElementById('dv-tot-prix'); if(p) p.textContent = fmtE(tot.prix_total);
  }

  // Rentabilité d'un lot : loyer estimé si réel vide, sinon réel, / prix. Loyers annuels.
  function dvLotRdt(el){
    const get = f => { const i = el.querySelector('[data-f="'+f+'"]'); if(!i) return 0;
      const v = (i.value || i.placeholder || '').replace(/[^0-9.]/g,''); return parseFloat(v) || 0; };
    const prix = get('prix_vente'), lp = get('loyer_potentiel'), lr = get('loyer_reel');
    const loyer = lp > 0 ? lp : lr;
    const cell = el.querySelector('.dv-lot-rdt');
    if(cell) cell.textContent = (prix > 0 && loyer > 0)
      ? new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(loyer/prix*100) + ' %' : '—';
  }

  // Sauvegarde inline d'un lot (prix / loyers) sur changement.
  window.dvLotSave = async function(tr){
    const lotId = tr.getAttribute('data-lot-id');
    const p = new URLSearchParams({action:'save', lot_id:lotId});
    tr.querySelectorAll('.dv-lot-in').forEach(i => p.append(i.dataset.f, i.value.replace(/[^0-9.]/g,'')));
    lotMsg('Enregistrement…');
    const j = await lotPost(p);
    if(j.ok){ lotMsg('✓ Enregistré'); lotRefreshTotaux(j.totaux); setTimeout(()=>lotMsg(''),1500); }
    else { lotMsg(j.error || 'Erreur', true); }
  };

  window.dvLotRemove = async function(lotId){
    if(!confirm('Retirer ce lot du mandat ?')) return;
    const j = await lotPost(new URLSearchParams({action:'remove', lot_id:lotId}));
    if(j.ok){ location.reload(); } else { lotMsg(j.error || 'Erreur', true); }
  };

  let lotSearchTimer = null;
  function dvLotCard(b){
    const lib  = b.reference_bien || b.designation || ('Bien #'+b.id);
    const sub  = [b.type_lib||'', b.surface_habitable?(parseFloat(b.surface_habitable)+' m²'):'', b.etage?('Ét. '+b.etage):'', b.numero_lot?('Lot '+b.numero_lot):''].filter(Boolean).join(' · ');
    const adr  = [b.adresse_1||'', b.ville||''].filter(Boolean).join(', ');
    const tag  = (+b.meme_immeuble) ? '<span style="background:#e7f6ec;color:#176a3a;font-size:10px;font-weight:700;border-radius:20px;padding:2px 8px;white-space:nowrap;">🏛️ même immeuble</span>' : '';
    return `<div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:5px;background:#fff;">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;"><strong style="font-size:13px;color:#143A41;">${lib}</strong>${tag}</div>
      <div style="font-size:11.5px;color:#64748b;">${sub||'—'}</div>
      <div style="font-size:11px;color:#94a3b8;">${adr}</div>
      <button type="button" onclick="dvLotAdd(${b.id})" style="margin-top:4px;background:#0e7490;color:#fff;border:none;border-radius:8px;padding:8px;font-size:12px;font-weight:700;cursor:pointer;">➕ Ajouter au dossier</button>
    </div>`;
  }
  function dvLotRenderGrid(items, emptyMsg){
    const g = document.getElementById('dv-lot-grid');
    if(!items || !items.length){ g.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#94a3b8;font-size:13px;padding:20px;">'+(emptyMsg||'Aucun bien.')+'</div>'; return; }
    g.innerHTML = items.map(dvLotCard).join('');
  }
  window.dvLotModalOpen = async function(){
    document.getElementById('dv-lot-modal').style.display='flex';
    document.getElementById('dv-lot-grid').innerHTML='<div style="grid-column:1/-1;text-align:center;color:#94a3b8;padding:20px;">Chargement…</div>';
    const s = document.getElementById('dv-lot-msearch'); if(s) s.value='';
    const j = await lotPost(new URLSearchParams({action:'owner_lots'}));
    const sub = document.getElementById('dv-lot-modal-sub');
    if(j.ok){
      sub.textContent = (j.owner?('Propriétaire : '+j.owner):'Aucun propriétaire') + (j.immeuble?(' · '+j.immeuble):'');
      dvLotRenderGrid(j.items, 'Aucun autre lot du propriétaire. Créez un nouveau lot ou recherchez un bien ci-dessus.');
    } else dvLotRenderGrid([], j.error||'Erreur');
  };
  window.dvLotModalClose = function(){ document.getElementById('dv-lot-modal').style.display='none'; };
  function dvLotModalSearch(q){
    clearTimeout(lotSearchTimer);
    lotSearchTimer = setTimeout(async ()=>{
      if(q.length < 2){ dvLotModalOpen(); return; }               // < 2 car. → revient aux lots du propriétaire
      const j = await lotPost(new URLSearchParams({action:'search', q:q}));
      if(j.ok) dvLotRenderGrid(j.items, 'Aucun bien pour « '+q+' ».');
    }, 250);
  }
  async function dvLotAdd(idBien){
    const j = await lotPost(new URLSearchParams({action:'add', id_bien:idBien}));
    if(j.ok){ location.reload(); } else { lotMsg(j.error || 'Erreur', true); alert(j.error || 'Ajout impossible'); }
  }
  window.dvLotAdd = dvLotAdd;

  (function initLots(){
    const fmtMontant = v => { v=(''+v).replace(/[^0-9]/g,''); return v ? new Intl.NumberFormat('fr-FR').format(parseInt(v,10))+' €' : ''; };
    document.querySelectorAll('#dv-lots-body .dv-lot-in').forEach(i => {
      // Affichage formaté au repos ; brut pendant l'édition.
      if(i.value) i.value = fmtMontant(i.value);
      if(i.placeholder) i.placeholder = fmtMontant(i.placeholder);
      i.addEventListener('focus', () => { i.value = i.value.replace(/[^0-9]/g,''); });
      i.addEventListener('blur',  () => { i.value = fmtMontant(i.value); });
      i.addEventListener('change', () => { const el = i.closest('.dv-lot'); dvLotSave(el); dvLotRdt(el); });
    });
    document.querySelectorAll('#dv-lots-body .dv-lot').forEach(el => dvLotRdt(el));
    const ms = document.getElementById('dv-lot-msearch');
    if(ms){ ms.addEventListener('input', e => dvLotModalSearch(e.target.value.trim())); }
  })();

  // Onglets du cockpit (Dashboard / Documents / Actes / Estimation).
  window.dvkTab = function(btn, name){
    document.querySelectorAll('.dvk-tab').forEach(t=>t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.dvk-panel').forEach(p=>p.classList.remove('active'));
    var panel = document.getElementById('dvk-'+name);
    if(panel) panel.classList.add('active');
  };

  // ── Mandat : création (termes) ──
  window.dvOpenMandatModal = function(){ document.getElementById('dvm-mandat').classList.add('open'); };
  window.dvCloseMandatModal = function(){ document.getElementById('dvm-mandat').classList.remove('open'); };
  // Honoraires : % ⇄ montant (base = prix mandat total des lots)
  (function(){
    var PRIX = <?= (float)$totaux['prix_total'] ?>;
    var hM=document.getElementById('dvm-honoraires'), hP=document.getElementById('dvm-honoraires-pct');
    if(hM&&hP){ var n=el=>parseFloat((el.value||'').replace(/[^0-9.]/g,''))||0;
      hM.addEventListener('input',()=>{ hP.value = PRIX>0 ? (n(hM)/PRIX*100).toFixed(2) : ''; });
      hP.addEventListener('input',()=>{ hM.value = PRIX>0 ? Math.round(n(hP)/100*PRIX) : ''; });
    }
  })();
  document.getElementById('dvm-charge')?.addEventListener('click', e=>{
    const b=e.target.closest('.dvm-role'); if(!b)return;
    document.querySelectorAll('#dvm-charge .dvm-role').forEach(x=>x.classList.remove('active'));
    b.classList.add('active'); mCharge=b.dataset.charge;
  });
  document.getElementById('dvm-exclusif')?.addEventListener('click', e=>{
    const b=e.target.closest('.dvm-role'); if(!b)return;
    document.querySelectorAll('#dvm-exclusif .dvm-role').forEach(x=>x.classList.remove('active'));
    b.classList.add('active'); mExcl=b.dataset.excl;
  });
  window.dvSubmitMandat = async function(){
    const msg=document.getElementById('dvm-mandat-form-msg'); msg.textContent='Création…';
    try{
      const body=new URLSearchParams({
        id_dossier:DOSSIER_ID,
        honoraires:(document.getElementById('dvm-honoraires').value||'').replace(/[^0-9.,]/g,''),
        honoraires_charge:mCharge, exclusif:mExcl,
        duree_mois:(document.getElementById('dvm-duree').value||'').replace(/[^0-9]/g,''),
        date_debut:document.getElementById('dvm-datedebut').value||''
      });
      const res=await fetch(API_MANDAT,{method:'POST',credentials:'same-origin',body});
      const out=await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      msg.style.color='#0b8043'; msg.textContent='✓ '+(out.message||'Mandat créé');
      setTimeout(()=>location.reload(), 800);
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

  // ── Mandat : envoi au vendeur pour signature ──
  window.dvSendMandat = async function(){
    const msg=document.getElementById('dv-mandat-msg'); msg.style.color='#94a3b8'; msg.textContent='Envoi…';
    try{
      const body=new URLSearchParams({ id_dossier:DOSSIER_ID });
      const res=await fetch(API_MSEND,{method:'POST',credentials:'same-origin',body});
      const out=await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      const lignes=(out.envois||[]).map(e=>{
        if(e.sent) return '✉️ Email envoyé à '+e.email;
        if(e.email) return '⚠️ Email non parti ('+e.email+') — lien : '+e.url;
        return '🔗 Pas d\'email vendeur — lien à transmettre : '+e.url;
      });
      msg.style.color='#0b8043'; msg.innerHTML='✓ '+lignes.join('<br>');
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

  // ── Estimation inline (écrit dans bien_prix via l'endpoint dédié) ──
  window.dvToggleEstim = function(show){
    document.getElementById('dv-estim-form').style.display = show ? '' : 'none';
    if (show) setTimeout(()=>document.getElementById('dv-estim-input').focus(), 30);
  };
  window.dvSaveEstim = async function(){
    const inp = document.getElementById('dv-estim-input');
    const msg = document.getElementById('dv-estim-msg');
    const prix = (inp.value || '').replace(/[^0-9.,]/g,'');
    if (!prix){ msg.textContent='Saisis un prix.'; return; }
    msg.textContent='Enregistrement…';
    try{
      const body = new URLSearchParams({ id_dossier:DOSSIER_ID, prix });
      const res  = await fetch(API_ESTIM, {method:'POST', credentials:'same-origin', body});
      const out  = await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      document.getElementById('dv-prix-val').textContent = out.prix_fmt;
      msg.style.color='#0b8043'; msg.textContent='✓ Estimation enregistrée'+(out.etape?' · étape : '+out.etape:'');
      setTimeout(()=>dvToggleEstim(false), 1200);
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

  // L'ajout d'acteur est géré par le composant partagé inc/acteur_modal.php
  // (modal id "trx_acteur", recharge la page après ajout). On conserve ici uniquement
  // la suppression d'un acteur déjà listé.

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  window.dvAppendActeur = function(a){
    document.getElementById('dv-acteurs-empty').style.display='none';
    const list = document.getElementById('dv-acteurs-list');
    // si le même rôle existe déjà (réactivation), ne pas dupliquer
    if (list.querySelector('[data-role-id="'+a.role_id+'"]')) return;
    const ct = [a.email, a.telephone].filter(Boolean).join(' · ');
    const div = document.createElement('div');
    div.className='dv-actor'; div.dataset.roleId=a.role_id; div.title=a.role_label||'';
    var icons={prospect_vendeur:'🔑',vendeur:'🔑',acquereur:'🛒',prospect_acquereur:'🛒',notaire:'⚖️',notaire_acquereur:'⚖️',avocat:'👔',partenaire_apporteur:'🤝',collaborateur:'👥'};
    div.innerHTML =
      '<span class="role-ic" title="'+esc(a.role_label||'')+'">'+(icons[a.role_code]||'👤')+'</span>'+
      '<div style="flex:1;min-width:0;"><div class="nm">'+esc(a.nom)+'</div>'+
      (ct?'<div class="ct">'+esc(ct)+'</div>':'')+'</div>'+
      '<a class="dv-badge" href="'+TIERS_FICHE+a.id_tiers+'">fiche →</a>'+
      '<button type="button" class="dv-actor-del" title="Retirer du dossier" onclick="dvRemoveActeur('+a.role_id+', this)">×</button>';
    list.appendChild(div);
  };

  window.dvRemoveActeur = async function(roleId, el){
    if(!confirm('Retirer cet acteur du dossier ?')) return;
    try{
      const body = new URLSearchParams({ id_dossier:DOSSIER_ID, role_id:roleId });
      const res  = await fetch(API_DEL, {method:'POST', credentials:'same-origin', body});
      const out  = await res.json();
      if(!out.ok){ alert('Erreur : '+(out.error||'inconnue')); return; }
      const row = el.closest('.dv-actor'); if(row) row.remove();
      if(!document.querySelector('#dv-acteurs-list .dv-actor'))
        document.getElementById('dv-acteurs-empty').style.display='';
    }catch(err){ alert('Erreur réseau : '+err.message); }
  };

  // ── Annulation / suppression du dossier de vente ──
  const API_DVCANCEL = <?= json_encode(app_url('/api/transaction_dossier_delete.php')) ?>;
  window.dvCancelOpen  = function(){ document.getElementById('dv-cancel-modal').classList.add('open'); };
  window.dvCancelClose = function(){ document.getElementById('dv-cancel-modal').classList.remove('open'); };
  window.dvCancelConfirm = async function(){
    const btn = document.getElementById('dv-cancel-go'); btn.disabled=true; btn.textContent='Annulation…';
    try{
      const body = new URLSearchParams({ id_dossier:DOSSIER_ID });
      const res  = await fetch(API_DVCANCEL, {method:'POST', credentials:'same-origin', body});
      const out  = await res.json();
      if(!out.ok){ alert('Erreur : '+(out.error||'inconnue')); btn.disabled=false; btn.textContent='Oui, annuler'; return; }
      // Retour à la PAGE PRÉCÉDENTE (d'où l'on vient), pas sur le bien.
      var ref = document.referrer;
      var back = (ref && ref.indexOf(location.origin) === 0 && ref.indexOf('transaction_dossier.php') === -1)
                 ? ref
                 : (out.redirect || <?= json_encode(app_url('/transaction_index.php')) ?>);
      location.href = back;
    }catch(err){ alert('Erreur réseau : '+err.message); btn.disabled=false; btn.textContent='Oui, annuler'; }
  };
})();
</script>

<!-- ── Modal de confirmation : annuler le dossier de vente ── -->
<?php $dvVide = ($dossier['etape'] === 'estimation') && empty($dossier['id_mandat']); ?>
<style>
  #dv-cancel-modal{display:none;position:fixed;inset:0;z-index:9300;background:rgba(15,18,24,.55);align-items:center;justify-content:center;padding:18px;}
  #dv-cancel-modal.open{display:flex;}
  #dv-cancel-modal .box{background:#fff;border-radius:14px;width:min(480px,95vw);overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}
  #dv-cancel-modal .hd{background:#b91c1c;color:#fff;padding:16px 20px;font-weight:700;font-size:16px;display:flex;gap:10px;align-items:center;}
  #dv-cancel-modal .bd{padding:20px;color:#334155;line-height:1.55;}
  #dv-cancel-modal .ft{display:flex;gap:10px;padding:0 20px 20px;}
  #dv-cancel-modal .btn{flex:1;padding:12px;border-radius:9px;font-weight:700;cursor:pointer;border:0;font-size:14px;}
  #dv-cancel-modal .no{background:#e2e8f0;color:#334155;}
  #dv-cancel-modal .yes{background:#b91c1c;color:#fff;}
</style>
<div id="dv-cancel-modal">
  <div class="box">
    <div class="hd">🗑️ Annuler le dossier de vente</div>
    <div class="bd">
      <?php if ($dvVide): ?>
        <p>Ce dossier est encore vide (étape estimation, aucun mandat). Il sera
           <strong>définitivement supprimé</strong>.</p>
      <?php else: ?>
        <p>Ce dossier est engagé (mandat / offre en cours). Il ne sera pas supprimé mais
           <strong>marqué « sans suite »</strong> et sortira des dossiers actifs.</p>
      <?php endif; ?>
      <p style="color:#64748b;font-size:13px;margin-bottom:0;">Les documents déjà classés ne sont pas supprimés.</p>
    </div>
    <div class="ft">
      <button type="button" class="btn no" onclick="dvCancelClose()">Non, garder</button>
      <button type="button" class="btn yes" id="dv-cancel-go" onclick="dvCancelConfirm()">Oui, annuler</button>
    </div>
  </div>
</div>

<!-- ── Modal « Charger un document » (mécanisme fiable identique à bail_360) ── -->
<div id="dvUpModal" style="display:none;position:fixed;inset:0;z-index:9100;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(560px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Charger un document</h3>
      <button type="button" onclick="document.getElementById('dvUpModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕</button>
    </div>
    <div style="padding:16px 18px;font-size:13px;">
      <div class="dv-note" style="margin-bottom:10px;">Le document est <b>rangé automatiquement</b> en GED et rattaché à toutes les entités liées : <b>dossier</b>, <b>bien</b>, <b>immeuble</b>, <b>propriétaire</b> et <b>bail</b> (si présents) — aucun doublon.</div>
      <form id="dvUpForm" enctype="multipart/form-data">
        <input type="hidden" name="id_dossier" value="<?= (int)$idDossier ?>">
        <input type="file" name="document[]" id="dvUpFile" multiple
               accept=".pdf,.jpg,.jpeg,.png,.tiff,.docx,.xlsx,.heic" style="display:none;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
          <label style="font-weight:700;color:#1d4e57;">Type :</label>
          <select id="dvUpType" name="type_document" style="padding:7px 10px;border:1px solid #b6d4d9;border-radius:8px;background:#fff;font-size:13px;">
            <optgroup label="Vente">
              <option value="MANDAT_VENTE">📜 Mandat de vente</option>
              <option value="ESTIMATION">📊 Avis de valeur / estimation</option>
              <option value="OFFRE_ACHAT">💰 Offre d'achat</option>
              <option value="COMPROMIS">📝 Compromis / promesse</option>
              <option value="ACTE_AUTHENTIQUE">🏛️ Acte authentique</option>
            </optgroup>
            <optgroup label="Financement (acquéreur)">
              <option value="FINANCEMENT_DEMANDE">🏦 Demande de financement</option>
              <option value="FINANCEMENT_ACCORD">✅ Accord / offre de prêt</option>
            </optgroup>
            <optgroup label="Bien & propriété">
              <option value="DIAG_DPE">⚡ Diagnostic (DPE…)</option>
              <option value="TITRE_PROPRIETE">🏠 Titre de propriété</option>
              <option value="COPROPRIETE">🏢 Documents copropriété (PV AG, règlement…)</option>
              <option value="BAIL">🔑 Bail / location</option>
            </optgroup>
            <optgroup label="Autre">
              <option value="IDENTITE">🪪 Pièce d'identité / KYC</option>
              <option value="AUTRE" selected>📎 Autre document</option>
            </optgroup>
          </select>
        </div>
        <div id="dvDropzone" tabindex="0"
             style="border:2px dashed #7fb3bc;border-radius:12px;background:#f3fafb;padding:24px 18px;text-align:center;cursor:pointer;">
          <div style="font-size:28px;line-height:1;">📥</div>
          <div style="font-weight:800;color:#1d4e57;margin-top:6px;">Glissez un document ici</div>
          <div style="font-size:12px;color:#7a766f;margin-top:3px;">ou cliquez pour parcourir · PDF, images, DOCX… (50 Mo max)</div>
          <div id="dvUpPicked" style="font-size:12px;color:#2d8a4e;font-weight:700;margin-top:8px;"></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
          <button type="submit" id="dvUpBtn" class="dvm-btn ok" style="padding:10px 18px;">📎 Charger</button>
          <span id="dvUpMsg" style="font-size:12.5px;font-weight:600;"></span>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
window.dvUploadOpen = function(){ document.getElementById('dvUpModal').style.display='flex'; };
(function(){
  var form = document.getElementById('dvUpForm');
  if (!form) return;
  var input  = document.getElementById('dvUpFile');
  var zone   = document.getElementById('dvDropzone');
  var picked = document.getElementById('dvUpPicked');
  var UP_URL = <?= json_encode(app_url('/api/transaction_dossier_doc_upload.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var CSRF_UP = <?= json_encode(function_exists('csrf_token') ? csrf_token('transaction_doc_upload') : '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  function listNames(files){ return Array.prototype.map.call(files, function(f){ return f.name; }).join(', '); }
  function doUpload(files){
    var btn = document.getElementById('dvUpBtn'), msg = document.getElementById('dvUpMsg');
    if (!files || !files.length){ msg.style.color='#c62828'; msg.textContent='❌ Aucun fichier.'; return; }
    var fd = new FormData();
    fd.append('csrf_token', CSRF_UP);
    fd.append('id_dossier', form.querySelector('[name=id_dossier]').value);
    var tSel = document.getElementById('dvUpType');
    fd.append('type_document', tSel ? tSel.value : 'AUTRE');
    for (var i=0;i<files.length;i++) fd.append('document[]', files[i]);
    var prev = btn.textContent; btn.disabled=true; btn.textContent='⏳ Chargement…'; msg.textContent='';
    fetch(UP_URL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        btn.disabled=false; btn.textContent=prev;
        if (j && j.ok){ msg.style.color='#2d8a4e'; msg.textContent='✓ '+j.n+' document(s) rangé(s).'; setTimeout(function(){ location.reload(); }, 1100); }
        else { msg.style.color='#c62828'; msg.textContent='❌ '+((j && (j.error || (j.errors||[]).join(' / '))) || 'Échec'); }
      })
      .catch(function(e){ btn.disabled=false; btn.textContent=prev; msg.style.color='#c62828'; msg.textContent='❌ Erreur réseau : '+e; });
  }
  zone.addEventListener('click', function(){ input.click(); });
  zone.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });
  input.addEventListener('change', function(){ if (input.files.length) picked.textContent = '📄 '+listNames(input.files); });
  ['dragenter','dragover'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.background='#e3f3f5'; }); });
  ['dragleave','dragend'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.background='#f3fafb'; }); });
  zone.addEventListener('drop', function(e){ e.preventDefault(); e.stopPropagation(); zone.style.background='#f3fafb'; var f=e.dataTransfer&&e.dataTransfer.files; if(f&&f.length){ picked.textContent='📄 '+listNames(f); doUpload(f); } });
  ['dragover','drop'].forEach(function(ev){ document.addEventListener(ev, function(e){ if (e.target!==zone && !zone.contains(e.target)) e.preventDefault(); }); });
  form.addEventListener('submit', function(ev){ ev.preventDefault(); doUpload(input.files); });
})();
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
