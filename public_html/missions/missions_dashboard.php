<?php
declare(strict_types=1);
/**
 * missions/missions_dashboard.php — TABLEAU DE BORD des missions (assistant métier).
 * Point d'entrée de la « deuxième branche » UX : une tuile par mission → ouvre le parcours.
 * ADMIN ONLY (déploiement restreint). Réutilise le socle mission (_mission_head/_foot).
 * Voir project_vision_missions_paradigme + project_mission_layout_socle.
 */
define('MBI', true);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

// Gate admin : la fonctionnalité missions n'est ouverte qu'aux admin/super admin.
$__admin = function_exists('is_admin_or_super_admin')
    ? is_admin_or_super_admin()
    : ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$__admin) {
    header('Location: ' . (function_exists('app_url') ? app_url('/agency_dashboard.php') : '/'));
    exit;
}

$pageTitle = 'Missions';
require __DIR__ . '/_mission_head.php';
?>
<div class="shell">

  <header class="hero">
    <div class="hero-bg"></div>
    <div class="hero-row">
      <div class="hero-mid">
        <div class="eyebrow"><span>Assistant métier · Parcours autoguidés</span></div>
        <div class="instance">Choisissez une mission — le logiciel vous guide étape par étape.</div>
      </div>
    </div>
  </header>

  <div class="mgrid">

    <a class="mtile" style="--blk:var(--c-immeuble)" href="<?= h($au('/missions/mettre_bien_location.php')) ?>">
      <div class="mt-ic">🔑</div>
      <h3>Mettre un bien en location</h3>
      <p>Immeuble → propriétaire → bien → annonce → visites → dossier, bail &amp; EDL.</p>
      <div class="mt-foot"><span class="mt-go">Démarrer →</span></div>
    </a>

    <span class="mtile soon" style="--blk:var(--c-bien)">
      <div class="mt-ic">🏷️</div>
      <h3>Vendre un bien</h3>
      <p>Mandat, avis de valeur, diffusion, offres, compromis, acte.</p>
      <div class="mt-foot"><span class="mt-badge">Bientôt</span></div>
    </span>

    <span class="mtile soon" style="--blk:var(--c-tiers)">
      <div class="mt-ic">📄</div>
      <h3>Renouveler / réviser un bail</h3>
      <p>Révision de loyer, avenant, renouvellement, congé.</p>
      <div class="mt-foot"><span class="mt-badge">Bientôt</span></div>
    </span>

    <span class="mtile soon" style="--blk:var(--c-bailleur)">
      <div class="mt-ic">🛠️</div>
      <h3>Gérer un sinistre</h3>
      <p>Déclaration, expert, devis, suivi travaux, clôture.</p>
      <div class="mt-foot"><span class="mt-badge">Bientôt</span></div>
    </span>

  </div>

</div>
<?php require __DIR__ . '/_mission_foot.php'; ?>
