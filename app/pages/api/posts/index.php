<?php
use App\Models\Post;
use App\Services\Posts;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Http\Response;

return static function (Request $request): Response {
    $request->requireMethod(['GET', 'HEAD', 'POST']);
    if ($request->method === 'POST') {
        $post = Post::create(Posts::validate($request->json()));
        return Response::json(['post' => $post->toArray()], 201, ['Location' => '/api/posts/' . $post->id]);
    }
    return Response::json(Posts::listing($request));
};
