<?php
/**
 * api/bail_send.php — Envoie le projet de bail aux signataires (preneur + garant) pour
 * signature en ligne : crée les tokens (bail_signatures), envoie le lien sécurisé + le PDF
 * en pièce jointe, passe le bail en `envoye`. Clone fonctionnel de transaction_dossier_mandat_send.
 *
 * POST JSON : { bail_id }  →  { ok, envois:[{role,nom,email,url,sent,statut}] }
 * Auth : user + scope société (bypass admin, exception bailleur rôles 9/10).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bail_signature.php';
require_once dirname(__DIR__) . '/inc/bail_commercial_pdf.php';
require_once dirname(__DIR__) . '/inc/mailer.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$userId=(int)($_SESSION['user_id']??0); $userSoc=(int)($_SESSION['id_societe']??0); $isAdmin=((int)($_SESSION['id_role']??0)===1);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$bailId=(int)($body['bail_id']??0);
if ($bailId<=0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));
// Emails par rôle saisis dans le modal de cérémonie (preneur/caution/mandataire/bailleur).
$roleEmails = is_array($body['role_emails'] ?? null) ? array_map(static fn($v)=>trim((string)$v), $body['role_emails']) : [];

$st=$pdo->prepare("SELECT bb.id, bb.statut, bb.id_societe, bb.numero_bail, b.id_proprietaire, b.reference_bien
    FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?");
$st->execute([$bailId]); $bail=$st->fetch(PDO::FETCH_ASSOC);
if (!$bail){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($bail['id_societe']) && (int)$bail['id_societe']!==$userSoc){
    $ok=false;
    if (in_array((int)($_SESSION['id_role']??0),[9,10],true) && (int)($bail['id_proprietaire']??0)>0){
        $c=$pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId,(int)$bail['id_proprietaire']]); $ok=(bool)$c->fetchColumn();
    }
    if(!$ok){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}
if (!in_array($bail['statut'], ['projet','envoye'], true)) {
    exit(json_encode(['ok'=>false,'error'=>'Ce bail est '.$bail['statut'].' : envoi possible uniquement sur un projet.'], JSON_UNESCAPED_UNICODE));
}

try {
    $sigs = bsig_create_for_signataires($pdo, $bailId, $userId, $roleEmails);
    if (!$sigs) exit(json_encode(['ok'=>false,'error'=>'Aucun signataire — renseigne l\'email du preneur.'], JSON_UNESCAPED_UNICODE));

    // Relance ciblée : ne (re)traiter QU'UN seul signataire (celui qui n'a pas signé).
    $onlySig = (int)($body['sig_id'] ?? 0);
    if ($onlySig > 0) {
        $sigs = array_values(array_filter($sigs, static fn($s) => (int)$s['id'] === $onlySig));
        if (!$sigs) exit(json_encode(['ok'=>false,'error'=>'Signataire introuvable pour ce bail.'], JSON_UNESCAPED_UNICODE));
    }

    // PDF du projet (filigrané) en pièce jointe.
    $pdfAttach = [];
    try {
        $tmp = bail_build_pdf_dispatch($pdo, $bailId, true);
        $clean = sys_get_temp_dir() . '/Bail_' . preg_replace('/[^A-Za-z0-9_-]/','', (string)($bail['numero_bail'] ?: $bailId)) . '.pdf';
        if (@copy($tmp, $clean)) { $pdfAttach = [$clean]; @unlink($tmp); } else { $pdfAttach = [$tmp]; }
    } catch (Throwable $e) { error_log('[bail_send pdf] '.$e->getMessage()); }

    // RIB de GESTION de l'agence + total à verser à la signature (corps du mail) + DPE en PJ.
    $ribHtml = ''; $totalHtml = '';
    try {
        $ctx = bail_commercial_pdf_context($pdo, $bailId);
        if ($ctx) {
            $ge = $ctx['gestionnaire'] ?? [];
            if (!empty($ge['rib_iban'])) {
                $ribHtml = '<p style="font-size:13px;background:#f4f7f7;border:1px solid #dbe6e6;border-radius:8px;padding:10px 12px;">'
                    . '<strong>Coordonnées bancaires pour le versement (RIB de gestion de l\'agence)</strong><br>'
                    . ($ge['rib_nom'] ? htmlspecialchars((string)$ge['rib_nom']) . '<br>' : '')
                    . 'IBAN : <strong>' . htmlspecialchars((string)$ge['rib_iban']) . '</strong>'
                    . ($ge['rib_bic'] ? ' &nbsp;&middot;&nbsp; BIC : <strong>' . htmlspecialchars((string)$ge['rib_bic']) . '</strong>' : '') . '</p>';
            }
            $c = $ctx['cond'] ?? [];
            $tvaOn = !empty($c['tva_app']); $tvaT = (float)($c['tva_taux'] ?? 20) ?: 20.0;
            $perM = (($c['perio'] ?? '') === 'trimestrielle') ? 3 : 1;
            $loyM = (float)($c['loyer_m'] ?? 0); $chM = (float)($c['charges_m'] ?? 0); $tfM = (float)($c['prov_tf'] ?? 0);
            $techM = ($c['tech_pct'] ?? null) !== null ? $loyM * (float)$c['tech_pct'] / 100 : 0.0;
            $echBaseT = $tvaOn ? ($loyM + $techM) * $perM * (1 + $tvaT/100) : ($loyM + $techM) * $perM;
            $prRatio = 1.0; $prRaw = ($c['prorata_date'] ?? '') ?: ($c['date_effet'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prRaw)) {
                $pts = strtotime((string)$prRaw); $pm=(int)date('n',$pts); $pd=(int)date('j',$pts); $py=(int)date('Y',$pts);
                if ($perM === 3) { $qs=intdiv($pm-1,3)*3+1; $qs1=mktime(0,0,0,$qs,1,$py); $qe=mktime(0,0,0,$qs+3,0,$py); $tot=(int)round(($qe-$qs1)/86400)+1; $rem=(int)round(($qe-$pts)/86400)+1; $prRatio=$tot>0?$rem/$tot:1.0; }
                else { $dim=(int)date('t',$pts); $prRatio=$dim>0?($dim-$pd+1)/$dim:1.0; }
            }
            $dgM=(float)($c['dg_montant'] ?? 0); $deM=(float)($c['droit_entree'] ?? 0);
            $loyAn=(float)($c['loyer_a'] ?? $loyM*12); $hpPren=$c['hono_pct_pren'] ?? null;
            $honoPrenTTC = $hpPren !== null ? $loyAn * (float)$hpPren / 100 * 1.20 : (float)($c['hono_loc'] ?? 0);
            $totSign = ($echBaseT + $chM*$perM + $tfM*$perM) * $prRatio + $dgM + $deM + $honoPrenTTC;
            if ($totSign > 0) $totalHtml = '<p style="font-size:14px;"><strong>Montant total à verser à la signature : ' . number_format($totSign, 2, ',', ' ') . ' €</strong><br>Merci de régler <strong>l\'intégralité des sommes</strong> demandées, par virement, sur le RIB ci-dessous.</p>';
        }
    } catch (Throwable $e) { error_log('[bail_send rib/total] '.$e->getMessage()); }

    /* ── DPE DU BIEN EN PIÈCE JOINTE ─────────────────────────────────────────
       ⚠️🔥 CE BLOC N'A JAMAIS JOINT UN SEUL DPE. Il lisait `d.path_on_disk` —
       une colonne qui **n'existe pas** dans `ged_documents` : le chemin d'un
       document ne s'y trouve pas, il se demande à la cage d'accès. La requête
       levait donc une PDOException à CHAQUE envoi, avalée par un `catch` vide.
       Aucune trace dans le journal, aucun DPE en pièce jointe, et un mail qui
       avait l'air complet. Repéré le 18/08/2026, corrigé le 22/08.

       Deuxième défaut du même bloc : le filtre ne connaissait que 'dpe' et
       'dpe_bien' alors que la GED utilise AUSSI **'DIAG_DPE'** (les deux codes
       coexistent dans `ged_document_types`). Une partie du parc serait passée à
       travers même une fois le chemin réparé. Comparaison en UPPER : le type est
       saisi tantôt en minuscules, tantôt en majuscules.

       Le chemin passe par `ged_internal_path()`, qui applique la cage : ici on a
       une session agent (`require_login()` en tête de fichier), c'est le bon
       appel — contrairement à la page publique de signature, où il faut un
       jeton (cf. `bail_annexes_chemins()`). */
    $dpeEtat = ['joint' => false, 'raison' => ''];
    try {
        $qd = $pdo->prepare("SELECT d.id FROM ged_documents d
                              JOIN ged_document_links l ON l.document_id = d.id
                                   AND l.entity_type = 'BIEN'
                                   AND l.entity_id = (SELECT id_bien FROM bien_baux WHERE id = ?)
                             WHERE d.status = 'active'
                               AND (UPPER(d.document_type) IN ('DPE','DIAG_DPE','DPE_BIEN')
                                    OR LOWER(d.name_display) LIKE '%dpe%')
                             ORDER BY d.id DESC LIMIT 1");
        $qd->execute([$bailId]);
        $dpeId = (int)($qd->fetchColumn() ?: 0);
        if ($dpeId <= 0) {
            $dpeEtat['raison'] = 'aucun DPE rattaché à ce bien dans la GED';
        } else {
            require_once dirname(__DIR__) . '/inc/ged_access.php';
            $dpePath = function_exists('ged_internal_path') ? ged_internal_path($dpeId, 'mail') : null;
            if ($dpePath && is_file($dpePath)) {
                $pdfAttach[] = $dpePath;
                $dpeEtat['joint'] = true;
            } else {
                $dpeEtat['raison'] = 'document #' . $dpeId . ' introuvable sur le disque ou refusé par la cage GED';
            }
        }
    } catch (Throwable $e) {
        /* ⚠️ ON NE SE TAIT PLUS. C'est exactement ce `catch` muet qui a caché le
           défaut pendant des mois : une pièce obligatoire manquait à chaque
           envoi et rien, nulle part, ne le disait. */
        $dpeEtat['raison'] = $e->getMessage();
    }
    if (!$dpeEtat['joint']) error_log('[bail_send dpe] non joint (bail ' . $bailId . ') : ' . $dpeEtat['raison']);

    $refBien = $bail['reference_bien'] ?: ('#'.$bailId);
    $envois = [];
    foreach ($sigs as $s) {
        $url = bsig_build_url((string)$s['token']);
        $email = $s['destinataire_email'] ?: null;
        $nom = $s['nom'] ?? ($s['nom_signataire'] ?? 'Madame, Monsieur');
        $sent = false;
        if (($s['statut'] ?? 'pending') === 'pending' && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Le versement (total + RIB) et l'attestation d'assurance ne concernent QUE le preneur.
            $isPreneur = (($s['role_code'] ?? '') === 'preneur');
            $subject = 'Signature de votre bail commercial — ' . $refBien;
            $mbody =
                "<p>Bonjour " . htmlspecialchars((string)$nom) . ",</p>" .
                "<p>Nous sommes heureux de vous transmettre votre <strong>bail commercial</strong> concernant le local <strong>" . htmlspecialchars($refBien) . "</strong>, prêt à être signé.</p>" .
                "<p>La signature se fait <strong>très simplement depuis votre téléphone</strong> : ouvrez cet email sur votre mobile, cliquez sur le bouton ci-dessous, lisez le bail puis signez <strong>avec votre doigt</strong>.</p>" .
                "<p><a href=\"" . htmlspecialchars($url) . "\" style=\"display:inline-block;padding:12px 22px;background:#84A7AB;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;\">Consulter et signer le bail</a></p>" .
                "<p style=\"font-size:12px;color:#666;\">Ou copiez ce lien : " . htmlspecialchars($url) . "</p>" .
                ($isPreneur ? ($totalHtml . $ribHtml) : '') .
                ($isPreneur ? "<p>Pour pouvoir <strong>prendre possession des lieux</strong>, merci de nous transmettre votre <strong>attestation d'assurance</strong> du local — vous pourrez la joindre directement au moment de la signature.</p>" : '') .
                "<p style=\"font-size:12px;color:#666;\">Le projet de bail est joint à cet email. Lien valable <strong>48 heures</strong> ; votre signature est horodatée et tracée (adresse IP) à des fins de preuve.</p>";
            try {
                $sent = send_mail($email, $subject, $mbody, $pdfAttach, true);
                if ($sent) bsig_mark_sent($pdo, (int)$s['id']);
            } catch (Throwable $e) { error_log('[bail_send mail] '.$e->getMessage()); }
        }
        $envois[] = ['role'=>$s['role_code'] ?? '', 'nom'=>$nom, 'email'=>$email, 'url'=>$url, 'sent'=>$sent, 'statut'=>$s['statut'] ?? 'pending'];
    }

    // Passe le bail en « envoye ».
    if ($bail['statut'] === 'projet') {
        $pdo->prepare("UPDATE bien_baux SET statut='envoye', sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$bailId]);
    }

    /* Le DPE est une pièce que le preneur DOIT recevoir : son absence se dit à
       l'agent au moment de l'envoi, pas trois mois plus tard. */
    echo json_encode([
        'ok'      => true,
        'envois'  => $envois,
        'dpe'     => $dpeEtat,
        'message' => 'Projet de bail envoyé pour signature.'
                   . ($dpeEtat['joint'] ? ' DPE joint.' : ' ⚠️ DPE NON joint : ' . $dpeEtat['raison'] . '.'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[bail_send] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
