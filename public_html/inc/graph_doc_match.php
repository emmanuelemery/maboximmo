<?php
/**
 * inc/graph_doc_match.php
 *
 * Briques partagées pour la recherche assistée de documents dans le OneDrive
 * général (Microsoft Graph) à partir d'un BIEN courant.
 *
 *  - gdm_normalize()        : normalisation tolérante (casse, accents, ponctuation, abréviations)
 *  - gdm_type_keywords()    : mots-clés OneDrive par type de document GED
 *  - gdm_score_candidate()  : score de pertinence d'un fichier OneDrive vs un bien
 *
 * Idée directrice (Emmanuel 2026-06-06) : la recherche est GUIDÉE PAR LE BIEN
 * COURANT (référence, adresse, ville, propriétaire) ; la recherche Graph globale
 * couvre toute l'arborescence (métier→agence→propriétaire, + exception Lyon par
 * type "Diag"/"Assemblée"). Le matching est insensible casse/accents/abréviations.
 */

declare(strict_types=1);

if (!function_exists('gdm_normalize')) {
    /**
     * Normalise une chaîne pour comparaison tolérante :
     *  - minuscules
     *  - accents retirés (é→e, è→e, ç→c…)
     *  - ponctuation/espaces multiples → espace simple
     *  - abréviations d'adresse dépliées (bd→boulevard, av→avenue, st→saint…)
     */
    function gdm_normalize(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';

        // Translittération accents → ASCII via table MANUELLE (fiable, locale-agnostique).
        // NB : on n'utilise PAS iconv //TRANSLIT qui, selon la locale, transforme
        // "É" en "'E" → "MARÉCHAL" deviendrait "MAR ECHAL" (bug 2026-06).
        static $accents = [
            'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a','À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A','Ã'=>'A','Å'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'î'=>'i','ï'=>'i','í'=>'i','ì'=>'i','Î'=>'I','Ï'=>'I','Í'=>'I','Ì'=>'I',
            'ô'=>'o','ö'=>'o','ò'=>'o','ó'=>'o','õ'=>'o','Ô'=>'O','Ö'=>'O','Ò'=>'O','Ó'=>'O','Õ'=>'O',
            'û'=>'u','ü'=>'u','ù'=>'u','ú'=>'u','Û'=>'U','Ü'=>'U','Ù'=>'U','Ú'=>'U',
            'ÿ'=>'y','ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C','œ'=>'oe','Œ'=>'OE','æ'=>'ae','Æ'=>'AE',
        ];
        $s = strtr($s, $accents);
        $s = strtolower($s);

        // Tout ce qui n'est pas alphanumérique → espace
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $s = trim((string)preg_replace('/\s+/', ' ', $s));

        // Dépliage des abréviations d'adresse courantes (mot entier)
        $abbr = [
            'bd' => 'boulevard', 'blv' => 'boulevard', 'bld' => 'boulevard',
            'av' => 'avenue', 'ave' => 'avenue',
            'r' => 'rue',
            'st' => 'saint', 'ste' => 'sainte',
            'pl' => 'place', 'imp' => 'impasse',
            'ch' => 'chemin', 'rte' => 'route',
            'sq' => 'square', 'all' => 'allee',
        ];
        if ($s !== '') {
            $words = explode(' ', $s);
            foreach ($words as &$w) {
                if (isset($abbr[$w])) $w = $abbr[$w];
            }
            unset($w);
            $s = implode(' ', $words);
        }
        return $s;
    }
}

if (!function_exists('gdm_tokens')) {
    /** Découpe en tokens normalisés significatifs (≥ 2 caractères). */
    function gdm_tokens(string $s): array
    {
        $n = gdm_normalize($s);
        if ($n === '') return [];
        $toks = array_filter(explode(' ', $n), fn($t) => strlen($t) >= 2);
        return array_values(array_unique($toks));
    }
}

