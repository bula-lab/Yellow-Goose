# Walidacja planu rozbudowy YG

Data: 2026-11-18. Kod bazowy: b1e8000.
Status: wcześniejsza walidacja, zachowana z własną historią ustaleń.
Aktualną ocenę i dodatkowe korekty zawiera [niezależna rewalidacja](fresh-plan-revalidation-2026-11-18.md).
Wejścia: pełny [plan](3d-framework-expansion-2026-11-13.md) oraz wymagania i decyzje
użytkownika z tej rozmowy. Wymagania są osobnym źródłem; nie uznajemy tez planu za ich dowód.

Wersja wejściowa miała 492 linie, SHA-256
2b4e5dc8427317a59bba8d0d7045af442854b6e9cbb358f3a762d3f10c36df58.
Lokalizacje ustaleń „przed” odnoszą się do tej wersji.

## 1. Werdykt i granice

Przed poprawkami: FAIL — sprzeczny podział pakietów, niepoprawna kolejność zależności
pipeline'u assetów oraz niezabezpieczone zamrożenie uploadu.

Po tamtych poprawkach: PASS WITH WARNINGS dla roadmapy oraz rozpoczęcia A0/A1.
Późniejsza rewalidacja wykazała dodatkowe luki zgodności legacy npm i granic
pakietów; jej korekty oraz aktualna bramka A0 przed A1 zastępują ten stan.
Brak otwartego Critical blokującego inwentaryzację i ekstrakcję bez zmiany zachowania.
Nie oznacza to akceptacji do wdrożenia wszystkich przyszłych funkcji.
CAD, publikacja modeli, konfiguracja gry i infrastruktura mają jawne bramki przed
własnymi falami. Nie blokują niezależnego A0/A1, lecz nie wolno ich uznać za gotowe.

Wykonano oddzielne przeglądy architektury/wykonalności, bezpieczeństwa, źródeł
porównawczych oraz lokalny przegląd scenariuszy QA. Po edycji ponowiono przegląd
architektury i bezpieczeństwa. Ustalenia dotyczą planu, nie podatności
nieistniejącego jeszcze modułu uploadu/gry.

## 2. Macierz wymagań

| ID | Wymaganie i źródło | Status / fala | Oczekiwany wynik i sprawdzenie |
| --- | --- | --- | --- |
| R1 | WWW + CMS jak Solidne-Garaże; pierwotna prośba użytkownika | W zakresie B2 | Redaktor publikuje produkt; gość widzi SSR i poster, potem viewer; polityka blokuje cudzy draft |
| R2 | Gra 2D/3D, tabele, modale; pierwotna prośba | W zakresie B1/D | Modal i ranking nie niszczą viewportu; UI nie wykonuje nieuprawnionej akcji |
| R3 | Oba warianty gry; odpowiedź użytkownika „Chcę obsłużyć oba warianty” | D1 strategia, D2 realtime | Deduplikacja kosztu i czas serwera; osobno autorytatywna symulacja i reconnect |
| R4 | Portal domów/planów/ogrodów i edycja; prośba i odpowiedź „Oba rodzaje; portal ma też umożliwiać edycję sceny” | B1/C1/C2 | Gotowy GLB oraz uzgodniony import CAD; zapis sceny, parametry i konflikt rewizji |
| R5 | Node dopuszczony do build/dev, nie na serwerze; wcześniejsza decyzja | Cały plan | PHP/V8Js dla SSR, klientowy silnik; procesy gry/importu bez Node; brak ukrytych importów serwerowych |
| R6 | Walidacja i poprawienie planu; prośba z 2026-11-18 | Bieżące zadanie, wykonane | Pełna lektura, odniesienie do źródeł i kodu, poprawione ustalenia z tabeli poniżej |
| R7 | Pakiety/bundle/biblioteki zamiast jednego dużego frameworka; prośba z 2026-11-18 | A0/A1 i każda nowa fala | Osobne manifesty, DAG i konsumenci; framework jest fasadą, nie właścicielem nowych domen |
| R8 | Zestawienie z Symfony/Laravel/Next i innymi; prośba z 2026-11-18 | Bieżące zadanie, wykonane | [Porównanie](framework-comparison-2026-11-18.md) rozdziela core/ekosystem, re-use/własny kontrakt i warunek bez Node |

