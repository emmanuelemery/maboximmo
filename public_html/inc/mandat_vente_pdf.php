<?php
declare(strict_types=1);
/**
 * inc/mandat_vente_pdf.php — Génère le PDF du mandat de vente avec mPDF (rendu fidèle
 * au CSS de transaction_mandat_preview.php en mode render=body). Filigrane PROJET natif.
 * Réutilisé par api/transaction_mandat_pdf.php (aperçu/GED) et l'envoi mail.
 *
 * @return string chemin du fichier PDF temporaire généré.
 */
if (!function_exists('mandat_vente_build_pdf')) {
    function mandat_vente_build_pdf(int $idDossier, string $modele, string $netV, bool $withProjet, string $extraHtml = ''): string
    {
        // L'include de la preview tourne dans CE scope : exposer $pdo (global).
        global $pdo;
        if (!isset($pdo) && isset($GLOBALS['pdo'])) $pdo = $GLOBALS['pdo'];

        // 1. HTML "corps seul" du mandat (réutilise la preview en mode render=body)
        $bk = $_GET;
        $_GET = ['id_dossier' => (string)$idDossier, 'render' => 'body', 'modele' => $modele];
        if ($netV !== '')  $_GET['net_vendeur'] = $netV;
        if ($withProjet)   $_GET['projet'] = '1';
        ob_start();
        include __DIR__ . '/../transaction_mandat_preview.php';
        $html = ob_get_clean();
        $_GET = $bk;
        if ($extraHtml !== '') $html .= '<pagebreak />' . $extraHtml;

        // 2. mPDF (rendu CSS fidèle)
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Autoload Composer introuvable (mPDF non installé)');
        require_once $autoload;
        if (!class_exists('\\Mpdf\\Mpdf')) throw new RuntimeException('mPDF introuvable');

        $tmpDir = __DIR__ . '/../uploads/_mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode'           => 'utf-8',
            'format'         => 'A4',
            'margin_top'     => 14,
            'margin_bottom'  => 14,
            'margin_left'    => 14,
            'margin_right'   => 14,
            'tempDir'        => $tmpDir,
            'default_font'   => 'dejavusans',
        ]);
        $mpdf->SetTitle('Mandat de vente');
        $mpdf->SetAuthor('Régie EMERY');

        // Filigrane PROJET (uniquement si demandé) — natif mPDF
        if ($withProjet) {
            $mpdf->SetWatermarkText('PROJET');
            $mpdf->showWatermarkText = true;
            $mpdf->watermarkTextAlpha = 0.08;
            $mpdf->watermark_font = 'DejaVuSans';
        }

        $mpdf->WriteHTML($html);

        $tmp = sys_get_temp_dir() . '/mandat_' . $idDossier . '_' . ($withProjet ? 'projet' : 'def') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $mpdf->Output($tmp, \Mpdf\Output\Destination::FILE);
        return $tmp;
    }
}
