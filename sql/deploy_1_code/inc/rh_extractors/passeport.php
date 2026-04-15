<?php
/**
 * inc/rh_extractors/passeport.php — Extraction passeport français.
 *
 * Format MRZ TD3 : 2 lignes de 44 caractères.
 *   Ligne 1 : P<FRA<NOM<<PRENOM<<<<<<<<<<<<<<<<<<<<<<<<<<<<<
 *   Ligne 2 : n°passeport + clé + FRA + date_naissance + sexe + date_exp + ...
 *
 * Stratégie hybride : parse MRZ (si détectée) + regex texte + fallback Vision IA.
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_passeport(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    /* ─── 1. Parse MRZ TD3 si trouvée ──────────────────────────── */
    $mrzFields = rhPasseportParseMRZ($text);
    if (!empty($mrzFields)) {
        foreach ($mrzFields as $k => $v) {
            $fields[$k] = $v;
            $valid[$k]  = 'ok_mrz';
        }
        $score += 60;
    }

    /* ─── 2. Regex sur le texte visible ──────────────────────── */
    if (trim($text) !== '') {
        if (empty($fields['nom']) && preg_match('/(?:Nom|Surname)[^\n:]*[:\s]\s*([A-ZÉÈÊ\-\'\s]{2,40})/u', $text, $m)) {
            $nom = preg_split('/\s{2,}/', trim($m[1]))[0] ?? $m[1];
            if (mb_strlen($nom) >= 2) { $fields['nom'] = rhNormalizeName($nom); $score += 8; }
        }
        if (empty($fields['prenom']) && preg_match('/(?:Pr[ée]noms?|Given\s+names?)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{2,60})/u', $text, $m)) {
            $p = preg_split('/\s{2,}/', trim($m[1]))[0] ?? $m[1];
            if (mb_strlen($p) >= 2) { $fields['prenom'] = rhNormalizeName($p); $score += 8; }
        }
        if (empty($fields['date_naissance']) && preg_match('/(?:Date\s+of\s+birth|N[ée]\(e\)\s+le)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) { $fields['date_naissance'] = $norm; $score += 8; }
        }
        if (empty($fields['lieu_naissance']) && preg_match('/(?:Place\s+of\s+birth|Lieu\s+de\s+naissance)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{3,50})/iu', $text, $m)) {
            $lieu = preg_split('/\s{2,}/', trim($m[1]))[0] ?? $m[1];
            $fields['lieu_naissance'] = rhNormalizeName($lieu);
            $score += 5;
        }
        if (empty($fields['numero_passeport']) && preg_match('/(?:Passport\s+No|N[°o]\s*(?:du\s+)?passeport)[^\n:]*[:\s]\s*([0-9A-Z]{8,12})/iu', $text, $m)) {
            $fields['numero_passeport'] = strtoupper(trim($m[1]));
            $score += 15;
        }
        if (empty($fields['date_expiration']) && preg_match('/(?:Date\s+of\s+expiry|Expire|Valable\s+jusqu\'au)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_expiration'] = $norm;
                $valid['date_expiration']  = rhDateNotExpired($norm) ? 'ok' : 'expired';
                $score += 10;
            }
        }
        if (empty($fields['nationalite']) && preg_match('/\bFRAN[ÇC]AISE?\b/i', $text)) {
            $fields['nationalite'] = 'Française';
            $score += 5;
        }
    }

    /* ─── 3. Fallback IA Vision ──────────────────────────────── */
    if ($score < 70 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $ia = rhPasseportCallIa($text, $visionImages);
            if (!empty($ia)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';
                foreach (['nom','prenom','date_naissance','lieu_naissance','nationalite','numero_passeport','date_delivrance','date_expiration','autorite_delivrance','civilite','sexe'] as $k) {
                    if (!empty($ia[$k]) && empty($fields[$k])) {
                        $val = $ia[$k];
                        if (in_array($k, ['date_naissance','date_delivrance','date_expiration'], true)) {
                            $val = rhNormalizeDate($val) ?? $val;
                        } elseif (in_array($k, ['nom','prenom','lieu_naissance','nationalite','autorite_delivrance'], true)) {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 7;
                    }
                }
                if (!empty($fields['date_expiration'])) {
                    $valid['date_expiration'] = rhDateNotExpired($fields['date_expiration']) ? 'ok' : 'expired';
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/passeport] IA: ' . $e->getMessage());
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
 * Parse une MRZ TD3 (passeport) : 2 lignes × 44 caractères.
 */
function rhPasseportParseMRZ(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    for ($i = 0; $i < count($lines) - 1; $i++) {
        $l1 = preg_replace('/\s+/', '', $lines[$i]     ?? '');
        $l2 = preg_replace('/\s+/', '', $lines[$i + 1] ?? '');

        if (strlen($l1) === 44 && strlen($l2) === 44
            && preg_match('/^[A-Z0-9<]+$/', $l1 . $l2)
            && str_starts_with($l1, 'P<FRA')) {

            $out = [];
            // Ligne 1 : P<FRA<NOM<<PRENOM1<PRENOM2<<<<
            $rest = substr($l1, 5);
            if (preg_match('/^([A-Z<]+)<<([A-Z<]+)/', $rest, $m)) {
                $nom    = trim(str_replace('<', ' ', $m[1]));
                $prenom = trim(str_replace('<', ' ', $m[2]));
                if ($nom)    $out['nom']    = rhNormalizeName($nom);
                if ($prenom) $out['prenom'] = rhNormalizeName($prenom);
            }
            // Ligne 2 : n°(9)+clé(1)+nat(3)+YYMMDD(6)+clé+sexe+YYMMDD_exp+...
            $num   = substr($l2, 0, 9);
            $nat   = substr($l2, 10, 3);
            $birth = substr($l2, 13, 6);
            $sex   = substr($l2, 20, 1);
            $exp   = substr($l2, 21, 6);

            $out['numero_passeport'] = str_replace('<', '', $num);
            if ($nat === 'FRA') $out['nationalite'] = 'Française';

            $byy = (int)substr($birth, 0, 2);
            $byear = $byy < 30 ? 2000 + $byy : 1900 + $byy;
            $out['date_naissance'] = sprintf('%04d-%s-%s', $byear, substr($birth, 2, 2), substr($birth, 4, 2));

            $eyy = (int)substr($exp, 0, 2);
            $eyear = $eyy < 50 ? 2000 + $eyy : 1900 + $eyy;
            $out['date_expiration'] = sprintf('%04d-%s-%s', $eyear, substr($exp, 2, 2), substr($exp, 4, 2));

            if ($sex === 'M')      $out['civilite'] = 'M.';
            elseif ($sex === 'F')  $out['civilite'] = 'Mme';

            return $out;
        }
    }
    return [];
}

function rhPasseportCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en passeport français. Tu extrais et retournes UNIQUEMENT
un JSON plat avec ces clés (omets celles absentes) :

  - "nom", "prenom"
  - "date_naissance"      : YYYY-MM-DD
  - "lieu_naissance"
  - "nationalite"         : ex "Française"
  - "numero_passeport"    : 8-12 caractères alphanumériques
  - "date_delivrance"     : YYYY-MM-DD
  - "date_expiration"     : YYYY-MM-DD
  - "autorite_delivrance" : préfecture / consulat
  - "civilite"            : "M." | "Mme"
  - "sexe"                : "M" | "F"

RÈGLES :
- N'INVENTE JAMAIS. Omets les clés incertaines.
- Dates au format YYYY-MM-DD.
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'un passeport français :\n\n{$text}\n\nJSON."
        : "Image d'un passeport français. JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
