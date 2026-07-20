<?php
declare(strict_types=1);

/**
 * pilotage_automations.php — Moteur d'automatisations DÉCLARATIVES + readiness.
 *
 * Règles = données (trigger + condition JSON + action). Aucune exécution de PHP
 * stocké en base. Garde-fou : une action juridiquement engageante n'est jamais
 * exécutée automatiquement (exec_mode='validation_required' → instance en attente).
 */

require_once __DIR__ . '/pilotage.php';

if (!function_exists('pilotage_exec_mode_label')) {
    function pilotage_exec_mode_label(string $m): string {
        return [
            'informative' => 'Informatif', 'assisted' => 'Assisté',
            'automatic' => 'Automatique', 'validation_required' => 'Soumis à validation',
        ][$m] ?? $m;
    }
}
if (!function_exists('pilotage_trigger_label')) {
    function pilotage_trigger_label(string $t): string {
        return [
            'task_started' => 'Mission démarrée', 'step_started' => 'Étape démarrée',
            'step_completed' => 'Étape terminée', 'task_completed' => 'Mission terminée',
            'status_changed' => 'Changement de statut', 'document_received' => 'Document reçu',
            'document_signed' => 'Document signé', 'form_submitted' => 'Formulaire soumis',
            'deadline_reached' => 'Échéance atteinte', 'manual_trigger' => 'Déclenchement manuel',
            'field_changed' => 'Champ modifié',
        ][$t] ?? $t;
    }
}
if (!function_exists('pilotage_action_label')) {
    function pilotage_action_label(string $a): string {
        return [
            'create_task_instance' => 'Créer une action', 'create_step_instance' => 'Créer une étape',
            'complete_task_instance' => 'Clôturer l’action', 'open_next_step' => 'Ouvrir l’étape suivante',
            'launch_other_mission' => 'Lancer une autre mission', 'assign_user' => 'Attribuer',
            'send_notification' => 'Notifier', 'send_email' => 'Envoyer un e-mail',
            'generate_document' => 'Générer un document', 'create_upload_link' => 'Créer un lien de chargement',
            'schedule_reminder' => 'Programmer une relance', 'update_entity_status' => 'Mettre à jour un statut',
            'add_fluxbox_item' => 'Ajouter à FluxBox', 'open_validation_request' => 'Demander une validation',
        ][$a] ?? $a;
    }
}

