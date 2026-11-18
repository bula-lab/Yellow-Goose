# Wykonanie i walidacja A0–A2

Data: 2026-11-18. Repozytorium: yellow-goose. Baza: main, b1e8000.
Werdykt: A0 PASS, A1 PASS, A2 PASS. Następny etap roadmapy: B1.
Zmiany są lokalne; nie wykonano commita ani pusha.

## A0: kontrakt przed ekstrakcją

Przed przeniesieniem kodu zapisano 17 kontraktów klas w tests/contracts/legacy.json:
metody publiczne/chronione, parametry i wartości domyślne, właściwości oraz stałe.
Zamrożono entry klienta, serwera, Vite i manifest npm w tests/fixtures/legacy.
[Macierz A0](a0-compatibility-2026-11-18.md) przypisuje właścicieli, eksporty,
wymagania platformy, zawartość archiwów i konsumentów.

Walidacja przed ekstrakcją: 145 SQLite, 20 nawigacji, 14 JavaScript oraz rzeczywisty
build klienta/SSR, 24 asercje V8Js i niezależny konsument Composer — PASS.
Pierwsza próba kontenera użyła zależności macOS; rozwiązano to przez npm ci
w osobnym wolumenie node_modules dla Linuksa. Nie nadpisano lokalnych zależności.

A0 zakończono przed zmianą odpowiedzialności klas w A1.

## A1: dystrybucja i zgodność

Wydzielono core, database, react-bridge i ssr-v8js oraz npm react/vite.
Framework jest fasadą Runtime/CLI. Zachowano wszystkie stare FQCN; 11 plików
PHP przeniesiono bez zmiany treści. Rozszerzenia Application, Migrator i Session
należą już do A2, a V8JsRenderer do kontroli ABI w A1.

Źródłem frontendowym jest packages/js. scripts/sync-legacy.mjs generuje lokalne
zasoby fasady. Nie dodano re-exportów wymagających @yellow-goose w starej aplikacji.
Nowe pakiety mają eksporty npm i dokładne peer versions dotychczasowego stosu.
Build sprawdza istnienie synchronicznego renderera React i zapisuje ABI/protocol 1.
Renderer odrzuca jawnie niezgodne wersje; nowy ścisły profil odrzuca brak metadanych,
a domyślna fasada nadal akceptuje stare buildy.

Walidacja A1 przed A2:

- dwie fizyczne instalacje archiwów Composer/npm poza repo: legacy i nowe eksporty;
- legacy bez pakietów @yellow-goose i bez współdzielenia node_modules;
- oba buildy, CLI, loader, API, escapowanie, rzeczywisty SSR i useId;
- Chromium: hydracja, zgodne useId i interaktywny licznik w obu konsumentach;
- niezależny core i CSR przez PHP -n, bez PDO i V8Js;
- 27 asercji V8Js i pełne E2E notatnika: SSR bez JS, CRUD, historia, błędy,
  restart, mobile oraz HMR — PASS.

Test archiwów początkowo wymagał ZIP nieobecnego w obrazie. Zmieniono format na tar,
bez dodawania rozszerzenia do produkcyjnego PHP. Pełna fizyczna instalacja przeszła.
Nie opublikowano pakietów do zewnętrznych rejestrów.

## A2: moduły, bezpieczeństwo i resources

| Wymaganie | Kod produkcyjny | Konsument i dowód |
| --- | --- | --- |
| Jawne moduły i graf | core/src/Module/Module.php, Registry.php | Test brakujących/zdublowanych ID, cykli, kolejności i zamrożenia rejestru |
| Usługi, komendy i właściciele | Registry: service/get/command/run/owners | Leniwe usługi, cykle usług, konflikt nazw oraz fizyczne CLI konsumenta |
| Dispatcher bez React/PDO | core/src/Http/Kernel.php | Core-only z archiwum, PHP -n; Request/params, GET/HEAD, 404/405 i sanitizacja 500 |
| Bezpieczny boot tras | Registry::route i Kernel | Konflikty literal/param/HEAD, nieznana polityka, mutacja bez polityki, brak authorizera odrzucane |
| Konta i sesje | security: Account, UserProvider, SessionAuthenticator | Poprawne/błędne logowanie, rotacja sesji/CSRF, wyłączenie konta i logout |
| Tożsamość i polityki | IdentityProvider, Gate, RequestAuthorizer, CsrfGuard | Brak zaufania do klienta; 401/403 i brak wywołania zapisu przy odmowie |
| Jawne zasoby i query | ResourceDefinition, QueryOptions, ResourceModule | Zakres identity, allowlist pól, sort/filtry, limit/cursor i schemat; PHP -n bez PDO/React/Symfony |
| Walidowane akcje | Action, Validator, opcjonalny SymfonyValidator | 422 z errors, nieznane pola i typy; 400/413/415; poprawna akcja dociera do handlera |
| Integracja fasady | Runtime::modules, Application, Cli | config/modules.php, moduł API, help/komenda i db:migrate z fizycznie zainstalowanego archiwum |
| Migracje modułów | database/src/Database/Migrator.php | Kolejność zależności, owner:ID, zachowany basename App, blokada, rollback SQLite i retry |
| Równoległe migratory | Blokada SQLite / GET_LOCK MySQL | Dwa procesy: jeden wykonuje obie migracje, drugi zero; powtórzenie niczego nie uruchamia |
| Brak DDL przy boot | Registry::migrations, Runtime | Pusta baza po uruchomieniu aplikacji; zmiana dopiero po jawnej komendzie |
| Zgodność plikowego API | react-bridge/src/Http/Application.php | Legacy API działa obok modułu; dzielenie namespace przerywa start |

