<?php
declare(strict_types=1);

/**
 * BienImportMapper — Moteur d'analyse et de mapping
 *
 * Analyse un texte extrait d'une fiche PDF et tente de mapper
 * le maximum de champs vers la structure BDD des biens.
 *
 * Retourne un tableau structuré :
 * [
 *   'champs'      => [...],   // champs mappés avec valeur + score
 *   'score'       => 0-100,   // score global
 *   'alertes'     => [...],   // incohérences détectées
 *   'manquants'   => [...],   // champs obligatoires non détectés
 *   'deduits'     => [...],   // champs déduits du descriptif
 *   'description' => '...',   // description nettoyée suggérée
 * ]
 */
class BienImportMapper
{
    // ── Poids des champs pour le score global ────────────────────
    private const POIDS = [
        'type_offre'    => 20,
        'type_bien'     => 15,
        'surface'       => 15,
        'prix'          => 15,
        'ville'         => 10,
        'code_postal'   => 5,
        'adresse'       => 5,
        'description'   => 5,
        'pieces'        => 5,
        'reference'     => 5,
    ];

    // ── Champs obligatoires ──────────────────────────────────────
    private const OBLIGATOIRES = ['type_offre','type_bien','surface','prix','ville'];

    // ────────────────────────────────────────────────────────────
    public static function analyse(string $texte, array $contexteSociete = []): array
    {
        $t = $texte; // texte de travail (case insensitive dans les regex)

        $champs   = [];
        $alertes  = [];
        $deduits  = [];

        // ── 1. Type d'offre ──────────────────────────────────────
        $offre = self::detectOffre($t);
        if ($offre) {
            $champs['type_offre'] = ['valeur' => $offre['valeur'], 'score' => $offre['confiance'], 'source' => $offre['extrait']];
        }

        // ── 2. Type de bien ──────────────────────────────────────
        $tb = self::detectTypeBien($t);
        if ($tb) {
            $champs['type_bien'] = ['valeur' => $tb['valeur'], 'score' => $tb['confiance'], 'source' => $tb['extrait']];
        }

        // ── 3. Surface ───────────────────────────────────────────
        $surfaces = self::detectSurfaces($t);
        if (!empty($surfaces['habitable'])) {
            $champs['surface_habitable'] = ['valeur' => $surfaces['habitable'], 'score' => 'eleve', 'source' => $surfaces['extrait_habitable'] ?? ''];
        }
        if (!empty($surfaces['terrain'])) {
            $champs['surface_terrain'] = ['valeur' => $surfaces['terrain'], 'score' => 'eleve', 'source' => $surfaces['extrait_terrain'] ?? ''];
        }
        if (!empty($surfaces['carrez'])) {
            $champs['surface_carrez'] = ['valeur' => $surfaces['carrez'], 'score' => 'eleve', 'source' => ''];
        }
        // Alerte si plusieurs surfaces ambiguës
        if (count($surfaces['toutes'] ?? []) > 3) {
            $alertes[] = ['niveau' => 'warning', 'champ' => 'surface', 'message' => 'Plusieurs surfaces détectées — vérifiez laquelle est la surface habitable.'];
        }

        // ── 4. Prix / loyer ──────────────────────────────────────
        $prix = self::detectPrix($t, $offre['valeur'] ?? '');
        if (!empty($prix)) {
            $key = ($offre['valeur'] ?? '') === 'location' ? 'loyer' : 'prix_vente';
            $champs[$key] = ['valeur' => $prix['valeur'], 'score' => $prix['confiance'], 'source' => $prix['extrait']];
            if (!empty($prix['charges'])) {
                $champs['charges_loc'] = ['valeur' => $prix['charges'], 'score' => 'moyen', 'source' => ''];
            }
            if (count($prix['tous'] ?? []) > 1) {
                $alertes[] = ['niveau' => 'warning', 'champ' => 'prix', 'message' => 'Plusieurs montants détectés : ' . implode(', ', array_map(fn($p) => number_format($p, 0, ',', ' ') . ' €', $prix['tous'])) . '. Vérifiez le prix principal.'];
            }
        }

        // ── 5. Localisation ──────────────────────────────────────
        $loc = self::detectLocalisation($t);
        if (!empty($loc['ville']))       $champs['ville']       = ['valeur' => $loc['ville'],       'score' => $loc['conf_ville'],  'source' => $loc['extrait_ville'] ?? ''];
        if (!empty($loc['code_postal'])) $champs['code_postal'] = ['valeur' => $loc['code_postal'], 'score' => 'eleve',            'source' => $loc['extrait_cp'] ?? ''];
        if (!empty($loc['adresse']))     $champs['adresse_1']   = ['valeur' => $loc['adresse'],     'score' => $loc['conf_adr'],   'source' => $loc['extrait_adr'] ?? ''];
        if (!empty($loc['quartier']))    $champs['quartier']    = ['valeur' => $loc['quartier'],    'score' => 'moyen',            'source' => ''];

        // ── 6. Pièces ────────────────────────────────────────────
        $pieces = self::detectPieces($t);
        if ($pieces) {
            $champs['nb_pieces']  = ['valeur' => $pieces['pieces'],  'score' => $pieces['confiance'], 'source' => $pieces['extrait']];
            if (!empty($pieces['chambres'])) {
                $champs['nb_chambres'] = ['valeur' => $pieces['chambres'], 'score' => 'eleve', 'source' => ''];
            }
        }

        // ── 7. Référence ─────────────────────────────────────────
        $ref = self::detectReference($t);
        if ($ref) {
            $champs['reference_bien'] = ['valeur' => $ref['valeur'], 'score' => 'moyen', 'source' => $ref['extrait']];
        }

        // ── 8. Étage ─────────────────────────────────────────────
        $etage = self::detectEtage($t);
        if ($etage !== null) {
            $champs['etage'] = ['valeur' => $etage, 'score' => 'moyen', 'source' => ''];
        }

        // ── 9. Année de construction ─────────────────────────────
        if (preg_match('/(?:construit|construction|bâti|réalisé)(?:[^0-9]{1,30})(1[89]\d{2}|20[012]\d)/i', $t, $m)) {
            $champs['annee_construction'] = ['valeur' => (int)$m[1], 'score' => 'moyen', 'source' => trim($m[0])];
        }

        // ── 10. Déduits du descriptif ────────────────────────────
        $deduits = self::deduireDepuisDescriptif($t);

        // ── 11. Description suggérée ─────────────────────────────
        $description = self::extraireDescription($t);

        // ── 11b. Propriétaire ─────────────────────────────────────
        $proprietaire = self::detectProprietaire($t);

        // ── 11c. Référence dossier / mandat ──────────────────────
        if (empty($champs['reference_bien'])) {
            if (preg_match('/n[°o]\s+de\s+(?:dossier|mandat)\s*:?\s*(\d+)/i', $t, $m)) {
                $champs['reference_bien'] = ['valeur' => $m[1], 'score' => 'eleve', 'source' => trim($m[0])];
            }
        }

        // ── 11d. Reprise descriptif intégral ─────────────────────
        $repriseDescriptif = self::extraireRepriseDescriptif($t);

        // ── 12. Champs obligatoires manquants ────────────────────
        $manquants = [];
        foreach (self::OBLIGATOIRES as $champ) {
            $found = match($champ) {
                'prix'   => isset($champs['prix_vente']) || isset($champs['loyer']),
                'surface'=> isset($champs['surface_habitable']) || isset($champs['surface_terrain']),
                default  => isset($champs[$champ]),
            };
            if (!$found) $manquants[] = $champ;
        }

        // ── 13. Score global ─────────────────────────────────────
        $score = self::calculerScore($champs);

        // Incohérence type offre/prix
        if (isset($champs['type_offre'], $champs['prix_vente'])) {
            if ($champs['type_offre']['valeur'] === 'location') {
                $alertes[] = ['niveau' => 'warning', 'champ' => 'type_offre', 'message' => 'Type d\'offre "location" mais un prix de vente a été détecté — vérifiez.'];
            }
        }

        return [
            'champs'             => $champs,
            'score'              => $score,
            'alertes'            => $alertes,
            'manquants'          => $manquants,
            'deduits'            => $deduits,
            'description'        => $description,
            'proprietaire'       => $proprietaire,
            'reprise_descriptif' => $repriseDescriptif,
        ];
    }

