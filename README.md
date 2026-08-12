# tillio-crm/oauth-client

Oficjalny klient PHP OAuth2 dla logowania przez **Tillio CRM**.

Pozwala dowolnej zewnętrznej aplikacji PHP zalogować użytkownika przy użyciu
konta w Tillio CRM (Single Sign-On). Wystarczą `client_id` i `client_secret`,
które developer otrzymuje z panelu Tillio.

[![Latest Version](https://img.shields.io/packagist/v/tillio-crm/oauth-client.svg)](https://packagist.org/packages/tillio-crm/oauth-client)
[![Total Downloads](https://img.shields.io/packagist/dt/tillio-crm/oauth-client.svg)](https://packagist.org/packages/tillio-crm/oauth-client)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Flow: **OAuth 2.0 Authorization Code + PKCE (S256)** na bazie
[`league/oauth2-client`](https://oauth2-client.thephpleague.com/).

---

## Spis treści

- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Szybki start](#szybki-start)
- [Konfiguracja](#konfiguracja)
  - [Development w Dockerze](#development-w-dockerze)
- [Własny storage sesji](#wlasny-storage-sesji)
- [API](#api)
  - [Klasa `Client`](#klasa-client)
  - [Klasa `TillioResourceOwner`](#klasa-tillioresourceowner)
  - [Wyjątki](#wyjatki)
- [Bezpieczeństwo](#bezpieczenstwo)
- [Testy](#testy)
- [Wersjonowanie](#wersjonowanie)
- [Troubleshooting](#troubleshooting)
- [Zgłaszanie błędów / pull requesty](#zglaszanie-bledow--pull-requesty)
- [Credits](#credits)
- [Licencja](#licencja)

---

## Wymagania

- PHP `^8.3`
- rozszerzenia: `ext-curl`, `ext-json`
- Composer

## Instalacja

```bash
composer require tillio-crm/oauth-client
```

## Szybki start

Minimalna aplikacja: `index.php`, `login.php`, `callback.php`. Redirect URI
musi być **identyczny** jak zarejestrowany w panelu Tillio.

```php
<?php
// index.php
require __DIR__ . '/vendor/autoload.php';
session_start();

$client = new TillioCrm\OAuth\Client\Client([
    'clientId'     => 'YOUR-CLIENT-ID',
    'clientSecret' => 'YOUR-CLIENT-SECRET',
    'redirectUri'  => 'https://app.example.com/callback.php',
]);

if (isset($_GET['logout'])) {
    $client->logout();
    header('Location: /');
    exit;
}

if (!$client->isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$user = $client->user();
echo "Witaj, " . htmlspecialchars($user->getName() ?? $user->getEmail() ?? 'użytkowniku') . '!';
```

```php
<?php
// login.php
require __DIR__ . '/vendor/autoload.php';
session_start();

$client = new TillioCrm\OAuth\Client\Client([/* ... jak wyżej */]);
$client->redirectToLogin();
```

```php
<?php
// callback.php
require __DIR__ . '/vendor/autoload.php';
session_start();

$client = new TillioCrm\OAuth\Client\Client([/* ... jak wyżej */]);

try {
    $client->handleCallback();
    header('Location: /');
    exit;
} catch (TillioCrm\OAuth\Client\Exception\TillioOAuthException $e) {
    http_response_code(400);
    echo 'Błąd logowania: ' . htmlspecialchars($e->getMessage());
}
```

Pełna działająca wersja z Dockerem → [`examples/`](examples/).

> **Integrujesz przez AI (Claude / Cursor / Copilot)?**
> Wklej swojemu asystentowi [`docs/AI_INTEGRATION.md`](docs/AI_INTEGRATION.md) —
> ma tam gotowy prompt, skeleton 3 stron, adaptery do Symfony/Laravel i
> checklist pułapek.

## Konfiguracja

| Klucz           | Wymagany | Domyślna wartość                                | Opis                                                              |
|-----------------|----------|-------------------------------------------------|-------------------------------------------------------------------|
| `clientId`      | tak      | —                                               | ID klienta z panelu Tillio.                                       |
| `clientSecret`  | tak      | —                                               | Secret klienta.                                                   |
| `redirectUri`   | tak      | —                                               | URL, pod który Tillio odeśle użytkownika po logowaniu.            |
| `server`        | nie      | `https://auth.tillio.app`                       | Base URL serwera — używany do redirectu przeglądarki (authorize). |
| `internalServer`| nie      | wartość `server`                                | Base URL dla wywołań server-to-server (token/user/profile/revoke).|
| `scopes`        | nie      | `['profile','email','openid','offline_access']` | Zakres uprawnień — patrz [Scope'y](#scopey).                      |
| `usePkce`       | nie      | `true`                                          | PKCE (RFC 7636, S256). Wyłącz tylko na żądanie.                   |

### Scope'y

**Serwer Tillio gate'uje dane po scope** — sekcja pojawia się w odpowiedzi
`/api/v1/auth/user` i `/api/v1/auth/user/profile` **tylko** gdy token ma
odpowiedni scope. Domyślne scope'y są minimalne, więc np. `workspace` nie
wróci, dopóki go nie zażądasz.

| Scope             | Stała `TillioProvider::`  | Co odblokowuje                                        |
|-------------------|---------------------------|-------------------------------------------------------|
| `openid`          | `SCOPE_OPENID`            | Identyfikator OIDC.                                    |
| `profile`         | `SCOPE_PROFILE`           | `first_name`, `last_name`, `avatar_url`, `post`, `settings` |
| `email`           | `SCOPE_EMAIL`             | `email` (adres logowania).                             |
| `offline_access`  | `SCOPE_OFFLINE_ACCESS`    | Refresh token (sesja bez ponownego logowania).         |
| `workspace`       | `SCOPE_WORKSPACE`         | Sekcja `workspace` (id, slug, nazwa, domena, logo).    |
| `acl`             | `SCOPE_ACL`               | `acl`, `is_workspace_superadmin`, `role_ids`.          |
| `organization`    | `SCOPE_ORGANIZATION`      | `organization` — dane rejestrowe firmy (nazwa, NIP, adres). Wymaga uprawnień w Tillio; bez nich `null`. |
| `profile_contact` | `SCOPE_PROFILE_CONTACT`   | `profile_contact` — telefon + e-mail kontaktowy.       |
| `tillio_client`   | `SCOPE_TILLIO_CLIENT`     | Zautomatyzowane działania na koncie w imieniu użytkownika. |
| `app_storage`     | `SCOPE_APP_STORAGE`       | Storage proxy apki: dokumenty (`/api/v1/apps/storage/*`) i pliki (`/api/v1/apps/files/*`). Prywatna przestrzeń apki per workspace — bez dostępu do danych CRM ani innych apek. |
| `install_app`     | `SCOPE_INSTALL_APP`       | Zgoda na **instalację apki** w workspace (flow onboardingowy). Token z tym scope pozwala wywołać `POST /api/v1/apps/install`. Przyznawany tylko klientowi onboardingowemu i wymaga, by user był administratorem workspace. |

`tillio_id` jest zwracane zawsze (identyfikator konta, odpowiednik `sub`).

`app_storage` dotyczy tylko klientów będących **apkami marketplace** (klient ze
slugiem) i działa wyłącznie w workspace'ach z **aktywną instalacją** apki —
token ze scope'em, ale bez instalacji, dostanie `401 access_denied` na
endpointach storage.

`install_app` obsługuje **onboarding apki**: klient onboardingowy loguje usera
i prosi o ten scope, a otrzymany token przekazuje do `POST /api/v1/apps/install`
(instalacja apki w workspace usera). Serwer wymaga, by token niósł `install_app`,
nie był zrewokowany, a user był administratorem workspace. Bez tego scope
instalacja jest odrzucana (`403`).

```php
use TillioCrm\OAuth\Client\TillioProvider;

$client->redirectToLogin([
    TillioProvider::SCOPE_OPENID,
    TillioProvider::SCOPE_PROFILE,
    TillioProvider::SCOPE_EMAIL,
    TillioProvider::SCOPE_WORKSPACE,
    TillioProvider::SCOPE_ACL,
]);
```

### Development w Dockerze

Jeśli API OAuth działa lokalnie na hoście (np. `localhost:8080`), a Twoja
aplikacja PHP jest w kontenerze, browser i kontener widzą ten serwer pod
**różnymi adresami**:

```php
return [
    // ...
    'server'         => 'http://localhost:8080',            // browser → authorize
    'internalServer' => 'http://host.docker.internal:8080', // kontener → token/user/...
];
```

## Własny storage sesji

Domyślnie biblioteka zapisuje stan w superglobali `$_SESSION` (klasa
`NativeSessionStorage`). Aby używać np. sesji Symfony/Laravel albo Redis,
zaimplementuj `SessionStorageInterface`:

```php
use TillioCrm\OAuth\Client\Session\SessionStorageInterface;

final class RedisSession implements SessionStorageInterface {
    public function get(string $key, mixed $default = null): mixed { /* ... */ }
    public function set(string $key, mixed $value): void          { /* ... */ }
    public function has(string $key): bool                         { /* ... */ }
    public function remove(string $key): void                      { /* ... */ }
    public function clear(): void                                  { /* ... */ }
    public function regenerate(): void                             { /* ... */ }
}

$client = new TillioCrm\OAuth\Client\Client($config, new RedisSession());
```

Metoda `regenerate()` powinna rotować identyfikator sesji (ochrona przed
session fixation). Jeśli Twój storage tego nie potrzebuje (np. identyfikator
jest po stronie aplikacji), zostaw pustą implementację.

## API

### Klasa `Client`

| Metoda                                         | Opis                                                                                      |
|------------------------------------------------|-------------------------------------------------------------------------------------------|
| `redirectToLogin(array $scopes = [])`          | Wysyła `302` do Tillio. Zapisuje `state` (i `pkce_verifier`) w sesji. Kończy skrypt.      |
| `getAuthorizationUrl(array $scopes = [])`      | Zwraca URL logowania bez redirectu (np. do AJAX-u).                                       |
| `handleCallback(): TillioResourceOwner`        | Weryfikuje `state`, wymienia `code` na token, rotuje ID sesji, pobiera dane użytkownika. |
| `isAuthenticated(): bool`                      | Czy w sesji jest ważny (lub możliwy do odświeżenia) token.                                |
| `user(): TillioResourceOwner`                  | Podstawowe dane z `/api/v1/auth/user`. Cache w sesji, auto-refresh tokena.                |
| `profile(): array`                             | Rozszerzone dane z `/api/v1/auth/user/profile`. Zawsze hit do serwera.                    |
| `accessToken(): string`                        | Zwraca ważny access token (auto-refresh, jeśli wygasa).                                   |
| `refreshUser(): TillioResourceOwner`           | Wymusza ponowne pobranie danych użytkownika z serwera.                                    |
| `logout(): void`                               | Kończy sesję OAuth na serwerze (`/api/v1/auth/logout`) i czyści sesję lokalną.            |
| `endSessionUrl(?string $redirect, ?string $state): string` | URL przeglądarkowego wylogowania OIDC dla tej aplikacji.                     |
| `getProvider(): TillioProvider`                | Dostęp do bazowego providera `league/oauth2-client` (escape hatch).                      |

### Klasa `TillioResourceOwner`

| Metoda              | Zwraca    | Źródłowe pole             |
|---------------------|-----------|---------------------------|
| `getId()`           | `?string` | `id`                      |
| `getPublicId()`     | `?string` | `public_id`               |
| `getTillioId()`     | `?string` | `tillio_id`               |
| `getFirstName()`    | `?string` | `first_name`              |
| `getLastName()`     | `?string` | `last_name`               |
| `getName()`         | `?string` | `first_name + last_name`  |
| `getEmail()`        | `?string` | `email`                   |
| `getAvatarUrl()`    | `?string` | `avatar_url`              |
| `getWorkspace()`    | `?array`  | `workspace` (scope `workspace`) |
| `toArray()`         | `array`   | surowa odpowiedź serwera  |

### Klasa `TillioProfile`

Typowany wrapper na `Client::profile()`. `profile()` nadal zwraca surowy
`array` — `TillioProfile` owija go, żeby nie grzebać po kluczach:

```php
use TillioCrm\OAuth\Client\TillioProfile;

$profile = new TillioProfile($client->profile());

if ($profile->isWorkspaceSuperAdmin()) {
    $nip = $profile->getOrganization()['tax_id'] ?? null;
}
```

Gettery zwracają `null` / `[]` / `false`, gdy sekcji nie ma w odpowiedzi
(bo scope nie został przyznany albo user nie ma uprawnień).

| Metoda                      | Zwraca    | Wymagany scope    |
|-----------------------------|-----------|-------------------|
| `getTillioId()`             | `?string` | — (zawsze)        |
| `getFirstName()`            | `?string` | `profile`         |
| `getLastName()`             | `?string` | `profile`         |
| `getName()`                 | `?string` | `profile`         |
| `getPost()`                 | `?string` | `profile`         |
| `getAvatarUrl()`            | `?string` | `profile`         |
| `getSettings()`             | `array`   | `profile`         |
| `getEmail()`                | `?string` | `email`           |
| `getWorkspace()`            | `?array`  | `workspace`       |
| `getAcl()`                  | `array`   | `acl`             |
| `isWorkspaceSuperAdmin()`   | `bool`    | `acl`             |
| `getRoleIds()`              | `list<int>` | `acl`           |
| `getOrganization()`         | `?array`  | `organization`    |
| `getContact()`              | `?array`  | `profile_contact` |
| `toArray()`                 | `array`   | surowa odpowiedź  |

## Wylogowanie

Serwer Tillio ma **trzy** różne operacje — łatwo je pomylić:

| Endpoint                  | Co robi                                                                                     | Kiedy używać |
|---------------------------|---------------------------------------------------------------------------------------------|--------------|
| `POST /api/v1/auth/revoke`| Rewokuje **tylko** przekazany token. Refresh token dalej działa, sesja OAuth zostaje.        | Rzadko — punktowe unieważnienie jednego tokena. |
| `POST /api/v1/auth/logout`| Rewokuje access + **wszystkie** refresh tokeny pary (user, client) i **kasuje sesję OAuth**. | Normalne wylogowanie z aplikacji. **Używa tego `Client::logout()`.** |
| `GET /auth/logout`        | OIDC end_session — wylogowanie **z przeglądarki**, per aplikacja. Nie rusza sesji SSO Tillio.| Gdy chcesz domknąć logout także po stronie Tillio. |

```php
$client->logout();                     // rewokuje tokeny + kasuje sesję OAuth + czyści sesję lokalną

// Opcjonalnie: domknij logout w przeglądarce
header('Location: ' . $client->endSessionUrl('https://twoja-app.example/wylogowano'));
exit;
```

**Uwaga:** `post_logout_redirect_uri` jest walidowane po **originie** (scheme + host +
port) względem URI zarejestrowanych dla klienta. Niepasujący adres → strona błędu
po stronie Tillio.

`logout()` **nie** wylogowuje użytkownika z innych aplikacji — sesja SSO Tillio
zostaje nietknięta. To celowe: logout jest per-aplikacja.

### Wyjątki

Wszystkie wyjątki biblioteki dziedziczą po `TillioOAuthException`, więc
łapiąc klasę bazową złapiesz każdy błąd.

| Wyjątek                          | Kiedy rzucany                                                    |
|----------------------------------|------------------------------------------------------------------|
| `TillioOAuthException`           | Bazowy. Rzucany m.in. przy błędnej konfiguracji i błędach HTTP.  |
| `InvalidStateException`          | `state` z callbacku nie pasuje do zapisanego w sesji (CSRF).     |
| `AuthorizationDeniedException`   | Użytkownik kliknął „Odmów" na ekranie logowania Tillio.          |
| `NotAuthenticatedException`      | Brak tokena albo refresh token wygasł / został odwołany.         |

Przykład łapania konkretnych przypadków:

```php
use TillioCrm\OAuth\Client\Exception;

try {
    $client->handleCallback();
} catch (Exception\AuthorizationDeniedException) {
    // user kliknął "Odmów"
} catch (Exception\InvalidStateException) {
    // CSRF — restart flow
} catch (Exception\TillioOAuthException $e) {
    // wszystko inne
}
```

## Bezpieczeństwo

- **PKCE (S256)** włączone domyślnie — chroni kod autoryzacyjny przed przechwyceniem (RFC 7636).
- `state` (CSRF) jest generowane i weryfikowane automatycznie (`hash_equals`).
- **Session fixation** — po udanym logowaniu ID sesji jest rotowane (`session_regenerate_id(true)`).
- `refresh_token` nigdy nie opuszcza sesji/storage'u.
- `logout()` woła endpoint `/api/v1/auth/revoke` i dopiero czyści sesję.
- Access token jest odświeżany 60 s przed wygaśnięciem.

**Po Twojej stronie zadbaj o:**
- HTTPS w produkcji.
- Bezpieczne flagi cookie sesji — przed `session_start()`:
  ```php
  session_set_cookie_params([
      'secure'   => true,   // tylko HTTPS
      'httponly' => true,   // niedostępne z JS
      'samesite' => 'Lax',
  ]);
  ```

Jeśli znalazłeś lukę bezpieczeństwa, **nie zgłaszaj jej publicznie**. Napisz
na `security@tillio.app`.

## Testy

```bash
composer install
./vendor/bin/phpunit
```

## Wersjonowanie

Pakiet stosuje [SemVer 2.0](https://semver.org/lang/pl/). Do osiągnięcia
`1.0.0` API może ulec zmianom między wersjami `0.x`. Od `1.0.0` breaking
changes będą wprowadzane wyłącznie w major.

## Troubleshooting

**`redirect_uri mismatch`**
Adres w panelu Tillio musi być **identyczny** jak `redirectUri` w konfigu —
protokół, host, port, ścieżka, końcowy slash.

**`OAuth2 state mismatch. Possible CSRF attempt.`**
Sesja PHP zgubiła się między requestami. Sprawdź `session_start()`, domenę
cookie, proxy, ustawienia `session.cookie_*`. W trybie produkcyjnym wymagane
jest `secure` + HTTPS — na `http://` w innej przeglądarce cookie może zostać
odrzucone.

**`Failed to exchange authorization code`**
Zwykle: `clientSecret` niezgodny z `clientId`, albo redirect URI rejestrowane
inaczej. Rzadziej: `code` już wymieniony (kody są jednorazowe).

**Docker: `Connection refused` przy wymianie tokena**
Kontener nie widzi `localhost`. Ustaw `internalServer` na
`http://host.docker.internal:8080` (patrz [Development w Dockerze](#development-w-dockerze)).

## Zgłaszanie błędów / pull requesty

Issues i PR-y: [github.com/tillio-crm/oauth-client/issues](https://github.com/tillio-crm/oauth-client/issues).

Rozwój lokalny:

```bash
git clone https://github.com/tillio-crm/oauth-client.git
cd oauth-client
composer install
./vendor/bin/phpunit
```

## Credits

Pod spodem korzysta z [`league/oauth2-client`](https://oauth2-client.thephpleague.com/)
(MIT), który obsługuje niskopoziomowe OAuth 2.0 flow.

## Licencja

MIT. Zobacz plik [LICENSE](LICENSE). Każdy może używać, modyfikować
i rozpowszechniać — także komercyjnie.
