<?php

declare(strict_types=1);

class Auth
{
    public static function verify(): bool
    {
        $lockFile = dirname(__DIR__, 2) . '/data/.lock';
        if (file_exists($lockFile)) {
            return false;
        }

        $token = getenv('API_TOKEN');
        if (!$token) {
            return false;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $provided = substr($header, 7);

        return hash_equals($token, $provided);
    }
}
