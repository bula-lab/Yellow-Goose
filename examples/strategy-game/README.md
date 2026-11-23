# Kepler Outpost

Referencyjna gra strategiczna D1: kolonia na mapie 2D, budowa według czasu serwera, zasoby, jednorazowe nagrody i ranking sezonu. PHP obsługuje HTTP, SSR oraz zadania. Node jest potrzebny do developmentu i builda, nie do działania serwera.

## Uruchomienie

W głównym katalogu repozytorium, z PHP >= 8.1, PDO SQLite, V8Js oraz Node >= 24:

```sh
composer install
npm ci
npm run build:strategy

export STRATEGY_DATA_DIR="$PWD/examples/strategy-game/var"
read -s 'STRATEGY_PLAYER_PASSWORD?Hasło graczy (12–72 bajty): '
export STRATEGY_PLAYER_PASSWORD
php examples/strategy-game/console.php migrate
php examples/strategy-game/console.php seed
php -S 127.0.0.1:8083 -t examples/strategy-game/public examples/strategy-game/public/router.php
```

`read -s` powyżej używa składni zsh. W innej powłoce ustaw hasło przez jej mechanizm wejścia interaktywnego. Seed tworzy konta `player` i `rival` z podanym hasłem oraz sezon `demo-ROK`, od 1 stycznia do 1 stycznia następnego roku UTC. Ponowne uruchomienie zachowuje konta i granice sezonu. Dane muszą być poza `public`. Tryb bez SSR można włączyć przez `GASKA_SSR=0`.

Zaloguj się, podaj nazwę kolonii i wybierz **Join season**. Jedno konto ma jedną kolonię w danym sezonie. W drugim profilu przeglądarki można zalogować konto `rival`. Mapa zachowuje zaznaczenie i powiększenie przy zmianie paneli, otwieraniu modala budowy i nawigacji wstecz/dalej.

Worker przyspiesza zapis ukończeń. Uruchom z tym samym katalogiem danych:

```sh
php examples/strategy-game/console.php jobs:work 100
```

Polecenie przetwarza dostępne zadania i kończy pracę. Uruchamiaj je cyklicznie z cron albo w nadzorowanej pętli. Termin jest zapisany w SQL; zadania przyszłe czekają na swoją kolej. Bez workera odczyt gry lub rankingu rozlicza należne budowy. Licznik klienta pokazuje czas, ale nie przyznaje ukończenia ani punktów.

## Reguły demonstracyjne

Reguły są w `src/Colony/DemoRules.php`, poza biblioteką. Gracz zaczyna z 300 jednostkami stopu, pojemnością 1000 i stałą produkcją 2/s. Budynki nie zmieniają tej stawki w tym przykładzie.

| Cel | Koszt × poziom | Sekundy × poziom | Punkty ukończenia × poziom | Nagroda: zasoby / punkty × poziom | Maksymalny poziom |
| --- | --- | --- | --- | --- | --- |
| mine | 100 | 5 | 10 | 25 / 2 | 10 |
| lab | 150 | 8 | 20 | 30 / 3 | 6 |
| reactor | 80 | 4 | 8 | 20 / 1 | 10 |

Pierwsza kopalnia pobiera 100 jednostek, kończy się po 5 sekundach i przyznaje 10 punktów. Jednorazowy odbiór nagrody dodaje 25 jednostek i 2 punkty. Bilans uwzględnia także produkcję od ostatniego punktu rozliczenia, do limitu 1000. Nadwyżka zasobów z nagrody jest tracona po osiągnięciu pojemności. Można równolegle budować różne cele, a jeden cel ma najwyżej jedną trwającą budowę.

Koszt, termin, wersja reguł i nagrody pochodzą z serwera. Każde wdrożenie zmieniające ceny lub zasady musi zmienić `version()`. Zmiana wersji między ofertą a potwierdzeniem daje konflikt; gracz przegląda nową ofertę i potwierdza nową komendę. Dokładne ponowienie zaakceptowanej komendy zwraca pierwotne potwierdzenie także po wdrożeniu nowych reguł.

Sezony nie mogą się nakładać. Komendy są dozwolone w przedziale `[startsAt, endsAt)`. Budowa może zakończyć się dokładnie na końcu sezonu; jej punkty nadal trafiają do rankingu. Nieodebrane nagrody wtedy wygasają. Kolejny sezon operator może utworzyć przez `season:open ID START_UNIX END_UNIX`; CLI przyjmuje sekundy Unix i kontroluje nakładanie terminów.

Ranking przyznaje wspólne miejsce za remis, np. `1, 1, 3`, i porządkuje remisy według identyfikatora gracza. Pokazuje czas aktualności serwera. Tabela pobiera pięć wierszy naraz. Jeśli pomiędzy stronami zmieni się wynik albo dołączy gracz, wraca na nową pierwszą stronę. Pozostali gracze widzą nazwy i wyniki, nie zasoby ani historię cudzej kolonii.

## Połączenie i pakiety

Klient odpytuje HTTP co trzy sekundy. Nie ma dodatkowej usługi realtime. Po utracie połączenia lub powrocie do karty uzgadnia snapshot i kursor zdarzeń. Utracone potwierdzenie pozostawia niezmienioną komendę; **Retry same order** nie tworzy nowego kosztu. Konflikt wymaga świadomego **Confirm updated order** albo **Dismiss order**. Przywrócenie karty może odczytać oczekującą kopertę z sessionStorage, ale wymaga potwierdzenia i aktualnej autoryzacji.

`yellow-goose/game-strategy` wymaga core/security/database/jobs, bez CMS, React ani silnika 3D. Ekonomia i nazwy budynków należą do aplikacji. `@yellow-goose/game-strategy/store` można instalować bez React; wejście główne dodaje hook React. Model kont, logowanie i utrzymywanie procesu workera także należą do aplikacji.

W obrębie sezonu zapis i spójny ranking są szeregowane. Po długiej przerwie workera odczyt rankingu musi najpierw rozliczyć cały zaległy sezon; przetwarza partie po 128 operacji w jednej transakcji. To profil demonstracyjny bez obietnicy liczby jednoczesnych graczy. Symulacja na żywo, WebSocket i mechaniki walki należą do D2 lub konkretnych aplikacji.

## Testy

```sh
php tests/d1.php
npm run build:strategy
npm run test:runtime
npm run test:strategy:http
npm run test:strategy:packages
npm run test:strategy:browser
```

CGI wymaga `php-cgi`, test przeglądarki Chromium/Playwright. [Środowisko V8Js](../../docs/ssr.md). Wyniki obrazowe trafiają do ignorowanego `test-results/`.
