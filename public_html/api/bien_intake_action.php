<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ubiflow_validator.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

// Helper : détermine le type d'input HTML pour la saisie inline
function intake_guess_input_type(string $key): string
{
    $numbers = ['nb_pieces','nb_chambres','nb_wc','nb_salles_bain','annee_construction',
                'altitude','copro_nb_lots','dpe_valeur','ges_valeur'];
    $decimals = ['surface_habitable','prix','loyer','copro_quote_part_charges','alur_pourcentage_honoraires_ttc'];
    $dates = ['dpe_date_realisation'];
    $selects = ['type_transaction','dpe_classe','ges_classe'];
    $urls = ['url_tarifs_publics'];
    $textareas = ['description'];
    $bools = ['honoraires_charge_acquereur'];
    if (in_array($key, $numbers, true))   return 'number';
    if (in_array($key, $decimals, true))  return 'decimal';
    if (in_array($key, $dates, true))     return 'date';
    if (in_array($key, $selects, true))   return 'select';
    if (in_array($key, $urls, true))      return 'url';
    if (in_array($key, $textareas, true)) return 'textarea';
    if (in_array($key, $bools, true))     return 'checkbox';
    return 'text';
}

$pdo = $GLOBALS['pdo'];
$societeId = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$bienId = isset($_REQUEST['bien_id']) && ctype_digit((string)$_REQUEST['bien_id']) ? (int)$_REQUEST['bien_id'] : 0;

if ($bienId <= 0) exit(json_encode(['ok' => false, 'error' => 'bien_id manquant']));

// Vérification que le bien appartient à l'utilisateur
try {
    $stmt = $pdo->prepare("SELECT id, id_societe FROM biens WHERE id = ? LIMIT 1");
    $stmt->execute([$bienId]);
    $bienRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bienRow) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}

// CSRF (sauf pour state qui est en GET)
if ($action !== 'state') {
    verify_csrf_any('ajouter_bien');
}

