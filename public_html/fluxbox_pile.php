<?php
declare(strict_types=1);

/**
 * FLUXBOX PILE — Pile de cartes à valider (carte unique + 3 boutons)
 *
 * Affiche UNE carte à la fois, 3 boutons :
 *   ✅ Tout valider     (Espace/Entrée)
 *   ✏️ Ajuster          (←)
 *   ⏭ Plus tard         (→)
 *
 * Filtre optionnel : ?source=telechargements|mails|all (défaut all)
 *   - telechargements → fluxbox_documents.source_type IN ('manual','watcher','zip','photo')
 *   - mails           → fluxbox_documents.source_type IN ('email','webhook')
 *   - all             → toutes sources
 *
 * Spec : project_fluxbox_module (validé EMERY 2026-05-13).
 * Home FluxBox = /fluxbox.php (dashboard 3 zones), cette page = traitement carte par carte.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fluxbox_functions.php';
require_once __DIR__ . '/inc/fluxbox_ia_cascade.php';
require_once __DIR__ . '/inc/fluxbox_va_orchestrator.php';
require_once __DIR__ . '/inc/fluxbox_resolution.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Filtre source
$sourceParam = (string)($_GET['source'] ?? 'all');
$sourceMap = [
    'telechargements' => ['manual','watcher','zip','photo'],
    'mails'           => ['email','webhook'],
    'all'             => null,
];
$sourceFilter = $sourceMap[$sourceParam] ?? null;

$sourceLabel = [
    'telechargements' => '📥 Téléchargements',
    'mails'           => '📧 Mails',
    'all'             => '🃏 Toutes sources',
][$sourceParam] ?? '🃏 Toutes sources';

// Stats bandeau + carte courante (filtrée si source précisée)
$stats = fluxbox_carte_stats($pdo);

// Si ?carte=ID est passé, on charge cette carte précise (click depuis la liste)
$forcedCarteId = (int)($_GET['carte'] ?? 0);
$carte = null;
if ($forcedCarteId > 0) {
    try {
        $tid = (int)(ged_current_tenant_id() ?? 0);
        $st = $pdo->prepare("
            SELECT * FROM fluxbox_cartes
            WHERE id = ? AND tenant_id = ? AND statut IN ('pending','in_progress')
            LIMIT 1
        ");
        $st->execute([$forcedCarteId, $tid]);
        $carte = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}
if (!$carte) {
    $carte = fluxbox_carte_get_next($pdo, $sourceFilter);
}

// Charge le fichier_nom + mime du document attaché à la carte courante (pour affichage)
$docFichierNom = '';
$docMimeType   = '';
if ($carte && !empty($carte['document_id'])) {
    try {
        $st = $pdo->prepare("SELECT fichier_nom, mime_type FROM fluxbox_documents WHERE id = ? LIMIT 1");
        $st->execute([(int)$carte['document_id']]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $docFichierNom = (string)($r['fichier_nom'] ?? '');
            $docMimeType   = (string)($r['mime_type']   ?? '');
        }
    } catch (Throwable) {}
}

// Liste des cartes pending suivantes (pour sélection multiple)
$pileList = [];
try {
    $tenantId = (int)(ged_current_tenant_id() ?? 0);
    if ($tenantId > 0) {
        $sql = "SELECT c.id, c.titre, c.sous_titre, c.priorite, c.confiance_ia, c.created_at,
                       d.source_type
                FROM fluxbox_cartes c
                LEFT JOIN fluxbox_documents d ON d.id = c.document_id
                WHERE c.tenant_id = ? AND c.statut = 'pending'";
        $params = [$tenantId];
        if (is_array($sourceFilter) && count($sourceFilter) > 0) {
            $placeholders = implode(',', array_fill(0, count($sourceFilter), '?'));
            $sql .= " AND (d.source_type IN ($placeholders) OR c.document_id IS NULL)";
            $params = array_merge($params, $sourceFilter);
        }
        $sql .= " ORDER BY FIELD(c.priorite,'urgent','important','normal','faible'), c.created_at ASC
                  LIMIT 100";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $pileList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable) {}

// Count cartes IA-prêtes (confiance >= 60 — l'IA propose un classement utilisable)
$iaReadyCount = 0;
foreach ($pileList as $p) {
    if ($p['confiance_ia'] !== null && (float)$p['confiance_ia'] >= 60) $iaReadyCount++;
}
$totalPending = (int)($stats['pending'] ?? 0);

// Contexte user (société/agence préremplies)
$ctx = ged_v3_get_user_context($pdo);

// Décode la proposition IA si présente
$proposition = [];
$classement  = ['n1'=>'','n2'=>'','n3'=>'','n4'=>'','n5'=>'','n6'=>''];
$actions     = [];
$priorite    = 'normal';
$confiance   = null;

if ($carte) {
    if (!empty($carte['proposition_json'])) {
        $proposition = json_decode((string)$carte['proposition_json'], true) ?: [];
    }
    $classement = array_merge($classement, $proposition['classement'] ?? []);
    $actions    = $carte['actions_ia'] ?? [];
    $priorite   = (string)($carte['priorite'] ?? 'normal');
    $confiance  = $carte['confiance_ia'] !== null ? (float)$carte['confiance_ia'] : null;
}

// Résout société/agence cibles depuis la proposition (avec fallback ctx user)
// Défensif : la colonne `code` n'existe pas sur tous les environnements — on essaie d'abord
// avec, et on retombe sur `nom` seul si la requête échoue.
$targetSocieteLabel = $ctx['societe_label'] ?: '—';
$targetAgenceLabel  = $ctx['agence_label']  ?: '—';

$resolveLabel = function (PDO $pdo, string $table, int $id): ?string {
    if ($id <= 0) return null;
    // Tentative avec colonne `code` (si présente)
    try {
        $st = $pdo->prepare("SELECT code, nom FROM `$table` WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return (!empty($row['code']) ? $row['code'] . ' — ' : '') . (string)$row['nom'];
    } catch (Throwable $e) {
        // Colonne code absente → fallback
    }
    try {
        $st = $pdo->prepare("SELECT nom FROM `$table` WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return (string)$row['nom'];
    } catch (Throwable $e) {}
    return null;
};

if ($carte && isset($proposition['target_societe_id'])) {
    $tSocId = (int)$proposition['target_societe_id'];
    if ($tSocId > 0) {
        $label = $resolveLabel($pdo, 'societes', $tSocId);
        if ($label !== null) $targetSocieteLabel = $label;
    }
}
if ($carte && array_key_exists('target_agence_id', $proposition)) {
    $tAgeId = (int)$proposition['target_agence_id'];
    if ($tAgeId > 0) {
        $label = $resolveLabel($pdo, 'agences', $tAgeId);
        if ($label !== null) $targetAgenceLabel = $label;
    } else {
        // 0 explicite = "Société uniquement"
        $targetAgenceLabel = '🏢 Société uniquement (pas d\'agence spécifique)';
    }
}

// Preview nom canonique live — utilise target_societe_id/target_agence_id du modal en priorité
$previewName = '';
if ($carte) {
    // ── Résolution TOUS segments via le glossaire officiel (ged_codes_glossaire) ──
    // soc/age/user/n1/n2/n3-immeuble = codes courts du glossaire, n4/n5 = codes directs.
    // user + upload_date depuis le contexte de la carte.
    $carteCtx = [
        'created_by' => (int)($carte['created_by'] ?? 0),
        'created_at' => (string)($carte['created_at'] ?? ''),
    ];
    $parts = fluxbox_resolve_canonical_parts($pdo, $classement, $proposition, $carteCtx);

    // Fallback société/agence depuis contexte user si pas de matching IA
    if ($parts['soc'] === '') $parts['soc'] = $ctx['societe_code'] ?: 'SOC';
    if ($parts['age'] === '') {
        // "Société uniquement" si target_agence_id explicitement à 0 (racine OU dans classement)
        $explicitNoAgence = (
            (array_key_exists('target_agence_id', $proposition) && (int)($proposition['target_agence_id'] ?? -1) === 0)
            || (array_key_exists('target_agence_id', $classement) && (int)($classement['target_agence_id'] ?? -1) === 0)
        );
        $parts['age'] = $explicitNoAgence
            ? 'SOC' // explicite "société uniquement"
            : ($ctx['agence_code'] ?: 'AGE');
    }
    // Fallback date : annee → today
    if ($parts['date'] === null && !empty($classement['annee'])) {
        $parts['date'] = $classement['annee'] . '-01-01';
    }

    $previewName = ged_v3_preview($parts);
}

// Plafond IA
$societeId = (int)($ctx['societe_id'] ?? 0);
$plafond   = $societeId > 0 ? fluxbox_ia_get_plafond_state($societeId, $pdo)
                            : ['plafond_eur'=>75,'consomme_eur'=>0,'pct'=>0,'mode_degrade'=>false];

$pageTitle    = 'FluxBox · La pile';
$pageSubtitle = $sourceLabel;
$layoutSidebar = 'sidebar_fluxbox';

// ── Topbar FluxBox : chips stats (même style que fluxbox.php) ──────────
$todayValidated  = (int)($stats['today_validated'] ?? 0);
$doublonsBlocked = (int)($stats['doublons_blocked'] ?? 0);
$pendingTotal    = (int)($stats['pending'] ?? 0);
$urgentTotal     = (int)($stats['urgent'] ?? 0);
$topbarActions = '<div class="fbx-topstats">'
    . '<a class="fbx-topchip is-pending" href="./fluxbox_pile.php" title="Ouvrir la pile">'
        . '🃏 <span class="lbl">À traiter</span> <strong>' . $pendingTotal . '</strong>'
      . '</a>'
    . '<a class="fbx-topchip is-today" href="./fluxbox_pile.php?statut=validated_today" title="Voir les cartes validées aujourd\'hui">'
        . '✅ <span class="lbl">Aujourd\'hui</span> <strong>' . $todayValidated . '</strong>'
      . '</a>'
    . ($urgentTotal > 0
        ? '<a class="fbx-topchip is-urgent" href="./fluxbox_pile.php?priorite=urgent" title="Voir les urgentes">'
            . '🔴 <span class="lbl">Urgentes</span> <strong>' . $urgentTotal . '</strong>'
          . '</a>'
        : ''
      )
    . ($doublonsBlocked > 0
        ? '<span class="fbx-topchip is-doublon" title="Doublons bloqués">'
            . '🛡️ <span class="lbl">Doublons</span> <strong>' . $doublonsBlocked . '</strong>'
          . '</span>'
        : ''
      )
  . '</div>';

$extraCss = '<style>
.fbx-topstats { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.fbx-topchip {
  display:inline-flex; align-items:center; gap:6px;
  height: 32px;
  padding: 0 10px;
  border-radius: 999px;
  background: #ffffff;
  color: #6a6660;
  text-decoration: none;
  box-shadow: 2px 2px 6px rgba(196,192,186,0.45), -2px -2px 6px #fff;
  border: 1px solid rgba(212,208,202,0.7);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}
.fbx-topchip .lbl { font-weight: 600; color:#8a8680; }
.fbx-topchip strong { font-weight: 900; color:#243B5C; }
.fbx-topchip:hover { box-shadow: 3px 3px 10px rgba(196,192,186,0.55), -2px -2px 6px #fff; transform: translateY(-1px); }
.fbx-topchip.is-today strong { color:#4a6038; }
.fbx-topchip.is-urgent strong { color:#8a5040; }
.fbx-topchip.is-doublon strong { color:#5a6878; }
</style>';

require_once __DIR__ . '/inc/agency_layout_top.php';

// Helpers couleurs
$prioriteClass = [
    'urgent'    => 'fbx-urgent',
    'important' => 'fbx-important',
    'normal'    => 'fbx-normal',
    'faible'    => 'fbx-faible',
][$priorite] ?? 'fbx-normal';

$prioriteIcon = [
    'urgent'    => '🔴',
    'important' => '🟠',
    'normal'    => '🟢',
    'faible'    => '⚪',
][$priorite] ?? '🟢';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(function_exists('asset_url') ? asset_url('/css/fluxbox.css') : '/css/fluxbox.css') ?>?v=<?= @filemtime(__DIR__ . '/css/fluxbox.css') ?: time() ?>">
<style>
/* Layout 2 colonnes : carte courante à gauche, liste cartes à droite (2026-05-17) */
/* On override le max-width 760px de .fbx-wrap pour utiliser toute la largeur disponible */
.fbx-wrap:has(.fbx-pile-layout) { max-width: none !important; }
.fbx-pile-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(360px, 1fr);
  gap: 20px;
  align-items: start;
  width: 100%;
}
.fbx-pile-layout .fbx-card-shell { min-width: 0; }
.fbx-pile-layout .fbx-batch {
  position: sticky; top: 8px;
  max-height: calc(100vh - 90px);
  overflow-y: auto;
  margin-top: 0 !important;
}
.fbx-pile-layout .fbx-batch-list { max-height: none; }
@media (max-width: 1100px) {
  .fbx-pile-layout { grid-template-columns: 1fr; }
  .fbx-pile-layout .fbx-batch { position: static; max-height: none; }
}
/* Item batch cliquable → curseur pointer + hover surligné */
.fbx-batch-item .fbx-batch-row { cursor: pointer; }
.fbx-batch-item.fbx-batch-clickable .fbx-batch-titre { cursor: pointer; }
.fbx-batch-item .fbx-batch-promote-btn {
  background: none; border: none; color: #6B33B5; cursor: pointer;
  font-size: 16px; padding: 2px 6px; border-radius: 4px;
  margin-left: auto;
}
.fbx-batch-item .fbx-batch-promote-btn:hover { background: rgba(107, 51, 181, 0.1); }