    // ── Détection type d'offre ───────────────────────────────────
    private static function detectOffre(string $t): ?array
    {
        // Format structuré : "Type d'offre : Vente" (fiches Hektor, etc.)
        if (preg_match("/type\s+d['']offre\s*:?\s*(vente|location)/iu", $t, $m)) {
            $val = mb_strtolower(trim($m[1]));
            return ['valeur' => $val, 'confiance' => 'eleve', 'extrait' => trim($m[0])];
        }

        $patterns = [
            'vente'    => ['/\b(?:vente|à vendre|en vente|vendu|cession|vend)\b/i',      'eleve'],
            'location' => ['/\b(?:location|à louer|en location|louer|loué|bail|loyer)\b/i','eleve'],
        ];
        $scores = [];
        foreach ($patterns as $type => [$pat, $conf]) {
            if (preg_match($pat, $t, $m)) {
                $scores[$type] = ['valeur' => $type, 'confiance' => $conf, 'extrait' => trim($m[0])];
            }
        }
        if (empty($scores)) return null;
        if (isset($scores['vente'], $scores['location'])) {
            $cv = preg_match_all('/\b(?:vente|à vendre)\b/i', $t);
            $cl = preg_match_all('/\b(?:loyer|location|bail|€\/mois)\b/i', $t);
            return $cl > $cv ? $scores['location'] : $scores['vente'];
        }
        return reset($scores);
    }

