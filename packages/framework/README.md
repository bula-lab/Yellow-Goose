# yellow-goose/framework

Pakiet PHP: routing plikowy, loadery, JSON API, ORM i integracja React SSR.

- PHP 8.1+, PDO i JSON; sterownik bazy zgodny z aplikacją.
- React 19 / React DOM 19; Vite 8 i plugin-react 6 do builda/developmentu.
- SSR: rozszerzenie PHP V8Js; serwer nie wymaga Node ani proc_open do renderowania.
- Node 24 LTS wyłącznie do builda i developmentu.
- CLI: `vendor/bin/gaska`, uruchamiane z katalogu aplikacji.
- Boot: `ZoltaGaska\Runtime::boot($root)`.
- HTTP: `ZoltaGaska\Runtime::application($root)`.

Kod aplikacji, konfiguracja bazy i migracje pozostają u konsumenta.
Runtime React/Vite i supervisor są w resources; PHP w src.
SSR używa osobnego kontekstu V8 z limitami czasu i pamięci, bez streamingu.
Nawigacja po hydracji pobiera props z PHP i aktualizuje istniejący root Reacta.
Development korzysta z CSR i HMR.

Pakiet nie został opublikowany w Packagist.
Integrację i test niezależnego konsumenta opisują w głównym repozytorium
docs/framework-package.md i tests/package.py.
