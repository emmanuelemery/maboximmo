<?php
declare(strict_types=1);

/**
 * OCR pour PDF scannés via GPT-4o Vision
 *
 * Pipeline :
 *  1. pdftoppm convertit chaque page du PDF en JPEG (200 DPI)
 *  2. Chaque image est envoyée à GPT-4o Vision avec un prompt qui :
 *     - lit l'image
 *     - extrait le texte (OCR)
 *     - structure les données en JSON
 *  3. Les résultats des pages sont fusionnés
 *
 * Coût indicatif : ~0.005 $ par page (gpt-4o vision input)
 */
class BienIntakeOCR
{
    private const PDFTOPPM_PATHS = [
        'C:\\poppler\\Library\\bin\\pdftoppm.exe',
        '/usr/bin/pdftoppm',
        '/usr/local/bin/pdftoppm',
    ];

    private const MAX_PAGES = 8;          // Limite pour éviter les coûts excessifs
    private const DPI       = 200;        // Bon compromis qualité / poids
    private const JPEG_Q    = 80;         // Qualité JPEG après conversion

    /**
     * Convertit un PDF en images JPEG (1 par page).
     * Retourne un tableau de chemins absolus vers les images générées.
     *
     * @param string $pdfPath  Chemin du PDF source
     * @param string $tmpDir   Dossier temporaire (sera créé si manquant)
     * @param int    $maxPages Limite de pages à convertir
     * @return string[] Liste des chemins vers les JPEG
     */
    public static function pdfToImages(string $pdfPath, string $tmpDir, int $maxPages = self::MAX_PAGES): array
    {
        $exe = self::findPdfToPpm();
        if (!$exe) {
            throw new RuntimeException('pdftoppm introuvable — Poppler doit être installé');
        }
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true)) {
            throw new RuntimeException('Impossible de créer le dossier temporaire OCR');
        }

        $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';
        $cmd = sprintf(
            '"%s" -jpeg -jpegopt quality=%d -r %d -f 1 -l %d "%s" "%s"',
            $exe,
            self::JPEG_Q,
            self::DPI,
            $maxPages,
            $pdfPath,
            $prefix
        );

        $output = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('pdftoppm a échoué : ' . implode("\n", $output));
        }

        // Récupère les fichiers générés (format : page-1.jpg, page-2.jpg, …)
        $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.jpg') ?: [];
        sort($files);
        return $files;
    }

    private static function findPdfToPpm(): ?string
    {
        foreach (self::PDFTOPPM_PATHS as $p) {
            if (is_executable($p) || (PHP_OS_FAMILY === 'Windows' && file_exists($p))) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Compresse une image JPEG si elle est trop lourde (>1.5 Mo).
     * Aide à rester dans les limites de l'API OpenAI Vision.
     */
    public static function compressIfNeeded(string $imagePath, int $maxBytes = 1572864): bool
    {
        if (!is_file($imagePath)) return false;
        if (filesize($imagePath) <= $maxBytes) return true;
        if (!function_exists('imagecreatefromjpeg')) return false;

        $img = @imagecreatefromjpeg($imagePath);
        if (!$img) return false;

        // Réduit la résolution si nécessaire
        $w = imagesx($img); $h = imagesy($img);
        if ($w > 1800) {
            $newW = 1800;
            $newH = (int) round($h * ($newW / $w));
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }
        // Sauvegarde avec qualité réduite
        $q = 75;
        do {
            imagejpeg($img, $imagePath, $q);
            $q -= 10;
        } while (filesize($imagePath) > $maxBytes && $q >= 40);
        imagedestroy($img);
        return true;
    }

    /**
     * Analyse les images via GPT-4o Vision avec un prompt OCR + extraction structurée.
     * Reprend la même structure JSON que analyseBienIntakeIA() pour la cohérence.
     *
     * @param string[] $imagePaths Liste de chemins vers les JPEG
     * @param array       $imagePaths  Chemins des images JPEG à analyser
     * @param string|null $forceType   Type explicite (diag|bail|mandat|titre|fiche|divers)
     *                                 — ajouté au prompt pour guider l'extraction
     * @return array {ok, fields, doc_type, doc_titre, resume, error}
     */
    public static function analyseImagesIA(array $imagePaths, ?string $forceType = null): array
    {
        $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
        if (!$api_key) {
            return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'OPENAI_API_KEY non configurée'];
        }
        if (empty($imagePaths)) {
            return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'Aucune image à analyser'];
        }

        // Prompt système : OCR + extraction structurée
        $system_prompt = "Tu es un assistant expert en immobilier français qui sait LIRE les documents scannés "
            . "(images) et en extraire les données structurées. Tu réponds UNIQUEMENT avec du JSON valide.";

        // Indication du type forcé (l'utilisateur a choisi explicitement via l'UI onglets)
        $typeHint = '';
        if ($forceType !== null && $forceType !== '') {
            $typeLabels = [
                'diag'   => 'DIAGNOSTIC (DPE / plomb / amiante / électricité / gaz / termites / ERP / mesurage)',
                'bail'   => 'BAIL (habitation / commercial / professionnel / civil / terrain)',
                'mandat' => 'MANDAT (vente / gestion / location / recherche)',
                'titre'  => 'ACTE DE PROPRIÉTÉ / NOTIFICATION DE MUTATION',
                'fiche'  => 'FICHE COMMERCIALE (Hektor / Périclès / Apimo / Poliris / Netty / ICI)',
                'divers' => 'DOCUMENT DIVERS (type non catégorisé)',
            ];
            $label = $typeLabels[$forceType] ?? strtoupper($forceType);
            $typeHint = "\n\n⚠️ L'UTILISATEUR A INDIQUÉ QUE CE DOCUMENT EST DE TYPE : {$label}.\n"
                . "Optimise ton extraction pour ce type de document. Concentre-toi sur les champs "
                . "pertinents pour ce type et remplis doc_type = \"{$forceType}\" (ou un sous-type).\n";
        }

        $user_text = <<<PROMPT
Tu vas recevoir une ou plusieurs images correspondant aux pages d'un document immobilier français scanné
(DPE, mandat, dossier de diagnostics, fiche commerciale, mesurage Loi Boutin, état des risques, attestation, etc.).{$typeHint}

LIS attentivement TOUTES les images (OCR), puis extrais TOUTES les données utiles.
Réponds UNIQUEMENT avec du JSON valide, structure exacte ci-dessous (null si absent) :

{
  "doc_type": "dpe|dossier_diagnostics|mandat_gestion|mandat_vente|mandat_location|fiche_commerciale|titre_propriete|mesurage_boutin|mesurage_carrez|etat_risques|attestation|autre",
  "doc_titre": "string court ou null",
  "doc_date": "YYYY-MM-DD ou null",
  "_bien": {
    "type_bien": "appartement|maison|villa|immeuble|terrain|local_commercial|bureau|garage|parking|null",
    "type_typologie": "T1|T2|T3|T4|T5|T6 ou null",
    "designation": "string ou null",
    "reference_bien": "string ou null",
    "annee_construction": "nombre ou null",
    "etage": "nombre ou null",
    "lot_principal": "string ou null"
  },
  "_adresse": {
    "_commentaire": "ATTENTION : adresse du BIEN IMMOBILIER, PAS du propriétaire ni du diagnostiqueur",
    "adresse_1": "string ou null",
    "adresse_2": "string ou null",
    "code_postal": "5 chiffres ou null",
    "ville": "string ou null",
    "pays": "string ou null"
  },
  "_surfaces": {
    "surface_habitable": "décimal ou null",
    "surface_carrez": "décimal ou null",
    "surface_sejour": "décimal ou null",
    "surface_terrain": "décimal ou null",
    "surface_balcon": "décimal ou null",
    "surface_terrasse": "décimal ou null",
    "surface_jardin": "décimal ou null",
    "surface_cave": "décimal ou null",
    "surface_garage": "décimal ou null"
  },
  "_pieces": {
    "nb_pieces": "entier ou null",
    "nb_chambres": "entier ou null",
    "nb_salles_bain": "entier ou null",
    "nb_salles_eau": "entier ou null",
    "nb_wc": "entier ou null"
  },
  "_dpe": {
    "dpe_classe": "A-G ou null",
    "ges_classe": "A-G ou null",
    "dpe_valeur": "entier ou null",
    "ges_valeur": "entier ou null",
    "dpe_valeur_conso_primaire": "nombre ou null",
    "dpe_valeur_conso_finale": "nombre ou null",
    "date_indice_prix_energies": "YYYY-MM-DD ou null",
    "altitude": "entier (m) ou null",
    "dpe_date_realisation": "YYYY-MM-DD ou null",
    "dpe_version": "2011|2021 ou null",
    "dpe_vierge": "true|false",
    "dpe_reference_certificat": "string (n° ADEME) ou null",
    "montant_estime_depenses_min": "nombre ou null",
    "montant_estime_depenses_max": "nombre ou null"
  },
  "_chauffage_energie": {
    "chauffage_type": "individuel|collectif|null",
    "chauffage_energie": "electricite|gaz|fioul|bois|granules|pompe a chaleur|solaire|null",
    "eau_chaude_type": "individuel|collectif|null",
    "double_vitrage": "true|false",
    "volets_roulants": "true|false",
    "menuiseries": "bois|pvc|aluminium|mixte|null"
  },
  "_alertes_diag": {
    "plomb_present": "true|false",
    "amiante_present": "true|false",
    "electricite_anomalies": "true|false",
    "gaz_anomalies": "true|false",
    "termites": "true|false",
    "zone_georisque": "true|false"
  },
  "_proprietaire": {
    "nom": "string ou null",
    "prenom": "string ou null",
    "civilite": "M.|Mme|null",
    "type_personne": "physique|morale|null",
    "societe": "string (raison sociale SCI/SARL) ou null",
    "adresse_1": "string ou null",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "email": "string ou null",
    "telephone": "string ou null"
  },
  "_mandat": {
    "numero_mandat": "string ou null",
    "type_mandat": "vente|location|gestion|recherche ou null",
    "nature_mandat": "simple|exclusif|semi ou null",
    "date_signature": "YYYY-MM-DD ou null",
    "date_debut": "YYYY-MM-DD ou null",
    "date_fin": "YYYY-MM-DD ou null",
    "honoraires": "nombre ou null",
    "honoraires_charge": "acquereur|vendeur|locataire|bailleur|null"
  },
  "_prix": {
    "prix_vente": "nombre ou null",
    "loyer_hc": "nombre ou null",
    "charges_locatives": "nombre ou null",
    "depot_garantie": "nombre ou null"
  },
  "_diagnostiqueur": {
    "operateur_nom": "string ou null",
    "operateur_societe": "string ou null",
    "dossier_numero": "string ou null"
  },
  "_resume": "2-4 phrases résumant le contenu et ce qui a été extrait"
}

RÈGLES :

⚠️ DISTINCTION DES ADRESSES (CRITIQUE) — Un document immobilier contient souvent plusieurs adresses :
  • Adresse du BIEN immobilier → mettre dans _adresse
  • Adresse du PROPRIÉTAIRE (domicile, siège SCI) → mettre dans _proprietaire.adresse_1
  • Adresse du DIAGNOSTIQUEUR / EXPERT / AGENCE → mettre dans _diagnostiqueur uniquement
NE LES CONFONDS JAMAIS. L'adresse du bien est dans la section "Adresse du bien", "Désignation", "Logement", "Bien immobilier".
Si tu vois deux codes postaux différents, c'est typique : un pour le bien, un pour le propriétaire.

- Pour "type_typologie" : T4 → nb_pieces=4
- Pour "annee_construction" : "Avant 1948" → 1948
- Dates au format YYYY-MM-DD strict
- N'INVENTE RIEN. null si tu n'es pas sûr.
PROMPT;

        // Construction du contenu multimodal (texte + N images)
        $content = [
            ['type' => 'text', 'text' => $user_text],
        ];
        foreach ($imagePaths as $imgPath) {
            if (!is_file($imgPath)) continue;
            self::compressIfNeeded($imgPath);
            $imageData = @file_get_contents($imgPath);
            if (!$imageData) continue;
            $base64 = base64_encode($imageData);
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url'    => 'data:image/jpeg;base64,' . $base64,
                    'detail' => 'high', // 'high' = meilleure OCR ; 'low' = + économique
                ],
            ];
        }
        if (count($content) < 2) {
            return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'Aucune image lisible'];
        }

        $payload = json_encode([
            'model'    => 'gpt-4o', // gpt-4o pour vision (gpt-4o-mini moins précis sur OCR)
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $content],
            ],
            'max_tokens'  => 3500,
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_TIMEOUT        => 180, // OCR Vision = plus long
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'Réseau OCR : ' . $curl_error];
        }
        $data = json_decode($response, true);
        if ($http_code !== 200) {
            return [
                'ok' => false, 'fields' => [], 'doc_type' => null,
                'error' => 'OpenAI Vision : ' . ($data['error']['message'] ?? "HTTP $http_code"),
            ];
        }
        $rawContent = $data['choices'][0]['message']['content'] ?? '';
        $parsed = json_decode($rawContent, true);
        if (!is_array($parsed) && preg_match('/\{[\s\S]+\}/m', $rawContent, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (!is_array($parsed)) {
            return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'JSON OCR non parsable'];
        }

        // Aplatissement (même logique que analyseBienIntakeIA)
        $docType  = $parsed['doc_type'] ?? null;
        $docTitre = $parsed['doc_titre'] ?? null;
        $docDate  = $parsed['doc_date'] ?? null;
        $resume   = $parsed['_resume'] ?? null;

        // Aplatissement avec préfixes (cohérent avec bien_intake_ia.php)
        $sectionPrefixes = [
            '_proprietaire'   => 'proprio_',
            '_diagnostiqueur' => 'diag_',
        ];
        $flat = [];
        foreach ($parsed as $section => $val) {
            if (in_array($section, ['doc_type','doc_titre','doc_date','_resume'], true)) continue;
            if (!is_array($val)) continue;
            $prefix = $sectionPrefixes[$section] ?? '';
            foreach ($val as $k => $v) {
                if ($k === '_commentaire' || str_starts_with((string)$k, '_')) continue;
                $flat[$prefix . $k] = $v;
            }
        }

        $boolFields = [
            'dpe_vierge','double_vitrage','volets_roulants',
            'plomb_present','amiante_present','electricite_anomalies',
            'gaz_anomalies','termites','zone_georisque',
        ];

        $dateFields = [
            'dpe_date_realisation','date_signature','date_debut','date_fin',
            'date_indice_prix_energies',
        ];

        $intFields = [
            'annee_construction','etage','nb_pieces','nb_chambres','nb_salles_bain','nb_salles_eau','nb_wc',
            'dpe_valeur','ges_valeur','altitude',
        ];

        $floatFields = [
            'surface_habitable','surface_carrez','surface_sejour','surface_terrain',
            'surface_balcon','surface_terrasse','surface_jardin','surface_cave','surface_garage',
            'montant_estime_depenses_min','montant_estime_depenses_max',
            'dpe_valeur_conso_primaire','dpe_valeur_conso_finale',
            'prix_vente','loyer_hc','charges_locatives','depot_garantie','honoraires',
        ];

        $toFloat = static function ($v): ?float {
            if (is_int($v) || is_float($v)) return (float)$v;
            $s = trim((string)$v);
            if ($s === '') return null;
            $s = str_replace(["\xC2\xA0", ' '], '', $s);
            $s = str_replace(',', '.', $s);
            $s = preg_replace('/[^0-9\.\-]/', '', $s);
            if ($s === '' || $s === '-' || $s === '.') return null;
            return is_numeric($s) ? (float)$s : null;
        };

        $toInt = static function ($v) use ($toFloat): ?int {
            $f = $toFloat($v);
            if ($f === null) return null;
            return (int)round($f);
        };

        $toDate = static function ($v): ?string {
            $s = trim((string)$v);
            if ($s === '') return null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
            if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) {
                return $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            return null;
        };
        $fields = [];
        foreach ($flat as $k => $v) {
            if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;
            if (in_array($k, ['dpe_classe','ges_classe'], true)) {
                $vRaw = trim((string)$v);
                if (preg_match('/^vierge$/i', $vRaw)) {
                    $v = 'vierge';
                } else {
                    $v = strtoupper($vRaw);
                    if (!preg_match('/^[A-G]$/', $v)) continue;
                }
            }
            if (in_array($k, $boolFields, true)) {
                $v = ($v === true || $v === 'true' || $v === 1 || $v === '1') ? 1 : 0;
                if ($v === 0) continue;
            }
            if (in_array($k, $dateFields, true)) {
                $d = $toDate($v);
                if ($d === null) continue;
                $v = $d;
            }
            if (in_array($k, $intFields, true)) {
                $n = $toInt($v);
                if ($n === null) continue;
                $v = $n;
            } elseif (in_array($k, $floatFields, true)) {
                $n = $toFloat($v);
                if ($n === null) continue;
                $v = $n;
            }
            if ($k === 'type_typologie' && preg_match('/^T(\d)/i', (string)$v, $mm)) {
                if (empty($flat['nb_pieces'])) $fields['nb_pieces'] = (int)$mm[1];
                continue;
            }
            $fields[$k] = $v;
        }

        return [
            'ok'        => true,
            'doc_type'  => $docType,
            'doc_titre' => $docTitre,
            'doc_date'  => $docDate,
            'resume'    => $resume,
            'fields'    => $fields,
            'count'     => count($fields),
            'pages'     => count($imagePaths),
            'method'    => 'ocr_vision',
        ];
    }

    /**
     * Nettoie les fichiers temporaires d'OCR (à appeler après analyse).
     */
    public static function cleanupTmpDir(string $tmpDir): void
    {
        if (!is_dir($tmpDir)) return;
        foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);
    }
}

