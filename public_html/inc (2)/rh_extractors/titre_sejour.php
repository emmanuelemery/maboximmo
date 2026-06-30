<?php
/**
 * inc/rh_extractors/titre_sejour.php — Extraction Titre de séjour.
 *
 * Document OBLIGATOIRE pour les ressortissants non-UE. Validation CRITIQUE :
 *   - Date expiration > aujourd'hui (sinon BLOQUANT, alerte RH)
 *   - Mention "autorisé à travailler" détectée (sinon BLOQUANT)
 *   - Alerte si expiration < 3 mois
 *
 * Champs extraits :
 *   - numero_titre        : identifiant alphanumérique
 *   - nom, prenom
 *   - date_naissance
 *   - nationalite
 *   - type_titre          : salarié / étudiant / passeport talent / etc.
 *   - date_delivrance
 *   - date_expiration
 *   - prefecture_emettrice
 *   - autorise_travailler : bool
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_titre_sejour(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    if (trim($text) !== '') {
        /* ─── Numéro du titre ────────────────────────────────── */
        if (preg_match('/(?:N[°o]\s*(?:du\s+)?titre|N[°o]\s*dossier|Num[ée]ro)[^\n:]*[:\s]\s*([A-Z0-9\-]{8,15})/iu', $text, $m)) {
            $fields['numero_titre'] = strtoupper(trim($m[1]));
            $score += 25;
        }

        /* ─── Type de titre ─────────────────────────────────── */
        $types = [
            '/salari[ée]/i'           => 'salarié',
            '/[ée]tudiant/i'          => 'étudiant',
            '/passeport\s+talent/i'   => 'passeport talent',
            '/vie\s+priv[ée]e/i'      => 'vie privée et familiale',
            '/travailleur\s+temporaire/i' => 'travailleur temporaire',
            '/carte\s+de\s+r[ée]sident/i' => 'carte de résident',
        ];
        foreach ($types as $pat => $label) {
            if (preg_match($pat, $text)) {
                $fields['type_titre'] = $label;
                $score += 10;
                break;
            }
        }

        /* ─── Préfecture émettrice ───────────────────────────── */
        if (preg_match('/(?:Pr[ée]fecture|PREFECTURE)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{3,60})/iu', $text, $m)) {
            $pref = trim($m[1]);
            $pref = preg_split('/\s{2,}|\n/', $pref)[0] ?? $pref;
            $fields['prefecture_emettrice'] = rhNormalizeName($pref);
            $score += 10;
        }

        /* ─── Autorisation de travailler (CRITIQUE) ──────────── */
        if (preg_match('/(?:autoris[ée]?\s+[àa]\s+travailler|droit\s+au\s+travail|permission\s+de\s+travail)/i', $text)) {
            $fields['autorise_travailler'] = true;
            $score += 20;
        } elseif (preg_match('/(?:ne\s+permet\s+pas.*travail|interdit\s+de\s+travailler)/i', $text)) {
            $fields['autorise_travailler'] = false;
            $valid['autorise_travailler'] = 'blocking';
            $score += 10;
        }

        /* ─── Nom / prénom ──────────────────────────────────── */
        if (preg_match('/(?:Nom|Surname)[^\n:]*[:\s]\s*([A-ZÉÈÊ\-\'\s]{2,40})/u', $text, $m)) {
            $nom = preg_split('/\s{2,}/', trim($m[1]))[0] ?? $m[1];
            if (mb_strlen($nom) >= 2) { $fields['nom'] = rhNormalizeName($nom); $score += 5; }
        }
        if (preg_match('/(?:Pr[ée]nom)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{2,40})/u', $text, $m)) {
            $p = preg_split('/\s{2,}/', trim($m[1]))[0] ?? $m[1];
            if (mb_strlen($p) >= 2) { $fields['prenom'] = rhNormalizeName($p); $score += 5; }
        }

        /* ─── Nationalité ───────────────────────────────────── */
        if (preg_match('/(?:Nationalit[ée]|Nationality)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{3,30})/iu', $text, $m)) {
            $fields['nationalite'] = rhNormalizeName(trim($m[1]));
            $score += 5;
        }

        /* ─── Date de naissance ──────────────────────────────── */
        if (preg_match('/(?:N[ée]\(e\)\s+le|Date\s+de\s+naissance)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) { $fields['date_naissance'] = $norm; $score += 5; }
        }

        /* ─── Date délivrance / expiration ───────────────────── */
        if (preg_match('/(?:D[ée]livr[ée]\s+le|Date\s+de\s+d[ée]livrance)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) { $fields['date_delivrance'] = $norm; $score += 5; }
        }
        if (preg_match('/(?:Valable\s+jusqu\'au|Date\s+d.expiration|Expire)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_expiration'] = $norm;
                // Validation CRITIQUE : blocant si expiré, alerte si < 3 mois
                if (!rhDateNotExpired($norm)) {
                    $valid['date_expiration'] = 'expired_blocking';
                } else {
                    $exp = DateTime::createFromFormat('Y-m-d', $norm);
                    $days = (int)(new DateTime('today'))->diff($exp)->days;
                    $valid['date_expiration'] = ($days <= 90) ? 'expires_soon_alert' : 'ok';
                }
                $score += 15;
            }
        }
    }

    /* ─── Fallback IA ────────────────────────────────────────── */
    if ($score < 70 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $ia = rhTitreSejourCallIa($text, $visionImages);
            if (!empty($ia)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';
                foreach (['numero_titre','nom','prenom','date_naissance','nationalite','type_titre','date_delivrance','date_expiration','prefecture_emettrice','autorise_travailler'] as $k) {
                    if (isset($ia[$k]) && (empty($fields[$k]) || $fields[$k] === null)) {
                        $val = $ia[$k];
                        if (in_array($k, ['date_naissance','date_delivrance','date_expiration'], true)) {
                            $val = rhNormalizeDate($val) ?? $val;
                        } elseif (in_array($k, ['nom','prenom','nationalite','prefecture_emettrice'], true)) {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 6;
                    }
                }
                if (!empty($fields['date_expiration'])) {
                    if (!rhDateNotExpired($fields['date_expiration'])) {
                        $valid['date_expiration'] = 'expired_blocking';
                    } else {
                        $exp = DateTime::createFromFormat('Y-m-d', $fields['date_expiration']);
                        $days = (int)(new DateTime('today'))->diff($exp)->days;
                        $valid['date_expiration'] = ($days <= 90) ? 'expires_soon_alert' : 'ok';
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/titre_sejour] IA: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhTitreSejourCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en titres de séjour français. Tu extrais et retournes
UNIQUEMENT un JSON plat avec ces clés (omets celles absentes) :

  - "numero_titre"          : alphanumérique
  - "nom", "prenom"
  - "date_naissance"        : YYYY-MM-DD
  - "nationalite"
  - "type_titre"            : "salarié" | "étudiant" | "passeport talent" |
                              "vie privée et familiale" | "carte de résident" | ...
  - "date_delivrance"       : YYYY-MM-DD
  - "date_expiration"       : YYYY-MM-DD
  - "prefecture_emettrice"
  - "autorise_travailler"   : true | false

RÈGLES :
- N'INVENTE JAMAIS.
- Dates au format YYYY-MM-DD.
- autorise_travailler : true si mention "autorise à travailler" / "droit au travail".
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'un titre de séjour :\n\n{$text}\n\nJSON."
        : "Image d'un titre de séjour français. JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
