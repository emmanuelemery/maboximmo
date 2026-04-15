<?php
declare(strict_types=1);

/**
 * Recherche floue de propriétaires et d'immeubles existants pour
 * éviter de créer des doublons à partir des données extraites par l'IA.
 *
 * Stratégie :
 *  1. Match exact (nom = X) → score 100
 *  2. Match LIKE (nom LIKE X%) → score 80
 *  3. Match SOUNDEX (consonnance) → score 60
 *  4. Similar_text (PHP) sur le top 30 → re-tri par similarité
 */
class BienIntakeSearch
{
    /**
     * Recherche des propriétaires existants pouvant correspondre aux infos extraites.
     *
     * @param array $extractedFields Champs extraits par l'IA (nom, prenom, societe, ville, email, telephone)
     * @param int   $idAgence        Pour scoper la recherche
     * @param int   $limit           Nombre max de candidats
     * @return array Liste de candidats avec score, format :
     *               [{id, nom, prenom, societe, ville, code_postal, email, telephone, score}]
     */
    public static function searchProprietaires(PDO $pdo, array $extractedFields, ?int $idAgence = null, int $limit = 6): array
    {
        $needleNom    = self::clean($extractedFields['nom'] ?? '');
        $needlePrenom = self::clean($extractedFields['prenom'] ?? '');
        $needleSoc    = self::clean($extractedFields['societe'] ?? '');
        $needleEmail  = strtolower(trim((string)($extractedFields['email'] ?? '')));
        $needlePhone  = preg_replace('/\D/', '', (string)($extractedFields['telephone'] ?? ''));

        if ($needleNom === '' && $needleSoc === '' && $needleEmail === '' && $needlePhone === '') {
            return [];
        }

        // Sélection large : on récupère tout ce qui pourrait matcher en SQL,
        // puis on score finement en PHP avec similar_text()
        $where = ['actif = 1'];
        $params = [];
        if ($idAgence !== null && $idAgence > 0) {
            $where[] = '(id_agence = :ag OR id_agence IS NULL)';
            $params[':ag'] = $idAgence;
        }
        $orParts = [];
        if ($needleNom !== '') {
            $orParts[] = 'nom LIKE :likeNom';
            $orParts[] = 'SOUNDEX(nom) = SOUNDEX(:exactNom)';
            $params[':likeNom'] = mb_substr($needleNom, 0, 4) . '%';
            $params[':exactNom'] = $needleNom;
        }
        if ($needleSoc !== '') {
            $orParts[] = 'societe LIKE :likeSoc';
            $params[':likeSoc'] = '%' . $needleSoc . '%';
        }
        if ($needleEmail !== '') {
            $orParts[] = 'email = :exactEmail';
            $params[':exactEmail'] = $needleEmail;
        }
        if ($needlePhone !== '' && strlen($needlePhone) >= 8) {
            $orParts[] = 'REPLACE(REPLACE(REPLACE(REPLACE(telephone, " ", ""), ".", ""), "-", ""), "+33", "0") LIKE :likePhone';
            $params[':likePhone'] = '%' . substr($needlePhone, -8) . '%';
        }
        if (empty($orParts)) return [];
        $where[] = '(' . implode(' OR ', $orParts) . ')';

        $sql = "SELECT id, type_personne, civilite, nom, prenom, societe,
                       email, telephone, adresse_1, code_postal, ville
                FROM proprietaires
                WHERE " . implode(' AND ', $where) . "
                ORDER BY nom, prenom
                LIMIT 30";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        // Scoring fin PHP — combine similar_text() sur nom + société
        $candidates = [];
        foreach ($rows as $row) {
            $score = self::scoreProprietaire($row, $needleNom, $needlePrenom, $needleSoc, $needleEmail, $needlePhone);
            if ($score < 30) continue;
            $row['score'] = $score;
            $row['_label'] = self::buildProprioLabel($row);
            $candidates[] = $row;
        }

        // Tri descendant par score, puis on garde les N meilleurs
        usort($candidates, fn($a, $b) => ($b['score'] <=> $a['score']));
        return array_slice($candidates, 0, $limit);
    }

