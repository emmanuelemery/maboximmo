<?php
$actionbar = $actionbar ?? [];

$show_back   = $actionbar['show_back'] ?? true;
$show_home   = $actionbar['show_home'] ?? true;
$home_link   = $actionbar['home_link'] ?? '/dashboard.php';
$actions_left = $actionbar['left'] ?? [];
$actions_right = $actionbar['right'] ?? [];
?>

<div class="actionbar">
    <div class="actionbar-left">
        <?php if ($show_back): ?>
            <button type="button" class="btn" onclick="history.back()">⬅ Page précédente</button>
        <?php endif; ?>

        <?php if ($show_home): ?>
            <a href="<?= htmlspecialchars($home_link) ?>" class="btn">🏠 Retour accueil</a>
        <?php endif; ?>

        <?php foreach ($actions_left as $item): ?>
            <?php if (($item['type'] ?? 'link') === 'dropdown'): ?>
                <div class="dropdown">
                    <button type="button" class="btn <?= !empty($item['primary']) ? 'btn-primary' : '' ?> dropdown-toggle">
                        <?= htmlspecialchars($item['label']) ?>
                    </button>
                    <div class="dropdown-menu">
                        <?php foreach (($item['items'] ?? []) as $sub): ?>
                            <a href="<?= htmlspecialchars($sub['url']) ?>">
                                <?= htmlspecialchars($sub['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['url'] ?? '#') ?>" class="btn <?= !empty($item['primary']) ? 'btn-primary' : '' ?>">
                    <?= htmlspecialchars($item['label'] ?? 'Action') ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="actionbar-right">
        <?php foreach ($actions_right as $item): ?>
            <?php if (($item['type'] ?? 'link') === 'dropdown'): ?>
                <div class="dropdown">
                    <button type="button" class="btn <?= !empty($item['primary']) ? 'btn-primary' : '' ?> dropdown-toggle">
                        <?= htmlspecialchars($item['label']) ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <?php foreach (($item['items'] ?? []) as $sub): ?>
                            <a href="<?= htmlspecialchars($sub['url']) ?>">
                                <?= htmlspecialchars($sub['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['url'] ?? '#') ?>" class="btn <?= !empty($item['primary']) ? 'btn-primary' : '' ?>">
                    <?= htmlspecialchars($item['label'] ?? 'Action') ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>