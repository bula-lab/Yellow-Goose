"""Install B1/B2 release archives outside the repository and exercise their consumers."""
from pathlib import Path
import argparse
import importlib.util
import json
import os
import shutil
import subprocess
import tempfile

from package import ROOT, archives, run


def install_php(app, requirements, releases, env):
    manifest = {"name": "test/b1-b2-consumer", "require": requirements,
                "repositories": [{"type": "package", "package": releases}, {"packagist.org": False}]}
    (app / "composer.json").write_text(json.dumps(manifest))
    run("composer", "install", "--no-scripts", "--no-interaction", cwd=app, env=env)
    for package in (app / "vendor/yellow-goose").iterdir():
        assert not package.is_symlink(), package


def php_runtime(minimal, extensions, env):
    if not minimal:
        return ["php"]
    location = Path(run("php", "-r", 'echo ini_get("extension_dir");', cwd=ROOT, env=env))
    command = ["php", "-n"]
    for extension in extensions:
        module = location / (extension + ".so")
        if module.exists():
            command.extend(["-d", "extension=" + str(module)])
    return command


def php_consumers(temporary, releases, env, minimal):
    cms = temporary / "cms-only"
    cms.mkdir()
    install_php(cms, {"yellow-goose/cms": "0.2.0"}, releases, env)
    assert not (cms / "vendor/yellow-goose/assets").exists()
    assert not (cms / "node_modules").exists()
    (cms / "app-migrations").mkdir()
    (cms / "check.php").write_text(r'''<?php
        require __DIR__ . '/vendor/autoload.php';
        if (class_exists('ZoltaGaska\Assets\AssetService') || class_exists('ZoltaGaska\Frontend\Assets')
            || class_exists('Symfony\Component\Validator\Validation')) throw new Exception('Hidden dependency');
        if (getenv('YG_MINIMAL_PROFILE') === '1' && extension_loaded('gd')) throw new Exception('CMS loaded GD');
        $db = new PDO('sqlite:' . __DIR__ . '/db.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new ZoltaGaska\Database\Migrator($db, __DIR__ . '/app-migrations',
            ['cms' => __DIR__ . '/vendor/yellow-goose/cms/migrations']))->migrate();
        $identity = new ZoltaGaska\Security\Identity('editor');
        $catalog = new ZoltaGaska\Cms\Catalog($db, fn ($actor, $project) => $actor?->id === 'editor' && $project === 'example');
        $data = ['id' => 'house', 'title' => 'Published title', 'description' => '<script>text only</script>',
            'assetVersionId' => null, 'materialIds' => []];
        $catalog->saveProduct('example', $identity, $data, false);
        $catalog->setPublication('example', $identity, ['id' => 'house', 'expectedRevision' => 1], true);
        $catalog->saveProduct('example', $identity, array_replace($data, ['title' => 'Private draft', 'expectedRevision' => 2]), true);
        if ($catalog->published('example', 'house', null)['title'] !== 'Published title') throw new Exception('Draft exposed');
        echo 'cms-consumer-ok';
    ''')
    assert run(*php_runtime(minimal, ['pdo', 'pdo_sqlite'], env), "check.php", cwd=cms, env=env) == "cms-consumer-ok"
    print("PASS: physical CMS-only Composer archives, published snapshot, no assets/React/Symfony" + ("/GD" if minimal else ""))

    assets = temporary / "assets-only"
    assets.mkdir()
    install_php(assets, {"yellow-goose/assets": "0.2.0"}, releases, env)
    for name in ["box.glb", "poster.png"]:
        shutil.copy2(ROOT / "tests/fixtures/b1" / name, assets / name)
    (assets / "check.php").write_text(r'''<?php
        require __DIR__ . '/vendor/autoload.php';
        if (class_exists('ZoltaGaska\Cms\Catalog') || class_exists('ZoltaGaska\Frontend\Assets')) throw new Exception('Hidden dependency');
        $db = new PDO('sqlite:' . __DIR__ . '/db.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['jobs/001_jobs.php', 'assets/001_assets.php'] as $file) {
            [$owner, $migration] = explode('/', $file);
            (require __DIR__ . '/vendor/yellow-goose/' . $owner . '/migrations/' . $migration)($db);
        }
        $queue = new ZoltaGaska\Jobs\Queue($db);
        $identity = new ZoltaGaska\Security\Identity('editor');
        $service = new ZoltaGaska\Assets\AssetService($queue, new ZoltaGaska\Assets\LocalStorage(__DIR__ . '/private'),
            str_repeat('test-signing-key-', 3), fn ($actor, $project) => $actor->id === 'editor' && $project === 'example',
            fn ($actor, $project, $version) => $actor?->id === 'editor' && $project === 'example');
        $files = ['model' => file_get_contents(__DIR__ . '/box.glb'), 'poster' => file_get_contents(__DIR__ . '/poster.png')];
        $meta = [];
        foreach ($files as $kind => $bytes) { $meta[$kind . 'Bytes'] = strlen($bytes); $meta[$kind . 'Checksum'] = hash('sha256', $bytes); }
        $session = $service->begin('example', $identity, $meta);
        foreach ($files as $kind => $bytes) {
            for ($offset = 0; $offset < strlen($bytes); $offset += 262144) {
                $service->append('example', $session['uploadId'], $identity, $session['token'], $kind, $offset, substr($bytes, $offset, 262144));
            }
        }
        $queued = $service->complete('example', $session['uploadId'], $identity, $session['token']);
        if ($queued['status'] !== 'queued') throw new Exception('Finalization failed');
        $queue->work(['assets.prepare' => [$service, 'process']], 1, ['assets.prepare' => [$service, 'failed']]);
        $ready = $service->descriptor('example', $queued['assetVersionId'], $identity);
        if ($ready['status'] !== 'ready' || $ready['checksum'] !== $meta['modelChecksum']) throw new Exception('Worker failed');
        echo 'assets-consumer-ok';
    ''')
    command = php_runtime(minimal, ['pdo', 'pdo_sqlite', 'gd'], env)
    assert run(*command, "-d", "disable_functions=proc_open,popen,exec,shell_exec,system,passthru", "check.php", cwd=assets, env=env) == "assets-consumer-ok"
    print("PASS: physical Assets/Jobs archives, frozen upload + GD/GLB worker without child-process execution or CMS/React")


