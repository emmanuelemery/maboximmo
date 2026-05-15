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
$carte = fluxbox_carte_get_next($pdo, $sourceFilter);

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

// Preview nom canonique live
$previewName = '';
if ($carte) {
    $previewName = ged_v3_preview([
        'soc' => $ctx['societe_code'] ?: 'SOC',
        'age' => $ctx['agence_code']  ?: 'AGE',
        'n1'  => $classement['n1'],
        'n2'  => $classement['n2'],
        'n3'  => $classement['n3'],
        'n4'  => $classement['n4'],
        'n5'  => $classement['n5'],
        'n6'  => $classement['n6'],
    ]);
}

// Plafond IA
$societeId = (int)($ctx['societe_id'] ?? 0);
$plafond   = $societeId > 0 ? fluxbox_ia_get_plafond_state($societeId, $pdo)
                            : ['plafond_eur'=>75,'consomme_eur'=>0,'pct'=>0,'mode_degrade'=>false];

$pageTitle    = 'FluxBox · La pile';
$pageSubtitle = $sourceLabel;
$layoutSidebar = 'sidebar_agency';

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

    <div class="fbx-card-shell" data-carte-id="<?= (int)$carte['id'] ?>" id="fbx-card">
      <div class="fbx-card <?= $h($prioriteClass) ?>">

        <!-- Header carte : priorité + titre -->
        <div class="fbx-card-head">
          <span class="fbx-priorite" title="<?= $h($carte['priorite_reason'] ?? '') ?>">
            <?= $prioriteIcon ?>
            <strong><?= $h(strtoupper($priorite)) ?></strong>
          </span>
          <h2 class="fbx-card-titre"><?= $h($carte['titre']) ?></h2>
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

        <!-- 3 boutons -->
        <div class="fbx-card-actions">
          <button type="button" class="fbx-btn fbx-btn-validate" data-action="validate"
                  title="Tout valider (Espace ou Entrée)">
            ✅ <strong>Tout valider</strong>
          </button>
          <button type="button" class="fbx-btn fbx-btn-adjust" data-action="adjust"
                  title="Ouvrir détails (←)">
            ✏️ Ajuster
          </button>
          <button type="button" class="fbx-btn fbx-btn-later" data-action="later" data-later="+1 day"
                  title="Reporter à demain (→)">
            ⏭ Plus tard
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

    <!-- Modal Ajuster (détails techniques) -->
    <dialog id="fbx-modal-adjust" class="fbx-modal">
      <form method="dialog" class="fbx-modal-form">
        <h3>Ajuster le classement</h3>

        <div class="fbx-form-row">
          <label>Société</label>
          <input type="text" value="<?= $h($ctx['societe_label'] ?: '—') ?>" disabled>
        </div>
        <div class="fbx-form-row">
          <label>Agence</label>
          <input type="text" value="<?= $h($ctx['agence_label'] ?: '—') ?>" disabled>
        </div>
        <div class="fbx-form-row">
          <label>Métier (N1) <span class="fbx-required">*</span></label>
          <input type="text" name="n1" value="<?= $h($classement['n1']) ?>" required placeholder="Code N1">
        </div>
        <div class="fbx-form-row">
          <label>Domaine (N2) <span class="fbx-required">*</span></label>
          <input type="text" name="n2" value="<?= $h($classement['n2']) ?>" required placeholder="Code N2">
        </div>
        <div class="fbx-form-row">
          <label>Sous-domaine (N3) <span class="fbx-required">*</span></label>
          <input type="text" name="n3" value="<?= $h($classement['n3']) ?>" required placeholder="Code N3">
        </div>
        <div class="fbx-form-row">
          <label>Type document (N4)</label>
          <input type="text" name="n4" value="<?= $h($classement['n4']) ?>" placeholder="optionnel">
        </div>
        <div class="fbx-form-row">
          <label>Statut/version (N5)</label>
          <input type="text" name="n5" value="<?= $h($classement['n5']) ?>" placeholder="optionnel">
        </div>
        <div class="fbx-form-row">
          <label>Libellé libre (N6)</label>
          <input type="text" name="n6" value="<?= $h($classement['n6']) ?>" placeholder="ex : Ascenseur, PV AG ordinaire">
        </div>

        <div class="fbx-modal-actions">
          <button type="button" value="cancel" data-modal-cancel class="fbx-btn fbx-btn-secondary">Annuler</button>
          <button type="button" value="confirm" data-modal-confirm class="fbx-btn fbx-btn-validate">Valider avec ajustements</button>
        </div>
      </form>
    </dialog>

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
      <li class="fbx-batch-item <?= $h($confClass) ?>" data-carte-id="<?= (int)$p['id'] ?>" data-confiance="<?= $pConf ?? '' ?>">
        <label class="fbx-batch-row">
          <input type="checkbox" class="fbx-batch-cb" value="<?= (int)$p['id'] ?>">
          <span class="fbx-batch-prio"><?= $pIcon ?></span>
          <span class="fbx-batch-titre"><?= $h($p['titre']) ?></span>
          <?php if (!empty($p['sous_titre'])): ?>
            <span class="fbx-batch-sous"><?= $h($p['sous_titre']) ?></span>
          <?php endif; ?>
          <?php if ($pConf !== null): ?>
            <span class="fbx-batch-conf" title="Confiance IA"><?= (int)$pConf ?>%</span>
          <?php endif; ?>
        </label>
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
</script>
<script src="<?= htmlspecialchars(function_exists('asset_url') ? asset_url('/js/fluxbox.js') : '/js/fluxbox.js') ?>?v=<?= @filemtime(__DIR__ . '/js/fluxbox.js') ?: time() ?>"></script>