    // ── Détection type de bien ───────────────────────────────────
    private static function detectTypeBien(string $t): ?array
    {
        // Format structuré : "Type de bien : Parking" (Hektor et similaires)
        if (preg_match('/type\s+de\s+bien\s*:?\s*([a-záàâéèêëîïôùûüœç\s_\-]+)/iu', $t, $m)) {
            $raw = mb_strtolower(trim($m[1]));
            $map = [
                'appartement' => 'appartement', 'appart' => 'appartement',
                'maison' => 'maison', 'villa' => 'maison', 'pavillon' => 'maison',
                'terrain' => 'terrain',
                'local commercial' => 'local_commercial', 'commerce' => 'local_commercial',
                'bureau' => 'bureau',
                'parking' => 'parking', 'stationnement' => 'parking', 'box' => 'parking',
                'garage' => 'garage',
                'immeuble' => 'immeuble',
            ];
            foreach ($map as $k => $v) {
                if (str_contains($raw, $k)) return ['valeur' => $v, 'confiance' => 'eleve', 'extrait' => trim($m[0])];
            }
        }

        // Détection par en-tête de section : "DESCRIPTION PARKING" / "INFORMATION PARKING"
        if (preg_match('/^(?:DESCRIPTION|INFORMATION)\s+PARKING\b/im', $t)) {
            return ['valeur' => 'parking', 'confiance' => 'eleve', 'extrait' => 'Section PARKING détectée'];
        }
        if (preg_match('/^DESCRIPTION\s+APPARTEMENT\b/im', $t)) {
            return ['valeur' => 'appartement', 'confiance' => 'eleve', 'extrait' => 'Section APPARTEMENT détectée'];
        }
        if (preg_match('/^DESCRIPTION\s+MAISON\b/im', $t)) {
            return ['valeur' => 'maison', 'confiance' => 'eleve', 'extrait' => 'Section MAISON détectée'];
        }
        if (preg_match('/^DESCRIPTION\s+TERRAIN\b/im', $t)) {
            return ['valeur' => 'terrain', 'confiance' => 'eleve', 'extrait' => 'Section TERRAIN détectée'];
        }

        $patterns = [
            'appartement'     => ['/\b(?:appartement|appart\.?|studio|duplex|triplex|T[1-9]\b|F[1-9]\b|type\s*[1-9])\b/i', 'eleve'],
            'maison'          => ['/\b(?:maison|villa|pavillon|propriété|corps de ferme|bastide|chalet|mas)\b/i', 'eleve'],
            'terrain'         => ['/\b(?:terrain|parcelle|lot à bâtir|terrain constructible)\b/i', 'eleve'],
            'local_commercial' => ['/\b(?:local commercial|fond[s]? de commerce|boutique|commerce|enseigne)\b/i', 'eleve'],
            'bureau'          => ['/\b(?:bureau[x]?|plateau de bureau|open-?space|espace de travail)\b/i', 'eleve'],
            'garage'          => ['/\b(?:garage|box fermé|box auto)\b/i', 'moyen'],
            'parking'         => ['/\b(?:place de stationnement|parking|place de parking)\b/i', 'moyen'],
            'immeuble'        => ['/\b(?:immeuble|immeuble de rapport|immeuble entier)\b/i', 'eleve'],
            'loft'            => ['/\b(?:loft|atelier aménagé|espace atypique)\b/i', 'eleve'],
        ];
        $best    = null;
        $bestPrio = 0;
        $prio = ['appartement'=>8,'maison'=>8,'immeuble'=>7,'terrain'=>7,'local_commercial'=>7,'bureau'=>7,'loft'=>7,'garage'=>5,'parking'=>5];
        foreach ($patterns as $type => [$pat, $conf]) {
            if (preg_match($pat, $t, $m)) {
                $p = $prio[$type] ?? 5;
                if ($p > $bestPrio) {
                    $bestPrio = $p;
                    $best = ['valeur' => $type, 'confiance' => $conf, 'extrait' => trim($m[0])];
                }
            }
        }
        return $best;
    }

