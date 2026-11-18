# Pakiety frameworka

Kod jest podzielony na biblioteki Composer i pakiety npm. Dotychczasowe nazwy klas
ZoltaGaska oraz importy vendor pozostają obsługiwane. Pakiety nie zostały opublikowane
w Packagist ani npm; testy instalują ich lokalne archiwa.

| Composer | Odpowiedzialność |
| --- | --- |
| yellow-goose/core | HTTP, routing, jawne moduły i dispatcher; bez PDO/React/V8Js |
| yellow-goose/database | PDO, ORM i migrator z blokadą oraz właścicielami migracji |
| yellow-goose/react-bridge | Loadery, protokół stron, Assets i interfejs Renderer |
| yellow-goose/ssr-v8js | SSR w PHP/V8Js, limity i zgodność ABI |
| yellow-goose/security | Tożsamość, polityki, CSRF i opcjonalne logowanie sesyjne |
| yellow-goose/resources | Jawne zapytania i akcje, schemat UI, walidacja i serializacja |
| yellow-goose/validator-symfony | Opcjonalny adapter Symfony Validator 6.4 |
| yellow-goose/framework | Fasada zgodności: Runtime, CLI oraz lokalne zasoby frontendowe |

Instalacja biblioteki nie włącza tras, panelu ani migracji. Reguły aplikacji i jej
modele pozostają poza pakietami. API modułów opisuje [instrukcja](modules.md).

## Instalacja z checkoutu

Composer nie dziedziczy repozytoriów z manifestów zależności. Konsument lokalnych
pakietów podaje oba repozytoria:

```json
{
  "name": "example/my-app",
  "require": { "yellow-goose/framework": "@dev" },
  "repositories": [
    {
      "type": "path", "url": "../yellow-goose/packages/php/*",
      "options": { "symlink": false }
    },
    {
      "type": "path", "url": "../yellow-goose/packages/framework",
      "options": { "symlink": false, "versions": { "yellow-goose/framework": "dev-main" } }
    }
  ],
  "autoload": { "psr-4": { "App\\": "app/" } }
}
```

Lekki konsument wymaga bezpośrednio `yellow-goose/core: ^0.2`, zamiast fasady.
Profil CSR wymaga react-bridge, a SSR dodaje ssr-v8js oraz rozszerzenie V8Js.
PHP >=8.1 pozostaje minimum. Database wymaga PDO, ale core i resources go nie wymagają.

Fasada zachowuje `php vendor/bin/gaska help`, dev, serve, db:migrate i db:seed.
Bootstrap nadal używa Runtime::boot oraz Runtime::application. Brak konfiguracji
bazy jest dozwolony, dopóki aplikacja nie korzysta z ORM.

## Frontend

Istniejąca aplikacja zachowuje importy z vendor/yellow-goose/framework/resources.
Pliki te są samowystarczalne i generowane z jednego źródła:

```sh
npm run sync:legacy
npm run check:packages
```

Nowa aplikacja instaluje archiwa `@yellow-goose/react` i `@yellow-goose/vite`
przygotowane przez npm pack. Eksporty:

```js
import { startClient } from '@yellow-goose/react/client';
import { renderPage } from '@yellow-goose/react/server';
import { navigate } from '@yellow-goose/react/navigation';
import { frameworkConfig } from '@yellow-goose/vite';
```

Globy stron pozostają w aplikacji, jak w app/client.jsx i app/server.jsx demonstracji.
Przypięty zestaw: React/React DOM 19.3.1, Vite 8.3.8, plugin-react 6.3.0.
Wewnętrzny synchroniczny renderer React musi istnieć; brak entry przerywa build.
Bundle SSR deklaruje protocolVersion=1 i bundleAbiVersion=1. V8JsRenderer odrzuca
niezgodne wersje; allowLegacyBundle=false wymaga metadanych także w nowej instalacji.
Fasada domyślnie obsługuje również stary bundle bez metadanych.

Node i npm są potrzebne do developmentu i builda. Gotowa aplikacja PHP nie wymaga
Node ani node_modules na serwerze. V8Js jest wymagane przy renderowaniu SSR;
samo zainstalowanie fasady nie blokuje jawnego CSR.

## Test niezależności

`npm run test:package` instaluje archiwa Composer i npm w fizycznych katalogach
poza repozytorium. Osobno sprawdza stare importy bez zależności @yellow-goose oraz
nowe eksporty npm, CLI, build klienta i SSR, loader/API oraz useId.
Nie współdzieli root node_modules. Osobne instalacje core, resources i CSR są
uruchamiane przez PHP -n bez PDO/V8Js; resources nie wymaga Symfony Validator.

`GASKA_PACKAGE_BROWSER=1 npm run test:package` dodaje Chromium: hydrację, useId
oraz interaktywny stan w obu konsumentach. Wymaga przygotowanego Playwright.
Macierz klas i eksportów jest sprawdzana przez npm run check:packages,
a stare sygnatury przez php tests/contracts.php.
