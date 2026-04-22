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
    public const string PATH_REVOKE       = '/api/v1/auth/revoke';

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

    protected function getDefaultScopes(): array
    {
        return ['profile', 'email', 'openid', 'offline_access'];
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
