<?php
/**
 * api/signature_signer.php — SIGNER MOI-MÊME un document de la GED.
 *
 * POST JSON : { doc_id, mention? } → { ok, doc_id_signe, hash, apposees, restantes, message }
 *
 * Le flux « je reçois un devis, un contrat, une convention et je le renvoie signé ».
 * Distinct de la cérémonie : ici c'est MOI qui signe, je suis déjà identifié dans MBI —
 * pas de lien nominatif, pas de code SMS. C'est ma session qui fait la preuve.
 *
 * ── CE QUI EST PRODUIT ─────────────────────────────────────────────────────────────
 *   1. le document ORIGINAL, repris page par page, avec les valeurs apposées ;
 *   2. une page de JUSTIFICATIFS décrivant qui a signé, quand, depuis où, et sur quel
 *      document (empreinte de la source) ;
 *   3. les deux fusionnés, aplatis, classés en GED comme un document NOUVEAU.
 *
 * ⚠️ L'ORIGINAL N'EST JAMAIS REMPLACÉ. On ajoute une pièce, on n'écrase pas celle qu'on
 * a reçue : le document non signé est la preuve de ce qui a été proposé, et le signé de
 * ce qui a été accepté. Les confondre, c'est perdre la moitié du dossier.
 *
 * ⚖️ Le tracé apposé est une APPARENCE, pas une preuve — n'importe qui disposant du
 * fichier pourrait le reproduire. Ce qui vaut signature électronique (art. 1367 al. 2
 * C. civ.), c'est le procédé d'identification : compte, horodatage, IP, empreinte. La
 * page de justificatifs le dit dans ces termes et ne prétend à rien de plus.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/inc/ged_access.php';
require_once dirname(__DIR__) . '/inc/signature_apposition.php';
require_once dirname(__DIR__) . '/inc/acte_pdf_fusion.php';
require_once dirname(__DIR__) . '/inc/ged_document_links.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
if (function_exists('verify_csrf_any')) verify_csrf_any('signature_zones');

$pdo    = $GLOBALS['pdo'];
$body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$docId  = (int)($body['doc_id'] ?? 0);
$userId = function_exists('current_user_id') ? (int)current_user_id() : 0;
$socId  = (int)($_SESSION['id_societe'] ?? 0);
$ageId  = (int)($_SESSION['id_agence'] ?? 0);
if ($docId <= 0) exit(json_encode(['ok'=>false,'error'=>'doc_id requis']));

$ko = static function (string $m, int $code = 400) { http_response_code($code); exit(json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE)); };

/* ── La source, et le droit de la lire ─────────────────────────────────────────── */
try {
    $g = GedAccess::grant($docId, 'preview');
    if (empty($g['path']) || !is_file($g['path'])) $ko('Document inaccessible', 403);
} catch (Throwable $e) { $ko('Document inaccessible : ' . $e->getMessage(), 403); }
$src = (string)$g['path'];

$st = $pdo->prepare("SELECT name_display, id_bien, id_bail FROM ged_documents WHERE id = ? LIMIT 1");
$st->execute([$docId]);
$doc = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$nomSrc = (string)($doc['name_display'] ?? ('Document #' . $docId));