/* Modal Ajuster avec viewer doc à droite (2026-05-17) :
   Split 2 colonnes : formulaire 1fr / viewer 2fr → viewer = 2/3 du modal */
.fbx-modal-adjust-with-viewer {
  max-width: 1600px !important;
  width: 96% !important;
}
.fbx-modal-split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 0;
  max-height: 92vh;
}
.fbx-modal-form-left {
  overflow-y: auto;
  border-right: 1px solid #e2e8f0;
  max-height: 92vh;
}
.fbx-modal-viewer-right {
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  max-height: 92vh;
}
.fbx-modal-viewer-head {
  padding: 12px 18px;
  border-bottom: 1px solid #e2e8f0;
  font-size: 13px;
  font-weight: 600;
  color: #243B5C;
  flex-shrink: 0;
}
.fbx-modal-viewer-name {
  font-weight: 400;
  color: #64748b;
  margin-left: 8px;
  font-family: 'DM Mono', monospace;
  font-size: 11px;
}
#fbx-adjust-viewer-iframe {
  flex: 1;
  width: 100%;
  border: none;
  background: #fff;
}
@media (max-width: 1100px) {
  .fbx-modal-split { grid-template-columns: 1fr; }
  .fbx-modal-viewer-right { max-height: 400px; }
}

/* Modal Ajuster — état visuel des champs (2026-05-17) :
   • fbx-auto-filled = champ rempli par l'IA → fond violet pâle (confirme la valeur détectée)
   • fbx-empty-fillable = champ vide mais user peut le remplir → fond or clair (incite à remplir) */
