<?php
use App\Services\Posts;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Http\Response;

return static function (Request $request, array $params): Response {
    $request->requireMethod(['GET', 'HEAD', 'PATCH', 'DELETE']);
    $post = Posts::find($params['id']);
    if ($request->method === 'DELETE') {
        $post->delete();
        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }
    if ($request->method === 'PATCH') {
        $post->fill(Posts::validate($request->json(), true))->save();
    }
    return Response::json(['post' => $post->toArray()]);
};
