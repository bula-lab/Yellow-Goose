# Integracja i weryfikacja B1/B2

Data: 2026-11-20. Baza zadania: 499ca68 na codex/modular-framework-a0-a2.
Dwa subagenty GPT-6.2 Astra z wysiłkiem max realizowały B1 i B2 równolegle.
Root ustalił kontrakty, zintegrował kod i przejrzał rzeczywisty diff obu części.
Wynik: B1 i B2 ukończone dla opisanego pierwszego profilu. Następny etap: C1.
Nie wykonano commita, pusha ani publikacji bibliotek do rejestrów.

[Raport B1](b1-implementation-2026-11-20.md) · [Raport B2](b2-implementation-2026-11-20.md) ·
[Kontrakt wspólny](b1-b2-contract-2026-11-20.md) · [Przegląd integracji](../reviews/b1-b2-integration-review-2026-11-20.md).

## Wynik dla użytkownika

Przykład examples/garage-cms obsługuje logowanie redaktora, formularze i tabele,
upload GLB/postera, przygotowanie pliku przez workera PHP i publikację produktu.
Gość otrzymuje treść i poster przez V8Js SSR, następnie rzeczywisty model Babylon
w przeglądarce. Zmiana wymiarów i koloru aktualizuje podgląd bez wymiany pliku.
PHP potwierdza dozwoloną konfigurację i cenę. Zapytanie z tą konfiguracją trafia do SQLite.

Edycja kolejnego szkicu nie zmienia opublikowanej migawki. Wycofanie ostatniej
publikacji odmawia również kolejnych odczytów jej lokalnego modelu, także z wydanym
wcześniej podpisem. Panel zachowuje aktualne polityki dla loaderów, JSON i API.

Model dostarczony z przykładem jest prostopadłościanem testowym. Ceny i ograniczenia
są fikcyjne; przykład nie stanowi aktualnej oferty Solidne-Garaże ani narzędzia CAD.
[Uruchomienie i przepływ](../../examples/garage-cms/README.md).

## Podział i połączenie

| Obszar | Właściciel kodu | Rzeczywisty konsument |
| --- | --- | --- |
| Kolejka, lease, retry, wyczerpanie prób | yellow-goose/jobs | jobs:work i finalizacja assetu na tym samym PDO |
| Upload, kwarantanna, wersje, walidacja i streaming | yellow-goose/assets | AssetPicker i publiczne signed URL w przykładzie |
| Formularze, tabele, modale, wybór modelu | @yellow-goose/ui | ProductEditor, Materials i publiczna oferta |
| Trwały układ i ochrona niezapisanych zmian | @yellow-goose/react/workspace | Offer.getLayout oraz formularz redaktora |
| Port viewera i Babylon | @yellow-goose/scene, @yellow-goose/babylon | Podgląd oferty, picking i rzeczywiste granice modelu |
| Szkice, materiały, migawki i publikacja | yellow-goose/cms | Redakcyjny panel i publiczny katalog |
| Metadane strony | react-bridge PageData i nawigacja | HTML oraz title/description/canonical po zmianie strony |
| Integracja dostępu | Garage/AssetIntegration.php | CMS AssetCatalog, polityki magazynu i moduły jobs/assets |
| Transport binarny i schemat jednostek | core Request::bytes, resources unit/group | Fragmenty 256 KiB oraz formularz cm↔mm |

Graf ma 11 bibliotek PHP i 50 jednoznacznych właścicieli klas. CMS nie zależy od
konkretnego magazynu ani silnika; port AssetCatalog otrzymuje adapter aplikacji.
Fasada framework zachowuje poprzednie zależności i nie instaluje CMS/Assets domyślnie.
Nowe zależności przykładu są rozwojowe w głównym demo, a produkcyjne w jego własnym manifeście.
Nie przepisano ORM ani aplikacji notatnika.

## Dowody akceptacji

| Wymaganie | Producent i konsument | Zaobserwowany wynik |
| --- | --- | --- |
| Bezpieczny, większy od JSON upload | Request::bytes → AssetModule → AssetService | GLB z 90 000 wierzchołków przekroczył 1 MiB i przeszedł w częściach; prawdziwe CGI zachowało bajt ASCII 0, binarne bajty i limit 256 KiB |
| Zamrożenie i ponowienie | LocalStorage → complete → Queue | Stary token nie zmienił ready; dwie finalizacje zwróciły jedną wersję, dwa workery podjęły jeden job |
| Atomowość i awarie | Queue/Lease → callback domenowy | Rollback producenta usunął job; wygasły worker nie opublikował wyniku; wyczerpane próby ustawiły wersję failed |
| Ograniczenie zasobów | GlbProfile/ImageProfile → worker | Odrzucono URI, złe indeksy, cykle, nadmierne złożone skale, uszkodzony poster i przekroczone budżety; prywatne pliki nie pojawiły się w props |
| Formularz i 422 | Schema → Form → PHP | Prawidłowy typ i wybór jednostki; błędy przy właściwych polach, odrzucone kwoty i pola klienta |
| Tabele | QueryOptions → Catalog → DataTable | Sort, filtr i kursor po stronie serwera; publiczne wyszukiwanie nie znalazło nowego szkicu |
| Prywatny szkic i aktualna rola | Catalog/Accounts → HTML, JSON, API | Dwa cookie jars; gość bez dostępu, zmiana roli odebrała dostęp tej samej sesji |
| Publikacja i wycofanie | CAS rewizji → immutable snapshot → asset policy | Dwa zapisy dały sukces i 409; nowy szkic pozostał prywatny; unpublish wycofał ofertę oraz istniejący lokalny URL |
| Stream i zakres | FileResponse → rzeczywiste CGI | Cały GLB oraz Range 4–7 zgadzały się z zamrożonym wejściem; HEAD nie wysłał body |
| SSR i hydracja | PageData/V8Js → React | Poster i tekst bez JS; brak wykonania surowych tagów, zgodne useId oraz metadane po nawigacji |
| Stały viewport | getLayout → WorkspaceShell → Babylon | Ta sama kamera/canvas i jedno pobranie modelu przez modal, tabelę i historię; inny projekt zwolnił stary silnik |
| Kontrola wejścia | Dialog → fokus i kamera | Tab/Shift+Tab/Escape, przywrócenie fokusu, pisanie i strzałki bez sterowania sceną |
| Niezapisany formularz | leave guard → save/discard/cancel | Anulowanie zachowało URL, zapis zakończył się przed wyjściem, odrzucenie zwolniło viewport |
| GPU i błędy | adapter lifecycle → użytkownik | Widoczna geometria, PNG capture, brak GPU, zły model, anulowanie fetch i odtworzenie kontekstu |
| Stały rozmiar obrazu | Viewport wrapper → SSR/loading/WebGL | Kwadratowy poster 320×320 nie zmienił powierzchni 884×497,25 px między stanami |
| Mobilny układ | CSS edytora i oferty → Chromium 390px | Brak poziomego przewijania; poprawka rozmiaru fieldset/select zachowała zawartość |

