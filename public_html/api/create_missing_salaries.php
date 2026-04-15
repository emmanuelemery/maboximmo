<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/rh_helpers.php';
require_once __DIR__ . '/../inc/auth.php';
verify_csrf_any();
require_once __DIR__ . '/../inc/mailer.php';

$roleId      = current_role_id();
$userId      = current_user_id();
$agenceScope = can_manage_salaires_agence(); // >0 si gestionnaire agence

$data        = json_decode(file_get_contents('php://input'), true);
$single_user = isset($data['id_user']) ? (int)$data['id_user'] : 0;

// Autorisation : admin, gestionnaire agence, ou user créant son propre salaire
$isSelf = ($single_user > 0 && $single_user === $userId);
if ($roleId !== 1 && $agenceScope === 0 && !$isSelf) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur base de données']);
    exit;
}

if (!isset($data['mois']) || !isset($data['annee'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
    exit;
}

$mois   = (int)$data['mois'];
$annee  = (int)$data['annee'];
$notify = !empty($data['notify']);

if ($mois < 1 || $mois > 12) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Mois invalide']);
    exit;
}

$mois_ref = sprintf('%04d-%02d-01', $annee, $mois);

$monthClosed = rh_is_salary_month_closed($pdo, sprintf('%04d-%02d', $annee, $mois));
if ($monthClosed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Mois cloture']);
    exit;
}

// Scope agence : si gestionnaire agence, utiliser son agence ; si passé par le JS, vérifier cohérence
$filterAgence = 0;
if ($agenceScope > 0) {
    $filterAgence = $agenceScope; // toujours la valeur serveur, jamais la valeur client
}

try {
    // Get all active users who don't have a salary for this month
    $agenceFilter = $filterAgence > 0 ? "AND u.id_agence = :agence_id" : "";
    $singleFilter = $single_user > 0 ? "AND u.id = :single_user" : "";
    $stmt = $pdo->prepare("
        SELECT u.id, u.prenom, u.nom, u.email, u.id_legacy
        FROM users u
        WHERE u.actif = 1
        $agenceFilter
        $singleFilter
        AND NOT EXISTS (
            SELECT 1 FROM salaires s
            WHERE (s.id_user = u.id OR (u.id_legacy IS NOT NULL AND s.id_user = u.id_legacy))
            AND s.mois_reference = :mr
            AND (s.salaire_modele IS NULL OR s.salaire_modele = 0)
        )
        ORDER BY u.prenom, u.nom
    ");
    $params = [':mr' => $mois_ref];
    if ($filterAgence > 0) $params[':agence_id'] = $filterAgence;
    if ($single_user > 0)  $params[':single_user'] = $single_user;
    $stmt->execute($params);
    $users_without_salary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Champs du modèle à copier dans le nouveau salaire
    $model_fields = [
        'salaire_brut_base', 'treizieme_mois', 'anciennete',
        'avantage_nature', 'heures_supp', 'commission_ca', 'commission_ca_nouvelles_affaires',
        'ik_nb_km', 'ik_montant', 'total_ik', 'vehicule_utilise', 'vehicule_nom',
        'vehicule_puissance_fiscale', 'prix_km',
        'remboursement_achat', 'frais_professionnels', 'frais_reception',
        'prime_admin', 'prime_exceptionnelle', 'stationnement', 'frais_deplacement',
        'numero_securite_sociale',
    ];

    $model_stmt = $pdo->prepare("SELECT * FROM salaires WHERE id_user = ? AND salaire_modele = 1 AND mois_reference = '0000-00-00' LIMIT 1");

    // Create salary records for all these users
    $created_count = 0;

    foreach ($users_without_salary as $user) {
        // Get the correct id_user for salaires table
        $id_user = !empty($user['id_legacy']) ? (int)$user['id_legacy'] : (int)$user['id'];

        // Load model for this user
        $model_stmt->execute([$id_user]);
        $model = $model_stmt->fetch(PDO::FETCH_ASSOC);

        // Build INSERT from model fields
        $cols   = ['id_user', 'mois_reference', 'date_entree'];
        $vals   = [':id_user', ':mois_ref', 'NOW()'];
        $params = [':id_user' => $id_user, ':mois_ref' => $mois_ref];

        if ($model) {
            foreach ($model_fields as $f) {
                if (isset($model[$f]) && $model[$f] !== null && $model[$f] !== '') {
                    $cols[]         = $f;
                    $vals[]         = ":$f";
                    $params[":$f"]  = $model[$f];
                }
            }
        }

        $sql = "INSERT INTO salaires (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $pdo->prepare($sql)->execute($params);
        $newSalId = (int)$pdo->lastInsertId();

        // ── Intégrer les KM IK clôturés pour ce mois ──────────────────────────
        // On cherche dans rh_ik_sessions les sessions clôturées (mois_paie_bloque=1)
        // dont mois_paie = ce mois, pour cet utilisateur (id_user dans users, pas id_legacy)
        $origUserId = (int)$user['id'];
        $stmtIkKm = $pdo->prepare("
            SELECT COALESCE(SUM(l.km_aller),0) + COALESCE(SUM(l.km_retour),0) AS total_km
            FROM rh_ik_lignes l
            JOIN rh_ik_sessions s ON s.id = l.id_session
            WHERE s.id_user = ?
              AND s.mois_paie = ?
              AND s.mois_paie_bloque = 1
        ");
        $moisPaieStr = sprintf('%04d-%02d', $annee, $mois);
        $stmtIkKm->execute([$origUserId, $moisPaieStr]);
        $ikKm = round((float)$stmtIkKm->fetchColumn(), 1);
        if ($ikKm > 0) {
            $pdo->prepare("UPDATE salaires SET ik_nb_km = ? WHERE id = ?")
                ->execute([$ikKm, $newSalId]);
        }
        // ──────────────────────────────────────────────────────────────────────

        $created_count++;
    }

    // Send emails with closure notification (only if notify=true)
    if (!$notify) {
        echo json_encode([
            'success' => true,
            'message' => "Salaires créés : $created_count (aucun email envoyé)"
        ]);
        exit;
    }

    $mois_names = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'
    ];
    $mois_name = $mois_names[$mois] ?? '';

    $mails_sent = 0;
    foreach ($users_without_salary as $user) {
        $nom_complet = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        $subject = "MaBoxImmo - Clôture des salaires de $mois_name $annee dans 24h";

        $htmlMessage = <<<HTML
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .header { background-color: #2d5016; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; line-height: 1.6; }
        .warning { background-color: #fff3cd; padding: 15px; border-left: 4px solid #ff6b6b; margin: 20px 0; color: #d32f2f; font-weight: bold; }
        .footer { background-color: #f5f5f5; padding: 10px; text-align: center; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MaBoxImmo</h1>
        <p>Gestion des salaires</p>
    </div>
    <div class="content">
        <p>Madame, Monsieur <strong>$nom_complet</strong>,</p>
        <p>Veuillez être informé que la clôture des salaires du mois de <strong>$mois_name $annee</strong> aura lieu dans <strong>24 heures</strong>.</p>
        <div class="warning">
            <p>⚠️ <strong>ATTENTION - DÉLAI LIMITE</strong></p>
            <p>Passé ce délai, vous ne pourrez plus :</p>
            <ul>
                <li>Modifier votre fiche salaire</li>
                <li>Ajouter ou modifier vos frais professionnels</li>
                <li>Saisir vos achats divers</li>
                <li>Effectuer toute modification relative au mois de $mois_name $annee</li>
            </ul>
            <p>Nous vous demandons donc de compléter et de valider votre fiche salaire au plus vite.</p>
        </div>
        <p>Connectez-vous à votre espace MaBoxImmo pour procéder à cette saisie ou mettre à jour votre dossier.</p>
        <p>Si vous rencontrez des difficultés, n'hésitez pas à contacter l'équipe RH.</p>
        <p><strong>Cordialement,</strong><br>L'équipe MaBoxImmo</p>
    </div>
    <div class="footer">
        <p>Email automatisé - Veuillez ne pas répondre à cet email</p>
    </div>
</body>
</html>
HTML;

        if (send_mail($user['email'], $subject, $htmlMessage, [], true)) {
            $mails_sent++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Salaires créés : $created_count | Emails envoyés : $mails_sent"
    ]);
} catch (Exception $e) {
    error_log("Error creating missing salaries: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    exit;
}
?>

