<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Construit le scope SQL pour récupérer les biens accessibles au module arbitrage.
 * Retour : [whereSql, params]
 */
function arb_scope_biens_where(): array
{
    $roleId = (int)current_role_id();
    $isAdmin = in_array($roleId, [1, 7], true) || !empty($_SESSION['super_admin']);

    if ($isAdmin) {
        // Admin : scope "société" si défini, sinon tout.
        $societeId = (int)($_SESSION['id_societe'] ?? 0);
        if ($societeId > 0) {
            return ['b.id_societe = ?', [$societeId]];
        }
        return ['1=1', []];
    }

    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    if ($societeId > 0) {
        return ['b.id_societe = ?', [$societeId]];
    }

    $code = strtoupper((string)($_SESSION['code_acces'] ?? ''));
    if ($code === 'SIR') {
        $props = get_sir_proprietaire_ids((int)current_user_id());
        $props = array_values(array_filter(array_map('intval', $props), fn($v) => $v > 0));
        if (empty($props)) {
            return ['0=1', []];
        }
        $ph = implode(',', array_fill(0, count($props), '?'));
        return ["b.id_proprietaire IN ($ph)", $props];
    }

    return ['0=1', []];
}

