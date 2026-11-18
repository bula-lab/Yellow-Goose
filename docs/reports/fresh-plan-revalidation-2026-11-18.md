# Niezależna rewalidacja planu Yellow-Goose

Data: 2026-11-18. Baza kodu: b1e8000, branch main. Sprawdzono pełny plan,
architekturę pakietów i porównanie ekosystemów, a następnie wcześniejszą walidację.
Wymagania przekazane do tej rewalidacji oceniono osobno od tez dokumentów.
To przegląd dokumentacji i kodu bazowego, bez implementacji nowych funkcji.

## 1. Werdykt

Przed korektami: **FAIL dla bezwarunkowego rozpoczęcia A1**. Re-exporty starych
zasobów vendor do nowych pakietów npm nie zapewniały deklarowanej zgodności
konsumenta, którego package.json nie zawiera tych pakietów. A0 można było rozpocząć.

Po korektach: **PASS WITH WARNINGS dla roadmapy i rozpoczęcia A0**. A1 ma techniczną
bramkę kompletnej macierzy eksportów/konsumentów A0 i określoną ścieżkę zgodności
legacy. Nie potrzeba ponownej decyzji o produktach C/D, żeby wykonać A0/A1.
Nie stwierdzono otwartego Critical blokującego A0. Dokumenty nie są jeszcze
szczegółowymi planami implementacyjnymi wszystkich fal B–D.

Kierunek PHP + React/V8Js pozostaje wykonalny przy zachowaniu ograniczeń renderera.
Opcjonalne pakiety mogą obsłużyć wskazane produkty; framework nie zastąpi silnika
GPU, konwerterów CAD ani autorytatywnej symulacji. Wydajność i gotowość produkcyjna
tych integracji pozostają do wykazania.

## 2. Niezależna macierz wymagań

Źródłem R1–R7 jest zlecenie użytkownika przekazane do tej rewalidacji. Dokumenty
planowe są ocenianym rozwiązaniem, nie źródłem dodatkowych wymagań użytkownika.

| ID | Wymaganie | Fala / decyzja | Oczekiwany wynik i scenariusz | Dowód / ograniczenie |
| --- | --- | --- | --- | --- |
| R1 | WWW/CMS/konfigurator jak Solidne-Garaże | B1/B2; rekomendowany pierwszy produkt | Redaktor publikuje, gość widzi SSR/poster, zmienia parametry i zapisuje zapytanie; cena zatwierdzona przez PHP | Plan §9A/§12; stary projekt potwierdza wzorce pól i konfiguratora, nie gotowy moduł YG |
| R2 | Gry 2D i 3D, rankingi i modale | UI/workspace B1; D1/D2 | Modal/ranking zachowuje viewport; 2D nie wymaga silnika 3D; wynik rankingu pochodzi z serwera | Plan §4/§7/§9B; client.jsx:29 obecnie wymienia całą stronę |
| R3 | Strategia jak OGame oraz gra na żywo | D1 i osobno D2; oba w zakresie | Czas serwera i atomowy koszt; osobno wejścia/symulacja, fencing, reconnect i wynik raz na matchId | Plan §9B/§12; brak dzisiejszego runtime gry nie jest wadą roadmapy |
| R4 | Portal domów/planów/ogrodów, edycja sceny, GLB/glTF i CAD/BIM | B1/C1/C2; próbki i formaty przed C2 | Viewer gotowego modelu, zapis komendy/CAS/undo; IFC zachowuje jednostki i źródłowe ID | Plan §5–6/§9C; RVT/DWG wymagają osobnych narzędzi i próbek, bez obietnicy bezstratności |
| R5 | Node tylko build/dev | Wszystkie fale, także produkcyjne workery | Rzeczywisty SSR w PHP bez binarki Node; upload, optymalizacja, import i gra także bez Node | V8JsRenderer.php:19–48; Dockerfile runtime bez binarki, z libnode.so jako biblioteką V8; narzędzia workerów dopiero kandydaci |
| R6 | Realne biblioteki/pakiety Composer/npm i opcjonalne moduły | A0/A1, potem pakiety potrzebne konsumentowi | Instalacja core-only, CSR bez V8Js, legacy bez nowych npm, nowe npm poza monorepo; CMS bez scene/game | Plan pakietów §2–7/§9; dzisiaj tylko jeden Composer i prywatny projekt npm |
| R7 | Porównanie z Symfony/Laravel/Next i innymi | Bieżące zadanie | Core odróżniony od integracji i osobnych usług; wariant SSR nie łamie wymogu PHP | Porównanie §1–7, oficjalne źródła poniżej; nie wykonano wspólnej instalacji zależności |
| R8 | Niezależnie rewalidować i poprawić dokumenty | Bieżące zadanie | Zweryfikowane ustalenia, zmienione cztery wejścia i niniejszy raport; bez kodu/commitów/pusha | Zakres plików i kontrole w §5–6 |

