<?php
declare(strict_types=1);

/**
 * inc/creancier_enrich_from_doc.php
 *
 * Hook métier post-commit FluxBox pour les documents rattachés à un dossier créancier.
 * Depuis l'extraction IA d'un document (acte d'avocat, jugement, courrier…), enrichit
 * le dossier SANS rien dupliquer :
 *   - résout/crée le tiers créancier (rôle 'creancier') + le lie au dossier ;
 *   - crée un item DETTE (montant extrait) ;
 *   - recopie le commentaire/avis de l'avocat sur le dossier (creancier_dossier.commentaire).
 *
 * Tolérant : lit plusieurs clés possibles de l'extraction IA générique FluxBox.
 */

if (!function_exists('cef_pick')) {
    /** Première valeur non vide parmi plusieurs clés candidates d'un tableau. */
    function cef_pick(array $a, array $keys): ?string {
        foreach ($keys as $k) {
            if (isset($a[$k]) && trim((string)$a[$k]) !== '') return trim((string)$a[$k]);
            // clé imbriquée "creancier.nom"
            if (str_contains($k, '.')) {
                [$p, $c] = explode('.', $k, 2);
                if (isset($a[$p][$c]) && trim((string)$a[$p][$c]) !== '') return trim((string)$a[$p][$c]);
            }
        }
        return null;
    }
}

if (!function_exists('cef_num')) {
    function cef_num($v): ?float {
        if ($v === null || $v === '') return null;
        $s = preg_replace('/[^0-9,.\-]/', '', (string)$v);
        $s = str_replace(',', '.', str_replace(' ', '', $s));
        return is_numeric($s) ? (float)$s : null;
    }
}

if (!function_exists('cef_flatten')) {
    /** Aplatit un tableau IA en paires clé pointée → valeur scalaire. */
    function cef_flatten(array $a, string $prefix = ''): array {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                // liste de scalaires → join ; sinon récursion
                $isList = array_keys($v) === range(0, count($v) - 1);
                if ($isList && !array_filter($v, 'is_array')) {
                    $out[$key] = implode(' | ', array_map('strval', $v));
                } else {
                    $out += cef_flatten($v, $key);
                }
            } elseif ($v !== null && $v !== '') {
                $out[$key] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
            }
        }
        return $out;
    }
}