Zakres dodany jako niezbędne zależności, a nie osobny produkt: autoryzacja, storage,
transakcje i jobs. Pełny CAD, współedycja na żywo i wybór konkretnej ekonomii gry
nie są potajemnie utożsamiane z samym viewerem; decyzje są jawnie przekazane do właściwych fal.

## 3. Ustalenia i poprawki

| ID | Waga przed poprawką | Dowód / problem | Zmiana i status |
| --- | --- | --- | --- |
| C1 | CRITICAL | Plan wejściowy:80–81 i etapy:420–427 odkładały pakiety i rozwijały src/resources jednego frameworka | [Plan pakietów](package-architecture-2026-11-18.md): osobny Composer/npm, fasada, DAG, migracja importów i profile; ZAMKNIĘTE |
| C2 | CRITICAL | Plan wejściowy:142 wymagał zadania pochodnych, ale Jobs wskazywał dopiero przy imporcie:425; Cli.php:16–44 nie ma workera | Minimalne jobs przed pipeline'em B1; brak ready zależnego od późniejszego konsumenta; ZAMKNIĘTE |
| C3 | CRITICAL | Plan wejściowy:138–148: ponowienie presigned PUT po complete mogło zmienić zaakceptowane bajty | Zamrożona wersja w kwarantannie, dokładny odczyt workera i immutable promocja bez prawa PUT klienta; ZAMKNIĘTE |
| W1 | WARNING | „Jeden aktywny edytor” nie wynikał z prośby i kolidował z przypadkiem dwóch kart | Równoległe sesje z CAS dozwolone; współedycja na żywo i wyłączna dzierżawa to inne decyzje; ZAMKNIĘTE |
| W2 | WARNING | Ogólne uprawnienia nie określały attach/clone/parent/assetVersion | Walidacja relacji scene–project–entity–assetVersion, obcych referencji i podpisania URL; ZAMKNIĘTE |
| W3 | WARNING | Idempotencja bez zakresu unikalności i transakcyjnego adaptera | Scoped receipt, hash operacji, aktualna autoryzacja, constraint SQL i to samo połączenie zapisu/outbox; ZAMKNIĘTE |
| W4 | WARNING | Sekwencje klienta nie rozstrzygały właściciela pokoju po awarii | Epoch/fencing i jeden właściciel, trwały wynik według matchId, ACK inputu oddzielony od wyniku; ZAMKNIĘTE |
| W5 | WARNING | „Migracje dodające” nie opisywały wyłączenia pakietu ani powrotu formatu | Owner/migrationId, expand/contract, zakres czytanych wersji, snapshot i brak automatycznego DROP; ZAMKNIĘTE |
| W6 | WARNING | Brak pełnego śladu nowych jednostek i niepotrzebna kwantyzacja każdego CAD do mm | heightMm tylko jako profil parametrycznego garażu; źródłowa precyzja CAD zachowana; macierz konsumentów poniżej; ZAMKNIĘTE |
| W7 | WARNING | Dotychczasowy test niezależnego konsumenta zna jeden vendor/framework i współdzieli node_modules | Profile oraz testy z fizycznych paczek bez ukrytych zależności; doprecyzowano ograniczenie obecnego dowodu; ZAMKNIĘTE W PLANIE |
| W8 | WARNING | PHP >=8.1 i suggest V8Js mogły zostać przypadkowo zmienione przez ekstrakcję | A1 zachowuje semantykę; nowe adaptery deklarują osobne minima; Session w fasadzie A1, security A2; ZAMKNIĘTE W PLANIE |

