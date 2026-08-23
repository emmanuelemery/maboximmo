<?php
/**
 * api/signature_zones_save.php — enregistre les zones posées sur un PDF de la GED.
 *
 * POST JSON : { doc_id, objet_type?, objet_id?, zones: [ {page,x,y,w,h,type,role_code,libelle,obligatoire}, … ] }
 *   → { ok, nb, zones }
 *
 * ── REMPLACEMENT INTÉGRAL, ASSUMÉ ──────────────────────────────────────────────────
 * L'éditeur envoie l'état COMPLET du document : on efface puis on réécrit, dans une
 * transaction. Faire du différentiel (créer/modifier/supprimer zone par zone) demanderait
 * des identifiants stables côté client et produirait, au premier écart, un document dont
 * les zones ne correspondent plus à ce qui est affiché. Ici l'écran est la vérité, et il
 * l'est en une seule opération.
 *
 * ⚠️ Ce remplacement ne touche QUE des emplacements — jamais une valeur signée. Les
 * zones disent OÙ écrire ; ce qui a été réellement apposé vit dans la chaîne de
 * signature et n'est pas concerné. Le jour où un document est parti en signature, ce
 * sont ses zones GELÉES qui feront foi : c'est pourquoi l'apposition devra recopier la
 * définition dans l'acte, et non relire cette table. On ne réécrit pas le passé.
 *
 * Auth : utilisateur connecté + CSRF. La lecture du document passe par GedAccess, donc
 * on ne peut pas poser de zones sur un document qu'on n'a pas le droit d'ouvrir.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/ged_access.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
if (function_exists('verify_csrf_any')) verify_csrf_any('signature_zones');

$pdo   = $GLOBALS['pdo'];
$docId = (int)($body['doc_id'] ?? 0);
if ($docId <= 0) exit(json_encode(['ok'=>false,'error'=>'doc_id requis']));

/* ── LE DROIT DE POSER DES ZONES = LE DROIT D'OUVRIR LE DOCUMENT ──────────────────
   On ne réinvente pas un contrôle de périmètre ici : on demande le document à la cage.
   Si elle refuse, il n'y a rien à annoter. C'est la règle du point de passage central. */
try {
    /* ⚠️ 'preview' — et non 'view', qui n'existe pas. `GED_USAGES` vaut
       download|preview|mail|ocr|ia|export|meta, et un usage inconnu fait renvoyer null
       à `grant()` : le document devenait « inaccessible » alors que le droit était là.
       Attrapé au premier test réel. 'preview' est le bon niveau : poser des zones, c'est
       regarder le document, pas l'exporter ni l'envoyer. */
    $g = GedAccess::grant($docId, 'preview');
    if (empty($g['path']) || !is_file($g['path'])) {
        http_response_code(403);
        exit(json_encode(['ok'=>false,'error'=>'Document inaccessible'], JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $e) {
    http_response_code(403);
    exit(json_encode(['ok'=>false,'error'=>'Document inaccessible : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

$TYPES = ['signature', 'paraphe', 'date', 'texte', 'case'];
$zones = is_array($body['zones'] ?? null) ? $body['zones'] : [];

/* ── ON BORNE, ON NE FAIT PAS CONFIANCE ────────────────────────────────────────────
   Une zone hors page ou de taille nulle est invisible à l'écran mais bien enregistrée :
   au moment d'apposer, elle écrirait dans le vide ou hors du papier. On ramène donc
   chaque valeur dans [0,100] et on impose une taille minimale visible. */
$clamp = static fn($v): float => max(0.0, min(100.0, (float)$v));
$prop  = [];
foreach ($zones as $i => $z) {
    $type = (string)($z['type'] ?? 'signature');
    if (!in_array($type, $TYPES, true)) $type = 'signature';
    $x = $clamp($z['x'] ?? 0); $y = $clamp($z['y'] ?? 0);
    $w = max(0.5, min(100.0 - $x, (float)($z['w'] ?? 0)));
    $h = max(0.5, min(100.0 - $y, (float)($z['h'] ?? 0)));
    $page = max(1, (int)($z['page'] ?? 1));
    $prop[] = [
        'page' => $page, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'type' => $type,
        'role_code'   => mb_substr(trim((string)($z['role_code'] ?? '')), 0, 40) ?: null,
        'libelle'     => mb_substr(trim((string)($z['libelle'] ?? '')), 0, 120) ?: null,
        'obligatoire' => !empty($z['obligatoire']) ? 1 : 0,
        'ordre'       => (int)$i,
    ];
}

$objetType = mb_substr(trim((string)($body['objet_type'] ?? '')), 0, 30) ?: null;
$objetId   = (int)($body['objet_id'] ?? 0) ?: null;
$userId    = function_exists('current_user_id') ? (int)current_user_id() : 0;

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM signature_zones WHERE ged_document_id = ?")->execute([$docId]);
    if ($prop) {
        /* ⚠️ Placeholders `?` et non `:nommes` : un placeholder nommé réutilisé dans
           une insertion multiple plante en production. */
        $st = $pdo->prepare("INSERT INTO signature_zones
            (ged_document_id, objet_type, objet_id, page, x, y, w, h, type, role_code, libelle, obligatoire, ordre, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($prop as $z) {
            $st->execute([$docId, $objetType, $objetId, $z['page'], $z['x'], $z['y'], $z['w'], $z['h'],
                          $z['type'], $z['role_code'], $z['libelle'], $z['obligatoire'], $z['ordre'], $userId ?: null]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[signature_zones_save] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>'Enregistrement impossible : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

/* ── UN DOCUMENT PRÉPARÉ SE VOIT ──────────────────────────────────────────────────
   Poser des zones, c'est préparer un document pour la signature. Sans mention, il
   redevient un fichier ordinaire dans la liste — et un document préparé puis oublié est
   exactement le genre de chose qui dort des semaines sans que personne s'en aperçoive.
   ⚠️ On ne redescend PAS l'état d'un document déjà signé : retirer toutes les zones d'un
   acte signé ne le rend pas « à préparer ». */
require_once dirname(__DIR__) . '/inc/signature_etat.php';
$dejaSigne = (string)(sig_etat_lire($pdo, $docId)['etat'] ?? '') === 'signe';
if (!$dejaSigne) sig_etat_marquer($pdo, $docId, ['etat' => $prop ? 'a_signer' : '']);

/* On renvoie ce qui est RÉELLEMENT en base — bornage compris. Si une zone a été
   ramenée dans la page, l'écran doit le voir plutôt que d'afficher ce qu'il croyait. */
$st = $pdo->prepare("SELECT id, page, x, y, w, h, type, role_code, libelle, obligatoire, ordre
                       FROM signature_zones WHERE ged_document_id = ? ORDER BY page, ordre, id");
$st->execute([$docId]);
echo json_encode([
    'ok'      => true,
    'nb'      => count($prop),
    'zones'   => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
    'message' => count($prop) . ' zone(s) enregistrée(s).',
], JSON_UNESCAPED_UNICODE);
