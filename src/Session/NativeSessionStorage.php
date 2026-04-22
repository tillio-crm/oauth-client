<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Session;

use TillioCrm\OAuth\Client\Exception\TillioOAuthException;

final class NativeSessionStorage implements SessionStorageInterface
{
    public function __construct(
        private readonly string $namespace = 'tillio_oauth',
    ) {
        if (session_status() === PHP_SESSION_DISABLED) {
            throw new TillioOAuthException('PHP sessions are disabled.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $_SESSION[$this->namespace][$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$this->namespace][$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();

        return isset($_SESSION[$this->namespace][$key]);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$this->namespace][$key]);
    }

    public function clear(): void
    {
        $this->ensureStarted();
        unset($_SESSION[$this->namespace]);
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
        session_regenerate_id(true);
    }

    private function ensureStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            throw new TillioOAuthException(
                'PHP session has not been started. Call session_start() before instantiating the client.'
            );
        }
    }
}
