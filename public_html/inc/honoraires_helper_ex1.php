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
    // Plafonds ALUR par défaut codés en dur (fallback ultime si table absente)
    $defaults = [
        'non_tendue'   => ['location_bail' => 8.00,  'edl' => 3.00],
        'tendue'       => ['location_bail' => 10.00, 'edl' => 3.00],
        'tres_tendue'  => ['location_bail' => 12.00, 'edl' => 3.00],
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
 * Calcule le loyer charges comprises pour une annonce donnée :
 *   loyer_cc = loyer (HC) + charges (biens.charges_locatives) + complément de loyer
 *
 * Source des champs :
 *   - annonces.loyer              (loyer hors charges)
 *   - annonces.complement_loyer   (maintenu par annonce_cpl_*.php)
 *   - biens.charges_locatives     (source canonique des charges)
 */
function loyer_cc_calcul(PDO $pdo, int $idAnnonce): float
{
    if ($idAnnonce <= 0) return 0.0;
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(a.loyer, 0)             AS loyer,
                COALESCE(a.complement_loyer, 0)  AS cpl,
                COALESCE(b.charges_locatives, 0) AS charges
            FROM annonces a
            LEFT JOIN biens b ON b.id = a.id_bien
            WHERE a.id = ?
            LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0.0;
        return round(((float)$row['loyer']) + ((float)$row['charges']) + ((float)$row['cpl']), 2);
    } catch (Throwable $e) {
        error_log('[honoraires_helper] loyer_cc_calcul: ' . $e->getMessage());
        return 0.0;
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
 * Recalcule les honoraires location+bail et EDL pour une annonce donnée, à
 * partir du bien associé (surface, code postal, société). Sauvegarde les
 * deux colonnes sur annonces (honoraires_location_bail, honoraires_etat_des_lieux).
 *
 * Uniquement écrasé si le montant stocké est NULL (jamais saisi) OU si
 * $force = true. Cela évite d'écraser un montant manuellement ajusté.
 *
 * Met également à jour biens.zone_tendue en amont, depuis le code postal de
 * l'immeuble lié.
 *
 * @return array{location_bail: float, edl: float, total: float, zone: string, overwritten: bool}
 */
function honoraires_recalc_save(PDO $pdo, int $idAnnonce, bool $force = false): array
{
    $result = ['location_bail' => 0.0, 'edl' => 0.0, 'total' => 0.0, 'zone' => 'non_tendue', 'overwritten' => false];
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

        // 2) Calcule les honoraires
        $calc = calcul_honoraires($pdo, $idSoc, $zone, $surface);

        // 3) Écrase uniquement si NULL ou $force = true
        $writeLoc = ($force || $row['curr_loc'] === null);
        $writeEdl = ($force || $row['curr_edl'] === null);

        if ($writeLoc || $writeEdl) {
            $sets = []; $params = [];
            if ($writeLoc) { $sets[] = "honoraires_location_bail = ?";  $params[] = $calc['location_bail']; }
            if ($writeEdl) { $sets[] = "honoraires_etat_des_lieux = ?"; $params[] = $calc['edl']; }
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
            'location_bail' => $writeLoc ? $calc['location_bail'] : (float)$row['curr_loc'],
            'edl'           => $writeEdl ? $calc['edl']           : (float)$row['curr_edl'],
            'total'         => 0.0,
            'zone'          => $zone,
            'overwritten'   => ($writeLoc || $writeEdl),
        ];
        $result['total'] = round($result['location_bail'] + $result['edl'], 2);
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
