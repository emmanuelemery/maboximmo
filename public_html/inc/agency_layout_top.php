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
// Sidebar : nom sans .php, fichier attendu dans inc/. Fallback = sidebar_agency.
$layoutSidebar = $layoutSidebar ?? 'sidebar_agency';
$_lySidebarFile = __DIR__ . '/' . basename((string)$layoutSidebar) . '.php';
if (!is_file($_lySidebarFile)) $_lySidebarFile = __DIR__ . '/sidebar_agency.php';

// Modes layout (inactifs par défaut) :
//   $layoutEmbed     : page embarquée (iframe) → ni sidebar ni topbar (déclenché aussi par ?embed=1)
//   $layoutNoSidebar : masque uniquement la sidebar (topbar conservée) — pour les pages "hub" à onglets
$layoutEmbed     = $layoutEmbed     ?? ((($_GET['embed'] ?? '') === '1'));
$layoutNoSidebar = $layoutNoSidebar ?? $layoutEmbed;
// Pages embarquées (iframes du hub) : jamais mises en cache (évite le double-layout sur version périmée).
if ($layoutEmbed && !headers_sent()) { header('Cache-Control: no-store, no-cache, must-revalidate'); }

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
    gap: 10px;
    margin-bottom: 14px;   /* compact : ~50% moins haut */
}
.agency-topbar .tb-nav-btn {
    width: 32px; height: 32px;
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
.agency-topbar .tb-title-block { display: flex; flex-direction: row; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.agency-topbar .tb-icon { align-self: center; font-size: 22px; line-height: 1; }
.agency-topbar .tb-title {
    font-family: 'Sora', sans-serif;
    font-size: 17px; font-weight: 800; color: #2c2a28; line-height: 1.1;
}
.agency-topbar .tb-subtitle {
    font-family: 'DM Mono', monospace;
    font-size: 11px; color: #9a9690;
}
.agency-topbar .tb-spacer { margin-left: auto; }
.agency-topbar .tb-center { flex: 1; display: flex; justify-content: center; align-items: center; }
.agency-topbar .tb-actions { display: flex; align-items: center; gap: 10px; }
.agency-topbar .tb-avatar {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #4878a6, #8eb4d3);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800; color: #fff;
}
.agency-topbar .tb-logout {
    width: 36px; height: 36px; flex-shrink: 0;
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    background: #fff; color: #b3261e; text-decoration: none;
    border: 1px solid #ece7df;
    transition: background .15s, color .15s, border-color .15s;
}
.agency-topbar .tb-logout:hover { background: #fdecea; border-color: #f0c8c4; }

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
/* Boutons « Charger » FluxBox (topbar + flottant) masqués partout — l'upload reste accessible
   via les cards Actions des fiches + raccourci Ctrl+U (la modale fbxOpenUploadModal reste active). */
#fbx-upload-open, #fbx-upload-fab { display: none !important; }
</style>
<?= $extraCss ?>
<?php if ($layoutNoSidebar): ?>
<style>.agency-content{ margin-left:0 !important; } .mbi-sidebar,.agency-sidebar{ display:none !important; }
/* Module à onglets : pas de bouton "Charger" (upload) qui flotte/double */
#fbx-upload-fab, #fbx-upload-open{ display:none !important; }</style>
<?php endif; ?>
<?php if ($layoutEmbed): ?>
<style>
.agency-topbar{ display:none !important; }
.agency-content{ padding-top:0 !important; }
/* Le titre est porté par la top bar + l'onglet du hub : on masque le hero/titre en double dans le contenu embarqué */
.pf-hero{ display:none !important; }
/* Pas de bouton "Charger" (upload) flottant dans les iframes */
#fbx-upload-fab, #fbx-upload-open{ display:none !important; }
</style>
<script>
// Mode embarqué (iframe du hub) : on propage embed=1 (+ bailleur) à tous les liens/formulaires
// internes, pour que la navigation DANS l'onglet reste sans cadre (pas de double layout).
document.addEventListener('DOMContentLoaded', function(){
    var qs = new URLSearchParams(location.search);
    if (qs.get('embed') !== '1') return;
    var bailleur = qs.get('bailleur');
    function withEmbed(href){
        try{
            var u = new URL(href, location.href);
            if (u.origin !== location.origin) return href;
            if (!/\.php$/i.test(u.pathname)) return href;
            u.searchParams.set('embed','1');
            if (bailleur) u.searchParams.set('bailleur', bailleur);
            return u.pathname + '?' + u.searchParams.toString() + (u.hash||'');
        }catch(e){ return href; }
    }
    document.querySelectorAll('a[href]').forEach(function(a){
        var h = a.getAttribute('href');
        if (!h || h.charAt(0)==='#' || /^(javascript:|mailto:|tel:)/i.test(h)) return;
        if (a.target === '_blank') return;            // liens "nouvel onglet" (ex: fiche 360) → page pleine
        a.setAttribute('href', withEmbed(a.href));
    });
    document.querySelectorAll('form').forEach(function(f){
        var m = (f.getAttribute('method')||'get').toLowerCase();
        if (m !== 'get') return;                      // POST → APIs via fetch, non concernés
        if (!f.querySelector('input[name="embed"]')){ var i=document.createElement('input'); i.type='hidden'; i.name='embed'; i.value='1'; f.appendChild(i); }
        if (bailleur && !f.querySelector('input[name="bailleur"]')){ var b=document.createElement('input'); b.type='hidden'; b.name='bailleur'; b.value=bailleur; f.appendChild(b); }
    });
});
</script>
<?php endif; ?>
</head>
<body <?= $bodyAttr ?>>

<?php if (!empty($_SESSION['impersonator'])): ?>
<div style="position:sticky;top:0;z-index:9999;background:linear-gradient(90deg,#7a4010,#c47a30);
            color:#fff;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;
            padding:7px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.2);">
  <span>🎭 Vous naviguez en tant que
    <strong><?= htmlspecialchars(trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))) ?></strong>
    <span style="opacity:.85;font-weight:400;">— compte bailleur (vue de test)</span>
  </span>
  <a href="<?= htmlspecialchars(app_url('/bailleur_impersonate.php?stop=1')) ?>"
     style="margin-left:auto;background:#fff;color:#7a4010;text-decoration:none;
            padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;">
    ↩ Revenir à mon compte
  </a>
