import { Engine } from '@babylonjs/core/Engines/engine.js';
import { Scene } from '@babylonjs/core/scene.js';
import { Vector3 } from '@babylonjs/core/Maths/math.vector.js';
import { Color3, Color4 } from '@babylonjs/core/Maths/math.color.js';
import { ArcRotateCamera } from '@babylonjs/core/Cameras/arcRotateCamera.js';
import { HemisphericLight } from '@babylonjs/core/Lights/hemisphericLight.js';
import { TransformNode } from '@babylonjs/core/Meshes/transformNode.js';
import { LoadAssetContainerAsync } from '@babylonjs/core/Loading/sceneLoader.js';
import { PointerEventTypes } from '@babylonjs/core/Events/pointerEvents.js';
import '@babylonjs/loaders/glTF/2.0/glTFLoader.js';
import '@babylonjs/core/Rendering/outlineRenderer.js';
import '@babylonjs/core/Culling/ray.js';

export function mount(canvas, { onStatus, onSelect } = {}) {
    const engine = new Engine(canvas, true, { preserveDrawingBuffer: true, stencil: true, loseContextOnDispose: true });
    const scene = new Scene(engine);
    scene.useRightHandedSystem = true;
    scene.clearColor = new Color4(.93, .94, .95, 1);
    const camera = new ArcRotateCamera('viewer', -Math.PI / 3, Math.PI / 3, 5, Vector3.Zero(), scene);
    camera.minZ = .01;
    camera.wheelPrecision = 45;
    camera.attachControl(canvas, true);
    new HemisphericLight('sky', Vector3.Up(), scene).intensity = 1.3;
    const root = new TransformNode('model-space', scene);
    let active = true;
    let visible = true;
    let lost = false;
    let animating = false;
    let model = null;
    let version = null;
    let baseSize = null;
    let selected = null;
    let generation = 0;
    let request = null;
    let options = {};
    const entities = new Map();
    const colors = new Map();
    const render = () => scene.render();
    function visibility() {
        const running = active && Boolean(model) && visible && !document.hidden && !lost;
        if (animating === running) return;
        animating = running;
        if (running) engine.runRenderLoop(render);
        else engine.stopRenderLoop(render);
    }
    const resize = new ResizeObserver(() => { if (active) engine.resize(); });
    resize.observe(canvas);
    const intersection = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; visibility(); });
    intersection.observe(canvas);
    document.addEventListener('visibilitychange', visibility);
    engine.onContextLostObservable.add(() => { if (active) { lost = true; visibility(); onStatus?.('lost'); } });
    engine.onContextRestoredObservable.add(() => { if (active) { lost = false; visibility(); onStatus?.(model ? 'ready' : 'loading'); } });
    visibility();

    function select(id) {
        selected = entities.has(id) ? id : null;
        // Selection works with stock PBR materials and requires no extra render pass.
        for (const [key, mesh] of entities) {
            mesh.renderOverlay = key === selected;
            mesh.overlayColor = new Color3(1, .7, .12);
        }
    }
    scene.onPointerObservable.add(event => {
        if (event.type !== PointerEventTypes.POINTERPICK) return;
        const entity = event.pickInfo?.pickedMesh?.metadata?.ygEntityId ?? null;
        select(entity);
        onSelect?.(entity);
    });
    function focus(id = selected) {
        if (!model) return;
        const bounds = entities.get(id)?.getBoundingInfo().boundingBox;
        const { min, max } = bounds ? { min: bounds.minimumWorld, max: bounds.maximumWorld } : root.getHierarchyBoundingVectors();
        const target = min.add(max).scale(.5);
        camera.setTarget(target);
        camera.radius = Math.max(.25, max.subtract(min).length() * 1.35);
        camera.lowerRadiusLimit = Math.max(.02, camera.radius * .02);
        camera.upperRadiusLimit = Math.max(100, camera.radius * 4);
    }
    function applyChanges(changes = {}) {
        options = changes || {};
        if (!model) return;
        for (const [axis, key] of [['x', 'widthMm'], ['y', 'heightMm'], ['z', 'depthMm']]) {
            const mm = options[key];
            root.scaling[axis] = Number.isFinite(mm) && mm > 0 && baseSize[axis] > .000001 ? mm / 1000 / baseSize[axis] : 1;
        }
        const color = /^#[0-9a-f]{6}$/i.test(options.color ?? '') ? Color3.FromHexString(options.color).toLinearSpace() : null;
        for (const [material, original] of colors) material.albedoColor.copyFrom(color ?? original);
        root.computeWorldMatrix(true);
        for (const mesh of entities.values()) mesh.computeWorldMatrix(true);
    }
    async function loadAsset(asset, signal) {
        const turn = ++generation;
        request?.abort();
        model?.dispose();
        model = null;
        version = null;
        selected = null;
        entities.clear();
        colors.clear();
        visibility();
        const controller = request = new AbortController();
        const abort = () => controller.abort();
        signal?.addEventListener('abort', abort, { once: true });
        if (signal?.aborted || !active) controller.abort();
        try {
            const url = new URL(asset.modelUrl, location.href);
            if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Invalid model URL.');
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Cannot fetch model.');
            const bytes = new Uint8Array(await response.arrayBuffer());
            if (controller.signal.aborted || !active) throw new DOMException('Aborted', 'AbortError');
            const next = await LoadAssetContainerAsync(bytes, scene, { pluginExtension: '.glb', name: 'asset.glb' });
            if (controller.signal.aborted || !active || turn !== generation) { next.dispose(); throw new DOMException('Aborted', 'AbortError'); }
            model = next;
            version = asset.assetVersionId;
            root.scaling.setAll(1);
            next.addAllToScene();
            for (const mat of next.materials) if (mat.albedoColor) colors.set(mat, mat.albedoColor.clone());
            for (const node of [...next.meshes, ...next.transformNodes]) if (!node.parent) node.parent = root;
            for (const [index, mesh] of next.meshes.entries()) {
                if (!mesh.getTotalVertices()) continue;
                const id = version + ':' + index;
                mesh.metadata = { ...mesh.metadata, ygEntityId: id };
                entities.set(id, mesh);
            }
            const { min, max } = root.getHierarchyBoundingVectors();
            baseSize = max.subtract(min);
            applyChanges(options);
            focus(null);
            visibility();
        } finally {
            signal?.removeEventListener('abort', abort);
            if (request === controller) request = null;
        }
    }
    return { loadAsset, applyChanges, select, focus,
        capturePreview() {
            if (!active || lost || !model) return Promise.reject(new Error('Preview unavailable.'));
            scene.render();
            return new Promise((resolve, reject) => canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Preview unavailable.')), 'image/png'));
        },
        viewState() {
            const bounds = active && model ? root.getHierarchyBoundingVectors() : null;
            return { assetVersionId: version, selected, entityIds: [...entities.keys()], running: animating,
                rightHanded: scene.useRightHandedSystem, dimensionsMeters: bounds ? bounds.max.subtract(bounds.min).asArray() : null,
                camera: { alpha: camera.alpha, beta: camera.beta, radius: camera.radius, target: camera.target.asArray() } };
        },
        dispose() {
            if (!active) return;
            active = false;
            ++generation;
            request?.abort();
            visibility();
            intersection.disconnect();
            resize.disconnect();
            document.removeEventListener('visibilitychange', visibility);
            camera.detachControl();
            entities.clear();
            colors.clear();
            model?.dispose();
            model = null;
            scene.dispose();
            engine.dispose();
        },
    };
}
