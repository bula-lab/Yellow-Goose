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

Linki wykonują pełną nawigację przeglądarki. Framework wybiera stronę po stronie PHP,
a komponent React jest ładowany osobnym chunkiem. Nie ma jeszcze routera SPA.
Po dodaniu strony w trybie produkcyjnym ponów npm run build.
