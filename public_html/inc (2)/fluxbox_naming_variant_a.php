<?php
declare(strict_types=1);

/**
 * FluxBox — nommage Variante A (validé EMERY 2026-05-15)
 *
 * Format figé 11 segments :
 *   {societe}_{agence}_{user}_{JJMMAA}_{n1}_{n2}_{banque}_{compte4}_{MM-AAAA}_{immeuble}_{type}.ext
 *
 * Règles strictes :
 *   - JJMMAA = date d'INTÉGRATION (upload), pas la date de la pièce
 *   - MM-AAAA = période de la pièce (mois de référence du relevé/facture)
 *   - compte4 = 4 DERNIERS chiffres du compte uniquement (pas d'IBAN complet)
 *   - banque, immeuble, type, soc, age, user = codes du glossaire UNIQUEMENT
 *     (jamais dérivés à la volée — passe par admin si manquant)
 *
 * Sécurité bancaire : aucun n° de compte complet, aucun IBAN, aucun nom long de banque.
 *
 * Cohérent avec [[project_fluxbox_naming_variant_a]] + [[ged_codes_glossaire]].
 */

require_once __DIR__ . '/ged_glossary.php';

if (!function_exists('fluxbox_va_slug_segment')) {
    /**
     * Slug strict pour un segment Variante A : ASCII upper, [A-Z0-9-] uniquement.
     * Le séparateur de segments est "_", on garde "-" interne (MM-AAAA, RC-PRO).
     */
    function fluxbox_va_slug_segment(string $s): string
    {
        if ($s === '') return '';
        $s = trim($s);
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false) $s = $tr;
        }
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9\-]+/', '-', $s) ?? '';
        $s = preg_replace('/-+/', '-', $s) ?? '';
        return trim($s, '-');
    }
}

if (!function_exists('fluxbox_va_format_date_integration')) {
    /**
     * Date d'intégration (upload) en format JJMMAA (6 chiffres).
     * @param string|DateTimeInterface|null $date  null = maintenant
     */
    function fluxbox_va_format_date_integration(string|DateTimeInterface|null $date = null): string
    {
        if ($date === null || $date === '') {
            return date('dmy');
        }
        if ($date instanceof DateTimeInterface) {
            return $date->format('dmy');
        }
        $ts = strtotime((string)$date);
        if ($ts === false) return date('dmy');
        return date('dmy', $ts);
    }
}

if (!function_exists('fluxbox_va_format_periode_mm_yyyy')) {
    /**
     * Période de la pièce en MM-AAAA. Accepte :
     *  - "2026-03" / "2026-03-15" / "2026/03"
     *  - "03-2026" / "03/2026"
     *  - "mars 2026" / "Mars 2026"
     *  - DateTime
     *  - null/vide → null
     *
     * @return string|null "MM-AAAA" ou null
     */
    function fluxbox_va_format_periode_mm_yyyy(string|DateTimeInterface|null $input): ?string
    {
        if ($input === null || $input === '') return null;

        if ($input instanceof DateTimeInterface) {
            return $input->format('m-Y');
        }

        $t = strtolower((string)$input);
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            if ($tr !== false) $t = strtolower($tr);
        }

        // YYYY-MM ou YYYY-MM-DD
        if (preg_match('/(20\d{2})[-_\/.](\d{1,2})/', $t, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2];
            if ($mo >= 1 && $mo <= 12) return sprintf('%02d-%04d', $mo, $y);
        }

        // MM-YYYY (Europe)
        if (preg_match('/(?<!\d)(\d{1,2})[-_\/.](20\d{2})(?!\d)/', $t, $m)) {
            $mo = (int)$m[1]; $y = (int)$m[2];
            if ($mo >= 1 && $mo <= 12) return sprintf('%02d-%04d', $mo, $y);
        }

        // Mois texte FR + année
        $monthsFr = [
            'janvier'=>1, 'janv'=>1, 'jan'=>1,
            'fevrier'=>2, 'fev'=>2,
            'mars'=>3, 'mar'=>3,
            'avril'=>4, 'avr'=>4,
            'mai'=>5,
            'juin'=>6, 'jun'=>6,
            'juillet'=>7, 'juil'=>7, 'jul'=>7,
            'aout'=>8,
            'septembre'=>9, 'sept'=>9, 'sep'=>9,
            'octobre'=>10, 'oct'=>10,
            'novembre'=>11, 'nov'=>11,
            'decembre'=>12, 'dec'=>12,
        ];
        $alt = implode('|', array_keys($monthsFr));
        if (preg_match('/(' . $alt . ')[^\d]{0,4}(20\d{2})/', $t, $m)) {
            $mo = $monthsFr[$m[1]] ?? 0;
            $y  = (int)$m[2];
            if ($mo > 0) return sprintf('%02d-%04d', $mo, $y);
        }
        if (preg_match('/(20\d{2})[^a-z]{0,4}(' . $alt . ')/', $t, $m)) {
            $y  = (int)$m[1];
            $mo = $monthsFr[$m[2]] ?? 0;
            if ($mo > 0) return sprintf('%02d-%04d', $mo, $y);
        }

        // MMAAAA compact (6 chiffres, ex "032026")
        if (preg_match('/(?<!\d)(\d{2})(20\d{2})(?!\d)/', $t, $m)) {
            $mo = (int)$m[1]; $y = (int)$m[2];
            if ($mo >= 1 && $mo <= 12) return sprintf('%02d-%04d', $mo, $y);
        }

        return null;
    }
}

