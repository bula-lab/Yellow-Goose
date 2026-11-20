"""The same reference app via PHP CGI, two real sessions, binary uploads and a PHP worker."""
import argparse
import hashlib
import json
import shutil
import sqlite3
import subprocess
from concurrent.futures import ThreadPoolExecutor
from email.parser import Parser
from http.cookies import SimpleCookie

from b2_support import APP, FILES, GarageFixture


class Browser:
    def __init__(self, fixture):
        self.fixture = fixture
        self.cookies = {}
        self.csrf = None

    def send(self, path, expected=200, method="GET", data=None, headers=None):
        binary = isinstance(data, bytes)
        body = data if binary else json.dumps(data).encode() if data is not None else b""
        env = dict(self.fixture.env, REDIRECT_STATUS="200", SCRIPT_FILENAME=str(APP / "public/index.php"),
                   SCRIPT_NAME="/index.php", REQUEST_URI=path, REQUEST_METHOD=method, SERVER_PROTOCOL="HTTP/1.1",
                   SERVER_NAME="localhost", SERVER_PORT="8000", GATEWAY_INTERFACE="CGI/1.1", REMOTE_ADDR="127.0.0.1",
                   HTTP_HOST="localhost:8000", CONTENT_LENGTH=str(len(body)),
                   CONTENT_TYPE="application/octet-stream" if binary else "application/json",
                   HTTP_COOKIE="; ".join(f"{key}={value}" for key, value in self.cookies.items()))
        if method not in ("HEAD", "GET") and self.csrf:
            env["HTTP_X_CSRF_TOKEN"] = self.csrf
        env.update({"HTTP_" + key.upper().replace("-", "_"): value for key, value in (headers or {}).items()})
        result = subprocess.run([shutil.which("php-cgi") or "php-cgi"], env=env, input=body, capture_output=True, check=True)
        head, body = result.stdout.split(b"\r\n\r\n", 1)
        metadata = Parser().parsestr(head.decode())
        for value in metadata.get_all("Set-Cookie", []):
            self.cookies.update({key: morsel.value for key, morsel in SimpleCookie(value).items()})
        status = int(metadata.get("Status", "200").split()[0])
        assert status in ((expected,) if isinstance(expected, int) else expected), \
            f"{method} {path.split('?')[0]} expected {expected}, received {status}: {result.stderr.decode()}"
        assert metadata["Cache-Control"] == "private, no-store", f"{path.split('?')[0]} cache policy"
        return body

    def read(self, path, **kwargs):
        return json.loads(self.send(path, **kwargs))

    def act(self, path, data, expected=200):
        return self.read(path, expected=expected, method="POST", data=data)

    def session(self):
        session = self.read("/api/auth/session")
        self.csrf = session["csrfToken"]
        return session


def upload_version(browser, project, files):
    base = f"/api/assets/projects/{project}/uploads"
    meta = {kind + "Bytes": len(content) for kind, content in files.items()}
    meta.update({kind + "Checksum": hashlib.sha256(content).hexdigest() for kind, content in files.items()})
    upload = browser.act(base, meta)
    url = base + "/" + upload["uploadId"]
    token = {"X-Upload-Token": upload["token"]}
    for kind, content in files.items():
        browser.send(f"{url}/{kind}?offset=0", method="PUT", data=content, headers=token)
    return browser.read(url + "/complete", method="POST", headers=token)


