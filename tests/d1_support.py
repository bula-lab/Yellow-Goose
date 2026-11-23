"""Isolated database and CGI sessions for the actual strategy-game application."""
from pathlib import Path
from email.parser import BytesParser
from http.cookies import SimpleCookie
import json
import os
import secrets
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'examples/strategy-game'


class StrategyFixture:
    def __init__(self, ssr=True):
        self.tmp = tempfile.TemporaryDirectory(prefix='yg-d1-')
        self.data = Path(self.tmp.name)
        (self.data / 'sessions').mkdir()
        self.password = secrets.token_urlsafe(24)
        self.env = dict(os.environ, STRATEGY_DATA_DIR=str(self.data), GASKA_SESSION_PATH=str(self.data / 'sessions'),
                        STRATEGY_PLAYER_PASSWORD=self.password, GASKA_SSR='1' if ssr else '0')

    def cli(self, *args):
        return subprocess.check_output(['php', str(APP / 'console.php'), *args], cwd=ROOT, env=self.env, text=True)

    def __enter__(self):
        assert 'game-strategy:' in self.cli('migrate')
        assert not self.cli('migrate'), 'Repeated migrations changed the database'
        self.cli('seed')
        return self

    def __exit__(self, *_):
        self.tmp.cleanup()


class Session:
    def __init__(self, fixture):
        self.fixture, self.cookies, self.csrf = fixture, {}, None

    def send(self, path, data=None, method='GET', expected=200, headers=None, raw=False):
        body = json.dumps(data).encode() if data is not None else b''
        env = dict(self.fixture.env, REDIRECT_STATUS='200', SCRIPT_FILENAME=str(APP / 'public/index.php'),
                   SCRIPT_NAME='/index.php', REQUEST_URI=path, REQUEST_METHOD=method, SERVER_PROTOCOL='HTTP/1.1',
                   SERVER_NAME='localhost', SERVER_PORT='8000', GATEWAY_INTERFACE='CGI/1.1', REMOTE_ADDR='127.0.0.1',
                   HTTP_HOST='localhost:8000', CONTENT_LENGTH=str(len(body)), CONTENT_TYPE='application/json',
                   HTTP_COOKIE='; '.join(f'{k}={v}' for k, v in self.cookies.items()))
        if self.csrf and method not in ('GET', 'HEAD'):
            env['HTTP_X_CSRF_TOKEN'] = self.csrf
        env.update({'HTTP_' + key.upper().replace('-', '_'): value for key, value in (headers or {}).items()})
        result = subprocess.run([shutil.which('php-cgi') or 'php-cgi'], env=env, input=body, capture_output=True, check=True)
        head, body = result.stdout.split(b'\r\n\r\n', 1)
        metadata = BytesParser().parsebytes(head)
        for value in metadata.get_all('Set-Cookie', []):
            self.cookies.update({key: morsel.value for key, morsel in SimpleCookie(value).items()})
        status = int(metadata.get('Status', '200').split()[0])
        assert status == expected, f'{method} {path.split("?")[0]}: expected {expected}, got {status}: {body[:600]!r}'
        assert metadata['Cache-Control'] == 'private, no-store'
        return body if raw else json.loads(body)

    def login(self, login='player'):
        self.csrf = self.send('/api/auth/session')['csrfToken']
        self.csrf = self.send('/api/auth/login', {'login': login, 'password': self.fixture.password}, 'POST')['csrfToken']
        return self

    def join(self, name='Kepler'):
        home = self.send('/', headers={'X-YG-Navigation': '1'})['props']
        season = home['seasons'][0]['seasonId']
        return self.send('/api/games', {'seasonId': season, 'displayName': name}, 'POST', expected=201)
