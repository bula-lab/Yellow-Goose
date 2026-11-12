import Layout from '../../components/Layout';
import PostForm from '../../components/PostForm';
export default function NewPost({ csrfToken }) {
    return <Layout><a href="/">← Wszystkie wpisy</a><p className="eyebrow">Nowy pomysł</p>
        <h1>Dodaj wpis</h1><PostForm csrfToken={csrfToken} /></Layout>;
}
