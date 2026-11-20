# Żółta-Gąska / Yellow-Goose

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="public/brand/logo-dark.svg">
  <img src="public/brand/logo-light.svg" alt="Yellow-Goose — PHP + React framework" width="550">
</picture>

Framework PHP + React: routing plikowy, loadery PHP, JSON API, ORM i SSR.
Biblioteki Composer są w `packages/php/`, a pakiety frontendowe w `packages/js/`.
`packages/framework/` zachowuje zgodność dotychczasowego Runtime, CLI i importów.
MVP zamknięte 2026-11-13: [zakres i akceptacja](docs/mvp.md).
Demonstracja: notatnik z listą, szczegółami, dodawaniem, edycją, usuwaniem i paginacją.

## Uruchomienie

PHP 8.1+ z PDO, pdo_sqlite i mbstring oraz Composer. SSR wymaga rozszerzenia PHP V8Js.
Node 24 LTS i npm są potrzebne wyłącznie do developmentu i builda.
Serwer z gotowym buildem nie potrzebuje Node ani node_modules.
Gotowe środowisko PHP/V8Js i instrukcje testowania: [SSR](docs/ssr.md#środowisko-docker).
UI: React / React DOM 19.3.1, Vite 8.3.8, plugin-react 6.3.0.

Pierwsza instalacja:

```sh
composer install
npm ci
php gaska db:migrate
```

Development — **jedna komenda, jeden terminal**:

```sh
npm run dev
```

Otwórz http://127.0.0.1:8000. Vite na 5173 dostarcza HMR.
Ctrl+C zatrzymuje środowisko. Awaria jednego procesu zatrzymuje drugi i zwraca błąd.
Inne porty: `npm run dev -- --port=8080 --vite-port=5174`.
Start development nie wykonuje migracji ani nie zmienia danych.

Demonstracja builda z SSR:

```sh
npm run demo
```

Komenda wykonuje migracje, dodaje dwa wpisy **tylko do pustej bazy**,
buduje frontend i SSR, następnie uruchamia PHP na porcie 8000.
Zatrzymaj wcześniej development. Domyślna baza: `database/database.sqlite`.
Można też wykonać `npm run build` i `php gaska serve` osobno.
Opcjonalną konfigurację opisuje .env.example; nie nadpisuj istniejącego .env.

## Tryby renderowania

| Tryb | Działanie |
| --- | --- |
| `npm run dev` | CSR + Vite HMR |
| `npm run build` + `php gaska serve` | PHP loader → React SSR w V8Js → HTML → hydracja |
| `GASKA_SSR=0 php gaska serve` | Jawne wyłączenie SSR, frontend z builda |

SSR zwraca treść przed wykonaniem JavaScript. Formularze i przyciski wymagają hydracji.
SSR używa osobnego kontekstu V8 w PHP, z limitem 5 s i 64 MiB pamięci.
Nie ma streamingu. Brak V8Js daje HTTP 503; GASKA_SSR=0 jawnie wybiera CSR.
Po hydracji linki i przekierowania formularzy korzystają z nawigacji bez przeładowania.
API i nawigacja JSON nie zależą od dostępności renderera SSR.

## Struktura

| Katalog | Zawartość |
| --- | --- |
| `packages/php/` | Core, baza, React bridge, V8Js SSR, security, resources i adapter walidacji |
| `packages/js/` | Pakiety npm React i Vite; źródło zasobów frontendowych |
| `packages/framework/src/` | Runtime i CLI zgodności |
| `packages/framework/resources/` | Generowane, samowystarczalne zasoby dla starych importów vendor |
| `app/pages/` | Strony React; API w podkatalogu api |
| `app/loaders/` | Dane stron z PHP |
| `app/components/` | Komponenty aplikacji |
| `database/` | Migracje, seed i lokalna baza |
| `public/build/` | Publiczne zasoby klienta |
| `build/ssr/` | Prywatny bundle serwerowy, poza document root |

Aplikacja instaluje yellow-goose/framework i jego biblioteki przez repozytoria Composer typu path.
Kod App nie jest częścią pakietu. Test niezależnego konsumenta instaluje fizyczną kopię
pakietu i buduje własną stronę, loader i API. Pakiet nie jest opublikowany w Packagist.

Dokumentacja: [pakiet](docs/framework-package.md), [SSR](docs/ssr.md),
[routing](docs/routing.md), [loadery](docs/data-fetching.md), [API](docs/api-routes.md),
[ORM](docs/orm.md), [konfiguracja](docs/configuration.md), [CLI](docs/cli.md),
[moduły i zasoby](docs/modules.md).

A0–A2 dostarczają podział pakietów, moduły, polityki, logowanie i walidowane akcje.
[Zakres i wyniki A0–A2](docs/reports/a0-a2-implementation-2026-11-18.md).
B1/B2 dodają formularze, tabele, modale, trwały workspace, upload modeli, worker PHP,
viewer Babylon oraz CMS z opublikowanymi rewizjami. Przykład examples/garage-cms
łączy te funkcje w katalog i konfigurator garażu z jawnymi cenami demonstracyjnymi.
[Uruchomienie przykładu](examples/garage-cms/README.md) ·
[Raport integracji B1/B2](docs/reports/b1-b2-integration-2026-11-20.md).
Notatnik zachowuje dotychczasowy kontrakt; prosty przykład kont i zasobów pozostaje
w examples/modular-api. Edytor scen, import CAD i gry są kolejnymi etapami.

Logo, warianty i pliki źródłowe: [identyfikacja frameworka](docs/branding.md).

## Aplikacja i API

| Adres | Funkcja |
| --- | --- |
| /, /?page=2 | Lista po 10 wpisów |
| /posts/new | Dodawanie |
| /posts/{id} | Szczegóły i usuwanie |
| /posts/{id}/edit | Edycja |
| GET /api/posts | Lista JSON |
| POST /api/posts | Utworzenie; 201 i Location |
| GET /api/posts/{id} | Szczegóły JSON |
| PATCH /api/posts/{id} | Częściowa aktualizacja |
| DELETE /api/posts/{id} | Usunięcie; pusty 204 |
| GET /api/hello | Kontrola połączenia |

POST/PATCH wymagają obiektu JSON: title (1–200 znaków), content (1–10000).
Walidacja zwraca 422 z mapą errors; nieznane pola są odrzucane.
Mutacje wymagają sesji i nagłówka X-CSRF-Token. Token trafia do props strony.

## Testy

Kontrole bez portów sieciowych (PHP z V8Js, Python 3, php-cgi i Node do builda):

```sh
npm run verify
```

Obejmuje testy PHP, buildy notatnika i konfiguratora, supervisor, SSR, rzeczywisty
entry point przez CGI oraz instalację archiwów w niezależnych aplikacjach.
Testy używają tymczasowych baz i magazynów plików. Profil Assets wymaga dodatkowo GD;
core, CSR i CMS tekstowy nie wymagają tej biblioteki.
Test pakietów instaluje lokalne archiwa w niezależnych katalogach i nie współdzieli
node_modules. Obejmuje stare importy vendor i nowe eksporty npm oraz profile core,
resources i CSR bez PDO/V8Js. GASKA_PACKAGE_BROWSER=1 dodaje hydrację w Chromium.

Pełne E2E — jednorazowo przygotuj Playwright:

```sh
python3 -m venv .venv
source .venv/bin/activate
python -m pip install -r requirements-dev.txt
python -m playwright install chromium
npm run verify:all
```

Na Linuxie można użyć `python -m playwright install --with-deps chromium`.
PLAYWRIGHT_CHROMIUM_EXECUTABLE opcjonalnie wskazuje istniejący Chrome/Chromium.
E2E wybiera wolne porty i uruchamia serwery w tymczasowej kopii projektu.
Sprawdza SSR bez JS, hydrację, CRUD, trwałość po restarcie, błędy, mobile
i HMR przez pojedynczą komendę development. Zrzuty i trace: `test-results/`.

Workflow [tests.yml](.github/workflows/tests.yml): PHP 8.1/8.5 i MySQL 8.4;
pełne verify i Chromium E2E w obrazie PHP 8.4/V8Js; osobna kontrola SSR i CGI
w obrazie bez Node. Obraz testowy i runtime opisuje [dokumentacja SSR](docs/ssr.md).

### Historyczna akceptacja MVP — 2026-11-13

MVP oraz rozszerzenia development/pakiet/SSR są wdrożone i zaakceptowane.
[MVP tests #2](https://github.com/bula-lab/Yellow-Goose/actions/runs/43381861459)
dla commita `9d0986741567aa4918ad214f4b9c6b100e9aad3d`: **wszystkie cztery zadania PASS**.

- PHP 8.1 i 8.5: PASS; SQLite i MySQL 8.4: po 145 asercji PASS.
- PHP-CGI: 37 asercji PASS, w tym HTML SSR, escapowanie, sesja i CRUD.
- SSR: 20 asercji PASS, w tym timeout, limity, sprzątanie procesu i błędy.
- Supervisor: 4 testy PASS, w tym awaria, SIGINT/SIGTERM i wymuszone zakończenie.
- Build klienta i SSR oraz niezależny konsument Composer: PASS.
- Chromium E2E: PASS — SSR bez JS, hydracja, CRUD, walidacja, CSRF, API,
  trwałość po restarcie serwera, obsługa błędów, mobile i HMR bez przeładowania strony.
- Czyste composer install i npm ci na runnerze CI: PASS.
- node_modules nie jest śledzone w Git.

Wyniki powyżej dotyczą historycznego commita wskazanego w tej sekcji, przed zmianą SSR na V8Js.
Wyniki pochodzą z wykonanego CI. Wcześniejsze ograniczenia lokalnego
sandboxa zostały rozstrzygnięte przez wykonanie testów na GitHub Actions.

## Granice

Notatnik jest lokalną demonstracją bez kont. Opcjonalny przykład CMS ma logowanie
redaktora i kontrolę publikacji; reguły oraz ceny konfiguratora są fikcyjne.
Brak SSG/ISR, RSC, RPC „Zero API” i rozbudowanych relacji ORM.
Wbudowany serwer PHP służy do developmentu. Przy innym serwerze document root musi
wskazywać `public/`; żądania aplikacji kieruj do index.php i blokuj pliki ukryte,
w szczególności `public/build/.vite/`.

[Zamknięcie MVP](docs/mvp.md) · [Pierwotna wizja](docs/vision.md)
