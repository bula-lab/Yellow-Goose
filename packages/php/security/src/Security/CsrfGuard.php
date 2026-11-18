<?php
namespace ZoltaGaska\Security;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;

final class CsrfGuard
{
    private \Closure $token;

    public function __construct(callable $token)
    {
        $this->token = \Closure::fromCallable($token);
    }

    public function validate(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
        $expected = ($this->token)();
        $actual = $request->header('X-CSRF-Token', '');
        if (!is_string($expected) || strlen($expected) < 32 || !hash_equals($expected, $actual)) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }
    }
}
