<?php
/**
 * inc/ged_doc_naming_v3.php
 *
 * Générateur de nom de fichier FluxBox V3 (validé EMERY 2026-05-15).
 * Format à 11 segments selon [[project_fluxbox_naming_variant_a]].
 *
 * Format canonique (exemple validé Emmanuel) :
 *   REGE_69-1_0042_150526_COMPTA_BANQUE_PALA_8320_02-2026_CHATEAUVIEUX_releve-banq.pdf
 *
 * Décomposition (11 segments séparés par `_`) :
 *   1. societe      — code court 4 chars (REGE, LOCA, ...) issus raison_sociale
 *   2. agence       — code agence (LI42-1, 69-1) depuis agences.code_agence
 *   3. user         — matricule_paie sur 4 chiffres (0042) sinon u{id}
 *   4. date-integration — JJMMAA (date('dmy')) à l'upload
 *   5. n1           — code MÉTIER court (COMPTA, TRANSAC, GESTION, SYNDIC...)
 *   6. n2           — domaine court (BANQUE, ACTE, MANDAT...)
 *   7. banque       — code banque (PALA, BNP...) ou "-"
 *   8. compte4      — 4 derniers chiffres compte/IBAN ou "-"
 *   9. periode      — MM-AAAA mois métier ou "-"
 *  10. immeuble     — code court (CHATEAUVIEUX, TISSOT...) depuis nom_immeuble
 *  11. type-document — kebab-case lowercase (compromis, bail-signe, releve-banq)
 *
 * MAJ 2026-05-24 (fix glossaire conformité) :
 *  - societe : 4 premiers chars du 1er mot significatif (REGE pour "Régie Emery")
 *  - n1/n2 : NOM COURT du module (pas code numérique)
 *  - immeuble : extraction mot le plus distinctif (sans stopwords/numéros)
 *  - type-document : kebab-case lowercase (cohérent spec)
 *  - user : matricule_paie en priorité (4 chars zéro-paddés)
 */

declare(strict_types=1);

if (!function_exists('gdn_v3_strip_accents')) {
    function gdn_v3_strip_accents(string $s): string {
        if (function_exists('em_strip_accents')) return em_strip_accents($s);
        $a = ['à','â','ä','á','ã','å','À','Â','Ä','Á','Ã','Å',
              'é','è','ê','ë','É','È','Ê','Ë',
              'î','ï','í','ì','Î','Ï','Í','Ì',
              'ô','ö','ò','ó','õ','Ô','Ö','Ò','Ó','Õ',
              'û','ü','ù','ú','Û','Ü','Ù','Ú',
              'ÿ','ñ','Ñ','ç','Ç','œ','Œ','æ','Æ'];
        $r = ['a','a','a','a','a','a','A','A','A','A','A','A',
              'e','e','e','e','E','E','E','E',
              'i','i','i','i','I','I','I','I',
              'o','o','o','o','o','O','O','O','O','O',
              'u','u','u','u','U','U','U','U',
              'y','n','N','c','C','oe','OE','ae','AE'];
        return str_replace($a, $r, $s);
    }
}

if (!function_exists('gdn_v3_societe_code')) {
    /**
     * Code court société (4 chars MAJ).
     * "Régie Emery"       → "REGE"
     * "LOCA IMMO Holding" → "LOCA"
     * "SARL Dupont & fils" → "SARL" (mais idéalement "DUPO" → on prend 2e mot si 1er = forme juridique)
     */
    function gdn_v3_societe_code(?string $raisonSociale): string {
        if ($raisonSociale === null || trim($raisonSociale) === '') return '-';
        $s = gdn_v3_strip_accents($raisonSociale);
        $s = strtoupper((string)preg_replace('/[^A-Z0-9 ]/i', ' ', $s));
        $s = trim((string)preg_replace('/\s+/', ' ', $s));
        if ($s === '') return '-';

        // Ignorer formes juridiques en 1er mot (SARL, SAS, SA, SCI, SCP, EURL, SELARL)
        $formes = ['SARL','SAS','SA','SCI','SCP','EURL','SELARL','SNC','SCM','SCEA','SASU'];
        $words = explode(' ', $s);
        if (count($words) > 1 && in_array($words[0], $formes, true)) {
            array_shift($words);
        }
        $first = $words[0] ?? $s;
        return substr($first, 0, 4) ?: '-';
    }
}

