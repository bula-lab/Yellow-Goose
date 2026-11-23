# @yellow-goose/game-strategy

The browser client reads colony snapshots and events over HTTP and submits revision-checked commands. The PHP game service owns resources, construction, rewards and ranking.

## React

Mount the hook in a layout keyed by `initialSnapshot.gameId`. Panels and dialogs in that layout share the same command queue and polling connection.

```jsx
import { useStrategyGame } from '@yellow-goose/game-strategy';

function Upgrade({ initialSnapshot, csrfToken }) {
    const [state, game] = useStrategyGame(initialSnapshot, csrfToken);
    const quote = state.snapshot?.quotes.find(q => q.target === 'mine');
    return <button disabled={state.connection !== 'online' || state.queue.length > 0 || !quote?.available}
        onClick={() => game.enqueue('build.start', { target: quote.target }, state.snapshot.revision, state.snapshot.rulesVersion)}>
        Build mine for {quote?.cost} alloy
    </button>;
}
```

The initial snapshot comes from the PHP loader. The hook rechecks access on mount, reconnection and return to a visible tab. A missing game or denied request clears the game state. Offline and hidden tabs pause requests. Unmounting aborts requests and removes the window and document listeners.

## Standalone store

Import `createStrategyStore`, `createStrategyClient` and `estimatedServerTime` from `@yellow-goose/game-strategy/store` for a consumer without React.

```js
const client = createStrategyClient(initial.gameId, { csrfToken: () => token });
const game = createStrategyStore(initial, client);
const unsubscribe = game.subscribe(() => render(game.getState()));
game.setMode('online');

// On teardown
game.setMode('stopped');
unsubscribe();
```

`setMode` accepts `online`, `paused`, `offline` and `stopped`. The default poll interval is three seconds. Each HTTP request has a twelve-second timeout. Failed reads back off to thirty seconds. `refresh()` requests an earlier read. `estimatedServerTime(state)` uses the last server timestamp and elapsed monotonic time for countdowns.

`enqueue(type, payload, expectedRevision, expectedRulesVersion)` freezes the order and returns whether the store accepted it. Pass the game revision and rules version from the snapshot that supplied the displayed offer. The store preserves an unacknowledged order after a timeout, disconnect or event cursor reset. `retry()` replays its original identifier, game revision, rules version and payload. The React hook saves pending envelopes in session storage when available. Restored orders wait for confirmation.

A change to the game revision or the pricing rules triggers a conflict and a fresh snapshot. Display the updated offer before calling `retryReviewed(revision, rulesVersion)`. This creates a new identifier for the reviewed order. `discardConflict()` drops the rejected intent. Ordinary rule failures appear in `state.error`. Only a server snapshot changes the displayed economy, buildings or rewards.
