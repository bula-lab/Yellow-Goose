import { mount } from '@yellow-goose/babylon/multi-engine';
import { UtilityLayerRenderer } from '@babylonjs/core/Rendering/utilityLayerRenderer.js';

const transform = { position: [0, 0, 0], rotation: [0, 0, 0, 1], scale: [1, 1, 1] };
const entity = { entityId: 'box', type: 'garage', visible: true, parentId: null, assetVersionId: null, properties: { heightMm: 2130 }, transform };
const document = { projectId: 'fixture', entities: [entity] };
window.commands = [];
window.port = mount(window.document.querySelector('canvas'), {
    onStatus: status => { window.phase = status; },
    onTransform(id, values) {
        window.commands.push({ id, values });
        entity.transform = { ...entity.transform, ...values };
        window.port.setDocument(document, []);
    },
});
window.port.setDocument(document, []);
window.port.select(entity.entityId);

// Hit-test the Babylon utility layer at CSS pixels, then drive the real pointers in Chromium.
window.getHandles = () => {
    const scene = UtilityLayerRenderer.DefaultUtilityLayer.utilityLayerScene;
    const hits = new Map();
    for (let y = 80; y < 420; y += 4) {
        for (let x = 160; x < 640; x += 4) {
            const mesh = scene.pick(x, y)?.pickedMesh;
            if (!mesh) continue;
            if (!hits.has(mesh.uniqueId)) hits.set(mesh.uniqueId, []);
            hits.get(mesh.uniqueId).push([x, y]);
        }
    }
    return [...hits.values()].sort((a, b) => b.length - a.length).map(points => points[Math.floor(points.length / 2)]);
};

window.flipHierarchy = () => {
    const a = { ...entity, entityId: 'a', parentId: null, transform: { ...transform, position: [2, 0, 0] } };
    const b = { ...entity, entityId: 'b', parentId: 'a', transform: { ...transform, position: [1, 0, 0] } };
    window.port.setDocument({ ...document, entities: [a, b] }, []);
    window.port.setDocument({ ...document, entities: [{ ...a, parentId: 'b' }, { ...b, parentId: null }] }, []);
    return window.port.viewState().entities;
};
