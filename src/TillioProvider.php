<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

final class TillioProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    public const string DEFAULT_SERVER = 'https://auth.tillio.app';

    public const string PATH_AUTHORIZE    = '/auth/login';
    public const string PATH_TOKEN        = '/api/v1/auth/token';
    public const string PATH_USER         = '/api/v1/auth/user';
    public const string PATH_USER_PROFILE = '/api/v1/auth/user/profile';

    /** RFC 7009 — rewokuje wyłącznie przekazany token. Nie kończy sesji OAuth. */
    public const string PATH_REVOKE = '/api/v1/auth/revoke';

    /** Pełny logout — rewokuje access + wszystkie refresh tokeny pary (user, client) i kasuje sesję OAuth. */
    public const string PATH_LOGOUT = '/api/v1/auth/logout';

    /** OIDC RP-Initiated Logout — przeglądarkowy, per-aplikacja. Nie rusza sesji SSO Tillio. */
    public const string PATH_END_SESSION = '/auth/logout';

    /**
     * Scope'y wspierane przez serwer Tillio. Serwer GATE'uje dane po scope —
     * pole/sekcja pojawia się w odpowiedzi tylko gdy odpowiedni scope został przyznany.
     */
    public const string SCOPE_OPENID          = 'openid';
    public const string SCOPE_PROFILE         = 'profile';         // first_name, last_name, avatar_url, post, settings
    public const string SCOPE_EMAIL           = 'email';           // email (login)
    public const string SCOPE_OFFLINE_ACCESS  = 'offline_access';  // refresh token
    public const string SCOPE_WORKSPACE       = 'workspace';       // sekcja workspace
    public const string SCOPE_ACL             = 'acl';             // acl + is_workspace_superadmin + role_ids
    public const string SCOPE_ORGANIZATION    = 'organization';    // dane rejestrowe firmy (wymaga uprawnień)
    public const string SCOPE_PROFILE_CONTACT = 'profile_contact'; // telefon + e-mail kontaktowy
    public const string SCOPE_TILLIO_CLIENT   = 'tillio_client';   // zautomatyzowane działania w imieniu usera

    /**
     * URL do którego przekierowujemy browser użytkownika (authorize).
     */
    private readonly string $server;

    /**
     * URL używany do wywołań server-to-server (token, user, profile, revoke).
     * Przydatne w Dockerze: browser widzi `localhost`, kontener łączy się przez `host.docker.internal`.
     */
    private readonly string $internalServer;

    private readonly ?string $pkceMethodOverride;

    /**
     * @param array<string, mixed>  $options
     * @param array<string, object> $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        $this->server         = rtrim($options['server'] ?? self::DEFAULT_SERVER, '/');
        $this->internalServer = rtrim($options['internalServer'] ?? $this->server, '/');
        unset($options['server'], $options['internalServer']);

        $pkce = $options['pkceMethod'] ?? null;
        $this->pkceMethodOverride = is_string($pkce) ? $pkce : null;
        unset($options['pkceMethod']);

        parent::__construct($options, $collaborators);
    }

    protected function getPkceMethod(): ?string
    {
        return $this->pkceMethodOverride ?? parent::getPkceMethod();
    }

    public function getBaseAuthorizationUrl(): string
    {
        return $this->server . self::PATH_AUTHORIZE;
    }

    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->internalServer . self::PATH_TOKEN;
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return $this->internalServer . self::PATH_USER;
    }

    public function getUserProfileUrl(): string
    {
        return $this->internalServer . self::PATH_USER_PROFILE;
    }

    public function getRevokeUrl(): string
    {
        return $this->internalServer . self::PATH_REVOKE;
    }

    public function getLogoutUrl(): string
    {
        return $this->internalServer . self::PATH_LOGOUT;
    }

    /**
     * URL przeglądarkowego wylogowania OIDC (`end_session`). Browser-facing,
     * więc buduje się na `server`, nie `internalServer`.
     *
     * @param array<string, string|null> $params np. client_id, post_logout_redirect_uri, state, id_token_hint
     */
    public function getEndSessionUrl(array $params = []): string
    {
        $query = array_filter($params, static fn(?string $value): bool => $value !== null && $value !== '');
        $url   = $this->server . self::PATH_END_SESSION;

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    protected function getDefaultScopes(): array
    {
        return [self::SCOPE_PROFILE, self::SCOPE_EMAIL, self::SCOPE_OPENID, self::SCOPE_OFFLINE_ACCESS];
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() >= 400 || isset($data['error'])) {
            $message = is_array($data) && isset($data['error_description'])
                ? (string) $data['error_description']
                : (is_array($data) && isset($data['error']) ? (string) $data['error'] : $response->getReasonPhrase());

            throw new IdentityProviderException($message, $response->getStatusCode(), $data);
        }
    }

    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        return new TillioResourceOwner($response);
    }
}
