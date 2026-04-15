<?php
declare(strict_types=1);

/**
 * DpeImportParser — Extraction des données depuis un DPE PDF
 *
 * Reconnaît les DPE format 2011 (ancien) et 2021 (nouveau) délivrés
 * par l'ADEME / l'observatoire DPE. Robuste aux variations de mise en
 * page (rapports d'experts, certificats officiels, exports portails…).
 *
 * Retourne un tableau associatif compatible avec les noms de champs
 * du formulaire bien_ajouter.php :
 *   - dpe_classe, ges_classe, dpe_valeur, ges_valeur
 *   - dpe_date_realisation, dpe_version
 *   - montant_estime_depenses_min, montant_estime_depenses_max
 *   - dpe_valeur_conso_primaire, dpe_valeur_conso_finale
 *   - date_indice_prix_energies
 *   - dpe_reference_certificat (ADEME)
 *   - altitude (rare)
 */
class DpeImportParser
{
    /**
     * Parse le texte extrait d'un PDF DPE et retourne les champs détectés.
     *
     * @param string $text Texte brut du PDF (déjà extrait par BienImportParser)
     * @return array {
     *     fields  : ['key' => value, …],     // valeurs prêtes pour le form
     *     score   : int (0-100),              // qualité de l'extraction
     *     debug   : ['matched' => […]]        // info debug (regex matchées)
     * }
     */
    public static function parse(string $text): array
    {
        if (trim($text) === '') {
            return ['fields' => [], 'score' => 0, 'debug' => ['error' => 'texte vide']];
        }

        // Normalisation : minuscules accentuées → ASCII pour regex insensibles
        $norm = self::normalize($text);

        $fields = [];
        $matched = [];

        // ─── 1. Classes DPE & GES (lettres A-G) ───────────────────
        // Patterns multiples pour maximiser les détections
        $cls = self::extractClasses($norm, $matched);
        if ($cls['dpe']) $fields['dpe_classe'] = $cls['dpe'];
        if ($cls['ges']) $fields['ges_classe'] = $cls['ges'];

        // ─── 2. Valeurs DPE (kWh/m²/an) et GES (kg CO2/m²/an) ────
        $vals = self::extractValeurs($norm, $matched);
        if ($vals['dpe'] !== null) $fields['dpe_valeur']      = $vals['dpe'];
        if ($vals['ges'] !== null) $fields['ges_valeur']      = $vals['ges'];
        if ($vals['conso_finale'] !== null) $fields['dpe_valeur_conso_finale'] = $vals['conso_finale'];
        if ($vals['conso_primaire'] !== null) $fields['dpe_valeur_conso_primaire'] = $vals['conso_primaire'];

        // ─── 3. Date de réalisation ──────────────────────────────
        $date = self::extractDate($norm, $matched);
        if ($date) $fields['dpe_date_realisation'] = $date;

        // ─── 4. Version DPE (2011 vs 2021) ───────────────────────
        $version = self::extractVersion($norm, $date, $matched);
        if ($version) $fields['dpe_version'] = $version;

        // ─── 5. Montants estimés annuels (€/an min - max) ────────
        $montants = self::extractMontants($norm, $matched);
        if ($montants['min'] !== null) $fields['montant_estime_depenses_min'] = $montants['min'];
        if ($montants['max'] !== null) $fields['montant_estime_depenses_max'] = $montants['max'];

        // ─── 6. Date indice prix énergies ────────────────────────
        $datePrix = self::extractDatePrix($norm, $matched);
        if ($datePrix) $fields['date_indice_prix_energies'] = $datePrix;

        // ─── 7. Numéro ADEME (référence certificat) ──────────────
        $ref = self::extractAdemeRef($text, $matched);
        if ($ref) $fields['dpe_reference_certificat'] = $ref;

        // ─── 8. Altitude (rare mais présent sur certains DPE) ────
        $alt = self::extractAltitude($norm, $matched);
        if ($alt !== null) $fields['altitude'] = $alt;

        // ─── Score de confiance ──────────────────────────────────
        $score = self::computeScore($fields);

        return [
            'fields' => $fields,
            'score'  => $score,
            'debug'  => ['matched' => $matched, 'text_length' => strlen($text)],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // EXTRACTEURS UNITAIRES
    // ─────────────────────────────────────────────────────────────

    /**
     * Extrait les classes A-G pour DPE (énergie) et GES (climat).
     * Stratégie : chercher des phrases types puis isoler la lettre.
     */
    private static function extractClasses(string $norm, array &$matched): array
    {
        $result = ['dpe' => null, 'ges' => null];

        // Patterns DPE / Classe énergie
        $patternsDpe = [
            '/classe\s*(?:energie|energetique|dpe)[\s:]*([a-g])\b/i',
            '/etiquette\s*(?:energie|energetique)[\s:]*([a-g])\b/i',
            '/consommation\s*(?:energetique|energie)[\s\S]{0,80}?classe[\s:]*([a-g])\b/i',
            '/dpe[\s:]*([a-g])(?:\s|\b)/i',
            '/energie[\s:]+([a-g])(?:\s|$)/i',
        ];
        foreach ($patternsDpe as $p) {
            if (preg_match($p, $norm, $m)) {
                $result['dpe'] = strtoupper($m[1]);
                $matched['dpe_classe_pattern'] = $p;
                break;
            }
        }

        // Patterns GES / Climat
        $patternsGes = [
            '/classe\s*(?:ges|climat|gaz)[\s:]*([a-g])\b/i',
            '/etiquette\s*(?:ges|climat)[\s:]*([a-g])\b/i',
            '/emission[\s\S]{0,80}?(?:ges|gaz)[\s\S]{0,40}?classe[\s:]*([a-g])\b/i',
            '/ges[\s:]*([a-g])(?:\s|\b)/i',
            '/climat[\s:]+([a-g])(?:\s|$)/i',
        ];
        foreach ($patternsGes as $p) {
            if (preg_match($p, $norm, $m)) {
                $result['ges'] = strtoupper($m[1]);
                $matched['ges_classe_pattern'] = $p;
                break;
            }
        }

        return $result;
    }

    /**
     * Extrait les valeurs numériques DPE (kWh/m²/an) et GES (kg CO2/m²/an).
     */
    private static function extractValeurs(string $norm, array &$matched): array
    {
        $result = ['dpe' => null, 'ges' => null, 'conso_finale' => null, 'conso_primaire' => null];

        // DPE — kWh par m² par an
        $patternsDpeVal = [
            '/(\d{1,4})\s*kwh\s*(?:ep|energie\s*primaire)?\s*\/?\s*m2?\s*[\/.]\s*an/i',
            '/(\d{1,4})\s*kwhep\s*\/?\s*m2?\s*[\/.]\s*an/i',
            '/consommation[\s\S]{0,50}?(\d{1,4})\s*kwh/i',
        ];
        foreach ($patternsDpeVal as $p) {
            if (preg_match($p, $norm, $m)) {
                $result['dpe'] = (int)$m[1];
                $matched['dpe_valeur_pattern'] = $p;
                break;
            }
        }

        // Conso primaire vs finale (DPE 2021 distingue les deux)
        if (preg_match('/(\d{1,4})\s*kwh\s*ep\s*\/?\s*m2?\s*[\/.]\s*an/i', $norm, $m)) {
            $result['conso_primaire'] = (int)$m[1];
        }
        if (preg_match('/(\d{1,4})\s*kwh\s*ef\s*\/?\s*m2?\s*[\/.]\s*an/i', $norm, $m)) {
            $result['conso_finale'] = (int)$m[1];
        }

        // GES — kg CO2 par m² par an
        $patternsGesVal = [
            '/(\d{1,3})\s*kg\s*(?:de\s*)?co2\s*(?:eq)?\s*\/?\s*m2?\s*[\/.]\s*an/i',
            '/(\d{1,3})\s*kgco2\s*\/?\s*m2?\s*[\/.]\s*an/i',
            '/emission[\s\S]{0,80}?(\d{1,3})\s*kg/i',
        ];
        foreach ($patternsGesVal as $p) {
            if (preg_match($p, $norm, $m)) {
                $result['ges'] = (int)$m[1];
                $matched['ges_valeur_pattern'] = $p;
                break;
            }
        }

        return $result;
    }

    /**
     * Extrait la date de réalisation du DPE.
     * Format retourné : YYYY-MM-DD (compatible <input type="date">).
     */
    private static function extractDate(string $norm, array &$matched): ?string
    {
        // Patterns de date DPE
        $patterns = [
            '/(?:date\s*(?:de\s*)?(?:realisation|etablissement|emission|visite|diagnostic)|realise\s*le|etabli\s*le)\s*[:.]?\s*(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/i',
            '/dpe\s*(?:du|date|realise)[\s:]*(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/i',
            '/(?:date|valable)[\s:]*(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $norm, $m)) {
                $j = (int)$m[1];
                $mois = (int)$m[2];
                $a = (int)$m[3];
                if ($a < 100) $a += 2000;
                if ($j >= 1 && $j <= 31 && $mois >= 1 && $mois <= 12 && $a >= 2010 && $a <= 2030) {
                    $matched['date_pattern'] = $p;
                    return sprintf('%04d-%02d-%02d', $a, $mois, $j);
                }
            }
        }
        return null;
    }

    /**
     * Détecte la version du DPE (2011 ou 2021).
     * Le 01/07/2021 marque le changement.
     */
    private static function extractVersion(string $norm, ?string $date, array &$matched): ?string
    {
        // Indices textuels explicites
        if (preg_match('/dpe\s*(?:version|nouvelle\s*version)?\s*2021/i', $norm)) {
            $matched['version_pattern'] = 'explicit_2021';
            return '2021';
        }
        if (preg_match('/dpe\s*(?:version|ancienne\s*version)?\s*2011/i', $norm)) {
            $matched['version_pattern'] = 'explicit_2011';
            return '2011';
        }
        // Indices structurels
        if (preg_match('/(?:opposable|nouveau\s*dpe|dpe\s*nouveau)/i', $norm)) {
            $matched['version_pattern'] = 'opposable';
            return '2021';
        }
        // Déduction depuis la date
        if ($date !== null) {
            $ts = strtotime($date);
            if ($ts !== false) {
                $matched['version_pattern'] = 'from_date';
                return $ts >= strtotime('2021-07-01') ? '2021' : '2011';
            }
        }
        return null;
    }

    /**
     * Extrait les montants estimés de dépenses énergétiques annuelles.
     * Souvent présenté comme "entre X et Y € par an" sur les DPE 2021.
     */
    private static function extractMontants(string $norm, array &$matched): array
    {
        $result = ['min' => null, 'max' => null];

        // Pattern fourchette : "entre 800 et 1100 € par an"
        $patterns = [
            '/(?:entre|de)\s*(\d{2,5})\s*(?:€|euros)?\s*(?:et|a)\s*(\d{2,5})\s*(?:€|euros)\s*(?:par|\/)\s*an/i',
            '/(\d{2,5})\s*(?:€|euros)?\s*(?:a|à|et)\s*(\d{2,5})\s*(?:€|euros)\s*\/?\s*an/i',
            '/cout[s]?\s*(?:annuel|estim[eé]s?)[\s:]*(\d{2,5})\s*(?:€|euros)?\s*(?:a|à|et)\s*(\d{2,5})\s*(?:€|euros)/i',
            '/montant[s]?\s*(?:annuel|estim[eé]s?)[\s:]*(\d{2,5})\s*(?:€|euros)?\s*(?:a|à|et)\s*(\d{2,5})\s*(?:€|euros)/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $norm, $m)) {
                $a = (int)$m[1];
                $b = (int)$m[2];
                $result['min'] = min($a, $b);
                $result['max'] = max($a, $b);
                $matched['montants_pattern'] = $p;
                return $result;
            }
        }

        // Pattern unique "estimé à 950 €/an"
        if (preg_match('/(?:cout|montant|estim[eé])[\s\S]{0,30}?(\d{2,5})\s*(?:€|euros)\s*\/?\s*an/i', $norm, $m)) {
            $val = (int)$m[1];
            $result['min'] = $val;
            $result['max'] = $val;
            $matched['montants_pattern'] = 'single';
        }

        return $result;
    }

