<?php
declare(strict_types=1);

/**
 * Contrôleur partagé des pages activité. Le fichier appelant définit
 * $mbiActiviteSlug puis require ce fichier. Chaque activité garde ainsi
 * son propre fichier/URL (bon pour le SEO) sans dupliquer la mécanique.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mbi_annonces_helpers.php';
require_once __DIR__ . '/mbi_annonces_pages_helpers.php';
require_once __DIR__ . '/mbi_annonces_activites_data.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$slug = (string)($mbiActiviteSlug ?? '');
$act  = mbi_annonces_activite_data($slug);
if ($act === null) { redirect('/mbi_annonces_index.php'); }

// Override agence optionnel (?agence=slug) → personnalisation de la page.
$agence = null;
$agSlug = trim((string)($_GET['agence'] ?? ''));
if ($agSlug !== '') {
    $agence = mbi_pages_fetch_agence_by_slug($pdo, $agSlug);
}

$agName    = $agence ? (string)($agence['nom_commercial'] ?: $agence['nom_agence']) : '';
$pagePath  = '/mbi_annonces_' . $slug . '.php' . ($agence ? '?agence=' . rawurlencode((string)$agence['slug']) : '');
$canonical = mbi_annonces_abs_url(app_url($pagePath));

$metaTitle = (string)$act['meta_title'] . ($agName !== '' ? ' · ' . $agName : '');
$metaDesc  = (string)$act['meta_desc'];

$mbiMeta = [
    'title'       => $metaTitle,
    'description' => $metaDesc,
    'canonical'   => $canonical,
    'image'       => mbi_annonces_abs_url(app_url('/images/Home.png')),
];

// JSON-LD : Service + FAQPage + fil d'Ariane (rich results Google / ChatGPT).
$mbiJsonLd  = mbi_pages_service_jsonld((string)$act['h1'], $metaDesc, $canonical, $agence);
$mbiJsonLd .= mbi_pages_faq_jsonld($act['faq'] ?? []);
$mbiJsonLd .= mbi_annonces_breadcrumb_jsonld([
    ['name' => 'Accueil',        'url' => app_url('/mbi_annonces_index.php')],
    ['name' => (string)$act['eyebrow'], 'url' => app_url($pagePath)],
]);

$mbiNavActive = (string)($act['nav'] ?? 'metiers');
$mbiBodyClass = 'mbi-page-editorial mbi-page-' . $slug;

include __DIR__ . '/mbi_annonces_header.php';
include __DIR__ . '/mbi_annonces_activite_render.php';
include __DIR__ . '/mbi_annonces_footer.php';
