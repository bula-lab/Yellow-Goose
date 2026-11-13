# Plan MVP Yellow-Goose

## Status realizacji — MVP zamknięte 2026-11-13

Etapy 0–6 oraz uzgodnione rozszerzenia (jedna komenda development, pakiet i SSR)
są wdrożone i zweryfikowane. Podstawa akceptacji: [MVP tests #2](https://github.com/bula-lab/Yellow-Goose/actions/runs/43381861459),
commit `9d0986741567aa4918ad214f4b9c6b100e9aad3d`, wszystkie cztery zadania zakończone sukcesem.

- [x] Konfiguracja .env, SQLite/MySQL, migrator i seed.
- [x] Request JSON/query/nagłówki, walidacja, odpowiedzi błędów.
- [x] ORM: kontrola zapisu, osobna hydracja, kontrola SQL i CRUD.
- [x] Loadery PHP dla stron, bez danych aplikacji w rdzeniu.
- [x] Lista, szczegóły, dodawanie, edycja, usuwanie, paginacja i CSRF.
- [x] 145 asercji PHP/SQLite oraz 37 asercji rzeczywistego entry pointu PHP-CGI.
- [x] Build na nowych bibliotekach UI; instalacja Composer z lockfile.
- [x] Instalacja Composer i testy PHP w czystej kopii źródeł.
- [x] Czyste npm ci w CI.
- [x] Skrypt przeglądarkowy (CRUD, restart, błędy, mobile, HMR) i workflow CI.
- [x] Wykonanie E2E w Chromium: SSR bez JS, hydracja, CRUD, walidacja, CSRF, API, trwałość po restarcie, błędy, mobile i HMR.
- [x] Integracja MySQL 8.4 oraz zdalne CI, w tym PHP 8.1 i 8.5.
- [x] Usunięcie node_modules z indeksu: git ls-files node_modules zwraca zero plików.
- [x] Etap 6: logo Yellow-Goose — wektorowe SVG, przezroczyste PNG, jasny/ciemny wariant, favicon, integracja aplikacji i README.

Nie ma otwartych kryteriów akceptacji w uzgodnionym zakresie MVP.
Instrukcje uruchomienia i powtórzenia kontroli znajdują się w README.

## Ponowna realizacja — 2026-11-12

- Logo wdrożone w aplikacji i README; źródła i eksporty: [branding](branding.md).
- E2E czeka na efekt React potwierdzający gotowość klienta, zanim zacznie obsługiwać formularze.
- Workflow obsługuje także ręczne uruchomienie przez workflow_dispatch.
- `npm run verify`: PASS — 145 asercji PHP, 37 CGI, 20 SSR, 4 testy supervisora,
  build klienta i SSR oraz instalacja frameworka w niezależnym projekcie.
- Czyste `composer install` ponownie przeszło w tymczasowym katalogu.

## Akceptacja w CI — 2026-11-13

| Kryterium | Wynik i dowód |
| --- | --- |
| Czysta instalacja | PASS: composer install i npm ci w zadaniu browser |
| PHP 8.1 i 8.5 | PASS: oba zadania macierzy PHP |
| SQLite i MySQL 8.4 | PASS: po 145 asercji dla każdego sterownika |
| npm run verify | PASS: PHP, build klienta i SSR, 4 testy supervisora, 20 asercji SSR, 37 CGI oraz niezależny konsument pakietu |
| E2E, hydracja i HMR | PASS: python tests/e2e.py, pełny scenariusz Chromium bez pominięcia kroku |
| Artefakty przeglądarkowe | PASS: krok upload-artifact, artefakt browser-results |
| Usunięcie node_modules z indeksu | PASS: zero śledzonych plików zależności |

Logi: [browser](https://github.com/bula-lab/Yellow-Goose/actions/runs/43381861459/job/131741235236),
[MySQL](https://github.com/bula-lab/Yellow-Goose/actions/runs/43381861459/job/131741234929).
Poprawka CI usunęła instalowanie niedostępnego pakietu apt php8.5-cgi;
workflow sprawdza teraz CGI i rozszerzenia dostarczone przez setup-php.
Wcześniejsze lokalne ograniczenia sieci, portów i Dockera nie blokują akceptacji:
wymagane kontrole zostały wykonane na runnerze GitHub Actions.

## Kolejny etap — wdrożony i zaakceptowany (2026-11-13)

Uzgodniona kolejność: domknięcie testów → jedna komenda development → pakiet → SSR.
Wszystkie cztery punkty mają potwierdzenie w zielonym CI wskazanym powyżej.

- [x] `npm run verify` obejmuje PHP, buildy, runtime, CGI i niezależnego konsumenta.
- [x] `npm run dev` uruchamia PHP + Vite, obsługuje porty i sprzątanie procesów.
- [x] Cztery testy supervisora: awaria, brak binarki, SIGINT, SIGTERM.
- [x] `yellow-goose/framework` w packages/framework, instalowany przez Composer path.
- [x] Druga aplikacja instaluje kopię pakietu i przechodzi własny build, loader, API i SSR.
- [x] SSR przez Node, wspólny runtime React i kod hydracji przez hydrateRoot.
- [x] 20 asercji SSR: błędy, limity, timeout, zakończenie procesu i odpowiedzi HTTP.
- [x] CGI potwierdza HTML przed JS, escapowanie danych i formularze z danymi.
- [x] E2E rozszerzone o brak JS, hydrację i HMR przez jedną komendę development.
- [x] Faktyczne przejście E2E, hydracji i HMR w Chromium.
- [x] MySQL oraz pełny zdalny workflow CI: PASS.

SSR dotyczy builda; development używa CSR z HMR. Jeden proces Node na żądanie
jest pierwszą implementacją, bez streamingu i puli workerów.
Dokumentacja: [pakiet](framework-package.md), [SSR](ssr.md).

## Cel i granice

Programista może na czystym checkoutcie uruchomić aplikację PHP + React,
dodać stronę z danymi z PHP i zbudować pełny CRUD bez zmieniania kodu frameworka.
Demonstracją jest lokalny panel wpisów: lista, szczegóły, dodawanie, edycja i usuwanie.
SQLite upraszcza pierwsze uruchomienie; MySQL pozostaje osobnym sprawdzanym wariantem.
To MVP do rozwoju i lokalnej demonstracji, nie deklaracja gotowości do publicznego wdrożenia.

## Punkt wyjścia przed realizacją

- React 19.3.1, React DOM 19.3.1, Vite 8.3.8 i plugin-react 6.3.0 są zainstalowane.
- Build i 47 asercji PHP przeszły; nie wykonano jeszcze testu w rzeczywistej przeglądarce.
- Są routing plikowy, CSR, manifest zasobów, JSON API, strona dynamiczna i konfiguracja HMR.
- Dane strony są zaszyte w `Application`; brak niezależnej logiki pobierania danych dla stron.
- `Request` przechowuje tylko URI i metodę; brak parsowania JSON, query i nagłówków.
- Konfiguracja wymienia SQLite, ale `Connection` buduje DSN i odczytuje pola jak dla MySQL.
- Brak ładowania `.env`, a port MySQL nie jest używany przy tworzeniu połączenia.
- ORM ignoruje `$fillable`, nie sprawdza nazw kolumn/operatorów i nie ma `destroy()`.
- `node_modules` nadal jest śledzone w Git; samo dopisanie do `.gitignore` tego nie zmieniło.

## Kolejność prac

| Etap | Zakres | Kryterium ukończenia |
| --- | --- | --- |
| 0. Potwierdzenie podstaw | Test przeglądarkowy obecnych stron, API, odświeżania adresu dynamicznego oraz HMR; uruchomienie tych kontroli w odpowiednim środowisku/CI | Strona renderuje się bez błędów JS, kliknięcie pobiera czas, odświeżenie `/posts/42` działa; edycja komponentu jest widoczna w development |
| 1. Konfiguracja i baza | `.env.example`, ładowanie konfiguracji, prawidłowe DSN SQLite/MySQL, port MySQL; inicjalizacja bazy i pierwsza migracja | Nowa lokalna baza powstaje z udokumentowanej komendy, ponowne wykonanie migracji nie niszczy danych |
| 2. Warstwa HTTP i danych | JSON body, query, nagłówki; odpowiedzi 400/415/422; walidacja; poprawienie `$fillable` z oddzieleniem hydracji rekordów; dozwolone kolumny i operatory SQL | Nieprawidłowe dane nie trafiają do bazy; odczyt rekordu zachowuje ID; testy CRUD używają izolowanej bazy |
| 3. Dane dla stron | Konwencja funkcji PHP przypisanych do stron, np. `app/loaders/posts/[id].php`; przekazywanie ich wyników jako props; obsługa braku rekordu | Można dodać stronę z danymi bez edycji `Application`; nieistniejący wpis daje 404 |
| 4. Pionowy przykład CRUD | Lista i szczegóły wpisów, formularze dodawania/edycji, usuwanie, API GET/POST/PATCH/DELETE, stany ładowania i błędów; ochrona żądań modyfikujących przed CSRF | Użytkownik tworzy, edytuje i usuwa wpis w przeglądarce; dane przetrwają restart; błędy walidacji są pokazane przy polach |
| 5. Powtarzalne uruchomienie | CI: czysta instalacja, testy PHP, build i E2E; wymagania PHP/rozszerzeń w Composerze; usunięcie zależności z indeksu Git bez usuwania ich lokalnie; aktualizacja dokumentacji | Inny programista uruchamia przykład zgodnie z README bez ręcznych zmian w kodzie; CI potwierdza cały scenariusz |
| 6. Logo frameworka | Projekt znaku Yellow-Goose / Żółta-Gąska z motywem żółtej gąski; wariant samego symbolu i z nazwą; wersje na jasne i ciemne tło; pliki SVG i PNG z przezroczystością oraz favicon; dodanie do aplikacji demonstracyjnej i README | Logo jest czytelne także jako mała ikona, pliki źródłowe i eksporty są w repozytorium, a aplikacja i README używają spójnego znaku |

Etap 5 częściowo towarzyszy poprzednim: testy i dokumentację dodajemy razem z funkcjami.
Nie rozbudowujemy ORM ponad metody rzeczywiście wymagane przez przykład.
Same testy PHP nie zastępują testów formularzy, trwałości danych ani faktycznej przeglądarki.

## Warunki uznania MVP za gotowe

- Czyste `composer install` i `npm ci`, przygotowanie bazy, build i start według README.
- Strony i endpointy dodaje się w aplikacji, bez zmian w `packages/framework/src/`.
- Jeden kompletny, przetestowany przepływ przeglądarka → PHP → baza → przeglądarka.
- Walidacja, kontrolowane błędy i ochrona mutacji; brak wycieku szczegółów wyjątków do klienta.
- E2E dla CRUD oraz ponownego otwarcia strony; testy konfiguracji i bazy.
- Udokumentowany zakres początkowy: CSR i lokalna demonstracja; kolejny etap dodaje SSR.
- Logo frameworka w repozytorium, aplikacji demonstracyjnej i README, wraz z favicon.

## Poza pierwotnym MVP

SSR, SSG, ISR, wykonywanie JavaScript w PHP, automatyczne RPC „Zero API”,
React Server Components, rozbudowane relacje ORM, generator OpenAPI,
system kont i ról oraz rozbudowane generatory CLI.

Logowanie i autoryzacja zasobów stają się wymagane przed użyciem panelu
jako publicznej aplikacji z prywatnymi danymi lub wieloma użytkownikami.
SSR zostało wybrane i zaimplementowane w kolejnym etapie opisanym powyżej.

## Pierwotnie zaplanowany pierwszy przyrost (zaimplementowany)

Najpierw potwierdzenie obecnego UI w przeglądarce, następnie konfiguracja SQLite,
inicjalizacja tabeli `posts`, obsługa JSON w `Request` i dodawanie wpisu z walidacją.
Pierwszy przyrost kończy się formularzem, który faktycznie zapisuje wpis i pokazuje go na liście.
