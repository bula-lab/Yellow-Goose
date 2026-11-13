# SSR i hydracja

SSR wykonuje React wewnątrz PHP przez rozszerzenie V8Js. Node jest potrzebny
wyłącznie do developmentu i builda. Serwer obsługujący zbudowaną aplikację nie
potrzebuje Node, npm, node_modules ani możliwości uruchamiania procesów dla SSR.

## Przepływ

1. PHP wybiera stronę i wykonuje jej loader.
2. V8JsRenderer tworzy nowy kontekst V8 i ładuje samodzielny bundle SSR.
3. React renderToString zwraca HTML z tymi samymi props, które trafiają do JSON strony.
4. Przeglądarka hydratuje HTML przez hydrateRoot.
5. Kolejne przejścia pobierają JSON z PHP i zmieniają komponent w istniejącym root Reacta.
   Nie uruchamiają SSR. Szczegóły: [nawigacja](routing.md).

Serwer i klient używają wspólnego PageShell oraz identifierPrefix `gaska-`.
Nie pobieramy ponownie danych loadera podczas hydracji.
Development oraz jawne `GASKA_SSR=0` korzystają z createRoot i CSR.
Brak rozszerzenia V8Js przy włączonym SSR daje kontrolowany HTTP 503.
Nie ma automatycznego przełączania na Node ani CSR.

## Wymagania i build

Serwer: PHP z V8Js oraz rozszerzeniami potrzebnymi aplikacji.
Development i build: Node 24 LTS, npm, React i Vite.

```sh
npm run build
php gaska serve
```

Build tworzy `public/build/` oraz prywatny `build/ssr/server.js`.
Bundle SSR zawiera komponenty, React i renderer; nie ładuje modułów z npm na serwerze.
Publiczny document root musi wskazywać `public/`.
CLI developmentu i wbudowany serwer PHP nadal uruchamiają procesy; sam SSR tego nie robi.

Wersje React/React DOM są przypięte w package.json i package-lock.json.
Publiczny browser entry Reacta ładuje również kod strumieniowy wymagający Web APIs.
Konfiguracja builda wybiera wewnętrzny moduł synchroniczny
`react-dom/cjs/react-dom-server-legacy.browser.production.js`.
To zależność od implementacji Reacta: aktualizacja wymaga ponowienia testów
rzeczywistego bundle'a, useId i hydracji. Streaming nie jest obsługiwany.

## Środowisko Docker

Powtarzalny obraz PHP 8.4 z V8Js:

```sh
docker build --target runtime -t yellow-goose-runtime -f tests/docker/V8Js.Dockerfile .
docker run --rm -p 8000:8000 -v "$PWD:/app" yellow-goose-runtime php gaska serve --host=0.0.0.0
```

Przed startem wykonaj instalację Composer, migracje i build według README.
Obraz runtime nie instaluje pakietu nodejs ani jego binarki. Używa V8 z biblioteki
libnode.so dostarczanej przez Debiana; nie uruchamia środowiska Node.
Źródła rozszerzenia V8Js są przypięte do konkretnego commita w Dockerfile.
Instalacja rozszerzenia poza Dockerem: [instrukcje V8Js](https://github.com/phpv8/v8js).

Pełne testy w kopii roboczej przeznaczonej do testów, z osobnymi zależnościami Linux:

```sh
docker build --target test -t yellow-goose-test -f tests/docker/V8Js.Dockerfile .
docker run --rm --shm-size=1g -v "$PWD:/app" yellow-goose-test sh -ec 'composer install --no-interaction; npm ci; npm run verify:all'
```

Na macOS nie uruchamiaj npm ci w kontenerze nad tym samym node_modules, którego
używasz lokalnie: zależności natywne różnią się między systemami. Użyj osobnej kopii projektu.
Obraz test zawiera Node do builda i HMR; runtime pozostaje bez niego.

Dowód SSR bez Node i bez procesów potomnych:

```sh
docker run --rm -v "$PWD:/app:ro" yellow-goose-runtime php -d disable_functions=proc_open,popen,exec,shell_exec,system,passthru tests/ssr.php
```

## Kontrakt komponentu

Pierwszy render w przeglądarce musi zgadzać się z HTML z serwera.
Nie używaj window/document, Node APIs, Date.now(), Math.random() ani lokalnej strefy
czasowej w renderze. Dane pobiera loader PHP. Operacje przeglądarkowe wykonuj
w efektach lub handlerach. Props są publiczne; nie przekazuj sekretów.
Komponenty muszą renderować się synchronicznie. Nie ma RSC, SSG/ISR ani streamingu.

## Limity i błędy

- Osobny kontekst V8 dla każdego renderu, bez współdzielenia globalnego stanu komponentów.
- Limit wykonania: 5 s; pamięć V8: 64 MiB; JSON wejściowy: 1 MiB; HTML: 2 MiB.
- Brak builda lub V8Js: HTTP 503.
- Wyjątek komponentu, timeout lub przekroczenie limitu: HTTP 500; szczegóły w logu PHP.
- Wyjście print/console nie jest dopisywane do odpowiedzi HTTP.
- API i nawigacja JSON działają niezależnie od V8Js oraz bundle'a SSR.

## Weryfikacja

`npm run verify` wymaga lokalnego V8Js albo opisanego obrazu testowego.
Testy obejmują limity, izolację kontekstów, Unicode, dane wejściowe, błędy,
rzeczywisty bundle React, CGI i niezależnego konsumenta pakietu.
E2E sprawdza HTML bez JS, hydrację, nawigację i HMR.
Workflow CI wykonuje również testy w obrazie runtime bez binarki Node.

Źródła: [V8Js](https://github.com/phpv8/v8js),
[renderToString](https://react.dev/reference/react-dom/server/renderToString),
[hydrateRoot](https://react.dev/reference/react-dom/client/hydrateRoot).
