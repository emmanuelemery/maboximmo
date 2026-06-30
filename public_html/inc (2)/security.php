<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(null|string|int|float $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('post')) {
    function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('get')) {
    function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): never
    {
        if (str_starts_with($url, '/')) {
            $url = app_url($url);
        }

        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $base = $GLOBALS['APP_BASE_PATH'] ?? '';
        if (!is_string($base) || $base === '') {
            $base = defined('APP_BASE_PATH') ? (string)APP_BASE_PATH : '';
        }
        return rtrim((string)$base, '/');
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $base = app_base_path();
        return $base !== '' ? $base . $path : $path;
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        return app_url($path);
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }

        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
