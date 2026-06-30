<?php
// inc/portefeuille_scope.php — Périmètre VISIBLE par l'utilisateur connecté (module Portefeuilles).
//
// RÈGLE D'OR : un propriétaire / bailleur ne voit QUE son patrimoine (ses user_proprietaires,
// assignés par l'admin). Le staff agence (admin / manager / collaborateur) voit tout le périmètre,
// et peut « voir en tant que » un bailleur précis via ?bailleur=<user_id>.
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!function_exists('pf_scope')) {
    /**
     * @return array{
     *   ids:int[],            // id_proprietaire visibles par CET utilisateur (après éventuel "voir en tant que")
     *   is_staff:bool,        // true = staff agence
     *   view_as:int,          // id_user du bailleur "vu" par le staff (0 = tout le périmètre)
     *   perimetre:array,      // id => libellé (config module)
     *   merge:array           // id_source => id_groupe (fusion d'affichage)
     * }
     */
    function pf_scope(PDO $pdo): array
    {
        $cfg   = require __DIR__ . '/portefeuille_perimetre.php';
        $perim = array_keys($cfg['perimetre']);

        $roleId  = function_exists('current_role_id') ? (int)current_role_id() : 0;
        // Staff agence : admin(1), manager(2), collaborateur(3), super admin(7).
        $isStaff = in_array($roleId, [1, 2, 3, 7], true);

        if ($isStaff) {
            $viewAs = isset($_GET['bailleur']) ? (int)$_GET['bailleur'] : 0;
            if ($viewAs > 0) {
                $ids = pf_user_props($pdo, $viewAs);   // staff "voit en tant que" ce bailleur
                return ['ids' => $ids, 'is_staff' => true, 'view_as' => $viewAs, 'perimetre' => $cfg['perimetre'], 'merge' => $cfg['merge']];
            }
            return ['ids' => $perim, 'is_staff' => true, 'view_as' => 0, 'perimetre' => $cfg['perimetre'], 'merge' => $cfg['merge']];
        }

        // Bailleur / propriétaire : uniquement ses propriétaires assignés.
        $uid = function_exists('current_user_id') ? (int)current_user_id() : 0;
        return ['ids' => pf_user_props($pdo, $uid), 'is_staff' => false, 'view_as' => 0, 'perimetre' => $cfg['perimetre'], 'merge' => $cfg['merge']];
    }

    /** Propriétaires assignés à un utilisateur. */
    function pf_user_props(PDO $pdo, int $uid): array
    {
        if ($uid <= 0) return [];
        try {
            $st = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?");
            $st->execute([$uid]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return []; }
    }

    /** Liste des bailleurs (utilisateurs ayant au moins 1 propriétaire assigné) pour le filtre staff. */
    function pf_bailleurs_list(PDO $pdo): array
    {
        try {
            // Uniquement les comptes BAILLEURS (rôles PROPRIO=9, PROPRIO_VIP=10) — pas le staff agence.
            return $pdo->query("
                SELECT u.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.prenom, u.nom)),''), u.email) AS label,
                       COUNT(up.id_proprietaire) AS nb
                FROM users u
                JOIN user_proprietaires up ON up.id_user = u.id
                WHERE u.id_role IN (9, 10)
                GROUP BY u.id
                HAVING nb > 0
                ORDER BY label
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }
}
