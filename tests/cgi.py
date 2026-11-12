"""Exercise public/index.php via PHP-CGI, including real cookies, headers and request bodies."""
from pathlib import Path
from email.parser import Parser
from http.cookies import SimpleCookie
import json
import os
import re
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    binary = shutil.which("php-cgi")
    if not binary:
        raise RuntimeError("Test wymaga php-cgi dostępnego w PATH.")
    with tempfile.TemporaryDirectory(prefix="gaska-cgi-") as tmp:
        env = dict(os.environ, DB_CONNECTION="sqlite", DB_DATABASE=str(Path(tmp) / "test.sqlite"),
                   GASKA_SESSION_PATH=tmp, GASKA_VITE_DEV_SERVER="", GASKA_SSR="1")
        subprocess.run(["php", "gaska", "db:migrate"], cwd=ROOT, env=env, check=True, capture_output=True)
        cookies = {}
        checks = 0

        def check(condition, message):
            nonlocal checks
            if not condition:
                raise AssertionError(message)
            checks += 1

        def request(uri, method="GET", body=None, token=None, content_type="application/json"):
            raw = b"" if body is None else (body.encode() if isinstance(body, str) else json.dumps(body).encode())
            values = dict(env, REDIRECT_STATUS="200", SCRIPT_FILENAME=str(ROOT / "public/index.php"),
                          SCRIPT_NAME="/index.php", REQUEST_URI=uri, REQUEST_METHOD=method,
                          SERVER_PROTOCOL="HTTP/1.1", SERVER_NAME="localhost", SERVER_PORT="8000",
                          GATEWAY_INTERFACE="CGI/1.1", REMOTE_ADDR="127.0.0.1",
                          HTTP_HOST="localhost:8000", CONTENT_LENGTH=str(len(raw)),
                          HTTP_COOKIE="; ".join(f"{k}={v}" for k, v in cookies.items()))
            if body is not None:
                values["CONTENT_TYPE"] = content_type
            if token is not None:
                values["HTTP_X_CSRF_TOKEN"] = token
            result = subprocess.run([binary], cwd=ROOT, env=values, input=raw, capture_output=True, check=True)
            output = result.stdout.decode()
            if "\r\n\r\n" in output:
                head, payload = output.split("\r\n\r\n", 1)
            else:
                head, payload = output.split("\n\n", 1)
            headers = Parser().parsestr(head)
            for value in headers.get_all("Set-Cookie", []):
                cookie = SimpleCookie(value)
                cookies.update({key: morsel.value for key, morsel in cookie.items()})
            return int(headers.get("Status", "200").split()[0]), headers, payload

        status, headers, html = request("/")
        check(status == 200, "homepage")
        check('<h1>Wpisy</h1>' in html and 'data-render-mode="ssr"' in html, "HTML rendered before JavaScript")
        check('Tu pojawią się Twoje wpisy' in html, "SSR empty state")
        check('<link rel="icon" href="/favicon.svg">' in html, "favicon in server head")
        check('src="/brand/icon-light.svg"' in html, "logo present before JavaScript")
        check("text/html" in headers["Content-Type"], "HTML content type")
        cookie_header = " ".join(headers.get_all("Set-Cookie", []))
        check("HttpOnly" in cookie_header and "SameSite=Lax" in cookie_header, "session cookie flags")
        check(headers["Cache-Control"] == "no-store", "HTML cache protection")
        data = json.loads(re.search(r'<script id="page-data" type="application/json">(.*?)</script>', html, re.S)[1])
        token = data["props"]["csrfToken"]
        check(len(token) == 64, "CSRF token generated")
        check(data["props"]["posts"] == [], "empty database list")
        env['GASKA_SSR'] = '0'
        _, _, csr = request('/')
        check('data-render-mode="csr"' in csr and '<h1>Wpisy</h1>' not in csr, "explicit CSR mode")
        env['GASKA_SSR'] = '1'
        status, _, _ = request("/api/posts", "POST", {"title": "No token", "content": "No token"})
        check(status == 403, "CSRF without token")
        status, _, _ = request("/api/posts", "POST", {}, token, "text/plain")
        check(status == 415, "wrong content type")
        status, _, _ = request("/api/posts", "POST", "{", token)
        check(status == 400, "malformed JSON")
        status, _, body = request("/api/posts", "POST", {"title": "", "content": ""}, token)
        check(status == 422 and "title" in json.loads(body)["errors"], "validation error fields")
        status, headers, body = request("/api/posts", "POST",
                                       {"title": "Zapis przez CGI", "content": "Ala\n<script>alert(1)</script>"}, token)
        check(status == 201, "created")
        post = json.loads(body)["post"]
        post_id = post["id"]
        check(headers["Location"] == f"/api/posts/{post_id}", "created Location")
        check(headers["X-Content-Type-Options"] == "nosniff", "nosniff header")
        status, _, html = request(f"/posts/{post_id}")
        check(status == 200, "dynamic page")
        check('<h1>Zapis przez CGI</h1>' in html, "SSR dynamic loader")
        check('&lt;script&gt;alert(1)&lt;/script&gt;' in html, "SSR escapes user content")
        check(request(f'/posts/{post_id}', 'HEAD')[2] == '', "SSR HEAD has no body")
        check('Wpis został zapisany.' in request(f'/posts/{post_id}?saved=1')[2], "SSR query notice")
        check('value="Zapis przez CGI"' in request(f'/posts/{post_id}/edit')[2], "SSR edit form")
        data = json.loads(re.search(r'<script id="page-data" type="application/json">(.*?)</script>', html, re.S)[1])
        check(data["props"]["post"]["content"].endswith("</script>"), "escaped data round trip")
        check(data["props"]["csrfToken"] == token, "session survives process restart")
        check("</script><script>alert" not in html, "no inline script injection")
        status, _, body = request(f"/api/posts/{post_id}", "PATCH", {"title": "Zmieniony CGI"}, token)
        check(status == 200 and json.loads(body)["post"]["title"] == "Zmieniony CGI", "updated")
        status, _, body = request(f"/api/posts/{post_id}")
        check(status == 200 and json.loads(body)["post"]["title"] == "Zmieniony CGI", "persistent after process restart")
        status, headers, body = request(f"/api/posts/{post_id}", "HEAD")
        check(status == 200 and body == "", "HEAD body empty")
        status, headers, _ = request(f"/api/posts/{post_id}", "PUT", {}, token)
        check(status == 405 and headers["Allow"] == "GET, HEAD, PATCH, DELETE", "405 Allow header")
        status, _, body = request(f"/api/posts/{post_id}", "DELETE", token=token)
        check(status == 204 and body == "", "DELETE body empty")
        check(request(f"/api/posts/{post_id}")[0] == 404, "deleted API 404")
        check(request(f"/posts/{post_id}")[0] == 404, "deleted page 404")
        check(request("/api/posts?page=bad")[0] == 400, "invalid query")
        check(request("/missing")[0] == 404, "unknown page 404")
        check('Wpis został usunięty.' in request('/?deleted=1')[2], "SSR deleted notice")
        print(f"PASS: {checks} CGI assertions (real entry point, session, JSON, headers, persistence)")


if __name__ == "__main__":
    main()
