import { createContext, useContext, useEffect, useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { WorkspaceShell } from '@yellow-goose/react/workspace';
import { DataTable, Dialog } from '@yellow-goose/ui';
import { estimatedServerTime, useStrategyGame } from '@yellow-goose/game-strategy';
import { Frame } from '../../components/Frame';
import { ColonyMap } from '../../components/ColonyMap';

const GameContext = createContext(null);
const panels = ['overview', 'buildings', 'leaderboard'];
const reason = { season_closed: 'Season closed', insufficient_time: 'Season ends before construction can finish',
    build_in_progress: 'Construction in progress', insufficient_resources: 'More alloy required' };
const connectionText = { online: 'Connected', connecting: 'Synchronizing', offline: 'Offline',
    paused: 'Paused', reconnecting: 'Connection lost · retrying', stopped: 'Paused' };

function url(gameId, panel, target) {
    const path = '/games/' + encodeURIComponent(gameId) + '?panel=' + encodeURIComponent(panel);
    return target ? path + '&dialog=build&target=' + encodeURIComponent(target) : path;
}
function label(text) { return text.charAt(0).toUpperCase() + text.slice(1); }
function utc(value) { return new Date(value * 1000).toISOString().replace('T', ' ').slice(0, 19) + ' UTC'; }
function activeQuote(snapshot, target) { return snapshot.quotes.find(offer => offer.target === target); }

function Construction({ state }) {
    const [, tick] = useState(0);
    const operations = state.snapshot.operations;
    useEffect(() => {
        if (!operations.length || state.connection === 'paused') return;
        const timer = setInterval(() => tick(n => n + 1), 1000);
        return () => clearInterval(timer);
    }, [operations.length, state.connection]);
    const now = estimatedServerTime(state);
    return <section><h2>Construction</h2>{operations.length ? <ul>{operations.map(op => {
        const seconds = Math.max(0, op.completesAt - now);
        return <li data-testid="operation" key={op.operationId}><strong>{label(op.target)} · level {op.level}</strong>
            <span>{seconds ? seconds + 's remaining' : 'Awaiting server confirmation'}</span></li>;
    })}</ul> : <p>No active construction.</p>}</section>;
}

function Quotes({ snapshot, panel, enabled }) {
    const targets = new Set([...snapshot.quotes.map(q => q.target), ...Object.keys(snapshot.levels)]);
    return <section><h2>Buildings</h2><ul>{[...targets].map(target => {
        const quote = activeQuote(snapshot, target);
        return <li key={target}>
            <div><strong>{label(target)}</strong><small>Level {snapshot.levels[target] || 0}{quote && ' → ' + quote.level}</small></div>
            {enabled && quote?.available ? <a aria-haspopup="dialog" href={url(snapshot.gameId, panel, target)}>Build {target}</a>
                : <small>{quote ? (reason[quote.reason] || 'Unavailable') : 'Fully upgraded'}</small>}
        </li>;
    })}</ul></section>;
}
function Rewards({ snapshot, enabled, store }) {
    return <section><h2>Rewards</h2>{snapshot.rewards.length ? <ul>{snapshot.rewards.map(r => <li key={r.rewardId}>
        <div><strong>{label(r.target)}</strong><small>+{r.resources} alloy · +{r.points} points</small></div>
        <button disabled={!enabled} onClick={() => store.enqueue('reward.claim', { rewardId: r.rewardId }, snapshot.revision, snapshot.rulesVersion)}>Claim reward</button>
    </li>)}</ul> : <p>No rewards to claim.</p>}</section>;
}
function Pending({ state, store }) {
    const item = state.queue[0];
    if (!item) return state.error ? <p role="alert">{state.error}</p> : null;
    const { type, payload } = item.envelope;
    const q = type === 'build.start' && activeQuote(state.snapshot, payload.target);
    const reward = type === 'reward.claim' && state.snapshot.rewards.find(r => r.rewardId === payload.rewardId);
    if (item.status === 'conflict') return <div className="notice" role="alert">
        <strong>Review the updated order.</strong>
        <p>{q ? label(q.target) + ' level ' + q.level + ' · ' + q.cost + ' alloy · ' + q.duration + 's · ' + q.points + ' points'
            : reward ? label(reward.target) + ' · +' + reward.resources + ' alloy · +' + reward.points + ' points' : 'This order is no longer available.'}</p>
        <button disabled={state.connection !== 'online' || !(q?.available || reward)} onClick={() => store.retryReviewed(state.snapshot.revision, state.snapshot.rulesVersion)}>Confirm updated order</button>
        <button onClick={store.discardConflict}>Dismiss order</button></div>;
    if (item.status === 'uncertain') return <div className="notice" role="status">Order receipt missing.
        <button disabled={state.connection !== 'online'} onClick={store.retry}>Retry same order</button></div>;
    return <p role="status">Sending order…</p>;
}

function Layout({ snapshot: initial, csrfToken, panel = 'overview', children }) {
    const [state, store] = useStrategyGame(initial, csrfToken);
    const snapshot = state.snapshot;
    if (!snapshot) return <Frame><section className="landing"><h1>Colony access ended</h1><a href="/login">Sign in</a></section></Frame>;
    return <Frame identity={snapshot.displayName}><WorkspaceShell className="game">
        <header className="game-heading"><div><div className="season">{snapshot.seasonId}</div><h1>{snapshot.displayName}</h1></div>
            <div className="status"><span data-testid="connection" data-state={state.connection}>{connectionText[state.connection]}</span>
                {state.connection === 'reconnecting' && <button onClick={store.refresh}>Reconnect</button>}
                <small>Revision <span data-testid="revision">{snapshot.revision}</span></small></div></header>
        <dl className="metrics"><div><dt>Alloy</dt><dd>{snapshot.resources}<small> / {snapshot.capacity}</small></dd></div>
            <div><dt>Production</dt><dd>+{snapshot.perSecond}<small> / sec</small></dd></div>
            <div><dt>Season points</dt><dd data-testid="points">{snapshot.points}</dd></div></dl>
        <div className="workspace"><ColonyMap levels={snapshot.levels}/>
            <aside><nav className="tabs" aria-label="Colony panels">{panels.map(p => <a href={url(snapshot.gameId, p)}
                key={p} aria-current={p === panel ? 'page' : undefined}>{label(p)}</a>)}</nav>
            <GameContext.Provider value={{ state, store }}><Pending state={state} store={store}/>{children}</GameContext.Provider></aside>
        </div></WorkspaceShell></Frame>;
}

const rankingFields = { rank: { label: 'Rank' }, displayName: { label: 'Colony' }, points: { label: 'Points' } };
export default function Colony({ panel = 'overview', dialog, target }) {
    const { state, store } = useContext(GameContext);
    const snapshot = state.snapshot;
    const q = activeQuote(snapshot, target);
    const enabled = state.connection === 'online' && !state.queue.length;
    const close = () => { void navigate(url(snapshot.gameId, panel)).catch(() => {}); };
    function confirm() {
        if (q?.available && enabled && store.enqueue('build.start', { target: q.target }, snapshot.revision, snapshot.rulesVersion)) close();
    }
    return <>
        {panel === 'leaderboard' ? <section><h2>Season leaderboard</h2><DataTable fields={rankingFields} limit={5}
            endpoint={'/api/leaderboards/' + encodeURIComponent(snapshot.seasonId)} rowKey="actorId"
            caption={snapshot.seasonId} onAccessDenied={store.revoke}
            renderPageInfo={page => page.asOf != null && <small>As of {utc(page.asOf)}</small>}/></section>
            : <>{panel === 'overview' && <Construction state={state}/>}<Quotes panel={panel} snapshot={snapshot} enabled={enabled}/>
                {panel === 'overview' && <Rewards snapshot={snapshot} enabled={enabled} store={store}/>}</>}
        <Dialog open={dialog === 'build'} onClose={close} title={q ? 'Build ' + q.target : 'Construction unavailable'}>
            {q ? <><p>{label(q.target)} · level {q.level}</p><dl className="offer"><div><dt>Cost</dt><dd>{q.cost} alloy</dd></div>
                <div><dt>Construction time</dt><dd>{q.duration}s</dd></div><div><dt>Completion points</dt><dd>{q.points}</dd></div></dl>
                {!q.available && <p>{reason[q.reason] || 'Construction unavailable'}</p>}
                <button disabled={!enabled || !q.available} onClick={confirm}>Confirm construction</button></>
                : <p>This site has no available upgrade.</p>}
        </Dialog>
    </>;
}
Colony.getLayout = (page, props) => <Layout key={props.snapshot.gameId} {...props}>{page}</Layout>;
