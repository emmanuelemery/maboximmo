<?php
declare(strict_types=1);
/**
 * api/immeuble_contenu.php — Inventaire d'un immeuble : lots (biens) + propriétaires
 * DÉJÀ connus, tous services confondus (gestion, transaction, location…).
 *
 * GARDE-FOU ANTI-DOUBLON INTER-SERVICES : quand l'immeuble est reconnu, la Transaction
 * doit VOIR ce que la Gestion connaît déjà (lots gérés + leurs propriétaires) pour
 * proposer de REPRENDRE l'existant au lieu de recréer un bien / un propriétaire en double.
 *
 * GET : immeuble_id=INT
 * Réponse : { ok, lots:[{id,reference,lot,etage,surface,statut,mission,proprio_id,proprio}],
 *             proprietaires:[{id,nom,nb_lots}] }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_missions.php'; // mission_statuts_terminaux()
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];

$immId = (int)($_GET['immeuble_id'] ?? 0);
if ($immId <= 0) exit(json_encode(['ok' => false, 'error' => 'immeuble_id requis']));

$TERM = "'" . implode("','", mission_statuts_terminaux()) . "'";

$sql = "SELECT b.id, b.reference_bien, b.designation,
               COALESCE(NULLIF(b.numero_lot,''), NULLIF(b.lot_principal,'')) AS lot,
               b.etage, b.surface_habitable, b.nb_pieces, b.statut_bien, b.type_commercialisation,
               bt.libelle AS type_label,
               b.dpe_classe, b.id_proprietaire, p.id_tiers AS proprio_tiers_id,
               COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale,
                        NULLIF(CONCAT_WS(' ', tp.prenom, tp.nom),''),
                        NULLIF(p.societe,''), NULLIF(CONCAT_WS(' ', p.prenom, p.nom),'')) AS proprio,
               EXISTS(SELECT 1 FROM mandats m WHERE m.id_bien = b.id
                        AND m.type_mandat='vente' AND m.statut NOT IN ($TERM)) AS has_mandat_vente,
               EXISTS(SELECT 1 FROM dpe_diags dd WHERE dd.id_bien = b.id) AS has_dpe_diag
        FROM biens b
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        LEFT JOIN tiers tp        ON tp.id = p.id_tiers
        LEFT JOIN bien_types bt   ON bt.id = b.id_bien_type
        WHERE b.id_immeuble = ?
          AND (b.statut_bien IS NULL OR b.statut_bien <> 'archive')
        ORDER BY (b.numero_lot+0), b.etage, b.id";
$st = $pdo->prepare($sql);
$st->execute([$immId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Acte de propriété : présence d'un document GED de type acte/titre rattaché au bien
// (tolérant : si le schéma GED diffère, on retombe sur false sans casser).
$acteOf = static function (PDO $pdo, int $bienId): bool {
    try {
        $q = $pdo->prepare("SELECT 1 FROM ged_document_links gl
                            JOIN ged_documents gd ON gd.id = gl.ged_document_id
                            WHERE gl.entity_type='BIEN' AND gl.entity_id = ?
                              AND (gd.document_type LIKE '%acte%' OR gd.document_type LIKE '%titre%')
                            LIMIT 1");
        $q->execute([$bienId]);
        return (bool)$q->fetchColumn();
    } catch (Throwable) { return false; }
};

$lots = []; $propIdx = [];
foreach ($rows as $r) {
    $lots[] = [
        'id'         => (int)$r['id'],
        'reference'  => (string)($r['reference_bien'] ?? ''),
        'lot'        => (string)($r['lot'] ?? ''),
        'etage'      => ($r['etage'] === null || $r['etage'] === '') ? null : (int)$r['etage'],
        'surface'    => ($r['surface_habitable'] !== null && $r['surface_habitable'] !== '') ? (float)$r['surface_habitable'] : null,
        'type'       => (string)($r['type_label'] ?? ''),
        'pieces'     => ($r['nb_pieces'] !== null && $r['nb_pieces'] !== '') ? (int)$r['nb_pieces'] : null,
        'statut'     => (string)($r['statut_bien'] ?? ''),
        'mission'    => (string)($r['type_commercialisation'] ?? ''),
        'proprio_id' => (int)($r['id_proprietaire'] ?? 0),
        'proprio'    => (string)($r['proprio'] ?? ''),
        // Pièces requises pour la vente (portes — Loi 6)
        'has_dpe'    => (!empty($r['dpe_classe']) || !empty($r['has_dpe_diag'])),
        'has_mandat_vente' => (bool)($r['has_mandat_vente'] ?? false),
        'has_acte'   => $acteOf($pdo, (int)$r['id']),
    ];
    $pid = (int)($r['id_proprietaire'] ?? 0);
    if ($pid > 0) {
        if (!isset($propIdx[$pid])) $propIdx[$pid] = ['id' => $pid, 'tiers_id' => (int)($r['proprio_tiers_id'] ?? 0), 'nom' => (string)($r['proprio'] ?? ('#'.$pid)), 'nb_lots' => 0];
        $propIdx[$pid]['nb_lots']++;
    }
}

echo json_encode([
    'ok'            => true,
    'immeuble_id'   => $immId,
    'lots'          => $lots,
    'proprietaires' => array_values($propIdx),
], JSON_UNESCAPED_UNICODE);
