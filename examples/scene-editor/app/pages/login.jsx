import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import Frame from '../components/Frame';
import { request } from '../components/http';
export default function Login({csrfToken}) {
    const [error, setError] = useState('');
    async function submit(event) {
        event.preventDefault();
        try { await request('/api/auth/login', Object.fromEntries(new FormData(event.currentTarget)), csrfToken); await navigate('/'); }
        catch (failure) { setError(failure.message); }
    }
    return <Frame><h1>Scene studio sign in</h1><form onSubmit={submit}><label>Login<input name="login" autoComplete="username" required /></label>
        <label>Password<input name="password" type="password" autoComplete="current-password" required /></label>
        <button>Sign in</button>{error && <p role="alert">{error}</p>}</form></Frame>;
}
