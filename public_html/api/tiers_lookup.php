<?php
// api/tiers_lookup.php — Recherche tiers (autocomplete) + détection de doublons
// Scopé par session : role_id=1 voit tout, sinon filtrage id_societe / id_agence
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$roleId    = (int)current_role_id();
$idSociete = (int)($_SESSION['id_societe'] ?? 0);
$idAgence  = (int)($_SESSION['id_agence'] ?? 0);

$q         = trim((string)($_GET['q'] ?? ''));
$role      = trim((string)($_GET['role'] ?? ''));      // filtre sur un role_code (optionnel)
$type      = trim((string)($_GET['type'] ?? ''));      // personne_physique | personne_morale (optionnel)
$email     = trim((string)($_GET['email'] ?? ''));     // détection doublon par email
$siret     = trim((string)($_GET['siret'] ?? ''));     // détection doublon par siret
$limit     = min(20, max(1, (int)($_GET['limit'] ?? 10)));

if ($q === '' && $email === '' && $siret === '') {
    echo json_encode(['ok' => true, 'items' => [], 'doublons' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// Cloisonnement multi-tenant (hors super admin)
$whereTenant = '';
$paramsTenant = [];
if ($roleId !== 1) {
    // Les tiers backfill n'ont pas id_societe (seulement id_agence) — on tolère NULL
    $whereTenant = " AND (t.id_societe = :societe OR t.id_societe IS NULL)
                     AND (t.id_agence  = :agence  OR t.id_agence  IS NULL) ";
    $paramsTenant = [':societe' => $idSociete, ':agence' => $idAgence];
}

$items = [];
$doublons = [];

try {
    // ── Détection doublons ──
    if ($email !== '' || $siret !== '') {
        $where = ['t.actif = 1'];
        $params = [];
        if ($email !== '') { $where[] = 't.email = :email'; $params[':email'] = $email; }
        if ($siret !== '') { $where[] = 't.siret = :siret'; $params[':siret'] = $siret; }
        $sqlDoublons = "
            SELECT t.id, t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale,
                   t.email, t.telephone, t.siret, t.ville, t.code_postal
            FROM tiers t
            WHERE (" . implode(' OR ', $where) . ")
            $whereTenant
            LIMIT 5
        ";
        $st = $pdo->prepare($sqlDoublons);
        $st->execute(array_merge($params, $paramsTenant));
        $doublons = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Recherche autocomplete ──
    if ($q !== '') {
        $like = '%' . $q . '%';
        $whereType = '';
        $paramsType = [];
        if ($type !== '') {
            $whereType = ' AND t.type_tiers = :type ';
            $paramsType[':type'] = $type;
        }

        $whereRole = '';
        $paramsRole = [];
        if ($role !== '') {
            $whereRole = ' AND EXISTS (
                SELECT 1 FROM tiers_roles tr
                WHERE tr.id_tiers = t.id AND tr.role_code = :role AND tr.actif = 1
            ) ';
            $paramsRole[':role'] = $role;
        }

        $sqlSearch = "
            SELECT t.id, t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale,
                   t.email, t.telephone, t.ville, t.code_postal, t.siret,
                   COALESCE(
                       NULLIF(t.nom_affichage, ''),
                       NULLIF(t.raison_sociale, ''),
                       TRIM(CONCAT_WS(' ', t.prenom, t.nom))
                   ) AS label,
                   (SELECT GROUP_CONCAT(DISTINCT role_code ORDER BY role_code SEPARATOR ',')
                    FROM tiers_roles tr WHERE tr.id_tiers = t.id AND tr.actif = 1) AS roles
            FROM tiers t
            WHERE t.actif = 1
              AND (
                   t.nom            LIKE :q
                OR t.prenom         LIKE :q
                OR t.raison_sociale LIKE :q
                OR t.email          LIKE :q
                OR t.telephone      LIKE :q
                OR t.siret          LIKE :q
                OR t.ville          LIKE :q
              )
              $whereType
              $whereRole
              $whereTenant
            ORDER BY
              CASE WHEN t.nom_affichage IS NOT NULL AND t.nom_affichage LIKE :qstart THEN 0 ELSE 1 END,
              t.nom ASC, t.raison_sociale ASC
            LIMIT :lim
        ";
        $st = $pdo->prepare($sqlSearch);
        $st->bindValue(':q', $like);
        $st->bindValue(':qstart', $q . '%');
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        foreach ($paramsType as $k => $v) $st->bindValue($k, $v);
        foreach ($paramsRole as $k => $v) $st->bindValue($k, $v);
        foreach ($paramsTenant as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'doublons' => $doublons,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
}
