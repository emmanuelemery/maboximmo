<?php
/**
 * inc/signature_finaliser.php — ÉTABLIR LE DOCUMENT DÉFINITIF, une fois tous signés.
 *
 * Lit les valeurs recueillies (`signature_zone_valeurs`), les appose sur le PDF d'origine,
 * ajoute la page de justificatifs listant TOUS les signataires, aplatit, calcule
 * l'empreinte et classe la pièce en GED.
 *
 * ── POURQUOI CETTE FONCTION EXISTE PLUTÔT QU'UN COPIER-COLLER ──────────────────────
 * Deux chemins mènent ici : « je signe seul depuis mon écran » et « le dernier signataire
 * vient de valider ». S'ils dupliquaient la production de l'acte, ils finiraient par ne
 * plus produire le même document — et c'est le genre d'écart qu'on ne découvre qu'au
 * contentieux. Un seul endroit fabrique l'acte définitif.
 *
 * ⚠️ IDEMPOTENTE : si l'acte a déjà été établi, on le renvoie sans le refaire. Un double
 * clic, un rechargement ou deux signataires qui valident à la même seconde ne doivent pas
 * créer deux actes concurrents portant deux empreintes différentes.
 */
declare(strict_types=1);

require_once __DIR__ . '/signature_apposition.php';
require_once __DIR__ . '/signature_etat.php';
require_once __DIR__ . '/acte_pdf_fusion.php';
require_once __DIR__ . '/ged_document_links.php';
require_once __DIR__ . '/ged_access.php';

