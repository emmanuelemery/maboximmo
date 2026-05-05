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

    // Vente — modèle 2026-04-24 : prix FAI = net + honoraires (calculé backend)
    'prix'                     => $flt('prix'),
    'prix_net_vendeur'         => $flt('prix_net_vendeur'),
    'honoraires'               => $flt('honoraires'),
    'alur_pourcentage_honoraires_ttc' => $flt('alur_pourcentage_honoraires_ttc'),
    // Flags exclusifs (tinyint 0/1) — piloté par le toggle Acquéreur/Vendeur
    'honoraires_charge_acquereur' => $bool('honoraires_charge_acquereur'),
    'honoraires_charge_vendeur'   => $bool('honoraires_charge_vendeur'),
    'url_tarifs_publics'       => $str('url_tarifs_publics'),

    // Location
    'loyer'                    => $flt('loyer'),
    'loyer_cc'                 => $flt('loyer_cc'),
    'loyer_de_base'            => $flt('loyer_de_base'),
    'loyer_reference_majore'   => $flt('loyer_reference_majore'),
    'complement_loyer'         => $flt('complement_loyer'),
    'depot_garantie'           => $flt('depot_garantie'),
    'zone_encadrement_loyer'   => $bool('zone_encadrement_loyer'),
    'loyer_est_cc'             => $bool('loyer_est_cc'),
    'loyer_mode'               => $str('loyer_mode'),
    'meuble'                   => $bool('meuble'),
    'modalite_recuperation_charges_locatives' => $str('modalite_recuperation_charges_locatives'),

    // Ancien loyer (ALUR)
    'ancien_loyer_montant'     => $flt('ancien_loyer_montant'),
    'ancien_loyer_charges'     => $flt('ancien_loyer_charges'),
    'ancien_loyer_date_revision' => $str('ancien_loyer_date_revision'),
    'ancien_locataire_date_sortie' => $str('ancien_locataire_date_sortie'),

    // Taxes
    'taxe_fonciere'            => $flt('taxe_fonciere'),
    'taxe_habitation'          => $flt('taxe_habitation'),
    'taxe_ordures_menageres'   => $flt('taxe_ordures_menageres'),

    // Honoraires locataire (ALUR) — modifiables manuellement
    'honoraires_etat_des_lieux' => $flt('honoraires_etat_des_lieux'),
    'honoraires_location_bail'  => $flt('honoraires_location_bail'),

    // Description / Détails / Titre / SEO
    'description'              => $str('description'),
    'resume_court'             => $str('resume_court'),
    'points_forts'             => $str('points_forts'),
    'accroche_commerciale'     => $str('accroche_commerciale'),
    'titre'                    => $str('titre'),
    'meta_title'               => $str('meta_title'),
    'meta_description'         => $str('meta_description'),
    'mots_cles'                => $str('mots_cles'),
    'slug'                     => $str('slug'),
];

// Mappage spécial : annonce_commercial_id (POST) → id_user (BDD)
// Le select commercial de la Card Diffusion écrit dans annonces.id_user
if (array_key_exists('annonce_commercial_id', $_POST)) {
    $cid = (int)$_POST['annonce_commercial_id'];
    $data['id_user'] = $cid > 0 ? $cid : null;
    // Ajoute la clé dans $_POST pour passer la protection ci-dessous
    $_POST['id_user'] = $_POST['annonce_commercial_id'];
}

// Validation ENUM loyer_mode
if (array_key_exists('loyer_mode', $data)) {
    $allowed = ['libre', 'majore', 'reference', 'minore'];
    if (!in_array($data['loyer_mode'], $allowed, true)) {
        unset($data['loyer_mode']);
    }
}

// Protection : si la cle n'est pas presente dans POST, on la retire
// (evite d'ecraser en base un champ non-envoye par l'autosave partiel)
foreach (array_keys($data) as $k) {
    if (!array_key_exists($k, $_POST)) {
        unset($data[$k]);
    }
}