AWS potwierdza możliwość wielokrotnego użycia podpisanego URL i nadpisania klucza;
niezmienność gotowego zasobu jest dodatkową gwarancją naszego pipeline'u.
[Źródło](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-presigned-url.html).

## 4. Dowody z kodu i ograniczenia istniejących testów

- Runtime.php:4–29 tworzy bezpośrednio integracje DB, sesji i SSR; nie jest lekkim niezależnym kernelem.
- Application.php:52–61 chroni mutacje CSRF, ale nie uwierzytelnia właściciela projektu.
  Application.php:85–95 rozdziela page JSON od renderowania; obecne head zawiera tytuł globalny.
- Request.php:6,35,44 ma limit 1 MiB; nie podnosimy go globalnie, aby wcisnąć do JSON modele.
- Response.php:9 wymaga string body; streaming zasobów jest przyszłym kontraktem lub zadaniem storage/webserwera.
- client.jsx:29 zmienia key przy każdej wizycie; zachowanie trwałego viewportu jest nową funkcją.
- V8JsRenderer.php:19–46 ma natywne limity i osobny kontekst; nie implementuje WebGL.
- Builder.php:114–121 zapisuje po pojedynczym kluczu; to nie CAS z expectedRevision.
- Connection.php:8–14,41–57 ma statyczne pojedyncze PDO; nie nadaje bez zmian izolacji kontekstu wielu współbieżnych graczy.
- Migrator.php:12–35 skanuje jeden katalog i basename, bez downgrade; MySQL DDL nie jest objęte transakcją.

tests/navigation.php korzysta z tymczasowych stron, loaderów i renderera rzucającego
wyjątek, co rzeczywiście sprawdza pominięcie SSR przy JSON. Nie bada jednak trwałego
workspace. tests/ssr.php wymaga prawdziwego V8Js, a tests/package.py instaluje
fizyczną kopię pakietu Composer, lecz współdzieli npm z root i nie testuje dystrybucji npm.
Fixture konsumenta nie zawiera sceny, uploadu, auth ani gry. Nie dopisujemy mu takich dowodów.

## 5. Ślad jednostek i konsumentów

Wyszukiwanie `rg -n 'heightMm|createSelector|SceneEntity|WorkspaceShell|ClientOnly|ResourceDefinition' app packages tests`
nie znalazło tych implementacji. heightMm jest kontraktem projektowanym, nie migrowanym
polem istniejącej bazy. Brak createSelector oznacza, że skrypt selector_read_sites.py
nie ma zastosowania; nie pomijamy istniejącego selektora. Poniższe miejsca są
proponowanymi właścicielami przyszłych odczytów, a nie udawanymi plikami produkcyjnymi.

Jeden proponowany fixture GARAGE-213: wejście 213 cm, zapis 2130 mm, render 2.13 m.

| Konsument / granica | Przyszły właściciel | Wartość oczekiwana / kontrola | Dowód dziś |
| --- | --- | --- | --- |
| Formularz i inspector | npm ui LengthField + editor Inspector | 213 cm; bez ponownego mnożenia przy zmianie panelu | Sprawdzona arytmetyka, brak implementacji |
| API wejściowe i komenda | zasób konfiguratora / walidator PHP | heightMm=2130, typ integer i zakres domeny; błędne jednostki → 422 | Kontrakt, test wymagany przy B2 |
| Trwały zapis | repozytorium konfiguratora + database | 2130; zapis/odczyt bez przeliczenia drugi raz | Kontrakt, brak tabeli |
| Lista i szczegóły | npm ui DataTable + resource serializer | 213 cm w obu widokach | Kontrakt, osobne asercje UI |
| Podgląd geometrii | adapter Babylon/scene | 2130/1000=2.13 m | Arytmetyka; wymagany test rzeczywistego viewera |
| Tooltip i pomiar | editor annotations/measurement | 213 cm dla tej samej krawędzi | Kontrakt, nie dowód z testu formularza |
| Walidacja progu / statystyki | reguły garażu | Porównanie w mm; np. próg 2200 mm: 2130 <= 2200 | Przykład projektowy; regułę ustala aplikacja |
| Eksport i raport | aplikacyjny eksport konfiguracji | Liczba i jawna jednostka, np. 213 cm; nie skala silnika | Dopiero po włączeniu eksportu; N/A dla A0/A1 |
| Dokumentacja | plan funkcjonalny sekcja 6 i ta tabela | Rozróżnienie profilu garażu od dokładności źródła CAD | Sprawdzono oba dokumenty |
| Migracja istniejących rekordów | brak istniejącego pola | N/A: nie ma population do backfill w tym zadaniu | Wyszukiwanie kodu/dokumentacji |

