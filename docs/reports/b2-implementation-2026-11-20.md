# B2. CMS i konfigurator garażu

Pakiet `yellow-goose/cms` przechowuje katalog materiałów, szkice i migawki produktów. Aplikacja w `examples/garage-cms` używa go od logowania redaktora po zapis zapytania gościa. Przykład ma własny katalog publiczny, bazę SQLite, sesje i prywatne pliki. [Uruchomienie i dane demonstracyjne](../../examples/garage-cms/README.md).

## Przekrój produkcyjny i dowody

| Wymaganie | Implementacja i konsument | Wynik sprawdzenia |
| --- | --- | --- |
| Opcjonalny CMS, jawne migracje | `CmsModule::forProject`, migracja `cms:001_catalog.php`, `Site::migrate` | Powtórna migracja pomija istniejące wpisy. Instalacja archiwów samego CMS działa z PDO SQLite, bez GD, React i pakietu assets. |
| Uprawnienia redaktora i sesje | `Accounts::canEdit`, `SessionAuthenticator`, polityka `cms.edit`, loadery `Site::edit/offer` | Dwie sesje mają osobne CSRF. Po zmianie roli w bazie ta sama sesja traci dostęp do szkicu, schematu, tabeli i publikacji. Gość dostaje 401 z HTML, JSON nawigacji, prywatnego API i wyceny szkicu. |
| Walidacja i zakres zapytań | `CmsModule`, `CatalogInput`, `Catalog::products/materials`, formularze i tabele panelu | Wysyłka cudzej tożsamości lub pola projektu zwraca 422. Niedozwolone sortowanie/filtr daje 400. Nieznana akcja daje 404. W Chromium redaktor dodaje materiał, zmienia nazwę i odnajduje produkt w tabeli. |
| Rewizje i publikacja | `Catalog::saveProduct/setPublication`, panel produktu, katalog ofert | Dwa równoległe zapisy CGI z oczekiwaną rewizją 3 dały jeden zapis rewizji 4 i jeden konflikt 409. Publikacja 2 zachowała nazwę i kolor po kolejnej edycji. Wyszukiwanie po nazwie nowego szkicu zwróciło pustą listę publiczną. |
| Model i poster | `AssetIntegration`, `AssetCatalog`, `AssetPicker`, worker `jobs:work` | CGI przesłało rzeczywisty GLB i PNG. Po przetworzeniu pobrany model zgadzał się bajt w bajt, również dla zakresu 4–7. CMS odmówił przypięcia wersji oczekującej, odrzuconej oraz gotowej z drugiego projektu. |
| Prywatny odczyt i wycofanie | `Catalog::usesPublishedAsset`, `Site::handle`, serwer plików B1 | Dokumenty, API i odpowiedzi plikowe miały `private, no-store`. Po wycofaniu ostatniej oferty jej URL, API, wersja modelu oraz wcześniej wydany lokalny link do pobrania zwróciły 404. Podpis wygasa po najwyżej 300 s. |
| Tekst i metadane | `PageData`, `Application`, `Offer.jsx`, nawigacja B1 | SSR zakodował znaczniki w nazwie i opisie. Nawigacja zwróciła te same metadane oraz właściwe parametry i token sesji. Przejście na stronę logowania usunęło opis i canonical poprzedniej oferty. |
| Panel redaktora | `ProductEditor.jsx`, `Form`, `DataTable`, `Dialog`, ochrona niezapisanych zmian B1 | Chromium wykonało wybór gotowej wersji, zapis i potwierdzenie publikacji. Po próbie opuszczenia formularza sprawdzono pozostanie na stronie, zapis z odświeżoną tabelą i odrzucenie zmian. |
| SSR i WebGL | `Offer.getLayout`, `WorkspaceShell`, `BabylonViewport` | Bez JS widoczne były nazwa, warianty i dostarczony poster 320 × 180 px. Chromium pobrało GLB raz i zachowało ten sam canvas przez zmianę zakładki, otwarcie okna i cofnięcie. Wybór innego koloru zmienił wyrenderowany obraz. |
| Cena i zapytanie | `DemoRules::quote`, `Enquiries::confirm/save`, `Offer.jsx` | Serwer zwrócił 624 500 gr za 3500 × 6000 × 2130 mm w wykończeniu `forest`. Podana z klienta kwota, obcy kolor i niedozwolona geometria dały 422. Potwierdzenie cudzej sesji dało 409. Powtórne wysłanie poprawnego zapytania pozostawiło jeden wpis. |

