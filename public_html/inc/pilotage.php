<?php
declare(strict_types=1);

/**
 * pilotage.php — Accès données du module Pilotage Métier (lecture + affectation).
 * Tout est scopé par id_societe (multi-tenant MBI). Pas de recréation de GED,
 * de collaborateurs ni de permissions : on lit `users` et `pilotage_*`.
 */

if (!function_exists('pilotage_freq_label')) {
    function pilotage_freq_label(string $type, ?string $value = null): string {
        $map = [
            'daily' => 'Quotidien', 'weekly' => 'Hebdomadaire', 'monthly' => 'Mensuel',
            'quarterly' => 'Trimestriel', 'yearly' => 'Annuel', 'event_based' => 'Sur événement',
            'on_demand' => 'À la demande', 'continuous' => 'Continu',
        ];
        $base = $map[$type] ?? $type;
        if ($value && $type === 'event_based') return $value;
        return $value ?: $base;
    }
}

if (!function_exists('pilotage_freq_icon')) {
    function pilotage_freq_icon(string $type): string {
        return [
            'daily' => '📅', 'weekly' => '🗓️', 'monthly' => '📆', 'quarterly' => '🈷️',
            'yearly' => '📈', 'event_based' => '⚡', 'on_demand' => '✋', 'continuous' => '♾️',
        ][$type] ?? '•';
    }
}

if (!function_exists('pilotage_role_label')) {
    function pilotage_role_label(string $role): string {
        return [
            'executor' => 'Exécutant', 'primary_responsible' => 'Responsable',
            'validator' => 'Validateur', 'supervisor' => 'Superviseur',
            'backup' => 'Remplaçant', 'informed' => 'Informé',
        ][$role] ?? $role;
    }
}

if (!function_exists('pilotage_poste_catalog')) {
    /**
     * Catalogue des POSTES du service Location (source unique, chargée partout).
     * `hint` = prénom canonique (suggestion de mapping uniquement, jamais auto-affecté).
     * @return array<string, array{label:string, short:string, hint:string}>
     */
    function pilotage_poste_catalog(): array {
        return [
            'responsable'          => ['label' => 'Responsable / supervision / contrôle', 'short' => 'Responsable', 'hint' => 'Claudine'],
            'gestionnaire'         => ['label' => 'Gestionnaire principal / validation métier', 'short' => 'Gestionnaire', 'hint' => 'Pierre-Emmanuel'],
            'accueil'              => ['label' => 'Assistante administrative / accueil', 'short' => 'Accueil', 'hint' => 'Ludivine'],
            'assistante_location'  => ['label' => 'Assistante Location', 'short' => 'Assistante Location', 'hint' => 'Juliette'],
            'assistant_polyvalent' => ['label' => 'Assistant polyvalent (renfort Location)', 'short' => 'Renfort Location', 'hint' => 'Colin'],
        ];
    }
}

if (!function_exists('pilotage_poste_short')) {
    function pilotage_poste_short(string $code): string {
        $c = pilotage_poste_catalog();
        return $c[$code]['short'] ?? ($c[$code]['label'] ?? ucfirst(str_replace('_', ' ', $code)));
    }
}

if (!function_exists('pilotage_status_dot')) {
    /** Pastille tricolore MBI. */
    function pilotage_status_dot(string $s): string {
        $c = ['red' => '#DD4735', 'yellow' => '#E0A82E', 'green' => '#2FA36B'][$s] ?? '#9ca3af';
        $t = ['red' => 'Non documentée', 'yellow' => 'À contrôler', 'green' => 'Validée'][$s] ?? '';
        return '<span class="pl-dot" title="' . htmlspecialchars($t) . '" style="background:' . $c . '"></span>';
    }
}

