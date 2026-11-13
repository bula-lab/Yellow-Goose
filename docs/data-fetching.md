# Dane stron z PHP

Dla `app/pages/posts/[id].jsx` funkcja pobierająca dane znajduje się w
`app/loaders/posts/[id].php`:

```php
<?php
use App\Services\Posts;
use ZoltaGaska\Http\Request;
return static fn (Request $request, array $params): array =>
    ['post' => Posts::find($params['id'])->toArray()];
```

Framework wywołuje loader na serwerze i przekazuje wynik do komponentu React.
Loader może też zwrócić Response albo rzucić HttpException, np. 404.
Bez loadera komponent dostaje tylko `params` i `csrfToken`.
Te dwa pola są zarezerwowane i nie można ich nadpisać wynikiem loadera.

Dane są osadzane jako JSON z escapowaniem HTML. W trybie builda V8Js renderuje
React do HTML wewnątrz PHP, a przeglądarka hydratuje go z tymi samymi props.
Development korzysta z CSR i HMR. Szczegóły: [SSR](ssr.md).
Nie ma wykonania `getServerSideProps` z JSX, statycznego generowania ani ISR.
PHP nie interpretuje JavaScriptu.
Nie przekazuj do props sekretów ani całych konfiguracji serwera.
