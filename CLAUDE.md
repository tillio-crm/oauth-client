# Instrukcje dla AI (kontrybutor biblioteki)

Ten plik czytają Claude Code / Cursor / Copilot, gdy edytują **tę bibliotekę**.
Konsumenci pakietu — `docs/AI_INTEGRATION.md`.

## Czym jest ten projekt

Publiczna biblioteka Composer: **`tillio-crm/oauth-client`**. Klient OAuth2 do
logowania użytkownika w dowolnej aplikacji PHP przez konto w Tillio CRM.

Flow: OAuth 2.0 Authorization Code + PKCE (S256). Pod spodem
[`league/oauth2-client`](https://oauth2-client.thephpleague.com/) — nie
wymyślamy OAuth od zera.

## Architektura (3 warstwy)

```
Client               ← fasada dla konsumenta (redirectToLogin/handleCallback/user/...)
  │
  ├─ TillioProvider  ← rozszerzenie League\AbstractProvider, stałe endpointy Tillio
  │
  └─ SessionStorageInterface
       ├─ NativeSessionStorage (default, $_SESSION)
       └─ implementacje usera (Redis, Symfony, Laravel, ...)
```

Klasy pomocnicze: `TillioResourceOwner` (user model), `Exception/*` (hierarchia
błędów — wszystkie dziedziczą po `TillioOAuthException`).

## Granice publicznego API

**Stabilne (breaking change = major bump):**
- publiczne metody `Client`, `TillioProvider`, `TillioResourceOwner`
- `SessionStorageInterface`
- kształt configu przekazywanego do `Client::__construct`
- klasy wyjątków (nazwy + hierarchia)

**Wewnętrzne (można zmieniać):**
- private/protected metody
- klucze sesji (`KEY_TOKENS`, `KEY_STATE`, itd.)
- szczegóły PKCE storage

## Konwencje kodu

- **PHP ^8.3** — używaj `readonly`, typed constants (`const string/int`),
  `never` return type, `declare(strict_types=1)` w każdym pliku.
- **Brak komentarzy WHAT** — nazwy mówią. Komentarz tylko gdy WHY jest
  nieoczywiste (np. „Prevent session fixation" przed `regenerate()`).
- **`final class` domyślnie** dla nowych klas (library code, nie zachęcaj do
  dziedziczenia — lepszy DI).
- **Wyjątki biblioteki** rzucaj przez `TillioOAuthException` lub podklasy.
  Nigdy nie rzucaj gołego `RuntimeException`/`Exception`.
- **Nigdy nie loguj tokenów / secretów.** `clientSecret` trafia tylko do body
  POST przy revoke.

## Zależności

- `php ^8.3`, `ext-curl`, `ext-json`
- `league/oauth2-client ^2.7`
- **Dev:** `phpunit/phpunit ^11`

**Nie dodawaj nowych zależności bez bardzo dobrego powodu.** Biblioteka
auth powinna być szczupła — każda zależność to potencjalny supply chain
vector. Jeśli musisz, upewnij się że to PSR (`psr/http-client`,
`psr/log` itd.), a nie konkretna implementacja.

## Testy

```bash
composer install
./vendor/bin/phpunit
```

Struktura:
- `tests/TillioResourceOwnerTest.php` — pure unit, modelek usera
- `tests/TillioProviderTest.php` — URL routing, PKCE, scopes (bez HTTP)
- `tests/ClientTest.php` — config validation, callback flow, session state
- `tests/InMemorySessionStorage.php` — test helper implementujący `SessionStorageInterface`

**Zasady pisania testów:**
- Każda nowa metoda publiczna `Client` → minimum 1 test.
- Nie mockujemy HTTP (jeszcze) — testuj to, co testowalne bez sieci.
  Dla testów HTTP użyj `GuzzleHttp\Handler\MockHandler` + `httpClient`
  collaborator w `TillioProvider`.
- Żadnego `session_start()` w testach — używaj `InMemorySessionStorage`.
- Resetuj `$_GET = []` w `setUp()` gdy test dotyka callbacku.

## Bezpieczeństwo — must‑have

Każda zmiana w `Client::handleCallback`, `refresh`, `logout`, `buildAccessToken`
wymaga rzutu okiem pod kątem:

- CSRF: czy `state` jest weryfikowany z `hash_equals`?
- Session fixation: czy `regenerate()` wołane po udanej wymianie kodu?
- PKCE verifier: czy czyszczony z sesji po użyciu?
- Refresh rotation: czy nowy refresh token nadpisuje stary, a brak nowego
  zachowuje stary (nie gubimy sesji)?
- Revoke: czy wołany PRZED `session->clear()` w `logout()`?

Przy zmianach w tych miejscach — zaktualizuj odpowiedni test.

## Kompatybilność

- **PHP** — tylko `^8.3`. Nie robimy polyfillów dla starszych wersji.
- **Framework‑agnostic** — żadnego importu Symfony/Laravel w `src/`.
  Integracje idą przez `SessionStorageInterface` i ewentualne adaptery
  (w przyszłości jako osobne pakiety, jeśli będą potrzebne).

## Publiczne URL‑e serwera Tillio

Stałe w `TillioProvider`:

- `/auth/login` — authorize (browser redirect)
- `/api/v1/auth/token` — token exchange + refresh (backend)
- `/api/v1/auth/user` — basic user info (backend)
- `/api/v1/auth/user/profile` — extended user profile (backend)
- `/api/v1/auth/revoke` — token revocation (backend)

Produkcyjny host: `https://auth.tillio.app` (stała `TillioProvider::DEFAULT_SERVER`).

Rozdział `server` / `internalServer` — browser vs backend. Nie zmieniaj tego
rozdziału bez rozmowy z maintainerem — to jest ważny feature dla Dockera.

## Czego NIE robić

- ❌ Nie dodawaj zależności bez dyskusji.
- ❌ Nie zmieniaj publicznego API w patch/minor.
- ❌ Nie dodawaj metod „pod konkretny framework" do `Client` — od tego jest
  `SessionStorageInterface` i `getProvider()` escape hatch.
- ❌ Nie loguj/nie wypisuj tokenów, secretów, ciała request/response przy
  błędach.
- ❌ Nie łap `\Throwable` bez potrzeby (oprócz `logout()` gdzie jest to
  zamierzone).
- ❌ Nie rób `session_start()` w kodzie biblioteki — to odpowiedzialność
  konsumenta. `NativeSessionStorage` tylko weryfikuje status sesji.

## Pre‑commit checklist

Po zmianach w `src/`:

1. `php -l` na zmienionych plikach (lub `find src -name '*.php' -exec php -l {} \;`)
2. `./vendor/bin/phpunit` — zielone
3. Czy zaktualizowałem `README.md` (API/konfig), jeśli zmieniło się publiczne API?
4. Czy dopisałem / zaktualizowałem test?
5. Czy nie zostawiłem `var_dump`/`print_r`/`echo`/`exit` w `src/`?