def npm_packages(dist, env):
    result = {}
    for name in ["react", "vite", "ui", "scene", "babylon"]:
        packed = json.loads(run("npm", "pack", "--json", "--ignore-scripts", "--pack-destination", str(dist),
                                cwd=ROOT / "packages/js" / name, env=env))
        result[name] = "file:" + str(dist / packed[0]["filename"])
    return result


def light_ui_consumer(temporary, releases, npm, env):
    app = temporary / 'ui-only'
    (app / 'app/pages').mkdir(parents=True)
    (app / 'public').mkdir()
    install_php(app, {'yellow-goose/react-bridge': '0.2.0', 'yellow-goose/ssr-v8js': '0.2.0'}, releases, env)
    (app / 'package.json').write_text(json.dumps({
        'name': 'test-ui-only', 'private': True, 'type': 'module',
        'scripts': {'build': 'vite build && vite build --ssr app/server.jsx'},
        'dependencies': {'react': '19.3.1', 'react-dom': '19.3.1', '@yellow-goose/react': npm['react'], '@yellow-goose/ui': npm['ui']},
        'devDependencies': {'@yellow-goose/vite': npm['vite'], 'vite': '8.3.8', '@vitejs/plugin-react': '6.3.0'},
    }))
    (app / 'vite.config.js').write_text("import { fileURLToPath } from 'node:url'; import { frameworkConfig } from '@yellow-goose/vite';\nexport default frameworkConfig(fileURLToPath(new URL('.', import.meta.url)));\n")
    (app / 'app/client.jsx').write_text("import { startClient } from '@yellow-goose/react/client';\nstartClient(import.meta.glob('./pages/*.jsx'));\n")
    (app / 'app/server.jsx').write_text("import { renderPage } from '@yellow-goose/react/server';\nconst pages=import.meta.glob('./pages/*.jsx',{eager:true});\nexport function render(page,props){return renderPage(pages,page,props);}\n")
    (app / 'app/pages/index.jsx').write_text("""import {Form} from '@yellow-goose/ui';
        export default function Page(){return <main><h1>UI without a scene</h1><Form
            fields={{heightMm:{type:'integer',label:'Height',unit:{label:'cm',factor:10},required:true,choices:[2000,2130,2400]}}}
            initialValues={{heightMm:2130}} onSubmit={async()=>({})}/></main>;}""")
    run('npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund', '--fetch-retries=0', cwd=app, env=env)
    assert not (app / 'node_modules/@babylonjs').exists()
    assert not (app / 'node_modules/@yellow-goose/scene').exists()
    assert not (app / 'node_modules/@yellow-goose/ui').is_symlink()
    run('npm', 'run', 'build', cwd=app, env=env)
    (app / 'check.php').write_text(r'''<?php
        require __DIR__ . '/vendor/autoload.php';
        $renderer = new ZoltaGaska\Frontend\V8JsRenderer(__DIR__ . '/build/ssr/server.js', allowLegacyBundle: false);
        echo $renderer->render('index.jsx', []);
    ''')
    html = run('php', 'check.php', cwd=app, env=env)
    assert '<h1>UI without a scene</h1>' in html and 'value="213"' in html and '>213</option>' in html
    print('PASS: physical UI/React/Vite tarballs, V8Js unit form213cm, no scene/Babylon installation')