Wszystkie nowe jednostki i konsumenci są N/A dla czystej ekstrakcji A0/A1.
Podczas wdrażania B2 powyższa tabela musi dostać rzeczywiste pliki i fixture testowy;
nie można zamknąć całej ścieżki tylko testem formularza lub arytmetyki.
Dla CAD sprawdzamy osobno źródłowe jednostki i precyzję, bez wymuszania heightMm.

Parametry eksperymentu gry: 20 Hz daje 50 ms/tick, a 10 Hz 100 ms/snapshot.
To sprawdzona konwersja, nie wynik pomiaru p95 ani SLA. Zegary i rankingi nie mają
jeszcze implementacji; ich dokładne reguły są bramką D1/D2.

## 6. Scenariusze wymagane przy implementacji

| ID | Fixture / rola / wejście | Oczekiwany wynik | Fala i środowisko |
| --- | --- | --- | --- |
| T1 | Legacy demo bez @yellow-goose/* w npm, nowa aplikacja npm oraz core + minimalny bootstrap API | Legacy importy vendor/FQCN/CLI działają; nowa aplikacja buduje archiwa npm poza monorepo; core nie wymaga PDO/React/V8Js | A1 po macierzy A0: rzeczywiste instalacje bez symlinków/root node_modules; dispatcher modułów dopiero A2 |
| T2 | Przypięty React/React DOM + Vite; CSR, SSR i viewer z markerem instancji | CSR bez V8Js; prawdziwy bundle SSR/useId i hydracja bez Node na serwerze; modal/back zachowuje viewport; engine nie ma w bundle SSR | A1: zgodność renderera i hydracji; B1: lifecycle/GPU w przeglądarce |
| T3 | Autor A: poprawny GLB, complete, ponowny PUT, retry i uszkodzony import | Ready oraz wejście workera nie zmieniają bajtów; retry nie duplikuje wersji; niepoprawne dane nie publikują się | B1: faktyczny adapter storage i worker, nie mock-only |
| T4 | Dwie sesje tej samej rewizji; obcy assetVersionId; powtórzony commandId | Jedna zmiana i jeden outbox; CAS 409; brak dostępu do cudzych referencji; zmieniona treść commandId odrzucona | A2/C1: współbieżne połączenia MySQL; SQLite nie dowodzi semantyki produkcji |
| T5 | GARAGE-213 i reprezentatywny IFC | Wszystkie powierzchnie jednostek z tabeli; źródłowy ID i precyzja IFC zachowane; awaria importera izolowana | B2/C2: dane kontraktowe + prawdziwe pliki i viewer |
| T6 | Gracz: dwa równoległe starty/nagrody i zmieniony zegar klienta | Atomowy koszt, brak duplikatu i czas serwera; ranking ma uzgodnioną regułę remisów | D1: zegar testowy i SQL |
| T7 | Dwa właściciele pokoju, stara epoch, reconnect, powtórzony matchId | Stary proces odrzucony, wynik zapisany raz, prywatne encje niewidoczne; odtworzenie zgodne z ustaloną polityką | D2: rzeczywisty proces i test awarii, brak Node |
| T8 | Gość i redaktor: draft, preview, lista/API, HTML i JSON tej samej strony; treść z tagiem/script | Gość widzi wyłącznie publikację; brak współdzielenia tokenów/draftów w cache; treść nie wykonuje kodu | B2: dwa rzeczywiste konteksty sesji, SSR i przeglądarka |

To projekt przyszłych scenariuszy, nie deklaracja ich dzisiejszego wykonania.
Nowe funkcje nie istnieją w repozytorium; nie ma uczciwej możliwości uruchomienia T1–T8
w całości przed ich implementacją. Dzisiejsze istniejące testy opisano osobno.

## 7. Pozostałe ostrzeżenia i bramki

| Bramka | Dosłowne pytanie / opcje | Właściciel | Skutek |
| --- | --- | --- | --- |
| Pierwszy pion po ekstrakcji | Czy pierwszy produkt to CMS/konfigurator, editor czy gra? Rekomendacja: CMS/konfigurator | Użytkownik | Wybór B2/C/D; A0/A1 niezależne |
| Formaty CAD | Które konkretne formaty i próbki muszą działać: IFC, DWG, RVT? | Użytkownik | Bez próbek C2 i kolejne adaptery nie są planem implementacyjnym |
| Infrastruktura | Czy B1 użyje workera pod nadzorem czy cyklicznego CLI/cron; czy C2/D2 mają izolowane Python/C++ i stałe procesy PHP? | Użytkownik/operator | Pierwszy wybór przed pochodnymi B1; kolejne przed importem/realtime |
| Panel | Czy panel B1/B2 budujemy z komponentów YG, czy używamy React Admin z data providerem? | Użytkownik / prowadzący implementację | Przed Form/DataTable/AssetPicker w B1; nie blokuje portu workspace ani storage |
| Poster | Czy B1 dostaje gotowy poster od redaktora, czy automatyczny render jest obowiązkowy? Rekomendacja: gotowy poster | Użytkownik / prowadzący implementację | Auto wymaga wybranego renderera bez Node; nie zakładamy WebGL w V8Js |
| Wydajność | Jakie urządzenie, rozmiar sceny i liczba graczy/pokój są celem? | Użytkownik | Wymagane przed SLA i wyborem parametrów symulacji |
| Publikacja | Czy unpublish usuwa ofertę, czy blokuje nowe pobrania plików? | Użytkownik | Wybór ACL/cache/TTL przed publikacją assetu |
| Współedycja | Czy wymagana jest współedycja na żywo, czy równoległe sesje z 409 wystarczą? | Użytkownik | Współedycja wymaga odrębnego protokołu, nie blokuje CAS |
| Gra | Jakie są remisy/sezony rankingu i utrata postępu po awarii? | Użytkownik/operator | Reguły D1/D2 i recovery; brak domysłów o produkcji |

Zgodność konkretnych zależności musi zostać potwierdzona Composer/npm. Nowy adapter
nie może milcząco podnieść PHP core ani wymusić V8Js w CSR. Te bramki nie zostały
rozstrzygnięte samym przeczytaniem dokumentacji producenta.

## 8. Kontrole wykonane 2026-11-18

- Pełna lektura planu i decyzji użytkownika; przegląd źródeł oraz bootstrapu i fixture konsumenta.
- Oficjalne dokumentacje frameworków; weryfikacja rozróżnienia core/ekosystem/runtime.
- npm test: PASS, 145 asercji SQLite + 20 nawigacji.
- node --test tests/*.test.mjs: PASS, 14 testów.
- Sprawdzona arytmetyka jednostek, lokalne odwołania dokumentów i planowany graf pakietów.
- Nie ponawiano w tym zadaniu Docker SSR, MySQL, Chromium ani importów CAD.
  Nie zmieniono kodu wykonawczego, manifestów zależności ani CI; wcześniejsze wyniki
  wdrożenia nie są przedstawiane jako test nowej funkcji 3D.

Wersje poprawione: [plan](3d-framework-expansion-2026-11-13.md),
[pakiety](package-architecture-2026-11-18.md), [porównanie](framework-comparison-2026-11-18.md).
