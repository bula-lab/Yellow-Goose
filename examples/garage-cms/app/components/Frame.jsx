import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { post } from './http';

export default function Frame({ children, editor = false, csrfToken, beforeSignOut }) {
    const [error, setError] = useState('');
    async function logout() {
        async function finish() {
            await post('/api/auth/logout', {}, csrfToken);
            await navigate('/');
        }
        try {
            if (beforeSignOut) await beforeSignOut(finish); else await finish();
        } catch (failure) { setError(failure.message); }
    }
    return <><header className="mast"><a className="wordmark" href="/">Garage studio</a><nav aria-label="Main">
        <a href="/">Catalog</a>{editor ? <><a href="/editor">Products</a><a href="/editor/materials">Materials</a>
            <button type="button" onClick={logout}>Sign out</button></> : <a href="/login">Editor sign in</a>}
    </nav></header>{error && <p role="alert">{error}</p>}{children}</>;
}