def frontend_consumers(temporary, releases, npm, env):
    app = temporary / "garage"
    shutil.copytree(ROOT / "examples/garage-cms", app,
                    ignore=shutil.ignore_patterns('vendor', 'node_modules', 'var', 'build', '.env', '*.log'))
    install_php(app, json.loads((ROOT / "examples/garage-cms/composer.json").read_text())["require"], releases, env)
    # The application keeps its own namespace while all libraries come from release archives.
    manifest = json.loads((app / "composer.json").read_text())
    manifest['autoload'] = {'psr-4': {'Garage\\': 'src/Garage/'}}
    (app / "composer.json").write_text(json.dumps(manifest))
    run("composer", "dump-autoload", "--no-scripts", cwd=app, env=env)
    package = json.loads((app / "package.json").read_text())
    for kind in ['dependencies', 'devDependencies']:
        for name in list(package.get(kind, {})):
            if name.startswith('@yellow-goose/'):
                package[kind][name] = npm[name.split('/')[1]]
    (app / 'package.json').write_text(json.dumps(package))
    run('npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund', '--fetch-retries=0', cwd=app, env=env)
    for name in ['react', 'ui', 'scene', 'babylon', 'vite']:
        assert not (app / 'node_modules/@yellow-goose' / name).is_symlink()
    run('npm', 'run', 'build', cwd=app, env=env)
    ssr = (app / 'build/ssr/server.js').read_text()
    assert 'ThinEngine' not in ssr and 'GLTFFileLoader' not in ssr and 'WebGLRenderingContext' not in ssr
    spec = importlib.util.spec_from_file_location('physical_garage_cgi', ROOT / 'tests/b2-http.py')
    suite = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(suite)
    import b2_support
    original = b2_support.APP
    try:
        b2_support.APP = app
        suite.APP = app
        suite.run(True)
    finally:
        b2_support.APP = original
    print('PASS: detached reference application, all five npm tarballs + Composer archives, real CGI/SSR/upload/publication, no Babylon in SSR')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--minimal-runtime', action='store_true')
    parser.add_argument('--php-only', action='store_true')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='yg-b1-b2-packages-') as tmp:
        temporary = Path(tmp)
        dist = temporary / 'dist'
        dist.mkdir()
        releases = archives(dist)
        env = dict(os.environ, COMPOSER_CACHE_DIR=str(temporary / 'composer-cache'),
                   YG_MINIMAL_PROFILE='1' if args.minimal_runtime else '0')
        env.pop('NODE_PATH', None)
        php_consumers(temporary, releases, env, args.minimal_runtime)
        if not args.php_only:
            npm = npm_packages(dist, env)
            light_ui_consumer(temporary, releases, npm, env)
            frontend_consumers(temporary, releases, npm, env)


if __name__ == '__main__':
    main()
