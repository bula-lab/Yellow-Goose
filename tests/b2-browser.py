"""Drive the actual editor and public WebGL configurator through Chromium."""
import os
import re
import socket
import subprocess
import time
import urllib.error
import urllib.request

from playwright.sync_api import expect, sync_playwright
from b2_support import APP, FILES, ROOT, GarageFixture


def available_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def wait_server(url, process):
    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError("Garage HTTP server exited unexpectedly.")
        try:
            with urllib.request.urlopen(url + "/api/auth/session", timeout=1) as response:
                if response.status == 200:
                    return
        except urllib.error.URLError:
            time.sleep(0.1)
    raise RuntimeError("Garage HTTP server did not start.")


def ready(page):
    expect(page.locator("#app")).to_have_attribute("data-client-ready", "true")


def check_mobile_width(page):
    page.set_viewport_size({"width": 390, "height": 844})
    outside = page.evaluate("""() => [...document.querySelectorAll('body *')].filter(element =>
        element.getBoundingClientRect().right > innerWidth + 1).map(element => element.tagName + '.' + element.className).slice(0, 10)""")
    assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), f"390 px layout overflows horizontally: {outside}"


def run():
    with GarageFixture() as fixture:
        base = f"http://127.0.0.1:{available_port()}"
        with open(fixture.data / "http.log", "w") as http_log:
            server = subprocess.Popen(["php", "-S", base.split("//")[1], "-t", str(APP / "public"), str(APP / "public/router.php")],
                                      cwd=ROOT, env=fixture.env, stdout=http_log, stderr=subprocess.STDOUT)
            try:
                wait_server(base, server)
                with sync_playwright() as playwright:
                    args = {"headless": True, "args": ["--use-angle=swiftshader", "--enable-unsafe-swiftshader"]}
                    if executable := os.getenv("PLAYWRIGHT_CHROMIUM_EXECUTABLE"):
                        args["executable_path"] = executable
                    with playwright.chromium.launch(**args) as browser:
                        exercise(browser, base, fixture)
            finally:
                server.terminate()
                server.wait(timeout=10)