if (!function_exists('gdm_type_keywords')) {
    /**
     * Mots-clés de recherche OneDrive par document_type GED.
     * Le 1er élément est le terme principal envoyé à Graph /search ;
     * les suivants servent au scoring (présence dans le nom/chemin).
     */
    function gdm_type_keywords(string $documentType): array
    {
        $map = [
            // DDT = Dossier de Diagnostic Technique (nom de fichier fréquent à Lyon)
            'DIAG_DPE'       => ['dpe', 'ddt', 'diagnostic', 'performance', 'energetique'],
            'DIAG_ERP'       => ['erp', 'risque', 'errial', 'georisque'],
            'DIAG_PLOMB'     => ['plomb', 'crep'],
            'DIAG_AMIANTE'   => ['amiante'],
            'DIAG_GAZ'       => ['gaz'],
            'DIAG_ELEC'      => ['electricite', 'electrique'],
            'SURFACE_CARREZ' => ['carrez', 'boutin', 'mesurage', 'surface'],
            'ETAT_LIEUX'     => ['etat', 'lieux', 'edl'],
        ];
        return $map[strtoupper($documentType)] ?? [strtolower($documentType)];
    }
}

if (!function_exists('gdm_type_strong_markers')) {
    /**
     * Marqueurs FORTS qui identifient SANS AMBIGUÏTÉ un type de document dans un
     * nom de fichier. Sert au filtre négatif : un fichier portant le marqueur fort
     * d'un AUTRE type (et pas celui recherché) est écarté.
     *
     * Le DDT (dossier complet) embarque DPE+amiante+plomb+gaz+ERP : ses marqueurs
     * 'dpe'/'ddt' priment donc sur les marqueurs des sous-diagnostics.
     */
    function gdm_type_strong_markers(): array
    {
        return [
            'DIAG_DPE'       => ['dpe', 'ddt'],
            'DIAG_ERP'       => ['erp', 'ernmt', 'ernt', 'errial'],
            'DIAG_PLOMB'     => ['plomb', 'crep'],
            'DIAG_AMIANTE'   => ['amiante'],
            'DIAG_GAZ'       => ['gaz'],
            'DIAG_ELEC'      => ['electricite', 'electrique'],
            'SURFACE_CARREZ' => ['carrez', 'boutin'],
            'ETAT_LIEUX'     => ['edl'],
        ];
    }
}

if (!function_exists('gdm_type_section_keywords')) {
    /**
     * Mot(s) du dossier de SECTION (niveau agence) où vit ce type de document.
     * Ex. arbo Lyon : 02_LYON GESTION / 022-DIAGNOSTICS / <adresse> / fichiers.
     * Pour un diagnostic → section "DIAGNOSTICS".
     */
    function gdm_type_section_keywords(string $documentType): array
    {
        $t = strtoupper($documentType);
        if (str_starts_with($t, 'DIAG_') || $t === 'SURFACE_CARREZ') {
            return ['diagnostic'];   // str_contains matche "022-DIAGNOSTICS"
        }
        return match ($t) {
            'ETAT_LIEUX' => ['location', 'etat des lieux'],
            default      => [],
        };
    }
}

if (!function_exists('gdm_foreign_section_keywords')) {
    /**
     * Sections (branches métier) à EXCLURE du chemin pour ce type — on ne veut
     * pas piocher un fichier d'une autre branche (ASSEMBLÉES, MANDATS, COMPTA…).
     */
    function gdm_foreign_section_keywords(string $documentType): array
    {
        $all = ['assemblee', 'mandat', 'baux', 'comptabilite', 'banque',
                'impot', 'assurance', 'fournisseur', 'photo', 'contentieux',
                'procedure', 'location', 'charge', 'syndic', 'attestation propriete',
                'avis tiers', 'copro', 'reglement', 'estimation'];
        $own = array_map('gdm_normalize', gdm_type_section_keywords($documentType));
        return array_values(array_filter($all, fn($k) => !in_array(gdm_normalize($k), $own, true)));
    }
}

