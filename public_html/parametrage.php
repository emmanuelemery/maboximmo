<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
if (!in_array((int)current_role_id(), [1], true) && !is_super_admin()) {
    http_response_code(403); exit('Accès refusé.');
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paramétrage — MaBoxImmo</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="/public_html/css/theme-rh.css">
  <link rel="stylesheet" href="/public_html/css/minicard.css">
  <?php include __DIR__ . '/inc/theme-init.php'; ?>
  <style>
    .pg-hero { padding: 40px 0 24px; }
    .pg-hero h1 { font-size: 28px; font-weight: 800; margin: 0 0 6px; }
    .pg-hero p  { font-size: 14px; color: var(--muted); margin: 0; }

    .pg-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 16px;
      margin-top: 32px;
    }

    .pg-card {
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding: 24px 20px 20px;
      border-radius: 16px;
      border: 1.5px solid #ffffff;
      background: #f7f8fa;
      text-decoration: none;
      color: var(--ink);
      transition: border-color .18s, background .18s, box-shadow .18s, transform .12s;
    }
    .pg-card:hover {
      border-color: rgba(102,217,255,0.35);
      background: rgba(102,217,255,0.07);
      box-shadow: 0 6px 24px rgba(0,0,0,0.28);
      transform: translateY(-3px);
      color: var(--ink);
      text-decoration: none;
    }
    .pg-card-icon { font-size: 32px; line-height: 1; }
    .pg-card-label { font-size: 16px; font-weight: 700; }
    .pg-card-desc  { font-size: 12px; color: var(--muted); line-height: 1.5; }
    .pg-card-arrow { font-size: 12px; color: var(--muted); margin-top: auto; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/inc/sidebar.php'; ?>

  <main class="mbi-main">
    <div class="mbi-topbar">
      <h1>🎛️ Paramétrage</h1>
    </div>

    <div class="mbi-content">
      <div class="pg-hero">
        <h1>Paramétrage société</h1>
        <p>Gérez les listes métier utilisées dans vos formulaires immobiliers. Chaque rubrique peut être personnalisée par société.</p>
      </div>

      <div class="pg-grid">
        <a href="/public_html/admin/param_vues.php" class="pg-card">
          <div class="pg-card-icon">👁️</div>
          <div class="pg-card-label">Vues</div>
          <div class="pg-card-desc">Dégagée, panoramique, mer, jardin… Gérez les types de vue associables aux biens.</div>
          <div class="pg-card-arrow">Gérer →</div>
        </a>

        <a href="/public_html/admin/param_types_bien.php" class="pg-card">
          <div class="pg-card-icon">🏠</div>
          <div class="pg-card-label">Types de bien</div>
          <div class="pg-card-desc">Appartement, maison, villa, local commercial… Définissez les catégories de biens disponibles.</div>
          <div class="pg-card-arrow">Gérer →</div>
        </a>

        <a href="/public_html/admin/param_dependances.php" class="pg-card">
          <div class="pg-card-icon">🏗️</div>
          <div class="pg-card-label">Dépendances &amp; Extérieurs</div>
          <div class="pg-card-desc">Cave, terrasse, garage, piscine… Gérez les dépendances et espaces extérieurs associés aux types de bien.</div>
          <div class="pg-card-arrow">Gérer →</div>
        </a>

        <a href="/public_html/admin/param_chauffage.php" class="pg-card">
          <div class="pg-card-icon">🔥</div>
          <div class="pg-card-label">Chauffage &amp; Énergies</div>
          <div class="pg-card-desc">Radiateur, pompe à chaleur, gaz, solaire… Gérez les types de chauffage et leurs énergies associées.</div>
          <div class="pg-card-arrow">Gérer →</div>
        </a>
      </div>
    </div>
  </main>
</body>
</html>
