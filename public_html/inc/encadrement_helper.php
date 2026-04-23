<?php
declare(strict_types=1);

/**
 * inc/encadrement_helper.php
 *
 * Helpers d'encadrement des loyers — Métropole de Lyon / Villeurbanne.
 * Données officielles (arrêté préfectoral 2025-2026) factorisées depuis
 * api/encadrement_loyers.php pour être réutilisables par annonce_autosave
 * lors du toggle de la zone encadrée.
 */

/**
 * Mapping CP → zone (1-5). Retourne null si CP hors zone encadrée.
 */
function enc_cp_to_zone(string $cp): ?int
{
    static $map = [
        '69001' => 1, '69002' => 3, '69003' => 5, '69004' => 2, '69005' => 4,
        '69006' => 3, '69007' => 5, '69008' => 5, '69009' => 5, '69100' => 5,
    ];
    $cp = trim($cp);
    return $map[$cp] ?? null;
}

/**
 * Label zone → arrondissements couverts (pour affichage enc_zone).
 */
function enc_zone_label(int $zone): string
{
    static $labels = [
        1 => 'Lyon 1er',
        2 => 'Lyon 4e',
        3 => 'Lyon 2e, 6e',
        4 => 'Lyon 5e',
        5 => 'Lyon 3e, 7e, 8e, 9e, Villeurbanne',
    ];
    return $labels[$zone] ?? ('Zone ' . $zone);
}

/**
 * Convertit une année de construction en tranche d'époque officielle.
 */
function enc_annee_to_epoque(?int $annee): string
{
    if ($annee === null || $annee <= 0) return 'avant_1946'; // défaut prudent (immeubles anciens Lyon)
    if ($annee < 1946)  return 'avant_1946';
    if ($annee <= 1970) return '1946_1970';
    if ($annee <= 1990) return '1971_1990';
    if ($annee <= 2005) return '1991_2005';
    return 'apres_2005';
}

/**
 * Lookup tarif [ref, max, min] depuis zone, pièces, époque, meublé.
 * Retourne null si combinaison introuvable.
 */
function enc_tarifs_lookup(int $zone, int $pieces, string $epoque, bool $meuble): ?array
{
    $tarifs = enc_tarifs_table();
    if ($pieces < 1) $pieces = 1;
    if ($pieces > 4) $pieces = 4;
    $typeKey = $meuble ? 'meuble' : 'non_meuble';
    return $tarifs[$zone][$pieces][$epoque][$typeKey] ?? null;
}

/**
 * Applique automatiquement l'encadrement depuis l'adresse sur une annonce.
 * Remplit biens.enc_zone, biens.enc_loyer_ref/max/min, active zone_encadrement_loyer
 * et loyer_mode='majore'.
 *
 * @return array{ok:bool, zone?:int, zone_label?:string, applied?:array, error?:string, epoque_inferee?:string, annee_manquante?:bool}
 */
