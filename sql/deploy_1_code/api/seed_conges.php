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

try {
    // Get users
    $users = $pdo->query("SELECT id FROM users WHERE actif = 1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo json_encode(['success' => false, 'message' => 'Aucun utilisateur actif trouvé']);
        exit;
    }

    $motifs = ['conges_payes', 'rtt', 'maladie_justifiee_non_deduite', 'absence_justifiee_deduite_heures', 'autre_legal_non_deduit'];
    $statuts = ['validé', 'en_attente', 'validé'];

    $inserted = 0;
    $year = 2026;
    $month = 3; // Mars

    // Ajouter des congés de test
    foreach ($users as $idx => $user) {
        $userId = $user['id'];

        // 3 congés par utilisateur
        for ($i = 0; $i < 3; $i++) {
            $startDay = 1 + ($i * 8);
            $endDay = $startDay + 4;

            $dateDebut = sprintf('%04d-%02d-%02d', $year, $month, $startDay);
            $dateFin = sprintf('%04d-%02d-%02d', $year, $month, min($endDay, 30));

            $motif = $motifs[$idx % count($motifs)];
            $statut = $statuts[$i % count($statuts)];

            $stmt = $pdo->prepare("
                INSERT INTO conges (id_user, date_demande, date_debut, date_fin, motif, statut, demi_journee_debut, demi_journee_fin)
                VALUES (?, NOW(), ?, ?, ?, ?, 'non', 'non')
            ");
            $stmt->execute([$userId, $dateDebut, $dateFin, $motif, $statut]);
            $inserted++;
        }

        // Ajouter solde annuel
        $stmt = $pdo->prepare("
            INSERT INTO conges_soldes (id_user, annee, jours_acquis, jours_pris)
            VALUES (?, ?, 25.0, ?) ON DUPLICATE KEY UPDATE jours_pris = ?
        ");
        $daysTaken = 5 + ($idx * 2);
        $stmt->execute([$userId, $year, $daysTaken, $daysTaken]);
    }

    echo json_encode([
        'success' => true,
        'message' => "✅ Données de test ajoutées!",
        'details' => [
            'conges_inseres' => $inserted,
            'utilisateurs' => count($users),
            'soldes_ajoutes' => count($users)
        ]
    ]);

} catch (Exception $e) {
    error_log("Seed error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    exit;
}
?>
