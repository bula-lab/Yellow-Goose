# Wdrożenie C1: trwały edytor scen

Baza prac: `1b13761`, pakiety A0–A2 i B1/B2. Naprawa czystej instalacji CI: `351c147`. Właściwe API C1 zaczyna się od `2fcf007`. Prace obejmują etap C1 z [roadmapy](3d-framework-expansion-2026-11-13.md), bez importu CAD z C2 ani funkcji gier.

## Przyrosty

1. `yellow-goose/scene-api`: projekty, role właściciela/redaktora/widza, dokumenty i migracje. Komendy zapisują scenę, receipt i zdarzenie outbox w jednej transakcji PDO. Warunkowy zapis rewizji oraz blokada projektu chronią też równoległą zmianę członkostwa.
2. `@yellow-goose/editor`, port wielu encji i opcjonalny adapter Babylon: lista, picking, inspector, transformacje, hierarchia, ukrywanie, duplikacja, usuwanie i przyciąganie do siatki. Geometria wersji GLB jest pobierana raz na viewport i współdzielona przez instancje.
3. Autosave, kompensujące Undo/Redo, powtórzenia komend po utracie odpowiedzi, konflikt dwóch kart oraz Save/Discard/Cancel. Kolejka rozdziela dokument potwierdzony od lokalnych zmian. Odrzucona operacja nie wymusza porzucenia niezależnych zmian.
4. `examples/scene-editor`: tworzenie projektów i scen, upload przez istniejący assets/jobs, zapis i ponowne otwarcie. Build klienta i SSR, testy samodzielnych instalacji, aktualizacja CI i przegląd integracji.

## Wymaganie → implementacja → sprawdzenie

| Wymaganie | Miejsce | Dowód |
| --- | --- | --- |
| Trwały dokument i jawne jednostki | SceneDocument/SceneInput, schemaVersion 1, RH/Y-up/m | `tests/c1.php`, CGI i ponowne otwarcie w Chromium |
| Właściciel, editor, viewer; bieżące uprawnienia | SceneService + SessionAuthenticator aplikacji | Odebranie członkostwa i wyłączenie konta w `c1-http.py`, nieaktywne kontrolki widza w `c1-browser.py` |
| Wersje modeli należące do projektu | Wstrzyknięty callback AssetService | Niegotowy i obcy asset, import do innego projektu, odmowa pobrania po odebraniu roli |
| Atomowy zapis komendy i odporność na wyścigi | CAS, klucze SQL receipt i outbox, jedna transakcja | 69 asercji SQLite i 66 MySQL, dwa rzeczywiste procesy, rollback po błędzie outbox |
| Ponowienie nie wykonuje drugi raz tej samej operacji | Hash operacji, zakres project/scene/actor/commandId | Powtórzenie po restarcie oraz przerwanie odpowiedzi po commicie w Chromium |
| Konflikt dwóch kart zachowuje lokalną kolejkę | 409 z dokumentem, jawny reload/retry | Testy store i dwie karty Chromium |
| Undo nie odtwarza starej bazy | Nowa komenda z aktualną rewizją i serwerowym inverse | Undo/Redo w przeglądarce oraz równoległe kliknięcia w testach store |
| Gesty nie zapisują każdej klatki | Podgląd silnika, pojedynczy callback po puszczeniu | Rzeczywiste przeciąganie uchwytów translate/rotate/scale w `gesture-browser.py` |
| cm → mm → m | Inspector height → properties.heightMm → rozmiar treści adaptera | 213 cm → 2130 mm → 2,13 m, również po odczycie; skala encji pozostaje osobna |
| Powiadomienia nie stosują zmiany drugi raz | receiveEvent z kontrolą zakresu/revision i uzgodnieniem snapshotu | Zdarzenie przed ACK, duplikat i luka rewizji w testach store |
| Moduł bez CMS/Babylon | Composer scene-api + niezależne npm editor | `c1-packages.py`: fizyczne archiwa, lekki viewport i pełny przykład poza repo |
| SSR bez Node i bez silnika 3D | V8Js, alias engine/multi-engine do stubu | Kontrola bundla SSR i odłączona aplikacja bez node_modules, CGI |

## Przegląd i ograniczenia profilu

Końcowe `GASKA_PACKAGE_BROWSER=1 npm run verify:all` zakończyło się powodzeniem: kontrakty legacy, PHP, SQLite, JS, CGI, samodzielne instalacje i wszystkie testy Chromium, w tym C1. Osobno przeszły `php tests/c1.php --mysql` (66 asercji), C1 na PHP 8.1 (69 asercji) oraz `tests/c1-http.py` w obrazie runtime bez programu Node. Sprawdzenie bundla i fizycznej instalacji nie wymaga node_modules podczas SSR. Ekrany desktop/mobile zostały obejrzane; widok 390 px nie ma przewijania poziomego.

Backend, frontend i niezależne sprawdzenie CI/review zostały rozdzielone między subagentów. Agent integrujący zbudował przykład, testy rzeczywistego HTTP i Chromium, sprawdził MySQL oraz połączył zależności i konfigurację. [Raport niezależnego przeglądu](../reviews/c1-integration-review-2026-11-20.md) opisuje znalezione problemy i testy poprawionych zachowań.

Naprawa B1/B2 została sprawdzona z czystego archiwum, bez istniejącego vendor/node_modules. [Zdalny przebieg `351c147`](https://github.com/bula-lab/Yellow-Goose/actions/runs/44744080804) zakończył się sukcesem. Nie jest to dowód CI dla późniejszych commitów C1; te otrzymają osobny przebieg po publikacji. PR pozostaje ręczny zgodnie z decyzją użytkownika.

Scena ma do 512 encji i 512 KiB. Parametry garażu używają całkowitych mm, a pozycje sceny metrów bez wymuszania siatki mm. Walidacja złożonych transformacji ogranicza skale i rozmiar hierarchii. Import JSON przenosi referencje, nie pliki modeli. Endpointy list w tym profilu nie realizują paginacji dużych bibliotek; taki pomiar i rozszerzenie należą do E.

Brak transportu współedycji live, CRDT, blokowania obiektów i automatycznego merge. Outbox i wejście zdarzeń są portami dla późniejszej integracji. Niezapisana kolejka istnieje w pamięci karty; po pełnym zamknięciu przeglądarki pozostaje stan potwierdzony na serwerze. Referencyjny garaż bez assetu jest bryłą demonstracyjną. Nie przeprowadzono pomiarów produkcyjnego obciążenia ani walidacji CAD.
