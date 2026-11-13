# Konfiguracja

`bootstrap.php` wywołuje Runtime::boot(), które ładuje opcjonalny `.env`
oraz config/database.php i przekazuje konfigurację do Connection.
Użyj `.env.example` jako wzoru. Wartości są literalne; parser nie rozwija zmiennych
ani sekwencji escape. Obsługuje puste wartości, komentarze i otoczenie wartości
pojedynczymi/podwójnymi cudzysłowami. Zmienne procesu mają pierwszeństwo.

Domyślnie: DB_CONNECTION=sqlite, DB_DATABASE=database/database.sqlite.
Ścieżki względne SQLite są liczone od katalogu projektu. Możliwe jest też :memory:.

MySQL: DB_CONNECTION=mysql oraz DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME,
DB_PASSWORD. Baza MySQL musi już istnieć; migrator tworzy w niej tabele.
Wymagane rozszerzenie pdo_mysql. Domyślny charset połączenia to utf8mb4.

Kod konfiguracji: `config/database.php`. Brak globalnych helperów env()/config()
i komend cache konfiguracji.

Opcjonalne `config/app.php` zwraca tablicę metadanych dokumentu: `title`, `favicon`
i `appleTouchIcon`. Są renderowane w HTML także przed hydracją. Pliki graficzne
aplikacja umieszcza w `public/`; konsument pakietu bez tego pliku nie dostaje linków
do nieistniejących ikon demonstracji.

GASKA_VITE_DEV_SERVER jest ustawiane przez `npm run dev` (PHP + Vite)
lub `php gaska serve --dev` (tylko PHP). Wybiera CSR z HMR.
GASKA_SSR=0 jawnie wyłącza SSR dla builda; domyślnie SSR jest włączone.
SSR wymaga rozszerzenia PHP V8Js; nie korzysta z binarki Node.
GASKA_NODE_BINARY opcjonalnie wskazuje Node wyłącznie dla supervisora developmentu
(domyślnie node z PATH).
Supervisor przekazuje GASKA_VITE_PORT do konfiguracji Vite; port wybierasz
przez `npm run dev -- --vite-port=5174`.
GASKA_SESSION_PATH opcjonalnie wskazuje istniejący zapisywalny katalog sesji;
testy wykorzystują go do izolacji. Standardowo PHP używa swojego katalogu sesji.