function enc_auto_apply(PDO $pdo, int $idAnnonce): array
{
    if ($idAnnonce <= 0) return ['ok' => false, 'error' => 'id_annonce invalide'];
    try {
        $st = $pdo->prepare("
            SELECT a.id, a.id_bien, a.meuble,
                   COALESCE(b.nb_pieces, 0)             AS nb_pieces,
                   b.annee_construction                 AS annee,
                   COALESCE(b.code_postal, i.code_postal, '') AS cp
            FROM annonces a
            JOIN biens b ON b.id = a.id_bien
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            WHERE a.id = ? LIMIT 1
        ");
        $st->execute([$idAnnonce]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'error' => 'Annonce introuvable'];

        $cp = (string)($row['cp'] ?? '');
        if ($cp === '') return ['ok' => false, 'error' => 'Adresse sans code postal'];

        $zone = enc_cp_to_zone($cp);
        if ($zone === null) {
            return ['ok' => false, 'error' => 'Adresse hors zone encadrée (CP ' . $cp . ')', 'cp' => $cp];
        }

        $pieces = (int)($row['nb_pieces'] ?: 1);
        $annee  = $row['annee'] !== null ? (int)$row['annee'] : null;
        $meuble = (int)($row['meuble'] ?? 0) === 1;
        $epoque = enc_annee_to_epoque($annee);

        $tarif = enc_tarifs_lookup($zone, $pieces, $epoque, $meuble);
        if (!$tarif) {
            return ['ok' => false, 'error' => 'Tarif introuvable pour zone/pieces/epoque'];
        }
        [$ref, $max, $min] = $tarif;
        $label = enc_zone_label($zone);

        $pdo->prepare("
            UPDATE biens
               SET enc_zone      = ?,
                   enc_loyer_ref = ?,
                   enc_loyer_max = ?,
                   enc_loyer_min = ?
             WHERE id = ?
        ")->execute([$label, $ref, $max, $min, (int)$row['id_bien']]);

        $pdo->prepare("
            UPDATE annonces
               SET zone_encadrement_loyer = 1,
                   loyer_mode = 'majore',
                   date_modification = NOW()
             WHERE id = ?
        ")->execute([$idAnnonce]);

        return [
            'ok'          => true,
            'zone'        => $zone,
            'zone_label'  => $label,
            'applied'     => ['enc_loyer_ref' => $ref, 'enc_loyer_max' => $max, 'enc_loyer_min' => $min],
            'epoque_inferee'    => $epoque,
            'annee_manquante'   => ($annee === null),
            'nb_pieces_retenus' => $pieces,
            'meuble'            => $meuble,
        ];
    } catch (Throwable $e) {
        error_log('[enc_auto_apply] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erreur SQL'];
    }
}

/**
 * Table des tarifs officiels €/m² Métropole Lyon 2025-2026.
 * Format : [zone][pieces][epoque][meuble|non_meuble] = [ref, max, min]
 * Source : data.gouv.fr — Arrêté préfectoral 2025-2026.
 */
function enc_tarifs_table(): array
{
    static $tarifs = null;
    if ($tarifs !== null) return $tarifs;
    $tarifs = [
        1 => [ // Lyon 1er
            1 => [
                'avant_1946'  => ['meuble' => [19.8, 23.8, 13.9], 'non_meuble' => [17.5, 21.0, 12.3]],
                '1946_1970'   => ['meuble' => [19.7, 23.6, 13.8], 'non_meuble' => [17.4, 20.9, 12.2]],
                '1971_1990'   => ['meuble' => [21.6, 25.9, 15.1], 'non_meuble' => [19.1, 22.9, 13.4]],
                '1991_2005'   => ['meuble' => [21.4, 25.7, 15.0], 'non_meuble' => [18.9, 22.7, 13.2]],
                'apres_2005'  => ['meuble' => [21.6, 25.9, 15.1], 'non_meuble' => [19.1, 22.9, 13.4]],
            ],
            2 => [
                'avant_1946'  => ['meuble' => [17.0, 20.4, 11.9], 'non_meuble' => [15.0, 18.0, 10.5]],
                '1946_1970'   => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
                '1971_1990'   => ['meuble' => [16.7, 20.0, 11.7], 'non_meuble' => [14.8, 17.8, 10.4]],
                '1991_2005'   => ['meuble' => [17.4, 20.9, 12.2], 'non_meuble' => [15.4, 18.5, 10.8]],
                'apres_2005'  => ['meuble' => [17.9, 21.5, 12.5], 'non_meuble' => [15.8, 19.0, 11.1]],
            ],
            3 => [
                'avant_1946'  => ['meuble' => [14.9, 17.9, 10.4], 'non_meuble' => [13.2, 15.8, 9.2]],
                '1946_1970'   => ['meuble' => [14.2, 17.0, 9.9],  'non_meuble' => [12.6, 15.1, 8.8]],
                '1971_1990'   => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
                '1991_2005'   => ['meuble' => [15.3, 18.4, 10.7], 'non_meuble' => [13.5, 16.2, 9.5]],
                'apres_2005'  => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
            ],
            4 => [
                'avant_1946'  => ['meuble' => [13.9, 16.7, 9.7],  'non_meuble' => [12.3, 14.8, 8.6]],
                '1946_1970'   => ['meuble' => [13.3, 16.0, 9.3],  'non_meuble' => [11.8, 14.2, 8.3]],
                '1971_1990'   => ['meuble' => [13.7, 16.4, 9.6],  'non_meuble' => [12.1, 14.5, 8.5]],
                '1991_2005'   => ['meuble' => [14.8, 17.8, 10.4], 'non_meuble' => [13.1, 15.7, 9.2]],
                'apres_2005'  => ['meuble' => [14.8, 17.8, 10.4], 'non_meuble' => [13.1, 15.7, 9.2]],
            ],
        ],
        2 => [ // Lyon 4e
            1 => [
                'avant_1946'  => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
                '1946_1970'   => ['meuble' => [19.3, 23.2, 13.5], 'non_meuble' => [17.1, 20.5, 12.0]],
                '1971_1990'   => ['meuble' => [20.2, 24.2, 14.1], 'non_meuble' => [17.9, 21.5, 12.5]],
                '1991_2005'   => ['meuble' => [21.9, 26.3, 15.3], 'non_meuble' => [19.4, 23.3, 13.6]],
                'apres_2005'  => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
            ],
            2 => [
                'avant_1946'  => ['meuble' => [15.9, 19.1, 11.1], 'non_meuble' => [14.1, 16.9, 9.9]],
                '1946_1970'   => ['meuble' => [15.3, 18.4, 10.7], 'non_meuble' => [13.5, 16.2, 9.5]],
                '1971_1990'   => ['meuble' => [15.0, 18.0, 10.5], 'non_meuble' => [13.3, 16.0, 9.3]],
                '1991_2005'   => ['meuble' => [16.4, 19.7, 11.5], 'non_meuble' => [14.5, 17.4, 10.2]],
                'apres_2005'  => ['meuble' => [17.1, 20.5, 12.0], 'non_meuble' => [15.1, 18.1, 10.6]],
            ],
            3 => [
                'avant_1946'  => ['meuble' => [14.1, 16.9, 9.9],  'non_meuble' => [12.5, 15.0, 8.8]],
                '1946_1970'   => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
                '1971_1990'   => ['meuble' => [13.6, 16.3, 9.5],  'non_meuble' => [12.0, 14.4, 8.4]],
                '1991_2005'   => ['meuble' => [14.6, 17.5, 10.2], 'non_meuble' => [12.9, 15.5, 9.0]],
                'apres_2005'  => ['meuble' => [15.0, 18.0, 10.5], 'non_meuble' => [13.3, 16.0, 9.3]],
            ],
            4 => [
                'avant_1946'  => ['meuble' => [13.8, 16.6, 9.7],  'non_meuble' => [12.2, 14.6, 8.5]],
                '1946_1970'   => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
                '1971_1990'   => ['meuble' => [12.4, 14.9, 8.7],  'non_meuble' => [11.0, 13.2, 7.7]],
                '1991_2005'   => ['meuble' => [13.4, 16.1, 9.4],  'non_meuble' => [11.9, 14.3, 8.3]],
                'apres_2005'  => ['meuble' => [13.8, 16.6, 9.7],  'non_meuble' => [12.2, 14.6, 8.5]],
            ],
        ],
        3 => [ // Lyon 2e, 6e
            1 => [
                'avant_1946'  => ['meuble' => [19.3, 23.2, 13.5], 'non_meuble' => [17.1, 20.5, 12.0]],
                '1946_1970'   => ['meuble' => [18.6, 22.3, 13.0], 'non_meuble' => [16.5, 19.8, 11.6]],
                '1971_1990'   => ['meuble' => [18.8, 22.6, 13.2], 'non_meuble' => [16.6, 19.9, 11.6]],
                '1991_2005'   => ['meuble' => [20.5, 24.6, 14.4], 'non_meuble' => [18.1, 21.7, 12.7]],
                'apres_2005'  => ['meuble' => [18.5, 22.2, 13.0], 'non_meuble' => [16.4, 19.7, 11.5]],
            ],
            2 => [
                'avant_1946'  => ['meuble' => [15.6, 18.7, 10.9], 'non_meuble' => [13.8, 16.6, 9.7]],
                '1946_1970'   => ['meuble' => [14.7, 17.6, 10.3], 'non_meuble' => [13.0, 15.6, 9.1]],
                '1971_1990'   => ['meuble' => [14.5, 17.4, 10.2], 'non_meuble' => [12.8, 15.4, 9.0]],
                '1991_2005'   => ['meuble' => [16.4, 19.7, 11.5], 'non_meuble' => [14.5, 17.4, 10.2]],
                'apres_2005'  => ['meuble' => [16.5, 19.8, 11.6], 'non_meuble' => [14.6, 17.5, 10.2]],
            ],
            3 => [
                'avant_1946'  => ['meuble' => [13.7, 16.4, 9.6],  'non_meuble' => [12.1, 14.5, 8.5]],
                '1946_1970'   => ['meuble' => [13.6, 16.3, 9.5],  'non_meuble' => [12.0, 14.4, 8.4]],
                '1971_1990'   => ['meuble' => [13.0, 15.6, 9.1],  'non_meuble' => [11.5, 13.8, 8.1]],
                '1991_2005'   => ['meuble' => [14.0, 16.8, 9.8],  'non_meuble' => [12.4, 14.9, 8.7]],
                'apres_2005'  => ['meuble' => [14.8, 17.8, 10.4], 'non_meuble' => [13.1, 15.7, 9.2]],
            ],
            4 => [
                'avant_1946'  => ['meuble' => [13.0, 15.6, 9.1],  'non_meuble' => [11.5, 13.8, 8.1]],
                '1946_1970'   => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
                '1971_1990'   => ['meuble' => [12.5, 15.0, 8.8],  'non_meuble' => [11.1, 13.3, 7.8]],
                '1991_2005'   => ['meuble' => [13.4, 16.1, 9.4],  'non_meuble' => [11.9, 14.3, 8.3]],
                'apres_2005'  => ['meuble' => [13.8, 16.6, 9.7],  'non_meuble' => [12.2, 14.6, 8.5]],
            ],
        ],
        4 => [ // Lyon 5e
            1 => [
                'avant_1946'  => ['meuble' => [18.1, 21.7, 12.7], 'non_meuble' => [16.0, 19.2, 11.2]],
                '1946_1970'   => ['meuble' => [17.6, 21.1, 12.3], 'non_meuble' => [15.6, 18.7, 10.9]],
                '1971_1990'   => ['meuble' => [17.5, 21.0, 12.3], 'non_meuble' => [15.5, 18.6, 10.9]],
                '1991_2005'   => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
                'apres_2005'  => ['meuble' => [17.6, 21.1, 12.3], 'non_meuble' => [15.6, 18.7, 10.9]],
            ],
            2 => [
                'avant_1946'  => ['meuble' => [15.1, 18.1, 10.6], 'non_meuble' => [13.4, 16.1, 9.4]],
                '1946_1970'   => ['meuble' => [14.9, 17.9, 10.4], 'non_meuble' => [13.2, 15.8, 9.2]],
                '1971_1990'   => ['meuble' => [14.6, 17.5, 10.2], 'non_meuble' => [12.9, 15.5, 9.0]],
                '1991_2005'   => ['meuble' => [15.7, 18.8, 11.0], 'non_meuble' => [13.9, 16.7, 9.7]],
                'apres_2005'  => ['meuble' => [16.4, 19.7, 11.5], 'non_meuble' => [14.5, 17.4, 10.2]],
            ],
            3 => [
                'avant_1946'  => ['meuble' => [13.1, 15.7, 9.2],  'non_meuble' => [11.6, 13.9, 8.1]],
                '1946_1970'   => ['meuble' => [12.3, 14.8, 8.6],  'non_meuble' => [10.9, 13.1, 7.6]],
                '1971_1990'   => ['meuble' => [12.2, 14.6, 8.5],  'non_meuble' => [10.8, 13.0, 7.6]],
                '1991_2005'   => ['meuble' => [13.4, 16.1, 9.4],  'non_meuble' => [11.9, 14.3, 8.3]],
                'apres_2005'  => ['meuble' => [14.2, 17.0, 9.9],  'non_meuble' => [12.6, 15.1, 8.8]],
            ],
            4 => [
                'avant_1946'  => ['meuble' => [12.9, 15.5, 9.0],  'non_meuble' => [11.4, 13.7, 8.0]],
                '1946_1970'   => ['meuble' => [11.3, 13.6, 7.9],  'non_meuble' => [10.0, 12.0, 7.0]],
                '1971_1990'   => ['meuble' => [12.1, 14.5, 8.5],  'non_meuble' => [10.7, 12.8, 7.5]],
                '1991_2005'   => ['meuble' => [13.0, 15.6, 9.1],  'non_meuble' => [11.5, 13.8, 8.1]],
                'apres_2005'  => ['meuble' => [12.9, 15.5, 9.0],  'non_meuble' => [11.4, 13.7, 8.0]],
            ],
        ],
        5 => [ // Lyon 3e, 7e, 8e, 9e + Villeurbanne
            1 => [
                'avant_1946'  => ['meuble' => [18.0, 21.6, 12.6], 'non_meuble' => [15.9, 19.1, 11.1]],
                '1946_1970'   => ['meuble' => [16.7, 20.0, 11.7], 'non_meuble' => [14.8, 17.8, 10.4]],
                '1971_1990'   => ['meuble' => [15.9, 19.1, 11.1], 'non_meuble' => [14.1, 16.9, 9.9]],
                '1991_2005'   => ['meuble' => [19.9, 23.9, 13.9], 'non_meuble' => [17.6, 21.1, 12.3]],
                'apres_2005'  => ['meuble' => [18.0, 21.6, 12.6], 'non_meuble' => [15.9, 19.1, 11.1]],
            ],
            2 => [
                'avant_1946'  => ['meuble' => [15.4, 18.5, 10.8], 'non_meuble' => [13.6, 16.3, 9.5]],
                '1946_1970'   => ['meuble' => [14.2, 17.0, 9.9],  'non_meuble' => [12.6, 15.1, 8.8]],
                '1971_1990'   => ['meuble' => [13.7, 16.4, 9.6],  'non_meuble' => [12.1, 14.5, 8.5]],
                '1991_2005'   => ['meuble' => [15.1, 18.1, 10.6], 'non_meuble' => [13.4, 16.1, 9.4]],
                'apres_2005'  => ['meuble' => [16.5, 19.8, 11.6], 'non_meuble' => [14.6, 17.5, 10.2]],
            ],
            3 => [
                'avant_1946'  => ['meuble' => [12.5, 15.0, 8.8],  'non_meuble' => [11.1, 13.3, 7.8]],
                '1946_1970'   => ['meuble' => [12.2, 14.6, 8.5],  'non_meuble' => [10.8, 13.0, 7.6]],
                '1971_1990'   => ['meuble' => [11.8, 14.2, 8.3],  'non_meuble' => [10.4, 12.5, 7.3]],
                '1991_2005'   => ['meuble' => [14.0, 16.8, 9.8],  'non_meuble' => [12.4, 14.9, 8.7]],
                'apres_2005'  => ['meuble' => [14.1, 16.9, 9.9],  'non_meuble' => [12.5, 15.0, 8.8]],
            ],
            4 => [
                'avant_1946'  => ['meuble' => [12.4, 14.9, 8.7],  'non_meuble' => [11.0, 13.2, 7.7]],
                '1946_1970'   => ['meuble' => [11.1, 13.3, 7.8],  'non_meuble' => [9.8, 11.8, 6.9]],
                '1971_1990'   => ['meuble' => [11.3, 13.6, 7.9],  'non_meuble' => [10.0, 12.0, 7.0]],
                '1991_2005'   => ['meuble' => [12.5, 15.0, 8.8],  'non_meuble' => [11.1, 13.3, 7.8]],
                'apres_2005'  => ['meuble' => [13.2, 15.8, 9.2],  'non_meuble' => [11.7, 14.0, 8.2]],
            ],
        ],
    ];
    return $tarifs;
}
