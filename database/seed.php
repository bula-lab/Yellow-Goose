<?php
use App\Models\Post;
use ZoltaGaska\Database\Connection;

return static function (): int {
    $pdo = Connection::getInstance();
    $pdo->beginTransaction();
    try {
        if (Post::count() !== 0) {
            $pdo->commit();
            return 0;
        }
        Post::create(['title' => 'Witaj w Żółtej Gąsce', 'content' => "To Twój pierwszy wpis. Możesz go edytować lub usunąć.\n\nDodaj własną notatkę, żeby sprawdzić formularz i zapis danych."]);
        Post::create(['title' => 'Miejsce na kolejny pomysł', 'content' => 'Wszystkie wpisy są zapisywane w bazie i pozostają dostępne po ponownym uruchomieniu aplikacji.']);
        $pdo->commit();
        return 2;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
};
