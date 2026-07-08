<?php
/**
 * api/fluxbox_ged_name_preview.php — Aperçu LIVE du nom GED via LE MOTEUR UNIQUE.
 *
 * Le modal d'upload ne doit PLUS recalculer le nom en JS (il ne connaît pas le
 * glossaire). Il appelle ici le MÊME moteur que le commit — `mbo_build_ged_name`
 * (positions 1-3 = codes glossaire société/agence/métier) — pour que l'aperçu
 * affiché soit STRICTEMENT identique au nom réellement classé.
 *
 * POST JSON : { flux_doc_id?, entity_type?, entity_id?, bail_id?, bien_id?,
 *               immeuble_id?, tiers_id?, societe_id?, agence_id?, metier?,
 *               type_doc?, libelle?, ref?, date_doc?, filename? }
 *   → { ok, name }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo = $GLOBALS['pdo'];
$b = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

require_once dirname(__DIR__) . '/inc/maboxoffice_match.php';
if (!function_exists('mbo_build_ged_name')) { exit(json_encode(['ok'=>false,'error'=>'moteur indisponible'])); }

// Point de départ : la ligne fluxbox_documents (société/agence/date d'ingestion…),
// si un fichier est déjà en file. Sinon on part d'un $d vide alimenté par le modal.
$d = [];
$fluxDocId = (int)($b['flux_doc_id'] ?? 0);
if ($fluxDocId > 0) {
    $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id=?");
    $st->execute([$fluxDocId]);
    $d = $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Entité : MÊME priorité que le commit (fluxbox_functions ~L888) BAIL > BIEN > IMB > TIERS.
$entType = strtoupper((string)($b['entity_type'] ?? ''));
$entId   = (int)($b['entity_id'] ?? 0);
if ($entType === '' || $entId <= 0) {
    if (!empty($b['bail_id']))          { $entType='BAIL';  $entId=(int)$b['bail_id']; }
    elseif (!empty($b['bien_id']))      { $entType='BIEN';  $entId=(int)$b['bien_id']; }
    elseif (!empty($b['immeuble_id']))  { $entType='IMB';   $entId=(int)$b['immeuble_id']; }
    elseif (!empty($b['tiers_id']))     { $entType='TIERS'; $entId=(int)$b['tiers_id']; }
}
if ($entType !== '' && $entId > 0) { $d['mbo_entity_type']=$entType; $d['mbo_entity_id']=$entId; }

// Overrides du modal (choix courant) — prioritaires sur la ligne stockée, pour que
// l'aperçu suive en direct les modifs de card / type / métier.
if (isset($b['societe_id']) && (int)$b['societe_id'] > 0) $d['mbo_societe_id'] = (int)$b['societe_id'];
if (isset($b['agence_id'])  && (int)$b['agence_id']  !== 0) $d['mbo_agence_id']  = (int)$b['agence_id']; // -1 = toutes agences
if (($b['metier'] ?? '') !== '')    $d['mbo_metier']       = strtolower((string)$b['metier']);
if (($b['type_doc'] ?? '') !== '')  $d['mbo_type_propose'] = strtolower((string)$b['type_doc']);
if (($b['libelle'] ?? '') !== '')   $d['mbo_libelle']      = (string)$b['libelle'];
if (($b['ref'] ?? '') !== '')       $d['mbo_ref_salaire']  = (string)$b['ref'];
if (($b['date_doc'] ?? '') !== '')  $d['mbo_date_doc']     = (string)$b['date_doc'];
if (($b['filename'] ?? '') !== '')  $d['fichier_nom']      = (string)$b['filename'];
if (empty($d['tenant_id']))         $d['tenant_id']        = (int)($_SESSION['id_societe'] ?? 0);

try {
    // ensure=false : APERÇU seulement → ne crée AUCUN code glossaire ni lot.
    $name = mbo_build_ged_name($pdo, $d, false);
    echo json_encode(['ok'=>true, 'name'=>$name], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
