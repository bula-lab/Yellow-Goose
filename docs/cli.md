# CLI

| Komenda | Działanie |
| --- | --- |
| php gaska help | Pomoc |
| php gaska db:migrate | Migracje z database/migrations; wykonane pliki zapisuje tabela migrations |
| php gaska db:seed | Dwa przykładowe wpisy, tylko gdy posts jest puste |
| php gaska serve | Lokalny serwer PHP, domyślnie 127.0.0.1:8000 |
| npm run dev / php gaska dev | PHP + Vite w jednym terminalu; Ctrl+C kończy oba |
| php gaska serve --dev | Sam PHP z zasobami Vite; osobno npm run dev:vite |
| npm run demo | Migracje, seed, build i lokalny serwer |
| npm test | Testy PHP i izolowanej bazy |
| npm run test:http | Integracja przez PHP-CGI |
| npm run test:runtime | Supervisor i integracja procesu SSR |
| npm run test:package | Instalacja i build niezależnego konsumenta pakietu |
| npm run verify | PHP, build klienta/SSR, runtime, CGI, konsument pakietu |
| npm run verify:all | verify i E2E |
| npm run test:e2e | Chromium, HTTP, CRUD i HMR; wymaga Playwright Python |

serve przyjmuje --host oraz --port, zarówno z =, jak i oddzielną wartością.
dev dodatkowo przyjmuje --vite-port. Przykład: `npm run dev -- --port=8080 --vite-port=5174`.
Awaria jednego procesu kończy środowisko z kodem błędu. Po sygnale zakończenia
supervisor daje procesom 2 sekundy, następnie wymusza zakończenie.
Konsument pakietu może używać `php vendor/bin/gaska` z katalogu swojej aplikacji.
Migracje są uruchamiane jawnie; start serwera nie zmienia schematu bazy.
SQLite: schemat i historia migracji są w transakcji. MySQL DDL wykonuje implicit
commit; migracje muszą nadawać się do bezpiecznego ponowienia po awarii.
Nie uruchamiaj migratorów równolegle.

Nie ma komend make:*, rollback ani produkcyjnego serwera gaska start.
