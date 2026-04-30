<?php
declare(strict_types=1);

/**
 * Autosave AJAX endpoint for bien_ajouter.php
 * Receives the form fields (no files) and updates the bien + annonce rows.
 * Returns JSON { ok: true, saved_at: "HH:MM" } or { ok: false, error: "..." }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

$bienId = isset($_POST['_edit_id']) && ctype_digit((string)$_POST['_edit_id']) ? (int)$_POST['_edit_id'] : 0;
if ($bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun bien à sauvegarder']));
}

// Verify ownership — super-admin (role=1) bypass le filtre société pour pouvoir éditer n'importe quel bien
$isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if ($isSuperAdmin) {
    $stmt = $pdo->prepare("SELECT id, statut_bien FROM biens WHERE id = ?");
    $stmt->execute([$bienId]);
} else {
    $stmt = $pdo->prepare("SELECT id, statut_bien FROM biens WHERE id = ? AND id_societe = ?");
    $stmt->execute([$bienId, $societeId]);
}
$bienCheckRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bienCheckRow) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
}
$bienEstActif = ((string)($bienCheckRow['statut_bien'] ?? '') === 'actif');

// ⚠️ Guard flow 2026-04-22 : si le bien est actif, l'adresse et le propriétaire
// sont VERROUILLÉS. Toute tentative de modifier ces champs depuis l'UI est rejetée.
// L'utilisateur doit d'abord dé-valider le bien (api/bien_validate.php action=invalidate).
if ($bienEstActif) {
    $lockedFields = ['adresse_1', 'adresse_2', 'code_postal', 'ville', 'latitude', 'longitude',
                     'google_place_id', 'adresse_formatted', 'id_immeuble_selected',
                     'id_proprietaire'];
    $attempted = [];
    foreach ($lockedFields as $_lf) {
        if (array_key_exists($_lf, $_POST) && (string)$_POST[$_lf] !== '') {
            $attempted[] = $_lf;
        }
    }
    if (!empty($attempted)) {
        http_response_code(403);
        exit(json_encode([
            'ok' => false,
            'error' => 'Champs verrouillés (bien actif) : ' . implode(', ', $attempted) . '. Dévalide le bien d\'abord.',
            'locked_fields' => $attempted,
            'statut_bien'   => 'actif',
        ]));
    }
}

// Helpers
$str  = static fn(string $k, string $d = ''): string => trim((string)($_POST[$k] ?? $d));
$int  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
$flt  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;
$bool = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)(bool)(int)$_POST[$k] : 0;

// ══════════════════════════════════════════════════════════════
// Map POST field → biens column
// ══════════════════════════════════════════════════════════════
$data = [
    // ── Identification ──
    'designation'             => $str('designation'),
    'reference_bien'          => $str('reference_bien'),
    'reference_externe'       => $str('reference_externe'),
    'sous_type_bien'          => $str('sous_type_bien'),
    'usage_bien'              => $str('usage_bien'),
    'statut_bien'             => $str('statut_bien'),
    'type_commercialisation'  => $str('type_commercialisation'),
    'lot_principal'           => $str('lot_principal'),
    'lot_secondaire'          => $str('lot_secondaire'),
    'standing'                => $str('standing'),
    'etat_bien'               => $str('etat_bien'),
    'disponibilite_bien'      => $str('disponibilite_bien'),
    'disponible_le'           => $str('disponibilite_date') ?: null,
    'occupation_bien'         => $str('occupation_bien'),
    'louable_immediatement'   => $bool('louable_immediatement'),
    'adresse_visible_public'  => $bool('adresse_visible_public'),

    // ── Environnement ──
    'exposition'              => $str('exposition'),
    'vue'                     => $str('vue'),
    'nuisances'               => $str('nuisances'),
    'quartier'                => $str('quartier'),
    'ambiance'                => $str('ambiance'),
    'argument_phare'          => $str('argument_phare'),
    'points_interet'          => $str('points_interet'),
    'acces_transports'        => $str('acces_transports') ?: null,
    'distance_commerces'      => $str('distance_commerces') ?: null,
    'altitude'                => $int('altitude'),

    // ── Surfaces ──
    'surface_habitable'       => $flt('surface_habitable'),
    'surface_carrez'          => $flt('surface_carrez'),
    'surface_sejour'          => $flt('surface_sejour'),
    'surface_totale'          => $flt('surface_totale'),
    'surface_terrain'         => $flt('surface_terrain'),
    'surface_balcon'          => $flt('surface_balcon'),
    'surface_terrasse'        => $flt('surface_terrasse'),
    'surface_jardin'          => $flt('surface_jardin'),
    'surface_cave'            => $flt('surface_cave'),
    'surface_garage'          => $flt('surface_garage'),
    'surface_box'             => $flt('surface_box'),
    'surface_veranda'         => $flt('surface_veranda'),
    'surface_annexe'          => $flt('surface_annexe'),
    'hauteur_sous_plafond'    => $flt('hauteur_sous_plafond'),

    // ── Composition ──
    'annee_construction'      => $int('annee_construction'),
    'etage'                   => $int('etage'),
    'nb_niveaux'              => $int('nb_niveaux'),
    'nb_pieces'               => $int('nb_pieces'),
    'nb_chambres'             => $int('nb_chambres'),
    'nb_salles_bain'          => $int('nb_salles_bain'),
    'nb_salles_eau'           => $int('nb_salles_eau'),
    'nb_wc'                   => $int('nb_wc'),
    'parking_nb'              => $int('parking_nb'),
    'numero_porte'            => $str('numero_porte'),
    'dernier_etage'           => $bool('dernier_etage'),

    // ── Équipements ──
    'cuisine_type'            => $str('cuisine_type'),
    'cuisine_equipee'         => $bool('cuisine_equipee'),
    'ascenseur'               => $bool('ascenseur'),
    'interphone'              => $bool('interphone'),
    'digicode'                => $bool('digicode'),
    'alarme'                  => $bool('alarme'),
    'climatisation'           => $bool('climatisation'),
    'fibre'                   => $bool('fibre'),
    'double_vitrage'          => $bool('double_vitrage'),
    'volets_roulants'         => $bool('volets_roulants'),
    'cheminee'                => $bool('cheminee'),
    'balcon'                  => $bool('balcon'),
    'terrasse'                => $bool('terrasse'),
    'jardin'                  => $bool('jardin'),
    'cour'                    => $bool('cour'),
    'cave'                    => $bool('cave'),
    'grenier'                 => $bool('grenier'),
    'garage'                  => $bool('garage'),
    'box'                     => $bool('box'),
    'piscine'                 => $bool('piscine'),
    'dependances'             => $bool('dependances'),
    'acces_camion'            => $bool('acces_camion'),
    'vitrine'                 => $bool('vitrine'),
    'animaux_acceptes'        => $bool('animaux_acceptes'),
    'fumeur_accepte'          => $bool('fumeur_accepte'),

    // ── Chauffage / Énergie ──
    'chauffage_type'          => $str('chauffage_type'),
    'chauffage_energie'       => $str('chauffage_energie'),
    'eau_chaude_type'         => $str('eau_chaude_type'),
    'menuiseries'             => $str('menuiseries'),
    'isolation'               => $str('isolation'),
    // VMC / plancher / régulation / solaire — absents du mapping initial
    // (écrits via Card 3 de bien_detail_v2)
    'chauffage_vmc'           => $bool('chauffage_vmc'),
    'chauffage_vmc_df'        => $bool('chauffage_vmc_df'),
    'chauffage_plancher'      => $bool('chauffage_plancher'),
    'chauffage_thermostat'    => $bool('chauffage_thermostat'),
    'chauffage_regulateur'    => $bool('chauffage_regulateur'),
    'eau_chaude_solaire'      => $bool('eau_chaude_solaire'),

    // ── DPE / GES ──
    'dpe_classe'              => $str('dpe_classe'),
    'ges_classe'              => $str('ges_classe'),
    'dpe_valeur'              => $flt('dpe_valeur'),
    'ges_valeur'              => $flt('ges_valeur'),
    'dpe_valeur_conso_primaire' => $flt('dpe_valeur_conso_primaire'),
    'dpe_valeur_conso_finale'   => $flt('dpe_valeur_conso_finale'),
    'date_indice_prix_energies' => $str('date_indice_prix_energies') ?: null,
    'montant_estime_depenses_min' => $flt('montant_estime_depenses_min'),
    'montant_estime_depenses_max' => $flt('montant_estime_depenses_max'),
    'dpe_date_realisation'    => $str('dpe_date_realisation'),
    'dpe_version'             => $str('dpe_version'),
    'dpe_vierge'              => $bool('dpe_vierge'),
    'dpe_reference_certificat'=> $str('dpe_reference_certificat'),

    // ── ERP / Géorisques ──
    'obligation_debroussaillement' => $bool('obligation_debroussaillement'),
    'erp_date_realisation'    => $str('erp_date_realisation') ?: null,
    'risque_inondation_g_score' => $str('risque_inondation_g_score'),
    'risque_inondation_p_score' => $str('risque_inondation_p_score'),

    // ── Prix / Loyers (table biens) ──
    'loyer_hc'                => $flt('loyer_hc'),
    'charges_locatives'       => $flt('charges_locatives'),
    'depot_garantie'          => $flt('depot_garantie'),
    'loyer_meuble'            => $flt('loyer_meuble'),
    'honoraires_locataire'    => $flt('honoraires_locataire'),
    'honoraires_inclus'       => $str('honoraires_inclus') ?: null,
    'honoraires_detail'       => $str('honoraires_detail') ?: null,
    'zone_tendue'             => $str('zone_tendue'),
    'prix_vente_estime'       => $flt('prix_vente_estime'),
    'rentabilite_brute_estimee' => $flt('rentabilite_brute_estimee'),
    'montant_travaux_estime'  => $flt('montant_travaux_estime'),

    // ── Estimation agence ──
    'estimation_agence_vente'    => $flt('estimation_agence_vente'),
    'estimation_agence_location' => $flt('estimation_agence_location'),
    'estimation_agence_date'     => $str('estimation_agence_date') ?: null,
    'estimation_agence_notes'    => $str('estimation_agence_notes'),

    // ── Copropriété ──
    'bien_en_copropriete'     => $bool('bien_en_copropriete'),
    'copro_nb_lots'           => $int('copro_nb_lots'),
    'copro_quote_part_charges'=> $flt('copro_quote_part_charges'),
    'syndic_type'             => $str('syndic_type'),
    'alur_syndicat_statut'    => $str('alur_syndicat_statut'),
    'copro_travaux_nature'    => $str('copro_travaux_nature'),

    // ── Encadrement loyers (stocké côté biens) ──
    'enc_zone'                => $str('enc_zone'),
    'enc_loyer_ref'           => $flt('enc_loyer_ref'),
    'enc_loyer_min'           => $flt('enc_loyer_min'),
    'enc_loyer_max'           => $flt('enc_loyer_max'),
    'enc_complement'          => $flt('enc_complement'),

    // ── Description / SEO ──
    'description'             => $str('description'),
    'reprise_descriptif'      => $str('reprise_descriptif'),
    'points_forts'            => $str('points_forts'),
    'mots_cles'               => $str('mots_cles'),
    'commentaire'             => $str('commentaire'),
    'titre_seo'               => $str('titre_seo'),
    'meta_description'        => $str('meta_description'),
    'accroche_commerciale'    => $str('accroche_commerciale'),
];

// Note : la conversion type_bien (code) → id_type_bien (id local en base) se
// fait APRÈS le bloc protectedFields ci-dessous — sinon l'unset id_type_bien
// (POST['id_type_bien'] absent quand le frontend envoie type_bien) écraserait
// la valeur résolue ici. La résolution par code est obligatoire car les IDs
// auto-increment de types_bien ne sont pas portables entre dev/prod.

// id_proprietaire — soit sélectionné, soit création à la volée
$proprioId = $int('id_proprietaire');
if ($proprioId !== null && $proprioId > 0) {
    $data['id_proprietaire'] = $proprioId;
} else {
    // Création propriétaire à la volée si champs remplis
    $pNom    = $str('proprio_nom');
    $pPrenom = $str('proprio_prenom');
    if ($pNom !== '') {
        $stmtNewP = $pdo->prepare("
            INSERT INTO proprietaires (nom, prenom, societe, telephone, email, adresse_1, actif, date_creation, date_modification)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmtNewP->execute([
            $pNom,
            $pPrenom ?: null,
            $str('proprio_societe') ?: null,
            $str('proprio_telephone') ?: null,
            $str('proprio_email') ?: null,
            $str('proprio_adresse') ?: null,
        ]);
        $newProprioId = (int)$pdo->lastInsertId();
        if ($newProprioId > 0) {
            $data['id_proprietaire'] = $newProprioId;
        }
    }
}

// ── Mandats (table séparée) ──
$mandatNumero   = $str('mandats_numero');
$mandatType     = $str('mandats_type');
$mandatNature   = $str('mandats_nature');
$mandatDateSig  = $str('mandats_date_signature');
$mandatDateDeb  = $str('mandats_date_debut');
$mandatDateFin  = $str('mandats_date_fin');
$mandatHono     = $flt('mandats_honoraires');

// Sauvegarder si au moins un champ mandat est rempli
if ($mandatType !== '' || $mandatNumero !== '' || $mandatDateSig !== '') {
    $stmtMandatExist = $pdo->prepare("SELECT id FROM mandats WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $stmtMandatExist->execute([$bienId]);
    $mandatId = (int)$stmtMandatExist->fetchColumn();

    if ($mandatId > 0) {
        $pdo->prepare("
            UPDATE mandats SET numero_mandat=?, type_mandat=?, nature_mandat=?,
                   date_signature=?, date_debut=?, date_fin=?, honoraires=?,
                   exclusif=?, date_modification=NOW()
            WHERE id=?
        ")->execute([
            $mandatNumero ?: null, $mandatType ?: null, $mandatNature ?: null,
            $mandatDateSig ?: null, $mandatDateDeb ?: null, $mandatDateFin ?: null, $mandatHono,
            $mandatType === 'exclusif' ? 1 : 0,
            $mandatId,
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO mandats (id_bien, numero_mandat, type_mandat, nature_mandat,
                   date_signature, date_debut, date_fin, honoraires,
                   exclusif, statut, date_creation, date_modification)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW(), NOW())
        ")->execute([
            $bienId,
            $mandatNumero ?: null, $mandatType ?: null, $mandatNature ?: null,
            $mandatDateSig ?: null, $mandatDateDeb ?: null, $mandatDateFin ?: null, $mandatHono,
            $mandatType === 'exclusif' ? 1 : 0,
        ]);
    }
}

// ── Mini-cards relation tables (vue, chauffage, énergie) ──
$vueIds      = isset($_POST['vue_ids']) && is_array($_POST['vue_ids']) ? array_map('intval', $_POST['vue_ids']) : [];
$chauffIds   = isset($_POST['chauffage_ids']) && is_array($_POST['chauffage_ids']) ? array_map('intval', $_POST['chauffage_ids']) : [];
$energieIds  = isset($_POST['energie_ids']) && is_array($_POST['energie_ids']) ? array_map('intval', $_POST['energie_ids']) : [];

$mcTables = [
    'bien_vues'     => ['id_societe_vue',      $vueIds],
    'bien_chauffages' => ['id_societe_chauffage', $chauffIds],
    'bien_energies' => ['id_societe_energie',  $energieIds],
];
foreach ($mcTables as $table => [$fkCol, $ids]) {
    if (!empty($ids)) {
        $pdo->prepare("DELETE FROM `{$table}` WHERE id_bien = ?")->execute([$bienId]);
        $stmtMc = $pdo->prepare("INSERT IGNORE INTO `{$table}` (id_bien, `{$fkCol}`) VALUES (?, ?)");
        foreach ($ids as $mcId) {
            if ($mcId > 0) $stmtMc->execute([$bienId, $mcId]);
        }
    }
}

// ══════════════════════════════════════════════════════════════
// Address fields — create/update immeuble and link to bien
// ══════════════════════════════════════════════════════════════
$adresse1    = $str('adresse_1');
$adresse2    = $str('adresse_2');
$codePostal  = $str('code_postal');
$ville       = $str('ville');
$pays        = $str('pays', 'France');
$latitude    = $str('latitude');
$longitude   = $str('longitude');

// Détecte si la colonne adresse_cle existe dans immeubles
// (Hostinger sans migration sync_phase1_structure_remote.sql → la colonne
// n'existe pas, le SQL planterait silencieusement en transaction).
static $_immHasAdresseCle = null;
if ($_immHasAdresseCle === null) {
    try {
        $_immHasAdresseCle = (bool)$pdo->query("SHOW COLUMNS FROM immeubles LIKE 'adresse_cle'")->fetchColumn();
    } catch (Throwable) { $_immHasAdresseCle = false; }
}
$adresseCle = mb_strtolower(trim("$adresse1 $adresse2 $codePostal $ville"));

// id_immeuble_selected : si envoyé par le modal d'adresse (quand l'utilisateur a
// sélectionné un immeuble existant dans l'autocomplete local), on l'utilise en
// priorité pour lier directement le bien — évite la création de doublons.
$immeubleSelected = isset($_POST['id_immeuble_selected']) && ctype_digit((string)$_POST['id_immeuble_selected'])
    ? (int)$_POST['id_immeuble_selected']
    : 0;

if ($adresse1 !== '' || $immeubleSelected > 0) {
    $stmtImm = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ?");
    $stmtImm->execute([$bienId]);
    $immId = (int)$stmtImm->fetchColumn();

    // Cas 1 : l'user a choisi explicitement un immeuble existant dans le modal
    //         → on ré-attache le bien à cet immeuble (même s'il en avait déjà un autre)
    if ($immeubleSelected > 0) {
        // Vérifie que l'immeuble existe (sanity check)
        $chkImm = $pdo->prepare("SELECT id FROM immeubles WHERE id = ? LIMIT 1");
        $chkImm->execute([$immeubleSelected]);
        if ((int)$chkImm->fetchColumn() === $immeubleSelected) {
            $immId = $immeubleSelected;
            $data['id_immeuble'] = $immId;
            // Pas d'UPDATE de l'immeuble : on ne modifie pas un immeuble partagé par
            // plusieurs biens sans raison. L'user doit passer par agency_immeuble_form
            // pour éditer l'immeuble lui-même.
        }
    }
    // Cas 2 : le bien avait déjà un immeuble lié → UPDATE dynamique (ne touche
    // QUE les colonnes présentes dans le POST, évite d'écraser ville/cp à NULL
    // quand l'user ne modifie que adresse_1 au clavier).
    elseif ($immId > 0) {
        $updCols   = [];
        $updParams = [];
        if (array_key_exists('adresse_1',   $_POST)) { $updCols[] = 'adresse_1 = ?';   $updParams[] = $adresse1   ?: null; }
        if (array_key_exists('adresse_2',   $_POST)) { $updCols[] = 'adresse_2 = ?';   $updParams[] = $adresse2   ?: null; }
        if (array_key_exists('code_postal', $_POST)) { $updCols[] = 'code_postal = ?'; $updParams[] = $codePostal ?: null; }
        if (array_key_exists('ville',       $_POST)) { $updCols[] = 'ville = ?';       $updParams[] = $ville      ?: null; }
        if (array_key_exists('pays',        $_POST)) { $updCols[] = 'pays = ?';        $updParams[] = $pays; }
        if (array_key_exists('latitude',    $_POST)) { $updCols[] = 'latitude = ?';    $updParams[] = $latitude   ?: null; }
        if (array_key_exists('longitude',   $_POST)) { $updCols[] = 'longitude = ?';   $updParams[] = $longitude  ?: null; }

        if (!empty($updCols)) {
            // adresse_cle : recalculer UNIQUEMENT si au moins un champ d'adresse est modifié
            if ($_immHasAdresseCle) {
                $touchesAddr = array_key_exists('adresse_1', $_POST)
                            || array_key_exists('adresse_2', $_POST)
                            || array_key_exists('code_postal', $_POST)
                            || array_key_exists('ville',     $_POST);
                if ($touchesAddr) {
                    // Recharge les valeurs actuelles pour calculer la clé complète
                    $stCur = $pdo->prepare("SELECT adresse_1, adresse_2, code_postal, ville FROM immeubles WHERE id = ?");
                    $stCur->execute([$immId]);
                    $cur = $stCur->fetch(PDO::FETCH_ASSOC) ?: [];
                    $finalA1 = array_key_exists('adresse_1',   $_POST) ? $adresse1   : ((string)($cur['adresse_1']   ?? ''));
                    $finalA2 = array_key_exists('adresse_2',   $_POST) ? $adresse2   : ((string)($cur['adresse_2']   ?? ''));
                    $finalCP = array_key_exists('code_postal', $_POST) ? $codePostal : ((string)($cur['code_postal'] ?? ''));
                    $finalV  = array_key_exists('ville',       $_POST) ? $ville      : ((string)($cur['ville']       ?? ''));
                    $updCols[]   = 'adresse_cle = ?';
                    $updParams[] = mb_strtolower(trim("$finalA1 $finalA2 $finalCP $finalV"));
                }
            }
            $updParams[] = $immId;
            $pdo->prepare("UPDATE immeubles SET " . implode(', ', $updCols) . " WHERE id = ?")
                ->execute($updParams);
        }
    }
    // Cas 3 : bien sans immeuble → cherche par adresse_cle, sinon crée
    else {
        if ($_immHasAdresseCle) {
            $stmtFind = $pdo->prepare("SELECT id FROM immeubles WHERE adresse_cle = ? LIMIT 1");
            $stmtFind->execute([$adresseCle]);
            $immId = (int)$stmtFind->fetchColumn();
        }
        if ($immId <= 0) {
            $cols = ['id_societe','id_agence','adresse_1','adresse_2','code_postal','ville','pays','latitude','longitude'];
            $vals = [
                $societeId ?: null,
                (int)($_SESSION['id_agence'] ?? 0) ?: null,
                $adresse1,
                $adresse2 ?: null,
                $codePostal ?: null,
                $ville ?: null,
                $pays,
                $latitude ?: null,
                $longitude ?: null,
            ];
            if ($_immHasAdresseCle) { $cols[] = 'adresse_cle'; $vals[] = $adresseCle; }
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO immeubles (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)")
                ->execute($vals);
            $immId = (int)$pdo->lastInsertId();
        }
        if ($immId > 0) {
            $data['id_immeuble'] = $immId;
        }
    }
}

// ══════════════════════════════════════════════════════════════
// Protection : ne JAMAIS écraser statut_bien avec une valeur vide.
// Cause d'un bug historique : Express n'a pas d'input statut_bien, donc
// un autosave depuis Express envoyait '' et écrasait le 'brouillon' initial.
// Résultat : biens fantômes invisibles du modal purge cascade (filtre par
// liste de statuts valides).
// ══════════════════════════════════════════════════════════════
if (isset($data['statut_bien']) && trim((string)$data['statut_bien']) === '') {
    unset($data['statut_bien']);
}

// ══════════════════════════════════════════════════════════════
// Protection GENERALE pour les champs "sensibles" qui peuvent être
// absents du form courant (Express stocke les chips env dans state.env JS
// sans input form) OU vides sur un form simplifié (Express input text DPE
// vide quand l'user n'a pas saisi). Sans cette protection, l'autosave
// écrase les valeurs persistées par finalize avec '' / NULL.
//
// 2 catégories protégées :
//   - ENV : exposition, vue, ambiance, nuisances, acces/distance, quartier,
//           points_interet, argument_phare, reprise_descriptif, accroche_commerciale
//   - DPE/CHAUFFAGE : dpe_classe, ges_classe, dpe_valeur, ges_valeur,
//           dpe_date_realisation, chauffage_type, chauffage_energie, eau_chaude_type
//
// Règle : si la clé est absente de $_POST OU si sa valeur POST est vide,
// on retire la clé de $data → l'UPDATE conserve la valeur BDD existante.
// ══════════════════════════════════════════════════════════════
$protectedFields = [
    // Environnement (chips JS, pas d'input direct dans Express)
    'exposition', 'vue', 'ambiance', 'nuisances',
    'acces_transports', 'distance_commerces',
    'quartier', 'points_interet', 'argument_phare',
    'reprise_descriptif', 'accroche_commerciale',
    // DPE / chauffage (inputs présents mais souvent vides en Express)
    'dpe_classe', 'ges_classe', 'dpe_valeur', 'ges_valeur',
    'dpe_date_realisation',
    // Champs DPE avancés remontés par l'import diag IA (ne doivent JAMAIS
    // être écrasés par un autosave avec valeur vide — cela supprimait les
    // valeurs extraites du PDF)
    'dpe_version', 'dpe_vierge', 'dpe_reference_certificat',
    'dpe_valeur_conso_primaire', 'dpe_valeur_conso_finale',
    'montant_estime_depenses_min', 'montant_estime_depenses_max',
    'annee_reference_depenses',
    'date_indice_prix_energies',
    'altitude',
    // Chauffage / isolation
    'chauffage_type', 'chauffage_energie', 'eau_chaude_type',
    'double_vitrage', 'volets_roulants', 'menuiseries',
    // Diagnostiqueur (info complémentaire du DPE)
    'diagnostiqueur_nom', 'diagnostiqueur_societe',
    // Surfaces détectées par le DPE
    'surface_sejour', 'surface_carrez',
    // ── Caractéristiques principales (bien_detail_v2 icon-radios) ──
    // CRITIQUE : l'autosave v2 envoie UN champ à la fois, donc les autres
    // doivent être protégés sinon ils sont écrasés à '' à chaque clic.
    'id_type_bien', 'sous_type_bien', 'usage_bien',
    'etat_bien', 'standing', 'statut_bien', 'type_commercialisation',
    // ── Adresse (idem : autosave v2 sur chaque input indépendamment) ──
    'adresse_1', 'adresse_2', 'code_postal', 'ville',
    // ── Identification (autosave v2 envoie 1 champ à la fois — sans ces
    // protections, chaque clic ailleurs écrasait la ref/désignation à '',
    // forçant ref_generate_bien à regénérer un nouveau numéro à chaque load)
    'reference_bien', 'reference_externe', 'designation', 'lot_principal', 'lot_secondaire',
    // ── Pièces / Surfaces / Équipements / Dépendances (bien_detail_v2 Card 2) ──
    'nb_pieces', 'nb_chambres', 'nb_salles_bain', 'nb_salles_eau', 'nb_wc',
    'nb_niveaux', 'parking_nb',
    'surface_habitable', 'surface_totale', 'surface_terrain',
    'surface_balcon', 'surface_terrasse', 'surface_jardin', 'surface_cave',
    'surface_garage', 'surface_box', 'surface_veranda', 'surface_annexe',
    'hauteur_plafond', 'annee_construction',
    'balcon', 'terrasse', 'jardin', 'cour', 'cave', 'grenier',
    'garage', 'box', 'piscine', 'dependances', 'acces_camion', 'vitrine',
    'cuisine_type', 'cuisine_equipee', 'ascenseur', 'interphone',
    'digicode', 'alarme', 'fibre', 'cheminee',
    // Environnement Card 4 v2
    'dernier_etage', 'numero_porte', 'adresse_visible_public', 'etage',
    // Card 3 v2 : chauffage / énergie / VMC / isolation
    'chauffage_plancher', 'chauffage_thermostat', 'chauffage_regulateur',
    'chauffage_vmc', 'chauffage_vmc_df', 'climatisation',
    'eau_chaude_solaire', 'isolation',
    // ── Encadrement loyer (Card 2 Annonce) — chantier 2026-04-22 ──
    // CRITIQUE : ces champs sont saisis au fil de l'eau dans Card 2 Encadrement.
    // Sans protection, TOUT autre autosave (surface, charges, etc.) les remet
    // à NULL parce que $flt('enc_loyer_max') retourne null quand le champ
    // n'est pas dans le POST → UPDATE biens SET enc_loyer_max = NULL → data perdue.
    'enc_zone', 'enc_loyer_ref', 'enc_loyer_max', 'enc_loyer_min', 'enc_complement',
    'zone_tendue',
    // ── Conditions financières (Card 1 Annonce) ──
    // Idem : charges locatives et honoraires sont saisis dans Card 1 et doivent
    // survivre aux autosaves des autres cards.
    'charges_locatives', 'honoraires_locataire', 'honoraires_inclus', 'honoraires_detail',
    'loyer_hc', 'loyer_meuble', 'depot_garantie',
    // ── Estimation / Projection (pareil — saisies ponctuelles) ──
    'prix_vente_estime', 'rentabilite_brute_estimee', 'montant_travaux_estime',
    'estimation_agence_vente', 'estimation_agence_location',
    'estimation_agence_date', 'estimation_agence_notes',
];
foreach ($protectedFields as $f) {
    $raw = $_POST[$f] ?? null;
    $isEmpty = ($raw === null) || (is_string($raw) && trim($raw) === '');
    if ($isEmpty) {
        unset($data[$f]);
    }
}

// Résolution finale : type_bien (code stable) → ids en base (nouveau + legacy).
// Doit rester APRÈS protectedFields, sinon le unset id_type_bien (absent du POST
// quand le frontend envoie type_bien) écraserait la valeur résolue ici.
//
// Migration 20260430_bien_types : on alimente DEUX colonnes en cohérence :
//   - biens.id_bien_type → FK vers bien_types (référentiel unifié, source de
//     vérité pour LBC/SeLoger/FNAIM)
//   - biens.id_type_bien → FK historique vers types_bien_legacy (préservée
//     pour permettre un rollback du code sans toucher à la BDD)
// Cf. inc/bien_type_helper.php pour la logique de mapping (incl. fallback
// sémantique pour les codes nouveaux absents en legacy : studio→appartement,
// villa→maison, etc.).
$typeBienCode = $str('type_bien');
if ($typeBienCode !== '') {
    require_once dirname(__DIR__) . '/inc/bien_type_helper.php';
    $resolved = bien_type_resolve($pdo, $typeBienCode);

    // CRITIQUE : on FORCE l'écriture des deux colonnes même si l'une retourne
    // null. Sans ça, l'ancienne valeur reste en BDD (cas observé : user clique
    // "Appartement" → id_type_bien mis à jour vers legacy.appartement, mais
    // id_bien_type ne change pas → reste sur l'ancien type "maison" → le
    // COALESCE(bt.code, btb.code) au reload prend bt.code='maison' en priorité.
    //
    // Garde : ne pas tenter d'écrire id_bien_type si la colonne n'existe pas
    // (instances pré-migration 20260430_bien_types). On détecte une seule fois
    // par requête et on cache via static.
    static $hasIdBienTypeCol = null;
    if ($hasIdBienTypeCol === null) {
        try {
            $hasIdBienTypeCol = (bool)$pdo->query("SHOW COLUMNS FROM biens LIKE 'id_bien_type'")->fetchColumn();
        } catch (Throwable) { $hasIdBienTypeCol = false; }
    }
    if ($hasIdBienTypeCol) {
        $data['id_bien_type'] = $resolved['id_bien_type']; // peut être NULL si pas trouvé
    }
    $data['id_type_bien'] = $resolved['id_type_bien']; // peut être NULL si pas trouvé

    error_log(sprintf(
        '[bien_autosave] type_bien resolution — code=%s id_bien_type=%s id_type_bien=%s (col_new_exists=%s)',
        $typeBienCode,
        $resolved['id_bien_type'] === null ? 'NULL' : (string)$resolved['id_bien_type'],
        $resolved['id_type_bien'] === null ? 'NULL' : (string)$resolved['id_type_bien'],
        $hasIdBienTypeCol ? '1' : '0'
    ));
}

// ══════════════════════════════════════════════════════════════
// Build & execute UPDATE biens
// ══════════════════════════════════════════════════════════════
$sets = [];
$params = [];
foreach ($data as $col => $val) {
    $ph = ':' . $col;
    $sets[] = "`{$col}` = {$ph}";
    $params[$ph] = $val;
}
$params[':_id'] = $bienId;

try {
    $pdo->prepare("UPDATE biens SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = :_id")
        ->execute($params);

    // ── Propagation designation → annonces.accroche_commerciale si vide ──
    // Permet de remplir automatiquement l'accroche de la derniere annonce
    // du bien quand l'utilisateur saisit la designation commerciale et que
    // l'accroche n'a pas encore ete generee par l'IA.
    if (isset($data['designation']) && !empty($data['designation'])) {
        try {
            $desVal = (string)$data['designation'];
            $pdo->prepare("
                UPDATE annonces
                SET accroche_commerciale = ?, date_modification = NOW()
                WHERE id_bien = ?
                  AND (accroche_commerciale IS NULL OR accroche_commerciale = '')
            ")->execute([mb_substr($desVal, 0, 255), $bienId]);
        } catch (Throwable $e) {
            error_log('[bien_autosave propagate designation] ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Update annonces table (honoraires, mandats, locataire précédent, taxes…)
    //
    // PROTECTION CRITIQUE : ne faire l'UPDATE annonces QUE si au moins un
    // champ annonce est explicitement présent dans le POST. Sinon, l'autosave
    // d'UN champ biens (ex: type_commercialisation, sous_type_bien) déclencherait
    // un UPDATE annonces avec TOUS les champs vides → écrase description,
    // mandat_type, prix, etc. C'était le bug "description s'efface au clic
    // sur n'importe quel bouton".
    // ══════════════════════════════════════════════════════════════
    $annonceFieldsInPost = [
        'annonce_transaction', 'annonce_prix_vente', 'annonce_loyer', 'annonce_commercial_id',
        'description', 'texte_ia',
        'honoraires_charge_acquereur', 'honoraires_charge_vendeur',
        'alur_pourcentage_honoraires_ttc', 'pourcentage_honoraires_vendeur',
        'honoraires_negociation_cumules', 'url_tarifs_publics',
        'zone_encadrement_loyer', 'loyer_de_base', 'loyer_est_cc',
        'loyer_reference_majore', 'complement_loyer',
        'modalite_recuperation_charges_locatives', 'honoraires_etat_des_lieux',
        'mandat_numero', 'mandat_type', 'date_mandat', 'mandat_echeance',
        'ancien_loyer_montant', 'ancien_loyer_charges', 'ancien_loyer_date_revision',
        'ancien_locataire_date_sortie', 'ancien_loyer_communique',
        'taxe_fonciere', 'taxe_habitation',
        'cpl_libelle', 'cpl_montant',
    ];
    $hasAnnonceFieldInPost = false;
    foreach ($annonceFieldsInPost as $_k) {
        if (array_key_exists($_k, $_POST)) { $hasAnnonceFieldInPost = true; break; }
    }

    $annonceTransaction = $str('annonce_transaction');
    $stmtAnn = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $stmtAnn->execute([$bienId]);
    $annonceId = (int)$stmtAnn->fetchColumn();

    // Create annonce if needed — hérite id_societe/id_agence/id_user (commercial) du bien
    // ⚠️ 2026-04-22 : auto-création d'annonce DÉSACTIVÉE (flow validation bien obligatoire).
    // L'annonce est désormais créée EXCLUSIVEMENT via api/annonce_create.php, déclenché
    // par le modal UI "Créer une annonce ?" après validation du bien. On ne crée plus
    // automatiquement à la réception d'un `annonce_transaction` en POST.
    //
    // Ancien comportement (pour mémoire) : création silencieuse avec héritage
    // id_societe/id_agence/id_user du bien. Retiré pour garantir que chaque annonce
    // passe par le flow de validation Ubiflow explicite.

    if ($annonceId > 0 && $hasAnnonceFieldInPost) {
        $annData = [
            'type_transaction'       => $annonceTransaction ?: null,
            'id_user'                => $int('annonce_commercial_id'),
            'prix'                   => $flt('annonce_prix_vente'),
            'loyer'                  => $flt('annonce_loyer'),
            // loyer_cc : recalculé par inc/honoraires_helper.php (HC + charges + complément) — ne PAS l'écraser ici
            'description'            => $str('description'),
            'texte_ia'               => $str('texte_ia') ?: null,
            // Honoraires ALUR
            'honoraires_charge_acquereur'  => $bool('honoraires_charge_acquereur'),
            'honoraires_charge_vendeur'    => $bool('honoraires_charge_vendeur'),
            'alur_pourcentage_honoraires_ttc' => $flt('alur_pourcentage_honoraires_ttc'),
            'pourcentage_honoraires_vendeur'  => $flt('pourcentage_honoraires_vendeur'),
            'honoraires_negociation_cumules'  => $flt('honoraires_negociation_cumules'),
            'url_tarifs_publics'     => $str('url_tarifs_publics') ?: null,
            // Encadrement loyers
            'zone_encadrement_loyer' => $bool('zone_encadrement_loyer'),
            'loyer_de_base'          => $flt('loyer_de_base'),
            'loyer_est_cc'           => $bool('loyer_est_cc'),
            'loyer_reference_majore' => $flt('loyer_reference_majore'),
            'complement_loyer'       => $flt('complement_loyer'),
            'modalite_recuperation_charges_locatives' => $str('modalite_recuperation_charges_locatives') ?: null,
            'honoraires_etat_des_lieux' => $flt('honoraires_etat_des_lieux'),
            // Mandat
            'mandat_numero'          => $str('mandat_numero') ?: null,
            'mandat_type'            => $str('mandat_type') ?: null,
            'date_mandat'            => $str('date_mandat') ?: null,
            'mandat_echeance'        => $str('mandat_echeance') ?: null,
            // Locataire précédent
            'ancien_loyer_montant'   => $flt('ancien_loyer_montant'),
            'ancien_loyer_charges'   => $flt('ancien_loyer_charges'),
            'ancien_loyer_date_revision'    => $str('ancien_loyer_date_revision') ?: null,
            'ancien_locataire_date_sortie'  => $str('ancien_locataire_date_sortie') ?: null,
            'ancien_loyer_communique'       => $bool('ancien_loyer_communique'),
            // Taxes
            'taxe_fonciere'          => $flt('taxe_fonciere'),
            'taxe_habitation'        => $flt('taxe_habitation'),
        ];

        $annSets = [];
        $annParams = [];
        foreach ($annData as $col => $val) {
            $ph = ':a_' . $col;
            $annSets[] = "`{$col}` = {$ph}";
            $annParams[$ph] = $val;
        }
        $annParams[':a_id'] = $annonceId;
        $pdo->prepare("UPDATE annonces SET " . implode(', ', $annSets) . ", date_modification = NOW() WHERE id = :a_id")
            ->execute($annParams);

        // ── Lignes de complément de loyer ──
        $cplLibelles = isset($_POST['cpl_libelle']) && is_array($_POST['cpl_libelle']) ? $_POST['cpl_libelle'] : [];
        $cplMontants = isset($_POST['cpl_montant']) && is_array($_POST['cpl_montant']) ? $_POST['cpl_montant'] : [];
        if (!empty($cplLibelles)) {
            $pdo->prepare("DELETE FROM annonces_complement_loyer_lignes WHERE id_annonce = ?")->execute([$annonceId]);
            $stmtCpl = $pdo->prepare("
                INSERT INTO annonces_complement_loyer_lignes (id_annonce, libelle, montant, ordre, date_creation)
                VALUES (?, ?, ?, ?, NOW())
            ");
            foreach ($cplLibelles as $i => $lib) {
                $lib = trim((string)$lib);
                if ($lib === '') continue;
                $mt = isset($cplMontants[$i]) ? (float)$cplMontants[$i] : 0;
                $stmtCpl->execute([$annonceId, $lib, $mt, $i + 1]);
            }
        }
    }

    // Auto-activation brouillon -> actif dès qu'adresse + propriétaire renseignés
    $autoActivated = false;
    if ($bienId > 0) {
        require_once dirname(__DIR__) . '/inc/bien_auto_activate.php';
        $autoActivated = bien_maybe_activate($pdo, $bienId);
    }

    // ── Recalcul honoraires / loyer CC / dépôt / majoré si champs impactants modifiés ──
    // Déclencheurs : surface, charges, adresse (CP), zone_tendue, encadrement
    $triggers = [
        'surface_habitable', 'surface_totale', 'charges_locatives',
        'code_postal', 'adresse_1', 'zone_tendue',
        'enc_loyer_max', 'enc_loyer_ref', 'enc_loyer_min',
        'annonce_loyer', 'loyer_reference_majore',
    ];
    $shouldRecalc = false;
    foreach ($triggers as $_t) {
        if (array_key_exists($_t, $_POST)) { $shouldRecalc = true; break; }
    }
    $loyerCC = null; $honoCalc = null; $depot = null; $majore = null;
    $loyerHC = null; $complementLoyer = null; $encZone = null;
    if ($bienId > 0) {
        require_once dirname(__DIR__) . '/inc/honoraires_helper.php';
        // Auto-fill du label de zone (indépendant de $shouldRecalc — dès que le CP
        // est renseigné, on tente le lookup)
        if (array_key_exists('code_postal', $_POST) || array_key_exists('adresse_1', $_POST)) {
            $encZone = enc_zone_label_recalc_save($pdo, $bienId);
        }
        if ($shouldRecalc) {
            $stA = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
            $stA->execute([$bienId]);
            $aid = (int)$stA->fetchColumn();
            if ($aid > 0) {
                $majore   = loyer_majore_recalc_save($pdo, $aid);
                $loyerHC  = loyer_hc_recalc_save($pdo, $aid);
                $depot    = depot_garantie_recalc_save($pdo, $aid);
                $honoCalc = honoraires_recalc_save($pdo, $aid, false);
                $loyerCC  = loyer_cc_recalc_save($pdo, $aid);
                // Re-lecture du complement pour renvoyer valeur à jour
                $stC = $pdo->prepare("SELECT complement_loyer FROM annonces WHERE id = ? LIMIT 1");
                $stC->execute([$aid]);
                $cv = $stC->fetchColumn();
                $complementLoyer = $cv !== false && $cv !== null ? (float)$cv : null;
            }
        }
    }

    echo json_encode([
        'ok'             => true,
        'saved_at'       => date('H:i'),
        'auto_activated' => $autoActivated,
        'loyer_cc'       => $loyerCC,
        'loyer_hc'       => $loyerHC,
        'loyer_reference_majore' => $majore,
        'complement_loyer'       => $complementLoyer,
        'depot_garantie' => $depot,
        'enc_zone'       => $encZone,
        'honoraires'     => $honoCalc,
    ]);
} catch (Throwable $e) {
    error_log('[bien_autosave] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
