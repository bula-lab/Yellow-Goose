# A0: kontrakty zgodności i podział dystrybucji

Baza: b1e8000. Istniejące dokumenty planistyczne zachowano. A0 nie zmienia runtime.
Wersja pierwszej fali pakietów: 0.2.0. Namespace ZoltaGaska pozostaje bez zmian.

## Właściciele i archiwa

| Pakiet | Eksporty | Wymagania | Zawartość archiwum |
| --- | --- | --- | --- |
| yellow-goose/core | Config/Environment, Http/Request, Response, HttpException, Routing/Route, Router; w A2 Modules | PHP >=8.1, JSON | composer.json, src, README |
| yellow-goose/database | Database/Connection, Builder, Model, Migrator | PHP >=8.1, PDO, sterownik konsumenta | composer.json, src, README |
| yellow-goose/react-bridge | Http/Application, Frontend/Assets, Renderer | core, JSON | composer.json, src, README |
| yellow-goose/ssr-v8js | Frontend/V8JsRenderer | react-bridge; V8Js wymagane przy renderowaniu | composer.json, src, README |
| yellow-goose/framework | Runtime, Console/Cli, Security/Session do A2 | powyższe pakiety; w A2 security | composer.json, src, bin/gaska, resources, README |
| @yellow-goose/react | ./client, ./server, ./navigation; kontrakt protokołu | React i React DOM dokładnie 19.3.1 | package.json, src, README |
| @yellow-goose/vite | główne frameworkConfig, ./dev | Node >=24 do build/dev; Vite 8.3.8, plugin-react 6.3.0 | package.json, src, README |

W A2 security przejmuje Session oraz Identity, polityki i provider uwierzytelnienia.
Resources zależy od core/security i posiada walidację oraz jawne query/actions.
Nie dodajemy zależności od CMS, silnika 3D ani serwera gry.

Publiczne i chronione sygnatury 17 klas, właściwości oraz stałe zapisano przed
ekstrakcją w tests/contracts/legacy.json. tests/contracts.php porównuje stary kontrakt;
dopuszcza nowe metody i opcjonalne argumenty, nie dopuszcza usunięcia starego API.

## Granice zachowania

- Stare entry app/client.jsx, app/server.jsx i vite.config.js zamrożono w
  tests/fixtures/legacy. Ich importy vendor działają bez nowych zależności npm.
- CLI: help, dev, serve, db:migrate, db:seed i dotychczasowe opcje pozostają dostępne.
- Routing: pierwszeństwo literal/param/catch-all, API PHP wyłącznie pod api/;
  loadery otrzymują Request i params oraz zwracają props albo Response.
- Payload strony: page, props (chronione params/csrfToken), url, version, head.title.
  X-YG-Navigation: 1 wybiera JSON, X-YG-Version wykrywa stary build; 409 oznacza
  przekierowanie/reload. Dotychczasowe nagłówki i cache no-store pozostają.
- Identyfikator React: gaska-. V8Js: 5 s, 64 MiB, props 1 MiB, HTML 2 MiB;
  brak rozszerzenia daje 503, a API/nawigacja działają bez renderera.
- Migracje aplikacji zachowują basename w tabeli migrations; A2 nie uruchamia ich
  ponownie pod nowym właścicielem. Włączenie modułu nie wykonuje DDL.
- Nowe buildy dostają protocolVersion=1 i bundleAbiVersion=1. Brak metadanych jest
  akceptowany tylko jako dotychczasowy format legacy; niezgodna jawna wersja jest błędem.

## Macierz akceptacji

| Konsument | Kontrola |
| --- | --- |
| Legacy | Fizyczna instalacja Composer i własne node_modules; zamrożone stare importy i manifest npm, CLI, build klienta/SSR, loader, API, useId |
| Nowy npm | npm pack, instalacja archiwów poza repo bez symlinków do root node_modules, eksporty npm, oba buildy |
| Core-only | Fizyczna instalacja wyłącznie core, PHP bez PDO/V8Js, Request/Router/Response; w A2 dispatcher |
| CSR | core/react-bridge bez database/ssr-v8js, strona i JSON nawigacji |
| SSR | Rzeczywisty bundle React w V8Js; hydracja i useId w Chromium; bez Node w runtime |
| Moduły A2 | Jawny boot, brak/duplikat/cykl zależności, konflikty tras/usług/komend/polityk; migracje tylko przez komendę |
| Zapis A2 | Tożsamość od serwera, polityka zasobu, CSRF dla sesji, walidacja; odrzucony zapis nie wywołuje handlera |

## Walidacja A0

Utrwalony kontrakt: 17 klas PASS. Bazowe testy: 145 SQLite, 20 nawigacji,
14 JavaScript PASS. Testy rzeczywistego SSR oraz konsumenta są wykonywane w lokalnym
obrazie PHP/V8Js przed ekstrakcją. Wyniki kolejnych etapów trafiają do wspólnego
raportu wykonania. Nie zmieniono kodu runtime ani danych aplikacji.
