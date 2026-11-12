import Layout from '../../../components/Layout';
import PostForm from '../../../components/PostForm';
export default function EditPost({ post, csrfToken }) {
    return <Layout><a href={'/posts/' + post.id}>← Wróć do wpisu</a>
        <p className="eyebrow">Popraw i rozwiń</p><h1>Edytuj wpis</h1>
        <PostForm post={post} csrfToken={csrfToken} /></Layout>;
}
