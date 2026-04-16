<?php
declare(strict_types=1);

function csrf_token(string $form = 'default'): string
{
    $key = '_csrf_' . $form;

    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION[$key];
}

function csrf_field(string $form = 'default'): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token($form)) . '">';
}

function verify_csrf(string $form = 'default'): void
{
    $key = '_csrf_' . $form;

    $sessionToken = $_SESSION[$key] ?? '';
    $postedToken  = $_POST['csrf_token'] ?? '';

    if (!$sessionToken || !$postedToken || !hash_equals((string)$sessionToken, (string)$postedToken)) {
        http_response_code(419);
        exit('Requête invalide (CSRF).');
    }
}

/**
 * Vérifie le token CSRF depuis POST ou depuis le header X-CSRF-Token.
 * Utilisé par les endpoints API appelés via fetch (JSON ou FormData).
 * Renvoie une réponse JSON en cas d'échec pour être cohérent avec les APIs.
 */
function verify_csrf_any(string $form = 'default'): void
{
    $key = '_csrf_' . $form;

    $sessionToken = $_SESSION[$key] ?? '';

    // Priorité 1 : champ POST (FormData)
    $clientToken = $_POST['csrf_token'] ?? '';

    // Priorité 2 : header HTTP X-CSRF-Token (appels JSON fetch)
    if (!$clientToken) {
        $clientToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }


    if (!$sessionToken || !$clientToken || !hash_equals((string)$sessionToken, (string)$clientToken)) {
        http_response_code(419);
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Requête invalide (CSRF).']));
    }
}