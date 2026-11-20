"""Install the scene API and both editor profiles outside the source checkout."""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import sys
import tempfile

from package import ROOT, archives, run


EXAMPLE = ROOT / 'examples/scene-editor'
NO_CHILD = ['-d', 'disable_functions=proc_open,popen,exec,shell_exec,system,passthru']


def read_json(path):
    return json.loads(path.read_text())


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


def install_php(app, manifest, releases, env):
    manifest['repositories'] = [{'type': 'package', 'package': releases}, {'packagist.org': False}]
    write_json(app / 'composer.json', manifest)
    run('composer', 'install', '--no-interaction', '--no-scripts', cwd=app, env=env)
    installed = app / 'vendor/yellow-goose'
    assert all(item.is_dir() and not item.is_symlink() for item in installed.iterdir())
    return {item.name for item in installed.iterdir()}


def scene_only(tmp, releases, env, minimal):
    app = tmp / 'scene-only'
    (app / 'tests').mkdir(parents=True)
    installed = install_php(app, {'name': 'test/scene-only', 'require': {'yellow-goose/scene-api': '0.2.0'}}, releases, env)
    assert installed == {'core', 'security', 'database', 'scene-api'}, installed
    shutil.copyfile(ROOT / 'tests/c1.php', app / 'tests/c1.php')
    php = ['php']
    if minimal:
        directory = Path(run('php', '-r', 'echo ini_get("extension_dir");', cwd=app, env=env))
        php.append('-n')
        for name in ['pdo', 'pdo_sqlite']:
            if (directory / (name + '.so')).is_file():
                php += ['-d', 'extension=' + str(directory / (name + '.so'))]
        run(*php, '-r', 'if (extension_loaded("gd") || extension_loaded("v8js")) exit(1);', cwd=app, env=env)
    print(run(*php, 'tests/c1.php', cwd=app, env=env).strip(), flush=True)
    print('PASS: detached scene-api installation with only core, security and database', flush=True)


def npm_archives(dist, env):
    packages = {}
    for name in ['react', 'scene', 'ui', 'editor', 'babylon', 'vite']:
        result = json.loads(run('npm', 'pack', '--json', '--ignore-scripts', '--pack-destination', str(dist),
                                cwd=ROOT / 'packages/js' / name, env=env))[0]
        packages[result['name']] = 'file:' + str(dist / result['filename'])
    return packages


def build(app, package, tarballs, env):
    used = set()
    for kind in ['dependencies', 'devDependencies']:
        for name in package[kind]:
            if name.startswith('@yellow-goose/'):
                package[kind][name] = tarballs[name]
                used.add(name)
    write_json(app / 'package.json', package)
    # Pin third-party registry dependencies to the release build's versions and integrities.
    # npm resolves the local libraries from the tarballs and prunes unused third-party dependencies.
    source = read_json(ROOT / 'package-lock.json')['packages']
    registry = {key: value for key, value in source.items() if value.get('resolved', '').startswith('https://')}
    root = {field: package[field] for field in ['name', 'version', 'dependencies', 'devDependencies']}
    write_json(app / 'package-lock.json', {'name': package['name'], 'version': package['version'], 'lockfileVersion': 3,
                                          'requires': True, 'packages': {'': root, **registry}})
    run('npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund', '--prefer-offline', '--fetch-retries=0', cwd=app, env=env)
    modules = app / 'node_modules'
    assert not modules.is_symlink() and all(not (parent / 'node_modules').exists() for parent in app.parents)
    installed = {item.name for item in (modules / '@yellow-goose').iterdir()}
    assert installed == {name.split('/')[1] for name in used}
    for name in used:
        assert (modules / name / 'package.json').is_file() and not (modules / name).is_symlink()
        locked = read_json(app / 'package-lock.json')['packages']['node_modules/' + name]
        assert locked['resolved'].endswith('.tgz') and 'integrity' in locked and not locked.get('link')
    assert (modules / '@babylonjs/core').is_dir() == ('@yellow-goose/babylon' in used)
    run('npm', 'run', 'build', cwd=app, env=env)
    ssr = (app / 'build/ssr/server.js').read_text()
    assert all(token not in ssr for token in ['ThinEngine', 'WebGLRenderingContext', 'GLTFFileLoader']), 'Babylon in SSR'
    shutil.rmtree(modules)
    return read_json(app / 'public/build/.vite/manifest.json')


