<?php
/**
 * inc/rh_field_validators.php — Validateurs de champs transversaux.
 *
 * Ensemble de fonctions pures, sans effet de bord, utilisées par tous les
 * extracteurs de documents (`inc/rh_extractors/*.php`) pour :
 *   - valider un IBAN (algorithme mod97),
 *   - valider un numéro de sécurité sociale français (clé mod97),
 *   - valider un VIN (checksum OACI position 9),
 *   - valider un MRZ (passeport / CNI — checksum OACI 9303),
 *   - normaliser des dates en YYYY-MM-DD,
 *   - normaliser des noms (capitalisation correcte),
 *   - vérifier la fraîcheur d'un document (ex: justif domicile < 3 mois).
 *
 * Aucun appel réseau, aucune dépendance OpenAI : tout est local.
 */
declare(strict_types=1);

/* ══════════════════════════════════════════════════════════════════════
   1. IBAN — Normalisation + validation mod97
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Nettoie un IBAN : retire les espaces, met en majuscules.
 */
function rhNormalizeIban(string $iban): string
{
    return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
}

/**
 * Valide un IBAN par l'algorithme mod97 (ISO 13616).
 * Retourne true si la structure et la clé sont correctes.
 */
function rhValidateIban(string $iban): bool
{
    $iban = rhNormalizeIban($iban);

    // Format général : 2 lettres pays + 2 chiffres contrôle + 11 à 30 alphanum.
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }

    // Déplace les 4 premiers caractères à la fin
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);

    // Remplace chaque lettre par sa valeur numérique (A=10, B=11, …, Z=35)
    $numeric = '';
    $len = strlen($rearranged);
    for ($i = 0; $i < $len; $i++) {
        $c = $rearranged[$i];
        if (ctype_alpha($c)) {
            $numeric .= (ord($c) - ord('A') + 10);
        } else {
            $numeric .= $c;
        }
    }

    // Calcule numeric mod 97 en chunks (bcmath pas garanti)
    $mod = 0;
    $len = strlen($numeric);
    for ($i = 0; $i < $len; $i += 7) {
        $chunk = (string)$mod . substr($numeric, $i, 7);
        $mod = (int)$chunk % 97;
    }

    return $mod === 1;
}

/* ══════════════════════════════════════════════════════════════════════
   2. BIC / SWIFT — Format uniquement
   ══════════════════════════════════════════════════════════════════════ */

function rhValidateBic(string $bic): bool
{
    $bic = strtoupper(preg_replace('/\s+/', '', $bic) ?? '');
    return (bool)preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic);
}

/* ══════════════════════════════════════════════════════════════════════
   3. NSS — Numéro de sécurité sociale français (15 chiffres + clé mod97)
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Valide un NSS français.
 *
 * Règle (INSEE) :
 *   - 15 chiffres au total : 13 pour l'identifiant + 2 pour la clé
 *   - clé = 97 - ((identifiant 13 chiffres) mod 97)
 *   - cas particulier Corse : remplacer 2A/2B par 19/18 avant calcul
 *
 * Exemple : "1 85 05 78 006 084 36" → valide (Raymond Martin)
 */
function rhValidateNSS(string $nss): bool
{
    // Ne garde que les chiffres et lettres (2A/2B Corse)
    $raw = preg_replace('/[^0-9AB]/i', '', strtoupper($nss)) ?? '';

    if (strlen($raw) !== 15) return false;

    $base = substr($raw, 0, 13);
    $key  = (int)substr($raw, 13, 2);

    // Gestion Corse : 2A → 19, 2B → 18 sur les positions 6-7 (département)
    $dept = substr($base, 5, 2);
    if ($dept === '2A') {
        $base = substr($base, 0, 5) . '19' . substr($base, 7);
    } elseif ($dept === '2B') {
        $base = substr($base, 0, 5) . '18' . substr($base, 7);
    }

    if (!ctype_digit($base)) return false;

    // Calcul mod 97 en chunks (bcmath non garanti)
    $mod = 0;
    $len = strlen($base);
    for ($i = 0; $i < $len; $i += 7) {
        $chunk = (string)$mod . substr($base, $i, 7);
        $mod = (int)$chunk % 97;
    }
    $expected = 97 - $mod;

    return $expected === $key;
}

