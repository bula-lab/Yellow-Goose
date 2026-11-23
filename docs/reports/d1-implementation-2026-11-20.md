# Wdrożenie D1: gra strategiczna według czasu serwera

Baza: `d58e397`, ukończone C1. Zakres: D1 z [roadmapy](3d-framework-expansion-2026-11-13.md), bez autorytatywnej symulacji na żywo z D2. Użytkownik zaakceptował jeden aktywny sezon, punkty za ukończenia i jednorazowe nagrody, wspólne miejsca przy remisach, kolejność po identyfikatorze gracza oraz odpytywanie HTTP.

## Przyrosty i własność

1. Osobny `yellow-goose/game-strategy`: interfejs Rules, sezon, kolonia gracza, atomowe koszty i komendy, zadania na termin, rozliczenie zaległości oraz ranking. Opcjonalny czwarty argument `Queue::enqueue()` ustala termin wykonania; dotychczasowi konsumenci zachowują działanie.
2. Osobny `@yellow-goose/game-strategy`: klient HTTP i store bez React oraz opcjonalny hook. Odtwarza połączenie, kontroluje spóźnione odpowiedzi i zachowuje niepewną komendę. DataTable otrzymał opcjonalne informacje o stronie, obsługę resetu i odmowy dostępu.
3. `examples/strategy-game`: mapa 2D, panel budów, potwierdzenie oferty, nagrody i paginowany ranking. PHP obsługuje sesję, SSR, ekonomię i worker. Reguły demonstracyjne pozostają w aplikacji.
4. Testy SQLite/MySQL, rzeczywiste CGI i Chromium, fizyczne instalacje oraz przegląd niezależnego agenta. Backend i frontend miały osobnych właścicieli; agent główny integrował aplikację, kontrakty, testy HTTP/przeglądarki i CI.

## Wymaganie → producent → konsument → dowód

| Wymaganie | Implementacja | Sprawdzenie |
| --- | --- | --- |
| Czas serwera, nie zegar przeglądarki | StrategyService zapisuje startedAt/completesAt; klient używa monotonicznego czasu tylko do prezentacji | `d1.php` z wstrzykniętym zegarem; Chromium z Date.now ustawionym na rok 2100 |
| Koszt raz, także przy równoległych żądaniach | Blokada sezonu, unikalna operacja, receipt i hash pełnej komendy | Niezależne procesy SQLite i MySQL, ponowienie po restarcie i utrata odpowiedzi po commicie |
| Atomowy koszt, zadanie, zdarzenie i receipt | Jedna transakcja na połączeniu Queue | Wycofanie po błędzie zapisu; brak kosztu bez zatwierdzonej komendy |
| Awaria workera nie blokuje gry | Odczyt rozlicza należne operacje w kolejności deadline/operationId | CGI z wyczerpanym zadaniem i reconnect Chromium bez workera |
| Stary lub spóźniony worker nie nalicza ponownie | Lease CAS oraz efekt domenowy na tym samym PDO | Test fencing i prawdziwe opóźnione zadania po wcześniejszym odczycie |
| Jednorazowa nagroda | Flaga claimable pod blokadą i receipt komendy | Równoległe odbiory, drugi identyfikator komendy, przepełnienie zasobów i granica końca sezonu |
| Uzgodniona cena | rulesVersion w snapshot; expectedRulesVersion oraz expectedRevision w komendzie | Zmiana ceny przy tej samej rewizji daje 409, a nowa oferta wymaga potwierdzenia; stare ACK nadal odtwarzane |
| Regeneracja bez zadania co sekundę | Całkowity bilans + checkpoint + stała stawka i pojemność aplikacji | Testy czasu, limitu i zamknięcia sezonu; odczyt stawki 2/s w CGI/UI |
| Wynik nie pochodzi od klienta | Punkty wyłącznie z zatwierdzonych build.completed/reward.claimed | Nadmiarowe pola kosztu/czasu/punktów odrzucone przez API; pierwsza kopalnia 10 pkt, nagroda +2 |
| Ranking: sezon, remisy, aktualność, ograniczona strona | SQL points DESC/actorId, competition rank, generacja projekcji i cursor | Remisy między stronami, limit, reset po zmianie punktów/dołączeniu; indeksy i oddzielna próba 1030 graczy |
| Reconnect i autoryzacja | Feed z kursorem, limit/retencja/reset, bieżąca sesja na każdym odczycie | Duplikat/luka, timeout, stara odpowiedź, wyłączenie konta; pusta odpowiedź 401 usuwa prywatny widok |
| Stały workspace | GameLayout z kluczem gameId, mapa poza zmiennym panelem | Ten sam element SVG, zaznaczenie i zoom po modalach, panelach oraz Back/Forward |
| Niezależne biblioteki i brak Node na serwerze | Fizyczne archiwa Composer/npm, headless store, PHP/V8Js | `d1-packages.py`: osobny projekt, klient bez React/Babylon, SSR bez node_modules i bez Node w PATH |

## Ocena integracji i granice

Końcowe `GASKA_PACKAGE_BROWSER=1 npm run verify:all` przeszło w pełni: dotychczasowe kontrakty, notatnik, CMS/konfigurator, C1 i D1. D1 ma 82 asercje backendu na SQLite i MySQL 8.4 oraz 13 testów klienta; cały zestaw JavaScript ma 44 testy. Osobno przeszło PHP 8.1 oraz rzeczywiste CGI/SSR D1 w obrazie bez programu Node. Obejrzano ekrany desktop i mobile; przy szerokości 390 px nie ma przewijania poziomego.

[Niezależny przegląd](../reviews/d1-integration-review-2026-11-20.md) wykrył zmianę ceny pomiędzy ofertą a komendą oraz oferowanie wygasłej nagrody. Oba przypadki poprawiono i ponownie sprawdzono. Powtórzenie kosztu, punktów lub skutku zadania nie jest rozwiązywane wyłącznie przyciskiem UI; chroni je transakcja i constrainty SQL.

Punkty i zasoby są liczbami całkowitymi. Przykład zaczyna z 300 jednostkami i regeneruje 2/s do 1000. Kopalnia poziomu 1 kosztuje 100, trwa 5 s i daje 10 punktów; odbiór dodaje 25 jednostek z uwzględnieniem pojemności i 2 punkty. Build quote, modal, komenda i wynik pochodzą z tej samej wersji reguł. Poziomy maksymalne przykładu: mine/reactor 10, lab 6, aby każdy oferowany poziom mieścił się w pojemności zasobów.

Transport D1 to HTTP polling. Feed jest trwałym dziennikiem zatwierdzonych zdarzeń udostępnianym według kursora; nie dodano osobnego brokera ani pakietu realtime bez konsumenta push. Zdarzenia są przycinane domyślnie do 256 na grę, a receipt zachowuje się przez życie gry. Wygasły kursor oznacza nowy snapshot, nie ponowne odtwarzanie wszystkich operacji.

Współbieżne operacje w jednym sezonie są szeregowane. Ranking po przerwie workera przetwarza wszystkie zaległości w jednej transakcji, w partiach po 128. Pamięć pobierania jest ograniczona, czas blokady zależy od zaległości. Nie ustalono SLA liczby graczy ani opóźnień; pomiary docelowego obciążenia należą do E. Przykład nie implementuje walki, przesyłania jednostek ani D2.
