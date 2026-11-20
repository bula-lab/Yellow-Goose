"""Exercise the shipped workspace, UI and Babylon adapter with PHP V8Js and real WebGL."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.error import URLError
from urllib.request import urlopen
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix="yg-b1-browser-") as tmp:
        project = Path(tmp)
        shutil.copytree(ROOT / "tests/fixtures/b1", project, dirs_exist_ok=True)
        (project / "node_modules").symlink_to(ROOT / "node_modules", target_is_directory=True)
        vite = str(project / "node_modules/.bin/vite")
        for args in [[], ["--ssr", "app/server.jsx"]]:
            result = subprocess.run([vite, "build", *args], cwd=project, capture_output=True, text=True)
            if result.returncode:
                raise RuntimeError(result.stderr + result.stdout)
        ssr = (project / "build/ssr/server.js").read_text()
        assert "ArcRotateCamera" not in ssr and "Babylon.js v" not in ssr and len(ssr) < 500000
        manifest = json.loads((project / "public/build/.vite/manifest.json").read_text())
        assert any(info.get("isDynamicEntry") and "engine" in key for key, info in manifest.items())
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        base = f"http://127.0.0.1:{port}"
        with (project / "server.log").open("w+") as log:
            server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public", "router.php"], cwd=project,
                                      env=dict(os.environ, B1_AUTOLOAD=str(ROOT / "vendor/autoload.php")), stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with urlopen(base, timeout=2) as response:
                            assert response.status == 200
                        break
                    except URLError:
                        if server.poll() is not None: break
                        time.sleep(.05)
                with sync_playwright() as pw:
                    options = {"headless": True, "args": ["--enable-unsafe-swiftshader", "--use-angle=swiftshader"]}
                    if os.environ.get("PLAYWRIGHT_CHROMIUM_EXECUTABLE"):
                        options["executable_path"] = os.environ["PLAYWRIGHT_CHROMIUM_EXECUTABLE"]
                    browser = pw.chromium.launch(**options)
                    try:
                        run_browser(browser, base)
                    finally:
                        browser.close()
            except BaseException:
                log.flush()
                log.seek(0)
                print(log.read()[-4000:])
                raise
            finally:
                server.terminate()
                server.wait(timeout=5)


def viewport_dimensions(page, with_poster):
    area = page.locator('[data-yg-viewport]')
    canvas = area.locator('canvas').bounding_box()
    assert canvas and canvas['width'] > 0
    assert abs(canvas['height'] - canvas['width'] * 9 / 16) < 1, canvas
    if with_poster:
        image = area.get_by_role('img')
        assert image.evaluate("(img)=>img.complete && img.naturalWidth===320 && img.naturalHeight===320")
        assert image.bounding_box() == canvas, (image.bounding_box(),canvas)
        assert image.evaluate("(img)=>getComputedStyle(img).objectFit") == "contain"
    return canvas['width'], canvas['height']


def run_browser(browser, base):
    screen = {"width": 900, "height": 900}
    static = browser.new_context(java_script_enabled=False, viewport=screen)
    p = static.new_page()
    p.goto(base + "/?project=a&dialog=1")
    expect(p.locator("#app")).to_have_attribute("data-render-mode", "ssr")
    expect(p.get_by_role("img")).to_be_visible()
    expect(p.get_by_role("dialog")).to_be_visible()
    static_dimensions = viewport_dimensions(p, with_poster=True)
    static.close()
    context = browser.new_context(viewport=screen)
    p = context.new_page()
    failures, models = [], []
    p.on("pageerror", lambda e: failures.append(str(e)))
    p.on("console", lambda m: failures.append(m.text) if m.type == "error" and "Hydration failed" in m.text else None)
    p.on("request", lambda r: models.append(r.url) if "/box.glb?" in r.url else None)
    model_request = []
    p.route("**/box.glb?**", lambda route: model_request.append(route))
    with p.expect_request("**/box.glb?**"):
        p.goto(base)
    active = p.locator('[data-yg-viewport]')
    expect(active).to_have_attribute("data-yg-viewport", "loading")
    assert viewport_dimensions(p, with_poster=True) == static_dimensions
    model_request[0].continue_()
    p.unroute("**/box.glb?**")
    expect(active).to_have_attribute("data-yg-viewport", "ready", timeout=30000)
    assert viewport_dimensions(p, with_poster=False) == static_dimensions
    p.evaluate("window.original = window.fixturePort")
    s = p.evaluate("fixturePort.viewState()")
    assert s["assetVersionId"] == "box-a" and s["rightHanded"] and s["running"]
    assert all(abs(a-b) < .0001 for a,b in zip(s["dimensionsMeters"], [3,2.13,5]))
    p.wait_for_function("""async () => {
        const blob=await fixturePort.capturePreview(), image=await createImageBitmap(blob);
        const c=document.createElement('canvas'); c.width=32; c.height=32;
        const ctx=c.getContext('2d'); ctx.drawImage(image,0,0,32,32); image.close();
        const colors=new Set(new Uint32Array(ctx.getImageData(0,0,32,32).data.buffer));
        return blob.type==='image/png' && colors.size>2;
    }""",timeout=15000)
    entity = s["entityIds"][0]
    p.evaluate("(id)=>{fixturePort.select(id); fixturePort.focus(id)}", entity)
    assert p.evaluate("fixturePort.viewState().selected") == entity
    canvas = active.locator("canvas")
    box = canvas.bounding_box()
    x,y = box["x"] + box["width"]*.5,box["y"] + box["height"]*.5
    p.mouse.click(x,y)
    assert p.evaluate("window.fixtureSelection") == entity
    p.mouse.move(x,y); p.mouse.down(); p.mouse.move(x+75,y+20,steps=12); p.mouse.up()
    p.wait_for_function("(alpha)=>Math.abs(fixturePort.viewState().camera.alpha-alpha)>.01", arg=s["camera"]["alpha"])
    # Finish camera inertia before comparing across a navigation.
    p.wait_for_function("""() => {
        const a=fixturePort.viewState().camera.alpha;
        const still=window.lastA!==undefined && Math.abs(a-window.lastA)<.000001;
        window.lastA=a; return still;
    }""", polling=100)
    camera = p.evaluate("fixturePort.viewState().camera")
    height = p.get_by_label("Height (cm)")
    expect(height).to_have_value("213")
    assert height.locator("option").all_text_contents() == ["200","213","240"]
    height.select_option("240")
    p.get_by_role("button", name="Confirm").click()
    p.wait_for_function("window.confirmed?.heightMm===2400")
    p.wait_for_function("Math.abs(fixturePort.viewState().dimensionsMeters[1]-2.4)<.0001")
    height.select_option("200")
    p.get_by_role("button", name="Confirm").click()
    expect(p.get_by_text("Unavailable height.")).to_be_visible()
    expect(height).to_have_attribute("aria-invalid","true")
    height.select_option("213")
    p.get_by_role("button", name="Confirm").click()
    p.wait_for_function("window.confirmed?.heightMm===2130")
    table = p.get_by_role("table")
    expect(table).to_contain_text("Alpha")
    p.get_by_role("button", name="Następna").click()
    expect(table).to_contain_text("Bravo")
    p.get_by_role("button", name="Poprzednia").click()
    expect(table).to_contain_text("Alpha")
    p.get_by_role("button", name="Name").click()
    expect(p.locator('th[aria-sort="ascending"]')).to_be_visible()
    p.get_by_role("button", name="Name").click()
    expect(table).to_contain_text("Bravo")
    p.get_by_role("textbox", name="Name").fill("Bra")
    p.get_by_role("button", name="Filtruj").click()
    expect(p.get_by_role("button", name="Następna")).to_be_disabled()
    p.get_by_role("link", name="Inspect").click()
    dialog = p.get_by_role("dialog")
    expect(dialog).to_be_visible()
    assert p.evaluate("document.activeElement.closest('dialog')!==null")
    dialog.get_by_role("textbox").fill("Inspection note")
    before_typing = p.evaluate("fixturePort.viewState().camera")
    p.keyboard.down("ArrowLeft")
    p.evaluate("new Promise(done=>requestAnimationFrame(()=>requestAnimationFrame(done)))")
    p.keyboard.up("ArrowLeft")
    assert p.evaluate("fixturePort.viewState().camera") == before_typing
    dialog.get_by_role("button", name="Zamknij").focus()
    p.keyboard.press("Tab")
    expect(dialog.get_by_role("textbox")).to_be_focused()
    p.keyboard.press("Shift+Tab")
    expect(dialog.get_by_role("button", name="Zamknij")).to_be_focused()
    p.evaluate("document.querySelector('canvas').focus()")
    assert p.evaluate("document.activeElement.closest('dialog')!==null")
    p.keyboard.press("Escape")
    expect(dialog).to_have_count(0)
    expect(p.get_by_role("link", name="Inspect")).to_be_focused()
    p.go_back()
    expect(dialog).to_be_visible()
    p.go_forward()
    expect(dialog).to_have_count(0)
    assert p.evaluate("fixturePort===original") and len(models)==1
    assert abs(p.evaluate("fixturePort.viewState().camera.alpha")-camera["alpha"]) < .001
    expect(table).to_contain_text("Bravo")
    expect(p.get_by_role("textbox", name="Name")).to_have_value("Bra")
    p.evaluate("window.scrollTo(0,document.body.scrollHeight)")
    p.wait_for_function("!fixturePort.viewState().running")
    p.evaluate("window.scrollTo(0,0)")
    p.wait_for_function("fixturePort.viewState().running")
    context_available = p.evaluate("""() => {
        const c=document.querySelector('canvas'),gl=c.getContext('webgl2')||c.getContext('webgl');
        if (!gl) return false;
        window.glLoss=gl.getExtension('WEBGL_lose_context');
        if (!glLoss) return false;
        glLoss.loseContext();return true;
    }""")
    assert context_available
    expect(active).to_have_attribute("data-yg-viewport", "lost")
    p.evaluate("glLoss.restoreContext()")
    expect(active).to_have_attribute("data-yg-viewport", "ready", timeout=15000)
    p.get_by_role("link", name="Switch project").click()
    expect(p.get_by_role("heading", name="Project b")).to_be_visible()
    expect(active).to_have_attribute("data-yg-viewport","ready",timeout=30000)
    assert p.evaluate("fixturePort!==original && !original.viewState().running && fixturePort.viewState().assetVersionId==='box-b'")
    assert len(models)==2
    expect(p.locator('meta[name="description"],link[rel="canonical"]')).to_have_count(0)
    p.go_back()
    expect(p.get_by_role("heading", name="Project a")).to_be_visible()
    expect(p.locator('meta[name="description"]')).to_have_attribute('content','First project')
    expect(p.locator('link[rel="canonical"]')).to_have_attribute('href','/?project=a')
    # A dirty workspace controls normal and Back navigations. Its save finishes before disposal.
    expect(active).to_have_attribute("data-yg-viewport","ready",timeout=30000)
    p.evaluate("window.dirtyPort=fixturePort")
    p.locator('#dirty').click()
    p.get_by_role("link", name="Switch project").click()
    prompt = p.get_by_role("dialog", name="Unsaved draft")
    expect(prompt).to_be_visible()
    prompt.get_by_role("button", name="cancel", exact=True).click()
    expect(prompt).to_have_count(0)
    assert p.evaluate("fixturePort===dirtyPort") and "project=b" not in p.url
    p.get_by_role("link", name="Switch project").click()
    prompt.get_by_role("button", name="save", exact=True).click()
    p.wait_for_function("typeof window.fixtureFinishSave==='function'")
    assert p.evaluate("fixturePort===dirtyPort") and "project=b" not in p.url
    p.evaluate("fixtureFinishSave()")
    expect(p.get_by_role("heading", name="Project b")).to_be_visible()
    expect(active).to_have_attribute("data-yg-viewport","ready",timeout=30000)
    p.evaluate("window.dirtyPort=fixturePort")
    p.locator('#dirty').click()
    p.go_back()
    expect(prompt).to_be_visible()
    prompt.get_by_role("button", name="cancel", exact=True).click()
    p.wait_for_function("location.search.includes('project=b')")
    assert p.evaluate("fixturePort===dirtyPort")
    p.go_back()
    expect(prompt).to_be_visible()
    prompt.get_by_role("button", name="discard", exact=True).click()
    expect(p.get_by_role("heading", name="Project a")).to_be_visible()
    expect(active).to_have_attribute("data-yg-viewport","ready",timeout=30000)
    assert p.evaluate("!dirtyPort.viewState().running && dirtyPort!==fixturePort")
    assert not failures, failures
    context.close()
    fallback = browser.new_context()
    p = fallback.new_page()
    p.add_init_script("""const getContext=HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext=function(kind,...args){
            if (!window.fixtureAllowGpu && kind.includes('webgl')) return null;
            return getContext.call(this,kind,...args);
        };""")
    p.goto(base)
    active = p.locator('[data-yg-viewport]')
    expect(active).to_have_attribute('data-yg-viewport','error',timeout=30000)
    expect(active.get_by_role('img')).to_be_visible()
    p.evaluate("window.fixtureAllowGpu=true")
    p.route("**/box.glb?**",lambda route: route.fulfill(status=200,body=b"invalid GLB"))
    with p.expect_request("**/box.glb?**"):
        active.get_by_role('button',name='Ponów').click()
    expect(active).to_have_attribute('data-yg-viewport','error',timeout=15000)
    expect(active.get_by_role('img')).to_be_visible()
    p.unroute("**/box.glb?**")
    active.get_by_role('button',name='Ponów').click()
    expect(active).to_have_attribute('data-yg-viewport','ready',timeout=30000)
    fallback.close()

    delayed = browser.new_context()
    p = delayed.new_page()
    p.add_init_script("""const nativeFetch=window.fetch;window.abortedModelLoads=0;
        window.fetch=function(url,opts){ if (String(url).includes('/box.glb?'))
            opts?.signal?.addEventListener('abort',()=>{window.abortedModelLoads++},{once:true});
            return nativeFetch.call(this,url,opts); };""")
    waiting = []
    def hold_first(route):
        if waiting:
            route.continue_()
        else:
            waiting.append(route)
    p.route("**/box.glb?**",hold_first)
    with p.expect_request("**/box.glb?**"):
        p.goto(base)
    expect(p.locator('[data-yg-viewport]')).to_have_attribute('data-yg-viewport','loading')
    p.get_by_role('link',name='Switch project').click()
    expect(p.get_by_role('heading',name='Project b')).to_be_visible()
    p.wait_for_function('window.abortedModelLoads===1')
    waiting[0].abort('aborted')
    expect(p.locator('[data-yg-viewport]')).to_have_attribute('data-yg-viewport','ready',timeout=30000)
    delayed.close()
    print("PASS: Chromium WebGL/Babylon with visible geometry and V8Js SSR, focus/history, camera persistence, "
          "2.13 m bounds, server query/422, save/discard/cancel, no-GPU/decoder recovery, context loss and fetch disposal.")


if __name__ == "__main__":
    main()
