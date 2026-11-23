import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { Frame, post } from '../components/Frame';

export default function Home({ identity, games = [], seasons = [], csrfToken }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const open = seasons.filter(s => !games.some(g => g.seasonId === s.seasonId));
    async function join(event) {
        event.preventDefault();
        if (busy) return;
        setError(''); setBusy(true);
        try {
            const data = Object.fromEntries(new FormData(event.currentTarget));
            const result = await post('/api/games', data, csrfToken);
            await navigate('/games/' + encodeURIComponent(result.gameId));
        } catch (failure) { setError(failure.message); }
        finally { setBusy(false); }
    }
    return <Frame identity={identity}><section className="landing"><h1>Your colonies</h1>
        {identity ? <><ul className="colony-list">{games.map(game => <li key={game.gameId}>
            <a href={'/games/' + encodeURIComponent(game.gameId)}>{game.displayName}</a><small>{game.seasonId}</small>
        </li>)}</ul>{open.length > 0 && <form onSubmit={join}><h2>Establish a colony</h2>
            <label>Season<select name="seasonId">{open.map(s => <option key={s.seasonId} value={s.seasonId}>{s.name}</option>)}</select></label>
            <label>Colony name<input name="displayName" required maxLength="60" /></label>
            <button disabled={busy}>Join season</button></form>}
            {!games.length && !open.length && <p>No open seasons.</p>}</>
        : <><p>Sign in to establish a colony and compete in the season rankings.</p><a className="cta" href="/login">Sign in</a></>}
        {error && <p role="alert">{error}</p>}</section></Frame>;
}
