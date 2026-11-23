<?php
namespace ZoltaGaska\Strategy;

use ZoltaGaska\Http\{HttpException, Request, Response};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Security\{Identity, IdentityProvider};

final class StrategyModule implements Module
{
    public function __construct(private StrategyService $service, private IdentityProvider $auth) {}
    public function id(): string { return 'game-strategy'; }
    public function dependencies(): array { return ['jobs']; }

    public function register(Registry $registry): void
    {
        $registry->migrations(dirname(__DIR__, 2) . '/migrations');
        $policy = 'strategy.authenticated';
        $registry->policy($policy, static fn (?Identity $actor) => $actor !== null);
        $actor = fn (Request $r) => $this->auth->identity($r) ?? throw new HttpException(401, 'Sign in required.');
        $route = fn ($name, $method, $path, $handler) =>
            $registry->route('strategy.' . $name, $method, '/api/games', $path, $handler, [$policy]);
        $route('games', 'GET', '', fn ($r) => ['items' => $this->service->games($actor($r))]);
        $route('join', 'POST', '', function ($r) use ($actor) {
            $body = $r->json();
            Input::keys($body, ['seasonId', 'displayName']);
            return Response::json($this->service->join($actor($r), Input::id($body['seasonId']), Input::text($body['displayName'], 120)), 201);
        });
        $route('snapshot', 'GET', '/{gameId}', fn ($r, $p) => $this->service->snapshot($actor($r), $p['gameId']));
        $route('command', 'POST', '/{gameId}/commands', fn ($r, $p) => $this->service->dispatch($actor($r), $p['gameId'], $r->json()));
        $route('events', 'GET', '/{gameId}/events', fn ($r, $p) => $this->service->events($actor($r), $p['gameId'],
            Input::queryInt($r->query('cursor', '0')), Input::queryInt($r->query('limit', '50'))));
        $registry->route('strategy.leaderboard', 'GET', '/api/leaderboards', '/{seasonId}', function ($r, $p) use ($actor) {
            $cursor = $r->query('cursor');
            if ($cursor !== null && !is_string($cursor)) throw new HttpException(422, 'Invalid standings cursor.');
            return $this->service->leaderboard($actor($r), $p['seasonId'], $cursor, Input::queryInt($r->query('limit', '25')));
        }, [$policy]);
    }
}
