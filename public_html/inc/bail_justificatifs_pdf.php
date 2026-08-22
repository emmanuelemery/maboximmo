<?php
declare(strict_types=1);
/**
 * inc/bail_justificatifs_pdf.php — LA PAGE DE JUSTIFICATIFS du bail signé.
 *
 * Le dernier feuillet du dossier : ce qui a été signé, par qui, quand, depuis
 * quelle adresse IP, avec quel code SMS, et sur quelle empreinte. C'est la pièce
 * qu'on produit le jour où quelqu'un conteste — pas le bail lui-même, qui ne dit
 * rien de son propre recueil.
 *
 * ── Ce que cette page affirme, et ce qu'elle n'affirme pas ───────────────────
 * ELLE AFFIRME : un lien nominatif a été adressé à une adresse connue ; un code à
 * usage unique a été envoyé sur le mobile enregistré pour cette personne et
 * ressaisi correctement ; la signature a été recueillie à telle heure depuis
 * telle IP ; le document réuni porte telle empreinte SHA-256.
 *
 * ⚠️ ELLE N'AFFIRME PAS que l'identité a été vérifiée par une pièce d'identité,
 * ni qu'il s'agit d'une double authentification. Le lien et le code voyagent par
 * deux canaux, mais rien ne garantit qu'ils n'aboutissent pas au même appareil.
 * Ce qui est démontré, c'est la DÉTENTION DU TÉLÉPHONE au moment de signer.
 * Écrire davantage exposerait l'agence à voir tout le certificat écarté pour
 * cause d'affirmation excessive — un certificat modeste et vrai vaut infiniment
 * mieux qu'un certificat flatteur et attaquable.
 *
 * ⚠️ Le MODE de signature (tracé au doigt / nom au clavier) est mentionné comme
 * une information, jamais comme une hiérarchie : ni l'un ni l'autre n'est une
 * signature manuscrite au sens du droit (art. 1367 al. 2 C. civ. — ce qui vaut,
 * c'est le procédé fiable d'identification).
 *
 * Ce fichier ne fabrique QUE cette page. La fusion avec le bail et les annexes
 * est l'affaire de `acte_fusionner_pdf()` (inc/acte_pdf_fusion.php).
 */

if (!function_exists('bjus_masque_tel')) {
    /** « +33 6 •• •• •• 16 » — assez pour se reconnaître, pas assez pour être réutilisé. */
    function bjus_masque_tel(?string $numero): string
    {
        $numero = trim((string)$numero);
        if ($numero === '') return '—';
        if (function_exists('otp_masquer_numero')) return otp_masquer_numero($numero);
        $n = preg_replace('/\D/', '', $numero) ?? '';
        return strlen($n) >= 4 ? ('•• •• •• ' . substr($n, -2)) : '••';
    }
}

