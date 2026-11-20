import { useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { uploadAsset } from '@yellow-goose/ui';
import Frame from '../../../components/Frame';
import { request } from '../../../components/http';
export default function Project({project, scenes, assets, csrfToken}) {
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    async function create(event) {
        event.preventDefault();
        try {
            const scene = await request('/api/projects/' + project.projectId + '/scenes', Object.fromEntries(new FormData(event.currentTarget)), csrfToken);
            await navigate('/projects/' + project.projectId + '/scenes/' + scene.sceneId);
        } catch (failure) { setError(failure.message); }
    }
    async function upload(event) {
        event.preventDefault(); setBusy(true);
        const fields = event.currentTarget.elements;
        try {
            await uploadAsset(project.projectId, fields.model.files[0], fields.poster.files[0], csrfToken);
            await navigate('/projects/' + project.projectId);
        } catch (failure) { setError(failure.message); }
        finally { setBusy(false); }
    }
    return <Frame><h1>{project.name}</h1><h2>Scenes</h2><ul>{scenes.map(scene => <li key={scene.sceneId}><a href={'/projects/' + project.projectId + '/scenes/' + scene.sceneId}>{scene.name}</a> — revision {scene.revision}</li>)}</ul>
        {project.role !== 'viewer' && <><form onSubmit={create}><label>Scene name<input name="name" required maxLength="160" /></label><button>Create scene</button></form>
        <h2>Models</h2><p>Upload a static GLB and a PNG/JPEG poster. Run the PHP worker to prepare files, then refresh this project.</p>
        <form onSubmit={upload}><label>Model (GLB)<input name="model" type="file" accept=".glb" required /></label><label>Poster<input name="poster" type="file" accept="image/png,image/jpeg" required /></label><button disabled={busy}>Upload model</button></form></>}
        <ul>{assets.map(asset => <li key={asset.assetVersionId}>{asset.assetVersionId}: {asset.status}</li>)}</ul>{error && <p role="alert">{error}</p>}</Frame>;
}