if (!function_exists('gdn_v3_agence_code')) {
    /**
     * Code agence depuis colonne agences.code_agence (priorité) sinon dérivé.
     * "LI42-1" → "LI42-1" (préservé tel quel, c'est déjà le code glossaire)
     */
    function gdn_v3_agence_code(?string $codeAgence, ?string $nomAgence = null): string {
        if ($codeAgence !== null && trim($codeAgence) !== '') {
            $c = trim($codeAgence);
            // Préserver tirets (cf "69-1"), strip espaces et autres
            $c = (string)preg_replace('/[^A-Z0-9\-]/i', '', $c);
            return substr(strtoupper($c), 0, 10) ?: '-';
        }
        // Fallback : initiales du nom agence
        if ($nomAgence !== null && trim($nomAgence) !== '') {
            $s = gdn_v3_strip_accents($nomAgence);
            $s = strtoupper((string)preg_replace('/[^A-Z0-9 ]/i', ' ', $s));
            $parts = preg_split('/\s+/', trim($s)) ?: [];
            $abbr = '';
            foreach ($parts as $p) {
                if ($p !== '') $abbr .= $p[0];
                if (strlen($abbr) >= 6) break;
            }
            return $abbr ?: '-';
        }
        return '-';
    }
}

if (!function_exists('gdn_v3_user_code')) {
    /**
     * Code user : matricule_paie (4 chars zéro-paddés) sinon username sinon u{id}.
     * "0042" → "0042" · "cecile" → "CECILE" · sans rien → "u8"
     */
    function gdn_v3_user_code(?string $matriculePaie, ?string $username = null, ?int $userId = null): string {
        if ($matriculePaie !== null && trim($matriculePaie) !== '') {
            $m = (string)preg_replace('/\D/', '', $matriculePaie);
            if ($m !== '') return str_pad(substr($m, -4), 4, '0', STR_PAD_LEFT);
            // Si matricule alpha (rare) : keep tel quel
            $alpha = (string)preg_replace('/[^A-Z0-9]/i', '', $matriculePaie);
            if ($alpha !== '') return strtoupper(substr($alpha, 0, 6));
        }
        if ($username !== null && trim($username) !== '') {
            $u = (string)preg_replace('/[^a-zA-Z0-9]/', '', $username);
            return substr(strtoupper($u), 0, 6) ?: 'USER';
        }
        if ($userId !== null && $userId > 0) return 'u' . $userId;
        return 'USER';
    }
}

if (!function_exists('gdn_v3_module_code')) {
    /**
     * Code court N1 (métier) ou N2 (domaine) depuis le slug.
     * "06_transaction"  → "TRANSAC"
     * "07_comptabilite" → "COMPTA"
     * "05_gestion_locative" → "GESTION"
     * "02_acte"         → "ACTE"
     * "01_banque"       → "BANQUE"
     * "01_assemblee_generale" → "ASSEMB"
     *
     * Règle : strip préfixe NN_, prendre 1er mot non vide, MAJ, tronquer à 7 chars.
     */
    function gdn_v3_module_code(?string $slug, int $maxLen = 7): string {
        if ($slug === null || trim($slug) === '') return '-';
        // Strip préfixe NN_
        $s = (string)preg_replace('/^\d{2}_/', '', trim($slug));
        // Prendre le 1er mot
        $parts = preg_split('/[_\-]/', $s) ?: [];
        $first = $parts[0] ?? $s;
        $first = (string)preg_replace('/[^A-Z0-9]/i', '', $first);
        if ($first === '') return '-';
        return strtoupper(substr($first, 0, $maxLen));
    }
}

