<?php
/**
 * api/bail_caution_doc_upload.php — Upload d'un ACTE DE CAUTIONNEMENT sur un bail.
 *
 * Un seul geste = plusieurs effets (doctrine MBI) :
 *   1. le PDF est committé en GED comme DOCUMENT OFFICIEL du bail (type CAUTIONNEMENT) :
 *      lié au BIEN (main), au BAIL (reference), à l'IMMEUBLE (reference), id_bail marqué ;
 *   2. si extract=1 (défaut) : extraction IA FOCALISÉE du garant (caution_analyse_pdf) →
 *      création/liaison du/des TIERS caution (rôle 'caution' scopé bail, anti-doublon) ;
 *   3. l'acte est lié à ce(s) TIERS caution → visible aussi sur la fiche tiers (contentieux).
 *   Si id_tiers est fourni : on rattache l'acte à CETTE caution existante (pas de nouveau tiers).
 *
 * POST : id_bail (= bien_baux.id, requis), document (PDF), extract (0|1, défaut 1),
 *        id_tiers (optionnel), csrf_token (form 'bail_caution').
 * Sécurité : login + CSRF + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
set_time_limit(120);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ged_document_links.php';   // gus_commit_document(), gdl_attach()
require_once __DIR__ . '/../inc/bail_cautions.php';        // bail_caution_upsert()
require_once __DIR__ . '/../inc/bail_analyse_service.php'; // caution_analyse_pdf()
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('bail_caution');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);

$bailId  = isset($_POST['id_bail']) && ctype_digit((string)$_POST['id_bail']) ? (int)$_POST['id_bail'] : 0;
$idTiers = isset($_POST['id_tiers']) && ctype_digit((string)$_POST['id_tiers']) ? (int)$_POST['id_tiers'] : 0;
$doExtract = !isset($_POST['extract']) || (string)$_POST['extract'] === '1'; // défaut : on extrait
if ($bailId <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bail requis']); exit; }

// ── Contexte : bien_baux → bien → immeuble → société/agence ──
$st = $pdo->prepare("SELECT bb.id_bien, bi.id_immeuble FROM bien_baux bb LEFT JOIN biens bi ON bi.id = bb.id_bien WHERE bb.id = ? LIMIT 1");
$st->execute([$bailId]);
$ctxRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctxRow) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Bail introuvable']); exit; }
$bienId = (int)($ctxRow['id_bien'] ?? 0);
$immId  = (int)($ctxRow['id_immeuble'] ?? 0);

require_once __DIR__ . '/../inc/bien_scope_resolver.php';
$rv = bien_resolve_soc_age($pdo, $bienId);
$socId = $rv['societe_id']; $ageId = $rv['agence_id'];
$societeRaison = (string)($pdo->query("SELECT raison_sociale FROM societes WHERE id = " . (int)$socId)->fetchColumn() ?: '');
$ageRow = $pdo->query("SELECT code_agence, nom_agence FROM agences WHERE id = " . (int)$ageId)->fetch(PDO::FETCH_ASSOC) ?: [];
$immNom = $immId > 0 ? (string)($pdo->query("SELECT nom_immeuble FROM immeubles WHERE id = " . (int)$immId)->fetchColumn() ?: '') : '';
$uRow = $userId > 0 ? ($pdo->query("SELECT matricule_paie, username FROM users WHERE id = " . (int)$userId)->fetch(PDO::FETCH_ASSOC) ?: []) : [];

// ── Fichier (unique) ──
$file = null;
if (!empty($_FILES['document']) && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $file = ['name'=>(string)$_FILES['document']['name'], 'tmp'=>(string)$_FILES['document']['tmp_name'], 'size'=>(int)$_FILES['document']['size']];
}
if (!$file) { echo json_encode(['ok'=>false,'error'=>'Aucun fichier reçu']); exit; }
if ($file['size'] > 50 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'Fichier > 50 Mo']); exit; }
$ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'pdf';
if (!in_array($ext, ['pdf','jpg','jpeg','png','tiff','tif'], true)) { echo json_encode(['ok'=>false,'error'=>'PDF ou image uniquement']); exit; }

// ── Stockage durable ──
$storageBase = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'storage_fluxbox';
if (!is_dir($storageBase)) @mkdir($storageBase, 0775, true);
$storageDir = $storageBase . DIRECTORY_SEPARATOR . $socId . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
$hash = hash_file('sha256', $file['tmp']) ?: '';
$mime = function_exists('mime_content_type') ? (mime_content_type($file['tmp']) ?: 'application/pdf') : 'application/pdf';
$safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $file['name']) ?: 'acte_caution.pdf';
$dest = $storageDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . substr($hash, 0, 8) . '_' . $safe;
$moved = is_uploaded_file($file['tmp']) ? move_uploaded_file($file['tmp'], $dest) : copy($file['tmp'], $dest);
if (!$moved) { echo json_encode(['ok'=>false,'error'=>'Échec écriture du fichier']); exit; }

// ── Commit GED : DOCUMENT OFFICIEL du bail (type CAUTIONNEMENT) ──
$namingCtx = [
    'upload_date'     => 'now',
    'n1_slug'         => '05_gestion_locative',
    'n2_slug'         => '02_bail',
    'n3_slug'         => '04_caution',
    'date_doc'        => date('Y-m-d'),
    'type_doc'        => 'CAUTIONNEMENT',
    'source_filename' => $file['name'],
    'user_id'         => $userId,
    'entity_type'     => 'BIEN',
    'entity_id'       => $bienId,
    'societe_raison'  => $societeRaison ?: null,
    'agence_code'     => $ageRow['code_agence'] ?? null,
    'agence_nom'      => $ageRow['nom_agence']  ?? null,
    'user_matricule'  => $uRow['matricule_paie'] ?? null,
    'user_username'   => $uRow['username']       ?? null,
    'immeuble_nom'    => $immNom ?: null,
];
$ctx = [
    'tenant_id'      => $socId ?: null,
    'societe_id'     => $socId ?: null,
    'agence_id'      => $ageId ?: null,
    'document_type'  => 'CAUTIONNEMENT',
    'source_module'  => '05_GESTION_LOCATIVE',
    'security_level' => 'interne',
    'created_by'     => $userId ?: null,
    'naming_ctx'     => $namingCtx,
];
$links = [
    ['entity_type'=>'BIEN', 'entity_id'=>$bienId, 'relation_type'=>'main',      'is_validated'=>true, 'validated_by'=>$userId],
    ['entity_type'=>'BAIL', 'entity_id'=>$bailId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId],
];
if ($immId > 0) $links[] = ['entity_type'=>'IMB', 'entity_id'=>$immId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId];

$res = gus_commit_document($pdo, [
    'path_on_disk' => $dest,
    'name_original'=> $file['name'],
    'hash_sha256'  => $hash,
    'mime_type'    => $mime,
    'size_bytes'   => $file['size'],
], $ctx, $links);

if (empty($res['ok'])) { echo json_encode(['ok'=>false,'error'=>implode(' / ', $res['errors'] ?? ['échec commit GED'])]); exit; }
$docId = (int)$res['doc_id'];
try { $pdo->prepare("UPDATE ged_documents SET id_bail = ? WHERE id = ?")->execute([$bailId, $docId]); } catch (Throwable $e) {}

// ── Rattachement au(x) tiers caution ──
$cautionsLiees = []; $tiersLies = []; $extraitInfo = null;

$linkDocToTiers = function(int $t) use ($pdo, $docId, $userId, &$tiersLies) {
    if ($t <= 0 || in_array($t, $tiersLies, true)) return;
    try { gdl_attach($pdo, $docId, 'TIERS', $t, ['relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId]); $tiersLies[] = $t; }
    catch (Throwable $e) { error_log('[caution_doc_upload] gdl_attach TIERS '.$t.': '.$e->getMessage()); }
};

if ($idTiers > 0) {
    // Rattacher l'acte à une caution EXISTANTE (pas d'extraction imposée).
    $linkDocToTiers($idTiers);
    $cautionsLiees[] = 'caution existante #' . $idTiers;
} elseif ($doExtract && $ext === 'pdf') {
    // Extraction IA focalisée garant → création/liaison tiers caution + lien de l'acte.
    try {
        $an = caution_analyse_pdf($dest);
        if (!empty($an['ok']) && !empty($an['data']['cautions'])) {
            // Reconnexion PDO fraîche : l'appel OpenAI peut dépasser wait_timeout (Hostinger).
            if (function_exists('db_reconnect_fresh')) {
                try { $pdo = db_reconnect_fresh(); } catch (Throwable $e) {}
            }
            foreach ($an['data']['cautions'] as $c) {
                if (!is_array($c)) continue;
                $nom = trim((string)($c['nom'] ?? '')); $rs = trim((string)($c['raison_sociale'] ?? ''));
                if ($nom === '' && $rs === '') continue;
                $adr = trim((string)($c['adresse'] ?? '')); $cp=''; $ville='';
                if ($adr !== '' && preg_match('/^(.*?)\s*\b(\d{5})\b\s+(.+)$/u', $adr, $m)) { $adr = trim($m[1], ' ,'); $cp=$m[2]; $ville=trim($m[3]); }
                $eng = strtolower(trim((string)($c['type_engagement'] ?? '')));
                $cautionType = (strpos($eng, 'simple') !== false) ? 'simple' : ($eng !== '' ? 'solidaire' : null);
                $up = bail_caution_upsert($pdo, $bailId, [
                    'civilite'=>$c['civilite']??null, 'nom'=>$nom, 'prenom'=>$c['prenom']??null,
                    'raison_sociale'=>$rs?:null, 'siren'=>$c['siren']??null,
                    'email'=>$c['email']??null, 'telephone'=>$c['telephone']??null,
                    'adresse_ligne1'=>$adr?:null, 'code_postal'=>$cp?:null, 'ville'=>$ville?:null,
                    'date_naissance'=>$c['date_naissance']??null, 'lieu_naissance'=>$c['lieu_naissance']??null,
                    'caution_type'=>$cautionType,
                    'montant_max'=>is_numeric($c['montant_max']??null)?(float)$c['montant_max']:null,
                    'duree_ans'=>is_numeric($c['duree_ans']??null)?(int)$c['duree_ans']:null,
                    'engagement_texte'=>$c['engagement_texte']??null,
                    'source'=>'acte_cautionnement',
                ]);
                if (($up['id_tiers'] ?? 0) > 0) {
                    $linkDocToTiers((int)$up['id_tiers']);
                    $cautionsLiees[] = trim(((string)($c['prenom']??'')) . ' ' . ($nom ?: $rs)) ?: ($nom ?: $rs);
                }
            }
            $extraitInfo = ['locataire_garanti' => $an['data']['locataire_garanti'] ?? null];
        } else {
            $extraitInfo = ['error' => $an['error'] ?? 'aucune caution détectée dans l\'acte'];
        }
    } catch (Throwable $e) {
        error_log('[caution_doc_upload] extraction: '.$e->getMessage());
        $extraitInfo = ['error' => $e->getMessage()];
    }
}

$nbC = count($cautionsLiees);
$msg = '✅ Acte de cautionnement classé en GED (document officiel du bail)';
if ($nbC > 0) $msg .= ' · 🛡️ ' . $nbC . ' caution(s) : ' . implode(', ', $cautionsLiees);
elseif ($doExtract && $idTiers <= 0) $msg .= ' · aucune caution extraite' . (!empty($extraitInfo['error']) ? ' (' . $extraitInfo['error'] . ')' : '');

echo json_encode([
    'ok'         => true,
    'doc_id'     => $docId,
    'name'       => $res['name_display'] ?? $file['name'],
    'id_bail'    => $bailId,
    'id_bien'    => $bienId,
    'cautions'   => $cautionsLiees,
    'tiers_lies' => $tiersLies,
    'extrait'    => $extraitInfo,
    'message'    => $msg,
    'reload'     => true,
], JSON_UNESCAPED_UNICODE);
