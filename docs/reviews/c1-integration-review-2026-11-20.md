# Przegląd integracji C1

Przegląd obejmuje zmiany C1 w drzewie roboczym względem `351c147` na gałęzi `codex/modular-framework-a0-a2`. Sprawdziłem kontrakt `scene-api`, formularz i historię w `@yellow-goose/editor`, port sceny, adapter Babylon oraz przykład `examples/scene-editor`. Wymagania dotyczące dwóch kart, powtórzeń komendy i jednostek pochodzą z [planu 3D, §6 i §12](../reports/3d-framework-expansion-2026-11-13.md).

## Ustalenia z przeglądu

| Priorytet | Przypadek | Wynik ponownej kontroli |
| --- | --- | --- |
| P1 | Dwa kliknięcia Undo podczas zapisu ruchu z `x=1` do `x=2` używały tej samej komendy odwrotnej. Obie operacje kończyły się sukcesem, historia traciła dwa wpisy, a obiekt zostawał na `x=1` zamiast `x=0`. | `historyAction` szereguje całe operacje Undo/Redo. Test dwóch równoległych wywołań przechodzi. |
| P1 | Po otwarciu dialogu opuszczenia strony trwający autosave zapisywał także kolejną operację przed decyzją użytkownika. Przycisk „Discard” nie mógł jej już odrzucić. | Test z opóźnioną odpowiedzią potwierdza, że po wyborze „Discard” serwer otrzymał tylko pierwszą komendę. Wybór „Save” kończy się po potwierdzeniu obu. |
| P2 | Loader zwracał `readOnly` dla roli `viewer`, lecz strona pomijała tę właściwość. Widz mógł lokalnie dodać obiekt i dopiero autosave zwracał 403. | Strona przekazuje `readOnly` do `SceneEditor`. Test CGI potwierdza właściwość dla widza oraz odmowę zapisu w API. |
| P2 | Inspektor przyjmował nazwę o długości 161 bajtów. Po błędzie 422 ponawiał tę samą komendę, co zmuszało użytkownika do wczytania sceny z serwera. | Walidacja przed dodaniem komendy przyjmuje 160 bajtów, odrzuca 161 bajtów ASCII i 81 znaków „ą” (162 bajty). Po definitywnej odmowie przez serwer użytkownik może usunąć odrzuconą operację i jej zależności, zachowując niezależne zmiany. |

## Weryfikacja

- `php tests/c1.php` zakończył się powodzeniem. W 69 asercjach SQLite znalazły się wyścig dwóch połączeń, powtórzenia komend, rewizje, kontrola relacji i atomowy zapis zdarzeń.
- `node --test tests/c1-editor.test.mjs tests/c1-scene-http.test.mjs` zakończył się powodzeniem. 17 przypadków objęło m.in. historię zapisów, utratę odpowiedzi, konflikt dwóch niezależnych instancji, przeliczanie jednostek i odświeżenie URL modelu. Własne zdarzenie przed odpowiedzią HTTP daje jedną zmianę rewizji, luka w rewizjach pobiera aktualny dokument, a obca zmiana zachowuje lokalną kolejkę do decyzji użytkownika.
- `python3 tests/c1-http.py` w środowisku PHP/V8Js zbudowanej aplikacji zakończył się powodzeniem. Test loguje oddzielne sesje, zapisuje scenę przez CGI, sprawdza 409, import i eksport, odwołanie członkostwa oraz HTML renderowany na serwerze.
- `python3 tests/fixtures/c1/gesture-browser.py` zakończył się powodzeniem w Chromium z programowym WebGL. Przesunięcie, obrót i skalowanie wysłały po jednej komendzie po puszczeniu uchwytu. Przesunięcie z przyciąganiem ustawiło współrzędne na siatce 0,25 m.

Oddzielny [przebieg CI dla `351c147`](https://github.com/bula-lab/Yellow-Goose/actions/runs/44744080804) potwierdził naprawę instalacji zależności w obrazie B1/B2. Ten przebieg poprzedza publikację zmian C1.
