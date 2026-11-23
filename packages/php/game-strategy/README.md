# yellow-goose/game-strategy

This PHP 8.1+ package runs server-timed builds with one integer resource balance per player and season. It provides command receipts, a delayed PHP worker, an HTTP event feed and seasonal standings. Install a SQLite or MySQL PDO driver. The application supplies an authenticated identity and implements `ZoltaGaska\Strategy\Rules`. The playable example is in `examples/strategy-game`.

## Registration

```php
use ZoltaGaska\Jobs\{JobsModule, Queue};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Strategy\{StrategyModule, StrategyService};

$queue = new Queue($pdo);
$game = new StrategyService($queue, $rules);
$registry = Registry::boot([
    new JobsModule($queue, $game->jobHandlers()),
    new StrategyModule($game, $identityProvider),
]);
```

Pass the registry migration directories to the application migrator. Protect the kernel with `RequestAuthorizer`, `Gate` and the application's CSRF guard. When other modules use the same queue, register their handlers in the same `JobsModule`.

Create seasons from application setup or the CLI with `createSeason($id, $startsAt, $endsAt)`. All times are Unix seconds. Repeating the same definition succeeds. Season windows are immutable and cannot overlap. Exactly adjacent windows are allowed. `seasons()` lists active windows. A player can join once per season and read their game after the season ends.

## Rules and resources

| Method | Return value |
| --- | --- |
| `version()` | Identifies an immutable ruleset. Change the version whenever gameplay rules, quotes or payouts change |
| `economy()` | Integer `initial`, `capacity` and `perSecond`, copied when the player joins |
| `targets()` | Distinct target IDs for this application |
| `build($target, $level)` | Quote for the next level, starting at 1. Return `null` at the level cap |

A build quote has integer fields `cost`, `duration` in seconds, `points`, `rewardResources` and `rewardPoints`. Amounts range from 0 to 2,147,483,647. Duration must be positive. Concurrent builds can use different targets. A target can have only one pending level in a game. The server stores the price, deadline, rules version and all completion and reward amounts at the start. A rule change affects future quotes.

Production credits complete seconds at the stored rate, up to capacity. The effective balance is the saved balance plus production since its checkpoint, capped at capacity and at season end. Commands that spend or credit resources update both the balance and its checkpoint. Polls between commands do not write production ticks. Reward claims credit up to the remaining capacity and grant their stored points once.

A season starts inclusively and ends exclusively for new commands. A build may complete exactly at the end. Its completion points count in that season. Any unclaimed rewards expire at season end, including rewards from a build completing at that exact second.

## HTTP API

Every route requires authentication. Game routes require the game's current owner. Standings require a game in the requested season. Mutating requests also require a valid CSRF token.

| Route | Result |
| --- | --- |
| `GET /api/games` | `items` with the player's game IDs, seasons and display names |
| `POST /api/games` | Join using `seasonId` and `displayName`. Returns the snapshot with HTTP 201. Rejoining returns the same game |
| `GET /api/games/{gameId}` | Snapshot with server time, balance, points, completed levels, build quotes, pending operations, claimable rewards, revision and event cursor |
| `POST /api/games/{gameId}/commands` | Command acknowledgement with `commandId`, `revision` and `cursor` |
| `GET /api/games/{gameId}/events?cursor=0&limit=50` | Owner-scoped committed events in revision order |
| `GET /api/leaderboards/{seasonId}?limit=25` | Standings page with `items`, `asOf`, `nextCursor` and `reset` |

```json
{"commandId":"build-73","type":"build.start","expectedRevision":0,"expectedRulesVersion":"v1","payload":{"target":"mine"}}
```

Use `reward.claim` with `payload.rewardId` to claim a completed operation's reward. Every command includes `expectedRulesVersion` from the snapshot's `rulesVersion`. The server supplies timestamps, price, score and reward amounts. A receipt is scoped to the game. An exact retry under the current owner's identity returns the original acknowledgement even after a rules deployment, later commands or season closure.

A changed command with the same ID returns HTTP 409 and `errors.code=command_id_conflict`. A stale revision returns `revision_conflict`. An uncommitted command with an outdated rules version returns `rules_conflict`. Fetch the snapshot, show the updated quote and let the player confirm a new command with a new ID.

## Worker and polling

Cost, frozen quote, delayed queue entry, event and command receipt commit on the same PDO connection. Run `jobs:work [limit]` from cron or a supervised PHP worker. Its lease guards the completion transaction. Game reads and commands also reconcile pending operations by deadline and then operation ID. A delayed worker may find the operation already complete. A standings read reconciles all due operations in the season before computing the page.

A season row lock serializes updates and consistent reads within that season. Different seasons can proceed independently on MySQL. SQLite serializes writers at database level. During a worker outage, the first standings request processes the overdue operations in batches of 128 inside one transaction. Its latency depends on the outstanding backlog.

Start polling from the snapshot's `cursor`. Each event has `cursor`, `type`, `at` and `payload`. Types are `build.started`, `build.completed` and `reward.claimed`. A completion's `at` is its scheduled deadline even when the worker runs later. `hasMore` indicates another page is available. Limits range from 1 to 100. Each game retains its latest 256 events by default, configurable via the service constructor. For an expired or future cursor the feed returns `reset=true` and the latest cursor. Fetch a fresh snapshot and resume from that snapshot's cursor. Each poll checks current access.

Standings include every member, including players with zero points. Only committed completion and reward events award points. Players with equal points share a rank (1, 1, 3) and sort by actor ID. Page size is 1–100. Pass the opaque `nextCursor` as the next request's `cursor`. Each page shows the server time at which that read reconciled the season. If a player joined or points changed between pages, the response starts again at the first page with `reset=true`. Replace the displayed list with that page.
