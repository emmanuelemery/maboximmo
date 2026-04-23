<?php
declare(strict_types=1);
/**
 * investisseur/prix_api.php — endpoint AJAX pour la page prix_priorites.php
 *
 * Actions :
 *   - simulate : reçoit un prix_vente_catalogue hypothétique + l'id_analyse,
 *                retourne la mini-analyse recalculée (rdt, multiple, cashflow,
 *                vente 5 ans, vente 10 ans, gain vs vente immédiate) — SANS sauver.
 *   - save     : persiste prix_vente_catalogue + priorite_vente sur l'analyse.
 *                Recalcule au passage tous les KPI (rdt, score...) via inv_save.
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
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    $id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('id manquant');

    $row = inv_load($pdo, $id);
    if (!$row) throw new RuntimeException('Analyse introuvable');

    // Appliquer le prix hypothétique (si fourni) pour simulate / save
    if (isset($_POST['prix_vente_catalogue']) && $_POST['prix_vente_catalogue'] !== '') {
        $row['prix_vente_catalogue'] = (float)str_replace(',', '.', (string)$_POST['prix_vente_catalogue']);
        // On aligne le prix_achat sur le nouveau prix de vente si aucune négociation n'est simulée
        // (sinon on garde l'écart existant en % pour conserver la logique de négociation)
        $oldVente  = (float)($row['prix_vente_catalogue'] ?? 0);
        $row['prix_achat'] = $row['prix_vente_catalogue'];
    }

    if ($action === 'simulate') {
        $calc = inv_compute_all($row);
        $arb  = inv_proj_arbitrage($row, [5, 10]);
        echo json_encode([
            'ok' => true,
            'kpi' => [
                'rendement_brut'    => (float)$calc['rendement_brut'],
                'rendement_net'     => (float)$calc['rendement_net'],
                'prix_m2'           => (float)$calc['prix_m2'],
                'multiple_loyer'    => (float)$calc['multiple_loyer'],
                'cashflow_mensuel'  => (float)$calc['cashflow_mensuel'],
                'score_global'      => (int)$calc['score_global'],
            ],
            'arbitrage' => [
                'vendre_now'  => (float)($arb['scenarios']['vendre_now']['cash_net'] ?? 0),
                'garder_5'    => (float)($arb['scenarios']['garder_5']['cash_net']   ?? 0),
                'garder_10'   => (float)($arb['scenarios']['garder_10']['cash_net']  ?? 0),
                'meilleur'    => $arb['meilleur'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save') {
        verify_csrf_any();
        if (isset($_POST['priorite_vente'])) {
            $row['priorite_vente'] = max(0, min(10, (int)$_POST['priorite_vente']));
        }
        inv_save($pdo, $row, $id);
        // Relecture pour renvoyer les KPI définitifs
        $row = inv_load($pdo, $id);
        echo json_encode([
            'ok' => true,
            'saved' => true,
            'kpi' => [
                'rendement_brut'    => (float)$row['rendement_brut'],
                'rendement_net'     => (float)$row['rendement_net'],
                'prix_m2'           => (float)$row['prix_m2'],
                'multiple_loyer'    => (float)$row['multiple_loyer'],
                'cashflow_mensuel'  => (float)$row['cashflow_mensuel'],
                'score_global'      => (int)$row['score_global'],
                'priorite_vente'    => (int)($row['priorite_vente'] ?? 0),
                'prix_vente_catalogue' => (float)($row['prix_vente_catalogue'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('action inconnue');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