if (!function_exists('signature_finaliser')) {
    /**
     * @return array{ok:bool, doc_id?:int, hash?:string, deja?:bool, error?:string}
     */
    function signature_finaliser(PDO $pdo, int $docId, ?int $userId = null, ?array $identite = null): array
    {
        if ($docId <= 0) return ['ok'=>false, 'error'=>'document inconnu'];

        /* Déjà établi ? On renvoie l'existant. Voir l'en-tête : deux validations
           simultanées ne doivent pas produire deux actes. */
        $etat = sig_etat_lire($pdo, $docId);
        if (!empty($etat['doc_signe'])) {
            return ['ok'=>true, 'deja'=>true, 'doc_id'=>(int)$etat['doc_signe'], 'hash'=>(string)($etat['hash'] ?? '')];
        }

        /* ⚠️🔥 LE PIÈGE DE LA FINALISATION SANS SESSION. Cette fonction est appelée depuis
           la PAGE PUBLIQUE de signature — aucune session, donc `GedAccess::grant()` sans
           identité refuse le document et l'acte ne se produit pas. C'est exactement ce qui
           était arrivé au combiné du bail le 18/08 : les annexes disparaissaient en
           silence et l'acte sortait à 16 pages au lieu de 52. Ici c'était moins discret —
           l'acte n'était pas produit du tout, « document source inaccessible » au journal —
           mais la cause est la même.
           On passe donc l'identité de l'AGENT QUI A ENVOYÉ le document en signature : c'est
           l'agence qui produit l'acte, pas le signataire externe. Ce n'est pas un
           contournement de la cage — c'est lui dire au nom de qui elle travaille. */
        if ($identite === null) {
            try {
                $q = $pdo->prepare("SELECT id_user_created, id_societe FROM bail_signatures
                                     WHERE objet_type='ged' AND objet_id = ? AND id_user_created IS NOT NULL
                                     ORDER BY id ASC LIMIT 1");
                $q->execute([$docId]);
                if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                    $identite = ['user_id' => (int)$r['id_user_created'],
                                 'societe' => (int)($r['id_societe'] ?? 0), 'admin_sup' => false];
                }
            } catch (Throwable $e) { /* pas d'identité trouvée : on tentera la session */ }
        }

        try {
            $g = GedAccess::grant($docId, 'preview', $identite);
            if (empty($g['path']) || !is_file($g['path'])) return ['ok'=>false, 'error'=>'document source inaccessible'];
        } catch (Throwable $e) { return ['ok'=>false, 'error'=>'document source inaccessible']; }
        $src = (string)$g['path'];

        $st = $pdo->prepare("SELECT name_display, societe_id, agence_id FROM ged_documents WHERE id = ? LIMIT 1");
        $st->execute([$docId]);
        $d = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $nomSrc = (string)($d['name_display'] ?? ('Document #' . $docId));

        $st = $pdo->prepare("SELECT id, page, x, y, w, h, type FROM signature_zones
                              WHERE ged_document_id = ? ORDER BY page, ordre, id");
        $st->execute([$docId]);
        $zones = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$zones) return ['ok'=>false, 'error'=>'aucune zone sur ce document'];

        /* Les valeurs telles qu'elles ont été recueillies — jamais reconstruites.
           C'est la MÊME source que celle relue par la page du signataire : l'écran et
           l'acte ne peuvent donc pas montrer deux choses différentes. */
        $st = $pdo->prepare("SELECT v.id_zone, v.valeur_texte, v.valeur_image
                               FROM signature_zone_valeurs v
                               JOIN signature_zones z ON z.id = v.id_zone
                              WHERE z.ged_document_id = ?");
        $st->execute([$docId]);
        $valeurs = [];
        foreach ($st as $r) {
            $valeurs[(int)$r['id_zone']] = ['image' => (string)($r['valeur_image'] ?? ''),
                                            'texte' => (string)($r['valeur_texte'] ?? '')];
        }
        if (!$valeurs) return ['ok'=>false, 'error'=>'aucune valeur recueillie'];

        $signe = signature_apposer($src, $zones, $valeurs, 'Signé — ' . $nomSrc);
        if (!$signe) return ['ok'=>false, 'error'=>'apposition impossible'];

        /* ── Les justificatifs : TOUS les signataires, chacun avec sa trace ────────── */
        $st = $pdo->prepare("SELECT role_code, nom_signataire, destinataire_email, destinataire_tel,
                                    signed_at, ip, otp_valide_at
                               FROM bail_signatures
                              WHERE objet_type='ged' AND objet_id = ? ORDER BY id");
        $st->execute([$docId]);
        $sigs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $hashSrc = hash_file('sha256', $src) ?: '';
        $justif = null;
        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
            $mp = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','tempDir'=>$tmpDir,
                                  'margin_top'=>18,'margin_left'=>18,'margin_right'=>18,'margin_bottom'=>16]);
            $mp->SetTitle('Justificatifs de signature');
            $lignes = '';
            foreach ($sigs as $s) {
                $lignes .= '<tr>'
                  . '<td style="border:0.5pt solid #cddada;padding:5px 7px;"><b>' . $e($s['nom_signataire'] ?: '—') . '</b>'
                  . '<br><span style="font-size:8pt;color:#666;">' . $e($s['role_code']) . '</span></td>'
                  . '<td style="border:0.5pt solid #cddada;padding:5px 7px;font-size:8.5pt;">'
                  . $e($s['destinataire_email'] ?: $s['destinataire_tel'] ?: '—') . '</td>'
                  . '<td style="border:0.5pt solid #cddada;padding:5px 7px;font-size:8.5pt;">'
                  . ($s['signed_at'] ? $e(date('d/m/Y \à H\hi\ms', strtotime((string)$s['signed_at']))) : '—') . '</td>'
                  . '<td style="border:0.5pt solid #cddada;padding:5px 7px;font-size:8.5pt;">' . $e($s['ip'] ?: '—')
                  . (!empty($s['otp_valide_at']) ? '<br><span style="color:#166534;">code SMS validé</span>' : '') . '</td>'
                  . '</tr>';
            }
            $html = '<div style="font-family:sans-serif;color:#1c2226;font-size:10.5pt;line-height:1.55;">'
              . '<h1 style="font-size:15pt;color:#243B5C;margin:0 0 2px;">Justificatifs de signature électronique</h1>'
              . '<p style="font-size:8.5pt;color:#666;margin:0 0 12px;">Pièce annexée à l\'acte signé — elle en est la preuve.</p>'
              . '<p><b>Document :</b> ' . $e($nomSrc) . '<br>'
              . '<span style="font-size:8.5pt;color:#555;">Empreinte de l\'original : <span style="font-family:monospace;">' . $e($hashSrc) . '</span></span></p>'
              . '<table style="width:100%;border-collapse:collapse;font-size:9.5pt;">'
              . '<tr style="background:#eef2f6;"><th style="border:0.5pt solid #b9cccc;padding:5px 7px;text-align:left;">Signataire</th>'
              . '<th style="border:0.5pt solid #b9cccc;padding:5px 7px;text-align:left;">Adressé à</th>'
              . '<th style="border:0.5pt solid #b9cccc;padding:5px 7px;text-align:left;">Signé le</th>'
              . '<th style="border:0.5pt solid #b9cccc;padding:5px 7px;text-align:left;">Depuis</th></tr>'
              . $lignes . '</table>'
              . '<h2 style="font-size:11pt;color:#243B5C;margin:16px 0 4px;">Nature du procédé</h2>'
              . '<p style="text-align:justify;">Chaque signataire a reçu un <b>lien nominatif</b> à l\'adresse ou au '
              . 'numéro indiqué ci-dessus, a consulté l\'intégralité du document, puis a apposé sa signature. Le tracé '
              . 'reproduit permet de le reconnaître mais ne constitue pas à lui seul une preuve : ce qui identifie '
              . 'chaque signataire, c\'est l\'ensemble consigné dans ce tableau — lien nominatif, horodatage, adresse IP '
              . 'et, le cas échéant, validation d\'un code reçu par SMS.</p>'
              . '<p style="text-align:justify;">Ce procédé relève de la signature électronique <b>simple</b> au sens de '
              . 'l\'article 1367 alinéa 2 du Code civil. Il ne s\'agit ni d\'une signature avancée ni d\'une signature '
              . 'qualifiée au sens du règlement eIDAS, et le présent document ne prétend pas le contraire.</p>'
              . '<p style="font-size:8.5pt;color:#666;margin-top:12px;">Toute modification ultérieure du document signé '
              . 'en changerait l\'empreinte, calculée sur le fichier final et conservée avec lui.</p></div>';
            $mp->WriteHTML($html);
            $justif = $tmpDir . '/justif_' . bin2hex(random_bytes(5)) . '.pdf';
            $mp->Output($justif, \Mpdf\Output\Destination::FILE);
        } catch (Throwable $ex) {
            error_log('[signature_finaliser justificatifs] ' . $ex->getMessage());
            $justif = null;   // non bloquant : l'acte signé vaut mieux que rien
        }

        $final = $justif ? (acte_fusionner_pdf([$signe, $justif], 'Signé — ' . $nomSrc) ?: $signe) : $signe;
        $hash  = signature_empreinte($final) ?: '';
        $nom   = 'Signé — ' . $nomSrc;
        if (!preg_match('/\.pdf$/i', $nom)) $nom .= '.pdf';

        try {
            $res = gus_commit_document($pdo, [
                'path_on_disk'  => $final,
                'name_original' => $nom,
                'hash_sha256'   => $hash,
                'mime_type'     => 'application/pdf',
                'size_bytes'    => filesize($final) ?: 0,
            ], [
                'tenant_id'      => (int)($d['societe_id'] ?? 0) ?: null,
                'societe_id'     => (int)($d['societe_id'] ?? 0) ?: null,
                'agence_id'      => (int)($d['agence_id'] ?? 0) ?: null,
                'document_type'  => 'ACTE_SIGNE',
                'source_module'  => '01_JURIDIQUE',
                'security_level' => 'interne',
                'created_by'     => $userId ?: null,
            ], []);
            if (empty($res['ok'])) return ['ok'=>false, 'error'=>implode(' / ', $res['errors'] ?? ['commit GED'])];
            $newId = (int)$res['doc_id'];
        } catch (Throwable $ex) {
            error_log('[signature_finaliser ged] ' . $ex->getMessage());
            return ['ok'=>false, 'error'=>$ex->getMessage()];
        }

        try { $pdo->prepare("UPDATE ged_documents SET parent_document_id = ? WHERE id = ?")->execute([$docId, $newId]); }
        catch (Throwable $ex) {}

        // Les deux pièces se pointent : le vierge dit ce qui a été proposé, le signé ce qui a été accepté.
        sig_etat_marquer($pdo, $newId, ['etat'=>'signe', 'source'=>$docId, 'hash'=>$hash, 'signe_le'=>date('Y-m-d H:i:s')]);
        sig_etat_marquer($pdo, $docId, ['etat'=>'signe', 'doc_signe'=>$newId, 'hash'=>$hash, 'signe_le'=>date('Y-m-d H:i:s')]);

        return ['ok'=>true, 'doc_id'=>$newId, 'hash'=>$hash, 'deja'=>false];
    }
}
