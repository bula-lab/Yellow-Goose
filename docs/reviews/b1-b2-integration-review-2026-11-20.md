# Ocena prac agentów i integracji B1/B2

Data: 2026-11-20. Baza: 499ca68. Zakres: nowe pliki i diff drzewa roboczego
po dwóch równoległych zadaniach. Wcześniejsza implementacja A0–A2 stanowiła wejście.
Oceniono rzeczywisty kod, zależności, konsumentów i wyniki, nie tylko opisy agentów.

Werdykt: PASS dla pierwszego profilu B1/B2. Przyjmuję prace obu agentów po poprawkach.
Nie pozostał problem wymagający naprawy w tym zakresie. Nie jest to audyt całego
historycznego MVP ani akceptacja wydajności CAD/gry.

## Ustalenia i poprawki

| Ustalenie | Zmiana i dowód zamknięcia |
| --- | --- |
| Pierwszy Form mieszał wartości enum z jednostką wyświetlania | B1 zmienił mapowanie opcji. Chromium wysłał 2130 mm po wybraniu 213 cm i 2400 po 240 cm; rzeczywisty model zmienił wysokość |
| Ponawiane awarie workera mogły przekroczyć budżet prób | B1 dodał ograniczone odzyskiwanie oraz atomowy callback failed; test obejmuje przerwany ostatni lease |
| Stary lease mógł zgłosić sukces przy CAS bez zmienionego wiersza | B1 wprowadził StaleLease; efekt domenowy nie wykonał się |
| Osobno skończone skale mogły dać nadmierne złożone współrzędne | B1 ograniczył skale i światowy zasięg hierarchii; przypadek zagnieżdżonych transformacji został odrzucony |
| Finalizacja mogła omijać limit samych otwartych sesji | Budżet obejmuje kolejkę, przetwarzanie oraz źródłowe/gotowe bajty; testy wyczerpały oba limity |
| MySQL może porównywać identyfikatory bez rozróżnienia liter | Wspólna blokada limitu normalizuje ASCII case; niekanoniczne ID uploadu jest odrzucane przed storage |
| Transport Request gubił pojedynczy bajt ASCII 0 | Root zmienił rozpoznawanie false i dodał bounded bytes(); realne CGI zwróciło niezmienione bajty oraz 413 ponad limitem |
| Metadane jednostki nie należały do starego schematu PHP | Root dodał jawne unit/group z walidacją dodatniego, skończonego przelicznika |
| Opakowanie odpowiedzi plikowej mogło usunąć jej streaming | Site zachowuje FileResponse; CGI sprawdziło pełny model, Range i HEAD |
| Mobilny formularz miał rzeczywisty overflow po wyborze wersji | B2 ograniczył intrinsic fieldset/form; 390px testuje edytor i ofertę bez obcinania zawartości |
| Kwadratowy poster zmieniał rozmiar viewera przy starcie WebGL | B1 odtworzył błąd przed poprawką i dodał wspólny obszar16:9; 884×497,25 px pozostało stałe w SSR/loading/ready |

## Kontrola połączenia

AssetIntegration składa ten sam PDO dla finalizacji i joba. Własny port CMS nie
zależy od konkretnego storage. Callback publicznego odczytu sprawdza bieżącą
publikację bez rekurencyjnego wystawiania kolejnych podpisów. Publiczny odczyt
nie korzysta z draft pointer; cena i zapytanie są sprawdzane na serwerze.

Sprawdzono instalacje fizycznych archiwów poza repozytorium, a nie tylko linki
monorepo. CMS i UI nie wymagają Babylon przez samą instalację. SSR nie zawiera
silnika przeglądarkowego, a worker działa bez Node i bez PHP child-process execution.
Wymaganie PHP >=8.1 pozostało potwierdzone rzeczywistymi testami.

Pełna regresja A0–B2 zakończyła się exit 0, następnie powtórzono testy granicy
postera po ostatniej poprawce. Root obejrzał widok desktopowy i mobilny. Dotychczasowy
notatnik, jego nawigacja i HMR nadal przechodzą E2E.

[Rejestr wymagań, konsumentów i wyników](../reports/b1-b2-integration-2026-11-20.md).
Pozostają jawne ograniczenia profilu: lokalne pliki, dostarczany poster, statyczny
GLB, dane demonstracyjne oraz brak pomiarów fizycznego GPU. To zakres późniejszego
rozwoju, nie ukryte brakujące implementacje opisanej ścieżki B1/B2.
