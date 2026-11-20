"""Scene API, project authorization, actual assets, SSR and durable reopening."""
import argparse
import json
import sqlite3
from c1_support import APP, SceneFixture, Session, upload


def run(ssr):
    with SceneFixture(ssr) as fixture:
        owner, other, guest = Session(fixture).login(), Session(fixture).login(), Session(fixture)
        with sqlite3.connect(fixture.data / 'studio.sqlite') as db:
            db.execute("INSERT INTO scene_studio_accounts SELECT 'viewer','viewer',password_hash,1 FROM scene_studio_accounts WHERE id='demo-editor'")
            db.execute("INSERT INTO scene_studio_accounts SELECT 'foreign','foreign',password_hash,1 FROM scene_studio_accounts WHERE id='demo-editor'")
        viewer, foreign = Session(fixture).login('viewer'), Session(fixture).login('foreign')
        guest.send('/api/projects', expected=401)
        owner.send('/api/projects', {'name': 'No CSRF'}, 'POST', expected=403, headers={'X-CSRF-Token': 'wrong'})
        project = owner.send('/api/projects', {'name': 'My plot'}, 'POST', expected=201)['projectId']
        base = f'/api/projects/{project}/scenes'
        document = owner.send(base, {'name': 'Garden and garage'}, 'POST', expected=201)
        assert document['revision'] == 0 and document['coordinateSystem'] == {'handedness': 'right', 'up': 'Y', 'unit': 'm'}
        scene = base + '/' + document['sceneId']
        assert other.send(scene) == document
        foreign.send(scene, expected=403)
        version = upload(owner, project)
        add = {'commandId': 'add-model', 'entityId': 'garage-model', 'type': 'entity.add', 'expectedRevision': 0,
               'payload': {'type': 'model', 'assetVersionId': version, 'name': 'Garage model'}}
        owner.send(scene + '/commands', add, 'POST', expected=422)
        fixture.cli('jobs:work', '1')
        receipt = owner.send(scene + '/commands', add, 'POST')
        assert receipt['revision'] == 1
        assert owner.send(scene + '/commands', add, 'POST') == receipt
        owner.send(scene + '/commands', dict(add, payload={'name': 'Changed operation'}), 'POST', expected=409)
        stale = dict(add, commandId='stale', entityId='stale')
        conflict = other.send(scene + '/commands', stale, 'POST', expected=409)
        assert conflict['errors']['code'] == 'revision_conflict' and conflict['errors']['document']['revision'] == 1
        owner.send(scene + '/commands', dict(add, commandId='foreign-asset', entityId='foreign-asset', expectedRevision=1,
                   payload={'type': 'model', 'assetVersionId': 'f' * 32}), 'POST', expected=422)
        garage = dict(add, commandId='add-garage', entityId='garage', expectedRevision=1,
                      payload={'type': 'garage', 'name': 'Garage', 'properties': {'heightMm': 2130}})
        owner.send(scene + '/commands', garage, 'POST')
        move = dict(add, commandId='move', entityId='garage', type='entity.transform', expectedRevision=2,
                    payload={'position': [2.13, .5, -4.25], 'rotation': [0, 0, 0, 1], 'scale': [1.5, 1, 2]})
        moved = owner.send(scene + '/commands', move, 'POST')
        reopened = other.send(scene)
        assert reopened['revision'] == 3 and reopened['entities'][1]['transform']['position'] == [2.13, .5, -4.25]
        assert reopened['entities'][1]['properties']['heightMm'] == 2130
        undo = dict(moved['inverse'], commandId='undo-move', expectedRevision=3)
        assert owner.send(scene + '/commands', undo, 'POST')['revision'] == 4
        exported = owner.send(scene + '/export')
        assert 'modelUrl' not in json.dumps(exported) and 'sig=' not in json.dumps(exported)
        imported = owner.send(base + '/import', {'document': exported}, 'POST', expected=201)
        assert imported['sceneId'] != document['sceneId'] and imported['entities'] == exported['entities']
        foreign_project = foreign.send('/api/projects', {'name': 'Foreign'}, 'POST', expected=201)['projectId']
        foreign.send(f'/api/projects/{foreign_project}/scenes/import', {'document': exported}, 'POST', expected=422)
        owner.send(f'/api/projects/{project}/members/viewer', {'role': 'viewer'}, 'PUT')
        assert viewer.send(scene)['revision'] == 4
        viewer.send(scene + '/commands', dict(add, commandId='viewer-write', expectedRevision=4), 'POST', expected=403)
        page = f'/projects/{project}/scenes/{document["sceneId"]}'
        nav = viewer.send(page, headers={'X-YG-Navigation': '1'})
        assert nav['props']['readOnly'] is True and len(nav['props']['assets']) == 1
        html = owner.send(page, raw=True)
        if ssr:
            assert b'data-render-mode="ssr"' in html and b'Scene editor' in html and b'Scene poster' in html
            bundle = (APP / 'build/ssr/server.js').read_text()
            assert 'ArcRotateCamera' not in bundle and 'Babylon.js v' not in bundle and len(bundle) < 500000
        descriptor = viewer.send(f'/api/assets/projects/{project}/versions/{version}')
        owner.send(f'/api/projects/{project}/members/viewer', method='DELETE')
        viewer.send(scene, expected=403)
        viewer.send(descriptor['modelUrl'], expected=404)
        with sqlite3.connect(fixture.data / 'studio.sqlite') as db:
            assert db.execute('SELECT COUNT(*) FROM yg_scene_receipts WHERE scene_id=?', [document['sceneId']]).fetchone()[0] == 4
            assert db.execute('SELECT COUNT(*) FROM yg_scene_outbox WHERE scene_id=?', [document['sceneId']]).fetchone()[0] == 4
            db.execute("UPDATE scene_studio_accounts SET enabled=0 WHERE id='demo-editor'")
        owner.send(scene, expected=401)
        owner.send(scene + '/commands', add, 'POST', expected=401)
    print('PASS: C1 real CGI sessions, project policies, assets, receipts, conflicts, import/export, undo and SSR')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--no-ssr', action='store_true')
    run(not parser.parse_args().no_ssr)