def light_editor(tmp, releases, tarballs, env):
    app = tmp / 'light-editor'
    (app / 'app/pages').mkdir(parents=True)
    (app / 'public').mkdir()
    install_php(app, {'name': 'test/light-editor', 'require': {'yellow-goose/ssr-v8js': '0.2.0'}}, releases, env)
    for source in ['vite.config.js', 'app/server.jsx', 'app/client.jsx', 'app/styles.css']:
        shutil.copyfile(EXAMPLE / source, app / source)
    (app / 'app/pages/index.jsx').write_text('''import {SceneEditor} from '@yellow-goose/editor';
import {WorkspaceShell} from '@yellow-goose/react/workspace';
function ListViewport({document}) { return <p>Objects in view: {document.entities.length}</p>; }
export default function Page({document}) {
    return <WorkspaceShell><SceneEditor initialDocument={document} Viewport={ListViewport} /></WorkspaceShell>;
}
''')
    package = read_json(EXAMPLE / 'package.json')
    package['name'] = 'test-light-editor'
    del package['dependencies']['@yellow-goose/babylon']
    build(app, package, tarballs, env)
    html = run('php', *NO_CHILD, '-r', r'''
        require 'vendor/autoload.php';
        $renderer = new ZoltaGaska\Frontend\V8JsRenderer('build/ssr/server.js', allowLegacyBundle: false);
        echo $renderer->render('index.jsx', ['document' => [
            'schemaVersion' => 1, 'projectId' => 'house', 'sceneId' => 'light', 'name' => 'Light scene', 'revision' => 0,
            'coordinateSystem' => ['handedness' => 'right', 'up' => 'Y', 'unit' => 'm'], 'entities' => [],
        ]]);
    ''', cwd=app, env=env)
    assert 'data-yg-scene-editor=' in html and 'Objects in view:' in html and 'data-revision="0"' in html
    print('PASS: physical editor tarball with an application viewport, no Babylon installed, native PHP/V8Js SSR', flush=True)


def full_editor(tmp, releases, tarballs, env):
    app = tmp / 'examples/scene-editor'
    app.parent.mkdir()
    shutil.copytree(EXAMPLE, app, ignore=shutil.ignore_patterns('vendor', 'node_modules', 'var', 'build', '.env',
                                                               'composer.lock', 'package-lock.json', '*.log'))
    installed = install_php(app, read_json(app / 'composer.json'), releases, env)
    assert 'scene-api' in installed and not installed.intersection({'cms', 'framework'})
    manifest = build(app, read_json(app / 'package.json'), tarballs, env)
    scene_page = manifest['app/pages/projects/[projectId]/scenes/[sceneId].jsx']
    assert any('multiEngine' in key for key in scene_page['dynamicImports'])
    assert not any('multiEngine' in key for key in scene_page.get('imports', []))

    env = dict(env, SCENE_DATA_DIR=str(app / 'var'), SCENE_EDITOR_PASSWORD=secrets.token_hex(16),
               SCENE_SIGNING_KEY=secrets.token_hex(32))
    env.pop('GASKA_SESSION_PATH', None)
    migrated = run('php', *NO_CHILD, 'console.php', 'migrate', cwd=app, env=env)
    assert 'scene-api:001_scenes.php' in migrated
    run('php', *NO_CHILD, 'console.php', 'seed', cwd=app, env=env)
    result = run('php', *NO_CHILD, '-r', r'''
        $site = require 'bootstrap.php';
        $actor = $site->auth->login('editor', getenv('SCENE_EDITOR_PASSWORD'));
        $site->scenes->createProject($actor, 'Archive project', 'house');
        $site->scenes->createScene($actor, 'house', 'Archive scene', 'garden');
        $site->scenes->dispatch($actor, 'house', 'garden', [
            'commandId' => 'initial', 'type' => 'entity.add', 'entityId' => 'garage', 'expectedRevision' => 0,
            'payload' => ['type' => 'garage', 'name' => 'Garage <archived>', 'properties' => ['heightMm' => 2130]],
        ]);
        $response = $site->handle(new ZoltaGaska\Http\Request('/projects/house/scenes/garden', 'GET'));
        if ($response->status !== 200) throw new RuntimeException('SSR HTTP status ' . $response->status);
        echo $response->body;
    ''', cwd=app, env=env)
    assert all(fragment in result for fragment in [
        'data-render-mode="ssr"', 'data-yg-scene-editor=', 'data-revision="1"', 'Garage &lt;archived&gt;',
        'data-yg-scene-viewport="poster"', '<h1>Archive scene</h1>',
    ])
    tests = tmp / 'tests'
    fixtures = tests / 'fixtures/b1'
    fixtures.mkdir(parents=True)
    for script in ['c1-http.py', 'c1_support.py']:
        shutil.copyfile(ROOT / 'tests' / script, tests / script)
    for name in ['box.glb', 'poster.png']:
        shutil.copyfile(ROOT / 'tests/fixtures/b1' / name, fixtures / name)
    print(run(sys.executable, 'tests/c1-http.py', cwd=tmp, env=env).strip(), flush=True)
    print('PASS: detached reference studio, six npm tarballs, dynamic Babylon client and PHP/V8Js SSR', flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--php-only', action='store_true')
    parser.add_argument('--minimal-runtime', action='store_true')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='yg-c1-packages-') as directory:
        tmp = Path(directory)
        dist = tmp / 'dist'
        dist.mkdir()
        env = dict(os.environ, COMPOSER_CACHE_DIR=str(tmp / 'composer-cache'), COMPOSER_DISABLE_NETWORK='1',
                   GASKA_VITE_DEV_SERVER='', GASKA_SSR='1')
        env.pop('NODE_PATH', None)
        releases = archives(dist)
        scene_only(tmp, releases, env, args.minimal_runtime)
        if not args.php_only:
            tarballs = npm_archives(dist, env)
            light_editor(tmp, releases, tarballs, env)
            full_editor(tmp, releases, tarballs, env)


if __name__ == '__main__':
    main()
