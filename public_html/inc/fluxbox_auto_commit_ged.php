<?php
/**
 * inc/fluxbox_auto_commit_ged.php
 *
 * Module d'auto-commit en GED après ingestion FluxBox + analyse IA.
 *
 * RÈGLES MÉTIER (validées Emery 2026-05-25) :
 *   - Si IA confiance ≥ 90% ET entité métier identifiée ET type doc spécifique
 *     → AUTO-COMMIT en ged_documents + ged_document_links
 *     → UPDATE fluxbox_cartes statut='applied' + naming_applied_at
 *     → Carte n'apparaît PLUS comme "à valider"
 *   - Sinon → carte reste 'pending' → review manuelle obligatoire dans bien_doc_360.php
 *
 * SAFETY : les hooks métier (MAJ biens.dpe_classe, mandats.date_signature, etc.)
 * NE sont PAS déclenchés en auto-commit. Ils restent opt-in via la page review.
 *
 * Réf : Sprint Objectif Ultime 2026-05-25 (étape 2/4)
 */

declare(strict_types=1);

require_once __DIR__ . '/ged_doc_naming_v3.php';
require_once __DIR__ . '/fluxbox_va_orchestrator.php';

if (!function_exists('fluxbox_auto_commit_eligible')) {
    /**
     * Évalue si une carte est éligible à l'auto-commit GED.
     *
     * @return array {
     *   eligible:bool,
     *   reason:string,                // raison de l'éligibilité ou refus
     *   ia_confidence:int,
     *   entity_type:?string,
     *   entity_id:?int,
     *   type_doc:?string,
     *   ged_folder_id:?int,
     *   v3_1_name:?string,
     * }
     */
    function fluxbox_auto_commit_eligible(int $carteId, PDO $pdo): array {
        $result = [
            'eligible'     => false,
            'reason'       => 'unknown',
            'ia_confidence'=> 0,
            'entity_type'  => null,
            'entity_id'    => null,
            'type_doc'     => null,
            'ged_folder_id'=> null,
            'v3_1_name'    => null,
        ];

        // 1. V3.1 compute (donne entity + N1 + type + nom)
        $v3 = fluxbox_va_compute_v3_1_name($carteId, $pdo);
        if (empty($v3['name']) || empty($v3['entity_type']) || empty($v3['entity_id'])) {
            $result['reason'] = 'no_entity_detected';
            return $result;
        }
        $result['entity_type'] = $v3['entity_type'];
        $result['entity_id']   = $v3['entity_id'];
        $result['type_doc']    = $v3['type'];
        $result['v3_1_name']   = $v3['name'];

        // 2. Confidence IA
        $st = $pdo->prepare("SELECT c.naming_extracted_json, c.confiance_ia, d.hash_sha256
                             FROM fluxbox_cartes c LEFT JOIN fluxbox_documents d ON d.id = c.document_id
                             WHERE c.id = ?");
        $st->execute([$carteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $result['reason'] = 'carte_not_found'; return $result; }

        $confCarte = (int)round((float)($row['confiance_ia'] ?? 0));
        $confCache = 0;
        if (!empty($row['hash_sha256'])) {
            $st = $pdo->prepare("SELECT confidence FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
            $st->execute([$row['hash_sha256']]);
            $confCache = (int)$st->fetchColumn();
        }
        $iaConf = max($confCarte, $confCache);
        $result['ia_confidence'] = $iaConf;

        if ($iaConf < 90) {
            $result['reason'] = 'ia_confidence_low (' . $iaConf . '% < 90%)';
            return $result;
        }

        // 3. Type doc doit être spécifique (pas 'diagnostic'/'document'/'autre')
        $generic = ['', 'diagnostic', 'document', 'autre', 'divers', 'generic'];
        if (in_array($v3['type'], $generic, true)) {
            $result['reason'] = 'type_doc_too_generic (' . $v3['type'] . ')';
            return $result;
        }

        // 4. Validation entité existe en BDD
        $entityValid = false;
        switch ($v3['entity_type']) {
            case 'BIEN':
                $st = $pdo->prepare("SELECT 1 FROM biens WHERE id = ? LIMIT 1");
                $st->execute([$v3['entity_id']]);
                $entityValid = (bool)$st->fetchColumn();
                break;
            case 'IMB':
            case 'IMMEUBLE':
                $st = $pdo->prepare("SELECT 1 FROM immeubles WHERE id = ? LIMIT 1");
                $st->execute([$v3['entity_id']]);
                $entityValid = (bool)$st->fetchColumn();
                break;
            case 'TIERS':
                $st = $pdo->prepare("SELECT 1 FROM tiers WHERE id = ? LIMIT 1");
                $st->execute([$v3['entity_id']]);
                $entityValid = (bool)$st->fetchColumn();
                break;
            case 'CREANCIER_DOSSIER':
                $st = $pdo->prepare("SELECT 1 FROM creancier_dossier WHERE id = ? LIMIT 1");
                $st->execute([$v3['entity_id']]);
                $entityValid = (bool)$st->fetchColumn();
                break;
            default:
                $result['reason'] = 'entity_type_unsupported (' . $v3['entity_type'] . ')';
                return $result;
        }
        if (!$entityValid) {
            $result['reason'] = 'entity_not_in_db (' . $v3['entity_type'] . '#' . $v3['entity_id'] . ')';
            return $result;
        }

        // 5. Résolution du folder_id GED
        $ctx = $v3['ctx'];
        $folderId = 0;
        $st = $pdo->prepare("SELECT id FROM ged_folders WHERE slug = ? AND (parent_id IS NULL OR parent_id = 0) AND is_archived = 0 LIMIT 1");
        $st->execute([$ctx['n1_slug']]);
        $n1Id = (int)$st->fetchColumn();
        if ($n1Id && !empty($ctx['n2_slug'])) {
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE slug = ? AND parent_id = ? AND is_archived = 0 LIMIT 1");
            $st->execute([$ctx['n2_slug'], $n1Id]);
            $n2Id = (int)$st->fetchColumn();
            if ($n2Id && !empty($ctx['n3_slug'])) {
                $st = $pdo->prepare("SELECT id FROM ged_folders WHERE slug = ? AND parent_id = ? AND is_archived = 0 LIMIT 1");
                $st->execute([$ctx['n3_slug'], $n2Id]);
                $n3Id = (int)$st->fetchColumn();
                $folderId = $n3Id ?: $n2Id;
            } else {
                $folderId = $n2Id ?: $n1Id;
            }
        } else {
            $folderId = $n1Id;
        }
        if ($folderId === 0) {
            $result['reason'] = 'ged_folder_not_resolved';
            return $result;
        }
        $result['ged_folder_id'] = $folderId;

        // ✅ TOUS CRITÈRES OK
        $result['eligible'] = true;
        $result['reason'] = 'ok (ia ' . $iaConf . '%, ' . $v3['entity_type'] . '#' . $v3['entity_id'] . ', ' . $v3['type'] . ')';
        return $result;
    }
}

if (!function_exists('fluxbox_auto_commit_promote')) {
    /**
     * Exécute l'auto-commit : INSERT ged_documents + ged_document_links + UPDATE carte.
     * Atomique (rollback total en cas d'erreur).
     *
     * @return array {
     *   ok:bool, ged_doc_id:?int, links_created:int, audit:string[], erreur:?string,
     * }
     */
    function fluxbox_auto_commit_promote(int $carteId, array $eligibility, PDO $pdo): array {
        $audit = [];
        $newGedDocId = null;
        $linksCreated = 0;

        if (!$eligibility['eligible']) {
            return ['ok' => false, 'ged_doc_id' => null, 'links_created' => 0, 'audit' => [], 'erreur' => 'not_eligible: ' . $eligibility['reason']];
        }

        // Lit la carte + doc + entité tenant
        $st = $pdo->prepare("SELECT c.*, d.id AS doc_id, d.fichier_nom, d.fichier_chemin, d.mime_type,
                                    d.taille_octets, d.hash_sha256, d.fluxbox_source_id
                             FROM fluxbox_cartes c JOIN fluxbox_documents d ON d.id = c.document_id
                             WHERE c.id = ?");
        $st->execute([$carteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'ged_doc_id' => null, 'links_created' => 0, 'audit' => [], 'erreur' => 'carte_not_found'];

        // Tenant_id : récup depuis bien/immeuble/tiers selon entité
        $tenantId = null;
        switch ($eligibility['entity_type']) {
            case 'BIEN':
                $st = $pdo->prepare("SELECT b.id_societe, i.id_societe AS imm_soc FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ?");
                $st->execute([$eligibility['entity_id']]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $tenantId = (int)($r['id_societe'] ?? 0) ?: (int)($r['imm_soc'] ?? 0);
                break;
            case 'IMB':
            case 'IMMEUBLE':
                $st = $pdo->prepare("SELECT id_societe FROM immeubles WHERE id = ?");
                $st->execute([$eligibility['entity_id']]);
                $tenantId = (int)$st->fetchColumn();
                break;
            case 'TIERS':
                $st = $pdo->prepare("SELECT id_societe FROM tiers WHERE id = ?");
                $st->execute([$eligibility['entity_id']]);
                $tenantId = (int)$st->fetchColumn();
                break;
            case 'CREANCIER_DOSSIER':
                $st = $pdo->prepare("SELECT id_societe FROM creancier_dossier WHERE id = ?");
                $st->execute([$eligibility['entity_id']]);
                $tenantId = (int)$st->fetchColumn();
                break;
        }
        if (!$tenantId) $tenantId = 1; // Régie EMERY par défaut

        try {
            $pdo->beginTransaction();
            $audit[] = "🔓 BEGIN TRANSACTION";

            // 1. INSERT ged_documents (ou SKIP si hash déjà promu)
            $hash = (string)$row['hash_sha256'];
            $existing = null;
            if ($hash !== '') {
                $st = $pdo->prepare("SELECT id FROM ged_documents WHERE hash_sha256 = ? LIMIT 1");
                $st->execute([$hash]);
                $existing = $st->fetchColumn();
            }

            $nameV3 = $eligibility['v3_1_name'];

            if ($existing) {
                $newGedDocId = (int)$existing;
                $audit[] = "⏭️ SKIP INSERT ged_documents (hash déjà promu, id=$newGedDocId)";
                // UPDATE name_file si différent
                $st = $pdo->prepare("SELECT name_file FROM ged_documents WHERE id = ?");
                $st->execute([$newGedDocId]);
                $curName = (string)$st->fetchColumn();
                if ($curName !== $nameV3 && $nameV3 !== '') {
                    $pdo->prepare("UPDATE ged_documents SET name_file = ?, document_type = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$nameV3, $eligibility['type_doc'] ?: null, $newGedDocId]);
                    $audit[] = "🔁 UPDATE name_file → $nameV3";
                }
            } else {
                // Générer UUID
                $b = random_bytes(16);
                $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
                $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
                $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
                $nameCanon = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', pathinfo($nameV3, PATHINFO_FILENAME)) ?: 'DOC');

                $sourceModule = match ($eligibility['entity_type']) {
                    'BIEN', 'IMB', 'IMMEUBLE' => 'GESTION',
                    'TIERS' => 'REFERENTIEL',
                    'CREANCIER_DOSSIER' => 'CONTENTIEUX',
                    default => 'AUTRE',
                };
                $stIns = $pdo->prepare("
                    INSERT INTO ged_documents
                        (uuid, tenant_id, fluxbox_source_id, name_file, name_display, name_canonical,
                         document_type, source_module, mime_type, size_bytes, hash_sha256,
                         folder_id, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                ");
                $stIns->execute([
                    $uuid, $tenantId, (int)$row['doc_id'],
                    $nameV3, (string)$row['fichier_nom'], $nameCanon,
                    $eligibility['type_doc'] ?: null, $sourceModule,
                    (string)$row['mime_type'], (int)$row['taille_octets'], $hash,
                    $eligibility['ged_folder_id'],
                ]);
                $newGedDocId = (int)$pdo->lastInsertId();
                $audit[] = "✅ INSERT ged_documents #$newGedDocId · $nameV3 · folder #" . $eligibility['ged_folder_id'];
            }

            // 2. INSERT ged_document_links (entité concernée)
            // Map BIEN → main, IMB/IMMEUBLE → reference, TIERS → reference
            $entityType = $eligibility['entity_type'] === 'IMMEUBLE' ? 'IMB' : $eligibility['entity_type'];
            $relationType = ($entityType === 'BIEN' || $entityType === 'CREANCIER_DOSSIER') ? 'main' : 'reference';

            $stLink = $pdo->prepare("
                INSERT INTO ged_document_links
                    (tenant_id, document_id, entity_type, entity_id, relation_type, is_validated, validated_at, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE is_validated = 1, validated_at = NOW()
            ");
            $stLink->execute([$tenantId, $newGedDocId, $entityType, (int)$eligibility['entity_id'], $relationType]);
            $linksCreated++;
            $audit[] = "✅ INSERT ged_document_links ($entityType#" . $eligibility['entity_id'] . " / $relationType)";

            // 3. Lien immeuble auto si BIEN avec id_immeuble (annexe)
            if ($entityType === 'BIEN') {
                $st = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ?");
                $st->execute([$eligibility['entity_id']]);
                $immId = (int)$st->fetchColumn();
                if ($immId > 0) {
                    $stLink->execute([$tenantId, $newGedDocId, 'IMB', $immId, 'reference']);
                    $linksCreated++;
                    $audit[] = "✅ INSERT ged_document_links auto IMB#$immId (reference)";
                }
            }

            // 4. UPDATE fluxbox_cartes : status validé + naming applied
            $pdo->prepare("
                UPDATE fluxbox_cartes
                SET statut = 'validated', naming_status = 'applied', naming_applied_at = NOW(),
                    naming_proposed = ?
                WHERE id = ?
            ")->execute([$nameV3, $carteId]);
            $audit[] = "✅ UPDATE fluxbox_cartes #$carteId statut=validated naming=applied";

            // 5. Audit trail
            try {
                $pdo->prepare("
                    INSERT INTO fluxbox_actions_ia
                        (tenant_id, carte_id, action_type, action_label, payload_json, confiance, created_at)
                    VALUES (?, ?, 'auto_commit_ged', ?, ?, ?, NOW())
                ")->execute([
                    $tenantId, $carteId,
                    'Auto-commit GED · ' . $eligibility['type_doc'] . ' · ' . $entityType . '#' . $eligibility['entity_id'],
                    json_encode([
                        'ged_doc_id'    => $newGedDocId,
                        'name_v3_1'     => $nameV3,
                        'entity_type'   => $entityType,
                        'entity_id'     => $eligibility['entity_id'],
                        'ia_confidence' => $eligibility['ia_confidence'],
                        'reason'        => $eligibility['reason'],
                    ], JSON_UNESCAPED_UNICODE),
                    $eligibility['ia_confidence'] / 100,
                ]);
            } catch (Throwable) {}

            $pdo->commit();
            $audit[] = "✅ COMMIT";

            // [Étape 3 — 2026-05-25] Enrichissement BDD post-commit (anti-doublon + UPDATE)
            // Hors transaction principale pour ne pas rollback le commit GED en cas d'erreur enrichissement.
            try {
                require_once __DIR__ . '/bien_enrich_from_doc.php';
                $enrich = bef_enrich_from_carte($carteId, $pdo);
                if ($enrich['bien'] && !empty($enrich['bien']['updated'])) {
                    $audit[] = "✨ Enrichissement bien : " . implode(',', $enrich['bien']['updated']);
                }
                if ($enrich['bien'] && !empty($enrich['bien']['conflicts'])) {
                    $audit[] = "⚠️ Conflit bien : " . implode('; ', $enrich['bien']['conflicts']);
                }
                if ($enrich['mandat'] && !empty($enrich['mandat']['updated'])) {
                    $audit[] = "✨ Enrichissement mandat : " . implode(',', $enrich['mandat']['updated']);
                }
                if ($enrich['tiers_resolution']) {
                    $audit[] = "🔍 Tiers résolution : " . $enrich['tiers_resolution']['action'] . " (score " . $enrich['tiers_resolution']['score'] . ")";
                }
            } catch (Throwable $e) {
                $audit[] = "⚠️ Enrichissement post-commit échec : " . $e->getMessage();
            }

            // [CRÉANCIERS — 2026-06-22] Hook métier : enrichit le dossier créancier depuis l'extraction
            // IA (créancier→tiers, dette, commentaire avocat). Hors transaction GED (non bloquant).
            if ($eligibility['entity_type'] === 'CREANCIER_DOSSIER' && $eligibility['entity_id']) {
                try {
                    require_once __DIR__ . '/creancier_enrich_from_doc.php';
                    $iaExt = [];
                    if (!empty($row['hash_sha256'])) {
                        $stCache = $pdo->prepare("SELECT response_json FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
                        $stCache->execute([(string)$row['hash_sha256']]);
                        $iaExt = json_decode((string)$stCache->fetchColumn(), true) ?: [];
                    }
                    $cenr = cef_enrich_dossier($pdo, (int)$eligibility['entity_id'], $iaExt, [
                        'ged_document_id' => $newGedDocId,
                        'created_by'      => (int)($row['created_by'] ?? 0),
                        'type_doc'        => $eligibility['type_doc'],
                    ]);
                    if (!empty($cenr['actions'])) $audit[] = "⚖️ Enrichissement créancier : " . implode(', ', $cenr['actions']);
                } catch (Throwable $e) {
                    $audit[] = "⚠️ Enrichissement créancier échec : " . $e->getMessage();
                }
            }

            // [Sprint I — 2026-05-25] Hooks métier smart-opt-in : si conf IA ≥ 95% → déclenche aussi
            // les hooks Transaction (MAJ mandats.date_signature, biens.date_retrait_commercialisation, etc.)
            if ($eligibility['ia_confidence'] >= 95 && $eligibility['entity_type'] === 'BIEN' && $eligibility['entity_id']) {
                try {
                    require_once __DIR__ . '/transaction_metier_hooks.php';
                    // Récup extraction IA
                    $iaExt = [];
                    if (!empty($row['hash_sha256'])) {
                        $stCache = $pdo->prepare("SELECT response_json FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
                        $stCache->execute([(string)$row['hash_sha256']]);
                        $iaExt = json_decode((string)$stCache->fetchColumn(), true) ?: [];
                    }
                    $hookReport = tmh_run_hooks($pdo, [
                        'type_doc'       => $eligibility['type_doc'],
                        'confidence_ia'  => $eligibility['ia_confidence'],
                        'date_doc'       => (string)($iaExt['date_signature'] ?? $iaExt['date_doc'] ?? ''),
                        'bien_id'        => (int)$eligibility['entity_id'],
                        'extracted_data' => $iaExt,
                    ], false, 95); // dryRun=false, seuil 95
                    $audit[] = "🔧 Hooks métier smart (conf≥95%) : " . (int)$hookReport['totals']['actions'] . " action(s), "
                             . (int)$hookReport['totals']['skipped'] . " skip, " . (int)$hookReport['totals']['errors'] . " err";
                } catch (Throwable $e) {
                    $audit[] = "⚠️ Hooks métier échec : " . $e->getMessage();
                }
            }

            return ['ok' => true, 'ged_doc_id' => $newGedDocId, 'links_created' => $linksCreated, 'audit' => $audit, 'erreur' => null];

        } catch (Throwable $e) {
            $pdo->rollBack();
            $audit[] = "🔴 ROLLBACK : " . $e->getMessage();
            return ['ok' => false, 'ged_doc_id' => null, 'links_created' => 0, 'audit' => $audit, 'erreur' => $e->getMessage()];
        }
    }
}

if (!function_exists('fluxbox_auto_commit_try')) {
    /**
     * Try auto-commit : évalue éligibilité puis exécute si éligible.
     * Méthode haut niveau à appeler après fluxbox_va_analyze_carte().
     *
     * @return array {
     *   attempted:bool, committed:bool, ged_doc_id:?int, eligibility:array, promote:?array,
     * }
     */
    function fluxbox_auto_commit_try(int $carteId, PDO $pdo): array {
        $elig = fluxbox_auto_commit_eligible($carteId, $pdo);
        if (!$elig['eligible']) {
            return ['attempted' => false, 'committed' => false, 'ged_doc_id' => null, 'eligibility' => $elig, 'promote' => null];
        }
        $prom = fluxbox_auto_commit_promote($carteId, $elig, $pdo);
        return ['attempted' => true, 'committed' => $prom['ok'], 'ged_doc_id' => $prom['ged_doc_id'], 'eligibility' => $elig, 'promote' => $prom];
    }
}
