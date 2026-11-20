# B1. Obszar roboczy, zasoby i worker

Zmiany robocze na gałęzi `codex/modular-framework-a0-a2`, baza `499ca68`. Wersje pakietów YG 0.2.0. PHP obsługuje żądania i zadania, V8Js renderuje stronę, a Babylon.js 8.26.0 uruchamia WebGL w przeglądarce.

## Wymaganie, ścieżka wykonania i wynik

| Wymaganie | Implementacja i konsument | Sprawdzenie |
| --- | --- | --- |
| Instalacja opcjonalnych pakietów | Osobne manifesty PHP `jobs` i `assets`, npm `ui`, `scene` i `babylon`. `react/workspace` korzysta z dotychczasowego bridge. | `scripts/check-packages.py` sprawdził graf 11 pakietów PHP, 50 właścicieli klas i eksporty npm. |
| Transakcyjny outbox | `AssetService::complete` zapisuje wersję, zadanie i potwierdzenie finalizacji w transakcji na PDO udostępnionym przez `Queue`. | Wymuszenie błędu zapisu zadania pozostawiło zero wersji. Ponowienie po zamrożeniu obu plików zapisało jedną wersję i jedno zadanie. Dwa procesy PHP finalizujące równocześnie otrzymały to samo ID. |
| Ponowienia i dzierżawa | `Queue::claim`, `Lease::complete/fail`, komenda `jobs:work` i callback `AssetService::failed`. | Dwa procesy workera podjęły jedno zadanie. Błąd przy instalacji postera pozostawił wersję bez URL, ponowienie doprowadziło ją do ready. Po trzech przerwanych dzierżawach zadanie i wersja przeszły do failed. Stary token dzierżawy nie zapisał efektu. |
| Upload, wznowienie i izolacja | `AssetModule`, `AssetService`, `Storage` i `LocalStorage`. Każdy fragment ma sprawdzony offset i limit, a projekt, konto i token są weryfikowane także przy ponowieniu. | Przez Kernel przeszedł GLB z 90 000 wierzchołków, większy od 1 MiB. Sprawdzono offsety części, błędny checksum, CSRF, inne konto/projekt, limit jednej części i sprzątanie po wygaśnięciu. Próba zapisu starym tokenem po finalizacji dała 409, SHA-256 pliku ready pozostał poprawny. |
| Walidacja i budżet projektu | `GlbProfile` sprawdza odwołania i geometrię binarną, `ImageProfile` dekoduje obrazy i ponownie koduje poster w GD. `begin` rezerwuje miejsce na oba źródła i gotowe pliki. | Testy objęły nieprawidłowy indeks, granice akcesora, cykl, złożenie skal poza zakresem, nieskończone współrzędne, niedozwolone URI, rozmiar tekstury, uszkodzony poster, JPEG/PNG oraz limity bajtów i zadań, wspólną blokadę limitu dla dwóch zapisów wielkości liter i odrzucenie niekanonicznego ID przed dostępem do storage. |
| Publikacja i wydawanie pliku | Metadane i `FileResponse` wymagają aktualnej polityki. Podpis wiąże wersję, rodzaj pliku, odbiorcę i wygaśnięcie. CMS przekazuje bieżące powiązanie publikacyjne przez adapter aplikacji. | Gość przed publikacją nie otrzymał deskryptora. Po publikacji odczytał model, po odwołaniu dostępu wydany URL zwrócił 404. Zakres 0–3 zwrócił 206 i cztery bajty `glTF` ze strumienia. |
| Formularz, tabela, wybór zasobu | Eksporty `@yellow-goose/ui` są używane przez formularz oferty i panel redakcyjny. `AssetPicker` pokazuje poster i status, a `uploadAsset` kontynuuje wysyłanie od offsetów serwera. | Chromium sprawdził sortowanie w obu kierunkach, filtr, następny/poprzedni kursor, zapamiętanie zapytania po wizycie i błąd 422 przy właściwym polu. Wartość 213 cm przeszła do PHP jako 2130 mm, a 240 cm jako 2400 mm. |
| Stały układ i metadane | `PageShell` zachowuje layout nad kluczem wizyty, `WorkspaceShell` posiada zakres projektu, a router ustawia title, description i canonical. | Po zmianie podpisanego URL w tym samym projekcie pozostały ta sama instancja, położenie kamery i jedno pobranie GLB. Przejście do kolejnego projektu zwolniło poprzednią scenę. Nawigacja usuwała i przywracała metadane. |
| Dialog i opuszczenie niezapisanego formularza | Natywny dialog blokuje tło. `useWorkspaceLeaveGuard` czeka na decyzję albo zapis. Po zapisie ponownie pobiera dane docelowej strony. | Chromium sprawdził Tab, Shift+Tab, Escape, pisanie i strzałki bez ruchu kamery, powrót fokusu oraz Wstecz/Dalej. Anulowanie Wstecz przywróciło adres i projekt, zapis zakończył się przed nawigacją, a odrzucenie zwolniło poprzedni viewport. |
| SSR, GPU i jednostki | `Viewport` renderuje poster w V8Js. Build SSR podstawia stub silnika, a klient ładuje adapter i glTF loader w osobnych chunkach. `applyChanges` skaluje oryginalne granice geometrii. | Przy wyłączonym JS poster i bezpośrednio otwarty dialog pochodziły z SSR. Przy ekranie o szerokości 900 px kwadratowy poster 320×320 px i canvas zajmowały 884×497,25 px przed hydracją, w trakcie pobierania i po wczytaniu modelu. Chromium z WebGL wczytał box.glb, wskazał siatkę kliknięciem i zapisał obraz PNG z widoczną geometrią. Pomiar rzeczywistych granic dał [3, 2.13, 5] m dla [3000, 2130, 5000] mm. |
| Błędy i cykl życia | Adapter reaguje na utratę i odzyskanie kontekstu, widoczność dokumentu i canvas, sygnał anulowania oraz `dispose`. | Sprawdzono utratę/odzyskanie WebGL, brak GPU, błąd dekodowania, ponowienie z postera, zatrzymanie animacji poza widokiem i anulowanie trwającego pobierania przy zmianie projektu. |

