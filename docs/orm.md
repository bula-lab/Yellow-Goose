# Dokumentacja API: ORM (Baza Danych)

Żółta-Gąska zawiera wbudowany system mapowania obiektowo-relacyjnego (ORM) oparty na wzorcu Active Record. Inspiracją dla niego był Eloquent z frameworka Laravel. Każda tabela w Twojej bazie danych posiada odpowiadający jej "Model", który jest używany do interakcji z tą tabelą.

## Definiowanie Modeli

Modele domyślnie znajdują się w katalogu `app/Models`. Najprostszym sposobem na stworzenie modelu jest rozszerzenie bazowej klasy `ŻoltaGaska\Database\Model`.

```php
// app/Models/Post.php

namespace App\Models;

use ŻoltaGaska\Database\Model;

class Post extends Model
{
    // Framework automatycznie założy, że ten model
    // odpowiada tabeli `posts`.
}
```

### Konwencje Nazewnicze

- **Nazwa tabeli:** Framework automatycznie przyjmie, że nazwa tabeli to nazwa klasy modelu w liczbie mnogiej i formacie `snake_case`. (np. klasa `UserRole` mapuje się na tabelę `user_roles`).
- **Klucz główny:** Domyślnie zakładany jest klucz główny o nazwie `id`.

Możesz nadpisać te konwencje, definiując odpowiednie właściwości w modelu:

```php
class Post extends Model
{
    protected $table = 'blog_posts'; // Własna nazwa tabeli
    protected $primaryKey = 'post_id'; // Własny klucz główny
}
```

## Pobieranie Danych

### Pobieranie wszystkich modeli

```php
$posts = App\Models\Post::all();

foreach ($posts as $post) {
    echo $post->title;
}
```

### Pobieranie modelu po kluczu głównym

```php
$post = App\Models\Post::find(1);

echo $post->title;
```

### Konstruktor Zapytań (Query Builder)

Możesz budować bardziej złożone zapytania za pomocą dostępnych metod.

```php
// Pobierz wszystkie opublikowane posty, posortowane od najnowszych
$posts = App\Models\Post::where('status', 'published')
                       ->orderBy('created_at', 'desc')
                       ->get();

// Pobierz pierwszy pasujący rekord
$post = App\Models\Post::where('slug', 'hello-world')->first();
```

## Wstawianie, Aktualizacja i Usuwanie

### Tworzenie nowego rekordu

```php
$post = new App\Models\Post;
$post->title = 'Nowy post';
$post->content = 'Treść posta.';
$post->save(); // Zapisuje model do bazy danych
```

### Aktualizacja rekordu

```php
$post = App\Models\Post::find(1);
$post->title = 'Zaktualizowany tytuł';
$post->save();
```

### Masowe Przypisanie (Mass Assignment)

Możesz użyć metody `create`, aby utworzyć nowy rekord za jednym razem. Aby chronić się przed niechcianymi modyfikacjami, musisz zdefiniować właściwość `$fillable` w modelu, która określa, które pola można masowo przypisać.

```php
// W modelu App\Models\Post
class Post extends Model
{
    protected $fillable = ['title', 'content', 'status'];
}

// W kontrolerze lub `getServerSideProps`
$post = App\Models\Post::create([
    'title' => 'Nowy post z mass assignment',
    'content' => 'Treść...',
    'status' => 'draft',
]);
```

### Usuwanie rekordu

```php
$post = App\Models\Post::find(1);
$post->delete();

// Możesz też usunąć rekord po kluczu głównym
App\Models\Post::destroy(1);
```

## Relacje (Wprowadzenie)

ORM pozwala również na definiowanie relacji między modelami.

**Relacja Jeden-do-Wielu (`belongsTo`)**

Załóżmy, że każdy `Post` należy do jednego `User`.

```php
// W modelu App\Models\Post
public function user()
{
    return $this->belongsTo(App\Models\User::class);
}
```

Teraz możesz łatwo pobrać autora posta:

```php
$post = App\Models\Post::find(1);
echo $post->user->name; // Pobiera powiązany model User i wyświetla jego nazwę
```
