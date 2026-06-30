<?php
/**
 * api/graph_doc_commit.php
 *
 * Classement GED d'un document choisi dans le OneDrive général.
 * Télécharge le fichier OneDrive (Microsoft Graph), le copie dans la GED locale,
 * crée l'entrée ged_documents + le lien polymorphe BIEN (+ TIERS/IMB) et renomme
 * selon la convention GED (gus_commit_document → naming V3.1 / display V4).
 *
 * Entrée (POST) :
 *   bien_id    int     — bien courant
 *   type_code  string  — document_type GED (v1 : DIAG_DPE)
 *   item_id    string  — id OneDrive du fichier validé par l'utilisateur
 *
 * Sortie (JSON) : ok, ged_doc_id, name_display, name_file, deduplicated
 *
 * Décision Emmanuel 2026-06-06 : on COPIE dans la GED locale (pas de lien OneDrive),
 * meilleur candidat pré-coché mais validation humaine obligatoire.
 */

declare(strict_types=1);
set_time_limit(180);
ini_set('display_errors', '0');   // API JSON : pas de HTML d'erreur dans le flux

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/microsoft_graph.php';
require_once dirname(__DIR__) . '/inc/ged_document_links.php';
require_login();

// Analyse OCR/IA à la validation (Emmanuel 2026-06 : faible volume, on extrait
// une fois pour toutes pour ne plus y revenir). Inclusions tolérantes.
$diagAnalyzeAvailable = true;
foreach (['bien_import_parser', 'bien_intake_ia', 'bien_intake_ocr'] as $incFile) {
    $p = dirname(__DIR__) . '/inc/' . $incFile . '.php';
    if (is_file($p)) { require_once $p; } else { $diagAnalyzeAvailable = false; }
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}
verify_csrf_any('ged_graph');

if (!graph_is_configured()) {
    exit(json_encode(['ok' => false, 'error' => 'Microsoft Graph non configuré sur ce serveur.']));
}

$pdo      = $GLOBALS['pdo'];
$bienId   = isset($_POST['bien_id']) && ctype_digit((string)$_POST['bien_id']) ? (int)$_POST['bien_id'] : 0;
$typeCode = strtoupper(trim((string)($_POST['type_code'] ?? '')));
$itemId   = trim((string)($_POST['item_id'] ?? ''));
if ($bienId <= 0 || $typeCode === '' || $itemId === '') {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres manquants (bien_id, type_code, item_id).']));
}

