<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_card_bien.php — Card "Communication" pour la section
 * Annonce de bien_detail.php (placée APRÈS la Card 5 Diffusion).
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Affiche dans le carousel v2 :
 *   - Score commercial actuel (badge cliquable)
 *   - Statut critique export (OK / KO) avec nb manquants
 *   - 3 boutons de génération (Affiche · Fiche client · Fiche interne)
 *   - Bouton "Ouvrir le module"
 *
 * Variables attendues :
 *   - $editingBienId : int
 *   - $pdo           : PDO (ou $GLOBALS['pdo'])
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!isset($editingBienId) || !is_int($editingBienId) || $editingBienId <= 0) return;

$mbiSupPdo = $pdo ?? $GLOBALS['pdo'] ?? null;
$mbiSupScore     = null;
$mbiSupNbSupports = 0;
$mbiSupCritiqueOk = null;
$mbiSupNbBd       = 0;

if ($mbiSupPdo instanceof PDO) {
    try {
        $st = $mbiSupPdo->prepare("
            SELECT score, niveau_urgence, angle_recommande, date_calcul
            FROM bien_score_commercial
            WHERE id_bien = :b AND statut = 'calcule'
            ORDER BY date_calcul DESC, id DESC LIMIT 1
        ");
        $st->execute([':b' => $editingBienId]);
        $mbiSupScore = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}

    try {
        $st = $mbiSupPdo->prepare("SELECT COUNT(*) FROM mbi_supports_commerciaux WHERE id_bien = :b AND deleted_at IS NULL");
        $st->execute([':b' => $editingBienId]);
        $mbiSupNbSupports = (int)$st->fetchColumn();
    } catch (Throwable) {}
}

// Critique côté affiche_vitrine (le plus exigeant) — best effort, ne bloque pas l'affichage
if ($mbiSupPdo instanceof PDO && function_exists('mbi_supports_critic_check')) {
    try {
        $cr = mbi_supports_critic_check($editingBienId, 'affiche_vitrine');
        if ($cr['ok']) {
            $mbiSupCritiqueOk = (bool)$cr['peut_exporter'];
            $mbiSupNbBd = count($cr['blocs_durs_violes'] ?? []);
        }
    } catch (Throwable) {}
} elseif (file_exists(__DIR__ . '/mbi_supports_critic_engine.php')) {
    require_once __DIR__ . '/mbi_supports_critic_engine.php';
    try {
        $cr = mbi_supports_critic_check($editingBienId, 'affiche_vitrine');
        if ($cr['ok']) {
            $mbiSupCritiqueOk = (bool)$cr['peut_exporter'];
            $mbiSupNbBd = count($cr['blocs_durs_violes'] ?? []);
        }
    } catch (Throwable) {}
}

$dashUrl = function_exists('app_url')
    ? app_url('/mbi_supports_dashboard.php?id_bien=' . $editingBienId)
    : '/mbi_supports_dashboard.php?id_bien=' . $editingBienId;

$scoreNum = $mbiSupScore ? (int)$mbiSupScore['score'] : null;
$scoreColor = match (true) {
    $scoreNum === null => '#6b7280',
    $scoreNum >= 80    => '#1a8754',
    $scoreNum >= 60    => '#5a8a3f',
    $scoreNum >= 40    => '#c97b2e',
    $scoreNum >= 20    => '#a85858',
    default            => '#7a3030',
};
?>
<!-- Card MBI Supports : Communication (Lot 6) -->
<section class="v2-card is-next" role="tabpanel" aria-label="Ma Box Communication">
  <div class="v2-card-label">📰 Communication <?php if ($mbiSupNbSupports > 0): ?><span class="v2-count"><?= (int)$mbiSupNbSupports ?></span><?php endif; ?></div>
  <div class="v2-card-body">
    <div style="max-width:880px; margin:0 auto; padding:8px 0;">

      <!-- Bandeau résumé -->
      <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:18px;">

        <!-- Score -->
        <div style="flex:1; min-width:200px; background:linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px;">
          <div style="font-size:10px; font-weight:700; letter-spacing:0.08em; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Score commercial</div>
          <?php if ($scoreNum !== null): ?>
            <div style="display:flex; align-items:baseline; gap:8px;">
              <span style="font-size:32px; font-weight:700; color:<?= $scoreColor ?>; line-height:1;"><?= $scoreNum ?></span>
              <span style="color:#9ca3af; font-size:14px;">/100</span>
            </div>
            <div style="font-size:11px; color:#6b7280; margin-top:4px;">
              Calculé le <?= htmlspecialchars(date('d/m/Y', strtotime((string)$mbiSupScore['date_calcul']))) ?>
              <?php if (!empty($mbiSupScore['angle_recommande'])): ?>
                · Angle : <strong><?= htmlspecialchars($mbiSupScore['angle_recommande']) ?></strong>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div style="font-size:14px; color:#9ca3af; font-style:italic; padding:6px 0;">
              Pas encore évalué
            </div>
            <a href="<?= htmlspecialchars($dashUrl . '&action=recalculer_score&modele=haiku') ?>"
               style="display:inline-block; margin-top:6px; font-size:12px; color:#243B5C; font-weight:600; text-decoration:underline;">
              Évaluer commercialement →
            </a>
          <?php endif; ?>
        </div>

        <!-- Critique export -->
        <div style="flex:1; min-width:200px; background:linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px;">
          <div style="font-size:10px; font-weight:700; letter-spacing:0.08em; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Mentions légales — affiche</div>
          <?php if ($mbiSupCritiqueOk === true): ?>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:24px; line-height:1;">✓</span>
              <span style="font-size:14px; font-weight:600; color:#1a5e36;">Export autorisé</span>
            </div>
            <div style="font-size:11px; color:#6b7280; margin-top:4px;">
              Toutes les mentions obligatoires sont OK.
            </div>
          <?php elseif ($mbiSupCritiqueOk === false): ?>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:24px; line-height:1; color:#7a2828;">✗</span>
              <span style="font-size:14px; font-weight:600; color:#7a2828;">
                <?= $mbiSupNbBd ?> mention<?= $mbiSupNbBd > 1 ? 's' : '' ?> à corriger
              </span>
            </div>
            <a href="<?= htmlspecialchars($dashUrl) ?>"
               style="display:inline-block; margin-top:6px; font-size:12px; color:#243B5C; font-weight:600; text-decoration:underline;">
              Voir le détail →
            </a>
          <?php else: ?>
            <div style="font-size:13px; color:#9ca3af; font-style:italic; padding:6px 0;">
              Critique non disponible
            </div>
          <?php endif; ?>
        </div>

        <!-- Compteur supports -->
        <div style="flex:1; min-width:200px; background:linear-gradient(135deg, #fff 0%, #f7f8fa 100%); border:1px solid #e5e7eb; border-radius:12px; padding:14px 18px;">
          <div style="font-size:10px; font-weight:700; letter-spacing:0.08em; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Supports déjà générés</div>
          <div style="font-size:32px; font-weight:700; color:#243B5C; line-height:1;"><?= (int)$mbiSupNbSupports ?></div>
          <div style="font-size:11px; color:#6b7280; margin-top:4px;">
            Versions historisées (jamais écrasées)
          </div>
        </div>

      </div>

      <!-- Boutons de génération -->
      <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:14px;">
        <a href="<?= htmlspecialchars($dashUrl . '&type=affiche_vitrine') ?>"
           style="display:block; padding:14px 16px; border:2px solid #243B5C; color:#243B5C; background:#fff; border-radius:12px; text-decoration:none; text-align:center; font-weight:600; transition:all 0.15s;"
           onmouseover="this.style.background='#243B5C'; this.style.color='#fff';"
           onmouseout="this.style.background='#fff'; this.style.color='#243B5C';">
          <div style="font-size:24px; margin-bottom:4px;">📰</div>
          <div style="font-size:14px;">Affiche vitrine</div>
          <div style="font-size:11px; opacity:0.7; margin-top:2px;">A4 portrait — photo héro + prix</div>
        </a>
        <a href="<?= htmlspecialchars($dashUrl . '&type=fiche_client') ?>"
           style="display:block; padding:14px 16px; border:2px solid #243B5C; color:#243B5C; background:#fff; border-radius:12px; text-decoration:none; text-align:center; font-weight:600; transition:all 0.15s;"
           onmouseover="this.style.background='#243B5C'; this.style.color='#fff';"
           onmouseout="this.style.background='#fff'; this.style.color='#243B5C';">
          <div style="font-size:24px; margin-bottom:4px;">📄</div>
          <div style="font-size:14px;">Fiche client</div>
          <div style="font-size:11px; opacity:0.7; margin-top:2px;">Multi-page — descriptif + galerie</div>
        </a>
        <a href="<?= htmlspecialchars($dashUrl . '&type=fiche_visite_interne') ?>"
           style="display:block; padding:14px 16px; border:2px solid #a85858; color:#a85858; background:#fff; border-radius:12px; text-decoration:none; text-align:center; font-weight:600; transition:all 0.15s;"
           onmouseover="this.style.background='#a85858'; this.style.color='#fff';"
           onmouseout="this.style.background='#fff'; this.style.color='#a85858';">
          <div style="font-size:24px; margin-bottom:4px;">🔒</div>
          <div style="font-size:14px;">Fiche visite interne</div>
          <div style="font-size:11px; opacity:0.7; margin-top:2px;">Filigrane — coaching négo</div>
        </a>
      </div>

      <!-- Lien vers dashboard -->
      <div style="text-align:center; padding-top:8px;">
        <a href="<?= htmlspecialchars($dashUrl) ?>"
           style="display:inline-block; padding:10px 22px; background:#243B5C; color:#fff; border-radius:999px; text-decoration:none; font-weight:600; font-size:13px;">
          Ouvrir le module Communication →
        </a>
      </div>

    </div>
  </div>
</section>
