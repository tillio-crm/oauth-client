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
        self::assertSame('http://localhost:8080/api/v1/auth/logout', $provider->getLogoutUrl());
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
        self::assertSame('http://host.docker.internal:8080/api/v1/auth/logout', $provider->getLogoutUrl());

        // end_session jest browser-facing → zostaje na `server`.
        self::assertSame('http://localhost:8080/auth/logout', $provider->getEndSessionUrl());
    }

    public function test_end_session_url_skips_empty_params(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS + ['server' => 'http://localhost:8080']);

        $url = $provider->getEndSessionUrl([
            'client_id'                => 'client-123',
            'post_logout_redirect_uri' => 'http://localhost/bye',
            'state'                    => null,
        ]);

        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('post_logout_redirect_uri=' . rawurlencode('http://localhost/bye'), $url);
        self::assertStringNotContainsString('state=', $url);
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

    public function test_scope_constants_match_server_scopes(): void
    {
        self::assertSame('openid', TillioProvider::SCOPE_OPENID);
        self::assertSame('profile', TillioProvider::SCOPE_PROFILE);
        self::assertSame('email', TillioProvider::SCOPE_EMAIL);
        self::assertSame('offline_access', TillioProvider::SCOPE_OFFLINE_ACCESS);
        self::assertSame('workspace', TillioProvider::SCOPE_WORKSPACE);
        self::assertSame('acl', TillioProvider::SCOPE_ACL);
        self::assertSame('organization', TillioProvider::SCOPE_ORGANIZATION);
        self::assertSame('profile_contact', TillioProvider::SCOPE_PROFILE_CONTACT);
        self::assertSame('tillio_client', TillioProvider::SCOPE_TILLIO_CLIENT);
    }

    public function test_custom_scopes_via_constants_land_in_authorization_url(): void
    {
        $provider = new TillioProvider(self::BASE_OPTIONS);

        $url = $provider->getAuthorizationUrl([
            'scope' => [TillioProvider::SCOPE_PROFILE, TillioProvider::SCOPE_WORKSPACE, TillioProvider::SCOPE_ACL],
        ]);

        self::assertStringContainsString('scope=profile%20workspace%20acl', $url);
    }
}
