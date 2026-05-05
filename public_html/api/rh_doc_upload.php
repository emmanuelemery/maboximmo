<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_document_extractor.php';
require_once __DIR__ . '/../inc/rh_doc_societe_ocr_hook.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Database error']); exit; }

$currentUserId = current_user_id();
$roleId        = current_role_id();
$agenceScope   = can_manage_salaires_agence();

$targetUserId = (int)($_POST['user_id'] ?? $currentUserId);

// Contrôle d'accès
if ($targetUserId !== $currentUserId && $roleId !== 1) {
    if ($agenceScope > 0) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
        $chk->execute([$targetUserId, $agenceScope]);
        if (!$chk->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Accès refusé']); exit; }
    } else {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'Accès refusé']); exit;
    }
}

// Vérifier que la table a les colonnes nécessaires
try {
    $cols = $pdo->query("SHOW COLUMNS FROM salaires_documents")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('confidentiel', $cols)) {
        $pdo->exec("ALTER TABLE salaires_documents ADD COLUMN confidentiel TINYINT DEFAULT 0");
    }
    if (!in_array('obligatoire', $cols)) {
        $pdo->exec("ALTER TABLE salaires_documents ADD COLUMN obligatoire TINYINT DEFAULT 0");
    }
} catch (Exception $e) {}

$rubrique    = trim($_POST['rubrique'] ?? 'divers');
$typeDoc     = trim($_POST['type_doc'] ?? 'autre');
$nomAffiche  = trim($_POST['nom_affiche'] ?? '');
// Seul le role_id=1 peut uploader un doc confidentiel
$confidentiel = ($roleId === 1 && !empty($_POST['confidentiel'])) ? 1 : 0;
$obligatoire  = !empty($_POST['obligatoire']) ? 1 : 0;

$allowedRubriques = ['personne', 'vehicule', 'societe', 'agence', 'rh', 'divers'];
if (!in_array($rubrique, $allowedRubriques)) $rubrique = 'divers';

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'Aucun fichier fourni']); exit;
}

$file    = $_FILES['file'];
$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
if (!in_array($fileExt, $allowedExt)) {
    echo json_encode(['success' => false, 'message' => 'Extension non autorisée']); exit;
}

$allowedMimes = [
    'application/pdf', 'image/jpeg', 'image/png', 'image/gif',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($realMime, $allowedMimes)) {
    echo json_encode(['success' => false, 'message' => "Type MIME non autorisé ($realMime)"]); exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Fichier trop volumineux (max 10 Mo)']); exit;
}

$uploadDir = __DIR__ . '/../uploads/rh_docs/' . $targetUserId . '/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$newFileName = 'doc_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
$filePath    = $uploadDir . $newFileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du téléchargement']); exit;
}

// ─────────────────────────────────────────────────────────────
// Conversion taxonomie rh_doc_types → rh_profil.php (table rh_documents)
// rh_documents est LA source de vérité unique pour les docs RH collaborateur.
// Cette page envoie les clés "rh_doc_types" (cni, permis, assurance_veh, …)
// mais rh_profil.php et la table rh_documents utilisent l'ancienne taxonomie
// (carte_identite, permis_conduire, assurance_vehicule, …).
// ─────────────────────────────────────────────────────────────
$rubriqueMap = [
    'personne' => 'identite',
    'divers'   => 'autre',
    // vehicule, rh, societe restent identiques
];
$typeDocMap = [
    'cni'             => 'carte_identite',
    'permis'          => 'permis_conduire',
    'assurance_veh'   => 'assurance_vehicule',
    'attestation_veh' => 'vehicule_autre',
    'contrat'         => 'contrat_travail',
    // carte_grise, rib, bulletin_mutuelle, autre… conservent leur nom
];
$rhDocCategorie    = $rubriqueMap[$rubrique] ?? $rubrique;
$rhDocTypeDocument = $typeDocMap[$typeDoc]   ?? $typeDoc;

// Récupérer id_societe / id_agence pour lier le doc à l'user
$uStmt = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ?");
$uStmt->execute([$targetUserId]);
$uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// LOT 4.B : pour la rubrique Société, cible société explicite. Pour rubrique
// Agence, cible agence explicite. Scope check : super admin (role=1) peut
// écrire partout, les autres uniquement sur leur société/agence courante.
$idSocieteCible = (int)($uRow['id_societe'] ?? 0);
$idAgenceCible  = (int)($uRow['id_agence']  ?? 0);

if ($rubrique === 'societe' && isset($_POST['id_societe']) && ctype_digit((string)$_POST['id_societe'])) {
    $idSocPost = (int)$_POST['id_societe'];
    $idSocSession = (int)($_SESSION['id_societe'] ?? 0);
    if ($roleId === 1 || ($idSocSession > 0 && $idSocPost === $idSocSession)) {
        $idSocieteCible = $idSocPost;
    } else {
        echo json_encode(['success' => false, 'message' => 'Société cible non autorisée']);
        exit;
    }
}