## Jednostki i cena przez cały przepływ

| Granica | Wejście | Wynik |
| --- | --- | --- |
| Schemat → HTML select | heightMm=2130, factor=10 | 213 cm; wybór 240 cm ma wartość kanoniczną 2400 mm |
| Form → żądanie potwierdzenia | 350×600×213 cm, kolor forest | 3500×6000×2130 mm i #41634c |
| Viewport → Babylon world bounds | Te same wartości kanoniczne | Rzeczywiste 3,5×6×2,13 m; pomiar nie jest kopią props |
| PHP → odpowiedź | 400000+84000+110000+12500+18000 | amountMinor=624500, currency=PLN |
| Odpowiedź → ekran i enquiry | 624500 gr, wysokość 2130 mm | 6245.00 PLN; SQLite zachowała kwotę i jednostki, powtórzenie nie dodało drugiego wpisu |

## Wykonane kontrole

Pełna komenda `GASKA_PACKAGE_BROWSER=1 npm run verify:all` zakończyła się exit 0
w przygotowanym obrazie PHP 8.4/V8Js/GD, Node 24 i Chromium. Obejmuje:

- 17 kontraktów legacy, 145 SQLite, 20 nawigacji, 72 modułów/security/resources;
- 13 asercji transportu/schematu, 89 B1, 43 B2 i 5 rzeczywistych przypadków binarnego CGI;
- 14 testów JavaScript, 27 V8Js SSR, 37 CGI dotychczasowego entry pointu;
- CGI CMS z rzeczywistym uploadem, workerem, dwiema sesjami i wycofaniem publikacji;
- fizyczne instalacje archiwów starego i nowego frontendu, hydrację i useId;
- CMS bez Assets/React/GD, Assets/Jobs bez CMS/React i bez uruchamiania procesów PHP;
- UI bez zainstalowanego Babylon i pełny przykład poza monorepo z archiwów Composer/npm;
- E2E starego notatnika, w tym CRUD, historia, odtworzenie po restarcie, mobile i HMR;
- rzeczywisty WebGL obu nowych fixture/przykładu, ochronę formularza i kontrolę obrazu.

Po końcowej poprawce rozmiaru postera ponownie uruchomiono pełny browser B1 oraz
build, CGI i browser B2. Kontrola użyła kwadratowego postera również bez JavaScriptu.

Dodatkowe niezależne kontrole:

- PHP 8.1.34 z GD: wszystkie kontrakty i testy PHP powyżej, w tym 89 B1 i 43 B2 — exit 0;
- PHP 8.5 lokalnie: testy agentów, kontrakty i granice transportu — PASS;
- MySQL 8.4: 15 asercji integracji CMS/Assets/Jobs, w tym rollback joba, lista wersji,
  sort/filtr, rewizje oraz wycofanie publicznego dostępu — exit 0;
- obraz runtime bez binarki Node: 27 SSR, 37 CGI i kompletny CGI CMS/upload/worker — exit 0;
- JSON, PHP lint, YAML workflow, sync-legacy, granice pakietów i diff whitespace — PASS.

Zdalnego GitHub Actions nie uruchamiano. CI rozszerzono o GD, nowe testy PHP,
integrację MySQL oraz pełne ścieżki archiwów i przeglądarki.

## Granice pierwszego profilu

Storage jest lokalny i wymaga wspólnego prywatnego katalogu oraz blokad dla HTTP
oraz workera. Operator uruchamia worker CLI lub cron i ustawia jego budżet.
Nie wdrożono S3/CDN, automatycznego generowania postera na serwerze, importu CAD
ani współedycji scen. Statyczny GLB ma limit 16 MiB i jawnie ograniczony format;
nie obsługuje kompresji, rozszerzeń, animacji ani dowolnych URI.

Browser używał programowego WebGL SwiftShader. Wynik potwierdza funkcjonalność,
nie wydajność fizycznego telefonu. Biblioteki pozostają lokalne i nie są opublikowane.
Kolejne prace dotyczą C1: dokumentu sceny, zapisu, autosave, undo oraz konfliktów edycji.
