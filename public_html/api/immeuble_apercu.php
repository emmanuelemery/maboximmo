<?php
declare(strict_types=1);
/**
 * api/immeuble_apercu.php — Aperçu LECTURE SEULE d'un immeuble connu.
 *
 * Sert la carte « remboursement » de bien_nouveau.php : quand l'adresse saisie
 * correspond à un immeuble déjà en base, on liste les informations qu'on peut
 * REPRENDRE (Manifeste — Loi 4 : une action, plusieurs bénéfices visibles).
 *
 * GET : immeuble_id=INT
 * Réponse : { ok, immeuble_id, nom_immeuble, nb_biens, nb_infos, infos:[{label,value}] }
 *
 * Aucune écriture. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];

$immId = (int)($_GET['immeuble_id'] ?? 0);
if ($immId <= 0) { exit(json_encode(['ok' => false, 'error' => 'immeuble_id requis'])); }

$st = $pdo->prepare("SELECT * FROM immeubles WHERE id = ? LIMIT 1");
$st->execute([$immId]);
$imm = $st->fetch(PDO::FETCH_ASSOC);
if (!$imm) { exit(json_encode(['ok' => false, 'error' => 'Immeuble introuvable'])); }

// Whitelist des champs « reprenables » → libellé lisible. Seuls les non-vides
// sont remboursés (jamais de faux 🟢 sur une colonne vide).
$labels = [
    'nom_immeuble'                  => "Nom de l'immeuble",
    'reference_immeuble'            => 'Référence immeuble',
    'adresse_formatee'             => 'Adresse normalisée Google',
    'code_postal'                   => 'Code postal',
    'ville'                         => 'Ville',
    'quartier'                      => 'Quartier',
    'pays'                          => 'Pays',
    'type_immeuble'                 => "Type d'immeuble",
    'annee_construction'            => 'Année de construction',
    'periode_construction'          => 'Période de construction',
    'nb_lots'                       => 'Nombre de lots',
    'copro_nb_lots'                 => 'Lots (copropriété)',
    'nb_etages'                     => "Nombre d'étages",
    'nb_appartements'               => "Nombre d'appartements",
    'ascenseur'                     => 'Ascenseur',
    'gardien'                       => 'Gardien',
    'chauffage_collectif'           => 'Chauffage collectif',
    'copro_budget_previsionnel_annuel' => 'Budget prévisionnel copro',
    'copro_tantiemes_total'         => 'Tantièmes totaux',
    'syndic_nom'                    => 'Syndic',
    'latitude'                      => 'Latitude (GPS)',
    'longitude'                     => 'Longitude (GPS)',
];

$infos = [];
foreach ($labels as $col => $label) {
    if (!array_key_exists($col, $imm)) continue;
    $v = $imm[$col];
    if ($v === null || $v === '' || $v === '0' || $v === 0) continue;
    // Booléens lisibles
    if (in_array($col, ['ascenseur', 'gardien', 'chauffage_collectif'], true)) {
        $v = 'Oui';
    }
    if (in_array($col, ['latitude', 'longitude'], true)) {
        $v = number_format((float)$v, 5, '.', '');
    }
    $infos[] = ['label' => $label, 'value' => (string)$v];
}

// Combien de biens (lots) déjà rattachés à cet immeuble → preuve de connaissance.
$nbBiens = 0;
try {
    $sb = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE id_immeuble = ?");
    $sb->execute([$immId]);
    $nbBiens = (int)$sb->fetchColumn();
} catch (Throwable) {}

echo json_encode([
    'ok'           => true,
    'immeuble_id'  => $immId,
    'nom_immeuble' => (string)($imm['nom_immeuble'] ?? ''),
    'nb_biens'     => $nbBiens,
    'nb_infos'     => count($infos),
    'infos'        => $infos,
], JSON_UNESCAPED_UNICODE);
