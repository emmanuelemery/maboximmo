<?php
/**
 * inc/crg_import_core.php — Cœur PARTAGÉ de l'import CRG.
 *
 * Factorise la logique commune entre le flux interactif (api/import_crg.php)
 * et le chargement de masse (api/import_crg_batch.php) pour éviter toute
 * divergence (une seule vérité : bien_baux + GED).
 *
 * 3 fonctions :
 *   - crg_parse_pdf()          : PDF → texte → analyse IA (GPT-4o) → tableau structuré
 *   - crg_resolve_proprietaire(): rapproche le propriétaire du PDF d'une fiche existante
 *   - crg_apply_parsed()       : crée/maj propriétaire N/A ici, immeubles, lots, baux
 *                                (bien_baux), situations financières + archive GED du PDF.
 */

declare(strict_types=1);

require_once __DIR__ . '/ged_document_links.php';

if (!function_exists('crg_core_norm')) {
    /** Normalise un nom (casse / accents / ponctuation) pour comparaison. */
    function crg_core_norm(string $s): string {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, ['À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U']);
        return preg_replace('/[^A-Z0-9]+/u', '', $s) ?? '';
    }
}

if (!function_exists('crg_parse_pdf')) {
    /**
     * Extrait le texte d'un PDF CRG et l'analyse via GPT-4o.
     * @return array ['ok'=>bool, 'data'=>array|null, 'error'=>string|null, 'raw'=>string|null]
     */
    function crg_parse_pdf(string $pdfPath, string $apiKey): array {
        if (!is_file($pdfPath)) return ['ok'=>false, 'data'=>null, 'error'=>'Fichier introuvable', 'raw'=>null];
        if ($apiKey === '')   return ['ok'=>false, 'data'=>null, 'error'=>'Clé API OpenAI non configurée', 'raw'=>null];

        $text = function_exists('extractPdfText') ? extractPdfText($pdfPath) : '';
        if (strlen(trim($text)) < 100) {
            return ['ok'=>false, 'data'=>null, 'error'=>'Impossible d\'extraire le texte (scan sans OCR ?)', 'raw'=>null];
        }
        $textTrunc = mb_substr($text, 0, 40000);

        $systemPrompt = "Tu es un expert-comptable spécialisé en gestion locative française. "
            . "Tu analyses des Comptes-Rendus de Gestion (CRG) trimestriels envoyés par les gestionnaires aux propriétaires bailleurs. "
            . "Tu extrais TOUTES les données structurées. Tu réponds UNIQUEMENT en JSON valide.";

        $userPrompt = <<<PROMPT
Analyse ce Compte-Rendu de Gestion (CRG) et extrais toutes les données structurées.

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
  "proprietaire": { "nom": "string (nom complet du propriétaire bailleur destinataire)", "adresse": "string ou null (adresse POSTALE du propriétaire)", "code_compte": "string ou null (n° COMPTE PERSONNEL)", "email": "string ou null", "telephone": "string ou null" },
  "gestionnaire": { "nom": "string (la régie/gestionnaire émettrice, en-tête du document)", "reference_mandat": "string ou null" },
  "periode": { "annee": "number", "trimestre": "number (1-4)", "date_arrete": "string (YYYY-MM-DD)" },
  "solde_report": "number", "total_debits": "number", "total_credits": "number", "solde_final": "number",
  "immeubles": [
    { "code": "string", "nom": "string", "adresse": "string",
      "lots": [
        { "numero_lot": "string (UN SEUL objet par numéro de lot)", "type_bien": "appartement|maison|commerce|parking|cave|bureau|local|autre", "etage": "string ou null", "surface": "number ou null", "loyer_mensuel": "number (loyer MENSUEL hors charges du lot, PAR MOIS — jamais le cumul du trimestre)",
          "locataires": [
            { "nom": "string", "actif": "boolean (true = loyer sur la période courante)", "loyer_appele": "number", "charges_provisions": "number", "solde_anterieur": "number", "total_loyers": "number", "total_charges": "number", "total_regle": "number", "total_impaye": "number", "date_bail": "string ou null (YYYY-MM-DD)" }
          ] }
      ],
      "ecritures": [ { "libelle": "string", "categorie": "loyer|charge|travaux|assurance|taxe|honoraires|autre", "debit": "number", "credit": "number", "tva": "number" } ]
    }
  ]
}

═══ REPÉRAGE DU PROPRIÉTAIRE (format ICS / Régie EMERY / Lyon) — RÈGLE ABSOLUE ═══
Le PROPRIÉTAIRE est le DESTINATAIRE du courrier, situé DANS UN BLOC PRÉCIS :
  • Il se trouve APRÈS la ligne « <Ville>, le JJ/MM/AAAA » (ex. « Lyon, le 31/03/2026 »)
    et AVANT la ligne « COMPTE PERSONNEL <numéro> ».
  • Ce bloc contient : 1) le NOM (« Monsieur X », « Madame Y », « M. et Mme Z », « SCI … », « Indivision … »),
    puis 2) son ADRESSE POSTALE (1 à 3 lignes : voie + code postal + ville).
  • Exemple : nom = « Monsieur JURINE CHARLES », adresse = « 42 BIS AVENUE DU 8 MAI 1945, 69160 TASSIN LA DEMI LUNE ».
  • Le « COMPTE PERSONNEL <numéro> » juste après = proprietaire.code_compte.

NE JAMAIS confondre le propriétaire avec :
  ✗ l'EN-TÊTE du document (la RÉGIE émettrice : « LOCA IMMO », « REGIE EMERY », « 19 BOULEVARD YVES FARGE 69007 LYON », « Powered by ICS », mentions Siret/APE/Carte pro/GALIAN) → c'est le GESTIONNAIRE, pas le propriétaire.
  ✗ une adresse d'IMMEUBLE / de BIEN (lignes « Immeuble : <code> », « <n°> RUE … » dans les tableaux « SITUATION DES LOCATAIRES ») → c'est l'adresse du bien loué, PAS celle du propriétaire.
Si tu hésites, l'adresse du propriétaire est celle du BLOC DESTINATAIRE en haut à droite/gauche, jamais celle répétée dans les tableaux de lots.

Règles CRUCIALES :
- Un même NUMÉRO DE LOT peut apparaître plusieurs fois avec des locataires différents : regroupe en UN SEUL "lot" avec plusieurs "locataires". PAS un lot par locataire.
- actif:true UNIQUEMENT si loyer sur la période courante. Un locataire avec seulement un "Solde Antérieur" = ancien parti (actif:false).
- Extrais TOUS les immeubles, lots et locataires (actifs ET anciens). Montants en euros sans symbole. Champ absent = null ou 0.
- loyer_mensuel : le montant du LOYER MENSUEL hors charges du lot (colonne « Loyer » d'une ligne de bail, tel qu'affiché PAR MOIS). Un CRG couvre 3 mois : NE multiplie PAS par 3, NE cumule PAS les lignes. Si seul un cumul trimestriel figure, divise-le par le nombre de mois de la période. En cas de doute, laisse 0.

TEXTE DU CRG :
{$textTrunc}
PROMPT;

        $payload = [
            'model'    => 'gpt-4o',
            'messages' => [
                ['role'=>'system', 'content'=>$systemPrompt],
                ['role'=>'user',   'content'=>$userPrompt],
            ],
            'temperature'     => 0.1,
            'max_tokens'      => 16000,
            'response_format' => ['type'=>'json_object'],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['ok'=>false, 'data'=>null, 'error'=>'Appel IA échoué (HTTP ' . $httpCode . ')', 'raw'=>substr((string)$response, 0, 500)];
        }
        $result  = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            return ['ok'=>false, 'data'=>null, 'error'=>'Contenu IA vide', 'raw'=>substr($response, 0, 500)];
        }
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
        }
        $parsed = json_decode($content, true);
        if (!$parsed && preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (!$parsed) {
            return ['ok'=>false, 'data'=>null, 'error'=>'JSON IA invalide', 'raw'=>substr($content, 0, 1000)];
        }
        return ['ok'=>true, 'data'=>$parsed, 'error'=>null, 'raw'=>null];
    }
}

