<?php
/**
 * RateLimiter — MaBoxImmo Phase 3
 * Basé sur fichiers /tmp — compatible Hostinger mutualisé, pas de Redis requis.
 */
class RateLimiter
{
    private static string $dir = '/tmp/mbi_ratelimit/';

    public static function check(string $key, int $max = 100, int $window = 300): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::$dir = sys_get_temp_dir() . '/mbi_ratelimit/';
        }
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0700, true);
        }

        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $file = self::$dir . md5($key . '_' . $ip) . '.json';
        $now  = time();
        $data = ['count' => 0, 'window_start' => $now];

        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $data = json_decode($raw, true) ?? $data;
        }

        if ($now - $data['window_start'] > $window) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        if ($data['count'] > $max) {
            http_response_code(429);
            header('Retry-After: ' . ($window - ($now - $data['window_start'])));
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Trop de requêtes. Réessayez dans quelques minutes.',
            ]);
            exit;
        }
    }

    public static function checkLogin():  void { self::check('login',  10, 300); }
    public static function checkUpload(): void { self::check('upload', 20, 300); }
    public static function checkAI():     void { self::check('ai',     10, 300); }
}