if (!function_exists('pilotage_get_service')) {
    function pilotage_get_service(PDO $pdo, int $soc, string $slug): ?array {
        $st = $pdo->prepare("SELECT * FROM pilotage_services WHERE id_societe=? AND slug=? AND is_active=1");
        $st->execute([$soc, $slug]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('pilotage_collaborators')) {
    /**
     * Collaborateurs impliqués dans un service + compteurs de missions.
     * Retourne les users effectivement affectés à au moins 1 mission du service.
     */
    function pilotage_collaborators(PDO $pdo, int $soc, int $serviceId): array {
        $sql = "SELECT u.id, u.prenom, u.nom, u.fonction, u.avatar_url,
                       SUM(a.is_primary = 1) AS nb_principales,
                       SUM(a.is_primary = 0) AS nb_soutien,
                       COUNT(*) AS nb_total
                FROM pilotage_task_assignments a
                JOIN pilotage_tasks t ON t.id = a.task_id AND t.service_id = ? AND t.is_active = 1
                JOIN users u ON u.id = a.user_id
                WHERE a.id_societe = ? AND a.is_active = 1
                GROUP BY u.id, u.prenom, u.nom, u.fonction, u.avatar_url
                ORDER BY nb_principales DESC, u.prenom";
        $st = $pdo->prepare($sql);
        $st->execute([$serviceId, $soc]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('pilotage_tasks_for_service')) {
    /**
     * Toutes les missions d'un service, avec catégorie et affectations agrégées.
     * Chaque mission porte : assignments[] (user_id, role, is_primary), has_checklist, has_procedure.
     */
    function pilotage_tasks_for_service(PDO $pdo, int $soc, int $serviceId): array {
        $sql = "SELECT t.id, t.name, t.slug, t.category_id, c.name AS category_name, c.display_order AS cat_order,
                       t.required_level, t.frequency_type, t.frequency_value, t.trigger_event,
                       t.estimated_duration_minutes, t.documentation_status, t.automation_level,
                       (t.procedure_text IS NOT NULL AND t.procedure_text <> '') AS has_procedure,
                       (SELECT COUNT(*) FROM pilotage_task_checklist_items ci WHERE ci.task_id = t.id) AS nb_checklist,
                       (SELECT COUNT(*) FROM pilotage_task_steps s WHERE s.task_id = t.id) AS nb_steps,
                       (SELECT COUNT(*) FROM pilotage_task_automations au WHERE au.source_task_id = t.id AND au.is_active = 1) AS nb_automations,
                       (SELECT COUNT(*) FROM pilotage_task_instances ti WHERE ti.task_id = t.id AND ti.status NOT IN ('completed','cancelled','valide_definitif')) AS nb_instances,
                       t.display_order
                FROM pilotage_tasks t
                LEFT JOIN pilotage_categories c ON c.id = t.category_id
                WHERE t.id_societe = ? AND t.service_id = ? AND t.is_active = 1
                ORDER BY c.display_order, t.display_order, t.name";
        $st = $pdo->prepare($sql);
        $st->execute([$soc, $serviceId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$tasks) return [];

        // Affectations en une requête
        $ids = array_column($tasks, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $as = $pdo->prepare("SELECT a.task_id, a.user_id, a.assignment_role, a.is_primary, u.prenom, u.nom
                             FROM pilotage_task_assignments a JOIN users u ON u.id = a.user_id
                             WHERE a.is_active = 1 AND a.task_id IN ($ph)");
        $as->execute($ids);
        $byTask = [];
        foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $byTask[$a['task_id']][] = $a;
        }
        // Postes canoniques (affichés quand aucune personne n'est mappée)
        $ps = $pdo->prepare("SELECT task_id, poste_code, assignment_role, is_primary FROM pilotage_task_postes WHERE task_id IN ($ph)");
        $ps->execute($ids);
        $posteByTask = [];
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) $posteByTask[$p['task_id']][] = $p;

        foreach ($tasks as &$t) {
            $t['assignments'] = $byTask[$t['id']] ?? [];
            $t['postes'] = $posteByTask[$t['id']] ?? [];
            $t['has_primary'] = false;
            foreach ($t['assignments'] as $a) if ((int)$a['is_primary'] === 1) $t['has_primary'] = true;
        }
        return $tasks;
    }
}

if (!function_exists('pilotage_set_primary_executor')) {
    /**
     * Réaffecte l'exécutant principal d'une mission à $userId, SANS retirer les
     * autres rôles (validateur/superviseur conservés). Idempotent.
     * @return array{ok:bool,error?:string}
     */
    function pilotage_set_primary_executor(PDO $pdo, int $soc, int $taskId, int $userId): array {
        // garde-fou tenant : la mission ET le user doivent appartenir à la société
        $chk = $pdo->prepare("SELECT 1 FROM pilotage_tasks WHERE id=? AND id_societe=?");
        $chk->execute([$taskId, $soc]);
        if (!$chk->fetchColumn()) return ['ok' => false, 'error' => 'Mission hors périmètre.'];
        $chkU = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND id_societe=?");
        $chkU->execute([$userId, $soc]);
        if (!$chkU->fetchColumn()) return ['ok' => false, 'error' => 'Collaborateur hors périmètre.'];

        $pdo->beginTransaction();
        try {
            // l'ancien exécutant principal perd is_primary (mais garde son rôle si superviseur/validateur ? non :
            // executor est un rôle distinct → on retire les executor primaires existants)
            $pdo->prepare("UPDATE pilotage_task_assignments SET is_primary=0
                           WHERE task_id=? AND assignment_role='executor'")->execute([$taskId]);
            // upsert nouvel exécutant principal
            $pdo->prepare("INSERT INTO pilotage_task_assignments (id_societe,task_id,user_id,assignment_role,is_primary,is_active)
                           VALUES (?,?,?,'executor',1,1)
                           ON DUPLICATE KEY UPDATE is_primary=1, is_active=1")
                ->execute([$soc, $taskId, $userId]);
            // historique
            $pdo->prepare("INSERT INTO pilotage_task_history (id_societe,task_id,action,field,new_value,user_id)
                           VALUES (?,?,'reassign','executor',?,?)")
                ->execute([$soc, $taskId, (string)$userId, (int)(function_exists('current_user_id') ? current_user_id() : 0)]);
            $pdo->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('pilotage_poste_label')) {
    /** Libellé lisible d'un poste (fallback = code). */
    function pilotage_poste_label(string $code): string {
        if (function_exists('pilotage_poste_catalog')) {
            $c = pilotage_poste_catalog();
            if (isset($c[$code]['label'])) return $c[$code]['label'];
        }
        return $code;
    }
}

if (!function_exists('pilotage_can_validate')) {
    /** Qui peut passer une mission au VERT / la rouvrir en jaune. */
    function pilotage_can_validate(): bool {
        $r = (int)(function_exists('current_role_id') ? current_role_id() : 0);
        return in_array($r, [1, 2, 7], true) || (function_exists('is_super_admin') && is_super_admin());
    }
}

if (!function_exists('pilotage_can_edit')) {
    /** Qui peut éditer procédure / checklist / affectations. */
    function pilotage_can_edit(): bool {
        $r = (int)(function_exists('current_role_id') ? current_role_id() : 0);
        // édition de contenu ouverte aux collaborateurs internes (1,2,3,7) ; validation reste restreinte.
        return in_array($r, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
    }
}

if (!function_exists('pilotage_task_scope_ok')) {
    /** La mission appartient-elle à la société de l'utilisateur ? (role 1/7 = bypass). */
    function pilotage_task_scope_ok(PDO $pdo, int $taskId, int $soc): bool {
        if ($soc <= 0 && (function_exists('is_super_admin') && is_super_admin())) return true;
        $st = $pdo->prepare("SELECT 1 FROM pilotage_tasks WHERE id=? AND id_societe=?");
        $st->execute([$taskId, $soc]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('pilotage_snapshot_version')) {
    /**
     * Sauvegarde une version (snapshot JSON de la mission + checklist + steps).
     * Utilisé avant de modifier une mission VERTE (conserve la version validée).
     * @return int version_number créé
     */
    function pilotage_snapshot_version(PDO $pdo, int $soc, int $taskId, ?int $userId): int {
        $t = $pdo->prepare("SELECT * FROM pilotage_tasks WHERE id=?");
        $t->execute([$taskId]);
        $task = $t->fetch(PDO::FETCH_ASSOC);
        if (!$task) return 0;
        $cl = $pdo->prepare("SELECT label,description,is_mandatory,display_order FROM pilotage_task_checklist_items WHERE task_id=? ORDER BY display_order");
        $cl->execute([$taskId]);
        $st = $pdo->prepare("SELECT step_number,title,description,responsible_role,requires_validation,display_order FROM pilotage_task_steps WHERE task_id=? ORDER BY display_order");
        $st->execute([$taskId]);
        $snapshot = [
            'task' => $task,
            'checklist' => $cl->fetchAll(PDO::FETCH_ASSOC),
            'steps' => $st->fetchAll(PDO::FETCH_ASSOC),
        ];
        $vn = $pdo->prepare("SELECT COALESCE(MAX(version_number),0)+1 FROM pilotage_task_versions WHERE task_id=?");
        $vn->execute([$taskId]);
        $ver = (int)$vn->fetchColumn();
        $pdo->prepare("INSERT INTO pilotage_task_versions (id_societe,task_id,version_number,documentation_status,snapshot_json,created_by)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$soc, $taskId, $ver, $task['documentation_status'], json_encode($snapshot, JSON_UNESCAPED_UNICODE), $userId]);
        return $ver;
    }
}

if (!function_exists('pilotage_history')) {
    function pilotage_history(PDO $pdo, int $soc, int $taskId, string $action, ?string $field = null, ?string $old = null, ?string $new = null, ?string $reason = null): void {
        $uid = (int)(function_exists('current_user_id') ? current_user_id() : 0);
        $pdo->prepare("INSERT INTO pilotage_task_history (id_societe,task_id,action,field,old_value,new_value,reason,user_id)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$soc, $taskId, $action, $field, $old, $new, $reason, $uid]);
    }
}

if (!function_exists('pilotage_touch_yellow_if_green')) {
    /**
     * Avant modification de contenu : si la mission est VERTE, snapshot + repasse en JAUNE
     * (nouvelle version à contrôler). Retourne true si un downgrade a eu lieu.
     */
    function pilotage_touch_yellow_if_green(PDO $pdo, int $soc, int $taskId): bool {
        $st = $pdo->prepare("SELECT documentation_status FROM pilotage_tasks WHERE id=?");
        $st->execute([$taskId]);
        if ($st->fetchColumn() !== 'green') return false;
        $uid = (int)(function_exists('current_user_id') ? current_user_id() : 0);
        pilotage_snapshot_version($pdo, $soc, $taskId, $uid);
        $pdo->prepare("UPDATE pilotage_tasks SET documentation_status='yellow', updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$uid, $taskId]);
        pilotage_history($pdo, $soc, $taskId, 'reopen_yellow', 'documentation_status', 'green', 'yellow', 'Modification d\'une mission validée');
        return true;
    }
}

if (!function_exists('pilotage_labels')) {
    /**
     * SOURCE UNIQUE des libellés français de l'interface. Aucune valeur technique
     * (on_demand, manual, executor…) ne doit apparaître à l'écran : tout passe ici.
     */
    function pilotage_labels(): array {
        return [
            'freq' => [
                'daily' => 'Quotidienne', 'weekly' => 'Hebdomadaire', 'monthly' => 'Mensuelle',
                'quarterly' => 'Trimestrielle', 'yearly' => 'Annuelle',
                'event_based' => 'Déclenchée par un événement', 'on_demand' => 'À la demande',
                'continuous' => 'Continue',
            ],
            'auto' => [
                'manual' => 'Manuelle', 'assisted' => 'Assistée',
                'partially_automated' => 'Partiellement automatisée', 'automated' => 'Automatisée',
            ],
            'role' => [
                'executor' => 'Exécutant', 'primary_responsible' => 'Responsable principal',
                'validator' => 'Validateur', 'supervisor' => 'Superviseur',
                'backup' => 'Remplaçant', 'informed' => 'Informé',
            ],
            'status' => [
                'red' => 'Non documentée', 'yellow' => 'À contrôler', 'green' => 'Validée',
            ],
            'priority' => [
                'low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'critical' => 'Critique',
            ],
        ];
    }
}

if (!function_exists('pilotage_L')) {
    /** Libellé français d'un code, avec repli lisible si code inconnu. */
    function pilotage_L(string $group, ?string $code, string $fallback = ''): string {
        if ($code === null || $code === '') return $fallback;
        $all = pilotage_labels();
        return $all[$group][$code] ?? ($fallback !== '' ? $fallback : ucfirst(str_replace('_', ' ', $code)));
    }
}
