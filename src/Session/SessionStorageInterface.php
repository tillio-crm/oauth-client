<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Session;

interface SessionStorageInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function clear(): void;

    /**
     * Regenerates the session identifier, keeping the data.
     * Called after successful authentication to prevent session fixation attacks.
     * Implementations without a cookie-based session ID can make this a no-op.
     */
    public function regenerate(): void;
}
