<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

// ── Paramètres GET ────────────────────────────────────────────────────────────
$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ts          = isset($_GET['ts']) ? (int)$_GET['ts'] : 0;

if ($entretienId <= 0) {
    http_response_code(400);
    echo json_encode(['updated' => false, 'error' => 'id manquant']);
    exit;
}

// ── Charger l'entretien ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, manager_id, collaborateur_id, statut,
               rubrique_en_cours, updated_at
        FROM rh_entretiens
        WHERE id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['updated' => false, 'error' => 'Erreur BDD']);
    exit;
}

if (!$entretien) {
    http_response_code(404);
    echo json_encode(['updated' => false, 'error' => 'Entretien introuvable']);
    exit;
}

// ── Vérifier accès ────────────────────────────────────────────────────────────
$isAdmin       = ($roleId === 1);
$isManager     = ((int)$entretien['manager_id']      === $userId);
$isCollab      = ((int)$entretien['collaborateur_id'] === $userId);

if (!$isAdmin && !$isManager && !$isCollab) {
    http_response_code(403);
    echo json_encode(['updated' => false, 'error' => 'Accès refusé']);
    exit;
}

// Déterminer le rôle effectif de l'appelant
$callerIsManager = ($isAdmin || $isManager);

// ── Timestamp actuel de l'entretien ───────────────────────────────────────────
$nowTs       = time();
$updatedAtTs = strtotime($entretien['updated_at'] ?? '') ?: 0;
$tsDateTime  = date('Y-m-d H:i:s', $ts);

// ── Définitions des rubriques ─────────────────────────────────────────────────
$rubriquesNom = [
    1  => 'Ouverture',
    2  => 'Bilan collaborateur',
    3  => 'Valorisation',
    4  => 'Performance',
    5  => 'Comportement',
    6  => 'Motivation',
    7  => 'Adaptabilité',
    8  => 'Compétences',
    9  => 'Analyse RH',
    10 => 'Plan d\'action',
    11 => 'Synthèse',
];

$rubriqueCourante = (int)($entretien['rubrique_en_cours'] ?? 1) ?: 1;
$rubriqueCouranteNom = $rubriquesNom[$rubriqueCourante] ?? '';

// ── Réponses modifiées depuis ts ──────────────────────────────────────────────
$reponses = [];
try {
    if ($callerIsManager) {
        // Manager voit tout
        $sqlR = "
            SELECT critere_id, rubrique_id, texte_final, note,
                   visible_collaborateur, validee_collaborateur,
                   desaccord_collaborateur, remarque_collaborateur,
                   commentaire, saved_at
            FROM rh_entretien_reponses
            WHERE entretien_id = ?
              AND saved_at > ?
            ORDER BY rubrique_id, critere_id
        ";
    } else {
        // Collaborateur : exclure rubrique 9, exclure non-visible
        $sqlR = "
            SELECT critere_id, rubrique_id, texte_final,
                   visible_collaborateur, validee_collaborateur,
                   desaccord_collaborateur, remarque_collaborateur,
                   saved_at
            FROM rh_entretien_reponses
            WHERE entretien_id = ?
              AND saved_at > ?
              AND rubrique_id != 9
              AND visible_collaborateur = 1
            ORDER BY rubrique_id, critere_id
        ";
    }
    $stmtR = $pdo->prepare($sqlR);
    $stmtR->execute([$entretienId, $tsDateTime]);
    $rawReponses = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawReponses as $r) {
        $item = [
            'critere_id'              => (int)$r['critere_id'],
            'rubrique_id'             => (int)$r['rubrique_id'],
            'texte_final'             => $r['texte_final'],
            'visible_collaborateur'   => (int)$r['visible_collaborateur'],
            'validee_collaborateur'   => (int)$r['validee_collaborateur'],
            'desaccord_collaborateur' => (int)$r['desaccord_collaborateur'],
            'remarque_collaborateur'  => $r['remarque_collaborateur'],
        ];
        if ($callerIsManager) {
            $item['note']       = isset($r['note']) ? (int)$r['note'] : null;
            $item['commentaire'] = $r['commentaire'] ?? null;
        }
        $reponses[] = $item;
    }
} catch (PDOException $e) {
    // Table inexistante ou autre : réponses vides
    $reponses = [];
}

