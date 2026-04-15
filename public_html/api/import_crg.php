<?php
/**
 * api/import_crg.php — Import CRG via extraction IA (GPT-4o)
 *
 * Étape 1 (action=parse)  : Upload PDF → extraction texte → analyse IA → retourne JSON preview
 * Étape 2 (action=confirm) : Reçoit le JSON validé → crée propriétaire/immeubles/lots/baux/écritures
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ia_analyse.php';
require_login();
verify_csrf_any();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(180);
ini_set('max_execution_time', '180');

$pdo       = $GLOBALS['pdo'];
$userId    = (int)current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence'] ?? 0);

$action = $_POST['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════
// ÉTAPE 1 : PARSE — Upload PDF, extraction texte, analyse IA
// ═══════════════════════════════════════════════════════════════════
if ($action === 'parse') {
    if (empty($_FILES['fichier_crg']['tmp_name']) || $_FILES['fichier_crg']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Fichier PDF requis.']);
        exit;
    }

    $tmp  = $_FILES['fichier_crg']['tmp_name'];
    $mime = mime_content_type($tmp);
    if ($mime !== 'application/pdf') {
        echo json_encode(['ok' => false, 'error' => 'Le fichier doit être un PDF (détecté : ' . $mime . ').']);
        exit;
    }
    if ($_FILES['fichier_crg']['size'] > 50 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 50 Mo).']);
        exit;
    }

    // Sauvegarder temporairement
    $tmpDir = __DIR__ . '/../uploads/_tmp/';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $tmpPath = $tmpDir . 'crg_' . uniqid('', true) . '.pdf';
    move_uploaded_file($tmp, $tmpPath);

    // Extraire le texte
    $text = extractPdfText($tmpPath);
    if (strlen(trim($text)) < 100) {
        @unlink($tmpPath);
        echo json_encode(['ok' => false, 'error' => 'Impossible d\'extraire le texte du PDF. Vérifiez que le document n\'est pas un scan sans OCR.']);
        exit;
    }

    // Analyse IA
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    // Forcer gpt-4o pour l'import CRG (rapide, fiable, pas de reasoning tokens excessifs)
    $model = 'gpt-4o';

    if (!$api_key) {
        @unlink($tmpPath);
        echo json_encode(['ok' => false, 'error' => 'Clé API OpenAI non configurée.']);
        exit;
    }

    $text_truncated = mb_substr($text, 0, 40000);

    $system_prompt = "Tu es un expert-comptable spécialisé en gestion locative française. "
        . "Tu analyses des Comptes-Rendus de Gestion (CRG) trimestriels envoyés par les gestionnaires aux propriétaires bailleurs. "
        . "Tu extrais TOUTES les données structurées. Tu réponds UNIQUEMENT en JSON valide.";

    $user_prompt = <<<PROMPT
Analyse ce Compte-Rendu de Gestion (CRG) et extrais toutes les données structurées.

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
  "proprietaire": {
    "nom": "string (nom complet du propriétaire bailleur)",
    "adresse": "string (adresse du propriétaire si présente)",
    "email": "string ou null",
    "telephone": "string ou null"
  },
  "gestionnaire": {
    "nom": "string (nom de la société de gestion)",
    "reference_mandat": "string ou null (numéro de mandat si mentionné)"
  },
  "periode": {
    "annee": "number",
    "trimestre": "number (1-4)",
    "date_arrete": "string (date d'arrêté des comptes, format YYYY-MM-DD)"
  },
  "solde_report": "number (solde reporté du trimestre précédent)",
  "total_debits": "number",
  "total_credits": "number",
  "solde_final": "number",
  "immeubles": [
    {
      "code": "string (code/référence interne de l'immeuble)",
      "nom": "string (nom ou adresse de l'immeuble)",
      "adresse": "string (adresse complète)",
      "lots": [
        {
          "numero_lot": "string",
          "type_bien": "appartement|maison|commerce|parking|cave|bureau|autre",
          "etage": "string ou null",
          "surface": "number ou null",
          "locataire_nom": "string (nom du locataire, vide si vacant)",
          "statut": "occupe|vacant|conge_donne",
          "loyer_appele": "number (loyer mensuel appelé)",
          "charges_provisions": "number (provisions pour charges)",
          "solde_anterieur": "number",
          "total_loyers": "number (total des loyers du trimestre)",
          "total_charges": "number (total charges du trimestre)",
          "total_regle": "number (total réglé par le locataire)",
          "total_impaye": "number (impayé résiduel)",
          "date_bail": "string ou null (date du bail, format YYYY-MM-DD)"
        }
      ],
      "ecritures": [
        {
          "libelle": "string (intitulé de l'écriture)",
          "categorie": "loyer|charge|travaux|assurance|taxe|honoraires|autre",
          "debit": "number",
          "credit": "number",
          "tva": "number"
        }
      ]
    }
  ]
}

Règles :
- Extrais TOUS les immeubles et TOUS les lots mentionnés
- Les montants sont en euros, sans symbole
- Si un champ est absent, utilise null ou 0
- Le type_bien doit correspondre aux valeurs proposées
- Si le locataire est parti, statut = "conge_donne"

TEXTE DU CRG :
{$text_truncated}
PROMPT;

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $user_prompt],
        ],
        'temperature'     => 0.1,
        'max_tokens'      => 16000,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        @unlink($tmpPath);
        error_log('[import_crg] OpenAI HTTP ' . $httpCode . ': ' . $response);
        echo json_encode(['ok' => false, 'error' => 'Erreur API IA (HTTP ' . $httpCode . '): ' . substr($response, 0, 500), 'model' => $model, 'text_len' => strlen($text_truncated)]);
        exit;
    }

    $result = json_decode($response, true);
    if (!$result) {
        @unlink($tmpPath);
        echo json_encode(['ok' => false, 'error' => 'Réponse API non-JSON.', 'raw' => substr($response, 0, 2000)]);
        exit;
    }
    $content = $result['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        @unlink($tmpPath);
        echo json_encode(['ok' => false, 'error' => 'Contenu IA vide.', 'raw' => substr(json_encode($result), 0, 2000)]);
        exit;
    }

    // Nettoyer le JSON (enlever ```json ... ```)
    $content = trim($content);
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
    }

    $parsed = json_decode($content, true);
    if (!$parsed) {
        // Tenter de trouver le JSON dans la réponse (parfois entouré de texte)
        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!$parsed) {
        @unlink($tmpPath);
        error_log('[import_crg] JSON parse failed: ' . substr($content, 0, 1000));
        echo json_encode(['ok' => false, 'error' => 'L\'IA n\'a pas retourné un JSON valide. Réessayez.', 'raw' => substr($content, 0, 2000)]);
        exit;
    }

    // Garder le chemin PDF pour l'étape 2
    $_SESSION['crg_tmp_path'] = $tmpPath;
    $_SESSION['crg_parsed']   = $parsed;

    echo json_encode(['ok' => true, 'data' => $parsed]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// ÉTAPE 2 : CONFIRM — Créer les entités en base
// ═══════════════════════════════════════════════════════════════════
if ($action === 'confirm') {
    try {
    $parsed = $_SESSION['crg_parsed'] ?? null;
    $tmpPath = $_SESSION['crg_tmp_path'] ?? null;

    if (!$parsed) {
        echo json_encode(['ok' => false, 'error' => 'Aucune analyse en session. Relancez l\'import.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // Propriétaire : créer ou réutiliser
    $proprietaireId = (int)($data['id_proprietaire'] ?? 0);
    if ($proprietaireId <= 0 && !empty($parsed['proprietaire']['nom'])) {
        $prop = $parsed['proprietaire'];
        $stmt = $pdo->prepare("INSERT INTO proprietaires (nom, societe, adresse_1, email, telephone, actif, type_personne, id_agence) VALUES (?, ?, ?, ?, ?, 1, 'morale', ?)");
        $stmt->execute([
            $prop['nom'],
            $prop['nom'],
            $prop['adresse'] ?? null,
            $prop['email'] ?? null,
            $prop['telephone'] ?? null,
            $agenceId ?: null,
        ]);
        $proprietaireId = (int)$pdo->lastInsertId();
    }

    $periode  = $parsed['periode'] ?? [];
    $annee    = (int)($periode['annee'] ?? date('Y'));
    $trimestre = (int)($periode['trimestre'] ?? 1);

    // Sauvegarder le PDF définitivement
    $pdfRelPath = null;
    if ($tmpPath && is_file($tmpPath)) {
        $destDir = __DIR__ . '/../uploads/crg/' . $proprietaireId;
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $filename = $annee . '_T' . $trimestre . '.pdf';
        $destPath = $destDir . '/' . $filename;
        rename($tmpPath, $destPath);
        $pdfRelPath = $proprietaireId . '/' . $filename;
    }

    // CRG trimestre
    $stmt = $pdo->prepare('SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?');
    $stmt->execute([$proprietaireId, $annee, $trimestre]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $crgId = (int)$existing['id'];
        $pdo->prepare('DELETE FROM crg_situations_locataires WHERE id_crg=?')->execute([$crgId]);
        $pdo->prepare('DELETE FROM crg_ecritures WHERE id_crg=?')->execute([$crgId]);
        $pdo->prepare('UPDATE crg_trimestres SET fichier_pdf=?, parse_statut="ok", uploaded_at=NOW(), date_arrete=?, solde_report=?, total_debits=?, total_credits=? WHERE id=?')
            ->execute([$pdfRelPath, $periode['date_arrete'] ?? null, $parsed['solde_report'] ?? 0, $parsed['total_debits'] ?? 0, $parsed['total_credits'] ?? 0, $crgId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO crg_trimestres (id_proprietaire, annee, trimestre, fichier_pdf, parse_statut, date_arrete, solde_report, total_debits, total_credits, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$proprietaireId, $annee, $trimestre, $pdfRelPath, 'ok', $periode['date_arrete'] ?? null, $parsed['solde_report'] ?? 0, $parsed['total_debits'] ?? 0, $parsed['total_credits'] ?? 0]);
        $crgId = (int)$pdo->lastInsertId();
    }

    $nbImmeubles = 0;
    $nbLots = 0;
    $nbEcritures = 0;

    foreach ($parsed['immeubles'] ?? [] as $imm) {
        $nbImmeubles++;
        $codeCrg = $imm['code'] ?? '';
        $nomImm  = $imm['nom'] ?? '';
        $adrImm  = $imm['adresse'] ?? '';

        // Find or create immeuble
        $stmt = $pdo->prepare('SELECT id FROM immeubles WHERE code_crg=? AND id_proprietaire=?');
        $stmt->execute([$codeCrg, $proprietaireId]);
        $immRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($immRow) {
            $idImmeuble = (int)$immRow['id'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO immeubles (id_proprietaire, id_societe, id_agence, code_crg, nom_immeuble, adresse_1, type_immeuble, mode_gestion) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$proprietaireId, $societeId ?: null, $agenceId ?: null, $codeCrg, $nomImm, $adrImm, 'immeuble', 'gestion']);
            $idImmeuble = (int)$pdo->lastInsertId();
        }

        // Lots
        foreach ($imm['lots'] ?? [] as $lot) {
            $nbLots++;
            $numLot    = $lot['numero_lot'] ?? '';
            $typeBien  = $lot['type_bien'] ?? 'appartement';
            $statutOcc = $lot['statut'] ?? 'vacant';

            $stmt = $pdo->prepare('SELECT id FROM biens WHERE id_immeuble=? AND numero_lot=?');
            $stmt->execute([$idImmeuble, $numLot]);
            $bienRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($bienRow) {
                $idBien = (int)$bienRow['id'];
                $pdo->prepare('UPDATE biens SET statut_occupation=? WHERE id=?')->execute([$statutOcc, $idBien]);
            } else {
                // Mapper type_bien vers id_type_bien
                $typeMap = ['appartement'=>2,'maison'=>1,'commerce'=>5,'parking'=>10,'cave'=>11,'bureau'=>6,'local'=>5,'autre'=>2];
                $idTypeBien = $typeMap[strtolower($typeBien)] ?? 2;
                $pdo->prepare('INSERT INTO biens (id_immeuble, id_proprietaire, id_societe, id_type_bien, numero_lot, statut_occupation, surface_habitable, statut_bien) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$idImmeuble, $proprietaireId, $societeId ?: null, $idTypeBien, $numLot, $statutOcc, $lot['surface'] ?? null, 'actif']);
                $idBien = (int)$pdo->lastInsertId();
            }

            // Bail
            $idBail = null;
            if ($statutOcc === 'occupe' && !empty($lot['locataire_nom'])) {
                $stmt = $pdo->prepare("SELECT id FROM baux WHERE id_bien=? AND statut='actif' LIMIT 1");
                $stmt->execute([$idBien]);
                $bailRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($bailRow) {
                    $idBail = (int)$bailRow['id'];
                } else {
                    $pdo->prepare("INSERT INTO baux (id_bien, locataire_nom, statut, date_debut) VALUES (?,?,'actif',?)")
                        ->execute([$idBien, $lot['locataire_nom'], $lot['date_bail'] ?? null]);
                    $idBail = (int)$pdo->lastInsertId();
                }
            }

            // Situation locataire
            $pdo->prepare('INSERT INTO crg_situations_locataires
                (id_crg, id_bien, id_bail, locataire_nom, numero_lot, type_bien,
                 loyer_appele, solde_anterieur, total_loyers, total_charges, total_regle, total_impaye, statut_trimestre)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $crgId, $idBien, $idBail,
                $lot['locataire_nom'] ?? '',
                $numLot, $typeBien,
                $lot['loyer_appele'] ?? 0,
                $lot['solde_anterieur'] ?? 0,
                $lot['total_loyers'] ?? 0,
                $lot['total_charges'] ?? 0,
                $lot['total_regle'] ?? 0,
                $lot['total_impaye'] ?? 0,
                $statutOcc,
            ]);
        }

        // Écritures
        foreach ($imm['ecritures'] ?? [] as $ecr) {
            $nbEcritures++;
            $pdo->prepare('INSERT INTO crg_ecritures (id_crg, libelle, categorie, debit, credit, tva) VALUES (?,?,?,?,?,?)')
                ->execute([$crgId, $ecr['libelle'] ?? '', $ecr['categorie'] ?? 'autre', $ecr['debit'] ?? 0, $ecr['credit'] ?? 0, $ecr['tva'] ?? 0]);
        }
    }

    // Nettoyage session
    unset($_SESSION['crg_parsed'], $_SESSION['crg_tmp_path']);

    echo json_encode([
        'ok' => true,
        'message' => "CRG importé : $nbImmeubles immeuble(s), $nbLots lot(s), $nbEcritures écriture(s).",
        'proprietaire_id' => $proprietaireId,
        'crg_id' => $crgId,
        'stats' => ['immeubles' => $nbImmeubles, 'lots' => $nbLots, 'ecritures' => $nbEcritures],
    ]);
    exit;
    } catch (Throwable $e) {
        error_log('[import_crg confirm] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['ok' => false, 'error' => 'Erreur import : ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
