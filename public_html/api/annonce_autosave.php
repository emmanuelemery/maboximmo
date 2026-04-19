<?php
// api/annonce_autosave.php — Autosave des champs d'une annonce (bien_detail_v2 Annonce)
// Pattern identique a bien_autosave.php : protection des champs non presents dans POST.
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

$annonceId = isset($_POST['_annonce_id']) && ctype_digit((string)$_POST['_annonce_id']) ? (int)$_POST['_annonce_id'] : 0;
if ($annonceId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'Aucune annonce à sauvegarder']));
}

// Verif scope
try {
    $st = $pdo->prepare("
        SELECT a.id, b.id_societe
        FROM annonces a
        JOIN biens b ON b.id = a.id_bien
        WHERE a.id = ?
        LIMIT 1
    ");
    $st->execute([$annonceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérif scope']));
}

// ── Whitelist des colonnes de `annonces` editables ──
$str  = static fn(string $k, string $d = ''): ?string => isset($_POST[$k]) ? (trim((string)$_POST[$k]) !== '' ? trim((string)$_POST[$k]) : null) : null;
$flt  = static fn(string $k): ?float => isset($_POST[$k]) && $_POST[$k] !== '' ? (float)$_POST[$k] : null;
$bool = static fn(string $k): ?int => isset($_POST[$k]) && $_POST[$k] !== '' ? (int)(bool)(int)$_POST[$k] : null;

$data = [
    // Etat publication
    'etat_publication'         => $str('etat_publication'),
    'visible_portails'         => $bool('visible_portails'),
    'visible_maboximmo'        => $bool('visible_maboximmo'),
    'visible_site_perso'       => $bool('visible_site_perso'),

    // Transaction
    'type_transaction'         => $str('type_transaction'),

    // Vente
    'prix'                     => $flt('prix'),
    'honoraires_charge_acquereur' => $flt('honoraires_charge_acquereur'),
    'honoraires_charge_vendeur'   => $flt('honoraires_charge_vendeur'),
    'pourcentage_honoraires_vendeur' => $flt('pourcentage_honoraires_vendeur'),
    'alur_pourcentage_honoraires_ttc' => $flt('alur_pourcentage_honoraires_ttc'),
    'honoraires_negociation_cumules'  => $flt('honoraires_negociation_cumules'),
    'url_tarifs_publics'       => $str('url_tarifs_publics'),

    // Location
    'loyer'                    => $flt('loyer'),
    'loyer_cc'                 => $flt('loyer_cc'),
    'loyer_de_base'            => $flt('loyer_de_base'),
    'loyer_reference_majore'   => $flt('loyer_reference_majore'),
    'complement_loyer'         => $flt('complement_loyer'),
    'zone_encadrement_loyer'   => $bool('zone_encadrement_loyer'),
    'loyer_est_cc'             => $bool('loyer_est_cc'),
    'modalite_recuperation_charges_locatives' => $str('modalite_recuperation_charges_locatives'),

    // Ancien loyer (ALUR)
    'ancien_loyer_montant'     => $flt('ancien_loyer_montant'),
    'ancien_loyer_charges'     => $flt('ancien_loyer_charges'),
    'ancien_loyer_date_revision' => $str('ancien_loyer_date_revision'),
    'ancien_locataire_date_sortie' => $str('ancien_locataire_date_sortie'),

    // Taxes
    'taxe_fonciere'            => $flt('taxe_fonciere'),
    'taxe_habitation'          => $flt('taxe_habitation'),

    // Autres
    'honoraires_etat_des_lieux' => $flt('honoraires_etat_des_lieux'),

    // Description / Détails / Titre / SEO
    'description'              => $str('description'),
    'resume_court'             => $str('resume_court'),
    'points_forts'             => $str('points_forts'),
    'accroche_commerciale'     => $str('accroche_commerciale'),
    'titre'                    => $str('titre'),
    'meta_title'               => $str('meta_title'),
    'meta_description'         => $str('meta_description'),
    'slug'                     => $str('slug'),
];

// Protection : si la cle n'est pas presente dans POST, on la retire
// (evite d'ecraser en base un champ non-envoye par l'autosave partiel)
foreach (array_keys($data) as $k) {
    if (!array_key_exists($k, $_POST)) {
        unset($data[$k]);
    }
}

if (empty($data)) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun champ à sauvegarder']));
}

try {
    $sets = [];
    $params = [':_id' => $annonceId];
    foreach ($data as $col => $val) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
        $sets[] = "`$col` = :$col";
        $params[":$col"] = $val;
    }
    $sql = "UPDATE annonces SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = :_id";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    exit(json_encode([
        'ok' => true,
        'saved_at' => date('H:i'),
        'fields' => array_keys($data),
    ]));
} catch (Throwable $e) {
    error_log('[annonce_autosave] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
