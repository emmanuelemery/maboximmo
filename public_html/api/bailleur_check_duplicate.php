<?php
declare(strict_types=1);

/**
 * POST /api/bailleur_check_duplicate.php
 *
 * Recherche des propriétaires existants qui pourraient être des doublons.
 *
 * Scoring (cumulatif) :
 *   - SIRET identique           +80
 *   - Email identique           +70
 *   - Téléphone identique (9 derniers chiffres équivalents)  +50
 *   - Société identique (normalisée)  +30
 *   - Nom identique             +20
 *   - Prénom identique          +10
 *
 * Score ≥ 30 = suggestion retournée. Score ≥ 70 = forte probabilité.
 *
 * Paramètres POST :
 *   - csrf_token
 *   - nom, prenom, societe, email, telephone, siret (au moins un rempli)
 *   - exclude_id (int, optionnel)
 *
 * Réponse :
 *   { ok: true, matches: [ { id, nom, prenom, societe, email, telephone, ville,
 *                            type_personne, score, reasons }, ... ], count: N }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = $GLOBALS['pdo'];
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $roleId    = (int)($_SESSION['id_role'] ?? 0);

    $norm = static function (string $s): string {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower(trim($s));
        return preg_replace('/\s+/', ' ', $s) ?? '';
    };
    $normPhone = static function (string $s): string {
        return substr(preg_replace('/[^0-9]/', '', $s) ?? '', -9);
    };

    $nom      = $norm((string)($_POST['nom'] ?? ''));
    $prenom   = $norm((string)($_POST['prenom'] ?? ''));
    $societe  = $norm((string)($_POST['societe'] ?? ''));
    $email    = $norm((string)($_POST['email'] ?? ''));
    $tel      = $normPhone((string)($_POST['telephone'] ?? ''));
    $siret    = preg_replace('/[^0-9]/', '', (string)($_POST['siret'] ?? '')) ?? '';
    $excludeId = isset($_POST['exclude_id']) && ctype_digit((string)$_POST['exclude_id'])
                 ? (int)$_POST['exclude_id'] : 0;

    if ($nom === '' && $prenom === '' && $societe === '' && $email === '' && $tel === '' && $siret === '') {
        exit(json_encode(['ok' => true, 'matches' => [], 'count' => 0]));
    }

    // Pré-filtre SQL (cherche large, scoring en PHP)
    $where  = ['p.actif = 1'];
    $params = [];
    if ($societeId > 0 && $roleId !== 7) {
        $where[] = '(p.id_societe = :societe OR p.id_societe IS NULL)';
        $params[':societe'] = $societeId;
    }
    if ($excludeId > 0) {
        $where[] = 'p.id <> :exclude';
        $params[':exclude'] = $excludeId;
    }

    $or = [];
    if ($email !== '')   { $or[] = 'LOWER(p.email) = :email';        $params[':email'] = $email; }
    if ($tel !== '')     { $or[] = 'REPLACE(REPLACE(REPLACE(REPLACE(p.telephone, " ", ""), ".", ""), "-", ""), "+", "") LIKE :tel'; $params[':tel'] = '%' . $tel; }
    if ($nom !== '')     { $or[] = 'LOWER(p.nom) = :nom';            $params[':nom'] = $nom; }
    if ($societe !== '') { $or[] = 'LOWER(p.societe) = :soc';        $params[':soc'] = $societe; }
    if ($siret !== '')   { $or[] = 'p.siret = :siret';               $params[':siret'] = $siret; }

    if (!$or) exit(json_encode(['ok' => true, 'matches' => [], 'count' => 0]));

    $where[] = '(' . implode(' OR ', $or) . ')';

    // Vérifie la présence de la colonne siret (optionnelle selon historique schéma)
    $colsStmt = $pdo->query("SHOW COLUMNS FROM proprietaires LIKE 'siret'");
    $hasSiret = (bool)$colsStmt->fetchColumn();

    $selectSiret = $hasSiret ? 'p.siret' : 'NULL AS siret';
    // Si siret manquant côté BDD, on retire la clause siret
    if (!$hasSiret && isset($params[':siret'])) {
        $newOr = array_filter($or, static fn($c) => !str_contains($c, ':siret'));
        $where[count($where) - 1] = '(' . implode(' OR ', $newOr) . ')';
        unset($params[':siret']);
    }

    $sql = "
        SELECT p.id, p.type_personne, p.civilite, p.nom, p.prenom, p.societe,
               p.email, p.telephone, p.ville, {$selectSiret}
        FROM proprietaires p
        WHERE " . implode(' AND ', $where) . "
        LIMIT 30
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matches = [];
    foreach ($candidates as $c) {
        $score = 0;
        $reasons = [];

        $cNom = $norm((string)($c['nom'] ?? ''));
        $cPre = $norm((string)($c['prenom'] ?? ''));
        $cSoc = $norm((string)($c['societe'] ?? ''));
        $cMail = $norm((string)($c['email'] ?? ''));
        $cTel = $normPhone((string)($c['telephone'] ?? ''));
        $cSiret = preg_replace('/[^0-9]/', '', (string)($c['siret'] ?? '')) ?? '';

        if ($siret !== '' && $cSiret !== '' && $siret === $cSiret) { $score += 80; $reasons[] = 'SIRET identique'; }
        if ($email !== '' && $cMail !== '' && $email === $cMail)   { $score += 70; $reasons[] = 'Email identique'; }
        if ($tel !== '' && $cTel !== '' && $tel === $cTel)          { $score += 50; $reasons[] = 'Téléphone identique'; }
        if ($societe !== '' && $cSoc !== '' && $societe === $cSoc) { $score += 30; $reasons[] = 'Société identique'; }
        if ($nom !== '' && $cNom !== '' && $nom === $cNom)          { $score += 20; $reasons[] = 'Nom identique'; }
        if ($prenom !== '' && $cPre !== '' && $prenom === $cPre)    { $score += 10; $reasons[] = 'Prénom identique'; }

        if ($score >= 30) {
            $matches[] = [
                'id'            => (int)$c['id'],
                'type_personne' => (string)($c['type_personne'] ?? 'physique'),
                'civilite'      => (string)($c['civilite'] ?? ''),
                'nom'           => (string)($c['nom'] ?? ''),
                'prenom'        => (string)($c['prenom'] ?? ''),
                'societe'       => (string)($c['societe'] ?? ''),
                'email'         => (string)($c['email'] ?? ''),
                'telephone'     => (string)($c['telephone'] ?? ''),
                'ville'         => (string)($c['ville'] ?? ''),
                'score'         => $score,
                'reasons'       => $reasons,
            ];
        }
    }

    usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);

    echo json_encode(['ok' => true, 'matches' => $matches, 'count' => count($matches)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
