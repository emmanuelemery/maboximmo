<?php
/**
 * inc/agency_layout_top.php — Layout normalisé pour toutes les pages agency_*
 *
 * Variables à définir AVANT d'inclure ce fichier :
 *   $pageTitle   (string)  — titre de la page, ex: "Contrats Syndic"
 *   $pageSubtitle(string)  — sous-titre topbar, ex: "Ma Box Agency · Syndic"
 *   $pageIcon    (string)  — SVG path(s) pour l'icône topbar (optionnel)
 *   $extraCss    (string)  — balises <link> ou <style> supplémentaires (optionnel)
 *   $bodyAttr    (string)  — attribut data-* sur <body> ex: 'data-theme-module="syndic"' (optionnel)
 *
 * Ce fichier ouvre : <!DOCTYPE html> … <div class="sb-content"> … topbar
 * Fermeture par : inc/agency_layout_bottom.php
 */
declare(strict_types=1);

$pageTitle    = $pageTitle    ?? 'Ma Box Agency';
$pageSubtitle = $pageSubtitle ?? 'Ma Box Agency';
$pageIcon     = $pageIcon     ?? '';
$extraCss     = $extraCss     ?? '';
$bodyAttr     = $bodyAttr     ?? '';

// Initiales utilisateur
$_agInitials = strtoupper(
    mb_substr($_SESSION['prenom'] ?? $_SESSION['nom'] ?? 'U', 0, 1) .
    mb_substr($_SESSION['nom'] ?? '', 0, 1)
);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php if (function_exists('app_url')): ?>
<base href="<?= htmlspecialchars(app_url('/')) ?>">
<?php endif; ?>
<title><?= htmlspecialchars($pageTitle) ?> — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php
// Chemins CSS via asset_url() pour fonctionner depuis n'importe quel sous-dossier
// (ex: public_html/admin/admin_*.php). Fallback sur chemins absolus si asset_url absent.
$_lyCss = static fn(string $p): string => function_exists('asset_url') ? asset_url($p) : $p;
?>
<link rel="stylesheet" href="<?= htmlspecialchars($_lyCss('/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($_lyCss('/css/base.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($_lyCss('/css/components.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($_lyCss('/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($_lyCss('/css/theme-syndic.css')) ?>">
<style>
/* ── Agency layout normalisé ──────────────────────────────────── */
body { margin:0; background:#ffffff; font-family:'Sora',sans-serif; }

.agency-content {
    margin-left: 248px;
    padding: 28px;
    min-height: 100vh;
    overflow-y: auto;
    box-sizing: border-box;
}

/* Topbar */
.agency-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 28px;
}
.agency-topbar .tb-nav-btn {
    width: 36px; height: 36px;
    border-radius: 10px; border: none; cursor: pointer;
    background: #ffffff;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
    color: #9a9690; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: box-shadow .15s;
    flex-shrink: 0;
}
.agency-topbar .tb-nav-btn:hover {
    box-shadow: 2px 2px 5px #c8c4be, -2px -2px 5px #fff;
}
.agency-topbar .tb-gap { width: 50px; flex-shrink: 0; }
.agency-topbar .tb-title-block { display: flex; flex-direction: column; gap: 2px; }
.agency-topbar .tb-title {
    font-family: 'Sora', sans-serif;
    font-size: 22px; font-weight: 800; color: #2c2a28; line-height: 1.1;
}
.agency-topbar .tb-subtitle {
    font-family: 'DM Mono', monospace;
    font-size: 11px; color: #9a9690;
}
.agency-topbar .tb-spacer { margin-left: auto; }
.agency-topbar .tb-actions { display: flex; align-items: center; gap: 10px; }
.agency-topbar .tb-avatar {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #4878a6, #8eb4d3);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800; color: #fff;
}

/* Bouton icône standard */
.btn-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 10px; border: none; cursor: pointer;
    background: #ffffff;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
    color: #4878a6; text-decoration: none;
    transition: box-shadow .15s;
}
.btn-icon:hover { box-shadow: 2px 2px 5px #c8c4be, -2px -2px 5px #fff; }
.btn-icon svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
</style>
<?= $extraCss ?>
</head>
<body <?= $bodyAttr ?>>

<?php include __DIR__ . '/sidebar_agency.php'; ?>

<div class="agency-content">

    <!-- ── Topbar ── -->
    <div class="agency-topbar">
        <button class="tb-nav-btn" onclick="history.back()" title="Retour">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button class="tb-nav-btn" onclick="history.forward()" title="Avancer">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <div class="tb-gap"></div>
        <div class="tb-title-block">
            <div class="tb-title"><?= htmlspecialchars($pageTitle) ?></div>
            <div class="tb-subtitle"><?= htmlspecialchars($pageSubtitle) ?></div>
        </div>
        <div class="tb-spacer"></div>
        <div class="tb-actions">
            <?php if (!empty($topbarActions)) echo $topbarActions; ?>
            <div class="tb-avatar"><?= htmlspecialchars($_agInitials) ?></div>
        </div>
    </div>
    <!-- /Topbar -->