    /**
     * Recherche des immeubles existants à partir d'une adresse extraite.
     *
     * Cherche dans 2 sources fusionnées :
     *   1. `immeubles` — table principale (partagée entre toutes les agences,
     *      pas de filtre par id_agence pour ne pas rater des doublons)
     *   2. `reg_immeubles` — registre national des copropriétés immatriculées
     *
     * @return array Candidats avec source = 'immeubles' | 'reg'
     */
    public static function searchImmeubles(PDO $pdo, array $extractedFields, ?int $idAgence = null, int $limit = 10): array
    {
        $adr   = self::clean($extractedFields['adresse_1'] ?? '');
        $cp    = trim((string)($extractedFields['code_postal'] ?? ''));
        $ville = self::clean($extractedFields['ville'] ?? '');

        if ($adr === '' && $cp === '' && $ville === '') return [];

        $candidates = [];

        // ─── Source 1 : table `immeubles` (toute la base, pas de filtre agence) ───
        try {
            $whereParts = [];
            $params = [];
            if ($adr !== '') {
                $shortAdr = mb_substr($adr, 0, 20);
                $whereParts[] = 'adresse_1 LIKE :likeAdr';
                $params[':likeAdr'] = '%' . $shortAdr . '%';
            }
            if ($cp !== '') {
                $whereParts[] = 'code_postal = :exactCp';
                $params[':exactCp'] = $cp;
            }
            if (!empty($whereParts)) {
                $sql = "SELECT id, adresse_1, adresse_2, code_postal, ville,
                               latitude, longitude, nom_immeuble,
                               (SELECT COUNT(*) FROM biens b WHERE b.id_immeuble = immeubles.id) AS nb_biens
                        FROM immeubles
                        WHERE " . implode(' OR ', $whereParts) . "
                        ORDER BY id DESC
                        LIMIT 50";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $score = self::scoreImmeuble($row, $adr, $cp, $ville);
                    if ($score < 30) continue;
                    $row['score']   = $score;
                    $row['source']  = 'immeubles';
                    $row['_label']  = self::buildImmeubleLabel($row);
                    $candidates[] = $row;
                }
            }
        } catch (Throwable) {}