// Auto-switch loyer_mode + enc_auto_apply quand zone_encadrement_loyer change
//   activation   (0 → 1) : mode 'majore' + pré-remplit tarifs depuis adresse
//   désactivation (1 → 0): mode 'libre'
$encAutoResult = null;
if (array_key_exists('zone_encadrement_loyer', $data) && !array_key_exists('loyer_mode', $data)) {
    try {
        $stPrev = $pdo->prepare("SELECT COALESCE(zone_encadrement_loyer, 0) FROM annonces WHERE id = ? LIMIT 1");
        $stPrev->execute([$annonceId]);
        $prevZone = (int)$stPrev->fetchColumn();
        $newZone  = (int)$data['zone_encadrement_loyer'];
        if ($prevZone !== $newZone) {
            $data['loyer_mode'] = $newZone === 1 ? 'majore' : 'libre';
            // Activation : on laisse l'UPDATE se faire, puis on déclenche enc_auto_apply
            // (les colonnes biens sont mises à jour en plus de annonces)
            if ($newZone === 1) {
                $_encTriggerAutoApply = true;
            }
        }
    } catch (Throwable $e) { /* non bloquant */ }
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

    // ── Recalculs automatiques en cascade ──
    //   1. loyer_reference_majore (auto si vide & surface × enc_loyer_max calculable)
    //   2. depot_garantie (auto si vide = 1 mois loyer HC de référence)
    //   3. honoraires (cap ALUR TOUJOURS appliqué, même sur saisie manuelle qui dépasse)
    //   4. loyer_cc (toujours recalculé = loyer HC réf + charges biens)
    require_once dirname(__DIR__) . '/inc/honoraires_helper.php';

    // Si l'user vient d'activer la zone encadrée → auto-remplit les tarifs
    // depuis l'adresse (CP + pièces + époque + meublé)
    if (!empty($_encTriggerAutoApply)) {
        require_once dirname(__DIR__) . '/inc/encadrement_helper.php';
        $encAutoResult = enc_auto_apply($pdo, $annonceId);
    }

    // Cascade vente : si net/hono/% modifiés → recalcul prix FAI + %/hono manquant
    $venteLastSaved = null;
    if (array_key_exists('prix_net_vendeur', $_POST))                      $venteLastSaved = 'net';
    elseif (array_key_exists('honoraires', $_POST))                        $venteLastSaved = 'hono';
    elseif (array_key_exists('alur_pourcentage_honoraires_ttc', $_POST))   $venteLastSaved = 'pct';
    if ($venteLastSaved !== null) {
        prix_fai_recalc_save($pdo, $annonceId, $venteLastSaved);
    }

    loyer_majore_recalc_save($pdo, $annonceId);
    // Sync complement_loyer selon le mode (0 si reference/minore/libre, SUM si majore)
    complement_loyer_sync_from_mode($pdo, $annonceId);
    // loyer HC selon mode (libre manuel / majore / reference / minore)
    loyer_hc_recalc_save($pdo, $annonceId);
    // Force recalcul dépôt si toggle meuble OU changement de mode (impact loyer HC)
    $forceDepot = array_key_exists('meuble', $_POST) || array_key_exists('loyer_mode', $_POST);
    depot_garantie_recalc_save($pdo, $annonceId, $forceDepot);

    // Honoraires : le helper lit la valeur DB (qui vient d'être mise à jour par
    // la saisie POST si elle était présente) et l'écrête au plafond si dépassement.
    $honoCalc = honoraires_recalc_save($pdo, $annonceId, false);

    // loyer_cc : dernier maillon (dépend des précédents)
    $loyerCC = loyer_cc_recalc_save($pdo, $annonceId);

    // Re-lecture pour renvoyer au front les valeurs finales (après cascade)
    $stFinal = $pdo->prepare("SELECT loyer, loyer_reference_majore, complement_loyer, depot_garantie, meuble, loyer_mode, zone_encadrement_loyer, prix, prix_net_vendeur, honoraires AS vente_honoraires, alur_pourcentage_honoraires_ttc, honoraires_charge_acquereur, honoraires_charge_vendeur FROM annonces WHERE id = ? LIMIT 1");
    $stFinal->execute([$annonceId]);
    $final = $stFinal->fetch(PDO::FETCH_ASSOC) ?: [];

    exit(json_encode([
        'ok' => true,
        'saved_at' => date('H:i'),
        'fields' => array_keys($data),
        'loyer_cc' => $loyerCC,
        'loyer_hc' => isset($final['loyer'])                  ? (float)$final['loyer']                  : null,
        'loyer_reference_majore' => isset($final['loyer_reference_majore']) ? (float)$final['loyer_reference_majore'] : null,
        'complement_loyer'       => isset($final['complement_loyer'])       ? (float)$final['complement_loyer']       : null,
        'depot_garantie'         => isset($final['depot_garantie'])         ? (float)$final['depot_garantie']         : null,
        'meuble'                 => isset($final['meuble'])                 ? (int)$final['meuble']                   : null,
        'loyer_mode'             => isset($final['loyer_mode'])             ? (string)$final['loyer_mode']            : null,
        'zone_encadrement_loyer' => isset($final['zone_encadrement_loyer']) ? (int)$final['zone_encadrement_loyer']   : null,
        'encadrement_auto'       => $encAutoResult,
        // Vente — valeurs finales après recalcul backend
        'vente' => [
            'prix_fai'          => isset($final['prix'])                  ? (float)$final['prix']                  : null,
            'prix_net_vendeur'  => isset($final['prix_net_vendeur'])      ? (float)$final['prix_net_vendeur']      : null,
            'honoraires'        => isset($final['vente_honoraires'])      ? (float)$final['vente_honoraires']      : null,
            'pct_alur'          => isset($final['alur_pourcentage_honoraires_ttc']) ? (float)$final['alur_pourcentage_honoraires_ttc'] : null,
            'charge_acquereur'  => isset($final['honoraires_charge_acquereur'])     ? (int)$final['honoraires_charge_acquereur']       : null,
            'charge_vendeur'    => isset($final['honoraires_charge_vendeur'])       ? (int)$final['honoraires_charge_vendeur']         : null,
        ],
        'honoraires' => $honoCalc,
    ]));
} catch (Throwable $e) {
    error_log('[annonce_autosave] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
