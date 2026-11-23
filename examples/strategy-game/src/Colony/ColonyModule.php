<?php
namespace Colony;

use ZoltaGaska\Http\{HttpException, Request};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Security\{Session, SessionAuthenticator};

final class ColonyModule implements Module
{
    public function __construct(private SessionAuthenticator $auth) {}
    public function id(): string { return 'colony'; }
    public function dependencies(): array { return ['game-strategy']; }
    public function register(Registry $registry): void
    {
        $registry->policy('colony.session', static fn () => true);
        $registry->route('colony.session', 'GET', '/api/auth', '/session', fn (Request $request) =>
            ['identity' => $this->auth->identity($request)?->id, 'csrfToken' => Session::csrfToken()], ['colony.session']);
        $registry->route('colony.login', 'POST', '/api/auth', '/login', function (Request $request) {
            $data = $request->json();
            if (array_diff(array_keys($data), ['login','password']) || !is_string($data['login'] ?? null) || !is_string($data['password'] ?? null)
                || strlen($data['login']) > 120 || strlen($data['password']) > 1024) throw new HttpException(422, 'Invalid login data.');
            return ['identity' => $this->auth->login($data['login'], $data['password'])->id, 'csrfToken' => Session::csrfToken()];
        }, ['colony.session']);
        $registry->route('colony.logout', 'POST', '/api/auth', '/logout', function (Request $request) {
            if ($request->json()) throw new HttpException(422, 'Unknown logout fields.');
            $this->auth->logout();
            return ['identity' => null, 'csrfToken' => Session::csrfToken()];
        }, ['colony.session']);
    }
}
