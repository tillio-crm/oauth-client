<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TillioCrm\OAuth\Client\Client;
use TillioCrm\OAuth\Client\Exception\AuthorizationDeniedException;
use TillioCrm\OAuth\Client\Exception\InvalidStateException;
use TillioCrm\OAuth\Client\Exception\NotAuthenticatedException;
use TillioCrm\OAuth\Client\Exception\TillioOAuthException;
use TillioCrm\OAuth\Client\TillioResourceOwner;

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    private const array BASE_CONFIG = [
        'clientId'     => 'client-123',
        'clientSecret' => 'secret-123',
        'redirectUri'  => 'http://localhost/cb',
    ];

    private InMemorySessionStorage $session;

    protected function setUp(): void
    {
        $this->session  = new InMemorySessionStorage();
        $_GET           = [];
    }

    public function test_missing_config_key_throws(): void
    {
        $this->expectException(TillioOAuthException::class);
        $this->expectExceptionMessage('Missing required config key "clientId"');

        new Client(['clientSecret' => 'x', 'redirectUri' => 'http://x/cb'], $this->session);
    }

    public function test_empty_config_value_throws(): void
    {
        $this->expectException(TillioOAuthException::class);
        $this->expectExceptionMessage('Missing required config key "clientSecret"');

        new Client(['clientSecret' => ''] + self::BASE_CONFIG, $this->session);
    }

    public function test_get_authorization_url_stores_state_and_pkce_by_default(): void
    {
        $client = new Client(self::BASE_CONFIG, $this->session);

        $url = $client->getAuthorizationUrl();

        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('code_challenge=', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertNotEmpty($this->session->get('state'));
        self::assertNotEmpty($this->session->get('pkce_verifier'));
    }

    public function test_pkce_can_be_disabled(): void
    {
        $client = new Client(self::BASE_CONFIG + ['usePkce' => false], $this->session);

        $url = $client->getAuthorizationUrl();

        self::assertStringNotContainsString('code_challenge', $url);
        self::assertNull($this->session->get('pkce_verifier'));
    }

    public function test_custom_scopes_override_defaults(): void
    {
        $client = new Client(self::BASE_CONFIG, $this->session);

        $url = $client->getAuthorizationUrl(['profile', 'email']);

        self::assertStringContainsString('scope=profile%20email&', $url);
    }

    public function test_is_authenticated_is_false_without_token(): void
    {
        $client = new Client(self::BASE_CONFIG, $this->session);

        self::assertFalse($client->isAuthenticated());
    }

    public function test_is_authenticated_is_true_with_token_in_session(): void
    {
        $this->session->set('tokens', [
            'access_token'  => 'abc',
            'refresh_token' => 'ref',
            'expires'       => time() + 3600,
        ]);

        $client = new Client(self::BASE_CONFIG, $this->session);

        self::assertTrue($client->isAuthenticated());
    }

    public function test_access_token_throws_when_not_authenticated(): void
    {
        $client = new Client(self::BASE_CONFIG, $this->session);

        $this->expectException(NotAuthenticatedException::class);

        $client->accessToken();
    }

    public function test_access_token_returns_stored_token_when_not_expiring(): void
    {
        $this->session->set('tokens', [
            'access_token' => 'abc',
            'expires'      => time() + 3600,
        ]);

        $client = new Client(self::BASE_CONFIG, $this->session);

        self::assertSame('abc', $client->accessToken());
    }

    public function test_user_returns_cached_owner_from_session(): void
    {
        $this->session->set('tokens', [
            'access_token' => 'abc',
            'expires'      => time() + 3600,
        ]);
        $this->session->set('user', [
            'id'    => 3,
            'email' => 'l@example.com',
        ]);

        $client = new Client(self::BASE_CONFIG, $this->session);
        $user   = $client->user();

        self::assertInstanceOf(TillioResourceOwner::class, $user);
        self::assertSame('3', $user->getId());
        self::assertSame('l@example.com', $user->getEmail());
    }

    public function test_handle_callback_rejects_mismatched_state(): void
    {
        $this->session->set('state', 'EXPECTED_STATE');
        $_GET = ['code' => 'auth-code', 'state' => 'WRONG_STATE'];

        $client = new Client(self::BASE_CONFIG, $this->session);

        $this->expectException(InvalidStateException::class);

        $client->handleCallback();
    }

    public function test_handle_callback_rejects_missing_state(): void
    {
        $this->session->set('state', 'EXPECTED_STATE');
        $_GET = ['code' => 'auth-code']; // no state param

        $client = new Client(self::BASE_CONFIG, $this->session);

        $this->expectException(InvalidStateException::class);

        $client->handleCallback();
    }

    public function test_handle_callback_rejects_missing_code(): void
    {
        $this->session->set('state', 'EXPECTED_STATE');
        $_GET = ['state' => 'EXPECTED_STATE']; // no code

        $client = new Client(self::BASE_CONFIG, $this->session);

        $this->expectException(TillioOAuthException::class);
        $this->expectExceptionMessage('Missing "code"');

        $client->handleCallback();
    }

    public function test_handle_callback_surfaces_authorization_denied(): void
    {
        $_GET = ['error' => 'access_denied', 'error_description' => 'User refused'];

        $client = new Client(self::BASE_CONFIG, $this->session);

        $this->expectException(AuthorizationDeniedException::class);
        $this->expectExceptionMessage('User refused');

        $client->handleCallback();
    }

    public function test_logout_clears_session_when_no_token_present(): void
    {
        $this->session->set('tokens', null);
        $this->session->set('user', ['id' => 3]);

        $client = new Client(self::BASE_CONFIG, $this->session);
        $client->logout();

        self::assertFalse($this->session->has('user'));
    }

    public function test_end_session_url_carries_client_id_and_redirect(): void
    {
        $client = new Client(self::BASE_CONFIG + ['server' => 'http://localhost:8080'], $this->session);

        $url = $client->endSessionUrl('http://localhost/bye', 'state-42');

        self::assertStringStartsWith('http://localhost:8080/auth/logout?', $url);
        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('post_logout_redirect_uri=' . rawurlencode('http://localhost/bye'), $url);
        self::assertStringContainsString('state=state-42', $url);
    }

    public function test_end_session_url_without_redirect_has_only_client_id(): void
    {
        $client = new Client(self::BASE_CONFIG + ['server' => 'http://localhost:8080'], $this->session);

        $url = $client->endSessionUrl();

        self::assertSame('http://localhost:8080/auth/logout?client_id=client-123', $url);
    }
}
