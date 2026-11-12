# Pakiet frameworka

Rdzeń: `packages/framework`, własny composer.json, przestrzeń ZoltaGaska.
Aplikacja wymaga yellow-goose/framework z repozytorium Composer typu path.
W tym repozytorium Composer tworzy symlink w vendor, więc zmiany rdzenia
są od razu dostępne.

Pakiet zawiera PHP, binarkę gaska i zasoby React/Vite. Nie zawiera modeli App,
stron notatnika, danych, konfiguracji bazy ani migracji aplikacji.
Nie jest wydany w Packagist; nie deklaruje jeszcze stabilnego publicznego API.

## Niezależna aplikacja

Przykład przy sąsiednim checkoutcie:

```json
{
  "name": "example/my-app",
  "require": { "yellow-goose/framework": "@dev" },
  "repositories": [{
    "type": "path",
    "url": "../yellow-goose/packages/framework",
    "options": {
      "symlink": false,
      "versions": { "yellow-goose/framework": "dev-main" }
    }
  }],
  "autoload": { "psr-4": { "App\\": "app/" } }
}
```

Po composer install dostępne jest `php vendor/bin/gaska help`.
Komendy pakietu używają bieżącego katalogu jako katalogu aplikacji.
Rootowy gaska w demonstracji jest cienkim wrapperem CLI.

Bootstrap:

```php
require __DIR__ . '/vendor/autoload.php';
ZoltaGaska\Runtime::boot(__DIR__);
```

Runtime ładuje .env i opcjonalne config/database.php.
Connection nie odczytuje konfiguracji przez ścieżki względem katalogu pakietu.
Aplikacja bez bazy nie potrzebuje konfiguracji; użycie ORM bez niej daje jawny błąd.

HTTP: `Runtime::application($projectRoot)` i Request.
Aplikacja dostarcza app/pages, opcjonalne app/loaders i public/router.php.
Wzór obsługi błędów znajduje się w public/index.php demonstracji.

Frontend instaluje react, react-dom, vite i @vitejs/plugin-react przez npm.
Sprawdzone wersje i skrypty są w głównym package.json.
app/client.jsx i app/server.jsx deklarują glob własnych stron oraz wywołują
startClient/renderPage z pakietu.

Konfiguracja Vite:

```js
import { fileURLToPath } from 'node:url';
import { frameworkConfig } from './vendor/yellow-goose/framework/resources/vite.mjs';
export default frameworkConfig(fileURLToPath(new URL('.', import.meta.url)));
```

## Dowód niezależności

`npm run test:package` tworzy osobny projekt, instaluje fizyczną kopię pakietu
przez Composer, uruchamia CLI, buduje klienta i SSR oraz renderuje własną stronę.
Sprawdza loader, API, escapowanie i useId; nie używa klas App ani autoloadera demo.
Zainstalowane node_modules są współdzielone tylko na czas testu.

Fixture: tests/fixtures/consumer. To fixture testowe, nie pełny generator aplikacji.
Źródło: [repozytoria path w Composer](https://getcomposer.org/doc/05-repositories.md#path).