Przepływ powtarzają [test domeny i metadanych](../../tests/b2.php), [test CGI](../../tests/b2-http.py) oraz [test Chromium](../../tests/b2-browser.py). [Schemat i API modułu](../../packages/php/cms/README.md).

## Jednostki i wycena

| Miejsce odczytu | Wartość w teście | Wynik |
| --- | --- | --- |
| `DemoRules::fields` → publiczny formularz | `heightMm=2130`, współczynnik 10 mm/cm | Pole HTML miało wartość `213` cm w SSR i po powrocie z zakładki. |
| `Form.onChange` → `BabylonViewport` → `viewState().dimensionsMeters` | 350 × 600 cm, zmiana wysokości 240 → 213 cm | Rzeczywiste granice modelu wyniosły 3,5 × 6 × 2,4 m, potem 3,5 × 6 × 2,13 m. |
| Formularz → PHP | `widthMm=3500`, `depthMm=6000`, `heightMm=2130`, `color=#41634c` | PHP potwierdził cztery wartości i obliczył 400 000 + 84 000 + 110 000 + 12 500 + 18 000 = 624 500 gr. |
| Odpowiedź → podsumowanie → SQLite | `amountMinor=624500`, `currency=PLN` | Przeglądarka wyświetliła `6245.00 PLN`. Zapytanie w bazie zachowało wysokość 2130 mm, 624 500 gr i walutę PLN. |

## Wykonane kontrole

- `php tests/b2.php` zakończył 43 asercje na PHP 8.1 i PHP 8.5. Testy `php tests/contracts.php` i `php tests/navigation.php` zachowały 17 kontraktów historycznych oraz 20 asercji dotychczasowej nawigacji.
- `npm run build --prefix examples/garage-cms` zbudował klienta i samodzielny bundle SSR. Vite zgłasza rozmiar osobnego chunka silnika powyżej 500 kB. Zwykły panel redakcyjny nie pobrał tego chunka podczas testu.
- `python3 tests/b2-http.py --csr` uruchomiono na hoście, a `python3 tests/b2-http.py` w PHP 8.4 z V8Js. Obie wersje przeszły z osobnymi bazami testowymi, rzeczywistymi sesjami i workerem PHP.
- `python3 tests/b2-browser.py` uruchomiono w Chromium z programowym WebGL i rzeczywistym serwerem PHP/V8Js. Test przechodzi przez panel redaktora, publikację, odczyt bez JS, potwierdzenie parametrów, zapis zapytania i wycofanie oferty.
- Przy szerokości 390 px formularz redaktora z pełnym identyfikatorem modelu i publiczna oferta mieściły się bez poziomego przewijania. Test zapisał dwa widoki potwierdzonej konfiguracji. [Komputer, 1280 × 1071 px](../../test-results/garage-cms-desktop.png), [telefon, 390 × 1291 px](../../test-results/garage-cms-mobile.png).

Wersją bazową zadania był `499ca68` na `codex/modular-framework-a0-a2`. Zmiany B1, B2 i ich integracja współdzielą drzewo robocze. Reguły cenowe dotyczą wyłącznie fixture demonstracyjnego. Pomiary na fizycznym telefonie i ofertę handlową pozostawiono poza zakresem tego przykładu.
