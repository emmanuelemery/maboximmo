<?php
declare(strict_types=1);
/**
 * inc/mail_button.php — Bouton « Envoyer par mail » réutilisable dans tout module.
 *
 * Usage (dans une page, ex. slot $layout_head_actions) :
 *   require_once __DIR__ . '/inc/mail_button.php';
 *   $layout_head_actions = mail_button('USER', $idUser, ['back' => 'rh_salaire_detail.php?...']);
 *
 * Ouvre le composeur générique mail_compose.php avec le bon contexte.
 */
if (!function_exists('mail_button')) {
    function mail_button(string $ctx, int $id, array $opts = []): string
    {
        $label = $opts['label'] ?? 'Envoyer par mail';
        $back  = (string)($opts['back'] ?? '');
        $class = $opts['class'] ?? 'ph-btn';
        $url = app_url('/mail_compose.php?ctx=' . urlencode($ctx) . '&id=' . (int)$id
            . ($back !== '' ? '&back=' . urlencode($back) : ''));
        $svg = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
        return '<a href="' . h($url) . '" class="' . h($class) . '" target="_blank" rel="noopener" title="Composer un mail avec contacts et documents">'
            . $svg . h($label) . '</a>';
    }
}
