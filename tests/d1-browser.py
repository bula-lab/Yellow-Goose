"""The shipped strategy UI with real PHP, deadlines, recovery and persistent workspace."""
from pathlib import Path
import os
import socket
import sqlite3
import subprocess
import time
from urllib.error import URLError
from urllib.request import urlopen
from playwright.sync_api import expect, sync_playwright
from d1_support import ROOT, APP, StrategyFixture, Session


def run():
    with StrategyFixture() as fixture:
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        with (fixture.data / 'server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=APP,
                                      env=fixture.env, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        urlopen(base, timeout=1).close(); break
                    except URLError:
                        time.sleep(.05)
                with sync_playwright() as pw:
                    options = {'headless': True}
                    if os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE'):
                        options['executable_path'] = os.environ['PLAYWRIGHT_CHROMIUM_EXECUTABLE']
                    browser = pw.chromium.launch(**options)
                    try:
                        checks(browser, fixture, base)
                    finally:
                        browser.close()
            except BaseException:
                log.flush(); log.seek(0); print(log.read()[-2000:]); raise
            finally:
                server.terminate(); server.wait(timeout=10)


def connected(page):
    expect(page.get_by_test_id('connection')).to_have_attribute('data-state', 'online', timeout=15000)


def build(page, target):
    connected(page)
    page.get_by_role('link', name='Build ' + target, exact=True).click()
    dialog = page.get_by_role('dialog', name='Build ' + target, exact=True)
    expect(dialog).to_be_visible()
    dialog.get_by_role('button', name='Confirm construction', exact=True).click()
    expect(dialog).to_have_count(0)


def checks(browser, fixture, base):
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    context.add_init_script('Date.now = () => 4102444800000;')
    page = context.new_page(); errors = []; sent = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('request', lambda request: sent.append(request.post_data_json)
            if request.method == 'POST' and request.url.endswith('/commands') else None)
    page.goto(base + '/login')
    page.get_by_label('Login', exact=True).fill('player')
    page.get_by_label('Password', exact=True).fill(fixture.password)
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.get_by_label('Colony name', exact=True).fill('Kepler Test')
    page.get_by_role('button', name='Join season', exact=True).click()
    expect(page.get_by_role('heading', name='Kepler Test', exact=True)).to_be_visible()
    connected(page)
    game_id = page.url.split('/games/')[1].split('?')[0]
    path = '/api/games/' + game_id
    owner = Session(fixture).login()
    before = owner.send(path)
    assert before['resources'] >= 300 and before['points'] == 0
    # The SVG instance, selection and zoom survive panel/modal/history navigation.
    board = page.get_by_test_id('colony-map').element_handle()
    page.get_by_role('button', name='Select Lab', exact=True).click()
    page.get_by_role('button', name='Zoom in', exact=True).click()
    page.get_by_role('link', name='Buildings', exact=True).click()
    expect(page.get_by_test_id('colony-map')).to_have_attribute('data-selected', 'lab')
    expect(page.get_by_test_id('colony-map')).to_have_attribute('data-zoom', '1.25')
    assert board.evaluate("el=>el===document.querySelector('[data-testid=colony-map]')")
    page.get_by_role('link', name='Build mine', exact=True).click()
    expect(page.get_by_role('dialog')).to_contain_text('100 alloy')
    page.go_back(); expect(page.get_by_role('dialog')).to_have_count(0)
    page.go_forward(); expect(page.get_by_role('dialog')).to_be_visible()
    assert board.evaluate("el=>el===document.querySelector('[data-testid=colony-map]')")
    page.get_by_role('button', name='Confirm construction', exact=True).click()
    page.get_by_role('link', name='Overview', exact=True).click()
    expect(page.get_by_test_id('operation')).to_have_count(1)
    expect(page.get_by_test_id('points')).to_have_text('0')
    pending = owner.send(path)
    assert len(pending['operations']) == 1 and pending['operations'][0]['cost'] == 100
    # Browser wall time is set to 2100. Only the PHP deadline can complete the build.
    expect(page.get_by_test_id('points')).to_have_text('10', timeout=15000)
    expect(page.get_by_test_id('operation')).to_have_count(0)
    page.get_by_role('button', name='Claim reward', exact=True).click()
    expect(page.get_by_test_id('points')).to_have_text('12', timeout=15000)
    assert owner.send(path)['points'] == 12
    # The server commits, then the network loses the receipt. Retry after reload is byte-for-byte.
    def lose(route):
        route.fetch(); route.abort('failed')
    page.route('**/commands', lose, times=1)
    build(page, 'reactor')
    expect(page.get_by_role('button', name='Retry same order', exact=True)).to_be_visible(timeout=15000)
    first = sent[-1]
    page.reload(); connected(page)
    page.get_by_role('button', name='Retry same order', exact=True).click()
    expect(page.get_by_role('button', name='Retry same order', exact=True)).to_have_count(0)
    page.wait_for_function("Object.keys(sessionStorage).every(k=>!k.startsWith('yg.strategy.pending.'))")
    assert sent[-1] == first
    with sqlite3.connect(fixture.data / 'colony.sqlite') as db:
        assert db.execute("SELECT COUNT(*) FROM yg_strategy_operations WHERE game_id=? AND target='reactor'", [game_id]).fetchone()[0] == 1
    # Reconnect catches up without any PHP worker running.
    context.set_offline(True)
    expect(page.get_by_test_id('connection')).to_have_attribute('data-state', 'offline')
    time.sleep(4.2)
    context.set_offline(False); connected(page)
    expect(page.get_by_test_id('points')).to_have_text('20', timeout=15000)
    assert owner.send(path)['levels']['reactor'] == 1
    # Bounded standings and reset of a stale continuation are exercised through DataTable.
    with sqlite3.connect(fixture.data / 'colony.sqlite') as db:
        for index in range(7):
            db.execute('INSERT INTO colony_accounts SELECT ?,?,password_hash,1 FROM colony_accounts WHERE id=?',
                       [f'guest-{index}', f'guest-{index}', 'demo-player'])
    for index in range(7):
        Session(fixture).login(f'guest-{index}').join('Colony ' + str(index))
    page.get_by_role('link', name='Leaderboard', exact=True).click()
    rows = page.locator('tbody tr')
    expect(rows).to_have_count(5)
    expect(page.get_by_text('As of', exact=False)).to_be_visible()
    page.get_by_role('button', name='Następna', exact=True).click()
    expect(rows).to_have_count(3)
    page.get_by_role('button', name='Poprzednia', exact=True).click()
    expect(rows).to_have_count(5)
    # Change the projection before the old continuation is consumed; the server resets the table.
    snapshot = owner.send(path)
    owner.send(path + '/commands', {'commandId': 'browser-extra-claim', 'type': 'reward.claim', 'expectedRevision': snapshot['revision'], 'expectedRulesVersion': snapshot['rulesVersion'],
               'payload': {'rewardId': snapshot['rewards'][0]['rewardId']}}, 'POST')
    page.get_by_role('button', name='Następna', exact=True).click()
    expect(rows).to_have_count(5)
    expect(page.get_by_role('button', name='Poprzednia', exact=True)).to_be_disabled()
    page.get_by_role('button', name='Następna', exact=True).click()
    expect(rows).to_have_count(3)
    # Client polling clears private state and prevents further orders after current account revocation.
    page.get_by_role('link', name='Overview', exact=True).click()
    Path(ROOT / 'test-results').mkdir(exist_ok=True)
    page.screenshot(path=str(ROOT / 'test-results/strategy-game-desktop.png'), full_page=True)
    page.set_viewport_size({'width': 390, 'height': 844})
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'), 'Mobile horizontal overflow'
    page.screenshot(path=str(ROOT / 'test-results/strategy-game-mobile.png'), full_page=True)
    with sqlite3.connect(fixture.data / 'colony.sqlite') as db:
        db.execute("UPDATE colony_accounts SET enabled=0 WHERE id='demo-player'")
    expect(page.get_by_role('heading', name='Colony access ended', exact=True)).to_be_visible(timeout=15000)
    expect(page.get_by_test_id('colony-map')).to_have_count(0)
    assert not errors, errors
    context.close()
    print('PASS: D1 Chromium server-time construction, rewards, persistent map/history, exact retry, reconnect, ranking and revocation')


if __name__ == '__main__':
    run()
