<?php
declare(strict_types=1);
set_time_limit(120);
/**
 * api/transaction_mandat_pdf.php — Génère le PDF du mandat de vente (TCPDF) à partir du
 * rendu "corps seul" de transaction_mandat_preview.php.
 *
 * Paramètres (GET ou POST) :
 *   id_dossier (requis), modele?, net_vendeur?, projet=0|1 (filigrane PROJET, défaut 0),
 *   save=0|1 (1 = enregistre en GED "provisoire" lié au bien ; 0 = stream/download),
 *   download=0|1 (si save=0 : inline ou attachment)
 *
 * Réponse : si save=1 → JSON { ok, doc_id, url } ; sinon → flux application/pdf.
 * Réutilisable par l'envoi mail (api/transaction_mandat_email.php).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_once __DIR__ . '/../inc/mandat_vente_pdf.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$idDossier = (int)($_REQUEST['id_dossier'] ?? 0);
$projet    = (($_REQUEST['projet'] ?? '0') === '1');
$save      = (($_REQUEST['save'] ?? '0') === '1');
$download  = (($_REQUEST['download'] ?? '0') === '1');
$netV      = isset($_REQUEST['net_vendeur']) && $_REQUEST['net_vendeur'] !== '' ? (string)$_REQUEST['net_vendeur'] : '';
$modele    = (string)($_REQUEST['modele'] ?? 'mandat_simple');
if ($idDossier <= 0) { http_response_code(400); exit('id_dossier requis'); }

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { http_response_code(404); exit('Dossier introuvable'); }
$idBien = (int)$dossier['id_bien'];
$refDossier = (string)($dossier['reference'] ?? ('#' . $idDossier));

try {
    $pdfPath = mandat_vente_build_pdf($idDossier, $modele, $netV, $projet);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json'); exit(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
}

// ── Enregistrement GED "provisoire" ──
if ($save) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $ctxB = $pdo->prepare("SELECT b.id_societe, b.id_agence, s.raison_sociale AS soc_raison, a.code_agence, a.nom_agence
                               FROM biens b LEFT JOIN societes s ON s.id=b.id_societe LEFT JOIN agences a ON a.id=b.id_agence
                               WHERE b.id=? LIMIT 1");
        $ctxB->execute([$idBien]); $cb = $ctxB->fetch(PDO::FETCH_ASSOC) ?: [];
        $socId = (int)($cb['id_societe'] ?? 0) ?: 1; $ageId = (int)($cb['id_agence'] ?? 0) ?: 3;
        $userId = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $dateDoc = date('Y-m-d');

        // Le fichier doit être PERSISTANT (la GED le référence sur disque). On le sort du tmp.
        $permDir = __DIR__ . '/../uploads/mandats/';
        if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
        $permName = 'mandat_'.$idDossier.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.pdf';
        $permPath = $permDir . $permName;
        if (!@rename($pdfPath, $permPath)) { @copy($pdfPath, $permPath); @unlink($pdfPath); }
        $publicUrl = '/uploads/mandats/' . $permName;

        // Anti-doublon : on désactive les précédents mandats PROVISOIRES de CE bien
        // (générés par cet écran) → un seul actif = la dernière saisie. Pas de doublon.
        try {
            $pdo->prepare("UPDATE ged_documents d
                           JOIN ged_document_links l ON l.document_id = d.id AND l.entity_type='BIEN' AND l.entity_id = ?
                           SET d.status = 'deleted'
                           WHERE d.document_type = 'MANDAT_VENTE' AND d.status = 'active'
                             AND d.metadata LIKE '%transaction_mandat_pdf%'")
                ->execute([$idBien]);
        } catch (Throwable) { /* best-effort */ }

        $res = gus_commit_document($pdo,
            ['path_on_disk'=>$permPath, 'name_original'=>'Mandat_vente_'.$refDossier.'.pdf', 'mime_type'=>'application/pdf', 'size_bytes'=>filesize($permPath) ?: 0, 'public_url'=>$publicUrl],
            [
                'document_type'=>'MANDAT_VENTE', 'source_module'=>'04_TRANSACTION', 'security_level'=>'interne',
                'societe_id'=>$socId, 'agence_id'=>$ageId, 'tenant_id'=>$socId, 'created_by'=>$userId, 'storage_provider'=>'local',
                'name_display'=>'Mandat de vente (provisoire) — '.$refDossier,
                'metadata_extra'=>['statut'=>'provisoire','id_dossier'=>$idDossier,'doc_date'=>$dateDoc,'legacy_source'=>'transaction_mandat_pdf',
                    'classement'=>['bien_id_bdd'=>$idBien,'date_doc'=>$dateDoc]],
                'naming_ctx'=>['societe_raison'=>$cb['soc_raison'] ?? 'Régie EMERY','agence_code'=>$cb['code_agence'] ?? 'RE69-2','agence_nom'=>$cb['nom_agence'] ?? 'LYON',
                    'user_id'=>$userId,'n1_slug'=>'04_transaction','n2_slug'=>'mandats','n3_slug'=>'mandat_vente','type_doc'=>'MANDAT_VENTE',
                    'entity_type'=>'BIEN','entity_id'=>$idBien,'date_doc'=>$dateDoc,'source_filename'=>'Mandat_vente.pdf'],
            ],
            [['entity_type'=>'BIEN','entity_id'=>$idBien,'relation_type'=>'main']]
        );
        @unlink($pdfPath);
        if (empty($res['ok'])) exit(json_encode(['ok'=>false,'error'=>'GED: '.json_encode($res['errors'] ?? ['unknown'])]));
        echo json_encode(['ok'=>true, 'doc_id'=>(int)($res['doc_id'] ?? 0), 'url'=>app_url('/api/ged_document_view.php?id='.(int)($res['doc_id'] ?? 0).'&mode=inline')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        @unlink($pdfPath); http_response_code(500); exit(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
    }
    exit;
}

// ── Stream direct (aperçu / téléchargement) ──
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="Mandat_vente_' . preg_replace('/[^A-Za-z0-9_\-]/','_',$refDossier) . '.pdf"');
header('Content-Length: ' . (filesize($pdfPath) ?: 0));
readfile($pdfPath);
@unlink($pdfPath);
