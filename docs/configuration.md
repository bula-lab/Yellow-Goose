# Dokumentacja API: Konfiguracja

Wszystkie pliki konfiguracyjne dla frameworka Żółta-Gąska znajdują się w katalogu `config`. Każda opcja jest dobrze udokumentowana, więc możesz swobodnie przeglądać pliki i zapoznawać się z dostępnymi ustawieniami.

## Zmienne Środowiskowe (`.env`)

Większość kluczowych ustawień aplikacji (zwłaszcza tych, które różnią się między środowiskami - deweloperskim, produkcyjnym, testowym) jest kontrolowana za pomocą zmiennych środowiskowych.

U podstawy Twojego projektu znajduje się plik `.env.example`. Powinieneś skopiować go do nowego pliku o nazwie `.env`. To właśnie w pliku `.env` umieszczasz swoje dane dostępowe do bazy danych, klucze API, itp. Plik `.env` nie powinien być dodawany do systemu kontroli wersji.

**Przykład pliku `.env`:**
```dotenv
APP_NAME="Żółta Gąska"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gaska_db
DB_USERNAME=root
DB_PASSWORD=
```

### Funkcja pomocnicza `env()`

Wartości z pliku `.env` są ładowane do plików konfiguracyjnych w katalogu `config` za pomocą funkcji pomocniczej `env()`. Możesz podać drugi argument, który zostanie użyty jako wartość domyślna, jeśli zmienna środowiskowa nie istnieje.

```php
// config/app.php

'debug' => env('APP_DEBUG', false),
```

## Dostęp do Wartości Konfiguracyjnych

Możesz łatwo uzyskać dostęp do wartości konfiguracyjnych z dowolnego miejsca w aplikacji za pomocą globalnej funkcji pomocniczej `config()`.

### Funkcja pomocnicza `config()`

Funkcja `config()` przyjmuje jako argument ciąg znaków w formacie `nazwa_pliku.klucz_konfiguracji`.

**Przykład:**

Aby pobrać nazwę aplikacji z pliku `config/app.php`:
```php
$appName = config('app.name');
```

Aby pobrać domyślny sterownik bazy danych z pliku `config/database.php`:
```php
$dbConnection = config('database.default');
```

Możesz również podać wartość domyślną, która zostanie zwrócona, jeśli dana opcja konfiguracji nie istnieje:

```php
$value = config('app.non_existent_key', 'wartość domyślna');
```

## Buforowanie Konfiguracji (Caching)

Aby zwiększyć wydajność aplikacji na środowisku produkcyjnym, powinieneś zbuforować wszystkie pliki konfiguracyjne w jednym pliku. Użyj do tego komendy `config:cache` w CLI `gaska`.

```bash
php gaska config:cache
```

Ta komenda połączy wszystkie opcje konfiguracyjne w jeden plik, który będzie szybko wczytywany przez framework. Po uruchomieniu tej komendy, plik `.env` nie będzie już wczytywany. Aby odświeżyć konfigurację, musisz ponownie uruchomić `config:cache`.

Aby wyczyścić bufor konfiguracji, użyj komendy `config:clear`.

```bash
php gaska config:clear
```
