# SSR i hydracja

## Przepływ

1. PHP wybiera stronę i wykonuje jej loader.
2. Node dostaje nazwę strony i te same props, które trafiają do JSON w HTML.
3. Bundle app/server.jsx ładuje strony eager i renderuje React przez renderToString.
4. Application osadza HTML w #app z data-render-mode="ssr".
5. Klient ładuje moduł strony i wywołuje hydrateRoot.

Serwer i klient używają tego samego PageShell oraz identifierPrefix `gaska-`.
Nie pobieramy ponownie danych loadera podczas hydracji.
W trybie CSR klient korzysta z createRoot.

## Uruchomienie

```sh
npm run build
php gaska serve
```

Build tworzy `public/build/` oraz `build/ssr/server.js`.
Node i zależności produkcyjne npm (react, react-dom) muszą być dostępne także na serwerze.
PHP wymaga proc_open. GASKA_NODE_BINARY może wskazać pełną ścieżkę do Node.
GASKA_SSR=0 włącza CSR. GASKA_VITE_DEV_SERVER wybiera CSR z HMR.

## Kontrakt komponentu

Render musi dawać identyczny wynik na serwerze i podczas pierwszego renderu klienta.
Nie używaj window/document, Date.now(), Math.random() ani zależnej od lokalnej strefy
formatacji dat w renderze. Przenieś dane żądania do loadera, a operacje przeglądarkowe
do efektów lub handlerów. React useId korzysta ze wspólnego prefiksu.

Strony demonstracyjne dostają flagi saved/deleted z loaderów zamiast window.location.
Dane użytkownika renderuj jako tekst JSX. Props są publiczne; nie przekazuj sekretów.

## Awaria i ograniczenia

- Brak manifestu lub bundle SSR: HTTP 503 z instrukcją builda.
- Błąd komponentu, niedostępny Node, timeout lub błędny protokół: ogólny HTTP 500,
  szczegóły wyłącznie w logu PHP.
- API działa niezależnie od buildów.
- Jeden proces Node na żądanie strony; timeout 5 sekund.
- Maksymalnie 1 MiB wejścia i 2 MiB łącznie stdout/stderr.
- Proces jest kończony i odbierany również po timeoutach i przekroczeniu limitu.
- Komunikacja przez stdin/stdout; logi kodu serwerowego kieruj na console.error.
- Ścieżkę bundle wybiera konfiguracja serwera, nie użytkownik.

Synchroniczne SSR bez streamingu, puli workerów, SSG/ISR, RSC i oczekiwania
na asynchroniczne dane w komponentach. Dane pobiera loader PHP przed renderowaniem.
Nie ma cichego przejścia do CSR po błędzie SSR.

## Weryfikacja

`npm run test:runtime`: błędy, timeout, limity i sprzątanie procesu.
`npm run test:http`: HTML przed JS, dynamiczne dane, escapowanie i formularz edycji.
`npm run test:e2e`: testy rzeczywistej hydracji, przycisków i stron bez JS.
W bieżącym sandboxie E2E nie uruchamia się z powodu blokady nasłuchu na porcie.

Źródła API: [renderToString](https://react.dev/reference/react-dom/server/renderToString),
[hydrateRoot](https://react.dev/reference/react-dom/client/hydrateRoot),
[Vite SSR](https://vite.dev/guide/ssr.html).