if (!function_exists('gdm_path_excluded')) {
    /**
     * Vrai si le CHEMIN du fichier appartient à une branche métier étrangère au
     * type recherché (ex. .../022-ASSEMBLEES/... pour une recherche de diagnostic).
     * Si le chemin contient la section du type (DIAGNOSTICS), jamais exclu.
     */
    function gdm_path_excluded(string $path, string $documentType): bool
    {
        $hay = gdm_normalize($path);
        if ($hay === '') return false;
        foreach (gdm_type_section_keywords($documentType) as $k) {
            $kn = gdm_normalize($k);
            if ($kn !== '' && str_contains($hay, $kn)) return false; // bonne section
        }
        foreach (gdm_foreign_section_keywords($documentType) as $k) {
            $kn = gdm_normalize($k);
            if ($kn !== '' && str_contains($hay, $kn)) return true;  // branche étrangère
        }
        return false;
    }
}

if (!function_exists('gdm_foreign_always_markers')) {
    /**
     * Marqueurs de documents qui ne sont JAMAIS un diagnostic (PV AG, mandat,
     * bail, acte, quittance, facture…). Toujours étrangers à une recherche de
     * diagnostic technique.
     */
    function gdm_foreign_always_markers(): array
    {
        return [
            'pv', 'ag', 'pvag', 'cvag', 'assemblee', 'convocation', 'proces',
            'mandat', 'bail', 'avenant', 'conge',
            'compromis', 'promesse', 'offre',
            'acte', 'notaire', 'attestation',
            'quittance', 'facture', 'appel', 'reglement', 'releve',
            'kbis', 'rib', 'taxe', 'fonciere', 'assurance',
            'etat date', 'pre etat date', 'carnet',
        ];
    }
}

if (!function_exists('gdm_name_conflicts_type')) {
    /**
     * Vrai si le NOM de fichier identifie un AUTRE type de document que celui
     * recherché → on ne le propose pas.
     *
     * Logique :
     *  - si le nom contient un marqueur fort du type RECHERCHÉ → jamais en conflit (on garde)
     *  - sinon, si le nom contient un marqueur fort d'un AUTRE type OU un marqueur
     *    "jamais diagnostic" (PV AG, mandat…) → conflit (on écarte)
     *  - sinon (aucun marqueur typant) → pas de conflit (neutre, classé par dossier)
     */
    function gdm_name_conflicts_type(string $fileName, string $wantedType): bool
    {
        $hay = ' ' . gdm_normalize($fileName) . ' ';
        $wanted = strtoupper($wantedType);
        $all = gdm_type_strong_markers();
        $wantedMarkers = $all[$wanted] ?? [];

        // Matching par MOT ENTIER uniquement (gdm_normalize a déjà converti _/- en
        // espaces : "DDT_-_2026" → "ddt 2026", donc "ddt" est un token isolé).
        // Évite les faux positifs ("ag" dans "agence"/"garage").
        $contains = static function (array $markers) use ($hay): bool {
            foreach ($markers as $m) {
                $mn = gdm_normalize($m);
                if ($mn !== '' && str_contains($hay, ' ' . $mn . ' ')) return true;
            }
            return false;
        };

        // 1) Type recherché présent → on garde
        if ($contains($wantedMarkers)) return false;

        // 2) Marqueur d'un autre type fort présent → conflit
        foreach ($all as $type => $markers) {
            if ($type === $wanted) continue;
            if ($contains($markers)) return true;
        }
        // 3) Marqueur "jamais diagnostic" → conflit
        if ($contains(gdm_foreign_always_markers())) return true;

        // 4) Aucun marqueur typant → neutre (gardé, le dossier fait foi)
        return false;
    }
}

