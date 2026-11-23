"""Exercise the strategy packages and the playable example as physical archive consumers."""
import argparse
import os
from pathlib import Path
import shutil
import sys
import tempfile

from package import ROOT, archives, build, install_php, npm_archives, read_json, run, write_json


EXAMPLE = ROOT / 'examples/strategy-game'
STRATEGY = '@yellow-goose/game-strategy'
BACKEND = {'core', 'security', 'database', 'jobs', 'game-strategy'}


def check_backend(tmp, releases, env, minimal):
    app = tmp / 'game-only'
    (app / 'tests').mkdir(parents=True)
    installed = install_php(app, {'name': 'test/game-only', 'require': {'yellow-goose/game-strategy': '0.2.0'}},
                            releases, env)
    assert installed == BACKEND, installed
    shutil.copyfile(ROOT / 'tests/d1.php', app / 'tests/d1.php')
    if minimal:
        # Child PHP processes in the concurrency test must inherit the same extension set.
        extension_dir = Path(run('php', '-r', 'echo ini_get("extension_dir");', cwd=app, env=env))
        extensions = [extension_dir / (name + '.so') for name in ('pdo', 'pdo_sqlite')]
        ini = app / 'php.ini'
        ini.write_text(''.join('extension=' + str(path) + '\n' for path in extensions if path.is_file()))
        env = dict(env, PHPRC=str(ini), PHP_INI_SCAN_DIR='')
        run('php', '-r', 'if (extension_loaded("v8js") || !extension_loaded("pdo_sqlite")) exit(1);',
            cwd=app, env=env)
    print(run('php', 'tests/d1.php', cwd=app, env=env).strip(), flush=True)
    print('PASS: independent PHP strategy consumer with core, database, security and jobs', flush=True)


def check_store(tmp, tarball, env):
    app = tmp / 'headless'
    app.mkdir()
    manifest = {'name': 'test-headless', 'private': True, 'type': 'module', 'dependencies': {STRATEGY: tarball}}
    write_json(app / 'package.json', manifest)
    run('npm', 'install', '--offline', '--ignore-scripts', '--no-audit', '--no-fund', cwd=app, env=env)
    dependencies = read_json(app / 'package-lock.json')['packages']
    assert set(dependencies) == {'', 'node_modules/' + STRATEGY}, dependencies.keys()
    installed = app / 'node_modules' / STRATEGY
    assert installed.is_dir() and not installed.is_symlink()
    run('node', '--input-type=module', '-e', '''
        import { createStrategyClient, createStrategyStore } from '@yellow-goose/game-strategy/store';
        const initial = { gameId: 'headless', cursor: 0, revision: 0 };
        const client = createStrategyClient(initial.gameId);
        const store = createStrategyStore(initial, client);
        if (store.getState().snapshot !== initial || store.getState().cursor !== 0) process.exit(1);
    ''', cwd=app, env=env)
    print('PASS: independent strategy store tarball imports with no other npm packages', flush=True)


def check_game(tmp, releases, tarballs, manifest, env):
    app = tmp / 'examples/strategy-game'
    shutil.copytree(EXAMPLE, app, ignore=shutil.ignore_patterns('vendor', 'node_modules', 'var', 'build', '.env',
                                                               '*.log', 'composer.lock', 'package-lock.json'))
    installed = install_php(app, read_json(app / 'composer.json'), releases, env)
    assert BACKEND | {'react-bridge', 'ssr-v8js'} <= installed, installed
    assert not installed.intersection({'scene-api', 'assets', 'cms', 'framework'}), installed
    build(app, manifest, tarballs, env)

    tests = tmp / 'tests'
    tests.mkdir()
    for name in ('d1-http.py', 'd1_support.py'):
        shutil.copyfile(ROOT / 'tests' / name, tests / name)
    # Restrict the runtime path after the build so CGI, SSR and the worker cannot find Node.
    runtime = tmp / 'runtime-bin'
    runtime.mkdir()
    for name in ('php', 'php-cgi'):
        binary = shutil.which(name)
        if not binary:
            raise RuntimeError('Missing runtime executable: ' + name)
        (runtime / name).symlink_to(binary)
    assert not (app / 'node_modules').exists()
    print(run(sys.executable, 'tests/d1-http.py', cwd=tmp, env=dict(env, PATH=str(runtime))).strip(), flush=True)
    print('PASS: detached strategy example, physical Composer/npm installs and PHP/V8Js CGI after removing Node modules',
          flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--php-only', action='store_true')
    parser.add_argument('--minimal-runtime', action='store_true')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='yg-d1-packages-') as directory:
        tmp = Path(directory)
        assert all(not (parent / 'node_modules').exists() for parent in tmp.parents)
        dist = tmp / 'dist'
        dist.mkdir()
        env = dict(os.environ, COMPOSER_DISABLE_NETWORK='1', COMPOSER_CACHE_DIR=str(tmp / 'composer-cache'),
                   GASKA_VITE_DEV_SERVER='', GASKA_SSR='1')
        env.pop('NODE_PATH', None)
        releases = archives(dist)
        check_backend(tmp, releases, env, args.minimal_runtime)
        if not args.php_only:
            manifest = read_json(EXAMPLE / 'package.json')
            packages = manifest['dependencies'] | manifest['devDependencies']
            names = [name.split('/')[1] for name in packages if name.startswith('@yellow-goose/')]
            tarballs = npm_archives(dist, env, names)
            check_store(tmp, tarballs[STRATEGY], env)
            check_game(tmp, releases, tarballs, manifest, env)


if __name__ == '__main__':
    main()