</div>
<?php endif; ?>

<?php if (!$layoutNoSidebar) include $_lySidebarFile; ?>

<div class="agency-content">

    <?php if (!$layoutEmbed): ?>
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
            <?php if ($pageIcon !== ''): ?><span class="tb-icon"><?= htmlspecialchars($pageIcon) ?></span><?php endif; ?>
            <div class="tb-title"><?= htmlspecialchars($pageTitle) ?></div>
            <div class="tb-subtitle"><?= htmlspecialchars($pageSubtitle) ?></div>
        </div>
        <div class="tb-center"><?php if (!empty($topbarCenter)) echo $topbarCenter; ?></div>
        <div class="tb-actions">
            <?php if (!empty($topbarActions)) echo $topbarActions; ?>
            <div class="tb-avatar" title="<?= htmlspecialchars(trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) ?: 'Mon compte') ?>"><?= htmlspecialchars($_agInitials) ?></div>
            <a href="<?= htmlspecialchars(app_url('/accueil.php')) ?>" title="Nos services pro & particuliers" aria-label="Nos services" style="font-size:11px;color:#9aa3ad;text-decoration:none;white-space:nowrap;">Nos services</a>
            <a href="<?= htmlspecialchars(app_url('/logout.php')) ?>" class="tb-logout" title="Se déconnecter" aria-label="Se déconnecter">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>
    <!-- /Topbar -->
    <?php endif; /* layoutEmbed */ ?>
