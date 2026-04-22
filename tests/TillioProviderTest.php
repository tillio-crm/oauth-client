<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Tests;

use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TillioCrm\OAuth\Client\TillioProvider;

#[CoversClass(TillioProvider::class)]
final class TillioProviderTest extends TestCase
{
    private const array BASE_OPTIONS = [
        'clientId'     => 'client-123',
        'clientSecret' => 'secret-123',
        'redirectUri'  => 'http://localhost/cb',
    ];

    public function test_defaults_to_production_server(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS);

        self::assertSame('https://auth.tillio.app/auth/login', $provider->getBaseAuthorizationUrl());
        self::assertSame('https://auth.tillio.app/api/v1/auth/token', $provider->getBaseAccessTokenUrl([]));
    }

    public function test_server_is_used_for_every_endpoint_when_internal_not_set(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS + ['server' => 'http://localhost:8080']);

        $token = new AccessToken(['access_token' => 'x']);

        self::assertSame('http://localhost:8080/auth/login', $provider->getBaseAuthorizationUrl());
        self::assertSame('http://localhost:8080/api/v1/auth/token', $provider->getBaseAccessTokenUrl([]));
        self::assertSame('http://localhost:8080/api/v1/auth/user', $provider->getResourceOwnerDetailsUrl($token));
        self::assertSame('http://localhost:8080/api/v1/auth/user/profile', $provider->getUserProfileUrl());
        self::assertSame('http://localhost:8080/api/v1/auth/revoke', $provider->getRevokeUrl());
    }

    public function test_internal_server_overrides_backend_urls_only(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS + [
            'server'         => 'http://localhost:8080',
            'internalServer' => 'http://host.docker.internal:8080',
        ]);

        $token = new AccessToken(['access_token' => 'x']);

        // Browser-facing stays on `server`.
        self::assertSame('http://localhost:8080/auth/login', $provider->getBaseAuthorizationUrl());

        // Backend-facing switches to `internalServer`.
        self::assertSame('http://host.docker.internal:8080/api/v1/auth/token', $provider->getBaseAccessTokenUrl([]));
        self::assertSame('http://host.docker.internal:8080/api/v1/auth/user', $provider->getResourceOwnerDetailsUrl($token));
        self::assertSame('http://host.docker.internal:8080/api/v1/auth/user/profile', $provider->getUserProfileUrl());
        self::assertSame('http://host.docker.internal:8080/api/v1/auth/revoke', $provider->getRevokeUrl());
    }

    public function test_trailing_slash_in_server_is_trimmed(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS + [
            'server' => 'http://localhost:8080/',
        ]);

        self::assertSame('http://localhost:8080/auth/login', $provider->getBaseAuthorizationUrl());
    }

    public function test_pkce_method_is_propagated_to_authorization_url(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS + [
            'pkceMethod' => 'S256',
        ]);

        $url = $provider->getAuthorizationUrl();

        self::assertStringContainsString('code_challenge=', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertNotEmpty($provider->getPkceCode());
    }

    public function test_pkce_is_off_when_method_not_provided(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS);

        $url = $provider->getAuthorizationUrl();

        self::assertStringNotContainsString('code_challenge', $url);
    }

    public function test_default_scopes_are_openid_compatible(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS);

        $url = $provider->getAuthorizationUrl();

        // Default scopes: profile, email, openid, offline_access — space-separated.
        self::assertStringContainsString('scope=profile%20email%20openid%20offline_access', $url);
    }
}
