<?php
declare(strict_types=1);

/**
 * inc/honoraires_helper.php
 *
 * Helpers de calcul liés à la location :
 *   - Zone tendue depuis le code postal (table base_zones_tendues)
 *   - Tarifs honoraires par société et zone (table societe_tarifs_honoraires)
 *   - Calcul honoraires location+bail et état des lieux (surface × tarif/m²)
 *   - Calcul loyer charges comprises (loyer HC + charges + complément de loyer)
 *
 * Toutes les fonctions sont "safe" : elles encadrent chaque requête dans un
 * try/catch et retournent des valeurs par défaut si les tables n'existent pas
 * encore ou si une erreur SQL survient. Cela permet au site de continuer à
 * fonctionner même si la migration SQL n'a pas encore été jouée en prod.
 */

/**
 * Déduit la zone tendue à partir du code postal via la table base_zones_tendues.
 * Retourne 'non_tendue' par défaut si aucune correspondance.
 */
function zone_tendue_from_cp(PDO $pdo, ?string $cp): string
{
    $cp = trim((string)$cp);
    if ($cp === '') return 'non_tendue';
    try {
        $st = $pdo->prepare("SELECT zone_tendue FROM base_zones_tendues WHERE code_postal = ? LIMIT 1");
        $st->execute([$cp]);
        $zone = $st->fetchColumn();
        if ($zone && in_array($zone, ['non_tendue','tendue','tres_tendue'], true)) {
            return (string)$zone;
        }
    } catch (Throwable $e) {
        error_log('[honoraires_helper] zone_tendue_from_cp: ' . $e->getMessage());
    }
    return 'non_tendue';
}

/**
 * Récupère les tarifs honoraires applicables à une société et une zone.
 * Fallback sur les tarifs par défaut (id_societe NULL) si la société n'a pas
 * de ligne personnalisée. Fallback final sur les plafonds ALUR codés en dur.
 *
 * @return array{location_bail: float, edl: float}  €/m²
 */
