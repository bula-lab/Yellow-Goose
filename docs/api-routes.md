# Dokumentacja API: API Routes

API Routes pozwalają na łatwe tworzenie backendowych punktów końcowych (endpointów) jako prostych plików PHP wewnątrz katalogu `app/pages/api`. Ta funkcja umożliwia budowanie logiki backendowej bez potrzeby tworzenia oddzielnego projektu API - jest to realizacja filozofii "Zero-API" / Backend-for-Frontend.

## Podstawy

Każdy plik `.php` umieszczony w katalogu `app/pages/api` staje się endpointem API. System routingu dla API działa analogicznie do routingu stron.

- `app/pages/api/hello.php` → `/api/hello`
- `app/pages/api/users/list.php` → `/api/users/list`

### Struktura Pliku API

Plik endpointu API musi zwracać anonimową funkcję, która przyjmuje jako argumenty obiekty `Request` i `Response`. Framework automatycznie wstrzyknie te obiekty.

```php
// app/pages/api/hello.php

use ŻoltaGaska\Http\Request;
use ŻoltaGaska\Http\Response;

return function (Request $request, Response $response) {
    $data = ['message' => 'Witaj, świecie!'];

    return $response->json($data);
};
```

## Obiekty Request i Response

### Obiekt `Request`

Obiekt `Request` dostarcza metod do interakcji z przychodzącym żądaniem HTTP.

- `method()`: Zwraca metodę HTTP żądania (`GET`, `POST`, `PUT`, etc.).
- `query('klucz', 'domyslna?')`: Pobiera zmienną z query string (`/api/users?sort=asc`).
- `body('klucz', 'domyslna?')`: Pobiera zmienną z ciała żądania (np. z JSON-a wysłanego metodą POST).
- `header('klucz')`: Pobiera wartość z nagłówka HTTP.

**Przykład:**
```php
// POST /api/register

return function (Request $request, Response $response) {
    if ($request->method() !== 'POST') {
        return $response->status(405)->json(['error' => 'Method Not Allowed']);
    }

    $email = $request->body('email');
    $password = $request->body('password');

    // ... logika rejestracji użytkownika ...

    return $response->status(201)->json(['status' => 'created']);
};
```

### Obiekt `Response`

Obiekt `Response` służy do konstruowania odpowiedzi, która zostanie wysłana do klienta.

- `status(kod)`: Ustawia kod statusu HTTP odpowiedzi. Metodę tę można łączyć.
- `json(tablica)`: Wysyła odpowiedź w formacie JSON z odpowiednim nagłówkiem `Content-Type`.
- `send(string)`: Wysyła odpowiedź jako zwykły tekst lub HTML.
- `redirect(url)`: Przekierowuje użytkownika na inny adres URL.

**Przykład łączenia metod:**
```php
return $response->status(404)->json(['error' => 'User not found']);
```

## Dynamiczne API Routes

API Routes wspierają te same dynamiczne wzorce co strony, włączając w to `[param]` i `[...slug]`.

**Przykład:** `app/pages/api/users/[id].php`

```php
// GET /api/users/123

return function (Request $request, Response $response) {
    // Parametr `id` jest dostępny w query, mimo że jest częścią ścieżki
    $userId = $request->query('id');

    $user = App\Models\User::find($userId);

    if (!$user) {
        return $response->status(404)->json(['error' => 'User not found']);
    }

    return $response->json($user);
};
```

## Middleware (Koncepcja)

Możesz chcieć uruchomić pewien kod przed wykonaniem logiki endpointu, np. w celu autoryzacji użytkownika. Można to osiągnąć za pomocą middleware.

Chociaż szczegółowa implementacja może się różnić, koncepcja polega na zdefiniowaniu warstwy pośredniczącej, która decyduje o przekazaniu żądania dalej.

**Przykład koncepcyjny:**

```php
// app/Middleware/AuthMiddleware.php
class AuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!AuthService::isLoggedIn($request)) {
            // Zatrzymaj żądanie i zwróć błąd
            return new Response(401, ['error' => 'Unauthorized']);
        }

        // Przekaż żądanie do następnego middleware lub do samego endpointu
        return $next($request);
    }
}

// W pliku konfiguracyjnym można by przypisać middleware do tras:
'/api/profile/*' => [AuthMiddleware::class],
```