if (!function_exists('bjus_compter_pages')) {
    /** Nombre de pages d'un PDF, 0 si illisible. FPDI (embarqué dans mPDF) le lit sans re-rendu. */
    function bjus_compter_pages(?string $chemin): int
    {
        if (!$chemin || !is_file($chemin)) return 0;
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) return 0;
        require_once $autoload;
        try {
            $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
            $m = new \Mpdf\Mpdf(['tempDir' => $tmpDir]);
            return (int)$m->setSourceFile($chemin);
        } catch (Throwable $e) { error_log('[bjus_compter_pages] ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('bail_justificatifs_html')) {
    /**
     * Le corps HTML de la page de justificatifs.
     *
     * @param array $ctx  bail (numero, nom, adresse, ref), signataires, annexes, empreinte
     */
    function bail_justificatifs_html(array $ctx): string
    {
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $dt = static function (?string $v): string {
            if (!$v) return '—';
            $t = strtotime((string)$v);
            return $t ? date('d/m/Y \à H\hi\m\i\n s\s', $t) : '—';
        };
        $roleLbl = static function (string $rc): string {
            $base = preg_replace('/_\d+$/', '', $rc) ?? $rc;
            $n    = preg_match('/_(\d+)$/', $rc, $m) ? ' n°' . ((int)$m[1] + 1) : '';
            $lbls = ['preneur' => 'Preneur', 'colocataire' => 'Colocataire', 'caution' => 'Caution',
                     'mandataire' => 'Mandataire (agence)', 'bailleur' => 'Bailleur'];
            return ($lbls[$base] ?? ucfirst($base)) . $n;
        };

        $sigs    = $ctx['signataires'] ?? [];
        $annexes = $ctx['annexes'] ?? [];

        $h = '<style>'
           . 'body{font-family:DejaVuSans,sans-serif;font-size:9.5pt;color:#1e293b;}'
           . '.jt{font-size:16pt;font-weight:bold;color:#243B5C;margin:0 0 2mm;}'
           . '.js{font-size:9pt;color:#64748b;margin:0 0 6mm;}'
           . 'h2{font-size:10.5pt;color:#243B5C;margin:6mm 0 2mm;padding-bottom:1mm;border-bottom:1px solid #cbd5e1;}'
           . 'table{width:100%;border-collapse:collapse;}'
           . 'td,th{border:1px solid #cbd5e1;padding:1.8mm 2.2mm;font-size:8.5pt;vertical-align:top;}'
           . 'th{background:#f1f5f9;text-align:left;font-weight:bold;color:#243B5C;}'
           . '.k{background:#f8fafc;width:38mm;font-weight:bold;}'
           . '.mono{font-family:DejaVuSansMono,monospace;font-size:7.5pt;word-wrap:break-word;}'
           . '.note{background:#f8fafb;border:1px solid #dbe6e6;padding:2.5mm 3mm;font-size:8pt;'
           . 'color:#475569;line-height:1.5;margin-top:3mm;}'
           . '.ok{color:#15803d;font-weight:bold;}.no{color:#b45309;}'
           . '</style>';

        $h .= '<div class="jt">Justificatifs de signature électronique</div>';
        $h .= '<div class="js">' . $e($ctx['nom_bail'] ?? 'Bail')
            . (($ctx['numero'] ?? '') !== '' ? ' n° ' . $e($ctx['numero']) : '')
            . (($ctx['adresse'] ?? '') !== '' ? ' — ' . $e($ctx['adresse']) : '') . '</div>';

        // ── 1. L'acte ────────────────────────────────────────────────────────
        $h .= '<h2>1. L\'acte</h2><table>';
        $h .= '<tr><td class="k">Document</td><td>' . $e($ctx['nom_bail'] ?? 'Bail')
            . (($ctx['numero'] ?? '') !== '' ? ' n° ' . $e($ctx['numero']) : '') . '</td></tr>';
        $h .= '<tr><td class="k">Bien</td><td>' . $e(($ctx['adresse'] ?? '') ?: ($ctx['ref'] ?? '—')) . '</td></tr>';
        $h .= '<tr><td class="k">Dernière signature</td><td>' . $e($dt($ctx['date_fin'] ?? null)) . '</td></tr>';
        /* ⚠️ LE NOMBRE TOTAL DE PAGES EST UNE PROTECTION, pas une décoration :
           sans lui, on peut retirer des feuillets d'un tirage papier sans que rien
           ne le trahisse. Avec, il suffit de compter. */
        if (!empty($ctx['pages_total'])) {
            $h .= '<tr><td class="k">Étendue du document</td><td><b>' . (int)$ctx['pages_total']
                . ' pages</b> <span style="color:#64748b;font-size:7.5pt;">— la présente page comprise. '
                . 'Un exemplaire comportant un autre nombre de pages est incomplet.</span></td></tr>';
        }
        /* L'empreinte porte sur le COMBINÉ (bail + annexes), pas sur le bail seul :
           des annexes qui ne voyageraient qu'en pièces jointes du mail ne seraient
           couvertes par rien, et le locataire pourrait soutenir n'avoir jamais reçu
           le règlement de copropriété. */
        $h .= '<tr><td class="k">Empreinte SHA-256<br><span style="font-weight:normal;font-size:7.5pt;">'
            . 'du document réuni</span></td><td class="mono">' . $e($ctx['hash'] ?? '(calculée à l\'assemblage)') . '</td></tr>';
        $h .= '</table>';

        // ── 2. Les signataires ───────────────────────────────────────────────
        $h .= '<h2>2. Les signataires</h2>';
        $h .= '<table><tr><th style="width:32mm;">Qualité</th><th>Signataire</th>'
            . '<th style="width:34mm;">Signature</th><th style="width:38mm;">Code SMS</th></tr>';
        foreach ($sigs as $s) {
            $signe = (($s['statut'] ?? '') === 'signe');
            $mode  = (string)($s['signature_mode'] ?? '');
            $modeLbl = $mode === 'clavier' ? 'nom saisi au clavier'
                     : ($mode === 'trace' ? 'signature tracée' : '');
            $h .= '<tr>';
            $h .= '<td>' . $e($roleLbl((string)($s['role_code'] ?? ''))) . '</td>';
            /* ⚠️ NI EMAIL NI TÉLÉPHONE ICI. Ce document est adressé à TOUTES les
               parties : y imprimer les coordonnées de chacune revient à diffuser
               le numéro du locataire au bailleur, et l'inverse, sans que personne
               ne l'ait voulu ni consenti. Le nom suffit à désigner le signataire ;
               les coordonnées restent en base, où elles servent. Seuls les deux
               derniers chiffres du mobile réapparaissent en colonne « Code SMS »,
               parce que là ils PROUVENT quelque chose : sur quel appareil le code
               a été reçu. */
            $h .= '<td>' . $e($s['nom_signataire'] ?? '—') . '</td>';
            $h .= '<td>' . ($signe
                    ? '<span class="ok">Signé</span><br><span style="font-size:7.5pt;">' . $e($dt($s['signed_at'] ?? null))
                      . ($modeLbl !== '' ? '<br>' . $e($modeLbl) : '')
                      . '<br>IP ' . $e($s['ip'] ?: '—') . '</span>'
                    : '<span class="no">Non signé</span>') . '</td>';
            /* Le code SMS n'est PAS reproduit — il est haché en base, et le
               reproduire permettrait de signer à la place du client. On atteste
               qu'il a été validé, et quand. */
            $telFin = preg_replace('/\D/', '', (string)($s['destinataire_tel'] ?? '')) ?? '';
            $telFin = strlen($telFin) >= 2 ? substr($telFin, -2) : '';
            $h .= '<td style="font-size:7.5pt;">' . (!empty($s['otp_valide_at'])
                    ? '<span class="ok">Code validé</span><br>' . $e($dt($s['otp_valide_at']))
                      . ($telFin !== '' ? '<br>mobile se terminant par ' . $e($telFin) : '')
                    : ((($s['destinataire_tel'] ?? '') === '')
                        ? '<span class="no">Sans code</span><br>lien nominatif seul'
                        : '<span class="no">Code non validé</span>')) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</table>';

        // ── 3. Les pièces réunies ────────────────────────────────────────────
        $h .= '<h2>3. Les pièces réunies dans ce document</h2><table>';
        $h .= '<tr><td class="k">1</td><td>' . $e($ctx['nom_bail'] ?? 'Bail')
            . ' <span style="color:#64748b;font-size:7.5pt;">(corps de l\'acte, signatures incluses)</span></td></tr>';
        $n = 1;
        foreach ($annexes as $a) {
            $h .= '<tr><td class="k">' . (++$n) . '</td><td>' . $e($a['nom'] ?? 'Pièce annexée') . '</td></tr>';
        }
        $h .= '<tr><td class="k">' . (++$n) . '</td><td>La présente page de justificatifs</td></tr>';
        $h .= '</table>';
        if (!$annexes) {
            /* Une annexe absente est un cas NORMAL (un DPE qu'on n'a pas) et ne
               bloque rien. Le dire ici évite qu'on lise plus tard le silence de
               cette page comme un oubli. */
            $h .= '<div class="note">Aucune pièce n\'était annexée à la demande de signature.</div>';
        }

        // ── 4. Le procédé ────────────────────────────────────────────────────
        /* ── 4. LA CONSULTATION DES PIÈCES ────────────────────────────────────
           La case « j'ai pris connaissance des pièces » est une DÉCLARATION du
           signataire ; elle ne prouve rien par elle-même. Ce tableau porte autre
           chose : le constat, par le serveur, qu'une pièce lui a effectivement été
           RENDUE, à la seconde près.

           ⚠️ On n'écrit jamais « lu le … ». Aucun système ne peut prouver qu'un
           document a été lu, et l'affirmer serait une prétention indéfendable le
           jour où elle compte. « Ouverte le … » est vrai, vérifiable, et c'est ce
           que la mise à disposition exige.

           ⚠️ Les pièces NON ouvertes figurent aussi : un tableau qui ne montrerait
           que les consultations laisserait croire, par son silence, que tout a été
           vu. */
        $aDesOuvertures = false;
        foreach ($sigs as $s0) {
            if (json_decode((string)($s0['annexes_lues_json'] ?? '[]'), true)) { $aDesOuvertures = true; break; }
        }
        if ($annexes) {
            $h .= '<h2>4. Consultation des pièces annexées</h2>';
            $h .= '<table><tr><th style="width:38mm;">Signataire</th><th>Pièce</th>'
                . '<th style="width:34mm;">Ouverte le</th></tr>';
            foreach ($sigs as $s0) {
                $journal = json_decode((string)($s0['annexes_lues_json'] ?? '[]'), true) ?: [];
                $premiere = true;
                foreach ($annexes as $ax) {
                    $uid = (string)($ax['uid'] ?? '');
                    $vue = null;
                    foreach ($journal as $lg) { if ((string)($lg['uid'] ?? '') === $uid) { $vue = $lg; break; } }
                    $h .= '<tr><td>' . ($premiere
                            ? $e($s0['nom_signataire'] ?? '—') . '<br><span style="font-size:7.5pt;color:#64748b;">'
                              . $e($roleLbl((string)($s0['role_code'] ?? ''))) . '</span>'
                            : '') . '</td>';
                    $h .= '<td>' . $e((string)($ax['nom'] ?? 'Pièce')) . '</td>';
                    $h .= '<td style="font-size:7.5pt;">' . ($vue
                        ? '<span class="ok">' . $e($dt((string)($vue['ouvert_at'] ?? ''))) . '</span>'
                          . ((int)($vue['nb'] ?? 1) > 1 ? '<br>' . (int)$vue['nb'] . ' ouvertures' : '')
                        : '<span class="no">non ouverte ici</span>') . '</td></tr>';
                    $premiere = false;
                }
            }
            $h .= '</table>';
            if (!$aDesOuvertures) {
                $h .= '<div class="note">Aucune pièce n&rsquo;a été ouverte depuis la page de signature. '
                    . 'Les annexes ont été transmises par courrier électronique avec la demande de signature, '
                    . 'et sont réunies dans le présent document.</div>';
            }
        }

        // ── 5. Le procédé ────────────────────────────────────────────────────
        $h .= '<h2>' . ($annexes ? '5' : '4') . '. Le procédé d\'identification</h2>';
        $h .= '<div class="note">'
            . 'Signature électronique <b>simple</b> au sens des articles 1366 et 1367 du Code civil. '
            . 'L\'identification repose sur un faisceau d\'éléments concordants : un lien nominatif adressé '
            . 'à une adresse de messagerie connue du dossier ; un <b>code à usage unique de six chiffres</b> '
            . 'transmis par SMS au numéro de téléphone mobile enregistré pour le signataire, puis ressaisi '
            . 'par lui sur la page de signature ; l\'horodatage et l\'adresse IP de la signature ; '
            . 'l\'empreinte cryptographique du document réuni.'
            . '<br><br>'
            . '<b>Portée exacte du code SMS.</b> Le code démontre que le signataire <b>détenait le téléphone '
            . 'enregistré pour lui</b> au moment de signer. Il ne constitue pas une vérification d\'identité '
            . 'par pièce officielle, ni une authentification à deux facteurs au sens technique : le lien et le '
            . 'code peuvent aboutir sur un même appareil. Le code est conservé sous forme <b>hachée</b>, '
            . 'valable dix minutes, limité à cinq tentatives et utilisable une seule fois.'
            . '<br><br>'
            . '<b>Mode de signature.</b> Le tracé au doigt et la saisie du nom au clavier sont deux '
            . '<i>représentations</i> de la signature ; aucune n\'est une signature manuscrite au sens du droit '
            . 'et aucune ne prévaut sur l\'autre. Ce qui vaut signature électronique est le procédé fiable '
            . 'd\'identification décrit ci-dessus. Le mode retenu par chaque signataire est indiqué à titre '
            . 'd\'information au tableau 2.'
            . '</div>';

        /* ⚠️ Une IP commune à plusieurs signataires n'est PAS une anomalie, et le
           certificat doit le dire lui-même : en signature présentielle, les
           parties signent l'une après l'autre sur le même appareil, donc depuis
           la même adresse. Sans cette phrase, un lecteur pressé — un avocat
           adverse, un juge — pourrait y lire l'indice que quelqu'un a signé à la
           place d'un autre, alors que c'est le déroulement le plus banal qui soit.
           Ce qui distingue les signataires n'est pas l'adresse IP : c'est le code
           reçu sur le téléphone de chacun. */
        $ips = array_filter(array_map(static fn($x) => trim((string)($x['ip'] ?? '')), $sigs));
        if (count($ips) > 1 && count(array_unique($ips)) < count($ips)) {
            $h .= '<div class="note">'
                . '<b>Adresse IP commune à plusieurs signataires.</b> Ce n’est pas une anomalie : '
                . 'en signature présentielle, les parties signent successivement depuis le même '
                . 'appareil ou le même réseau, et présentent donc la même adresse. Ce qui distingue '
                . 'les signataires est le <b>code à usage unique reçu sur le téléphone de chacun</b>, '
                . 'consigné au tableau 2 — pas l’adresse de connexion.'
                . '</div>';
        }

        $h .= '<div class="note" style="margin-top:2mm;">'
            . 'Document établi automatiquement par MaBoxImmo à la dernière signature. '
            . 'Toute modification ultérieure du bail ou de ses annexes changerait l\'empreinte indiquée '
            . 'au tableau 1 et serait donc immédiatement décelable.'
            . '</div>';

        return $h;
    }
}

if (!function_exists('bail_justificatifs_pdf')) {
    /**
     * Construit la page de justificatifs et renvoie le chemin du PDF temporaire.
     *
     * @return string|null null si mPDF est indisponible — l'appelant produit alors
     *                     le bail sans sa page de preuve plutôt que rien du tout.
     */
    function bail_justificatifs_pdf(PDO $pdo, int $bailId, ?string $hash = null, ?int $pagesTotal = null): ?string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) { error_log('[bail_justificatifs_pdf] vendor absent'); return null; }
        require_once $autoload;
        require_once __DIR__ . '/bail_ceremonie.php';
        require_once __DIR__ . '/sms_otp.php';

        $ctx = bcer_contexte($pdo, $bailId);

        try {
            $st = $pdo->prepare("SELECT role_code, nom_signataire, destinataire_email, destinataire_tel,
                                        statut, ip, signed_at, signature_mode, otp_valide_at, vague,
                                        annexes_lues_json
                                   FROM bail_signatures
                                  WHERE id_bail = ? AND statut <> 'refuse'
                               ORDER BY vague ASC, id ASC");
            $st->execute([$bailId]);
            $ctx['signataires'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[bail_justificatifs_pdf sigs] ' . $e->getMessage()); $ctx['signataires'] = []; }

        try {
            $st = $pdo->prepare("SELECT annexes_signature_json FROM bien_baux WHERE id = ? LIMIT 1");
            $st->execute([$bailId]);
            $ctx['annexes'] = json_decode((string)($st->fetchColumn() ?: '[]'), true) ?: [];
        } catch (Throwable $e) { $ctx['annexes'] = []; }

        // La date qui fait foi est celle de la DERNIÈRE signature, pas celle du jour.
        $fin = null;
        foreach ($ctx['signataires'] as $s) {
            if (!empty($s['signed_at']) && (!$fin || strtotime((string)$s['signed_at']) > strtotime($fin))) {
                $fin = (string)$s['signed_at'];
            }
        }
        $ctx['date_fin']    = $fin;
        $ctx['hash']        = $hash;
        $ctx['pages_total'] = $pagesTotal;

        $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        try {
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmpDir,
                                    'margin_top' => 14, 'margin_bottom' => 14,
                                    'margin_left' => 14, 'margin_right' => 14]);
            $mpdf->SetTitle('Justificatifs de signature — ' . (string)($ctx['nom_bail'] ?? 'Bail'));
            $mpdf->SetAuthor('MaBoxImmo');
            $mpdf->SetHTMLFooter('<div style="font-size:7pt;color:#94a3b8;text-align:center;">'
                . 'Justificatifs de signature électronique · page {PAGENO}/{nbpg}</div>');
            $mpdf->WriteHTML(bail_justificatifs_html($ctx));
            $dest = $tmpDir . '/justif_' . $bailId . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $mpdf->Output($dest, \Mpdf\Output\Destination::FILE);
            return is_file($dest) ? $dest : null;
        } catch (Throwable $e) {
            error_log('[bail_justificatifs_pdf] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('bail_annexes_chemins')) {
    /**
     * Les chemins disque des annexes FIGÉES à l'envoi, dans leur ordre.
     *
     * ⚠️ On relit l'instantané `annexes_signature_json`, JAMAIS les documents
     * actuellement rattachés au bien : détacher une pièce six mois plus tard ne
     * doit pas changer l'acte signé. C'est tout l'intérêt d'avoir figé la liste.
     *
     * ⚠️ Seuls les PDF sont fusionnables. Une annexe en JPEG ou en DOCX reste
     * mentionnée au tableau des justificatifs mais n'entre pas dans le combiné —
     * la taire donnerait à croire qu'elle y est.
     *
     * @return array{chemins:string[], jointes:array, ecartees:array}
     */
    function bail_annexes_chemins(PDO $pdo, int $bailId): array
    {
        $out = ['chemins' => [], 'jointes' => [], 'ecartees' => []];
        require_once __DIR__ . '/ged_access.php';
        try {
            $st = $pdo->prepare("SELECT annexes_signature_json FROM bien_baux WHERE id = ? LIMIT 1");
            $st->execute([$bailId]);
            $liste = json_decode((string)($st->fetchColumn() ?: '[]'), true) ?: [];
        } catch (Throwable $e) { error_log('[bail_annexes_chemins] ' . $e->getMessage()); return $out; }

        usort($liste, static fn($a, $b) => (int)($a['ordre'] ?? 0) <=> (int)($b['ordre'] ?? 0));

        /* Le périmètre du jeton : exactement les pièces gelées sur CET acte. */
        $autorises = [];
        foreach ($liste as $axP) {
            if (preg_match('/^(?:ged|mbo):(\d+)$/', (string)($axP['uid'] ?? ''), $mA)) $autorises[] = (int)$mA[1];
        }

        foreach ($liste as $ax) {
            $uid = (string)($ax['uid'] ?? '');
            $nom = (string)($ax['nom'] ?? 'Pièce annexée');
            // uid = « ged:123 » / « mbo:123 » — les deux pointent ged_documents.
            if (!preg_match('/^(ged|mbo):(\d+)$/', $uid, $m)) { $out['ecartees'][] = $nom; continue; }
            try {
                /* ⚠️🔥 `ged_documents` N'A PAS de colonne de chemin — le fichier se
                   demande au POINT DE PASSAGE CENTRAL, qui applique le niveau de
                   sécurité avant de rendre un chemin.

                   ⚠️🔥 ET SURTOUT : PAS `ged_internal_path()` ICI — elle lit
                   l'identité de SESSION. Or l'assemblage du combiné est déclenché
                   par `bsig_sign()` depuis la page PUBLIQUE de signature : aucune
                   session, donc aucune identité, donc la cage refusait TOUT.
                   Mesuré sur un cas réel le 18/08 : le bail signé faisait 16 pages
                   au lieu de 32 — les trois annexes avaient disparu du document,
                   EN SILENCE, alors que le signataire venait de cocher qu'il les
                   avait lues et que la page de justificatifs les listait comme
                   « réunies dans ce document ». Le certificat affirmait donc une
                   composition fausse, et rien ne l'aurait révélé sans compter les
                   pages.

                   On passe l'identité de JETON, dont le périmètre est la liste
                   figée elle-même. Ce n'est pas un contournement : l'autorisation
                   de fond a été prise À L'ENVOI, quand l'agent a joint la pièce en
                   passant par la cage ; le gel dans `annexes_signature_json` en est
                   la trace. On rouvre ce qui a déjà été autorisé, pour l'acte
                   auquel cela appartient. */
                $g = GedAccess::grant((int)$m[2], 'mail', [
                    'user_id'         => 0,
                    'societe'         => 0,
                    'admin_sup'       => false,
                    'ged_token_scope' => ['bail_annexes' => $autorises],
                ]);
                $chemin = $g['path'] ?? null;
                if ($chemin === null || !is_file($chemin)) { $out['ecartees'][] = $nom; continue; }
                // Un fichier dont l'extension ment reste écarté : mPDF refuserait la source.
                if (strtolower((string)pathinfo($chemin, PATHINFO_EXTENSION)) !== 'pdf') { $out['ecartees'][] = $nom; continue; }
                $out['chemins'][] = $chemin;
                $out['jointes'][] = $nom;
            } catch (Throwable $e) { error_log('[bail_annexes_chemins] ' . $e->getMessage()); $out['ecartees'][] = $nom; }
        }
        return $out;
    }
}

if (!function_exists('bail_combine_construire')) {
    /**
     * LE DOCUMENT FINAL : bail signé + annexes + page de justificatifs, en un PDF.
     *
     * ── L'ordre des opérations n'est pas négociable ──────────────────────────
     *   1. le bail définitif (sans filigrane, signatures incrustées) ;
     *   2. fusion avec les annexes figées → c'est L'ACTE RÉUNI ;
     *   3. empreinte SHA-256 de cet acte réuni — c'est ELLE que le certificat atteste ;
     *   4. page de justificatifs, qui PORTE cette empreinte ;
     *   5. fusion finale acte + justificatifs.
     *
     * L'empreinte ne peut pas porter sur le document final : la page de
     * justificatifs ne peut pas contenir sa propre empreinte. Elle porte donc sur
     * ce qui a été signé — bail et annexes — ce qui est exactement ce qu'on veut
     * pouvoir prouver.
     *
     * ⚠️ RIEN NE BLOQUE. Sans annexe, l'acte réuni est le bail seul. Sans mPDF,
     * on renvoie le bail tel quel plutôt que rien : un dossier sans sa page de
     * preuve reste infiniment préférable à un bail signé qu'on ne classe pas.
     *
     * @return array{path:string, hash:string, annexes:array, ecartees:array, justif:bool}
     */
    function bail_combine_construire(PDO $pdo, int $bailId, string $bailPdf): array
    {
        require_once __DIR__ . '/acte_pdf_fusion.php';

        $ax = bail_annexes_chemins($pdo, $bailId);

        // 2. L'acte réuni : le bail et ses annexes ne font plus qu'une pièce.
        $acte = $bailPdf;
        if ($ax['chemins']) {
            $fusion = acte_fusionner_pdf(array_merge([$bailPdf], $ax['chemins']), 'Bail et annexes');
            if ($fusion) { $acte = $fusion; }
            else {
                /* La fusion a échoué : on garde le bail seul plutôt que de perdre
                   l'acte, mais on le DIT — sans cette trace, un combiné amputé
                   passerait pour complet. */
                error_log('[bail_combine_construire] fusion annexes impossible (bail #' . $bailId . ')');
                $ax['ecartees'] = array_merge($ax['ecartees'], $ax['jointes']);
                $ax['jointes'] = [];
            }
        }

        // 3. L'empreinte de CE QUI A ÉTÉ SIGNÉ.
        $hash = is_file($acte) ? (hash_file('sha256', $acte) ?: '') : '';

        /* 4-5. Les justificatifs portent l'empreinte ET l'étendue du document.
           ⚠️ DEUX PASSES, et c'est inévitable : la page de justificatifs doit
           annoncer le nombre total de pages du document dont elle fait partie —
           elle ne peut donc pas le connaître avant d'exister. On la fabrique une
           première fois pour la mesurer, puis on la refait en y inscrivant le
           total. Le même raisonnement que pour l'empreinte, qui ne peut pas porter
           sur un fichier qui la contient. */
        $final = $acte; $justifOk = false;
        $nActe = bjus_compter_pages($acte);

        $essai = bail_justificatifs_pdf($pdo, $bailId, $hash);
        $nJustif = bjus_compter_pages($essai);
        if ($essai) @unlink($essai);

        $total = ($nActe > 0 && $nJustif > 0) ? ($nActe + $nJustif) : null;
        $justif = bail_justificatifs_pdf($pdo, $bailId, $hash, $total);
        /* Si la mention du total a fait déborder d'une page, le compte annoncé
           serait faux : on refait une passe avec le nouveau total plutôt que
           d'imprimer un chiffre qui ne correspond pas au document. */
        if ($justif && $total !== null) {
            $nReel = bjus_compter_pages($justif);
            if ($nReel > 0 && $nReel !== $nJustif) {
                @unlink($justif);
                $justif = bail_justificatifs_pdf($pdo, $bailId, $hash, $nActe + $nReel);
            }
        }
        if ($justif) {
            $tout = acte_fusionner_pdf([$acte, $justif], 'Bail signé, annexes et justificatifs');
            if ($tout) { $final = $tout; $justifOk = true; }
            else { error_log('[bail_combine_construire] fusion justificatifs impossible (bail #' . $bailId . ')'); }
            if ($justif !== $final) @unlink($justif);
        }

        return ['path' => $final, 'hash' => $hash, 'annexes' => $ax['jointes'],
                'ecartees' => $ax['ecartees'], 'justif' => $justifOk];
    }
}