def exercise(browser, base, fixture):
    editor = browser.new_context()
    page = editor.new_page()
    errors, editor_urls = [], []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.on("request", lambda req: editor_urls.append(req.url))
    page.goto(base + "/login")
    ready(page)
    page.get_by_label("Login", exact=True).fill("editor")
    page.get_by_label("Password").fill(fixture.password)
    page.get_by_role("button", name="Sign in", exact=True).click()
    expect(page.get_by_role("heading", name="Products", exact=True)).to_be_visible()
    page.get_by_role("link", name="Materials", exact=True).click()
    page.get_by_role("button", name="Add material").click()
    material = page.get_by_role("dialog")
    material.get_by_label("Material ID").fill("zinc")
    material.get_by_label("Material name").fill("Zinc")
    material.get_by_label("Color (#rrggbb)").fill("#8899aa")
    material.get_by_role("button", name="Save material").click()
    page.get_by_role("button", name="Zinc", exact=True).click()
    material.get_by_label("Material name").fill("Brushed zinc")
    material.get_by_role("button", name="Save material").click()
    expect(page.get_by_role("button", name="Brushed zinc", exact=True)).to_be_visible()
    page.get_by_role("link", name="Products", exact=True).click()
    page.get_by_role("link", name="New product").click()
    page.get_by_label("Product ID").fill("workshop")
    page.get_by_label("Product name").fill("Courtyard workshop")
    description = "An enclosed workshop with room for bicycles, tools and a workbench."
    page.get_by_label("Description").fill(description)
    page.get_by_role("button", name="Save draft").click()
    expect(page.get_by_role("heading", name="Edit product")).to_be_visible()
    expect(page.get_by_text("Unpublished", exact=False)).to_be_visible()
    no_js = browser.new_context(java_script_enabled=False)
    document = no_js.new_page()
    assert document.goto(base + "/garages/workshop").status == 404
    page.get_by_label("Plik GLB").set_input_files(str(FILES / "box.glb"))
    page.get_by_label("Poster PNG lub JPEG").set_input_files(str(FILES / "poster.png"))
    with page.expect_response(lambda response: response.url.endswith("/complete")) as complete:
        page.get_by_role("button", name="Wyślij pliki").click()
    queued = complete.value.json()
    assert queued["status"] == "queued"
    version = queued["assetVersionId"]
    fixture.cli("jobs:work", "1")
    option = page.locator('select option').filter(has_text=version + " (ready)")
    expect(option).to_have_count(1, timeout=15000)
    page.get_by_label("Model", exact=True).select_option(version)
    page.get_by_role("button", name="Save draft").click()
    expect(page.get_by_text("Draft revision 2", exact=False)).to_be_visible()
    check_mobile_width(page)
    page.set_viewport_size({"width": 1280, "height": 900})
    page.get_by_role("button", name="Publish saved draft").click()
    page.get_by_role("dialog").get_by_role("button", name="Confirm publish").click()
    expect(page.get_by_role("link", name="View published offer")).to_be_visible()
    page.get_by_role("link", name="Products", exact=True).click()
    expect(page.get_by_role("heading", name="Products", exact=True)).to_be_visible()
    page.get_by_role("textbox", name="Product name").fill("Courtyard")
    with page.expect_response(lambda res: "/api/resources/products?" in res.url and "Courtyard" in res.url):
        page.get_by_role("button", name="Filtruj").click()
    expect(page.get_by_role("row").filter(has_text="Courtyard workshop")).to_be_visible()
    page.get_by_role("link", name="Courtyard workshop").click()
    expect(page.get_by_role("heading", name="Edit product")).to_be_visible()
    page.get_by_label("Product name").fill("Private revision")
    page.get_by_role("link", name="Products", exact=True).click()
    page.get_by_role("dialog").get_by_role("button", name="Keep editing").click()
    expect(page.get_by_label("Product name")).to_have_value("Private revision")
    page.get_by_role("link", name="Products", exact=True).click()
    page.get_by_role("dialog").get_by_role("button", name="Save and leave").click()
    expect(page.get_by_role("link", name="Private revision")).to_be_visible()
    page.get_by_role("link", name="Private revision").click()
    expect(page.get_by_role("heading", name="Edit product")).to_be_visible()
    page.get_by_label("Product name").fill("Discarded title")
    page.get_by_role("link", name="Products", exact=True).click()
    page.get_by_role("dialog").get_by_role("button", name="Discard edits").click()
    expect(page.get_by_role("link", name="Private revision")).to_be_visible()
    assert not any(re.search(r"/engine-[\w-]+\.js", url) for url in editor_urls), "ordinary CMS pages loaded Babylon"

    response = document.goto(base + "/garages/workshop")
    assert response.status == 200 and response.headers["cache-control"] == "private, no-store"
    expect(document.get_by_role("heading", name="Courtyard workshop")).to_be_visible()
    expect(document.get_by_text(description, exact=True)).to_be_visible()
    expect(document.locator("[data-yg-viewport] img")).to_be_visible()
    assert document.locator("[data-yg-viewport] img").evaluate("(image) => image.complete && image.naturalWidth") == 320
    expect(document.get_by_label("Height (cm)")).to_have_value("213")
    assert document.locator("link[rel=canonical]").get_attribute("href") == "/garages/workshop"

    guest = browser.new_context(viewport={"width": 1280, "height": 900})
    offer = guest.new_page()
    offer.on("pageerror", lambda error: errors.append(str(error)))
    offer.on("console", lambda msg: errors.append(msg.text) if re.search("hydrat", msg.text, re.I) and msg.type == "error" else None)
    downloads = []
    offer.on("response", lambda res: downloads.append(res.status) if f"/versions/{version}/model?" in res.url else None)
    offer.goto(base + "/garages/workshop")
    ready(offer)
    expect(offer.locator("[data-yg-viewport]")).to_have_attribute("data-yg-viewport", "ready", timeout=45000)
    offer.evaluate("window.originalGarageCanvas = document.querySelector('canvas')")
    offer.get_by_label("Width (cm)").select_option("350")
    offer.get_by_label("Depth (cm)").select_option("600")
    offer.get_by_label("Height (cm)").select_option("240")
    offer.get_by_label("Color", exact=True).select_option("#343d46")
    settled = "() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))"
    offer.evaluate(settled)
    graphite = offer.locator("canvas").screenshot()
    offer.get_by_label("Color", exact=True).select_option("#41634c")
    offer.evaluate(settled)
    assert graphite != offer.locator("canvas").screenshot(), "selecting a different finish must repaint the model"
    offer.get_by_role("link", name="Model details").click()
    expect(offer.get_by_role("dialog").get_by_text("3.5 × 6 × 2.4 m")).to_be_visible()
    offer.go_back()
    expect(offer.get_by_role("dialog")).to_have_count(0)
    expect(offer.get_by_role("link", name="Model details")).to_be_focused()
    offer.get_by_label("Height (cm)").select_option("213")
    offer.get_by_role("link", name="Finishes", exact=True).click()
    expect(offer.get_by_role("table")).to_contain_text("Forest green")
    offer.get_by_role("link", name="Model details").click()
    expect(offer.get_by_role("dialog").get_by_text("3.5 × 6 × 2.13 m")).to_be_visible()
    offer.get_by_role("dialog").get_by_role("button", name="Zamknij").click()
    offer.get_by_role("link", name="Configure", exact=True).click()
    expect(offer.get_by_label("Height (cm)")).to_have_value("213")
    expect(offer.get_by_label("Width (cm)")).to_have_value("350")
    assert offer.evaluate("document.querySelector('canvas') === window.originalGarageCanvas")
    assert downloads == [200], "panel and dialog navigation should reuse the loaded GLB"
    with offer.expect_response(lambda res: res.url.endswith("/confirm")) as confirmation:
        offer.get_by_role("button", name="Confirm configuration").click()
    result = confirmation.value.json()
    assert result["configuration"] == {"widthMm": 3500, "depthMm": 6000, "heightMm": 2130, "color": "#41634c"}
    assert result["amountMinor"] == 624500 and result["currency"] == "PLN"
    expect(offer.get_by_text("6245.00 PLN", exact=True)).to_be_visible()
    artifacts = ROOT / "test-results"
    artifacts.mkdir(exist_ok=True)
    offer.screenshot(path=str(artifacts / "garage-cms-desktop.png"), full_page=True)
    check_mobile_width(offer)
    offer.screenshot(path=str(artifacts / "garage-cms-mobile.png"), full_page=True)
    offer.get_by_label("Email", exact=True).fill("visitor@example.invalid")
    offer.get_by_role("button", name="Save enquiry").click()
    expect(offer.get_by_text("Enquiry saved. Reference", exact=False)).to_be_visible()
    offer.get_by_role("link", name="Catalog", exact=True).click()
    expect(offer).to_have_title("Garage studio | Catalog")
    expect(offer.locator("link[rel=canonical]")).to_have_attribute("href", "/")
    expect(offer.locator('meta[name="description"]')).to_have_attribute("content", "Configure a garage from the published studio catalog.")
    offer.get_by_role("link", name="Editor sign in").click()
    expect(offer.locator("link[rel=canonical]")).to_have_count(0)
    expect(offer.locator('meta[name="description"]')).to_have_count(0)
    page.get_by_role("link", name="Private revision").click()
    page.get_by_role("button", name="Unpublish offer").click()
    page.get_by_role("button", name="Confirm unpublish").click()
    expect(page.get_by_text("Unpublished", exact=False)).to_be_visible()
    assert document.reload().status == 404
    assert errors == [], errors
    print("PASS: Chromium editor login, product form/table, upload + PHP worker, publish dialog, no-JS SSR poster, "
          "Babylon geometry at 2.40/2.13 m, persistent canvas, 624500 grosze confirmation, saved enquiry, metadata and unpublish")


if __name__ == "__main__":
    run()