        // ─── Source 2 : table `reg_immeubles` (registre national copros) ───
        try {
            $whereParts = [];
            $params = [];
            if ($adr !== '') {
                $whereParts[] = 'adresse LIKE :likeAdr';
                $params[':likeAdr'] = '%' . mb_substr($adr, 0, 20) . '%';
            }
            if ($cp !== '') {
                $whereParts[] = 'code_postal = :exactCp';
                $params[':exactCp'] = $cp;
            }
            if (!empty($whereParts)) {
                $sql = "SELECT id, reference, nom, adresse, code_postal, ville,
                               nb_lots, type, immatriculation, gestionnaire
                        FROM reg_immeubles
                        WHERE " . implode(' OR ', $whereParts) . "
                        ORDER BY id DESC
                        LIMIT 50";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    // Adapte les noms de colonnes pour scorer
                    $rowForScore = [
                        'adresse_1'   => $row['adresse'] ?? null,
                        'code_postal' => $row['code_postal'] ?? null,
                        'ville'       => $row['ville'] ?? null,
                    ];
                    $score = self::scoreImmeuble($rowForScore, $adr, $cp, $ville);
                    if ($score < 30) continue;
                    $candidates[] = [
                        'id'             => (int)$row['id'],
                        'adresse_1'      => $row['adresse'] ?? null,
                        'adresse_2'      => null,
                        'code_postal'    => $row['code_postal'] ?? null,
                        'ville'          => $row['ville'] ?? null,
                        'nom_immeuble'   => $row['nom'] ?? null,
                        'nb_biens'       => 0,
                        'nb_lots'        => $row['nb_lots'] ?? null,
                        'type_immeuble'  => $row['type'] ?? null,
                        'immatriculation'=> $row['immatriculation'] ?? null,
                        'gestionnaire'   => $row['gestionnaire'] ?? null,
                        'reference'      => $row['reference'] ?? null,
                        'score'          => $score,
                        'source'         => 'reg',
                        '_label'         => trim(
                            ($row['nom'] ? $row['nom'] . ' • ' : '')
                            . ($row['adresse'] ?? '')
                            . ' • ' . ($row['code_postal'] ?? '') . ' ' . ($row['ville'] ?? '')
                        ),
                    ];
                }
            }
        } catch (Throwable) {}

        // Tri descendant par score, top N
        usort($candidates, fn($a, $b) => ($b['score'] <=> $a['score']));
        return array_slice($candidates, 0, $limit);
    }

    // ─────────────────────────────────────────────────────────────
    // Scoring
    // ─────────────────────────────────────────────────────────────

    private static function scoreProprietaire(array $row, string $nom, string $prenom, string $societe, string $email, string $phone): int
    {
        $score = 0;
        $rowNom = self::clean((string)($row['nom'] ?? ''));
        $rowPre = self::clean((string)($row['prenom'] ?? ''));
        $rowSoc = self::clean((string)($row['societe'] ?? ''));
        $rowEmail = strtolower(trim((string)($row['email'] ?? '')));
        $rowPhone = preg_replace('/\D/', '', (string)($row['telephone'] ?? ''));

        // Email match exact = très fort signal
        if ($email !== '' && $rowEmail === $email) $score += 100;

        // Téléphone match (8 derniers chiffres)
        if ($phone !== '' && $rowPhone !== '' && substr($phone, -8) === substr($rowPhone, -8)) $score += 80;

        // Match nom
        if ($nom !== '' && $rowNom !== '') {
            if ($rowNom === $nom)               $score += 100;
            elseif (str_starts_with($rowNom, $nom)) $score += 80;
            else {
                $sim = 0;
                similar_text($rowNom, $nom, $sim);
                if ($sim >= 80) $score += 70;
                elseif ($sim >= 60) $score += 50;
                elseif ($sim >= 40) $score += 30;
            }
        }

        // Match prénom (bonus)
        if ($prenom !== '' && $rowPre !== '') {
            if ($rowPre === $prenom) $score += 30;
            elseif (str_starts_with($rowPre, $prenom)) $score += 20;
        }

        // Match société
        if ($societe !== '' && $rowSoc !== '') {
            if ($rowSoc === $societe)                  $score += 100;
            elseif (str_contains($rowSoc, $societe))   $score += 70;
            else {
                $sim = 0;
                similar_text($rowSoc, $societe, $sim);
                if ($sim >= 70) $score += 50;
            }
        }

        return min(100, (int)round($score / 2)); // normalise à 100
    }

    private static function scoreImmeuble(array $row, string $adr, string $cp, string $ville): int
    {
        $score = 0;
        $rowAdr   = self::clean((string)($row['adresse_1'] ?? ''));
        $rowCp    = trim((string)($row['code_postal'] ?? ''));
        $rowVille = self::clean((string)($row['ville'] ?? ''));

        // Code postal = signal fort
        if ($cp !== '' && $rowCp === $cp) $score += 60;

        // Ville
        if ($ville !== '' && $rowVille !== '') {
            if ($rowVille === $ville)               $score += 40;
            elseif (str_contains($rowVille, $ville)) $score += 25;
        }

        // Adresse — c'est le critère principal
        if ($adr !== '' && $rowAdr !== '') {
            if ($rowAdr === $adr)                     $score += 100;
            elseif (str_contains($rowAdr, $adr) || str_contains($adr, $rowAdr)) $score += 70;
            else {
                $sim = 0;
                similar_text($rowAdr, $adr, $sim);
                if ($sim >= 80) $score += 70;
                elseif ($sim >= 60) $score += 45;
                elseif ($sim >= 40) $score += 25;
            }
        }

        return min(100, (int)round($score / 2));
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private static function clean(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        // Normalise les accents
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false) $s = $tr;
        }
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private static function buildProprioLabel(array $row): string
    {
        if (!empty($row['societe'])) {
            $label = $row['societe'];
            if (!empty($row['nom'])) $label .= ' (' . trim(($row['prenom'] ?? '') . ' ' . $row['nom']) . ')';
        } else {
            $label = trim(($row['civilite'] ?? '') . ' ' . ($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''));
        }
        if (!empty($row['ville'])) $label .= ' — ' . $row['ville'];
        return $label;
    }

    private static function buildImmeubleLabel(array $row): string
    {
        $parts = [];
        if (!empty($row['nom_immeuble'])) $parts[] = $row['nom_immeuble'];
        if (!empty($row['adresse_1']))   $parts[] = $row['adresse_1'];
        if (!empty($row['adresse_2']))   $parts[] = $row['adresse_2'];
        $loc = trim(($row['code_postal'] ?? '') . ' ' . ($row['ville'] ?? ''));
        if ($loc !== '') $parts[] = $loc;
        return implode(' • ', $parts);
    }
}
