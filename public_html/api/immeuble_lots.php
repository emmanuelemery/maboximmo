<?php
/**
 * api/immeuble_lots.php — Liste les LOTS (biens) d'un immeuble, pour le sélecteur
 * rapide de lot du modal de saisie des diagnostics.
 *
 * GET ?id_immeuble=123
 * Réponse : { ok:true, immeuble:{...}, lots:[ {id, label, reference_bien, lot, etage,
 *             numero_porte, nb_pieces, surface, has_dpe} ] }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/dpe_lyon_lib.php'; // dly_nrm()
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$idImmeuble = (int)($_GET['id_immeuble'] ?? 0);
if ($idImmeuble <= 0) { echo json_encode(['ok' => false, 'error' => 'id_immeuble manquant']); exit; }

$imm = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville FROM immeubles WHERE id = ?");
$imm->execute([$idImmeuble]);
$immeuble = $imm->fetch(PDO::FETCH_ASSOC);
if (!$immeuble) { echo json_encode(['ok' => false, 'error' => 'immeuble introuvable']); exit; }

// ── Agrégation des IMMEUBLES EN DOUBLE (même adresse) ───────────────────
// Les lots d'une même adresse sont souvent éclatés sur plusieurs fiches immeuble
// dupliquées (import). On rassemble les biens de toutes les fiches même adresse+CP.
$key = dly_nrm((string)$immeuble['adresse_1']);
$cp  = trim((string)$immeuble['code_postal']);
$immIds = [$idImmeuble];
if ($key !== '' && $cp !== '') {
    $cand = $pdo->prepare("SELECT id, adresse_1 FROM immeubles WHERE code_postal = ?");
    $cand->execute([$cp]);
    foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (dly_nrm((string)$c['adresse_1']) === $key) $immIds[] = (int)$c['id'];
    }
    $immIds = array_values(array_unique($immIds));
}
$in = implode(',', array_map('intval', $immIds));

$st = $pdo->query("
    SELECT b.id, b.reference_bien, b.designation, b.id_proprietaire,
           COALESCE(NULLIF(b.lot_principal,''), NULLIF(b.numero_lot,'')) AS lot,
           b.etage, b.numero_porte, b.nb_pieces, b.surface_habitable,
           COALESCE(NULLIF(pr.societe,''), NULLIF(TRIM(CONCAT_WS(' ', pr.prenom, pr.nom)),'')) AS proprio_nom,
           (b.dpe_reference_certificat IS NOT NULL AND b.dpe_reference_certificat <> '') AS has_dpe
    FROM biens b
    LEFT JOIN proprietaires pr ON pr.id = b.id_proprietaire
    WHERE b.id_immeuble IN ($in)
      AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
    ORDER BY CAST(NULLIF(REGEXP_REPLACE(COALESCE(NULLIF(b.lot_principal,''),NULLIF(b.numero_lot,''),''),'[^0-9]',''),'') AS UNSIGNED),
             b.etage, b.reference_bien");

$rowsRaw = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Dédoublonnage PRUDENT ──────────────────────────────────────────────
// On NE retire QUE les coquilles d'import sans valeur : aucune info métier ET
// aucun propriétaire rattaché (vrai placeholder). Les lots réels peu renseignés
// (mais rattachés à un proprio / portant un n° de lot) sont TOUJOURS conservés.
$richesse = function (array $b): int {
    $n = 0;
    foreach (['lot','etage','numero_porte','nb_pieces','surface_habitable'] as $k) {
        if (isset($b[$k]) && $b[$k] !== '' && $b[$k] !== null) $n++;
    }
    if (!empty($b['has_dpe'])) $n++;
    return $n;
};
$byRef = [];
foreach ($rowsRaw as $b) {
    $r = $richesse($b);
    $hasOwner = !empty($b['id_proprietaire']);
    // coquille = 0 info ET sans propriétaire → placeholder d'import à masquer
    if ($r === 0 && !$hasOwner && count($rowsRaw) > 1) continue;
    $ref = trim((string)($b['reference_bien'] ?? ''));
    $key2 = $ref !== '' ? 'R:' . mb_strtolower($ref) : 'ID:' . $b['id'];
    if (!isset($byRef[$key2]) || $r > $byRef[$key2]['_r']) { $b['_r'] = $r; $byRef[$key2] = $b; }
}

$lots = [];
foreach ($byRef as $b) {
    unset($b['_r']);
    // Libellé court et parlant pour le bouton de lot
    $parts = [];
    if (!empty($b['lot']))         $parts[] = 'Lot ' . $b['lot'];
    if ($b['etage'] !== null && $b['etage'] !== '') {
        $parts[] = ((int)$b['etage'] === 0) ? 'RDC' : ($b['etage'] . 'e');
    }
    if (!empty($b['numero_porte'])) $parts[] = 'P.' . $b['numero_porte'];
    if (!empty($b['nb_pieces']))    $parts[] = 'T' . (int)$b['nb_pieces'];
    if (!$parts && !empty($b['designation'])) $parts[] = mb_substr((string)$b['designation'], 0, 24);
    $label = $parts ? implode(' · ', $parts) : ('Bien ' . $b['reference_bien']);

    $lots[] = [
        'id'             => (int)$b['id'],
        'label'          => $label,
        'reference_bien' => (string)$b['reference_bien'],
        'proprio'        => (string)($b['proprio_nom'] ?? ''),
        'lot'            => (string)($b['lot'] ?? ''),
        'etage'          => $b['etage'],
        'numero_porte'   => (string)($b['numero_porte'] ?? ''),
        'nb_pieces'      => $b['nb_pieces'],
        'surface'        => $b['surface_habitable'],
        'has_dpe'        => (bool)(int)$b['has_dpe'],
    ];
}

echo json_encode(['ok' => true, 'immeuble' => $immeuble, 'lots' => $lots], JSON_UNESCAPED_UNICODE);