    // ── Détection surfaces ───────────────────────────────────────
    private static function detectSurfaces(string $t): array
    {
        $res = ['toutes' => []];

        // Toutes les occurrences de "XX m²"
        preg_match_all('/(\d[\d\s]*(?:[.,]\d+)?)\s*m[²2]/u', $t, $all);
        foreach ($all[1] as $v) {
            $val = (float)str_replace([' ',','], ['','.'], $v);
            if ($val >= 5 && $val <= 50000) $res['toutes'][] = $val;
        }

        // Surface habitable : mot-clé + valeur
        if (preg_match('/surface\s+(?:habitable|loi\s+carrez|séjour)?\s*:?\s*(\d[\d\s]*(?:[.,]\d+)?)\s*m[²2]/iu', $t, $m)) {
            $res['habitable'] = (float)str_replace([' ',','], ['','.'], $m[1]);
            $res['extrait_habitable'] = trim($m[0]);
        } elseif (!empty($res['toutes'])) {
            // La plus grande surface plausible comme habitable par défaut
            arsort($res['toutes']);
            $v = reset($res['toutes']);
            if ($v >= 10 && $v <= 2000) { $res['habitable'] = $v; }
        }

        // Terrain
        if (preg_match('/terrain\s*:?\s*(\d[\d\s]*(?:[.,]\d+)?)\s*m[²2]/iu', $t, $m)) {
            $res['terrain'] = (float)str_replace([' ',','], ['','.'], $m[1]);
            $res['extrait_terrain'] = trim($m[0]);
        } elseif (preg_match('/(\d[\d\s]*(?:[.,]\d+)?)\s*(?:m[²2]|ha)\s+(?:de\s+)?terrain/iu', $t, $m)) {
            $res['terrain'] = (float)str_replace([' ',','], ['','.'], $m[1]);
        }

        // Carrez
        if (preg_match('/(?:carrez|loi\s+carrez)[^0-9]{0,20}(\d[\d\s]*(?:[.,]\d+)?)\s*m[²2]/iu', $t, $m)) {
            $res['carrez'] = (float)str_replace([' ',','], ['','.'], $m[1]);
        }

        return $res;
    }

    // ── Détection prix ───────────────────────────────────────────
    private static function detectPrix(string $t, string $offre): array
    {
        $res = ['tous' => []];

        // Patterns prix : "250 000 €" / "250000€" / "250 000 euros"
        preg_match_all('/(\d[\d\s]{1,8}(?:[.,]\d+)?)\s*(?:€|euros?|EUR)\b/ui', $t, $all);
        foreach ($all[1] as $v) {
            $val = (float)str_replace([' ',','], ['','.'], $v);
            if ($val >= 500 && $val <= 50_000_000) $res['tous'][] = $val;
        }

        // Loyer mensuel : "X €/mois" ou "X €/mois CC"
        if ($offre === 'location' || preg_match('/€\s*\/\s*mois/i', $t)) {
            if (preg_match('/(\d[\d\s]*(?:[.,]\d+)?)\s*(?:€|euros?)\s*(?:\/\s*mois|par\s+mois|mensuel)/iu', $t, $m)) {
                $val = (float)str_replace([' ',','], ['','.'], $m[1]);
                if ($val >= 100 && $val <= 50_000) {
                    $res['valeur']    = $val;
                    $res['confiance'] = 'eleve';
                    $res['extrait']   = trim($m[0]);
                }
            }
            // Charges
            if (preg_match('/charges?\s*:?\s*(\d[\d\s]*(?:[.,]\d+)?)\s*(?:€|euros?)/iu', $t, $m)) {
                $res['charges'] = (float)str_replace([' ',','], ['','.'], $m[1]);
            }
        }

        // Format structuré : "Prix vente public : 21 000,00 €" (prioritaire)
        if (!isset($res['valeur'])) {
            if (preg_match('/prix\s+(?:vente\s+)?(?:public|net\s+vendeur|de\s+vente)?\s*:?\s*([\d\s]+(?:[.,]\d+)?)\s*(?:€|euros?)/ui', $t, $m)) {
                $val = (float)str_replace([' ',','], ['','.'], $m[1]);
                if ($val >= 1_000) {
                    $res['valeur']    = $val;
                    $res['confiance'] = 'eleve';
                    $res['extrait']   = trim($m[0]);
                }
            }
        }

        if (!isset($res['valeur']) && !empty($res['tous'])) {
            rsort($res['tous']);
            foreach ($res['tous'] as $v) {
                if ($v >= 10_000) { $res['valeur'] = $v; $res['confiance'] = 'moyen'; $res['extrait'] = $v . ' €'; break; }
            }
        }

        return $res;
    }