Auth, transakcje, jobs, bezpieczny upload i zgodność builda to zależności tych
produktów. Współedycja na żywo, pełna konstrukcyjna edycja CAD i gotowe SLA nie
wynikają automatycznie z wymagania edycji sceny; plan zawiera dla nich osobne bramki.

## 3. Ustalenia i wprowadzone poprawki

Waga odnosi się do luki planu przed tą korektą. „Zamknięte w planie” nie oznacza
wykonanej implementacji ani zaliczonego testu przyszłego modułu.

| ID | Waga / etap | Dowód i skutek | Poprawka / status |
| --- | --- | --- | --- |
| F1 | CRITICAL, A1 | Wejściowy plan pakietów §3/§7 obiecywał re-exporty vendor i niezmienione App. app/client.jsx:2, app/server.jsx:1 i vite.config.js:2 importują vendor; package.json oraz tests/package.py:33–40 nie wymagają @yellow-goose/*. Sam Composer nie dostarczy nowego npm | Fasada archiwizuje samowystarczalne resources generowane przy wydaniu; stare entry/CLI działają bez nowych npm. Osobny fixture legacy i nowego konsumenta; plan pakietów §7/§9. ZAMKNIĘTE W PLANIE |
| F2 | WARNING, A1 | Plan wymieniał peers React, ale nie prywatny renderer. resources/vite.mjs:11 wybiera wewnętrzny plik CJS; docs/ssr.md już wymaga ponowienia testów przy aktualizacji | Zachować dokładny zestaw React/React DOM 19.3.1, Vite 8.3.8, plugin-react 6.3.0; brak entry kończy build; rzeczywisty V8Js/useId/hydracja przed rozszerzeniem wsparcia. ZAMKNIĘTE W PLANIE |
| F3 | WARNING, A1/A2 | Application.php:13–21 wymaga Assets; cały dispatcher jest w bridge. Hasło API-only nie rozstrzygało, kto wykonuje handler po wydzieleniu samego core | A1 core-only to router/HTTP i mały bootstrap aplikacji; obecna Application zachowuje kontrakt w bridge. Dispatcher modułów dopiero A2. ZAMKNIĘTE W PLANIE |
| F4 | WARNING, B1/D2 | Plan §11 wymieniał npm workspace, którego nie było w tabeli pakietów; realtime-openswoole zależało od kontraktów bez właściciela | Workspace jako @yellow-goose/react/workspace; port viewera w scene; jawny właściciel portów realtime przy pierwszym konsumencie push D1/D2 i osobny adapter. D1 z pollingiem nie wymaga tego pakietu. Reguły punktów/ekonomii pozostają w App. ZAMKNIĘTE W PLANIE |
| F5 | WARNING, B1/B2 | Porównanie pozwalało wybrać panel w B2, choć B1 już budowało Form/DataTable/AssetPicker | Wybór małego UI YG albo React Admin przed komponentami B1; niezależny storage/workspace może powstawać wcześniej. Pytanie i rekomendacja w planie §13. ZAMKNIĘTE W PLANIE; wybór przed B1 |
| F6 | WARNING, B1 | Worker miał przygotowywać poster bez wskazania renderera; link do kompresora nie potwierdza runtime bez Node. V8Js nie ma GPU; Node CLI nie staje się dozwolony jako osobny worker | Jawny zakaz Node w pipeline uploadów; natywny gltf_validator/gltfpack jako kandydaci. Rekomendacja gotowego postera w pierwszym profilu; automatyczny wymaga wyboru i testu renderera. ZAMKNIĘTE JAKO BRAMKA B1 |
| F7 | WARNING, B2 | Plan §9A wymieniał draft/published i przyszły rich text bez pokrycia SSR/page JSON/cache. Application.php:84–91 dodaje csrfToken do payloadu i używa wspólnego loadera obu reprezentacji | Publiczny odczyt tylko published; preview/lista/API/URL stosują politykę; private,no-store na start, tekst/dozwolone bloki, sanitizacja przy rich text. Scenariusz dwóch sesji i T8. ZAMKNIĘTE W PLANIE |
| F8 | WARNING, B1 | Główna tabela pytań infrastrukturalnych przesuwała je dopiero przed realtime/importem, choć pochodne potrzebują workera już w B1 | Wybór nadzorowanego workera albo cyklicznego CLI/cron przed pochodnymi B1; osobno stałe pokoje D2 i konwertery C2. ZAMKNIĘTE W PLANIE |

Nie potwierdzono dodatkowych wad w już poprawionych zasadach zamrożenia uploadu,
CAS, deduplikacji receipts/outbox na jednym połączeniu i fencing pokoju. Są to
poprawne wymagania projektowe, nadal wymagające testu rzeczywistego adaptera SQL/storage.
F7 nie jest zgłoszeniem istniejącej podatności niezaimplementowanego CMS.

## 4. Weryfikacja źródeł i jednostek

Oficjalne źródła potwierdzają niezależne komponenty Composer w
[Symfony](https://symfony.com/doc/6.4/components/using_components.html), integrację
[pakietów Laravel](https://laravel.com/docs/13.x/packages) i podział admina Django
od interfejsu procesu w [Django admin](https://docs.djangoproject.com/en/6.1/ref/contrib/admin/).
[Filament 5](https://filamentphp.com/docs/5.x/introduction/overview) należy do stosu
Laravel/Livewire; nie jest biblioteką React dla YG.

[Symfony 6.4](https://symfony.com/releases/6.4) wymaga PHP >=8.1,
[Symfony 7.4](https://symfony.com/releases/7.4) >=8.2, a
[Laravel 13](https://laravel.com/docs/13.x/releases) >=8.3. Zgodność wersji
adaptera trzeba rozwiązać w Composer; sama inspiracja frameworkiem nie podnosi minimum core.

[Inertia SSR](https://inertiajs.com/docs/v3/advanced/server-side-rendering) wymaga
produkcyjnego runtime JS; dokumentacja dopuszcza Bun zamiast domyślnego Node.
[Next layout](https://nextjs.org/docs/app/api-reference/file-conventions/layout) i
[lazy loading](https://nextjs.org/docs/app/guides/lazy-loading) potwierdzają wzorce
layoutu i client-only, lecz nie renderer PHP. [Static export](https://nextjs.org/docs/app/guides/static-exports)
ma inną semantykę niż dynamiczny SSR YG. [React renderToString](https://react.dev/reference/react-dom/server/renderToString)
nie czeka na asynchroniczne dane ani nie zapewnia streamingu.

[AWS](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-presigned-url.html)
potwierdza powtarzalne użycie podpisu i nadpisanie klucza. Plan prawidłowo wymaga
zamrożonej wersji przed workerem i nie utożsamia ETag z checksum całego pliku.
[glTF](https://registry.khronos.org/glTF/specs/2.0/glTF-2.0.html) potwierdza metry,
Y-up, układ prawoskrętny i możliwość URI. Sam GLB nie rozwiązuje kontroli odwołań.
[IfcOpenShell](https://docs.ifcopenshell.org/ifcopenshell/geometry_iterator.html)
i [OpenSwoole](https://openswoole.com/docs/modules/swoole-websocket-server)
potwierdzają dostępność odpowiednich narzędzi, nie SLA ani test zgodności z YG.

[Natywny walidator](https://github.com/KhronosGroup/glTF-Validator#cli-tool) może być
zbudowany jako plik wykonywalny przez Dart; istnieje także odrębny wariant npm.
[gltfpack](https://github.com/zeux/meshoptimizer/blob/master/gltf/README.md)
dostarcza binarki natywne i może zmieniać hierarchię modelu. Wymaga to testu
mapowania BIM. [glTF Transform CLI](https://gltf-transform.dev/cli) pozostaje
narzędziem build/dev w tym projekcie. Nie uruchamiano tych narzędzi w rewalidacji.

Ryzyko surowego HTML wynika z [kontraktu React](https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html).
[Symfony cache/sesje](https://symfony.com/doc/current/http_cache.html#http-caching-and-user-sessions)
stanowi sprawdzony wzorzec prywatnych odpowiedzi; dodanie Validator do YG nie
przeniesie automatycznie polityki HTTP cache.

Jednostki zweryfikowano niezależnie: 213 cm → 2130 mm → 2.13 m → 213 cm;
przykładowe porównanie 2130 <= 2200 odbywa się w mm. 20 Hz = 50 ms/tick,
10 Hz = 100 ms/snapshot. To arytmetyka, bez benchmarku ani normy dokładności CAD.
`rg` nie wykrył heightMm/createSelector/WorkspaceShell/ClientOnly/ResourceDefinition
w app/packages/tests. Nie ma istniejących konsumentów ani migracji heightMm;
selector_read_sites.py nie ma zastosowania. Zachowano macierz przyszłych odczytów
we wcześniejszej walidacji, bez traktowania jej jako testu produkcyjnego UI.

## 5. Dokładny zakres edycji

- Plan funkcjonalny: uporządkowana bramka A0→A1, potwierdzone historyczne źródła
  CMS/cen/Babylon, właściciel workspace/realtime, pipeline bez Node i wybór postera,
  publikacja/cache/HTML CMS oraz pytania infrastruktury/panelu przed B1.
- Plan pakietów: samowystarczalne legacy resources, osobne fizyczne instalacje
  legacy/npm, ograniczenia prywatnego renderera React, jawny konsument core,
  własność workspace/realtime i granice reguł aplikacji.
- Porównanie: dopisane ograniczenia renderToString/prywatnego CJS, produkcyjne
  workery bez Node, obowiązki CMS/cache i wcześniejszy wybór UI.
- Wcześniejsza walidacja: oznaczona jako historyczna z odsyłaczem do tego raportu;
  uaktualnione T1/T2, dodane T8 oraz bramki panelu/postera/infrastruktury.
  Nie przepisano jej historycznych wyników testów na nowe dowody.
- Dodano niniejszy raport. Nie zmieniono kodu, manifestów, CI ani danych.

Zachowano styl istniejących tabel i konkretne terminy autora: fasada, roadmapa,
workspace, commandId, schemaVersion. Poprawiono jedną usterkę gramatyczną
„Ustalenia są dotyczą”. Nie wykonano mechanicznej redakcji niezmienionych fragmentów.

## 6. Kontrole i pozostałe ryzyka

Wykonano:

- Odczyt instrukcji repo/globalnych i pełną lekturę trzech dokumentów planowych
  przed wcześniejszym raportem walidacji. Przegląd perspektyw architektury,
  bezpieczeństwa, QA i wykonalności wykonano lokalnie, bez dalszej delegacji.
- Przegląd Runtime, Application, routera, Request/Response, Session, Assets,
  V8JsRenderer, klienta/SSR/Vite, PDO/Migrator/CLI oraz fixture konsumenta.
- Kontrolowany odczyt trzech wskazanych plików starego projektu Solidne-Garaże,
  bez konfiguracji dostępu; cms_formularze:64–68, reguły kreatora:146–282,
  scena Babylon:314–323. To osobne źródło historyczne.
- `npm test`: PASS, 145 asercji SQLite i 20 nawigacji.
- `node --test tests/*.test.mjs`: PASS, 14 testów.
- Weryfikację oficjalnych źródeł wskazanych w §4, obliczeń jednostek,
  lokalnych linków Markdown i diffu wobec kopii wejściowej.

Lokalny PHP 8.5.12 nie ma V8Js. Nie wykonano natywnego SSR, hydracji Chromium,
MySQL współbieżnego, benchmarków GPU, CAD, storage ani przyszłych instalacji pakietów.
Zielone obecne testy nie dowodzą działania B1–D2. Nie wykonano commita ani pusha.

Przed A1 pozostaje macierz eksportów i platform z A0; podczas A1 fizyczne archiwa,
legacy bez nowych npm, nowe npm poza monorepo i rzeczywisty SSR/hydracja.
Przed B1: wybór panelu, nadzorowanego workera/cron, polityki dostępu do assetów
oraz postera. Przed C2: formaty/próbki, jednostki i narzędzia importu. Przed D1/D2:
reguły rankingu, profil obciążenia i odzyskanie stanu pokoju. Każda decyzja ma pytanie,
opcje/rekomendację i właściciela w planie §13 oraz wcześniejszej walidacji §7.
