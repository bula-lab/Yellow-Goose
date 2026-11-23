import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { Frame, post } from '../components/Frame';

export default function Login({ csrfToken }) {
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    async function signIn(event) {
        event.preventDefault();
        if (busy) return;
        setBusy(true); setError('');
        try {
            await post('/api/auth/login', Object.fromEntries(new FormData(event.currentTarget)), csrfToken);
            await navigate('/');
        } catch (failure) { setError(failure.message); }
        finally { setBusy(false); }
    }
    return <Frame><section className="landing"><h1>Sign in</h1><form onSubmit={signIn}>
        <label>Login<input name="login" autoComplete="username" required /></label>
        <label>Password<input type="password" name="password" autoComplete="current-password" required /></label>
        <button disabled={busy}>Sign in</button>{error && <p role="alert">{error}</p>}</form></section></Frame>;
}