    // ── Détection localisation ───────────────────────────────────
    private static function detectLocalisation(string $t): array
    {
        $res = [];

        // Format structuré "Adresse du bien : 15 avenue Pasteur" (Hektor)
        if (preg_match('/adresse\s+du\s+bien\s*:?\s*(.+)/ui', $t, $m)) {
            $adr = trim($m[1]);
            if (strlen($adr) >= 4 && strlen($adr) <= 120) {
                $res['adresse']    = $adr;
                $res['conf_adr']   = 'eleve';
                $res['extrait_adr']= trim($m[0]);
            }
        }

        // Format structuré "Ville : Chamalières 63400" (ville + CP sur même ligne)
        if (preg_match('/(?:^|\n)\s*ville\s*:?\s*([A-ZÀ-Ÿa-zà-ÿ][A-ZÀ-Ÿa-zà-ÿ\s\-\']{1,40})\s+(\d{5})\b/uim', $t, $m)) {
            $res['ville']        = trim($m[1]);
            $res['code_postal']  = $m[2];
            $res['conf_ville']   = 'eleve';
            $res['extrait_ville']= trim($m[0]);
            $res['extrait_cp']   = $m[2];
        }
        // Format "Ville : Chamalières" + CP séparé sur ligne suivante
        elseif (preg_match('/(?:^|\n)\s*ville\s*:?\s*([A-ZÀ-Ÿa-zà-ÿ][A-ZÀ-Ÿa-zà-ÿ\s\-\']{1,40})/uim', $t, $m)) {
            $res['ville']       = trim($m[1]);
            $res['conf_ville']  = 'eleve';
            $res['extrait_ville']= trim($m[0]);
        }

        // Code postal seul si pas encore trouvé
        if (empty($res['code_postal'])) {
            if (preg_match('/\b(0[1-9]|[1-9]\d)\d{3}\b/', $t, $m)) {
                $res['code_postal'] = $m[0];
                $res['extrait_cp']  = $m[0];
            }
        }

        // Ville après code postal (fallback)
        if (empty($res['ville']) && !empty($res['code_postal'])) {
            if (preg_match('/' . preg_quote($res['code_postal'], '/') . '\s+([A-ZÀ-Ÿ][a-zà-ÿA-ZÀ-Ÿ\s\-]{2,30})/u', $t, $m)) {
                $res['ville']       = trim($m[1]);
                $res['conf_ville']  = 'eleve';
                $res['extrait_ville']= trim($m[0]);
            }
        }
        if (empty($res['ville'])) {
            if (preg_match('/\bà\s+([A-ZÀ-Ÿ][a-zà-ÿA-ZÀ-Ÿ\s\-]{2,25})\b/u', $t, $m)) {
                $res['ville']       = trim($m[1]);
                $res['conf_ville']  = 'moyen';
                $res['extrait_ville']= trim($m[0]);
            }
        }

        // Adresse (numéro + voie) — fallback si format structuré non trouvé
        if (empty($res['adresse'])) {
            if (preg_match('/\b(\d{1,4}[a-zA-Z]?\s+(?:rue|avenue|boulevard|allée|chemin|impasse|place|résidence|route|voie|cité|villa|lotissement)[^,\n]{3,60})/ui', $t, $m)) {
                $res['adresse']    = trim($m[1]);
                $res['conf_adr']   = 'moyen';
                $res['extrait_adr']= trim($m[0]);
            }
        }

        // Quartier / secteur — exclut les titres de section en majuscules (ex: "SECTEUR ET COMMODITES")
        if (preg_match('/(?:quartier|secteur)\s*:\s*([A-ZÀ-Ÿa-zà-ÿ][A-ZÀ-Ÿa-zà-ÿ\s\-]{2,25})/u', $t, $m)) {
            $candidat = trim($m[1]);
            // Rejette si tout en majuscules (= titre de section PDF)
            if ($candidat !== mb_strtoupper($candidat)) {
                $res['quartier'] = $candidat;
            }
        }

        return $res;
    }