/* ── Les zones ─────────────────────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT id, page, x, y, w, h, type, role_code, libelle FROM signature_zones
                      WHERE ged_document_id = ? ORDER BY page, ordre, id");
$st->execute([$docId]);
$zones = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$zones) $ko('Aucune zone n\'a été posée sur ce document : ouvre « ✍️ Signer » et place-les d\'abord.');

/* ── Mon spécimen ──────────────────────────────────────────────────────────────── */
$spec = ['signature'=>null, 'paraphe'=>null, 'cachet'=>null];
try {
    $st = $pdo->prepare("SELECT type, image_data FROM signature_specimens
                          WHERE actif = 1 AND ((proprietaire_type='user' AND proprietaire_id=?)
                                            OR (proprietaire_type='societe' AND proprietaire_id=?))
                          ORDER BY id DESC");
    $st->execute([$userId, $socId]);
    foreach ($st as $r) { if (array_key_exists($r['type'], $spec) && !$spec[$r['type']]) $spec[$r['type']] = (string)$r['image_data']; }
} catch (Throwable $e) { $ko('Table des spécimens absente : rejouer la migration 20260823b.'); }
if (!$spec['signature']) $ko('Aucune signature enregistrée. Ouvre « Ma signature » et trace-la une première fois.');

/* ── Quelles zones je remplis ──────────────────────────────────────────────────────
   Les miennes (rôle « mandataire ») et celles qui n'ont été attribuées à personne :
   sur un devis qu'on valide seul, poser un rôle n'a pas de sens et l'agent ne le fera
   pas. Les zones destinées à d'autres sont laissées INTACTES et comptées : elles
   partiront par la cérémonie. */
$mention  = trim((string)($body['mention'] ?? ''));
$valeurs  = []; $apposees = 0; $restantes = 0;
foreach ($zones as $z) {
    $role = (string)($z['role_code'] ?? '');
    if ($role !== '' && $role !== 'mandataire') { $restantes++; continue; }
    switch ((string)$z['type']) {
        case 'signature': $valeurs[(int)$z['id']] = ['image' => $spec['signature']]; break;
        case 'paraphe':   $valeurs[(int)$z['id']] = ['image' => $spec['paraphe'] ?: $spec['signature']]; break;
        case 'date':      $valeurs[(int)$z['id']] = ['texte' => date('d/m/Y')]; break;
        case 'case':      $valeurs[(int)$z['id']] = ['texte' => 'X']; break;
        case 'texte':
            /* Le libellé de la zone EST le texte à écrire (« Bon pour accord »). À
               défaut, la mention envoyée par l'écran. Une zone sans l'un ni l'autre est
               ignorée : on n'invente pas le contenu d'un acte. */
            $t = (string)($z['libelle'] ?? '') ?: $mention;
            if ($t !== '') $valeurs[(int)$z['id']] = ['texte' => $t];
            break;
    }
    if (isset($valeurs[(int)$z['id']])) $apposees++;
}
if (!$apposees) $ko('Aucune zone ne vous est destinée sur ce document.');

/* ── 1. Apposition ─────────────────────────────────────────────────────────────── */
$signe = signature_apposer($src, $zones, $valeurs, 'Signé — ' . $nomSrc);
if (!$signe) $ko('L\'apposition a échoué — voir le journal du serveur.', 500);

/* ── 2. Les justificatifs ──────────────────────────────────────────────────────────
   Ce qui prouve réellement quelque chose. Écrits SANS emphase et sans promesse : on
   décrit le procédé tel qu'il est, pour qu'un lecteur puisse juger de sa valeur. */
$moi = ['nom' => '', 'email' => ''];
try {
    $q = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) n, email FROM users WHERE id=? LIMIT 1");
    $q->execute([$userId]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $moi['nom'] = trim((string)$r['n']); $moi['email'] = (string)$r['email']; }
} catch (Throwable $e) {}
$ip       = function_exists('client_ip') ? (string)client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
$hashSrc  = hash_file('sha256', $src) ?: '';
$quand    = date('d/m/Y \à H\hi\ms');
$e        = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$justif = null;
try {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $tmpDir = dirname(__DIR__) . '/uploads/_mpdf_tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    $mp = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','tempDir'=>$tmpDir,
                          'margin_top'=>18,'margin_left'=>18,'margin_right'=>18,'margin_bottom'=>16]);
    $mp->SetTitle('Justificatifs de signature');
    $html = '<div style="font-family:sans-serif;color:#1c2226;font-size:10.5pt;line-height:1.55;">'
      . '<h1 style="font-size:15pt;color:#243B5C;margin:0 0 2px;">Justificatifs de signature électronique</h1>'
      . '<p style="font-size:8.5pt;color:#666;margin:0 0 14px;">Pièce annexée à l\'acte signé — elle en est la preuve.</p>'
      . '<table style="width:100%;border-collapse:collapse;font-size:9.5pt;">'
      . '<tr><td style="border:0.5pt solid #cddada;padding:5px 8px;width:34%;background:#eef2f6;">Document signé</td>'
      .     '<td style="border:0.5pt solid #cddada;padding:5px 8px;">' . $e($nomSrc) . '</td></tr>'
      . '<tr><td style="border:0.5pt solid #cddada;padding:5px 8px;background:#eef2f6;">Empreinte du document d\'origine</td>'
      .     '<td style="border:0.5pt solid #cddada;padding:5px 8px;font-family:monospace;font-size:8pt;">' . $e($hashSrc) . '</td></tr>'
      . '<tr><td style="border:0.5pt solid #cddada;padding:5px 8px;background:#eef2f6;">Signataire</td>'
      .     '<td style="border:0.5pt solid #cddada;padding:5px 8px;"><b>' . $e($moi['nom'] ?: ('utilisateur #' . $userId)) . '</b>'
      .     ($moi['email'] ? ' — ' . $e($moi['email']) : '') . '</td></tr>'
      . '<tr><td style="border:0.5pt solid #cddada;padding:5px 8px;background:#eef2f6;">Date et heure</td>'
      .     '<td style="border:0.5pt solid #cddada;padding:5px 8px;">' . $e($quand) . '</td></tr>'
      . '<tr><td style="border:0.5pt solid #cddada;padding:5px 8px;background:#eef2f6;">Adresse IP</td>'
      .     '<td style="border:0.5pt solid #cddada;padding:5px 8px;">' . $e($ip ?: '—') . '</td></tr>'
      . '<tr><td style="border:0.5pt solid #cddada;padding:5px 8px;background:#eef2f6;">Éléments apposés</td>'
      .     '<td style="border:0.5pt solid #cddada;padding:5px 8px;">' . (int)$apposees . ' zone(s) remplie(s)'
      .     ($restantes > 0 ? ', ' . (int)$restantes . ' laissée(s) à d\'autres signataires' : '') . '</td></tr>'
      . '</table>'
      . '<h2 style="font-size:11pt;color:#243B5C;margin:16px 0 4px;">Nature du procédé</h2>'
      . '<p style="text-align:justify;">La signature a été apposée depuis un compte nominatif de MaBoxImmo, '
      . 'après authentification. Le tracé reproduit est un <b>spécimen enregistré par le signataire</b> : il '
      . 'permet de le reconnaître, mais ne constitue pas à lui seul une preuve. Ce qui identifie le signataire, '
      . 'c\'est l\'ensemble consigné ci-dessus — compte utilisé, horodatage, adresse IP et empreinte du document.</p>'
      . '<p style="text-align:justify;">Ce procédé relève de la signature électronique <b>simple</b> au sens de '
      . 'l\'article 1367 alinéa 2 du Code civil. Il ne s\'agit ni d\'une signature avancée ni d\'une signature '
      . 'qualifiée au sens du règlement eIDAS, et le présent document ne prétend pas le contraire.</p>'
      . '<p style="font-size:8.5pt;color:#666;margin-top:14px;">Toute modification ultérieure du document signé '
      . 'en changerait l\'empreinte : celle-ci est calculée sur le fichier final et conservée avec lui.</p>'
      . '</div>';
    $mp->WriteHTML($html);
    $justif = $tmpDir . '/justif_' . bin2hex(random_bytes(5)) . '.pdf';
    $mp->Output($justif, \Mpdf\Output\Destination::FILE);
} catch (Throwable $ex) {
    error_log('[signature_signer justificatifs] ' . $ex->getMessage());
    $justif = null;   // non bloquant : mieux vaut l'acte signé sans preuve annexe que rien
}

