# Tillio OAuth — examples

Minimalny, działający przykład logowania przez Tillio CRM w czystym PHP.

## Setup

1. W katalogu głównym projektu zainstaluj zależności:
   ```bash
   composer install
   ```

2. Skopiuj konfigurację przykładową:
   ```bash
   cp examples/config.example.php examples/config.php
   ```

3. Edytuj `examples/config.php` i wstaw:
   - `clientId` i `clientSecret` (otrzymane z panelu Tillio)
   - `redirectUri` — musi być dokładnie taki sam, jak zarejestrowany po stronie Tillio, np. `http://localhost:8000/callback.php`

4. Uruchom wbudowany serwer PHP z katalogu `examples/`:
   ```bash
   php -S localhost:8000 -t examples
   ```

5. Wejdź na `http://localhost:8000/index.php` i kliknij **Zaloguj się przez Tillio**.

## Przepływ

| Plik             | Rola                                                       |
|------------------|------------------------------------------------------------|
| `index.php`      | Strona główna. Pokazuje status i dane zalogowanego usera. |
| `login.php`      | Przekierowuje do Tillio (rozpoczęcie logowania).          |
| `callback.php`   | Odbiera kod z Tillio, wymienia na token, pobiera usera.   |
| `bootstrap.php`  | Ładuje autoloader, config i uruchamia sesję.              |
| `config.php`     | **Lokalny (gitignored)** — Twoje `clientId`/`clientSecret`. |

## Troubleshooting

- **`Missing required config key`** — sprawdź `config.php`.
- **`redirect_uri mismatch`** — URI w Tillio musi być identyczny jak w `config.php`.
- **Błąd CSRF** — sesja PHP zgubiła się między requestami. Sprawdź domenę, cookie, ustawienia `session.cookie_*`.
