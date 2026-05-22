<?php
// inc/admin_tiers_scope.php — Helper de vérification scope agence/société pour les opérations tiers
declare(strict_types=1);

if (!function_exists('tiers_check_scope')) {
    /**
     * Vérifie que l'utilisateur courant a le droit d'agir sur tous les tiers passés en paramètre.
     * Règles :
     *   - Super admin (role 1) ou Admin (role 7) : toujours autorisé
     *   - Manager (role 2) : tiers de sa société uniquement
     *   - Autres rôles : tiers de son agence uniquement
     *   - Tiers avec id_societe/id_agence NULL : autorisé pour tous (legacy)
     *
     * @param PDO $pdo
     * @param array $tiersIds liste d'ids tiers à vérifier
     * @return array{ok:bool, error:?string, denied_ids:array}
     */
    function tiers_check_scope(PDO $pdo, array $tiersIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $tiersIds)));
        if (empty($ids)) return ['ok' => true, 'error' => null, 'denied_ids' => []];

        $roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
        $isAdmin      = function_exists('is_admin_or_super_admin') ? is_admin_or_super_admin() : ($roleId === 1 || $roleId === 7);
        $isManager    = ($roleId === 1 || $roleId === 2);

        if ($isAdmin) return ['ok' => true, 'error' => null, 'denied_ids' => []];

        $idSoc = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
        $idAge = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;

        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, id_societe, id_agence FROM tiers WHERE id IN ($in)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $denied = [];
        foreach ($rows as $t) {
            $tSoc = $t['id_societe'] !== null ? (int)$t['id_societe'] : null;
            $tAge = $t['id_agence']  !== null ? (int)$t['id_agence']  : null;

            // Tiers globaux (sans société/agence) : autorisés
            if ($tSoc === null && $tAge === null) continue;

            if ($isManager) {
                if ($tSoc !== null && $idSoc !== null && $tSoc !== $idSoc) {
                    $denied[] = (int)$t['id'];
                }
            } else {
                if ($tAge !== null && $idAge !== null && $tAge !== $idAge) {
                    $denied[] = (int)$t['id'];
                } elseif ($tAge === null && $tSoc !== null && $idSoc !== null && $tSoc !== $idSoc) {
                    $denied[] = (int)$t['id'];
                }
            }
        }

        if (!empty($denied)) {
            return [
                'ok' => false,
                'error' => 'hors scope agence/société : tiers #' . implode(', #', $denied),
                'denied_ids' => $denied,
            ];
        }
        return ['ok' => true, 'error' => null, 'denied_ids' => []];
    }
}
