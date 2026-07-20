<?php
declare(strict_types=1);
/**
 * _mission_head.php — EN-TÊTE PARTAGÉ des pages mission (la « deuxième branche » UX).
 *   Charge le socle mission UNE SEULE FOIS (règle d'or) : auth + agence + tokens/composants/
 *   bureau/layout + polices, puis ouvre le conteneur .mbi-mission.
 *   Chaque page mission = define('MBI') + $pageTitle + require ce head + son HTML + require foot.
 *
 * PARTIAL PROTÉGÉ : jamais accessible seul par URL (garde MBI + .htaccess du dossier).
 */
defined('MBI') or exit(http_response_code(403));

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$au     = static fn(string $p) => function_exists('app_url')   ? app_url($p)   : $p;
$assets = static fn(string $p) => function_exists('asset_url') ? asset_url($p) : $p;

$GOOGLE_MAPS_API_KEY = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');

// Agence active (attribution bien/proprio) — même logique que bien_nouveau.php
$ml_pdo       = $GLOBALS['pdo'] ?? null;
$ml_societeId = (int)($_SESSION['id_societe'] ?? 0);
$ml_agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$ml_agences   = [];
if ($ml_pdo && $ml_societeId > 0) {
    try {
        $st = $ml_pdo->prepare("SELECT id, COALESCE(NULLIF(ville,''), nom_agence) AS lib FROM agences WHERE id_societe = ? ORDER BY lib");
        $st->execute([$ml_societeId]);
        $ml_agences = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $ml_agences = []; }
}
$ml_agIds = array_map(static fn($a) => (int)$a['id'], $ml_agences);
if ($ml_agenceId <= 0 || !in_array($ml_agenceId, $ml_agIds, true)) { $ml_agenceId = $ml_agIds[0] ?? 0; }

$docCsrf = function_exists('csrf_token') ? csrf_token('ajouter_bien') : '';

// Endpoints backend RÉUTILISÉS (on branche, on ne réécrit pas)
$EP = [
    'places_auto'    => $au('/api/places_autocomplete.php'),
    'places_details' => $au('/api/places_details.php'),
    'geocode'        => $au('/api/geocode_address.php'),
    'immeuble_reco'  => $au('/api/immeuble_reconnaitre.php'),
    'immeuble_cont'  => $au('/api/immeuble_contenu.php'),
    'geo_cadastre'   => $au('/api/geo_cadastre_plu.php'),
    'geo_urba'       => $au('/api/geo_urbanisme.php'),
    'geo_risques'    => $au('/api/geo_risques.php'),
    'geo_copro'      => $au('/api/registre_copro.php'),
    'geo_altitude'   => $au('/api/geo_altitude.php'),
    'infos_pub'      => $au('/api/immeuble_infos_publiques.php'),
    'tiers_lookup'   => $au('/api/tiers_lookup.php'),
    'pappers'        => $au('/api/pappers_search.php'),
    'bien_creer'     => $au('/api/bien_creer.php'),
    'dpe_upload'     => $au('/api/dpe_import_upload.php'),
    'intake_upload'  => $au('/api/bien_intake_upload.php'),
    'bien_360'       => $au('/bien_360.php'),
];

// Socle mission CENTRALISÉ (chargé ici pour TOUTES les missions)
$extraCss = ''
  . '<link rel="preconnect" href="https://fonts.googleapis.com">'
  . '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
  . '<link rel="stylesheet" href="' . h($assets('/css/mission-tokens.css')) . '">'
  . '<link rel="stylesheet" href="' . h($assets('/css/mission-components.css')) . '">'
  . '<link rel="stylesheet" href="' . h($assets('/css/mission-bureau.css')) . '">'
  . '<link rel="stylesheet" href="' . h($assets('/css/mission-layout.css')) . '">';

$pageTitle    = $pageTitle    ?? 'Mission';
$pageSubtitle = $pageSubtitle ?? '';
$robots       = 'noindex, nofollow';
$layoutNoSidebar = false; // Sidebar INCLUSE mais rendue OFF-CANVAS (mission-layout.css) : ouverte au clic depuis le rail ☰
include __DIR__ . '/../inc/agency_layout_top.php';
?>
<div class="mbi-mission" style="--hero-img:url('<?= h($assets('/images/maboximmo_puzzle_fond_seul.png')) ?>')">
