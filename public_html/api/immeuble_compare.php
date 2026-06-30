<?php
/**
 * api/immeuble_compare.php — Compare 2 immeubles champ par champ (fusion de doublons).
 * GET a=<id> b=<id> → champs comparables + conflits + nb de biens rattachés.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$a = (int)($_GET['a'] ?? 0);
$b = (int)($_GET['b'] ?? 0);
if ($a <= 0 || $b <= 0 || $a === $b) {
    echo json_encode(['ok' => false, 'error' => 'Deux immeubles distincts requis.']); exit;
}

$champs = [
    'reference_immeuble' => 'Référence',
    'nom_immeuble'       => "Nom de l'immeuble",
    'adresse_1'          => 'Adresse',
    'adresse_2'          => 'Complément',
    'code_postal'        => 'Code postal',
    'ville'              => 'Ville',
    'type_immeuble'      => 'Type',
    'statut_immeuble'    => 'Statut',
    'nb_lots'            => 'Nb lots',
];

$cols = implode(',', array_map(fn($c) => "`$c`", array_keys($champs)));
$st = $pdo->prepare("SELECT id, $cols FROM immeubles WHERE id IN (?, ?)");
$st->execute([$a, $b]);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = $r; }
if (!isset($rows[$a]) || !isset($rows[$b])) {
    echo json_encode(['ok' => false, 'error' => 'Immeuble introuvable.']); exit;
}

$nbBiens = function (int $id) use ($pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_immeuble = " . $id)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
};

$fields = [];
foreach ($champs as $col => $label) {
    $va = $rows[$a][$col] ?? null; $vb = $rows[$b][$col] ?? null;
    $na = ($va === null || $va === ''); $nb = ($vb === null || $vb === '');
    $fields[] = [
        'col' => $col, 'label' => $label, 'a' => $va, 'b' => $vb,
        'conflit' => (!$na && !$nb && (string)$va !== (string)$vb),
        'defaut'  => $na ? 'b' : 'a',
    ];
}

echo json_encode([
    'ok'     => true,
    'a'      => ['id' => $a, 'nb_biens' => $nbBiens($a)],
    'b'      => ['id' => $b, 'nb_biens' => $nbBiens($b)],
    'fields' => $fields,
], JSON_UNESCAPED_UNICODE);
