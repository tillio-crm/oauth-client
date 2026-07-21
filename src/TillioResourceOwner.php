<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

final class TillioResourceOwner implements ResourceOwnerInterface
{
    public function __construct(
        private readonly array $response,
    ) {
    }

    public function getId(): ?string
    {
        return isset($this->response['id']) ? (string) $this->response['id'] : null;
    }

    public function getPublicId(): ?string
    {
        return $this->response['public_id'] ?? null;
    }

    public function getTillioId(): ?string
    {
        return isset($this->response['tillio_id']) ? (string) $this->response['tillio_id'] : null;
    }

    public function getFirstName(): ?string
    {
        return $this->response['first_name'] ?? null;
    }

    public function getLastName(): ?string
    {
        return $this->response['last_name'] ?? null;
    }

    public function getName(): ?string
    {
        $parts = array_filter([
            $this->response['first_name'] ?? null,
            $this->response['last_name'] ?? null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    public function getEmail(): ?string
    {
        return $this->response['email'] ?? null;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->response['avatar_url'] ?? null;
    }

    /**
     * Sekcja `workspace` — obecna tylko gdy token ma scope `workspace`.
     *
     * @return array<string, mixed>|null
     */
    public function getWorkspace(): ?array
    {
        $workspace = $this->response['workspace'] ?? null;

        return is_array($workspace) ? $workspace : null;
    }

    public function toArray(): array
    {
        return $this->response;
    }
}
