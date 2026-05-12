<?php
declare(strict_types=1);

/**
 * GED — Inbox : recherche instantanée (tiers principal)
 * Fichier : public_html/api/ged_inbox_entity_search.php
 *
 * Permet de chercher rapidement :
 *   - SDC (immeubles) : ref/nom/adresse/ville/CP
 *   - BAILLEUR (proprietaires) : societe/nom/prenom/adresse/ville/CP
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo       = $GLOBALS['pdo'];
$roleId    = (int)current_role_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

$kind = isset($_GET['kind']) ? strtoupper(trim((string)$_GET['kind'])) : '';
$q    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

if (!in_array($kind, ['SDC', 'BAILLEUR'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'kind invalide (SDC|BAILLEUR)']);
    exit;
}

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $needle = '%' . $q . '%';
    $results = [];

    if ($kind === 'SDC') {
        $where = [];
        $params = ['q' => $needle];
        if (!$isAdmin && $societeId > 0) {
            $where[] = '(id_societe = :sid OR id_societe IS NULL)';
            $params['sid'] = $societeId;
        }
        $whereSql = $where ? (' AND ' . implode(' AND ', $where)) : '';

        $st = $pdo->prepare("
            SELECT id, reference_immeuble, nom_immeuble, adresse_1, code_postal, ville
            FROM immeubles
            WHERE (
                reference_immeuble LIKE :q
                OR nom_immeuble LIKE :q
                OR adresse_1 LIKE :q
                OR code_postal LIKE :q
                OR ville LIKE :q
            )
            {$whereSql}
            ORDER BY reference_immeuble ASC, nom_immeuble ASC
            LIMIT 20
        ");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ref = trim((string)($r['reference_immeuble'] ?? ''));
            $nom = trim((string)($r['nom_immeuble'] ?? ''));
            $cp  = trim((string)($r['code_postal'] ?? ''));
            $ville = trim((string)($r['ville'] ?? ''));
            $adr = trim((string)($r['adresse_1'] ?? ''));

            $label = ($ref !== '' ? $ref . ' · ' : '')
                   . ($nom !== '' ? $nom : ($adr !== '' ? $adr : ('Immeuble #' . (int)$r['id'])));
            if ($cp !== '' || $ville !== '') {
                $label .= ' · ' . trim($cp . ' ' . $ville);
            }

            $results[] = [
                'id'         => (int)$r['id'],
                'label'      => $label,
                'objet_type' => 'IMB',
                'objet_id'   => (int)$r['id'],
                'tiers_nom'  => $nom !== '' ? $nom : ($ref !== '' ? $ref : $label),
                'hint'       => $adr,
            ];
        }
    } else {
        // BAILLEUR : table proprietaires (scope agence si non-admin)
        $where = ['actif = 1'];
        $params = ['q' => $needle];
        if (!$isAdmin && $agenceId > 0) {
            $where[] = '(id_agence = :ag OR id_agence IS NULL)';
            $params['ag'] = $agenceId;
        }
        $whereSql = implode(' AND ', $where);

        $st = $pdo->prepare("
            SELECT id, nom, prenom, societe, adresse_1, code_postal, ville
            FROM proprietaires
            WHERE {$whereSql}
              AND (
                societe LIKE :q
                OR nom LIKE :q
                OR prenom LIKE :q
                OR adresse_1 LIKE :q
                OR code_postal LIKE :q
                OR ville LIKE :q
              )
            ORDER BY societe ASC, nom ASC, prenom ASC
            LIMIT 20
        ");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $soc = trim((string)($r['societe'] ?? ''));
            $nom = trim((string)($r['nom'] ?? ''));
            $pre = trim((string)($r['prenom'] ?? ''));
            $cp  = trim((string)($r['code_postal'] ?? ''));
            $ville = trim((string)($r['ville'] ?? ''));
            $adr = trim((string)($r['adresse_1'] ?? ''));

            $label = $soc !== '' ? $soc : trim($pre . ' ' . $nom);
            if ($label === '') $label = 'Bailleur #' . (int)$r['id'];
            if ($cp !== '' || $ville !== '') $label .= ' · ' . trim($cp . ' ' . $ville);

            $results[] = [
                'id'         => (int)$r['id'],
                'label'      => $label,
                // GED naming n'a pas de pivot PROPRIO : on ne force pas objet_type ici
                'objet_type' => null,
                'objet_id'   => null,
                'tiers_nom'  => $soc !== '' ? $soc : trim($pre . ' ' . $nom),
                'hint'       => $adr,
            ];
        }
    }

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

