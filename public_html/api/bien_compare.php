<?php
/**
 * api/bien_compare.php — Compare 2 biens champ par champ (pour la fusion de doublons).
 * GET a=<id> b=<id>
 * Retourne : pour chaque champ comparable, la valeur des 2 biens + un drapeau "conflit"
 * (valeurs différentes et toutes deux non vides), + des compteurs (baux, docs) pour aider
 * à choisir le bien à conserver.
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
    echo json_encode(['ok' => false, 'error' => 'Deux biens distincts requis.']); exit;
}

// Champs comparables (libellé + colonne)
$champs = [
    'reference_bien'      => 'Référence',
    'designation'         => 'Désignation',
    'usage_bien'          => 'Usage',
    'type_commercialisation' => 'Commercialisation',
    'statut_bien'         => 'Statut',
    'adresse_1'           => 'Adresse',
    'code_postal'         => 'Code postal',
    'ville'               => 'Ville',
    'lot_principal'       => 'Lot principal',
    'lot_secondaire'      => 'Lot secondaire',
    'etage'               => 'Étage',
    'surface_habitable'   => 'Surface habitable',
    'surface_carrez'      => 'Surface Carrez',
    'nb_pieces'           => 'Nb pièces',
    'nb_chambres'         => 'Nb chambres',
    'dpe_classe'          => 'DPE',
    'ges_classe'          => 'GES',
    'annee_construction'  => 'Année constr.',
    'loyer_hc'            => 'Loyer HC',
    'charges_locatives'   => 'Charges',
    'id_immeuble'         => 'ID immeuble',
    'id_proprietaire'     => 'ID propriétaire',
];

$cols = implode(',', array_map(fn($c) => "`$c`", array_keys($champs)));
$st = $pdo->prepare("SELECT id, $cols FROM biens WHERE id IN (?, ?)");
$st->execute([$a, $b]);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = $r; }
if (!isset($rows[$a]) || !isset($rows[$b])) {
    echo json_encode(['ok' => false, 'error' => 'Bien introuvable.']); exit;
}

// Compteurs liés (aide au choix du bien à garder)
$compte = function (int $id) use ($pdo): array {
    $c = ['baux' => 0, 'documents' => 0, 'mandats' => 0, 'annonces' => 0];
    try { $c['baux'] = (int)$pdo->query("SELECT COUNT(*) FROM bien_baux WHERE id_bien=" . $id)->fetchColumn(); } catch (Throwable $e) {}
    try { $c['mandats'] = (int)$pdo->query("SELECT COUNT(*) FROM mandats WHERE id_bien=" . $id)->fetchColumn(); } catch (Throwable $e) {}
    try { $c['annonces'] = (int)$pdo->query("SELECT COUNT(*) FROM annonces WHERE id_bien=" . $id)->fetchColumn(); } catch (Throwable $e) {}
    try {
        $c['documents'] = (int)$pdo->query("SELECT COUNT(*) FROM ged_document_links WHERE entity_type='BIEN' AND entity_id=" . $id)->fetchColumn();
    } catch (Throwable $e) {}
    return $c;
};

$fields = [];
foreach ($champs as $col => $label) {
    $va = $rows[$a][$col] ?? null;
    $vb = $rows[$b][$col] ?? null;
    $na = ($va === null || $va === '');
    $nb = ($vb === null || $vb === '');
    $conflit = !$na && !$nb && ((string)$va !== (string)$vb);
    $fields[] = [
        'col'     => $col,
        'label'   => $label,
        'a'       => $va,
        'b'       => $vb,
        'conflit' => $conflit,
        // défaut : la valeur non vide ; si conflit, on défaut sur A (à confirmer par l'user)
        'defaut'  => $na ? 'b' : 'a',
    ];
}

echo json_encode([
    'ok'       => true,
    'a'        => ['id' => $a, 'compteurs' => $compte($a)],
    'b'        => ['id' => $b, 'compteurs' => $compte($b)],
    'fields'   => $fields,
], JSON_UNESCAPED_UNICODE);
