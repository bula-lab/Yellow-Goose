import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import Frame from '../components/Frame';
import { post } from '../components/http';

export default function Login({ csrfToken }) {
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    async function submit(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget));
        setBusy(true);
        try {
            await post('/api/auth/login', data, csrfToken);
            await navigate('/editor');
        } catch (failure) { setError(failure.message); }
        finally { setBusy(false); }
    }
    return <Frame><main><h1>Editor sign in</h1><form onSubmit={submit} className="stack narrow">
        <label>Login<input name="login" autoComplete="username" required /></label>
        <label>Password<input type="password" name="password" autoComplete="current-password" required /></label>
        {error && <p role="alert">{error}</p>}<button disabled={busy}>Sign in</button>
    </form></main></Frame>;
}