if (!function_exists('gdn_v3_immeuble_code')) {
    /**
     * Code court immeuble : extraire le mot le plus distinctif du nom (ignorer
     * stopwords, codes postaux, "France", etc.).
     * "Le Tissot, 42530 Saint-Genest-Lerpt, France" → "TISSOT"
     * "Résidence Chateauvieux"                       → "CHATEAUVIEUX"
     * "15 rue Mermet"                                → "MERMET"
     */
    function gdn_v3_immeuble_code(?string $nomImmeuble, int $maxLen = 12): string {
        if ($nomImmeuble === null || trim($nomImmeuble) === '') return '-';
        $s = gdn_v3_strip_accents($nomImmeuble);
        $s = (string)preg_replace('/[,;.]/', ' ', $s);
        $s = (string)preg_replace('/\s+/', ' ', trim($s));

        $stopwords = ['LE','LA','LES','DU','DE','DES','DELLA','UN','UNE','RUE','RUE-DE',
                      'AVENUE','BOULEVARD','ROUTE','CHEMIN','PLACE','IMPASSE','SQUARE',
                      'ALLEE','RESIDENCE','RESID','LOT','BAT','BATIMENT','LOTISSEMENT',
                      'FRANCE','BELGIQUE','LUXEMBOURG','SUISSE',
                      'SAINT','ST','STE','SAINTE'];
        $tokens = preg_split('/\s+/', strtoupper($s)) ?: [];
        $kept = [];
        foreach ($tokens as $t) {
            $t = (string)preg_replace('/[^A-Z0-9\-]/', '', $t);
            if ($t === '' || ctype_digit($t)) continue;
            if (in_array($t, $stopwords, true)) continue;
            $kept[] = $t;
        }
        if (empty($kept)) return '-';

        // Prendre le mot le plus long parmi les significatifs (heuristique simple)
        usort($kept, static fn($a, $b) => strlen($b) <=> strlen($a));
        return substr($kept[0], 0, $maxLen);
    }
}

if (!function_exists('gdn_v3_type_code')) {
    /**
     * Code type document : kebab-case lowercase ASCII (conformité glossaire).
     * "compromis"          → "compromis"
     * "bail_signe"         → "bail-signe"
     * "releve_bancaire"    → "releve-banq" (tronqué à 12 chars)
     * "acte_authentique"   → "acte-authent"
     */
    // MaxLen 20 (vs 15 initial) : permet de garder "acte-authentique" entier (16 chars)
    // sans tronquer en "acte-authentiqu" (bug Sprint 5 P2 — fix stabilisation 2026-05-24).
    function gdn_v3_type_code(?string $typeDoc, int $maxLen = 20): string {
        // Fix B9 (2026-05-26) : fallback 'document' au lieu de '-' (segment 11 = type, jamais vide visuellement)
        if ($typeDoc === null || trim($typeDoc) === '') return 'document';
        $s = gdn_v3_strip_accents($typeDoc);
        $s = strtolower((string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $s));
        $s = str_replace('_', '-', $s);
        $s = (string)preg_replace('/-+/', '-', trim($s, '-'));
        if ($s === '') return 'document';
        return substr($s, 0, $maxLen);
    }
}

if (!function_exists('gdn_v3_date_ddmmyy')) {
    function gdn_v3_date_ddmmyy(?string $date = 'now'): string {
        if ($date === null || $date === '') $date = 'now';
        try { return (new DateTime($date))->format('dmy'); }
        catch (Throwable) { return date('dmy'); }
    }
}

if (!function_exists('gdn_v3_mm_yyyy')) {
    function gdn_v3_mm_yyyy(?string $date = null): string {
        if ($date === null || $date === '') return '-';
        try { return (new DateTime($date))->format('m-Y'); }
        catch (Throwable) {
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($date), $m)) {
                return sprintf('%02d-%s', (int)$m[2], $m[3]);
            }
            return '-';
        }
    }
}

if (!function_exists('gdn_v3_compte4')) {
    function gdn_v3_compte4(?string $compte): string {
        if ($compte === null || trim($compte) === '') return '-';
        $d = (string)preg_replace('/\D/', '', $compte);
        return strlen($d) >= 4 ? substr($d, -4) : '-';
    }
}

if (!function_exists('gdn_v3_banque_code')) {
    function gdn_v3_banque_code(?string $banque): string {
        if ($banque === null || trim($banque) === '') return '-';
        $s = gdn_v3_strip_accents($banque);
        $s = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $s));
        // Mapping connu (peut être étendu en V1+)
        $known = ['CREDITMUTUEL'=>'CMUT','BNPPARIBAS'=>'BNPP','SOCIETEGENERALE'=>'SOGE',
                  'PALATINE'=>'PALA','LABANQUEPOSTALE'=>'LBP','CREDITAGRICOLE'=>'CAGR',
                  'CICCREDIT'=>'CIC','CIC'=>'CIC','BPCE'=>'BPCE','HSBC'=>'HSBC'];
        if (isset($known[$s])) return $known[$s];
        return substr($s, 0, 4) ?: '-';
    }
}

