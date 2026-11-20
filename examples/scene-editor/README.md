# Scene studio

Referencyjny edytor wielu modeli: projekty, sceny, instancje GLB, przesuwanie, obracanie, skalowanie, hierarchia, inspector, autosave i historia komend. HTTP, autoryzacja i SSR działają w PHP. Node jest potrzebny do zbudowania klienta i bundla SSR.

## Uruchomienie

W głównym katalogu repozytorium, z PHP >= 8.1, PDO SQLite, GD i V8Js oraz Node >= 24:

```sh
composer install
npm ci
npm run build:scene

export SCENE_DATA_DIR="$PWD/examples/scene-editor/var"
export SCENE_SIGNING_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
read -s 'SCENE_EDITOR_PASSWORD?Hasło redaktora (12–72 bajty): '
export SCENE_EDITOR_PASSWORD
php examples/scene-editor/console.php migrate
php examples/scene-editor/console.php seed
php -S 127.0.0.1:8082 -t examples/scene-editor/public examples/scene-editor/public/router.php
```

Przykład `read -s` powyżej używa zsh. Dla innej powłoki ustaw hasło odpowiednim mechanizmem wejścia interaktywnego. Logowanie: `editor` i podane hasło. Ponowne `seed` zachowuje istniejące konto. Katalog danych musi być poza `public`; klucz podpisywania zachowaj pomiędzy uruchomieniami. Bez rozszerzenia V8Js można uruchomić demonstrację z `GASKA_SSR=0`; to tryb CSR.

Utwórz projekt i scenę. **Add garage** dodaje bryłę demonstracyjną z wysokością w cm; nie jest gotowym modelem architektonicznym. Aby dodać własny model, na stronie projektu prześlij statyczny GLB oraz poster PNG/JPEG. Przykładowe pliki testowe to `tests/fixtures/b1/box.glb` i `poster.png`. Przetwórz zadanie w drugim terminalu, z tymi samymi zmiennymi środowiska:

```sh
php examples/scene-editor/console.php jobs:work 10
```

Odśwież projekt i otwórz scenę. **Add model** tworzy instancję wybranej gotowej wersji. Kolejne instancje współdzielą geometrię, zachowując osobne transformacje. Nie trzeba generować ani wysyłać GLB po przesunięciu obiektu.

## Edycja i zapis

Wybierz obiekt na scenie lub liście. Uchwyty działają w trybach Translate, Rotate i Scale. Snap to grid przyciąga przesunięcie do 0,25 m, obrót do 15°, skalę do 0,1. Gest zmienia podgląd lokalnie, a komenda trafia do kolejki po puszczeniu uchwytu. Inspector pokazuje metry, stopnie i bezwymiarową skalę. Wysokość garażu 213 cm zapisuje się jako `heightMm: 2130`, a silnik odtwarza 2,13 m przed lokalnym skalowaniem.

Pola inspectora najpierw pokazują podgląd. **Apply changes** zatwierdza je do kolejki; **Save** zatwierdza i czeka na zapis. Autosave scala sąsiednie oczekujące przesunięcia. Undo/Redo wysyłają nowe komendy sprawdzane przez serwer. Zmiana rodzica zachowuje lokalną transformację; nie oznacza automatycznego zachowania pozycji światowej. Przed usunięciem rodzica trzeba przenieść lub usunąć jego dzieci.

Po konflikcie dwóch kart pozostają lokalne zmiany. **Reload server version** je odrzuca. **Retry on latest revision** wymaga jawnej decyzji i ponawia lokalne pola na aktualnym dokumencie; nie jest automatycznym scalaniem. Cofanie zaczyna wtedy nową historię. Utrata odpowiedzi HTTP zachowuje identyczną komendę do ponowienia. Definitywnie odrzuconą zmianę można usunąć wraz z zależnymi operacjami, zachowując pozostałą kolejkę.

Przy opuszczaniu sceny wybierz Save, Discard albo Cancel. Pełne przeładowanie dokumentu korzysta z ostrzeżenia przeglądarki. Niezapisane zmiany nie przetrwają zamknięcia przeglądarki; trwały dokument obejmuje komendy potwierdzone przez serwer.

## Dane i dostęp

`yellow-goose/scene-api` ma własne migracje i korzysta z PDO oraz security. Nie wymaga CMS, renderera ani konkretnego magazynu modeli. Aplikacja wstrzykuje kontrolę gotowości i przynależności wersji assetu. `@yellow-goose/editor` nie zależy od Babylon; można przekazać własny viewport. Osobne wejście `@yellow-goose/babylon/multi` dostarcza adapter wielu instancji.

Właściciel zarządza członkostwem przez API: `PUT /api/projects/{projectId}/members/{userId}` z `{"role":"editor"}` albo `{"role":"viewer"}`, nagłówkiem CSRF i sesją. `DELETE` tej samej ścieżki odbiera dostęp. Identyfikatory pochodzą z systemu kont aplikacji; biblioteka nie dostarcza rejestracji kont ani wysyłania zaproszeń. Viewer ma nieaktywne kontrolki edycji i serwerową odmowę zapisu.

Link **Export scene JSON** pobiera dokument z trwałymi identyfikatorami wersji modeli. `POST /api/projects/{projectId}/scenes/import` z `{"document": ...}` tworzy nową scenę po walidacji wszystkich relacji. Import do obcego projektu nie przyznaje dostępu do modeli. Plik JSON nie zawiera podpisanych URL ani binarnych plików; nie stanowi samodzielnego archiwum assetów.

Profil C1 obsługuje statyczne GLB oraz współbieżną edycję przez rewizje HTTP. Port outbox i `receiveEvent` pozwalają dołączyć transport powiadomień; aplikacja nie uruchamia WebSocket ani SSE. Import IFC/RVT/DWG, teren geograficzny i współedycja CRDT należą do dalszych etapów.

## Sprawdzenie

```sh
php tests/c1.php
npm run build:scene
npm run test:runtime
npm run test:scene:http
npm run test:scene:packages
npm run test:scene:browser
```

Testy CGI wymagają `php-cgi`, testy przeglądarkowe Chromium/Playwright. [Środowisko PHP/V8Js](../../docs/ssr.md). Dane testów są tymczasowe; obrazy wynikowe trafiają do ignorowanego `test-results/`.
