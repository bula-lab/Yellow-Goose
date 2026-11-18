<?php
namespace ZoltaGaska\Security;

final class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if ($path = getenv('GASKA_SESSION_PATH')) session_save_path($path);
            session_name('gaska_session');
            $started = session_start([
                'use_strict_mode' => true, 'use_only_cookies' => true,
                'cookie_httponly' => true, 'cookie_samesite' => 'Lax',
                'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
            if (!$started) throw new \RuntimeException('Cannot start session.');
        }
    }

    public static function csrfToken(): string
    {
        self::start();
        if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $token = $_SESSION['csrf'];
        session_write_close();
        return $token;
    }
}
