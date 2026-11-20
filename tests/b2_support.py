"""Disposable installation of the actual garage example for request and browser tests."""
from pathlib import Path
import os
import secrets
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "examples" / "garage-cms"
FILES = ROOT / "tests" / "fixtures" / "b1"


class GarageFixture:
    def __init__(self, ssr=True):
        self.tmp = tempfile.TemporaryDirectory(prefix="yg-garage-")
        self.data = Path(self.tmp.name)
        sessions = self.data / "sessions"
        sessions.mkdir()
        self.password = secrets.token_urlsafe(24)
        self.env = dict(os.environ, GARAGE_DATA_DIR=str(self.data), GASKA_SESSION_PATH=str(sessions),
                        GARAGE_EDITOR_PASSWORD=self.password, GARAGE_SIGNING_KEY=secrets.token_hex(32),
                        GASKA_SSR="1" if ssr else "0")

    def cli(self, *args):
        return subprocess.check_output(["php", str(APP / "console.php"), *args], cwd=ROOT, env=self.env, text=True)

    def __enter__(self):
        migration = self.cli("migrate")
        assert "cms:001_catalog.php" in migration and "assets:001_assets.php" in migration
        assert not self.cli("migrate"), "migration was applied twice"
        self.cli("seed")
        return self

    def __exit__(self, *_):
        self.tmp.cleanup()
