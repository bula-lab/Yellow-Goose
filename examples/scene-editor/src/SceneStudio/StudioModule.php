<?php
namespace SceneStudio;

use ZoltaGaska\Http\{HttpException, Request};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Scene\SceneService;
use ZoltaGaska\Security\{Session, SessionAuthenticator};

final class StudioModule implements Module
{
    public function __construct(private SceneService $scenes, private SessionAuthenticator $auth) {}
    public function id(): string { return 'studio'; }
    public function dependencies(): array { return ['scene-api']; }
    public function register(Registry $registry): void
    {
        $registry->policy('studio.session', static fn () => true);
        $registry->policy('assets.write', fn ($actor, $subject) => $this->scenes->mayEditProject($actor, $subject['params']['project'] ?? ''));
        $registry->route('studio.session', 'GET', '/api/auth', '/session', fn (Request $request) =>
            ['identity' => $this->auth->identity($request)?->id, 'csrfToken' => Session::csrfToken()], ['studio.session']);
        $registry->route('studio.login', 'POST', '/api/auth', '/login', function (Request $request) {
            $data = $request->json();
            if (array_diff(array_keys($data), ['login','password']) || !is_string($data['login'] ?? null) || !is_string($data['password'] ?? null)
                || strlen($data['login']) > 120 || strlen($data['password']) > 1024) throw new HttpException(422, 'Invalid login data.');
            return ['identity' => $this->auth->login($data['login'], $data['password'])->id, 'csrfToken' => Session::csrfToken()];
        }, ['studio.session']);
        $registry->route('studio.logout', 'POST', '/api/auth', '/logout', function (Request $request) {
            if ($request->json()) throw new HttpException(422, 'Unknown logout fields.');
            $this->auth->logout();
            return ['identity' => null, 'csrfToken' => Session::csrfToken()];
        }, ['studio.session']);
    }
}
