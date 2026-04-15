<?php
/**
 * inc/rh_extractors/carte_vitale.php — Extraction Carte Vitale / attestation Ameli.
 *
 * Champs extraits :
 *   - num_secu  : numéro de sécurité sociale (15 chiffres, clé mod97)
 *   - nom, prenom
 *   - date_naissance
 *   - cpam      : caisse de rattachement
 *   - medecin_traitant
 *   - date_attestation (pour attestations Ameli)
 *
 * Validation : clé mod97 (rhValidateNSS).
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_carte_vitale(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    if (trim($text) !== '') {
        /* ─── Numéro de sécurité sociale ──────────────────────────── */
        // Format : [12] YY MM DD PPPPPP CCC KK où P=département/commune, C=ordre, K=clé
        // Tolère espaces, points, séparateurs
        if (preg_match('/\b([12][\s\.]?\d{2}[\s\.]?\d{2}[\s\.]?(?:\d{2}|[AB]\d|2[AB])[\s\.]?\d{3}[\s\.]?\d{3}[\s\.]?\d{2})\b/i', $text, $m)) {
            $nss = $m[1];
            $fields['num_secu'] = rhNormalizeNSS($nss);
            $valid['num_secu']  = rhValidateNSS($nss) ? 'ok' : 'key_mismatch';
            $score += $valid['num_secu'] === 'ok' ? 45 : 20;
        }

        /* ─── Nom / prénom ────────────────────────────────────────── */
        if (preg_match('/(?:Madame|Monsieur|M\.|Mme)\s+([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s]{2,60})/u', $text, $m)) {
            $full = trim($m[1]);
            $full = preg_split('/\s{2,}/', $full)[0] ?? $full;
            // Split nom/prénom heuristique : prénom en premier si minuscules après
            $parts = preg_split('/\s+/', $full);
            if (count($parts) >= 2) {
                $fields['prenom'] = rhNormalizeName($parts[0]);
                $fields['nom']    = rhNormalizeName(implode(' ', array_slice($parts, 1)));
                $score += 10;
            }
        }

        /* ─── Date de naissance ───────────────────────────────────── */
        if (preg_match('/(?:N[ée]\(e\)\s+le|Date\s+de\s+naissance)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_naissance'] = $norm;
                $score += 10;
            }
        }

        /* ─── CPAM (caisse) ───────────────────────────────────────── */
        if (preg_match('/(?:CPAM|Caisse\s+Primaire(?:\s+d.Assurance\s+Maladie)?)[^\n:]*[:\s\-]+([A-ZÉÈÊa-zéèê\-\'\s]{3,60})/u', $text, $m)) {
            $cpam = trim($m[1]);
            $cpam = preg_split('/\s{2,}|\n/', $cpam)[0] ?? $cpam;
            if (mb_strlen($cpam) >= 3 && mb_strlen($cpam) <= 80) {
                $fields['cpam'] = rhNormalizeName($cpam);
                $score += 15;
            }
        }

        /* ─── Médecin traitant ────────────────────────────────────── */
        if (preg_match('/(?:m[ée]decin\s+traitant|MT)[^\n:]*[:\s]\s*(?:Dr\.?\s+)?([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\-\'\s]{3,60})/iu', $text, $m)) {
            $mt = trim($m[1]);
            $fields['medecin_traitant'] = rhNormalizeName(preg_split('/\s{2,}/', $mt)[0] ?? $mt);
            $score += 5;
        }

        /* ─── Date de l'attestation ───────────────────────────────── */
        if (preg_match('/(?:Date\s+(?:d.[ée]dition|de\s+mise\s+[àa]\s+jour))[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_attestation'] = $norm;
                $score += 5;
            }
        }
    }

    /* ─── Fallback IA ────────────────────────────────────────────── */
    if ($score < 60 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $iaResult = rhCarteVitaleCallIa($text, $visionImages);
            if (!empty($iaResult)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';
                foreach (['num_secu','nom','prenom','date_naissance','cpam','medecin_traitant','date_attestation'] as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        $val = $iaResult[$k];
                        if ($k === 'date_naissance' || $k === 'date_attestation') {
                            $val = rhNormalizeDate($val) ?? $val;
                        } elseif ($k === 'num_secu') {
                            $val = rhNormalizeNSS($val);
                            $valid['num_secu'] = rhValidateNSS($iaResult[$k]) ? 'ok' : 'key_mismatch';
                        } elseif (in_array($k, ['nom', 'prenom', 'cpam', 'medecin_traitant'], true)) {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 10;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/carte_vitale] IA fallback: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhCarteVitaleCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en documents de la Sécurité sociale française (carte Vitale
et attestations Ameli). Tu extrais et retournes UNIQUEMENT un JSON plat :

  - "num_secu"         : numéro de sécurité sociale (15 chiffres)
  - "nom"
  - "prenom"
  - "date_naissance"   : YYYY-MM-DD
  - "cpam"             : nom de la caisse de rattachement
  - "medecin_traitant" : nom du médecin (sans "Dr.")
  - "date_attestation" : date d'édition (YYYY-MM-DD)

RÈGLES :
- N'INVENTE JAMAIS.
- Le num_secu doit commencer par 1 (homme) ou 2 (femme) et faire 15 chiffres.
- Dates au format YYYY-MM-DD.
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'une carte Vitale / attestation Ameli :\n\n{$text}\n\nJSON."
        : "Image d'une carte Vitale ou attestation Ameli. JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
