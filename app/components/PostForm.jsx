import { useState } from 'react';
import { api } from '../lib/api';

export default function PostForm({ post, csrfToken }) {
    const [title, setTitle] = useState(post?.title || '');
    const [content, setContent] = useState(post?.content || '');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    async function submit(event) {
        event.preventDefault();
        if (saving) return;
        setSaving(true);
        setErrors({});
        setError('');
        try {
            const result = await api(post ? '/api/posts/' + post.id : '/api/posts', {
                method: post ? 'PATCH' : 'POST', data: { title, content }, csrfToken,
            });
            window.location.assign('/posts/' + result.post.id + '?saved=1');
        } catch (error) {
            setErrors(error.errors || {});
            setError(error.message);
            setSaving(false);
        }
    }
    return (
        <form onSubmit={submit} noValidate>
            {error && <div role="alert" className="error">{error}</div>}
            <label htmlFor="title">Tytuł</label>
            <input id="title" name="title" value={title} onChange={e => setTitle(e.target.value)}
                maxLength={200} required disabled={saving} aria-invalid={Boolean(errors.title)}
                aria-describedby={errors.title ? 'title-error' : undefined} />
            {errors.title && <p id="title-error" className="field-error">{errors.title}</p>}
            <label htmlFor="content">Treść</label>
            <textarea id="content" name="content" rows={10} value={content} onChange={e => setContent(e.target.value)}
                maxLength={10000} required disabled={saving} aria-invalid={Boolean(errors.content)}
                aria-describedby={errors.content ? 'content-error' : undefined} />
            {errors.content && <p id="content-error" className="field-error">{errors.content}</p>}
            <div className="actions">
                <button type="submit" disabled={saving}>{saving ? 'Zapisywanie…' : 'Zapisz wpis'}</button>
                <a href={post ? '/posts/' + post.id : '/'}>Anuluj</a>
            </div>
        </form>
    );
}
