<?php
declare(strict_types=1);

/**
 * inc/investisseur_prefill.php
 *
 * Pré-remplissage d'une analyse investisseur à partir d'un bien existant
 * de la BDD (table biens + arbitrage_biens + CRG).
 *
 * Principe :
 *   - Tolérant : si arbitrage_biens ou CRG sont absents, on se rabat
 *     sur les colonnes de biens et on documente l'hypothèse.
 *   - Privilégie le réel observé (CRG) sur le déclaratif.
 */

require_once __DIR__ . '/investisseur_helpers.php';
if (file_exists(__DIR__ . '/arbitrage_crg.php')) {
    require_once __DIR__ . '/arbitrage_crg.php';
}

if (!function_exists('inv_prefill_from_bien')) {
    /**
     * Retourne un array prêt à alimenter un formulaire d'analyse investisseur,
     * rempli depuis biens + arbitrage_biens + CRG.
     */
    function inv_prefill_from_bien(PDO $pdo, int $idBien): array
    {
        $data = [];
        $notes = [];

        // ── 1) Données "biens"
        $sql = "SELECT b.*, tb.libelle AS type_libelle
                FROM biens b
                LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
                WHERE b.id = :id
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', $idBien, PDO::PARAM_INT);
        $st->execute();
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new RuntimeException("Bien introuvable (id=$idBien).");

        $data['id_bien_source']   = $idBien;
        $data['id_proprietaire']  = isset($b['id_proprietaire']) ? (int)$b['id_proprietaire'] : null;
        $data['reference_bien']   = (string)($b['reference_bien'] ?? '');
        $data['type_bien']        = (string)($b['type_libelle'] ?? $b['type_bien'] ?? '');
        $data['ville']            = (string)($b['ville'] ?? '');
        $data['quartier']         = (string)($b['quartier'] ?? '');
        $data['adresse']          = trim(((string)($b['adresse_1'] ?? '')) . ' ' . ((string)($b['code_postal'] ?? '')));
        $data['surface']          = (float)($b['surface_habitable'] ?? 0);
        $data['nb_pieces']        = (int)($b['nb_pieces'] ?? 0);
        $data['etage']            = (string)($b['etage'] ?? '');
        $data['annee_construction'] = (int)($b['annee_construction'] ?? 0);
        $data['dpe']              = strtoupper(substr((string)($b['dpe'] ?? ''), 0, 1));
        $data['ges']              = strtoupper(substr((string)($b['ges'] ?? ''), 0, 1));
        $data['cave']             = !empty($b['cave']) ? 1 : 0;
        $data['garage']           = !empty($b['garage']) ? 1 : 0;
        $data['parking']          = !empty($b['parking']) ? 1 : 0;

        $titre = trim((string)($b['designation'] ?? ''));
        if ($titre === '') $titre = $data['type_bien'] . ' — ' . $data['ville'];
        $data['titre_analyse'] = 'Analyse — ' . $titre;

        // Prix d'achat = prix_vente_estime (valeur marché)
        if (!empty($b['prix_vente_estime'])) {
            $data['prix_achat'] = (float)$b['prix_vente_estime'];
        }

        // Loyer de référence : priorité CRG moyenne, sinon arbitrage, sinon loyer_hc
        $loyerRef = 0.0;
        $sourceLoyer = '';

        // ── 2) CRG (historique réel)
        if (function_exists('arb_crg_latest_snapshot')) {
            try {
                $crg = arb_crg_latest_snapshot($pdo, $idBien);
                if (!empty($crg['loyer_appele_mensuel']) && (float)$crg['loyer_appele_mensuel'] > 0) {
                    $loyerRef = (float)$crg['loyer_appele_mensuel'];
                    $sourceLoyer = 'CRG (dernier trimestre)';
                }
                if (!empty($crg['impaye_latest']) && (float)$crg['impaye_latest'] > 0) {
                    $notes[] = "⚠ Impayé observé sur dernier CRG : " . number_format((float)$crg['impaye_latest'], 0, ',', ' ') . " €";
                }
                if (!empty($crg['locataire_nom'])) {
                    $notes[] = "Locataire actuel : " . $crg['locataire_nom'];
                }
            } catch (Throwable $e) {
                // CRG absent : on continue
            }
        }

        // Moyenne CRG sur 8 derniers trimestres (plus robuste que le snapshot)
        try {
            $st = $pdo->prepare("
                SELECT AVG(NULLIF(sl.loyer_appele,0)) AS loyer_moy,
                       SUM(CASE WHEN sl.statut_trimestre LIKE '%vacant%' THEN 1 ELSE 0 END) AS nb_vac,
                       COUNT(*) AS nb_tot,
                       SUM(sl.total_impaye) AS impaye_cumule
                FROM crg_situations_locataires sl
                JOIN crg_trimestres ct ON ct.id = sl.id_crg
                WHERE sl.id_bien = :id
                  AND (ct.parse_statut = 'ok' OR ct.parse_statut IS NULL)
                ORDER BY ct.annee DESC, ct.trimestre DESC
                LIMIT 8
            ");
            $st->bindValue(':id', $idBien, PDO::PARAM_INT);
            $st->execute();
            $agg = $st->fetch(PDO::FETCH_ASSOC);
            if ($agg && (float)($agg['loyer_moy'] ?? 0) > 0) {
                $loyerRef = (float)$agg['loyer_moy'];
                $sourceLoyer = 'CRG (moyenne 8 derniers trimestres)';
                $notes[] = "Loyer moyen CRG retenu : " . number_format($loyerRef, 0, ',', ' ') . " €/mois";
            }
            $nbTot = (int)($agg['nb_tot'] ?? 0);
            if ($nbTot > 0 && (int)$agg['nb_vac'] > 0) {
                $vacancePct = round(((int)$agg['nb_vac'] / $nbTot) * 100, 2);
                $data['vacance_locative'] = $vacancePct;
                $notes[] = "Vacance observée : {$agg['nb_vac']}/{$nbTot} trimestres ≈ {$vacancePct} %";
            }
            if ((float)($agg['impaye_cumule'] ?? 0) > 0) {
                $notes[] = "Impayés cumulés sur 8 trimestres : " . number_format((float)$agg['impaye_cumule'], 0, ',', ' ') . " €";
            }
        } catch (Throwable $e) {
            // table CRG peut-être absente
        }

        // ── 3) arbitrage_biens
        try {
            $st = $pdo->prepare("SELECT * FROM arbitrage_biens WHERE id_bien = :id LIMIT 1");
            $st->bindValue(':id', $idBien, PDO::PARAM_INT);
            $st->execute();
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if ($a) {
                if ($loyerRef <= 0 && !empty($a['loyer_actuel_mensuel'])) {
                    $loyerRef = (float)$a['loyer_actuel_mensuel'];
                    $sourceLoyer = 'arbitrage_biens (loyer actuel)';
                }
                if (!empty($a['loyer_potentiel_mensuel']) && $loyerRef > 0) {
                    $pot = (float)$a['loyer_potentiel_mensuel'];
                    if ($pot > $loyerRef * 1.05) {
                        $notes[] = "Loyer potentiel estimé : " . number_format($pot, 0, ',', ' ')
                                 . " €/mois (+" . round(($pot - $loyerRef) / $loyerRef * 100, 1) . " %)";
                    }
                }

                if (!empty($a['taxe_fonciere']))      $data['taxe_fonciere'] = (float)$a['taxe_fonciere'];
                if (!empty($a['charges_non_recup'])) $data['charges_non_recuperables'] = round((float)$a['charges_non_recup'] / 12, 2);
                if (!empty($a['assurance']))          $data['assurance_pno']  = (float)$a['assurance'];
                if (!empty($a['frais_gestion']))      $data['gestion_locative'] = (float)$a['frais_gestion'];
                if (!empty($a['travaux_1an']))        $data['travaux']        = (float)$a['travaux_1an'];

                if (!empty($a['frais_notaire']))      $data['frais_notaire']  = (float)$a['frais_notaire'];
                if (!empty($a['frais_agence']))       $data['frais_agence']   = (float)$a['frais_agence'];
                if (!empty($a['prix_vente_realiste'])) {
                    // Si arbitrage a un prix vente réaliste plus fiable que prix_vente_estime
                    if (empty($data['prix_achat']) || $data['prix_achat'] <= 0) {
                        $data['prix_achat'] = (float)$a['prix_vente_realiste'];
                    }
                }

                // Échelles qualitatives (1-5 déjà en place côté arbitrage)
                if (!empty($a['liquidite_niveau'])) $data['facilite_revente'] = (int)$a['liquidite_niveau'];
                if (!empty($a['risque_niveau']))    $data['niveau_risque']    = (int)$a['risque_niveau'];

                // Travaux à venir sur 3-5 ans : note contextuelle
                $trv3 = (float)($a['travaux_3ans'] ?? 0);
                $trv5 = (float)($a['travaux_5ans'] ?? 0);
                if ($trv3 > 0 || $trv5 > 0) {
                    $notes[] = "Travaux anticipés : 3 ans " . number_format($trv3, 0, ',', ' ') . " € / 5 ans " . number_format($trv5, 0, ',', ' ') . " €";
                }
                if (!empty($a['decision'])) {
                    $notes[] = "Décision arbitrage actuelle : " . $a['decision'];
                }
            }
        } catch (Throwable $e) {
            // arbitrage_biens absent sur cette société : on continue
        }

        // Fallback ultime : loyer_hc sur biens
        if ($loyerRef <= 0 && !empty($b['loyer_hc'])) {
            $loyerRef    = (float)$b['loyer_hc'];
            $sourceLoyer = 'biens.loyer_hc (déclaré)';
        }
        if ($loyerRef > 0) {
            $data['loyer_estime'] = $loyerRef;
            $notes[] = "Source loyer retenu : $sourceLoyer";
        }

        // Locataire actif via baux + date fin
        try {
            $st = $pdo->prepare("SELECT locataire_nom, date_fin, date_debut FROM baux WHERE id_bien = :id AND statut = 'actif' ORDER BY id DESC LIMIT 1");
            $st->bindValue(':id', $idBien, PDO::PARAM_INT);
            $st->execute();
            $bail = $st->fetch(PDO::FETCH_ASSOC);
            if (!empty($bail['locataire_nom'])) {
                $data['locataire_nom'] = (string)$bail['locataire_nom'];
                $notes[] = "Bail actif : " . $bail['locataire_nom'];
            }
            if (!empty($bail['date_fin'])) {
                $data['bail_fin'] = substr((string)$bail['date_fin'], 0, 10);
                $notes[] = "Échéance bail : " . date('d/m/Y', strtotime((string)$bail['date_fin']));
            }
        } catch (Throwable $e) {}

        // Commentaire humain : concaténation des notes auto (éditable ensuite)
        if (!empty($notes)) {
            $data['commentaire_humain'] = "— Pré-rempli depuis l'historique BDD (CRG / arbitrage / bail) —\n"
                                        . "• " . implode("\n• ", $notes);
        }

        $data['statut'] = 'brouillon';

        return $data;
    }
}

if (!function_exists('inv_listable_biens')) {
    /**
     * Retourne la liste des biens proposables pour le pré-remplissage,
     * en respectant le scope du user courant (via arb_scope_biens_where si dispo,
     * sinon filtrage société simple).
     */
    function inv_listable_biens(PDO $pdo, int $limit = 500): array {
        $hasArb = file_exists(__DIR__ . '/arbitrage_scope.php');
        if ($hasArb) {
            require_once __DIR__ . '/arbitrage_scope.php';
            [$where, $params] = arb_scope_biens_where();
        } else {
            $societeId = (int)($_SESSION['id_societe'] ?? 0);
            $where = $societeId ? 'b.id_societe = ?' : '1=1';
            $params = $societeId ? [$societeId] : [];
        }

        $sql = "SELECT b.id, b.reference_bien, b.designation, b.ville, b.code_postal,
                       b.surface_habitable, b.statut_occupation, b.prix_vente_estime, b.loyer_hc,
                       tb.libelle AS type_libelle
                FROM biens b
                LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
                WHERE $where
                ORDER BY b.ville ASC, b.designation ASC, b.id DESC
                LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
