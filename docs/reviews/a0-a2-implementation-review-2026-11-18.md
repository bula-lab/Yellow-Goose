# Przegląd implementacji A0–A2

Data: 2026-11-18. Oceniono zmiany drzewa roboczego względem b1e8000 oraz nowe pliki
pakietów, konsumentów i testów. Wcześniejsze nieśledzone raporty potraktowano jako
wejście zadania. Nie jest to ponowny audyt istniejącego MVP.

Werdykt: PASS. Nie pozostał problem wymagający poprawki przed zamknięciem A0–A2.
Pełny [rejestr wymagań i wyników](../reports/a0-a2-implementation-2026-11-18.md).

## Zweryfikowane granice

- ZoltaGaska ma jednego właściciela każdej klasy. 11 plików przeniesiono bez zmian;
  pozostałe rozszerzenia zachowują utrwalone API. Kontrakty działają na PHP 8.1/8.4/8.5.
- Fasada ma rzeczywiste zależności Composer. Core/resources/CSR działają z fizycznych
  archiwów przez PHP -n; brak przypadkowego PDO, React i V8Js w lekkim profilu.
- Zasoby legacy są kopią jednego źródła, bez wymagania npm po stronie Composer.
  Nowe eksporty i stare importy mają osobne instalacje oraz testy SSR/hydracji.
- Moduł nie jest automatycznie wykrywany ani uruchamiany po instalacji. Boot odrzuca
  cykle, brak zależności, konflikty nazw, nakładające się trasy i brak polityki.
- Tożsamość pochodzi z providera. Sesja zmienia ID i CSRF, uprawnienia są odczytywane
  z konta, a odmowa lub błędny payload nie docierają do handlera akcji.
- Query przyjmuje ograniczone opcje, otrzymuje tożsamość i serializuje jawne pola.
  Schemat UI nie eksportuje constraints PHP ani wykonywalnego kodu do klienta.
- Migrator zachowuje wpisy legacy, rozdziela owner/ID i wyliczoną kolejność.
  Równoległe procesy sprawdzono na SQLite i MySQL, łącznie z powtórzeniem wdrożenia.
- Runtime, plikowe API oraz CLI mają rzeczywistego konsumenta archiwum. Rejestracja
  modułu nie wykonuje migracji; jawna komenda wykonuje je raz.
- SSR nadal działa bez Node i z wyłączonymi funkcjami uruchamiania procesów PHP.
  Pełne Chromium E2E zachowuje CRUD, nawigację i HMR istniejącego demo.

## Poprawione ustalenia podczas walidacji

1. Różne opisy self w refleksji PHP 8.4/8.5: normalizacja testu, bez zmiany API.
2. ZIP i natywne zależności macOS w kontenerze: przenośny tar i oddzielne node_modules.
3. Konflikt parametru i literalnej trasy: odrzucenie podczas boot, bez zależności od kolejności.
4. Niechroniona mutacja modułu lub brak authorizera: odrzucenie konfiguracji.
5. Nieudany start/rotacja sesji oraz nielistowy wynik query: jawne odrzucenie.

Nie wykonano commita, pusha ani zdalnego CI. Pakiety są gotowe do lokalnej instalacji
z archiwów, ale nie zostały opublikowane. Zakres B1/B2 oraz reguły domenowe aplikacji
pozostają poza tym changesetem.