function tarifs_honoraires_get(PDO $pdo, ?int $idSociete, string $zone): array
{
    $zone = in_array($zone, ['non_tendue','tendue','tres_tendue'], true) ? $zone : 'non_tendue';
    // Plafonds ALUR 2026 codés en dur (fallback ultime si table absente).
    // Valeurs indexées IRL applicables à partir de 2026 :
    //   non_tendue 8,07 €/m² · tendue 10,09 €/m² · très_tendue 12,10 €/m² · EDL 3 €/m²
    $defaults = [
        'non_tendue'   => ['location_bail' => 8.07,  'edl' => 3.00],
        'tendue'       => ['location_bail' => 10.09, 'edl' => 3.00],
        'tres_tendue'  => ['location_bail' => 12.10, 'edl' => 3.00],
    ];
    try {
        // 1) Tarif spécifique à la société
        if ($idSociete && $idSociete > 0) {
            $st = $pdo->prepare("
                SELECT honoraires_location_bail_m2, honoraires_edl_m2
                FROM societe_tarifs_honoraires
                WHERE id_societe = ? AND zone_tendue = ? AND actif = 1
                LIMIT 1
            ");
            $st->execute([$idSociete, $zone]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'location_bail' => (float)$row['honoraires_location_bail_m2'],
                    'edl'           => (float)$row['honoraires_edl_m2'],
                ];
            }
        }
        // 2) Tarif par défaut (id_societe NULL)
        $st = $pdo->prepare("
            SELECT honoraires_location_bail_m2, honoraires_edl_m2
            FROM societe_tarifs_honoraires
            WHERE id_societe IS NULL AND zone_tendue = ? AND actif = 1
            LIMIT 1
        ");
        $st->execute([$zone]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'location_bail' => (float)$row['honoraires_location_bail_m2'],
                'edl'           => (float)$row['honoraires_edl_m2'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[honoraires_helper] tarifs_honoraires_get: ' . $e->getMessage());
    }
    return $defaults[$zone];
}

/**
 * Calcule les honoraires location+bail et état des lieux pour une surface donnée.
 *
 * @return array{location_bail: float, edl: float, total: float, zone: string, tarifs: array}
 */
function calcul_honoraires(PDO $pdo, ?int $idSociete, ?string $zone, float $surface): array
{
    $zone = $zone ?: 'non_tendue';
    $tarifs = tarifs_honoraires_get($pdo, $idSociete, $zone);
    $surface = max(0.0, $surface);
    $locBail = round($surface * $tarifs['location_bail'], 2);
    $edl     = round($surface * $tarifs['edl'], 2);
    return [
        'location_bail' => $locBail,
        'edl'           => $edl,
        'total'         => round($locBail + $edl, 2),
        'zone'          => $zone,
        'tarifs'        => $tarifs,
    ];
}

/**
 * Calcule le loyer charges comprises pour une annonce donnée.
 *
 * ⚠️ MODÈLE CORRIGÉ 2026-04-22 — le complément est INCLUS dans le loyer HC,
 * on ne l'ajoute donc PAS une deuxième fois au CC.
 *
 *   loyer_HC = loyer_majoré + complément       [si majoré renseigné]
 *              OU loyer saisi manuellement     [sinon]
 *   loyer_CC = loyer_HC + charges
 *
 * Source des champs :
 *   - annonces.loyer                   (loyer HC — saisi ou auto-calculé)
 *   - annonces.loyer_reference_majore  (zone encadrée — prioritaire sur loyer)
 *   - annonces.complement_loyer        (maintenu par annonce_cpl_*.php)
 *   - biens.charges_locatives          (source canonique des charges)
 */
function loyer_cc_calcul(PDO $pdo, int $idAnnonce): float
{
    if ($idAnnonce <= 0) return 0.0;
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(a.loyer, 0)              AS loyer_hc,
                COALESCE(b.charges_locatives, 0)  AS charges
            FROM annonces a
            LEFT JOIN biens b ON b.id = a.id_bien
            WHERE a.id = ?
            LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0.0;
        // loyer_hc est maintenant la source de vérité (recalculé selon mode en amont)
        return round((float)$row['loyer_hc'] + (float)$row['charges'], 2);
    } catch (Throwable $e) {
        error_log('[honoraires_helper] loyer_cc_calcul: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Retourne la valeur "loyer HC de référence" utilisée pour les dérivés
 * (loyer_CC, dépôt de garantie) :
 *   - loyer_reference_majore + complément si majoré > 0
 *   - sinon annonces.loyer (saisi manuellement)
 */
function loyer_hc_reference(PDO $pdo, int $idAnnonce): float
{
    if ($idAnnonce <= 0) return 0.0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(loyer, 0) FROM annonces WHERE id = ? LIMIT 1");
        $st->execute([$idAnnonce]);
        return (float)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Recalcule et sauvegarde annonces.loyer_reference_majore si le champ est vide
 * et qu'on peut le calculer (enc_loyer_max × surface).
 *
 * Règle : saisie manuelle > calcul auto — n'écrase JAMAIS une valeur existante.
 */
/**
 * Recalcule et sauvegarde les montants vente : prix_net_vendeur, honoraires (€),
 * alur_pourcentage_honoraires_ttc (%), et prix (FAI).
 *
 * Logique :
 *   - prix (FAI) = prix_net_vendeur + honoraires  (TOUJOURS recalculé)
 *   - Si honoraires € et % absents → pas de calcul
 *   - Si % renseigné et honoraires € vide → hono = net × % / 100
 *   - Si honoraires € renseigné et % vide → % = hono / net × 100
 *   - Si les deux renseignés, le dernier saisi côté front prime (le backend
 *     accepte ce que POST envoie, puis synchronise l'autre)
 *
 * @param string|null $lastSaved 'net' | 'hono' | 'pct' | null (si connu, impose la priorité)
 */
function prix_fai_recalc_save(PDO $pdo, int $idAnnonce, ?string $lastSaved = null): ?float
{
    if ($idAnnonce <= 0) return null;
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(prix_net_vendeur, 0) AS net,
                   COALESCE(honoraires, 0)       AS hono,
                   COALESCE(alur_pourcentage_honoraires_ttc, 0) AS pct,
                   COALESCE(prix, 0)             AS prix
            FROM annonces WHERE id = ? LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $net  = (float)$row['net'];
        $hono = (float)$row['hono'];
        $pct  = (float)$row['pct'];

        if ($net > 0) {
            // Si l'user vient de saisir le %, on recalcule hono. Sinon si hono existe, on recalcule %.
            if ($lastSaved === 'pct' && $pct > 0) {
                $hono = round($net * $pct / 100, 2);
            } elseif ($lastSaved === 'hono' && $hono > 0) {
                $pct  = round($hono / $net * 100, 2);
            } elseif ($hono > 0 && $pct <= 0) {
                $pct  = round($hono / $net * 100, 2);
            } elseif ($pct > 0 && $hono <= 0) {
                $hono = round($net * $pct / 100, 2);
            } elseif ($lastSaved === 'net') {
                // Net modifié : on recalcule le plus cohérent (priorité au % s'il > 0)
                if ($pct > 0) $hono = round($net * $pct / 100, 2);
                elseif ($hono > 0) $pct = round($hono / $net * 100, 2);
            }
        }

        $fai = round($net + $hono, 2);

        $pdo->prepare("
            UPDATE annonces
               SET honoraires = ?,
                   alur_pourcentage_honoraires_ttc = ?,
                   prix = ?,
                   date_modification = NOW()
             WHERE id = ?
        ")->execute([$hono, $pct, $fai, $idAnnonce]);

        return $fai;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] prix_fai_recalc_save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Synchronise annonces.complement_loyer depuis la SUM des lignes justificatives,
 * MAIS uniquement si le mode de loyer l'autorise :
 *   - mode 'majore'     : SUM des lignes appliquée (complément effectif)
 *   - autres modes      : complement_loyer forcé à 0 (lignes conservées pour info)
 *
 * À appeler après toute modification de ligne complément (add/update/delete)
 * ET après tout changement de loyer_mode.
 */
function complement_loyer_sync_from_mode(PDO $pdo, int $idAnnonce): float
{
    if ($idAnnonce <= 0) return 0.0;
    try {
        $stM = $pdo->prepare("SELECT COALESCE(loyer_mode, 'libre') FROM annonces WHERE id = ? LIMIT 1");
        $stM->execute([$idAnnonce]);
        $mode = (string)($stM->fetchColumn() ?: 'libre');

        if ($mode !== 'majore') {
            $pdo->prepare("UPDATE annonces SET complement_loyer = 0, date_modification = NOW() WHERE id = ?")
                ->execute([$idAnnonce]);
            return 0.0;
        }

        $stS = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM annonces_complement_loyer_lignes WHERE id_annonce = ?");
        $stS->execute([$idAnnonce]);
        $sum = (float)$stS->fetchColumn();
        $pdo->prepare("UPDATE annonces SET complement_loyer = ?, date_modification = NOW() WHERE id = ?")
            ->execute([$sum, $idAnnonce]);
        return $sum;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] complement_loyer_sync_from_mode: ' . $e->getMessage());
        return 0.0;
    }
}

function loyer_majore_recalc_save(PDO $pdo, int $idAnnonce): ?float
{
    if ($idAnnonce <= 0) return null;
    try {
        $st = $pdo->prepare("
            SELECT a.loyer_reference_majore,
                   COALESCE(b.surface_habitable, b.surface_totale, 0) AS surface,
                   COALESCE(b.enc_loyer_max, 0) AS encmax
            FROM annonces a JOIN biens b ON b.id = a.id_bien
            WHERE a.id = ? LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // Champ rempli manuellement : on le respecte
        if ($row['loyer_reference_majore'] !== null && (float)$row['loyer_reference_majore'] > 0) {
            return (float)$row['loyer_reference_majore'];
        }
        $surface = (float)$row['surface'];
        $encmax  = (float)$row['encmax'];
        if ($surface <= 0 || $encmax <= 0) return null;
        $calc = round($surface * $encmax, 2);
        $pdo->prepare("UPDATE annonces SET loyer_reference_majore = ?, date_modification = NOW() WHERE id = ?")
            ->execute([$calc, $idAnnonce]);
        return $calc;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] loyer_majore_recalc_save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Recalcule et sauvegarde annonces.loyer (HC) = loyer_reference_majore + complément
 * UNIQUEMENT si loyer_reference_majore > 0 (zone encadrée avec valeur).
 *
 * Dans ce cas, le backend écrase toujours annonces.loyer pour maintenir la
 * cohérence : HC affiché = majoré + complément, mis à jour en live dès qu'un
 * complément est ajouté/modifié/supprimé.
 *
 * Si majoré == 0 : on ne touche pas à annonces.loyer (saisie manuelle libre).
 *
 * @return ?float Le nouveau loyer HC, ou null si aucune action.
 */
function loyer_hc_recalc_save(PDO $pdo, int $idAnnonce): ?float
{
    if ($idAnnonce <= 0) return null;
    try {
        $st = $pdo->prepare("
            SELECT a.loyer, a.loyer_reference_majore, a.complement_loyer, a.loyer_mode,
                   COALESCE(b.surface_habitable, b.surface_totale, 0) AS surface,
                   COALESCE(b.enc_loyer_ref, 0)  AS enc_ref,
                   COALESCE(b.enc_loyer_min, 0)  AS enc_min,
                   COALESCE(b.enc_loyer_max, 0)  AS enc_max
            FROM annonces a
            LEFT JOIN biens b ON b.id = a.id_bien
            WHERE a.id = ? LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $mode    = (string)($row['loyer_mode'] ?? 'libre');
        $surface = (float)$row['surface'];
        $majore  = (float)($row['loyer_reference_majore'] ?? 0);
        $cpl     = (float)($row['complement_loyer'] ?? 0);

        $calc = null;
        switch ($mode) {
            case 'libre':
                // Saisie manuelle → on ne touche pas
                return $row['loyer'] !== null ? (float)$row['loyer'] : null;

            case 'majore':
                if ($majore <= 0) return $row['loyer'] !== null ? (float)$row['loyer'] : null;
                $calc = round($majore + $cpl, 2);
                break;

            case 'reference':
                $encRef = (float)$row['enc_ref'];
                if ($surface <= 0 || $encRef <= 0) return $row['loyer'] !== null ? (float)$row['loyer'] : null;
                $calc = round($surface * $encRef, 2);
                break;

            case 'minore':
                $encMin = (float)$row['enc_min'];
                if ($surface <= 0 || $encMin <= 0) return $row['loyer'] !== null ? (float)$row['loyer'] : null;
                $calc = round($surface * $encMin, 2);
                break;

            default:
                return $row['loyer'] !== null ? (float)$row['loyer'] : null;
        }

        if ($row['loyer'] === null || abs(((float)$row['loyer']) - $calc) > 0.001) {
            $pdo->prepare("UPDATE annonces SET loyer = ?, date_modification = NOW() WHERE id = ?")
                ->execute([$calc, $idAnnonce]);
        }
        return $calc;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] loyer_hc_recalc_save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Auto-remplit biens.enc_zone (label zone d'encadrement) depuis la commune
 * de base_zones_tendues, si le label est actuellement vide.
 */
function enc_zone_label_recalc_save(PDO $pdo, int $idBien): ?string
{
    if ($idBien <= 0) return null;
    try {
        $st = $pdo->prepare("
            SELECT b.enc_zone, COALESCE(i.code_postal, b.code_postal) AS cp
            FROM biens b
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            WHERE b.id = ? LIMIT 1
        ");
        $st->execute([$idBien]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!empty($row['enc_zone'])) return (string)$row['enc_zone']; // déjà rempli
        $cp = trim((string)($row['cp'] ?? ''));
        if ($cp === '') return null;
        $stZ = $pdo->prepare("SELECT commune FROM base_zones_tendues WHERE code_postal = ? LIMIT 1");
        $stZ->execute([$cp]);
        $commune = (string)($stZ->fetchColumn() ?: '');
        if ($commune === '') return null;
        $pdo->prepare("UPDATE biens SET enc_zone = ? WHERE id = ?")
            ->execute([$commune, $idBien]);
        return $commune;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] enc_zone_label_recalc_save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Recalcule et sauvegarde annonces.depot_garantie.
 *   - Libre  (meuble=0) : 1 mois de loyer HC de référence
 *   - Meublé (meuble=1) : 2 mois de loyer HC de référence
 *
 * Par défaut, respecte une saisie manuelle (depot_garantie > 0). Passer $force=true
 * pour écraser la valeur existante — utilisé quand l'user toggle le flag meublé,
 * car le montant est légal (pas libre de saisie).
 */
function depot_garantie_recalc_save(PDO $pdo, int $idAnnonce, bool $force = false): ?float
{
    if ($idAnnonce <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT depot_garantie, COALESCE(meuble, 0) AS meuble FROM annonces WHERE id = ? LIMIT 1");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $cur = $row['depot_garantie'];
        if (!$force && $cur !== null && (float)$cur > 0) {
            return (float)$cur; // saisie manuelle respectée
        }
        $hc = loyer_hc_reference($pdo, $idAnnonce);
        if ($hc <= 0) return null;
        $mult = ((int)$row['meuble'] === 1) ? 2 : 1;
        $depot = round($hc * $mult, 2);
        $pdo->prepare("UPDATE annonces SET depot_garantie = ?, date_modification = NOW() WHERE id = ?")
            ->execute([$depot, $idAnnonce]);
        return $depot;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] depot_garantie_recalc_save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Recalcule et sauvegarde annonces.loyer_cc pour une annonce donnée.
 * À appeler après chaque autosave qui modifie loyer, charges ou complement_loyer.
 *
 * @return float Le montant calculé.
 */
function loyer_cc_recalc_save(PDO $pdo, int $idAnnonce): float
{
    if ($idAnnonce <= 0) return 0.0;
    $cc = loyer_cc_calcul($pdo, $idAnnonce);
    try {
        $pdo->prepare("UPDATE annonces SET loyer_cc = ?, date_modification = NOW() WHERE id = ?")
            ->execute([$cc, $idAnnonce]);
    } catch (Throwable $e) {
        error_log('[honoraires_helper] loyer_cc_recalc_save: ' . $e->getMessage());
    }
    return $cc;
}

/**
 * Recalcule les honoraires location+bail et EDL pour une annonce donnée.
 *
 * ⚖️ COMPLIANCE ALUR : contrairement aux autres champs auto-calculés, les
 * honoraires locataire ne peuvent JAMAIS dépasser le plafond légal
 * (surface × tarif de la zone). Si une saisie manuelle dépasse → écrêtage
 * forcé au plafond.
 *
 * Règles appliquées par champ :
 *   - NULL en base     → rempli avec le calcul auto (plafond par défaut)
 *   - ≤ plafond calcul → saisie respectée (l'agent peut facturer moins)
 *   - > plafond calcul → forcé au plafond (cap ALUR) + flag capped=true
 *
 * Met aussi à jour biens.zone_tendue depuis le CP de l'immeuble lié.
 *
 * @return array{location_bail: float, edl: float, total: float, zone: string,
 *               overwritten: bool, location_bail_capped: bool, edl_capped: bool,
 *               plafond_location_bail: float, plafond_edl: float}
 */
function honoraires_recalc_save(PDO $pdo, int $idAnnonce, bool $force = false): array
{
    $result = [
        'location_bail' => 0.0, 'edl' => 0.0, 'total' => 0.0,
        'zone' => 'non_tendue', 'overwritten' => false,
        'location_bail_capped' => false, 'edl_capped' => false,
        'plafond_location_bail' => 0.0, 'plafond_edl' => 0.0,
    ];
    if ($idAnnonce <= 0) return $result;
    try {
        // Récup contexte bien / adresse / surface / société / montants actuels
        $st = $pdo->prepare("
            SELECT
                b.id                                             AS id_bien,
                b.id_societe                                     AS id_societe,
                COALESCE(b.surface_habitable, b.surface_totale, 0) AS surface,
                COALESCE(i.code_postal, b.code_postal)           AS cp,
                b.zone_tendue                                    AS zone_bien,
                a.honoraires_location_bail                       AS curr_loc,
                a.honoraires_etat_des_lieux                      AS curr_edl
            FROM annonces a
            JOIN biens b       ON b.id = a.id_bien
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            WHERE a.id = ?
            LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $result;

        $cp       = (string)($row['cp'] ?? '');
        $surface  = (float)($row['surface'] ?? 0);
        $idSoc    = $row['id_societe'] !== null ? (int)$row['id_societe'] : null;
        $zoneDb   = (string)($row['zone_bien'] ?? '');

        // 1) Déduit zone depuis CP (prioritaire sur ancienne valeur)
        $zone = zone_tendue_from_cp($pdo, $cp);
        if ($zone !== $zoneDb) {
            try {
                $pdo->prepare("UPDATE biens SET zone_tendue = ? WHERE id = ?")
                    ->execute([$zone, (int)$row['id_bien']]);
            } catch (Throwable $e) {
                error_log('[honoraires_helper] update biens.zone_tendue: ' . $e->getMessage());
            }
        }

        // 2) Calcule les plafonds (= montants max ALUR)
        $calc = calcul_honoraires($pdo, $idSoc, $zone, $surface);
        $plafondLoc = (float)$calc['location_bail'];
        $plafondEdl = (float)$calc['edl'];

        // 3) Détermine la valeur finale pour chaque champ + flag capped
        $currLoc = $row['curr_loc'] === null ? null : (float)$row['curr_loc'];
        $currEdl = $row['curr_edl'] === null ? null : (float)$row['curr_edl'];

        // Location + bail
        $cappedLoc = false;
        if ($currLoc === null || $force) {
            $finalLoc = $plafondLoc;               // défaut = plafond
            $writeLoc = true;
        } elseif ($plafondLoc > 0 && $currLoc > $plafondLoc) {
            $finalLoc = $plafondLoc;               // saisie > plafond → cap
            $writeLoc = true;
            $cappedLoc = true;
            error_log(sprintf('[honoraires_helper] CAP ALUR location_bail : annonce=%d saisie=%.2f plafond=%.2f', $idAnnonce, $currLoc, $plafondLoc));
        } else {
            $finalLoc = $currLoc;                  // saisie ≤ plafond → respectée
            $writeLoc = false;
        }

        // État des lieux
        $cappedEdl = false;
        if ($currEdl === null || $force) {
            $finalEdl = $plafondEdl;
            $writeEdl = true;
        } elseif ($plafondEdl > 0 && $currEdl > $plafondEdl) {
            $finalEdl = $plafondEdl;
            $writeEdl = true;
            $cappedEdl = true;
            error_log(sprintf('[honoraires_helper] CAP ALUR edl : annonce=%d saisie=%.2f plafond=%.2f', $idAnnonce, $currEdl, $plafondEdl));
        } else {
            $finalEdl = $currEdl;
            $writeEdl = false;
        }

        // 4) Persiste les écrasements
        if ($writeLoc || $writeEdl) {
            $sets = []; $params = [];
            if ($writeLoc) { $sets[] = "honoraires_location_bail = ?";  $params[] = $finalLoc; }
            if ($writeEdl) { $sets[] = "honoraires_etat_des_lieux = ?"; $params[] = $finalEdl; }
            $sets[] = "date_modification = NOW()";
            $params[] = $idAnnonce;
            try {
                $pdo->prepare("UPDATE annonces SET " . implode(', ', $sets) . " WHERE id = ?")
                    ->execute($params);
            } catch (Throwable $e) {
                error_log('[honoraires_helper] update annonces honoraires: ' . $e->getMessage());
            }
        }

        $result = [
            'location_bail' => (float)$finalLoc,
            'edl'           => (float)$finalEdl,
            'total'         => round((float)$finalLoc + (float)$finalEdl, 2),
            'zone'          => $zone,
            'overwritten'   => ($writeLoc || $writeEdl),
            'location_bail_capped' => $cappedLoc,
            'edl_capped'    => $cappedEdl,
            'plafond_location_bail' => $plafondLoc,
            'plafond_edl'   => $plafondEdl,
        ];
        return $result;
    } catch (Throwable $e) {
        error_log('[honoraires_helper] honoraires_recalc_save: ' . $e->getMessage());
        return $result;
    }
}

/**
 * Retourne le total des honoraires locataire pour une annonce
 * (location+bail + EDL), à partir des colonnes déjà stockées.
 */
function honoraires_locataire_total(PDO $pdo, int $idAnnonce): float
{
    if ($idAnnonce <= 0) return 0.0;
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(honoraires_location_bail, 0) + COALESCE(honoraires_etat_des_lieux, 0)
            FROM annonces WHERE id = ? LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[honoraires_helper] honoraires_locataire_total: ' . $e->getMessage());
        return 0.0;
    }
}
