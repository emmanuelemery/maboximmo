<?php
/**
 * api/creancier_doc_valider.php — Validation d'une analyse IA créancier.
 *
 * À la validation humaine d'une analyse (creancier_doc_analyse, statut='a_valider') :
 *   1. Commit du PDF en GED (gus_commit_document) — réutilise le pipeline central.
 *   2. Liens polymorphes : doc ↔ CREANCIER_DOSSIER (+ TIERS créancier).
 *   3. Tiers créancier : réutilise le match anti-doublon, sinon CRÉE un tiers
 *      (personne_morale) + rôle 'creancier', et le LIE au dossier.
 *   4. Crée un item DETTE dans le dossier (montant extrait) → visible au cockpit.
 *   5. Marque l'analyse 'valide' (ged_document_id, validated_by/at).
 *
 * Transactionnel. Ne duplique aucun tiers (match d'abord). N'écrit jamais dans biens/annonces.
 *
 * POST : analyse_id (requis), csrf_token (form 'creancier_doc_valider').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST requis']); exit;
}
verify_csrf_any('creancier_doc_valider');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'manager+ requis']); exit; }

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$analyseId = (int)($_POST['analyse_id'] ?? 0);
if ($analyseId <= 0) { echo json_encode(['ok' => false, 'error' => 'analyse_id requis']); exit; }

// ── Charge l'analyse ─────────────────────────────────────────────────
$st = $pdo->prepare("SELECT * FROM creancier_doc_analyse WHERE id = ? LIMIT 1");
$st->execute([$analyseId]);
$an = $st->fetch(PDO::FETCH_ASSOC);
if (!$an) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Analyse introuvable']); exit; }
if ($an['statut'] !== 'a_valider') { echo json_encode(['ok' => false, 'error' => 'Analyse déjà traitée (' . $an['statut'] . ')']); exit; }

$socId = $an['id_societe'] !== null ? (int)$an['id_societe'] : null;
$ageId = $an['id_agence']  !== null ? (int)$an['id_agence']  : null;

// ── Résolution du dossier : choisi / existant / NOUVEAU ──────────────────
// Priorité : id_dossier POST > nouveau_dossier > analyse.id_dossier.
$postDossier   = (int)($_POST['id_dossier'] ?? 0);
$nouveauFlag   = !empty($_POST['nouveau_dossier']);
$idDossier     = 0;
$dossierCree   = false;

$pdo->beginTransaction();
try {
    if ($postDossier > 0) {
        $idDossier = $postDossier;
    } elseif ($nouveauFlag) {
        // Création d'un nouveau dossier (débiteur) — code auto si absent.
        $libelle = trim((string)($_POST['libelle'] ?? '')) ?: 'Dossier créancier';
        $code    = strtoupper(trim((string)($_POST['code'] ?? '')));
        if ($code === '') {
            $base = preg_replace('/[^A-Z0-9]/', '', strtoupper(function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT', $libelle) ?: $libelle) : $libelle));
            $code = substr($base ?: 'DOSSIER', 0, 8) ?: 'DOSSIER';
        }
        // Unicité du code.
        $stC = $pdo->prepare("SELECT 1 FROM creancier_dossier WHERE code = ? LIMIT 1");
        $base = $code; $i = 1;
        while (true) { $stC->execute([$code]); if (!$stC->fetchColumn()) break; $code = substr($base, 0, 6) . $i; $i++; }
        $insD = $pdo->prepare("INSERT INTO creancier_dossier (code, libelle, statut, niveau_risque, synthese, numero_dossier_adverse, id_societe, id_agence, created_by)
                               VALUES (?,?, 'surveillance','orange', ?, ?, ?, ?, ?)");
        $insD->execute([$code, mb_substr($libelle, 0, 190),
            'Créé automatiquement depuis l\'analyse d\'un document.',
            $an['extr_numero_dossier'] ?: null, $socId, $ageId, $userId]);
        $idDossier = (int)$pdo->lastInsertId();
        $dossierCree = true;
        // ACL pilote pour le créateur (sinon il ne verrait pas son propre dossier).
        $pdo->prepare("INSERT IGNORE INTO creancier_dossier_acces (id_dossier, id_user, niveau, created_by) VALUES (?,?, 'pilote', ?)")
            ->execute([$idDossier, $userId, $userId]);
    } elseif ((int)$an['id_dossier'] > 0) {
        $idDossier = (int)$an['id_dossier'];
    } else {
        throw new RuntimeException('Aucun dossier : choisir un dossier existant ou cocher « nouveau dossier ».');
    }

    // ACL : accès au dossier (le créateur vient de se l'octroyer ci-dessus).
    if (!creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
        throw new RuntimeException('Accès dossier refusé');
    }

    $creaNom = trim((string)($an['extr_creancier_nom'] ?? ''));
    $montant = $an['extr_montant_total'] !== null ? (float)$an['extr_montant_total']
             : ($an['extr_montant_principal'] !== null ? (float)$an['extr_montant_principal'] : null);

    // ── 1. Tiers créancier (match d'abord, sinon création) ───────────────
    $idCreancier = $an['id_tiers_creancier_match'] !== null ? (int)$an['id_tiers_creancier_match'] : 0;
    $creancierCree = false;
    if ($idCreancier <= 0 && $creaNom !== '') {
        $ins = $pdo->prepare("INSERT INTO tiers (id_societe, id_agence, type_tiers, raison_sociale, nom_affichage, actif, id_user_createur, date_creation, date_modification)
                              VALUES (?,?, 'personne_morale', ?, ?, 1, ?, NOW(), NOW())");
        $ins->execute([$socId, $ageId, $creaNom, $creaNom, $userId]);
        $idCreancier = (int)$pdo->lastInsertId();
        $creancierCree = true;
    }
    // Rôle 'creancier' (idempotent via UK uniq_tiers_role_objet).
    if ($idCreancier > 0) {
        $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, actif) VALUES (?, 'creancier', NULL, 1)")
            ->execute([$idCreancier]);
    }

    // ── 2. Commit GED du PDF stocké ──────────────────────────────────────
    $docId = 0; $docName = null;
    $path = (string)($an['storage_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        $typeDoc = (string)($an['type_doc'] ?? 'autre');
        $n3map = [
            'conclusions'   => '01_conclusions', 'jugement' => '02_jugement',
            'commandement'  => '03_commandement', 'acte_saisie' => '04_acte_saisie',
            'correspondance'=> '05_correspondance', 'mail' => '06_mail', 'autre' => '99_autre',
        ];
        $socRaison = $socId ? (string)($pdo->query("SELECT raison_sociale FROM societes WHERE id = " . (int)$socId)->fetchColumn() ?: '') : '';
        $namingCtx = [
            'upload_date'     => 'now',
            'n1_slug'         => '12_contentieux',
            'n2_slug'         => '01_creanciers',
            'n3_slug'         => $n3map[$typeDoc] ?? '99_autre',
            'date_doc'        => date('Y-m-d'),
            'type_doc'        => $typeDoc,
            'source_filename' => (string)($an['file_name'] ?? 'document.pdf'),
            'user_id'         => $userId,
            'entity_type'     => 'CREANCIER_DOSSIER',
            'entity_id'       => $idDossier,
            'societe_raison'  => $socRaison ?: null,
        ];
        $ctx = [
            'tenant_id'      => $socId ?: null,
            'societe_id'     => $socId ?: null,
            'agence_id'      => $ageId ?: null,
            'document_type'  => strtoupper($typeDoc),
            'source_module'  => '12_CONTENTIEUX',
            'security_level' => 'confidentiel',
            'created_by'     => $userId ?: null,
            'naming_ctx'     => $namingCtx,
        ];
        $links = [
            ['entity_type' => 'CREANCIER_DOSSIER', 'entity_id' => $idDossier, 'relation_type' => 'main', 'is_validated' => true, 'validated_by' => $userId],
        ];
        if ($idCreancier > 0) {
            $links[] = ['entity_type' => 'TIERS', 'entity_id' => $idCreancier, 'relation_type' => 'reference', 'is_validated' => true, 'validated_by' => $userId];
        }
        $res = gus_commit_document($pdo, [
            'path_on_disk' => $path,
            'name_original'=> (string)($an['file_name'] ?? 'document.pdf'),
            'hash_sha256'  => (string)($an['file_hash'] ?? ''),
            'mime_type'    => (string)($an['file_mime'] ?? 'application/pdf'),
            'size_bytes'   => (int)($an['file_size'] ?? 0),
        ], $ctx, $links);
        if (empty($res['ok'])) {
            throw new RuntimeException('Commit GED échoué : ' . implode(' / ', $res['errors'] ?? ['inconnu']));
        }
        $docId   = (int)$res['doc_id'];
        $docName = $res['name_display'] ?? null;
    }

    // ── 3. Lier le créancier au dossier (idempotent via UK) ──────────────
    if ($idCreancier > 0) {
        $pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, created_by) VALUES (?, 'TIERS', ?, 'creancier', ?)")
            ->execute([$idDossier, $idCreancier, $userId]);
    }

    // ── 4. Item DETTE (montant extrait) → visible au cockpit ─────────────
    $itemId = 0;
    if ($montant !== null && $montant > 0) {
        $titre = 'Créance ' . ($creaNom ?: 'créancier') . ($an['extr_numero_dossier'] ? ' (' . $an['extr_numero_dossier'] . ')' : '');
        $insItem = $pdo->prepare("INSERT INTO creancier_dossier_item (id_dossier, type, titre, description, montant, id_tiers_lie, statut, priorite, created_by)
                                  VALUES (?, 'DETTE', ?, ?, ?, ?, 'ouvert', 5, ?)");
        $insItem->execute([$idDossier, mb_substr($titre, 0, 190), $an['extr_objet'] ?? null, $montant, $idCreancier ?: null, $userId]);
        $itemId = (int)$pdo->lastInsertId();
    }

    // ── 5. Marquer l'analyse validée ─────────────────────────────────────
    $pdo->prepare("UPDATE creancier_doc_analyse
                   SET statut='valide', ged_document_id=?, id_tiers_creancier_match=?, validated_by=?, validated_at=NOW()
                   WHERE id=?")
        ->execute([$docId ?: null, $idCreancier ?: null, $userId, $analyseId]);

    $pdo->commit();
    echo json_encode([
        'ok'              => true,
        'analyse_id'      => $analyseId,
        'id_dossier'      => $idDossier,
        'dossier_cree'    => $dossierCree,
        'ged_document_id' => $docId,
        'ged_name'        => $docName,
        'id_creancier'    => $idCreancier,
        'creancier_cree'  => $creancierCree,
        'item_dette_id'   => $itemId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
