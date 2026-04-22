<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Tests;

use TillioCrm\OAuth\Client\Session\SessionStorageInterface;

final class InMemorySessionStorage implements SessionStorageInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public int $regenerateCount = 0;

    public function regenerate(): void
    {
        $this->regenerateCount++;
    }
}
