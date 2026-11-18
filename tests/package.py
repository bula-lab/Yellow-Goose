"""Install release archives in physical consumers, without inherited node_modules."""
from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile
import io
import tarfile
import socket
import time
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[1]


def browser_check(app, env):
    from playwright.sync_api import sync_playwright, expect
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    (app / 'public/index.php').write_text('''<?php
        require __DIR__ . '/../vendor/autoload.php';
        ZoltaGaska\\Runtime::boot(dirname(__DIR__));
        ZoltaGaska\\Runtime::application(dirname(__DIR__))->handle(ZoltaGaska\\Http\\Request::capture())->send();
    ''')
    with (app / 'http.log').open('w') as log:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public'],
                                  cwd=app, env=env, stdout=log, stderr=log)
        try:
            url = f'http://127.0.0.1:{port}/'
            for _ in range(100):
                try:
                    urllib.request.urlopen(url, timeout=1).close()
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise RuntimeError('Consumer HTTP server failed: ' + (app / 'http.log').read_text())
            with sync_playwright() as p:
                options = {'headless': True}
                if os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE'):
                    options['executable_path'] = os.environ['PLAYWRIGHT_CHROMIUM_EXECUTABLE']
                browser = p.chromium.launch(**options)
                try:
                    page = browser.new_page()
                    errors = []
                    page.on('pageerror', lambda error: errors.append(str(error)))
                    page.on('console', lambda message: errors.append(message.text)
                            if message.type == 'error' and 'Hydration failed' in message.text else None)
                    page.goto(url)
                    expect(page.locator('#app')).to_have_attribute('data-client-ready', 'true')
                    assert page.get_by_label('Example').get_attribute('id').startswith('_gaska-')
                    page.get_by_role('button', name='Clicks: 0').click()
                    expect(page.get_by_role('button', name='Clicks: 1')).to_be_visible()
                    assert not errors, errors
                finally:
                    browser.close()
        finally:
            server.terminate()
            server.wait(timeout=10)


def facade_modules_check(app, env):
    (app / 'config').mkdir()
    (app / 'database/migrations').mkdir(parents=True)
    (app / 'database/module').mkdir()
    (app / 'config/database.php').write_text('''<?php return ['default' => 'sqlite', 'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => dirname(__DIR__) . '/database/test.sqlite']]];''')
    (app / 'database/module/001.php').write_text('''<?php return function (PDO $pdo) {
        $pdo->exec('CREATE TABLE module_marker (id INTEGER)');
    };''')
    (app / 'config/modules.php').write_text('''<?php return [new class implements ZoltaGaska\\Module\\Module {
        public function id(): string { return 'consumer'; }
        public function dependencies(): array { return []; }
        public function register(ZoltaGaska\\Module\\Registry $registry): void {
            $registry->migrations(dirname(__DIR__) . '/database/module');
            $registry->route('module.health', 'GET', '/api/module', '/health', fn () => ['module' => true]);
            $registry->command('module:check', function () { echo 'module enabled'; return 0; });
        }
    }];''')
    (app / 'modules-check.php').write_text('''<?php require __DIR__ . '/vendor/autoload.php';
        ZoltaGaska\\Runtime::boot(__DIR__);
        $app = ZoltaGaska\\Runtime::application(__DIR__);
        $pdo = ZoltaGaska\\Database\\Connection::getInstance();
        if ((int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn() !== 0) throw new Exception('Boot executed DDL');
        echo $app->handle(new ZoltaGaska\\Http\\Request('/api/module/health', 'GET'))->body;
    ''')
    assert run('php', 'modules-check.php', cwd=app, env=env) == '{"module":true}'
    assert run('php', 'vendor/bin/gaska', 'module:check', cwd=app, env=env) == 'module enabled'
    assert 'module:check' in run('php', 'vendor/bin/gaska', 'help', cwd=app, env=env)
    assert 'consumer:001.php' in run('php', 'vendor/bin/gaska', 'db:migrate', cwd=app, env=env)
    assert 'Baza jest aktualna' in run('php', 'vendor/bin/gaska', 'db:migrate', cwd=app, env=env)
    print('PASS: facade config/modules.php, module API/CLI, explicit migrations and idempotent deployment')


