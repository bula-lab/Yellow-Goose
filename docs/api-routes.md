# API

Endpoint jest plikiem PHP w `app/pages/api/`, zwracającym funkcję.
`index.php` odpowiada adresowi katalogu, `[id].php` — parametrowi.

```php
<?php
use ZoltaGaska\Http\Request;
use ZoltaGaska\Http\Response;
return static function (Request $request, array $params): Response {
    $request->requireMethod(['GET', 'HEAD']);
    return Response::json(['id' => $params['id']]);
};
```

Dostęp do wejścia: `$request->uri`, `$request->method`, `query('page', '1')`,
`header('Content-Type')` i `json()`. Metoda `json()` wymaga obiektu JSON,
Content-Type application/json i maksymalnie 1 MiB treści.

Odpowiedzi: `Response::json($array, $status, $headers)`,
`Response::html($html, $status)` albo `new Response($body, $status, $headers)`.
Tablica zwrócona bezpośrednio z handlera jest automatycznie zamieniana na JSON.

Kontrolowany błąd: `throw new HttpException(422, 'Popraw dane.', ['title' => 'Wymagane.'])`.
HTTP 400 oznacza błędny JSON/query, 403 błędny CSRF, 404 brak zasobu,
405 niedozwoloną metodę, 413 za dużą treść, 415 błędny Content-Type.
Nieoczekiwane wyjątki są logowane; klient otrzymuje ogólny błąd 500.

Wszystkie mutacje API wymagają sesji oraz nagłówka X-CSRF-Token zgodnego z props strony.
GET/HEAD/OPTIONS nie mogą modyfikować danych. Samo pominięcie CSRF dla OPTIONS
nie implementuje CORS — endpoint nadal musi jawnie dopuszczać metodę.
CSRF nie zastępuje uwierzytelniania ani autoryzacji.
