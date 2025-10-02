# Dokumentacja API: CLI `gaska`

Żółta-Gąska jest wyposażona w potężne narzędzie wiersza poleceń o nazwie `gaska`, które jest Twoim głównym asystentem podczas tworzenia i zarządzania aplikacją. Wszystkie komendy są wywoływane za pomocą `php gaska [komenda]`.

## Główne Komendy

### `serve`

Uruchamia serwer deweloperski z włączonym Hot Module Replacement (HMR).

```bash
php gaska serve
```

**Opcje:**
- `--port <numer>`: Uruchamia serwer na określonym porcie (domyślnie: 8000).
  ```bash
  php gaska serve --port 3000
  ```
- `--host <adres>`: Określa host, na którym serwer ma nasłuchiwać (domyślnie: `localhost`).
  ```bash
  php gaska serve --host 0.0.0.0
  ```

### `build`

Kompiluje i buduje aplikację do wersji produkcyjnej. Ten proces obejmuje:
- Generowanie statycznych stron (SSG) za pomocą `getStaticProps`.
- Optymalizację zasobów front-endowych (JS, CSS) za pomocą Vite.
- Przygotowanie kodu serwerowego do uruchomienia w trybie produkcyjnym.

```bash
php gaska build
```

### `start`

Uruchamia serwer produkcyjny. Ta komenda powinna być wykonana **po** `php gaska build`.

```bash
php gaska start
```

Serwer produkcyjny jest zoptymalizowany pod kątem wydajności i nie posiada HMR.

## Generatory Kodu (`make`)

Komendy `make` służą do szybkiego tworzenia boilerplate'u dla różnych części aplikacji.

### `make:page <nazwa>`

Tworzy nowy komponent strony w katalogu `app/pages`.

```bash
# Stworzy plik app/pages/contact.jsx
php gaska make:page contact

# Stworzy plik app/pages/user/profile.jsx
php gaska make:page user/profile
```

### `make:api <nazwa>`

Tworzy nowy endpoint API w katalogu `app/pages/api`.

```bash
# Stworzy plik app/pages/api/submit-form.php
php gaska make:api submit-form
```

## Baza Danych

Komendy związane z bazą danych i systemem migracji.

### `db:migrate`

Uruchamia wszystkie zaległe migracje bazy danych.

```bash
php gaska db:migrate
```

### `make:migration <nazwa>`

Tworzy nowy, pusty plik migracji w katalogu `database/migrations`.

```bash
php gaska make:migration create_users_table
```

## Pomoc

### `help`

Wyświetla listę wszystkich dostępnych komend. Możesz również uzyskać pomoc dla konkretnej komendy.

```bash
# Wyświetla pomoc dla komendy `serve`
php gaska help serve
```