## Uruchomione sprawdzenia

- `php tests/b1.php` na PHP 8.5.12 lokalnie i na PHP 8.4 w obrazie projektu, z SQLite i GD, po 89 sprawdzeń. Test porównuje rzeczywiście zapisane pliki i uruchamia niezależne procesy PHP.
- `python3 tests/b1-browser.py` w obrazie testowym projektu z PHP V8Js, Vite i Playwright Chromium, kod wyjścia 0. Test buduje obie wersje aplikacji, a następnie używa HTTP i WebGL SwiftShader.
- `node --test tests/navigation.test.mjs`, 10 dotychczasowych testów nawigacji przeszło.
- `node scripts/sync-legacy.mjs --check` oraz `git diff --check`, kod wyjścia 0.

## Zakres pierwszego profilu

Storage lokalny wymaga wspólnego prywatnego systemu plików z blokadami procesowymi i atomowym przejęciem pliku. Podstawowy worker można uruchamiać z cron. Projekt ma domyślnie 16 sesji lub zadań w toku i budżet 512 MiB. Oferta może publikować wyłącznie deskryptor gotowej wersji.

Walidator w PHP przyjmuje udokumentowany podzbiór statycznego glTF 2.0 z geometrią trójkątną. Rozszerzenia, kompresja, animacje, skiny, macierze transformacji i URI spoza pliku kończą sprawdzenie błędem. Przeglądarka rysuje rzeczywistą geometrię, a gotowy poster dostarcza redaktor. Testy wykonane przez SwiftShader potwierdzają zachowanie WebGL na Chromium. Budżety wydajności fizycznego telefonu wymagają odrębnego pomiaru.

[Kontrakt uploadu, limitów i workera](../../packages/php/assets/README.md) · [Port viewera](../../packages/js/scene/README.md) · [Interfejs obszaru roboczego](../../packages/js/react/README.md)
