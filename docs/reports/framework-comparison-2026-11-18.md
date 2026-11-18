# Yellow-Goose a istniejące frameworki i biblioteki

Odczyt dokumentacji: 2026-11-18. Porównanie dotyczy mechanizmów, nie identyczności
API ani wyników benchmarku. Wersjonowane źródła wskazano przy odpowiednich funkcjach.
Nie instalowano tych rozwiązań w YG i nie potwierdzono jeszcze wspólnego zestawu zależności.

Większość planowanej infrastruktury ma gotowe odpowiedniki. Wyróżnikiem YG pozostaje
kompozycja PHP + React SSR przez V8Js oraz wspólny kontrakt sceny, komend i workspace.
Nie ma powodu budować własnego storage drivera, silnika GPU czy drugiej rozbudowanej kolejki.

## 1. Porównanie ekosystemów

| Ekosystem | Co dostarcza | Core a dodatki | Konsekwencja dla YG |
| --- | --- | --- | --- |
| Symfony | Niezależne komponenty, konfiguracja, walidacja, formularze, bezpieczeństwo, Messenger | Komponent Composer jest biblioteką; bundle integruje funkcję z Symfony | Najbliższy wzorzec podziału infrastruktury; pojedyncze komponenty można wykorzystać bez pełnego Symfony |
| Laravel 13.x | Auth/policies, walidacja, ORM, storage, kolejki i scheduling; pakiety integrują service providers | Reverb jest osobnym serwerem; Filament jest odrębnym projektem | Dobry wzorzec composition root i opcjonalnych usług; nie kopiujemy całego Laravel do YG |
| Next.js App Router | Trasy React, layouty, renderowanie serwerowe i klienckie, metadata, granice lazy loading | CMS, trwały storage modeli i autorytatywna gra wymagają aplikacji/usług | Wzorzec trwałego layoutu i client-only; inny runtime serwera niż PHP/V8Js |
| Inertia | Protokół stron, wizyty bez przeładowania, forms i persistent layouts | Adapter serwerowy; SSR przez zewnętrzny runtime JS | Najbliższy odpowiednik obecnego page protocol YG; standardowy SSR Inertia nie usuwa Node/Bun |
| Django | Modele, formularze i admin oparty na metadanych modelu | Admin służy zarządzaniu wewnętrznemu; procesowy edytor ma własne widoki | Potwierdza rozdzielenie generowanego CRUD od edytora sceny lub interfejsu gry |

