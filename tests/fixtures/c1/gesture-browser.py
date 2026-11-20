"""Drive Babylon transform handles with real Chromium pointer events."""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from tempfile import TemporaryDirectory
from threading import Thread
import os
import shutil
import subprocess
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[3]


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def run(browser, base):
    page = browser.new_page(viewport={"width": 820, "height": 520})
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.goto(base)
    page.wait_for_function("window.phase === 'ready'")
    for mode, field in [("translate", "position"), ("rotate", "rotation"), ("scale", "scale")]:
        page.evaluate("mode => { port.focus('box'); port.setTool(mode, true); }", mode)
        page.evaluate("() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))")
        before = page.evaluate("port.viewState().entities[0].transform")
        count = page.evaluate("commands.length")
        handles = page.evaluate("getHandles()")
        assert handles, (mode, 'no visible or pickable gizmo handles', errors)
        for x, y in handles:
            page.mouse.move(x, y)
            page.mouse.down()
            page.mouse.move(x + 75, y - 40, steps=8)
            assert page.evaluate("commands.length") == count, 'pointer movement sent an intermediate command'
            page.mouse.up()
            if page.evaluate("commands.length") == count + 1:
                break
        assert page.evaluate("commands.length") == count + 1, (mode, handles, errors)
        result = page.evaluate("commands.at(-1)")
        after = page.evaluate("port.viewState().entities[0].transform")
        assert result['id'] == 'box' and set(result['values']) == {field}, result
        assert after[field] == result['values'][field] and after[field] != before[field], (mode, result, before, after)
        if mode == 'translate':
            assert all(abs(v * 4 - round(v * 4)) < 1e-6 for v in after[field]), after
        if mode == 'rotate':
            assert abs(sum(v*v for v in after[field]) - 1) < 1e-6, after
        if mode == 'scale':
            assert all(v > 0 for v in after[field]), after
    a, b = page.evaluate("flipHierarchy()")
    assert a['parentId'] == 'b' and a['worldPosition'] == [3, 0, 0] and b['worldPosition'] == [1, 0, 0], (a, b)
    assert not errors, errors
    page.close()


def main():
    with TemporaryDirectory(prefix='yg-gestures-') as temp:
        project = Path(temp)
        shutil.copytree(Path(__file__).parent / 'gestures', project, dirs_exist_ok=True)
        (project / 'node_modules').symlink_to(ROOT / 'node_modules', target_is_directory=True)
        subprocess.run([ROOT / 'node_modules/.bin/vite', 'build', '--logLevel', 'error'], cwd=project, check=True)
        server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=project / 'dist'))
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with sync_playwright() as pw:
                options = {'headless': True, 'args': ['--enable-unsafe-swiftshader', '--use-angle=swiftshader']}
                if os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE'):
                    options['executable_path'] = os.environ['PLAYWRIGHT_CHROMIUM_EXECUTABLE']
                browser = pw.chromium.launch(**options)
                try:
                    run(browser, f'http://127.0.0.1:{server.server_port}')
                finally:
                    browser.close()
        finally:
            server.shutdown()
            server.server_close()
            thread.join()
    print('PASS: translate, rotate and scale use real gizmo drags; snapping and one command per gesture')


if __name__ == '__main__':
    main()