if (!function_exists('gdm_score_candidate')) {
    /**
     * Score un fichier OneDrive (nom + chemin parent) vs le contexte d'un bien.
     *
     * @param array $item   item Graph (clés : name, parentReference.path, file…)
     * @param array $ctx {
     *     type_keywords: string[],   // mots-clés du type de doc attendu
     *     bien_tokens:   string[],   // tokens réf/adresse/ville
     *     proprio_tokens:string[],   // tokens nom/société propriétaire
     * }
     * @return array { score:int, reasons:string[] }
     */
    function gdm_score_candidate(array $item, array $ctx): array
    {
        $name = (string)($item['name'] ?? '');
        $path = (string)($item['parentReference']['path'] ?? '');
        $hayName = gdm_normalize($name);
        $hayPath = gdm_normalize($path);
        $hayAll  = trim($hayName . ' ' . $hayPath);

        $score = 0;
        $reasons = [];

        // (1) Type de doc dans le NOM du fichier = SIGNAL LE PLUS FORT.
        //     Un fichier littéralement nommé "DPE 2023.pdf" doit dominer tout
        //     fichier simplement rangé dans un dossier "diagnostics".
        $wantedType = strtoupper((string)($ctx['document_type'] ?? ''));
        $strongMarkers = $wantedType !== '' ? (gdm_type_strong_markers()[$wantedType] ?? []) : [];
        $strongInName = false;
        $hayNamePad = ' ' . $hayName . ' ';
        foreach ($strongMarkers as $m) {
            $mn = gdm_normalize($m);
            if ($mn !== '' && str_contains($hayNamePad, ' ' . $mn . ' ')) { $strongInName = true; break; }
        }
        $typeHitName = false; $typeHitPath = false;
        foreach (($ctx['type_keywords'] ?? []) as $kw) {
            $kwn = gdm_normalize($kw);
            if ($kwn === '') continue;
            if (str_contains($hayName, $kwn)) { $typeHitName = true; }
            elseif (str_contains($hayPath, $kwn)) { $typeHitPath = true; }
        }
        if ($strongInName)     { $score += 100; $reasons[] = 'type dans le nom'; }
        elseif ($typeHitName)  { $score += 55;  $reasons[] = 'type dans le nom'; }
        elseif ($typeHitPath)  { $score += 20;  $reasons[] = 'type dans le dossier'; }

        // (2) Tokens du bien (référence, adresse, ville) présents
        $bienHits = 0;
        foreach (($ctx['bien_tokens'] ?? []) as $t) {
            if ($t !== '' && str_contains($hayAll, $t)) $bienHits++;
        }
        if ($bienHits > 0) {
            $score += min(40, $bienHits * 15);
            $reasons[] = $bienHits . ' indice(s) bien';
        }

        // (2ter) Nom du LOCATAIRE (actuel ou parti) dans le nom du fichier.
        //        Forte corrélation : diagnostics d'immeubles à lots souvent nommés
        //        d'après le locataire du lot (ex. "LOT 85 KL HABILLEMENT.pdf").
        $locHits = 0;
        foreach (($ctx['locataire_tokens'] ?? []) as $t) {
            $tn = gdm_normalize($t);
            if ($tn !== '' && str_contains($hayNamePad, ' ' . $tn . ' ')) $locHits++;
        }
        if ($locHits > 0) {
            $score += min(60, $locHits * 35);
            $reasons[] = 'nom locataire';
        }

        // (2bis) Numéro de lot présent (indice FORT : présent dans bien ET diag)
        $lotHit = false;
        foreach (($ctx['lot_tokens'] ?? []) as $t) {
            // match par mot entier (un lot "12" ne doit pas matcher "122")
            if ($t !== '' && str_contains(' ' . $hayAll . ' ', ' ' . gdm_normalize($t) . ' ')) {
                $lotHit = true; break;
            }
        }
        if ($lotHit) {
            $score += 35;
            $reasons[] = 'n° lot';
        }

        // (3) Tokens propriétaire présents
        $propHits = 0;
        foreach (($ctx['proprio_tokens'] ?? []) as $t) {
            if ($t !== '' && str_contains($hayAll, $t)) $propHits++;
        }
        if ($propHits > 0) {
            $score += min(30, $propHits * 15);
            $reasons[] = 'propriétaire';
        }

        // (4) Bonus PDF (les diagnostics sont quasi toujours en PDF)
        if (str_ends_with($hayName, ' pdf') || str_ends_with(strtolower($name), '.pdf')) {
            $score += 5;
        }

        return ['score' => $score, 'reasons' => $reasons];
    }
}
