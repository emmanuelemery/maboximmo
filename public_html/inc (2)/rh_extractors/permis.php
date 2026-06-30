<?php
/**
 * inc/rh_extractors/permis.php — Extraction Permis de conduire (recto/verso).
 *
 * Stratégie hybride :
 *   1. Regex sur le texte visible (n° permis, nom, prénom, dates, préfecture)
 *   2. Détection des catégories obtenues (A, A1, A2, B, BE, C, CE, D, DE, AM…)
 *   3. Détection de validité : date d'expiration ou mention "sans limite"
 *   4. Fallback IA Vision si score < 70
 *
 * Champs extraits :
 *   - numero_permis           : 9 à 13 caractères alphanumériques
 *   - nom, prenom
 *   - date_naissance
 *   - lieu_naissance
 *   - date_delivrance          : 1ère délivrance (format YYYY-MM-DD)
 *   - date_expiration          : ou "sans_limite" si permis à vie
 *   - autorite_delivrance      : préfecture ou autorité émettrice
 *   - categories               : array ex ['A','A1','B','BE']
 *   - has_category_b           : bool — utile pour IK voiture (prérequis)
 *
 * Validations :
 *   - Date expiration > aujourd'hui (sinon 'expired' — BLOQUANT pour IK)
 *   - Alerte 'expires_soon' si expiration < 60 jours
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

function rhExtract_permis(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    if (trim($text) !== '') {
        /* ─── Numéro de permis ────────────────────────────────────── */
        // Format européen : ex "12AB34567" ou "12 AB 34567" ou "120123456789"
        if (preg_match('/(?:N[°o]\s*(?:du\s+)?permis|Licence\s+No|N[°o]\s*du\s+document)[^\n:]*[:\s]\s*([A-Z0-9][\sA-Z0-9\-]{7,14})/iu', $text, $m)) {
            $num = strtoupper(preg_replace('/[\s\-]/', '', $m[1]));
            if (preg_match('/^[A-Z0-9]{9,13}$/', $num)) {
                $fields['numero_permis'] = $num;
                $score += 30;
            }
        }
        // Fallback : capture isolée d'un pattern type "12AB34567..."
        if (empty($fields['numero_permis']) && preg_match('/\b(\d{2}[A-Z]{2}\d{5,9})\b/', $text, $m)) {
            $fields['numero_permis'] = strtoupper($m[1]);
            $score += 20;
        }

        /* ─── Nom (format "Nom : DUPONT" ou "Surname : DUPONT") ──── */
        if (preg_match('/(?:1\.\s*Nom|Nom\s*:|Surname)\s*[:\s]\s*([A-ZÉÈÊ][A-ZÉÈÊ\-\'\s]{2,40})/u', $text, $m)) {
            $nom = trim($m[1]);
            $nom = preg_split('/\s{2,}|\n/', $nom)[0] ?? $nom;
            if (mb_strlen($nom) >= 2 && mb_strlen($nom) <= 50) {
                $fields['nom'] = rhNormalizeName($nom);
                $score += 10;
            }
        }

        /* ─── Prénom ──────────────────────────────────────────────── */
        if (preg_match('/(?:2\.\s*Pr[ée]nom|Pr[ée]nom\s*:|Given\s+names?)\s*[:\s]\s*([A-ZÉÈÊa-zéèê][A-ZÉÈÊa-zéèê\-\'\s]{1,40})/u', $text, $m)) {
            $prenom = trim($m[1]);
            $prenom = preg_split('/\s{2,}|\n/', $prenom)[0] ?? $prenom;
            if (mb_strlen($prenom) >= 2 && mb_strlen($prenom) <= 50) {
                $fields['prenom'] = rhNormalizeName($prenom);
                $score += 10;
            }
        }

        /* ─── Date de naissance ──────────────────────────────────── */
        if (preg_match('/(?:3\.|N[ée]\(e\)\s+le|Date\s+of\s+birth)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_naissance'] = $norm;
                $score += 10;
            }
        }

        /* ─── Lieu de naissance ──────────────────────────────────── */
        if (preg_match('/(?:[ÀA]\s+|Place\s+of\s+birth)\s*([A-ZÉÈÊa-zéèê\-\'\s]{3,40})(?:\n|$)/u', $text, $m)) {
            $lieu = trim($m[1]);
            if (mb_strlen($lieu) >= 3 && mb_strlen($lieu) <= 50) {
                $fields['lieu_naissance'] = rhNormalizeName($lieu);
                $score += 5;
            }
        }

        /* ─── Date de délivrance initiale ────────────────────────── */
        // Pattern "4a. DD/MM/AAAA" ou "Délivré le DD/MM/AAAA"
        if (preg_match('/(?:4a\.|D[ée]livr[ée]\s+le|Date\s+of\s+issue)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_delivrance'] = $norm;
                $score += 10;
            }
        }

        /* ─── Date d'expiration (ou sans limite) ─────────────────── */
        // Pattern "4b. DD/MM/AAAA" ou "Valable jusqu'au DD/MM/AAAA"
        if (preg_match('/(?:4b\.|Valable\s+jusqu\'au|Valid\s+until|Date\s+of\s+expiry)[^\n:]*[:\s]\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $text, $m)) {
            $norm = rhNormalizeDate($m[1]);
            if ($norm) {
                $fields['date_expiration'] = $norm;
                $valid['date_expiration'] = rhDateNotExpired($norm) ? 'ok' : 'expired';
                // Alerte si expire dans moins de 60 jours
                if (rhDateNotExpired($norm)) {
                    $exp = DateTime::createFromFormat('Y-m-d', $norm);
                    $today = new DateTime('today');
                    $days = (int)$today->diff($exp)->days;
                    if ($days <= 60) $valid['date_expiration'] = 'expires_soon';
                }
                $score += 15;
            }
        } elseif (preg_match('/(?:sans\s+limite|ill?imit[ée]|no\s+expir)/i', $text)) {
            $fields['date_expiration'] = 'sans_limite';
            $valid['date_expiration']  = 'lifetime';
            $score += 15;
        }

        /* ─── Autorité de délivrance / préfecture ────────────────── */
        if (preg_match('/(?:4c\.|Pr[ée]fecture|Autorit[ée]\s+de\s+d[ée]livrance|Issuing\s+authority)[^\n:]*[:\s]\s*([A-ZÉÈÊa-zéèê\-\'\s]{3,60})/iu', $text, $m)) {
            $auth = trim($m[1]);
            $auth = preg_split('/\s{2,}|\n/', $auth)[0] ?? $auth;
            if (mb_strlen($auth) >= 3 && mb_strlen($auth) <= 80) {
                $fields['autorite_delivrance'] = rhNormalizeName($auth);
                $score += 5;
            }
        }

        /* ─── Catégories obtenues ────────────────────────────────── */
        $categories = [];
        // Liste officielle des catégories européennes
        $allCats = ['A','A1','A2','B','B1','BE','C','C1','CE','C1E','D','D1','DE','D1E','AM','L','T'];

        // Recherche en contexte : ligne "9. A, B, BE" ou "Categorie: B"
        if (preg_match_all('/\b(A[12]?|B[1E]?|C[1E]?|C1E|D[1E]?|D1E|AM|L|T)\b(?:\s*[:.]|\s*[-–]|\s+\d{2}[\/.\-])/iu', $text, $matches)) {
            foreach ($matches[1] as $cat) {
                $catUpper = strtoupper($cat);
                if (in_array($catUpper, $allCats, true) && !in_array($catUpper, $categories, true)) {
                    $categories[] = $catUpper;
                }
            }
        }
        // Détection large "B" isolé (typiquement sur permis français récent)
        if (empty($categories) && preg_match('/\b(CAT[ÉE]GORIES?|CATEGORIES?)\b[^\n]{0,80}\bB\b/i', $text)) {
            $categories[] = 'B';
        }

        if (!empty($categories)) {
            $fields['categories'] = $categories;
            $fields['has_category_b'] = in_array('B', $categories, true);
            $score += 10;
        }
    }

    /* ─── Fallback IA si score insuffisant ──────────────────────── */
    if ($score < 70 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $iaResult = rhPermisCallIa($text, $visionImages);
            if (!empty($iaResult)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';

                $stringKeys = [
                    'numero_permis','nom','prenom','lieu_naissance','autorite_delivrance',
                ];
                $dateKeys = ['date_naissance','date_delivrance','date_expiration'];

                foreach ($stringKeys as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        $val = $iaResult[$k];
                        if (in_array($k, ['nom','prenom','lieu_naissance','autorite_delivrance'], true)) {
                            $val = rhNormalizeName($val);
                        } elseif ($k === 'numero_permis') {
                            $val = strtoupper(preg_replace('/\s+/', '', $val));
                        }
                        $fields[$k] = $val;
                        $score += 8;
                    }
                }

                foreach ($dateKeys as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        if ($k === 'date_expiration' && is_string($iaResult[$k])
                            && preg_match('/(sans\s+limite|ill?imit|lifetime)/i', $iaResult[$k])) {
                            $fields[$k] = 'sans_limite';
                            $valid['date_expiration'] = 'lifetime';
                            $score += 8;
                            continue;
                        }
                        $norm = rhNormalizeDate($iaResult[$k]);
                        if ($norm) {
                            $fields[$k] = $norm;
                            if ($k === 'date_expiration') {
                                $valid['date_expiration'] = rhDateNotExpired($norm) ? 'ok' : 'expired';
                            }
                            $score += 8;
                        }
                    }
                }

                // Catégories (array)
                if (!empty($iaResult['categories']) && is_array($iaResult['categories']) && empty($fields['categories'])) {
                    $cats = array_values(array_unique(array_map('strtoupper', array_filter($iaResult['categories'], 'is_string'))));
                    if (!empty($cats)) {
                        $fields['categories'] = $cats;
                        $fields['has_category_b'] = in_array('B', $cats, true);
                        $score += 10;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[rh_extractors/permis] IA fallback: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

function rhPermisCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en permis de conduire français et européens (format
carte CE rose/carte-crédit). Tu extrais et retournes UNIQUEMENT un JSON
plat avec ces clés (omets celles absentes) :

  - "numero_permis"       : 9 à 13 caractères alphanumériques (champ 5 du permis)
  - "nom"                 : nom de famille (champ 1)
  - "prenom"              : prénom(s) (champ 2)
  - "date_naissance"      : YYYY-MM-DD (champ 3)
  - "lieu_naissance"      : ville (champ 3, après la date)
  - "date_delivrance"     : YYYY-MM-DD (champ 4a, 1ère délivrance)
  - "date_expiration"     : YYYY-MM-DD ou "sans_limite" si permis à vie (champ 4b)
  - "autorite_delivrance" : préfecture émettrice (champ 4c)
  - "categories"          : array des catégories obtenues, ex ["A1","B","BE"]
                            (champ 9, valeurs possibles : A, A1, A2, B, B1, BE,
                             C, C1, CE, C1E, D, D1, DE, D1E, AM, L, T)

RÈGLES STRICTES :
- N'INVENTE JAMAIS. Si tu n'es pas sûr, omets la clé.
- Dates au format YYYY-MM-DD. Pour un permis à vie, mets "sans_limite".
- "categories" doit être un tableau JSON, même avec une seule catégorie.
- Retourne du JSON pur, pas de markdown.
SYS;

    $userText = trim($text) !== ''
        ? "Texte extrait d'un permis de conduire :\n\n{$text}\n\nRetourne le JSON."
        : "Image d'un permis de conduire français/européen. Lis attentivement et retourne le JSON.";

    // gpt-4o (Vision) plus rapide et moins cher que gpt-5 pour cette tâche
    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