if (!function_exists('pilotage_automations_for_task')) {
    function pilotage_automations_for_task(PDO $pdo, int $taskId): array {
        $st = $pdo->prepare("SELECT a.*, tt.name AS target_task_name
                             FROM pilotage_task_automations a
                             LEFT JOIN pilotage_tasks tt ON tt.id = a.target_task_id
                             WHERE a.source_task_id=? ORDER BY a.display_order, a.id");
        $st->execute([$taskId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('pilotage_delay_label')) {
    function pilotage_delay_label(?int $v, ?string $u): string {
        if (!$v) return 'Immédiat';
        $map = ['minute' => 'minute', 'hour' => 'heure', 'day' => 'jour', 'business_day' => 'jour ouvré', 'week' => 'semaine', 'month' => 'mois'];
        $lab = $map[$u] ?? $u;
        return $v . ' ' . $lab . ($v > 1 ? 's' : '');
    }
}

/* ─────────── Évaluation de condition (déclaratif, sûr) ─────────── */
if (!function_exists('pilotage_eval_condition')) {
    /**
     * condition_json : null (toujours vrai) OU {"field":"x","op":"=","value":"y"}
     * OU {"all":[...]} / {"any":[...]}. Comparé au tableau $ctx (contexte de l'action).
     */
    function pilotage_eval_condition(?string $json, array $ctx): bool {
        if ($json === null || trim($json) === '') return true;
        $c = json_decode($json, true);
        if (!is_array($c)) return true;
        return pilotage_eval_node($c, $ctx);
    }
    function pilotage_eval_node(array $c, array $ctx): bool {
        if (isset($c['all'])) { foreach ($c['all'] as $n) if (!pilotage_eval_node($n, $ctx)) return false; return true; }
        if (isset($c['any'])) { foreach ($c['any'] as $n) if (pilotage_eval_node($n, $ctx)) return true; return false; }
        $f = $c['field'] ?? null; if ($f === null) return true;
        $op = $c['op'] ?? '='; $v = $c['value'] ?? null; $actual = $ctx[$f] ?? null;
        switch ($op) {
            case '=':  return (string)$actual === (string)$v;
            case '!=': return (string)$actual !== (string)$v;
            case '>':  return (float)$actual > (float)$v;
            case '<':  return (float)$actual < (float)$v;
            case '>=': return (float)$actual >= (float)$v;
            case '<=': return (float)$actual <= (float)$v;
            case 'empty': return $actual === null || $actual === '' ;
            case 'not_empty': return !($actual === null || $actual === '');
            default: return false;
        }
    }
}

/* ─────────── Création d'une action réelle (instance) ─────────── */
if (!function_exists('pilotage_create_instance')) {
    /**
     * @param array $d { task_id, title, assigned_user_id?, entity_type?, entity_id?, due_at?,
     *                   status?, priority?, source_type?, source_id?, dedup_key?, id_agence?, description? }
     * @return array{ok:bool, id?:int, duplicate?:bool, error?:string}
     */
    function pilotage_create_instance(PDO $pdo, int $soc, array $d): array {
        $taskId = (int)($d['task_id'] ?? 0);
        if ($taskId <= 0 || !$soc) return ['ok' => false, 'error' => 'task/soc requis'];
        $dedup = $d['dedup_key'] ?? null;
        if ($dedup) {
            $ex = $pdo->prepare("SELECT id FROM pilotage_task_instances WHERE id_societe=? AND dedup_key=?");
            $ex->execute([$soc, $dedup]);
            $id = $ex->fetchColumn();
            if ($id) return ['ok' => true, 'id' => (int)$id, 'duplicate' => true];
        }
        $pdo->prepare("INSERT INTO pilotage_task_instances
            (id_societe,id_agence,task_id,assigned_user_id,entity_type,entity_id,title,description,period_label,
             due_at,visible_from,status,priority,source_type,source_id,dedup_key,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $soc, $d['id_agence'] ?? null, $taskId, $d['assigned_user_id'] ?? null,
                $d['entity_type'] ?? null, $d['entity_id'] ?? null, (string)($d['title'] ?? 'Action'),
                $d['description'] ?? null, $d['period_label'] ?? null, $d['due_at'] ?? null,
                $d['visible_from'] ?? null, $d['status'] ?? 'pending', $d['priority'] ?? 'normal',
                $d['source_type'] ?? null, $d['source_id'] ?? null, $dedup,
                (int)(function_exists('current_user_id') ? current_user_id() : 0),
            ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }
}

/* ─────────── Déclenchement du moteur ─────────── */
if (!function_exists('pilotage_automation_fire')) {
    /**
     * Exécute les automatisations d'une mission pour un déclencheur donné.
     * SÛR : ne réalise que des actions NON engageantes (créer action/relance/validation/notif,
     * lancer une mission suivante). Les actions engageantes sont préparées en attente de validation.
     *
     * @param array $ctx contexte { trigger_status?, entity_type?, entity_id?, assigned_user_id?,
     *                              proprietaire?, bien?, ...champs pour conditions }
     * @return array liste des runs {automation_id, action_type, status, instance_id?, message}
     */
    function pilotage_automation_fire(PDO $pdo, int $soc, int $sourceTaskId, string $triggerType, array $ctx = []): array {
        $runs = [];
        $q = $pdo->prepare("SELECT * FROM pilotage_task_automations
                            WHERE id_societe=? AND source_task_id=? AND trigger_type=? AND is_active=1
                            ORDER BY display_order, id");
        $q->execute([$soc, $sourceTaskId, $triggerType]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            // filtre statut du déclencheur
            if ($a['trigger_status'] && (string)$a['trigger_status'] !== (string)($ctx['trigger_status'] ?? '')) continue;
            if (!pilotage_eval_condition($a['condition_json'], $ctx)) continue;

            $res = pilotage_automation_apply($pdo, $soc, $a, $ctx);
            // journalise le run
            $pdo->prepare("UPDATE pilotage_task_automations SET last_run_at=NOW(), last_run_status=?, last_run_error=? WHERE id=?")
                ->execute([$res['status'], $res['error'] ?? null, $a['id']]);
            $pdo->prepare("INSERT INTO pilotage_automation_runs (id_societe,automation_id,instance_id,status,message,triggered_by)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$soc, $a['id'], $res['instance_id'] ?? null, $res['status'], $res['message'] ?? null,
                           (int)(function_exists('current_user_id') ? current_user_id() : 0)]);
            $runs[] = ['automation_id' => (int)$a['id'], 'action_type' => $a['action_type']] + $res;
        }
        return $runs;
    }

    /** Applique UNE règle (partie sûre du moteur). @return array{status,message?,error?,instance_id?} */
    function pilotage_automation_apply(PDO $pdo, int $soc, array $a, array $ctx): array {
        $engaging = ['generate_document', 'send_email', 'document_signed', 'update_entity_status'];
        $action = $a['action_type'];

        // Calcul d'échéance
        $due = null;
        if (!empty($a['delay_value'])) {
            $unit = ['minute' => 'minute', 'hour' => 'hour', 'day' => 'day', 'business_day' => 'day', 'week' => 'week', 'month' => 'month'][$a['delay_unit']] ?? 'day';
            $due = date('Y-m-d H:i:s', strtotime('+' . (int)$a['delay_value'] . ' ' . $unit));
        }
        $title = (string)($a['action_title_template'] ?? pilotage_action_label($action));
        // interpolation simple {champ}
        $title = preg_replace_callback('/\{(\w+)\}/', fn($m) => (string)($ctx[$m[1]] ?? $m[1]), $title);

        // Attribution
        $assignee = null;
        if ($a['assigned_user_rule'] === 'previous_executor') $assignee = $ctx['assigned_user_id'] ?? null;

        switch ($action) {
            case 'launch_other_mission':
                if (!$a['target_task_id']) return ['status' => 'skipped', 'message' => 'Mission cible non définie'];
                $r = pilotage_create_instance($pdo, $soc, [
                    'task_id' => (int)$a['target_task_id'], 'title' => $title, 'assigned_user_id' => $assignee,
                    'entity_type' => $ctx['entity_type'] ?? null, 'entity_id' => $ctx['entity_id'] ?? null,
                    'due_at' => $due, 'source_type' => 'automation', 'source_id' => (int)$a['id'],
                    'dedup_key' => 'auto:' . $a['id'] . ':' . ($ctx['entity_type'] ?? '') . ':' . ($ctx['entity_id'] ?? ''),
                ]);
                return $r['ok'] ? ['status' => $r['duplicate'] ?? false ? 'duplicate' : 'ok', 'instance_id' => $r['id'] ?? null, 'message' => 'Mission suivante lancée'] : ['status' => 'error', 'error' => $r['error']];

            case 'create_task_instance':
            case 'schedule_reminder':
            case 'create_step_instance':
            case 'open_next_step':
            case 'assign_user':
            case 'send_notification':
            case 'add_fluxbox_item':
            case 'create_upload_link':
                $r = pilotage_create_instance($pdo, $soc, [
                    'task_id' => (int)($a['target_task_id'] ?: $a['source_task_id']), 'title' => $title,
                    'assigned_user_id' => $assignee, 'entity_type' => $ctx['entity_type'] ?? null,
                    'entity_id' => $ctx['entity_id'] ?? null, 'due_at' => $due, 'status' => 'pending',
                    'source_type' => 'automation', 'source_id' => (int)$a['id'],
                    'dedup_key' => 'auto:' . $a['id'] . ':' . ($ctx['entity_type'] ?? '') . ':' . ($ctx['entity_id'] ?? ''),
                ]);
                return $r['ok'] ? ['status' => ($r['duplicate'] ?? false) ? 'duplicate' : 'ok', 'instance_id' => $r['id'] ?? null] : ['status' => 'error', 'error' => $r['error']];

            case 'open_validation_request':
                $r = pilotage_create_instance($pdo, $soc, [
                    'task_id' => (int)($a['target_task_id'] ?: $a['source_task_id']), 'title' => $title,
                    'assigned_user_id' => $assignee, 'entity_type' => $ctx['entity_type'] ?? null,
                    'entity_id' => $ctx['entity_id'] ?? null, 'due_at' => $due, 'status' => 'waiting',
                    'source_type' => 'automation', 'source_id' => (int)$a['id'],
                    'dedup_key' => 'val:' . $a['id'] . ':' . ($ctx['entity_type'] ?? '') . ':' . ($ctx['entity_id'] ?? ''),
                ]);
                return $r['ok'] ? ['status' => 'ok', 'instance_id' => $r['id'] ?? null, 'message' => 'Demande de validation créée'] : ['status' => 'error', 'error' => $r['error']];

            default:
                // Action potentiellement engageante : jamais exécutée automatiquement.
                if (in_array($action, $engaging, true) || $a['exec_mode'] === 'validation_required') {
                    return ['status' => 'held', 'message' => 'Action engageante : préparée, en attente de validation humaine'];
                }
                return ['status' => 'skipped', 'message' => 'Action non exécutable automatiquement'];
        }
    }
}

/* ─────────── Contrôle préalable (readiness) avant validation ─────────── */
if (!function_exists('pilotage_task_readiness')) {
    /**
     * @return array<string, bool> Procédure / Checklist / Juridique / Affectations / Liens MBI / Automatisations
     */
    function pilotage_task_readiness(PDO $pdo, int $taskId): array {
        $count = function (string $sql) use ($pdo, $taskId): int {
            $s = $pdo->prepare($sql); $s->execute([$taskId]); return (int)$s->fetchColumn();
        };
        $t = $pdo->prepare("SELECT procedure_text FROM pilotage_tasks WHERE id=?"); $t->execute([$taskId]);
        $hasProc = trim((string)$t->fetchColumn()) !== '';
        $nbSteps = $count("SELECT COUNT(*) FROM pilotage_task_steps WHERE task_id=?");
        $nbCheck = $count("SELECT COUNT(*) FROM pilotage_task_checklist_items WHERE task_id=?");
        $legalGreen = $count("SELECT COUNT(*) FROM pilotage_task_legal_rules WHERE task_id=? AND validation_status='green'");
        $legalTotal = $count("SELECT COUNT(*) FROM pilotage_task_legal_rules WHERE task_id=?");
        $nbAssign = $count("SELECT COUNT(*) FROM pilotage_task_assignments WHERE task_id=? AND is_active=1");
        $nbMbi = $count("SELECT COUNT(*) FROM pilotage_task_resources WHERE task_id=? AND (page_route IS NOT NULL OR resource_type='internal_page')");
        $autoTested = $count("SELECT COUNT(*) FROM pilotage_task_automations a JOIN pilotage_automation_runs r ON r.automation_id=a.id WHERE a.source_task_id=?");

        return [
            'Procédure complète'      => $hasProc && $nbSteps > 0,
            'Checklist complète'      => $nbCheck > 0,
            'Juridique contrôlé'      => $legalTotal > 0 && $legalGreen === $legalTotal,
            'Affectations définies'   => $nbAssign > 0,
            'Liens MBI configurés'    => $nbMbi > 0,
            'Automatisations testées' => $autoTested > 0,
        ];
    }
}