if (!function_exists('fluxbox_va_extract_compte4')) {
    /**
     * Extrait les 4 DERNIERS chiffres d'un n° de compte / IBAN / RIB.
     * Refuse explicitement tout retour > 4 chiffres : sécurité bancaire.
     *
     * @return string|null  4 chiffres ou null si introuvable
     */
    function fluxbox_va_extract_compte4(?string $input): ?string
    {
        if ($input === null || $input === '') return null;
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if (strlen($digits) < 4) return null;
        return substr($digits, -4);
    }
}

if (!function_exists('fluxbox_va_resolve_glossary_label')) {
    /**
     * Cherche un code dans le glossaire à partir d'un libellé texte (extraction Vision).
     * Stratégie :
     *   1. Match exact sur label (case-insensitive)
     *   2. Match exact sur code (l'OCR a parfois lu le code directement)
     *   3. Fuzzy : levenshtein normalisé sur labels actifs de la catégorie
     *
     * @return array{code:string|null, match_type:string, confidence:int}
     *   match_type ∈ exact_label | exact_code | fuzzy | none
     *   confidence 0-100
     */
    function fluxbox_va_resolve_glossary_label(string $category, string $label, ?PDO $pdo = null, int $fuzzyThreshold = 80): array
    {
        if ($label === '') {
            return ['code' => null, 'match_type' => 'none', 'confidence' => 0];
        }
        if ($pdo === null) $pdo = ged_glossary_pdo();

        $tenantId = ged_glossary_current_tenant();
        $needle = trim($label);

        // 1. Match exact label (case-insensitive)
        $st = $pdo->prepare("
            SELECT code, label FROM ged_codes_glossaire
            WHERE tenant_id = ? AND category = ? AND is_active = 1 AND LOWER(label) = LOWER(?)
            LIMIT 1
        ");
        $st->execute([$tenantId, $category, $needle]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['code' => (string)$row['code'], 'match_type' => 'exact_label', 'confidence' => 100];
        }

        // 2. Match exact code (l'OCR a peut-être lu le code)
        $needleSlug = ged_glossary_slug_code($needle);
        if ($needleSlug !== '') {
            $st = $pdo->prepare("
                SELECT code, label FROM ged_codes_glossaire
                WHERE tenant_id = ? AND category = ? AND is_active = 1 AND code = ?
                LIMIT 1
            ");
            $st->execute([$tenantId, $category, $needleSlug]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['code' => (string)$row['code'], 'match_type' => 'exact_code', 'confidence' => 95];
            }
        }

        // 3. Fuzzy sur labels (normalisation ASCII upper)
        $normalize = static function (string $s): string {
            if (function_exists('iconv')) {
                $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
                if ($tr !== false) $s = $tr;
            }
            return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $s) ?? '');
        };

        $needleNorm = $normalize($needle);
        if ($needleNorm === '') {
            return ['code' => null, 'match_type' => 'none', 'confidence' => 0];
        }

        $st = $pdo->prepare("
            SELECT code, label FROM ged_codes_glossaire
            WHERE tenant_id = ? AND category = ? AND is_active = 1
        ");
        $st->execute([$tenantId, $category]);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC);

        $best = ['code' => null, 'score' => 0];
        foreach ($candidates as $c) {
            $candNorm = $normalize((string)$c['label']);
            if ($candNorm === '') continue;
            $maxLen = max(strlen($needleNorm), strlen($candNorm));
            if ($maxLen === 0) continue;
            $lev = levenshtein($needleNorm, $candNorm);
            // Score = (1 - lev/maxLen) * 100
            $score = (int)round((1 - $lev / $maxLen) * 100);
            // Bonus si l'un contient l'autre
            if (str_contains($candNorm, $needleNorm) || str_contains($needleNorm, $candNorm)) {
                $score = min(100, $score + 10);
            }
            if ($score > $best['score']) {
                $best = ['code' => (string)$c['code'], 'score' => $score];
            }
        }

        if ($best['code'] !== null && $best['score'] >= $fuzzyThreshold) {
            return ['code' => $best['code'], 'match_type' => 'fuzzy', 'confidence' => $best['score']];
        }

        return ['code' => null, 'match_type' => 'none', 'confidence' => $best['score']];
    }
}

