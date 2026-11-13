# SSR bez Node i nawigacja Yellow-Goose

Zmiana po zamknięciu MVP, 2026-11-13. Node pozostaje narzędziem builda i developmentu.
Produkcyjny SSR wykonuje React w PHP przez V8Js. Nawigacja po hydracji pobiera dane
strony z PHP i aktualizuje istniejący root Reacta.

## Zakres i wynik

| Wymaganie | Implementacja i konsument | Weryfikacja |
| --- | --- | --- |
| Dynamiczny React SSR bez Node na serwerze | V8JsRenderer, Runtime, pełne odpowiedzi HTML | 24 asercje rzeczywistego V8Js; obraz bez binarki Node; wyłączone proc_open/popen/exec/shell_exec/system/passthru |
| Samodzielny bundle | resources/vite.mjs, globalny GaskaSSR.render | Build klienta i SSR; instalacja fizycznej kopii pakietu w niezależnej aplikacji |
| Izolacja i limity SSR | Osobny kontekst V8, limity wejścia, HTML, czasu i pamięci | Unicode, próba wstrzyknięcia kodu, izolacja globali, timeout, limit pamięci, wyjątki i ochrona odpowiedzi HTTP |
| Hydracja | PageShell, client.jsx, identyczne props i prefiks useId | CGI, niezależny konsument i Chromium z JS oraz bez JS |
| Dane stron bez ponownego SSR | Application, X-YG-Navigation, wersja manifestu, Vary | 20 asercji PHP, w tym działanie przy uszkodzonym lub brakującym SSR |
| Nawigacja i historia | navigation.mjs i istniejący root Reacta | Testy JS: opóźnione odpowiedzi, błędy sieci, historia, kanoniczny URL, wersja builda i przekierowania |
| Zapis i usuwanie bez przeładowania | PostForm, strona szczegółów, app/lib/navigation.js | Chromium zachowuje znacznik dokumentu przez dodawanie, edycję i usuwanie |
| Standardowe zachowanie linków | Przechwytywanie tylko zwykłych wewnętrznych kliknięć | Chromium: Ctrl/Cmd+klik otwiera osobną kartę; błędy sieci pozwalają ponowić przejście |
| Środowisko i CI | tests/docker/V8Js.Dockerfile, tests.yml | Lokalne wykonanie środowiska testowego i runtime; workflow przygotowany, zdalnie nieuruchamiany |

## Wyniki

- PHP 8.5 lokalnie: 145 asercji SQLite i 20 asercji nawigacji PASS.
- JavaScript: 14 testów PASS, w tym 10 nawigacji i 4 supervisora.
- PHP 8.4/V8Js w Dockerze: 24 asercje SSR i 37 CGI PASS.
- Niezależny konsument Composer: własny loader, API, build, SSR, escapowanie i useId PASS.
- Chromium: SSR bez JS, hydracja, CRUD w jednym dokumencie, cofanie/dalej,
  odzyskiwanie po błędzie sieci, nowa karta, zmiana wersji builda, CSRF,
  trwałość po restarcie, mobile i HMR PASS.
- Czyste composer install i npm ci wykonane w osobnej kopii projektu w środowisku Linux.
- Brak V8Js lokalnie daje kontrolowany HTTP 503; brak rozszerzenia nie uruchamia Node.

Środowisko wykonanej integracji: Linux arm64, PHP 8.4, V8Js 2.1.2,
V8 11.3 dostarczane przez bibliotekę Debiana. Rozszerzenie zbudowano z commita
8a39efa3cf3b275e402ddf3c4f6b611a5f69a499. Testów zdalnego workflow i MySQL
nie ponawiano w tym zadaniu; integracja MySQL pozostaje osobnym zadaniem CI.

## Wymagania wdrożenia

Na serwer trafiają kod PHP, vendor, public/build i prywatny build/ssr/server.js.
SSR wymaga PHP z V8Js. Node, npm i node_modules nie są potrzebne na serwerze.
Brak V8Js lub bundle'a daje 503. Jawne GASKA_SSR=0 wybiera CSR.
API i odpowiedzi nawigacji nie zależą od renderera SSR.

Obraz runtime używa biblioteki V8 z libnode.so, bez pakietu nodejs i jego binarki.
Nie uruchamia procesów JavaScript. Obraz test dodaje Node wyłącznie do builda i HMR.
Instalację oraz komendy opisuje [SSR](../ssr.md).

Wersje React są przypięte. Synchroniczny renderer jest wewnętrznym modułem React DOM;
aktualizacja React wymaga sprawdzenia bundle'a i hydracji. Nie dodano streamingu,
RSC ani trwałej puli silników. Formularze nadal wymagają JavaScriptu.

Kontrakt nawigacji, opcje i zachowanie linków: [routing](../routing.md).
