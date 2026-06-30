<?php
// api/biens_documents_reanalyze.php — Relance l'analyse IA sur un document déjà uploadé
//
// Pourquoi : si un document a été uploadé en "Divers" et mal classifié, ou si on
// a fait évoluer le prompt IA, on peut le réanalyser sans avoir à re-uploader
// le PDF. Le coût IA reste le même côté OpenAI (un nouveau call), mais l'user
// ne paie pas en temps (pas de re-glisser le fichier) ni en risque d'erreur de
// classification manuelle.
//
// POST { id_doc, csrf_token }
//   - id_doc : "ged_<id>" (cas nominal Sprint 7D) ou "<id>" legacy biens_documents
//
// Flux :
//   1. Charge le ged_documents (ou biens_documents en legacy), lit le path disque
//   2. Re-extrait le texte natif (ou OCR Vision si scan)
//   3. Re-route vers analyseBienIntakeIA() en force_type='divers' (auto-detect réel)
//   4. Met à jour ged_documents.document_type + metadata.extra.ia_result
//   5. Si type re-détecté = bail / diag / mandat, on log mais on NE crée PAS de
//      doublon dans bien_baux/dpe_diags (à faire dans une 2e étape si besoin).
//   6. Retourne fields + doc_type au front.
declare(strict_types=1);
set_time_limit(120);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_import_parser.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ia.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ocr.php';
require_once dirname(__DIR__) . '/inc/bien_apply_extracted.php';
require_once dirname(__DIR__) . '/inc/ged_doc_naming_v3.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$roleId    = (int)($_SESSION['id_role']    ?? 0);

$raw = isset($_POST['id_doc']) ? trim((string)$_POST['id_doc']) : '';
if ($raw === '') exit(json_encode(['ok' => false, 'error' => 'id_doc manquant']));

$useGed = false;
if (str_starts_with($raw, 'ged_')) { $useGed = true; $raw = substr($raw, 4); }
if (!ctype_digit($raw)) exit(json_encode(['ok' => false, 'error' => 'id_doc invalide']));
$idDoc = (int)$raw;

