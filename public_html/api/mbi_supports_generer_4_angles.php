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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mbi_supports_4angles_jsend(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (empty($_SESSION['user_id'])) {
    mbi_supports_4angles_jsend(401, ['ok' => false, 'error' => 'not_authenticated']);
}

$idBien   = (int)($_POST['id_bien'] ?? 0);
$type     = (string)($_POST['type']      ?? 'affiche_vitrine');
$modeleIa = (string)($_POST['modele_ia'] ?? 'haiku');

if ($idBien <= 0) {
    mbi_supports_4angles_jsend(400, ['ok' => false, 'error' => 'id_bien_required']);
}

// ─── Pré-check critique : on n'engage pas l'IA si l'export est refusé ───
$critique = mbi_supports_critic_check($idBien, $type);
if (!($critique['ok'] ?? false)) {
    mbi_supports_4angles_jsend(500, ['ok' => false, 'error' => 'critique_internal']);
}
if (!($critique['peut_exporter'] ?? false)) {
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

foreach ($angles as $angle) {
    try {
        $r = mbi_supports_pdf_generer(
            $idBien,
            $type,
            'P', // A3 portrait par défaut (template ignore actuellement)
            [
                'angle_marketing' => $angle,
                'ia_modele'       => $modeleIa,
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
        $resultats[] = [
            'angle'         => $angle,
            'ok'            => (bool)($r['ok'] ?? false),
            'support_id'    => (int)($r['support_id'] ?? 0),
            'pdf_url'       => (string)($r['fichier_pdf'] ?? ''),
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
