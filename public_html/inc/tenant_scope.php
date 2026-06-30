<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * tenant_scope.php — Helper de filtrage multi-tenant (société / agence)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Règles globales (feedback_filtrage_multi_tenant.md) :
 *   - Un user standard ne voit QUE sa société (imposée par la session)
 *   - L'agence est un filtre OPTIONNEL : l'user peut basculer entre
 *     "toutes mes agences" et une agence spécifique de sa société
 *   - Super admin (role_id = 1) voit tout + peut filtrer par société
 *
 * API publique :
 *   tenant_current_context(): array
 *     → { id_societe, id_agence, role_id, is_super_admin }
 *
 *   tenant_agences_visibles(PDO, ?int $idSocieteCible = null): array
 *     → Liste des agences que l'user peut voir (sa société, ou toutes
 *       si super admin). Chaque agence : { id, nom, id_societe, ville }
 *
 *   tenant_societes_visibles(PDO): array
 *     → Liste des sociétés pour le super admin (vide pour les autres,
 *       qui sont verrouillés à id_societe de la session)
 *
 *   tenant_resolve_filter(array $validAgenceIds = []): array
 *     → { id_societe, id_agence } basés sur $_GET['societe'], $_GET['agence']
 *       avec garde-fous selon le rôle
 *
 *   tenant_render_filter_bar(PDO, array $opts = []): string
 *     → HTML du bloc filtres (select société si super admin, select agence)
 *       À insérer dans une barre de filtres existante.
 *       opts: [ 'show_societe' => bool, 'show_agence' => bool,
 *               'name_societe' => 'societe', 'name_agence' => 'agence',
 *               'onchange_submit' => true ]
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('tenant_current_context')) {
    function tenant_current_context(): array {
        $roleId = (int)($_SESSION['id_role'] ?? 0);
        return [
            'id_societe'     => (int)($_SESSION['id_societe'] ?? 0),
            'id_agence'      => (int)($_SESSION['id_agence']  ?? 0),
            'role_id'        => $roleId,
            'is_super_admin' => $roleId === 1,
        ];
    }
}

if (!function_exists('tenant_agences_visibles')) {
    /**
     * Liste les agences accessibles à l'user courant.
     * @param PDO $pdo
     * @param int|null $idSocieteCible Si fourni (et user autorisé), filtre sur cette société
     * @return array<int, array{id:int, nom:string, id_societe:int, ville:string}>
     */
    function tenant_agences_visibles(PDO $pdo, ?int $idSocieteCible = null): array {
        $ctx = tenant_current_context();

        $where = [];
        $params = [];
        if ($ctx['is_super_admin']) {
            if ($idSocieteCible !== null && $idSocieteCible > 0) {
                $where[] = 'a.id_societe = :soc';
                $params[':soc'] = $idSocieteCible;
            }
        } else {
            // Forcé sur la société de la session (zéro risque de fuite)
            $where[] = 'a.id_societe = :soc';
            $params[':soc'] = $ctx['id_societe'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT a.id, a.nom_agence AS nom, a.id_societe,
                       COALESCE(a.ville, '') AS ville
                FROM agences a
                $whereSql
                ORDER BY a.nom_agence ASC";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[tenant_agences_visibles] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tenant_societes_visibles')) {
    /**
     * Liste les sociétés (super admin uniquement, sinon tableau vide).
     * @param PDO $pdo
     * @return array<int, array{id:int, nom:string}>
     */
    function tenant_societes_visibles(PDO $pdo): array {
        $ctx = tenant_current_context();
        if (!$ctx['is_super_admin']) return [];
        try {
            $st = $pdo->query("SELECT id, nom FROM societes WHERE actif=1 ORDER BY nom ASC");
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[tenant_societes_visibles] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tenant_resolve_filter')) {
    /**
     * Résout le filtre courant depuis $_GET, avec garde-fous.
     * - Un user standard ne peut PAS passer ?societe=X sur une autre société
     * - L'agence filtre doit appartenir à la société visible
     *
     * @param array<int> $validAgenceIds IDs d'agences visibles (pour validation)
     * @return array{id_societe:int, id_agence:int}
     */
    function tenant_resolve_filter(array $validAgenceIds = []): array {
        $ctx = tenant_current_context();

        // Société : forcée pour les users standards
        if ($ctx['is_super_admin']) {
            $idSoc = isset($_GET['societe']) && ctype_digit((string)$_GET['societe']) ? (int)$_GET['societe'] : 0;
        } else {
            $idSoc = $ctx['id_societe'];
        }

        // Agence : $_GET['agence'] validé contre la liste des agences visibles
        $idAg = isset($_GET['agence']) && ctype_digit((string)$_GET['agence']) ? (int)$_GET['agence'] : 0;
        if ($idAg > 0 && !empty($validAgenceIds) && !in_array($idAg, $validAgenceIds, true)) {
            $idAg = 0;
        }

        return ['id_societe' => $idSoc, 'id_agence' => $idAg];
    }
}

if (!function_exists('tenant_render_filter_bar')) {
    /**
     * Rend le bloc HTML des selects société (si super admin) + agence.
     * À concaténer dans une <form class="bl-filters"> existante.
     *
     * @param PDO $pdo
     * @param array $opts
     * @return string HTML
     */
    function tenant_render_filter_bar(PDO $pdo, array $opts = []): string {
        $ctx = tenant_current_context();
        $showSoc  = $opts['show_societe'] ?? true;
        $showAg   = $opts['show_agence']  ?? true;
        $nameSoc  = $opts['name_societe'] ?? 'societe';
        $nameAg   = $opts['name_agence']  ?? 'agence';
        $onSubmit = !empty($opts['onchange_submit'] ?? true) ? ' onchange="this.form.submit()"' : '';

        $selSoc = isset($_GET[$nameSoc]) ? (int)$_GET[$nameSoc] : 0;
        $selAg  = isset($_GET[$nameAg])  ? (int)$_GET[$nameAg]  : 0;

        $html = '';

        // Select société (super admin uniquement)
        if ($showSoc && $ctx['is_super_admin']) {
            $societes = tenant_societes_visibles($pdo);
            $html .= '<select name="' . htmlspecialchars($nameSoc) . '" class="bl-select"' . $onSubmit . '>';
            $html .= '<option value="">Toutes sociétés</option>';
            foreach ($societes as $s) {
                $sel = ($selSoc === (int)$s['id']) ? ' selected' : '';
                $html .= '<option value="' . (int)$s['id'] . '"' . $sel . '>' . htmlspecialchars((string)$s['nom']) . '</option>';
            }
            $html .= '</select>';
        }

        // Select agence — liste filtrée par société visible
        if ($showAg) {
            $agences = tenant_agences_visibles($pdo, $ctx['is_super_admin'] ? ($selSoc ?: null) : null);
            $html .= '<select name="' . htmlspecialchars($nameAg) . '" class="bl-select"' . $onSubmit . '>';
            $html .= '<option value="">Toutes agences</option>';
            foreach ($agences as $a) {
                $sel = ($selAg === (int)$a['id']) ? ' selected' : '';
                $label = (string)$a['nom'] . ($a['ville'] ? ' — ' . $a['ville'] : '');
                $html .= '<option value="' . (int)$a['id'] . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
            }
            $html .= '</select>';
        }

        return $html;
    }
}

if (!function_exists('tenant_can_hard_delete')) {
    /**
     * Le super admin (role_id = 1) peut faire des suppressions définitives.
     * Les autres peuvent uniquement archiver (soft-delete).
     */
    function tenant_can_hard_delete(): bool {
        return (int)($_SESSION['id_role'] ?? 0) === 1;
    }
}
