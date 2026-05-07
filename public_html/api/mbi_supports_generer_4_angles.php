<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * api/mbi_supports_generer_4_angles.php — Génération en lot des 4 affiches
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Endpoint POST AJAX du LOT 3 : génère 4 affiches vitrine pour le même
 * bien, une par angle marketing (famille / investisseur / premium /
 * premier_achat). Chaque affiche a sa rédaction IA dédiée (Lot 2).
 *
 * Reçoit :
 *   POST id_bien    : int
 *   POST type       : ?string (par défaut 'affiche_vitrine')
 *   POST modele_ia  : ?string (haiku|sonnet|opus, défaut haiku)
 *
 * Renvoie JSON :
 *   {
 *     ok: bool,
 *     id_bien: int,
 *     critique_ko: bool,                  // true si la critique a refusé
 *     blocs_durs_violes: [...],           // si critique_ko
 *     resultats: [
 *        { angle, ok, support_id, pdf_url, version, erreur, modele_ia, cout_centimes }
 *     ]
 *   }
 *
 * Si la critique refuse l'export, on s'arrête AVANT de générer (pas de
 * tirage IA inutile) et on renvoie la liste des blocs durs pour que le
 * front bascule sur la modale du Lot 1.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/mbi_supports_pdf_generator.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('mbi_supports_4angles_jsend')) {
    function mbi_supports_4angles_jsend(int $http, array $payload): void {
        http_response_code($http);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Détecte le préfixe d'URL (utile en local XAMPP où le projet est servi
// sous /MaBoxImmo2026/public_html/, contrairement à dev/prod où il est à
// la racine). Permet de renvoyer des pdf_url qui marchent partout.
if (!function_exists('mbi_supports_4angles_url_prefix')) {
    function mbi_supports_4angles_url_prefix(): string {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        // SCRIPT_NAME ressemble à /[prefix]/api/mbi_supports_generer_4_angles.php
        $prefix = preg_replace('#/api/[^/]+\.php$#', '', $script);
        return ($prefix === '/' || $prefix === '') ? '' : $prefix;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mbi_supports_4angles_jsend(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (empty($_SESSION['user_id'])) {
    mbi_supports_4angles_jsend(401, ['ok' => false, 'error' => 'not_authenticated']);
}

$idBien   = (int)($_POST['id_bien'] ?? 0);
$type     = (string)($_POST['type']      ?? 'affiche_vitrine');
$modeleIa = (string)($_POST['modele_ia'] ?? 'haiku');
$force    = !empty($_POST['force']) && $_POST['force'] !== '0';

if ($idBien <= 0) {
    mbi_supports_4angles_jsend(400, ['ok' => false, 'error' => 'id_bien_required']);
}

// Force réservé super admin (role=1) — par sécurité côté serveur en plus du toggle UI
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
if ($force && !$isSuperAdmin) $force = false;

// ─── Pré-check critique : on n'engage pas l'IA si l'export est refusé ───
//     SAUF si force=1 (super admin) — on génère malgré les blocs durs.
$critique = mbi_supports_critic_check($idBien, $type);
if (!($critique['ok'] ?? false)) {
    mbi_supports_4angles_jsend(500, ['ok' => false, 'error' => 'critique_internal']);
}
if (!$force && !($critique['peut_exporter'] ?? false)) {
    mbi_supports_4angles_jsend(200, [
        'ok'                => false,
        'id_bien'           => $idBien,
        'critique_ko'       => true,
        'blocs_durs_violes' => $critique['blocs_durs_violes'] ?? [],
        'resultats'         => [],
    ]);
}

// ─── Génère les 4 angles ───────────────────────────────────────────────
$angles = ['famille', 'investisseur', 'premium', 'premier_achat'];
$resultats = [];
$totalCentimes = 0;
$urlPrefix = mbi_supports_4angles_url_prefix();

foreach ($angles as $angle) {
    try {
        $r = mbi_supports_pdf_generer(
            $idBien,
            $type,
            'P', // A3 portrait par défaut (template ignore actuellement)
            [
                'angle_marketing' => $angle,
                'ia_modele'       => $modeleIa,
                'force_export'    => $force,
            ]
        );
        // Récupère le coût IA depuis le contenu_json fraîchement écrit
        $cout = 0;
        if (!empty($r['support_id'])) {
            try {
                $pdo = $GLOBALS['pdo'] ?? db();
                $st = $pdo->prepare("SELECT contenu_json FROM mbi_supports_commerciaux WHERE id = :id");
                $st->execute([':id' => (int)$r['support_id']]);
                $cj = json_decode((string)($st->fetchColumn() ?: ''), true);
                $cout = (int)($cj['ia_redaction']['cout_centimes'] ?? 0);
            } catch (Throwable) {}
        }
        $totalCentimes += $cout;
        $pdfUrlBrut = (string)($r['fichier_pdf'] ?? '');
        // Préfixe le base path en local (ex: /MaBoxImmo2026/public_html/uploads/...)
        $pdfUrl = ($pdfUrlBrut !== '' && $pdfUrlBrut[0] === '/')
            ? $urlPrefix . $pdfUrlBrut
            : $pdfUrlBrut;

        $resultats[] = [
            'angle'         => $angle,
            'ok'            => (bool)($r['ok'] ?? false),
            'support_id'    => (int)($r['support_id'] ?? 0),
            'pdf_url'       => $pdfUrl,
            'version'       => (int)($r['version'] ?? 0),
            'erreur'        => $r['erreur'] ?? null,
            'cout_centimes' => $cout,
        ];
    } catch (Throwable $e) {
        error_log('[mbi_supports_generer_4_angles ' . $angle . '] ' . $e->getMessage());
        $resultats[] = [
            'angle'  => $angle,
            'ok'     => false,
            'erreur' => $e->getMessage(),
        ];
    }
}

mbi_supports_4angles_jsend(200, [
    'ok'                  => true,
    'id_bien'             => $idBien,
    'critique_ko'         => false,
    'resultats'           => $resultats,
    'cout_total_centimes' => $totalCentimes,
    'modele_ia'           => $modeleIa,
]);