function rhNormalizeNSS(string $nss): string
{
    $raw = preg_replace('/[^0-9AB]/i', '', strtoupper($nss)) ?? '';
    if (strlen($raw) !== 15) return $raw;
    // Format lisible : X XX XX XX XXX XXX XX
    return sprintf('%s %s %s %s %s %s %s',
        substr($raw, 0, 1), substr($raw, 1, 2),
        substr($raw, 3, 2), substr($raw, 5, 2),
        substr($raw, 7, 3), substr($raw, 10, 3),
        substr($raw, 13, 2)
    );
}

/* ══════════════════════════════════════════════════════════════════════
   4. VIN — Vehicle Identification Number (17 caractères + checksum pos 9)
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Valide un VIN selon la norme ISO 3779 / OACI.
 * - 17 caractères alphanumériques (sans I, O, Q)
 * - Position 9 = chiffre de contrôle (algorithme de pondération)
 */
function rhValidateVIN(string $vin): bool
{
    $vin = strtoupper(trim($vin));
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) return false;

    $values = [
        'A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,
        'J'=>1,'K'=>2,'L'=>3,'M'=>4,'N'=>5,'P'=>7,'R'=>9,
        'S'=>2,'T'=>3,'U'=>4,'V'=>5,'W'=>6,'X'=>7,'Y'=>8,'Z'=>9,
        '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
    ];
    $weights = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];

    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $sum += $values[$vin[$i]] * $weights[$i];
    }
    $check = $sum % 11;
    $checkChar = $check === 10 ? 'X' : (string)$check;

    return $vin[8] === $checkChar;
}

/* ══════════════════════════════════════════════════════════════════════
   5. MRZ — Machine Readable Zone (passeport / CNI)
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Valide une checksum OACI 9303 sur une portion de MRZ.
 * Chaque caractère est pondéré selon le cycle 7/3/1 puis sommé modulo 10.
 */
function rhValidateMRZChecksum(string $segment, int $expected): bool
{
    $weights = [7, 3, 1];
    $sum = 0;
    $len = strlen($segment);
    for ($i = 0; $i < $len; $i++) {
        $c = $segment[$i];
        if (ctype_digit($c)) {
            $val = (int)$c;
        } elseif (ctype_alpha($c)) {
            $val = ord(strtoupper($c)) - ord('A') + 10;
        } elseif ($c === '<') {
            $val = 0;
        } else {
            return false;
        }
        $sum += $val * $weights[$i % 3];
    }
    return ($sum % 10) === $expected;
}

/**
 * Valide le format d'une MRZ (2 lignes de 44 pour passeport TD3,
 * 3 lignes de 30 pour CNI TD1).
 * Retourne true si au moins la structure est cohérente (pas une garantie
 * forte — les checksums individuelles sont validées à l'extraction).
 */
function rhValidateMRZ(string $mrz): bool
{
    $lines = preg_split('/\r\n|\r|\n/', trim($mrz)) ?: [];
    $lines = array_values(array_filter($lines, fn($l) => strlen(trim($l)) > 0));

    if (count($lines) === 2 && strlen($lines[0]) === 44 && strlen($lines[1]) === 44) {
        return (bool)preg_match('/^[A-Z0-9<]+$/', $lines[0] . $lines[1]);
    }
    if (count($lines) === 3 && strlen($lines[0]) === 30 && strlen($lines[1]) === 30 && strlen($lines[2]) === 30) {
        return (bool)preg_match('/^[A-Z0-9<]+$/', $lines[0] . $lines[1] . $lines[2]);
    }
    return false;
}

/* ══════════════════════════════════════════════════════════════════════
   6. Dates — Normalisation et validation
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Normalise une date vers YYYY-MM-DD. Accepte :
 *   - "JJ/MM/AAAA" ou "JJ.MM.AAAA" ou "JJ-MM-AAAA"
 *   - "AAAA-MM-JJ" (déjà normalisé)
 *   - "1 janvier 2025" (mois littéral français)
 *
 * Retourne null si la date ne peut pas être parsée.
 */