try {
    switch ($action) {

        // ─────────────────────────────────────────────────────
        // STATE : retourne l'état complet du bien (diags, champs manquants, propriétaire/immeuble actuels)
        // ─────────────────────────────────────────────────────
        case 'state': {
            // Diags du bien
            $stmtD = $pdo->prepare("
                SELECT id, type_diag, fichier_url, nom_fichier_original,
                       taille_fichier_octets, extraction_method, extraction_score,
                       resume_bailleur, date_creation,
                       alerte_plomb_present, alerte_amiante_present,
                       alerte_electricite_anomalies, alerte_gaz_anomalies, alerte_zone_georisque
                FROM dpe_diags
                WHERE id_bien = ?
                ORDER BY id DESC
            ");
            $stmtD->execute([$bienId]);
            $diags = $stmtD->fetchAll(PDO::FETCH_ASSOC);

            // Bien complet pour le validator
            $stmtB = $pdo->prepare("
                SELECT b.*, tb.code AS _type_bien_code,
                       i.adresse_1 AS _imm_adresse_1, i.code_postal AS _imm_code_postal, i.ville AS _imm_ville,
                       p.nom AS _proprio_nom, p.prenom AS _proprio_prenom, p.societe AS _proprio_societe,
                       i.id AS _imm_id, p.id AS _proprio_id
                FROM biens b
                LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
                LEFT JOIN immeubles i ON i.id = b.id_immeuble
                LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                WHERE b.id = ?
            ");
            $stmtB->execute([$bienId]);
            $bien = $stmtB->fetch(PDO::FETCH_ASSOC);

            // Annonce principale
            $stmtA = $pdo->prepare("SELECT * FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
            $stmtA->execute([$bienId]);
            $annonce = $stmtA->fetch(PDO::FETCH_ASSOC) ?: [];

            // Photos count
            $photosCount = 0;
            try {
                $stmtP = $pdo->prepare("SELECT COUNT(*) FROM biens_photos WHERE id_bien = ?");
                $stmtP->execute([$bienId]);
                $photosCount = (int)$stmtP->fetchColumn();
            } catch (Throwable) {}

            // Validation Ubiflow
            $check = ubiflow_check_completude($bien, $annonce, $photosCount, $pdo, $societeId);

            // Format des champs manquants pour le frontend (avec input type pour saisie inline)
            // Exclusions intake : champs qui se remplissent à l'étape suivante (annonce)
            $intakeSkip = ['description'];
            $missingForUi = [];
            foreach ($check['missing'] as $m) {
                if (in_array($m['key'], $intakeSkip, true)) continue;
                $missingForUi[] = [
                    'key'    => $m['key'],
                    'label'  => $m['label'],
                    'source' => $m['source'],
                    'input_type' => intake_guess_input_type($m['key']),
                ];
            }

            echo json_encode([
                'ok' => true,
                'bien_id' => $bienId,
                'score' => $check['score'],
                'status' => $check['status'],
                'missing' => $missingForUi,
                'missing_count' => count($missingForUi),
                'diags' => array_map(function($d) {
                    return [
                        'id' => (int)$d['id'],
                        'type' => $d['type_diag'],
                        'nom' => $d['nom_fichier_original'],
                        'url' => function_exists('app_url') && !empty($d['fichier_url'])
                                 ? app_url($d['fichier_url'])
                                 : $d['fichier_url'],
                        'taille' => (int)$d['taille_fichier_octets'],
                        'method' => $d['extraction_method'],
                        'score' => (int)$d['extraction_score'],
                        'resume' => $d['resume_bailleur'],
                        'alertes' => [
                            'plomb' => (int)$d['alerte_plomb_present'],
                            'amiante' => (int)$d['alerte_amiante_present'],
                            'electricite' => (int)$d['alerte_electricite_anomalies'],
                            'gaz' => (int)$d['alerte_gaz_anomalies'],
                            'georisque' => (int)$d['alerte_zone_georisque'],
                        ],
                    ];
                }, $diags),
                'proprietaire' => $bien['_proprio_id'] ? [
                    'id' => (int)$bien['_proprio_id'],
                    'label' => trim(($bien['_proprio_societe'] ?? '') ?: (($bien['_proprio_prenom'] ?? '') . ' ' . ($bien['_proprio_nom'] ?? ''))),
                ] : null,
                'immeuble' => $bien['_imm_id'] ? [
                    'id' => (int)$bien['_imm_id'],
                    'label' => trim(($bien['_imm_adresse_1'] ?? '') . ' • ' . ($bien['_imm_code_postal'] ?? '') . ' ' . ($bien['_imm_ville'] ?? '')),
                ] : null,
            ]);
            exit;
        }

        // ─────────────────────────────────────────────────────
        // DELETE : supprime un diagnostic + son fichier
        // ─────────────────────────────────────────────────────
        case 'delete_diag': {
            $diagId = isset($_POST['diag_id']) && ctype_digit((string)$_POST['diag_id']) ? (int)$_POST['diag_id'] : 0;
            if ($diagId <= 0) exit(json_encode(['ok' => false, 'error' => 'diag_id manquant']));

            // Vérifie que le diag appartient au bien
            $stmt = $pdo->prepare("SELECT id, fichier_url, champs_extraits_json FROM dpe_diags WHERE id = ? AND id_bien = ?");
            $stmt->execute([$diagId, $bienId]);
            $diag = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$diag) exit(json_encode(['ok' => false, 'error' => 'Diagnostic introuvable']));

            // Supprime le fichier physique
            if (!empty($diag['fichier_url'])) {
                $filePath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $diag['fichier_url']);
                if (is_file($filePath)) @unlink($filePath);
            }

            // ── Reverse les champs auto-remplis par ce diag ──
            // On reset à NULL chaque colonne où la valeur courante du bien correspond
            // encore exactement à ce que ce diag avait inséré (= pas modifié manuellement).
            $reverted = [];
            $extracted = json_decode((string)($diag['champs_extraits_json'] ?? ''), true);
            if (is_array($extracted) && !empty($extracted)) {
                // Colonnes biens.* candidates au reverse (mêmes que le sync map d'upload)
                $reversibleBien = [
                    'adresse_1','adresse_2','code_postal','ville','pays',
                    'dpe_classe','ges_classe','dpe_valeur','ges_valeur',
                    'dpe_date_realisation','dpe_version','dpe_vierge','dpe_reference_certificat',
                    'montant_estime_depenses_min','montant_estime_depenses_max',
                    'surface_habitable','surface_sejour','surface_carrez',
                    'nb_pieces','nb_chambres','nb_salles_bain','nb_salles_eau','nb_wc',
                    'etage','annee_construction',
                    'chauffage_type','chauffage_energie','eau_chaude_type',
                    'double_vitrage','volets_roulants','menuiseries','zone_georisque',
                    'designation','reference_bien',
                ];
                // Lit les valeurs courantes
                $cur = $pdo->prepare("SELECT * FROM biens WHERE id = ? LIMIT 1");
                $cur->execute([$bienId]);
                $bienRow = $cur->fetch(PDO::FETCH_ASSOC) ?: [];

                $resetParts = [];
                foreach ($reversibleBien as $col) {
                    if (!array_key_exists($col, $extracted)) continue;
                    $extVal = $extracted[$col];
                    if ($extVal === null || $extVal === '') continue;
                    $curVal = $bienRow[$col] ?? null;
                    // Comparaison souple (string-cast) pour gérer les nombres BDD vs JSON
                    if ($curVal === null || (string)$curVal === '') continue;
                    if ((string)$curVal === (string)$extVal
                        || (is_numeric($curVal) && is_numeric($extVal) && (float)$curVal === (float)$extVal)) {
                        $resetParts[] = "`$col` = NULL";
                        $reverted[] = $col;
                    }
                }
                if (!empty($resetParts)) {
                    $pdo->prepare("UPDATE biens SET " . implode(', ', $resetParts) . " WHERE id = " . (int)$bienId)
                        ->execute();
                }
            }

            // Supprime la ligne BDD
            $pdo->prepare("DELETE FROM dpe_diags WHERE id = ?")->execute([$diagId]);

            echo json_encode(['ok' => true, 'deleted_id' => $diagId, 'reverted' => $reverted]);
            exit;
        }

        // ─────────────────────────────────────────────────────
        // DELETE_MANDAT : supprime un mandat (et son PDF)
        // ─────────────────────────────────────────────────────
        case 'delete_mandat': {
            $mandatId = isset($_POST['mandat_id']) && ctype_digit((string)$_POST['mandat_id']) ? (int)$_POST['mandat_id'] : 0;
            if ($mandatId <= 0) exit(json_encode(['ok' => false, 'error' => 'mandat_id manquant']));

            $stmt = $pdo->prepare("SELECT id, document_pdf FROM mandats WHERE id = ? AND id_bien = ?");
            $stmt->execute([$mandatId, $bienId]);
            $mandat = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mandat) exit(json_encode(['ok' => false, 'error' => 'Mandat introuvable']));

            // Supprime le fichier physique si stocké localement
            if (!empty($mandat['document_pdf']) && !str_contains((string)$mandat['document_pdf'], '://')) {
                $rel = 'uploads/mandats/' . ltrim((string)$mandat['document_pdf'], '/');
                $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (is_file($filePath)) @unlink($filePath);
            }

            $pdo->prepare("DELETE FROM mandats WHERE id = ?")->execute([$mandatId]);
            echo json_encode(['ok' => true, 'deleted_id' => $mandatId]);
            exit;
        }

        // ─────────────────────────────────────────────────────
        // DELETE_DOC : supprime un document générique (biens_documents)
        // ─────────────────────────────────────────────────────
        case 'delete_doc': {
            $docId = isset($_POST['doc_id']) && ctype_digit((string)$_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
            if ($docId <= 0) exit(json_encode(['ok' => false, 'error' => 'doc_id manquant']));

            try {
                $stmt = $pdo->prepare("SELECT id, url_fichier FROM biens_documents WHERE id = ? AND id_bien = ?");
                $stmt->execute([$docId, $bienId]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$doc) exit(json_encode(['ok' => false, 'error' => 'Document introuvable']));

                if (!empty($doc['url_fichier'])) {
                    $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim((string)$doc['url_fichier'], '/'));
                    if (is_file($filePath)) @unlink($filePath);
                }

                $pdo->prepare("DELETE FROM biens_documents WHERE id = ?")->execute([$docId]);
                echo json_encode(['ok' => true, 'deleted_id' => $docId]);
                exit;
            } catch (Throwable $e) {
                exit(json_encode(['ok' => false, 'error' => 'Table biens_documents indisponible : ' . $e->getMessage()]));
            }
        }

        // ─────────────────────────────────────────────────────
        // SAVE_FIELD : saisie inline d'un champ obligatoire manquant
        // ─────────────────────────────────────────────────────
        case 'save_field': {
            $fieldKey = (string)($_POST['field'] ?? '');
            $value    = $_POST['value'] ?? null;
            if ($fieldKey === '') exit(json_encode(['ok' => false, 'error' => 'field manquant']));

            // Whitelist des champs autorisés (sécurité)
            $allowed_biens = [
                'reference_bien','designation','annee_construction',
                'surface_habitable','nb_pieces','nb_chambres','nb_wc','nb_salles_bain',
                'dpe_classe','ges_classe','dpe_valeur','ges_valeur','dpe_date_realisation',
                'copro_nb_lots','copro_quote_part_charges','altitude',
            ];
            $allowed_annonce = [
                'description','type_transaction','prix','loyer',
                'alur_pourcentage_honoraires_ttc','url_tarifs_publics',
                'honoraires_charge_acquereur',
            ];
            $allowed_immeuble = ['code_postal','ville','adresse_1'];

            if (in_array($fieldKey, $allowed_biens, true)) {
                $pdo->prepare("UPDATE biens SET `$fieldKey` = ? WHERE id = ?")->execute([$value, $bienId]);
            } elseif (in_array($fieldKey, $allowed_annonce, true)) {
                // Récupère ou crée l'annonce
                $stmt = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$bienId]);
                $annId = (int)$stmt->fetchColumn();
                if ($annId === 0) {
                    $insA = $pdo->prepare("INSERT INTO annonces (id_bien, type_transaction, statut, date_creation, date_modification) VALUES (?, 'vente', 'brouillon', NOW(), NOW())");
                    $insA->execute([$bienId]);
                    $annId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE annonces SET `$fieldKey` = ? WHERE id = ?")->execute([$value, $annId]);
            } elseif (in_array($fieldKey, $allowed_immeuble, true)) {
                // Met à jour l'immeuble lié, ou en crée un minimal
                $stmt = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ?");
                $stmt->execute([$bienId]);
                $immId = (int)$stmt->fetchColumn();
                if ($immId === 0) {
                    $insI = $pdo->prepare("INSERT INTO immeubles (id_societe, id_agence, adresse_1, pays) VALUES (?, ?, ?, 'France')");
                    $insI->execute([
                        $societeId,
                        isset($_SESSION['id_agence']) ? (int)$_SESSION['id_agence'] : null,
                        $fieldKey === 'adresse_1' ? $value : '',
                    ]);
                    $immId = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE biens SET id_immeuble = ? WHERE id = ?")->execute([$immId, $bienId]);
                }
                $pdo->prepare("UPDATE immeubles SET `$fieldKey` = ? WHERE id = ?")->execute([$value, $immId]);
            } else {
                exit(json_encode(['ok' => false, 'error' => 'Champ non autorisé : ' . $fieldKey]));
            }

            echo json_encode(['ok' => true, 'field' => $fieldKey, 'value' => $value]);
            exit;
        }

        default:
            exit(json_encode(['ok' => false, 'error' => 'action inconnue: ' . $action]));
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}

