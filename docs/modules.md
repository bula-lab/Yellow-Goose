# Moduły, bezpieczeństwo i zasoby

Moduł implementuje ZoltaGaska\Module\Module: id(), dependencies() oraz register(Registry).
Registry::boot otrzymuje jawną listę obiektów. Porządkuje ją według zależności,
odrzuca brakujące/zdublowane moduły i cykle, następnie zamraża rejestr.
Nie skanuje vendor i nie wykonuje migracji.

## Rejestracja

W register dostępne są:

| Metoda | Kontrakt |
| --- | --- |
| service(id, factory) | Leniwa usługa; factory otrzymuje rejestr, get wykrywa cykle |
| route(name, method, prefix, path, handler, policies) | Jawna trasa API; handler otrzymuje Request, params i rejestr |
| policy(name, callable) | Polityka serwera; wynik musi być dokładnie true |
| command(name, handler) | Jawna komenda; handler otrzymuje argumenty i rejestr, zwraca exit code |
| migrations(directory) | Katalog migracji przypisany do ID bieżącego modułu |

ID modułu zawiera małe litery, cyfry i myślniki; app jest zarezerwowane.
Nazwy usług, tras, komend i polityk mają jednego właściciela. Trasy mają literalny
prefiks /api/nazwa oraz opcjonalne parametry {id}. Nakładające się trasy dla tej
samej metody przerywają boot, również literalna trasa i parametr. GET rezerwuje HEAD.
Nie wybieramy handlera zależnie od kolejności rejestracji.

Mutacja wymaga jawnej polityki. Brak zadeklarowanej polityki przerywa boot.
Chroniona trasa wymaga authorizera w konstruktorze Http\Kernel. Sam Router
pozostaje biblioteką dopasowania ścieżek i nie wykonuje autoryzacji.

## Profile aplikacji

Samodzielny bootstrap API składa Registry i Kernel; nie potrzebuje React, PDO ani V8Js.
Uruchamialny przykład kont, zasobów i CLI znajduje się w examples/modular-api.
W repozytorium opcjonalne biblioteki resources/validator-symfony są zależnościami
rozwojowymi demonstracji. W docelowej aplikacji, która ich używa, należą do require.

```sh
composer install
php examples/modular-api/console.php example:status
php -S 127.0.0.1:8081 -t examples/modular-api/public examples/modular-api/public/index.php
```

Przykład nie ma domyślnego hasła. Ustaw YG_EXAMPLE_PASSWORD_HASH na hash utworzony
przez password_hash. Login przykładowego konta to editor. GET /api/auth/session
wydaje CSRF; POST /api/auth/login przyjmuje login/password i wymaga tego tokena.
Po loginie używaj nowego tokena. POST /api/auth/logout usuwa tożsamość.
Przykładowa akcja save waliduje i zwraca zaakceptowane dane; nie zapisuje ich do bazy.

Fasada ładuje opcjonalny config/modules.php zwracający listę modułów. Usługa
http.authorizer dostarcza callable do Kernel. Legacy App/pages nadal działa;
moduł rezerwuje swój pierwszy segment po /api i nie może dzielić go z plikowym API.
Konflikt przerywa start. CLI pokazuje wyłącznie zarejestrowane komendy; moduł nie
może zastąpić help/dev/serve/db:migrate/db:seed.

## Tożsamość i polityki

UserProvider dostarcza Account po loginie lub ID. Repozytorium kont jest własnością
aplikacji; może korzystać z bazy, usługi zewnętrznej albo fixture. Account zawiera
Identity, hash hasła i flagę enabled. Nie przyjmujemy tożsamości ani ról z payloadu.

SessionAuthenticator weryfikuje hasło, zmienia ID sesji i token CSRF przy loginie
oraz wylogowaniu. Sesja przechowuje wyłącznie ID konta. Provider ponownie odczytuje
konto i jego uprawnienia; wyłączone konto traci dostęp przy kolejnym żądaniu.
Stateless API może dostarczyć własny IdentityProvider bez rozszerzenia session.

RequestAuthorizer składa provider, Gate oraz CsrfGuard. Polityka otrzymuje
Identity lub null oraz subject z request i params. Polityka aplikacji sprawdza
uprawnienia do konkretnego obiektu i relacji; rola sama nie ustanawia własności
projektu. Dla mutacji sesyjnych CSRF jest sprawdzane przed handlerem.
Nie ustawiaj wspólnego tokena w aplikacji produkcyjnej; użyj Session::csrfToken.
Kontekst tożsamości providera musi być spójny w obrębie pojedynczego żądania.

## Zasoby

ResourceDefinition deklaruje pola, dozwolone sortowanie/filtry, readPolicy,
callback query oraz listę Action. ResourceModule rejestruje:

- GET /api/resources/{resource}/schema;
- GET /api/resources/{resource};
- POST /api/resources/{resource}/actions/{action}.

QueryOptions dopuszcza limit 1–100, cursor do 1024 bajtów, dozwolone pole sortowania,
kierunek asc/desc oraz płaskie filtry tekstowe z listy. Callback sam nakłada zakres
użytkownika i interpretuje cursor. Nie przekazuj pól/filtrów bezpośrednio do SQL.
Zwraca listę items i opcjonalne nextCursor. Serializacja listy usuwa pola spoza
jawnego schematu. Odpowiedzi mają Cache-Control: no-store.

Każda Action deklaruje politykę, Validator i handler. JSON musi być obiektem;
nieprawidłowe dane dają 422 z errors. Handler otrzymuje dane dopiero po pomyślnej
walidacji i autoryzacji. Adapter SymfonyValidator używa Collection z jawnymi
constraints i odrzuca nieznane oraz brakujące wymagane pola. Wspiera też Optional.
Resources nie zależy od Symfony; inny adapter implementuje Validator.

Schemat UI jest osobną, ograniczoną deklaracją typów string/integer/number/boolean,
etykiet, wymaganych pól, długości, zakresów i choices. Nie serializuje callbacków
ani dowolnych constraints PHP do JavaScript. Nie zastępuje walidacji serwera.
Komponenty Form/DataTable/Dialog oraz panel CMS należą do kolejnych etapów B1/B2.

Adapter opiera się na oficjalnym [Symfony Validator 6.4](https://symfony.com/doc/6.4/components/validator.html)
i [Collection](https://symfony.com/doc/6.4/reference/constraints/Collection.html).

## Migracje i wycofanie

Migrator otrzymuje PDO, katalog aplikacji i migrationDirectories z rejestru.
Komenda db:migrate fasady przekazuje ten rejestr; boot wykonuje wyłącznie rejestrację.
Moduły są wykonywane w kolejności zależności, potem katalog aplikacji.
Historia modułu używa owner:basename.php, a stare migracje aplikacji nadal basename.php.
Usunięcie modułu z konfiguracji nie usuwa tabel i nie wykonuje downgrade.

SQLite używa blokady plikowej obok bazy i transakcji na każdą migrację. MySQL
używa GET_LOCK dla wybranej bazy; DDL może wykonać implicit commit. Migrator
odrzuca uruchomienie wewnątrz istniejącej transakcji. Limit oczekiwania blokady
wynosi 5 s. Wszystkie instancje SQLite muszą widzieć tę samą ścieżkę bazy i pliku
blokady. Zmiany nazw już opublikowanych owner/migrationId wymagają jawnego mapowania.
