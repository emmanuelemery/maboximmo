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

        // ── VUE ADMIN / PLEIN ACCÈS : staff = tout (ou &bailleur=ID) ; bailleur = son périmètre ──
        if (!empty($_GET['admin'])) {
            require_once __DIR__ . '/auth.php';
            require_login();
            $uid     = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);
            $isSA    = function_exists('is_super_admin') && is_super_admin();
            $isStaff = $isSA || in_array((int)($_SESSION['id_role'] ?? 0), [1, 2, 3, 7], true);
            $bid     = $isStaff ? (int)($_GET['bailleur'] ?? 0) : $uid;
            if (!$isStaff) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM user_proprietaires WHERE id_user=?");
                $chk->execute([$uid]);
                if (!(int)$chk->fetchColumn()) pp_auth_stop('Accès refusé', 'Aucun patrimoine associé à votre compte.');
            }
            $allScen = (string)($pdo->query("SELECT GROUP_CONCAT(DISTINCT scenario_code) FROM bien_prix WHERE type_valeur='prix_vente' AND is_courant=1")->fetchColumn() ?: 'courant');
            $share = [
                'id' => 0, 'token' => '', 'id_user_bailleur' => $bid, 'id_user_gestionnaire' => null,
                'id_tiers_destinataire' => null, 'destinataire_nom' => ($isStaff ? 'Vue admin — plein accès' : 'Mon patrimoine'),
                'scenario_code' => $allScen,
                'montrer_prix_vente' => 1, 'montrer_creanciers' => 1, 'montrer_financements' => 1,
                'montrer_loyer' => 1, 'montrer_locataire' => 1, 'montrer_descriptif' => 1,
                'niveau_acces' => 'contribution', 'actif' => 1, 'consent_at' => date('Y-m-d H:i:s'),
                '_admin_all' => ($isStaff && $bid === 0), // staff sans bailleur ciblé = accès à tous les propriétaires
            ];
            return [$share, true, true];
        }

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

if (!function_exists('pp_perimeter_ok')) {
    /** Le propriétaire est-il accessible depuis ce partage ? (bypass staff admin « Tous »). */
    function pp_perimeter_ok(PDO $pdo, array $share, int $proprioId): bool
    {
        if ($proprioId <= 0) return false;
        if (!empty($share['_admin_all'])) {
            return (bool)$pdo->query("SELECT 1 FROM proprietaires WHERE id=" . $proprioId)->fetchColumn();
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_proprietaires WHERE id_user=? AND id_proprietaire=?");
        $st->execute([(int)($share['id_user_bailleur'] ?? 0), $proprioId]);
        return (int)$st->fetchColumn() > 0;
    }
}

if (!function_exists('pp_sub_qs')) {
    /** Query-string pour atteindre les sous-pages (créancier/financement/export) selon le contexte. */
    function pp_sub_qs(array $share, bool $isPreview, bool $isAdmin = false, string $token = ''): string
    {
        if ($isAdmin) {
            $b = (int)($share['id_user_bailleur'] ?? 0);
            return 'admin=1' . ($b > 0 ? '&bailleur=' . $b : '');
        }
        if ($isPreview) return 'preview=' . (int)($share['id'] ?? 0);
        return 't=' . urlencode($token);
    }
}
