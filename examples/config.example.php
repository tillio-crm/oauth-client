<?php

/**
 * Skopiuj ten plik do examples/config.php i wstaw swoje credentials.
 * Plik examples/config.php jest w .gitignore — NIE commit'uj go.
 */

declare(strict_types=1);

return [
    'clientId'     => 'YOUR-CLIENT-ID',
    'clientSecret' => 'YOUR-CLIENT-SECRET',
    'redirectUri'  => 'http://localhost:8000/callback.php',

    // === Produkcja ===
    // Domyślnie biblioteka używa https://auth.tillio.app — nic nie trzeba ustawiać.

    // === Development lokalny bez Dockera ===
    // 'server' => 'http://localhost:8080',

    // === Development w Dockerze ===
    // Browser użytkownika idzie na `localhost` (authorize redirect),
    // a kontener woła API po `host.docker.internal` (token, user, profile, revoke).
    // 'server'         => 'http://localhost:8080',
    // 'internalServer' => 'http://host.docker.internal:8080',

    // === Inne opcje ===
    // 'scopes'  => ['profile', 'email', 'openid', 'offline_access'],
    // 'usePkce' => true,   // domyślnie true (S256); ustaw false tylko w wyjątkach
];
