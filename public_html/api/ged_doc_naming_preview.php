<?php
/**
 * api/ged_doc_naming_preview.php
 *
 * Endpoint preview JSON du nom V3 recalculé dynamiquement.
 * Utilisé par le JS de la page transaction_upload_review.php pour mettre
 * à jour la preview à chaque changement de type_doc, date_doc, n2_slug.
 *
 * GET ?bien_id=X&type_doc=...&date_doc=...&n2_slug=... → JSON {name_v3, segments}
 * Accès super admin (role=1) OU agent agence du bien.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_doc_naming_v3.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['id_user'] ?? 0);
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1);

$bienId  = (int)($_GET['bien_id']  ?? 0);
$docId   = (int)($_GET['doc_id']   ?? 0);
$typeDoc = trim((string)($_GET['type_doc']  ?? ''));
$dateDoc = trim((string)($_GET['date_doc']  ?? ''));
$n1Slug  = trim((string)($_GET['n1_slug']   ?? '06_transaction'));
$n2Slug  = trim((string)($_GET['n2_slug']   ?? ''));
$n3Slug  = trim((string)($_GET['n3_slug']   ?? ''));
$n4Slug  = trim((string)($_GET['n4_slug']   ?? ''));
$entityType = trim((string)($_GET['entity_type'] ?? 'BIEN'));
$entityId   = trim((string)($_GET['entity_id']   ?? (string)$bienId));

if ($bienId <= 0) {
    echo json_encode(['error' => 'bien_id requis']);
    exit;
}

// Lecture bien + scope
$st = $pdo->prepare("SELECT id, id_societe, id_agence, id_immeuble FROM biens WHERE id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { echo json_encode(['error' => 'bien introuvable']); exit; }

// Scope check : si bien sans société (orphelin), on accepte (admin de toute façon)
if (!$isAdmin && !empty($bien['id_societe']) && (int)$bien['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['error' => 'hors société']);
    exit;
}

// Lecture nom fichier source (pour extension)
$sourceFilename = null;
if ($docId > 0) {
    $st = $pdo->prepare("SELECT fichier_nom FROM fluxbox_documents WHERE id = ?");
    $st->execute([$docId]);
    $sourceFilename = (string)$st->fetchColumn();
}

// Contexte glossaire (lecture BDD)
$ctx = [
    'upload_date'     => 'now',
    'n1_slug'         => $n1Slug,
    'n2_slug'         => $n2Slug,
    'n3_slug'         => $n3Slug,
    'n4_slug'         => $n4Slug,
    'date_doc'        => $dateDoc,
    'type_doc'        => $typeDoc,
    'user_id'         => $userId,
    'source_filename' => $sourceFilename,
    'entity_type'     => $entityType,
    'entity_id'       => $entityId,
];
try {
    // Fix P2 (2026-05-26) : cascade résolution société/agence comme orchestrator
    // bien.id_* → immeuble.id_* → fallback Régie EMERY/LYON #1/#3
    $resolvedSocId = (int)($bien['id_societe'] ?? 0);
    $resolvedAgeId = (int)($bien['id_agence']  ?? 0);
    if ($resolvedSocId === 0 && !empty($bien['id_immeuble'])) {
        $stImm = $pdo->prepare("SELECT id_societe, id_agence FROM immeubles WHERE id = ?");
        $stImm->execute([(int)$bien['id_immeuble']]);
        $imm = $stImm->fetch(PDO::FETCH_ASSOC) ?: [];
        $resolvedSocId = (int)($imm['id_societe'] ?? 0);
        $resolvedAgeId = (int)($imm['id_agence']  ?? 0);
    }
    if ($resolvedSocId === 0) { $resolvedSocId = 1; $resolvedAgeId = 3; } // défaut REGIE EMERY LYON

    if ($resolvedSocId) {
        $st = $pdo->prepare("SELECT raison_sociale FROM societes WHERE id = ?");
        $st->execute([$resolvedSocId]);
        $ctx['societe_raison'] = (string)$st->fetchColumn();
    }
    if ($resolvedAgeId) {
        $st = $pdo->prepare("SELECT code_agence, nom_agence FROM agences WHERE id = ?");
        $st->execute([$resolvedAgeId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        $ctx['agence_code'] = $a['code_agence'] ?? null;
        $ctx['agence_nom']  = $a['nom_agence']  ?? null;
    }
    // Fix B8 (2026-05-26) : aligne avec orchestrator → préfère carte.created_by si dispo
    // (sinon fallback session id_user). Évite l'incohérence "USER" vs "0005".
    $userIdNaming = $userId;
    if ($docId > 0) {
        try {
            $stC = $pdo->prepare("SELECT c.created_by FROM fluxbox_cartes c WHERE c.document_id = ? ORDER BY c.id DESC LIMIT 1");
            $stC->execute([$docId]);
            $cardOwner = (int)$stC->fetchColumn();
            if ($cardOwner > 0) $userIdNaming = $cardOwner;
        } catch (Throwable) {}
    }
    $ctx['user_id'] = $userIdNaming;
    if ($userIdNaming > 0) {
        $st = $pdo->prepare("SELECT matricule_paie, username FROM users WHERE id = ?");
        $st->execute([$userIdNaming]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $ctx['user_matricule'] = $u['matricule_paie'] ?? null;
        $ctx['user_username']  = $u['username']       ?? null;
    }
} catch (Throwable) {}

$segments = gdn_v3_explain($ctx);
echo json_encode([
    'name_v3'  => $segments['final_name'],
    'segments' => $segments,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