if (!function_exists('crg_resolve_proprietaire')) {
    /**
     * Rapproche le propriétaire extrait du PDF d'une fiche `proprietaires` existante.
     * Ne crée RIEN : renvoie l'id trouvé + un niveau de confiance.
     * @return array ['id'=>int, 'confidence'=>int(0-100), 'reliable'=>bool, 'label'=>string]
     */
    function crg_resolve_proprietaire(PDO $pdo, array $parsed, ?int $agenceId = null): array {
        $nom = trim((string)($parsed['proprietaire']['nom'] ?? ''));
        if ($nom === '' || mb_strlen($nom) < 3) {
            return ['id'=>0, 'confidence'=>0, 'reliable'=>false, 'label'=>$nom];
        }
        $norm = crg_core_norm($nom);

        // Rapprochement sur nom OU societe (normalisés), scope agence si fourni.
        $rows = $pdo->query("SELECT id, nom, societe, id_agence FROM proprietaires WHERE actif = 1")->fetchAll(PDO::FETCH_ASSOC);
        $best = null; $bestScore = 0;
        foreach ($rows as $r) {
            $cand = crg_core_norm((string)($r['societe'] ?: $r['nom']));
            if ($cand === '') continue;
            $score = 0;
            if ($cand === $norm) $score = 100;
            elseif (str_contains($cand, $norm) || str_contains($norm, $cand)) $score = 80;
            if ($score > 0 && $agenceId && (int)$r['id_agence'] === (int)$agenceId) $score += 5;
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        if ($best) {
            return ['id'=>(int)$best['id'], 'confidence'=>min(100,$bestScore), 'reliable'=>$bestScore >= 100, 'label'=>$nom];
        }
        return ['id'=>0, 'confidence'=>0, 'reliable'=>false, 'label'=>$nom];
    }
}

if (!function_exists('crg_create_proprietaire')) {
    /** Crée une fiche propriétaire (personne morale) depuis le bloc IA. */
    function crg_create_proprietaire(PDO $pdo, array $parsed, ?int $agenceId = null): int {
        $prop = $parsed['proprietaire'] ?? [];
        $nom  = trim((string)($prop['nom'] ?? ''));
        if ($nom === '') return 0;
        $st = $pdo->prepare("INSERT INTO proprietaires (nom, societe, adresse_1, email, telephone, actif, type_personne, id_agence) VALUES (?, ?, ?, ?, ?, 1, 'morale', ?)");
        $st->execute([$nom, $nom, $prop['adresse'] ?? null, $prop['email'] ?? null, $prop['telephone'] ?? null, $agenceId ?: null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('crg_norm_addr')) {
    /**
     * Clé d'adresse pour l'anti-doublon : « 11 RUE PAUL GAUGUIN 69500 BRON »
     * → « 11 PAUL GAUGUIN » (numéro + nom de voie, sans type de voie / CP / ville).
     * Permet de rapprocher un immeuble CRG d'un immeuble/bien d'annonce existant.
     */
    function crg_norm_addr(string $s): string {
        $n = mb_strtoupper(trim($s), 'UTF-8');
        $n = strtr($n, ['À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U']);
        $n = preg_replace('/\b\d{5}\b.*$/', '', $n);      // retire code postal + ville
        $n = preg_replace('/[^A-Z0-9]+/', ' ', $n);
        $n = trim(preg_replace('/\s+/', ' ', $n));
        $stop = ['RUE','AVENUE','AV','BD','BLD','BOULEVARD','PLACE','PL','IMPASSE','IMP','CHEMIN','CHEM',
                 'COURS','CRS','ROUTE','RTE','QUAI','ALLEE','ALLEES','ALL','MONTEE','MTEE','PASSAGE','PASS',
                 'SQUARE','SQ','VILLA','CLOS','GRANDE','GRAND','DU','DE','DES','LA','LE','LES','D','L','ET','A'];
        $bisTer = ['BIS','TER','QUATER'];
        $out = [];
        foreach (explode(' ', $n) as $w) {
            if ($w === '' || in_array($w, $stop, true) || in_array($w, $bisTer, true)) continue;
            $out[] = $w;
        }
        return implode(' ', $out);
    }
}

if (!function_exists('crg_detect_type_personne')) {
    /** Personne morale si le nom porte une forme sociale, sinon physique. */
    function crg_detect_type_personne(string $nom): string {
        return preg_match('/\b(SCI|SARL|SASU|SAS|SNC|EURL|SCP|SCM|SA|GPE|GROUPE|SC|GFA|GIE|INDIVISION)\b/i', $nom)
            ? 'morale' : 'physique';
    }
}

if (!function_exists('crg_resolve_or_create_proprio')) {
    /**
     * Résout le propriétaire par CLÉ code_compte (unique/robuste), sinon par nom,
     * sinon le crée. Pose/complète code_compte, nom, adresse, type_personne.
     * → idempotence trimestre après trimestre, zéro doublon de propriétaire.
     */
    function crg_resolve_or_create_proprio(PDO $pdo, string $nom, string $codeCompte, ?string $adresse, ?int $agenceId): int {
        $nom = trim($nom); $codeCompte = trim($codeCompte);
        $type = crg_detect_type_personne($nom);
        // 1) Par code_compte (clé de référence ICS).
        if ($codeCompte !== '') {
            $st = $pdo->prepare("SELECT id FROM proprietaires WHERE code_compte = ? LIMIT 1");
            $st->execute([$codeCompte]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                // L'agence du CRG prime : on la pose si absente (gestionnaire = agence du CRG).
                $pdo->prepare("UPDATE proprietaires
                    SET nom=COALESCE(NULLIF(nom,''),?), societe=COALESCE(NULLIF(societe,''),?),
                        adresse_1=COALESCE(NULLIF(adresse_1,''),?),
                        id_agence=COALESCE(id_agence,?) WHERE id=?")
                    ->execute([$nom, $nom, $adresse ?: null, $agenceId ?: null, $id]);
                return $id;
            }
        }
        // 2) Fallback par nom (et on pose le code_compte si trouvé sans).
        $r = crg_resolve_proprietaire($pdo, ['proprietaire' => ['nom' => $nom]], $agenceId);
        if (($r['id'] ?? 0) > 0) {
            $pdo->prepare("UPDATE proprietaires
                    SET code_compte=COALESCE(NULLIF(code_compte,''),?), id_agence=COALESCE(id_agence,?)
                    WHERE id=?")
                ->execute([$codeCompte ?: null, $agenceId ?: null, (int)$r['id']]);
            return (int)$r['id'];
        }
        // 3) Création.
        $st = $pdo->prepare("INSERT INTO proprietaires (nom, societe, adresse_1, code_compte, actif, type_personne, id_agence)
                             VALUES (?,?,?,?,1,?,?)");
        $st->execute([$nom, ($type === 'morale' ? $nom : null), $adresse ?: null, $codeCompte ?: null, $type, $agenceId ?: null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('crg_detect_agence')) {
    /**
     * Détecte l'agence GESTIONNAIRE directement dans l'entête du CRG (indépendant du
     * parser Python/IA). Discriminant UNIQUE = le 1er code postal du document, qui est
     * toujours l'adresse de l'agence (ex. « 69007 LYON » → LYON, « 63200 RIOM » → RIOM ;
     * le CP du propriétaire vient plus bas). Fallback : nom de ville dans l'entête.
     * Permet d'importer « tout azimut » des dossiers mixtes (LYON, RIOM, …).
     * @return int|null id de l'agence, ou null si indéterminée.
     */
    function crg_detect_agence(PDO $pdo, string $text): ?int {
        if (trim($text) === '') return null;
        $head = mb_substr($text, 0, 800); // borne l'entête → évite de capter le CP du propriétaire
        // 1) Code postal de l'agence = 1er code postal à 5 chiffres du document.
        if (preg_match('/\b(\d{5})\b/', $head, $m)) {
            $st = $pdo->prepare("SELECT id FROM agences WHERE code_postal = ? AND actif = 1 ORDER BY id LIMIT 1");
            $st->execute([$m[1]]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) return $id;
        }
        // 2) Fallback : nom de ville d'agence présent dans l'entête (« LYON 07 » → « LYON »).
        $rows = $pdo->query("SELECT id, ville FROM agences WHERE actif = 1 AND ville IS NOT NULL AND ville <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $H = mb_strtoupper($head);
        foreach ($rows as $r) {
            $ville = mb_strtoupper(trim(preg_replace('/\s*\d+\s*$/', '', (string)$r['ville'])));
            if ($ville !== '' && mb_strlen($ville) >= 4 && mb_strpos($H, $ville) !== false) return (int)$r['id'];
        }
        return null;
    }
}

if (!function_exists('crg_ensure_tiers_for_proprio')) {
    /**
     * Crée (si absent) le TIERS + rôle « proprietaire » pour une fiche propriétaire,
     * et pose proprietaires.id_tiers. Sans ça, un nouveau propriétaire est « sans tiers »
     * (pas de vue 360). Reprend la logique du script de migration prod (run_prod.php).
     * @return int id du tiers (0 si échec).
     */
    function crg_ensure_tiers_for_proprio(PDO $pdo, int $propId, ?int $societeId = null): int {
        if ($propId <= 0) return 0;
        $st = $pdo->prepare("SELECT id_agence, id_tiers, type_personne, nom, prenom, societe, email, telephone, adresse_1, code_postal, ville FROM proprietaires WHERE id=?");
        $st->execute([$propId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return 0;
        if (!empty($p['id_tiers'])) return (int)$p['id_tiers'];
        $morale = ($p['type_personne'] ?? '') === 'morale';
        $tt  = $morale ? 'personne_morale' : 'personne_physique';
        $rs  = $morale ? ($p['societe'] ?: $p['nom']) : null;
        $aff = $morale ? (string)$rs : trim(((string)($p['prenom'] ?? '')) . ' ' . ((string)($p['nom'] ?? '')));
        if ($aff === '') $aff = (string)($p['nom'] ?: $rs ?: ('Propriétaire #' . $propId));
        try {
            $pdo->prepare("INSERT INTO tiers (id_societe,id_agence,type_tiers,nom,prenom,raison_sociale,nom_affichage,email,telephone,adresse_ligne1,code_postal,ville,source_creation,actif,date_creation)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'import_crg',1,NOW())")
                ->execute([$societeId ?: null, $p['id_agence'] ?: null, $tt, $p['nom'], $p['prenom'] ?? null, $rs, $aff,
                           $p['email'] ?? null, $p['telephone'] ?? null, $p['adresse_1'] ?? null, $p['code_postal'] ?? null, $p['ville'] ?? null]);
            $idt = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO tiers_roles (id_tiers,role_code,actif,date_creation) VALUES (?,'proprietaire',1,NOW())")->execute([$idt]);
            $pdo->prepare("UPDATE proprietaires SET id_tiers=? WHERE id=?")->execute([$idt, $propId]);
            return $idt;
        } catch (Throwable $e) { error_log('[crg_ensure_tiers] ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('crg_split_adresse')) {
    /**
     * Découpe une adresse française « <voie> <CP5> <VILLE> » en [voie, code_postal, ville].
     * Ex. « 9 BOULEVARD DES BROTTEAUX 69006 LYON » → ['9 BOULEVARD DES BROTTEAUX','69006','LYON'].
     * Nécessaire pour le registre copropriété (RNC) qui cherche par CP + voie séparés.
     * Retourne [voie_complète, '', ''] si aucun CP à 5 chiffres n'est trouvé.
     */
    function crg_split_adresse(string $adr): array {
        $adr = trim(preg_replace('/\s+/', ' ', $adr));
        if ($adr === '') return ['', '', ''];
        // Voie OPTIONNELLE (certains CRG n'ont que « CP VILLE »).
        if (preg_match('/^(.*?)\s*\b(\d{5})\b\s+(.+)$/u', $adr, $m)) {
            return [trim($m[1], " ,"), $m[2], crg_clean_ville($m[3])];
        }
        return [$adr, '', ''];
    }
}

if (!function_exists('crg_clean_ville')) {
    /**
     * Nettoie le libellé de ville : coupe la pollution du tableau CRG collée derrière
     * (« LYON RECAPITULATIF DES OPERATIONS Débits Crédits Dont T.V.A. … » → « LYON »).
     * Conserve les suffixes légitimes (« CEDEX 2 », « LA PAPE »).
     */
    function crg_clean_ville(string $v): string {
        $v = trim(preg_replace('/\s+/', ' ', $v), " ,-");
        $v = preg_replace('/\s+(R[ÉE]CAPITULATIF|OP[ÉE]RATIONS?|D[ÉE]BITS?|CR[ÉE]DITS?|D[ÉE]PENSES?|D[ÉE]DUCTIBLE|LOCATIF|SYNDIC|DONT|T\.?V\.?A)\b.*$/iu', '', (string)$v);
        return trim((string)$v, " ,-");
    }
}

if (!function_exists('crg_proprio_id_by_compte_text')) {
    /**
     * PHASE 1 (archivage PDF sans IA) : retrouve l'id du propriétaire à partir du
     * TEXTE du PDF (extraction gratuite), via son n° de COMPTE (8 chiffres). On teste
     * tous les nombres de 8 chiffres du document et on retient le 1er qui correspond à
     * un `proprietaires.code_compte` existant. Retourne 0 si aucun (→ repli « à classer »).
     */
    function crg_proprio_id_by_compte_text(PDO $pdo, string $pdfText): int {
        if (trim($pdfText) === '') return 0;
        if (!preg_match_all('/\b(\d{8})\b/', $pdfText, $m)) return 0;
        $seen = [];
        foreach ($m[1] as $cpt) {
            if (isset($seen[$cpt])) continue; $seen[$cpt] = true;
            $st = $pdo->prepare("SELECT id FROM proprietaires WHERE code_compte = ? LIMIT 1");
            $st->execute([$cpt]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) return $id;
        }
        return 0;
    }
}

if (!function_exists('crg_archive_pdf_only')) {
    /**
     * PHASE 1 : archive le PDF du CRG en GED, lié AU SEUL PROPRIÉTAIRE (TIERS 'main'),
     * SANS analyse IA ni écriture des soldes. Idempotent (dédup par hash côté GED). Le
     * parse+apply ultérieur (phase 2) ré-archive avec les liens complets (immeuble/bail),
     * dédupliqué. But : ne JAMAIS perdre le document même si l'IA échoue/timeoute.
     * @return array{ok:bool, ged:string, doc_id?:int, error?:?string}
     */
    function crg_archive_pdf_only(PDO $pdo, string $pdfAbs, ?string $pdfUrl, int $proprietaireId, int $annee, int $trimestre, array $ctx = []): array {
        if (!is_file($pdfAbs) || $proprietaireId <= 0) return ['ok'=>false, 'ged'=>'skip'];
        $societeId = $ctx['societeId'] ?? null; $agenceId = $ctx['agenceId'] ?? null; $userId = $ctx['userId'] ?? null;
        try {
            $tiersId = crg_ensure_tiers_for_proprio($pdo, $proprietaireId, $societeId);
            if ($tiersId <= 0) return ['ok'=>false, 'ged'=>'err', 'error'=>'tiers introuvable'];
            $stP = $pdo->prepare("SELECT id_agence FROM proprietaires WHERE id=? LIMIT 1");
            $stP->execute([$proprietaireId]); $propAge = (int)($stP->fetchColumn() ?: 0) ?: ($agenceId ?: null);

            $qn = $pdo->prepare("SELECT CASE WHEN COALESCE(societe,'')<>'' THEN societe ELSE TRIM(CONCAT_WS(' ', nom, NULLIF(prenom,''))) END FROM proprietaires WHERE id=?");
            $qn->execute([$proprietaireId]); $propNomGed = (string)$qn->fetchColumn();
            $slugSrc  = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $propNomGed) ?: $propNomGed;
            $propSlug = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $slugSrc), '_'));
            $srcName  = 'CRG_' . ($propSlug !== '' ? $propSlug . '_' : '') . $annee . '_T' . $trimestre . '.pdf';
            $dateDoc  = ($annee > 0 && $trimestre > 0) ? sprintf('%04d-%02d-%02d', $annee, $trimestre*3, in_array($trimestre*3,[6,9],true)?30:31) : null;

            $gedRes = gus_commit_document(
                $pdo,
                ['path_on_disk'=>$pdfAbs, 'name_original'=>$srcName, 'mime_type'=>'application/pdf', 'size_bytes'=>(int)@filesize($pdfAbs), 'public_url'=>$pdfUrl],
                [
                    'document_type'=>'crg', 'source_module'=>'05_GESTION_LOCATIVE', 'security_level'=>'interne',
                    'societe_id'=>$societeId ?: null, 'agence_id'=>$propAge, 'tenant_id'=>$societeId ?: null, 'created_by'=>$userId ?: null,
                    'metadata_extra'=>['annee'=>$annee, 'trimestre'=>$trimestre, 'phase'=>'pdf_only'],
                    'naming_ctx'=>[
                        'n1_slug'=>'05_gestion_locative', 'n2_slug'=>'02_crg', 'n3_slug'=>'01_crg_trimestriel',
                        'type_doc'=>'crg', 'entity_type'=>'TIERS', 'entity_id'=>$tiersId,
                        'source_filename'=>$srcName, 'ext'=>'pdf', 'date_doc'=>$dateDoc,
                    ],
                ],
                [['entity_type'=>'TIERS', 'entity_id'=>$tiersId, 'relation_type'=>'main', 'is_validated'=>1, 'validated_by'=>$userId ?: null]]
            );
            $ged = !empty($gedRes['ok']) ? (!empty($gedRes['deduplicated']) ? 'dedup' : 'ok') : 'err';
            return ['ok'=>($ged!=='err'), 'ged'=>$ged, 'doc_id'=>(int)($gedRes['doc_id'] ?? 0), 'error'=>($ged==='err' ? implode(' / ', $gedRes['errors'] ?? ['echec']) : null)];
        } catch (Throwable $e) {
            error_log('[crg_archive_pdf_only] ' . $e->getMessage());
            return ['ok'=>false, 'ged'=>'err', 'error'=>$e->getMessage()];
        }
    }
}

if (!function_exists('crg_trimestre_from_filename')) {
    /** Déduit année/trimestre du nom de fichier (format YYYYMMDD…). @return array{annee:int,trimestre:int} */
    function crg_trimestre_from_filename(string $filename): array {
        if (preg_match('/(\d{4})(\d{2})(\d{2})/', basename($filename), $m)) {
            $annee = (int)$m[1]; $mois = (int)$m[2];
            $trimMap = [3=>1, 6=>2, 9=>3, 12=>4];
            $trimestre = $trimMap[$mois] ?? (int)ceil($mois / 3);
            return ['annee'=>$annee, 'trimestre'=>$trimestre];
        }
        return ['annee'=>0, 'trimestre'=>0];
    }
}

if (!function_exists('crg_proprio_from_filename')) {
    /**
     * Extrait le nom du propriétaire depuis le nom de fichier, quand il précède
     * le token d'année (ex. « Monsieur_QU_XINLIANG_YANG_2026_T1_… » → « QU XINLIANG YANG »,
     * « SCI_FAVRE_2026_T1_… » → « SCI FAVRE »). Retourne '' si aucun nom exploitable
     * (ex. fichiers datés « 20260331_… » → propriétaire à prendre au dossier).
     */
    function crg_proprio_from_filename(string $filename): string {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/^(.*?)[_\-\s]+(20\d\d)(?=[_\-\s.]|$)/u', $base, $m)) {
            $name = trim(str_replace(['_', '-'], ' ', $m[1]));
            // Retirer les civilités en tête (améliore le rapprochement de fiche).
            $name = preg_replace('/^(monsieur et madame|mr et mme|m\.? et mme|madame|monsieur|mademoiselle|melle|mme|mlle|mr|m\.)\s+/iu', '', $name) ?? $name;
            $name = trim($name);
            if (mb_strlen($name) >= 3 && !ctype_digit($name)) return $name;
        }
        return '';
    }
}

if (!function_exists('crg_is_gestionnaire_name')) {
    /**
     * Garde anti-régie : un propriétaire ne peut PAS être le gestionnaire/la régie
     * émettrice du CRG. Empêche le fallback « nom de dossier » (ex. « CRG REGIE EMERY
     * LYON ») ou l'en-tête du PDF de créer un faux propriétaire unique.
     * NB : ciblé sur la RÉGIE (« regie emery », « emery immo », « crg … lyon »),
     * PAS sur le simple mot « emery » — un vrai propriétaire « M. et Mme EMERY » reste valide.
     */
    function crg_is_gestionnaire_name(string $name): bool {
        // crg_core_norm renvoie en MAJUSCULES sans séparateurs (« REGIE EMERY » → « REGIEEMERY »).
        // On normalise DONC les motifs de la même façon pour comparer.
        $n = crg_core_norm($name);
        if ($n === '') return false;
        $patterns = [
            'regie emery', 'emery immo', 'emery immobilier', 'loca immo',
            'crg regie', 'agence emery', 'regie',
        ];
        foreach ($patterns as $p) {
            $pn = crg_core_norm($p);
            if ($pn !== '' && ($n === $pn || str_contains($n, $pn))) return true;
        }
        // Nom de dossier d'export « CRG … » n'est jamais un propriétaire.
        if (str_starts_with($n, 'CRG')) return true;
        return false;
    }
}

if (!function_exists('crg_is_invalid_proprio_name')) {
    /**
     * Rejette un nom de propriétaire « poubelle » venant d'un mauvais parse PDF :
     * libellé de trimestre (« - 1er Trimestre 2026 - »), en-tête de récap, date,
     * référence TTAxxxx, ou chaîne sans vraie lettre. Empêche l'import de créer un
     * faux propriétaire quand ni le nom de fichier ni le dossier ne donnent le vrai.
     */
    function crg_is_invalid_proprio_name(string $name): bool {
        $t = mb_strtolower(trim($name), 'UTF-8');
        if ($t === '' || mb_strlen(preg_replace('/[^a-zà-ÿ]/u', '', $t)) < 3) return true; // < 3 lettres réelles
        $bad = [
            'trimestre', 'compte personnel', 'recapitulatif', 'récapitulatif',
            'totaux generaux', 'totaux généraux', 'solde', 'arrete des comptes',
            'periode', 'période', 'du au', 'immeuble :', 'lot ',
        ];
        foreach ($bad as $b) { if (str_contains($t, $b)) return true; }
        // « TTA1234 » (référence de fichier), « 1er/2eme/… », dates JJ/MM/AAAA seules.
        if (preg_match('/^tta[0-9a-f]{3,}$/i', trim($name))) return true;
        if (preg_match('/\b(1er|2e|2eme|3e|3eme|4e|4eme|[1-4])\s*(er|eme|ème)?\s*trimestre\b/iu', $t)) return true;
        if (preg_match('/^\W*\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\W*$/', $t)) return true;
        return false;
    }
}

if (!function_exists('crg_is_duplicate')) {
    /** Un CRG (propriétaire+année+trimestre) est-il déjà importé (parse_statut='ok') ? */
    function crg_is_duplicate(PDO $pdo, int $proprietaireId, int $annee, int $trimestre): bool {
        if ($proprietaireId <= 0 || $annee <= 0 || $trimestre <= 0) return false;
        $st = $pdo->prepare("SELECT 1 FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=? AND parse_statut='ok' LIMIT 1");
        $st->execute([$proprietaireId, $annee, $trimestre]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('crg_adapt_python_output')) {
    /**
     * Adapte la sortie de parse_crg.py (meta + immeubles[charges]) vers la structure
     * attendue par crg_apply_parsed (periode + immeubles[ecritures] + lots à plat).
     */
    function crg_adapt_python_output(array $data, array $fileHint = []): array {
        $meta = $data['meta'] ?? [];
        // Date d'arrêté : parser en DD/MM/YYYY → normaliser en YYYY-MM-DD.
        $dateArrete = (string)($meta['date_arrete'] ?? '');
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $dateArrete, $m)) {
            $dateArrete = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $annee     = (int)($meta['annee'] ?? 0)     ?: (int)($fileHint['annee'] ?? 0);
        $trimestre = (int)($meta['trimestre'] ?? 0) ?: (int)($fileHint['trimestre'] ?? 0);

        $immeubles = [];
        foreach ($data['immeubles'] ?? [] as $imm) {
            $ecritures = [];
            foreach ($imm['charges'] ?? [] as $c) {
                $ecritures[] = [
                    'libelle'   => $c['libelle'] ?? '',
                    'categorie' => $c['categorie'] ?? 'autre',
                    'debit'     => $c['montant'] ?? 0,
                    'credit'    => 0,
                    'tva'       => $c['tva'] ?? 0,
                ];
            }
            $lots = [];
            foreach ($imm['lots'] ?? [] as $lot) {
                // Champs à plat (retro-compat crg_apply_parsed) + alias manquants.
                $lot['total_charges'] = $lot['total_charges'] ?? ($lot['total_provisions'] ?? 0);
                $lot['total_loyers']  = $lot['total_loyers']  ?? ($lot['loyer_appele'] ?? 0);
                // Loyer MENSUEL : fourni par le parseur (valeur d'une ligne de période
                // normalisée au mois). Fallback ÷3 seulement s'il manque.
                if (!isset($lot['loyer_mensuel']) || (float)$lot['loyer_mensuel'] <= 0) {
                    $lm = (float)($lot['loyer_appele'] ?? 0);
                    $lot['loyer_mensuel'] = $lm > 0 ? round($lm / 3, 2) : 0.0;
                }
                $lots[] = $lot;
            }
            $immeubles[] = [
                'code'      => $imm['code'] ?? '',
                'nom'       => $imm['nom'] ?? '',
                'adresse'   => $imm['adresse'] ?? '',
                'lots'      => $lots,
                'ecritures' => $ecritures,
            ];
        }

        return [
            'proprietaire' => [
                'nom'         => (string)($meta['proprietaire'] ?? ''),
                'adresse'     => $meta['proprietaire_adresse'] ?? null,
                'code_compte' => $meta['compte'] ?? null,
            ],
            'periode'      => ['annee'=>$annee, 'trimestre'=>$trimestre, 'date_arrete'=>($dateArrete ?: null)],
            'solde_report' => $meta['solde_report'] ?? 0,
            'total_debits' => $meta['total_debits'] ?? 0,
            'total_credits'=> $meta['total_credits'] ?? 0,
            'immeubles'    => $immeubles,
        ];
    }
}

if (!function_exists('crg_apply_parsed')) {
    /**
     * Applique un CRG parsé : crg_trimestre + immeubles/lots/baux (bien_baux) +
     * situations financières + archivage GED du PDF.
     *
     * @param array $ctx ['societeId'=>?int,'agenceId'=>?int,'userId'=>?int,
     *                    'pdfAbsPath'=>?string,'pdfPublicUrl'=>?string]
     * @return array ['ok'=>bool,'crg_id'=>int,'stats'=>[...],'error'=>?string]
     */
    function crg_apply_parsed(PDO $pdo, array $parsed, int $proprietaireId, array $ctx = []): array {
        if ($proprietaireId <= 0) return ['ok'=>false, 'crg_id'=>0, 'stats'=>[], 'error'=>'Propriétaire manquant'];

        $societeId = $ctx['societeId'] ?? null;
        $agenceId  = $ctx['agenceId'] ?? null;
        $userId    = $ctx['userId'] ?? null;
        $pdfAbs    = $ctx['pdfAbsPath'] ?? null;
        $pdfUrl    = $ctx['pdfPublicUrl'] ?? null;

        // TIERS : garantir que le propriétaire a bien un tiers (+ rôle) — sinon « sans tiers ».
        if (function_exists('crg_ensure_tiers_for_proprio')) {
            crg_ensure_tiers_for_proprio($pdo, $proprietaireId, $societeId);
        }

        $periode   = $parsed['periode'] ?? [];
        $annee     = (int)($periode['annee'] ?? date('Y'));
        $trimestre = (int)($periode['trimestre'] ?? 1);
        $dateArrete = $periode['date_arrete'] ?? date('Y-m-d');
        $pdfRelPath = ($pdfAbs && $proprietaireId) ? ($proprietaireId . '/' . $annee . '_T' . $trimestre . '.pdf') : null;

        // ── CRG trimestre (upsert : on rejoue proprement) ──
        $st = $pdo->prepare('SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?');
        $st->execute([$proprietaireId, $annee, $trimestre]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $crgId = (int)$existing['id'];
            $pdo->prepare('DELETE FROM crg_situations_locataires WHERE id_crg=?')->execute([$crgId]);
            $pdo->prepare('DELETE FROM crg_ecritures WHERE id_crg=?')->execute([$crgId]);
            $pdo->prepare('UPDATE crg_trimestres SET fichier_pdf=?, parse_statut="ok", uploaded_at=NOW(), date_arrete=?, solde_report=?, total_debits=?, total_credits=? WHERE id=?')
                ->execute([$pdfRelPath, $periode['date_arrete'] ?? null, $parsed['solde_report'] ?? 0, $parsed['total_debits'] ?? 0, $parsed['total_credits'] ?? 0, $crgId]);
        } else {
            $pdo->prepare('INSERT INTO crg_trimestres (id_proprietaire, annee, trimestre, fichier_pdf, parse_statut, date_arrete, solde_report, total_debits, total_credits, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$proprietaireId, $annee, $trimestre, $pdfRelPath, 'ok', $periode['date_arrete'] ?? null, $parsed['solde_report'] ?? 0, $parsed['total_debits'] ?? 0, $parsed['total_credits'] ?? 0]);
            $crgId = (int)$pdo->lastInsertId();
        }

        $nbImmeubles = 0; $nbLots = 0; $nbEcritures = 0; $nbBascules = 0;
        $immeubleIds = []; $bailIdsTouched = []; $bienIdsTouched = []; $adoptedBiens = [];

        foreach ($parsed['immeubles'] ?? [] as $imm) {
            $nbImmeubles++;
            $codeCrg = $imm['code'] ?? ''; $nomImm = $imm['nom'] ?? ''; $adrImm = $imm['adresse'] ?? '';

            $st = $pdo->prepare('SELECT id FROM immeubles WHERE code_crg=? AND id_proprietaire=?');
            $st->execute([$codeCrg, $proprietaireId]);
            $immRow = $st->fetch(PDO::FETCH_ASSOC);
            $idImmeuble = 0;
            if ($immRow) {
                $idImmeuble = (int)$immRow['id'];
            } else {
                // ANTI-DOUBLON : chercher un immeuble EXISTANT à la même adresse (ex. déjà
                // créé pour une annonce) avant d'en créer un nouveau.
                $keyC = crg_norm_addr($adrImm);
                if ($keyC !== '') {
                    $lastWord = trim((string)strrchr($keyC, ' ')) ?: $keyC; // ex. « GAUGUIN »
                    try {
                        $cand = $pdo->prepare("SELECT id, adresse_1, code_postal, ville FROM immeubles
                                               WHERE adresse_1 LIKE ? LIMIT 50");
                        $cand->execute(['%' . $lastWord . '%']);
                        foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $imx) {
                            if (crg_norm_addr(($imx['adresse_1'] ?? '') . ' ' . ($imx['code_postal'] ?? '') . ' ' . ($imx['ville'] ?? '')) === $keyC) {
                                $idImmeuble = (int)$imx['id'];
                                // Complète aussi CP/ville (registre copro RNC) s'ils manquent.
                                [$vImm, $cpImm, $viImm] = crg_split_adresse((string)$adrImm);
                                $pdo->prepare("UPDATE immeubles SET code_crg=COALESCE(NULLIF(code_crg,''),?), id_proprietaire=COALESCE(id_proprietaire,?), mode_gestion=COALESCE(NULLIF(mode_gestion,''),'gestion'), code_postal=COALESCE(NULLIF(code_postal,''),?), ville=COALESCE(NULLIF(ville,''),?) WHERE id=?")
                                    ->execute([$codeCrg, $proprietaireId, $cpImm ?: null, $viImm ?: null, $idImmeuble]);
                                error_log("[crg dedup] immeuble réutilisé #$idImmeuble pour « $adrImm »");
                                break;
                            }
                        }
                    } catch (Throwable $e) {}
                }
                if ($idImmeuble === 0) {
                    // Découpe l'adresse « voie CP ville » (registre copro RNC = recherche CP+voie).
                    [$vImm, $cpImm, $viImm] = crg_split_adresse((string)$adrImm);
                    $pdo->prepare('INSERT INTO immeubles (id_proprietaire, id_societe, id_agence, code_crg, nom_immeuble, adresse_1, code_postal, ville, type_immeuble, mode_gestion) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$proprietaireId, $societeId ?: null, $agenceId ?: null, $codeCrg, $nomImm, ($vImm ?: $adrImm), $cpImm ?: null, $viImm ?: null, 'immeuble', 'gestion']);
                    $idImmeuble = (int)$pdo->lastInsertId();
                }
            }
            $immeubleIds[$idImmeuble] = true;

            foreach ($imm['lots'] ?? [] as $lot) {
                $nbLots++;
                $numLot = $lot['numero_lot'] ?? ''; $typeBien = $lot['type_bien'] ?? 'appartement';

                $locataires = $lot['locataires'] ?? null;
                if ($locataires === null) {
                    $locataires = [];
                    if (!empty($lot['locataire_nom'])) {
                        $locataires[] = [
                            'nom' => $lot['locataire_nom'],
                            'actif' => (($lot['statut'] ?? '') === 'occupe' || ($lot['statut'] ?? '') === 'occupé'),
                            'loyer_appele' => $lot['loyer_appele'] ?? 0, 'charges_provisions' => $lot['charges_provisions'] ?? 0,
                            'solde_anterieur' => $lot['solde_anterieur'] ?? 0, 'total_loyers' => $lot['total_loyers'] ?? 0,
                            'total_charges' => $lot['total_charges'] ?? 0, 'total_regle' => $lot['total_regle'] ?? 0,
                            'total_impaye' => $lot['total_impaye'] ?? 0, 'date_bail' => $lot['date_bail'] ?? null,
                        ];
                    }
                }

                // Occupé = loyer appelé > 0 (doctrine CRG), en repli le flag `actif` du parser.
                // « LOGEMENT VACANT » ne compte jamais comme occupé.
                $hasActif = false;
                foreach ($locataires as $loc) {
                    $nomL = strtoupper(trim((string)($loc['nom'] ?? '')));
                    if ($nomL === 'LOGEMENT VACANT') continue;
                    if (!empty($loc['actif']) || (float)($loc['loyer_appele'] ?? 0) > 0) { $hasActif = true; break; }
                }
                $statutOcc = $hasActif ? 'occupé' : 'vacant';

                $idBien = 0;
                // [ANTI-DOUBLON] On retrouve le bien EXISTANT par code_crg (déjà importé, format
                // CODE_LOT) ou par reference_bien du bien ENRICHI (format CODE-LOT) AVANT le simple
                // (immeuble+lot) — sinon un format/OCR différent crée un squelette à côté du vrai
                // bien (cause n°1 des doublons SMH/SIR/SABY). On garde le plus riche (enrichi > squelette).
                $refDash = ($codeCrg !== '' && $numLot !== '') ? ($codeCrg . '-' . $numLot) : '';
                $codeUnd = ($codeCrg !== '' && $numLot !== '') ? ($codeCrg . '_' . $numLot) : '';
                $st = $pdo->prepare("SELECT id FROM biens
                    WHERE (statut_bien IS NULL OR statut_bien NOT IN ('supprime','archive'))
                      AND ( (? <> '' AND code_crg = ?)
                         OR (? <> '' AND UPPER(REPLACE(reference_bien,' ','')) = UPPER(?))
                         OR (id_immeuble = ? AND numero_lot = ? AND numero_lot <> '') )
                    ORDER BY ((CASE WHEN reference_bien IS NOT NULL AND reference_bien<>'' THEN 8 ELSE 0 END)
                             +(CASE WHEN COALESCE(NULLIF(surface_habitable,0),NULLIF(surface_carrez,0),0)>0 THEN 4 ELSE 0 END)) DESC, id DESC
                    LIMIT 1");
                $st->execute([$codeUnd, $codeUnd, $refDash, $refDash, $idImmeuble, $numLot]);
                $bienRow = $st->fetch(PDO::FETCH_ASSOC);
                if ($bienRow) {
                    $idBien = (int)$bienRow['id'];
                    // On COMPLÈTE le bien enrichi (code_crg/lot/immeuble/proprio manquants) — sans
                    // écraser ce qui existe — au lieu de créer un doublon.
                    $pdo->prepare("UPDATE biens SET code_crg=COALESCE(NULLIF(code_crg,''),?),
                          numero_lot=COALESCE(NULLIF(numero_lot,''),?), id_immeuble=COALESCE(id_immeuble,?),
                          id_proprietaire=COALESCE(id_proprietaire,?) WHERE id=?")
                        ->execute([$codeUnd ?: null, $numLot ?: null, $idImmeuble, $proprietaireId, $idBien]);
                    if ($hasActif) $pdo->prepare('UPDATE biens SET statut_occupation=? WHERE id=?')->execute(['occupé', $idBien]);
                } else {
                    // ANTI-DOUBLON : adopter un bien d'ANNONCE de cet immeuble (sans code_crg
                    // ni numéro de lot) au lieu de créer un doublon. 1 bien = 1 lot.
                    $exclude = $adoptedBiens ? implode(',', array_map('intval', $adoptedBiens)) : '0';
                    $stA = $pdo->prepare("SELECT id FROM biens
                        WHERE id_immeuble=? AND (code_crg IS NULL OR code_crg='')
                          AND (numero_lot IS NULL OR numero_lot='')
                          AND id NOT IN ($exclude)
                          AND (statut_bien IS NULL OR statut_bien NOT IN ('supprime','archive'))
                        ORDER BY id LIMIT 1");
                    $stA->execute([$idImmeuble]);
                    $adopt = (int)$stA->fetchColumn();
                    if ($adopt > 0) {
                        $pdo->prepare("UPDATE biens SET id_proprietaire=?, numero_lot=?, code_crg=?, statut_occupation=? WHERE id=?")
                            ->execute([$proprietaireId, $numLot, $codeCrg . '_' . $numLot, $statutOcc, $adopt]);
                        $idBien = $adopt; $adoptedBiens[] = $adopt;
                        error_log("[crg dedup] bien annonce adopté #$idBien (lot $numLot, imm $idImmeuble)");
                    } else {
                        // Type sur les DEUX colonnes (moderne id_bien_type + legacy id_type_bien)
                        // via le résolveur officiel. Le libellé CRG est normalisé en code canonique.
                        require_once __DIR__ . '/bien_type_helper.php';
                        $crgTypeCanon = ['appartement'=>'appartement','maison'=>'maison','commerce'=>'local_commercial','local'=>'local_commercial','parking'=>'parking','cave'=>'cave','bureau'=>'bureau','garage'=>'garage','box'=>'box','autre'=>'appartement'];
                        $tt = bien_type_resolve($pdo, $crgTypeCanon[strtolower(trim((string)$typeBien))] ?? 'appartement');
                        $idTypeBien = $tt['id_type_bien']; $idBienType = $tt['id_bien_type'];
                        $pdo->prepare('INSERT INTO biens (id_immeuble, id_proprietaire, id_societe, id_type_bien, id_bien_type, numero_lot, code_crg, statut_occupation, surface_habitable, statut_bien) VALUES (?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$idImmeuble, $proprietaireId, $societeId ?: null, $idTypeBien, $idBienType, $numLot, $codeCrg . '_' . $numLot, $statutOcc, $lot['surface'] ?? null, 'actif']);
                        $idBien = (int)$pdo->lastInsertId();
                    }
                }

                // ── BAIL dans bien_baux (table canonique unique) ──
                // Locataire du bail = le PRÉSENT (loyer appelé > 0), en repli le flag `actif`.
                // On prend celui au plus fort loyer appelé. On exclut occupant propriétaire /
                // logement vacant (pas de bail locataire pour ceux-là).
                $activeName = null; $activeDate = null; $activeLoyerAppele = -1.0;
                foreach ($locataires as $loc) {
                    $nomL = trim((string)($loc['nom'] ?? ''));
                    if ($nomL === '' || strcasecmp($nomL, 'LOGEMENT VACANT') === 0 || strcasecmp($nomL, 'OCCUPÉ PAR PROPRIÉTAIRE') === 0) continue;
                    $ly = (float)($loc['loyer_appele'] ?? 0);
                    if (($ly > 0 || !empty($loc['actif'])) && $ly > $activeLoyerAppele) {
                        $activeName = $nomL; $activeDate = $loc['date_bail'] ?? null; $activeLoyerAppele = $ly;
                    }
                }
                // Loyer MENSUEL HC du lot (déjà ramené au mois par le parser, jamais ÷3 ici).
                // Alimente bien_baux.loyer_mensuel_hc = « Loyer de base » de la fiche.
                $loyerMensuel = round((float)($lot['loyer_mensuel'] ?? 0), 2);

                $activeBailId = null;
                try {
                    $stCur = $pdo->prepare("SELECT id, COALESCE(NULLIF(locataire_raison_sociale,''), locataire_nom) AS nom
                                            FROM bien_baux WHERE id_bien=? AND statut='actif' ORDER BY id DESC LIMIT 1");
                    $stCur->execute([$idBien]);
                    $cur = $stCur->fetch(PDO::FETCH_ASSOC);
                    $curName = $cur ? trim((string)$cur['nom']) : null;
                    $same = $activeName !== null && $curName !== null && crg_core_norm($curName) === crg_core_norm($activeName);

                    if ($same) {
                        $activeBailId = (int)$cur['id'];
                        // Backfill loyer si le bail existant est à 0/NULL (re-import des baux créés avant ce correctif).
                        if ($loyerMensuel > 0) {
                            $pdo->prepare("UPDATE bien_baux SET loyer_mensuel_hc=? WHERE id=? AND (loyer_mensuel_hc IS NULL OR loyer_mensuel_hc=0)")
                                ->execute([$loyerMensuel, $activeBailId]);
                        }
                    } else {
                        if ($cur) {
                            $pdo->prepare("UPDATE bien_baux SET statut='archive', date_fin=COALESCE(date_fin, ?) WHERE id=?")
                                ->execute([$dateArrete, (int)$cur['id']]);
                            $nbBascules++;
                        }
                        if ($activeName !== null) {
                            $stEx = $pdo->prepare("SELECT id FROM bien_baux WHERE id_bien=? AND LOWER(TRIM(locataire_nom))=LOWER(TRIM(?)) ORDER BY id DESC LIMIT 1");
                            $stEx->execute([$idBien, $activeName]);
                            $ex = $stEx->fetch(PDO::FETCH_ASSOC);
                            if ($ex) {
                                $activeBailId = (int)$ex['id'];
                                $pdo->prepare("UPDATE bien_baux SET statut='actif', date_prise_effet=COALESCE(date_prise_effet, ?),
                                                loyer_mensuel_hc = CASE WHEN ? > 0 THEN ? ELSE loyer_mensuel_hc END WHERE id=?")
                                    ->execute([$activeDate ?: null, $loyerMensuel, $loyerMensuel, $activeBailId]);
                            } else {
                                $pdo->prepare("INSERT INTO bien_baux (id_bien, id_proprietaire, locataire_nom, loyer_mensuel_hc, statut, date_prise_effet) VALUES (?,?,?,?,'actif',?)")
                                    ->execute([$idBien, $proprietaireId, $activeName, $loyerMensuel ?: null, $activeDate ?: null]);
                                $activeBailId = (int)$pdo->lastInsertId();
                            }
                            $nbBascules++;
                        }
                    }
                } catch (Throwable $exBail) {
                    error_log('[crg_apply bail] bien#' . $idBien . ' : ' . $exBail->getMessage());
                }
                if ($activeBailId) { $bailIdsTouched[$activeBailId] = true; }
                if ($idBien > 0) { $bienIdsTouched[$idBien] = true; }
                // TRAÇABILITÉ : marquer le CRG source (trimestre) sur le bien et le bail touchés.
                // Réécrit à CHAQUE import → reflète toujours le dernier CRG intégré (T2 2026, puis
                // T3, …). Défensif : sans effet si la migration source_crg_id n'est pas encore passée.
                try {
                    if ($idBien > 0)   $pdo->prepare("UPDATE biens SET source_crg_id=? WHERE id=?")->execute([$crgId, $idBien]);
                    if ($activeBailId) $pdo->prepare("UPDATE bien_baux SET source_crg_id=? WHERE id=?")->execute([$crgId, $activeBailId]);
                } catch (Throwable $eSrc) { /* colonne absente : sans gravité */ }

                foreach ($locataires as $loc) {
                    $nom = trim((string)($loc['nom'] ?? ''));
                    if ($nom === '') continue;
                    $estActif = !empty($loc['actif']);
                    if ($estActif && $activeName !== null && crg_core_norm($nom) === crg_core_norm($activeName)) {
                        $idBail = $activeBailId;
                    } else {
                        $stB = $pdo->prepare("SELECT id FROM bien_baux WHERE id_bien=? AND LOWER(TRIM(locataire_nom))=LOWER(TRIM(?)) ORDER BY (statut='actif') DESC, id DESC LIMIT 1");
                        $stB->execute([$idBien, $nom]);
                        $b = $stB->fetch(PDO::FETCH_ASSOC);
                        $idBail = $b ? (int)$b['id'] : null;
                    }
                    $statutTrim = $estActif ? 'occupé' : 'parti-débiteur';
                    $pdo->prepare('INSERT INTO crg_situations_locataires
                        (id_crg, id_bien, id_bail, locataire_nom, numero_lot, type_bien,
                         loyer_appele, solde_anterieur, total_loyers, total_charges, total_regle, total_impaye, statut_trimestre)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                        $crgId, $idBien, $idBail, $nom, $numLot, $typeBien,
                        $loc['loyer_appele'] ?? 0, $loc['solde_anterieur'] ?? 0, $loc['total_loyers'] ?? 0,
                        $loc['total_charges'] ?? 0, $loc['total_regle'] ?? 0, $loc['total_impaye'] ?? 0, $statutTrim,
                    ]);
                }
            }

            foreach ($imm['ecritures'] ?? [] as $ecr) {
                $nbEcritures++;
                $pdo->prepare('INSERT INTO crg_ecritures (id_crg, libelle, categorie, debit, credit, tva) VALUES (?,?,?,?,?,?)')
                    ->execute([$crgId, $ecr['libelle'] ?? '', $ecr['categorie'] ?? 'autre', $ecr['debit'] ?? 0, $ecr['credit'] ?? 0, $ecr['tva'] ?? 0]);
            }
        }

        // ── LOT INTERNE garanti sur chaque bien du CRG (jamais de bien sans lot) ──
        if (is_file(__DIR__ . '/bien_lot.php')) {
            require_once __DIR__ . '/bien_lot.php';
            foreach (array_keys($bienIdsTouched) as $biId) { try { bien_ensure_lot_interne($pdo, (int)$biId); } catch (Throwable) {} }
        }

        // ── MANDAT DE GESTION automatique sur chaque bien du CRG ──
        // Un bien géré via CRG est, par définition, en GESTION (même s'il appartient à
        // un immeuble en syndic) → il doit porter un mandat de gestion ACTIF. Seuls les
        // biens en annonce de VENTE dès l'origine en sont exclus, or un bien présent dans
        // un CRG n'entre jamais dans ce cas. Numéro déterministe AUTO-G-CRG-<id> (cohérent
        // avec la migration 20260611). Idempotent : actif → rien ; archivé → réactivé ;
        // sinon création (évite la collision sur uk_mandats_numero au ré-import).
        $nbMandats = 0;
        $stMActive = $pdo->prepare("SELECT id FROM mandats WHERE id_bien=? AND statut='actif' AND type_mandat IN ('gestion','gerance') LIMIT 1");
        $stMAny    = $pdo->prepare("SELECT id FROM mandats WHERE id_bien=? AND type_mandat='gestion' ORDER BY id DESC LIMIT 1");
        $stMRevive = $pdo->prepare("UPDATE mandats SET statut='actif', date_fin=NULL,
                                        id_proprietaire=COALESCE(id_proprietaire,?), id_agence=COALESCE(id_agence,?),
                                        date_debut=COALESCE(date_debut, CURDATE()), date_modification=NOW()
                                    WHERE id=?");
        $stMIns    = $pdo->prepare("INSERT INTO mandats
                (id_bien, id_proprietaire, id_agence, id_user, numero_mandat, type_mandat, nature_mandat, exclusif, date_debut, statut, date_creation)
             SELECT b.id, b.id_proprietaire, b.id_agence, ?, CONCAT('AUTO-G-CRG-', b.id), 'gestion', NULL, 0, CURDATE(), 'actif', NOW()
               FROM biens b WHERE b.id=? AND (b.statut_bien IS NULL OR b.statut_bien <> 'supprime')");
        foreach (array_keys($bienIdsTouched) as $biId) {
            try {
                $stMActive->execute([$biId]);
                if ($stMActive->fetchColumn()) continue;                 // déjà un mandat gestion actif
                $stMAny->execute([$biId]);
                $reviveId = (int)($stMAny->fetchColumn() ?: 0);
                if ($reviveId > 0) { $stMRevive->execute([$proprietaireId ?: null, $agenceId ?: null, $reviveId]); }
                else               { $stMIns->execute([$userId ?: null, $biId]); }
                $nbMandats++;
            } catch (Throwable $exM) {
                error_log('[crg_apply mandat] bien#' . $biId . ' : ' . $exM->getMessage());
            }
        }

        // ── Archivage GED du PDF → propriétaire (TIERS) + cascade immeuble/bail ──
        $gedStatut = 'skip';
        $gedError  = null;
        $gedDocId  = 0;
        if ($pdfAbs && is_file($pdfAbs)) {
            try {
                // NB : proprietaires n'a PAS de colonne id_societe → la société est
                // dérivée du contexte d'appel ($societeId) via le fallback ci-dessous.
                $stP = $pdo->prepare("SELECT id_tiers, id_agence FROM proprietaires WHERE id = ? LIMIT 1");
                $stP->execute([$proprietaireId]);
                $prop = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
                $tiersId = (int)($prop['id_tiers'] ?? 0);
                $propSoc = isset($prop['id_societe']) && $prop['id_societe'] !== null ? (int)$prop['id_societe'] : ($societeId ?: null);
                $propAge = isset($prop['id_agence'])  && $prop['id_agence']  !== null ? (int)$prop['id_agence']  : ($agenceId ?: null);

                $links = [];
                if ($tiersId > 0) $links[] = ['entity_type'=>'TIERS', 'entity_id'=>$tiersId, 'relation_type'=>'main', 'is_validated'=>1, 'validated_by'=>$userId ?: null];
                foreach (array_keys($immeubleIds) as $imId)  $links[] = ['entity_type'=>'IMB',  'entity_id'=>(int)$imId, 'relation_type'=>'reference'];
                foreach (array_keys($bienIdsTouched) as $biId) $links[] = ['entity_type'=>'BIEN', 'entity_id'=>(int)$biId, 'relation_type'=>'reference'];
                foreach (array_keys($bailIdsTouched) as $bId) $links[] = ['entity_type'=>'BAIL', 'entity_id'=>(int)$bId,  'relation_type'=>'reference'];

                if ($links) {
                    // Nom du PDF GED avec le PROPRIÉTAIRE (ex. CRG_SCI_FAVRE_2026_T1.pdf).
                    $propNomGed = '';
                    try {
                        // Société pour une morale, sinon NOM + prénom (jamais le prénom seul).
                        $qn = $pdo->prepare("SELECT CASE WHEN COALESCE(societe,'')<>'' THEN societe
                                                          ELSE TRIM(CONCAT_WS(' ', nom, NULLIF(prenom,''))) END
                                             FROM proprietaires WHERE id=?");
                        $qn->execute([$proprietaireId]); $propNomGed = (string)$qn->fetchColumn();
                    } catch (Throwable) {}
                    // Slug sans accents, MAJUSCULES (ex. CRG_RENAUD_FREDERIC_2026_T1.pdf).
                    $slugSrc  = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $propNomGed) ?: $propNomGed;
                    $propSlug = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $slugSrc), '_'));
                    $srcName = 'CRG_' . ($propSlug !== '' ? $propSlug . '_' : '') . $annee . '_T' . $trimestre . '.pdf';
                    $gedRes = gus_commit_document(
                        $pdo,
                        ['path_on_disk'=>$pdfAbs, 'name_original'=>$srcName, 'mime_type'=>'application/pdf', 'size_bytes'=>(int)@filesize($pdfAbs), 'public_url'=>$pdfUrl],
                        [
                            'document_type'=>'crg', 'source_module'=>'05_GESTION_LOCATIVE', 'security_level'=>'interne',
                            'societe_id'=>$propSoc, 'agence_id'=>$propAge, 'tenant_id'=>$propSoc, 'created_by'=>$userId ?: null,
                            'metadata_extra'=>['crg_id'=>$crgId, 'annee'=>$annee, 'trimestre'=>$trimestre],
                            'naming_ctx'=>[
                                'n1_slug'=>'05_gestion_locative', 'n2_slug'=>'02_crg', 'n3_slug'=>'01_crg_trimestriel',
                                'type_doc'=>'crg', 'entity_type'=>($tiersId>0?'TIERS':'IMB'),
                                'entity_id'=>($tiersId>0?$tiersId:(int)array_key_first($immeubleIds)),
                                'source_filename'=>$srcName, 'ext'=>'pdf', 'date_doc'=>($periode['date_arrete'] ?? null),
                            ],
                        ],
                        $links
                    );
                    $gedStatut = !empty($gedRes['ok']) ? (!empty($gedRes['deduplicated']) ? 'dedup' : 'ok') : 'err';
                    if ($gedStatut === 'err') {
                        $gedError = implode(' / ', $gedRes['errors'] ?? ['echec']);
                        error_log('[crg_apply GED] crg#' . $crgId . ' : ' . $gedError);
                    } elseif (!empty($gedRes['doc_id'])) {
                        // Traçabilité + idempotence : on mémorise le doc GED sur le trimestre.
                        $gedDocId = (int)$gedRes['doc_id'];
                        try {
                            $pdo->prepare('UPDATE crg_trimestres SET ged_document_id=? WHERE id=?')
                                ->execute([$gedDocId, $crgId]);
                        } catch (Throwable) {}
                    }
                } else {
                    $gedError = 'aucun lien (tiers/immeuble/bail introuvable)';
                }
            } catch (Throwable $exGed) {
                $gedStatut = 'err';
                $gedError  = $exGed->getMessage();
                error_log('[crg_apply GED] crg#' . $crgId . ' : ' . $exGed->getMessage());
            }
        }

        return [
            'ok'     => true,
            'crg_id' => $crgId,
            'stats'  => ['immeubles'=>$nbImmeubles, 'lots'=>$nbLots, 'ecritures'=>$nbEcritures, 'bascules'=>$nbBascules, 'mandats'=>$nbMandats, 'ged'=>$gedStatut, 'ged_error'=>$gedError, 'ged_document_id'=>$gedDocId, 'annee'=>$annee, 'trimestre'=>$trimestre],
            'error'  => null,
        ];
    }
}