    // ── Détection pièces ─────────────────────────────────────────
    private static function detectPieces(string $t): ?array
    {
        $res = null;
        // "T3", "F3", "3 pièces", "3P"
        if (preg_match('/\b(?:T|F|type\s*)([1-9])\b/i', $t, $m)) {
            $res = ['pieces' => (int)$m[1], 'confiance' => 'eleve', 'extrait' => $m[0]];
        } elseif (preg_match('/(\d)\s*pièces?/i', $t, $m)) {
            $res = ['pieces' => (int)$m[1], 'confiance' => 'eleve', 'extrait' => $m[0]];
        } elseif (preg_match('/\b(\d)\s*P\b/', $t, $m)) {
            $res = ['pieces' => (int)$m[1], 'confiance' => 'moyen', 'extrait' => $m[0]];
        }

        if ($res) {
            // Chambres
            if (preg_match('/(\d)\s+(?:chambre|chambre à coucher)/i', $t, $m)) {
                $res['chambres'] = (int)$m[1];
            }
        }
        return $res;
    }

    // ── Détection référence ──────────────────────────────────────
    private static function detectReference(string $t): ?array
    {
        if (preg_match('/(?:réf\.?|ref\.?|mandat|n°|numéro)\s*:?\s*([A-Z0-9][A-Z0-9\-_\/]{2,20})/i', $t, $m)) {
            return ['valeur' => strtoupper(trim($m[1])), 'extrait' => trim($m[0])];
        }
        return null;
    }

    // ── Détection étage ──────────────────────────────────────────
    private static function detectEtage(string $t): ?int
    {
        if (preg_match('/\b(?:rez[- ]de[- ]chaussée|RDC)\b/i', $t)) return 0;
        if (preg_match('/\b(\d{1,2})(?:er|ème|e|ième)?\s+étage/i', $t, $m)) return (int)$m[1];
        return null;
    }

    // ── Déduction depuis le descriptif ───────────────────────────
    private static function deduireDepuisDescriptif(string $t): array
    {
        $d = [];

        // Dépendances / extérieurs
        $keywords = [
            'balcon'             => '/\bbalcon\b/i',
            'terrasse'           => '/\bterrasse\b/i',
            'jardin'             => '/\bjardin\b/i',
            'cave'               => '/\bcave\b/i',
            'grenier'            => '/\bgrenier\b/i',
            'garage'             => '/\bgarage\b/i',
            'parking'            => '/\bparking\b|\bstationnement\b/i',
            'piscine'            => '/\bpiscine\b/i',
            'veranda'            => '/\bvéranda\b|\bveranda\b/i',
            'box'                => '/\bbox\b/i',
            'parking_interieur'  => '/\bparking (?:int[ée]rieur|souterrain|en sous-sol)\b/i',
            'terrain_attenant'   => '/\bterrain attenant\b|\bterrain de\b/i',
        ];
        foreach ($keywords as $code => $pat) {
            if (preg_match($pat, $t)) $d['dependances'][$code] = true;
        }

        // Chauffage / énergie
        $chauffage = [];
        if (preg_match('/\bgaz\b/i', $t))                         $chauffage[] = 'gaz';
        if (preg_match('/\bélectrique|électricité\b/i', $t))      $chauffage[] = 'electricite';
        if (preg_match('/\bfioul\b/i', $t))                       $chauffage[] = 'fioul';
        if (preg_match('/\bpompe à chaleur|PAC\b/i', $t))         $chauffage[] = 'pompe_chaleur';
        if (preg_match('/\bplancher chauffant\b/i', $t))          $chauffage[] = 'plancher_chauffant';
        if (preg_match('/\bchaudière\b/i', $t))                   $chauffage[] = 'chaudiere';
        if (preg_match('/\bpoêle\b/i', $t))                       $chauffage[] = 'poele';
        if (preg_match('/\bcheminée\b/i', $t))                    $chauffage[] = 'cheminee';
        if (!empty($chauffage)) $d['chauffage'] = $chauffage;

        // Bâtiment / résidence
        if (preg_match('/\brésidence\b/i', $t))                   $d['residence'] = true;
        if (preg_match('/\bcentre[- ]ville\b|\bcentre ville\b/i', $t)) $d['centre_ville'] = true;
        if (preg_match('/\bcalme\b/i', $t))                       $d['calme'] = true;
        if (preg_match('/\bascenseur\b/i', $t))                   $d['ascenseur'] = true;
        if (preg_match('/\bdigicode|interphone|visiophone\b/i', $t)) $d['digicode'] = true;
        if (preg_match('/\brénové|rénovation|refait à neuf\b/i', $t)) $d['renove'] = true;
        if (preg_match('/\bmeublé\b/i', $t))                      $d['meuble'] = true;
        if (preg_match('/\bbeau parquet\b|\bparquet\b/i', $t))    $d['parquet'] = true;
        if (preg_match('/\bcuisine équipée|cuisine aménagée\b/i', $t)) $d['cuisine_equipee'] = true;
        if (preg_match('/\bdouble vitrage\b/i', $t))              $d['double_vitrage'] = true;

        // Vue
        $vues = [];
        if (preg_match('/\bvue dégagée\b/i', $t))    $vues[] = 'degagee';
        if (preg_match('/\bvue mer\b/i', $t))         $vues[] = 'eau';
        if (preg_match('/\bvue jardin\b/i', $t))      $vues[] = 'jardin';
        if (preg_match('/\bvue ville\b/i', $t))       $vues[] = 'ville';
        if (preg_match('/\bvue montagne\b/i', $t))    $vues[] = 'relief';
        if (preg_match('/\bvue panoramique\b/i', $t)) $vues[] = 'panoramique';
        if (preg_match('/\bsans vis[- ]à[- ]vis\b/i', $t)) $vues[] = 'sans_vis_a_vis';
        if (!empty($vues)) $d['vues'] = $vues;

        return $d;
    }

