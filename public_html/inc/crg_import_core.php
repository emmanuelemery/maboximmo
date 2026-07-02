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
  "proprietaire": { "nom": "string (nom complet du propriétaire bailleur)", "adresse": "string ou null", "email": "string ou null", "telephone": "string ou null" },
  "gestionnaire": { "nom": "string", "reference_mandat": "string ou null" },
  "periode": { "annee": "number", "trimestre": "number (1-4)", "date_arrete": "string (YYYY-MM-DD)" },
  "solde_report": "number", "total_debits": "number", "total_credits": "number", "solde_final": "number",
  "immeubles": [
    { "code": "string", "nom": "string", "adresse": "string",
      "lots": [
        { "numero_lot": "string (UN SEUL objet par numéro de lot)", "type_bien": "appartement|maison|commerce|parking|cave|bureau|local|autre", "etage": "string ou null", "surface": "number ou null",
          "locataires": [
            { "nom": "string", "actif": "boolean (true = loyer sur la période courante)", "loyer_appele": "number", "charges_provisions": "number", "solde_anterieur": "number", "total_loyers": "number", "total_charges": "number", "total_regle": "number", "total_impaye": "number", "date_bail": "string ou null (YYYY-MM-DD)" }
          ] }
      ],
      "ecritures": [ { "libelle": "string", "categorie": "loyer|charge|travaux|assurance|taxe|honoraires|autre", "debit": "number", "credit": "number", "tva": "number" } ]
    }
  ]
}

Règles CRUCIALES :
- Un même NUMÉRO DE LOT peut apparaître plusieurs fois avec des locataires différents : regroupe en UN SEUL "lot" avec plusieurs "locataires". PAS un lot par locataire.
- actif:true UNIQUEMENT si loyer sur la période courante. Un locataire avec seulement un "Solde Antérieur" = ancien parti (actif:false).
- Extrais TOUS les immeubles, lots et locataires (actifs ET anciens). Montants en euros sans symbole. Champ absent = null ou 0.

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
        if (preg_match('/^(.*?)[_\-\s]+(20\d\d)\b/u', $base, $m)) {
            $name = trim(str_replace(['_', '-'], ' ', $m[1]));
            // Retirer les civilités en tête (améliore le rapprochement de fiche).
            $name = preg_replace('/^(monsieur et madame|mr et mme|m\.? et mme|madame|monsieur|mademoiselle|melle|mme|mlle|mr|m\.)\s+/iu', '', $name) ?? $name;
            $name = trim($name);
            if (mb_strlen($name) >= 3 && !ctype_digit($name)) return $name;
        }
        return '';
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
            'proprietaire' => ['nom' => (string)($meta['proprietaire'] ?? '')],
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
        $immeubleIds = []; $bailIdsTouched = [];

        foreach ($parsed['immeubles'] ?? [] as $imm) {
            $nbImmeubles++;
            $codeCrg = $imm['code'] ?? ''; $nomImm = $imm['nom'] ?? ''; $adrImm = $imm['adresse'] ?? '';

            $st = $pdo->prepare('SELECT id FROM immeubles WHERE code_crg=? AND id_proprietaire=?');
            $st->execute([$codeCrg, $proprietaireId]);
            $immRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($immRow) {
                $idImmeuble = (int)$immRow['id'];
            } else {
                $pdo->prepare('INSERT INTO immeubles (id_proprietaire, id_societe, id_agence, code_crg, nom_immeuble, adresse_1, type_immeuble, mode_gestion) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$proprietaireId, $societeId ?: null, $agenceId ?: null, $codeCrg, $nomImm, $adrImm, 'immeuble', 'gestion']);
                $idImmeuble = (int)$pdo->lastInsertId();
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

                $hasActif = false;
                foreach ($locataires as $loc) { if (!empty($loc['actif'])) { $hasActif = true; break; } }
                $statutOcc = $hasActif ? 'occupé' : 'vacant';

                $st = $pdo->prepare('SELECT id FROM biens WHERE id_immeuble=? AND numero_lot=?');
                $st->execute([$idImmeuble, $numLot]);
                $bienRow = $st->fetch(PDO::FETCH_ASSOC);
                if ($bienRow) {
                    $idBien = (int)$bienRow['id'];
                    if ($hasActif) $pdo->prepare('UPDATE biens SET statut_occupation=? WHERE id=?')->execute(['occupé', $idBien]);
                } else {
                    $typeMap = ['appartement'=>2,'maison'=>1,'commerce'=>5,'parking'=>10,'cave'=>11,'bureau'=>6,'local'=>5,'autre'=>2];
                    $idTypeBien = $typeMap[strtolower($typeBien)] ?? 2;
                    $pdo->prepare('INSERT INTO biens (id_immeuble, id_proprietaire, id_societe, id_type_bien, numero_lot, statut_occupation, surface_habitable, statut_bien) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$idImmeuble, $proprietaireId, $societeId ?: null, $idTypeBien, $numLot, $statutOcc, $lot['surface'] ?? null, 'actif']);
                    $idBien = (int)$pdo->lastInsertId();
                }

                // ── BAIL dans bien_baux (table canonique unique) ──
                $activeName = null; $activeDate = null;
                foreach ($locataires as $loc) {
                    if (!empty($loc['actif']) && trim((string)($loc['nom'] ?? '')) !== '') {
                        $activeName = trim((string)$loc['nom']); $activeDate = $loc['date_bail'] ?? null; break;
                    }
                }

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
                                $pdo->prepare("UPDATE bien_baux SET statut='actif', date_prise_effet=COALESCE(date_prise_effet, ?) WHERE id=?")
                                    ->execute([$activeDate ?: null, $activeBailId]);
                            } else {
                                $pdo->prepare("INSERT INTO bien_baux (id_bien, id_proprietaire, locataire_nom, statut, date_prise_effet) VALUES (?,?,?,'actif',?)")
                                    ->execute([$idBien, $proprietaireId, $activeName, $activeDate ?: null]);
                                $activeBailId = (int)$pdo->lastInsertId();
                            }
                            $nbBascules++;
                        }
                    }
                } catch (Throwable $exBail) {
                    error_log('[crg_apply bail] bien#' . $idBien . ' : ' . $exBail->getMessage());
                }
                if ($activeBailId) { $bailIdsTouched[$activeBailId] = true; }

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

        // ── Archivage GED du PDF → propriétaire (TIERS) + cascade immeuble/bail ──
        $gedStatut = 'skip';
        $gedError  = null;
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
                foreach (array_keys($bailIdsTouched) as $bId) $links[] = ['entity_type'=>'BAIL', 'entity_id'=>(int)$bId,  'relation_type'=>'reference'];

                if ($links) {
                    $srcName = 'CRG_' . $annee . '_T' . $trimestre . '.pdf';
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
                        try {
                            $pdo->prepare('UPDATE crg_trimestres SET ged_document_id=? WHERE id=?')
                                ->execute([(int)$gedRes['doc_id'], $crgId]);
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
            'stats'  => ['immeubles'=>$nbImmeubles, 'lots'=>$nbLots, 'ecritures'=>$nbEcritures, 'bascules'=>$nbBascules, 'ged'=>$gedStatut, 'ged_error'=>$gedError, 'annee'=>$annee, 'trimestre'=>$trimestre],
            'error'  => null,
        ];
    }
}
