<?php

// public/index.php

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Post;

// ====================================================================
// WAŻNA INFORMACJA DLA UŻYTKOWNIKA
// ====================================================================
// Poniższy kod testuje działanie ORM. Aby zadziałał, musisz:
//
// 1. Skonfigurować połączenie z bazą danych w pliku .env.
//    Przykład dla MySQL:
//    DB_CONNECTION=mysql
//    DB_HOST=127.0.0.1
//    DB_PORT=3306
//    DB_DATABASE=zolta_gaska
//    DB_USERNAME=root
//    DB_PASSWORD=
//
// 2. Utworzyć w tej bazie danych tabelę `posts` za pomocą poniższej kwerendy SQL:
//
// CREATE TABLE `posts` (
//   `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
//   `title` varchar(255) DEFAULT NULL,
//   `content` text DEFAULT NULL,
//   `created_at` timestamp NULL DEFAULT current_timestamp(),
//   `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
//   PRIMARY KEY (`id`)
// );
//
// ====================================================================

try {
    echo "====== TEST ORM ŻÓŁTA-GĄSKA ======\n\n";

    // === 1. TWORZENIE (CREATE) ===
    echo "1. Tworzenie nowego posta...\n";
    $post1 = new Post();
    $post1->title = 'Mój pierwszy post';
    $post1->content = 'To jest treść mojego pierwszego posta.';
    $post1->save();
    echo "   -> Zapisano post o ID: " . $post1->id . "\n\n";

    echo "2. Tworzenie drugiego posta za pomocą metody create()...\n";
    $post2 = Post::create(['title' => 'Drugi post', 'content' => 'Treść drugiego posta.']);
    echo "   -> Zapisano post o ID: " . $post2->id . "\n\n";

    // === 2. ODCZYT (READ) ===
    echo "3. Odczytywanie posta o ID: " . $post1->id . "...\n";
    $foundPost1 = Post::find($post1->id);
    echo "   -> Znaleziono tytuł: " . $foundPost1->title . "\n\n";

    echo "4. Wyszukiwanie postów z tytułem 'Drugi post'...\n";
    $foundPosts = Post::where('title', '=', 'Drugi post')->get();
    echo "   -> Znaleziono " . count($foundPosts) . " post(ów).\n";
    if (count($foundPosts) > 0) {
        echo "   -> Tytuł pierwszego znalezionego: " . $foundPosts[0]->title . "\n\n";
    }

    // === 3. AKTUALIZACJA (UPDATE) ===
    echo "5. Aktualizacja tytułu posta o ID: " . $post1->id . "...\n";
    $postToUpdate = Post::find($post1->id);
    $postToUpdate->title = 'Zaktualizowany Tytuł Pierwszego Posta';
    $postToUpdate->save();
    $reloadedPost = Post::find($post1->id);
    echo "   -> Nowy tytuł: " . $reloadedPost->title . "\n\n";

    // === 4. USUWANIE (DELETE) ===
    echo "6. Usunięcie posta o ID: " . $post1->id . "...\n";
    $postToDelete = Post::find($post1->id);
    $postToDelete->delete();
    $deletedPost = Post::find($post1->id);
    if ($deletedPost === null) {
        echo "   -> Post został pomyślnie usunięty.\n\n";
    }

    echo "7. Usunięcie posta o ID: " . $post2->id . " za pomocą metody destroy()...\n";
    Post::destroy($post2->id);
    $deletedPost2 = Post::find($post2->id);
    if ($deletedPost2 === null) {
        echo "   -> Post został pomyślnie usunięty.\n\n";
    }

    echo "====== TEST ZAKOŃCZONY PRAWIDŁOWO ======\n";

} catch (\Exception $e) {
    echo "!!!!!! WYSTĄPIŁ BŁĄD !!!!!!\n\n";
    echo "Komunikat błędu: " . $e->getMessage() . "\n\n";
    echo "Sprawdź, czy poprawnie skonfigurowałeś plik .env i czy utworzyłeś tabelę `posts` w bazie danych.\n";
}