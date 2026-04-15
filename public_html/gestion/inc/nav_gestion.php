<?php
/**
 * nav_gestion.php — Sidebar module gestion locative
 * Variable attendue : $current_page, $nav_context ('admin'|'sir'|'proprio')
 */
$current_page = $current_page ?? '';
$nav_context  = $nav_context ?? 'admin';

$nav_items = [
    'admin' => [
        'label'   => 'Régie Emery — Gestion',
        'items'   => [
            ['page' => 'upload_crg',    'href' => app_url('/gestion/upload_crg.php'),    'icon' => '📄', 'label' => 'Import CRG'],
            ['page' => 'dashboard_crg', 'href' => app_url('/gestion/dashboard_crg.php'), 'icon' => '📊', 'label' => 'Tableau CRG'],
            ['page' => 'config_ia',     'href' => app_url('/gestion/config_ia.php'),     'icon' => '🤖', 'label' => 'Config IA'],
        ],
    ],
    'sir' => [
        'label'   => 'Groupe SIR & SABY',
        'items'   => [
            ['page' => 'dashboard_sir',  'href' => app_url('/gestion/dashboard_sir.php'),  'icon' => '📊', 'label' => 'Tableau de bord'],
            ['page' => 'patrimoine_sir','href' => app_url('/gestion/patrimoine_sir.php'),'icon' => '🏠', 'label' => 'Patrimoine'],
            ['page' => 'analyses_sir',  'href' => app_url('/gestion/analyses_sir.php'),  'icon' => '📈', 'label' => 'Analyses'],
            ['page' => 'assistant_ia',  'href' => app_url('/gestion/assistant_ia.php'),  'icon' => '🤖', 'label' => 'Assistant IA'],
        ],
    ],
    'proprio' => [
        'label'   => 'Espace Propriétaire',
        'items'   => [
            ['page' => 'dashboard_proprio', 'href' => app_url('/dashboard_proprietaire.php'), 'icon' => '📊', 'label' => 'Tableau de bord'],
            ['page' => 'assistant_ia',      'href' => app_url('/gestion/assistant_ia.php'),   'icon' => '🤖', 'label' => 'Assistant IA'],
        ],
    ],
];

$ctx = $nav_items[$nav_context] ?? $nav_items['admin'];
?>
<div class="sb-section" style="margin-top:12px;">
  <div class="sb-section-title" style="padding:8px 20px;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-tertiary,var(--gray-500));">
    <?= htmlspecialchars($ctx['label']) ?>
  </div>
  <?php foreach ($ctx['items'] as $item): ?>
    <a href="<?= $item['href'] ?>"
       class="sb-link<?= $current_page === $item['page'] ? ' active' : '' ?>"
       style="display:flex;align-items:center;gap:10px;padding:9px 20px;font-size:13px;color:var(--text-secondary,var(--gray-600));text-decoration:none;border-left:2px solid transparent;transition:all .15s;<?= $current_page === $item['page'] ? 'color:var(--brand-primary);border-left-color:var(--brand-primary);background:var(--brand-primary-light,var(--gray-100));' : '' ?>">
      <span><?= $item['icon'] ?></span> <?= $item['label'] ?>
    </a>
  <?php endforeach; ?>
</div>
