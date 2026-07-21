# AI integration guide — `tillio-crm/oauth-client`

Ten dokument jest napisany tak, żebyś mógł go **wkleić do swojego AI asystenta**
(Claude, Cursor, Copilot Chat, Windsurf itd.) razem z opisem własnej aplikacji —
a asystent poprawnie zintegruje logowanie przez Tillio CRM.

Plik jest celowo gęsty: zero prozy, dużo konkretów i gotowych snippetów.

---

## 1. Copy‑paste prompt do AI

> Zintegruj logowanie przez Tillio CRM używając pakietu Composer
> `tillio-crm/oauth-client` (PHP `^8.3`). Wymagania:
>
> - Flow: OAuth 2.0 Authorization Code + PKCE (S256) — biblioteka robi to sama.
> - 3 endpointy/strony w moim aplikacji: `/login` (start), `/callback`
>   (odbiór kodu), `/` (strona główna z chronionym contentem).
> - Użyj klasy `TillioCrm\OAuth\Client\Client` z natywnej sesji PHP
>   (`$_SESSION`) **chyba że** mój framework (Symfony/Laravel/inny) ma własną
>   sesję — wtedy zaimplementuj `SessionStorageInterface` jako adapter.
> - `clientId` / `clientSecret` / `redirectUri` czytaj z ENV, nie hardkoduj.
> - Po callback: przekieruj usera na stronę, z której przyszedł (jeśli to
>   trackujesz), inaczej na `/`.
> - Po logout: wywołaj `$client->logout()` — kończy sesję OAuth na serwerze
>   (rewokuje access + wszystkie refresh tokeny, kasuje sesję OAuth) i czyści
>   sesję lokalną. Potem przekieruj na `/`, albo na `$client->endSessionUrl(...)`
>   jeśli logout ma się domknąć również w przeglądarce.
> - Catch‑uj `AuthorizationDeniedException` osobno (user kliknął „Odmów",
>   pokaż mu przyjazny komunikat), resztę jako generyczny błąd 400/500.
> - Upewnij się, że `session_start()` jest wołane **przed** utworzeniem
>   `Client` i że cookie sesji ma flagi `secure`, `httponly`, `samesite=Lax`.

---

## 2. Minimalny skeleton (framework‑agnostic)

### 2.1. Config z ENV

```php
// config/tillio.php
return [
    'clientId'     => $_ENV['TILLIO_CLIENT_ID']     ?? throw new RuntimeException('TILLIO_CLIENT_ID missing'),
    'clientSecret' => $_ENV['TILLIO_CLIENT_SECRET'] ?? throw new RuntimeException('TILLIO_CLIENT_SECRET missing'),
    'redirectUri'  => $_ENV['TILLIO_REDIRECT_URI']  ?? throw new RuntimeException('TILLIO_REDIRECT_URI missing'),
    // 'server'         => $_ENV['TILLIO_SERVER']          ?? null, // domyślnie https://auth.tillio.app
    // 'internalServer' => $_ENV['TILLIO_INTERNAL_SERVER'] ?? null, // docker dev
];
```

### 2.2. Bootstrap

```php
// src/bootstrap.php
use TillioCrm\OAuth\Client\Client;

require __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'secure'   => !str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$config = require __DIR__ . '/../config/tillio.php';

return new Client($config);
```

### 2.3. Trzy strony

**`/login`:**
```php
/** @var \TillioCrm\OAuth\Client\Client $client */
$client = require __DIR__ . '/../src/bootstrap.php';

// Opcjonalnie: zapamiętaj gdzie user chciał pójść.
$_SESSION['return_to'] = $_GET['return_to'] ?? '/';

$client->redirectToLogin();
```

**`/callback`:**
```php
use TillioCrm\OAuth\Client\Exception\AuthorizationDeniedException;
use TillioCrm\OAuth\Client\Exception\TillioOAuthException;

/** @var \TillioCrm\OAuth\Client\Client $client */
$client = require __DIR__ . '/../src/bootstrap.php';

try {
    $client->handleCallback();

    $returnTo = $_SESSION['return_to'] ?? '/';
    unset($_SESSION['return_to']);
    header('Location: ' . $returnTo);
    exit;

} catch (AuthorizationDeniedException $e) {
    http_response_code(403);
    echo 'Logowanie anulowane. <a href="/">Wróć</a>.';

} catch (TillioOAuthException $e) {
    http_response_code(400);
    echo 'Nie udało się zalogować. Spróbuj ponownie.';
    error_log('Tillio OAuth error: ' . $e->getMessage());
}
```

