<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * ⚠ SUPPRESSION RÉELLE DE BIENS AVEC CASCADE — ADMIN ONLY
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 2 modes :
 *   - action=preview  → retourne la liste des biens candidats + compte
 *                       par table liée, SANS rien supprimer.
 *   - action=delete   → exécute la suppression définitive en transaction.
 *                       Nécessite d'avoir d'abord lu une préview (token en
 *                       session qui expire après 10 min).
 *
 * Sécurités :
 *   - role_id = 1 obligatoire (vérifié serveur)
 *   - CSRF token
 *   - Preview obligatoire avant delete (token serveur)
 *   - Transaction atomique : rollback complet en cas d'erreur
 *   - Filtre par id_societe (ne supprime jamais un bien d'une autre société)
 *   - Log détaillé (error_log) de chaque suppression
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo     = $GLOBALS['pdo'];
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$userId  = (int)($_SESSION['user_id'] ?? 0);
$socId   = (int)($_SESSION['id_societe'] ?? 0);

if ($roleId !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Accès réservé aux super-admins (role_id=1)']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('bien_delete_cascade');

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['preview', 'delete'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'Action inconnue']));
}

// ─── Filtres du scope ───
$statuts = (array)($_POST['statuts'] ?? ['brouillon', 'actif']);
$statuts = array_values(array_filter(array_map('trim', $statuts), fn($s) => in_array($s, ['brouillon','actif','archive','vendu','loue','suspendu'], true)));
if (empty($statuts)) $statuts = ['brouillon', 'actif'];

$dateMin = (string)($_POST['date_min'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $dateMin)) {
    // Par défaut : hier 00:00
    $dateMin = date('Y-m-d 00:00:00', strtotime('yesterday'));
}
// Normalise au format MySQL datetime
$dateMin = str_replace('T', ' ', $dateMin);
if (strlen($dateMin) === 10) $dateMin .= ' 00:00:00';
elseif (strlen($dateMin) === 16) $dateMin .= ':00';

// Périmètre : mêmes biens que ceux affichés sur bien_liste → filtre id_societe
// (Si le super-admin a id_societe=0/null, on laisse voir toutes les sociétés —
// car un super-admin est global ; sinon on restreint à sa société.)
$restrictSociete = $socId > 0;

// ─── Liste des biens candidats ───
$sqlCandidates = "
    SELECT b.id, b.reference_bien, b.statut_bien, b.date_creation, b.adresse_1, b.ville, b.id_societe
    FROM biens b
    WHERE b.statut_bien IN (" . implode(',', array_fill(0, count($statuts), '?')) . ")
      AND b.date_creation >= ?
      " . ($restrictSociete ? 'AND b.id_societe = ?' : '') . "
    ORDER BY b.date_creation DESC
    LIMIT 500
";
$params = array_merge($statuts, [$dateMin]);
if ($restrictSociete) $params[] = $socId;

$stmt = $pdo->prepare($sqlCandidates);
$stmt->execute($params);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ids = array_map('intval', array_column($candidates, 'id'));

// ─── Liste des tables filles (id_bien) ───
// Ordre : enfants indirects (via annonces) → enfants directs → biens
$childTables = [
    'annonces', 'baux', 'bien_baux', 'bailleur_acte',
    'bien_dependances_exterieurs', 'bien_energies', 'bien_types_chauffage', 'bien_vues',
    'biens_caracteristiques', 'biens_documents', 'biens_photos', 'biens_tags', 'biens_versions',
    'crg_ecritures', 'crg_situations_locataires', 'documents', 'dpe_diags',
    'estimations', 'leads_annonces', 'mandats', 'medias', 'programmes_lots',
    'url_redirects', 'visites', 'visites_zones',
];

