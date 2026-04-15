<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
session_start();

try {
    require_once __DIR__ . '/inc/bootstrap.php';
    require_once __DIR__ . '/inc/auth.php';
    require_once __DIR__ . '/inc/rh_helpers.php';
    require_once __DIR__ . '/inc/rh_salaires_conges_pdf.php';

    require_login();

    $roleId      = current_role_id();
    $agenceScope = can_manage_salaires_agence();
    if ($roleId !== 1 && $agenceScope === 0) {
        http_response_code(403);
        exit('Accès refusé');
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) { http_response_code(500); exit('Erreur DB'); }

    $mois  = (int)($_GET['mois']  ?? date('n'));
    $annee = (int)($_GET['annee'] ?? date('Y'));

    $pdfContent = rh_generate_salaires_conges_pdf($pdo, $mois, $annee, $agenceScope);
    $baseName = 'SALAIRES_CONGES_' . $annee . '_' . str_pad((string)$mois, 2, '0', STR_PAD_LEFT) . '.pdf';

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $baseName . '"');
    echo $pdfContent;
    exit;

} catch (Throwable $e) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERREUR: " . $e->getMessage();
    error_log("Erreur export sal+conges: " . $e->getMessage());
    exit;
}