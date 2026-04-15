<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PDO non disponible']);
    exit;
}

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin only']);
    exit;
}

// Mapping de type_conge vers motif
$motifMapping = [
    'CP' => 'conges_payes',
    'RTT' => 'rtt',
    'Maladie' => 'maladie_justifiee_deduite',
    'Absent' => 'absence_justifiee_deduite_heures',
    'Autre' => 'autre_legal_non_deduit'
];

// Mapping de statut
$statutMapping = [
    'En attente' => 'en_attente',
    'Validé' => 'validé',
    'Refusé' => 'refusé'
];

try {
    // Récupérer les données du fichier SQL
    $sqlFile = __DIR__ . '/../../conges_data.sql';

    if (!file_exists($sqlFile)) {
        echo json_encode(['success' => false, 'message' => 'Fichier SQL non trouvé']);
        exit;
    }

    $sql = file_get_contents($sqlFile);

    // Parse les INSERT statements
    if (preg_match('/INSERT INTO `conges`.*?VALUES\s*(.+?)(?=;|\n\nALTER)/s', $sql, $matches)) {
        $valuesStr = $matches[1];

        // Parse chaque ligne de données
        preg_match_all('/\(([^)]+)\)/s', $valuesStr, $rows);

        $imported = 0;
        $errors = [];

        foreach ($rows[1] as $rowStr) {
            // Parse les valeurs
            $values = str_getcsv($rowStr, ',', "'");

            if (count($values) >= 10) {
                $id = (int)trim($values[0]);
                $id_user = (int)trim($values[1]);
                $date_debut = trim($values[2], "'\"");
                $date_fin = trim($values[3], "'\"");
                $type_conge = trim($values[4], "'\"");
                $statut = trim($values[5], "'\"");
                $date_soumission = trim($values[6], "'\"");
                $commentaire = trim($values[7], "'\"");
                $valide_par = !empty(trim($values[8])) ? (int)trim($values[8]) : null;
                $date_validation = trim($values[9], "'\"");

                // Mapper les valeurs
                $motif = $motifMapping[$type_conge] ?? 'conges_payes';
                $new_statut = $statutMapping[$statut] ?? 'en_attente';
                $id_validateur = $valide_par;

                // Insérer dans la nouvelle table
                $stmt = $pdo->prepare("
                    INSERT INTO conges (
                        id, id_user, date_demande, date_debut, date_fin,
                        motif, statut, id_validateur, commentaire, date_validation,
                        demi_journee_debut, demi_journee_fin
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'non', 'non')
                    ON DUPLICATE KEY UPDATE
                        id_user = VALUES(id_user),
                        date_demande = VALUES(date_demande),
                        date_debut = VALUES(date_debut),
                        date_fin = VALUES(date_fin),
                        motif = VALUES(motif),
                        statut = VALUES(statut),
                        id_validateur = VALUES(id_validateur),
                        commentaire = VALUES(commentaire),
                        date_validation = VALUES(date_validation)
                ");

                $stmt->execute([
                    $id,
                    $id_user,
                    $date_soumission ?: date('Y-m-d H:i:s'),
                    $date_debut,
                    $date_fin,
                    $motif,
                    $new_statut,
                    $id_validateur,
                    $commentaire,
                    $date_validation ?: null
                ]);

                $imported++;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "✅ Import terminé!",
            'imported' => $imported,
            'errors' => $errors
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Impossible de parser le fichier SQL']);
    }

} catch (Exception $e) {
    error_log("Import error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    exit;
}
?>
