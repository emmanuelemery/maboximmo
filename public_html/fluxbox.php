<?php
declare(strict_types=1);

/**
 * FLUXBOX — Dashboard d'entrée (home)
 *
 * Page d'accueil simple : 3 zones, 3 actions, 1 chemin.
 *  1. 📥 Téléchargements  → fluxbox_pile.php?source=telechargements
 *  2. 📧 Mails           → fluxbox_pile.php?source=mails
 *  3. 🃏 La pile         → fluxbox_pile.php
 *
 * Spec : project_fluxbox_module (validé EMERY 2026-05-13).
 * Page de traitement carte par carte = fluxbox_pile.php.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fluxbox_functions.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$prenom = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');

// Stats globales + par source (graceful fallback si tables non migrées)
$bySource = ['telechargements'=>['new_docs'=>0,'pending_cards'=>0,'ia_ready'=>0,'latest'=>[]],
             'mails'=>['new_docs'=>0,'pending_cards'=>0,'ia_ready'=>0,'latest'=>[]],
             'total_pending'=>0];
$stats = ['pending'=>0,'urgent'=>0,'later'=>0,'today_validated'=>0,'doublons_blocked'=>0];
$tablesReady = false;
try {
    $bySource = fluxbox_stats_by_source($pdo);
    $stats    = fluxbox_carte_stats($pdo);
    $tablesReady = true;
} catch (Throwable) {}

// 5 dernières cartes globales (toutes sources, pour aperçu zone La Pile)
$pileLatest = [];
try {
    $tenantId = (int)(ged_current_tenant_id() ?? 0);
    if ($tenantId > 0) {
        $st = $pdo->prepare("
            SELECT id, titre, sous_titre, priorite, confiance_ia, created_at
            FROM fluxbox_cartes
            WHERE tenant_id = ? AND statut IN ('pending','in_progress')
            ORDER BY FIELD(priorite,'urgent','important','normal','faible'), created_at ASC
            LIMIT 5
        ");
        $st->execute([$tenantId]);
        $pileLatest = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable) {}

$total = (int)$stats['pending'];
$urgent = (int)$stats['urgent'];

// Format temps relatif simple
$timeAgo = function (?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $diff = time() - $t;
    if ($diff < 60)     return "à l'instant";
    if ($diff < 3600)   return floor($diff / 60) . ' min';
    if ($diff < 86400)  return floor($diff / 3600) . ' h';
    if ($diff < 172800) return 'hier';
    return floor($diff / 86400) . ' j';
};

$prioriteIcon = ['urgent'=>'🔴','important'=>'🟠','normal'=>'🟢','faible'=>'⚪'];

$layout_title          = 'FluxBox';
$layout_module         = 'FluxBox';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.fbh-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 80px;
            font-family: "Sora", "Inter", system-ui, sans-serif; }

/* ─── Header ──────────────────────────────────────────────── */
.fbh-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 14px;
  margin-bottom: 26px; padding: 0 4px;
}
.fbh-title {
  font-size: 30px; font-weight: 700; color: #243B5C; margin: 0;
  letter-spacing: -0.02em; display: flex; align-items: center; gap: 12px;
}
.fbh-title-icon { font-size: 32px; }
.fbh-subtitle { font-size: 14px; color: #64748b; margin-top: 4px; }
.fbh-stats-mini { display: flex; gap: 12px; font-size: 13px; color: #475569; }
.fbh-stats-mini strong { color: #243B5C; }

/* ─── Grid 2 zones (Téléchargements + Mails) ─────────────── */
.fbh-sources {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 26px;
}
.fbh-source-card {
  background: #fff;
  border-radius: 20px;
  padding: 24px 26px;
  box-shadow: 8px 8px 20px rgba(196,192,186,0.5), -6px -6px 16px #fff;
  display: flex; flex-direction: column;
  text-decoration: none; color: inherit;
  transition: transform .2s ease, box-shadow .2s;
  border-top: 4px solid transparent;
}
.fbh-source-card:hover {
  transform: translateY(-3px);
  box-shadow: 12px 12px 28px rgba(196,192,186,0.6), -6px -6px 16px #fff;
}
.fbh-source-card.fbh-card-download { border-top-color: #4878a6; }
.fbh-source-card.fbh-card-mails    { border-top-color: #c97b2e; }

.fbh-source-head {
  display: flex; align-items: center; gap: 12px; margin-bottom: 16px;
}
.fbh-source-icon {
  width: 48px; height: 48px;
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 24px;
}
.fbh-card-download .fbh-source-icon { background: #4878a614; }
.fbh-card-mails    .fbh-source-icon { background: #c97b2e14; }
.fbh-source-title {
  font-size: 18px; font-weight: 700; color: #2c2a28;
}

.fbh-source-counts {
  display: flex; gap: 16px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.fbh-count {
  display: flex; flex-direction: column;
  background: #f8fafc; border-radius: 12px; padding: 10px 16px;
  flex: 1; min-width: 120px;
}
.fbh-count-value { font-size: 22px; font-weight: 700; color: #243B5C; line-height: 1; font-family: "Sora", sans-serif; }
.fbh-count-label { font-size: 11px; color: #64748b; margin-top: 4px;
                   text-transform: uppercase; letter-spacing: 0.06em; }

.fbh-source-latest {
  flex: 1; min-height: 100px; margin-bottom: 18px;
}
.fbh-latest-list { list-style: none; padding: 0; margin: 0; }
.fbh-latest-list li {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 0; font-size: 13px; color: #475569;
  border-bottom: 1px dashed #e2e8f0;
}
.fbh-latest-list li:last-child { border-bottom: none; }
.fbh-latest-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbh-latest-time { font-size: 11px; color: #94a3b8; flex-shrink: 0; }
.fbh-latest-empty {
  text-align: center; padding: 24px 0;
  color: #94a3b8; font-size: 13px; font-style: italic;
}

.fbh-source-cta {
  background: linear-gradient(135deg, #243B5C, #1e3050);
  color: #fff; font-weight: 600; font-size: 14px;
  padding: 12px 20px; border-radius: 12px;
  text-align: center;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: 3px 3px 10px rgba(36,59,92,0.25);
}
.fbh-source-card:hover .fbh-source-cta {
  background: linear-gradient(135deg, #1e3050, #142440);
}
.fbh-source-cta-arrow { transition: transform .2s; }
.fbh-source-card:hover .fbh-source-cta-arrow { transform: translateX(4px); }

/* ─── Zone La Pile (full width) ───────────────────────────── */
.fbh-pile {
  background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
  border-radius: 22px;
  padding: 26px 30px;
  box-shadow: 8px 8px 22px rgba(196,192,186,0.5), -6px -6px 16px #fff;
  border-left: 5px solid #D4A047;
  display: flex; flex-direction: column; gap: 18px;
}
.fbh-pile-head {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 14px;
}
.fbh-pile-title {
  font-size: 20px; font-weight: 700; color: #243B5C;
  display: flex; align-items: center; gap: 10px; margin: 0;
}
.fbh-pile-count {
  background: #D4A047; color: #1a1816;
  padding: 4px 14px; border-radius: 12px;
  font-size: 13px; font-weight: 700;
}
.fbh-pile-empty {
  text-align: center; padding: 40px 20px;
  color: #64748b;
}
.fbh-pile-empty-icon { font-size: 48px; margin-bottom: 8px; }
.fbh-pile-empty-title { font-size: 18px; font-weight: 700; color: #243B5C; margin-bottom: 6px; }

.fbh-pile-preview { list-style: none; padding: 0; margin: 0; }
.fbh-pile-preview li {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; background: #fff; border-radius: 10px;
  margin-bottom: 6px; box-shadow: 2px 2px 6px rgba(196,192,186,0.3);
}
.fbh-pile-prio { font-size: 14px; }
.fbh-pile-titre { flex: 1; font-size: 14px; color: #2c2a28; font-weight: 600;
                  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbh-pile-sous { font-size: 12px; color: #64748b; }

.fbh-pile-cta {
  background: linear-gradient(135deg, #D4A047, #b88835);
  color: #1a1816; font-weight: 700; font-size: 15px;
  padding: 14px 28px; border-radius: 14px;
  text-align: center; text-decoration: none;
  display: inline-flex; align-items: center; justify-content: center; gap: 10px;
  align-self: center; min-width: 280px;
  box-shadow: 3px 3px 10px rgba(212,160,71,0.4);
  transition: transform .15s ease;
}
.fbh-pile-cta:hover { transform: translateY(-2px); }

/* ─── Responsive ──────────────────────────────────────────── */
@media (max-width: 800px) {
  .fbh-sources { grid-template-columns: 1fr; }
  .fbh-title { font-size: 24px; }
  .fbh-pile { padding: 20px; }
}
</style>';

ob_start();
?>

<div class="fbh-wrap">

  <!-- Header -->
  <div class="fbh-header">
    <div>
      <h1 class="fbh-title">
        <span class="fbh-title-icon">🃏</span>
        FluxBox<?= $prenom !== '' ? ' · Bonjour ' . $h($prenom) : '' ?>
      </h1>
      <div class="fbh-subtitle">
        <?php if (!$tablesReady): ?>
          Pile de cartes — pas encore activée (migration BDD requise)
        <?php elseif ($total === 0): ?>
          🎉 Tout est traité. Profitez-en.
        <?php else: ?>
          <?= $total ?> chose<?= $total > 1 ? 's' : '' ?> à traiter — environ <?= max(1, (int)round($total * 0.5)) ?> minute<?= $total > 2 ? 's' : '' ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($tablesReady && ($urgent > 0 || (int)$stats['today_validated'] > 0)): ?>
    <div class="fbh-stats-mini">
      <?php if ($urgent > 0): ?>
        <span>🔴 <strong><?= $urgent ?></strong> urgent<?= $urgent > 1 ? 'es' : '' ?></span>
      <?php endif; ?>
      <?php if ((int)$stats['today_validated'] > 0): ?>
        <span>✅ <strong><?= (int)$stats['today_validated'] ?></strong> validé<?= $stats['today_validated'] > 1 ? 's' : '' ?> aujourd'hui</span>
      <?php endif; ?>
      <?php if ((int)$stats['doublons_blocked'] > 0): ?>
        <span>🛡️ <strong><?= (int)$stats['doublons_blocked'] ?></strong> doublons bloqués</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- 2 zones source : Téléchargements + Mails -->
  <div class="fbh-sources">

    <!-- 📥 Téléchargements -->
    <a href="./fluxbox_pile.php?source=telechargements" class="fbh-source-card fbh-card-download">
      <div class="fbh-source-head">
        <div class="fbh-source-icon">📥</div>
        <div class="fbh-source-title">Téléchargements</div>
      </div>
      <div class="fbh-source-counts">
        <div class="fbh-count">
          <div class="fbh-count-value"><?= (int)$bySource['telechargements']['pending_cards'] ?></div>
          <div class="fbh-count-label">À traiter</div>
        </div>
        <div class="fbh-count">
          <div class="fbh-count-value">🤖 <?= (int)$bySource['telechargements']['ia_ready'] ?></div>
          <div class="fbh-count-label">Prêts par IA</div>
        </div>
      </div>
      <div class="fbh-source-latest">
        <?php if (empty($bySource['telechargements']['latest'])): ?>
          <div class="fbh-latest-empty">Aucun fichier en attente.</div>
        <?php else: ?>
          <ul class="fbh-latest-list">
            <?php foreach ($bySource['telechargements']['latest'] as $item): ?>
              <li>
                <span><?= $prioriteIcon[$item['priorite'] ?? 'normal'] ?? '🟢' ?></span>
                <span class="fbh-latest-title"><?= $h($item['titre']) ?></span>
                <span class="fbh-latest-time"><?= $h($timeAgo($item['created_at'] ?? null)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="fbh-source-cta">
        Traiter maintenant
        <span class="fbh-source-cta-arrow">→</span>
      </div>
    </a>

    <!-- 📧 Mails -->
    <a href="./fluxbox_pile.php?source=mails" class="fbh-source-card fbh-card-mails">
      <div class="fbh-source-head">
        <div class="fbh-source-icon">📧</div>
        <div class="fbh-source-title">Mails</div>
      </div>
      <div class="fbh-source-counts">
        <div class="fbh-count">
          <div class="fbh-count-value"><?= (int)$bySource['mails']['pending_cards'] ?></div>
          <div class="fbh-count-label">À traiter</div>
        </div>
        <div class="fbh-count">
          <div class="fbh-count-value">🤖 <?= (int)$bySource['mails']['ia_ready'] ?></div>
          <div class="fbh-count-label">Prêts par IA</div>
        </div>
      </div>
      <div class="fbh-source-latest">
        <?php if (empty($bySource['mails']['latest'])): ?>
          <div class="fbh-latest-empty">Aucun mail en attente.</div>
        <?php else: ?>
          <ul class="fbh-latest-list">
            <?php foreach ($bySource['mails']['latest'] as $item): ?>
              <li>
                <span><?= $prioriteIcon[$item['priorite'] ?? 'normal'] ?? '🟢' ?></span>
                <span class="fbh-latest-title"><?= $h($item['titre']) ?></span>
                <span class="fbh-latest-time"><?= $h($timeAgo($item['created_at'] ?? null)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="fbh-source-cta">
        Traiter maintenant
        <span class="fbh-source-cta-arrow">→</span>
      </div>
    </a>

  </div>

  <!-- Zone La Pile -->
  <div class="fbh-pile">
    <div class="fbh-pile-head">
      <h2 class="fbh-pile-title">
        🃏 La pile
        <span class="fbh-pile-count"><?= $total ?> carte<?= $total > 1 ? 's' : '' ?></span>
      </h2>
    </div>

    <?php if ($total === 0): ?>
      <div class="fbh-pile-empty">
        <div class="fbh-pile-empty-icon">🎉</div>
        <div class="fbh-pile-empty-title">Pile vide</div>
        <div>Plus aucune carte à traiter pour le moment.</div>
      </div>
    <?php else: ?>
      <ul class="fbh-pile-preview">
        <?php foreach ($pileLatest as $p): ?>
          <li>
            <span class="fbh-pile-prio"><?= $prioriteIcon[$p['priorite'] ?? 'normal'] ?? '🟢' ?></span>
            <span class="fbh-pile-titre"><?= $h($p['titre']) ?></span>
            <?php if (!empty($p['sous_titre'])): ?>
              <span class="fbh-pile-sous"><?= $h($p['sous_titre']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if ($total > count($pileLatest)): ?>
          <li style="background:transparent;box-shadow:none;color:#94a3b8;font-style:italic;justify-content:center">
            … et <?= $total - count($pileLatest) ?> autre<?= ($total - count($pileLatest)) > 1 ? 's' : '' ?>
          </li>
        <?php endif; ?>
      </ul>
      <a href="./fluxbox_pile.php" class="fbh-pile-cta">
        🃏 Démarrer la pile <span>→</span>
      </a>
    <?php endif; ?>
  </div>

</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