try {
    // ─── 1. Récup path physique + scope ────────────────────
    $publicUrl = '';
    $nameOrig  = '';
    $docTypePrev = '';
    if ($useGed) {
        $st = $pdo->prepare("SELECT id, name_display, name_file, document_type,
                                    metadata, societe_id, tenant_id
                               FROM ged_documents
                              WHERE id = ? AND COALESCE(status,'active') = 'active'
                              LIMIT 1");
        $st->execute([$idDoc]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document GED introuvable']));
        $docSoc = (int)($row['societe_id'] ?? 0);
        if ($roleId !== 1 && $societeId > 0 && $docSoc > 0 && $docSoc !== $societeId) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
        }
        $meta = json_decode((string)$row['metadata'], true) ?: [];
        $publicUrl = (string)($meta['public_url'] ?? '');
        $nameOrig  = (string)($row['name_display'] ?? $row['name_file']);
        $docTypePrev = (string)($row['document_type'] ?? '');
    } else {
        $st = $pdo->prepare("SELECT bd.id, bd.id_bien, bd.url_fichier, bd.nom_original,
                                    bd.type_document, b.id_societe
                               FROM biens_documents bd
                               JOIN biens b ON b.id = bd.id_bien
                              WHERE bd.id = ? LIMIT 1");
        $st->execute([$idDoc]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document introuvable']));
        if ($roleId !== 1 && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
        }
        $publicUrl   = (string)$row['url_fichier'];
        $nameOrig    = (string)$row['nom_original'];
        $docTypePrev = (string)$row['type_document'];
    }

    if ($publicUrl === '') {
        exit(json_encode(['ok' => false, 'error' => 'URL fichier manquante en BDD']));
    }
    $diskPath = dirname(__DIR__) . '/' . ltrim($publicUrl, '/');
    if (!is_file($diskPath)) {
        exit(json_encode(['ok' => false, 'error' => 'Fichier physique introuvable : ' . $publicUrl]));
    }

    // ─── 2. Cache IA : si on a déjà un résultat stocké et pas de force=1, on l'utilise.
    //       → ZÉRO coût OpenAI. Le bouton 🔄 standard ne re-paie pas.
    //       → Pour forcer une nouvelle analyse, le client passe force=1.
    $forceFresh = !empty($_POST['force']) && (string)$_POST['force'] === '1';
    $cached     = ($useGed && isset($meta['extra']['ia_result_last']) && is_array($meta['extra']['ia_result_last']))
                ? $meta['extra']['ia_result_last']
                : null;
    $textLen    = 0;
    $usedCache  = false;

    $timing = ['t_start' => microtime(true), 't_extract' => null, 't_ia' => null];
    if (!$forceFresh && $cached && !empty($cached['fields'])) {
        // Reconstitue un iaResult depuis le cache (pas d'appel OpenAI)
        $iaResult = [
            'ok'       => true,
            'doc_type' => (string)($cached['doc_type'] ?? 'autre'),
            'fields'   => (array)$cached['fields'],
            'router'   => $cached['router'] ?? 'cache',
            'method'   => 'cache',
        ];
        $usedCache = true;
        $textLen   = (int)($cached['text_len'] ?? 0);
    } else {
        // Pas de cache exploitable ou force=1 → on RE-extrait via OpenAI.
        $t0 = microtime(true);
        $texteSource = BienImportParser::extractText($diskPath);
        $textLen     = mb_strlen(trim($texteSource));
        $timing['t_extract'] = round(microtime(true) - $t0, 2);

        $t1 = microtime(true);
        // ─── Choix du forceType : respecter le type déjà connu ─────────
    // FIX RÉGRESSION : un doc tagué BAIL ne doit JAMAIS être reclassé en DIAG_DPE
    // par auto-détection (le bail contient le DPE annexé qui trompe la regex).
    // → Si docTypePrev est un type canonique (BAIL/DIAG_DPE/MANDAT_*/ACTE_*),
    //   on force ce type au lieu de 'divers'. Seuls les docs AUTRE bénéficient
    //   de l'auto-détection.
    // Override par paramètre POST force_doc_type pour permettre une reclassification
    // manuelle si jamais l'IA s'est trompée à la 1re analyse.
    $reverseGedMap = [
        'BAIL'              => 'bail',
        'DIAG_DPE'          => 'diag',
        'DIAG_AMIANTE'      => 'diag',
        'DIAG_PLOMB'        => 'diag',
        'DIAG_GAZ'          => 'diag',
        'DIAG_ELEC'         => 'diag',
        'DIAG_TERMITES'     => 'diag',
        'DIAG_ERP'          => 'diag',
        'SURFACE_CARREZ'    => 'diag',
        'MANDAT_VENTE'      => 'mandat',
        'MANDAT_LOCATION'   => 'mandat',
        'MANDAT_GESTION'    => 'mandat',
        'MANDAT_RECHERCHE'  => 'mandat',
        'ACTE_AUTHENTIQUE'  => 'titre',
        'COMPROMIS'         => 'titre',
        'PROMESSE_VENTE'    => 'titre',
    ];
    $forceTypeForIA = 'divers';  // défaut : autorise détection auto
    $manualForce = isset($_POST['force_doc_type']) ? strtolower(trim((string)$_POST['force_doc_type'])) : '';
    if ($manualForce && in_array($manualForce, ['bail','diag','mandat','titre','fiche','divers'], true)) {
        $forceTypeForIA = $manualForce; // override explicite par l'utilisateur
    } elseif ($useGed && $docTypePrev !== '' && strtoupper($docTypePrev) !== 'AUTRE') {
        $mapped = $reverseGedMap[strtoupper($docTypePrev)] ?? null;
        if ($mapped) $forceTypeForIA = $mapped;
    }

    if ($textLen < 200) {
            $tmpDir = dirname(__DIR__) . '/uploads/_ocr_tmp/reana_' . $idDoc . '_' . time();
            $images = BienIntakeOCR::pdfToImages($diskPath, $tmpDir, 8);
            if (empty($images)) {
                exit(json_encode(['ok' => false, 'error' => 'PDF scanné non analysable (Poppler manquant ?)']));
            }
            $iaResult = BienIntakeOCR::analyseImagesIA($images, $forceTypeForIA);
            BienIntakeOCR::cleanupTmpDir($tmpDir);
        } else {
            $iaResult = analyseBienIntakeIA($texteSource, $forceTypeForIA);
        }
        $timing['t_ia'] = round(microtime(true) - $t1, 2);

        if (empty($iaResult['ok'])) {
            exit(json_encode([
                'ok' => false,
                'error' => 'Analyse IA échouée : ' . ($iaResult['error'] ?? 'inconnue'),
            ]));
        }
    }

    $newType   = (string)($iaResult['doc_type'] ?? 'autre');
    $fields    = (array)($iaResult['fields'] ?? []);
    $resumeIa  = (string)($iaResult['resume'] ?? ($fields['diag_resume_ia'] ?? ''));

    // ─── Reconnexion PDO fraîche post-IA ───────────────────────
    // L'appel OpenAI peut durer 10–60 s, Hostinger ferme la connexion MySQL.
    // Sans ce reconnect, le UPDATE ged_documents + SELECT ged_document_links +
    // SHOW COLUMNS dans apply_* plantent tous en 'MySQL server has gone away'.
    if (!$usedCache && function_exists('db_reconnect_fresh')) {
        try { $pdo = db_reconnect_fresh(); }
        catch (Throwable $e) { error_log('[reanalyze] db_reconnect_fresh failed: ' . $e->getMessage()); }
    }

    // ─── 4. Mise à jour ged_documents.document_type + metadata.extra.ia_result ──
    if ($useGed) {
        try {
            $gedTypeMap = [
                'dpe'                 => 'DIAG_DPE',
                'diag'                => 'DIAG_DPE',
                'dossier_complet'     => 'DIAG_DPE',
                'dossier_diagnostics' => 'DIAG_DPE',
                'mandat_vente'        => 'MANDAT_VENTE',
                'mandat_location'     => 'MANDAT_LOCATION',
                'mandat_gestion'      => 'MANDAT_GESTION',
                'mandat'              => 'MANDAT_VENTE',
                'bail'                => 'BAIL',
                'titre'               => 'ACTE_AUTHENTIQUE',
                'acte_propriete'      => 'ACTE_AUTHENTIQUE',
                'acte'                => 'ACTE_AUTHENTIQUE',
                'fiche'               => 'AUTRE',
                'divers'              => 'AUTRE',
                'autre'               => 'AUTRE',
            ];
            $newCanonical = $gedTypeMap[$newType] ?? 'AUTRE';

            // Merge metadata existante + résultat IA frais
            $metaArr = json_decode((string)$row['metadata'], true) ?: [];
            $extra = $metaArr['extra'] ?? [];
            $extra['ia_result_last'] = [
                'at'        => date('Y-m-d H:i:s'),
                'doc_type'  => $newType,
                'fields'    => $fields,
                'router'    => $iaResult['router'] ?? null,
                'text_len'  => $textLen,
            ];
            if ($resumeIa !== '') $extra['resume_ia'] = $resumeIa;
            $metaArr['extra'] = $extra;

            // ─ Si le type a changé, on régénère le name_display via gdn_v4_build_display ─
            $newDisplay = null;
            $newCanonicalName = null;
            if (strtoupper((string)$docTypePrev) !== strtoupper($newCanonical) && function_exists('gdn_v4_build_display')) {
                $namingCtx = is_array($metaArr['naming_ctx'] ?? null) ? $metaArr['naming_ctx'] : [];
                $namingCtx['type_doc']   = $newCanonical;
                $namingCtx['n3_slug']    = strtolower($newCanonical);
                // n1 contextuel selon le type
                if (in_array($newCanonical, ['MANDAT_VENTE','COMPROMIS','PROMESSE_VENTE','ACTE_AUTHENTIQUE','OFFRE_ACHAT'], true)) {
                    $namingCtx['n1_slug'] = '06_transaction';
                } else {
                    $namingCtx['n1_slug'] = '05_gestion_locative';
                }
                $namingCtx['date_doc'] = $fields['dpe_date_realisation']
                    ?? ($fields['bail_date_signature'] ?? ($namingCtx['date_doc'] ?? null));
                try {
                    $newDisplay = gdn_v4_build_display($namingCtx);
                    $newCanonicalName = pathinfo($newDisplay, PATHINFO_FILENAME);
                    // Anti-collision : si un autre doc du même tenant a déjà ce name_display
                    $stColl = $pdo->prepare("SELECT COUNT(*) FROM ged_documents
                                              WHERE name_display = :n AND id <> :self
                                                AND COALESCE(status,'active') = 'active'");
                    $stColl->execute([':n' => $newDisplay, ':self' => $idDoc]);
                    if ((int)$stColl->fetchColumn() > 0) {
                        $extPart = pathinfo($newDisplay, PATHINFO_EXTENSION);
                        $base    = pathinfo($newDisplay, PATHINFO_FILENAME);
                        $newDisplay      = $base . '_' . $idDoc . ($extPart ? '.' . $extPart : '');
                        $newCanonicalName = pathinfo($newDisplay, PATHINFO_FILENAME);
                    }
                    // Mémorise la nouvelle naming_ctx
                    $metaArr['naming_ctx'] = $namingCtx;
                } catch (Throwable $e) {
                    error_log('[reanalyze rename] ' . $e->getMessage());
                }
            }

            $sql = "UPDATE ged_documents
                       SET document_type = :t,
                           metadata = :m,
                           updated_at = NOW()"
                 . ($newDisplay !== null ? ", name_display = :nd, name_canonical = :nc" : "")
                 . " WHERE id = :id";
            $params = [
                ':t'  => $newCanonical,
                ':m'  => json_encode($metaArr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ':id' => $idDoc,
            ];
            if ($newDisplay !== null) {
                $params[':nd'] = $newDisplay;
                $params[':nc'] = $newCanonicalName;
            }
            $pdo->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            error_log('[reanalyze] UPDATE ged_documents failed: ' . $e->getMessage());
        }
    }

    // ─── 5. UPSERT défensif des tables structurées (bail / dpe) ─────────
    // 3 sources pour retrouver l'id_bien (par robustesse) :
    //   a) ged_document_links entity_type IN (BIEN, bien)
    //   b) ged_document_links — n'importe quel type, on prend le 1er BIEN-like
    //   c) metadata.extra.classement.bien_id_bdd (rangé par gus_commit_document)
    //   d) Referer HTTP : bien_detail.php?edit=X (dernier recours)
    $applyReport = null;
    $bienIdLinked = 0;
    $linkSource   = null;
    try {
        // a) match insensible à la casse
        $stL = $pdo->prepare("SELECT entity_id FROM ged_document_links
                               WHERE document_id = ?
                                 AND UPPER(entity_type) IN ('BIEN','B','BN')
                               ORDER BY id ASC LIMIT 1");
        $stL->execute([$idDoc]);
        $bienIdLinked = (int)($stL->fetchColumn() ?: 0);
        if ($bienIdLinked > 0) $linkSource = 'ged_document_links';
    } catch (Throwable $e) {
        error_log('[reanalyze] lookup BIEN link a : ' . $e->getMessage());
    }
    // b) si rien, on regarde la metadata.extra.classement.bien_id_bdd
    if ($bienIdLinked <= 0 && $useGed && isset($meta['extra']['classement']['bien_id_bdd'])) {
        $bienIdLinked = (int)$meta['extra']['classement']['bien_id_bdd'];
        if ($bienIdLinked > 0) $linkSource = 'metadata.classement.bien_id_bdd';
    }
    // c) dernier recours : Referer
    if ($bienIdLinked <= 0) {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if (preg_match('/[?&]edit=(\d+)/', $ref, $m)) {
            $bienIdLinked = (int)$m[1];
            if ($bienIdLinked > 0) $linkSource = 'referer';
        }
    }
    if ($bienIdLinked > 0) error_log('[reanalyze] bien_id résolu via ' . $linkSource . ' : ' . $bienIdLinked);
    else error_log('[reanalyze] aucun id_bien trouvé pour ged_doc #' . $idDoc);

    if ($bienIdLinked > 0 && $useGed) {
        $publicUrl = (string)($meta['public_url'] ?? '');
        $userId    = function_exists('current_user_id') ? (int)current_user_id() : null;
        // Reconnexion PDO fraîche : l'appel OpenAI peut durer 10-30 s et tuer la
        // connexion MySQL (Hostinger wait_timeout court). Sans ça → 'MySQL server
        // has gone away' sur le SHOW COLUMNS dans le helper.
        if (function_exists('db_reconnect_fresh') && !$usedCache) {
            try { $pdo = db_reconnect_fresh(); } catch (Throwable $e) {
                error_log('[reanalyze] db_reconnect_fresh failed: ' . $e->getMessage());
            }
        }
        if ($newType === 'bail') {
            $applyReport = apply_bail_extracted_to_bien($pdo, $bienIdLinked, $fields, $publicUrl, $userId);
        } elseif (in_array($newType, ['dpe', 'diag', 'dossier_complet', 'dossier_diagnostics'], true)) {
            $applyReport = apply_dpe_extracted_to_bien($pdo, $bienIdLinked, $fields, $publicUrl, $userId);
        }
    }

    // ─── 6. Message final résumant ce qui a été fait ────────
    $timing['t_total'] = round(microtime(true) - $timing['t_start'], 2);
    $msgParts = [];
    if ($usedCache) {
        $msgParts[] = "💾 cache IA (gratuit, " . $timing['t_total'] . "s)";
    } else {
        $msgParts[] = "🧠 nouvelle analyse OpenAI ("
                   . ($timing['t_extract'] !== null ? "extraction " . $timing['t_extract'] . "s + " : "")
                   . "IA " . ($timing['t_ia'] ?? '?') . "s = "
                   . $timing['t_total'] . "s total)";
    }
    $msgParts[] = "Type détecté : {$newType}";
    $msgParts[] = "ged_documents mis à jour";
    if ($bienIdLinked > 0) {
        $msgParts[] = "bien lié #{$bienIdLinked} (via {$linkSource})";
    }
    if ($applyReport && !empty($applyReport['ok'])) {
        $msgParts[] = ($newType === 'bail' ? 'bien_baux' : 'dpe_diags') . ' ' . $applyReport['action']
                    . ' (+ sync biens défensive)';
    } elseif ($applyReport && !empty($applyReport['notes'])) {
        $msgParts[] = 'sync ignorée : ' . implode(' / ', $applyReport['notes']);
    } elseif ($bienIdLinked <= 0) {
        $msgParts[] = 'aucun bien lié à ce doc (pas de sync)';
    }

    exit(json_encode([
        'ok'           => true,
        'id_doc'       => ($useGed ? 'ged_' : '') . $idDoc,
        'doc_type'     => $newType,
        'doc_type_previous' => $docTypePrev,
        'fields'       => $fields,
        'router'       => $iaResult['router'] ?? null,
        'method'       => $iaResult['method'] ?? ($textLen < 200 ? 'ocr_vision' : 'text_ia'),
        'apply_report' => $applyReport,
        'message'      => implode(' · ', $msgParts),
        'reload'       => true,
    ], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    error_log('[biens_documents_reanalyze] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
