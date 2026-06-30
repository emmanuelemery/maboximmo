<?php
/**
 * admin/admin_doc_persistance_v1.php
 *
 * Sprint 3D V0 — Persistance relationnelle finale (DRY-RUN par défaut).
 *
 * Quand un document a parcouru 3A (validation) + 3B (matching) + 3C (classement),
 * cette page assemble l'opération finale qui le rend "officiellement archivé" :
 *
 *   1. Promotion fluxbox_documents → ged_documents (idempotent par hash_sha256)
 *   2. Création N lignes ged_document_links (1 par entité métier liée)
 *   3. Mise à jour fluxbox_cartes (statut=validated + snapshot persistance_v1)
 *   4. Audit trail dans fluxbox_actions_ia (action_type=workflow)
 *
 * RÈGLES OBLIGATOIRES (validées user 2026-05-23) :
 *   - DRY-RUN par défaut, aucune écriture sans clic explicite "Appliquer"
 *   - Transaction PDO (BEGIN/COMMIT/ROLLBACK) — atomicité totale
 *   - Idempotence : re-exécution = no-op (skip si déjà fait)
 *   - UK anti-doublon respectée (SELECT préalable + skip)
 *   - Rollback automatique sur la moindre exception
 *   - Log clair par opération (avant / action / après)
 *   - Zéro DDL, zéro DELETE
 *
 * Accès super admin uniquement (role=1).
 *
 * Cohérent avec [[project_sprint3_persistance_relationnelle]].
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

// ════════════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════════════
function dp_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function dp_table_exists(PDO $pdo, string $name): bool {
    // MariaDB 11+ refuse `SHOW TABLES LIKE ?` en prepared statement quand
    // EMULATE_PREPARES=false. Passer par information_schema qui supporte les params.
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) { return false; }
}
function dp_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function dp_canonical(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?: '';
    return trim($s, '_');
}
function dp_entity_exists(PDO $pdo, string $table, int $id): bool {
    if ($id <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) { return false; }
}

// ════════════════════════════════════════════════════════════════════
// Lecture des paramètres
// ════════════════════════════════════════════════════════════════════
$cardId = (int)($_GET['card_id'] ?? $_POST['card_id'] ?? 0);
$mode   = (string)($_POST['mode'] ?? 'dry_run'); // 'dry_run' ou 'apply'
$isApply = ($mode === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST');

// Saisie utilisateur : entités à lier (depuis le formulaire)
$inputLinks = [];
$linkSpecs = [
    'bien'     => ['table' => 'biens',         'entity_type' => 'BIEN',  'relation_default' => 'main'],
    'immeuble' => ['table' => 'immeubles',     'entity_type' => 'IMB',   'relation_default' => 'reference'],
    'tiers'    => ['table' => 'tiers',         'entity_type' => 'TIERS', 'relation_default' => 'reference'],
    'mandat'   => ['table' => 'mandats',       'entity_type' => 'MDT',   'relation_default' => 'annexe'],
];
foreach ($linkSpecs as $k => $spec) {
    $id = (int)($_POST["link_{$k}_id"] ?? $_GET["link_{$k}_id"] ?? 0);
    $relation = trim((string)($_POST["link_{$k}_rel"] ?? $_GET["link_{$k}_rel"] ?? $spec['relation_default']));
    if ($id > 0) {
        $inputLinks[$k] = [
            'entity_type'   => $spec['entity_type'],
            'entity_id'     => $id,
            'relation_type' => $relation,
            'table'         => $spec['table'],
        ];
    }
}

// ════════════════════════════════════════════════════════════════════
// Lecture de la carte + document source
// ════════════════════════════════════════════════════════════════════
$card = null;
$doc  = null;
$prop = [];
$errors = [];

if ($cardId > 0) {
    if (!dp_table_exists($pdo, 'fluxbox_cartes')) {
        $errors[] = 'Table fluxbox_cartes absente';
    } else {
        $st = $pdo->prepare("SELECT * FROM fluxbox_cartes WHERE id = ?");
        $st->execute([$cardId]);
        $card = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$card) {
            $errors[] = "Carte #$cardId introuvable.";
        } else {
            $prop = json_decode((string)($card['proposition_json'] ?? '{}'), true) ?: [];
            if (!empty($card['document_id'])) {
                $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
                $st->execute([(int)$card['document_id']]);
                $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            // Préfill auto depuis proposition_json si l'user n'a rien saisi
            if (empty($inputLinks) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $auto = $prop['matching_v0'] ?? $prop['validation_v0'] ?? null;
                if (is_array($auto)) {
                    foreach (['bien_id','immeuble_id','tiers_id','mandat_id'] as $k) {
                        if (!empty($auto[$k])) {
                            $key = str_replace('_id', '', $k);
                            if (isset($linkSpecs[$key])) {
                                $inputLinks[$key] = [
                                    'entity_type'   => $linkSpecs[$key]['entity_type'],
                                    'entity_id'     => (int)$auto[$k],
                                    'relation_type' => $linkSpecs[$key]['relation_default'],
                                    'table'         => $linkSpecs[$key]['table'],
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
}

// ════════════════════════════════════════════════════════════════════
// Calcul des opérations prévues (TOUJOURS, dry-run ou apply)
// ════════════════════════════════════════════════════════════════════
$ops = [
    'ged_documents'      => null,
    'ged_document_links' => [],
    'fluxbox_cartes'     => null,
    'fluxbox_actions_ia' => null,
];

$beforeSnapshot = [];
$existingDocId  = null;
$folderTarget   = null;

if ($card && $doc) {
    // ── 1. ged_documents : insert ou skip ?
    $hash = (string)($doc['hash_sha256'] ?? '');
    if ($hash !== '' && dp_table_exists($pdo, 'ged_documents')) {
        $st = $pdo->prepare("SELECT id, name_display, folder_id, status FROM ged_documents WHERE hash_sha256 = ? LIMIT 1");
        $st->execute([$hash]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        $beforeSnapshot['ged_documents'] = $existing ?: '(aucun)';
        if ($existing) {
            $existingDocId = (int)$existing['id'];
            $ops['ged_documents'] = [
                'mode'   => 'skip',
                'reason' => "hash_sha256 déjà présent dans ged_documents (id={$existingDocId}).",
                'data'   => $existing,
            ];
        } else {
            // Calcul folder_id cible depuis classification_v0
            $classification = $prop['classification_v0'] ?? [];
            $folderIds = $classification['folder_ids'] ?? [];
            $folderTarget = !empty($folderIds) ? (int)end($folderIds) : null;

            $validation = $prop['validation_v0'] ?? [];
            $nameDisplay = (string)($doc['fichier_nom'] ?? 'document.pdf');
            $nameFile    = $nameDisplay;
            $nameCanon   = dp_canonical(pathinfo($nameDisplay, PATHINFO_FILENAME))
                         ?: 'doc_' . substr($hash, 0, 8);

            $sourceModule = $classification['path_canonical'][0] ?? null;
            $docType      = (string)($validation['type_doc'] ?? '');

            $ops['ged_documents'] = [
                'mode' => 'insert',
                'sql'  => "INSERT INTO ged_documents (uuid, tenant_id, folder_id, name_display, name_canonical, "
                        . "name_file, document_type, source_module, storage_provider, mime_type, size_bytes, "
                        . "hash_sha256, status, created_by, created_at) VALUES (...)",
                'data' => [
                    'uuid'             => '(généré à l\'insert)',
                    'folder_id'        => $folderTarget,
                    'name_display'     => $nameDisplay,
                    'name_canonical'   => $nameCanon,
                    'name_file'        => $nameFile,
                    'document_type'    => $docType !== '' ? $docType : null,
                    'source_module'    => $sourceModule,
                    'storage_provider' => 'local',
                    'mime_type'        => $doc['mime_type'] ?? null,
                    'size_bytes'       => $doc['taille_octets'] ?? null,
                    'hash_sha256'      => $hash,
                    'status'           => 'active',
                    'created_by'       => (int)($_SESSION['id_user'] ?? 0) ?: null,
                ],
                'reason' => 'Promotion fluxbox_documents → ged_documents.',
            ];
        }
    } else {
        $ops['ged_documents'] = ['mode' => 'error', 'reason' => 'hash_sha256 vide ou table ged_documents absente.'];
    }

    // ── 2. ged_document_links : 1 par entité saisie
    if (dp_table_exists($pdo, 'ged_document_links')) {
        $beforeSnapshot['ged_document_links'] = [];
        // Snapshot des liens existants pour ce doc (si déjà existant)
        if ($existingDocId) {
            $st = $pdo->prepare("SELECT entity_type, entity_id, relation_type, is_validated, confidence
                                 FROM ged_document_links WHERE document_id = ?");
            $st->execute([$existingDocId]);
            $beforeSnapshot['ged_document_links'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        foreach ($inputLinks as $key => $link) {
            // Vérification d'existence de l'entité
            $entityExists = dp_entity_exists($pdo, $link['table'], (int)$link['entity_id']);
            if (!$entityExists) {
                $ops['ged_document_links'][] = [
                    'mode'   => 'error',
                    'spec'   => $link,
                    'reason' => "Entité #{$link['entity_id']} introuvable dans {$link['table']}.",
                ];
                continue;
            }

            // Si doc déjà existant → vérifier UK
            $linkExists = false;
            if ($existingDocId) {
                $st = $pdo->prepare("SELECT id FROM ged_document_links
                                      WHERE document_id = ? AND entity_type = ? AND entity_id = ? AND relation_type = ?
                                      LIMIT 1");
                $st->execute([$existingDocId, $link['entity_type'], (int)$link['entity_id'], $link['relation_type']]);
                $linkExists = (bool)$st->fetchColumn();
            }

            if ($linkExists) {
                $ops['ged_document_links'][] = [
                    'mode'   => 'skip',
                    'spec'   => $link,
                    'reason' => 'Lien déjà présent (UK respecté).',
                ];
            } else {
                $ops['ged_document_links'][] = [
                    'mode'   => 'insert',
                    'spec'   => $link,
                    'data'   => [
                        'document_id'   => $existingDocId ?: '(id du nouveau ged_documents)',
                        'entity_type'   => $link['entity_type'],
                        'entity_id'     => (int)$link['entity_id'],
                        'relation_type' => $link['relation_type'],
                        'confidence'    => null,
                        'is_validated'  => 1,
                        'validated_by'  => (int)($_SESSION['id_user'] ?? 0) ?: null,
                    ],
                ];
            }
        }
    } else {
        $ops['ged_document_links'][] = ['mode' => 'error', 'reason' => 'Table ged_document_links absente.'];
    }

    // ── 3. fluxbox_cartes : update si pas déjà validated
    $beforeSnapshot['fluxbox_cartes'] = [
        'statut'         => $card['statut'],
        'validated_at'   => $card['validated_at'],
        'has_persistance'=> isset($prop['persistance_v1']),
    ];
    if ($card['statut'] !== 'validated' || !isset($prop['persistance_v1'])) {
        $ops['fluxbox_cartes'] = [
            'mode' => 'update',
            'data' => [
                'statut'             => 'validated',
                'validated_by'       => (int)($_SESSION['id_user'] ?? 0) ?: null,
                'validated_at'       => '(NOW)',
                'proposition_json+'  => 'persistance_v1 = {ged_document_id, ged_links_ids[]}',
            ],
        ];
    } else {
        $ops['fluxbox_cartes'] = [
            'mode'   => 'skip',
            'reason' => 'Carte déjà validated avec snapshot persistance_v1.',
        ];
    }

    // ── 4. fluxbox_actions_ia : audit trail
    if (dp_table_exists($pdo, 'fluxbox_actions_ia')) {
        $st = $pdo->prepare("SELECT id FROM fluxbox_actions_ia
                              WHERE carte_id = ? AND action_label LIKE 'Sprint 3D%' LIMIT 1");
        $st->execute([$cardId]);
        $existingAction = $st->fetchColumn();
        $beforeSnapshot['fluxbox_actions_ia_existing'] = $existingAction ?: '(aucune)';
        if ($existingAction) {
            $ops['fluxbox_actions_ia'] = [
                'mode'   => 'skip',
                'reason' => "Action Sprint 3D déjà loggée (id=$existingAction).",
            ];
        } else {
            $ops['fluxbox_actions_ia'] = [
                'mode'   => 'insert',
                'data'   => [
                    'carte_id'     => $cardId,
                    'action_type'  => 'workflow',
                    'action_label' => 'Sprint 3D — persistance ged_documents + ged_document_links',
                    'statut'       => 'executed',
                ],
            ];
        }
    }
}

// ════════════════════════════════════════════════════════════════════
// EXÉCUTION (uniquement si mode=apply ET POST)
// ════════════════════════════════════════════════════════════════════
$execLog = [];
$execStatus = null; // 'ok' | 'rollback' | null

if ($isApply && $card && $doc && empty($errors)) {
    try {
        $pdo->beginTransaction();
        $execLog[] = '🔓 BEGIN TRANSACTION';

        $newDocId = $existingDocId;
        $tenantId = (int)($card['tenant_id'] ?? 0) ?: null;

        // 1. ged_documents
        if ($ops['ged_documents']['mode'] === 'insert') {
            $d = $ops['ged_documents']['data'];
            $uuid = dp_uuid();
            $st = $pdo->prepare("
                INSERT INTO ged_documents
                    (uuid, tenant_id, folder_id, name_display, name_canonical, name_file,
                     document_type, source_module, storage_provider, mime_type, size_bytes,
                     hash_sha256, status, created_by, created_at)
                VALUES
                    (:uuid, :tid, :fid, :nd, :nc, :nf,
                     :dt, :sm, 'local', :mt, :sz,
                     :h, 'active', :cb, NOW())
            ");
            $st->execute([
                ':uuid' => $uuid,
                ':tid'  => $tenantId,
                ':fid'  => $d['folder_id'],
                ':nd'   => $d['name_display'],
                ':nc'   => $d['name_canonical'],
                ':nf'   => $d['name_file'],
                ':dt'   => $d['document_type'],
                ':sm'   => $d['source_module'],
                ':mt'   => $d['mime_type'],
                ':sz'   => $d['size_bytes'],
                ':h'    => $d['hash_sha256'],
                ':cb'   => $d['created_by'],
            ]);
            $newDocId = (int)$pdo->lastInsertId();
            $execLog[] = "✅ INSERT ged_documents → id=$newDocId (uuid=$uuid)";

            // Lien GED ← FluxBox (colonne ajoutée par migration v3.01)
            try {
                $st = $pdo->prepare("UPDATE ged_documents SET fluxbox_source_id = ? WHERE id = ?");
                $st->execute([(int)$doc['id'], $newDocId]);
                $execLog[] = "  ↳ ged_documents.fluxbox_source_id = {$doc['id']}";
            } catch (Throwable) {
                $execLog[] = "  ↳ (colonne fluxbox_source_id absente — skip)";
            }
        } else {
            $execLog[] = "⏭️  SKIP ged_documents : {$ops['ged_documents']['reason']}";
        }

        // 2. ged_document_links
        $insertedLinkIds = [];
        if ($newDocId) {
            $stLink = $pdo->prepare("
                INSERT INTO ged_document_links
                    (tenant_id, document_id, entity_type, entity_id, relation_type,
                     confidence, is_validated, validated_by, validated_at, created_at)
                VALUES
                    (:tid, :did, :et, :eid, :rt,
                     NULL, 1, :vb, NOW(), NOW())
            ");
            foreach ($ops['ged_document_links'] as $linkOp) {
                if ($linkOp['mode'] === 'insert') {
                    try {
                        $stLink->execute([
                            ':tid' => $tenantId,
                            ':did' => $newDocId,
                            ':et'  => $linkOp['spec']['entity_type'],
                            ':eid' => (int)$linkOp['spec']['entity_id'],
                            ':rt'  => $linkOp['spec']['relation_type'],
                            ':vb'  => (int)($_SESSION['id_user'] ?? 0) ?: null,
                        ]);
                        $linkId = (int)$pdo->lastInsertId();
                        $insertedLinkIds[] = $linkId;
                        $execLog[] = "✅ INSERT ged_document_links → id=$linkId "
                                   . "({$linkOp['spec']['entity_type']}#{$linkOp['spec']['entity_id']} "
                                   . "/ {$linkOp['spec']['relation_type']})";
                    } catch (PDOException $pe) {
                        if ($pe->getCode() === '23000') {
                            // Doublon UK : on log et continue (idempotent)
                            $execLog[] = "⏭️  SKIP ged_document_links (UK conflict) : "
                                       . "{$linkOp['spec']['entity_type']}#{$linkOp['spec']['entity_id']}";
                        } else {
                            throw $pe;
                        }
                    }
                } else {
                    $execLog[] = "⏭️  SKIP ged_document_links : {$linkOp['reason']}";
                }
            }
        }

        // 3. fluxbox_cartes
        if ($ops['fluxbox_cartes']['mode'] === 'update') {
            $patchPersistance = [
                'persistance_v1' => [
                    'ged_document_id'   => $newDocId,
                    'ged_links_ids'     => $insertedLinkIds,
                    'persisted_by'      => (int)($_SESSION['id_user'] ?? 0),
                    'persisted_at'      => date('Y-m-d H:i:s'),
                    'dry_run_done_at'   => null,
                ],
            ];
            $st = $pdo->prepare("
                UPDATE fluxbox_cartes
                SET statut = 'validated',
                    validated_by = COALESCE(validated_by, :vb),
                    validated_at = COALESCE(validated_at, NOW()),
                    proposition_json = COALESCE(proposition_json, JSON_OBJECT()),
                    proposition_json = JSON_MERGE_PATCH(proposition_json, :patch),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $st->execute([
                ':vb'    => (int)($_SESSION['id_user'] ?? 0) ?: null,
                ':patch' => json_encode($patchPersistance, JSON_UNESCAPED_UNICODE),
                ':id'    => $cardId,
            ]);
            $execLog[] = "✅ UPDATE fluxbox_cartes #$cardId → statut=validated + persistance_v1";
        } else {
            $execLog[] = "⏭️  SKIP fluxbox_cartes : {$ops['fluxbox_cartes']['reason']}";
        }

        // 4. fluxbox_actions_ia
        if (($ops['fluxbox_actions_ia']['mode'] ?? null) === 'insert') {
            $st = $pdo->prepare("
                INSERT INTO fluxbox_actions_ia
                    (tenant_id, carte_id, action_type, action_label, payload_json, statut, executed_at, result_json, created_at)
                VALUES
                    (:tid, :cid, 'workflow', :lbl, :payload, 'executed', NOW(), :result, NOW())
            ");
            $st->execute([
                ':tid'     => $tenantId,
                ':cid'     => $cardId,
                ':lbl'     => 'Sprint 3D — persistance ged_documents + ged_document_links',
                ':payload' => json_encode([
                    'source' => 'admin_doc_persistance_v1.php',
                    'input'  => $inputLinks,
                ], JSON_UNESCAPED_UNICODE),
                ':result'  => json_encode([
                    'ged_document_id' => $newDocId,
                    'ged_links_ids'   => $insertedLinkIds,
                    'log'             => $execLog,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $auditId = (int)$pdo->lastInsertId();
            $execLog[] = "✅ INSERT fluxbox_actions_ia → id=$auditId (audit trail)";
        } else {
            $execLog[] = "⏭️  SKIP fluxbox_actions_ia : "
                       . ($ops['fluxbox_actions_ia']['reason'] ?? 'aucune action prévue');
        }

        $pdo->commit();
        $execLog[] = '🔒 COMMIT';
        $execStatus = 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            $execLog[] = '🔴 ROLLBACK : ' . $e->getMessage();
        } else {
            $execLog[] = '❌ Exception hors transaction : ' . $e->getMessage();
        }
        $execStatus = 'rollback';
    }
}

// ════════════════════════════════════════════════════════════════════
// Liste des cartes candidates (sidebar)
// ════════════════════════════════════════════════════════════════════
$candidates = [];
if (dp_table_exists($pdo, 'fluxbox_cartes')) {
    try {
        $candidates = $pdo->query("
            SELECT c.id, c.titre, c.statut, c.priorite, c.confiance_ia,
                   c.document_id,
                   JSON_EXTRACT(c.proposition_json, '$.persistance_v1.ged_document_id') AS already_persisted,
                   d.fichier_nom
            FROM fluxbox_cartes c
            LEFT JOIN fluxbox_documents d ON d.id = c.document_id
            WHERE c.document_id IS NOT NULL
            ORDER BY (c.statut = 'validated') DESC, c.id DESC
            LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {}
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Persistance documentaire Sprint 3D V0</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171;
            --insert: #84a98c; --skip: #94a3b8; --update: #fde68a; --error: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DM Mono", "JetBrains Mono", monospace;
            background: var(--bg); color: var(--text); font-size: 12.5px;
        }
        header {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 18px; background: #11203b;
            border-bottom: 2px solid var(--mbi-or);
        }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .badge {
            background: #7c3aed; color: #fff; padding: 2px 8px;
            border-radius: 4px; font-size: 10px; font-weight: 700;
        }
        header .mode-badge {
            font-size: 11px; padding: 3px 10px; border-radius: 4px; font-weight: 700;
        }
        header .mode-dry { background: var(--warn); color: #1a1a1a; }
        header .mode-applied-ok { background: var(--ok); color: #1a1a1a; }
        header .mode-applied-ko { background: var(--ko); color: #fff; }
        header nav { margin-left: auto; display: flex; gap: 10px; }
        header nav a {
            color: var(--muted); text-decoration: none; font-size: 11px;
            border: 1px solid var(--line); padding: 4px 10px; border-radius: 4px;
        }
        header nav a:hover { color: var(--text); border-color: var(--mbi-or); }

        .layout { display: grid; grid-template-columns: 260px 1fr; min-height: calc(100vh - 47px); }

        .sidebar {
            background: #0a1424; padding: 10px; overflow-y: auto;
            max-height: calc(100vh - 47px); border-right: 1px solid var(--line);
        }
        .sidebar h2 { font-size: 11px; color: var(--mbi-or); margin: 4px 0 8px;
                      text-transform: uppercase; letter-spacing: 0.5px; }
        .card-link {
            display: block; padding: 7px 10px; margin-bottom: 5px;
            background: var(--panel); border-radius: 4px;
            color: var(--text); text-decoration: none; font-size: 11px;
            border-left: 3px solid var(--line);
        }
        .card-link.active { border-left-color: var(--mbi-or); background: #2a3548; }
        .card-link.persisted { border-left-color: var(--ok); }
        .card-link .t { font-weight: 700; }
        .card-link .m { color: var(--muted); font-size: 9.5px; margin-top: 2px; }
        .badge-status {
            display: inline-block; padding: 1px 5px; border-radius: 2px;
            font-size: 9px; margin-right: 3px;
        }
        .bs-validated { background: var(--ok); color: #1a1a1a; }
        .bs-pending { background: #475569; color: #f1f5f9; }
        .bs-persisted { background: var(--mbi-or); color: #1a1a1a; }

        .main { padding: 18px; max-width: 1100px; }

        .panel {
            background: var(--panel); border-radius: 6px; padding: 16px 20px;
            margin-bottom: 14px;
        }
        .panel h2 {
            font-size: 13px; margin: 0 0 12px; color: var(--mbi-or);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--line); padding-bottom: 6px;
        }
        .panel h3 { font-size: 11.5px; margin: 12px 0 6px; color: var(--warn); }

        .meta { font-size: 11px; color: var(--muted); line-height: 1.7; }
        .meta b { color: var(--text); }
        .meta code { background: #0f172a; padding: 1px 5px; border-radius: 2px; color: var(--mbi-or); }

        .flash {
            padding: 10px 14px; border-radius: 4px; margin-bottom: 14px;
            font-size: 11.5px; line-height: 1.6;
        }
        .flash.ok { background: #14532d; color: #d1fae5; border-left: 4px solid var(--ok); }
        .flash.ko { background: #7f1d1d; color: #fee2e2; border-left: 4px solid var(--ko); }
        .flash.warn { background: #422006; color: #fde68a; border-left: 4px solid var(--warn); }

        /* Ops table */
        .op {
            background: #0f172a; border-radius: 4px; padding: 10px 14px;
            margin-bottom: 8px; border-left: 4px solid var(--line);
        }
        .op.insert { border-left-color: var(--insert); }
        .op.update { border-left-color: var(--update); }
        .op.skip   { border-left-color: var(--skip); opacity: 0.7; }
        .op.error  { border-left-color: var(--error); }
        .op .op-head {
            display: flex; align-items: center; gap: 10px; font-size: 11.5px;
            font-weight: 700;
        }
        .op .op-tag {
            font-size: 9px; padding: 1px 7px; border-radius: 3px; font-weight: 700;
        }
        .op.insert .op-tag { background: var(--insert); color: #1a1a1a; }
        .op.update .op-tag { background: var(--update); color: #1a1a1a; }
        .op.skip .op-tag { background: var(--skip); color: #1a1a1a; }
        .op.error .op-tag { background: var(--error); color: #fff; }
        .op .op-reason { color: var(--muted); font-size: 10.5px; margin-top: 4px; }
        .op pre {
            background: #0a1424; padding: 8px 10px; margin: 6px 0 0;
            border-radius: 3px; font-size: 10.5px; line-height: 1.5;
            overflow-x: auto; border: 1px solid var(--line);
        }

        .field { margin-bottom: 10px; }
        .field label {
            display: block; font-size: 10.5px; color: var(--muted);
            margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .field input, .field select {
            width: 100%; padding: 6px 10px;
            background: #0f172a; border: 1px solid var(--line);
            color: var(--text); border-radius: 3px;
            font-family: inherit; font-size: 12px;
        }
        .field input:focus { border-color: var(--mbi-or); outline: none; }

        .link-row {
            display: grid; grid-template-columns: 120px 1fr 140px;
            gap: 10px; margin-bottom: 8px; align-items: end;
        }
        .link-row .lbl { font-size: 10.5px; color: var(--muted); padding-bottom: 8px; }

        .actions {
            display: flex; gap: 10px; justify-content: space-between;
            margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line);
        }
        .btn {
            padding: 9px 16px; border: none; border-radius: 4px;
            font-family: inherit; font-size: 12px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            font-weight: 700;
        }
        .btn-primary { background: var(--mbi-or); color: #1a1a1a; }
        .btn-primary:hover { background: #b8862f; }
        .btn-danger { background: var(--ko); color: #fff; }
        .btn-danger:hover { background: #ef4444; }
        .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .btn-ghost:hover { color: var(--text); border-color: var(--mbi-or); }

        pre.exec-log {
            background: #0a1424; padding: 12px 14px; border-radius: 4px;
            border-left: 4px solid var(--mbi-or);
            font-size: 11px; line-height: 1.7; overflow-x: auto;
            max-height: 350px; overflow-y: auto;
        }
        .v0-warn {
            background: #422006; color: #fde68a; padding: 8px 12px;
            border-radius: 3px; font-size: 10.5px; margin-top: 14px;
            border-left: 3px solid var(--warn);
        }
        details summary { cursor: pointer; color: var(--muted); font-size: 11px; }
        details pre { font-size: 10.5px; }
    </style>
</head>
<body>

<header>
    <h1>💾 Persistance documentaire</h1>
    <span class="badge">SPRINT 3D · V0</span>
    <?php if ($execStatus === 'ok'): ?>
        <span class="mode-badge mode-applied-ok">✅ APPLIQUÉ</span>
    <?php elseif ($execStatus === 'rollback'): ?>
        <span class="mode-badge mode-applied-ko">🔴 ROLLBACK</span>
    <?php else: ?>
        <span class="mode-badge mode-dry">🟡 DRY-RUN</span>
    <?php endif; ?>
    <nav>
        <a href="admin_doc_validation_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">3A</a>
        <a href="admin_doc_match_assistant_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">3B</a>
        <a href="admin_doc_classify_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">3C</a>
    </nav>
</header>

<div class="layout">

    <!-- ─── SIDEBAR : sélection carte ─── -->
    <aside class="sidebar">
        <h2>🎯 Cartes candidates</h2>
        <?php if (empty($candidates)): ?>
            <div style="color: var(--muted); font-size: 11px;">Aucune carte avec document_id.</div>
        <?php else: ?>
            <?php foreach ($candidates as $c):
                $isActive = ((int)$c['id'] === $cardId);
                $isPersisted = !empty($c['already_persisted']) && $c['already_persisted'] !== 'null';
            ?>
                <a href="?card_id=<?= (int)$c['id'] ?>"
                   class="card-link <?= $isActive ? 'active' : '' ?> <?= $isPersisted ? 'persisted' : '' ?>">
                    <div class="t">#<?= (int)$c['id'] ?> · <?= dp_html(mb_substr((string)$c['titre'], 0, 40)) ?></div>
                    <div class="m">
                        <?php if ($c['statut'] === 'validated'): ?>
                            <span class="badge-status bs-validated">validé</span>
                        <?php else: ?>
                            <span class="badge-status bs-pending"><?= dp_html((string)$c['statut']) ?></span>
                        <?php endif; ?>
                        <?php if ($isPersisted): ?>
                            <span class="badge-status bs-persisted">persisté</span>
                        <?php endif; ?>
                        <?php if (!empty($c['fichier_nom'])): ?>
                            · <?= dp_html(mb_substr((string)$c['fichier_nom'], 0, 24)) ?>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>

    <!-- ─── MAIN ─── -->
    <main class="main">

        <?php foreach ($errors as $err): ?>
            <div class="flash ko">❌ <?= dp_html($err) ?></div>
        <?php endforeach; ?>

        <?php if ($execStatus === 'ok'): ?>
            <div class="flash ok">
                ✅ <b>Transaction commit OK.</b> Toutes les opérations ont été appliquées atomiquement.
                <br>Recharge la page (sans <code>mode=apply</code>) pour voir le nouvel état.
            </div>
        <?php elseif ($execStatus === 'rollback'): ?>
            <div class="flash ko">
                🔴 <b>ROLLBACK :</b> exception levée pendant la transaction. Aucun changement persisté.
                Voir le log détaillé ci-dessous.
            </div>
        <?php endif; ?>

        <?php if (!$cardId): ?>
            <div class="panel">
                <h2>👈 Sélectionne une carte</h2>
                <p class="meta">
                    Cette page assemble la persistance finale d'un document validé :<br>
                    ① promotion <code>fluxbox_documents → ged_documents</code><br>
                    ② création des liens <code>ged_document_links</code> (1 par entité métier)<br>
                    ③ stamp <code>fluxbox_cartes.proposition_json.persistance_v1</code><br>
                    ④ audit trail dans <code>fluxbox_actions_ia</code>
                </p>
                <div class="v0-warn">
                    <b>Mode DRY-RUN par défaut</b> — aucune écriture tant que tu ne cliques pas explicitement
                    sur le bouton "Appliquer (COMMIT)" en bas de page.
                </div>
            </div>
        <?php elseif ($card && $doc): ?>

            <!-- État avant -->
            <div class="panel">
                <h2>📸 État avant</h2>
                <div class="meta">
                    <b>Carte FluxBox</b> #<?= (int)$card['id'] ?> ·
                    <code><?= dp_html((string)$card['statut']) ?></code> ·
                    <code><?= dp_html((string)$card['priorite']) ?></code><br>
                    <b>Titre</b> : <?= dp_html((string)$card['titre']) ?><br>
                    <b>Document</b> : <?= dp_html((string)$doc['fichier_nom']) ?>
                    · hash <code><?= dp_html(substr((string)$doc['hash_sha256'], 0, 16)) ?>…</code>
                    · <?= number_format((int)$doc['taille_octets'] / 1024, 0) ?> Ko
                </div>
                <details style="margin-top: 10px;">
                    <summary>🔍 Snapshot BDD avant exécution</summary>
                    <pre><?= dp_html(json_encode($beforeSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
            </div>

            <!-- Formulaire : entités à lier -->
            <form method="POST" action="?card_id=<?= (int)$cardId ?>">
                <input type="hidden" name="card_id" value="<?= (int)$cardId ?>">

                <div class="panel">
                    <h2>🔗 Entités métier à lier (ged_document_links)</h2>
                    <p class="meta">Saisis les IDs des entités à lier au document. Préfill auto depuis
                       <code>proposition_json.matching_v0/validation_v0</code> si dispo.</p>

                    <?php foreach ($linkSpecs as $key => $spec):
                        $val = $inputLinks[$key]['entity_id'] ?? 0;
                        $rel = $inputLinks[$key]['relation_type'] ?? $spec['relation_default'];
                    ?>
                        <div class="link-row">
                            <div class="lbl"><b><?= dp_html(strtoupper($key)) ?></b><br>
                                <span style="font-size:9.5px;"><?= dp_html($spec['entity_type']) ?></span></div>
                            <div class="field" style="margin: 0;">
                                <label>ID <?= dp_html($spec['table']) ?>.id</label>
                                <input type="number" name="link_<?= $key ?>_id" min="0"
                                       value="<?= $val > 0 ? (int)$val : '' ?>"
                                       placeholder="(vide = ne pas lier)">
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>relation_type</label>
                                <select name="link_<?= $key ?>_rel">
                                    <?php foreach (['main','reference','annexe','piece_jointe'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $rel === $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Opérations prévues -->
                <div class="panel">
                    <h2>📋 Opérations <?= $execStatus === 'ok' ? 'exécutées' : 'prévues (dry-run)' ?></h2>

                    <h3>1. ged_documents</h3>
                    <?php if ($ops['ged_documents']):
                        $o = $ops['ged_documents'];
                        $cls = $o['mode'] === 'insert' ? 'insert' : ($o['mode'] === 'skip' ? 'skip' : 'error');
                    ?>
                        <div class="op <?= $cls ?>">
                            <div class="op-head">
                                <span class="op-tag"><?= dp_html(strtoupper($o['mode'])) ?></span>
                                ged_documents
                            </div>
                            <div class="op-reason"><?= dp_html((string)($o['reason'] ?? '')) ?></div>
                            <?php if (!empty($o['data'])): ?>
                                <pre><?= dp_html(json_encode($o['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h3>2. ged_document_links (<?= count($ops['ged_document_links']) ?>)</h3>
                    <?php if (empty($ops['ged_document_links'])): ?>
                        <div class="op skip">
                            <div class="op-head">
                                <span class="op-tag">VIDE</span> aucune entité saisie
                            </div>
                            <div class="op-reason">Saisis au moins un ID d'entité dans le bloc ci-dessus.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($ops['ged_document_links'] as $linkOp):
                            $cls = $linkOp['mode'];
                        ?>
                            <div class="op <?= $cls ?>">
                                <div class="op-head">
                                    <span class="op-tag"><?= dp_html(strtoupper($linkOp['mode'])) ?></span>
                                    <?= dp_html((string)($linkOp['spec']['entity_type'] ?? '?')) ?>
                                    #<?= (int)($linkOp['spec']['entity_id'] ?? 0) ?>
                                    / <?= dp_html((string)($linkOp['spec']['relation_type'] ?? '?')) ?>
                                </div>
                                <?php if (!empty($linkOp['reason'])): ?>
                                    <div class="op-reason"><?= dp_html((string)$linkOp['reason']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <h3>3. fluxbox_cartes</h3>
                    <?php if ($ops['fluxbox_cartes']):
                        $o = $ops['fluxbox_cartes'];
                        $cls = $o['mode'] === 'update' ? 'update' : 'skip';
                    ?>
                        <div class="op <?= $cls ?>">
                            <div class="op-head">
                                <span class="op-tag"><?= dp_html(strtoupper($o['mode'])) ?></span>
                                fluxbox_cartes #<?= (int)$cardId ?>
                            </div>
                            <div class="op-reason"><?= dp_html((string)($o['reason'] ?? '')) ?></div>
                            <?php if (!empty($o['data'])): ?>
                                <pre><?= dp_html(json_encode($o['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h3>4. fluxbox_actions_ia (audit trail)</h3>
                    <?php if ($ops['fluxbox_actions_ia']):
                        $o = $ops['fluxbox_actions_ia'];
                        $cls = $o['mode'] === 'insert' ? 'insert' : 'skip';
                    ?>
                        <div class="op <?= $cls ?>">
                            <div class="op-head">
                                <span class="op-tag"><?= dp_html(strtoupper($o['mode'])) ?></span>
                                fluxbox_actions_ia
                            </div>
                            <div class="op-reason"><?= dp_html((string)($o['reason'] ?? '')) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Log exécution si apply -->
                <?php if (!empty($execLog)): ?>
                    <div class="panel">
                        <h2>📜 Log d'exécution (transaction)</h2>
                        <pre class="exec-log"><?= dp_html(implode("\n", $execLog)) ?></pre>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="panel">
                    <div class="actions">
                        <button type="submit" name="mode" value="dry_run" class="btn btn-ghost">
                            🔄 Refresh dry-run
                        </button>
                        <div style="display:flex; gap:10px;">
                            <a href="?" class="btn btn-ghost">← Changer de carte</a>
                            <button type="submit" name="mode" value="apply" class="btn btn-danger"
                                    onclick="return confirm('⚠️ COMMIT en BDD sur la carte #<?= (int)$cardId ?>. Continuer ?');">
                                💾 Appliquer (COMMIT)
                            </button>
                        </div>
                    </div>
                    <div class="v0-warn">
                        <b>Sécurités actives :</b>
                        transaction PDO atomique · rollback auto sur exception ·
                        UK <code>(document_id, entity_type, entity_id, relation_type)</code> respectée ·
                        re-exécution = no-op (idempotent) · 0 DDL · 0 DELETE.
                    </div>
                </div>
            </form>

        <?php endif; ?>

        <details style="margin-top: 20px;">
            <summary>🔍 Debug : état session/post brut</summary>
            <pre><?= dp_html(json_encode([
                'cardId'       => $cardId,
                'mode'         => $mode,
                'isApply'      => $isApply,
                'execStatus'   => $execStatus,
                'inputLinks'   => $inputLinks,
                'beforeSnapshot' => $beforeSnapshot,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>

    </main>
</div>

</body>
</html>