**`/` (strona główna / middleware auth):**
```php
/** @var \TillioCrm\OAuth\Client\Client $client */
$client = require __DIR__ . '/../src/bootstrap.php';

if (isset($_GET['logout'])) {
    $client->logout(); // rewokuje tokeny + kasuje sesję OAuth + czyści sesję lokalną

    // Wariant A: zostajemy w aplikacji.
    header('Location: /');

    // Wariant B: domknij logout też po stronie Tillio (przeglądarkowo):
    // header('Location: ' . $client->endSessionUrl('https://twoja-app.example/'));

    exit;
}

if (!$client->isAuthenticated()) {
    header('Location: /login');
    exit;
}

$user = $client->user();
echo 'Zalogowany: ' . htmlspecialchars($user->getEmail() ?? '—');
```

---

## 3. Checklist pułapek (AI — przejdź po tym przed „done")

- [ ] `session_start()` wywołane **przed** `new Client(...)`.
- [ ] Ten sam `redirectUri` w konfigu i zarejestrowany w panelu Tillio
      (identyczny — protokół, host, port, ścieżka, slash na końcu).
- [ ] Sekrety (`clientSecret`) w ENV, **nie** w repo.
- [ ] HTTPS w produkcji + `secure` cookie.
- [ ] `handleCallback()` **opakowane w try/catch** z osobnym handlingiem
      `AuthorizationDeniedException`.
- [ ] `logout()` NIE czyści sesji ręcznie — biblioteka to robi.
- [ ] Przy chronieniu route'ów używaj `isAuthenticated()` przed
      `user()` — `user()` rzuca `NotAuthenticatedException` jeśli nie ma tokena.
- [ ] Access token auto‑refreshuje się sam w `user()`/`accessToken()` —
      nie pisz własnej logiki refresh.
- [ ] Docker dev: jeśli Tillio działa lokalnie na hoście, ustaw
      `internalServer` na `http://host.docker.internal:...`.

---

## 4. Wzorce frameworkowe

### 4.1. Symfony — adapter sesji

```php
use Symfony\Component\HttpFoundation\RequestStack;
use TillioCrm\OAuth\Client\Session\SessionStorageInterface;

final class SymfonyTillioSession implements SessionStorageInterface
{
    public function __construct(private RequestStack $requests) {}

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        return $this->requests->getSession();
    }

    public function get(string $key, mixed $default = null): mixed { return $this->session()->get('tillio_' . $key, $default); }
    public function set(string $key, mixed $value): void          { $this->session()->set('tillio_' . $key, $value); }
    public function has(string $key): bool                         { return $this->session()->has('tillio_' . $key); }
    public function remove(string $key): void                      { $this->session()->remove('tillio_' . $key); }
    public function clear(): void                                  {
        foreach (['tokens','state','pkce_verifier','user'] as $k) { $this->session()->remove('tillio_' . $k); }
    }
    public function regenerate(): void                             { $this->session()->migrate(true); }
}
```

### 4.2. Laravel — adapter sesji

```php
use Illuminate\Contracts\Session\Session;
use TillioCrm\OAuth\Client\Session\SessionStorageInterface;

final class LaravelTillioSession implements SessionStorageInterface
{
    public function __construct(private Session $session) {}

    public function get(string $key, mixed $default = null): mixed { return $this->session->get('tillio.' . $key, $default); }
    public function set(string $key, mixed $value): void          { $this->session->put('tillio.' . $key, $value); }
    public function has(string $key): bool                         { return $this->session->has('tillio.' . $key); }
    public function remove(string $key): void                      { $this->session->forget('tillio.' . $key); }
    public function clear(): void                                  { $this->session->forget('tillio'); }
    public function regenerate(): void                             { $this->session->regenerate(true); }
}
```

---

## 5. Shape danych zwracanych przez bibliotekę

> ⚠️ **Serwer gate'uje dane po scope.** Sekcja jest w odpowiedzi tylko gdy token
> ma odpowiedni scope. Domyślne scope'y (`profile`, `email`, `openid`,
> `offline_access`) **nie** zawierają `workspace` ani `acl` — jeśli ich
> potrzebujesz, zażądaj ich jawnie przy logowaniu. Zawsze wracaj z fallbackiem
> na `null` / `[]`.

