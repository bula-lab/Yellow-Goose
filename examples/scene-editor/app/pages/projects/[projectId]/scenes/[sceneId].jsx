import { SceneEditor } from '@yellow-goose/editor';
import { BabylonSceneViewport } from '@yellow-goose/babylon/multi';
import { WorkspaceShell } from '@yellow-goose/react/workspace';
import Frame from '../../../../components/Frame';
export default function ScenePage({document, assets, csrfToken, readOnly}) {
    return <Frame><h1>{document.name}</h1><p><a href={'/projects/' + document.projectId}>Back to project</a></p>
        <p><a download="scene.json" href={'/api/projects/' + document.projectId + '/scenes/' + document.sceneId + '/export'}>Export scene JSON</a></p>
        <WorkspaceShell><SceneEditor key={document.sceneId} initialDocument={document} assets={assets} csrfToken={csrfToken}
            readOnly={readOnly} Viewport={BabylonSceneViewport} /></WorkspaceShell></Frame>;
}
