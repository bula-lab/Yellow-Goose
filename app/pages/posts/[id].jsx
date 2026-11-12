import { useState } from 'react';
import Layout from '../../components/Layout';
import { api } from '../../lib/api';
export default function PostPage({ post, csrfToken, saved = false }) {
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState('');
    async function remove() {
        if (deleting || !window.confirm('Usunąć ten wpis? Tej operacji nie można cofnąć.')) return;
        setDeleting(true); setError('');
        try {
            await api('/api/posts/' + post.id, { method: 'DELETE', csrfToken });
            window.location.assign('/?deleted=1');
        } catch (error) { setError(error.message); setDeleting(false); }
    }
    return <Layout>
        <a href="/">← Wszystkie wpisy</a>
        <p className="eyebrow">Wpis · {post.created_at}</p>
        <h1>{post.title}</h1>
        {saved &&
            <p role="status" className="success">Wpis został zapisany.</p>}
        <div className="post-content">{post.content}</div>
        {error && <p role="alert" className="error">{error}</p>}
        <div className="actions"><a className="button" href={'/posts/' + post.id + '/edit'}>Edytuj wpis</a>
            <button className="danger" onClick={remove} disabled={deleting}>{deleting ? 'Usuwanie…' : 'Usuń wpis'}</button>
        </div>
    </Layout>;
}
