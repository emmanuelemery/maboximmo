<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ik_carte_grise.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$userId        = (int)($_POST['user_id'] ?? 0);
$currentUserId = current_user_id();
$roleId        = current_role_id();
$agenceScope   = can_manage_salaires_agence();
$isShared      = (int)($_POST['is_shared'] ?? 0);
$currentSocieteId = current_societe_id();

// Check access: admin, the user themselves, ou gestionnaire agence pour son agence
if ($roleId !== 1 && $currentUserId !== $userId) {
    if ($agenceScope > 0) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
        $chk->execute([$userId, $agenceScope]);
        if (!$chk->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Utilisateur hors de votre agence']);
            exit;
        }
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
}

// Only admins and managers can mark as shared
if ($isShared && $roleId !== 1 && $roleId !== 2) {
    $isShared = 0;
}

// Verify user exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
$stmt->execute([$userId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Create salaires_documents table if missing
try {
    $pdo->query("CREATE TABLE IF NOT EXISTS salaires_documents (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_salaire INT,
        id_user INT NOT NULL,
        id_societe INT,
        categorie VARCHAR(100),
        sous_categorie VARCHAR(100),
        filename VARCHAR(255),
        original_name VARCHAR(255),
        file_path VARCHAR(500),
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        uploaded_by INT,
        is_shared TINYINT DEFAULT 0,
        mois_document VARCHAR(2),
        annee_document INT,
        INDEX(id_user), INDEX(categorie), INDEX(id_salaire), INDEX(id_societe), INDEX(is_shared)
    )");

    // Add missing columns if they don't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires_documents");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');

    $requiredCols = [
        'sous_categorie' => 'VARCHAR(100)',
        'uploaded_by' => 'INT',
        'mois_document' => 'VARCHAR(2)',
        'annee_document' => 'INT',
        'id_societe' => 'INT',
        'is_shared' => 'TINYINT DEFAULT 0'
    ];

    foreach ($requiredCols as $col => $type) {
        if (!in_array($col, $colNames)) {
            $pdo->exec("ALTER TABLE salaires_documents ADD COLUMN `$col` $type");
        }
    }
} catch (Exception $e) {}

// Handle file uploads
if (!isset($_FILES['files'])) {
    echo json_encode(['success' => false, 'message' => 'No files provided']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/rh_docs/' . $userId . '/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$uploaded = 0;
$errors = [];
$analysisResults = [];

foreach ($_FILES['files']['tmp_name'] as $key => $tmpFile) {
    $fileName = $_FILES['files']['name'][$key];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Validate file extension
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    if (!in_array($fileExt, $allowedExt)) {
        $errors[] = "$fileName: Extension non autorisée";
        continue;
    }

    // Validate real MIME type (prevents renamed .php files)
    $allowedMimes = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $tmpFile);
    finfo_close($finfo);
    if (!in_array($realMime, $allowedMimes)) {
        $errors[] = "$fileName: Type MIME non autorisé ($realMime)";
        continue;
    }

    // Validate file size (max 10MB)
    if ($_FILES['files']['size'][$key] > 10 * 1024 * 1024) {
        $errors[] = "$fileName: Fichier trop volumineux (max 10MB)";
        continue;
    }

    // Generate unique filename
    $newFileName = 'doc_' . time() . '_' . uniqid() . '.' . $fileExt;
    $filePath = $uploadDir . $newFileName;

    if (move_uploaded_file($tmpFile, $filePath)) {
        // Insert into database
        try {
            $categorie = $_POST['categorie'] ?? 'divers';
            $sousCategorie = $_POST['sous_categorie'] ?? null;
            $moisUpload = $_POST['mois_upload'] ?? null;
            $anneeUpload = $_POST['annee_upload'] ?? null;

            $stmt = $pdo->prepare("INSERT INTO salaires_documents (id_user, id_societe, categorie, sous_categorie, filename, original_name, file_path, uploaded_by, is_shared, mois_document, annee_document) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $currentSocieteId,
                $categorie,
                $sousCategorie,
                $newFileName,
                $fileName,
                '/uploads/rh_docs/' . $userId . '/' . $newFileName,
                current_user_id(),
                $isShared,
                $moisUpload,
                $anneeUpload
            ]);
            $uploaded++;

            // Analyse OCR carte grise (PDF/JPG/PNG) si catégorie véhicule
            $isCarteGrise = false;
            if (is_string($categorie) && strtolower($categorie) === 'vehicule') {
                if (is_string($sousCategorie) && preg_match('/carte\s*grise/i', $sousCategorie)) {
                    $isCarteGrise = true;
                } elseif (preg_match('/carte\s*grise|immatricul/i', $fileName)) {
                    $isCarteGrise = true;
                }
            }
            if ($isCarteGrise) {
                $analysis = rh_analyse_carte_grise($pdo, $userId, $filePath, $realMime);
                $analysisResults[] = [
                    'file' => $fileName,
                    'analysis' => $analysis,
                ];
            }
        } catch (Exception $e) {
            @unlink($filePath);
            $errors[] = "$fileName: Erreur base de données";
        }
    } else {
        $errors[] = "$fileName: Erreur lors du téléchargement";
    }
}

echo json_encode([
    'success' => $uploaded > 0,
    'uploaded' => $uploaded,
    'errors' => $errors,
    'analysis' => $analysisResults,
]);
?>