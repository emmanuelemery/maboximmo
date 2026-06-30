<?php
/**
 * api/avis_echeance_import.php — Import EN MASSE d'avis d'échéance ICS, sans IA.
 * Pour chaque PDF déposé : OCR/texte → parse déterministe (mandat+immeuble+nom locataire)
 *  → match bail/locataire → SI match certain : classé direct en GED (lié BAIL + BIEN [+ TIERS]),
 *  document_type='avis_echeance', id_bail renseigné (visible sur bail_360 / GED locataire).
 *  SINON : déposé dans la pile FluxBox (carte pending) pour attribution manuelle.
 *
 * POST : document[] (multi), csrf_token (form 'avis_import'). Sécurité : login + manager.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ia_analyse.php';            // extractPdfText
require_once __DIR__ . '/../inc/avis_echeance_parser.php';  // avis_parse / avis_match
require_once __DIR__ . '/../inc/ged_document_links.php';    // gus_commit_document
require_once __DIR__ . '/../inc/bien_scope_resolver.php';   // société/agence depuis le bien (jamais session)
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('avis_import');
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);

// Collecte fichiers
$files = [];
if (!empty($_FILES['document'])) {
    $f = $_FILES['document'];
    if (is_array($f['name'])) {
        for ($i=0;$i<count($f['name']);$i++) if (($f['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK)
            $files[] = ['name'=>(string)$f['name'][$i],'tmp'=>(string)$f['tmp_name'][$i],'size'=>(int)$f['size'][$i]];
    } elseif (($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK) {
        $files[] = ['name'=>(string)$f['name'],'tmp'=>(string)$f['tmp_name'],'size'=>(int)$f['size']];
    }
}
if (empty($files)) { echo json_encode(['ok'=>false,'error'=>'Aucun fichier reçu']); exit; }

$results = [];
foreach ($files as $file) {
    $row = ['name'=>$file['name'], 'status'=>'pile', 'locataire'=>null, 'bail_id'=>0, 'reason'=>''];
    $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { $row['status']='erreur'; $row['reason']='non PDF'; $results[]=$row; continue; }

    $text = extractPdfText($file['tmp']);
    if (strlen(trim((string)$text)) < 50) { $row['status']='pile'; $row['reason']='texte non extractible (scan ? → pile)'; }

    $parsed = avis_parse((string)$text);
    $match  = avis_match($pdo, $parsed, (string)$text);
    $row['immeuble_id'] = $match['immeuble_id'] ?? 0;

    // Contexte société/agence : résolu depuis le bien/propriétaire (jamais la session).
    $bienId = (int)($match['bien_id'] ?? 0); $immId = (int)($match['immeuble_id'] ?? 0);
    $rv = bien_resolve_soc_age($pdo, $bienId);
    $socId = $rv['societe_id']; $ageId = $rv['agence_id'];
    if ($bienId > 0) {
        $b = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id=?"); $b->execute([$bienId]);
        $immId = (int)($b->fetchColumn() ?: $immId);
    }

    // Stockage physique
    $storageDir = realpath(dirname(__DIR__)).DIRECTORY_SEPARATOR.'storage_fluxbox'.DIRECTORY_SEPARATOR.$socId.DIRECTORY_SEPARATOR.date('Y').DIRECTORY_SEPARATOR.date('m');
    if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
    $hash = hash_file('sha256', $file['tmp']) ?: '';
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/','_',$file['name']) ?: 'avis.pdf';
    $dest = $storageDir.DIRECTORY_SEPARATOR.date('Ymd_His').'_'.substr($hash,0,8).'_'.$safe;
    if (!(is_uploaded_file($file['tmp']) ? move_uploaded_file($file['tmp'],$dest) : copy($file['tmp'],$dest))) {
        $row['status']='erreur'; $row['reason']='écriture disque'; $results[]=$row; continue;
    }

    if (!empty($match['certain']) && $bienId > 0) {
        // ── Classement direct en GED ──
        $bailId = (int)$match['bail_id']; $tiersId = (int)($match['tiers_id'] ?? 0);
        $namingCtx = ['upload_date'=>'now','n1_slug'=>'05_gestion_locative','n2_slug'=>'07_quittances_avis',
            'n3_slug'=>'01_avis_echeance','date_doc'=>date('Y-m-d'),'type_doc'=>'avis_echeance',
            'source_filename'=>$file['name'],'user_id'=>$userId,'entity_type'=>'BIEN','entity_id'=>$bienId];
        $ctx = ['tenant_id'=>$socId,'societe_id'=>$socId,'agence_id'=>$ageId,'document_type'=>'avis_echeance',
            'source_module'=>'05_GESTION_LOCATIVE','security_level'=>'interne','created_by'=>$userId,'naming_ctx'=>$namingCtx];
        $links = [
            ['entity_type'=>'BIEN','entity_id'=>$bienId,'relation_type'=>'main','is_validated'=>true,'validated_by'=>$userId],
            ['entity_type'=>'BAIL','entity_id'=>$bailId,'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId],
        ];
        if ($immId > 0)   $links[] = ['entity_type'=>'IMB','entity_id'=>$immId,'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId];
        if ($tiersId > 0) $links[] = ['entity_type'=>'TIERS','entity_id'=>$tiersId,'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId];

        $res = gus_commit_document($pdo, ['path_on_disk'=>$dest,'name_original'=>$file['name'],'hash_sha256'=>$hash,
            'mime_type'=>'application/pdf','size_bytes'=>$file['size']], $ctx, $links);
        if (!empty($res['ok'])) {
            try { $pdo->prepare("UPDATE ged_documents SET id_bail=? WHERE id=?")->execute([$bailId,(int)$res['doc_id']]); } catch (Throwable $e) {}
            $row['status']='classé'; $row['bail_id']=$bailId; $row['locataire']=$match['locataire_nom'];
            $row['reason']=$match['reason']; $row['doc_id']=(int)$res['doc_id'];
        } else {
            $row['status']='erreur'; $row['reason']='commit: '.implode(' / ',$res['errors']??['?']);
        }
    } else {
        // ── Pile FluxBox (attribution manuelle) ──
        try {
            $st = $pdo->prepare("SELECT id FROM fluxbox_documents WHERE tenant_id=? AND hash_sha256=? LIMIT 1");
            $st->execute([$socId,$hash]); $docId=(int)$st->fetchColumn();
            if ($docId===0) {
                $pdo->prepare("INSERT INTO fluxbox_documents (tenant_id,hash_sha256,source_type,source_meta,fichier_nom,fichier_chemin,taille_octets,mime_type,ocr_status,first_seen_at,last_seen_at,seen_count,created_by,created_at)
                               VALUES (?,?,'manual',?,?,?,?,'application/pdf','pending',NOW(),NOW(),1,?,NOW())")
                    ->execute([$socId,$hash,json_encode(['origin'=>'avis_import','immeuble_id'=>$immId,'parse'=>$parsed],JSON_UNESCAPED_UNICODE),$file['name'],$dest,$file['size'],$userId?:null]);
                $docId=(int)$pdo->lastInsertId();
            }
            $st=$pdo->prepare("SELECT id FROM fluxbox_cartes WHERE document_id=? LIMIT 1"); $st->execute([$docId]);
            if ((int)$st->fetchColumn()===0) {
                $pdo->prepare("INSERT INTO fluxbox_cartes (tenant_id,document_id,titre,sous_titre,priorite,statut,proposition_json,created_by,created_at)
                               VALUES (?,?,?,?,'normal','pending',?,?,NOW())")
                    ->execute([$socId,$docId,mb_substr('Avis d\'échéance · '.$file['name'],0,255),'À attribuer (avis non rattaché auto)',
                        json_encode(['avis_import'=>true,'immeuble_id'=>$immId,'parse'=>$parsed],JSON_UNESCAPED_UNICODE),$userId?:null]);
            }
            $row['status']='pile'; if($row['reason']==='') $row['reason']=$match['reason'];
        } catch (Throwable $e) { $row['status']='erreur'; $row['reason']='pile: '.$e->getMessage(); }
    }
    $results[] = $row;
}

$classes = count(array_filter($results, fn($r)=>$r['status']==='classé'));
$pile    = count(array_filter($results, fn($r)=>$r['status']==='pile'));
$err     = count(array_filter($results, fn($r)=>$r['status']==='erreur'));
echo json_encode(['ok'=>true,'total'=>count($results),'classes'=>$classes,'pile'=>$pile,'erreurs'=>$err,'results'=>$results], JSON_UNESCAPED_UNICODE);