function rhNormalizeDate(string $input): ?string
{
    $s = trim($input);
    if ($s === '') return null;

    // Déjà au format ISO
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
    }

    // JJ/MM/AAAA (ou . / -)
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $s, $m)) {
        $day = (int)$m[1]; $mon = (int)$m[2]; $year = (int)$m[3];
        if (checkdate($mon, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $mon, $day);
        }
        return null;
    }

    // "1 janvier 2025" français
    $moisMap = [
        'janvier'=>1,'janv'=>1, 'février'=>2,'fevrier'=>2,'févr'=>2,'fev'=>2,
        'mars'=>3, 'avril'=>4,'avr'=>4, 'mai'=>5, 'juin'=>6,
        'juillet'=>7,'juil'=>7, 'août'=>8,'aout'=>8,
        'septembre'=>9,'sept'=>9, 'octobre'=>10,'oct'=>10,
        'novembre'=>11,'nov'=>11, 'décembre'=>12,'decembre'=>12,'déc'=>12,'dec'=>12,
    ];
    if (preg_match('/^(\d{1,2})\s+([a-zéû]+)\s+(\d{4})$/iu', $s, $m)) {
        $day = (int)$m[1];
        $monKey = strtolower($m[2]);
        $monKey = strtr($monKey, ['é'=>'e','è'=>'e','ê'=>'e','û'=>'u']);
        $year = (int)$m[3];
        if (isset($moisMap[$monKey]) && checkdate($moisMap[$monKey], $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $moisMap[$monKey], $day);
        }
    }

    return null;
}

/**
 * Vérifie qu'une date (YYYY-MM-DD) n'est pas plus vieille que X jours.
 * Utilisé pour les justificatifs de domicile (< 3 mois).
 */
function rhDateIsFresh(string $date, int $maxAgeDays): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d) return false;
    $age = (new DateTimeImmutable('today'))->diff($d)->days;
    return $age <= $maxAgeDays;
}

/**
 * Vérifie qu'une date d'expiration est dans le futur (doc encore valide).
 */
function rhDateNotExpired(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d) return false;
    return $d >= new DateTime('today');
}

/* ══════════════════════════════════════════════════════════════════════
   7. Normalisation de noms
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Capitalise proprement un nom : "DUPONT-MARTIN" → "Dupont-Martin",
 * "jean-pierre" → "Jean-Pierre", "MARIE ANNE" → "Marie Anne".
 */
function rhNormalizeName(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    // Toutes en minuscules avec respect accents
    $lower = mb_strtolower($name, 'UTF-8');
    // Capitalise après séparateurs : espace, tiret, apostrophe
    $result = '';
    $capitalizeNext = true;
    $len = mb_strlen($lower, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($lower, $i, 1, 'UTF-8');
        if (in_array($ch, [' ', '-', "'"], true)) {
            $result .= $ch;
            $capitalizeNext = true;
            continue;
        }
        $result .= $capitalizeNext ? mb_strtoupper($ch, 'UTF-8') : $ch;
        $capitalizeNext = false;
    }
    return $result;
}

/* ══════════════════════════════════════════════════════════════════════
   8. Immatriculation française (SIV ou FNI)
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Nettoie et valide une immat. Accepte AA-000-AA (SIV) ou 0000 AA 00 (FNI).
 */
function rhNormalizePlate(string $plate): string
{
    $raw = strtoupper(preg_replace('/[\s\-\.]/', '', $plate) ?? '');
    // SIV : 2 lettres + 3 chiffres + 2 lettres
    if (preg_match('/^([A-Z]{2})(\d{3})([A-Z]{2})$/', $raw, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    // FNI : 1-4 chiffres + 1-3 lettres + 1-3 chiffres (ancien format)
    if (preg_match('/^(\d{1,4})([A-Z]{1,3})(\d{1,3})$/', $raw, $m)) {
        return $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    return $plate;
}

function rhValidatePlate(string $plate): bool
{
    $raw = strtoupper(preg_replace('/[\s\-\.]/', '', $plate) ?? '');
    return (bool)(
        preg_match('/^[A-Z]{2}\d{3}[A-Z]{2}$/', $raw) ||
        preg_match('/^\d{1,4}[A-Z]{1,3}\d{1,3}$/', $raw)
    );
}
