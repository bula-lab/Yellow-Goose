"""Install the framework in an independent app, build it and render its own page."""
from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def run(*args, cwd, env):
    result = subprocess.run(args, cwd=cwd, env=env, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(" ".join(args) + "\n" + result.stdout + result.stderr)
    return result.stdout


def main():
    with tempfile.TemporaryDirectory(prefix="gaska-consumer-") as tmp:
        app = Path(tmp) / "consumer"
        shutil.copytree(ROOT / "tests/fixtures/consumer", app)
        manifest = {
            "name": "test/independent-consumer",
            "require": {"yellow-goose/framework": "@dev"},
            "repositories": [
                {"type": "path", "url": str(ROOT / "packages/framework"), "options": {
                    "symlink": False, "versions": {"yellow-goose/framework": "dev-main"}}},
                {"packagist.org": False},
            ],
        }
        (app / "composer.json").write_text(json.dumps(manifest))
        source = json.loads((ROOT / "package.json").read_text())
        (app / "package.json").write_text(json.dumps({
            "name": "independent-consumer", "private": True, "type": "module",
            "scripts": {key: source["scripts"][key] for key in ["build", "build:client", "build:ssr"]},
            "dependencies": source["dependencies"], "devDependencies": source["devDependencies"],
        }))
        for name in ["app/client.jsx", "app/server.jsx", "vite.config.js"]:
            shutil.copy2(ROOT / name, app / name)
        (app / "app/styles.css").write_text("body { font-family: sans-serif; }\n")
        (app / "public").mkdir()
        # Only npm's installed dependencies are reused; Composer must install a real package copy.
        (app / "node_modules").symlink_to(ROOT / "node_modules", target_is_directory=True)
        env = dict(os.environ, COMPOSER_DISABLE_NETWORK="1", GASKA_VITE_DEV_SERVER="",
                   GASKA_SESSION_PATH=tmp, GASKA_SSR="1")
        run("composer", "install", "--no-interaction", "--no-scripts", cwd=app, env=env)
        assert not (app / "vendor/yellow-goose/framework").is_symlink()
        assert not (app / "src").exists()
        assert "Żółta-Gąska CLI" in run("php", "vendor/bin/gaska", "help", cwd=app, env=env)
        run("npm", "run", "build", cwd=app, env=env)
        html = run("php", "check.php", cwd=app, env=env)
        assert '<h1>Independent app &lt;safe&gt;</h1>' in html, html
        assert 'data-render-mode="ssr"' in html
        assert 'id="_gaska-' in html, "SSR useId must use the configured prefix"
        assert "Clicks:" in html
        print("PASS: independent Composer consumer, CLI, Vite client/SSR build, loader, API, escaped SSR and useId")


if __name__ == "__main__":
    main()