if (!function_exists('fluxbox_va_resolve_extracted')) {
    /**
     * Orchestrateur : prend la sortie brute Vision et retourne le bloc résolu + statut.
     *
     * @param array $extracted  ['banque_text', 'compte_text', 'periode_text', 'immeuble_text', 'type_text', ...]
     * @param array $ctx        ['societe_code', 'agence_code', 'user_code', 'n1_code', 'n2_code']
     * @return array{resolved:array, status:string, review_reason:?string, missing:array}
     *   status ∈ ready | needs_review
     *   review_reason : banque_unknown | immeuble_unknown | compte4_missing | periode_missing | multiple | null
     */
    function fluxbox_va_resolve_extracted(array $extracted, array $ctx, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_glossary_pdo();

        // Pour bank statements : société/agence dérivés de l'immeuble détecté (pas de la session).
        // Le contexte session reste en fallback uniquement si AUCUN immeuble n'est dans le doc.
        $resolved = [
            'societe_code'  => null,  // rempli plus bas via immeuble
            'agence_code'   => null,  // rempli plus bas via immeuble
            'user_code'     => fluxbox_va_slug_segment((string)($ctx['user_code']    ?? '')),
            'date_integration' => fluxbox_va_format_date_integration(),
            'n1_code'       => fluxbox_va_slug_segment((string)($ctx['n1_code'] ?? '')),
            'n2_code'       => fluxbox_va_slug_segment((string)($ctx['n2_code'] ?? '')),
            'banque_code'   => null,
            'compte4'       => null,
            'periode_mm_yyyy' => null,
            'immeuble_code' => null,
            'immeuble_id'   => null,   // id immeubles.id (pour metadata)
            'societe_id'    => null,
            'agence_id'     => null,
            'type_code'     => null,
            'match_details' => [],
        ];

        $missing = [];

        // Banque
        $banqueText = trim((string)($extracted['banque_text'] ?? ''));
        if ($banqueText !== '') {
            $r = fluxbox_va_resolve_glossary_label('banque', $banqueText, $pdo);
            $resolved['banque_code'] = $r['code'];
            $resolved['match_details']['banque'] = $r;
            if ($r['code'] === null) $missing[] = 'banque_unknown';
        } else {
            $missing[] = 'banque_unknown';
        }

        // Compte4
        $compteRaw = (string)($extracted['compte_text'] ?? '');
        $compte4   = fluxbox_va_extract_compte4($compteRaw);
        $resolved['compte4'] = $compte4;
        if ($compte4 === null) $missing[] = 'compte4_missing';

        // Période
        $periodeText = (string)($extracted['periode_text'] ?? '');
        $periode = fluxbox_va_format_periode_mm_yyyy($periodeText !== '' ? $periodeText : null);
        $resolved['periode_mm_yyyy'] = $periode;
        if ($periode === null) $missing[] = 'periode_missing';

        // Immeuble — déclenche la résolution société/agence depuis immeubles.id_*
        $immeubleText = trim((string)($extracted['immeuble_text'] ?? ''));
        if ($immeubleText !== '') {
            $r = fluxbox_va_resolve_glossary_label('immeuble', $immeubleText, $pdo);
            $resolved['immeuble_code'] = $r['code'];
            $resolved['match_details']['immeuble'] = $r;
            if ($r['code'] === null) $missing[] = 'immeuble_unknown';
            else {
                // Récupère immeuble_id depuis le glossaire (entity_id) puis id_societe / id_agence
                $gloss = ged_glossary_get_by_code('immeuble', $r['code'], $pdo);
                if ($gloss && !empty($gloss['entity_id']) && (string)$gloss['entity_table'] === 'immeubles') {
                    $immId = (int)$gloss['entity_id'];
                    $resolved['immeuble_id'] = $immId;
                    $stImm = $pdo->prepare("SELECT id_societe, id_agence FROM immeubles WHERE id = ? LIMIT 1");
                    $stImm->execute([$immId]);
                    $immRow = $stImm->fetch(PDO::FETCH_ASSOC);
                    if ($immRow) {
                        $resolved['societe_id'] = !empty($immRow['id_societe']) ? (int)$immRow['id_societe'] : null;
                        $resolved['agence_id']  = !empty($immRow['id_agence'])  ? (int)$immRow['id_agence']  : null;
                        if ($resolved['societe_id']) {
                            $g = ged_glossary_get('societe', $resolved['societe_id'], $pdo);
                            if ($g && !empty($g['code'])) $resolved['societe_code'] = (string)$g['code'];
                        }
                        if ($resolved['agence_id']) {
                            $g = ged_glossary_get('agence', $resolved['agence_id'], $pdo);
                            if ($g && !empty($g['code'])) $resolved['agence_code'] = (string)$g['code'];
                        }
                    }
                }
            }
        }

        // Type document
        $typeText = trim((string)($extracted['type_text'] ?? ''));
        if ($typeText !== '') {
            $r = fluxbox_va_resolve_glossary_label('type_document', $typeText, $pdo);
            $resolved['type_code'] = $r['code'];
            $resolved['match_details']['type_document'] = $r;
        }

        // Statut
        $status = 'ready';
        $reason = null;
        if (count($missing) === 1) {
            $status = 'needs_review';
            $reason = $missing[0];
        } elseif (count($missing) > 1) {
            $status = 'needs_review';
            $reason = 'multiple';
        }

        return [
            'resolved'      => $resolved,
            'status'        => $status,
            'review_reason' => $reason,
            'missing'       => $missing,
        ];
    }
}

