"""Real browser + real HTTP tests. Uses a disposable project and SQLite database."""
from pathlib import Path
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]


def run(*command, cwd, env):
    subprocess.run(command, cwd=cwd, env=env, check=True)


def available_port():
    try:
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            return sock.getsockname()[1]
    except OSError as error:
        raise RuntimeError("E2E wymaga możliwości otwarcia lokalnego portu HTTP. " + str(error)) from error


def wait_http(url, process):
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError("Serwer zakończył pracę; sprawdź log test-results/e2e-servers.log.")
        try:
            with urllib.request.urlopen(url, timeout=1) as response:
                if response.status == 200:
                    return
        except (urllib.error.URLError, TimeoutError):
            time.sleep(0.1)
    raise RuntimeError("Przekroczono czas oczekiwania na " + url)


def expect_client_ready(page):
    # SSR content can be visible before React has attached event handlers.
    expect(page.locator("#app")).to_have_attribute("data-client-ready", "true")


def main():
    port = available_port()
    base = f"http://127.0.0.1:{port}"
    artifacts = ROOT / "test-results"
    artifacts.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="yellow-goose-e2e-") as tmp:
        project = Path(tmp) / "project"
        project.mkdir()
        for name in ["app", "packages", "config", "database", "public"]:
            shutil.copytree(ROOT / name, project / name,
                            ignore=shutil.ignore_patterns("*.sqlite*", "build", "__pycache__"))
        for name in ["gaska", "bootstrap.php", "vite.config.js", "package.json", "package-lock.json", "composer.json", "composer.lock"]:
            shutil.copy2(ROOT / name, project / name)
        # Reuse installed dependencies, never copy local .env or application data.
        (project / "node_modules").symlink_to(ROOT / "node_modules", target_is_directory=True)
        sessions = Path(tmp) / "sessions"
        sessions.mkdir()
        env = dict(os.environ, DB_CONNECTION="sqlite", DB_DATABASE=str(Path(tmp) / "test.sqlite"),
                   GASKA_SESSION_PATH=str(sessions), GASKA_VITE_DEV_SERVER="", GASKA_SSR="1",
                   COMPOSER_DISABLE_NETWORK="1")
        run("composer", "install", "--no-interaction", "--no-scripts", cwd=project, env=env)
        run("php", "gaska", "db:migrate", cwd=project, env=env)
        run("php", "gaska", "db:migrate", cwd=project, env=env)
        run("php", "gaska", "db:seed", cwd=project, env=env)
        run("npm", "run", "build", cwd=project, env=env)
        processes = []
        server_log = open(artifacts / "e2e-servers.log", "w")
        browser = None

        def start_php(dev=False):
            args = ["php", "gaska", "serve", f"--port={port}"]
            if dev:
                args.append("--dev")
            process = subprocess.Popen(args, cwd=project, env=env, stdout=server_log,
                                       stderr=subprocess.STDOUT, start_new_session=True)
            processes.append(process)
            wait_http(base + "/api/hello", process)
            return process

        def stop(process):
            if process.poll() is None:
                # gaska launches the PHP server as a child; terminate its process group.
                import signal
                os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait(timeout=5)

        try:
            php = start_php()
            with sync_playwright() as p:
                options = {"headless": True}
                if os.environ.get("PLAYWRIGHT_CHROMIUM_EXECUTABLE"):
                    options["executable_path"] = os.environ["PLAYWRIGHT_CHROMIUM_EXECUTABLE"]
                browser = p.chromium.launch(**options)
                context = browser.new_context()
                page = context.new_page()
                errors = []
                page.on("pageerror", lambda error: errors.append(str(error)))
                page.on("console", lambda message: errors.append(message.text)
                        if message.type == "error" and "Hydration failed" in message.text else None)
                context.tracing.start(screenshots=True, snapshots=True, sources=True)
                try:
                    # Baseline: actual React rendering, API and static assets.
                    page.goto(base)
                    expect(page.get_by_role("heading", name="Wpisy", exact=True)).to_be_visible()
                    expect_client_ready(page)
                    assert page.locator('#app').get_attribute('data-render-mode') == 'ssr'
                    no_js = browser.new_context(java_script_enabled=False)
                    try:
                        static_page = no_js.new_page()
                        static_page.goto(base)
                        expect(static_page.get_by_role('heading', name='Wpisy', exact=True)).to_be_visible()
                        expect(static_page.locator('.post-card')).to_have_count(2)
                    finally:
                        no_js.close()
                    page.get_by_text("Sprawdź połączenie z serwerem", exact=True).click()
                    page.get_by_role("button", name="Pobierz czas z serwera (API)").click()
                    expect(page.get_by_role("status")).to_contain_text("Czas z API:")
                    assert page.request.get(base + "/build/.vite/manifest.json").status == 404
                    assert page.request.get(base + "/.env").status == 404
                    assert page.request.get(base + "/favicon.svg").status == 200
                    assert page.request.get(base + "/brand/icon-light.svg").status == 200
                    expect(page.locator('.brand img')).to_be_visible()
                    assert page.locator('.brand img').evaluate('(img) => img.complete && img.naturalWidth > 0')
                    assert page.request.head(base + "/api/hello").body() == b""
                    assert page.request.post(base + "/api/posts", data={"title": "blocked", "content": "blocked"}).status == 403

                    # Empty form validation, creation, dynamic refresh and editing.
                    page.get_by_role("link", name="Nowy wpis", exact=True).click()
                    expect(page.get_by_role("heading", name="Dodaj wpis")).to_be_visible()
                    expect_client_ready(page)
                    page.get_by_role("button", name="Zapisz wpis").click()
                    expect(page.locator("#title-error")).to_have_text("To pole jest wymagane.")
                    expect(page.locator("#content-error")).to_have_text("To pole jest wymagane.")
                    page.get_by_label("Tytuł", exact=True).fill("Przeglądarkowy wpis")
                    page.get_by_label("Treść", exact=True).fill("Pierwsza linia\nDruga linia <script>alert(1)</script>")
                    page.get_by_role("button", name="Zapisz wpis").click()
                    expect(page.get_by_role("heading", name="Przeglądarkowy wpis", exact=True)).to_be_visible()
                    expect_client_ready(page)
                    detail_url = page.url.split("?")[0]
                    page.reload()
                    expect(page.get_by_role("heading", name="Przeglądarkowy wpis", exact=True)).to_be_visible()
                    expect_client_ready(page)
                    expect(page.locator(".post-content")).to_contain_text("<script>alert(1)</script>")
                    page.get_by_role("link", name="Edytuj wpis", exact=True).click()
                    expect(page.get_by_label("Tytuł", exact=True)).to_have_value("Przeglądarkowy wpis")
                    expect_client_ready(page)
                    page.get_by_label("Tytuł", exact=True).fill("Zmieniony w przeglądarce")
                    page.get_by_role("button", name="Zapisz wpis").click()
                    expect(page.get_by_role("heading", name="Zmieniony w przeglądarce")).to_be_visible()
                    expect_client_ready(page)

                    # Persistence across an actual PHP process restart.
                    stop(php)
                    php = start_php()
                    page.goto(detail_url)
                    expect(page.get_by_role("heading", name="Zmieniony w przeglądarce")).to_be_visible()
                    expect_client_ready(page)

                    # Error recovery: failed deletion keeps record and re-enables button.
                    def fail_delete(route):
                        route.fulfill(status=500, content_type="application/json", body='{"error":"Testowy błąd serwera"}')
                    page.route("**/api/posts/*", fail_delete)
                    page.once("dialog", lambda dialog: dialog.accept())
                    page.get_by_role("button", name="Usuń wpis").click()
                    expect(page.get_by_role("alert")).to_contain_text("Testowy błąd serwera")
                    expect(page.get_by_role("button", name="Usuń wpis")).to_be_enabled()
                    page.unroute("**/api/posts/*", fail_delete)
                    page.once("dialog", lambda dialog: dialog.accept())
                    page.get_by_role("button", name="Usuń wpis").click()
                    expect(page.get_by_role("heading", name="Wpisy", exact=True)).to_be_visible()
                    expect_client_ready(page)
                    expect(page.get_by_role("status")).to_contain_text("Wpis został usunięty.")
                    assert page.request.get(detail_url).status == 404
                    assert page.request.get(base + "/api/posts/999999").status == 404

                    # Mobile layout has no horizontal overflow.
                    page.set_viewport_size({"width": 390, "height": 844})
                    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
                    page.screenshot(path=str(artifacts / "mvp-mobile.png"), full_page=True)
                    page.set_viewport_size({"width": 1280, "height": 900})
                    page.screenshot(path=str(artifacts / "mvp-desktop.png"), full_page=True)

                    # Real HMR in disposable sources, not by reloading the page.
                    stop(php)
                    vite_port = available_port()
                    vite = subprocess.Popen(["php", "gaska", "dev", f"--port={port}", f"--vite-port={vite_port}"], cwd=project, env=env,
                                            stdout=server_log, stderr=subprocess.STDOUT, start_new_session=True)
                    processes.append(vite)
                    wait_http(f"http://127.0.0.1:{vite_port}/build/@vite/client", vite)
                    wait_http(base + "/api/hello", vite)
                    page.goto(base)
                    expect(page.get_by_role("heading", name="Wpisy", exact=True)).to_be_visible()
                    expect_client_ready(page)
                    assert page.locator('#app').get_attribute('data-render-mode') == 'csr'
                    page.evaluate("window.__gaskaHmrMarker = 'keep-me'")
                    component = project / "app/pages/index.jsx"
                    source = component.read_text()
                    component.write_text(source.replace("<h1>Wpisy</h1>", "<h1>Wpisy HMR</h1>"))
                    expect(page.get_by_role("heading", name="Wpisy HMR", exact=True)).to_be_visible(timeout=15000)
                    assert page.evaluate("window.__gaskaHmrMarker") == "keep-me", "HMR caused a full reload"
                    assert not errors, errors
                    print("PASS: SSR without JS, hydration, CRUD, validation, CSRF, API, persistence, errors, mobile and one-command HMR")
                finally:
                    context.tracing.stop(path=str(artifacts / "e2e-trace.zip"))
                    browser.close()
        finally:
            for process in reversed(processes):
                stop(process)
            server_log.close()


if __name__ == "__main__":
    main()