// ─── Compte par table (preview) ───
$countByTable = [];
if (!empty($ids)) {
    $inIds = implode(',', $ids);
    foreach ($childTables as $tbl) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}` WHERE id_bien IN ({$inIds})")->fetchColumn();
            if ($n > 0) $countByTable[$tbl] = $n;
        } catch (Throwable $e) {
            // Table inexistante sur cette instance → ignoré
        }
    }
    // Cas particulier : annonces_photos (FK id_annonce)
    try {
        $n = (int)$pdo->query("
            SELECT COUNT(*) FROM annonces_photos ap
            JOIN annonces a ON a.id = ap.id_annonce
            WHERE a.id_bien IN ({$inIds})
        ")->fetchColumn();
        if ($n > 0) $countByTable['annonces_photos'] = $n;
    } catch (Throwable) {}
}

// ═══════════════════════════════════════════════════════════════════════
// MODE PREVIEW
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'preview') {
    // Génère un token serveur stocké en session — expire dans 10 min.
    // Le token encode le hash des IDs candidats : si le scope change, il devient invalide.
    $scopeHash = hash('sha256', implode(',', $ids) . '|' . implode(',', $statuts) . '|' . $dateMin);
    $_SESSION['bien_delete_cascade_token'] = [
        'hash'    => $scopeHash,
        'expires' => time() + 600,
        'count'   => count($ids),
    ];
    exit(json_encode([
        'ok'             => true,
        'mode'           => 'preview',
        'count'          => count($ids),
        'candidates'     => $candidates,
        'count_by_table' => $countByTable,
        'scope'          => ['statuts' => $statuts, 'date_min' => $dateMin, 'id_societe' => $restrictSociete ? $socId : null],
        'token'          => $scopeHash,
    ], JSON_UNESCAPED_UNICODE));
}

// ═══════════════════════════════════════════════════════════════════════
// MODE DELETE
// ═══════════════════════════════════════════════════════════════════════
if (empty($ids)) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun bien dans le scope — préview d\'abord']));
}

// Vérifie le token preview
$submittedToken = (string)($_POST['token'] ?? '');
$stored = $_SESSION['bien_delete_cascade_token'] ?? null;
if (!is_array($stored) || empty($stored['hash']) || $stored['expires'] < time()) {
    exit(json_encode(['ok' => false, 'error' => 'Token de préview absent ou expiré — re-clique sur "Prévisualiser"']));
}
$expectedHash = hash('sha256', implode(',', $ids) . '|' . implode(',', $statuts) . '|' . $dateMin);
if (!hash_equals($expectedHash, $submittedToken) || !hash_equals($stored['hash'], $submittedToken)) {
    exit(json_encode(['ok' => false, 'error' => 'Scope modifié depuis la préview — refais une préview']));
}

// ─── Exécution en transaction ───
$deleted = [];
$inIds = implode(',', $ids);

try {
    $pdo->beginTransaction();

    // 1. Enfants d'annonces (avant de supprimer annonces)
    try {
        $n = $pdo->exec("
            DELETE ap FROM annonces_photos ap
            JOIN annonces a ON a.id = ap.id_annonce
            WHERE a.id_bien IN ({$inIds})
        ");
        if ($n > 0) $deleted['annonces_photos'] = $n;
    } catch (Throwable $e) { error_log('[bien_delete_cascade] annonces_photos: ' . $e->getMessage()); }

    // 2. Toutes les tables filles directes
    foreach ($childTables as $tbl) {
        try {
            $n = $pdo->exec("DELETE FROM `{$tbl}` WHERE id_bien IN ({$inIds})");
            if ($n > 0) $deleted[$tbl] = $n;
        } catch (Throwable $e) {
            error_log("[bien_delete_cascade] {$tbl}: " . $e->getMessage());
        }
    }

    // 3. La table biens elle-même (double-filtre de sécurité : statut + société)
    $sqlFinal = "
        DELETE FROM biens
        WHERE id IN ({$inIds})
          AND statut_bien IN (" . implode(',', array_fill(0, count($statuts), '?')) . ")
          " . ($restrictSociete ? 'AND id_societe = ?' : '');
    $finalParams = $statuts;
    if ($restrictSociete) $finalParams[] = $socId;

    $stmtFinal = $pdo->prepare($sqlFinal);
    $stmtFinal->execute($finalParams);
    $deleted['biens'] = $stmtFinal->rowCount();

    $pdo->commit();

    // Invalide le token pour éviter une ré-exécution
    unset($_SESSION['bien_delete_cascade_token']);

    error_log(sprintf(
        '[bien_delete_cascade] OK user=%d societe=%d statuts=%s date_min=%s deleted=%s',
        $userId, $socId, implode('+', $statuts), $dateMin, json_encode($deleted)
    ));

    exit(json_encode([
        'ok'           => true,
        'mode'         => 'delete',
        'deleted'      => $deleted,
        'total_biens'  => $deleted['biens'] ?? 0,
        'scope'        => ['statuts' => $statuts, 'date_min' => $dateMin],
    ], JSON_UNESCAPED_UNICODE));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[bien_delete_cascade] ROLLBACK : ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode([
        'ok'    => false,
        'error' => 'Échec transaction — rollback complet : ' . $e->getMessage(),
    ]));
}
