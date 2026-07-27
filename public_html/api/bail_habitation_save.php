<?php
/**
 * api/bail_habitation_save.php — Crée / met à jour un PROJET de bail HABITATION (bien_baux,
 * bail_nature='habitation'). Whitelist stricte des colonnes. Scope société.
 *
 * POST JSON : { bail_id?, bien_id, ...champs... }
 * Réponse : { ok, bail_id }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'POST requis'])); }

$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);
$userSoc= (int)($_SESSION['id_societe'] ?? 0);
$isAdmin= ((int)($_SESSION['id_role'] ?? 0) === 1);
$in     = json_decode((string)file_get_contents('php://input'), true) ?: [];
$bailId = (int)($in['bail_id'] ?? 0);
$bienId = (int)($in['bien_id'] ?? 0);

// Colonnes autorisées (habitation + socle partagé).
$COLS = [
    // texte
    'dpe_classe' => 's', 'equipement_tic' => 's', 'complement_loyer_caracteristiques' => 's',
    'paiement_jour' => 's', 'paiement_beneficiaire' => 's', 'lieu_signature' => 's', 'annexes_json' => 's',
    'indice_trimestre' => 's', 'date_revision_jour_mois' => 's', 'bien_designation' => 's',
    'lot_copropriete' => 's', 'lot_tantiemes' => 's', 'travaux_realises_3ans' => 's', 'travaux_prevus_3ans' => 's',
    'conditions_particulieres' => 's', 'bailleur_representant_nom' => 's',
    'locataire_nom' => 's', 'locataire_prenom' => 's', 'locataire_raison_sociale' => 's',
    'locataire_email' => 's', 'locataire_telephone' => 's', 'locataire_adresse' => 's',
    'locataire_lieu_naissance' => 's', 'locataire_nationalite' => 's',
    // dates
    'date_prise_effet' => 'd', 'prorata_date_debut' => 'd', 'dernier_loyer_date_versement' => 'd',
    'dernier_loyer_date_revision' => 'd', 'locataire_date_naissance' => 'd',
    // nombres décimaux
    'loyer_mensuel_hc' => 'f', 'complement_loyer' => 'f', 'charges_mensuelles' => 'f', 'depot_garantie' => 'f',
    'loyer_reference' => 'f', 'loyer_reference_majore' => 'f', 'dernier_loyer_montant' => 'f',
    'teom_montant' => 'f', 'assurance_colocataires_mensuel' => 'f', 'indice_valeur' => 'f',
    'depenses_energie_min' => 'f', 'depenses_energie_max' => 'f', 'surface_habitable' => 'f',
    'honoraires_plafond_visite_m2' => 'f', 'honoraires_plafond_edl_m2' => 'f',
    'hono_bailleur_visite' => 'f', 'hono_bailleur_entremise' => 'f', 'hono_bailleur_edl' => 'f',
    'hono_locataire_visite' => 'f', 'hono_locataire_edl' => 'f',
    // entiers
    'nb_pieces' => 'i', 'teom_annee' => 'i', 'depenses_energie_annee' => 'i',
    // booléens / enum
    'zone_tendue' => 'i', 'colocation' => 'i', 'en_copropriete' => 'i',
    'charges_type' => 'enum:provisions,forfait', 'locataire_type' => 'enum:physique,societe',
];

$set = []; $val = [];
foreach ($COLS as $col => $type) {
    if (!array_key_exists($col, $in)) continue;
    $v = $in[$col];
    if ($v === '' || $v === null) { $set[] = "`$col`=?"; $val[] = null; continue; }
    if ($type === 'f')      $v = is_numeric($v) ? (float)$v : null;
    elseif ($type === 'i')  $v = (int)$v;
    elseif ($type === 'd')  $v = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : null;
    elseif (strpos($type, 'enum:') === 0) { $allowed = explode(',', substr($type, 5)); $v = in_array($v, $allowed, true) ? $v : null; }
    else                    $v = mb_substr((string)$v, 0, 65000);
    $set[] = "`$col`=?"; $val[] = $v;
}

try {
    if ($bailId > 0) {
        // Scope
        $st = $pdo->prepare("SELECT bb.id_societe FROM bien_baux bb WHERE bb.id=?"); $st->execute([$bailId]);
        $soc = (int)($st->fetchColumn() ?: 0);
        if (!$isAdmin && $soc && $soc !== $userSoc) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Hors périmètre'])); }
        if ($set) { $val[] = $bailId; $pdo->prepare("UPDATE bien_baux SET " . implode(',', $set) . ", updated_at=NOW() WHERE id=?")->execute($val); }
    } else {
        if ($bienId <= 0) exit(json_encode(['ok' => false, 'error' => 'bien_id requis pour créer le bail']));
        // Société/agence du bien.
        $b = $pdo->prepare("SELECT id_societe, id_agence, id_proprietaire FROM biens WHERE id=?"); $b->execute([$bienId]);
        $br = $b->fetch(PDO::FETCH_ASSOC) ?: [];
        $cols = "id_bien, id_proprietaire, id_societe, id_agence, bail_nature, statut, id_user_created, created_at";
        $ph   = "?,?,?,?, 'habitation','projet', ?, NOW()";
        $args = [$bienId, (int)($br['id_proprietaire'] ?? 0) ?: null, (int)($br['id_societe'] ?? 0) ?: $userSoc, (int)($br['id_agence'] ?? 0) ?: null, $userId];
        $pdo->prepare("INSERT INTO bien_baux ($cols) VALUES ($ph)")->execute($args);
        $bailId = (int)$pdo->lastInsertId();
        if ($set) { $val[] = $bailId; $pdo->prepare("UPDATE bien_baux SET " . implode(',', $set) . " WHERE id=?")->execute($val); }
    }
    echo json_encode(['ok' => true, 'bail_id' => $bailId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