if (!function_exists('gdn_v3_extract_ext')) {
    function gdn_v3_extract_ext(?string $filename, string $default = 'pdf'): string {
        if (!$filename) return $default;
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        return $ext !== '' ? (string)preg_replace('/[^a-z0-9]/', '', $ext) : $default;
    }
}

if (!function_exists('gdn_v3_build')) {
    /**
     * Construit le nom V3 conforme au glossaire FluxBox.
     *
     * @param array $ctx Clés attendues (toutes optionnelles, fallback "-") :
     *   - societe_raison    (raison sociale brute, ex: "Régie Emery")
     *   - agence_code       (code agence BDD, ex: "LI42-1")
     *   - agence_nom        (nom agence si pas de code)
     *   - user_matricule    (matricule paie, ex: "0042")
     *   - user_username     (username sinon)
     *   - user_id           (id user sinon)
     *   - upload_date       (default 'now')
     *   - n1_slug           (ex: "06_transaction")
     *   - n2_slug           (ex: "02_acte")
     *   - banque            (nom banque)
     *   - compte_iban       (IBAN ou compte)
     *   - date_doc          (date métier doc)
     *   - immeuble_nom      (nom_immeuble BDD)
     *   - type_doc          (ex: "compromis", "bail_signe")
     *   - ext / source_filename
     */
    function gdn_v3_build(array $ctx, int $maxLen = 250): string {
        $ext = $ctx['ext'] ?? gdn_v3_extract_ext($ctx['source_filename'] ?? null);

        // ─── FORMAT V3.1 (validé Emery 2026-05-24) — 11 segments universels ───
        // 1=société, 2=agence, 3=user, 4=date upload, 5=N1, 6=N2, 7=N3, 8=N4,
        // 9=date doc, 10=entité concernée (préfixe lettre + id), 11=type doc
        // L'immeuble/banque/compte ne sont plus dans le nom : retrouvables en BDD.
        $segments = [
            gdn_v3_societe_code($ctx['societe_raison'] ?? $ctx['societe'] ?? null),                     // 1
            gdn_v3_agence_code($ctx['agence_code'] ?? null, $ctx['agence_nom'] ?? $ctx['agence'] ?? null), // 2
            gdn_v3_user_code(
                $ctx['user_matricule'] ?? null,
                $ctx['user_username'] ?? $ctx['user'] ?? null,
                isset($ctx['user_id']) ? (int)$ctx['user_id'] : null
            ),                                                                                          // 3
            gdn_v3_date_ddmmyy($ctx['upload_date'] ?? 'now'),                                           // 4
            gdn_v3_module_code($ctx['n1_slug'] ?? null, 7),                                             // 5
            gdn_v3_module_code($ctx['n2_slug'] ?? null, 7),                                             // 6
            gdn_v3_module_code($ctx['n3_slug'] ?? null, 9),                                             // 7
            gdn_v3_module_code($ctx['n4_slug'] ?? null, 9),                                             // 8
            gdn_v3_mm_yyyy($ctx['date_doc'] ?? null),                                                   // 9
            gdn_v3_entity_ref($ctx['entity_type'] ?? null, $ctx['entity_id'] ?? null, $ctx['entity_ref'] ?? null), // 10
            gdn_v3_type_code($ctx['type_doc'] ?? null),                                                 // 11
        ];

        $name = implode('_', $segments) . '.' . $ext;

        if (strlen($name) > $maxLen) {
            // Tronquer en dernier recours
            $name = substr($name, 0, $maxLen - 4) . '.' . $ext;
        }
        return $name;
    }
}