if (!function_exists('cef_capture_champs')) {
    /**
     * Capture AUTOMATIQUE de tous les champs extraits dans creancier_doc_champ
     * (clé/valeur), sans jamais altérer le schéma. Idempotent par (doc, clé).
     */
    function cef_capture_champs(PDO $pdo, int $idDossier, array $ia, ?int $gedDocId, ?int $userId): int {
        $flat = cef_flatten($ia);
        if (!$flat) return 0;
        // Purge des champs précédents de ce doc (re-import) pour éviter les doublons.
        if ($gedDocId) {
            $pdo->prepare("DELETE FROM creancier_doc_champ WHERE ged_document_id = ?")->execute([$gedDocId]);
        }
        $ins = $pdo->prepare("INSERT INTO creancier_doc_champ (id_dossier, ged_document_id, cle, valeur, source, created_by) VALUES (?,?,?,?, 'ia', ?)");
        $n = 0;
        foreach ($flat as $cle => $val) {
            $ins->execute([$idDossier ?: null, $gedDocId ?: null, mb_substr($cle, 0, 150), mb_substr((string)$val, 0, 65000), $userId]);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('cef_enrich_dossier')) {
    /**
     * @param PDO   $pdo
     * @param int   $idDossier
     * @param array $ia    Extraction IA (clés génériques tolérées)
     * @param array $ctx   ['ged_document_id'=>int,'created_by'=>int,'type_doc'=>string]
     * @return array ['actions'=>string[], 'id_creancier'=>?int, 'item_dette_id'=>?int]
     */
    function cef_enrich_dossier(PDO $pdo, int $idDossier, array $ia, array $ctx = []): array {
        $actions = [];
        $userId  = (int)($ctx['created_by'] ?? 0) ?: null;

        // Tenant du dossier.
        $st = $pdo->prepare("SELECT id_societe, id_agence FROM creancier_dossier WHERE id = ? LIMIT 1");
        $st->execute([$idDossier]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return ['actions' => [], 'id_creancier' => null, 'item_dette_id' => null];
        $socId = $d['id_societe'] !== null ? (int)$d['id_societe'] : null;
        $ageId = $d['id_agence']  !== null ? (int)$d['id_agence']  : null;

        // ── Champs extraits (clés tolérantes) ──
        $creaNom  = cef_pick($ia, ['creancier_nom', 'creancier.nom', 'creancier', 'emetteur', 'expediteur', 'partie_adverse']);
        $numDoss  = cef_pick($ia, ['numero_dossier', 'reference_dossier', 'reference', 'num_dossier']);
        $objet    = cef_pick($ia, ['objet', 'cause', 'nature']);
        $commentaire = cef_pick($ia, ['commentaire', 'avis', 'avis_avocat', 'observations', 'conclusion', 'recommandation']);
        $montant  = cef_num(cef_pick($ia, ['montant_total', 'montants.total', 'montant', 'montant_principal', 'montants.principal']));

        // ── 1. Tiers créancier (match anti-doublon sinon création) ──
        $idCreancier = 0;
        if ($creaNom) {
            $stm = $pdo->prepare("SELECT id FROM tiers
                WHERE (raison_sociale LIKE :q OR nom LIKE :q OR nom_affichage LIKE :q)
                  AND (:soc IS NULL OR id_societe IS NULL OR id_societe = :soc)
                ORDER BY (raison_sociale = :exact OR nom_affichage = :exact) DESC LIMIT 1");
            $stm->bindValue(':q', '%' . $creaNom . '%'); $stm->bindValue(':exact', $creaNom); $stm->bindValue(':soc', $socId);
            $stm->execute();
            $idCreancier = (int)($stm->fetchColumn() ?: 0);
            if ($idCreancier <= 0) {
                $pdo->prepare("INSERT INTO tiers (id_societe, id_agence, type_tiers, raison_sociale, nom_affichage, actif, id_user_createur, date_creation, date_modification)
                               VALUES (?,?, 'personne_morale', ?, ?, 1, ?, NOW(), NOW())")
                    ->execute([$socId, $ageId, $creaNom, $creaNom, $userId]);
                $idCreancier = (int)$pdo->lastInsertId();
                $actions[] = "créancier créé «$creaNom»";
            } else {
                $actions[] = "créancier rattaché «$creaNom»";
            }
            $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, actif) VALUES (?, 'creancier', NULL, 1)")
                ->execute([$idCreancier]);
            $pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, created_by) VALUES (?, 'TIERS', ?, 'creancier', ?)")
                ->execute([$idDossier, $idCreancier, $userId]);
        }

        // ── 1b. Professionnel (avocat / huissier-commissaire) → tiers + lien ──
        $proNom  = cef_pick($ia, ['professionnel.nom', 'pro', 'avocat', 'huissier', 'commissaire', 'cabinet']);
        $proRoleRaw = strtolower((string)(cef_pick($ia, ['professionnel.role', 'pro_role', 'role_pro']) ?? ''));
        $proRole = (str_contains($proRoleRaw, 'huissier') || str_contains($proRoleRaw, 'commissaire')) ? 'commissaire_justice'
                 : (str_contains($proRoleRaw, 'notaire') ? 'notaire' : 'avocat');
        if ($proNom) {
            $stp = $pdo->prepare("SELECT id FROM tiers WHERE (raison_sociale LIKE :q OR nom LIKE :q OR nom_affichage LIKE :q)
                AND (:soc IS NULL OR id_societe IS NULL OR id_societe = :soc) LIMIT 1");
            $stp->bindValue(':q', '%' . $proNom . '%'); $stp->bindValue(':soc', $socId); $stp->execute();
            $idPro = (int)($stp->fetchColumn() ?: 0);
            if ($idPro <= 0) {
                $pdo->prepare("INSERT INTO tiers (id_societe, id_agence, type_tiers, raison_sociale, nom_affichage, actif, id_user_createur, date_creation, date_modification)
                               VALUES (?,?, 'personne_morale', ?, ?, 1, ?, NOW(), NOW())")
                    ->execute([$socId, $ageId, $proNom, $proNom, $userId]);
                $idPro = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, actif) VALUES (?, ?, NULL, 1)")->execute([$idPro, $proRole]);
            $pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, created_by) VALUES (?, 'TIERS', ?, ?, ?)")
                ->execute([$idDossier, $idPro, $proRole, $userId]);
            $actions[] = "$proRole « $proNom »";
        }

        // ── 1c. Bien (OPTIONNEL — une créance peut porter sur la société, pas un bien) ──
        $bienRef = cef_pick($ia, ['bien.reference', 'reference_bien', 'bien_reference']);
        $bienAdr = cef_pick($ia, ['bien.adresse', 'adresse_bien', 'adresse']);
        if ($bienRef || $bienAdr) {
            try {
                require_once __DIR__ . '/entity_matcher.php';
                if (function_exists('em_match_bien')) {
                    $m = em_match_bien($pdo, ['adresse' => (string)$bienAdr, 'reference' => (string)$bienRef]);
                    if (!empty($m['found']) && (int)($m['confidence'] ?? 0) >= 75 && !empty($m['best']['id'])) {
                        $pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, created_by) VALUES (?, 'BIEN', ?, 'bien_concerne', ?)")
                            ->execute([$idDossier, (int)$m['best']['id'], $userId]);
                        $actions[] = "bien #" . (int)$m['best']['id'] . " lié";
                    }
                }
            } catch (Throwable) {} // bien non identifié → on n'impose rien
        }

        // ── 1d. Locataire saisi → saisie ATTRIBUTION_LOYER (conservateur) ──
        // Se déclenche si l'IA fournit un loyer capté + un locataire, OU type_saisie=ATTRIBUTION_LOYER.
        $typeSaisie = strtoupper((string)(cef_pick($ia, ['type_saisie','saisie_type']) ?? ''));
        $locNom     = cef_pick($ia, ['locataire','locataire_nom','locataire.nom']);
        $loyerCapte = cef_num(cef_pick($ia, ['loyer_capte','loyer_mensuel_capte','loyer_saisi','loyer_mensuel','loyer']));
        $estAttribLoyer = ($typeSaisie === 'ATTRIBUTION_LOYER') || ($locNom && $loyerCapte !== null && $loyerCapte > 0);
        if ($estAttribLoyer && $idCreancier > 0) {
            // Match du bail par nom de locataire (cible LOCATAIRE = bien_baux.id) — best effort.
            $bailId = 0;
            if ($locNom) {
                try {
                    $stb = $pdo->prepare("SELECT id FROM bien_baux WHERE locataire_nom LIKE ? ORDER BY (statut='actif') DESC, id DESC LIMIT 1");
                    $stb->execute(['%' . $locNom . '%']);
                    $bailId = (int)($stb->fetchColumn() ?: 0);
                } catch (Throwable $e) {}
            }
            // Évite les doublons : une saisie identique (créancier + cible) déjà liée au dossier ?
            $dup = $pdo->prepare("SELECT 1 FROM creancier_dossier_lien l JOIN creancier_saisie s ON s.id=l.entity_id
                WHERE l.id_dossier=? AND l.entity_type='SAISIE' AND s.id_creancier=? AND s.cible_type='LOCATAIRE' AND s.cible_id=? LIMIT 1");
            $dup->execute([$idDossier, $idCreancier, $bailId]);
            if (!$dup->fetchColumn()) {
                $net = $montant ?? ($loyerCapte ?? 0);
                $insS = $pdo->prepare("INSERT INTO creancier_saisie
                    (id_societe,id_agence,type_saisie,id_creancier,cible_type,cible_id,montant_reclame,montant_net_bloque,loyer_mensuel_capte,statut,reference_acte,created_by)
                    VALUES (?,?, 'ATTRIBUTION_LOYER', ?, 'LOCATAIRE', ?, ?, ?, ?, 'en_cours', ?, ?)");
                $insS->execute([$socId,$ageId,$idCreancier,$bailId ?: null, $net, $net, $loyerCapte, mb_substr((string)($ctx['type_doc'] ?? 'attribution_loyer'),0,190), $userId]);
                $idSaisie = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier,entity_type,entity_id,role_dossier,created_by) VALUES (?, 'SAISIE', ?, 'saisie_loyers', ?)")
                    ->execute([$idDossier,$idSaisie,$userId]);
                $actions[] = "locataire saisi" . ($locNom ? " ($locNom)" : "") . ($loyerCapte ? " — loyer " . number_format($loyerCapte,0,',',' ') . " €" : "");
            }
        }

        // ── 2. Item DETTE ──
        $itemId = 0;
        if ($montant !== null && $montant > 0) {
            $titre = 'Créance ' . ($creaNom ?: 'créancier') . ($numDoss ? " ($numDoss)" : '');
            $pdo->prepare("INSERT INTO creancier_dossier_item (id_dossier, type, titre, description, montant, id_tiers_lie, statut, priorite, created_by)
                           VALUES (?, 'DETTE', ?, ?, ?, ?, 'ouvert', 5, ?)")
                ->execute([$idDossier, mb_substr($titre, 0, 190), $objet, $montant, $idCreancier ?: null, $userId]);
            $itemId = (int)$pdo->lastInsertId();
            $actions[] = "dette " . number_format($montant, 0, ',', ' ') . " €";
        }

        // ── 3. Commentaire avocat recopié sur le dossier ──
        if ($commentaire) {
            $stcur = $pdo->prepare("SELECT commentaire FROM creancier_dossier WHERE id = ?");
            $stcur->execute([$idDossier]);
            $cur = (string)($stcur->fetchColumn() ?: '');
            $stamp = '[' . ($ctx['type_doc'] ?? 'doc') . '] ' . $commentaire;
            $new = trim($cur) === '' ? $stamp : ($cur . "\n— " . $stamp);
            $pdo->prepare("UPDATE creancier_dossier SET commentaire = ?, updated_at = NOW() WHERE id = ?")
                ->execute([mb_substr($new, 0, 60000), $idDossier]);
            $actions[] = "commentaire avocat recopié";
        }

        // ── 4. N° dossier adverse si absent ──
        if ($numDoss) {
            $pdo->prepare("UPDATE creancier_dossier SET numero_dossier_adverse = COALESCE(NULLIF(numero_dossier_adverse,''), ?) WHERE id = ?")
                ->execute([$numDoss, $idDossier]);
        }

        // ── 5. Agenda : dates extraites du scan → creancier_echeance ──
        $dateMap = [
            'date_audience'      => 'audience',
            'date_butoir'        => 'butoir',
            'date_signification' => 'signification',
            'date_expertise'     => 'expertise',
            'date_appel'         => 'delai_appel',
            'date_limite'        => 'butoir',
            'date_echeance'      => 'echeance',
        ];
        $nbDates = 0;
        foreach ($dateMap as $key => $type) {
            $dv = cef_pick($ia, [$key, 'dates.' . $key]);
            if ($dv && preg_match('/\d{4}-\d{2}-\d{2}/', $dv, $mD)) {
                $pdo->prepare("INSERT INTO creancier_echeance (id_dossier, type, libelle, date_echeance, source, ged_document_id, id_tiers_lie, created_by)
                               VALUES (?,?,?,?, 'scan', ?, ?, ?)")
                    ->execute([$idDossier, $type, ucfirst($type) . ($creaNom ? ' — ' . $creaNom : ''), $mD[0],
                               (int)($ctx['ged_document_id'] ?? 0) ?: null, $idCreancier ?: null, $userId]);
                $nbDates++;
            }
        }
        if ($nbDates) $actions[] = "$nbDates date(s) → agenda";

        // ── 6. Capture générique de TOUS les champs extraits (aucun ALTER) ──
        $nbChamps = cef_capture_champs($pdo, $idDossier, $ia, (int)($ctx['ged_document_id'] ?? 0) ?: null, $userId);
        if ($nbChamps) $actions[] = "$nbChamps champ(s) capturé(s)";

        return ['actions' => $actions, 'id_creancier' => $idCreancier ?: null, 'item_dette_id' => $itemId ?: null];
    }
}
