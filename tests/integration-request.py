"""The upload transport must preserve binary bytes through real PHP request capture."""
from email.parser import Parser
from pathlib import Path
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    php = shutil.which('php-cgi')
    if not php:
        raise RuntimeError('php-cgi is required')
    with tempfile.TemporaryDirectory(prefix='yg-upload-capture-') as tmp:
        script = Path(tmp) / 'echo.php'
        script.write_text('''<?php
            require getenv('YG_AUTOLOAD');
            try {
                $request = ZoltaGaska\\Http\\Request::capture();
                (new ZoltaGaska\\Http\\Response($request->bytes(262144), 200,
                    ['Content-Type' => 'application/octet-stream']))->send();
            } catch (ZoltaGaska\\Http\\HttpException $error) {
                ZoltaGaska\\Http\\Response::json(['error' => $error->getMessage()], $error->status)->send();
            }
        ''')
        checks = 0
        for raw, expected in [(b'0', 200), (b'\x00\xff\r\n', 200), (b'x' * 262144, 200),
                              (b'x' * 262145, 413), (b'x' * 1048704, 413)]:
            env = dict(os.environ, YG_AUTOLOAD=str(ROOT / 'vendor/autoload.php'),
                       REDIRECT_STATUS='200', SCRIPT_FILENAME=str(script), SCRIPT_NAME='/echo.php',
                       REQUEST_URI='/api/upload', REQUEST_METHOD='PUT', CONTENT_TYPE='application/octet-stream',
                       CONTENT_LENGTH=str(len(raw)), SERVER_PROTOCOL='HTTP/1.1', SERVER_NAME='localhost',
                       SERVER_PORT='8000', GATEWAY_INTERFACE='CGI/1.1', REMOTE_ADDR='127.0.0.1')
            result = subprocess.run([php], input=raw, env=env, capture_output=True, check=True)
            head, body = result.stdout.split(b'\r\n\r\n', 1)
            headers = Parser().parsestr(head.decode())
            assert int(headers.get('Status', '200').split()[0]) == expected, headers
            if expected == 200:
                assert body == raw, 'Captured bytes changed'
            checks += 1
        print(f'PASS: {checks} PHP-CGI binary capture cases, ASCII zero, exact limit and oversized bodies')


if __name__ == '__main__':
    main()
