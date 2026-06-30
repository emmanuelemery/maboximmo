<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Module d'extraction IA spécialisé — DIAGNOSTICS (DPE, plomb, amiante,
 *   électricité, gaz, termites, ERP, mesurage Loi Boutin/Carrez, etc.)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Wrapper fin qui délègue à analyseDpeIA() (inc/dpe_ia_analyse.php).
 * Cette fonction a déjà un prompt très riche pour les diagnostics.
 *
 * Exposé comme point d'entrée unique pour que le dispatcher appelle
 * toujours le même module quand le document est détecté comme un diag.
 */

require_once __DIR__ . '/dpe_ia_analyse.php';

function analyseDiagIA(string $text): array
{
    $result = analyseDpeIA($text);
    // Normalise la forme de retour (doc_type explicite)
    if (!isset($result['doc_type'])) {
        $result['doc_type'] = 'diag';
    }
    return $result;
}
