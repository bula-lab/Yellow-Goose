import { useState } from 'react';
import Layout from '../components/Layout';
import { api } from '../lib/api';

export default function HomePage({ posts, pagination, deleted = false }) {
    const [time, setTime] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    async function checkApi() {
        setLoading(true); setError('');
        try { setTime((await api('/api/hello')).time); }
        catch (error) { setError(error.message); }
        finally { setLoading(false); }
    }
    return (
        <Layout>
            <p className="eyebrow">Twoje miejsce na pomysły</p>
            <div className="heading-row"><h1>Wpisy</h1><span className="count">{pagination.total}</span></div>
            <p className="intro">Zapisuj, rozwijaj i porządkuj swoje notatki.</p>
            {deleted &&
                <p role="status" className="success">Wpis został usunięty.</p>}
            {posts.length === 0 ? (
                <section className="empty"><h2>Tu pojawią się Twoje wpisy</h2>
                    <p>Zacznij od pierwszego pomysłu.</p><a className="button" href="/posts/new">Dodaj pierwszy wpis</a></section>
            ) : (
                <div className="post-list">{posts.map(post => (
                    <article className="post-card" key={post.id}>
                        <time>{post.created_at}</time>
                        <h2><a href={'/posts/' + post.id}>{post.title}</a></h2>
                        <p>{post.content.slice(0, 180)}{post.content.length > 180 ? '…' : ''}</p>
                        <a className="text-link" href={'/posts/' + post.id}>Otwórz wpis →</a>
                    </article>
                ))}</div>
            )}
            {pagination.pages > 1 && <nav className="pagination" aria-label="Strony wpisów">
                {pagination.page > 1 && <a href={'/?page=' + (pagination.page - 1)}>← Poprzednia</a>}
                <span>Strona {pagination.page} z {pagination.pages}</span>
                {pagination.page < pagination.pages && <a href={'/?page=' + (pagination.page + 1)}>Następna →</a>}
            </nav>}
            <details className="api-check"><summary>Sprawdź połączenie z serwerem</summary>
                <button className="secondary" onClick={checkApi} disabled={loading}>
                    {loading ? 'Pobieranie…' : 'Pobierz czas z serwera (API)'}
                </button>
                {time && <p role="status">Czas z API: {time}</p>}
                {error && <p role="alert" className="error">{error}</p>}
            </details>
        </Layout>
    );
}