Security i resources są oddzielnymi pakietami. Adapter Symfony 6.4 jest opcjonalny;
resources nie wymaga go ani bazy. Root demonstracji instaluje te biblioteki w require-dev,
a aplikacja, która ich używa na produkcji, deklaruje je w require.
Przykład examples/modular-api pokazuje konta, schemat, query/action i CLI bez React.
Akcja przykładu zwraca zaakceptowane dane, bez deklaracji trwałego zapisu.

## Walidacja i poprawki

Test kontraktów ujawnił różnicę refleksji PHP 8.4/8.5: self oraz pełna nazwa klasy.
Dodano normalizację tego samego typu bez zmiany utrwalonych sygnatur.
Przegląd uzupełnił odrzucanie nakładających się tras podczas boot, obowiązek
polityki mutacji, sprawdzanie błędu startu/rotacji sesji i listowy format items.
Sprawdzono ponownie konkretne ścieżki oraz pełną regresję.

| Wykonana kontrola | Wynik |
| --- | --- |
| GASKA_PACKAGE_BROWSER=1 npm run verify:all w PHP 8.4/V8Js, Node 24 i Chromium | Exit 0; pakiety, buildy, runtime, HTTP i przeglądarka PASS |
| php tests/contracts.php | 17 klas PASS; również PHP 8.1/8.4/8.5 |
| php tests/run.php | 145 SQLite i osobno 145 MySQL 8.4 PASS |
| php tests/navigation.php | 20 PASS |
| php tests/modules.php | 72 PASS; również PHP 8.1/8.4/8.5 |
| node --test tests/*.test.mjs | 14 PASS |
| php tests/ssr.php | 27 PASS; również obraz bez Node i z wyłączonym wykonywaniem procesów PHP |
| python3 tests/cgi.py | 37 PASS; również runtime bez Node |
| python3 tests/modules-cgi.py | 20 PASS: dwa cookie jars, login/logout, rotacja, odmowy i poprawna akcja |
| python3 tests/migrations.py | SQLite i MySQL 8.4: współbieżność, kolejność, powtórzenie PASS |
| GASKA_PACKAGE_BROWSER=1 python3 tests/package.py po dodaniu testu fasady | Exit 0; legacy/npm SSR+hydracja, config/modules/CLI/migracje i profile core/resources/CSR PASS |
| PHP 8.1.34: contracts/run/navigation/modules | Exit 0; minimum wersji potwierdzone |
| PHP lint, composer validate, check-packages, sync-legacy --check, workflow YAML, git diff --check | PASS; znane ostrzeżenia manifestu demo: brak licencji i @dev |

Macierz CI rozszerzono o sprawdzanie granic pakietów, migracje MySQL i hydrację
fizycznych konsumentów. Nie uruchamiano zdalnego GitHub Actions; powyższe wyniki
pochodzą z wykonanych lokalnie poleceń i kontenerów. Tymczasowy kontener MySQL usunięto.

## Granice wykonania

A2 zapewnia kontrakty i działających konsumentów; nie dodaje jeszcze panelu CMS,
Form/DataTable/Dialog, trwałego WorkspaceShell, uploadów 3D ani zadań w tle.
To zakres B1/B2. Reset hasła, MFA i rejestracja użytkowników nie są częścią A2;
repozytorium kont oraz reguły dostępu do konkretnego projektu należą do aplikacji.
Baza demonstracji, .env i wcześniejsze nieśledzone raporty nie zostały usunięte.