if (!function_exists('gdn_v3_entity_ref')) {
    /**
     * Référence d'entité polymorphe (segment 10 du nom V3.1).
     *
     * Préfixe 1 lettre selon le type d'entité concernée :
     *   B = BIEN          → "B733"
     *   I = IMMEUBLE      → "I836"
     *   T = TIERS         → "T8"
     *   M = MANDAT        → "M12"
     *   L = BAIL (Loc)    → "L45"
     *   C = COMPTE compta → "C2026-001"
     *   F = FOURNISSEUR   → "F-EDF"
     *   R = RH            → "R-EMERY"
     *   S = SOCIÉTÉ       → "S1" (doc niveau société : KBIS, carte pro…)
     *   A = AGENCE        → "A3"
     *
     * @param ?string $entityType BIEN|IMB|IMMEUBLE|TIERS|MANDAT|BAIL|COMPTE|FOURNISSEUR|RH|SOCIETE|AGENCE
     * @param int|string|null $entityId  ID numérique ou code (slug pour fournisseur ex.)
     * @param ?string $rawRef   Override direct (ex: "C2026-001"). Si fourni, ignore type+id.
     */
    function gdn_v3_entity_ref(?string $entityType, $entityId, ?string $rawRef = null): string {
        if ($rawRef !== null && trim($rawRef) !== '') {
            // Override : nettoyer juste les caractères interdits
            $r = strtoupper((string)preg_replace('/[^A-Z0-9\-]/i', '', $rawRef));
            return $r ?: '-';
        }
        if ($entityType === null || $entityId === null || $entityId === '' || $entityId === 0 || $entityId === '0') {
            return '-';
        }
        $type = strtoupper(trim($entityType));
        $prefix = match ($type) {
            'BIEN'                       => 'B',
            'IMB', 'IMMEUBLE'            => 'I',
            'TIERS'                      => 'T',
            'MANDAT', 'MDT'              => 'M',
            'BAIL', 'LOCATION', 'LOC'    => 'L',
            'COMPTE', 'CPC', 'COMPTA'    => 'C',
            'FOURNISSEUR', 'FOURNI', 'F' => 'F',
            'RH', 'USER'                 => 'R',
            'SOCIETE', 'SOC'             => 'S',
            'AGENCE', 'AGE'              => 'A',
            'CREANCIER_DOSSIER', 'CREANCIER' => 'K',
            default                      => 'X',
        };
        // Pour IDs numériques : juste préfixe+id
        if (is_numeric($entityId)) return $prefix . (int)$entityId;
        // Pour codes (ex: "EDF") : préfixe-CODE
        $code = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)$entityId));
        return $code !== '' ? $prefix . '-' . $code : '-';
    }
}

if (!function_exists('gdn_v3_explain')) {
    function gdn_v3_explain(array $ctx): array {
        return [
            '01_societe'   => gdn_v3_societe_code($ctx['societe_raison'] ?? $ctx['societe'] ?? null),
            '02_agence'    => gdn_v3_agence_code($ctx['agence_code'] ?? null, $ctx['agence_nom'] ?? $ctx['agence'] ?? null),
            '03_user'      => gdn_v3_user_code($ctx['user_matricule'] ?? null, $ctx['user_username'] ?? $ctx['user'] ?? null, isset($ctx['user_id']) ? (int)$ctx['user_id'] : null),
            '04_date'      => gdn_v3_date_ddmmyy($ctx['upload_date'] ?? 'now'),
            '05_n1'        => gdn_v3_module_code($ctx['n1_slug'] ?? null, 7),
            '06_n2'        => gdn_v3_module_code($ctx['n2_slug'] ?? null, 7),
            '07_n3'        => gdn_v3_module_code($ctx['n3_slug'] ?? null, 9),
            '08_n4'        => gdn_v3_module_code($ctx['n4_slug'] ?? null, 9),
            '09_date_doc'  => gdn_v3_mm_yyyy($ctx['date_doc'] ?? null),
            '10_entity'    => gdn_v3_entity_ref($ctx['entity_type'] ?? null, $ctx['entity_id'] ?? null, $ctx['entity_ref'] ?? null),
            '11_type'      => gdn_v3_type_code($ctx['type_doc'] ?? null),
            'ext'          => $ctx['ext'] ?? gdn_v3_extract_ext($ctx['source_filename'] ?? null),
            'final_name'   => gdn_v3_build($ctx),
        ];
    }
}
