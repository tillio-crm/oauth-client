<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use TillioCrm\OAuth\Client\Exception\AuthorizationDeniedException;
use TillioCrm\OAuth\Client\Exception\InvalidStateException;
use TillioCrm\OAuth\Client\Exception\NotAuthenticatedException;
use TillioCrm\OAuth\Client\Exception\TillioOAuthException;
use TillioCrm\OAuth\Client\Session\NativeSessionStorage;
use TillioCrm\OAuth\Client\Session\SessionStorageInterface;
use Throwable;

final class Client
{
    private const string KEY_TOKENS        = 'tokens';
    private const string KEY_STATE         = 'state';
    private const string KEY_PKCE_VERIFIER = 'pkce_verifier';
    private const string KEY_USER          = 'user';

    private const int REFRESH_LEEWAY_SECONDS = 60;

    private readonly TillioProvider $provider;

    private readonly SessionStorageInterface $session;

    /**
     * @var list<string>
     */
    private readonly array $defaultScopes;

    private readonly bool $usePkce;

    private readonly string $clientId;

    private readonly string $clientSecret;

    /**
     * @param array{
     *     clientId: string,
     *     clientSecret: string,
     *     redirectUri: string,
     *     server?: string,
     *     internalServer?: string,
     *     scopes?: list<string>,
     *     usePkce?: bool,
     * } $config
     */
    public function __construct(array $config, ?SessionStorageInterface $session = null)
    {
        foreach (['clientId', 'clientSecret', 'redirectUri'] as $required) {
            if (empty($config[$required]) || !is_string($config[$required])) {
                throw new TillioOAuthException(sprintf('Missing required config key "%s".', $required));
            }
        }

        $this->clientId      = $config['clientId'];
        $this->clientSecret  = $config['clientSecret'];
        $this->defaultScopes = $config['scopes'] ?? ['profile', 'email', 'openid', 'offline_access'];
        $this->usePkce       = (bool) ($config['usePkce'] ?? true);

        $providerOptions = [
            'clientId'     => $config['clientId'],
            'clientSecret' => $config['clientSecret'],
            'redirectUri'  => $config['redirectUri'],
        ];

        if (isset($config['server'])) {
            $providerOptions['server'] = $config['server'];
        }

        if (isset($config['internalServer'])) {
            $providerOptions['internalServer'] = $config['internalServer'];
        }

        if ($this->usePkce) {
            $providerOptions['pkceMethod'] = 'S256';
        }

        $this->provider = new TillioProvider($providerOptions);
        $this->session  = $session ?? new NativeSessionStorage();
    }

    /**
     * Build authorization URL and persist state (+ PKCE verifier) in session.
     * Use this when you want to render a link instead of redirecting.
     *
     * @param list<string> $scopes
     */
    public function getAuthorizationUrl(array $scopes = []): string
    {
        $url = $this->provider->getAuthorizationUrl([
            'scope' => $scopes !== [] ? $scopes : $this->defaultScopes,
        ]);

        $this->session->set(self::KEY_STATE, $this->provider->getState());

        if ($this->usePkce) {
            $this->session->set(self::KEY_PKCE_VERIFIER, $this->provider->getPkceCode());
        }

        return $url;
    }

