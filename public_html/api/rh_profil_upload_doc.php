<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ik_carte_grise.php';
require_once __DIR__ . '/../inc/rh_document_extractor.php';
require_login();
verify_csrf_any();
header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$meId      = current_user_id();
$roleId    = current_role_id();
$targetId  = isset($_POST['user_id']) ? (int)$_POST['user_id'] : $meId;
$typeDoc   = trim($_POST['categorie'] ?? '');
$label     = trim($_POST['label'] ?? $typeDoc);

// Sécurité
if ($targetId !== $meId && $roleId > 2) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Accès refusé']); exit;
}

$allowedTypes = ['carte_grise','assurance_vehicule','permis_conduire','carte_identite','carte_secu','passeport','rib','contrat_travail','avenant','vehicule_autre','autre'];
if (!in_array($typeDoc, $allowedTypes, true)) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Type invalide']); exit;
}

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Fichier manquant']); exit;
}

$file = $_FILES['fichier'];
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success'=>false,'error'=>'Fichier trop volumineux (max 10 Mo)']); exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) {
    echo json_encode(['success'=>false,'error'=>'Format non autorisé (PDF, JPG, PNG)']); exit;
}

// Déterminer catégorie rh_documents
$catMap = [
    'carte_grise'        => 'vehicule',
    'assurance_vehicule' => 'vehicule',
    'permis_conduire'    => 'vehicule',
    'vehicule_autre'     => 'vehicule',
    'carte_identite'     => 'identite',
    'carte_secu'         => 'identite',
    'passeport'          => 'identite',
    'rib'                => 'rh',
    'contrat_travail'    => 'rh',
    'avenant'            => 'rh',
    'autre'              => 'autre',
];
$categorie = $catMap[$typeDoc] ?? 'autre';

// Répertoire de stockage — même chemin que rh_documents existant
$uploadDir = __DIR__ . '/../uploads/rh_docs/' . $targetId . '/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext        = $mime === 'application/pdf' ? 'pdf' : str_replace('image/', '', $mime);
$nomFichier = 'rhdoc_' . $targetId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest       = $uploadDir . $nomFichier;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500); echo json_encode(['success'=>false,'error'=>'Erreur enregistrement']); exit;
}

// Récupérer id_societe et id_agence de l'utilisateur
$uStmt = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ?");
$uStmt->execute([$targetId]);
$uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$pdo->prepare("INSERT INTO rh_documents
    (id_user, id_societe, id_agence, categorie, type_document, label, filename, original_name, file_path, version, upload_date, uploaded_by, actif)
    VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),?,1)")
->execute([
    $targetId,
    $uRow['id_societe'] ?? null,
    $uRow['id_agence']  ?? null,
    $categorie,
    $typeDoc,
    $label ?: $typeDoc,
    $nomFichier,
    $file['name'],
    $dest,
    $meId,
]);

$docId = (int)$pdo->lastInsertId();
$url   = './uploads/rh_docs/' . $targetId . '/' . $nomFichier;

$analysis       = null;
$rhdxResult     = null;
$rhdxApply      = null;

if ($typeDoc === 'carte_grise') {
    // Pipeline historique carte grise (ik_carte_grise.php) — déjà opérationnel
    $analysis = rh_analyse_carte_grise($pdo, $targetId, $dest, $mime);
} else {
    // ─────────────────────────────────────────────────────────────
    // Pipeline moderne rh_document_extractor (tous autres types)
    // Mapping typeDoc « profil » → type supporté par le router
    // ─────────────────────────────────────────────────────────────
    $rhdxTypeMap = [
        'permis_conduire'    => 'permis',
        'carte_identite'     => 'cni',
        'passeport'          => 'passeport',   // round 2
        'carte_secu'         => 'carte_vitale',
        'rib'                => 'rib',
        'assurance_vehicule' => 'assurance_vehicule', // round 2
        // 'contrat_travail', 'avenant', 'autre', 'vehicule_autre' : non supportés (pas de mapping)
    ];

    $mappedType = $rhdxTypeMap[$typeDoc] ?? null;

    if ($mappedType !== null) {
        try {
            // 1. Extraction (détection forcée via le hint — on connaît le type)
            $rhdxResult = rhExtractDocument($dest, $mime, $mappedType);

            // 2. Application au profil si succès (sans écrasement)
            if (!empty($rhdxResult['success']) && !empty($rhdxResult['fields'])) {
                $rhdxApply = rhApplyExtractedToProfile(
                    $pdo,
                    $targetId,
                    $mappedType,
                    $rhdxResult['fields']
                );
            }
        } catch (Throwable $rhdxEx) {
            error_log('[rh_profil_upload_doc/rhdx] ' . $rhdxEx->getMessage());
            $rhdxResult = ['success' => false, 'error' => $rhdxEx->getMessage()];
        }
    }
}

echo json_encode([
    'success'      => true,
    'id'           => $docId,
    'nom_original' => htmlspecialchars($file['name']),
    'taille'       => $file['size'],
    'url'          => $url,
    'date'         => date('d/m/Y'),
    'analysis'     => $analysis,     // carte grise (pipeline historique)
    'rhdx'         => [              // pipeline moderne (autres types)
        'type'        => $rhdxResult['doc_type']    ?? null,
        'confidence'  => $rhdxResult['confidence']  ?? 0,
        'engine'      => $rhdxResult['engine']      ?? null,
        'fields'      => $rhdxResult['fields']      ?? [],
        'validations' => $rhdxResult['validations'] ?? [],
        'applied'     => $rhdxApply['applied']      ?? [],
        'candidates'  => $rhdxApply['candidates']   ?? [],
        'error'       => $rhdxResult['error']       ?? null,
    ],
]);