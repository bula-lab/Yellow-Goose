<?php

use ZoltaGaska\Http\Request;
use ZoltaGaska\Http\Response;

return static function (Request $request): Response {
    if (!in_array($request->method, ['GET', 'HEAD'], true)) {
        return Response::json(['error' => 'Method Not Allowed'], 405, ['Allow' => 'GET, HEAD']);
    }
    return Response::json(['time' => (new DateTimeImmutable())->format('H:i:s')]);
};
