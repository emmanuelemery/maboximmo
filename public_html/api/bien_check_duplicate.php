<?php
declare(strict_types=1);

/**
 * POST /api/bien_check_duplicate.php
 *
 * Recherche des biens existants qui pourraient être des doublons du bien
 * que l'utilisateur est en train de créer.
 *
 * Stratégie de matching (scoring cumulatif) :
 *   - Adresse normalisée exacte (adresse_1 + code_postal + ville)     +50
 *   - Étage + porte/lot identiques                                    +30
 *   - Surface ± 5 %                                                   +10
 *   - Nombre de pièces exact                                          +5
 *   - Même mandat (numero_mandat) : score 100 direct (bloquant)
 *   - Même référence externe (si saisie) : score 100
 *
 * Score ≥ 50 → retourné à l'UI qui affiche le popup.
 *
 * Scope : limité aux biens de la société de l'utilisateur courant
 * (sauf super admin role 7 qui voit tout).
 *
 * Paramètres POST :
 *   - csrf_token
 *   - adresse_1 (string, obligatoire pour check)
 *   - code_postal (string)
 *   - ville (string)
 *   - etage (string, optionnel)
 *   - porte (string, optionnel)      — champ libre "porte A", "B", etc.
 *   - lot_principal (string, optionnel)
 *   - surface_habitable (float, optionnel)
 *   - nb_pieces (int, optionnel)
 *   - numero_mandat (string, optionnel)
 *   - reference_externe (string, optionnel)
 *   - exclude_id (int, optionnel) — bien en cours d'édition à exclure
 *
 * Réponse JSON :
 *   {
 *     ok: true,
 *     matches: [ { id, reference_bien, designation, adresse_1, code_postal, ville,
 *                  etage, lot_principal, surface_habitable, nb_pieces, statut_bien,
 *                  score, reasons, edit_url }, ... ],
 *     count: N
 *   }
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

    // Normalisation d'une string d'adresse (minuscule, sans accents, espaces compactés)
    $norm = static function (string $s): string {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    };

    $adresse     = $norm((string)($_POST['adresse_1'] ?? ''));
    $codePostal  = trim((string)($_POST['code_postal'] ?? ''));
    $ville       = $norm((string)($_POST['ville'] ?? ''));
    $etage       = trim((string)($_POST['etage'] ?? ''));
    $porte       = trim((string)($_POST['porte'] ?? ''));
    $lot         = trim((string)($_POST['lot_principal'] ?? ''));
    $surface     = isset($_POST['surface_habitable']) && $_POST['surface_habitable'] !== ''
                   ? (float)$_POST['surface_habitable'] : null;
    $nbPieces    = isset($_POST['nb_pieces']) && ctype_digit((string)$_POST['nb_pieces'])
                   ? (int)$_POST['nb_pieces'] : null;
    $numMandat   = trim((string)($_POST['numero_mandat'] ?? ''));
    $refExterne  = trim((string)($_POST['reference_externe'] ?? ''));
    $excludeId   = isset($_POST['exclude_id']) && ctype_digit((string)$_POST['exclude_id'])
                   ? (int)$_POST['exclude_id'] : 0;

    if ($adresse === '' && $numMandat === '' && $refExterne === '') {
        exit(json_encode(['ok' => true, 'matches' => [], 'count' => 0]));
    }

    // ── Construction WHERE : d'abord filtrage large, puis scoring côté PHP ──
    $where  = ['1=1'];
    $params = [];
    if ($societeId > 0 && $roleId !== 7) {
        $where[] = 'b.id_societe = :societe';
        $params[':societe'] = $societeId;
    }
    if ($excludeId > 0) {
        $where[] = 'b.id <> :exclude';
        $params[':exclude'] = $excludeId;
    }

    // Filtre ville/CP si fourni (sinon on tombe sur mandat/ref_externe pur)
    $orClauses = [];
    if ($adresse !== '' && ($codePostal !== '' || $ville !== '')) {
        if ($codePostal !== '') {
            $orClauses[] = '(b.code_postal = :cp)';
            $params[':cp'] = $codePostal;
        }
        if ($ville !== '') {
            $orClauses[] = '(LOWER(b.ville) LIKE :ville_like)';
            $params[':ville_like'] = '%' . $ville . '%';
        }
    }
    if ($numMandat !== '') {
        // Recherche dans mandats par numero_mandat
        $stmtM = $pdo->prepare("
            SELECT id_bien FROM mandats
            WHERE numero_mandat = ? LIMIT 10
        ");
        $stmtM->execute([$numMandat]);
        $ids = $stmtM->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $orClauses[] = "(b.id IN ({$in}))";
        }
    }
    if ($refExterne !== '') {
        $orClauses[] = '(b.reference_externe = :refext OR b.reference_bien = :refext)';
        $params[':refext'] = $refExterne;
    }
    if ($orClauses) {
        $where[] = '(' . implode(' OR ', $orClauses) . ')';
    } elseif ($adresse !== '') {
        // Seulement adresse, pas de CP/ville → recherche très large sur adresse_1
        $where[] = 'LOWER(b.adresse_1) LIKE :adr_like';
        $params[':adr_like'] = '%' . substr($adresse, 0, 40) . '%';
    } else {
        exit(json_encode(['ok' => true, 'matches' => [], 'count' => 0]));
    }

    $sql = "
        SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.code_postal, b.ville,
               b.etage, b.lot_principal, b.surface_habitable, b.nb_pieces, b.statut_bien,
               b.id_proprietaire, b.date_modification,
               (SELECT numero_mandat FROM mandats m WHERE m.id_bien = b.id ORDER BY m.id DESC LIMIT 1) AS numero_mandat
        FROM biens b
        WHERE " . implode(' AND ', $where) . "
        ORDER BY b.date_modification DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Scoring ──
    $matches = [];
    foreach ($candidates as $c) {
        $score   = 0;
        $reasons = [];

        $cAdr  = $norm((string)($c['adresse_1'] ?? ''));
        $cCp   = trim((string)($c['code_postal'] ?? ''));
        $cVille= $norm((string)($c['ville'] ?? ''));
        $cEtg  = trim((string)($c['etage'] ?? ''));
        $cLot  = trim((string)($c['lot_principal'] ?? ''));
        $cSrf  = isset($c['surface_habitable']) ? (float)$c['surface_habitable'] : null;
        $cNbP  = isset($c['nb_pieces']) ? (int)$c['nb_pieces'] : null;
        $cNum  = trim((string)($c['numero_mandat'] ?? ''));
        $cRef  = trim((string)($c['reference_bien'] ?? ''));

        // Match fort direct
        if ($numMandat !== '' && $cNum !== '' && $numMandat === $cNum) {
            $score = 100; $reasons[] = 'N° mandat identique';
        }
        if ($refExterne !== '' && $cRef !== '' && $refExterne === $cRef) {
            $score = 100; $reasons[] = 'Référence identique';
        }

        // Adresse + CP + ville
        if ($adresse !== '' && $cAdr !== '' && $adresse === $cAdr
            && ($codePostal === '' || $codePostal === $cCp)
            && ($ville === '' || $ville === $cVille)) {
            $score += 50;
            $reasons[] = 'Adresse identique';
        } elseif ($adresse !== '' && $cAdr !== '' && str_contains($cAdr, $adresse)) {
            $score += 25;
            $reasons[] = 'Adresse similaire';
        }

        // Étage / lot
        if ($etage !== '' && $cEtg !== '' && $etage === $cEtg) {
            $score += 15;
            $reasons[] = "Étage $etage";
        }
        if ($lot !== '' && $cLot !== '' && strcasecmp($lot, $cLot) === 0) {
            $score += 15;
            $reasons[] = "Lot $lot";
        }
        if ($porte !== '') {
            // Pas de colonne dédiée, on vérifie dans lot_principal/adresse
            $haystack = strtolower($cLot . ' ' . (string)$c['adresse_1']);
            if (str_contains($haystack, strtolower($porte))) {
                $score += 10; $reasons[] = "Porte $porte";
            }
        }

        // Surface ± 5 %
        if ($surface !== null && $cSrf !== null && $cSrf > 0) {
            $diff = abs($surface - $cSrf) / max($surface, $cSrf);
            if ($diff <= 0.05) { $score += 10; $reasons[] = 'Surface proche'; }
        }

        // Nb pièces identique
        if ($nbPieces !== null && $cNbP !== null && $nbPieces === $cNbP) {
            $score += 5; $reasons[] = $nbPieces . ' pièces';
        }

        if ($score >= 50) {
            $matches[] = [
                'id'                => (int)$c['id'],
                'reference_bien'    => $cRef ?: '#' . (int)$c['id'],
                'designation'       => (string)($c['designation'] ?? ''),
                'adresse_1'         => (string)($c['adresse_1'] ?? ''),
                'code_postal'       => (string)($c['code_postal'] ?? ''),
                'ville'             => (string)($c['ville'] ?? ''),
                'etage'             => (string)($c['etage'] ?? ''),
                'lot_principal'     => (string)($c['lot_principal'] ?? ''),
                'surface_habitable' => $cSrf,
                'nb_pieces'         => $cNbP,
                'statut_bien'       => (string)($c['statut_bien'] ?? ''),
                'numero_mandat'     => $cNum,
                'score'             => $score,
                'reasons'           => $reasons,
                'edit_url'          => 'bien_ajouter.php?edit=' . (int)$c['id'],
            ];
        }
    }

    // Tri décroissant par score
    usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);

    echo json_encode(['ok' => true, 'matches' => $matches, 'count' => count($matches)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
