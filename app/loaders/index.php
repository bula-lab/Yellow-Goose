<?php
use App\Services\Posts;
use ZoltaGaska\Http\Request;
return static fn (Request $request): array => Posts::listing($request) + ['deleted' => $request->query('deleted') === '1'];
