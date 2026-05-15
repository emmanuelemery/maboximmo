<?php
declare(strict_types=1);

/**
 * FluxBox — helpers agents IA configurables.
 *
 * Cohérent avec [[project_fluxbox_module]] et migration 20260515_fluxbox_v3_03_agents_ia.
 *
 * API publique :
 *   fluxbox_agents_list_templates(?PDO $pdo = null): array
 *   fluxbox_agents_list_tenant(int $tenantId, ?PDO $pdo = null): array
 *   fluxbox_agents_get(int $agentId, ?PDO $pdo = null): ?array
 *   fluxbox_agents_clone_template(int $templateId, int $tenantId, ?PDO $pdo = null): array
 *   fluxbox_agents_toggle_active(int $agentId, ?PDO $pdo = null): bool
 *   fluxbox_agents_update(int $agentId, array $changes, ?PDO $pdo = null): array
 *   fluxbox_agents_evaluate_condition(array $condition, array $context): bool
 */

require_once __DIR__ . '/ged_glossary.php';

if (!function_exists('fluxbox_agents_pdo')) {
    function fluxbox_agents_pdo(): PDO
    {
        return ged_pdo();
    }
}

if (!function_exists('fluxbox_agents_list_templates')) {
    /**
     * Liste les 10 templates système (tenant_id=0, is_template=1).
     */
    function fluxbox_agents_list_templates(?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_agents_pdo();
        $st = $pdo->query("
            SELECT id, code, nom, description, provider, modele, plafond_eur_mensuel, is_active
            FROM fluxbox_agents_ia
            WHERE is_template = 1 AND tenant_id = 0
            ORDER BY code ASC
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fluxbox_agents_list_tenant')) {
    /**
     * Liste les agents d'un tenant (copies des templates ou créés ex nihilo).
     */
    function fluxbox_agents_list_tenant(int $tenantId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_agents_pdo();
        $st = $pdo->prepare("
            SELECT a.*, t.code AS template_code, t.nom AS template_nom
            FROM fluxbox_agents_ia a
            LEFT JOIN fluxbox_agents_ia t ON t.id = a.cloned_from
            WHERE a.tenant_id = ? AND a.is_template = 0
            ORDER BY a.code ASC
        ");
        $st->execute([$tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fluxbox_agents_get')) {
    function fluxbox_agents_get(int $agentId, ?PDO $pdo = null): ?array
    {
        if ($pdo === null) $pdo = fluxbox_agents_pdo();
        $st = $pdo->prepare("SELECT * FROM fluxbox_agents_ia WHERE id = ? LIMIT 1");
        $st->execute([$agentId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('fluxbox_agents_clone_template')) {
    /**
     * Clone un template vers un tenant. Copie aussi sections + sous-sections + actions.
     * Idempotent : si une copie existe déjà pour ce (tenant, code), ne fait rien.
     *
     * @return array{ok:bool, agent_id:?int, action:string, errors:array}
     */
    function fluxbox_agents_clone_template(int $templateId, int $tenantId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_agents_pdo();
        $userId = function_exists('current_user_id') ? (int)current_user_id() : null;

        // Lit le template
        $tpl = fluxbox_agents_get($templateId, $pdo);
        if (!$tpl) return ['ok'=>false, 'agent_id'=>null, 'action'=>'template_not_found', 'errors'=>['Template introuvable']];
        if ((int)$tpl['is_template'] !== 1) {
            return ['ok'=>false, 'agent_id'=>null, 'action'=>'not_a_template', 'errors'=>['Cet agent n\'est pas un template']];
        }

        // Vérifie qu'une copie n'existe pas déjà pour ce tenant
        $st = $pdo->prepare("SELECT id FROM fluxbox_agents_ia WHERE tenant_id = ? AND code = ? AND is_template = 0 LIMIT 1");
        $st->execute([$tenantId, $tpl['code']]);
        $existing = $st->fetchColumn();
        if ($existing) {
            return ['ok'=>true, 'agent_id'=>(int)$existing, 'action'=>'already_cloned', 'errors'=>[]];
        }

        try {
            $pdo->beginTransaction();

            // Clone l'agent
            $st = $pdo->prepare("
                INSERT INTO fluxbox_agents_ia
                  (tenant_id, code, nom, description, provider, modele, prompt_systeme,
                   plafond_eur_mensuel, is_template, cloned_from, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?)
            ");
            $st->execute([
                $tenantId, $tpl['code'], $tpl['nom'], $tpl['description'],
                $tpl['provider'], $tpl['modele'], $tpl['prompt_systeme'],
                $tpl['plafond_eur_mensuel'], $templateId, $userId ?: null,
            ]);
            $newAgentId = (int)$pdo->lastInsertId();

            // Clone les sections
            $stSec = $pdo->prepare("SELECT * FROM fluxbox_agents_sections WHERE agent_id = ? ORDER BY ordre ASC");
            $stSec->execute([$templateId]);
            $sections = $stSec->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sections as $sec) {
                $stIns = $pdo->prepare("
                    INSERT INTO fluxbox_agents_sections
                      (agent_id, code, nom, description, ordre, detection_rules, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stIns->execute([
                    $newAgentId, $sec['code'], $sec['nom'], $sec['description'],
                    $sec['ordre'], $sec['detection_rules'], $sec['is_active'],
                ]);
                $newSectionId = (int)$pdo->lastInsertId();

                // Clone les sous-sections de cette section
                $stSub = $pdo->prepare("SELECT * FROM fluxbox_agents_subsections WHERE section_id = ? ORDER BY ordre ASC");
                $stSub->execute([(int)$sec['id']]);
                $subs = $stSub->fetchAll(PDO::FETCH_ASSOC);

                foreach ($subs as $sub) {
                    $stIns2 = $pdo->prepare("
                        INSERT INTO fluxbox_agents_subsections
                          (section_id, code, nom, description, ordre, detection_rules,
                           fields_schema, table_extraction_cible, provider_override, modele_override, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stIns2->execute([
                        $newSectionId, $sub['code'], $sub['nom'], $sub['description'],
                        $sub['ordre'], $sub['detection_rules'], $sub['fields_schema'],
                        $sub['table_extraction_cible'], $sub['provider_override'],
                        $sub['modele_override'], $sub['is_active'],
                    ]);
                    $newSubsectionId = (int)$pdo->lastInsertId();

                    // Clone les actions
                    $stAct = $pdo->prepare("SELECT * FROM fluxbox_agents_actions WHERE subsection_id = ? ORDER BY ordre ASC");
                    $stAct->execute([(int)$sub['id']]);
                    foreach ($stAct->fetchAll(PDO::FETCH_ASSOC) as $act) {
                        $stIns3 = $pdo->prepare("
                            INSERT INTO fluxbox_agents_actions
                              (subsection_id, ordre, label, condition_json, action_type, payload_json, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stIns3->execute([
                            $newSubsectionId, $act['ordre'], $act['label'],
                            $act['condition_json'], $act['action_type'],
                            $act['payload_json'], $act['is_active'],
                        ]);
                    }
                }
            }

            $pdo->commit();
            return ['ok'=>true, 'agent_id'=>$newAgentId, 'action'=>'cloned', 'errors'=>[]];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok'=>false, 'agent_id'=>null, 'action'=>'error', 'errors'=>[$e->getMessage()]];
        }
    }
}

if (!function_exists('fluxbox_agents_toggle_active')) {
    function fluxbox_agents_toggle_active(int $agentId, ?PDO $pdo = null): bool
    {
        if ($pdo === null) $pdo = fluxbox_agents_pdo();
        $st = $pdo->prepare("UPDATE fluxbox_agents_ia SET is_active = 1 - is_active WHERE id = ? AND is_template = 0");
        return $st->execute([$agentId]) && $st->rowCount() > 0;
    }
}

if (!function_exists('fluxbox_agents_update')) {
    /**
     * Modifie les champs éditables d'un agent (tenant, non template).
     * Champs autorisés : nom, description, provider, modele, prompt_systeme, plafond_eur_mensuel
     */
    function fluxbox_agents_update(int $agentId, array $changes, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_agents_pdo();

        $agent = fluxbox_agents_get($agentId, $pdo);
        if (!$agent) return ['ok'=>false, 'errors'=>['Agent introuvable']];
        if ((int)$agent['is_template'] === 1) {
            return ['ok'=>false, 'errors'=>['Un template ne peut pas être modifié — cloner d\'abord']];
        }

        $allowed = ['nom','description','provider','modele','prompt_systeme','plafond_eur_mensuel'];
        $set = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $changes)) {
                $set[] = "`$f` = ?";
                $params[] = $changes[$f];
            }
        }
        if (!$set) return ['ok'=>true, 'errors'=>[]];

        $params[] = $agentId;
        $sql = "UPDATE fluxbox_agents_ia SET " . implode(', ', $set) . " WHERE id = ?";
        try {
            $pdo->prepare($sql)->execute($params);
            return ['ok'=>true, 'errors'=>[]];
        } catch (Throwable $e) {
            return ['ok'=>false, 'errors'=>[$e->getMessage()]];
        }
    }
}

if (!function_exists('fluxbox_agents_evaluate_condition')) {
    /**
     * Évalue une condition JSON sur un contexte.
     *
     * Syntaxe :
     *   {"always": true}                                         → toujours vrai
     *   {"field":"solde_fin","op":"<","value":0}                 → solde_fin < 0
     *   {"field":"compte4","op":"in","value":["0042","0043"]}    → compte4 ∈ liste
     *   {"field":"banque","op":"contains","value":"Crédit"}      → contient "Crédit"
     *   {"field":"periode","op":"regex","value":"^12-\\d{4}$"}    → décembre
     *   {"and":[c1, c2, ...]}                                    → ET logique
     *   {"or":[c1, c2, ...]}                                     → OU logique
     *   {"not":c}                                                → négation
     *
     * @param array $condition Spec JSON décodée
     * @param array $context   Valeurs à matcher (clé => valeur)
     * @return bool
     */
    function fluxbox_agents_evaluate_condition(array $condition, array $context): bool
    {
        if (!empty($condition['always'])) return true;

        if (isset($condition['and']) && is_array($condition['and'])) {
            foreach ($condition['and'] as $sub) {
                if (!fluxbox_agents_evaluate_condition((array)$sub, $context)) return false;
            }
            return true;
        }
        if (isset($condition['or']) && is_array($condition['or'])) {
            foreach ($condition['or'] as $sub) {
                if (fluxbox_agents_evaluate_condition((array)$sub, $context)) return true;
            }
            return false;
        }
        if (isset($condition['not'])) {
            return !fluxbox_agents_evaluate_condition((array)$condition['not'], $context);
        }

        $field = (string)($condition['field'] ?? '');
        $op    = (string)($condition['op']    ?? '=');
        $value = $condition['value'] ?? null;
        if ($field === '') return false;

        $actual = $context[$field] ?? null;

        return match ($op) {
            '=', '==' => $actual == $value,
            '!='      => $actual != $value,
            '<'       => is_numeric($actual) && is_numeric($value) && $actual <  $value,
            '<='      => is_numeric($actual) && is_numeric($value) && $actual <= $value,
            '>'       => is_numeric($actual) && is_numeric($value) && $actual >  $value,
            '>='      => is_numeric($actual) && is_numeric($value) && $actual >= $value,
            'in'      => is_array($value) && in_array((string)$actual, array_map('strval', $value), true),
            'contains'=> is_string($actual) && is_string($value) && stripos($actual, $value) !== false,
            'regex'   => is_string($actual) && is_string($value) && @preg_match('/' . $value . '/u', $actual) === 1,
            'empty'   => $actual === null || $actual === '' || $actual === [],
            'not_empty' => !($actual === null || $actual === '' || $actual === []),
            default   => false,
        };
    }
}
