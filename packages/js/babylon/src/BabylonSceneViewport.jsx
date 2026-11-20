import { SceneViewport } from '@yellow-goose/scene/multi';

const loadAdapter = () => import('@yellow-goose/babylon/multi-engine');
export function BabylonSceneViewport(props) {
    return <SceneViewport {...props} loadAdapter={loadAdapter} />;
}
