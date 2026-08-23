<?php
/**
 * inc/signature_apposition.php — APPOSER les valeurs sur le PDF, aux zones prévues.
 *
 * On reprend le document ORIGINAL page par page (FPDI, via mPDF : setSourceFile /
 * importPage / useTemplate — le même mécanisme éprouvé que `inc/acte_pdf_fusion.php`)
 * et on écrit par-dessus. Le document n'est jamais régénéré : sa mise en page, ses
 * polices et son contenu restent au pixel près ce qu'ils étaient.
 *
 * ⚠️🔥 ON APLATIT, ON NE CRÉE PAS DE CHAMPS DE FORMULAIRE. Une valeur apposée ne doit
 * plus pouvoir être modifiée ni retirée : c'est précisément ce qui distingue un acte
 * d'un brouillon. Un PDF à champs remplissables laisserait le destinataire changer le
 * montant au-dessus de la signature.
 *
 * ── LA CONVERSION, À UN SEUL ENDROIT ───────────────────────────────────────────────
 * Les zones sont stockées en POURCENTAGE de la page. La conversion en millimètres se
 * fait ICI et nulle part ailleurs, à partir de la taille RÉELLE de la page importée —
 * pas d'un A4 supposé. Un scan en Letter, une page paysage ou un plan A3 tombent juste
 * sans un cas particulier : c'est tout l'intérêt d'avoir stocké des pourcentages.
 *
 * ⚠️ mPDF compte en millimètres depuis le coin HAUT-GAUCHE, comme l'éditeur. Aucune
 * inversion d'axe à faire. Si un moteur comptant depuis le bas était utilisé un jour,
 * c'est cette fonction — et elle seule — qu'il faudrait corriger.
 */
declare(strict_types=1);

if (!function_exists('sap_echapper')) {
    function sap_echapper(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('signature_apposer')) {
    /**
     * @param string $pdfSource Chemin du PDF d'origine. L'appelant l'obtient de GedAccess :
     *                          la décision d'accès n'appartient pas à cette fonction.
     * @param array  $zones     Lignes de `signature_zones` (page, x, y, w, h en %, type, id).
     * @param array  $valeurs   [id_zone => ['image' => dataURL|null, 'texte' => string|null]]
     *                          Une zone sans valeur est simplement ignorée — on n'invente
     *                          rien et on ne dessine surtout pas de cadre vide sur l'acte.
     * @return string|null Chemin du PDF produit, ou null (raison journalisée).
     */
    function signature_apposer(string $pdfSource, array $zones, array $valeurs, ?string $titre = null): ?string
    {
        if (!is_file($pdfSource)) { error_log('[signature_apposer] source introuvable : ' . $pdfSource); return null; }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) { error_log('[signature_apposer] vendor absent'); return null; }
        require_once $autoload;

        $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        // Les zones groupées par page : on ne parcourt la liste qu'une fois.
        $parPage = [];
        foreach ($zones as $z) { $parPage[(int)$z['page']][] = $z; }

        try {
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmpDir,
                                    'margin_top' => 0, 'margin_bottom' => 0,
                                    'margin_left' => 0, 'margin_right' => 0]);
            $mpdf->SetTitle($titre ?: 'Document signé');
            $mpdf->SetAuthor('MaBoxImmo');

            $nb = $mpdf->setSourceFile($pdfSource);
            for ($p = 1; $p <= $nb; $p++) {
                $tpl = $mpdf->importPage($p);
                /* La taille RÉELLE de CETTE page — les clés diffèrent selon la version
                   de FPDI ('width'/'w'), d'où le double repli. */
                $dim = $mpdf->getTemplateSize($tpl);
                $pw  = (float)($dim['width']  ?? $dim['w'] ?? 210);
                $ph  = (float)($dim['height'] ?? $dim['h'] ?? 297);
                $mpdf->AddPageByArray(['orientation' => ($pw > $ph) ? 'L' : 'P', 'sheet-size' => [$pw, $ph]]);
                $mpdf->useTemplate($tpl);

                foreach ($parPage[$p] ?? [] as $z) {
                    $v = $valeurs[(int)$z['id']] ?? null;
                    if (!$v) continue;
                    $img = trim((string)($v['image'] ?? ''));
                    $txt = trim((string)($v['texte'] ?? ''));
                    if ($img === '' && $txt === '') continue;   // rien à apposer : on ne dessine rien

                    // % → mm, sur les dimensions de CETTE page.
                    $x = (float)$z['x'] / 100 * $pw;
                    $y = (float)$z['y'] / 100 * $ph;
                    $w = (float)$z['w'] / 100 * $pw;
                    $h = (float)$z['h'] / 100 * $ph;

                    if ($img !== '' && str_starts_with($img, 'data:image')) {
                        /* L'image REMPLIT la zone sans la déborder : `object-fit: contain`
                           n'existe pas en mPDF, on contraint donc la largeur ET la hauteur
                           et on laisse le ratio décider. Une signature étirée se voit
                           immédiatement et décrédibilise l'acte entier. */
                        $html = '<img src="' . sap_echapper($img) . '" style="max-width:' . round($w, 2)
                              . 'mm;max-height:' . round($h, 2) . 'mm;">';
                    } else {
                        /* Le texte est dimensionné d'après la HAUTEUR de la zone : c'est
                           l'agent qui a décidé de la place, pas une taille fixe qui
                           déborderait sur un cadre étroit. */
                        $pt = max(6, min(18, $h * 2.2));
                        $html = '<div style="font-family:sans-serif;font-size:' . round($pt, 1)
                              . 'pt;color:#101418;line-height:1.15;">' . nl2br(sap_echapper($txt)) . '</div>';
                    }
                    /* WriteFixedPosHTML place un bloc à des coordonnées absolues, en mm
                       depuis le coin haut-gauche — exactement le repère de l'éditeur. */
                    $mpdf->WriteFixedPosHTML($html, $x, $y, $w, $h, 'auto');
                }
            }

            $dest = $tmpDir . '/appose_' . bin2hex(random_bytes(6)) . '.pdf';
            $mpdf->Output($dest, \Mpdf\Output\Destination::FILE);
            return is_file($dest) ? $dest : null;
        } catch (Throwable $e) {
            error_log('[signature_apposer] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('signature_empreinte')) {
    /**
     * L'empreinte du fichier PRODUIT. Elle est ce qui permettra, plus tard, de démontrer
     * que le document présenté est bien celui qui a été signé — un octet changé, une
     * empreinte différente. Calculée sur le fichier final, jamais sur la source.
     */
    function signature_empreinte(string $chemin): ?string
    {
        return is_file($chemin) ? hash_file('sha256', $chemin) : null;
    }
}
