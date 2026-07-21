<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client;

/**
 * Typowany wrapper na odpowiedź `/api/v1/auth/user/profile`.
 *
 * Serwer gate'uje sekcje po scope — pole jest obecne tylko gdy token ma odpowiedni
 * scope (patrz stałe `TillioProvider::SCOPE_*`). Gettery zwracają null / [] / false,
 * gdy sekcji nie ma w odpowiedzi. Owija `Client::profile()`:
 *
 *     $profile = new TillioProfile($client->profile());
 *
 * @phpstan-type ProfileResponse array<string, mixed>
 */
final class TillioProfile
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        private readonly array $response,
    ) {
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

    public function getPost(): ?string
    {
        return $this->response['post'] ?? null;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->response['avatar_url'] ?? null;
    }

    /**
     * Preferencje użytkownika (scope `profile`).
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $settings = $this->response['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * Sekcja `workspace` (scope `workspace`).
     *
     * @return array<string, mixed>|null
     */
    public function getWorkspace(): ?array
    {
        $workspace = $this->response['workspace'] ?? null;

        return is_array($workspace) ? $workspace : null;
    }

    /**
     * Płaska mapa uprawnień ACL (scope `acl`).
     *
     * @return array<string, mixed>
     */
    public function getAcl(): array
    {
        $acl = $this->response['acl'] ?? [];

        return is_array($acl) ? $acl : [];
    }

    /**
     * Czy user jest superadminem workspace (scope `acl`).
     */
    public function isWorkspaceSuperAdmin(): bool
    {
        return (bool) ($this->response['is_workspace_superadmin'] ?? false);
    }

    /**
     * ID ról przypisanych użytkownikowi (scope `acl`).
     *
     * @return list<int>
     */
    public function getRoleIds(): array
    {
        $roleIds = $this->response['role_ids'] ?? [];
        if (!is_array($roleIds)) {
            return [];
        }

        return array_values(array_map(static fn($id): int => (int) $id, $roleIds));
    }

    /**
     * Dane rejestrowe organizacji (scope `organization`, wymaga uprawnień w Tillio).
     * null gdy scope nieprzyznany lub user nie ma uprawnień.
     *
     * @return array<string, mixed>|null
     */
    public function getOrganization(): ?array
    {
        $organization = $this->response['organization'] ?? null;

        return is_array($organization) ? $organization : null;
    }

    /**
     * Dane kontaktowe użytkownika — telefon + e-mail kontaktowy (scope `profile_contact`).
     *
     * @return array<string, mixed>|null
     */
    public function getContact(): ?array
    {
        $contact = $this->response['profile_contact'] ?? null;

        return is_array($contact) ? $contact : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->response;
    }
}