    // ── Détection propriétaire / contact ────────────────────────
    private static function detectProprietaire(string $t): array
    {
        $res = [];

        // ── Format Hektor : section PROPRIETAIRE ─────────────────
        // "Civilité : M. MOULIN FABRICE" (civilité + NOM + PRENOM sur une ligne)
        if (preg_match('/civilit[eé]\s*:?\s*(M\.?|Mme\.?|Monsieur|Madame)\s+([A-ZÀ-Ÿ\-]{2,30})\s+([A-ZÀ-Ÿa-zà-ÿ\-]{2,30})/ui', $t, $m)) {
            $civ = mb_strtolower(trim($m[1]));
            $res['civilite'] = (str_starts_with($civ, 'mme') || str_starts_with($civ, 'mad')) ? 'Mme' : 'M.';
            // Heuristique : tout en majuscules = NOM de famille
            $w1 = trim($m[2]);
            $w2 = trim($m[3]);
            if (mb_strtoupper($w1) === $w1 && mb_strtoupper($w2) !== $w2) {
                $res['nom']    = $w1;
                $res['prenom'] = ucfirst(mb_strtolower($w2));
            } elseif (mb_strtoupper($w2) === $w2 && mb_strtoupper($w1) !== $w1) {
                $res['prenom'] = ucfirst(mb_strtolower($w1));
                $res['nom']    = $w2;
            } else {
                // Les deux en majuscules : le premier est prénom (Hektor met NOM PRÉNOM)
                $res['nom']    = $w1;
                $res['prenom'] = ucfirst(mb_strtolower($w2));
            }
        }

        // ── Email ─────────────────────────────────────────────────
        // Cherche d'abord dans la section PROPRIETAIRE/CONTACT
        if (preg_match('/(?:PROPRIETAIRE|CONTACT)[^\n]*\n(?:[^\n]*\n){0,5}[^\n]*?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/ui', $t, $m)) {
            $res['email'] = $m[1];
        } elseif (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $t, $m)) {
            // Ignore les emails d'agence (riom@emery.immo, etc.) si on en a déjà un
            $res['email'] = $m[0];
        }

        // ── Téléphone ─────────────────────────────────────────────
        // "portable XXXXXXXXXX" ou "mobile XXXXXXXXXX" (format Hektor)
        if (preg_match('/(?:portable|mobile|tel\.?|tél\.?|fixe)\s*:?\s*((?:\+33\s*(?:\(0\)\s*)?|0)[1-9](?:[\s.\-]?\d{2}){4})/ui', $t, $m)) {
            $tel = preg_replace('/[\s.\-]/', '', $m[1]);
            $tel = preg_replace('/^\+33/', '0', $tel);
            $res['telephone'] = $tel;
        }
        // Fallback : téléphone générique dans section propriétaire
        if (empty($res['telephone'])) {
            $secProprio = '';
            if (preg_match('/PROPRIETAIRE\s*\n((?:[^\n]*\n){0,8})/ui', $t, $ms)) {
                $secProprio = $ms[1];
            }
            $zone = $secProprio ?: $t;
            if (preg_match('/((?:\+33\s*(?:\(0\)\s*)?|0)[1-9](?:[\s.\-]?\d{2}){4})/', $zone, $m)) {
                $tel = preg_replace('/[\s.\-]/', '', $m[1]);
                $tel = preg_replace('/^\+33/', '0', $tel);
                $res['telephone'] = $tel;
            }
        }