/* ── 3. Fusion, empreinte, classement ──────────────────────────────────────────── */
$final = $justif ? (acte_fusionner_pdf([$signe, $justif], 'Signé — ' . $nomSrc) ?: $signe) : $signe;
$hash  = signature_empreinte($final) ?: '';

$nomFinal = 'Signé — ' . $nomSrc;
if (!preg_match('/\.pdf$/i', $nomFinal)) $nomFinal .= '.pdf';

try {
    $res = gus_commit_document($pdo, [
        'path_on_disk'  => $final,
        'name_original' => $nomFinal,
        'hash_sha256'   => $hash,
        'mime_type'     => 'application/pdf',
        'size_bytes'    => filesize($final) ?: 0,
    ], [
        'tenant_id'      => $socId ?: null,
        'societe_id'     => $socId ?: null,
        'agence_id'      => $ageId ?: null,
        'document_type'  => 'ACTE_SIGNE',
        'source_module'  => '01_JURIDIQUE',
        'security_level' => 'interne',
        'created_by'     => $userId ?: null,
    ], []);
    if (empty($res['ok'])) $ko('Classement en GED impossible : ' . implode(' / ', $res['errors'] ?? ['inconnu']), 500);
    $newId = (int)$res['doc_id'];
    /* Le signé pointe vers l'original : on doit toujours pouvoir remonter à ce qui a
       été proposé avant signature. */
    try { $pdo->prepare("UPDATE ged_documents SET parent_document_id = ? WHERE id = ?")->execute([$docId, $newId]); }
    catch (Throwable $e2) { error_log('[signature_signer parent] ' . $e2->getMessage()); }

    /* ── LES DEUX PIÈCES SONT MARQUÉES, ET SE POINTENT L'UNE L'AUTRE ──────────────
       Le vierge reste en GED — c'est la preuve de ce qui a été PROPOSÉ, le signé de ce
       qui a été ACCEPTÉ. Sans mention, une liste les montrerait côte à côte, avec presque
       le même nom, et on ne saurait pas lequel envoyer. D'où l'état, visible d'un coup
       d'œil, et le renvoi croisé pour passer de l'un à l'autre sans le chercher.
       Reste `en_cours` si des zones attendent d'autres signataires : ce document N'EST
       PAS abouti, et l'écrire « signé » serait faux. */
    require_once dirname(__DIR__) . '/inc/signature_etat.php';
    $etatFinal = $restantes > 0 ? 'en_cours' : 'signe';
    sig_etat_marquer($pdo, $newId, [
        'etat'      => $etatFinal,
        'signe_le'  => date('Y-m-d H:i:s'),
        'signe_par' => $moi['nom'] ?: ('utilisateur #' . $userId),
        'hash'      => $hash,
        'source'    => $docId,
        'apposees'  => $apposees,
        'restantes' => $restantes,
    ]);
    sig_etat_marquer($pdo, $docId, [
        'etat'      => $etatFinal,
        'doc_signe' => $newId,
        'signe_le'  => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $ex) {
    error_log('[signature_signer ged] ' . $ex->getMessage());
    $ko('Classement en GED impossible : ' . $ex->getMessage(), 500);
}

echo json_encode([
    'ok'           => true,
    'doc_id_signe' => $newId,
    'hash'         => $hash,
    'apposees'     => $apposees,
    'restantes'    => $restantes,
    'url'          => app_url('/api/ged_doc_serve.php?id=' . $newId),
    'message'      => '✅ Document signé — ' . $apposees . ' zone(s) remplie(s)'
                    . ($restantes > 0 ? ', ' . $restantes . ' laissée(s) aux autres signataires' : '')
                    . ($justif ? ', justificatifs joints.' : '. ⚠️ Justificatifs non générés — voir le journal.'),
], JSON_UNESCAPED_UNICODE);