if ($rubrique === 'agence' && isset($_POST['id_agence']) && ctype_digit((string)$_POST['id_agence'])) {
    $idAgPost = (int)$_POST['id_agence'];
    $idAgSession = (int)($_SESSION['id_agence'] ?? 0);
    if ($roleId === 1 || ($idAgSession > 0 && $idAgPost === $idAgSession)) {
        $idAgenceCible = $idAgPost;
        // Récupère aussi la société de l'agence cible (pour renseigner id_societe sur le doc)
        try {
            $stA = $pdo->prepare("SELECT id_societe FROM agences WHERE id = ? LIMIT 1");
            $stA->execute([$idAgenceCible]);
            $idSocAg = (int)($stA->fetchColumn() ?: 0);
            if ($idSocAg > 0) $idSocieteCible = $idSocAg;
        } catch (Throwable) {}
    } else {
        echo json_encode(['success' => false, 'message' => 'Agence cible non autorisée']);
        exit;
    }
}

$uRow['id_societe'] = $idSocieteCible ?: null;
$uRow['id_agence']  = $idAgenceCible  ?: null;

try {
    $stmt = $pdo->prepare("INSERT INTO rh_documents
        (id_user, id_societe, id_agence, categorie, sous_categorie, type_document,
         label, filename, original_name, file_path, version, upload_date, uploaded_by,
         actif, obligatoire)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, 1, ?)");
    $stmt->execute([
        $targetUserId,
        $uRow['id_societe'] ?? null,
        $uRow['id_agence']  ?? null,
        $rhDocCategorie,
        $rhDocTypeDocument,      // sous_categorie (alias de type_document dans rh_profil.php)
        $rhDocTypeDocument,
        $nomAffiche ?: ($file['name'] ?? $rhDocTypeDocument),
        $newFileName,
        $file['name'],
        $filePath,               // chemin absolu
        $currentUserId,
        $obligatoire,
    ]);
    $newId = (int)$pdo->lastInsertId();

    // ─────────────────────────────────────────────────────────────
    // Analyse IA automatique via rh_document_extractor (Phase 2)
    // Mapping rh_doc_types.type_key → type supporté par le router
    // ─────────────────────────────────────────────────────────────
    $rhdxTypeMap = [
        'cni'                => 'cni',
        'rib'                => 'rib',
        'carte_grise'        => 'carte_grise',
        'permis'             => 'permis',
        'assurance_veh'      => 'assurance_vehicule',
        'bulletin_mutuelle'  => 'mutuelle',
        'carte_vitale'       => 'carte_vitale',
        'justif_domicile'    => 'justif_domicile',
        'mutuelle'           => 'mutuelle',
        'titre_sejour'       => 'titre_sejour',
        'passeport'          => 'passeport',
        // Types non-mappés (contrat, diplome, photo, ct, kbis, rcp…) :
        // stockage brut sans analyse IA
    ];
    $mappedType = $rhdxTypeMap[$typeDoc] ?? null;

    $rhdxResult = null;
    $rhdxApply  = null;
    if ($mappedType !== null) {
        try {
            $rhdxResult = rhExtractDocument($filePath, $realMime, $mappedType);
            if (!empty($rhdxResult['success']) && !empty($rhdxResult['fields'])) {
                $rhdxApply = rhApplyExtractedToProfile(
                    $pdo,
                    $targetUserId,
                    $mappedType,
                    $rhdxResult['fields']
                );
            }
        } catch (Throwable $rhdxEx) {
            error_log('[rh_doc_upload/rhdx] ' . $rhdxEx->getMessage());
            $rhdxResult = ['success' => false, 'error' => $rhdxEx->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Hook OCR Sonnet pour les docs OFFICIELS Société (LOT 4.B refonte)
    // Types : kbis, carte_pro, garant_financier, rcp, bareme_honoraires
    // → OCR Claude Sonnet → UPDATE rh_documents.{numero,emetteur,date_validite,...}
    // → Réplication vers TOUTES les agences de cette société (carte_pro_*, kbis_*, ...)
    // ─────────────────────────────────────────────────────────────
    $societeHook = null;
    if ($rubrique === 'societe') {
        try {
            $societeHook = rh_doc_societe_hook_apres_upload($pdo, $newId);
        } catch (Throwable $shEx) {
            error_log('[rh_doc_upload/societe_hook] ' . $shEx->getMessage());
            $societeHook = ['ok' => false, 'ocr_erreur' => $shEx->getMessage()];
        }
    }

    echo json_encode([
        'success' => true,
        'id'      => $newId,
        'message' => 'Document uploadé',
        'rhdx'    => [
            'type'        => $rhdxResult['doc_type']    ?? null,
            'confidence'  => $rhdxResult['confidence']  ?? 0,
            'engine'      => $rhdxResult['engine']      ?? null,
            'fields'      => $rhdxResult['fields']      ?? [],
            'validations' => $rhdxResult['validations'] ?? [],
            'applied'     => $rhdxApply['applied']      ?? [],
            'candidates'  => $rhdxApply['candidates']   ?? [],
            'error'       => $rhdxResult['error']       ?? null,
        ],
        'societe' => $societeHook, // null si pas un doc société, sinon résultat hook
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    @unlink($filePath);
    error_log('rh_doc_upload error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur base de données']);
}