Źródła dla zakresów: [komponenty Symfony](https://symfony.com/doc/current/components/using_components.html),
[bundles](https://symfony.com/doc/current/bundles.html),
[pakiety Laravel](https://laravel.com/docs/13.x/packages),
[Next layout](https://nextjs.org/docs/app/api-reference/file-conventions/layout),
[Inertia layouts](https://inertiajs.com/docs/v3/the-basics/layouts),
[Django admin](https://docs.djangoproject.com/en/6.1/ref/contrib/admin/).
Dokumentacja Django wyraźnie rozróżnia administrację modelami od interfejsu
obsługującego proces biznesowy. Nie proponujemy zmiany backendu YG na Python.

## 2. Nasze pomysły zestawione z konkretnymi rozwiązaniami

| Pomysł YG | Istniejący mechanizm | Co wykorzystać / czego nie zakładać |
| --- | --- | --- |
| Biblioteki i moduł integracyjny | [Symfony components](https://symfony.com/doc/current/components/using_components.html), [Bundle System](https://symfony.com/doc/current/bundles.html) | Oddzielne manifesty i jawna integracja; bundle nie jest samą nazwą katalogu domeny. Symfony odradza organizowanie całego własnego App jako bundle |
| Rejestracja usług, tras, migracji | [Laravel package providers/discovery](https://laravel.com/docs/13.x/packages) | Mały kontrakt rozszerzenia YG; composition root wybiera moduły. Instalacja zależności nie wykonuje migracji |
| Walidacja danych i form | [Symfony Validator](https://symfony.com/doc/6.4/validation.html), [Form](https://symfony.com/doc/current/forms.html) | Validator można adaptować; Form nie jest automatycznie schematem formularza React. Nie wszystkie reguły PHP można wysłać jako regułę JS |
| Reguły dostępu do zasobu | [Symfony voters](https://symfony.com/doc/current/security/voters.html), [Laravel authorization](https://laravel.com/docs/13.x/authorization) | Polityka serwera dla operacji i relacji. Ukrycie przycisku/kolumny nie jest autoryzacją |
| ResourceDefinition i jawne actions | [API Platform operations](https://api-platform.com/docs/core/operations/) | Zasoby, providers/processors, paginacja i jawne operacje to istniejący wzorzec. API Platform wymaga integracji z własnym frameworkiem; nie podłączymy go samą zmianą nazwy endpointu |
| UI złożone z form/tables/actions | [Filament 5 overview](https://filamentphp.com/docs/5.x/introduction/overview) | Trafny wzorzec osobnych pakietów oraz panel buildera. Filament używa Laravel/Livewire, więc nie jest biblioteką React do bezpośredniego importu |
| Gotowy panel administracyjny Symfony | [EasyAdmin](https://symfony.com/bundles/EasyAdminBundle) | Inspiracja dla pól, filtrów, akcji i permissions. Wymaga Symfony i Doctrine ORM; nie jest adapterem obecnego ORM YG |
| Gotowy panel React | [React Admin data providers](https://marmelab.com/react-admin/DataProviders.html), [API Platform Admin](https://api-platform.com/docs/admin/) | Możliwy osobny panel/adapter danych zamiast własnego admin UI. Należy sprawdzić peer dependencies React 19.3.1 oraz protokół paginacji i błędów |
| DataTable | [TanStack pagination](https://tanstack.com/table/v8/docs/guide/pagination), [virtualization](https://tanstack.com/table/v8/docs/guide/virtualization) | Re-use logiki tabel; wirtualizacja DOM nie oznacza server-side filtering ani ograniczenia pobierania |
| Model storage z adapterami | [Flysystem](https://flysystem.thephpleague.com/docs/), [Laravel file storage](https://laravel.com/docs/13.x/filesystem) | Flysystem jako biblioteka, nie nowa implementacja S3. Quarantine, VersionId i promocja gotowego assetu należą nadal do kontraktu YG |
| Direct upload | [Laravel temporaryUploadUrl](https://laravel.com/docs/13.x/filesystem#temporary-upload-urls) | Mechanizm podpisania operacji to tylko część pipeline'u. Nie daje sam z siebie niezmienności, walidacji ani publikacji zasobu |
| Retry i worker | [Symfony Messenger](https://symfony.com/doc/current/messenger.html), [Laravel queues](https://laravel.com/docs/13.x/queues) | Wykorzystać dispatcher/transport/worker przez adapter; retry nie zastępuje idempotencji domeny i atomowego outbox |
| Publikowanie zmian UI | [Symfony Mercure](https://symfony.com/doc/current/mercure.html), [Laravel broadcasting](https://laravel.com/docs/13.x/broadcasting) | Port publikacji oddzielony od transportu i symulacji. Hub nie zatwierdza wyniku gry |
| WebSocket bez Node | [Laravel Reverb](https://laravel.com/docs/13.x/reverb), [OpenSwoole](https://openswoole.com/docs/modules/swoole-websocket-server) | Długotrwały serwer PHP jest możliwy; wymaga nadzoru, autoryzacji i izolacji stanu. Reverb jest zintegrowany z Laravel i nie jest gotowym serwerem gry YG |

Symfony Asset/AssetMapper i integracja Vite w Laravel rozwiązują zasoby webowe/build.
Nie są katalogiem modeli 3D z prywatnymi oryginałami, rewizjami i konwersją CAD.
Nazwy „asset” i „resource” w tych ekosystemach nie oznaczają automatycznie tego samego kontraktu.

## 3. Trwały viewport i renderowanie

Inertia opisuje persistent layouts, które zachowują instancję layoutu między
wizytami; Next App Router również zachowuje layout przy przejściach w jego zakresie.
YG obecnie zmienia key całej strony przy każdej wizycie. Proponowany WorkspaceShell
przenosi trwałą scenę ponad tę granicę i zmienia klucz dopiero przy zmianie projektu/pokoju.
Źródła: [Inertia](https://inertiajs.com/docs/v3/the-basics/layouts),
[Next](https://nextjs.org/docs/app/api-reference/file-conventions/layout).

W Next wyłączenie prerenderowania przez ssr:false dla komponentu klienckiego jest
jawną granicą, a nie skutkiem samego React.lazy. W YG analogiczny efekt wymaga
osobnego entry/stuba SSR oraz browser-only entry adaptera. Trzeba sprawdzić wynik builda.
[Lazy loading w Next](https://nextjs.org/docs/app/guides/lazy-loading).

Nie kopiujemy protokołu RSC ani założeń Next Server Actions do PHP/V8Js.
YG renderuje synchronicznie React przez renderToString, a dane pobiera loader PHP.
Ta metoda nie czeka na asynchroniczne dane ani nie zapewnia streamingu. Dodatkowo
obecny build YG aliasuje publiczny import do prywatnego pliku CJS React DOM
(resources/vite.mjs:11); zgodności przyszłego pakietu nie dowodzi szeroki peer range
React 19. A1 zachowuje przypięty zestaw i wymaga testu bundle’a w V8Js oraz hydracji.
[Ograniczenia renderToString](https://react.dev/reference/react-dom/server/renderToString),
[obecna konfiguracja YG](../../packages/framework/resources/vite.mjs).
Client Component w Next nie jest synonimem „tego kodu nigdy nie renderuje serwer”.

## 4. Warunek bez Node na serwerze

| Rozwiązanie | Co naprawdę oznacza wdrożenie |
| --- | --- |
| Symfony/Laravel z renderowaniem PHP | PHP może obsługiwać dynamiczne żądania bez Node; narzędzia frontendowe mogą wymagać go podczas builda |
| Inertia bez SSR | React renderuje klient; backend PHP zwraca page object |
| Standardowy Inertia SSR | Renderowanie korzysta z Node albo innego wspieranego runtime, np. Bun; to nie PHP/V8Js |
| Next pełne funkcje serwera | Wymaga obsługi serwerowego runtime JS; typowy self-host to Node/Docker. Nie jest zamiennikiem renderera PHP |
| Next static export | Może być obsługiwany jako pliki statyczne, ale ma ograniczenia funkcji serwerowych i nie daje dynamicznego SSR PHP |
| YG SSR | PHP + rozszerzenie V8Js oraz zgodny samodzielny bundle; Node wyłącznie build/dev |

Źródła: [Inertia SSR](https://inertiajs.com/docs/v3/advanced/server-side-rendering),
[Next deployment](https://nextjs.org/docs/app/getting-started/deploying),
[Next static export](https://nextjs.org/docs/app/guides/static-exports),
[obecny kontrakt YG](../ssr.md).
Warunek obejmuje też uploady po wdrożeniu: Node CLI do optymalizacji glTF nie jest
narzędziem build/dev, gdy przetwarza pliki użytkowników na produkcji. Dla walidacji
istnieje natywny gltf_validator, dla optymalizacji natywny gltfpack; ich użycie
w YG wymaga jeszcze prototypu i testu zachowania mapowania BIM.
[Walidator](https://github.com/KhronosGroup/glTF-Validator#cli-tool),
[gltfpack](https://github.com/zeux/meshoptimizer/blob/master/gltf/README.md).

Inny runtime JS, zdalny renderer albo eksport statyczny mogą usuwać binarkę Node,
ale nie są równoważne uzgodnionemu rozwiązaniu dynamicznego SSR wewnątrz PHP.

## 5. 3D i CAD są osobną warstwą

W opisanych mechanizmach webowych nie dostajemy kompletnego edytora ogrodu,
konwertera dowolnego CAD ani autorytatywnej symulacji gry. Web framework zapewnia
integrację HTTP/UI i usług, a rendering zostaje w bibliotece browser-only.

Babylon.js dostarcza m.in. scenę, kamery, picking, animacje i obsługę WebGL/WebGPU;
Three.js/R3F oferują inny sposób składania runtime 3D. To alternatywy adaptera,
nie obowiązkowe zależności całego YG.
[Babylon](https://www.babylonjs.com/specifications/),
[R3F](https://r3f.docs.pmnd.rs/advanced/scaling-performance).
IFC wymaga osobnego mapowania geometrii i semantyki, np. przez IfcOpenShell;
RVT/DWG pozostają osobnymi integracjami, zależnymi od próbek i dostępnych narzędzi.
[IfcOpenShell](https://docs.ifcopenshell.org/ifcopenshell/geometry_iterator.html).

## 6. Rekomendacja: adaptować czy pisać

| Zachować jako własny kontrakt YG | Oprzeć na zewnętrznej implementacji | Pozostawić w aplikacji |
| --- | --- | --- |
| Bridge PHP–React/V8Js i zgodność builda | Symfony Validator przez adapter | Reguły i cena garażu |
| Page protocol, trwały workspace i powiązanie wyboru sceny z UI | Flysystem i vendor SDK dla szczególnych operacji storage | Ekonomia, walka i zasady rankingu |
| Dokument sceny, jednostki, rewizje i komendy | Messenger/wybrany transport jobs | Geometria parametryczna domu/ogrodu |
| Granice transakcji, receipts i outbox | TanStack Table albo osobny adapter React Admin | Polityki produktu i publikacji |
| Rejestr modułów i profile instalacji | Babylon/Three, narzędzia IFC | Konkretny runtime pokoju i format eksportu |

Biblioteki Symfony nie dodają automatycznie swoich zabezpieczeń do YG. Przykładowo
Symfony chroni sesyjne odpowiedzi przed współdzielonym cache; YG musi określić to
w bridge/CMS. Draft i preview wymagają polityki w obu kanałach HTML/page JSON,
a surowy HTML CMS wymaga sanitizacji.
[Symfony cache i sesje](https://symfony.com/doc/current/http_cache.html#http-caching-and-user-sessions),
[React i surowy HTML](https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html).

Dla CMS są dwie rozsądne ścieżki: własne małe UI YG na wspólnych komponentach
albo opcjonalny panel React Admin z data providerem. Wybór gotowego Filament/EasyAdmin
wymagałby oddzielnej aplikacji/integracji ich stosu, a nie prostego plugina React.
Decyzję o własnym panelu albo React Admin należy podjąć przed implementacją
Form/DataTable/AssetPicker w B1, żeby nie budować równolegle dwóch paneli.
B1 może niezależnie realizować port workspace, asset upload i viewer.
Granice core/assets/scene nie zależą od wyboru panelu.

## 7. Zgodność wersji

Obecny YG deklaruje PHP >=8.1. Symfony 7.4 LTS wymaga >=8.2, a Symfony 6.4 >=8.1;
nie wolno wybrać dowolnego current bez sprawdzenia manifestów.
[Symfony 7.4](https://symfony.com/releases/7.4), [Symfony 6.4](https://symfony.com/releases/6.4).
Laravel 13 wymaga PHP >=8.3, co dodatkowo potwierdza, że jego pakiety integracyjne
nie są automatycznie zgodne z lekkim profilem YG.
[Laravel release notes](https://laravel.com/docs/13.x/releases).

Oddzielne pakiety/adaptory mogą deklarować wyższe minima, nie podnosząc minimum
core. Weryfikacja wymaga Composer resolution oraz peer dependency check npm
w projektach testowych. Niniejsze porównanie potwierdza wzorce i dostępność API,
a nie wykonaną instalację całego ekosystemu w YG.

Plan wynikający z porównania: [granice pakietów i fale ekstrakcji](package-architecture-2026-11-18.md).
