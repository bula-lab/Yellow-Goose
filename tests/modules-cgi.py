"""Real session login and protected resource requests through the modular API."""
from pathlib import Path
from email.parser import Parser
from http.cookies import SimpleCookie
import json
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    binary = shutil.which('php-cgi')
    if not binary:
        raise RuntimeError('php-cgi is required')
    with tempfile.TemporaryDirectory(prefix='yg-modules-cgi-') as tmp:
        password = 'temporary-test-password'
        password_hash = subprocess.check_output(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], text=True)
        env = dict(os.environ, GASKA_SESSION_PATH=tmp, YG_EXAMPLE_PASSWORD_HASH=password_hash)
        checks = 0
        def check(ok, label):
            nonlocal checks
            assert ok, label
            checks += 1
        def request(cookies, path, method='GET', data=None, token=None):
            raw = json.dumps(data).encode() if data is not None else b''
            values = dict(env, REDIRECT_STATUS='200', SCRIPT_FILENAME=str(ROOT / 'examples/modular-api/public/index.php'),
                          SCRIPT_NAME='/index.php', REQUEST_URI=path, REQUEST_METHOD=method, SERVER_PROTOCOL='HTTP/1.1',
                          SERVER_NAME='localhost', SERVER_PORT='8000', GATEWAY_INTERFACE='CGI/1.1',
                          REMOTE_ADDR='127.0.0.1', HTTP_HOST='localhost:8000', CONTENT_LENGTH=str(len(raw)),
                          CONTENT_TYPE='application/json', HTTP_COOKIE='; '.join(f'{key}={value}' for key, value in cookies.items()))
            if token is not None:
                values['HTTP_X_CSRF_TOKEN'] = token
            result = subprocess.run([binary], env=values, cwd=ROOT, input=raw, capture_output=True, check=True)
            head, body = result.stdout.decode().split('\r\n\r\n', 1)
            headers = Parser().parsestr(head)
            for value in headers.get_all('Set-Cookie', []):
                cookies.update({key: morsel.value for key, morsel in SimpleCookie(value).items()})
            return int(headers.get('Status', '200').split()[0]), headers, json.loads(body)

        first, second = {}, {}
        status, headers, data = request(first, '/api/auth/session')
        token = data['csrfToken']
        check(status == 200 and data['identity'] is None and len(token) == 64, 'anonymous session bootstrap')
        check('HttpOnly' in headers['Set-Cookie'] and 'SameSite=Lax' in headers['Set-Cookie'], 'session cookie flags')
        check(headers['Cache-Control'] == 'no-store', 'session response never shared')
        _, _, other = request(second, '/api/auth/session')
        check(other['csrfToken'] != token, 'two sessions do not share CSRF')
        check(request(first, '/api/resources/notes')[0] == 401, 'anonymous resource rejected')
        credentials = {'login': 'editor', 'password': password}
        check(request(first, '/api/auth/login', 'POST', credentials)[0] == 403, 'login CSRF required')
        check(request(first, '/api/auth/login', 'POST', {'login': 'editor', 'password': 'wrong'}, token)[0] == 401, 'invalid credentials')
        old_session = first['gaska_session']
        status, headers, logged = request(first, '/api/auth/login', 'POST', credentials, token)
        new_token = logged['csrfToken']
        check(status == 200 and logged['identity'] == 'editor', 'authenticated account')
        check(first['gaska_session'] != old_session and new_token != token, 'login rotates ID and CSRF')
        check(request(second, '/api/resources/notes')[0] == 401, 'other session remains anonymous')
        status, headers, rows = request(first, '/api/resources/notes?limit=1&sort=title')
        check(status == 200 and rows['items'][0]['title'].endswith('editor'), 'resource query uses server identity')
        check(headers['Cache-Control'] == 'no-store', 'resource response never shared')
        status, _, schema = request(first, '/api/resources/notes/schema')
        check(status == 200 and schema['actions'] == ['save'], 'schema exposes declared actions')
        action = '/api/resources/notes/actions/save'
        check(request(first, action, 'POST', {'title': 'ok'}, token)[0] == 403, 'old CSRF invalid after login')
        check(request(first, action, 'POST', {'title': ''}, new_token)[0] == 422, 'server validates action')
        check(request(first, action, 'POST', {'title': 'ok', 'identity': 'admin'}, new_token)[0] == 422, 'client cannot inject identity')
        status, _, accepted = request(first, action, 'POST', {'title': 'accepted'}, new_token)
        check(status == 200 and accepted == {'accepted': {'title': 'accepted'}, 'actor': 'editor'}, 'validated action consumer')
        check(request(second, action, 'POST', {'title': 'blocked'}, other['csrfToken'])[0] == 401, 'valid CSRF does not replace authorization')
        status, _, logged_out = request(first, '/api/auth/logout', 'POST', {}, new_token)
        check(status == 200 and logged_out['csrfToken'] != new_token, 'logout rotates CSRF')
        check(request(first, '/api/resources/notes')[0] == 401, 'logout removes access')
        print(f'PASS: {checks} modular API CGI assertions (two sessions, login/logout, CSRF, policies, schema, validation)')


if __name__ == '__main__':
    main()
