<?php
/**
 * bailleur_scenarios.php — Liste et chargement des scénarios de valorisation (prix de vente).
 *   ?action=list                       → scénarios existants dans le périmètre
 *   ?action=load&scenario=CODE         → { bien_id: montant } pour ce scénario (is_courant)
 * Scénario 'courant' = vérité du jour (diffusée). Les autres (ifi/prix_min/prix_max/snapshot) = internes.
 * Lecture seule. Scope : super admin = tout ; bailleur = ses propriétaires.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit;
}

// Sous-requête de périmètre sur les biens (id du bien autorisé)
if ($isSuperAdmin) {
    $bienScope = "SELECT id FROM biens";
} else {
    $bienScope = "SELECT b.id FROM biens b WHERE
        b.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=".$userId.")
        OR b.id IN (SELECT s.id_bien FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg
                    WHERE t.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=".$userId."))";
}

$action = (string)($_GET['action'] ?? 'list');

if ($action === 'list') {
    $sql = "SELECT scenario_code,
                   MAX(COALESCE(NULLIF(scenario_label,''), scenario_code)) AS label,
                   COUNT(*) AS nb, MAX(date_validation) AS derniere
            FROM bien_prix
            WHERE type_valeur='prix_vente' AND is_courant=1 AND id_bien IN ($bienScope)
            GROUP BY scenario_code ORDER BY (scenario_code='courant') DESC, derniere DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'scenarios'=>$rows], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'load') {
    $scenario = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['scenario'] ?? 'courant'))) ?: 'courant';
    $st = $pdo->prepare("SELECT id_bien, montant FROM bien_prix
        WHERE type_valeur='prix_vente' AND is_courant=1 AND scenario_code=? AND id_bien IN ($bienScope)");
    $st->execute([$scenario]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $map[(int)$r['id_bien']] = (float)$r['montant']; }
    echo json_encode(['ok'=>true, 'scenario'=>$scenario, 'prix'=>$map], JSON_UNESCAPED_UNICODE); exit;
}

echo json_encode(['ok'=>false, 'error'=>'action inconnue']);
