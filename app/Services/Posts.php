<?php
namespace App\Services;

use App\Models\Post;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;

final class Posts
{
    public static function find(string $id): Post
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) throw new HttpException(404, 'Nie znaleziono wpisu.');
        return Post::find($id) ?? throw new HttpException(404, 'Nie znaleziono wpisu.');
    }

    public static function listing(Request $request): array
    {
        $page = $request->query('page', '1');
        if (!is_string($page) || !preg_match('/^[1-9][0-9]{0,5}$/D', $page)) throw new HttpException(400, 'Nieprawidłowy numer strony.');
        $page = (int) $page;
        $total = Post::count();
        return [
            'posts' => array_map(fn (Post $post) => $post->toArray(), Post::orderBy('id', 'desc')->limit(10, ($page - 1) * 10)->get()),
            'pagination' => ['page' => $page, 'pages' => max(1, (int) ceil($total / 10)), 'total' => $total],
        ];
    }

    public static function validate(array $input, bool $partial = false): array
    {
        $errors = [];
        $data = [];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['title', 'content'], true)) $errors[$field] = 'Niedozwolone pole.';
        }
        foreach (['title' => 200, 'content' => 10000] as $field => $max) {
            if ($partial && !array_key_exists($field, $input)) continue;
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[$field] = 'To pole jest wymagane.';
            } elseif (mb_strlen(trim($value), 'UTF-8') > $max) {
                $errors[$field] = 'Maksymalna długość: ' . $max . ' znaków.';
            } else {
                $data[$field] = trim($value);
            }
        }
        if ($partial && !$input) $errors['_form'] = 'Przekaż co najmniej jedno pole.';
        if ($errors) throw new HttpException(422, 'Popraw błędy formularza.', $errors);
        return $data;
    }
}