**`$client->user()` → `TillioResourceOwner`:**
```
getId()        → string (numeryczny ID)
getPublicId()  → string (stabilny zewnętrzny id, np. "lukg0r")
getTillioId()  → string
getFirstName() → string
getLastName()  → string
getName()      → string (first + last)
getEmail()     → string
getAvatarUrl() → string (URL)
getWorkspace() → ?array  (scope `workspace`)
toArray()      → array (raw)
```

**`$client->profile()` → `array`** — rozszerzone dane z
`/api/v1/auth/user/profile`. Zamiast grzebać po kluczach, owiń w `TillioProfile`:

```php
use TillioCrm\OAuth\Client\TillioProfile;

$profile = new TillioProfile($client->profile());

$profile->getTillioId();           // zawsze
$profile->getName();               // scope profile
$profile->getEmail();              // scope email
$profile->getWorkspace();          // scope workspace       → ?array
$profile->getAcl();                // scope acl             → array
$profile->isWorkspaceSuperAdmin(); // scope acl             → bool
$profile->getRoleIds();            // scope acl             → list<int>
$profile->getOrganization();       // scope organization    → ?array (nazwa, tax_id/NIP, adres)
$profile->getContact();            // scope profile_contact → ?array (phone, email)
```

**Scope'y** — używaj stałych `TillioProvider::SCOPE_*` zamiast stringów:

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

`organization` wymaga uprawnień po stronie Tillio — gdy user ich nie ma,
`getOrganization()` zwróci `null` mimo przyznanego scope. To poprawny stan,
nie błąd.

---

## 6. Hierarchia wyjątków

```
TillioOAuthException              # bazowy — łap go jako fallback
├─ InvalidStateException          # CSRF mismatch — restart flow
├─ AuthorizationDeniedException   # user kliknął "Odmów"
└─ NotAuthenticatedException      # brak/wygasły token, refresh padł
```

Łap od najbardziej konkretnego do bazowego.

---

## 7. Częste błędy AI (unikaj)

- ❌ **Ręczne generowanie `state` albo PKCE** — biblioteka to robi sama
  w `getAuthorizationUrl()`.
- ❌ **Ręczne wołanie `/api/v1/auth/token`** — od tego jest `handleCallback()`.
- ❌ **Sprawdzanie `expires` tokena w kodzie usera** — auto‑refresh siedzi
  w `accessToken()`/`user()`.
- ❌ **Cache'owanie `profile()` w sesji na zawsze** — biblioteka celowo hit‑uje
  serwer; jeśli chcesz cache, rób TTL po swojej stronie.
- ❌ **Zakładanie, że `workspace` / `acl` / `organization` zawsze są w odpowiedzi** —
  są gate'owane po scope. Brak sekcji to nie błąd serwera, tylko nieprzyznany
  scope (albo brak uprawnień usera przy `organization`). Zażądaj scope przy
  logowaniu i tak czy siak koduj z fallbackiem.
- ❌ **Ręczne wołanie `/api/v1/auth/revoke` jako „wylogowania"** — `revoke`
  (RFC 7009) unieważnia **tylko** przekazany token; refresh token dalej działa
  i sesja OAuth zostaje, więc user potrafi „wrócić" zalogowany. Do wylogowania
  służy `$client->logout()` (`/api/v1/auth/logout`).
- ❌ **Wywoływanie `session_destroy()` po `logout()`** — biblioteka już
  wyczyściła swoją część sesji, reszta (twoje appowe dane) zostaje nietknięta —
  **to feature**, nie bug.
- ❌ **`redirectUri` budowane dynamicznie z `$_SERVER['HTTP_HOST']`** —
  musi być stałe i zarejestrowane w panelu Tillio.

---

## 8. Przykład minimalny do pokazania użytkownikowi

Po integracji ma się dać klikalnie przetestować:

1. Wejście na `/` jako niezalogowany → redirect na `/login`.
2. `/login` → redirect na `auth.tillio.app/auth/login?...`.
3. User loguje się w Tillio → wraca na `/callback?code=...&state=...`.
4. `/callback` wymienia kod na token, zapisuje usera w sesji, redirect na `/`.
5. `/` pokazuje email + avatar zalogowanego.
6. Klik „Wyloguj" → `/callback?logout=1` lub `/?logout=1` → sesja OAuth
   skasowana na serwerze + sesja lokalna wyczyszczona, redirect na `/`.
7. Ponowne wejście na `/` → znowu redirect na `/login` (refresh token też został
   unieważniony, więc nie ma cichego wskrzeszenia sesji).

Jeśli coś z powyższych nie działa, idź po checklist z sekcji 3.