    /**
     * Extrait la date de référence des prix de l'énergie (DPE 2021/2022).
     */
    private static function extractDatePrix(string $norm, array &$matched): ?string
    {
        // "prix au 1er janvier 2021" / "prix moyens de l'énergie au 15/08/2021"
        $patterns = [
            '#(?:prix|tarifs?)\s*(?:moyens?|de\s*l\s*\'?\s*energie|energie)?\s*(?:au|du|en|date\s*de\s*reference)[\s:]*(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#i',
            '#indice[\s\S]{0,30}?prix[\s\S]{0,30}?(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#i',
            '#(?:prix|tarifs?)[\s\S]{0,40}?(?:1er|premier)?\s*janvier\s*(\d{4})#i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $norm, $m)) {
                $matched['date_prix_pattern'] = $p;
                if (count($m) === 4) {
                    $j = (int)$m[1]; $mois = (int)$m[2]; $a = (int)$m[3];
                    if ($a < 100) $a += 2000;
                    if ($j >= 1 && $j <= 31 && $mois >= 1 && $mois <= 12) {
                        return sprintf('%04d-%02d-%02d', $a, $mois, $j);
                    }
                } elseif (count($m) === 2) {
                    return sprintf('%04d-01-01', (int)$m[1]);
                }
            }
        }
        return null;
    }

    /**
     * Numéro ADEME — format : 4 chiffres + lettre + 4 chiffres + lettre + 4 chiffres
     * Ex : 2123E1234B5678
     */
    private static function extractAdemeRef(string $text, array &$matched): ?string
    {
        if (preg_match('/(\d{4}[A-Z]\d{4}[A-Z]\d{4})/', $text, $m)) {
            $matched['ref_pattern'] = 'ademe_strict';
            return $m[1];
        }
        if (preg_match('/(?:numero|n°|ref(?:erence)?)\s*(?:ademe|dpe|certificat)[\s:]*([A-Z0-9\-]{10,20})/i', $text, $m)) {
            $matched['ref_pattern'] = 'ademe_loose';
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Altitude en mètres (utile pour les zones climatiques H1b/H1c/H2d > 800m).
     */
    private static function extractAltitude(string $norm, array &$matched): ?int
    {
        if (preg_match('/altitude[\s:]+(\d{1,4})\s*m/i', $norm, $m)) {
            $matched['altitude_pattern'] = true;
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Score de confiance basé sur le nombre de champs détectés.
     */
    private static function computeScore(array $fields): int
    {
        $criticalKeys = ['dpe_classe', 'ges_classe', 'dpe_valeur', 'ges_valeur', 'dpe_date_realisation'];
        $bonusKeys    = ['dpe_version', 'montant_estime_depenses_min', 'montant_estime_depenses_max', 'dpe_valeur_conso_primaire', 'dpe_valeur_conso_finale', 'dpe_reference_certificat'];

        $critical = 0;
        foreach ($criticalKeys as $k) if (!empty($fields[$k])) $critical++;
        $bonus = 0;
        foreach ($bonusKeys as $k) if (!empty($fields[$k])) $bonus++;

        // 60% du score = champs critiques (sur 5), 40% = bonus (sur 6)
        return min(100, (int) round(($critical / count($criticalKeys)) * 60 + ($bonus / count($bonusKeys)) * 40));
    }

    /**
     * Normalise le texte pour faciliter les regex insensibles aux accents.
     */
    private static function normalize(string $text): string
    {
        // Translittération approximative — accents + exposants + symboles spéciaux
        $from = [
            'é','è','ê','ë','É','È','Ê','Ë','à','â','ä','À','Â','Ä','î','ï','Î','Ï','ô','ö','Ô','Ö',
            'ù','û','ü','Ù','Û','Ü','ç','Ç','ÿ','Ÿ','œ','Œ','æ','Æ',
            '’','‘','"','"','«','»','–','—','…',"\u{00A0}",
            '²','³','¹','€','₂','₃','°',
        ];
        $to   = [
            'e','e','e','e','E','E','E','E','a','a','a','A','A','A','i','i','I','I','o','o','O','O',
            'u','u','u','U','U','U','c','C','y','Y','oe','OE','ae','AE',
            "'","'",'"','"','"','"','-','-','...',' ',
            '2','3','1','€','2','3','o',
        ];
        $text = str_replace($from, $to, $text);
        // Normalise les espaces
        $text = preg_replace('/\s+/u', ' ', $text);
        return $text;
    }
}