def run(ssr):
    with GarageFixture(ssr) as fixture:
        editor, guest = Browser(fixture), Browser(fixture)
        editor.session()
        guest.session()
        previous = editor.csrf
        assert guest.csrf != previous
        old_cookie = editor.cookies.copy()
        editor.csrf = editor.act("/api/auth/login", {"login": "editor", "password": fixture.password})["csrfToken"]
        assert editor.csrf != previous and editor.cookies != old_cookie
        nav = {"X-YG-Navigation": "1"}
        command = "/api/resources/products/actions/"
        product_url = "/garages/studio"
        preview = "/preview/studio"
        versions = "/api/assets/projects/garage-demo/versions"
        title = "Studio <b>garage</b>"
        description = 'Plain text </script><script>window.cmsMarkupRan=true</script> " &'
        product = {"id": "studio", "title": title, "description": description,
                   "assetVersionId": None, "materialIds": ["graphite", "forest"]}
        create = editor.act(command + "create", product)
        assert create["revision"] == 1
        editor.act(command + "publish", {"id": "studio", "expectedRevision": 1}, 422)
        editor.act(command + "save", dict(product, expectedRevision=1, projectId="other"), 422)
        editor.send(command + "save", method="POST", data=dict(product, expectedRevision=1),
                    headers={"X-CSRF-Token": guest.csrf}, expected=403)
        guest.act(command + "publish", {"id": "studio", "expectedRevision": 1}, 401)
        guest.send("/api/cms/preview/studio?role=editor", expected=401)
        for path in [preview, "/editor/products/studio", "/editor", "/editor/materials", "/editor/new"]:
            for headers in [nav, {}]:
                body = guest.send(path, expected=401, headers=headers)
                assert b"cmsMarkupRan" not in body and editor.csrf.encode() not in body
        for headers in [nav, {}]:
            guest.send(product_url, expected=404, headers=headers)
        assert guest.read("/api/resources/offers?filter%5Btitle%5D=Studio")["items"] == []
        guest.read("/api/resources/materials/schema", expected=401)
        assert editor.read(preview, headers=nav)["props"]["product"]["description"] == description
        preview_values = {"widthMm": 3000, "depthMm": 5000, "heightMm": 2130, "color": "#343d46", "expectedRevision": 1}
        guest.act("/api/garage/preview/studio/quote", preview_values, 401)
        estimate = editor.act("/api/garage/preview/studio/quote", preview_values)
        assert estimate["amountMinor"] == 509500 and "confirmationId" not in estimate
        if ssr:
            html = editor.send(preview).decode()
            assert 'data-render-mode="ssr"' in html and '&lt;/script&gt;' in html and '<script>window.cmsMarkupRan' not in html

        files = {"model": (FILES / "box.glb").read_bytes(), "poster": (FILES / "poster.png").read_bytes()}
        queued = upload_version(editor, "garage-demo", files)
        version = queued["assetVersionId"]
        assert queued["status"] == "queued"
        data = dict(product, expectedRevision=1, assetVersionId=version)
        editor.act(command + "save", data, 422)
        guest.send(versions + "/" + version, expected=404)
        fixture.cli("jobs:work", "1")
        ready = editor.read(versions + "/" + version)
        assert ready["status"] == "ready" and ready["checksum"] == hashlib.sha256(files["model"]).hexdigest()
        invalid = upload_version(editor, "garage-demo", dict(files, model=b"not a GLB"))
        with sqlite3.connect(fixture.data / "garage.sqlite") as db:
            db.execute("""INSERT INTO garage_accounts (id,login,password_hash,project_id,role,enabled)
                          SELECT 'foreign-editor','foreign',password_hash,'another-project','editor',1
                          FROM garage_accounts WHERE login='editor'""")
        foreign = Browser(fixture)
        foreign.session()
        foreign.csrf = foreign.act("/api/auth/login", {"login": "foreign", "password": fixture.password})["csrfToken"]
        foreign_version = upload_version(foreign, "another-project", files)["assetVersionId"]
        fixture.cli("jobs:work", "10")
        failed_version = invalid["assetVersionId"]
        assert editor.read(versions + "/" + failed_version)["status"] == "failed"
        assert foreign.read("/api/assets/projects/another-project/versions/" + foreign_version)["status"] == "ready"
        editor.send("/api/assets/projects/another-project/versions/" + foreign_version, expected=404)
        foreign.send("/api/resources/products", expected=403)
        for unavailable in [foreign_version, failed_version]:
            editor.act(command + "save", dict(data, assetVersionId=unavailable), 422)
        poster = editor.send(ready["posterUrl"])
        # GD decodes and re-encodes the supplied poster. Its PNG header retains the image dimensions.
        assert poster.startswith(b"\x89PNG\r\n\x1a\n") and poster[16:24] == files["poster"][16:24]
        guest.send(ready["modelUrl"], expected=403)
        assert editor.act(command + "save", data)["revision"] == 2
        editor.act(command + "save", data, 409)
        pub = editor.act(command + "publish", {"id": "studio", "expectedRevision": 2})
        assert pub == {"id": "studio", "revision": 3, "publishedRevision": 2}
        public = guest.read(product_url, headers=nav)
        assert public["props"]["csrfToken"] == guest.csrf and editor.csrf not in json.dumps(public)
        assert public["head"] == {"title": title + " | Garage studio", "description": description, "canonical": product_url}
        assert public["props"]["product"]["revision"] == 2 and "draftRevision" not in public["props"]["product"]
        if ssr:
            doc = guest.send(product_url).decode()
            assert 'data-render-mode="ssr"' in doc and '<img' in doc and '<h1>Studio &lt;b&gt;garage&lt;/b&gt;</h1>' in doc
            assert '<script>window.cmsMarkupRan' not in doc and '<link rel="canonical" href="/garages/studio">' in doc
        assert guest.send(product_url, method="HEAD") == b""
        signed = public["props"]["product"]["asset"]
        assert guest.send(signed["modelUrl"]) == files["model"]
        assert guest.send(signed["modelUrl"], expected=206, headers={"Range": "bytes=4-7"}) == files["model"][4:8]
        assert guest.send(signed["posterUrl"]) == poster
        second_editor = Browser(fixture)
        second_editor.session()
        second_editor.csrf = second_editor.act("/api/auth/login", {"login": "editor", "password": fixture.password})["csrfToken"]
        with ThreadPoolExecutor(max_workers=2) as requests:
            results = list(requests.map(lambda browser: browser.act(command + "save",
                dict(data, expectedRevision=3, title="Unreleased"), (200, 409)), [editor, second_editor]))
        assert [result.get("revision") for result in results].count(4) == 1
        assert [result.get("error") for result in results].count("Revision conflict.") == 1
        assert guest.read("/api/cms/offers/studio")["title"] == title
        assert guest.read("/api/resources/offers?filter%5Btitle%5D=Unreleased")["items"] == []
        assert b"Unreleased" not in guest.send("/?filter%5Btitle%5D=Unreleased", headers=nav).split(b'"listing"', 1)[1].split(b'"filter"', 1)[0]
        assert editor.read("/api/resources/products?filter%5Btitle%5D=Unreleased")["items"][0]["id"] == "studio"
        guest.send("/api/resources/offers?sort=project_id", expected=400)
        guest.send("/api/resources/offers?filter%5Brole%5D=editor", expected=400)
        editor.send("/api/resources/products/actions/drop", expected=404, method="POST", data={})
        with sqlite3.connect(fixture.data / "garage.sqlite") as db:
            db.execute("UPDATE garage_accounts SET role='reader' WHERE login='editor'")
        editor.read("/api/auth/session")
        for path in ["/api/cms/preview/studio", "/api/resources/products/schema", "/api/resources/products", versions]:
            editor.send(path, expected=403)
        editor.send(preview, expected=403, headers=nav)
        editor.act(command + "publish", {"id": "studio", "expectedRevision": 4}, 403)
        with sqlite3.connect(fixture.data / "garage.sqlite") as db:
            db.execute("UPDATE garage_accounts SET role='editor' WHERE login='editor'")

        api = "/api/garage/offers/studio"
        values = {"widthMm": 3500, "depthMm": 6000, "heightMm": 2130, "color": "#41634c", "expectedRevision": 2}
        guest.act(api + "/confirm", dict(values, amountMinor=1), 422)
        guest.act(api + "/confirm", dict(values, color="#123456"), 422)
        guest.act(api + "/confirm", dict(values, widthMm=2500), 422)
        guest.act(api + "/confirm", dict(values, expectedRevision=1), 409)
        quote_result = guest.act(api + "/confirm", values)
        assert quote_result["amountMinor"] == 624500 and quote_result["configuration"]["heightMm"] == 2130
        enquiry = {"confirmationId": quote_result["confirmationId"], "email": "test@example.invalid"}
        editor.act(api + "/enquire", enquiry, 409)
        guest.act(api + "/enquire", dict(enquiry, amountMinor=1), 422)
        assert guest.act(api + "/enquire", enquiry)["amountMinor"] == 624500
        guest.act(api + "/enquire", enquiry)
        with sqlite3.connect(fixture.data / "garage.sqlite") as db:
            row = db.execute("SELECT configuration_json, amount_minor, currency FROM garage_enquiries").fetchall()
            assert len(row) == 1 and json.loads(row[0][0])["heightMm"] == 2130 and row[0][1:] == (624500, "PLN")
        editor.act(command + "unpublish", {"id": "studio", "expectedRevision": 4})
        guest.send(product_url, expected=404)
        guest.send(product_url, expected=404, headers=nav)
        guest.send("/api/cms/offers/studio", expected=404)
        assert guest.read("/api/resources/offers")["items"] == []
        guest.send(versions + "/" + version, expected=404)
        guest.send(signed["modelUrl"], expected=404)
        guest.act(api + "/enquire", enquiry, 404)
        assert editor.read("/api/cms/preview/studio")["title"] == "Unreleased"
        editor.act("/api/auth/logout", {})
        editor.send(preview, expected=401)
        print("PASS: garage CGI with {} (two sessions, current role, upload + worker + streaming, draft/public isolation, "
              "metadata, CSRF, confirmation at 624500 grosze, persisted enquiry, unpublish)".format("V8Js SSR" if ssr else "CSR"))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--csr", action="store_true")
    run(not parser.parse_args().csr)
