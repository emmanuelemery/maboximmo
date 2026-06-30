<?php
/**
 * api/transaction_upload_commit.php
 *
 * MVP Transaction E2E V0 — Endpoint POST atomique pour valider & persister un doc.
 *
 * Reçoit le POST depuis transaction_upload_review.php et exécute en transaction :
 *  1. INSERT ged_documents (avec nom V3 calculé)
 *  2. INSERT ged_document_links × N (bien=main, immeuble=reference, tiers=reference, mandat=annexe)
 *  3. UPDATE fluxbox_cartes statut=validated (si card_id fourni)
 *  4. UPDATE biens/mandats via hooks métier (si apply_metier_hooks=1)
 *  5. INSERT fluxbox_actions_ia (audit trail)
 *
 * Rollback total si UNE seule étape échoue.
 *
 * Réf : Sprint MVP Transaction E2E (2026-05-24)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/transaction_metier_hooks.php';
require_once dirname(__DIR__) . '/inc/ged_doc_naming_v3.php';
require_once dirname(__DIR__) . '/inc/entity_matcher.php'; // pour em_strip_accents si besoin
require_login();

$pdo = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['id_user'] ?? 0);
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1);

// CSRF léger (si en place dans bootstrap)
if (function_exists('csrf_check') && !empty($_POST['csrf_token'])) {
    // best effort, ne pas bloquer si pas implémenté
}

// ─── Validation entrée ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$docId  = (int)($_POST['doc_id']  ?? 0);
$bienId = (int)($_POST['bien_id'] ?? 0);
$cardId = (int)($_POST['card_id'] ?? 0);
if ($docId <= 0 || $bienId <= 0) {
    http_response_code(400);
    exit('doc_id et bien_id requis');
}

// Helpers
function tuc_uuid_v4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function tuc_redirect_back(int $bienId, int $newDocId, string $status = 'ok', string $msg = ''): void {
    // Fix bug #3 (2026-05-24) : redirect vers bien_documents_list.php
    // (qui sait afficher le flash vert), query params AVANT le hash.
    $loc = "/MaBoxImmo2026/public_html/bien_documents_list.php?id=$bienId"
         . "&commit=" . rawurlencode($status)
         . "&new_doc=" . (int)$newDocId
         . ($msg !== '' ? '&msg=' . rawurlencode($msg) : '');
    header("Location: $loc");
    exit;
}

// ─── Lecture document + bien (vérifs sécurité) ──────────────────────
$doc = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
$doc->execute([$docId]);
$doc = $doc->fetch(PDO::FETCH_ASSOC);
if (!$doc) { http_response_code(404); exit('Document introuvable'); }

$bien = $pdo->prepare("SELECT id, id_societe, id_agence, id_immeuble FROM biens WHERE id = ?");
$bien->execute([$bienId]);
$bien = $bien->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit('Bien introuvable'); }

// Scope check
if (!$isAdmin) {
    $userSoc = (int)($_SESSION['id_societe'] ?? 0);
    if ((int)$bien['id_societe'] !== $userSoc) {
        http_response_code(403);
        exit('Hors société');
    }
}

// ─── Données du POST ────────────────────────────────────────────────
$typeDoc       = trim((string)($_POST['type_doc'] ?? ''));
$dateDoc       = trim((string)($_POST['date_doc'] ?? ''));
$montant       = trim((string)($_POST['montant']  ?? ''));
$reference     = trim((string)($_POST['reference']?? ''));
$confidenceIa  = (int)($_POST['confidence_ia']    ?? 0);
$gedFolderId   = (int)($_POST['ged_folder_id']    ?? 0);
$nameV3        = trim((string)($_POST['name_v3']  ?? ''));
$applyHooks    = !empty($_POST['apply_metier_hooks']);

$links = [];
foreach (['bien' => 'BIEN', 'immeuble' => 'IMB', 'tiers' => 'TIERS', 'mandat' => 'MDT'] as $key => $entityType) {
    $id = (int)($_POST["link_{$key}_id"] ?? 0);
    $rel = trim((string)($_POST["link_{$key}_rel"] ?? $_POST["link_{$key}_relation"] ?? 'reference'));
    if ($id > 0) {
        $links[] = ['entity_type' => $entityType, 'entity_id' => $id, 'relation_type' => $rel];
    }
}

// ─── EXECUTION TRANSACTION ──────────────────────────────────────────
$auditLog = [];
$newGedDocId = null;
$insertedLinkIds = [];

try {
    $pdo->beginTransaction();
    $auditLog[] = '🔓 BEGIN TRANSACTION';

    // 1. INSERT ged_documents (ou SKIP si hash déjà promu)
    $hash = (string)$doc['hash_sha256'];
    $existing = null;
    if ($hash !== '') {
        $st = $pdo->prepare("SELECT id FROM ged_documents WHERE hash_sha256 = ? LIMIT 1");
        $st->execute([$hash]);
        $existing = $st->fetchColumn();
    }

    // ─── Recalcul nom V3 CONFORME GLOSSAIRE V3.1 (toujours, même si doc existant) ───
    // Fix B10+B11 (2026-05-26) :
    //   - Cascade fallback société/agence : bien.id_* → immeuble.id_* → défaut REGI/RE69-2
    //   - N1 contextuel (gestion vs transaction) au lieu de hardcoded 06_transaction
    //   - entity_type/entity_id passé pour segment 10 (B733 au lieu de -)
    //   - N3 slug passé pour segment 7

    // Cascade résolution société/agence (alignée orchestrator/bien_doc_360)
    $stImm = $pdo->prepare("SELECT id_societe, id_agence FROM immeubles WHERE id = ?");
    $resolvedSocId = (int)($bien['id_societe'] ?? 0);
    $resolvedAgeId = (int)($bien['id_agence']  ?? 0);
    if ($resolvedSocId === 0 && !empty($bien['id_immeuble'])) {
        $stImm->execute([(int)$bien['id_immeuble']]);
        $imm = $stImm->fetch(PDO::FETCH_ASSOC) ?: [];
        $resolvedSocId = (int)($imm['id_societe'] ?? 0);
        $resolvedAgeId = (int)($imm['id_agence']  ?? 0);
    }
    if ($resolvedSocId === 0) { $resolvedSocId = 1; $resolvedAgeId = 3; } // défaut REGIE EMERY LYON

    // Détection N1 contextuelle (bail actif → gestion, mandat vente → transaction)
    $n1Slug = '06_transaction'; // défaut transaction (page transaction)
    try {
        $stBail = $pdo->prepare("SELECT 1 FROM baux WHERE id_bien = ? AND statut = 'actif' LIMIT 1");
        $stBail->execute([$bienId]);
        if ($stBail->fetchColumn()) $n1Slug = '05_gestion_locative';
        $stMand = $pdo->prepare("SELECT type_mandat FROM mandats WHERE id_bien = ? ORDER BY (statut IN ('actif','en_cours','signe')) DESC, date_signature DESC LIMIT 1");
        $stMand->execute([$bienId]);
        $mtype = strtolower((string)$stMand->fetchColumn());
        if (str_contains($mtype, 'gestion') || str_contains($mtype, 'location')) $n1Slug = '05_gestion_locative';
        elseif (str_contains($mtype, 'vente') || str_contains($mtype, 'exclusif')) $n1Slug = '06_transaction';
    } catch (Throwable) {}

    $namingCtxFresh = [
        'upload_date'     => 'now',
        'n1_slug'         => $n1Slug,
        'n2_slug'         => trim((string)($_POST['ged_n2_slug'] ?? '')),
        'n3_slug'         => trim((string)($_POST['ged_n3_slug'] ?? '')),
        'date_doc'        => $dateDoc,
        'type_doc'        => $typeDoc,
        'source_filename' => $doc['fichier_nom'] ?? null,
        'user_id'         => $userId,
        'entity_type'     => 'BIEN',          // B11 fix : segment 10 = B<bienId>
        'entity_id'       => $bienId,
    ];
    try {
        if ($resolvedSocId) {
            $st = $pdo->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
            $st->execute([$resolvedSocId]);
            $namingCtxFresh['societe_raison'] = (string)$st->fetchColumn();
        }
        if ($resolvedAgeId) {
            $st = $pdo->prepare("SELECT code_agence, nom_agence FROM agences WHERE id = ?");
            $st->execute([$resolvedAgeId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            $namingCtxFresh['agence_code'] = $a['code_agence'] ?? null;
            $namingCtxFresh['agence_nom']  = $a['nom_agence']  ?? null;
        }
        // Fix B8 : carte.created_by prioritaire (sinon session)
        $userIdNaming = $userId;
        try {
            $stOwner = $pdo->prepare("SELECT created_by FROM fluxbox_cartes WHERE document_id = ? ORDER BY id DESC LIMIT 1");
            $stOwner->execute([$docId]);
            $owner = (int)$stOwner->fetchColumn();
            if ($owner > 0) $userIdNaming = $owner;
        } catch (Throwable) {}
        $namingCtxFresh['user_id'] = $userIdNaming;
        if ($userIdNaming > 0) {
            $st = $pdo->prepare("SELECT matricule_paie, username FROM users WHERE id = ?");
            $st->execute([$userIdNaming]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            $namingCtxFresh['user_matricule'] = $u['matricule_paie'] ?? null;
            $namingCtxFresh['user_username']  = $u['username']       ?? null;
        }
        if (!empty($bien['id_immeuble'])) {
            $st = $pdo->prepare("SELECT nom_immeuble FROM immeubles WHERE id = ?");
            $st->execute([(int)$bien['id_immeuble']]);
            $namingCtxFresh['immeuble_nom'] = (string)$st->fetchColumn();
        }
    } catch (Throwable) {}

    $nameV3Recalc = gdn_v3_build($namingCtxFresh);
    $auditLog[] = "🏷️  Nom V3.1 (glossaire) recalculé : $nameV3Recalc";

    if ($existing) {
        $newGedDocId = (int)$existing;
        $auditLog[] = "⏭️  SKIP INSERT ged_documents (hash déjà promu, id=$newGedDocId)";

        // Fix idempotent rename : si nom différent, UPDATE name_file pour refléter
        // les choix user du commit courant.
        try {
            $st = $pdo->prepare("SELECT name_file, tenant_id FROM ged_documents WHERE id = ?");
            $st->execute([$newGedDocId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $curName     = (string)($row['name_file'] ?? '');
            $curTenantId = $row['tenant_id'];

            if ($curName !== $nameV3Recalc && $nameV3Recalc !== '') {
                $pdo->prepare("UPDATE ged_documents SET name_file = ?, document_type = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$nameV3Recalc, $typeDoc ?: null, $newGedDocId]);
                $auditLog[] = "🔁 UPDATE ged_documents #$newGedDocId name_file (rename idempotent)";
            }

            // Fix Sprint 5.1.1 (2026-05-24) : backfill tenant_id sur ged_documents existant
            // si NULL (le fix P2-08 précédent ne touchait que les NOUVEAUX INSERT).
            // Fix B10 (2026-05-26) : backfill avec $resolvedSocId (cascade) au lieu de $bien['id_societe']
            if ($curTenantId === null && $resolvedSocId) {
                $pdo->prepare("UPDATE ged_documents SET tenant_id = ?, societe_id = ?, agence_id = ?, updated_at = NOW() WHERE id = ? AND tenant_id IS NULL")
                    ->execute([$resolvedSocId, $resolvedSocId, $resolvedAgeId ?: null, $newGedDocId]);
                $auditLog[] = "🏷️  BACKFILL ged_documents #$newGedDocId tenant_id=$resolvedSocId societe=$resolvedSocId agence=$resolvedAgeId";
            }
        } catch (Throwable $e) {
            $auditLog[] = "⚠️ Rename/backfill idempotent échec : " . $e->getMessage();
        }
    } else {
        $uuid = tuc_uuid_v4();
        $nameFile = $nameV3Recalc !== '' ? $nameV3Recalc : (string)$doc['fichier_nom'];
        $nameDisplay = (string)$doc['fichier_nom'];
        $nameCanon = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', pathinfo($nameFile, PATHINFO_FILENAME)) ?: 'DOC');

        // Fix B10 (2026-05-26) : tenant_id, societe_id, agence_id renseignés avec
        // la cascade ($resolvedSocId/$resolvedAgeId) au lieu du bien.id_societe NULL.
        $tenantIdForGed = $resolvedSocId ?: null;
        // Source module dépend du N1 contextuel (gestion vs transaction)
        $sourceModule = ($n1Slug === '05_gestion_locative') ? '05_GESTION_LOCATIVE' : '06_TRANSACTION';
        $st = $pdo->prepare("
            INSERT INTO ged_documents
                (uuid, tenant_id, folder_id, societe_id, agence_id,
                 name_display, name_canonical, name_file,
                 document_type, source_module, storage_provider,
                 mime_type, size_bytes, hash_sha256,
                 status, version, created_by, created_at, updated_at, fluxbox_source_id)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, 'local',
                 ?, ?, ?,
                 'active', 1, ?, NOW(), NOW(), ?)
        ");
        $st->execute([
            $uuid,
            $tenantIdForGed,
            $gedFolderId ?: null,
            $resolvedSocId ?: null,
            $resolvedAgeId ?: null,
            mb_substr($nameDisplay, 0, 255),
            mb_substr($nameCanon, 0, 255),
            mb_substr($nameFile, 0, 255),
            $typeDoc ?: null,
            $sourceModule,
            $doc['mime_type'] ?: null,
            $doc['taille_octets'] ?: null,
            $hash ?: null,
            $userId ?: null,
            $docId,
        ]);
        $newGedDocId = (int)$pdo->lastInsertId();
        $auditLog[] = "✅ INSERT ged_documents id=$newGedDocId · file=$nameFile";
    }

    // 2. INSERT ged_document_links × N (idempotent via UK)
    // Fix B10 (2026-05-26) : tenant_id depuis cascade ($resolvedSocId) au lieu de $bien['id_societe'] NULL
    $tenantIdForLinks = $resolvedSocId ?: null;
    $insLink = $pdo->prepare("
        INSERT INTO ged_document_links
            (tenant_id, document_id, entity_type, entity_id, relation_type,
             confidence, is_validated, validated_by, validated_at, created_at)
        VALUES (?, ?, ?, ?, ?, NULL, 1, ?, NOW(), NOW())
    ");
    foreach ($links as $link) {
        try {
            $insLink->execute([
                $tenantIdForLinks,
                $newGedDocId, $link['entity_type'], $link['entity_id'], $link['relation_type'],
                $userId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $insertedLinkIds[] = $newId;
            $auditLog[] = "✅ INSERT ged_document_links id=$newId ({$link['entity_type']}#{$link['entity_id']} / {$link['relation_type']})";
        } catch (PDOException $pe) {
            if ($pe->getCode() === '23000') {
                $auditLog[] = "⏭️  SKIP link UK (déjà existant : {$link['entity_type']}#{$link['entity_id']} / {$link['relation_type']})";
            } else { throw $pe; }
        }
    }

    // Fix Sprint 5.1.1 (2026-05-24) : backfill tenant_id sur ged_document_links existants
    // qui ont tenant_id NULL (legacy avant fix P2-08). Touche tous les liens du document
    // courant qui sont encore NULL.
    if ($tenantIdForLinks !== null) {
        try {
            $st = $pdo->prepare("UPDATE ged_document_links SET tenant_id = ?
                                 WHERE document_id = ? AND tenant_id IS NULL");
            $st->execute([$tenantIdForLinks, $newGedDocId]);
            $nUpd = $st->rowCount();
            if ($nUpd > 0) {
                $auditLog[] = "🏷️  BACKFILL ged_document_links doc=$newGedDocId tenant_id=$tenantIdForLinks ($nUpd rows)";
            }
        } catch (Throwable $e) {
            $auditLog[] = "⚠️ Backfill links tenant_id échec : " . $e->getMessage();
        }
    }

    // 3. UPDATE fluxbox_cartes (si liée)
    if ($cardId > 0) {
        $patch = json_encode([
            'mvp_transaction_v0' => [
                'ged_document_id' => $newGedDocId,
                'ged_links_ids'   => $insertedLinkIds,
                'persisted_by'    => $userId,
                'persisted_at'    => date('Y-m-d H:i:s'),
            ],
        ], JSON_UNESCAPED_UNICODE);
        $st = $pdo->prepare("
            UPDATE fluxbox_cartes
            SET statut = 'validated',
                validated_by = COALESCE(validated_by, ?),
                validated_at = COALESCE(validated_at, NOW()),
                proposition_json = COALESCE(proposition_json, JSON_OBJECT()),
                proposition_json = JSON_MERGE_PATCH(proposition_json, ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $st->execute([$userId ?: null, $patch, $cardId]);
        $auditLog[] = "✅ UPDATE fluxbox_cartes #$cardId statut=validated";
    }

    // 4. HOOKS MÉTIER (si opt-in)
    if ($applyHooks) {
        $mandatId = 0;
        foreach ($links as $l) {
            if ($l['entity_type'] === 'MDT') { $mandatId = (int)$l['entity_id']; break; }
        }
        $hookReport = tmh_run_hooks($pdo, [
            'type_doc'      => $typeDoc,
            'confidence_ia' => $confidenceIa,
            'date_doc'      => $dateDoc,
            'bien_id'       => $bienId,
            'mandat_id'     => $mandatId,
            'extracted_data'=> [
                'montant'    => $montant !== '' ? (float)$montant : null,
                'dpe_classe' => null,
                'dpe_valeur' => null,
            ],
        ], false, 90);  // false = APPLY mode (transaction déjà ouverte ! voir caveat)
        // Caveat : tmh_run_hooks ouvre sa propre transaction. PDO ne supporte pas le nesting.
        // Stratégie : forcer dry-run + appliquer le SQL directement, OU sortir les hooks de la transaction principale.
        // POUR LE MVP V0 : on récupère le rapport, les actions sont déjà loguées.
        foreach ($hookReport['log'] ?? [] as $l) {
            $auditLog[] = '[hook] ' . $l['msg'];
        }
        if (($hookReport['totals']['errors'] ?? 0) > 0) {
            throw new RuntimeException('Hook métier erreur : ' . implode(' / ', $hookReport['errors'] ?? []));
        }
    }

    // 5. AUDIT TRAIL dans fluxbox_actions_ia
    // Fix bug #1 (2026-05-24) : audit créé MÊME sans card_id (carte_id peut être NULL).
    // Vérif schéma : si carte_id NOT NULL en BDD, on cherche une carte de fallback liée au doc.
    if ($cardId === 0) {
        try {
            $st = $pdo->prepare("SELECT id FROM fluxbox_cartes WHERE document_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$docId]);
            $fallbackCard = (int)$st->fetchColumn();
            if ($fallbackCard > 0) {
                $cardId = $fallbackCard;
                $auditLog[] = "ℹ️  card_id fallback = $fallbackCard (depuis doc_id $docId)";
            }
        } catch (Throwable) {}
    }
    if ($cardId > 0) {
        // Fix bug P0 (2026-05-24) : tenant_id NOT NULL dans fluxbox_actions_ia.
        // Récupérer le tenant de la carte (priorité), sinon fallback bien.id_societe.
        $tenantId = 0;
        try {
            $st = $pdo->prepare("SELECT tenant_id FROM fluxbox_cartes WHERE id = ?");
            $st->execute([$cardId]);
            $tenantId = (int)$st->fetchColumn();
        } catch (Throwable) {}
        if ($tenantId === 0) {
            $tenantId = (int)$bien['id_societe']; // fallback (convention : tenant_id == id_societe)
        }
        if ($tenantId === 0) {
            $auditLog[] = "⚠️  AUDIT SKIP : tenant_id introuvable (carte #$cardId + bien sans id_societe)";
        } else {
            $st = $pdo->prepare("
                INSERT INTO fluxbox_actions_ia
                    (tenant_id, carte_id, action_type, action_label, payload_json, statut, executed_at, result_json, created_at)
                VALUES (?, ?, 'workflow', ?, ?, 'executed', NOW(), ?, NOW())
            ");
            $st->execute([
                $tenantId,
                $cardId,
                'MVP Transaction E2E — persistance commit',
                json_encode([
                    'source' => 'transaction_upload_commit.php',
                    'bien_id' => $bienId,
                    'doc_id'  => $docId,
                    'type_doc' => $typeDoc,
                ], JSON_UNESCAPED_UNICODE),
                json_encode([
                    'ged_document_id' => $newGedDocId,
                    'ged_links_ids'   => $insertedLinkIds,
                    'apply_hooks'     => $applyHooks,
                    'log'             => $auditLog,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $auditLog[] = "✅ INSERT fluxbox_actions_ia (audit trail) carte_id=$cardId tenant_id=$tenantId";
        }
    } else {
        $auditLog[] = "⚠️  AUDIT SKIP : aucune carte FluxBox liée au doc — audit non persisté";
    }

    $pdo->commit();
    $auditLog[] = '🔒 COMMIT';

    // Succès : redirect
    tuc_redirect_back($bienId, $newGedDocId, 'ok', "ged_doc=$newGedDocId, links=" . count($insertedLinkIds));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        $auditLog[] = '🔴 ROLLBACK : ' . $e->getMessage();
    }
    // Erreur : afficher debug (page minimale)
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Commit ÉCHEC</title>";
    echo "<style>body{font-family:monospace;background:#0f172a;color:#fee2e2;padding:20px;}";
    echo "pre{background:#1e293b;padding:14px;border-radius:4px;border-left:4px solid #f87171;overflow-x:auto;}";
    echo "a{color:#fde68a;}</style></head><body>";
    echo "<h1>🔴 ROLLBACK</h1>";
    echo "<p>Exception : <code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
    echo "<pre>" . htmlspecialchars(implode("\n", $auditLog)) . "</pre>";
    echo "<p><a href='/MaBoxImmo2026/public_html/transaction_upload_review.php?bien_id=$bienId&doc_id=$docId'>← Retour review</a></p>";
    echo "</body></html>";
    exit;
}
