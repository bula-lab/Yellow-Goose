# yellow-goose/framework

Pakiet PHP: routing plikowy, loadery, JSON API, ORM i integracja React SSR.

- PHP 8.1+, PDO i JSON; sterownik bazy zgodny z aplikacją.
- React 19 / React DOM 19; Vite 8 i plugin-react 6 do builda/developmentu.
- Node 24 LTS i proc_open dla SSR.
- CLI: `vendor/bin/gaska`, uruchamiane z katalogu aplikacji.
- Boot: `ZoltaGaska\Runtime::boot($root)`.
- HTTP: `ZoltaGaska\Runtime::application($root)`.

Kod aplikacji, konfiguracja bazy i migracje pozostają u konsumenta.
Runtime React/Vite i supervisor są w resources; PHP w src.
SSR używa procesu Node na żądanie z timeoutem, bez streamingu.
Development korzysta z CSR i HMR.

Pakiet nie został opublikowany w Packagist.
Integrację i test niezależnego konsumenta opisują w głównym repozytorium
docs/framework-package.md i tests/package.py.
