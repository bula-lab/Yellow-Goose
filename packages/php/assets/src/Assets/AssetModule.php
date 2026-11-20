<?php
namespace ZoltaGaska\Assets;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Module\Module;
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Security\IdentityProvider;

final class AssetModule implements Module
{
    public function __construct(private AssetService $assets, private IdentityProvider $identities) {}
    public function id(): string { return 'assets'; }
    public function dependencies(): array { return ['jobs']; }

    public function register(Registry $registry): void
    {
        $registry->migrations(__DIR__ . '/../../migrations');
        $registry->command('assets:prune', function (): int { $this->assets->prune(); return 0; });
        $prefix = '/api/assets';
        $uploads = '/projects/{project}/uploads';
        $versions = '/projects/{project}/versions';
        $policy = ['assets.write'];
        $actor = fn (Request $r) => $this->identities->identity($r);
        $token = fn (Request $r) => $r->header('X-Upload-Token', '');
        $registry->route('assets.begin', 'POST', $prefix, $uploads,
            fn ($r, $p) => $this->assets->begin($p['project'], $actor($r), $r->json()), $policy);
        $registry->route('assets.progress', 'GET', $prefix, $uploads . '/{upload}',
            fn ($r, $p) => $this->assets->progress($p['project'], $p['upload'], $actor($r), $token($r)), $policy);
        $registry->route('assets.append', 'PUT', $prefix, $uploads . '/{upload}/{part}', function ($r, $p) use ($actor, $token) {
            if (strtolower(trim(explode(';', $r->header('Content-Type', ''))[0])) !== 'application/octet-stream') {
                throw new HttpException(415, 'Upload chunk requires application/octet-stream.');
            }
            $offset = $r->query('offset');
            if (!is_string($offset) || !preg_match('/^[0-9]{1,10}$/D', $offset)) throw new HttpException(400, 'Invalid chunk offset.');
            return $this->assets->append($p['project'], $p['upload'], $actor($r), $token($r), $p['part'], (int) $offset,
                $r->bytes(AssetService::CHUNK_BYTES));
        }, $policy);
        $registry->route('assets.complete', 'POST', $prefix, $uploads . '/{upload}/complete',
            fn ($r, $p) => $this->assets->complete($p['project'], $p['upload'], $actor($r), $token($r)), $policy);
        $registry->route('assets.versions', 'GET', $prefix, $versions, function ($r, $p) use ($actor) {
            $viewer = $actor($r) ?? throw new HttpException(401, 'Sign in required.');
            return ['items' => $this->assets->listVersions($p['project'], $viewer)];
        }, $policy);
        $registry->route('assets.descriptor', 'GET', $prefix, $versions . '/{version}', fn ($r, $p) =>
            $this->assets->descriptor($p['project'], $p['version'], $actor($r)) ?? throw new HttpException(404, 'Asset not found.'));
        $registry->route('assets.file', 'GET', $prefix, $versions . '/{version}/{kind}', fn ($r, $p) =>
            $this->assets->download($p['project'], $p['version'], $p['kind'], $actor($r), $r->query('exp'), $r->query('sig'), $r->header('Range')));
    }
}
