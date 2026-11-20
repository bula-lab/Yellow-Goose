import { Viewport } from '@yellow-goose/scene';

const loadAdapter = () => import('@yellow-goose/babylon/engine');

export function BabylonViewport(props) {
    return <Viewport {...props} loadAdapter={loadAdapter} />;
}