// ── Garde-fou anti-doublon (#2) : ce bien a-t-il déjà un DPE en GED ? ───
// On ne reclasse pas un DPE si le bien en a déjà un actif (évite les doublons
// type bien 907 ×10). Contournable explicitement via force=1 (remplacement voulu).
$force = !empty($_POST['force']) && $_POST['force'] !== '0';
// no_analyse=1 : classe le PDF en GED SANS analyse IA/OCR payante (saisie manuelle des
// champs ailleurs). La réutilisation gratuite par hash (déjà analysé) reste active.
$noAnalyse = !empty($_POST['no_analyse']) && $_POST['no_analyse'] !== '0';
if (!$force && (str_contains(strtoupper($typeCode), 'DPE'))) {
    try {
        $stDup = $pdo->prepare("SELECT gd.id, gd.name_display
                                  FROM ged_documents gd
                                  JOIN ged_document_links gdl ON gdl.document_id=gd.id
                                       AND gdl.entity_type='BIEN' AND gdl.entity_id=?
                                 WHERE gd.status='active'
                                   AND gd.document_type IN ('DPE','DIAG_DPE')
                                 LIMIT 1");
        $stDup->execute([$bienId]);
        if ($ex = $stDup->fetch(PDO::FETCH_ASSOC)) {
            exit(json_encode(['ok' => false, 'duplicate' => true,
                'error' => 'Ce bien a déjà un DPE en GED ('.($ex['name_display'] ?: ('#'.$ex['id'])).'). Classement annulé pour éviter un doublon.',
                'existing_doc_id' => (int)$ex['id']], JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) { /* table absente → on laisse passer */ }
}

// ── Drive métier ───────────────────────────────────────────────────────
if (defined('GRAPH_ONEDRIVE_USER') && (string)GRAPH_ONEDRIVE_USER !== '') {
    graph_set_drive_user((string)GRAPH_ONEDRIVE_USER);
}

// ── (1) Téléchargement du contenu OneDrive ─────────────────────────────
try {
    $dl = graph_download_file_content($itemId);
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Téléchargement OneDrive : ' . $e->getMessage()]));
}
if (empty($dl['ok'])) {
    exit(json_encode(['ok' => false, 'error' => $dl['error'] ?? 'Téléchargement impossible.']));
}

$origName = (string)($dl['name'] ?? ('document_' . $itemId));
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'pdf';

// ── (2) Stockage dans la GED locale ────────────────────────────────────
$uploadDir = dirname(__DIR__) . '/uploads/biens_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
$safeName  = 'onedrive_' . $bienId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath  = $uploadDir . $safeName;
$publicUrl = '/uploads/biens_docs/' . $safeName;

if (file_put_contents($destPath, (string)$dl['content']) === false) {
    exit(json_encode(['ok' => false, 'error' => 'Écriture du fichier dans la GED impossible.']));
}

// ── (2b) ANALYSE OCR/IA du diagnostic (non-bloquant) ──────────────────
// Extrait classe DPE/GES, dates, n° ADEME… pour remplir dpe_diags + la date
// dans le nommage GED. Si l'analyse échoue, le classement se poursuit quand même.
$diagFields = [];
$diagDate   = null;
$diagAnalyzed = false;
$diagReused   = false;
$tcU        = strtoupper((string)$typeCode);
$isDpeType  = str_contains($tcU, 'DPE');
$isDiagType = $isDpeType || ($tcU === 'DIAG_DPE') || str_starts_with($tcU, 'DIAG_');

// ── Idempotence anti-double-facturation : si CE fichier (même empreinte SHA-256)
//    a déjà été analysé par l'IA, on RÉUTILISE l'extraction au lieu de re-payer
//    un appel OpenAI. (Emmanuel : ne pas re-payer le même doc local↔prod.)
$fileHash = @hash_file('sha256', $destPath) ?: '';
if ($isDiagType && $fileHash !== '') {
    try {
        $stH = $pdo->prepare("SELECT metadata FROM ged_documents
                               WHERE hash_sha256 = ? AND status IN ('active','archived')
                               ORDER BY id DESC LIMIT 1");
        $stH->execute([$fileHash]);
        $metaJson = $stH->fetchColumn();
        if ($metaJson) {
            $m = json_decode((string)$metaJson, true);
            $df = $m['extra']['diag_fields'] ?? null;
            if (is_array($df) && $df) {
                $diagFields = $df;
                $diagDate = $df['dpe_date_realisation'] ?? ($df['date_signature'] ?? null);
                $diagAnalyzed = true;
                $diagReused   = true;   // réutilisé → aucun appel IA, gratuit
            }
        }
    } catch (Throwable $e) { error_log('[graph_doc_commit] cache diag : ' . $e->getMessage()); }
}

if (!$noAnalyse && !$diagReused && $isDiagType && $diagAnalyzeAvailable && $ext === 'pdf') {
    try {
        if ($isDpeType) {
            // ── DPE : SERVICE DPE CENTRAL (inc/dpe_service.php) ──
            // MÊME extraction que tous les autres canaux (bien_detail inclus).
            require_once dirname(__DIR__) . '/inc/dpe_service.php';
            $resAna = dpe_analyser($destPath, $bienId);
            if (!empty($resAna['ok']) && !empty($resAna['fields'])) {
                $diagFields = (array)$resAna['fields'];
                $diagDate = $diagFields['dpe_date_realisation'] ?? ($diagFields['date_signature'] ?? null);
                $diagAnalyzed = true;
            } else {
                error_log('[graph_doc_commit] extraction DPE (service) : ' . ($resAna['error'] ?? '?'));
            }
        } elseif (function_exists('analyseBienIntakeIA') && class_exists('BienImportParser')) {
            // ── Autres diagnostics : moteur générique ──
            $texte = BienImportParser::extractText($destPath);
            $ia = null;
            if (mb_strlen(trim((string)$texte)) >= 200) {
                $ia = analyseBienIntakeIA($texte, 'diag');
            }
            // PDF scanné → OCR Vision
            if ((!$ia || empty($ia['ok']) || (int)($ia['count'] ?? 0) < 3) && class_exists('BienIntakeOCR')) {
                $tmpDir = dirname(__DIR__) . '/uploads/_ocr_tmp/onedrive_' . $bienId . '_' . bin2hex(random_bytes(3));
                $imgs = BienIntakeOCR::pdfToImages($destPath, $tmpDir, 8);
                if (!empty($imgs)) {
                    $ia = BienIntakeOCR::analyseImagesIA($imgs, 'diag');
                    BienIntakeOCR::cleanupTmpDir($tmpDir);
                }
            }
            if ($ia && !empty($ia['ok'])) {
                $diagFields = (array)($ia['fields'] ?? []);
                $diagDate = $diagFields['dpe_date_realisation'] ?? ($diagFields['date_signature'] ?? null);
                $diagAnalyzed = true;
            }
        }
        // Reconnexion MySQL (l'appel IA peut durer 15-30s → "server gone away")
        if (function_exists('db_reconnect_fresh')) { $pdo = db_reconnect_fresh(); }
    } catch (Throwable $exAna) {
        error_log('[graph_doc_commit] analyse IA non-bloquante échouée : ' . $exAna->getMessage());
    }
}

try {
    // ── (3) Contexte du bien pour liens + naming ───────────────────────
    $stBienCtx = $pdo->prepare("SELECT b.id_societe, b.id_agence, b.id_immeuble,
                                       p.id_tiers AS proprio_tiers_id,
                                       s.raison_sociale AS soc_raison,
                                       a.code_agence, a.nom_agence
                                  FROM biens b
                                  LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                                  LEFT JOIN societes s      ON s.id = b.id_societe
                                  LEFT JOIN agences  a      ON a.id = b.id_agence
                                  WHERE b.id = ? LIMIT 1");
    $stBienCtx->execute([$bienId]);
    $bienCtx = $stBienCtx->fetch(PDO::FETCH_ASSOC) ?: [];

    // Fallback société/agence : REGI EMERY (#1) / LYON (#3) si bien non rattaché
    $socIdGed = (int)($bienCtx['id_societe'] ?? 0) ?: 1;
    $ageIdGed = (int)($bienCtx['id_agence']  ?? 0) ?: 3;
    if (empty($bienCtx['soc_raison'])) {
        $stFb = $pdo->prepare("SELECT s.raison_sociale, a.code_agence, a.nom_agence
                                FROM societes s, agences a
                                WHERE s.id = ? AND a.id = ? LIMIT 1");
        $stFb->execute([$socIdGed, $ageIdGed]);
        $bienCtx = array_merge($bienCtx, $stFb->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    // N1 contextuel : transaction pour les actes de vente, gestion sinon
    $n1Ged = in_array($typeCode, ['MANDAT_VENTE', 'COMPROMIS', 'PROMESSE_VENTE',
                                    'ACTE_AUTHENTIQUE', 'OFFRE_ACHAT'], true)
           ? '06_transaction'
           : '05_gestion_locative';

    // Liens polymorphes : BIEN obligatoire, TIERS (proprio) + IMB si dispo
    $links = [['entity_type' => 'BIEN', 'entity_id' => $bienId, 'relation_type' => 'main']];
    if (!empty($bienCtx['proprio_tiers_id'])) {
        $links[] = ['entity_type' => 'TIERS', 'entity_id' => (int)$bienCtx['proprio_tiers_id'], 'relation_type' => 'annexe'];
    }
    if (!empty($bienCtx['id_immeuble'])) {
        $links[] = ['entity_type' => 'IMB', 'entity_id' => (int)$bienCtx['id_immeuble'], 'relation_type' => 'annexe'];
    }

    $uidUp = function_exists('current_user_id') ? (int)current_user_id() : null;

    $commit = gus_commit_document(
        $pdo,
        [
            'path_on_disk'  => $destPath,
            'name_original' => $origName,
            'mime_type'     => (string)($dl['mime_type'] ?? 'application/pdf'),
            'size_bytes'    => (int)($dl['size'] ?? filesize($destPath) ?: 0),
            'public_url'    => $publicUrl,
        ],
        [
            'document_type'  => $typeCode,
            'source_module'  => '05_TRANSACTION',
            'security_level' => 'interne',
            'societe_id'     => $socIdGed,
            'agence_id'      => $ageIdGed,
            'tenant_id'      => $socIdGed,
            'created_by'     => $uidUp,
            'storage_provider' => 'local',
            'metadata_extra' => [
                'source'           => 'onedrive_graph_search',
                'onedrive_item_id' => $itemId,
                'onedrive_name'    => $origName,
                'diag_analyzed'    => $diagAnalyzed,
                'diag_fields'      => $diagFields ?: null,
                'classement'       => [
                    'bien_id_bdd'      => $bienId,
                    'immeuble_id_bdd'  => $bienCtx['id_immeuble'] ?? null,
                    'tiers_proprio_id' => $bienCtx['proprio_tiers_id'] ?? null,
                    'date_doc'         => $diagDate,
                ],
            ],
            'naming_ctx' => [
                'societe_raison' => $bienCtx['soc_raison'] ?? 'Régie EMERY',
                'agence_code'    => $bienCtx['code_agence'] ?? 'RE69-2',
                'agence_nom'     => $bienCtx['nom_agence']  ?? 'LYON',
                'user_id'        => $uidUp,
                'n1_slug'        => $n1Ged,
                'n2_slug'        => 'biens',
                'n3_slug'        => strtolower($typeCode),
                'type_doc'       => $typeCode,
                'entity_type'    => 'BIEN',
                'entity_id'      => $bienId,
                'date_doc'       => $diagDate,   // remplit le segment 9 du nom GED
                'source_filename'=> $origName,
            ],
        ],
        $links
    );

    if (empty($commit['ok'])) {
        @unlink($destPath);
        exit(json_encode([
            'ok'    => false,
            'error' => 'Classement GED : ' . json_encode($commit['errors'] ?? ['inconnu']),
        ]));
    }

    // ── (4) COMPLÈTE LA FICHE BIEN + LE DPE (réutilise l'IA DPE existante) ──
    // On écrit le diagnostic dans dpe_diags (traçabilité) et on synchronise les
    // champs DPE du bien en NON-ÉCRASANT (COALESCE) — comme bien_intake_upload.
    $diagId = 0;
    if ($diagAnalyzed && !empty($diagFields)) {
        $f = $diagFields;
        // DPE vierge : marquage si aucune valeur trouvée
        $noVal = empty($f['dpe_classe']) && empty($f['ges_classe'])
              && empty($f['dpe_valeur']) && empty($f['ges_valeur']);
        if (!empty($f['dpe_vierge']) || $noVal) {
            $f['dpe_vierge'] = 1;
            $f['dpe_classe'] = $f['dpe_classe'] ?? 'vierge';
            $f['ges_classe'] = $f['ges_classe'] ?? 'vierge';
        }
        // ── Stockage via le SERVICE DPE CENTRAL (inc/dpe_service.php) ──
        // MÊME stockage que bien_detail (dpe_diags complet + sync fiche bien).
        require_once dirname(__DIR__) . '/inc/dpe_service.php';
        if (empty($f['dpe_date_realisation']) && $diagDate) { $f['dpe_date_realisation'] = $diagDate; }
        $stStore = dpe_enregistrer($pdo, $bienId, $f, [
            'url'      => $publicUrl,
            'nom_orig' => $origName,
            'mime'     => 'application/pdf',
            'method'   => 'service_onedrive',
        ]);
        $diagId = (int)($stStore['diag_id'] ?? 0);
        if (function_exists('db_reconnect_fresh')) { $pdo = db_reconnect_fresh(); }
    }

    echo json_encode([
        'ok'           => true,
        'bien_id'      => $bienId,
        'type_code'    => $typeCode,
        'ged_doc_id'   => $commit['doc_id'] ?? null,
        'name_display' => $commit['name_display'] ?? null,
        'name_file'    => $commit['name_file'] ?? null,
        'deduplicated' => $commit['deduplicated'] ?? false,
        'onedrive_name'=> $origName,
        'diag_analyzed'=> $diagAnalyzed,
        'diag_reused'  => $diagReused,   // true = extraction réutilisée (0 appel IA)
        'diag_id'      => $diagId,
        'dpe_classe'   => $diagFields['dpe_classe'] ?? null,
        'ges_classe'   => $diagFields['ges_classe'] ?? null,
        'diag_date'    => $diagDate,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