.fbx-auto-filled {
  background: linear-gradient(180deg, #f5f0fb 0%, #ece3f7 100%) !important;
  border: 1px solid #9F7BCC !important;
  color: #3D1A6E !important;
  font-weight: 600 !important;
}
.fbx-empty-fillable {
  background: #fefae8 !important;  /* or très clair */
  border: 1px dashed #d4a047 !important;
}
.fbx-empty-fillable::placeholder { color: #b8862e; opacity: 0.7; }

/* Modal Ajuster : société + agence sur la même ligne (2 colonnes) */
.fbx-form-row-2col { display: flex; gap: 12px; margin-bottom: 10px; }
.fbx-form-row-2col .fbx-form-half { flex: 1; display: flex; flex-direction: column; gap: 4px; }
.fbx-form-row-2col select {
  height: 36px; padding: 4px 10px; border: 1px solid #d4d7de; border-radius: 8px;
  background: #fff; font-size: 13px; font-family: inherit;
}
.fbx-form-row-2col label { font-size: 12px; font-weight: 600; color: #475569; }

/* Métiers compacts dans Ajuster — 7 boutons par ligne (2026-05-17) */
.fbx-btn-grid-compact { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.fbx-btn-grid-compact .fbx-choice-btn {
  padding: 6px 4px !important; font-size: 10px !important;
  min-height: 52px !important;
}
.fbx-btn-grid-compact .fbx-choice-icon { font-size: 16px !important; }
.fbx-btn-grid-compact .fbx-choice-label { font-size: 9px !important; line-height: 1.2; }
@media (max-width: 1100px) {
  .fbx-btn-grid-compact { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}

/* Bouton lien Agents IA (admin only) dans le modal Ajuster */
.fbx-form-agents-link { margin: 16px 0 8px; padding-top: 14px; border-top: 1px dashed #cbd5e1; }
.fbx-btn-agents-link {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: 10px;
  background: linear-gradient(180deg, #fef3c7 0%, #fde68a 100%);
  border: 1px solid #d97706;
  color: #92400e; font-weight: 600; font-size: 13px;
  text-decoration: none;
  transition: all .15s ease;
}
.fbx-btn-agents-link:hover {
  background: linear-gradient(180deg, #fde68a 0%, #fcd34d 100%);
  transform: translateY(-1px);
}
.fbx-btn-agents-link .fbx-form-hint { font-weight: 400; opacity: 0.85; font-size: 11px; margin-left: auto; }

/* Champ recherche N3 (immeuble/propriétaire/bien) — au-dessus du grid */
.fbx-search-n3 {
  width: 100%;
  height: 34px;
  padding: 6px 12px;
  margin-bottom: 8px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
  outline: none;
  transition: border-color .15s;
}
.fbx-search-n3:focus {
  border-color: #6B33B5;
  box-shadow: 0 0 0 2px rgba(107, 51, 181, 0.15);
}
/* Bouton masqué par le filtre recherche N3 */
.fbx-choice-btn.fbx-hidden-by-search { display: none !important; }
/* Bouton "virtuel" issu d'une recherche BDD immeubles (pas encore seedé en glossaire) */
.fbx-choice-btn.fbx-search-result {
  background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%) !important;
  border-color: #0284c7 !important;
  color: #075985 !important;
}

/* Bouton + admin pour ajouter une rubrique — gris clair, petit, centré */
.fbx-btn-add-ref {
  background: #f1f3f5 !important;
  color: #6c757d !important;
  border: 1px dashed #ced4da !important;
  font-weight: 500 !important;
  padding: 4px 10px !important;
  min-height: 32px !important;
  font-size: 11px !important;
  align-self: center !important;
  justify-self: center !important;
  width: auto !important;
  max-width: 100px;
  margin: 4px auto !important;
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.fbx-btn-add-ref .fbx-choice-icon { font-size: 13px !important; }
.fbx-btn-add-ref .fbx-choice-label { font-size: 10px !important; }
.fbx-btn-add-ref:hover {
  background: #e9ecef !important;
  border-style: solid !important;
  color: #495057 !important;
}
</style>

<div class="fbx-wrap">

  <!-- ─── Fil d'Ariane retour home ────────────────────────────── -->
  <div class="fbx-breadcrumb">
    <a href="./fluxbox.php">← Retour à FluxBox</a>
    <?php if ($sourceParam !== 'all'): ?>
      <span class="fbx-breadcrumb-source"><?= $h($sourceLabel) ?></span>
    <?php endif; ?>
  </div>

  <!-- ─── Bandeau dashboard ─────────────────────────────────────── -->
  <div class="fbx-banner">
    <div class="fbx-banner-stats">
      <span class="fbx-stat"><strong><?= (int)$stats['pending'] ?></strong> cartes en attente</span>
      <?php if ((int)$stats['urgent'] > 0): ?>
        <span class="fbx-stat fbx-stat-urgent">🔴 <strong><?= (int)$stats['urgent'] ?></strong> urgentes</span>
      <?php endif; ?>
      <span class="fbx-stat">⏰ <strong><?= (int)$stats['later'] ?></strong> reportées</span>
      <span class="fbx-stat">✅ <strong><?= (int)$stats['today_validated'] ?></strong> validées aujourd'hui</span>
      <span class="fbx-stat fbx-stat-shield">🛡️ <strong><?= (int)$stats['doublons_blocked'] ?></strong> doublons bloqués</span>
    </div>
    <div class="fbx-banner-ia">
      <span class="fbx-ia-label">IA ce mois</span>
      <span class="fbx-ia-amount"><strong><?= number_format((float)$plafond['consomme_eur'], 2, ',', ' ') ?> €</strong> / <?= number_format((float)$plafond['plafond_eur'], 0, ',', ' ') ?> €</span>
      <div class="fbx-ia-bar"><div class="fbx-ia-bar-fill" style="width:<?= min(100, (float)$plafond['pct']) ?>%"></div></div>
    </div>
  </div>

  <!-- ─── Bandeau mode batch : sélection multiple + validation en masse ───── -->
  <?php if ($totalPending > 1): ?>
  <div class="fbx-mode-batch">
    <div class="fbx-mode-batch-msg">
      📚 <strong><?= $totalPending ?></strong> cartes à traiter — au lieu d'une par une, fais une sélection :
    </div>
    <div class="fbx-mode-batch-actions">
      <a href="#fbx-batch" class="fbx-btn fbx-btn-secondary">📋 Sélection multiple</a>
      <button type="button" id="fbx-mass-validate" class="fbx-btn fbx-btn-validate"
              title="Valide toutes les cartes pending de cette source en une fois (les IA-prêtes ≥ 60%)">
        ⚡ Tout valider en masse (<?= $totalPending ?>)
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ─── Carte courante ────────────────────────────────────────── -->
  <?php if (!$carte): ?>

    <div class="fbx-empty">
      <div class="fbx-empty-icon">🎉</div>
      <h2>Pile vide</h2>
      <p>Plus aucune carte à traiter pour le moment. Profitez-en.</p>
      <div class="fbx-empty-actions">
        <a href="/fluxbox_import.php" class="fbx-btn fbx-btn-secondary">📥 Importer des documents</a>
      </div>
    </div>

  <?php else: ?>

    <div class="fbx-pile-layout">
    <div class="fbx-card-shell" data-carte-id="<?= (int)$carte['id'] ?>" data-doc-filename="<?= $h($docFichierNom) ?>" data-doc-mime="<?= $h($docMimeType) ?>" id="fbx-card">
      <div class="fbx-card <?= $h($prioriteClass) ?>">

        <!-- Header carte : priorité + titre -->
        <?php
          $userLabelOnCard = trim((string)($proposition['user_label'] ?? ''));
          $titreCarte = trim((string)$carte['titre']);
          // Si le user a saisi un libellé custom, on l'affiche en premier (titre principal)
          $titrePrincipal = $userLabelOnCard !== '' ? $userLabelOnCard : $titreCarte;
        ?>
        <div class="fbx-card-head">
          <span class="fbx-priorite" title="<?= $h($carte['priorite_reason'] ?? '') ?>">
            <?= $prioriteIcon ?>
            <strong><?= $h(strtoupper($priorite)) ?></strong>
          </span>
          <h2 class="fbx-card-titre"><?= $h($titrePrincipal) ?></h2>
          <?php if ($docFichierNom !== '' && $docFichierNom !== $titrePrincipal): ?>
            <div class="fbx-card-filename">📄 <?= $h($docFichierNom) ?></div>
          <?php endif; ?>
          <?php if ($userLabelOnCard !== '' && $titreCarte !== $userLabelOnCard && $titreCarte !== $docFichierNom): ?>
            <div class="fbx-card-titre-auto">🤖 Titre auto : <?= $h($titreCarte) ?></div>
          <?php endif; ?>
          <?php if (!empty($carte['sous_titre'])): ?>
            <div class="fbx-card-sous-titre"><?= $h($carte['sous_titre']) ?></div>
          <?php endif; ?>
        </div>

        <!-- Aperçu document (si attaché) -->
        <?php if (!empty($carte['document_id'])): ?>
          <div class="fbx-card-preview">
            <div class="fbx-card-preview-placeholder">📄 Aperçu document (chargement à la demande)</div>
          </div>
        <?php endif; ?>

        <!-- Propositions IA -->
        <div class="fbx-card-propositions">
          <div class="fbx-card-propositions-title">💡 L'IA a préparé :</div>
          <?php if (!empty($actions)): ?>
            <ul class="fbx-propositions-list">
              <?php foreach ($actions as $a): ?>
                <li>
                  <span class="fbx-prop-check">✓</span>
                  <?= $h($a['action_label']) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="fbx-prop-empty">Aucune proposition automatique. Cliquez sur <em>Ajuster</em> pour classer manuellement.</p>
          <?php endif; ?>

          <?php if ($confiance !== null): ?>
            <div class="fbx-confiance">
              <?php
                $confianceTxt = $confiance >= 85 ? "L'IA est sûre"
                              : ($confiance >= 60 ? "L'IA hésite un peu — vérifiez avant de valider"
                                                  : "L'IA est incertaine — votre avis est nécessaire");
                $confianceClass = $confiance >= 85 ? 'fbx-conf-ok'
                                 : ($confiance >= 60 ? 'fbx-conf-mid' : 'fbx-conf-low');
              ?>
              <span class="fbx-conf-badge <?= $confianceClass ?>"><?= $h($confianceTxt) ?></span>
            </div>
          <?php endif; ?>

          <?php if (!empty($previewName)): ?>
            <div class="fbx-preview-name" title="Nom de fichier généré automatiquement">
              📋 <code><?= $h($previewName) ?>.<?= $h(pathinfo((string)($carte['titre'] ?? 'doc'), PATHINFO_EXTENSION) ?: 'pdf') ?></code>
            </div>
          <?php endif; ?>
        </div>

        <!-- ─── Correctif 5 — Proposition inline, champ par champ (vert/jaune/rouge + source) ─── -->
        <?php
          // « Réviser = cette carte inline ». Résolution fiable (Correctifs 1→4) rendue ici.
          try {
              $resolution = fluxbox_resoudre_carte((int)$carte['id'], $pdo);
              if (!empty($resolution)) {
                  // Persiste resolution_json + type_document (lecture → trace requêtable)
                  fluxbox_persister_resolution($pdo, (int)$carte['id'], $resolution);
                  $tid = (int)(ged_current_tenant_id() ?? 0);
                  if ($tid > 0 && !empty($resolution['_ancres'])) {
                      fluxbox_persister_ancres($pdo, $tid, (int)$carte['id'], $resolution['_ancres']);
                  }
                  echo fluxbox_render_proposition_inline($resolution, (int)$carte['id']);
              }
          } catch (Throwable $e) { /* affichage best-effort, ne bloque pas la carte */ }
        ?>

        <!-- Boutons d'action -->
        <div class="fbx-card-actions">
          <button type="button" class="fbx-btn fbx-btn-validate" data-action="validate"
                  title="Tout valider (Espace ou Entrée)">
            ✅ <strong>Tout valider</strong>
          </button>
          <button type="button" class="fbx-btn fbx-btn-adjust" data-action="adjust"
                  title="Ouvrir détails (←)">
            ✏️ Ajuster
          </button>
          <?php if (!empty($carte['document_id'])): ?>
          <button type="button" class="fbx-btn fbx-btn-view" data-action="view"
                  title="Visualiser le document">
            👁️ Voir
          </button>
          <?php endif; ?>
          <button type="button" class="fbx-btn fbx-btn-later" data-action="later" data-later="+1 day"
                  title="Reporter à demain (→)">
            ⏭ Plus tard
          </button>
          <button type="button" class="fbx-btn fbx-btn-delete" data-action="delete"
                  title="Supprimer définitivement le fichier (action irréversible)">
            🗑️ Supprimer
          </button>
        </div>

        <!-- Footer carte -->
        <div class="fbx-card-foot">
          <span><?= max(0, (int)$stats['pending'] - 1) ?> autres cartes en attente</span>
          <?php if ((int)$stats['pending'] > 1): ?>
            <span class="fbx-card-foot-time">⏱ ~<?= max(1, (int)round(((int)$stats['pending'] - 1) * 0.5)) ?> min</span>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- Modal Ajuster (détails techniques — toutes les saisies du modal upload sont reprises) -->
    <?php
      $targetDateProp        = (string)($proposition['target_date']     ?? ($classement['date'] ?? ''));
      $targetDateSrc         = (string)($proposition['target_date_source'] ?? 'unknown');
      $targetDateConf        = (int)($proposition['target_date_confidence'] ?? 0);
      // Libellé personnalisé : vide par défaut (règle métier 2026-05-17 — toutes les infos sont
      // déjà dans les segments classés, le libellé personnalisé sert UNIQUEMENT si user veut
      // un texte additionnel libre. Sinon il reste vide).
      $userLabelProp         = trim((string)($proposition['user_label'] ?? ''));
      $userCommentProp       = (string)($proposition['user_comment']    ?? '');
      // Pré-remplit le champ entity_instance avec la valeur qui sera utilisée dans le nom canonique.
      // Priorité : override user > prefill bien_id BDD > IA matching adresse > immeuble matché > Haiku brut
      $entityInstanceProp = trim((string)(
          $proposition['entity_instance']        // override user précédent
          ?? $classement['immeuble_ref_bdd']     // ce qui sera dans le canonical (ex "3005")
          ?? $classement['immeuble_nom_bdd']     // nom BDD lisible
          ?? $classement['entity_instance']      // détection Haiku brute
          ?? ''
      ));

      // ─── [2026-05-25] Auto-match BIEN depuis prefill OU adresse IA ──
      // Objectif ultime user : l'entité du modal doit toujours être pré-remplie.
      // Source 1 : proposition.bien_id (vient de bien_documents_list ou prefill modal)
      // Source 2 : source_meta.bien_id du document (idem si stocké à l'ingest)
      // Source 3 : em_match_bien() via adresse extraite par IA (ia_extract_cache)
      require_once __DIR__ . '/inc/entity_matcher.php';
      $matchedBien = null;       // ['id', 'designation', 'adresse_1', 'ville', 'score', 'source']
      $autoMatchSource = null;

      // Source 1 : bien_id dans proposition_json
      $propBienId = (int)($proposition['bien_id'] ?? 0);

      // Source 2 : source_meta.bien_id
      if (!$propBienId) {
          try {
              $stDocMeta = $pdo->prepare("SELECT source_meta FROM fluxbox_documents WHERE id = ? LIMIT 1");
              $stDocMeta->execute([(int)($carte['document_id'] ?? 0)]);
              $sm = json_decode((string)$stDocMeta->fetchColumn(), true);
              if (is_array($sm) && !empty($sm['bien_id'])) $propBienId = (int)$sm['bien_id'];
          } catch (Throwable) {}
      }

      // Source 3 : matching adresse IA (si pas de bien_id)
      if (!$propBienId) {
          try {
              $stDocHash = $pdo->prepare("SELECT hash_sha256 FROM fluxbox_documents WHERE id = ? LIMIT 1");
              $stDocHash->execute([(int)($carte['document_id'] ?? 0)]);
              $hashDoc = (string)$stDocHash->fetchColumn();
              if ($hashDoc !== '') {
                  $stCache = $pdo->prepare("SELECT response_json, confidence FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
                  $stCache->execute([$hashDoc]);
                  $cache = $stCache->fetch(PDO::FETCH_ASSOC);
                  if ($cache && !empty($cache['response_json'])) {
                      $iaExtract = json_decode((string)$cache['response_json'], true) ?: [];
                      $iaAdr = (string)($iaExtract['adresse_bien'] ?? $iaExtract['adresse'] ?? '');
                      $iaCp  = (string)($iaExtract['code_postal']  ?? '');
                      $iaCity = (string)($iaExtract['ville']       ?? '');
                      if ($iaAdr !== '' || $iaCity !== '') {
                          $matchResult = em_match_bien($pdo, [
                              'adresse'      => $iaAdr,
                              'code_postal'  => $iaCp,
                              'ville'        => $iaCity,
                              'numero_mandat'=> (string)($iaExtract['numero_mandat'] ?? ''),
                          ]);
                          if (!empty($matchResult['found']) && !empty($matchResult['best']) && ((int)($matchResult['confidence'] ?? 0) >= 70)) {
                              $matchedBien = [
                                  'id'          => (int)$matchResult['best']['id'],
                                  'designation' => (string)($matchResult['best']['designation'] ?? $matchResult['best']['adresse_1'] ?? ''),
                                  'adresse_1'   => (string)($matchResult['best']['adresse_1'] ?? ''),
                                  'ville'       => (string)($matchResult['best']['ville'] ?? ''),
                                  'score'       => (int)($matchResult['confidence'] ?? 0),
                                  'source'      => 'IA matching adresse',
                                  'ia_adresse'  => $iaAdr,
                              ];
                              $propBienId = $matchedBien['id'];
                              $autoMatchSource = 'ia_address';
                          }
                      }
                  }
              }
          } catch (Throwable) {}
      } else {
          $autoMatchSource = isset($proposition['bien_id']) ? 'prefill_proposition' : 'doc_source_meta';
      }

      // Lookup designation depuis bien_id
      if ($propBienId > 0 && !$matchedBien) {
          try {
              $stB = $pdo->prepare("SELECT id, designation, adresse_1, code_postal, ville FROM biens WHERE id = ? LIMIT 1");
              $stB->execute([$propBienId]);
              $b = $stB->fetch(PDO::FETCH_ASSOC);
              if ($b) {
                  $matchedBien = [
                      'id'          => (int)$b['id'],
                      'designation' => (string)($b['designation'] ?: $b['adresse_1'] ?: ('Bien #' . $b['id'])),
                      'adresse_1'   => (string)$b['adresse_1'],
                      'ville'       => (string)$b['ville'],
                      'score'       => 100,
                      'source'      => $autoMatchSource ?: 'prefill',
                  ];
              }
          } catch (Throwable) {}
      }

      // Si bien matché ET entityInstance encore vide → utilise le bien pour pré-remplir
      if ($matchedBien && $entityInstanceProp === '') {
          $entityInstanceProp = $matchedBien['designation'] ?: ('Bien #' . $matchedBien['id']);
      }

      // Fix B4 (2026-05-26) : extraction heuristique depuis le nom de fichier en dernier recours
      // Ex: "IBAN EVEREST - CAISSE EPARGNE.pdf" → entity_instance = "EVEREST" (mot le plus distinctif)
      if ($entityInstanceProp === '') {
          try {
              $stDocFn = $pdo->prepare("SELECT fichier_nom FROM fluxbox_documents WHERE id = ? LIMIT 1");
              $stDocFn->execute([(int)($carte['document_id'] ?? 0)]);
              $fileNameRaw = (string)$stDocFn->fetchColumn();
              if ($fileNameRaw !== '') {
                  // Strip extension + mots techniques courants
                  $base = pathinfo($fileNameRaw, PATHINFO_FILENAME);
                  $base = preg_replace('/[_\-\s\.]+/', ' ', $base);
                  $stopwords = ['IBAN','RIB','DPE','ERNT','ERNMT','ERP','BAIL','MANDAT','ACTE','FACTURE',
                                'RELEVE','QUITTANCE','LOYER','AVIS','ECHEANCE','CAUTION','GARANT',
                                'CAISSE','EPARGNE','BANQUE','CREDIT','AGRICOLE','LCL','BNP','SG',
                                'COPIE','SCAN','FINAL','DEFINITIF','SIGNED','SIGNE','PDF','DOC',
                                'PROPRIO','PROPRIETAIRE','LOCATAIRE','TIERS',
                                'ET','DE','DU','LA','LE','LES','DES','UN','UNE','SUR','POUR','CHEZ'];
                  $words = preg_split('/\s+/', strtoupper($base)) ?: [];
                  $candidates = [];
                  foreach ($words as $w) {
                      $w = trim($w);
                      if (mb_strlen($w) < 3) continue;
                      if (in_array($w, $stopwords, true)) continue;
                      if (preg_match('/^\d+$/', $w)) continue; // numéros purs
                      $candidates[] = $w;
                  }
                  if (!empty($candidates)) {
                      // Garde les 2 premiers mots distinctifs (ex: "EVEREST" ou "DUPONT PIERRE")
                      $entityInstanceProp = implode(' ', array_slice($candidates, 0, 2));
                  }
              }
          } catch (Throwable) {}
      }
      // Label humain de la source de date
      $dateSrcLabels = [
          'user_input'     => '✏️ Saisie manuelle',
          'filename_regex' => '📄 Détectée dans le nom de fichier',
          'pdf_metadata'   => '🔍 Métadonnées du PDF',
          'msg_sent_date'  => '📧 Date d\'envoi du mail',
          'ocr_content'    => '👁️ Texte OCR du document',
          'file_mtime'     => '🕐 Date de modification du fichier (à vérifier)',
          'none'           => '⚠️ Non détectée — à renseigner',
          'unknown'        => '',
      ];
      $dateSrcLabel = $dateSrcLabels[$targetDateSrc] ?? '';
    ?>
    <dialog id="fbx-modal-adjust" class="fbx-modal fbx-modal-adjust-with-viewer">
      <div class="fbx-modal-split">
      <form method="dialog" class="fbx-modal-form fbx-modal-form-left">
        <h3>Ajuster le classement</h3>

        <?php if ($userCommentProp !== ''): ?>
        <div class="fbx-form-info">
          💬 <strong>Commentaire upload :</strong> <?= $h($userCommentProp) ?>
        </div>
        <?php endif; ?>

        <?php
          // Charge listes société/agence pour selects
          try {
              $stSoc = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom");
              $listeSoc = $stSoc->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable) { $listeSoc = []; }
          try {
              $stAge = $pdo->query("SELECT id, nom_agence, id_societe FROM agences ORDER BY nom_agence");
              $listeAge = $stAge->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable) { $listeAge = []; }
          $currentSocId = (int)($proposition['target_societe_id'] ?? $classement['target_societe_id'] ?? 0);
          $currentAgeId = (int)($proposition['target_agence_id']  ?? $classement['target_agence_id']  ?? 0);
        ?>
        <div class="fbx-form-row fbx-form-row-2col">
          <div class="fbx-form-half">
            <label>🏢 Société</label>
            <select name="target_societe_id" id="fbx-adjust-soc"
                    class="<?= $currentSocId > 0 ? 'fbx-auto-filled' : 'fbx-empty-fillable' ?>">
              <option value="">— Choisir —</option>
              <?php foreach ($listeSoc as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $currentSocId === (int)$s['id'] ? 'selected' : '' ?>><?= $h($s['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fbx-form-half">
            <label>🏬 Agence</label>
            <select name="target_agence_id" id="fbx-adjust-age"
                    class="<?= $currentAgeId > 0 ? 'fbx-auto-filled' : 'fbx-empty-fillable' ?>">
              <option value="">— Société uniquement —</option>
              <?php foreach ($listeAge as $a): ?>
                <option value="<?= (int)$a['id'] ?>" data-societe="<?= (int)$a['id_societe'] ?>" <?= $currentAgeId === (int)$a['id'] ? 'selected' : '' ?>><?= $h($a['nom_agence']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <!-- Cascade GED (mêmes grilles boutons que le modal upload pour cohérence UX) -->
        <div class="fbx-form-row fbx-form-row-grid">
          <label>💼 Métier (N1) <span class="fbx-required">*</span></label>
          <div class="fbx-btn-grid fbx-btn-grid-compact" id="fbx-adjust-row-metiers" data-meta-level="1">
            <div class="fbx-loading">Chargement…</div>
          </div>
        </div>
        <div class="fbx-form-row fbx-form-row-grid">
          <label>📂 Domaine (N2) <span class="fbx-required">*</span></label>
          <div class="fbx-btn-grid-sm" id="fbx-adjust-row-n2" data-meta-level="2">
            <div class="fbx-row-empty">— Choisir un métier d'abord —</div>
          </div>
        </div>
        <div class="fbx-form-row fbx-form-row-grid">
          <label>📁 Sous-domaine (N3) <span class="fbx-required">*</span></label>
          <input type="search" id="fbx-adjust-n3-search" class="fbx-search-n3" placeholder="🔍 Rechercher un immeuble / propriétaire / bien (nom ou réf)…" autocomplete="off">
          <div class="fbx-btn-grid-sm" id="fbx-adjust-row-n3" data-meta-level="3">
            <div class="fbx-row-empty">— Choisir un domaine d'abord —</div>
          </div>
        </div>
        <div class="fbx-form-row">
          <label>👤 Instance entité <span class="fbx-form-hint">(ex : Dupont-Pierre, BNP, Imm Foch)</span></label>
          <?php if ($matchedBien): ?>
            <!-- 🎯 Bien détecté auto (prefill, source_meta ou matching IA adresse) -->
            <div style="background:#d9f0db;border-left:4px solid #2d6a35;border-radius:6px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:18px;">🎯</span>
                <span style="font-weight:700;color:#14532d;">Bien détecté auto</span>
                <span style="background:#14532d;color:#d9f0db;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">
                  score <?= (int)$matchedBien['score'] ?>% · <?= $h($matchedBien['source']) ?>
                </span>
              </div>
              <div style="margin-top:6px;color:#14532d;">
                <b>#<?= (int)$matchedBien['id'] ?></b> · <?= $h($matchedBien['designation']) ?>
                <?php if ($matchedBien['adresse_1']): ?>
                  <div style="font-size:11px;color:#15803d;margin-top:2px;">📍 <?= $h($matchedBien['adresse_1']) ?> · <?= $h($matchedBien['ville']) ?></div>
                <?php endif; ?>
                <?php if (!empty($matchedBien['ia_adresse'])): ?>
                  <div style="font-size:10.5px;color:#5a5650;margin-top:2px;">🔍 IA avait extrait : <code><?= $h($matchedBien['ia_adresse']) ?></code></div>
                <?php endif; ?>
              </div>
              <input type="hidden" name="prefill_bien_id" value="<?= (int)$matchedBien['id'] ?>">
              <input type="hidden" name="prefill_bien_source" value="<?= $h((string)$autoMatchSource) ?>">
            </div>
          <?php else:
            // [Sprint D — 2026-05-25] IA a-t-elle extrait une adresse exploitable ? Propose création nouveau bien
            $iaAdrForCreate = '';
            $iaCpForCreate = '';
            $iaCityForCreate = '';
            $iaProprioForCreate = '';
            try {
                $stHash = $pdo->prepare("SELECT hash_sha256 FROM fluxbox_documents WHERE id = ? LIMIT 1");
                $stHash->execute([(int)($carte['document_id'] ?? 0)]);
                $hashTry = (string)$stHash->fetchColumn();
                if ($hashTry !== '') {
                    $stC = $pdo->prepare("SELECT response_json FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
                    $stC->execute([$hashTry]);
                    $iaTry = json_decode((string)$stC->fetchColumn(), true) ?: [];
                    $iaAdrForCreate = (string)($iaTry['adresse_bien'] ?? $iaTry['adresse'] ?? '');
                    $iaCpForCreate  = (string)($iaTry['code_postal']  ?? '');
                    $iaCityForCreate = (string)($iaTry['ville']       ?? '');
                    $iaProprioForCreate = (string)($iaTry['proprietaire'] ?? '');
                }
            } catch (Throwable) {}
            if ($iaAdrForCreate !== '' || $iaCpForCreate !== ''):
                // Suggestion création
                $createUrl = '/MaBoxImmo2026/public_html/bien_detail.php?'
                          . http_build_query([
                              'adresse_1'   => $iaAdrForCreate,
                              'code_postal' => $iaCpForCreate,
                              'ville'       => $iaCityForCreate,
                              'origin'      => 'fluxbox_ia_propose_create',
                              'from_carte'  => (int)$carteId,
                          ]);
          ?>
            <!-- 🆕 Pas de bien matché — proposition création -->
            <div style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:6px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:18px;">🆕</span>
                <span style="font-weight:700;color:#92400e;">Aucun bien matché — IA a extrait une adresse</span>
              </div>
              <div style="margin-top:6px;color:#92400e;font-size:11.5px;">
                📍 <b><?= $h($iaAdrForCreate) ?></b> · <?= $h($iaCpForCreate) ?> <?= $h($iaCityForCreate) ?>
                <?php if ($iaProprioForCreate): ?><br>👤 Propriétaire détecté : <b><?= $h($iaProprioForCreate) ?></b><?php endif; ?>
              </div>
              <div style="margin-top:8px;">
                <a href="<?= $h($createUrl) ?>" target="_blank"
                   style="background:#f59e0b;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:700;font-size:11.5px;">
                  ➕ Créer un nouveau bien (pré-rempli IA)
                </a>
                <?php if ($iaProprioForCreate): ?>
                  <?php
                    $createTiersUrl = '/MaBoxImmo2026/public_html/admin/admin_tiers_merge.php?'
                                    . http_build_query([
                                        'create_new' => 1,
                                        'nom_suggest' => $iaProprioForCreate,
                                        'origin' => 'fluxbox_ia_propose_create',
                                        'from_carte' => (int)$carteId,
                                    ]);
                  ?>
                  <a href="<?= $h($createTiersUrl) ?>" target="_blank"
                     style="background:#0e7490;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:700;font-size:11.5px;margin-left:6px;">
                    👤 Créer le tiers "<?= $h($iaProprioForCreate) ?>"
                  </a>
                <?php endif; ?>
                <span style="font-size:10.5px;color:#92400e;margin-left:8px;display:block;margin-top:6px;">— ouvre une nouvelle fenêtre, revenir ici après pour rattacher</span>
              </div>
            </div>
          <?php
            endif;
          endif; ?>
          <input type="text" name="entity_instance" value="<?= $h($entityInstanceProp) ?>"
                 class="<?= $entityInstanceProp !== '' ? 'fbx-auto-filled' : '' ?>"
                 placeholder="Détectée auto si nom de dossier upload — modifiable">
        </div>
        <div class="fbx-form-row fbx-form-row-grid">
          <label>📄 Catégorie (N4)</label>
          <div class="fbx-btn-grid-sm" id="fbx-adjust-row-n4" data-meta-level="4">
            <div class="fbx-row-empty">— Choisir un sous-domaine d'abord —</div>
          </div>
        </div>
        <div class="fbx-form-row fbx-form-row-grid">
          <label>📑 Sous-catégorie (N5)</label>
          <div class="fbx-btn-grid-sm" id="fbx-adjust-row-n5" data-meta-level="5">
            <div class="fbx-row-empty">— Choisir une catégorie d'abord —</div>
          </div>
        </div>

        <!-- Champs hidden alimentés par les boutons cascade ci-dessus -->
        <input type="hidden" name="n1" id="fbx-adjust-n1" value="<?= $h($classement['n1']) ?>">
        <input type="hidden" name="n2" id="fbx-adjust-n2" value="<?= $h($classement['n2']) ?>">
        <input type="hidden" name="n3" id="fbx-adjust-n3" value="<?= $h($classement['n3']) ?>">
        <input type="hidden" name="n4" id="fbx-adjust-n4" value="<?= $h($classement['n4']) ?>">
        <input type="hidden" name="n5" id="fbx-adjust-n5" value="<?= $h($classement['n5']) ?>">
        <div class="fbx-form-row fbx-form-row-grid">
          <label>📝 Signature (N6) <span class="fbx-form-hint">(auto si pertinent — laisser vide si non applicable)</span></label>
          <div class="fbx-btn-grid-sm fbx-n6-grid" id="fbx-adjust-row-n6">
            <?php $currentN6 = strtoupper((string)$classement['n6']); ?>
            <button type="button" class="fbx-choice-btn <?= $currentN6 === '' ? 'is-selected' : '' ?>" data-n6-val="">
              <span class="fbx-choice-label">— vide —</span>
            </button>
            <button type="button" class="fbx-choice-btn <?= $currentN6 === 'SIGNE' ? 'is-selected' : '' ?>" data-n6-val="SIGNE">
              <span class="fbx-choice-icon">✅</span>
              <span class="fbx-choice-label">SIGNÉ</span>
            </button>
            <button type="button" class="fbx-choice-btn <?= $currentN6 === 'NON_SIGNE' ? 'is-selected' : '' ?>" data-n6-val="NON_SIGNE">
              <span class="fbx-choice-icon">❌</span>
              <span class="fbx-choice-label">NON SIGNÉ</span>
            </button>
          </div>
          <input type="hidden" name="n6" id="fbx-adjust-n6" value="<?= $h($currentN6) ?>">
        </div>
        <div class="fbx-form-row">
          <label>Libellé personnalisé <span class="fbx-form-hint">(libre — laisser vide si tout est déjà dans les niveaux)</span></label>
          <input type="text" name="user_label" value="<?= $h($userLabelProp) ?>"
                 class="<?= $userLabelProp !== '' ? 'fbx-auto-filled' : 'fbx-empty-fillable' ?>"
                 placeholder="Titre custom optionnel">
        </div>
        <div class="fbx-form-row">
          <label>
            Date du document
            <?php if ($dateSrcLabel !== ''): ?>
              <span style="font-size:11px;color:<?= $targetDateConf >= 70 ? '#16a34a' : ($targetDateConf >= 40 ? '#ca8a04' : '#dc2626') ?>;font-weight:400;">
                — <?= $h($dateSrcLabel) ?>
              </span>
            <?php endif; ?>
          </label>
          <input type="date" name="target_date" value="<?= $h($targetDateProp) ?>"
                 class="<?= $targetDateProp !== '' ? 'fbx-auto-filled' : 'fbx-empty-fillable' ?>">
        </div>

        <?php if ((int)($_SESSION['id_role'] ?? 0) === 1): ?>
        <div class="fbx-form-row fbx-form-agents-link">
          <?php
            $agentsUrl = (function_exists('app_url') ? app_url('/admin/admin_agents_ia.php') : '/admin/admin_agents_ia.php')
              . '?n1=' . urlencode((string)$classement['n1'])
              . '&n4=' . urlencode((string)$classement['n4'])
              . '&n5=' . urlencode((string)$classement['n5']);
          ?>
          <a href="<?= $h($agentsUrl) ?>" target="_blank" rel="noopener" class="fbx-btn-agents-link">
            🤖 Compléter les agents IA pour ce type de doc
            <span class="fbx-form-hint">(ouvre admin Agents IA — N1=<?= $h($classement['n1']) ?> · N4=<?= $h($classement['n4']) ?>)</span>
          </a>
        </div>
        <?php endif; ?>

        <div class="fbx-modal-actions">
          <button type="button" value="cancel" data-modal-cancel class="fbx-btn fbx-btn-secondary">Annuler</button>
          <button type="button" value="confirm" data-modal-confirm class="fbx-btn fbx-btn-validate">Valider avec ajustements</button>
        </div>
      </form>
      <?php if (!empty($carte['document_id'])): ?>
      <div class="fbx-modal-viewer-right">
        <div class="fbx-modal-viewer-head">📄 Aperçu document <span class="fbx-modal-viewer-name"><?= $h($docFichierNom) ?></span></div>
        <iframe id="fbx-adjust-viewer-iframe" src="about:blank" title="Aperçu document"></iframe>
      </div>
      <?php endif; ?>
      </div><!-- /fbx-modal-split -->
    </dialog>

    <!-- Modal Viewer (visualisation document attaché — PDF/image) -->
    <?php if (!empty($carte['document_id'])): ?>
    <dialog id="fbx-modal-view" class="fbx-modal fbx-modal-view">
      <div class="fbx-view-head">
        <h3>👁️ Visualisation du document</h3>
        <button type="button" class="fbx-btn fbx-btn-secondary" data-view-close>✕ Fermer</button>
      </div>
      <div class="fbx-view-body">
        <iframe id="fbx-view-iframe"
                src="about:blank"
                title="Visualisation document"
                style="width:100%;height:75vh;border:0;border-radius:8px;background:#f1f5f9;"></iframe>
      </div>
      <div class="fbx-view-foot">
        <?php
          $viewUrl = (function_exists('app_url') ? app_url('/api/fluxbox_action.php') : '/api/fluxbox_action.php')
                   . '?action=view_doc&carte_id=' . (int)$carte['id'];
        ?>
        <a href="<?= $h($viewUrl) ?>" target="_blank" rel="noopener" class="fbx-btn fbx-btn-secondary">↗️ Ouvrir dans un nouvel onglet</a>
      </div>
    </dialog>

    <!-- Sous-modal Pièce jointe (popup au-dessus du modal mail) -->
    <dialog id="fbx-modal-att" class="fbx-modal fbx-modal-msg" style="z-index:9999;">
      <div class="fbx-msg-head">
        <h3 id="fbx-att-title">📎 Pièce jointe</h3>
        <button type="button" class="fbx-btn fbx-btn-secondary" data-att-close>✕ Fermer</button>
      </div>
      <div class="fbx-msg-body" id="fbx-att-body"
           style="display:flex;align-items:center;justify-content:center;background:#f1f5f9;border-radius:8px;height:75vh;overflow:auto;">
        <iframe id="fbx-att-iframe" src="about:blank"
                style="width:100%;height:100%;border:0;background:transparent;display:none;"
                title="Pièce jointe"></iframe>
        <img id="fbx-att-image" src="" alt=""
             style="max-width:100%;max-height:100%;object-fit:contain;display:none;" />
        <div id="fbx-att-fallback" style="display:none;text-align:center;padding:40px;">
          <div style="font-size:48px;margin-bottom:14px;">📄</div>
          <div style="font-size:16px;color:#243B5C;font-weight:600;margin-bottom:8px;">Aperçu non disponible</div>
          <div style="font-size:13px;color:#64748b;">Télécharge la pièce jointe pour l'ouvrir.</div>
        </div>
      </div>
      <!-- Panneau édition (nom + cascade classement) — collapsible -->
      <details id="fbx-att-edit" style="margin:12px 0;border-top:1px dashed #cbd5e1;padding-top:10px;">
        <summary style="cursor:pointer;font-size:12px;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">
          ✏️ Modifier le nom ou le classement avant d'enregistrer
        </summary>
        <div style="margin-top:12px;display:grid;gap:14px;">
          <label style="display:block;">
            <span style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nom du document</span>
            <input type="text" id="fbx-att-name-input" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;margin-top:4px;" placeholder="Nom de la pièce jointe">
          </label>
          <!-- Grilles boutons cascade (alimentent les hidden inputs ci-dessous) -->
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💼 Métier (N1)</div>
            <div class="fbx-btn-grid" id="fbx-att-row-metiers" data-meta-level="1">
              <div class="fbx-loading">Chargement…</div>
            </div>
          </div>
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📂 Domaine (N2)</div>
            <div class="fbx-btn-grid-sm" id="fbx-att-row-n2" data-meta-level="2">
              <div class="fbx-row-empty">— Choisir un métier —</div>
            </div>
          </div>
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📁 Sous-domaine (N3)</div>
            <div class="fbx-btn-grid-sm" id="fbx-att-row-n3" data-meta-level="3">
              <div class="fbx-row-empty">— Choisir un domaine —</div>
            </div>
          </div>
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📄 Catégorie (N4)</div>
            <div class="fbx-btn-grid-sm" id="fbx-att-row-n4" data-meta-level="4">
              <div class="fbx-row-empty">— Choisir un sous-domaine —</div>
            </div>
          </div>
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📑 Sous-catégorie (N5)</div>
            <div class="fbx-btn-grid-sm" id="fbx-att-row-n5" data-meta-level="5">
              <div class="fbx-row-empty">— Choisir une catégorie —</div>
            </div>
          </div>
          <input type="hidden" id="fbx-att-n1" value="">
          <input type="hidden" id="fbx-att-n2" value="">
          <input type="hidden" id="fbx-att-n3" value="">
          <input type="hidden" id="fbx-att-n4" value="">
          <input type="hidden" id="fbx-att-n5" value="">
          <div style="font-size:11px;color:#94a3b8;font-style:italic;">
            💡 Vide = classement du mail parent. Modifie seulement ce qui change.
          </div>
        </div>
      </details>
      <div style="padding:12px 0 0 0;text-align:right;border-top:1px solid #e2e8f0;">
        <button type="button" id="fbx-att-save" class="fbx-btn fbx-btn-validate" style="font-weight:600;">💾 Enregistrer dans la GED</button>
        <a id="fbx-att-open-new" href="#" target="_blank" rel="noopener" class="fbx-btn fbx-btn-view" style="text-decoration:none;">↗️ Nouvel onglet</a>
        <a id="fbx-att-download"  href="#" class="fbx-btn fbx-btn-view" style="text-decoration:none;">📥 Télécharger</a>
      </div>
    </dialog>

    <!-- Modal Visualiseur .msg (fiche document mail reçu — PAS un client mail) -->
    <dialog id="fbx-modal-msg" class="fbx-modal fbx-modal-msg">
      <div class="fbx-msg-head">
        <h3>📋 Document mail reçu</h3>
        <button type="button" class="fbx-btn fbx-btn-secondary" data-msg-close>✕ Fermer</button>
      </div>
      <div class="fbx-msg-body">
        <!-- Vue principale : fiche du document -->
        <div id="fbx-msg-view-fiche" class="fbx-msg-view">
          <div class="fbx-msg-loading">⏳ Chargement…</div>
        </div>
        <!-- Vue formulaire de réponse (action métier, masquée par défaut) -->
        <div id="fbx-msg-view-reply" class="fbx-msg-view" hidden>
          <div class="fbx-msg-section">
            <div class="fbx-msg-section-title">📤 Réponse à envoyer</div>
            <div class="fbx-msg-form-row">
              <label>Destinataire</label>
              <input type="email" id="fbx-msg-reply-to" readonly>
            </div>
            <div class="fbx-msg-form-row">
              <label>Sujet</label>
              <input type="text" id="fbx-msg-reply-subject">
            </div>
            <div class="fbx-msg-form-row">
              <label>Texte de la réponse</label>
              <textarea id="fbx-msg-reply-body" rows="10" placeholder="Saisissez votre réponse ici…"></textarea>
            </div>
            <div class="fbx-msg-form-actions">
              <button type="button" class="fbx-btn fbx-btn-secondary" id="fbx-msg-reply-cancel">← Retour à la fiche</button>
              <button type="button" class="fbx-btn fbx-btn-validate" id="fbx-msg-reply-send">📤 Envoyer la réponse</button>
            </div>
          </div>
        </div>
      </div>
    </dialog>
    <?php endif; ?>

  <?php endif; ?>

  <!-- ─── Liste compacte des autres cartes en attente ─── -->
  <?php if (count($pileList) > 1): // > 1 car la 1ère est déjà la carte active ?>
  <div class="fbx-batch" id="fbx-batch">
    <div class="fbx-batch-head">
      <h3>📋 Sélection multiple <span class="fbx-batch-count"><?= count($pileList) - 1 ?> cartes</span></h3>
    </div>

    <!-- Toolbar du haut (actions principales bien visibles) -->
    <div class="fbx-batch-top-actions">
      <label class="fbx-batch-toggle">
        <input type="checkbox" id="fbx-batch-all">
        <span>☐ Tout cocher</span>
      </label>
      <button type="button" id="fbx-batch-ia-ready" class="fbx-btn fbx-btn-secondary"
              title="Cocher les cartes où l'IA est suffisamment sûre (confiance ≥ 60%)">
        🤖 Cocher IA-prêtes (<?= $iaReadyCount ?>)
      </button>
      <span class="fbx-batch-spacer"></span>
      <span class="fbx-batch-top-count">
        <strong id="fbx-batch-selected-top">0</strong> sélectionnée(s)
      </span>
      <button type="button" class="fbx-btn fbx-btn-validate" data-batch-action="validate" data-batch-top>
        ✅ Valider la sélection
      </button>
    </div>

    <div class="fbx-batch-info">
      💡 Coche les cartes que tu veux valider. <kbd>Shift</kbd> + clic = cocher une plage entière.
    </div>

    <ul class="fbx-batch-list" id="fbx-batch-list">
      <?php
      $first = true;
      foreach ($pileList as $p):
          if ($first) { $first = false; continue; } // skip la carte active déjà au-dessus
          $pPrio = (string)($p['priorite'] ?? 'normal');
          $pIcon = ['urgent'=>'🔴','important'=>'🟠','normal'=>'🟢','faible'=>'⚪'][$pPrio] ?? '🟢';
          $pConf = $p['confiance_ia'] !== null ? (float)$p['confiance_ia'] : null;
          $confClass = $pConf === null ? '' : ($pConf >= 85 ? 'is-ia-ok' : ($pConf >= 60 ? 'is-ia-mid' : 'is-ia-low'));
      ?>
      <?php
        // URL absolue (pas relative) pour éviter qu'un <base> du layout renvoie vers la racine du site
        $promoteUrl = 'fluxbox_pile.php?carte=' . (int)$p['id'] . ($sourceParam !== 'all' ? '&source=' . urlencode($sourceParam) : '');
      ?>
      <li class="fbx-batch-item fbx-batch-clickable <?= $h($confClass) ?>" data-carte-id="<?= (int)$p['id'] ?>" data-confiance="<?= $pConf ?? '' ?>" data-promote-url="<?= $h($promoteUrl) ?>">
        <div class="fbx-batch-row">
          <label class="fbx-batch-cb-wrap" title="Cocher pour validation en masse">
            <input type="checkbox" class="fbx-batch-cb" value="<?= (int)$p['id'] ?>">
          </label>
          <span class="fbx-batch-prio"><?= $pIcon ?></span>
          <a href="<?= $h($promoteUrl) ?>" class="fbx-batch-titre" title="Ouvrir cette carte">
            <?= $h($p['titre']) ?>
          </a>
          <?php if (!empty($p['sous_titre'])): ?>
            <span class="fbx-batch-sous"><?= $h($p['sous_titre']) ?></span>
          <?php endif; ?>
          <?php if ($pConf !== null): ?>
            <span class="fbx-batch-conf" title="Confiance IA"><?= (int)$pConf ?>%</span>
          <?php endif; ?>
          <a href="<?= $h($promoteUrl) ?>" class="fbx-batch-promote-btn" title="Ouvrir cette carte">↑</a>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>

    <!-- Toolbar action sélection (sticky) -->
    <div class="fbx-batch-toolbar" id="fbx-batch-toolbar">
      <span class="fbx-batch-toolbar-count">
        <strong id="fbx-batch-selected">0</strong> sélectionnée(s)
      </span>
      <div class="fbx-batch-toolbar-actions">
        <button type="button" class="fbx-btn fbx-btn-later" data-batch-action="later">
          ⏭ Plus tard
        </button>
        <button type="button" class="fbx-btn fbx-btn-validate" data-batch-action="validate">
          ✅ Valider la sélection
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($carte): ?></div><!-- /.fbx-pile-layout --><?php endif; ?>

  <!-- Aide raccourcis -->
  <div class="fbx-help">
    <kbd>Espace</kbd> Valider · <kbd>←</kbd> Ajuster · <kbd>→</kbd> Plus tard · <kbd>?</kbd> Aide
  </div>

</div>

<!-- Overlay de progression pour validations en masse / sélection -->
<div id="fbx-bulk-overlay" class="fbx-bulk-overlay" aria-hidden="true">
  <div class="fbx-bulk-dialog">
    <h3 id="fbx-bulk-title">⏳ Validation en cours…</h3>
    <div class="fbx-bulk-stats">
      <span><strong id="fbx-bulk-done">0</strong> / <span id="fbx-bulk-total">0</span> cartes</span>
      <span class="fbx-bulk-pct"><span id="fbx-bulk-pct">0</span>%</span>
    </div>
    <div class="fbx-bulk-bar">
      <div class="fbx-bulk-fill" id="fbx-bulk-fill" style="width:0%"></div>
    </div>
    <div class="fbx-bulk-details">
      <span>✅ <strong id="fbx-bulk-ok">0</strong> validées</span>
      <span>❌ <strong id="fbx-bulk-err">0</strong> erreurs</span>
      <span id="fbx-bulk-chunk-info">Lot 0 / 0</span>
    </div>
    <div class="fbx-bulk-actions">
      <button type="button" id="fbx-bulk-cancel" class="fbx-btn fbx-btn-secondary">Annuler</button>
      <button type="button" id="fbx-bulk-close"  class="fbx-btn fbx-btn-validate" style="display:none;">Fermer</button>
    </div>
  </div>
</div>

<script>
  window.FLUXBOX_API_URL = <?= json_encode(function_exists('app_url') ? app_url('/api/fluxbox_action.php') : '/api/fluxbox_action.php', JSON_UNESCAPED_SLASHES) ?>;
  window.FLUXBOX_CSRF    = '<?= $h($_SESSION['csrf_token'] ?? '') ?>';
  window.FLUXBOX_IS_ADMIN = <?= ((int)($_SESSION['id_role'] ?? 0) === 1) ? 'true' : 'false' ?>;
  // Classement du mail parent (pour pré-remplir la cascade du sous-modal PJ)
  window.FLUXBOX_PARENT_CLASSEMENT = <?= json_encode([
    'n1' => $classement['n1'] ?? '',
    'n2' => $classement['n2'] ?? '',
    'n3' => $classement['n3'] ?? '',
    'n4' => $classement['n4'] ?? '',
    'n5' => $classement['n5'] ?? '',
  ], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars(function_exists('asset_url') ? asset_url('/js/fluxbox.js') : '/js/fluxbox.js') ?>?v=<?= @filemtime(__DIR__ . '/js/fluxbox.js') ?: time() ?>"></script>

<?php
require_once __DIR__ . '/inc/agency_layout_bottom.php';
?>
