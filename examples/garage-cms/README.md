# Garage studio

Aplikacja referencyjna Yellow-Goose łączy redakcję ofert, publiczny katalog i konfigurator garażu. PHP obsługuje HTTP, SSR, wycenę oraz przygotowanie plików. Przeglądarka ładuje Babylon po wyświetleniu postera. Cennik i ograniczenia geometrii są fikcyjnymi danymi demonstracyjnymi.

## Uruchomienie

Uruchom w głównym katalogu repozytorium. Build wymaga Node 24 lub nowszego. Serwer używa PHP >= 8.1 z PDO SQLite, GD i V8Js. [Środowisko Docker z V8Js](../../docs/ssr.md#środowisko-docker).

```sh
composer install
npm ci
npm run build --prefix examples/garage-cms

export GARAGE_DATA_DIR="$PWD/examples/garage-cms/var"
export GARAGE_SIGNING_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
php examples/garage-cms/console.php migrate

# Wpisz hasło redaktora o długości 12–72 bajtów i naciśnij Enter.
read -rs GARAGE_EDITOR_PASSWORD
export GARAGE_EDITOR_PASSWORD
php examples/garage-cms/console.php seed
unset GARAGE_EDITOR_PASSWORD

php -S 127.0.0.1:8085 -t examples/garage-cms/public examples/garage-cms/public/router.php
```

Otwórz [katalog](http://127.0.0.1:8085) albo [logowanie redaktora](http://127.0.0.1:8085/login). Login to `editor`. Komenda `seed` tworzy konto i trzy materiały. Ponowne uruchomienie zachowuje hasło i zmiany w istniejących materiałach. Pierwsza oferta pojawia się w katalogu po jej publikacji.

Worker potrzebuje tego samego `GARAGE_DATA_DIR` i `GARAGE_SIGNING_KEY` co serwer. Uruchom oba procesy w sesjach, które dziedziczą te wartości. W instalacji długotrwałej przekaż klucz z konfiguracji operatora. Prywatne pliki, SQLite i domyślny katalog sesji mieszczą się w `GARAGE_DATA_DIR`. Jedynym katalogiem publicznym jest `public/`.

## Od szkicu do zapytania

1. W **Products → New product** podaj identyfikator, nazwę i opis, wybierz materiały i zapisz szkic.
2. W edycji produktu wgraj [bryłę testową GLB](../../tests/fixtures/b1/box.glb) i [poster](../../tests/fixtures/b1/poster.png) albo własną parę w obsługiwanym formacie. Dostarczony model to prostopadłościan do sprawdzania wymiarów i koloru. Panel pokaże numer wersji i stan kolejki.
3. W drugim terminalu uruchom `php examples/garage-cms/console.php jobs:work 10`. Worker sprawdza model i przygotowuje poster. Panel odświeża status. Wybierz wersję `ready`, zapisz szkic, kliknij **Publish saved draft** i potwierdź publikację. Komenda przetwarza najwyżej 10 zadań i kończy pracę, więc można ją uruchamiać z cron.
4. Otwórz ofertę w osobnym oknie prywatnym. Tekst, poster, warianty i cena przykładowej konfiguracji pochodzą z SSR. Po uruchomieniu JavaScript zmiany szerokości, głębokości, wysokości i koloru widać w modelu. Zakładka **Finishes** oraz okno **Model details** zachowują kamerę i konfigurację. W oknie szczegółów można odczytać rzeczywiste wymiary załadowanego modelu.
5. Kliknij **Confirm configuration**. PHP sprawdzi kombinację i wyliczy cenę. **Save enquiry** zapisuje adres e-mail i potwierdzoną konfigurację. Potwierdzenie działa w tej samej sesji przez 15 minut i odnosi się do wskazanej rewizji oferty. Kolejna publikacja wymaga ponownego potwierdzenia.
6. Zmień opis lub materiał i zapisz szkic. Gość nadal widzi poprzednią publikację. **Unpublish offer** usuwa produkt z katalogu, wyszukiwarki i API. Lokalny serwer plików sprawdza aktualny dostęp przy każdym pobraniu. Podpis ma maksymalną ważność 300 sekund. Kopie wcześniej pobrane przez odbiorcę pozostają u niego.

Panel **Materials** obsługuje dodawanie i edycję wykończeń. Zapis szkicu przechowuje nazwy i kolory wybranych materiałów w rewizji produktu. Przy równoległej edycji nowszy zapis powoduje HTTP 409 na karcie korzystającej ze starej rewizji. Przy próbie opuszczenia zmienionego formularza można zapisać zmiany, odrzucić je lub pozostać w edycji.

## Przykład wyceny

Formularz pokazuje centymetry. PHP przyjmuje szerokości 2500, 3000 i 3500 mm, głębokości 4000, 5000 i 6000 mm oraz wysokości 2000, 2130 i 2400 mm. Głębokość 6000 mm lub wysokość 2400 mm wymaga szerokości co najmniej 3000 mm.

Dla grafitowej podstawy 2500 × 4000 × 2000 mm cena wynosi 400 000 gr. Każdy kolejny wariant szerokości dodaje 42 000 gr, a głębokości 55 000 gr. Wysokości 2130 i 2400 mm dodają odpowiednio 12 500 i 36 000 gr. Materiał `forest` dodaje 18 000 gr, `sand` 9000 gr, a własny materiał 24 000 gr. Przykładowe 350 × 600 × 213 cm z wykończeniem `forest` kosztuje **6245,00 PLN**. API zwraca `amountMinor: 624500`. Adapter ustawia wysokość modelu na 2,13 m. [Reguły demonstracyjne](src/Garage/DemoRules.php).

## Weryfikacja

Po buildzie uruchom z głównego katalogu repozytorium. Testy tworzą własne katalogi danych i konta.

```sh
php tests/b2.php
python3 tests/b2-http.py
python3 tests/b2-browser.py
```

Test HTTP używa `php-cgi` z V8Js. Na hoście z samym PHP można sprawdzić CSR przez `python3 tests/b2-http.py --csr`. Test przeglądarkowy wymaga Chromium z WebGL, Playwright dla Pythona i PHP z V8Js.
