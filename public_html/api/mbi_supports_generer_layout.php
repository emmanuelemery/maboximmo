<?php
declare(strict_types=1);

/**
 * =======================================================================
 * api/mbi_supports_generer_layout.php - Genere UNE affiche vitrine dans un
 * layout choisi (split_5050 / mosaique_haute / asymetrique / cinema_coin /
 * magazine_bandeau).
 * =======================================================================
 *
 * Recoit (POST) :
 *   id_bien : int
 *   layout  : string (code layout, cf. catalogue routeur)
 *   angle   : ?string (defaut 'generique')
 *   titre   : ?string (titre annonce valide/modifie par le commercial)
 *   force   : ?'1'   (super admin -> bypass critique)
 *
 * Renvoie JSON :
 *   { ok, id_bien, layout, critique_ko, blocs_durs_violes,
 *     support_id, pdf_url, version, erreur }
 * =======================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/mbi_supports_pdf_generator.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('mbi_supports_layout_jsend')) {
    function mbi_supports_layout_jsend(int $http, array $payload): void {
        http_response_code($http);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Prefixe d'URL (local XAMPP servi sous /MaBoxImmo2026/public_html/, racine en prod)
if (!function_exists('mbi_supports_layout_url_prefix')) {
    function mbi_supports_layout_url_prefix(): string {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $prefix = preg_replace('#/api/[^/]+\.php$#', '', $script);
        return ($prefix === '/' || $prefix === '') ? '' : $prefix;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mbi_supports_layout_jsend(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (empty($_SESSION['user_id'])) {
    mbi_supports_layout_jsend(401, ['ok' => false, 'error' => 'not_authenticated']);
}

$idBien = (int)($_POST['id_bien'] ?? 0);
$layout = (string)($_POST['layout'] ?? '');
$angle  = (string)($_POST['angle']  ?? 'generique');
$titre  = trim((string)($_POST['titre'] ?? ''));
$desc   = trim((string)($_POST['description'] ?? ''));
$force  = !empty($_POST['force']) && $_POST['force'] !== '0';
$type   = 'affiche_vitrine';

$layoutsValides = ['cinema_coin', 'magazine_bandeau', 'split_5050', 'mosaique_haute', 'asymetrique'];
if ($idBien <= 0) {
    mbi_supports_layout_jsend(400, ['ok' => false, 'error' => 'id_bien_required']);
}
if (!in_array($layout, $layoutsValides, true)) {
    mbi_supports_layout_jsend(400, ['ok' => false, 'error' => 'layout_invalide']);
}

// Force reserve super admin (role = 1)
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
if ($force && !$isSuperAdmin) $force = false;

// --- Pre-check critique (sauf force super admin) ---
$critique = mbi_supports_critic_check($idBien, $type);
if (!($critique['ok'] ?? false)) {
    mbi_supports_layout_jsend(500, ['ok' => false, 'error' => 'critique_internal']);
}
if (!$force && !($critique['peut_exporter'] ?? false)) {
    mbi_supports_layout_jsend(200, [
        'ok'                => false,
        'id_bien'           => $idBien,
        'layout'            => $layout,
        'critique_ko'       => true,
        'blocs_durs_violes' => $critique['blocs_durs_violes'] ?? [],
    ]);
}

// --- Genere l'affiche dans le layout choisi ---
$urlPrefix = mbi_supports_layout_url_prefix();
try {
    $opts = [
        'layout_force'    => $layout,
        'angle_marketing' => $angle,
        'force_export'    => $force,
    ];
    // Titre valide/modifie par le commercial -> surcharge titre_personnalise
    // + persistance sur le bien (reutilise au prochain ouvrir du modal).
    if ($titre !== '') {
        $titreClean = mb_substr($titre, 0, 150, 'UTF-8');
        $opts['titre_personnalise'] = $titreClean;
        try {
            $pdo = $GLOBALS['pdo'] ?? db();
            $pdo->prepare("UPDATE biens SET bien_titre_affiche = :t WHERE id = :id")
                ->execute([':t' => $titreClean, ':id' => $idBien]);
        } catch (Throwable) { /* persistance best-effort */ }
    }
    // Texte d'annonce synthetise/modifie -> surcharge description + persistance sur le bien
    if ($desc !== '') {
        $descClean = mb_substr($desc, 0, 600, 'UTF-8');
        $opts['description_personnalisee'] = $descClean;
        try {
            $pdo = $GLOBALS['pdo'] ?? db();
            $pdo->prepare("UPDATE biens SET bien_annonce_affiche = :t WHERE id = :id")
                ->execute([':t' => $descClean, ':id' => $idBien]);
        } catch (Throwable) { /* persistance best-effort */ }
    }

    $r = mbi_supports_pdf_generer($idBien, $type, null, $opts);

    $pdfUrlBrut = (string)($r['fichier_pdf'] ?? '');
    $pdfUrl = ($pdfUrlBrut !== '' && $pdfUrlBrut[0] === '/')
        ? $urlPrefix . $pdfUrlBrut
        : $pdfUrlBrut;

    mbi_supports_layout_jsend(200, [
        'ok'          => (bool)($r['ok'] ?? false),
        'id_bien'     => $idBien,
        'layout'      => $layout,
        'critique_ko' => false,
        'support_id'  => (int)($r['support_id'] ?? 0),
        'pdf_url'     => $pdfUrl,
        'version'     => (int)($r['version'] ?? 0),
        'erreur'      => $r['erreur'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('[mbi_supports_generer_layout ' . $layout . '] ' . $e->getMessage());
    mbi_supports_layout_jsend(500, [
        'ok'     => false,
        'id_bien'=> $idBien,
        'layout' => $layout,
        'erreur' => $e->getMessage(),
    ]);
}
