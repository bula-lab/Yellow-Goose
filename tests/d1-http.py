"""Real requests prove lazy time settlement, current access and bounded seasonal standings."""
import argparse
import sqlite3
import time
from urllib.parse import quote
from d1_support import APP, StrategyFixture, Session


def run(ssr):
    with StrategyFixture(ssr) as fixture:
        player, rival, guest = Session(fixture).login(), Session(fixture).login('rival'), Session(fixture)
        guest.send('/api/games', expected=401)
        player.send('/api/games', {'seasonId': 'none', 'displayName': 'No CSRF'}, 'POST', expected=403, headers={'X-CSRF-Token': 'wrong'})
        colony, opponent = player.join('Kepler <script>test</script>'), rival.join('Altair')
        path = '/api/games/' + colony['gameId']
        enemy = '/api/games/' + opponent['gameId']
        season = colony['seasonId']
        assert colony['resources'] == 300 and colony['capacity'] == 1000 and colony['perSecond'] == 2
        player.send(enemy, expected=403)
        rival.send(path + '/events', expected=403)
        guest.send(path, expected=401)
        command = {'commandId': 'mine-start', 'type': 'build.start', 'expectedRevision': colony['revision'],
                   'expectedRulesVersion': colony['rulesVersion'], 'payload': {'target': 'mine'}}
        old_rules = player.send(path + '/commands', dict(command, expectedRulesVersion='old-demo'), 'POST', expected=409)
        assert old_rules['errors']['code'] == 'rules_conflict' and not player.send(path)['operations']
        player.send(path + '/commands', dict(command, payload={'target': 'mine', 'cost': 0, 'duration': 0, 'points': 1000000}), 'POST', expected=422)
        player.send(path + '/commands', dict(command, clientNow=9999999999), 'POST', expected=422)
        rival_command = dict(command, expectedRevision=opponent['revision'])
        receipt = player.send(path + '/commands', command, 'POST', headers={'X-Client-Time': '9999999999'})
        rival.send(enemy + '/commands', rival_command, 'POST')
        assert player.send(path + '/commands', command, 'POST') == receipt
        player.send(path + '/commands', dict(command, payload={'target': 'reactor'}), 'POST', expected=409)
        pending = player.send(path)
        assert len(pending['operations']) == 1 and pending['points'] == 0
        op = pending['operations'][0]
        assert op['completesAt'] - op['startedAt'] == 5 and op['cost'] == 100
        assert 200 <= pending['resources'] <= 212
        player.send(path + '/commands', dict(command, commandId='second-click', expectedRevision=pending['revision']), 'POST', expected=409)
        rival.send(path + '/commands', command, 'POST', expected=403)
        # No worker runs. Mark the pending task exhausted: GET must still settle the operation.
        with sqlite3.connect(fixture.data / 'colony.sqlite') as db:
            db.execute("UPDATE yg_jobs SET state='failed',attempts=3 WHERE job_type='strategy.complete'")
        rival_deadline = rival.send(enemy)['operations'][0]['completesAt']
        time.sleep(max(0, max(op['completesAt'], rival_deadline) - time.time() + .1))
        completed = player.send(path)
        assert completed['levels']['mine'] == 1 and completed['points'] == 10
        assert not completed['operations'] and len(completed['rewards']) == 1
        assert completed['serverNow'] >= op['completesAt']
        # A second read and a later worker cannot award completion again.
        fixture.cli('jobs:work', '10')
        assert player.send(path)['points'] == 10
        rival.send(enemy)
        ranking = '/api/leaderboards/' + season
        first = player.send(ranking + '?limit=1')
        assert first['items'][0]['rank'] == 1 and first['nextCursor']
        second = player.send(ranking + '?limit=1&cursor=' + quote(first['nextCursor']))
        assert second['items'][0]['rank'] == 1 and second['items'][0]['actorId'] != first['items'][0]['actorId']
        assert all(set(item) == {'actorId', 'displayName', 'rank', 'points'} for item in first['items'] + second['items'])
        player.send(ranking + '?limit=101', expected=422)
        claim = {'commandId': 'claim', 'type': 'reward.claim', 'expectedRevision': completed['revision'], 'expectedRulesVersion': completed['rulesVersion'],
                 'payload': {'rewardId': completed['rewards'][0]['rewardId']}}
        reward = player.send(path + '/commands', claim, 'POST')
        assert player.send(path + '/commands', claim, 'POST') == reward
        claimed = player.send(path)
        assert claimed['points'] == 12 and not claimed['rewards']
        player.send(path + '/commands', dict(claim, commandId='claim-again', expectedRevision=claimed['revision']), 'POST', expected=409)
        reset = player.send(ranking + '?limit=1&cursor=' + quote(first['nextCursor']))
        assert reset['reset'] is True and reset['items'][0]['points'] == 12
        feed = player.send(path + '/events?cursor=0&limit=2')
        assert len(feed['items']) <= 2 and feed['hasMore']
        assert all(event['cursor'] > 0 for event in feed['items'])
        for url in [f'/games/{colony["gameId"]}', f'/games/{colony["gameId"]}?panel=leaderboard']:
            html = player.send(url, raw=True)
            if ssr:
                assert b'data-render-mode="ssr"' in html and b'Kepler &lt;script&gt;test&lt;/script&gt;' in html
        with sqlite3.connect(fixture.data / 'colony.sqlite') as db:
            db.execute("UPDATE colony_accounts SET enabled=0 WHERE id='demo-player'")
        for url in [path, path + '/events', ranking]:
            player.send(url, expected=401)
        player.send(path + '/commands', command, 'POST', expected=401)
    print('PASS: D1 CGI/SSR, server time, costs, receipt replay, exhausted-worker catch-up, rewards, ties and cursor resets')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--no-ssr', action='store_true')
    run(not parser.parse_args().no_ssr)
