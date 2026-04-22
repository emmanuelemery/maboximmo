<?php
declare(strict_types=1);
/**
 * investisseur/arbitrage_api.php — Endpoint JSON pour le bloc arbitrage live de detail.php.
 *
 * Actions :
 *   - arbitrage_preview : recalcule les 3 scénarios avec les hypothèses POST (sans sauver)
 *   - arbitrage_save    : sauvegarde les hypothèses dans investisseur_analyses + renvoie le résultat
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';
require_once __DIR__ . '/../inc/investisseur_interpretations.php';
require_once __DIR__ . '/../inc/investisseur_projection.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];

try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('id manquant');

    $row = inv_load($pdo, $id);
    if (!$row) throw new RuntimeException('Analyse introuvable ou hors périmètre.');

    // Merge des hypothèses POST dans la ligne
    foreach (['credit_crd','credit_duree_restante_mois','taux_credit','ira_pct',
              'revalorisation_bien_pct_an','indexation_loyer_pct_an','taux_imposition_pct'] as $k) {
        if (isset($_POST[$k])) {
            $v = (string)$_POST[$k];
            if ($v === '') {
                $row[$k] = $k === 'taux_imposition_pct' ? null : 0;
            } else {
                $row[$k] = (float)str_replace(',', '.', $v);
            }
        }
    }

    $action = (string)($_POST['action'] ?? 'arbitrage_preview');

    if ($action === 'arbitrage_save') {
        verify_csrf_any();
        $clean = inv_sanitize_post($_POST);
        $clean = array_intersect_key($clean, array_flip([
            'credit_crd','credit_duree_restante_mois','taux_credit','ira_pct',
            'revalorisation_bien_pct_an','indexation_loyer_pct_an','taux_imposition_pct',
        ]));
        // Merge avec la ligne complète pour que inv_save recalcule correctement
        $full = array_merge($row, $clean);
        inv_save($pdo, $full, $id);
    }

    $arb = inv_proj_arbitrage($row, [5, 10]);

    $out = [
        'ok'        => true,
        'meilleur'  => $arb['meilleur'],
        'conseils'  => $arb['conseils'],
        'scenarios' => [],
    ];
    foreach ($arb['scenarios'] as $k => $s) {
        $out['scenarios'][$k] = [
            'label'    => $s['label'],
            'cash_net' => $s['cash_net'],
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
