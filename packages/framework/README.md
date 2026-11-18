# yellow-goose/framework

Fasada zgodności składająca core, database, react-bridge, ssr-v8js i security.
Runtime i CLI zachowują przestrzeń ZoltaGaska oraz dotychczasowe kontrakty.
Nowe funkcje należą do opcjonalnych bibliotek, instalowanych osobno.

- PHP 8.1+, PDO i JSON; sterownik bazy zgodny z aplikacją.
- React 19 / React DOM 19; Vite 8 i plugin-react 6 do builda/developmentu.
- SSR: rozszerzenie PHP V8Js; serwer nie wymaga Node ani proc_open do renderowania.
- Node 24 LTS wyłącznie do builda i developmentu.
- CLI: `vendor/bin/gaska`, uruchamiane z katalogu aplikacji.
- Boot: `ZoltaGaska\Runtime::boot($root)`.
- HTTP: `ZoltaGaska\Runtime::application($root)`.

Kod aplikacji, konfiguracja bazy i migracje pozostają u konsumenta.
Runtime React/Vite i supervisor w resources są generowane z pakietów npm.
Nie wymagają instalacji @yellow-goose/* w dotychczasowej aplikacji.
PHP w src zawiera composition root oraz CLI; klasy HTTP/ORM/SSR należą do bibliotek.
SSR używa osobnego kontekstu V8 z limitami czasu i pamięci, bez streamingu.
Nawigacja po hydracji pobiera props z PHP i aktualizuje istniejący root Reacta.
Development korzysta z CSR i HMR.

Pakiet nie został opublikowany w Packagist.
Integrację i test niezależnego konsumenta opisują w głównym repozytorium
docs/framework-package.md i tests/package.py.
Fasada ładuje jawną listę modułów z config/modules.php; moduły nie wykonują DDL przy boot.
