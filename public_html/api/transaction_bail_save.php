<?php
// api/transaction_bail_save.php — Crée une ligne bien_baux + classe le doc en GED + update biens
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_once __DIR__ . '/../inc/transaction_doc_lib.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

// Keepalive : l'utilisateur a pu garder la modale ouverte plusieurs minutes
$pdo = db_keepalive();
// Auto-ajout colonnes étendues bien_baux si pas encore présentes
tr_bail_ensure_columns($pdo);

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien    = (int)(post('id_bien') ?? 0);
$stagingId = (int)(post('staging_id') ?? 0);
if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager    = ($roleId === 1 || $roleId === 2);
$idSoc        = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAge        = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;
$idUser       = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

// Helper : récupère valeur POST, retourne null si vide
$pv = function (string $k) {
    $v = post($k);
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
};
$pvBool = function (string $k) {
    $v = post($k);
    return ($v === '1' || $v === 'on' || $v === 'true') ? 1 : 0;
};

try {
    // Scope bien
    $st = $pdo->prepare('SELECT id_societe, id_agence, id_proprietaire FROM biens WHERE id = ? LIMIT 1');
    $st->execute([$idBien]);
    $bien = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bien) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }
    if (!$isManager && $idSoc !== null && $bien['id_societe'] !== null && (int)$bien['id_societe'] !== $idSoc) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
    }

    // ── Construction de la ligne bien_baux ────────────────────
    $bail = [
        'id_bien'         => $idBien,
        'id_proprietaire' => (int)$bien['id_proprietaire'] ?: null,
        'id_societe'      => $bien['id_societe'] ? (int)$bien['id_societe'] : null,
        'id_agence'       => $bien['id_agence']  ? (int)$bien['id_agence']  : null,
        'bail_nature'     => $pv('bail_nature') ?? 'commercial',
        'bail_type'       => $pv('bail_type'),
        'usage_bien'      => $pv('usage_bien'),
        'destination_activite' => $pv('destination_activite'),
        'reference_bail'  => $pv('reference_bail'),
        'date_signature'  => $pv('date_signature'),
        'date_prise_effet'=> $pv('date_prise_effet'),
        'duree_mois'      => $pv('duree_mois'),
        'date_fin'        => $pv('date_fin'),
        'reconduction'    => $pv('reconduction'),
        // LOYER DE BASE (immuable à la signature)
        'loyer_mensuel_hc'    => $pv('loyer_mensuel_hc'),
        'complement_loyer'    => $pv('complement_loyer'),
        'charges_mensuelles'  => $pv('charges_mensuelles'),
        'charges_type'        => $pv('charges_type') ?? 'provisions',
        'tva_applicable'      => $pvBool('tva_applicable'),
        'tva_taux'            => $pv('tva_taux'),
        'indice_type'         => $pv('indice_type'),
        'indice_trimestre'    => $pv('indice_trimestre'),
        'indice_valeur'       => $pv('indice_valeur'),
        'date_revision_jour_mois' => $pv('date_revision_jour_mois'),
        'zone_tendue'         => $pvBool('zone_tendue'),
        'depot_garantie'      => $pv('depot_garantie'),
        'nb_termes_garantie'  => $pv('nb_termes_garantie'),
        'clause_resolutoire'  => post('clause_resolutoire') === '0' ? 0 : 1,
        'honoraires_bailleur_ttc'  => $pv('honoraires_bailleur_ttc'),
        'honoraires_locataire_ttc' => $pv('honoraires_locataire_ttc'),
        'honoraires_charge'   => $pv('honoraires_charge'),
        'locataire_type'      => $pv('locataire_type') ?? 'societe',
        'locataire_nom'       => $pv('locataire_nom'),
        'locataire_prenom'    => $pv('locataire_prenom'),
        'locataire_raison_sociale' => $pv('locataire_raison_sociale'),
        'locataire_siren'     => $pv('locataire_siren'),
        'locataire_email'     => $pv('locataire_email'),
        'locataire_telephone' => $pv('locataire_telephone'),
        // Représentants bailleur / locataire (V0.2)
        'bailleur_representant_nom'        => $pv('bailleur_representant_nom'),
        'bailleur_representant_qualite'    => $pv('bailleur_representant_qualite'),
        'bailleur_representant_email'      => $pv('bailleur_representant_email'),
        'bailleur_representant_telephone'  => $pv('bailleur_representant_telephone'),
        'locataire_representant_nom'       => $pv('locataire_representant_nom'),
        'locataire_representant_qualite'   => $pv('locataire_representant_qualite'),
        'locataire_representant_email'     => $pv('locataire_representant_email'),
        'locataire_representant_telephone' => $pv('locataire_representant_telephone'),
        // Caution
        'caution_type'        => $pv('caution_type') ?? 'aucune',
        'caution_nom'         => $pv('caution_nom'),
        'caution_prenom'      => $pv('caution_prenom'),
        // Assurance détaillée (refonte 2026-05-18)
        'renonciation_recours_locataire'  => (post('renonciation_recours_locataire') === '1' ? 1 : (post('renonciation_recours_locataire') === '0' ? 0 : null)),
        'renonciation_recours_bailleur'   => (post('renonciation_recours_bailleur')  === '1' ? 1 : (post('renonciation_recours_bailleur')  === '0' ? 0 : null)),
        'renonciation_recours_reciproque' => null, // calculé ci-dessous
        'assurance_surprimes_a_charge'    => in_array(post('assurance_surprimes_a_charge'), ['locataire','bailleur','partage'], true) ? post('assurance_surprimes_a_charge') : null,
        'assurance_justification_annuelle'=> (post('assurance_justification_annuelle') === '1' ? 1 : (post('assurance_justification_annuelle') === '0' ? 0 : null)),
        'assurance_risques_couverts'      => (function() {
            $v = post('assurance_risques_couverts');
            if (is_array($v))  return json_encode($v, JSON_UNESCAPED_UNICODE);
            if (is_string($v) && $v !== '') return $v;
            return null;
        })(),
        'conditions_particulieres' => $pv('conditions_particulieres'),
        // Métadonnées
        'metadata'            => $pv('metadata'),
        'statut'              => $pv('statut') ?? 'actif',
        'commentaire_admin'   => $pv('commentaire'),
        'id_user_created'     => $idUser ?: null,
    ];

    // Filtre défensif : on enlève les colonnes qui n'existent pas encore (au cas où la migration tarde)
    try {
        $existCols = [];
        $stCols = $pdo->query('SHOW COLUMNS FROM bien_baux');
        while ($r = $stCols->fetch(PDO::FETCH_ASSOC)) $existCols[$r['Field']] = true;
        foreach (array_keys($bail) as $k) {
            if (!isset($existCols[$k])) unset($bail[$k]);
        }
    } catch (Throwable $e) {}

    // Calcul total_mensuel si absent
    $loyer = (float)($bail['loyer_mensuel_hc'] ?? 0);
    $compl = (float)($bail['complement_loyer'] ?? 0);
    $chgs  = (float)($bail['charges_mensuelles'] ?? 0);
    $total = $loyer + $compl + $chgs;
    if ($total > 0) $bail['total_mensuel'] = $total;

    // Calcul renonciation_recours_reciproque dérivé : OUI seulement si les 2 sens à OUI
    if ($bail['renonciation_recours_locataire'] === 1 && $bail['renonciation_recours_bailleur'] === 1) {
        $bail['renonciation_recours_reciproque'] = 1;
    } elseif ($bail['renonciation_recours_locataire'] === 0 && $bail['renonciation_recours_bailleur'] === 0) {
        $bail['renonciation_recours_reciproque'] = 0;
    }

    // Filtre NULLs pour ne pas écrire de colonne inutile
    $bail = array_filter($bail, fn($v) => $v !== null);

    $cols = array_keys($bail);
    $placeholders = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO bien_baux (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($bail as $k => $v) $stmt->bindValue(':' . $k, $v);
    $stmt->execute();
    $bailId = (int)$pdo->lastInsertId();

    // ── Update bien : occupation + loyer_hc si vide ──────────
    $updateBien = [];
    if (($bail['statut'] ?? 'actif') === 'actif') {
        $updateBien[] = "statut_occupation = 'occupé'";
    }
    if (isset($bail['loyer_mensuel_hc'])) {
        $updateBien[] = "loyer_hc = COALESCE(loyer_hc, " . (float)$bail['loyer_mensuel_hc'] . ")";
    }
    if (!empty($updateBien)) {
        $pdo->exec('UPDATE biens SET ' . implode(', ', $updateBien) . ', date_modification = NOW() WHERE id = ' . (int)$idBien);
    }

    // ─── PROPAGATION DESCRIPTIF BIEN ↔ table `biens` ────────
    // Le bail contient souvent des infos qui décrivent le BIEN lui-même
    // (lot, tantièmes, parkings, surfaces, étage…). On les pousse vers
    // `biens` en UPDATE défensif : on n'écrase QUE les champs vides.
    $iaMetaRaw = $bail['metadata'] ?? null;
    if (is_string($iaMetaRaw) && $iaMetaRaw !== '') {
        $iaData = json_decode($iaMetaRaw, true);
        if (is_array($iaData)) {
            // Détecte colonnes biens dispo (défensif)
            $colsBien = [];
            try {
                $cstB = $pdo->query('SHOW COLUMNS FROM biens');
                while ($r = $cstB->fetch(PDO::FETCH_ASSOC)) $colsBien[$r['Field']] = true;
            } catch (Throwable $e) {}

            // Mapping IA → colonnes biens (UPDATE si col vide en base)
            // Pour les locaux commerciaux/pros : surface va dans surface_commerciale/bureau
            // ET surface_totale, mais PAS dans surface_habitable (réservée à l'habitation).
            $usageIa  = (string)($iaData['bien_destination_usage'] ?? '');
            $isHabit  = ($usageIa === 'habitation' || $usageIa === '');
            $isCommer = ($usageIa === 'commercial' || $usageIa === 'mixte');
            $isPro    = ($usageIa === 'professionnel');
            $surfaceM2 = $iaData['bien_surface_totale_m2'] ?? null;

            $mapping = [
                'numero_lot'              => $iaData['bien_numero_lot_copro']     ?? null,
                'lot_principal'           => $iaData['bien_numero_lot_copro']     ?? null,
                'copro_quote_part_charges'=> $iaData['bien_quote_part_copro_pct'] ?? null,
                'bien_en_copropriete'     => (!empty($iaData['bien_quote_part_copro_pct']) || !empty($iaData['bien_numero_lot_copro'])) ? 1 : null,
                'parking_nb'              => $iaData['bien_nb_parkings']          ?? null,
                // Surfaces : ventilation selon usage (jamais surface_habitable pour local commercial)
                'surface_habitable'       => $isHabit  ? $surfaceM2 : null,
                'surface_totale'          => $surfaceM2,
                'surface_commerciale'     => $isCommer ? $surfaceM2 : null,
                'surface_bureau'          => $isPro    ? $surfaceM2 : null,
                'usage_bien'              => $usageIa ?: null,
                'annee_construction'      => $iaData['bien_annee_construction']   ?? null,
                'description'             => $iaData['bien_description']          ?? null,
                'commentaire'             => $iaData['bien_surfaces_detail']      ?? null,
            ];
            // Étage : convertir RDC en 0
            if (!empty($iaData['bien_etage'])) {
                $et = mb_strtoupper($iaData['bien_etage']);
                if (in_array($et, ['RDC','RDJ','REZ','REZ-DE-CHAUSSEE','REZ DE CHAUSSEE','0'], true)) {
                    $mapping['etage'] = 0;
                } elseif (is_numeric($et)) {
                    $mapping['etage'] = (int)$et;
                }
            }

            $updates = [];
            $params  = [':id' => $idBien];
            foreach ($mapping as $col => $val) {
                if (!isset($colsBien[$col]) || $val === null || $val === '') continue;
                // UPDATE défensif : ne touche QUE si vide (NULL ou 0/'' pour numériques)
                $updates[] = "$col = CASE
                    WHEN $col IS NULL THEN :$col
                    WHEN $col = '' THEN :$col
                    WHEN $col = 0 AND :{$col}_isnum = 1 THEN :$col
                    ELSE $col END";
                $params[':' . $col] = $val;
                $params[':' . $col . '_isnum'] = is_numeric($val) ? 1 : 0;
            }
            if (!empty($updates)) {
                try {
                    $sqlU = 'UPDATE biens SET ' . implode(', ', $updates) . ', date_modification = NOW() WHERE id = :id';
                    $stU = $pdo->prepare($sqlU);
                    foreach ($params as $k => $v) $stU->bindValue($k, $v);
                    $stU->execute();
                } catch (Throwable $e) {
                    error_log('[bail_save propagation bien] ' . $e->getMessage());
                }
            }
        }
    }

    // ─── A. LIEN IMMEUBLE ↔ BIEN ─────────────────────────────
    // Si le bien n'a pas d'immeuble rattaché, on cherche par adresse/CP, sinon on crée
    $stBien = $pdo->prepare('SELECT id_immeuble, adresse_1, code_postal, ville, id_societe, id_agence FROM biens WHERE id = ? LIMIT 1');
    $stBien->execute([$idBien]);
    $bRow = $stBien->fetch(PDO::FETCH_ASSOC);
    $idImmeubleLink = $bRow['id_immeuble'] ? (int)$bRow['id_immeuble'] : null;
    if (!$idImmeubleLink && !empty($bRow['adresse_1']) && !empty($bRow['code_postal'])) {
        $stI = $pdo->prepare('SELECT id FROM immeubles WHERE LOWER(adresse_1) = LOWER(?) AND code_postal = ? LIMIT 1');
        $stI->execute([$bRow['adresse_1'], $bRow['code_postal']]);
        $existing = $stI->fetchColumn();
        if ($existing) {
            $idImmeubleLink = (int)$existing;
        } else {
            // Création minimale d'immeuble (réutilise l'infrastructure)
            $colsI = [];
            try {
                $cstI = $pdo->query('SHOW COLUMNS FROM immeubles');
                while ($r = $cstI->fetch(PDO::FETCH_ASSOC)) $colsI[$r['Field']] = true;
            } catch (Throwable $e) {}
            $fldsI = []; $valsI = []; $pi = [];
            $addI = function (string $col, $val) use (&$fldsI, &$valsI, &$pi, $colsI) {
                if (!isset($colsI[$col]) || $val === null || $val === '') return;
                $fldsI[] = $col; $valsI[] = ':' . $col; $pi[':' . $col] = $val;
            };
            $addI('adresse_1', $bRow['adresse_1']);
            $addI('code_postal', $bRow['code_postal']);
            $addI('ville', $bRow['ville']);
            $addI('nom_immeuble', trim($bRow['adresse_1'] . ' ' . $bRow['ville']));
            $addI('id_societe', $bRow['id_societe'] ?: $idSoc);
            $addI('id_agence',  $bRow['id_agence']  ?: $idAge);
            $addI('statut_immeuble', 'actif');
            if (!empty($fldsI)) {
                $sqlI = 'INSERT INTO immeubles (' . implode(',', $fldsI) . ') VALUES (' . implode(',', $valsI) . ')';
                $stIns = $pdo->prepare($sqlI);
                foreach ($pi as $k => $v) $stIns->bindValue($k, $v);
                $stIns->execute();
                $idImmeubleLink = (int)$pdo->lastInsertId();
            }
        }
        if ($idImmeubleLink) {
            $pdo->prepare('UPDATE biens SET id_immeuble = ?, date_modification = NOW() WHERE id = ?')
                ->execute([$idImmeubleLink, $idBien]);
        }
    }

    // ─── B. LIEN TIERS PROPRIÉTAIRE ↔ BIEN + ENRICHISSEMENT IA ───────
    // proprietaires.id_tiers est déjà rempli par le backfill. On link via tiers_roles
    // ET on enrichit le tiers propriétaire avec les données extraites par l'IA si elles manquent.
    $idTiersProprio = null;
    try {
        $stP = $pdo->prepare('SELECT p.id_tiers FROM biens b
            INNER JOIN proprietaires p ON p.id = b.id_proprietaire
            WHERE b.id = ? LIMIT 1');
        $stP->execute([$idBien]);
        $idTiersProprio = $stP->fetchColumn() ?: null;
        if ($idTiersProprio) {
            $idTiersProprio = (int)$idTiersProprio;
            $stIns = $pdo->prepare("INSERT INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif)
                VALUES (?, 'proprietaire', 'bien', ?, 1)
                ON DUPLICATE KEY UPDATE actif = 1");
            $stIns->execute([$idTiersProprio, $idBien]);

            // Enrichissement défensif : UPDATE des champs vides du tiers propriétaire
            $bailType = (string)(post('bailleur_type') ?? 'societe');
            $bMap = [];
            if ($bailType === 'physique') {
                $bMap = [
                    'nom'       => $pv('bailleur_nom'),
                    'prenom'    => $pv('bailleur_prenom'),
                    'email'     => $pv('bailleur_email_phys'),
                    'telephone' => $pv('bailleur_telephone_phys'),
                ];
            } else {
                $bMap = [
                    'raison_sociale' => $pv('bailleur_raison_sociale'),
                    'siren'          => $pv('bailleur_siren'),
                    'email'          => $pv('bailleur_email'),
                    'telephone'      => $pv('bailleur_telephone'),
                ];
            }
            $upd = []; $params = [':id' => $idTiersProprio];
            foreach ($bMap as $col => $val) {
                if ($val === null || $val === '') continue;
                $upd[] = "$col = COALESCE(NULLIF($col, ''), :$col)";
                $params[':' . $col] = $val;
            }
            if (!empty($upd)) {
                try {
                    $sqlU = 'UPDATE tiers SET ' . implode(', ', $upd) . ', date_modification = NOW() WHERE id = :id';
                    $stU = $pdo->prepare($sqlU);
                    foreach ($params as $k => $v) $stU->bindValue($k, $v);
                    $stU->execute();
                } catch (Throwable $e) { error_log('[enrichir tiers proprio] ' . $e->getMessage()); }
            }
        }
    } catch (Throwable $e) { error_log('[link proprio→bien] ' . $e->getMessage()); }

    // ─── B-bis. REPRÉSENTANT DU BAILLEUR (tiers_contacts) ────────────
    // Si l'IA a extrait un représentant légal du bailleur, on le crée comme
    // personne physique liée au tiers société via tiers_contacts(qualite='gerant')
    $repBailleurNom = $pv('bailleur_representant_nom');
    if ($idTiersProprio && $repBailleurNom) {
        try {
            $repPrenom = '';
            $repNom    = $repBailleurNom;
            $parts = preg_split('/\s+/', trim($repBailleurNom), 2);
            if (count($parts) === 2) { $repPrenom = $parts[0]; $repNom = $parts[1]; }

            $repEmail = $pv('bailleur_representant_email');
            $repTel   = $pv('bailleur_representant_telephone');
            $repQual  = $pv('bailleur_representant_qualite') ?: 'gerant';

            // Recherche tiers existant (nom + prenom OU email)
            $idTiersRep = null;
            if ($repEmail) {
                $stR = $pdo->prepare('SELECT id FROM tiers WHERE LOWER(email) = LOWER(?) LIMIT 1');
                $stR->execute([$repEmail]);
                $idTiersRep = $stR->fetchColumn() ?: null;
            }
            if (!$idTiersRep && $repNom) {
                $stR = $pdo->prepare('SELECT id FROM tiers WHERE type_tiers = "personne_physique"
                    AND LOWER(nom) = LOWER(?) AND LOWER(COALESCE(prenom, "")) = LOWER(?) LIMIT 1');
                $stR->execute([$repNom, $repPrenom]);
                $idTiersRep = $stR->fetchColumn() ?: null;
            }
            if (!$idTiersRep) {
                $insR = $pdo->prepare('INSERT INTO tiers
                    (id_societe, id_agence, type_tiers, nom, prenom, email, telephone,
                     nom_affichage, source_creation, id_user_createur, actif)
                    VALUES (?, ?, "personne_physique", ?, ?, ?, ?, ?, "transaction_bail_representant", ?, 1)');
                $insR->execute([
                    $idSoc, $idAge,
                    $repNom ?: null, $repPrenom ?: null,
                    $repEmail ?: null, $repTel ?: null,
                    trim($repPrenom . ' ' . $repNom),
                    $idUser ?: null,
                ]);
                $idTiersRep = (int)$pdo->lastInsertId();
            }
            // Lien tiers_contacts (entité = SCI/société propriétaire, contact = représentant)
            $stTc = $pdo->prepare("INSERT IGNORE INTO tiers_contacts
                (id_tiers_entite, id_tiers_contact, qualite, priorite, canal_principal, actif, date_creation)
                VALUES (?, ?, ?, 0, 'email', 1, NOW())");
            $stTc->execute([$idTiersProprio, $idTiersRep, $repQual]);
        } catch (Throwable $e) { error_log('[representant bailleur] ' . $e->getMessage()); }
    }

    // ─── C. CRÉATION TIERS LOCATAIRE + LIEN AU BAIL ──────────
    $idTiersLocataire = null;
    $locType = $bail['locataire_type'] ?? 'societe';
    $locNom  = trim((string)($bail['locataire_nom'] ?? ''));
    $locPrenom = trim((string)($bail['locataire_prenom'] ?? ''));
    $locRaison = trim((string)($bail['locataire_raison_sociale'] ?? ''));
    $locEmail = trim((string)($bail['locataire_email'] ?? ''));
    $locTel   = trim((string)($bail['locataire_telephone'] ?? ''));
    $locSiren = trim((string)($bail['locataire_siren'] ?? ''));

    if ($locRaison !== '' || $locNom !== '' || $locEmail !== '') {
        try {
            // 1. Recherche tiers existant — PRIORITÉ SIREN (identifiant unique national)
            //    sinon raison_sociale + ville (limite les variantes), sinon email, sinon nom+prenom.
            $idTiersLocataire = null;

            // 1.a — SIREN d'abord (le plus fiable)
            if ($locSiren !== '' && preg_match('/^\d{9}(\d{5})?$/', preg_replace('/\D/', '', $locSiren))) {
                $sirenClean = substr(preg_replace('/\D/', '', $locSiren), 0, 9);
                $st = $pdo->prepare('SELECT id FROM tiers WHERE REPLACE(siren, " ", "") = ? OR LEFT(REPLACE(siret, " ", ""), 9) = ? LIMIT 1');
                $st->execute([$sirenClean, $sirenClean]);
                $idTiersLocataire = $st->fetchColumn() ?: null;
            }

            // 1.b — Fallback : raison_sociale (matching fuzzy + LIKE pour gérer variantes)
            if (!$idTiersLocataire && $locRaison !== '') {
                // Normalise : supprime suffixes juridiques fréquents pour comparer
                $rsNorm = preg_replace('/\s+(sas|sarl|sa|sci|snc|eurl|sasu|civile|et\s+cie)\b\.?/i', '', $locRaison);
                $rsNorm = trim(preg_replace('/\s+/', ' ', (string)$rsNorm));
                if ($rsNorm !== '') {
                    $st = $pdo->prepare('SELECT id FROM tiers
                        WHERE LOWER(raison_sociale) LIKE LOWER(?)
                           OR LOWER(nom_affichage)  LIKE LOWER(?)
                        LIMIT 1');
                    $like = '%' . $rsNorm . '%';
                    $st->execute([$like, $like]);
                    $idTiersLocataire = $st->fetchColumn() ?: null;
                }
            }

            // 1.c — Fallback final : email, ou nom+prenom (personne physique)
            if (!$idTiersLocataire) {
                $conds = []; $params = [];
                if ($locEmail !== '') { $conds[] = 'LOWER(email) = LOWER(?)'; $params[] = $locEmail; }
                if ($locNom !== '' && $locType === 'physique') {
                    $conds[] = '(LOWER(nom) = LOWER(?) AND LOWER(COALESCE(prenom, "")) = LOWER(?))';
                    $params[] = $locNom; $params[] = $locPrenom;
                }
                if (!empty($conds)) {
                    $st = $pdo->prepare('SELECT id FROM tiers WHERE ' . implode(' OR ', $conds) . ' LIMIT 1');
                    $st->execute($params);
                    $idTiersLocataire = $st->fetchColumn() ?: null;
                }
            }
            // 2. Création si absent
            if (!$idTiersLocataire) {
                $typeT = ($locType === 'physique') ? 'personne_physique' : 'personne_morale';
                $nomAff = $locRaison ?: trim($locPrenom . ' ' . $locNom);
                $insT = $pdo->prepare('INSERT INTO tiers
                    (id_societe, id_agence, type_tiers, nom, prenom, raison_sociale, siren, email, telephone,
                     nom_affichage, source_creation, id_user_createur, actif)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "transaction_bail_save", ?, 1)');
                $insT->execute([
                    $idSoc, $idAge, $typeT,
                    $locNom ?: null,
                    $locPrenom ?: null,
                    $locRaison ?: null,
                    $locSiren ?: null,
                    $locEmail ?: null,
                    $locTel ?: null,
                    $nomAff ?: null,
                    $idUser ?: null,
                ]);
                $idTiersLocataire = (int)$pdo->lastInsertId();
            } else {
                $idTiersLocataire = (int)$idTiersLocataire;
            }
            // 3. Rôle "locataire" sur le bail
            $stRole = $pdo->prepare("INSERT INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, date_debut, date_fin, actif)
                VALUES (?, 'locataire', 'bail', ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE actif = 1, date_debut = VALUES(date_debut), date_fin = VALUES(date_fin)");
            $stRole->execute([
                $idTiersLocataire, $bailId,
                $bail['date_prise_effet'] ?? null,
                $bail['date_fin'] ?? null,
            ]);
            // 4. Aussi rôle "locataire" sur le bien (pour visibilité depuis la fiche bien)
            $stRole2 = $pdo->prepare("INSERT INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, date_debut, date_fin, actif)
                VALUES (?, 'locataire', 'bien', ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE actif = 1, date_debut = VALUES(date_debut), date_fin = VALUES(date_fin)");
            $stRole2->execute([
                $idTiersLocataire, $idBien,
                $bail['date_prise_effet'] ?? null,
                $bail['date_fin'] ?? null,
            ]);
            // 5. FK directe bien_baux.id_tiers_locataire (raccourci pour les JOIN sans tiers_roles)
            try {
                $pdo->prepare('UPDATE bien_baux SET id_tiers_locataire = ? WHERE id = ?')
                    ->execute([$idTiersLocataire, $bailId]);
            } catch (Throwable $e) {
                error_log('[link id_tiers_locataire] ' . $e->getMessage());
            }

            // 6. Représentant du LOCATAIRE → tiers personne_physique + tiers_contacts
            $repLocNom = $pv('locataire_representant_nom');
            if ($repLocNom) {
                try {
                    $rPrenom = ''; $rNom = $repLocNom;
                    $parts = preg_split('/\s+/', trim($repLocNom), 2);
                    if (count($parts) === 2) { $rPrenom = $parts[0]; $rNom = $parts[1]; }
                    $rEmail = $pv('locataire_representant_email');
                    $rTel   = $pv('locataire_representant_telephone');
                    $rQual  = $pv('locataire_representant_qualite') ?: 'gerant';

                    $idRep = null;
                    if ($rEmail) {
                        $stRep = $pdo->prepare('SELECT id FROM tiers WHERE LOWER(email) = LOWER(?) LIMIT 1');
                        $stRep->execute([$rEmail]);
                        $idRep = $stRep->fetchColumn() ?: null;
                    }
                    if (!$idRep && $rNom) {
                        $stRep = $pdo->prepare('SELECT id FROM tiers WHERE type_tiers = "personne_physique"
                            AND LOWER(nom) = LOWER(?) AND LOWER(COALESCE(prenom, "")) = LOWER(?) LIMIT 1');
                        $stRep->execute([$rNom, $rPrenom]);
                        $idRep = $stRep->fetchColumn() ?: null;
                    }
                    if (!$idRep) {
                        $insR = $pdo->prepare('INSERT INTO tiers
                            (id_societe, id_agence, type_tiers, nom, prenom, email, telephone,
                             nom_affichage, source_creation, id_user_createur, actif)
                            VALUES (?, ?, "personne_physique", ?, ?, ?, ?, ?, "transaction_bail_representant", ?, 1)');
                        $insR->execute([
                            $idSoc, $idAge, $rNom ?: null, $rPrenom ?: null,
                            $rEmail ?: null, $rTel ?: null,
                            trim($rPrenom . ' ' . $rNom), $idUser ?: null,
                        ]);
                        $idRep = (int)$pdo->lastInsertId();
                    }
                    $stTc = $pdo->prepare("INSERT IGNORE INTO tiers_contacts
                        (id_tiers_entite, id_tiers_contact, qualite, priorite, canal_principal, actif, date_creation)
                        VALUES (?, ?, ?, 0, 'email', 1, NOW())");
                    $stTc->execute([$idTiersLocataire, $idRep, $rQual]);
                } catch (Throwable $e) { error_log('[representant locataire] ' . $e->getMessage()); }
            }
        } catch (Throwable $e) {
            error_log('[link locataire] ' . $e->getMessage());
        }
    }

    // ── Classement doc GED (si staging_id fourni) ────────────
    $docId = null;
    if ($stagingId > 0) {
        $row = tr_staging_check_owner($pdo, $stagingId, $idUser);
        if ($row) {
            $abs = __DIR__ . '/../' . $row['stored_path'];
            if (is_file($abs)) {
                $fakeFile = [
                    'name'     => (string)$row['filename'],
                    'type'     => (string)$row['mime_type'],
                    'tmp_name' => $abs,
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => (int)$row['size_bytes'],
                ];
                $typeDoc = $pv('type_document') ?? 'BAIL';
                $commen  = 'Bail #' . $bailId . ' — ' . ($pv('commentaire') ?? '');
                $res = transaction_upload_document(
                    $pdo, $idBien, $fakeFile, $typeDoc, 'interne', $commen,
                    $idSoc, $idAge, $idUser ?: null, 'copy'
                );
                $docId = $res['id'] ?? null;
                // Cleanup staging
                @unlink($abs);
                $pdo->prepare('DELETE FROM transaction_chargement_staging WHERE id = ?')->execute([(int)$row['id']]);
            }
        }
    }

    echo json_encode([
        'ok'                => true,
        'bail_id'           => $bailId,
        'doc_id'            => $docId,
        'id_immeuble'       => $idImmeubleLink,
        'id_tiers_proprio'  => $idTiersProprio,
        'id_tiers_locataire'=> $idTiersLocataire,
        'links_created'     => array_filter([
            $idImmeubleLink     ? '🏢 Immeuble lié' : null,
            $idTiersProprio     ? '👤 Propriétaire (tiers) lié' : null,
            $idTiersLocataire   ? '👥 Locataire (tiers) lié' : null,
        ]),
    ]);
} catch (Throwable $e) {
    error_log('[transaction_bail_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
