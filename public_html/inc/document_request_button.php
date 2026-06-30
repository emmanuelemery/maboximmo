<?php
declare(strict_types=1);
/**
 * inc/document_request_button.php — Bouton « Demander un document » réutilisable.
 *
 * À déposer dans n'importe quelle fiche/dossier (bien, immeuble, tiers, bail, user…).
 * Le contexte (ctx + id) est transmis à l'écran de création : les pièces déposées
 * via le lien seront CLASSÉES AUTOMATIQUEMENT en GED sur cette entité.
 *
 * Usage :
 *   require_once __DIR__ . '/inc/document_request_button.php';
 *   echo document_request_button('BIEN', $idBien, ['back' => 'bien_360.php?id='.$idBien]);
 *
 * ctx valides pour classement GED auto : BIEN, IMMEUBLE, IMB, TIERS, BAIL.
 * (USER fonctionne aussi mais les docs RH ne sont pas encore en GED centrale.)
 */
if (!function_exists('document_request_button')) {
    function document_request_button(string $ctx, int $id, array $opts = []): string
    {
        $label = $opts['label'] ?? 'Demander un document';
        $back  = (string)($opts['back'] ?? '');
        $class = $opts['class'] ?? 'ph-btn';
        $url = app_url('/document_request_new.php?ctx=' . urlencode($ctx) . '&id=' . (int)$id
            . ($back !== '' ? '&back=' . urlencode($back) : ''));
        $svg = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
        return '<a href="' . h($url) . '" class="' . h($class) . '" title="Envoyer un lien de dépôt sécurisé — les pièces se classent ici automatiquement">'
            . $svg . h($label) . '</a>';
    }
}