        // ── Nom/Prénom générique (fallback si pas de "Civilité :") ─
        if (empty($res['nom'])) {
            $marqueurs = 'propriétaire|proprietaire|bailleur|mandant|vendeur|contact|client';
            $patNom    = '/(?:' . $marqueurs . ')\s*:?\s*(?:(M\.?|Mme\.?|Monsieur|Madame)\s+)?'
                       . '([A-ZÀ-Ÿ][A-ZÀ-Ÿa-zà-ÿ\-]{1,30})\s+([A-ZÀ-Ÿ][A-ZÀ-Ÿa-zà-ÿ\-]{1,30})/ui';
            if (preg_match($patNom, $t, $m)) {
                if (!empty(trim($m[1] ?? ''))) {
                    $civ = mb_strtolower(trim($m[1]));
                    $res['civilite'] = str_contains($civ, 'mme') ? 'Mme' : 'M.';
                }
                $w1 = trim($m[2]);
                $w2 = trim($m[3]);
                if (mb_strtoupper($w1) === $w1) {
                    $res['nom'] = $w1; $res['prenom'] = ucfirst(mb_strtolower($w2));
                } else {
                    $res['prenom'] = ucfirst(mb_strtolower($w1)); $res['nom'] = mb_strtoupper($w2);
                }
            }
        }

        return $res;
    }

    // ── Reprise descriptif intégral (section DESCRIPTION) ────────
    public static function extraireRepriseDescriptif(string $t): string
    {
        // Cherche la section "DESCRIPTION" (en-tête type Hektor)
        // Peut s'appeler DESCRIPTION, DESCRIPTION PARKING, DESCRIPTION APPARTEMENT, etc.
        $patterns = [
            '/(?:^|\n)\s*DESCRIPTION(?:\s+[A-Z]+)?\s*\n((?:[^\n]+\n?){3,50})/ui',
            '/(?:^|\n)\s*DESCRIPTIF\s*\n((?:[^\n]+\n?){3,50})/ui',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $t, $m)) {
                $bloc = trim($m[1]);
                // Stoppe avant une prochaine section en majuscules
                $bloc = preg_replace('/\n[A-Z]{4,}[^\n]*$.*$/su', '', $bloc);
                $bloc = preg_replace('/\s{2,}/', ' ', $bloc);
                if (strlen($bloc) >= 80) return trim($bloc);
            }
        }
        // Fallback : plus grand bloc de texte (même algo que extraireDescription)
        $lines   = preg_split('/\n+/', $t);
        $blocs   = [];
        $courant = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 30) {
                $courant .= $line . ' ';
            } else {
                if (strlen($courant) > 120) $blocs[] = trim($courant);
                $courant = '';
            }
        }
        if (strlen($courant) > 120) $blocs[] = trim($courant);
        if (empty($blocs)) return '';
        usort($blocs, fn($a, $b) => strlen($b) - strlen($a));
        return trim($blocs[0]);
    }

    // ── Extraction description commerciale ───────────────────────
    public static function extraireDescription(string $t): string
    {
        // Cherche le plus long paragraphe qui ressemble à une description
        $lines  = preg_split('/\n+/', $t);
        $blocs  = [];
        $courant = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 40) {
                $courant .= $line . ' ';
            } else {
                if (strlen($courant) > 100) $blocs[] = trim($courant);
                $courant = '';
            }
        }
        if (strlen($courant) > 100) $blocs[] = trim($courant);

        if (empty($blocs)) return '';

        // Prend le bloc le plus long (probablement la description)
        usort($blocs, fn($a, $b) => strlen($b) - strlen($a));
        $desc = $blocs[0];

        // Nettoie les mentions purement techniques (prix, ref, etc.)
        $desc = preg_replace('/\b(?:réf\.?|ref\.?|mandat|n°)\s*:?\s*[A-Z0-9\-\/]{2,20}/i', '', $desc);
        $desc = preg_replace('/\d[\d\s]*(?:[.,]\d+)?\s*(?:€|euros?)(?:\s*\/\s*mois)?/ui', '', $desc);
        $desc = preg_replace('/\s{2,}/', ' ', $desc);

        return trim($desc);
    }

    // ── Score global ─────────────────────────────────────────────
    private static function calculerScore(array $champs): int
    {
        $score = 0;
        foreach (self::POIDS as $champ => $poids) {
            $found = match($champ) {
                'prix'   => isset($champs['prix_vente']) || isset($champs['loyer']),
                'surface'=> isset($champs['surface_habitable']) || isset($champs['surface_terrain']),
                default  => isset($champs[$champ]),
            };
            if ($found) $score += $poids;
        }
        return min(100, $score);
    }
}
