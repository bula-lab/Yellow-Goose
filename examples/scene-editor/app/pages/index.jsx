import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import Frame from '../components/Frame';
import { request } from '../components/http';
export default function Projects({projects, identity, csrfToken}) {
    const [error, setError] = useState('');
    async function create(event) {
        event.preventDefault();
        try {
            const project = await request('/api/projects', Object.fromEntries(new FormData(event.currentTarget)), csrfToken);
            await navigate('/projects/' + encodeURIComponent(project.projectId));
        } catch (failure) { setError(failure.message); }
    }
    return <Frame><h1>Projects</h1>{identity ? <><form onSubmit={create}><label>Project name<input name="name" required maxLength="160" /></label>
        <button>Create project</button></form><ul>{projects.map(project => <li key={project.projectId}><a href={'/projects/' + encodeURIComponent(project.projectId)}>{project.name}</a> ({project.role})</li>)}</ul></>
        : <p><a href="/login">Sign in</a> to create and edit your projects.</p>}{error && <p role="alert">{error}</p>}</Frame>;
}