    /**
     * Redirects the browser to the Tillio authorization page. Terminates the script.
     *
     * @param list<string> $scopes
     */
    public function redirectToLogin(array $scopes = []): never
    {
        $url = $this->getAuthorizationUrl($scopes);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Handles the OAuth2 callback: validates state, exchanges the code for tokens
     * and fetches the user profile.
     */
    public function handleCallback(): TillioResourceOwner
    {
        $query = $_GET;

        if (isset($query['error'])) {
            $this->session->remove(self::KEY_STATE);
            $this->session->remove(self::KEY_PKCE_VERIFIER);

            throw new AuthorizationDeniedException(
                (string) ($query['error_description'] ?? $query['error'])
            );
        }

        if (empty($query['code']) || !is_string($query['code'])) {
            throw new TillioOAuthException('Missing "code" parameter in OAuth2 callback.');
        }

        $expectedState = $this->session->get(self::KEY_STATE);
        $receivedState = $query['state'] ?? null;

        if (!is_string($expectedState) || !is_string($receivedState) || !hash_equals($expectedState, $receivedState)) {
            $this->session->remove(self::KEY_STATE);

            throw new InvalidStateException('OAuth2 state mismatch. Possible CSRF attempt.');
        }

        $this->session->remove(self::KEY_STATE);

        if ($this->usePkce) {
            $verifier = $this->session->get(self::KEY_PKCE_VERIFIER);
            if (is_string($verifier)) {
                $this->provider->setPkceCode($verifier);
            }
            $this->session->remove(self::KEY_PKCE_VERIFIER);
        }

        try {
            $accessToken = $this->provider->getAccessToken('authorization_code', [
                'code' => $query['code'],
            ]);
        } catch (IdentityProviderException $e) {
            throw new TillioOAuthException('Failed to exchange authorization code: ' . $e->getMessage(), previous: $e);
        }

        // Prevent session fixation: rotate session id now that we know the user is authenticated.
        $this->session->regenerate();

        $this->storeToken($accessToken);

        $owner = $this->provider->getResourceOwner($accessToken);
        \assert($owner instanceof TillioResourceOwner);

        $this->session->set(self::KEY_USER, $owner->toArray());

        return $owner;
    }

    public function isAuthenticated(): bool
    {
        $tokens = $this->session->get(self::KEY_TOKENS);

        return is_array($tokens) && isset($tokens['access_token']);
    }

    /**
     * Returns a valid access token, refreshing it if needed.
     */
    public function accessToken(): string
    {
        $tokens = $this->session->get(self::KEY_TOKENS);

        if (!is_array($tokens) || !isset($tokens['access_token'])) {
            throw new NotAuthenticatedException('No access token in session. Call handleCallback() first.');
        }

        if ($this->isTokenExpiring($tokens)) {
            $this->refresh();
            $tokens = $this->session->get(self::KEY_TOKENS);
        }

        return (string) $tokens['access_token'];
    }

    /**
     * Returns the authenticated resource owner, refreshing the token if needed.
     */
    public function user(): TillioResourceOwner
    {
        // Ensures token is fresh (or throws).
        $this->accessToken();

        $cached = $this->session->get(self::KEY_USER);
        if (is_array($cached)) {
            return new TillioResourceOwner($cached);
        }

        return $this->refreshUser();
    }

    /**
     * Force re-fetch the user profile from the server.
     */
    public function refreshUser(): TillioResourceOwner
    {
        $token = $this->buildAccessToken();

        $owner = $this->provider->getResourceOwner($token);
        \assert($owner instanceof TillioResourceOwner);

        $this->session->set(self::KEY_USER, $owner->toArray());

        return $owner;
    }

    /**
     * Fetches the extended user profile from /api/v1/auth/user/profile.
     * Always hits the server (no caching). Auto-refreshes the access token.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        // Ensures token is fresh (or throws).
        $this->accessToken();

        $request = $this->provider->getAuthenticatedRequest(
            'GET',
            $this->provider->getUserProfileUrl(),
            $this->buildAccessToken()
        );

        $response = $this->provider->getParsedResponse($request);

        if (!is_array($response)) {
            throw new TillioOAuthException('Unexpected response from user profile endpoint.');
        }

        return $response;
    }

    /**
     * Kończy sesję OAuth po stronie serwera (`/api/v1/auth/logout`) i czyści sesję lokalną.
     *
     * Serwer rewokuje access token, WSZYSTKIE refresh tokeny pary (user, client)
     * i kasuje rekord sesji OAuth — sam `revoke` (RFC 7009) tego nie robi i zostawiłby
     * działający refresh token.
     *
     * Nie rusza sesji SSO Tillio — inne aplikacje pozostają zalogowane. Żeby wylogować
     * użytkownika także w przeglądarce, przekieruj go na `endSessionUrl()`.
     *
     * Silently ignores network errors — local session is always cleared.
     */
    public function logout(): void
    {
        $tokens = $this->session->get(self::KEY_TOKENS);

        if (is_array($tokens) && isset($tokens['access_token'])) {
            try {
                $this->logoutRemote((string) $tokens['access_token']);
            } catch (Throwable) {
                // ignore — session will be cleared anyway
            }
        }

        $this->session->clear();
    }

    /**
     * URL przeglądarkowego wylogowania OIDC (`end_session`) dla TEJ aplikacji.
     * Przekieruj tam browser po `logout()`, jeśli chcesz zamknąć też sesję po stronie Tillio
     * dla tego klienta.
     *
     * `$postLogoutRedirectUri` musi pasować originem (scheme+host+port) do URI
     * zarejestrowanych dla klienta — inaczej serwer pokaże stronę błędu.
     */
    public function endSessionUrl(?string $postLogoutRedirectUri = null, ?string $state = null): string
    {
        return $this->provider->getEndSessionUrl([
            'client_id'                => $this->clientId,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'state'                    => $state,
        ]);
    }

    public function getProvider(): TillioProvider
    {
        return $this->provider;
    }

    private function refresh(): void
    {
        $tokens = $this->session->get(self::KEY_TOKENS);

        if (!is_array($tokens) || empty($tokens['refresh_token'])) {
            $this->session->clear();
            throw new NotAuthenticatedException('Access token expired and no refresh token available.');
        }

        try {
            $new = $this->provider->getAccessToken('refresh_token', [
                'refresh_token' => (string) $tokens['refresh_token'],
            ]);
        } catch (IdentityProviderException $e) {
            $this->session->clear();
            throw new NotAuthenticatedException('Session expired: ' . $e->getMessage(), previous: $e);
        }

        $this->storeToken($new, fallbackRefreshToken: (string) $tokens['refresh_token']);
    }

    private function storeToken(AccessToken $token, ?string $fallbackRefreshToken = null): void
    {
        $this->session->set(self::KEY_TOKENS, [
            'access_token'  => $token->getToken(),
            'refresh_token' => $token->getRefreshToken() ?? $fallbackRefreshToken,
            'expires'       => $token->getExpires(),
        ]);
    }

    private function buildAccessToken(): AccessToken
    {
        $tokens = $this->session->get(self::KEY_TOKENS);
        \assert(is_array($tokens));

        return new AccessToken([
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires'       => $tokens['expires'] ?? null,
        ]);
    }

    /**
     * @param array{access_token?: string, refresh_token?: string, expires?: int|null} $tokens
     */
    private function isTokenExpiring(array $tokens): bool
    {
        if (!isset($tokens['expires']) || $tokens['expires'] === null) {
            return false;
        }

        return time() >= ((int) $tokens['expires'] - self::REFRESH_LEEWAY_SECONDS);
    }

    private function logoutRemote(string $token): void
    {
        $request = $this->provider->getRequestFactory()->getRequestWithOptions(
            'POST',
            $this->provider->getLogoutUrl(),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'token'         => $token,
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ], JSON_THROW_ON_ERROR),
            ]
        );

        $this->provider->getHttpClient()->send($request);
    }
}
