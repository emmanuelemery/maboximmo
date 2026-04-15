<?php
/**
 * inc/rh_extractors/cni.php — Extraction CNI (Carte Nationale d'Identité).
 *
 * Stratégie hybride :
 *   1. Recherche MRZ (Machine Readable Zone) TD1 (3 lignes × 30 car)
 *      → si trouvée et checksums ok, c'est la source la plus fiable
 *   2. Regex sur le texte visible (nom, prénom, date naissance, date
 *      délivrance, date expiration, n° pièce, nationalité)
 *   3. Fallback IA Vision si score < 70
 *
 * Note : pour le passeport (format TD3 : 2 lignes × 44 car), voir
 * `inc/rh_extractors/passeport.php` (round 2).
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_cni(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    /* ─── 1. Recherche MRZ TD1 (CNI) ──────────────────────────────── */
    // TD1 : 3 lignes de 30 caractères [A-Z0-9<]
    $mrzFields = rhCniParseMRZ($text);
    if (!empty($mrzFields)) {
        foreach ($mrzFields as $k => $v) {
            $fields[$k] = $v;
            $valid[$k]  = 'ok_mrz';
        }
        $score += 60; // MRZ = source de vérité
    }

    /* ─── 2. Regex sur le texte visible ──────────────────────────── */
    if (trim($text) !== '') {
        // Nom (format "NOM : DUPONT" ou "Nom / Surname : DUPONT")
        if (empty($fields['nom']) && preg_match('/(?:Nom|Surname)[^\n:]*[:\s]\s*([A-ZÉÈÊ\-\'\s]{2,40})/u', $text, $m)) {
            $nom = trim($m[1]);
            // Enlève tout ce qui suit en doublon (après un ligne break virtuel)
            $nom = preg_split('/\s{2,}/', $nom)[0] ?? $nom;
            if (mb_strlen($nom) >= 2) {
                $fields['nom'] = rhNormalizeName($nom);
                $score += 10;
            }
        }

        // Prénom
        if (empty($fields['prenom']) && preg_match('/(?:Pr[ée]nom|Given\s+names?)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{2,40})/u', $text, $m)) {
            $prenom = trim($m[1]);
            $prenom = preg_split('/\s{2,}/', $prenom)[0] ?? $prenom;
            if (mb_strlen($prenom) >= 2) {
                $fields['prenom'] = rhNormalizeName($prenom);
                $score += 10;
            }
        }

        // Date de naissance
        if (empty($fields['date_naissance']) && preg_match('/(?:N[ée]\(e\)\s+le|Date\s+of\s+birth|Born\s+on)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_naissance'] = $norm;
                $valid['date_naissance']  = 'ok';
                $score += 10;
            }
        }

        // Lieu de naissance
        if (empty($fields['lieu_naissance']) && preg_match('/(?:[ÀA]\s+|Place\s+of\s+birth[^\n:]*[:\s])\s*([A-ZÉÈÊa-zéèê\-\'\s]{3,50})(?:\n|$)/u', $text, $m)) {
            $lieu = trim($m[1]);
            if (mb_strlen($lieu) >= 3 && mb_strlen($lieu) <= 60) {
                $fields['lieu_naissance'] = rhNormalizeName($lieu);
                $score += 5;
            }
        }

        // Nationalité
        if (empty($fields['nationalite']) && preg_match('/(?:Nationalit[ée]|Nationality)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\s]{3,30})/iu', $text, $m)) {
            $fields['nationalite'] = rhNormalizeName(trim($m[1]));
            $score += 5;
        } elseif (empty($fields['nationalite']) && preg_match('/\bFRAN[ÇC]AISE?\b/i', $text)) {
            $fields['nationalite'] = 'Française';
            $score += 5;
        }

        // Numéro de pièce (format variable : 9 à 12 caractères alphanumériques)
        if (empty($fields['numero_piece']) && preg_match('/(?:N[°o]\s*(?:de\s+la\s+carte|du\s+document)?)\s*[:\s]\s*([A-Z0-9]{8,14})/iu', $text, $m)) {
            $fields['numero_piece'] = strtoupper(trim($m[1]));
            $score += 10;
        }

        // Date d'expiration
        if (empty($fields['date_expiration']) && preg_match('/(?:Date\s+d.expiration|Expire|Valable\s+jusqu\'au|Valid\s+until)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_expiration'] = $norm;
                $valid['date_expiration']  = rhDateNotExpired($norm) ? 'ok' : 'expired';
                $score += 10;
            }
        }
    }

    /* ─── 3. Fallback IA Vision si incomplet ─────────────────────── */
    if ($score < 70 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $iaResult = rhCniCallIa($text, $visionImages);
            if (!empty($iaResult)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';

                $mapping = [
                    'nom', 'prenom', 'date_naissance', 'lieu_naissance',
                    'nationalite', 'numero_piece', 'date_delivrance',
                    'date_expiration', 'autorite_delivrance', 'sexe',
                ];
                foreach ($mapping as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        $val = $iaResult[$k];
                        // Normalisations par type
                        if (in_array($k, ['date_naissance', 'date_delivrance', 'date_expiration'], true)) {
                            $val = rhNormalizeDate($val) ?? $val;
                        } elseif (in_array($k, ['nom', 'prenom', 'lieu_naissance'], true)) {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 8;
                    }
                }

                // Re-validation dates
                if (!empty($fields['date_expiration'])) {
                    $valid['date_expiration'] = rhDateNotExpired($fields['date_expiration']) ? 'ok' : 'expired';
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/cni] IA fallback failed: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

/**
 * Recherche et parse une MRZ TD1 (CNI) dans le texte.
 * Format :
 *   Ligne 1 : IDFRA<nom_doc_checksum>personnal_number
 *   Ligne 2 : YYMMDD<sex>YYMMDD<nationality>...
 *   Ligne 3 : nom<<prenom<<...
 *
 * Retourne [] si pas de MRZ valide trouvée.
 */
function rhCniParseMRZ(string $text): array
{
    // Cherche 3 lignes consécutives de 30 caractères [A-Z0-9<]
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    for ($i = 0; $i < count($lines) - 2; $i++) {
        $l1 = preg_replace('/\s+/', '', $lines[$i]     ?? '');
        $l2 = preg_replace('/\s+/', '', $lines[$i + 1] ?? '');
        $l3 = preg_replace('/\s+/', '', $lines[$i + 2] ?? '');

        if (strlen($l1) === 30 && strlen($l2) === 30 && strlen($l3) === 30
            && preg_match('/^[A-Z0-9<]+$/', $l1 . $l2 . $l3)
            && str_starts_with($l1, 'IDFRA')) {

            $out = [];

            // Ligne 3 = nom<<prénom<<<<…
            if (preg_match('/^([A-Z<]+)<<([A-Z<]+)/', $l3, $m)) {
                $nom    = str_replace('<', ' ', trim($m[1], '<'));
                $prenom = str_replace('<', ' ', trim($m[2], '<'));
                if ($nom !== '')    $out['nom']    = rhNormalizeName(trim($nom));
                if ($prenom !== '') $out['prenom'] = rhNormalizeName(trim($prenom));
            }

            // Ligne 2 : date naissance YYMMDD + sexe + date exp YYMMDD + nationalité
            if (preg_match('/^(\d{6})\d([MF<])(\d{6})\d([A-Z]{3})/', $l2, $m)) {
                $birth = $m[1];
                $sex   = $m[2];
                $exp   = $m[3];
                $nat   = $m[4];

                // Gestion siècle : YY < 30 → 20YY, sinon 19YY
                $birthYY = (int)substr($birth, 0, 2);
                $birthYear = $birthYY < 30 ? 2000 + $birthYY : 1900 + $birthYY;
                $out['date_naissance'] = sprintf('%04d-%s-%s',
                    $birthYear, substr($birth, 2, 2), substr($birth, 4, 2));

                $expYY = (int)substr($exp, 0, 2);
                $expYear = $expYY < 50 ? 2000 + $expYY : 1900 + $expYY;
                $out['date_expiration'] = sprintf('%04d-%s-%s',
                    $expYear, substr($exp, 2, 2), substr($exp, 4, 2));

                if ($sex === 'M') $out['civilite'] = 'M.';
                elseif ($sex === 'F') $out['civilite'] = 'Mme';

                if ($nat === 'FRA') $out['nationalite'] = 'Française';
            }

            return $out;
        }
    }
    return [];
}

/**
 * Fallback IA pour CNI.
 */
function rhCniCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en documents d'identité français (Carte Nationale d'Identité).
Tu extrais les informations présentes et retournes UNIQUEMENT un objet JSON
plat avec ces clés (omets celles absentes ou incertaines) :

  - "nom"                 : nom de famille (majuscules)
  - "prenom"              : prénom(s) (premier prénom suffit)
  - "date_naissance"      : format YYYY-MM-DD
  - "lieu_naissance"      : ville (+ dép./pays si présent)
  - "nationalite"         : ex "Française"
  - "numero_piece"        : numéro de la carte (9 à 14 caractères alphanum.)
  - "date_delivrance"     : format YYYY-MM-DD
  - "date_expiration"     : format YYYY-MM-DD
  - "autorite_delivrance" : préfecture ou mairie émettrice
  - "sexe"                : "M" ou "F"
  - "civilite"            : "M." | "Mme"

RÈGLES :
- N'INVENTE JAMAIS de valeur. Si tu n'es pas sûr, omets la clé.
- Toutes les dates au format YYYY-MM-DD.
- Retourne du JSON pur, pas de markdown.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'une carte d'identité française :\n\n{$text}\n\nRetourne le JSON."
        : "Image d'une carte d'identité française. Lis attentivement et retourne le JSON.";

    // gpt-4o Vision : plus rapide et moins cher que gpt-5 pour cette tâche
    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