if (!function_exists('fluxbox_va_build_name')) {
    /**
     * Construit le nom de fichier Variante A à partir d'un bloc résolu.
     *
     * Segments vides en milieu de chaîne sont remplacés par "-" (placeholder),
     * sauf banque/compte4/periode/immeuble qui peuvent légitimement manquer
     * (non bancaire). Les "-" finaux sont trimés.
     *
     * @param array  $resolved  retour de fluxbox_va_resolve_extracted()['resolved']
     * @param string $ext       extension sans point (pdf, jpg, ...)
     * @return string nom complet avec extension
     */
    function fluxbox_va_build_name(array $resolved, string $ext = 'pdf'): string
    {
        $segments = [
            fluxbox_va_slug_segment((string)($resolved['societe_code']      ?? '')) ?: 'SOC',
            fluxbox_va_slug_segment((string)($resolved['agence_code']       ?? '')) ?: 'AGE',
            fluxbox_va_slug_segment((string)($resolved['user_code']         ?? '')) ?: 'USR',
            (string)($resolved['date_integration'] ?? date('dmy')),
            fluxbox_va_slug_segment((string)($resolved['n1_code']           ?? '')),
            fluxbox_va_slug_segment((string)($resolved['n2_code']           ?? '')),
            fluxbox_va_slug_segment((string)($resolved['banque_code']       ?? '')),
            (string)($resolved['compte4']         ?? ''),
            (string)($resolved['periode_mm_yyyy'] ?? ''),
            fluxbox_va_slug_segment((string)($resolved['immeuble_code']     ?? '')),
            fluxbox_va_slug_segment((string)($resolved['type_code']         ?? '')),
        ];

        // Trim segments vides en fin
        while (count($segments) > 0 && $segments[count($segments) - 1] === '') {
            array_pop($segments);
        }

        // Segments vides en milieu = "-" pour préserver la position
        for ($i = 0, $n = count($segments); $i < $n; $i++) {
            if ($segments[$i] === '') $segments[$i] = '-';
        }

        $base = implode('_', $segments);

        $ext = strtolower(trim($ext, " \t.\n\r"));
        return $ext === '' ? $base : ($base . '.' . $ext);
    }
}

if (!function_exists('fluxbox_va_preview')) {
    /**
     * Génère une preview du nom (sans extension) pour l'UI temps réel.
     */
    function fluxbox_va_preview(array $resolved): string
    {
        return fluxbox_va_build_name($resolved, '');
    }
}
