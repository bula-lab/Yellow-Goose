# Routing plikowy

| Plik | Adres |
| --- | --- |
| app/pages/index.jsx | / |
| app/pages/posts/new.jsx | /posts/new |
| app/pages/posts/[id].jsx | /posts/123 |
| app/pages/posts/[id]/edit.jsx | /posts/123/edit |
| app/pages/docs/[...parts].jsx | /docs/a/b; parts jest tablicą |
| app/pages/search/[[...query]].jsx | /search lub /search/a/b |
| app/pages/api/posts/index.php | /api/posts |

Strony wspierają JSX/JS/TSX/TS. Pliki PHP są endpointami tylko w app/pages/api.
Pliki i katalogi z prefiksem _ lub . są pomijane.
Pierwszeństwo: segment statyczny, parametr, catch-all, opcjonalny catch-all.
Końcowy slash jest opcjonalny. Nieznana trasa zwraca 404.

Pierwsze wejście i odświeżenie zwracają dokument HTML. Po hydracji wewnętrzne
linki korzystają z nawigacji bez przeładowania dokumentu. Routing pozostaje w PHP;
przeglądarka pobiera wybrany przez serwer komponent z istniejącego globu Vite.
Po dodaniu strony w trybie produkcyjnym ponów npm run build.

## Nawigacja

Zwykłe linki `a href` działają również bez JavaScriptu. Po uruchomieniu klienta
kliknięcie lewym przyciskiem wewnętrznego linku wysyła GET z nagłówkami
`X-YG-Navigation: 1` i `X-YG-Version` z fingerprintem manifestu.
Odpowiedź JSON zawiera `page`, `props`, `url`, `version` i `head.title`.
Serwer wykonuje ten sam loader co dla dokumentu, ale pomija renderer SSR.
API pod /api zachowuje dotychczasowy kontrakt.

Framework utrzymuje jeden root Reacta i aktualizuje go po pobraniu komponentu.
Każda nawigacja montuje nową stronę; stan formularza nie przechodzi do innego wpisu.
Historia nie przechowuje props ani tokenów. Cofanie ponownie pobiera aktualne dane.
Obsługiwane są query string, cofanie/dalej, fokus nagłówka i przywracanie scrolla.
Nowe żądanie anuluje poprzednie; opóźniona odpowiedź nie nadpisuje bieżącej strony.
Błąd sieci pozostawia bieżący widok i pokazuje komunikat umożliwiający ponowienie.
Błędy HTTP 404/500 mogą być wyświetlane w tym samym dokumencie.

Ctrl/Cmd/Shift+klik, target, download, linki zewnętrzne i kotwice zachowują
standardowe działanie przeglądarki. `data-yg-reload` wymusza pełną nawigację.
Zmiana wersji builda powoduje pełne przeładowanie i pobranie zgodnych chunków.
Odpowiedzi stron zawierają `Vary: X-YG-Navigation, X-YG-Version` oraz domyślne no-store.
Przekierowanie loadera staje się odpowiedzią protokołu 409; zwykłe wejście
nadal otrzymuje standardowe przekierowanie HTTP.

Nawigacja z handlera, np. po zapisaniu formularza:

```js
import { navigate } from './vendor/yellow-goose/framework/resources/navigation.mjs';
await navigate('/posts/42?saved=1');
```

Ścieżkę importu dopasuj do miejsca pliku. Demonstracja udostępnia re-export
w app/lib/navigation.js. Opcja `{ replace: true }` zastępuje bieżący wpis historii.
Błąd sieci odrzuca Promise, więc handler może ponownie odblokować formularz.
