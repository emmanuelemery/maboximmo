<?php
/**
 * inc/patrimoine_partage_auth.php — Résolution d'un partage patrimoine (jeton ou aperçu staff).
 *
 * Source unique partagée par patrimoine_partage.php et patrimoine_creancier.php.
 * Renvoie [$share, $isPreview, $canWrite] ou stoppe via pp_auth_stop().
 * Ne journalise pas / ne gère pas le consentement (laissé à la page hôte).
 */
declare(strict_types=1);

if (!function_exists('pp_auth_stop')) {
    function pp_auth_stop(string $titre, string $msg): void {
        http_response_code(403);
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($titre) . '</title>'
           . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#10254d;color:#fff;display:grid;place-items:center;height:100vh;margin:0;}'
           . '.c{max-width:460px;text-align:center;padding:30px;}h1{font-size:22px;margin:0 0 10px;}p{color:#aebfd8;line-height:1.6;}</style></head>'
           . '<body><div class="c"><div style="font-size:46px;margin-bottom:10px;">🔒</div><h1>' . htmlspecialchars($titre) . '</h1><p>' . htmlspecialchars($msg) . '</p></div></body></html>';
        exit;
    }
}

if (!function_exists('pp_resolve_share')) {
    /**
     * @return array{0:array,1:bool,2:bool} [$share, $isPreview, $canWrite]
     */
    function pp_resolve_share(PDO $pdo): array
    {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        header('Referrer-Policy: no-referrer');

        $previewId = (int)($_GET['preview'] ?? 0);
        if ($previewId > 0) {
            require_once __DIR__ . '/auth.php';
            require_login();
            $staffRoles = [1, 2, 3, 7];
            $realRole = (int)($_SESSION['id_role'] ?? 0);
            $effRole  = function_exists('current_role_id') ? (int)current_role_id() : 0;
            if (!in_array($realRole, $staffRoles, true) && !in_array($effRole, $staffRoles, true)
                && !(function_exists('is_super_admin') && is_super_admin())) {
                pp_auth_stop('Accès refusé', 'Aperçu réservé au personnel.');
            }
            $st = $pdo->prepare("SELECT * FROM patrimoine_partages WHERE id = ?");
            $st->execute([$previewId]);
            $share = $st->fetch(PDO::FETCH_ASSOC);
            if (!$share) pp_auth_stop('Introuvable', 'Partage introuvable.');
            return [$share, true, true]; // staff en aperçu écrit toujours
        }

        $token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
        if ($token === '') pp_auth_stop('Lien invalide', 'Ce lien de partage est incomplet.');
        $st = $pdo->prepare("SELECT * FROM patrimoine_partages WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        $share = $st->fetch(PDO::FETCH_ASSOC);
        if (!$share)                    pp_auth_stop('Lien invalide', 'Ce lien n\'existe pas ou a été supprimé.');
        if ((int)$share['actif'] !== 1) pp_auth_stop('Accès clôturé', 'Ce partage a été désactivé ou révoqué.');
        if ($share['expire_at'] && strtotime($share['expire_at']) < time())
                                        pp_auth_stop('Lien expiré', 'Ce lien de partage a expiré.');
        if (empty($share['consent_at'])) pp_auth_stop('Accès non confirmé', 'Ouvrez d\'abord votre espace patrimoine.');

        $canWrite = (($share['niveau_acces'] ?? 'lecture') === 'contribution');
        return [$share, false, $canWrite];
    }
}
