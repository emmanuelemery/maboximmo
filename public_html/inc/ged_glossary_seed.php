<?php
declare(strict_types=1);

/**
 * GED Glossaire — Helper de seed batch optimisé mémoire.
 *
 * Réutilisé par :
 *  - admin/admin_ged_glossaire.php (UI manuelle)
 *  - api/cron_ged_glossary_sync.php (synchronisation quotidienne automatique)
 *
 * Précharge tout en mémoire (1 SELECT), insère via prepared statement réutilisé,
 * transaction unique pour batch atomique. Conçu pour tourner sur ~1000 entrées
 * en quelques secondes.
 */

require_once __DIR__ . '/ged_glossary.php';

if (!function_exists('ged_glossary_seed_initial')) {
    /**
     * Lance le seed initial du glossaire pour le tenant courant.
     *
     * @param PDO $pdo
     * @param bool $dryRun Si true, simule sans rien créer
     * @return array{
     *   dry_run:bool, tenant_id:int, created:int, skipped:int,
     *   errors:array, by_category:array, samples:array, duration_ms:int
     * }
     */
    function ged_glossary_seed_initial(PDO $pdo, bool $dryRun = false): array
    {
        @set_time_limit(600);
        @ignore_user_abort(true);

        $tenantId = (int)(ged_current_tenant_id() ?? 0);
        $startTime = microtime(true);
        $report = [
            'dry_run'    => $dryRun,
            'tenant_id'  => $tenantId,
            'created'    => 0,
            'skipped'    => 0,
            'errors'     => [],
            'by_category'=> [],
            'samples'    => [],
            'duration_ms'=> 0,
        ];

        // Précharge cache mémoire (1 SELECT global)
        $takenCodes = [];
        $existing   = [];
        try {
            $st = $pdo->prepare("
                SELECT category, entity_table, entity_id, code
                FROM ged_codes_glossaire
                WHERE tenant_id = ?
            ");
            $st->execute([$tenantId]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cat = (string)$r['category'];
                $takenCodes[$cat][(string)$r['code']] = true;
                if (!empty($r['entity_id']) && !empty($r['entity_table'])) {
                    $existing[$cat][$r['entity_table'] . ':' . $r['entity_id']] = true;
                }
            }
        } catch (Throwable $e) {
            $report['errors'][] = "Précharge cache : " . $e->getMessage();
        }

        $stIns = $pdo->prepare("
            INSERT INTO ged_codes_glossaire
              (tenant_id, category, entity_table, entity_id, code, label, is_locked, is_active, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?)
        ");
        $createdBy = function_exists('current_user_id') ? (int)current_user_id() : null;
        $notes = 'Seedé le ' . date('Y-m-d H:i:s');

        $deriveUnique = function (string $category, string $label, array $opts = []) use (&$takenCodes): string {
            $maxLen = ged_glossary_max_length($category);
            $candidate = '';
            if ($category === 'user') {
                $mat = trim((string)($opts['matricule_paie'] ?? ''));
                if ($mat !== '') {
                    $candidate = ged_glossary_slug_code($mat);
                } else {
                    $candidate = 'U' . (int)($opts['user_id'] ?? 0);
                }
            } elseif (in_array($category, ['agence', 'immeuble'], true)) {
                $existingCode = trim((string)($opts['code_existant'] ?? ''));
                if ($existingCode !== '') {
                    $candidate = ged_glossary_slug_code($existingCode);
                } else {
                    $candidate = ged_glossary_derive_from_label($label, $maxLen);
                }
            } elseif (in_array($category, ['metier_n1', 'metier_n2', 'metier_n3'], true)) {
                $clean = preg_replace('/^\d+\s*[-_]?\s*/', '', $label) ?? $label;
                $candidate = ged_glossary_derive_from_label($clean, $maxLen);
            } else {
                $candidate = ged_glossary_derive_from_label($label, $maxLen);
            }
            if (mb_strlen($candidate) > $maxLen) {
                $candidate = mb_substr($candidate, 0, $maxLen);
            }
            if ($candidate === '' || isset($takenCodes[$category][$candidate])) {
                $base = $candidate !== '' ? $candidate : 'X';
                $found = false;
                for ($i = 1; $i <= 99; $i++) {
                    $suffix = (string)$i;
                    $room = $maxLen - mb_strlen($suffix);
                    if ($room < 1) { $base = mb_substr($base, 0, 1); $room = $maxLen - mb_strlen($suffix); }
                    $try = mb_substr($base, 0, $room) . $suffix;
                    if (!isset($takenCodes[$category][$try])) {
                        $candidate = $try; $found = true; break;
                    }
                }
                if (!$found) {
                    $candidate = mb_substr(strtoupper(bin2hex(random_bytes(4))), 0, $maxLen);
                }
            }
            $takenCodes[$category][$candidate] = true;
            return $candidate;
        };

        $seedOne = function (string $category, ?string $entityTable, ?int $entityId,
                            string $code, string $label)
                   use (&$report, &$existing, &$takenCodes, $stIns, $tenantId, $dryRun, $createdBy, $notes): string {
            if (!isset($report['by_category'][$category])) {
                $report['by_category'][$category] = ['created' => 0, 'skipped' => 0, 'failed' => 0];
            }
            if ($entityId !== null && $entityTable !== null) {
                if (isset($existing[$category][$entityTable . ':' . $entityId])) {
                    $report['skipped']++;
                    $report['by_category'][$category]['skipped']++;
                    return 'exists';
                }
            }
            if ($dryRun) {
                $report['created']++;
                $report['by_category'][$category]['created']++;
                return 'would_create';
            }
            try {
                $stIns->execute([
                    $tenantId, $category,
                    ($entityTable !== null && $entityTable !== '') ? $entityTable : null,
                    $entityId, $code, $label, $notes, $createdBy ?: null,
                ]);
                if ($entityId !== null && $entityTable !== null) {
                    $existing[$category][$entityTable . ':' . $entityId] = true;
                }
                $report['created']++;
                $report['by_category'][$category]['created']++;
                return 'created';
            } catch (Throwable $e) {
                $report['errors'][] = "$category | $label → $code : " . $e->getMessage();
                $report['by_category'][$category]['failed']++;
                return 'error';
            }
        };

        if (!$dryRun) {
            try { $pdo->beginTransaction(); } catch (Throwable) {}
        }

        // 1. Sociétés
        try {
            $st = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
                $name = (string)$s['nom'];
                $code = $deriveUnique('societe', $name);
                $r = $seedOne('societe', 'societes', (int)$s['id'], $code, $name);
                $report['samples']['societe'][] = ['id'=>(int)$s['id'],'label'=>$name,'code'=>$code,'status'=>$r];
            }
        } catch (Throwable $e) { $report['errors'][] = "Sociétés : " . $e->getMessage(); }

        // 2. Agences
        try {
            $st = $pdo->query("
                SELECT id, nom_agence,
                       COALESCE(NULLIF(code_agence,''), NULLIF(code_interne,'')) AS code_existant
                FROM agences ORDER BY nom_agence
            ");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
                $name = (string)$a['nom_agence'];
                $code = $deriveUnique('agence', $name, ['code_existant' => (string)$a['code_existant']]);
                $r = $seedOne('agence', 'agences', (int)$a['id'], $code, $name);
                $report['samples']['agence'][] = ['id'=>(int)$a['id'],'label'=>$name,'code'=>$code,'status'=>$r];
            }
        } catch (Throwable $e) { $report['errors'][] = "Agences : " . $e->getMessage(); }

        // 3. Users actifs
        try {
            $st = $pdo->query("
                SELECT id, prenom, nom, matricule_paie
                FROM users WHERE actif = 1 ORDER BY nom, prenom
            ");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
                $label = trim((string)$u['prenom'] . ' ' . (string)$u['nom']);
                if ($label === '') $label = 'User#' . $u['id'];
                $code = $deriveUnique('user', $label, [
                    'matricule_paie' => (string)$u['matricule_paie'],
                    'user_id'        => (int)$u['id'],
                ]);
                $r = $seedOne('user', 'users', (int)$u['id'], $code, $label);
                $report['samples']['user'][] = ['id'=>(int)$u['id'],'label'=>$label,'code'=>$code,'status'=>$r];
            }
        } catch (Throwable $e) { $report['errors'][] = "Users : " . $e->getMessage(); }

        // 4. Immeubles
        try {
            $st = $pdo->query("
                SELECT id, nom_immeuble, reference_immeuble
                FROM immeubles ORDER BY nom_immeuble LIMIT 1000
            ");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $imm) {
                $name = (string)$imm['nom_immeuble'];
                $code = $deriveUnique('immeuble', $name, [
                    'code_existant' => (string)$imm['reference_immeuble'],
                ]);
                $r = $seedOne('immeuble', 'immeubles', (int)$imm['id'], $code, $name);
                $report['samples']['immeuble'][] = ['id'=>(int)$imm['id'],'label'=>$name,'code'=>$code,'status'=>$r];
            }
        } catch (Throwable $e) { $report['errors'][] = "Immeubles : " . $e->getMessage(); }

        // 5. Types document de base
        $typesBase = [
            'RELEVE' => 'Relevé bancaire', 'FACTURE' => 'Facture fournisseur',
            'BULLETIN' => 'Bulletin de paie', 'PV' => 'PV Assemblée Générale',
            'MANDAT' => 'Mandat (gestion/syndic/vente)', 'KBIS' => 'Extrait Kbis',
            'DEVIS' => 'Devis', 'BAIL' => 'Bail',
            'COMPROMIS' => 'Compromis de vente', 'ACTE' => 'Acte de propriété',
            'EDL' => 'État des lieux', 'RIB' => 'RIB',
            'CARTE-PRO' => 'Carte professionnelle', 'GARANTIE' => 'Garantie financière',
            'RC-PRO' => 'RC Pro', 'ATTESTATION' => 'Attestation',
            'COURRIER' => 'Courrier', 'MAIL' => 'Email',
            'CONTRAT' => 'Contrat', 'AVENANT' => 'Avenant',
        ];
        foreach ($typesBase as $code => $label) {
            if (isset($takenCodes['type_document'][$code])) {
                $report['skipped']++;
                $report['by_category']['type_document']['skipped'] = ($report['by_category']['type_document']['skipped'] ?? 0) + 1;
                $report['samples']['type_document'][] = ['id'=>null,'label'=>$label,'code'=>$code,'status'=>'exists'];
                continue;
            }
            $takenCodes['type_document'][$code] = true;
            $r = $seedOne('type_document', null, null, $code, $label);
            $report['samples']['type_document'][] = ['id'=>null,'label'=>$label,'code'=>$code,'status'=>$r];
        }

        // 6. Métiers N1/N2/N3
        foreach (['metier_n1' => 1, 'metier_n2' => 2, 'metier_n3' => 3] as $cat => $lvl) {
            try {
                $st = $pdo->prepare("
                    SELECT id, code AS source_code, label
                    FROM ged_level_codes
                    WHERE level_number = ? AND is_active = 1 AND COALESCE(is_virtual, 0) = 0
                    ORDER BY position
                ");
                $st->execute([$lvl]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lc) {
                    $label = (string)$lc['label'];
                    $sourceCode = (string)$lc['source_code'];
                    $code = $deriveUnique($cat, $sourceCode !== '' ? $sourceCode : $label);
                    $r = $seedOne($cat, 'ged_level_codes', (int)$lc['id'], $code, $label);
                    $report['samples'][$cat][] = ['id'=>(int)$lc['id'],'label'=>$label,'code'=>$code,'status'=>$r];
                }
            } catch (Throwable $e) {
                $report['errors'][] = "Métier $cat : " . $e->getMessage();
            }
        }

        if (!$dryRun) {
            try { $pdo->commit(); } catch (Throwable) {}
        }

        $report['duration_ms'] = (int)round((microtime(true) - $startTime) * 1000);
        return $report;
    }
}
