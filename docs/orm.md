# Podstawowy ORM

Model dziedziczy po ZoltaGaska\Database\Model. Przykład: App\Models\Post.

- `$fillable` określa pola zapisywalne; masowe przypisanie ignoruje pozostałe.
- `$columns` określa dodatkowe kolumny odczytywane z bazy.
- `hydrate()` służy wyłącznie zaufanym rekordom z bazy i zachowuje klucz główny.
- Dane od użytkownika przechodzą przez walidację aplikacji przed ORM.
- Nazwy kolumn/operatorów są kontrolowane; wartości zapytań są bindowane przez PDO.

```php
$post = Post::create(['title' => 'Tytuł', 'content' => 'Treść']);
$post = Post::find($post->id); // null, gdy nie istnieje
$post->fill(['title' => 'Nowy tytuł'])->save();
$posts = Post::where('title', 'LIKE', '%tytuł%')->orderBy('id', 'desc')->limit(10)->get();
$count = Post::count();
$post->delete();
Post::destroy(123);
```

Dostępne: all(), get(), first(), find(), count(), where(), orderBy(), limit(),
create(), save(), delete(), destroy(), toArray().
limit przyjmuje drugi argument offset; maksymalny limit wynosi 1000.

Brak relacji, eager loadingu, soft delete, automatycznego updated_at i ogólnego
systemu migracji wstecz. created_at w przykładzie ustawia baza.
