<?php
/**
 * inc/rh_extractors/carte_grise.php — Extraction carte grise.
 *
 * Wrapper léger au-dessus de `inc/ik_carte_grise.php` (607 lignes déjà
 * opérationnelles pour le module IK). On réutilise sa fonction
 * `rh_parse_carte_grise_text()` qui connaît déjà les marques, le format
 * SIV, la puissance fiscale, le VIN, etc.
 *
 * Champs extraits (mappés sur les champs `users`) :
 *   - vehicule_immat
 *   - vehicule_marque
 *   - vehicule_modele
 *   - vehicule_puissance_fiscale
 *   - vehicule_carburant
 *   - vehicule_vin
 *   - vehicule_date_1ere_immat
 *   - titulaire_nom (pour cross-check avec CNI)
 *
 * Validation : VIN (checksum position 9), immat (format SIV ou FNI).
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';
require_once __DIR__ . '/../ik_carte_grise.php';

function rhExtract_carte_grise(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    /* ─── Si texte natif : appel direct du parser existant ─────── */
    if (trim($text) !== '' && function_exists('rh_parse_carte_grise_text')) {
        $parsed = rh_parse_carte_grise_text($text);

        // Mapping vers notre schéma normalisé
        $map = [
            'vehicule_immat'              => 'plate',
            'vehicule_marque'              => 'brand',
            'vehicule_modele'              => 'model',
            'vehicule_puissance_fiscale'   => 'cv_fiscal',
            'vehicule_carburant'           => 'fuel',
            'vehicule_vin'                 => 'vin',
            'vehicule_date_1ere_immat'     => 'first_reg',
        ];
        foreach ($map as $myKey => $parserKey) {
            if (!empty($parsed[$parserKey])) {
                $fields[$myKey] = $parsed[$parserKey];
                $score += 10;
            }
        }

        // Cross-check nom (pour cohérence avec la CNI)
        if (!empty($parsed['holder_name'])) {
            $fields['titulaire_nom'] = rhNormalizeName($parsed['holder_name']);
            $score += 5;
        }

        // Validations
        if (!empty($fields['vehicule_immat'])) {
            $fields['vehicule_immat'] = rhNormalizePlate($fields['vehicule_immat']);
            $valid['vehicule_immat'] = rhValidatePlate($fields['vehicule_immat']) ? 'ok' : 'format_invalid';
        }
        if (!empty($fields['vehicule_vin'])) {
            $valid['vehicule_vin'] = rhValidateVIN($fields['vehicule_vin']) ? 'ok' : 'checksum_failed';
        }
        if (!empty($fields['vehicule_date_1ere_immat'])) {
            $norm = rhNormalizeDate($fields['vehicule_date_1ere_immat']);
            if ($norm) {
                $fields['vehicule_date_1ere_immat'] = $norm;
            }
        }
    }

    /* ─── Fallback IA si score faible ou pas de texte ───────────── */
    if ($score < 60 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $iaResult = rhCarteGriseCallIa($text, $visionImages);
            if (!empty($iaResult)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';

                $mapping = [
                    'vehicule_immat', 'vehicule_marque', 'vehicule_modele',
                    'vehicule_puissance_fiscale', 'vehicule_carburant',
                    'vehicule_vin', 'vehicule_date_1ere_immat', 'titulaire_nom',
                ];
                foreach ($mapping as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        $val = $iaResult[$k];
                        if ($k === 'vehicule_date_1ere_immat') {
                            $val = rhNormalizeDate($val) ?? $val;
                        } elseif ($k === 'vehicule_immat') {
                            $val = rhNormalizePlate($val);
                        } elseif ($k === 'titulaire_nom') {
                            $val = rhNormalizeName($val);
                        }
                        $fields[$k] = $val;
                        $score += 10;
                    }
                }

                // Re-validation
                if (!empty($fields['vehicule_immat'])) {
                    $valid['vehicule_immat'] = rhValidatePlate($fields['vehicule_immat']) ? 'ok' : 'format_invalid';
                }
                if (!empty($fields['vehicule_vin'])) {
                    $valid['vehicule_vin'] = rhValidateVIN($fields['vehicule_vin']) ? 'ok' : 'checksum_failed';
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/carte_grise] IA fallback: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhCarteGriseCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en certificat d'immatriculation français (carte grise).
Tu extrais et retournes UNIQUEMENT un JSON plat avec ces clés
(omets celles absentes) :

  - "vehicule_immat"             : immat. format SIV AA-000-AA
  - "vehicule_marque"            : marque (D.1), ex: "RENAULT", "PEUGEOT"
  - "vehicule_modele"            : modèle / dénomination commerciale (D.3)
  - "vehicule_puissance_fiscale" : puissance fiscale en CV (P.6)
  - "vehicule_carburant"         : "essence" | "diesel" | "hybride" | "electrique"
  - "vehicule_vin"               : numéro VIN 17 caractères (E)
  - "vehicule_date_1ere_immat"   : 1ère mise en circulation (B), YYYY-MM-DD
  - "titulaire_nom"              : nom du titulaire (C.1.1)

RÈGLES :
- N'INVENTE JAMAIS.
- Immat. au format SIV (AA-000-AA).
- VIN : 17 caractères alphanumériques sans I, O, Q.
- Retourne du JSON pur.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'une carte grise française :\n\n{$text}\n\nJSON."
        : "Image d'une carte grise française. JSON.";

    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
