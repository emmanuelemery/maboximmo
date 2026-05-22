<?php
// api/transaction_chargement_match.php — Match bien à partir de tokens nom de fichier (V0)
// Score V0 = nb de tokens matchés × poids (référence > ville > adresse).
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 3) { echo json_encode(['items'=>[]]); exit; }

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSociete    = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

// Découpe en tokens (3+ chars)
$tokens = array_values(array_filter(
    preg_split('~[_\-\s\.,/]+~', mb_strtolower($q)),
    fn($t) => mb_strlen($t) >= 3
));
$tokens = array_slice(array_unique($tokens), 0, 6);
if (empty($tokens)) { echo json_encode(['items'=>[]]); exit; }

// Construit le WHERE : chaque token doit matcher AU MOINS une colonne.
// Placeholders positionnels — EMULATE_PREPARES=false n'autorise pas la
// répétition d'un même placeholder nommé dans la requête.
$conds = [];
$bind  = [];
foreach ($tokens as $t) {
    $like = '%' . $t . '%';
    $conds[] = '(LOWER(b.reference_bien) LIKE ?
              OR LOWER(b.designation) LIKE ?
              OR LOWER(b.ville) LIKE ?
              OR LOWER(b.adresse_1) LIKE ?
              OR LOWER(b.code_postal) LIKE ?)';
    for ($i = 0; $i < 5; $i++) $bind[] = $like;
}
$whereTokens = '(' . implode(' OR ', $conds) . ')';

$whereScope = '1=1';
if (!$isSuperAdmin && $idSociete !== null) {
    $whereScope = '(b.id_societe = ? OR b.id_societe IS NULL)';
    $bind[]     = $idSociete;
}

// On récupère les candidats élargis, puis on score côté PHP
// Ordre des placeholders : tokens d'abord (déjà dans $bind), puis scope APRÈS
// → on doit positionner whereTokens AVANT whereScope dans la requête.
$sql = "SELECT b.id, b.reference_bien, b.designation, b.ville, b.code_postal, b.adresse_1
        FROM biens b
        WHERE $whereTokens AND $whereScope
          AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
        LIMIT 100";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Scoring V0 : poids des tokens trouvés
    $scored = [];
    foreach ($rows as $r) {
        $hit = 0; $w = 0;
        $haystack = mb_strtolower(implode(' ', [
            $r['reference_bien'] ?? '', $r['designation'] ?? '',
            $r['ville'] ?? '', $r['code_postal'] ?? '', $r['adresse_1'] ?? '',
        ]));
        foreach ($tokens as $t) {
            if (str_contains($haystack, $t)) {
                $hit++;
                // Poids selon où il est trouvé
                $ref = mb_strtolower((string)($r['reference_bien'] ?? ''));
                $ville = mb_strtolower((string)($r['ville'] ?? ''));
                if (str_contains($ref, $t))        $w += 40;
                elseif (str_contains($ville, $t)) $w += 25;
                else                               $w += 12;
            }
        }
        if ($hit === 0) continue;
        $score = min(100, $w + ($hit === count($tokens) ? 10 : 0));
        $scored[] = ['score' => $score] + $r;
    }
    // Tri desc par score
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $items = array_slice($scored, 0, 10);
    echo json_encode(['items' => $items]);
} catch (Throwable $e) {
    error_log('[transaction_chargement_match] ' . $e->getMessage());
    echo json_encode(['items'=>[], 'error'=>$e->getMessage()]);
}