// ── Actions modifiées depuis ts ───────────────────────────────────────────────
$actions = [];
try {
    if ($callerIsManager) {
        $sqlA = "
            SELECT a.id, a.libelle, CONCAT(u.prenom, ' ', u.nom) AS responsable, a.echeance, a.statut, a.visible_collaborateur, a.updated_at
            FROM rh_entretien_actions a LEFT JOIN users u ON u.id = a.responsable_id
            WHERE a.entretien_id = ?
              AND a.updated_at > ?
            ORDER BY a.id
        ";
    } else {
        $sqlA = "
            SELECT a.id, a.libelle, CONCAT(u.prenom, ' ', u.nom) AS responsable, a.echeance, a.statut, a.updated_at
            FROM rh_entretien_actions a LEFT JOIN users u ON u.id = a.responsable_id
            WHERE a.entretien_id = ?
              AND a.updated_at > ?
              AND a.visible_collaborateur = 1
            ORDER BY a.id
        ";
    }
    $stmtA = $pdo->prepare($sqlA);
    $stmtA->execute([$entretienId, $tsDateTime]);
    foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $item = [
            'id'          => (int)$a['id'],
            'libelle'     => $a['libelle'],
            'responsable' => $a['responsable'] ?? null,
            'echeance'    => $a['echeance'] ?? null,
            'statut'      => $a['statut'] ?? null,
        ];
        if ($callerIsManager) {
            $item['visible_collaborateur'] = (int)($a['visible_collaborateur'] ?? 0);
        }
        $actions[] = $item;
    }
} catch (PDOException $e) {
    $actions = [];
}

// ── Alertes et scores (manager seulement) ────────────────────────────────────
$alertes = null;
$scores  = null;
if ($callerIsManager) {
    // Alertes
    try {
        $stmtAl = $pdo->prepare("
            SELECT id, type_alerte AS type, message, niveau, created_at
            FROM rh_entretien_alertes
            WHERE entretien_id = ?
              AND created_at > ?
            ORDER BY created_at DESC
        ");
        $stmtAl->execute([$entretienId, $tsDateTime]);
        $alertes = $stmtAl->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $alertes = [];
    }

    // Scores globaux (pas filtrés par ts – toujours les derniers)
    try {
        $stmtSc = $pdo->prepare("
            SELECT axe, score
            FROM rh_entretien_scores
            WHERE entretien_id = ?
            ORDER BY axe
        ");
        $stmtSc->execute([$entretienId]);
        $rawScores = $stmtSc->fetchAll(PDO::FETCH_ASSOC);
        $scores = [];
        foreach ($rawScores as $s) {
            $scores[$s['axe']] = (float)$s['score'];
        }
    } catch (PDOException $e) {
        $scores = [];
    }
}

// ── Décider si updated ───────────────────────────────────────────────────────
$hasNew = !empty($reponses) || !empty($actions)
       || ($callerIsManager && (!empty($alertes) || $updatedAtTs > $ts));

if (!$hasNew) {
    echo json_encode(['updated' => false, 'timestamp' => $nowTs]);
    exit;
}

// ── Réponse complète ─────────────────────────────────────────────────────────
$output = [
    'updated'          => true,
    'timestamp'        => $nowTs,
    'rubrique_en_cours' => $rubriqueCourante,
    'rubrique_nom'     => $rubriqueCouranteNom,
    'reponses'         => $reponses,
    'actions'          => $actions,
];

if ($callerIsManager) {
    $output['alertes'] = $alertes;
    $output['scores']  = $scores;
}

echo json_encode($output, JSON_UNESCAPED_UNICODE);
