<?php
use App\Services\Posts;
use ZoltaGaska\Http\Request;
return static fn (Request $request, array $params): array => ['post' => Posts::find($params['id'])->toArray(), 'saved' => $request->query('saved') === '1'];