def run(*args, cwd, env):
    result = subprocess.run(args, cwd=cwd, env=env, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(" ".join(args) + "\n" + result.stdout + result.stderr)
    return result.stdout


def archives(directory):
    packages = [ROOT / "packages/framework", *sorted((ROOT / "packages/php").iterdir())]
    releases = []
    for package in packages:
        manifest = json.loads((package / "composer.json").read_text())
        manifest["version"] = "0.2.0"
        filename = directory / (manifest["name"].replace("/", "-") + ".tar.gz")
        with tarfile.open(filename, "w:gz") as archive:
            for file in package.rglob("*"):
                if file.is_file() and file.name != "composer.json":
                    archive.add(file, arcname=str(file.relative_to(package)), recursive=False)
            data = json.dumps(manifest).encode()
            info = tarfile.TarInfo("composer.json")
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
        manifest["dist"] = {"type": "tar", "url": filename.as_uri()}
        releases.append(manifest)
    return releases


def main():
    with tempfile.TemporaryDirectory(prefix="gaska-consumers-") as tmp:
        temporary = Path(tmp)
        dist = temporary / "dist"
        dist.mkdir()
        releases = archives(dist)
        env = dict(os.environ, GASKA_VITE_DEV_SERVER="", GASKA_SESSION_PATH=tmp, GASKA_SSR="1",
                   COMPOSER_CACHE_DIR=str(temporary / "composer-cache"))
        env.pop("NODE_PATH", None)
        npm_archives = {}
        for name in ["react", "vite"]:
            output = run("npm", "pack", "--json", "--ignore-scripts", "--pack-destination", str(dist),
                         cwd=ROOT / "packages/js" / name, env=env)
            npm_archives[name] = str(dist / json.loads(output)[0]["filename"])
        for mode in ["legacy", "npm"]:
            app = temporary / mode
            shutil.copytree(ROOT / "tests/fixtures/consumer", app)
            manifest = {
                "name": "test/" + mode + "-consumer",
                "require": {"yellow-goose/framework": "0.2.0"},
                "repositories": [{"type": "package", "package": releases}, {"packagist.org": False}],
            }
            (app / "composer.json").write_text(json.dumps(manifest))
            source = json.loads((ROOT / "tests/fixtures/legacy/package.json").read_text())
            package = {
                "name": mode + "-consumer", "private": True, "type": "module",
                "scripts": {key: source["scripts"][key] for key in ["build", "build:client", "build:ssr"]},
                "dependencies": source["dependencies"], "devDependencies": source["devDependencies"],
            }
            if mode == "npm":
                package["dependencies"]["@yellow-goose/react"] = "file:" + npm_archives["react"]
                package["devDependencies"]["@yellow-goose/vite"] = "file:" + npm_archives["vite"]
            (app / "package.json").write_text(json.dumps(package))
            for name in ["client.jsx", "server.jsx", "vite.config.js"]:
                text = (ROOT / "tests/fixtures/legacy" / name).read_text()
                if mode == "npm":
                    text = text.replace("../vendor/yellow-goose/framework/resources/client", "@yellow-goose/react/client")
                    text = text.replace("../vendor/yellow-goose/framework/resources/server", "@yellow-goose/react/server")
                    text = text.replace("./vendor/yellow-goose/framework/resources/vite.mjs", "@yellow-goose/vite")
                (app / (name if name == "vite.config.js" else "app/" + name)).write_text(text)
            (app / "app/styles.css").write_text("body { font-family: sans-serif; }\n")
            (app / "public").mkdir()
            run("composer", "install", "--no-interaction", "--no-scripts", cwd=app, env=env)
            assert not (app / "vendor/yellow-goose/framework").is_symlink()
            assert "Żółta-Gąska CLI" in run("php", "vendor/bin/gaska", "help", cwd=app, env=env)
            run("npm", "install", "--ignore-scripts", "--no-audit", "--no-fund", cwd=app, env=env)
            assert not (app / "node_modules").is_symlink()
            assert not (app / "node_modules/@yellow-goose/react").is_symlink()
            if mode == "legacy":
                assert not (app / "node_modules/@yellow-goose").exists()
            run("npm", "run", "build", cwd=app, env=env)
            html = run("php", "check.php", cwd=app, env=env)
            assert '<h1>Independent app &lt;safe&gt;</h1>' in html, html
            assert 'data-render-mode="ssr"' in html and 'id="_gaska-' in html
            print("PASS:", mode, "Composer tar + physical npm install, CLI, client/SSR build, loader/API/useId")
            if os.environ.get('GASKA_PACKAGE_BROWSER') == '1':
                browser_check(app, env)
                print('PASS:', mode, 'Chromium hydration, useId and interactive state')
            if mode == 'legacy':
                facade_modules_check(app, env)

        for name, requirements, code in [
            ("core", {"yellow-goose/core": "0.2.0"}, r'''
                if (class_exists('PDO') || class_exists('ZoltaGaska\\Database\\Connection') || class_exists('ZoltaGaska\\Frontend\\Assets')) throw new Exception('Heavy dependency');
                $router = new ZoltaGaska\Routing\Router(__DIR__ . '/pages');
                $route = $router->dispatch(new ZoltaGaska\Http\Request('/api/health', 'GET'));
                $handler = require $route->filePath;
                $module = new class($handler) implements ZoltaGaska\Module\Module {
                    public function __construct(private $handler) {}
                    public function id(): string { return 'health'; }
                    public function dependencies(): array { return []; }
                    public function register(ZoltaGaska\Module\Registry $registry): void {
                        $registry->route('health', 'GET', '/api/health', '', $this->handler);
                    }
                };
                $kernel = new ZoltaGaska\Http\Kernel(ZoltaGaska\Module\Registry::boot([$module]));
                echo $kernel->handle(new ZoltaGaska\Http\Request('/api/health', 'GET'))->body;
            '''),
            ("resources", {"yellow-goose/resources": "0.2.0"}, r'''
                if (class_exists('PDO') || class_exists('ZoltaGaska\\Frontend\\Assets') || class_exists('Symfony\\Component\\Validator\\Validation')) throw new Exception('Heavy dependency');
                $identities = new class implements ZoltaGaska\Security\IdentityProvider {
                    public function identity(ZoltaGaska\Http\Request $request): ?ZoltaGaska\Security\Identity { return new ZoltaGaska\Security\Identity('1'); }
                };
                $policies = new class implements ZoltaGaska\Module\Module {
                    public function id(): string { return 'auth'; }
                    public function dependencies(): array { return []; }
                    public function register(ZoltaGaska\Module\Registry $registry): void {
                        $registry->policy('notes', fn ($identity) => $identity?->id === '1');
                    }
                };
                $validator = new class implements ZoltaGaska\Resources\Validator {
                    public function validate(array $data): array { return $data === ['title' => 'safe'] ? [] : ['title' => ['Invalid title']]; }
                };
                $resource = new ZoltaGaska\Resources\ResourceDefinition('notes', 'notes', ['title' => ['type' => 'string']],
                    fn () => ['items' => [['title' => 'safe']]], [new ZoltaGaska\Resources\Action('save', 'notes', $validator, fn () => ['consumer' => true])]);
                $registry = ZoltaGaska\Module\Registry::boot([$policies, new ZoltaGaska\Resources\ResourceModule($resource, $identities, ['auth'])]);
                $kernel = new ZoltaGaska\Http\Kernel($registry, new ZoltaGaska\Security\RequestAuthorizer($identities,
                    new ZoltaGaska\Security\Gate($registry->policies()), new ZoltaGaska\Security\CsrfGuard(fn () => str_repeat('a', 64))));
                $response = $kernel->handle(new ZoltaGaska\Http\Request('/api/resources/notes/actions/save', 'POST',
                    ['Content-Type' => 'application/json', 'X-CSRF-Token' => str_repeat('a', 64)], '{"title":"safe"}'));
                if ($response->status !== 200) throw new Exception($response->body);
                echo $response->body;
            '''),
            ("csr", {"yellow-goose/react-bridge": "0.2.0"}, r'''
                if (class_exists('ZoltaGaska\\Database\\Connection') || class_exists('ZoltaGaska\\Frontend\\V8JsRenderer')) throw new Exception('Heavy dependency');
                $app = new ZoltaGaska\Http\Application(__DIR__ . '/pages', new ZoltaGaska\Frontend\Assets(__DIR__, 'http://127.0.0.1:5173'));
                $response = $app->handle(new ZoltaGaska\Http\Request('/', 'GET'));
                if ($response->status !== 200 || !str_contains($response->body, 'data-render-mode="csr"')) throw new Exception('CSR failed');
                $response = $app->handle(new ZoltaGaska\Http\Request('/', 'GET', ['X-YG-Navigation' => '1']));
                if ($response->status !== 200) throw new Exception('Navigation failed');
                echo '{"consumer":true}';
            '''),
        ]:
            app = temporary / name
            (app / "pages/api").mkdir(parents=True)
            shutil.copy2(ROOT / "tests/fixtures/consumer/app/pages/api/health.php", app / "pages/api/health.php")
            (app / "pages/index.jsx").touch()
            (app / "composer.json").write_text(json.dumps({"name": "test/" + name, "require": requirements,
                "repositories": [{"type": "package", "package": releases}, {"packagist.org": False}]}))
            run("composer", "install", "--no-interaction", "--no-scripts", cwd=app, env=env)
            (app / "check.php").write_text("<?php require __DIR__ . '/vendor/autoload.php';\n" + code)
            assert run("php", "-n", "check.php", cwd=app, env=env).strip() == '{"consumer":true}'
            print("PASS:", name, "archive consumer with PHP -n (no PDO/V8Js)")


if __name__ == "__main__":
    main()
