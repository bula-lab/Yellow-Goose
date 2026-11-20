"""Real WebGL, server persistence, two-tab conflicts and recovery in the shipped UI."""
from pathlib import Path
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.error import URLError
from urllib.request import urlopen
from playwright.sync_api import expect, sync_playwright
import c1_support
from c1_support import ROOT, SceneFixture, Session, upload


def run():
    with SceneFixture() as fixture, tempfile.TemporaryDirectory(prefix='yg-c1-browser-app-') as tmp:
        owner = Session(fixture).login()
        project = owner.send('/api/projects', {'name': 'Browser plot'}, 'POST', expected=201)['projectId']
        doc = owner.send(f'/api/projects/{project}/scenes', {'name': 'My garden'}, 'POST', expected=201)
        scene = f'/api/projects/{project}/scenes/{doc["sceneId"]}'
        version = upload(owner, project)
        fixture.cli('jobs:work', '1')
        app = Path(tmp)
        shutil.copytree(c1_support.APP, app, dirs_exist_ok=True, ignore=shutil.ignore_patterns('vendor', 'node_modules', 'var', 'build'))
        (app / 'vendor').symlink_to(ROOT / 'vendor', target_is_directory=True)
        (app / 'node_modules').symlink_to(ROOT / 'node_modules', target_is_directory=True)
        # The probe exists only in this disposable test copy, never in distributed application code.
        (app / 'app/components/Probe.jsx').write_text('''import { useCallback } from 'react';
import { BabylonSceneViewport } from '@yellow-goose/babylon/multi';
export function Probe(props) {
 const probe = useCallback(port => { window.c1Port = port; props.viewportRef?.(port); }, [props.viewportRef]);
 return <BabylonSceneViewport {...props} viewportRef={probe} />;
}''')
        page_file = app / 'app/pages/projects/[projectId]/scenes/[sceneId].jsx'
        page_file.write_text(page_file.read_text().replace("import { BabylonSceneViewport } from '@yellow-goose/babylon/multi';",
                             "import { Probe as BabylonSceneViewport } from '../../../../components/Probe';"))
        result = subprocess.run(['npm', 'run', 'build'], cwd=app, capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        with (app / 'server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=app,
                                      env=fixture.env, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        urlopen(base, timeout=1).close()
                        break
                    except URLError:
                        time.sleep(.05)
                with sync_playwright() as pw:
                    options = {'headless': True, 'args': ['--enable-unsafe-swiftshader', '--use-angle=swiftshader']}
                    if os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE'):
                        options['executable_path'] = os.environ['PLAYWRIGHT_CHROMIUM_EXECUTABLE']
                    browser = pw.chromium.launch(**options)
                    try:
                        checks(browser, fixture, owner, base, scene, project, doc['sceneId'], version)
                    finally:
                        browser.close()
            except BaseException:
                log.flush(); log.seek(0); print(log.read()[-3000:])
                raise
            finally:
                server.terminate(); server.wait(timeout=10)


def login(page, base, password):
    page.goto(base + '/login')
    page.get_by_label('Login', exact=True).fill('editor')
    page.get_by_label('Password', exact=True).fill(password)
    page.get_by_role('button', name='Sign in', exact=True).click()
    expect(page.get_by_role('heading', name='Projects', exact=True)).to_be_visible()


def saved(page):
    expect(page.locator('[data-yg-scene-editor]')).to_have_attribute('data-status', 'saved', timeout=15000)


def state(page):
    return page.evaluate('window.c1Port.viewState()')


def checks(browser, fixture, owner, base, scene, project, scene_id, version):
    url = base + f'/projects/{project}/scenes/{scene_id}'
    static = browser.new_context(java_script_enabled=False)
    static.add_cookies([{'name': key, 'value': value, 'url': base} for key, value in owner.cookies.items()])
    p = static.new_page(); p.goto(url)
    expect(p.locator('#app')).to_have_attribute('data-render-mode', 'ssr')
    expect(p.get_by_role('heading', name='My garden')).to_be_visible()
    static.close()
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    p = context.new_page(); errors = []; downloads = []
    p.on('pageerror', lambda error: errors.append(str(error)))
    p.on('request', lambda request: downloads.append(request.url) if '/model?' in request.url else None)
    login(p, base, fixture.password)
    p.get_by_label('Project name', exact=True).fill('Created in the browser')
    p.get_by_role('button', name='Create project', exact=True).click()
    expect(p.get_by_role('heading', name='Created in the browser', exact=True)).to_be_visible()
    p.get_by_label('Scene name', exact=True).fill('Created scene')
    p.get_by_role('button', name='Create scene', exact=True).click()
    expect(p.get_by_role('heading', name='Created scene', exact=True)).to_be_visible()
    p.goto(url)
    expect(p.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'ready', timeout=30000)
    p.get_by_role('button', name='Add model', exact=True).click(); saved(p)
    expect(p.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'ready')
    p.get_by_role('button', name='Add model', exact=True).click(); saved(p)
    p.wait_for_function('window.c1Port.viewState().entities.filter(e=>e.geometryIds.length).length===2')
    assert len(downloads) == 1, downloads
    entities = state(p)['entities']; assert entities[0]['geometryIds'] == entities[1]['geometryIds']
    p.get_by_label('Position X (m)', exact=True).fill('4.25')
    p.get_by_label('Rotation Y (°)', exact=True).fill('90')
    p.get_by_label('Scale Z', exact=True).fill('1.5')
    p.get_by_role('button', name='Apply changes', exact=True).click(); saved(p)
    p.get_by_role('button', name='Add garage', exact=True).click(); saved(p)
    p.get_by_label('Garage height (cm)', exact=True).fill('213')
    p.get_by_label('Position Z (m)', exact=True).fill('-3.5')
    p.get_by_role('button', name='Apply changes', exact=True).click(); saved(p)
    p.wait_for_function('window.c1Port.viewState().entities.length===3')
    garage = next(e for e in state(p)['entities'] if e['assetVersionId'] is None)
    assert abs(garage['dimensionsMeters'][1] - 2.13) < .00001, garage
    confirmed = owner.send(scene)
    assert confirmed['entities'][2]['properties']['heightMm'] == 2130
    assert confirmed['entities'][1]['transform']['position'][0] == 4.25
    p.reload(); expect(p.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'ready', timeout=30000)
    p.locator('[data-entity-id="' + garage['entityId'] + '"]').click()
    expect(p.get_by_label('Garage height (cm)', exact=True)).to_have_value('213')
    p.get_by_role('button', name='Hide', exact=True).click(); saved(p)
    p.get_by_role('button', name='Undo', exact=True).click(); saved(p)
    expect(p.get_by_role('button', name='Hide', exact=True)).to_be_visible()
    p.get_by_role('button', name='Redo', exact=True).click(); saved(p)
    expect(p.get_by_role('button', name='Show', exact=True)).to_be_visible()
    p.get_by_role('button', name='Show', exact=True).click(); saved(p)
    p.get_by_role('button', name='Duplicate', exact=True).click(); saved(p)
    assert len(owner.send(scene)['entities']) == 4
    p.get_by_role('button', name='Delete', exact=True).click(); saved(p)
    assert len(owner.send(scene)['entities']) == 3
    # Lose the response after PHP commits. Retrying must replay the same receipt.
    def lose(route):
        route.fetch(); route.abort('failed')
    p.route('**/commands', lose, times=1)
    before = owner.send(scene)['revision']
    p.get_by_role('button', name='Add garage', exact=True).click()
    expect(p.get_by_role('button', name='Retry save', exact=True)).to_be_visible()
    p.get_by_role('button', name='Retry save', exact=True).click(); saved(p)
    assert owner.send(scene)['revision'] == before + 1
    # Two independently rendered tabs start at the same revision.
    second = context.new_page(); second.goto(url)
    expect(second.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'ready', timeout=30000)
    p.get_by_role('button', name='Add garage', exact=True).click(); saved(p)
    second.get_by_role('button', name='Add garage', exact=True).click()
    expect(second.get_by_role('button', name='Reload server version', exact=True)).to_be_visible()
    count = len(owner.send(scene)['entities'])
    assert second.locator('[data-entity-id]').count() == count
    second.get_by_role('button', name='Retry on latest revision', exact=True).click(); saved(second)
    assert len(owner.send(scene)['entities']) == count + 1
    p.get_by_role('button', name='Add garage', exact=True).click()
    expect(p.get_by_role('button', name='Reload server version', exact=True)).to_be_visible()
    p.get_by_role('button', name='Reload server version', exact=True).click(); saved(p)
    assert p.locator('[data-entity-id]').count() == count + 1
    # Inspector drafts invoke the shared leave guard. Cancel and discard preserve server revision.
    p.locator('[data-entity-id]').first.click()
    p.get_by_label('Name', exact=True).fill('Unsaved name')
    p.get_by_role('link', name='Back to project', exact=True).click()
    dialog = p.get_by_role('dialog', name='Leave this scene?')
    expect(dialog).to_be_visible(); dialog.get_by_role('button', name='Cancel', exact=True).click()
    expect(p.get_by_label('Name', exact=True)).to_have_value('Unsaved name')
    p.get_by_role('link', name='Back to project', exact=True).click()
    dialog.get_by_role('button', name='Discard', exact=True).click()
    expect(p.get_by_role('heading', name='Browser plot', exact=True)).to_be_visible()
    assert p.evaluate('window.c1Port===null'), 'Unmounted viewport was retained'
    p.goto(url); p.locator('[data-entity-id]').first.click()
    p.get_by_label('Name', exact=True).fill('Saved before leaving')
    p.get_by_role('link', name='Back to project', exact=True).click()
    p.get_by_role('dialog').get_by_role('button', name='Save', exact=True).click()
    expect(p.get_by_role('heading', name='Browser plot', exact=True)).to_be_visible()
    assert owner.send(scene)['entities'][0]['name'] == 'Saved before leaving'
    p.goto(url)
    expect(p.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'ready', timeout=30000)
    p.locator('[data-entity-id]').first.click()
    p.get_by_role('button', name='Frame selection', exact=True).click()
    target = state(p)['entities'][0]['screenCenter']; canvas = p.locator('canvas').bounding_box()
    p.mouse.click(canvas['x'] + target[0] * canvas['width'], canvas['y'] + target[1] * canvas['height'])
    assert state(p)['selected'] is not None
    p.evaluate("window.c1LostContext=document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context'); window.c1LostContext.loseContext()")
    expect(p.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'lost')
    p.evaluate('window.c1LostContext.restoreContext()')
    expect(p.locator('[data-yg-scene-viewport]')).to_have_attribute('data-yg-scene-viewport', 'ready', timeout=15000)
    Path(ROOT / 'test-results').mkdir(exist_ok=True)
    p.screenshot(path=str(ROOT / 'test-results/scene-editor-desktop.png'), full_page=True)
    p.set_viewport_size({'width': 390, 'height': 844})
    assert p.evaluate('document.documentElement.scrollWidth<=window.innerWidth'), 'Mobile horizontal overflow'
    p.screenshot(path=str(ROOT / 'test-results/scene-editor-mobile.png'), full_page=True)
    # Read-only membership is enforced before any optimistic edits and on the PHP endpoint.
    with sqlite3.connect(fixture.data / 'studio.sqlite') as db:
        db.execute("INSERT INTO scene_studio_accounts SELECT 'browser-viewer','browser-viewer',password_hash,1 FROM scene_studio_accounts WHERE id='demo-editor'")
    owner.send(f'/api/projects/{project}/members/browser-viewer', {'role': 'viewer'}, 'PUT')
    viewer = Session(fixture).login('browser-viewer')
    read_context = browser.new_context()
    read_context.add_cookies([{'name': key, 'value': value, 'url': base} for key, value in viewer.cookies.items()])
    read_page = read_context.new_page(); read_page.goto(url)
    expect(read_page.get_by_role('button', name='Add garage', exact=True)).to_be_disabled()
    read_page.locator('[data-entity-id]').first.click()
    expect(read_page.get_by_label('Position X (m)', exact=True)).to_be_disabled()
    expect(read_page.get_by_role('button', name='Delete', exact=True)).to_be_disabled()
    read_context.close()
    assert not errors, errors
    context.close()
    print('PASS: C1 WebGL instances, units, persistence, undo/redo, response loss, two tabs and leave guards')


if __name__ == '__main__':
    run()
