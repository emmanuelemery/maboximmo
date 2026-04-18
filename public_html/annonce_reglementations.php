<?php
declare(strict_types=1);

/**
 * annonce_reglementations.php — Référentiel métier interne
 * Centralise toutes les obligations légales par type de mandat.
 * Source alimentée par inc/annonce_reglementation.php (single source of truth).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/annonce_reglementation.php';
require_login();

$appLayout       = true;
$pageTitle       = 'Réglementations annonces — Référentiel métier';
$robots          = 'noindex, nofollow';

$communes = annonce_regles_communes();
$mandats  = ['vente', 'location_seule', 'gestion_locative'];
$regles   = [];
foreach ($mandats as $m) {
    $regles[$m] = annonce_regles_par_mandat($m);
}

require_once __DIR__ . '/inc/header.php';
?>
<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset_url('/css/tokens.css')) ?>">

<style>
  :root {
    --bg: var(--bg-secondary, #f7f8fa);
    --card: var(--bg-primary, #ffffff);
    --ink: var(--text-primary, #1a1816);
    --muted: var(--text-secondary, #6a6660);
    --accent: var(--brand-primary, #36577d);
    --stroke: var(--border-light, #e4e6ec);
    --sidebar-w: 220px;
    --topbar-h: 56px;
    --neu-out: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #ffffff);
    --neu-in:  inset 4px 4px 10px var(--shadow-dark, #d4d7de), inset -4px -4px 10px var(--shadow-light, #ffffff);
  }
  body { font-family: 'Sora', system-ui, sans-serif; background: var(--bg); color: var(--ink); margin: 0; }

  .mbi-main { margin-left: var(--sidebar-w); min-height: 100vh; display: flex; flex-direction: column; }
  @media (max-width: 900px) { .mbi-main { margin-left: 0; } }

  .mbi-topbar { position: sticky; top: 0; height: var(--topbar-h); background: var(--card);
    box-shadow: 0 2px 8px var(--shadow-dark, #d4d7de); border-bottom: 1px solid var(--stroke);
    display: flex; align-items: center; gap: 10px; padding: 0 24px; z-index: 50; }
  .topbar-nav-btn { width: 34px; height: 34px; border-radius: 8px; background: var(--bg); border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center; box-shadow: var(--neu-out); color: var(--muted); flex-shrink: 0; }
  .topbar-nav-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
  .topbar-gap { width: 40px; flex-shrink: 0; }
  .topbar-breadcrumb { font-size: 13px; font-weight: 500; color: var(--muted); }
  .topbar-breadcrumb a { color: var(--muted); text-decoration: none; }
  .topbar-breadcrumb a:hover { color: var(--ink); }
  .topbar-breadcrumb .sep { color: var(--stroke); margin: 0 6px; }
  .topbar-breadcrumb .active { color: var(--accent); font-weight: 600; }
  .topbar-spacer { flex: 1; }

  .mbi-container { padding: 24px 32px 80px; max-width: 1200px; width: 100%; }

  .page-head { padding: 6px 0 18px; }
  .page-head-label { font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500; letter-spacing: 1.2px;
    text-transform: uppercase; color: #7a9060; margin-bottom: 4px; }
  .page-head-title { font-size: 24px; font-weight: 700; margin: 0 0 6px; }
  .page-head-sub { font-size: 13px; color: var(--muted); max-width: 720px; line-height: 1.6; }

  /* Table of contents flottant */
  .reg-toc { position: sticky; top: calc(var(--topbar-h) + 10px); float: right; width: 200px;
    margin-left: 20px; padding: 12px 14px; background: var(--card); border-radius: 10px;
    box-shadow: var(--neu-out); font-size: 12px; }
  .reg-toc h4 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: .04em; }
  .reg-toc a { display: block; padding: 4px 0; color: var(--ink); text-decoration: none; }
  .reg-toc a:hover { color: var(--accent); }

  .reg-section { background: var(--card); border-radius: 14px; padding: 22px 26px; margin-bottom: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,.04); border: 1px solid var(--stroke); }
  .reg-section h2 { margin: 0 0 14px; font-size: 18px; display: flex; align-items: center; gap: 10px; }
  .reg-badge { font-size: 11px; padding: 3px 10px; border-radius: 99px; color: #fff; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
  .reg-badge.renforcee { background: #c0392b; }
  .reg-badge.standard  { background: var(--accent); }

  .reg-block { margin-top: 16px; }
  .reg-block-title { font-weight: 700; font-size: 13px; color: var(--accent); text-transform: uppercase;
    letter-spacing: .04em; margin-bottom: 8px; border-bottom: 2px solid var(--stroke); padding-bottom: 4px; }
  .reg-block-title.warn { color: #b67c00; }
  .reg-block-title.danger { color: #c0392b; }
  .reg-block ul { margin: 0; padding-left: 22px; line-height: 1.7; font-size: 13px; }
  .reg-block li { margin-bottom: 4px; }
  .reg-block li code { font-family: 'DM Mono', monospace; font-size: 11px; background: var(--bg); padding: 1px 6px; border-radius: 4px; color: var(--accent); }
  .reg-block.mentions-interdites li { color: #991b1b; }
  .reg-block.points-vigilance li { color: #92400e; }

  .reg-checklist { background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 14px 18px; }
  .reg-checklist li { color: #166534; padding: 3px 0; list-style: none; position: relative; padding-left: 26px; }
  .reg-checklist li::before { content: '✓'; position: absolute; left: 0; top: 3px; color: #16a34a; font-weight: 800; font-size: 14px; }

  .reg-responsabilite-banner { padding: 12px 18px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; font-weight: 600; }
  .reg-responsabilite-banner.standard { background: #f0f9ff; color: #0369a1; border-left: 4px solid #0ea5e9; }
  .reg-responsabilite-banner.renforcee { background: #fef2f2; color: #991b1b; border-left: 4px solid #dc2626; }
</style>

<main class="mbi-main">
  <header class="mbi-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <a href="<?= h(app_url('/bien_liste.php')) ?>">Biens</a>
      <span class="sep">›</span>
      <span class="active">⚖️ Réglementations annonces</span>
    </nav>
    <div class="topbar-spacer"></div>
  </header>

  <div class="mbi-container">
    <!-- Menu de navigation flottant -->
    <nav class="reg-toc" id="reg-toc">
      <h4>Sommaire</h4>
      <a href="#vue-ensemble">Vue d'ensemble</a>
      <a href="#communes">Règles communes</a>
      <a href="#vente">🤝 Mandat de vente</a>
      <a href="#location">🔑 Location seule</a>
      <a href="#gestion">🏢 Gestion locative</a>
    </nav>

    <!-- Page head -->
    <div class="page-head">
      <div class="page-head-label">Référentiel métier</div>
      <h1 class="page-head-title">⚖️ Réglementations des annonces immobilières</h1>
      <p class="page-head-sub">
        Référentiel métier centralisé des obligations légales à respecter selon le type de mandat.
        Cette page est la source de vérité unique utilisée par le moteur de création d'annonces
        (<code>annonce_nouvelle.php</code>) pour adapter les champs, les alertes et les contrôles.
      </p>
    </div>

    <!-- VUE D'ENSEMBLE -->
    <section class="reg-section" id="vue-ensemble">
      <h2>📋 Vue d'ensemble</h2>
      <p style="line-height:1.7;font-size:13px;color:var(--muted);">
        Toute annonce immobilière est soumise à un socle d'obligations légales (loi Hoguet, loi ALUR,
        arrêtés sur les honoraires, décrets sur la décence, etc.) + des obligations spécifiques selon
        le type de mandat confié par le propriétaire.
      </p>
      <p style="line-height:1.7;font-size:13px;color:var(--muted);">
        L'agence engage sa responsabilité professionnelle (carte T, assurance RCP) à chaque diffusion.
        La <strong>gestion locative</strong> en particulier impose une responsabilité renforcée
        (conseil au bailleur, vérification décence, diagnostics, assurance PNO…).
      </p>
    </section>

    <!-- RÈGLES COMMUNES -->
    <section class="reg-section" id="communes">
      <h2>🧩 Règles communes à tous les mandats</h2>
      <p style="font-size:12px;color:var(--muted);margin:0 0 16px;">Socle minimum applicable à toute annonce quel que soit le type de mandat.</p>

      <div class="reg-block">
        <div class="reg-block-title">🔒 Champs bloquants (publication impossible sans)</div>
        <ul>
          <?php foreach ($communes['champs_bloquants'] as $c => $msg): ?>
            <li><code><?= h($c) ?></code> — <?= h($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block">
        <div class="reg-block-title warn">⚠️ Alertes (à renseigner)</div>
        <ul>
          <?php foreach ($communes['champs_alertes'] as $c => $msg): ?>
            <li><code><?= h($c) ?></code> — <?= h($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block mentions-interdites">
        <div class="reg-block-title danger">🚫 Mentions interdites</div>
        <ul>
          <?php foreach ($communes['mentions_interdites'] as $m): ?>
            <li><?= h($m) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block points-vigilance">
        <div class="reg-block-title warn">⚠️ Points de vigilance</div>
        <ul>
          <?php foreach ($communes['points_vigilance'] as $p): ?>
            <li><?= h($p) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <?php foreach ($regles as $code => $r):
        $anchor = ($code === 'vente') ? 'vente' : (($code === 'location_seule') ? 'location' : 'gestion');
        $isRenforce = $r['responsabilite'] === 'RENFORCÉE';
    ?>
    <section class="reg-section" id="<?= h($anchor) ?>" style="border-left: 4px solid <?= h($r['color']) ?>;">
      <h2>
        <?= h($r['emoji']) ?> <?= h($r['label']) ?>
        <span class="reg-badge <?= $isRenforce ? 'renforcee' : 'standard' ?>">
          <?= $isRenforce ? 'Responsabilité renforcée' : 'Standard' ?>
        </span>
      </h2>

      <?php if ($isRenforce): ?>
      <div class="reg-responsabilite-banner renforcee">
        ⚠️ <strong>Responsabilité RENFORCÉE</strong> — Le gestionnaire engage sa responsabilité civile
        professionnelle en cas de défaut de conseil, de non-décence ou d'omission de diagnostic obligatoire.
      </div>
      <?php else: ?>
      <div class="reg-responsabilite-banner standard">
        ℹ️ Responsabilité standard d'agent immobilier (loi Hoguet, carte T).
      </div>
      <?php endif; ?>

      <div class="reg-block">
        <div class="reg-block-title">🔒 Champs bloquants</div>
        <ul>
          <?php
            // Ne montrer que les bloquants SPÉCIFIQUES (hors communs)
            $specifiques = array_diff_key($r['champs_bloquants'], $communes['champs_bloquants']);
            if (empty($specifiques)) echo '<li style="color:var(--muted);font-style:italic;">Aucun bloquant supplémentaire par rapport aux règles communes.</li>';
            foreach ($specifiques as $c => $msg): ?>
            <li><code><?= h($c) ?></code> — <?= h($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block">
        <div class="reg-block-title warn">⚠️ Alertes spécifiques</div>
        <ul>
          <?php
            $alertesSpec = array_diff_key($r['champs_alertes'], $communes['champs_alertes']);
            if (empty($alertesSpec)) echo '<li style="color:var(--muted);font-style:italic;">Mêmes alertes que les règles communes.</li>';
            foreach ($alertesSpec as $c => $msg): ?>
            <li><code><?= h($c) ?></code> — <?= h($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block mentions-interdites">
        <div class="reg-block-title danger">🚫 Mentions interdites spécifiques</div>
        <ul>
          <?php
            $mentionsSpec = array_diff($r['mentions_interdites'], $communes['mentions_interdites']);
            if (empty($mentionsSpec)) echo '<li style="color:var(--muted);font-style:italic;">Mêmes mentions interdites que les règles communes.</li>';
            foreach ($mentionsSpec as $m): ?>
            <li><?= h($m) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block points-vigilance">
        <div class="reg-block-title warn">⚠️ Points de vigilance</div>
        <ul>
          <?php
            $vigSpec = array_diff($r['points_vigilance'], $communes['points_vigilance']);
            if (empty($vigSpec)) echo '<li style="color:var(--muted);font-style:italic;">Mêmes points de vigilance que les règles communes.</li>';
            foreach ($vigSpec as $p): ?>
            <li><?= h($p) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="reg-block">
        <div class="reg-block-title">✅ Checklist opérationnelle avant publication</div>
        <ul class="reg-checklist">
          <?php foreach ($r['checklist'] as $c): ?>
            <li><?= h($c) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
    <?php endforeach; ?>

    <p style="margin:40px 0 0;font-size:11px;color:var(--muted);text-align:center;">
      Source : <code>public_html/inc/annonce_reglementation.php</code> — modifier ici pour impacter tout le projet.
    </p>
  </div>
</main>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
